<?php

namespace App\Services;

use App\Enums\CallDirection;
use App\Enums\CallStatus;
use App\Jobs\ResolveCallerFromPeople;
use App\Models\Person;
use App\Models\PhoneCall;
use App\Models\SipEndpoint;
use App\Support\PhoneNumber;
use App\Support\PlivoConfig;
use GuzzleHttp\Client as GuzzleClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\Ticket;
use Illuminate\Support\Facades\Storage;

class PhoneCallService
{
    /**
     * Log an incoming call from a Plivo webhook.
     * Returns immediately — caller lookup dispatched async.
     */
    public function logIncomingCall(array $data): PhoneCall
    {
        $fromNumber = $data['From'] ?? '';

        $call = PhoneCall::updateOrCreate(
            ['call_uuid' => $data['CallUUID']],
            [
                'direction' => CallDirection::Inbound,
                'from_number' => $fromNumber,
                'to_number' => $data['To'] ?? null,
                'sip_endpoint' => $data['SipEndpoint'] ?? $data['To'] ?? null,
                'status' => CallStatus::Ringing,
                'started_at' => now(),
            ]
        );

        // Async: resolve caller from people table without blocking the webhook response
        if ($call->wasRecentlyCreated && $fromNumber) {
            ResolveCallerFromPeople::dispatch($call->id);
        }

        Log::debug('PhoneCallService: call logged', [
            'call_uuid' => $data['CallUUID'],
            'from' => $fromNumber,
            'created' => $call->wasRecentlyCreated,
        ]);

        return $call;
    }

    /**
     * Log an outbound call initiated from a browser endpoint.
     */
    public function logOutboundCall(array $data): PhoneCall
    {
        $toNumber = $data['To'] ?? '';
        $fromSip = $data['From'] ?? '';

        // Resolve which user placed the call via SIP endpoint
        // Plivo may send the SIP URI in various formats:
        //   sip:user@phone.plivo.com, user@phone.plivo.com, or just the username
        $endpoint = SipEndpoint::where('sip_uri', $fromSip)
            ->where('is_active', true)
            ->first();

        if (!$endpoint && $fromSip) {
            $endpoint = SipEndpoint::where('sip_uri', 'like', "%{$fromSip}%")
                ->where('is_active', true)
                ->first();
        }

        $call = PhoneCall::updateOrCreate(
            ['call_uuid' => $data['CallUUID']],
            [
                'direction' => CallDirection::Outbound,
                'from_number' => $toNumber,
                'to_number' => \App\Support\PlivoConfig::get('did_number'),
                'sip_endpoint' => $fromSip,
                'answered_by' => $endpoint?->user_id,
                'status' => CallStatus::Ringing,
                'started_at' => now(),
            ]
        );

        if ($call->wasRecentlyCreated && $toNumber) {
            ResolveCallerFromPeople::dispatch($call->id);
        }

        Log::debug('PhoneCallService: outbound call logged', [
            'call_uuid' => $data['CallUUID'],
            'from_sip' => $fromSip,
            'to' => $toNumber,
            'resolved_user' => $endpoint?->user_id,
        ]);

        return $call;
    }

    /**
     * Handle a call being answered — update status and resolve who answered.
     */
    public function handleCallAnswered(string $callUuid, array $data): ?PhoneCall
    {
        return $this->updateCallSafely($callUuid, function (PhoneCall $call) use ($data) {
            $callEnded = $call->ended_at !== null;

            // For active calls, mark as in-progress and stamp answered_at.
            // For already-ended calls, this is a late "answer" webhook (Plivo's
            // DialAction=answer fires at end-of-dial, not at answer). Derive
            // answered_at from duration so it reflects the real moment the
            // call was picked up, not when this late webhook arrived.
            if (! $callEnded) {
                $call->status = CallStatus::InProgress;
                $call->answered_at = now();
            } elseif ($call->duration && $call->duration > 0) {
                $call->answered_at = $call->ended_at->copy()->subSeconds($call->duration);
                if ($call->status !== CallStatus::Voicemail) {
                    $call->status = CallStatus::Completed;
                }
            }

            // Resolve which user answered via SIP endpoint (inbound calls only).
            // Outbound calls already have answered_by set from logOutboundCall().
            // Plivo sends the answering endpoint as DialBLegTo (e.g. sip:user@phone.plivo.com)
            if (!$call->answered_by) {
                $sipUri = $data['DialBLegTo'] ?? $data['SipEndpoint'] ?? $data['To'] ?? null;
                if ($sipUri) {
                    $call->sip_endpoint = $sipUri;
                    $endpoint = SipEndpoint::where('sip_uri', $sipUri)->where('is_active', true)->first();
                    if ($endpoint?->user_id) {
                        $call->answered_by = $endpoint->user_id;
                    }
                }
            }

            $call->save();
            return $call;
        });
    }

    /**
     * Handle a call ending — set duration and final status.
     */
    public function handleCallEnded(string $callUuid, array $data): ?PhoneCall
    {
        return $this->updateCallSafely($callUuid, function (PhoneCall $call) use ($data) {
            $call->ended_at = now();
            $call->duration = isset($data['Duration']) ? (int) $data['Duration'] : null;

            // Determine final status — preserve voicemail if already detected.
            // Don't infer "answered" from non-zero duration: Plivo's Duration
            // includes voicemail recording time, so a missed-call-with-voicemail
            // also has duration > 0. Use answered_at as the only signal —
            // handleCallAnswered sets it only for genuine answer events.
            if ($call->status === CallStatus::Voicemail) {
                // Already recorded as voicemail — preserve
            } elseif ($call->answered_at === null) {
                $call->status = CallStatus::Missed;
            } else {
                $call->status = CallStatus::Completed;
            }

            $call->save();

            // Re-run the debit with whatever duration is available. The
            // service uses effectiveDurationSeconds() which falls back to
            // recording_duration when Plivo omits Duration from the hangup
            // payload. Idempotent — updates existing txn if one exists.
            if ($call->ticket_id && $call->is_billable) {
                app(PrepayService::class)->debitFromPhoneCall($call);
            }

            return $call;
        });
    }

    /**
     * Mark a call as voicemail (auto-detected from recording on unanswered call).
     */
    public function markAsVoicemail(string $callUuid): ?PhoneCall
    {
        return $this->updateCallSafely($callUuid, function (PhoneCall $call) {
            $call->status = CallStatus::Voicemail;
            $call->save();
            return $call;
        });
    }

    /**
     * Handle recording becoming available.
     */
    public function handleRecordingReady(string $callUuid, string $url, ?int $duration): ?PhoneCall
    {
        $call = $this->updateCallSafely($callUuid, function (PhoneCall $call) use ($url, $duration) {
            $call->recording_url = $url;
            $call->recording_duration = $duration;

            // Plivo occasionally omits Duration from the hangup webhook. When
            // the recording webhook arrives afterward, use its duration as the
            // call duration so UI, reports, and exports all render correctly.
            if (! $call->duration && $duration && $duration > 0) {
                $call->duration = $duration;
            }

            $call->save();

            return $call;
        });

        // Download to local storage (non-blocking — failure doesn't affect the webhook response)
        if ($call) {
            $this->downloadRecording($call);

            // When Plivo's hangup webhook arrives without Duration, the debit
            // can't be calculated at call-end time. Re-run once the recording
            // duration lands — effectiveDurationSeconds() will use it as the
            // fallback. Idempotent.
            if ($call->ticket_id && $call->is_billable) {
                app(PrepayService::class)->debitFromPhoneCall($call);
            }
        }

        return $call;
    }

    /**
     * Resolve the actual recording URL from the Plivo API by call UUID.
     *
     * Plivo's initial recording callback (duration=-1) provides a temporary
     * recording ID. For unanswered calls (voicemails), the final recording
     * gets a different ID. This method queries the Plivo API to find the
     * correct, permanent recording URL.
     */
    public function resolveRecordingFromPlivo(PhoneCall $call): void
    {
        $authId = \App\Support\PlivoConfig::get('auth_id');
        $authToken = \App\Support\PlivoConfig::get('auth_token');
        if (!$authId || !$authToken) {
            return;
        }

        try {
            $client = new \GuzzleHttp\Client(['timeout' => 15]);
            $response = $client->get(
                "https://api.plivo.com/v1/Account/{$authId}/Recording/",
                [
                    'auth' => [$authId, $authToken],
                    'query' => ['call_uuid' => $call->call_uuid, 'limit' => 5],
                ]
            );

            $data = json_decode($response->getBody()->getContents(), true);
            $recordings = $data['objects'] ?? [];

            // Pick the longest recording for this call (the actual voicemail, not the stub)
            $best = null;
            foreach ($recordings as $rec) {
                if (!$best || ($rec['recording_duration_ms'] ?? 0) > ($best['recording_duration_ms'] ?? 0)) {
                    $best = $rec;
                }
            }

            if ($best && !empty($best['recording_url'])) {
                $durationSec = (int) round(($best['recording_duration_ms'] ?? 0) / 1000);
                $call->recording_url = $best['recording_url'];
                $call->recording_duration = $durationSec > 0 ? $durationSec : null;
                $call->save();

                Log::info('[PhoneCall] Resolved recording from Plivo API', [
                    'call_id' => $call->id,
                    'recording_id' => $best['recording_id'],
                    'duration_ms' => $best['recording_duration_ms'],
                ]);

                $this->downloadRecording($call);
            }
        } catch (\Throwable $e) {
            Log::warning('[PhoneCall] Failed to resolve recording from Plivo API', [
                'call_id' => $call->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Download a call recording from Plivo CDN and store it locally.
     */
    public function downloadRecording(PhoneCall $call): bool
    {
        if (! $call->recording_url || $call->recording_disk_path) {
            return false;
        }

        $authId = PlivoConfig::get('auth_id');
        $authToken = PlivoConfig::get('auth_token');

        $options = ['timeout' => 300];
        if ($authId && $authToken && str_contains($call->recording_url, 'media.plivo.com')) {
            $options['auth'] = [$authId, $authToken];
        }

        try {
            $client = new GuzzleClient($options);
            $response = $client->get($call->recording_url);
            $content = $response->getBody()->getContents();

            if (strlen($content) === 0) {
                Log::warning('[PhoneCall] Downloaded recording is empty', ['call_id' => $call->id]);

                return false;
            }

            $path = "call-recordings/{$call->id}.mp3";
            Storage::disk('local')->put($path, $content);

            $call->recording_disk_path = $path;
            $call->save();

            Log::info('[PhoneCall] Recording stored locally', [
                'call_id' => $call->id,
                'path' => $path,
                'size' => strlen($content),
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::warning('[PhoneCall] Failed to download recording', [
                'call_id' => $call->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Look up a Person by caller phone number. Used by both the async
     * ResolveCallerFromPeople job (post-call) and the synchronous
     * Plivo IVR resolve-caller endpoint (during the call, for routing).
     *
     * Returns null if the number can't be normalized or no match exists.
     * When multiple people share a number, picks the highest-scoring
     * candidate (primary > active > recent ticket activity).
     */
    public function findPersonByPhoneNumber(?string $rawNumber): ?Person
    {
        $normalized = PhoneNumber::normalize($rawNumber);
        if (! $normalized) {
            return null;
        }

        $candidates = Person::query()
            ->where(function ($q) use ($normalized) {
                $q->where('phone', $normalized)->orWhere('mobile', $normalized);
            })
            ->with('client')
            ->get();

        if ($candidates->isEmpty()) {
            return null;
        }
        if ($candidates->count() === 1) {
            return $candidates->first();
        }
        return $this->pickBestPersonCandidate($candidates);
    }

    /**
     * Score: primary contact (+100), active (+50), recent ticket (+0–30).
     */
    private function pickBestPersonCandidate(Collection $candidates): Person
    {
        return $candidates->sortByDesc(function (Person $person) {
            $score = 0;
            if ($person->is_primary) $score += 100;
            if ($person->is_active)  $score += 50;
            $latest = $person->tickets()->orderByDesc('opened_at')->value('opened_at');
            if ($latest) {
                $score += max(0, 30 - now()->diffInDays($latest));
            }
            return $score;
        })->first();
    }

    /**
     * Link an existing call to a PSA ticket.
     * Sets billability from triage if classification exists, otherwise leaves
     * it null — the triage pipeline will set it after classification runs.
     */
    public function linkCallToTicket(PhoneCall $call, int $ticketId): PhoneCall
    {
        $call->ticket_id = $ticketId;

        // Only set billability if triage has already classified this ticket
        if ($call->is_billable === null) {
            $ticket = Ticket::with('latestTriageRun')->find($ticketId);
            if ($ticket?->latestTriageRun?->stageResult('classification')) {
                $call->is_billable = app(TicketService::class)->defaultBillable($ticket);
            }
        }

        $call->save();

        // Trigger prepay debit only if billability is determined
        if ($call->is_billable && $call->duration) {
            app(PrepayService::class)->debitFromPhoneCall($call);
        }

        return $call;
    }

    /**
     * Unlink a call from its ticket, reversing any prepay debit.
     */
    public function unlinkCallFromTicket(PhoneCall $call): PhoneCall
    {
        app(PrepayService::class)->reverseDebitForPhoneCall($call);

        $call->ticket_id = null;
        $call->is_billable = null;
        $call->save();

        return $call;
    }

    /**
     * Toggle billability on a phone call and update prepay accordingly.
     */
    public function setBillable(PhoneCall $call, bool $billable): PhoneCall
    {
        $call->is_billable = $billable;
        $call->save();

        // Re-evaluate prepay: debitFromPhoneCall handles both create and reverse
        app(PrepayService::class)->debitFromPhoneCall($call);

        return $call;
    }

    /**
     * Mark a missed/voicemail call as followed up.
     */
    public function markFollowedUp(PhoneCall $call, int $userId): PhoneCall
    {
        $call->followed_up_at = now();
        $call->followed_up_by = $userId;
        $call->save();

        return $call;
    }

    /**
     * Get recent calls with optional filters.
     */
    public function getRecentCalls(int $limit = 50, array $filters = []): Collection
    {
        $query = PhoneCall::with(['answeredBy', 'client', 'person', 'ticket'])->orderByDesc('started_at');

        if (!empty($filters['status'])) {
            if ($filters['status'] === 'needs-follow-up') {
                $query->unfollowedUp();
            } else {
                $query->where('status', $filters['status']);
            }
        }

        if (!empty($filters['date_from'])) {
            $query->whereDate('started_at', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->whereDate('started_at', '<=', $filters['date_to']);
        }

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('from_number', 'like', "%{$search}%")
                  ->orWhere('halo_client_name', 'like', "%{$search}%");
            });
        }

        return $query->limit($limit)->get();
    }

    /**
     * Get all People matching this call's phone number for disambiguation.
     */
    public function getCandidateCallers(PhoneCall $call): Collection
    {
        $normalized = PhoneNumber::normalize($call->from_number);
        if (!$normalized) {
            return collect();
        }

        return Person::where(function ($q) use ($normalized) {
                $q->where('phone', $normalized)->orWhere('mobile', $normalized);
            })
            ->with('client')
            ->withCount(['tickets as open_ticket_count' => function ($q) {
                $q->open();
            }])
            ->orderByDesc('is_primary')
            ->orderByDesc('is_active')
            ->get();
    }

    /**
     * Safely update a call record with pessimistic locking.
     * Prevents race conditions when multiple webhooks arrive for the same call.
     */
    private function updateCallSafely(string $callUuid, callable $callback): ?PhoneCall
    {
        try {
            return DB::transaction(function () use ($callUuid, $callback) {
                $call = PhoneCall::where('call_uuid', $callUuid)->lockForUpdate()->first();

                if (!$call) {
                    Log::warning('PhoneCallService: call not found', ['call_uuid' => $callUuid]);
                    return null;
                }

                return $callback($call);
            });
        } catch (\Throwable $e) {
            Log::error('PhoneCallService: failed to update call', [
                'call_uuid' => $callUuid,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
