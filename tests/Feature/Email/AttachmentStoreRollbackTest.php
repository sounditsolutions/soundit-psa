<?php

namespace Tests\Feature\Email;

use App\Models\Attachment;
use App\Services\AttachmentService;
use App\Services\AttachmentStoreFailedException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Monolog\Handler\TestHandler;
use Monolog\LogRecord;
use Tests\TestCase;

/**
 * b4i (card 6ac5994e), #5706 review leads on AttachmentService's store path:
 *  - #5709: a write that returns false (the local disk is 'throw' => false) leaves no row
 *    naming a missing file; a cleanup step that throws is recorded, not swallowed.
 *  - #5716: storeUpload gets the same rollback as storeFromContent (#5553).
 *  - #5719: the Stored records carry ids, size and type; no filename or contentId.
 *
 * Synthetic data only.
 */
class AttachmentStoreRollbackTest extends TestCase
{
    use RefreshDatabase;

    private const FILENAME = 'Payroll-Q3-Example.xlsx';

    private const CONTENT_ID = 'img1@synthetic.example.test';

    private TestHandler $logs;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->logs = new TestHandler;
        $handler = $this->logs;
        foreach (array_keys(config('logging.channels')) as $name) {
            config(["logging.channels.{$name}" => [
                'driver' => 'custom',
                'via' => fn () => new \Monolog\Logger($name, [$handler]),
            ]]);
            Log::forgetChannel($name);
        }
    }

    /** The faked local disk, with the named methods returning false (a refused write). */
    private function diskRefusing(string ...$methods): void
    {
        $real = Storage::disk('local');
        $disk = \Mockery::mock(FilesystemAdapter::class, [$real->getDriver(), $real->getAdapter(), $real->getConfig()])->makePartial();
        foreach ($methods as $method) {
            $disk->shouldReceive($method)->andReturn(false);
        }
        Storage::set('local', $disk);
    }

    /** @return list<LogRecord> */
    private function withMessage(string $message): array
    {
        return array_values(array_filter($this->logs->getRecords(), fn (LogRecord $r) => $r->message === $message));
    }

    private function upload(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(self::FILENAME, 'synthetic-xlsx-bytes');
    }

    /** @return array<string, array{0: \Closure(AttachmentService): Attachment, 1: string}> */
    public static function stores(): array
    {
        return [
            'storeFromContent' => [fn (AttachmentService $s, self $t) => $s->storeFromContent('synthetic-bytes', self::FILENAME, 'text/plain', true, self::CONTENT_ID), 'put'],
            'storeUpload' => [fn (AttachmentService $s, self $t) => $s->storeUpload($t->upload()), 'putFileAs'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('stores')]
    public function test_a_write_that_returns_false_leaves_no_row_and_throws(\Closure $store, string $write): void
    {
        $this->diskRefusing($write);
        $made = null;
        Attachment::created(function (Attachment $a) use (&$made) {
            $made = $a->id;
        });

        try {
            $store(app(AttachmentService::class), $this);
            $this->fail('a refused write must not return an Attachment');
        } catch (AttachmentStoreFailedException $e) {
            $this->assertStringNotContainsString('Payroll', $e->getMessage(), 'id only, no filename');
        }

        $this->assertNotNull($made, 'positive control: the row was created before the write');
        $this->assertSame(0, Attachment::withTrashed()->count(), '#5709: no row names a file that was never written');
        $this->assertSame([], Storage::disk('local')->allFiles('attachments'));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('stores')]
    public function test_a_failed_storage_path_update_removes_the_row_and_the_file(\Closure $store): void
    {
        // #5716 for storeUpload; #5553 kept for storeFromContent.
        Attachment::updating(function (Attachment $a) {
            if ($a->isDirty('storage_path')) {
                throw new \RuntimeException('B4I-SYNTHETIC-UPDATE-FAIL');
            }
        });

        try {
            $store(app(AttachmentService::class), $this);
            $this->fail('the update failure is rethrown');
        } catch (\RuntimeException $e) {
            $this->assertSame('B4I-SYNTHETIC-UPDATE-FAIL', $e->getMessage(), 'rethrown unchanged');
        }

        $this->assertSame(0, Attachment::withTrashed()->count(), 'no row naming the placeholder');
        $this->assertSame([], Storage::disk('local')->allFiles('attachments'), 'no orphan file');
    }

    public function test_a_cleanup_step_that_throws_is_recorded_by_id_step_and_class(): void
    {
        Attachment::updating(function (Attachment $a) {
            if ($a->isDirty('storage_path')) {
                throw new \RuntimeException('B4I-SYNTHETIC-UPDATE-FAIL');
            }
        });
        // The row cleanup is a query-builder delete (no model events), so it is failed at the
        // statement.
        \Illuminate\Support\Facades\DB::beforeExecuting(function (string $sql) {
            if (str_starts_with(strtolower($sql), 'delete from "attachments"')) {
                throw new \LogicException('B4I-SYNTHETIC-CLEANUP '.self::FILENAME);
            }
        });

        try {
            app(AttachmentService::class)->storeFromContent('synthetic-bytes', self::FILENAME, 'text/plain');
            $this->fail('rethrown');
        } catch (\RuntimeException $e) {
            $this->assertSame('B4I-SYNTHETIC-UPDATE-FAIL', $e->getMessage(), 'the original failure, not the cleanup one');
        }

        $cleanup = $this->withMessage('[Attachment] Cleanup after a failed store threw');
        $this->assertCount(1, $cleanup, '#5709: the row cleanup failure is recorded, not swallowed');
        $row = Attachment::withTrashed()->sole();
        $this->assertSame(['attachment_id' => $row->id, 'step' => 'row', 'exception' => \LogicException::class], $cleanup[0]->context);
        $this->assertStringNotContainsString('Payroll', json_encode($cleanup[0]->context));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('stores')]
    public function test_the_stored_record_carries_no_filename_or_content_id(\Closure $store): void
    {
        $attachment = $store(app(AttachmentService::class), $this);

        $this->assertStringStartsWith("attachments/{$attachment->id}/payroll-q3-example", $attachment->storage_path, 'positive control: stored');
        $records = array_values(array_filter($this->logs->getRecords(), fn (LogRecord $r) => str_starts_with($r->message, '[Attachment] Stored')));
        $this->assertCount(1, $records);
        $this->assertSame(['attachment_id', 'size', 'mime'], array_slice(array_keys($records[0]->context), 0, 3));
        $text = $records[0]->message.json_encode($records[0]->context);
        foreach (['payroll', 'Payroll', '.xlsx', self::CONTENT_ID, 'synthetic.example.test'] as $needle) {
            $this->assertStringNotContainsString($needle, $text, '#5719: no filename or contentId');
        }
    }
}
