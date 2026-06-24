<?php

namespace Tests\Unit\Transcription;

use App\Enums\TranscriptionStatus;
use App\Jobs\DownloadRecording;
use App\Jobs\TranscribePhoneCall;
use App\Models\PhoneCall;
use App\Services\TranscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Tests for the split download → transcription pipeline introduced by psa-amrs.
 *
 * DownloadRecording (job 1) downloads the CDN recording to a persisted local file
 * under its own generous timeout, then dispatches TranscribePhoneCall (job 2) to
 * run Whisper STT + AI analysis with a separate budget.
 *
 * These tests assert the handoff contract:
 *   - job 1 writes recording_disk_path and dispatches job 2
 *   - job 1 is idempotent (skips re-download if file already exists)
 *   - job 1 marks failure and does NOT dispatch job 2 on error
 *   - job 2 uses the local file when recording_disk_path is set
 *   - job 2 cleans up the disk file on success and on failure
 */
class DownloadRecordingJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Bus::fake();
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function makeCall(array $attrs = []): PhoneCall
    {
        $call = new PhoneCall([
            'call_uuid' => uniqid('test_', true),
            'recording_url' => $attrs['recording_url'] ?? 'https://media.plivo.com/fake.mp3',
            'transcription_status' => $attrs['transcription_status'] ?? TranscriptionStatus::Pending,
        ]);
        $call->recording_disk_path = $attrs['recording_disk_path'] ?? null;
        $call->save();

        return $call;
    }

    // ─── DownloadRecording job ─────────────────────────────────────────────

    public function test_download_job_saves_to_disk_and_dispatches_transcribe(): void
    {
        $call = $this->makeCall();

        // Partial mock: inject a stub for downloadRecordingForJob
        $service = $this->createPartialMock(TranscriptionService::class, ['downloadRecordingForJob']);

        // Make downloadRecordingForJob write a real temp file (as the real method does)
        $service->method('downloadRecordingForJob')->willReturnCallback(function (string $url) {
            $tmp = tempnam(sys_get_temp_dir(), 'test_rec_');
            file_put_contents($tmp, 'fake-audio');

            return $tmp;
        });

        $job = new DownloadRecording($call->id);
        $job->handle($service);

        // File persisted on disk
        $diskPath = "call-recordings/{$call->id}.mp3";
        Storage::disk('local')->assertExists($diskPath);

        // DB column updated
        $call->refresh();
        $this->assertSame($diskPath, $call->recording_disk_path);

        // TranscribePhoneCall was dispatched (with the correct call ID via reflection)
        Bus::assertDispatched(TranscribePhoneCall::class, function ($j) use ($call) {
            $ref = new \ReflectionProperty($j, 'callId');

            return $ref->getValue($j) === $call->id;
        });
    }

    public function test_download_job_dispatches_transcribe_when_file_already_exists(): void
    {
        $diskPath = 'call-recordings/99.mp3';
        Storage::disk('local')->put($diskPath, 'pre-existing-audio');

        $call = $this->makeCall(['recording_disk_path' => $diskPath]);
        $call->id = 99; // force id to match path
        $call->save();

        $service = $this->createMock(TranscriptionService::class);
        // downloadRecordingForJob must NOT be called
        $service->expects($this->never())->method('downloadRecordingForJob');

        // Re-read fresh call with the forced disk path
        $freshCall = PhoneCall::where('recording_disk_path', $diskPath)->first();
        $job = new DownloadRecording($freshCall->id);
        $job->handle($service);

        Bus::assertDispatched(TranscribePhoneCall::class);
    }

    public function test_download_job_marks_failed_and_does_not_dispatch_transcribe_on_error(): void
    {
        $call = $this->makeCall();

        $service = $this->createPartialMock(TranscriptionService::class, ['downloadRecordingForJob']);
        $service->method('downloadRecordingForJob')->willThrowException(
            new \RuntimeException('CDN timeout')
        );

        $job = new DownloadRecording($call->id);

        // On the first attempt the job rethrows to let Laravel retry; we catch it
        // here and just assert the DB state (status=Failed, error stored, no dispatch).
        try {
            $job->handle($service);
        } catch (\Throwable) {
            // First attempt rethrows — that's expected; we only care about DB state below
        }

        $call->refresh();
        $this->assertSame(TranscriptionStatus::Failed, $call->transcription_status);
        $this->assertStringContainsString('CDN timeout', $call->transcription_error);

        // TranscribePhoneCall must NOT be dispatched
        Bus::assertNotDispatched(TranscribePhoneCall::class);
    }

    // ─── TranscribePhoneCall uses local file ──────────────────────────────

    /**
     * The updated guard in TranscriptionService::transcribe() allows a call with only
     * recording_disk_path (no recording_url) to proceed.  We verify it passes the guard
     * by confirming the first failure is the missing Whisper API key, NOT a "no recording"
     * bail-out — which would mean the guard incorrectly blocked a pre-downloaded file.
     */
    public function test_transcription_service_guard_accepts_disk_path_without_recording_url(): void
    {
        $diskPath = 'call-recordings/42.mp3';
        Storage::disk('local')->put($diskPath, 'fake-audio');

        $service = new TranscriptionService;

        // Call has ONLY disk path — no CDN URL.
        $call = new PhoneCall;
        $call->recording_disk_path = $diskPath;
        $call->recording_url = null;
        $call->transcription_status = TranscriptionStatus::Pending;

        // Without a Whisper key the service throws — but it should be the missing-key
        // error, not a silent return (which would mean the guard blocked it).
        try {
            $service->transcribe($call);
            $this->fail('Expected RuntimeException for missing API key');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString(
                'OpenAI API key not configured',
                $e->getMessage(),
                'Should fail at missing API key, not at missing recording URL'
            );
        }
    }

    // ─── TranscribePhoneCall disk cleanup ────────────────────────────────

    public function test_transcribe_job_cleans_up_disk_file_on_completion(): void
    {
        $diskPath = 'call-recordings/55.mp3';
        Storage::disk('local')->put($diskPath, 'audio');

        $call = $this->makeCall([
            'transcription_status' => TranscriptionStatus::Pending,
            'recording_disk_path' => $diskPath,
        ]);

        // Mock TranscriptionService.transcribe to succeed without hitting real APIs
        $service = $this->createMock(TranscriptionService::class);
        $service->method('transcribe')->willReturnCallback(function (PhoneCall $c) {
            $c->update(['transcription_status' => TranscriptionStatus::Completed]);
        });

        $job = new \App\Jobs\TranscribePhoneCall($call->id);
        $job->handle($service);

        // Disk file must be gone
        Storage::disk('local')->assertMissing($diskPath);

        // DB column cleared
        $call->refresh();
        $this->assertNull($call->recording_disk_path);
    }

    public function test_transcribe_job_cleans_up_disk_file_on_failure(): void
    {
        $diskPath = 'call-recordings/66.mp3';
        Storage::disk('local')->put($diskPath, 'audio');

        $call = $this->makeCall([
            'transcription_status' => TranscriptionStatus::Pending,
            'recording_disk_path' => $diskPath,
        ]);

        $service = $this->createMock(TranscriptionService::class);
        $service->method('transcribe')->willThrowException(new \RuntimeException('Whisper error'));

        $job = new \App\Jobs\TranscribePhoneCall($call->id);

        try {
            $job->handle($service);
        } catch (\Throwable) {
            // Expected on first attempt
        }

        // Disk file must still be cleaned up even though transcription failed
        Storage::disk('local')->assertMissing($diskPath);

        $call->refresh();
        $this->assertNull($call->recording_disk_path);
    }
}
