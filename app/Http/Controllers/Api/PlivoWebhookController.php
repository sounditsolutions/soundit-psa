<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PhoneCall;
use App\Services\NotificationService;
use App\Services\PhoneCallService;
use App\Support\PlivoConfig;
use App\Support\TranscriptionConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class PlivoWebhookController extends Controller
{
    public function __construct(
        private readonly PhoneCallService $phoneCallService,
        private readonly NotificationService $notificationService,
    ) {}

    /**
     * After a call ends, resolve the recording from Plivo's API if none arrived via callback.
     * Plivo's inbound call recording callbacks often don't reach our webhook (configured in the
     * Plivo application, not our code). This queries the API directly after a short delay to
     * give Plivo time to finalize the recording.
     */
    private function resolveRecordingAfterEnd(?PhoneCall $call): void
    {
        if (! $call || $call->recording_url || ! $call->duration || $call->duration < 1) {
            return;
        }

        // Delay 30s — Plivo needs time to process and finalize the recording after hangup
        $cmd = sprintf(
            'sleep 30 && php %s calls:resolve-recording %d > /dev/null 2>&1 &',
            base_path('artisan'),
            $call->id,
        );
        Process::run($cmd);
    }

    /**
     * Answer URL for outbound calls from browser endpoints.
     * Returns Dial XML to connect the browser caller to the destination number.
     */
    public function browserAnswer(Request $request): Response
    {
        $destination = $request->input('ForwardTo') ?? $request->input('To');

        // Strip non-digit/+ chars, require valid phone pattern
        $destination = preg_replace('/[^\d+]/', '', $destination ?? '');
        if (! preg_match('/^\+?1?\d{10,15}$/', $destination)) {
            Log::warning('Invalid outbound destination', ['raw' => $request->input('ForwardTo') ?? $request->input('To')]);

            return response('<?xml version="1.0"?><Response><Hangup/></Response>', 200,
                ['Content-Type' => 'application/xml']);
        }

        // Normalize: ensure country code for US numbers
        if (strlen(ltrim($destination, '+')) === 10) {
            $destination = '1'.ltrim($destination, '+');
        }

        // Log outbound call before returning XML (non-blocking — don't let DB errors break the call)
        try {
            $this->phoneCallService->logOutboundCall([
                'CallUUID' => $request->input('CallUUID'),
                'From' => $request->input('From'),
                'To' => $destination,
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to log outbound call', ['error' => $e->getMessage()]);
        }

        $callerId = PlivoConfig::get('did_number');
        $callbackUrl = url('/api/plivo/'.PlivoConfig::get('webhook_secret').'/webhook');

        $xml = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml .= '<Response>';
        $xml .= '<Record startOnDialAnswer="true" redirect="false" maxLength="14400" callbackUrl="'.htmlspecialchars($callbackUrl).'" callbackMethod="POST" />';
        $xml .= '<Dial callerId="'.htmlspecialchars($callerId).'" callbackUrl="'.htmlspecialchars($callbackUrl).'">';
        $xml .= '<Number>'.htmlspecialchars($destination).'</Number>';
        $xml .= '</Dial>';
        $xml .= '</Response>';

        return response($xml, 200, ['Content-Type' => 'application/xml']);
    }

    /**
     * Resolve the inbound caller against the PSA phone directory and people table.
     * Designed for a Plivo PHLO HTTP-request node to call during the IVR so the
     * flow can branch on whether the caller is blocked, allow-listed, a known
     * client contact, or unknown.
     *
     * Request body (Plivo standard fields): From, To, CallUUID
     *
     * Response flags:
     *   - known:   true if matched anything in our system (client/blocked/allowed)
     *   - client:  true if matched a Person in the people table
     *   - blocked: true if on the blocked list
     *   - allowed: true if on the allow list
     *
     * Branch priority for the PHLO: blocked -> allowed -> client -> unknown.
     * The endpoint never blocks or fails the call: any error returns
     * everything-false so PHLO routes the caller to the unknown branch.
     */
    public function resolveCaller(Request $request): JsonResponse
    {
        $from = $request->input('From') ?? $request->input('from');
        $callUuid = $request->input('CallUUID') ?? $request->input('callUuid');

        $payload = [
            'known' => false,
            'client' => false,
            'blocked' => false,
            'allowed' => false,
            'person_id' => null,
            'person_name' => null,
            'person_first_name' => null,
            'client_id' => null,
            'client_name' => null,
            'caller_label' => null,
        ];

        if (! $from) {
            Log::info('[PlivoResolveCaller] Missing From — returning unknown', [
                'call_uuid' => $callUuid,
            ]);

            return response()->json($payload);
        }

        // Phone directory lookup takes precedence over person lookup so the PHLO
        // can hang up on blocked callers and ring allow-listed callers through
        // with their label without doing any client matching.
        $directoryEntry = \App\Models\PhoneDirectoryEntry::lookup($from);

        if ($directoryEntry?->isBlocked()) {
            $payload['known'] = true;
            $payload['blocked'] = true;
            Log::info('[PlivoResolveCaller] Blocked', [
                'call_uuid' => $callUuid,
                'from' => $from,
            ]);

            return response()->json($payload);
        }

        if ($directoryEntry?->isAllowed()) {
            $payload['known'] = true;
            $payload['allowed'] = true;
            $payload['caller_label'] = $directoryEntry->label;
            Log::info('[PlivoResolveCaller] Allowed', [
                'call_uuid' => $callUuid,
                'from' => $from,
                'label' => $directoryEntry->label,
            ]);

            return response()->json($payload);
        }

        try {
            $person = $this->phoneCallService->findPersonByPhoneNumber($from);
        } catch (\Throwable $e) {
            Log::warning('[PlivoResolveCaller] Lookup failed', [
                'call_uuid' => $callUuid,
                'from' => $from,
                'error' => $e->getMessage(),
            ]);

            return response()->json($payload);
        }

        if (! $person) {
            Log::info('[PlivoResolveCaller] No match', [
                'call_uuid' => $callUuid,
                'from' => $from,
            ]);

            return response()->json($payload);
        }

        $payload['known'] = true;
        $payload['client'] = true;
        $payload['person_id'] = $person->id;
        $payload['person_name'] = $person->fullName;
        $payload['person_first_name'] = $person->first_name;
        $payload['client_id'] = $person->client_id;
        $payload['client_name'] = $person->client?->name;

        Log::info('[PlivoResolveCaller] Match', [
            'call_uuid' => $callUuid,
            'from' => $from,
            'person_id' => $person->id,
            'client_id' => $person->client_id,
        ]);

        return response()->json($payload);
    }

    /**
     * Single endpoint for all Plivo callbacks.
     * Routes internally based on payload content.
     *
     * Plivo sends various event types with different field structures:
     * - Some have CallStatus (ringing, in-progress, completed)
     * - Some use DialAction (answer, hangup) without CallStatus
     * - Recording callbacks have RecordUrl (may arrive before call is answered)
     * We must handle all variants and ensure a record exists first.
     */
    public function handle(Request $request): Response
    {
        Log::debug('Plivo webhook received', [
            'all' => $request->all(),
        ]);

        $request->validate([
            'CallUUID' => 'required|string|max:100',
        ]);

        $callUuid = $request->input('CallUUID');
        $callStatus = $request->input('CallStatus', '');
        $dialAction = $request->input('DialAction', '');
        $hasRecording = $request->filled('RecordUrl');

        // Ensure a call record exists — Plivo's first callback may already
        // have a status like "in-progress", so we can't rely on an empty
        // status to trigger creation.
        $existing = PhoneCall::where('call_uuid', $callUuid)->exists();
        if (! $existing) {
            $this->phoneCallService->logIncomingCall($request->all());
        }

        // Recording callback — RecordUrl present (may arrive early with duration=-1)
        if ($hasRecording) {
            $request->validate([
                'RecordUrl' => 'required|url|max:2048',
                'RecordingDuration' => 'nullable|integer',
            ]);

            $recordingDuration = $request->integer('RecordingDuration');

            // Only save recording URL when duration >= 0 (recording complete).
            // The duration=-1 callback provides a temporary recording ID that Plivo
            // discards for unanswered calls, replaced by a different permanent ID.
            if ($recordingDuration >= 0) {
                $this->phoneCallService->handleRecordingReady(
                    $callUuid,
                    $request->input('RecordUrl'),
                    $recordingDuration,
                );
            }

            // Auto-detect voicemail: recording completed on an unanswered call.
            // Require minimum 3s duration — callers who hang up during the greeting
            // produce 0-second recordings that aren't real voicemails.
            if ($recordingDuration >= 3) {
                $call = PhoneCall::where('call_uuid', $callUuid)->first();
                if ($call && $call->answered_at === null) {
                    $this->phoneCallService->markAsVoicemail($callUuid);

                    // For voicemails, Plivo's callback URL may differ from the
                    // actual recording. Resolve the real recording via API.
                    $this->phoneCallService->resolveRecordingFromPlivo($call);

                    // If auto-transcribe is going to run for this recording,
                    // defer the notification until transcription completes so
                    // the email can include the AI summary and transcript.
                    // Otherwise notify immediately. TranscriptionService
                    // dispatches notifyNewVoicemail itself in its finally block.
                    $willTranscribe = TranscriptionConfig::autoTranscribeEnabled()
                        && TranscriptionConfig::isConfigured()
                        && $recordingDuration >= TranscriptionConfig::minDurationSeconds();

                    if (! $willTranscribe) {
                        $this->notificationService->notifyNewVoicemail($call->refresh());
                    }
                }
            }

            // Auto-transcribe if enabled and recording is complete (duration >= 0).
            if ($recordingDuration >= 0 && TranscriptionConfig::autoTranscribeEnabled() && TranscriptionConfig::isConfigured()) {
                $minDuration = TranscriptionConfig::minDurationSeconds();
                if ($recordingDuration >= $minDuration) {
                    $call = $call ?? PhoneCall::where('call_uuid', $callUuid)->first();
                    if ($call && ! $call->isTranscribed() && ! $call->isTranscribing()) {
                        $call->update(['transcription_status' => \App\Enums\TranscriptionStatus::Pending]);
                        // Delay 15s — Plivo's CDN needs time to finalize the MP3 after the callback fires
                        $cmd = sprintf('sleep 15 && php %s calls:transcribe %d > /dev/null 2>&1 &', base_path('artisan'), $call->id);
                        Process::run($cmd);
                    }
                }
            }

            return response('OK', 200);
        }

        // Hangup — DialAction=hangup (Plivo often omits CallStatus on hangup)
        if ($dialAction === 'hangup') {
            // Use DialBLegDuration as fallback for Duration
            $data = $request->all();
            if (! isset($data['Duration']) && isset($data['DialBLegDuration'])) {
                $data['Duration'] = $data['DialBLegDuration'];
            }

            $call = $this->phoneCallService->handleCallEnded($callUuid, $data);
            $this->resolveRecordingAfterEnd($call);

            return response('OK', 200);
        }

        // Terminal states via CallStatus
        if (in_array($callStatus, ['completed', 'busy', 'failed', 'timeout', 'no-answer', 'cancel'])) {
            $call = $this->phoneCallService->handleCallEnded($callUuid, $request->all());
            $this->resolveRecordingAfterEnd($call);

            return response('OK', 200);
        }

        // Call answered — via DialAction or CallStatus
        if ($dialAction === 'answer' || in_array($callStatus, ['in-progress', 'answered'])) {
            $this->phoneCallService->handleCallAnswered($callUuid, $request->all());

            return response('OK', 200);
        }

        // Ringing or initial answer URL — return Plivo XML
        return response(
            '<?xml version="1.0" encoding="UTF-8"?><Response></Response>',
            200,
            ['Content-Type' => 'application/xml']
        );
    }
}
