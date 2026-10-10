<?php

namespace App\Services\ControlD;

use App\Enums\TechnicianTier;
use App\Models\Client;
use App\Models\ControlDOnboardingIntent;
use App\Models\TechnicianActionLog;
use App\Models\User;
use App\Services\Tactical\Actions\ActionRedactor;
use App\Support\ControlDConfig;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * Dark B3: explicit stage + execute, no published verb, job, retry or reconciliation.
 * The unique active_client_id is a durable non-expiring per-client lock. It survives
 * process death and remains held on uncertainty. No cache lease can expire mid-POST.
 * Rejected/bound release it, and so does `released` (a never-admitted intent ended by an
 * Admin release or by the global-profile step's already-enforced no-op); staged/posted/
 * uncertain never self-expire or self-retry.
 */
class ControlDOnboardingStaged
{
    public function __construct(
        private readonly ControlDClient $vendor,
        private readonly ControlDProvisioning $provisioning,
        private readonly ActionRedactor $redactor = new ActionRedactor,
    ) {}

    public function stageOrganization(#[\SensitiveParameter] User $actor, int $clientId, #[\SensitiveParameter] string $name, #[\SensitiveParameter] string $contactEmail, int $requireMfa, string $statsEndpoint): string
    {
        if (trim($name) === '' || trim($name) !== $name || mb_strlen($name) > 255
            || preg_match('/[\x00-\x1f\x7f]/', $name) || ! filter_var($contactEmail, FILTER_VALIDATE_EMAIL)
            || ! in_array($requireMfa, [0, 1], true) || ! preg_match('/\A[A-Za-z0-9_-]{1,255}\z/', $statsEndpoint)) {
            throw new ControlDClientException('Explicit organization name, contact email, MFA choice and analytics region are required.');
        }
        $this->globalProfileSetting();

        return $this->stage($actor, $clientId, 'organization', [
            'name' => $name, 'contact_email' => $contactEmail, 'twofa_req' => $requireMfa, 'stats_endpoint' => $statsEndpoint,
        ]);
    }

    public function stageCode(#[\SensitiveParameter] User $actor, int $clientId, string $icon, #[\SensitiveParameter] ?string $pin = null, #[\SensitiveParameter] ?string $namePrefix = null): string
    {
        return $this->stage($actor, $clientId, 'code', ['icon' => $icon, 'pin' => $pin, 'name_prefix' => $namePrefix]);
    }

    /**
     * Enforce the configured Global Profile on an already-mapped sub-organization whose
     * parent_profile is ABSENT or null. The org PK and profile PK the approver saw are pinned in
     * the payload; staging refuses unless both still equal the client's live mapping and
     * the live setting, and execute() refuses before admission if either has drifted.
     */
    public function stageGlobalProfile(#[\SensitiveParameter] User $actor, int $clientId, string $orgPk, string $profilePk): string
    {
        $this->pinnedStillLive(Client::find($clientId), $orgPk, $profilePk);

        return $this->stage($actor, $clientId, self::GLOBAL_PROFILE, ['org_pk' => $orgPk, 'profile_pk' => $profilePk]);
    }

    /** Drift refusal (definite, pre-admission): the pins must equal the live mapping and setting. */
    private function pinnedStillLive(?Client $client, mixed $orgPk, mixed $profilePk): void
    {
        $global = $this->globalProfileSetting();
        if (! is_string($orgPk) || ! preg_match('/\A[A-Za-z0-9_-]{1,255}\z/', $orgPk)
            || ! is_string($profilePk) || ! preg_match('/\A[A-Za-z0-9_-]{1,255}\z/', $profilePk)
            || $client === null || $client->controld_org_id !== $orgPk || $profilePk !== $global) {
            throw new ControlDClientException('The Control D organization or the configured global profile no longer matches what was approved; nothing was written.');
        }
    }

    public const GLOBAL_PROFILE = 'global-profile';

    /** The intent state a released, never-admitted intent ends in. Terminal; owns nothing. */
    public const RELEASED = 'released';

    public const PROFILE_ENFORCED = 'enforced';

    public const PROFILE_ABSENT = 'absent';

    public const PROFILE_DIFFERENT = 'different';

    /**
     * Read-only: the sub-organization's parent_profile against the configured Global
     * Profile — PROFILE_ENFORCED (equal), PROFILE_ABSENT (unset: the key ABSENT, the only
     * documented shape, or PRESENT with JSON null, the shape observed live on 2026-10-09;
     * ControlDSubOrganizations::parentProfile()) or
     * PROFILE_DIFFERENT (another PK is already enforced; staging and approval refuse on it).
     * Throws ControlDClientException, so unknown is never "enforced", on every arm:
     * before any request, when the configured global profile setting is missing or not a
     * valid PK (globalProfileSetting()) or Control D is disabled or unconfigured
     * (available()); and after the GET, when the request fails in transport or its
     * response is not a confirmed success (requestParent()), the inventory is malformed or
     * does not list the org exactly once (ControlDSubOrganizations::row()), or the org's
     * parent_profile is malformed (ControlDSubOrganizations::parentProfile()).
     */
    public function globalProfileState(string $orgPk): string
    {
        $global = $this->globalProfileSetting();
        $this->available();
        $current = (new ControlDSubOrganizations($this->vendor))->parentProfileOf($orgPk);

        return $current === null ? self::PROFILE_ABSENT : ($current === $global ? self::PROFILE_ENFORCED : self::PROFILE_DIFFERENT);
    }

    private function globalProfileSetting(): string
    {
        $global = ControlDConfig::defaultProfileId();
        if ($global === null || ! preg_match('/\A[A-Za-z0-9_-]{1,255}\z/', $global)) {
            throw new ControlDClientException(ControlDConfig::DEFAULT_PROFILE_SETTING.' is required to onboard.');
        }

        return $global;
    }

    /**
     * Admin-only, audited release of a NEVER-ADMITTED intent of $clientId: state staged,
     * phase preflight, no vendor PK, still holding that client's lock. One conditional
     * UPDATE carries every one of those predicates, so an admit() that commits first leaves
     * nothing for it to match and a posted/uncertain/bound/rejected row is never touched.
     * A release racing an in-flight execute() is safe the other way too: admit() is itself
     * a guarded UPDATE (state staged AND active_client_id = client_id), so once a release
     * commits, admission matches 0 rows and refuses before any vendor write. The audit row
     * (reason through ActionRedactor) is written in the same transaction: no audit, no release.
     */
    public function release(#[\SensitiveParameter] User $actor, int $clientId, string $intentId, string $reason): void
    {
        $this->independent();
        $this->authorize($actor);
        $this->authorize(User::find($actor->getKey()));
        $reason = mb_substr(trim($reason), 0, 500);
        if ($reason === '') {
            throw new ControlDClientException('A reason is required to release a Control D intent.');
        }
        if (! $this->releaseNeverAdmitted($intentId, $clientId, (int) $actor->getKey(), 'staff:'.$actor->getKey(), 'released by admin #'.$actor->getKey(),
            "released never-admitted staged intent for client #{$clientId} by admin #{$actor->getKey()}: {$reason}")) {
            throw new ControlDClientException(self::RELEASE_REFUSAL);
        }
    }

    public const RELEASE_REFUSAL = 'Only a never-admitted staged Control D intent of this client can be released; nothing was changed.';

    /**
     * The ONE release predicate (Admin release and the already-enforced no-op): the row
     * is this client's, staged, preflight, has no vendor PK and still holds this client's
     * lock. Returns false when the guarded UPDATE matched no row (nothing changed, no
     * audit). On a match, the controld_release_intent audit row commits in the same
     * transaction; if it cannot be written the release rolls back and this throws.
     */
    private function releaseNeverAdmitted(string $intentId, int $clientId, int $actorId, string $actorLabel, string $rowReason, string $summary): bool
    {
        return (new Client)->getConnection()->transaction(function () use ($intentId, $clientId, $actorId, $actorLabel, $rowReason, $summary): bool {
            $released = ControlDOnboardingIntent::whereKey($intentId)->where('client_id', $clientId)
                ->where('active_client_id', $clientId)->where('state', 'staged')
                ->where('phase', 'preflight')->whereNull('vendor_pk')
                ->update(['state' => self::RELEASED, 'active_client_id' => null, 'reason' => $rowReason, 'updated_at' => now()]);
            if ($released !== 1) {
                return false;
            }
            TechnicianActionLog::create([
                'actor_id' => $actorId, 'approver_user_id' => $actorId,
                'actor_label' => $actorLabel, 'action_type' => 'controld_release_intent',
                'tier' => TechnicianTier::Approve->value, 'result_status' => 'executed',
                'client_id' => Client::whereKey($clientId)->exists() ? $clientId : null,
                'content_hash' => hash('sha256', 'controld_release_intent:'.$intentId),
                'summary' => mb_substr($this->redactor->redactString("controld-intent:{$intentId}: {$summary}"), 0, 1000),
                'correlation_id' => (string) Str::uuid(),
            ]);

            return true;
        }, 1);
    }

    private function independent(): void
    {
        if ((new Client)->getConnection()->transactionLevel() > 0) {
            throw new ControlDClientException('Onboarding requires an independent transaction.');
        }
    }

    private function authorize(#[\SensitiveParameter] ?User $actor): void
    {
        if ($actor === null || ! $actor->exists || ! $actor->is_active || ! $actor->isAdmin()) {
            throw new ControlDClientException('Onboarding requires an active Admin user.');
        }
    }

    private function eligible(?Client $client, string $operation): void
    {
        if ($client === null || ($operation === 'organization' && $client->controld_org_id !== null)
            || ($operation === self::GLOBAL_PROFILE && (! is_string($client->controld_org_id) || trim($client->controld_org_id) === ''))
            || ($operation === 'code' && (! is_string($client->controld_org_id) || trim($client->controld_org_id) === ''
                || $client->getRawOriginal('controld_provisioning_code') !== null || $client->getRawOriginal('controld_deactivation_pin') !== null))) {
            throw new ControlDClientException('Client is missing, deleted or already has conflicting Control D state.');
        }
    }

    private function available(): void
    {
        if (! ControlDConfig::isEnabled() || ! $this->vendor->isConfigured()) {
            throw new ControlDClientException('Control D is disabled or unconfigured.');
        }
    }

    private function stage(#[\SensitiveParameter] User $actor, int $clientId, string $operation, #[\SensitiveParameter] array $payload): string
    {
        $this->independent();
        $this->authorize($actor);
        $this->authorize(User::find($actor->getKey()));
        $this->available();
        $this->eligible(Client::find($clientId), $operation);
        $intent = new ControlDOnboardingIntent;
        $intent->forceFill([
            'id' => (string) Str::uuid(), 'client_id' => $clientId, 'actor_id' => $actor->getKey(),
            'active_client_id' => $clientId, 'operation' => $operation, 'state' => 'staged',
            'phase' => 'preflight', 'payload' => $payload,
        ]);
        try {
            // Autocommit unique INSERT is the lock acquisition. A loser is refused,
            // never queued for execution after the winner, even if the winner crashes.
            $intent->saveOrFail();
        } catch (UniqueConstraintViolationException) {
            throw new ControlDClientException('A Control D onboarding intent already owns this client; do not retry.');
        } catch (\Throwable) {
            throw new ControlDClientException('Control D intent could not be durably staged; no vendor write was made.');
        }

        return $intent->id;
    }

    /** Returns only the durable id. Read the internal evidence row for the outcome. */
    public function execute(#[\SensitiveParameter] User $actor, string $intentId): string
    {
        $this->independent();
        $this->authorize($actor);
        $this->authorize(User::find($actor->getKey()));
        $this->available();
        $intent = ControlDOnboardingIntent::find($intentId);
        if ($intent === null || $intent->actor_id != $actor->getKey()) {
            throw new ControlDClientException('Control D intent does not belong to this actor.');
        }
        // Cheap definite refusal before any local work or read-only vendor GET; the
        // authoritative atomic one-shot admission is admit(), immediately before the write.
        if ($intent->state !== 'staged') {
            throw new ControlDClientException('Control D intent is not executable; do not retry.');
        }
        try {
            $this->eligible(Client::find($intent->client_id), $intent->operation);
            if ($intent->operation === 'organization') {
                $this->organization($intent, $actor);
            } elseif ($intent->operation === self::GLOBAL_PROFILE) {
                $this->globalProfile($intent, $actor);
            } else {
                $this->code($intent, $actor);
            }
        } catch (ControlDOrganizationUncertainException $e) {
            $this->finish($intent, 'uncertain', $e->phase, $e->orgPk, $e->orgPk);
        } catch (ControlDWriteUncertainException $e) {
            $this->finish($intent, 'uncertain', $e->phase, $e->orgPk, $e->provisionPk);
        } catch (ControlDWriteRejectedException $e) {
            $this->finish($intent, 'rejected', 'post', null, null, $e->reasonCode,
                $e->isReadOnlyKey() ? 'vendor key is read-only' : 'vendor rejected the write', $e->vendorMessage);
        } catch (\Throwable $e) {
            // Refused before admission: no POST can have been issued, so this stays a
            // definite local refusal. The intent remains staged and executable once the
            // cause is fixed, instead of an uncertain row holding the lock indefinitely.
            if ($intent->state !== 'posted') {
                if ($e instanceof ControlDClientException) {
                    throw $e;
                }
                throw new ControlDClientException('Control D intent was refused before admission; no vendor write was made.');
            }
            // Never leak SQL bindings, request PII, secrets or raw vendor text. A
            // durable posted row/lock is retained if even this update fails.
            $this->finish($intent, 'uncertain', $intent->phase, $intent->org_pk, $intent->vendor_pk);
        }

        return $intentId;
    }

    /**
     * Atomic one-shot admission, taken at the last moment before the intended vendor
     * write. posted means MAY have been sent, not success; a crash immediately after
     * this commit requires manual reconciliation too. Everything before it is local
     * validation or a read-only GET, so a refusal there provably precedes any POST.
     */
    private function admit(ControlDOnboardingIntent $intent): void
    {
        $admitted = ControlDOnboardingIntent::whereKey($intent->id)->where('state', 'staged')
            ->where('active_client_id', $intent->client_id)->update(['state' => 'posted', 'phase' => 'post', 'updated_at' => now()]);
        if ($admitted !== 1) {
            throw new ControlDClientException('Control D intent is not executable; do not retry.');
        }
        // In-memory state matches the durable row before anything is sent, so a later
        // failure is classified as post-admission even if this read-back itself fails.
        $intent->forceFill(['state' => 'posted', 'phase' => 'post']);
        $intent->refresh();
    }

    /**
     * $reasonDetail is the sanitized vendor `error.message`, written only on a `rejected`
     * outcome. Every other outcome leaves the reason_detail column out of the UPDATE entirely,
     * so it still records if the code is served before that column's migration has run.
     */
    private function finish(ControlDOnboardingIntent $intent, string $state, string $phase, ?string $orgPk, ?string $pk, ?int $reasonCode = null, ?string $reason = null, #[\SensitiveParameter] ?string $reasonDetail = null): void
    {
        try {
            $intent->forceFill(['state' => $state, 'phase' => $phase, 'org_pk' => $orgPk, 'vendor_pk' => $pk,
                'reason_code' => $reasonCode, 'reason' => $reason ?? 'outcome requires reconciliation; do not retry',
                'active_client_id' => $state === 'rejected' ? null : $intent->client_id]
                + ($state === 'rejected' ? ['reason_detail' => $reasonDetail] : []))->saveOrFail();
        } catch (\Throwable) {
            throw new ControlDClientException('Control D intent outcome could not be recorded; do not retry.');
        }
    }

    private function organization(ControlDOnboardingIntent $intent, #[\SensitiveParameter] User $actor): void
    {
        $payload = $intent->payload;
        // Pre-admission: no setting, no POST (a definite local refusal).
        $global = $this->globalProfileSetting();
        $this->admit($intent);
        $response = $this->vendor->requestParent('POST', 'organizations/suborg', [...$payload, 'parent_profile' => $global]);
        $row = $response['body']->organization ?? null;
        if (! $row instanceof \stdClass || ! is_string($row->PK ?? null)
            || ! preg_match('/\A[A-Za-z0-9_-]{1,255}\z/', $row->PK)) {
            throw new ControlDOrganizationUncertainException(null, 'post');
        }
        $pk = $row->PK;
        // Commit the orphan handle before any further I/O; failed persistence never
        // licenses another POST. Stored intent remains posted if this write fails.
        $intent->forceFill(['org_pk' => $pk, 'vendor_pk' => $pk, 'phase' => 'readback'])->saveOrFail();
        $response = $this->vendor->requestParent('GET', 'organizations/sub_organizations');
        $rows = $response['body']->sub_organizations ?? null;
        if (! is_array($rows)) {
            throw new ControlDOrganizationUncertainException($pk, 'readback');
        }
        $matches = 0;
        foreach ($rows as $listed) {
            if (! $listed instanceof \stdClass || ! is_string($listed->PK ?? null) || ! is_string($listed->name ?? null)) {
                throw new ControlDOrganizationUncertainException($pk, 'readback');
            }
            if ($listed->PK === $pk) {
                if ($listed->name !== $payload['name']) {
                    throw new ControlDOrganizationUncertainException($pk, 'readback');
                }
                try {
                    $listedGlobal = ControlDSubOrganizations::parentProfile($listed);
                } catch (ControlDClientException) {
                    throw new ControlDOrganizationUncertainException($pk, 'readback');
                }
                if ($listedGlobal !== $global) {
                    throw new ControlDOrganizationUncertainException($pk, 'readback');
                }
                $matches++;
            }
        }
        if ($matches !== 1 || strlen($pk) > 50) {
            throw new ControlDOrganizationUncertainException($pk, 'readback');
        }
        $intent->phase = 'local-persistence';
        $this->bind($intent, $actor, $pk, null);
    }

    /**
     * Fills a parent_profile that is ABSENT or null at the pre-admission read; a DIFFERENT one seen
     * there refuses with no write. This code sends an unconditional PUT, so a profile set
     * after that read is not detected and the PUT may replace it; nothing here measures
     * what Control D does then. Only ControlDClient's ControlDWriteRejectedException (an
     * HTTP 4xx carrying the vendor error envelope) ends the intent `rejected`; every other
     * response requestForOrg() refuses (a 4xx without the envelope; a 1xx, 3xx or 5xx,
     * since redirects are not followed; a 2xx whose body is not JSON or does not carry
     * success true), an unknown outcome or a read-back that does not show the configured
     * profile ends it uncertain. If finish() cannot save that outcome, or the process
     * stops after admit() and before the outcome is recorded, the row stays `posted`;
     * never retried either way. PUT organizations under X-Force-Org-Id (Modify
     * Organization: the sub-org itself) with the PINNED profile PK under the PINNED org,
     * then the parent's GET sub_organizations read-back. Before admission: pin drift, an
     * unreadable inventory, or a DIFFERENT parent_profile already set are definite
     * refusals with no write; already enforced is a no-op that releases the intent. One
     * PUT, never retried: a definite HTTP 4xx vendor envelope rejection is `rejected`; any other
     * failure after admission, a read-back that does not show the profile, or a failed
     * bind() re-check, is uncertain and terminal.
     */
    private function globalProfile(ControlDOnboardingIntent $intent, #[\SensitiveParameter] User $actor): void
    {
        $payload = $intent->payload;
        $this->pinnedStillLive(Client::find($intent->client_id), $payload['org_pk'] ?? null, $payload['profile_pk'] ?? null);
        $orgPk = $payload['org_pk'];
        $global = $payload['profile_pk'];
        $intent->org_pk = $orgPk;
        $intent->saveOrFail();
        $inventory = new ControlDSubOrganizations($this->vendor);
        // Read-only pre-admission check: listed exactly once, and parent_profile absent or null.
        $current = $inventory->parentProfileOf($orgPk);
        if ($current === $global) {
            // Definite no-op: no write is needed or made. The never-admitted intent ends
            // released through the SAME guarded predicate and audit as release().
            if (! $this->releaseNeverAdmitted((string) $intent->id, (int) $intent->client_id, (int) $actor->getKey(), 'system:controld-global-profile-noop', 'already enforced; no vendor write',
                'global-profile step found the configured global profile already enforced after a read-only check; intent released as a system no-op; no vendor write was made')) {
                throw new ControlDClientException('The Control D global profile is already enforced on this organization; no vendor write was made, but the intent no longer matched a never-admitted staged intent of this client and was not released.');
            }
            throw new ControlDClientException('The Control D global profile is already enforced on this organization; no vendor write was made and the intent was released.');
        }
        if ($current !== null) {
            throw new ControlDClientException(self::DIFFERENT_PROFILE_REFUSAL);
        }
        $this->admit($intent);
        try {
            $this->vendor->requestForOrg('PUT', 'organizations', $orgPk, ['parent_profile' => $global]);
        } catch (ControlDWriteRejectedException $e) {
            throw $e;
        } catch (\Throwable) {
            throw new ControlDOrganizationUncertainException($orgPk, 'post');
        }
        try {
            $intent->forceFill(['phase' => 'readback'])->saveOrFail();
            $confirmed = $inventory->parentProfileOf($orgPk) === $global;
        } catch (\Throwable) {
            throw new ControlDOrganizationUncertainException($orgPk, 'readback');
        }
        if (! $confirmed) {
            throw new ControlDOrganizationUncertainException($orgPk, 'readback');
        }
        $intent->phase = 'local-persistence';
        // Same locked posted->bound transition and re-checks as every other step; a
        // failure here is post-admission, so it is uncertain and terminal.
        $this->bind($intent, $actor, $orgPk, null);
    }

    public const DIFFERENT_PROFILE_REFUSAL = 'This Control D sub-organization already enforces a different global profile, so onboarding refused and nothing was written to Control D. Change it in Control D, or change the configured default, before onboarding continues. A different profile is detected only when onboarding reads it: the global-profile update this code sends is unconditional, so one set after the last read before that update would not be detected.';

    private function code(ControlDOnboardingIntent $intent, #[\SensitiveParameter] User $actor): void
    {
        $client = Client::find($intent->client_id);
        $payload = $intent->payload;
        $fields = $this->codeFields($client, $payload);
        $intent->org_pk = $client->controld_org_id;
        $intent->saveOrFail();
        $intentId = $intent->id;
        $created = $this->provisioning->create($intent->org_pk, $fields, $payload['pin'], static function (string $pk) use ($intentId): void {
            if (ControlDOnboardingIntent::whereKey($intentId)->where('state', 'posted')->update([
                'vendor_pk' => $pk, 'phase' => 'read-back', 'updated_at' => now(),
            ]) !== 1) {
                throw new ControlDClientException('Control D intent checkpoint failed.');
            }
        }, fn () => $this->admit($intent));
        $intent->forceFill(['vendor_pk' => $created['PK'], 'phase' => 'local-persistence'])->saveOrFail();
        $this->bind($intent, $actor, $intent->org_pk, $created);
    }

    private function bind(ControlDOnboardingIntent $intent, #[\SensitiveParameter] User $actor, string $orgPk, #[\SensitiveParameter] ?array $created): void
    {
        // Secret-bearing parameters are annotated, not captured in a transaction
        // callback. One attempt only; nothing vendor-facing is inside this section.
        $connection = (new Client)->getConnection();
        $connection->beginTransaction();
        try {
            $locked = ControlDOnboardingIntent::whereKey($intent->id)->lockForUpdate()->firstOrFail();
            if ($locked->state !== 'posted' || $locked->active_client_id != $intent->client_id) {
                throw new ControlDClientException('Control D intent ownership changed.');
            }
            $client = Client::query()->lockForUpdate()->find($intent->client_id);
            $this->authorize(User::query()->lockForUpdate()->find($actor->getKey()));
            $this->eligible($client, $intent->operation);
            if ($intent->operation === self::GLOBAL_PROFILE) {
                // Nothing local changes; the mapping must still be the org that was PUT.
                if ($client->controld_org_id !== $orgPk) {
                    throw new ControlDClientException('Control D organization mapping changed.');
                }
            } elseif ($created === null) {
                if (Client::withTrashed()->where('controld_org_id', $orgPk)->exists()) {
                    throw new ControlDClientException('Control D organization is already mapped.');
                }
                $client->controld_org_id = $orgPk;
            } else {
                if ($client->controld_org_id !== $orgPk) {
                    throw new ControlDClientException('Control D organization mapping changed.');
                }
                $client->controld_provisioning_code = $created['code'];
                $client->controld_deactivation_pin = $created['deactivation_pin'];
            }
            if ($intent->operation !== self::GLOBAL_PROFILE && ! $client->save()) {
                throw new ControlDClientException('Control D persistence was refused.');
            }
            $locked->forceFill(['state' => 'bound', 'phase' => 'local-persistence', 'org_pk' => $orgPk,
                'vendor_pk' => $created['PK'] ?? $orgPk, 'active_client_id' => null,
                'reason' => null, 'reason_code' => null])->saveOrFail();
            $connection->commit();
        } catch (\Throwable) {
            $connection->rollBack();
            if ($created === null) {
                throw new ControlDOrganizationUncertainException($orgPk, 'local-persistence');
            }
            throw new ControlDWriteUncertainException($orgPk, $created['PK'], 'local-persistence');
        }
    }

    private function codeFields(Client $client, #[\SensitiveParameter] array $payload): array
    {
        $profile = ControlDConfig::defaultProfileId();
        $days = ControlDConfig::codeExpiryDays();
        $headroom = ControlDConfig::codeDeviceLimitHeadroom();
        $stats = ControlDConfig::codeAnalyticsLevel();
        $intercept = ControlDConfig::codeInterceptMode();
        foreach ([
            ControlDConfig::TACTICAL_CLIENT_ORG_FIELD_SETTING => ControlDConfig::tacticalClientOrgFieldId(),
            ControlDConfig::DEFAULT_PROFILE_SETTING => $profile,
            ControlDConfig::CODE_EXPIRY_DAYS_SETTING => $days,
            ControlDConfig::CODE_DEVICE_LIMIT_HEADROOM_SETTING => $headroom,
            ControlDConfig::CODE_ANALYTICS_LEVEL_SETTING => $stats,
            ControlDConfig::CODE_INTERCEPT_MODE_SETTING => $intercept,
        ] as $setting => $value) {
            if ($value === null) {
                throw new ControlDClientException($setting.' is required to onboard.');
            }
        }
        if (! preg_match('/\A[012]\z/', $stats)) {
            throw new ControlDClientException('controld_code_analytics_level must be exactly 0, 1 or 2.');
        }
        $count = $client->assets()->count();
        if ($headroom < 0 || $count > 10000 || $headroom > 10000 - $count || $count + $headroom < 1) {
            throw new ControlDClientException('Asset count plus controld_code_device_limit_headroom must be 1..10000.');
        }
        $now = now()->getTimestamp();
        $ceiling = min(PHP_INT_MAX, 253402300799);
        if ($now < 0 || $now >= $ceiling || $days > intdiv($ceiling - $now, 86400)) {
            throw new ControlDClientException('controld_code_expiry_days exceeds the supported timestamp range.');
        }
        $fields = ['icon' => $payload['icon'], 'profile_id' => $profile, 'max' => $count + $headroom,
            'ts_exp' => $now + $days * 86400, 'stats' => (int) $stats, 'intercept_mode' => $intercept];
        if ($payload['name_prefix'] !== null) {
            $fields['name_prefix'] = $payload['name_prefix'];
        }

        return $fields;
    }
}
