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

    /**
     * $pins (P7A74iGD (a), item E): the derived code values the approver saw — max, expiry_days,
     * stats, intercept_mode, profile_id — exactly as codePins() returns them. When given, they
     * are what executes (codeFields() uses them instead of the live settings and asset count);
     * the cockpit approval refuses before calling this when they differ from a fresh
     * derivation. Null keeps the live derivation (direct service callers only).
     */
    public function stageCode(#[\SensitiveParameter] User $actor, int $clientId, string $icon, #[\SensitiveParameter] ?string $pin = null, #[\SensitiveParameter] ?string $namePrefix = null, ?array $pins = null): string
    {
        if ($pins !== null && ! self::wellFormedCodePins($pins)) {
            throw new ControlDClientException('The pinned provisioning code values are malformed; nothing was written.');
        }

        return $this->stage($actor, $clientId, 'code', ['icon' => $icon, 'pin' => $pin, 'name_prefix' => $namePrefix] + ($pins !== null ? ['pins' => $pins] : []));
    }

    /** The five keys a code pin set carries, in a fixed order (codePins()). */
    public const CODE_PIN_KEYS = ['max', 'expiry_days', 'stats', 'intercept_mode', 'profile_id'];

    /**
     * The derived values a code step would execute with NOW, from the live settings and the
     * client's asset count, with codeFields()'s own checks (throws ControlDClientException
     * on a missing or out-of-range value). Read-only: no vendor call, no write. No secret.
     *
     * @return array{max: int, expiry_days: int, stats: int, intercept_mode: string, profile_id: string}
     */
    public static function codePins(Client $client): array
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

        return ['max' => $count + $headroom, 'expiry_days' => $days, 'stats' => (int) $stats, 'intercept_mode' => $intercept, 'profile_id' => $profile];
    }

    /** Exactly the five keys, each of codePins()'s type. Values are range-checked again by codeFields(). */
    public static function wellFormedCodePins(mixed $pins): bool
    {
        return is_array($pins) && array_keys($pins) === self::CODE_PIN_KEYS
            && is_int($pins['max']) && is_int($pins['expiry_days']) && is_int($pins['stats'])
            && is_string($pins['intercept_mode']) && is_string($pins['profile_id']);
    }

    /**
     * Stage invalidation of the client's BOUND provisioning code. $codePk must be the PK the
     * client's bound code intent recorded (boundCodePk()); it is never looked up at the vendor
     * by value. The org PK and code PK are pinned and re-checked at execution.
     */
    public function stageInvalidate(#[\SensitiveParameter] User $actor, int $clientId, string $orgPk, string $codePk): string
    {
        $this->invalidatePinsStillLive(Client::find($clientId), $orgPk, $codePk);

        return $this->stage($actor, $clientId, self::INVALIDATE, ['org_pk' => $orgPk, 'code_pk' => $codePk]);
    }

    public const INVALIDATE = 'invalidate';

    /**
     * The PK onboarding last recorded for the client's stored provisioning code: the vendor_pk
     * of its most recent `bound` code intent under the client's CURRENT org, unless a `bound`
     * invalidate intent of this client already spent that PK; otherwise null (a code stored by
     * another path, a spent PK, or no code). Read-only, local only: it does NOT prove the
     * stored code is that record; invalidateCode() proves that at Control D before any write.
     */
    public static function boundCodePk(Client $client): ?string
    {
        if (! is_string($client->controld_org_id) || $client->controld_org_id === ''
            || $client->getRawOriginal('controld_provisioning_code') === null) {
            return null;
        }
        $pk = ControlDOnboardingIntent::where('client_id', $client->id)->where('operation', 'code')->where('state', 'bound')
            ->where('org_pk', $client->controld_org_id)->latest('updated_at')->latest('id')->value('vendor_pk');

        if (! is_string($pk) || ! preg_match('/\A[A-Za-z0-9_-]{1,255}\z/', $pk)) {
            return null;
        }

        return ControlDOnboardingIntent::where('client_id', $client->id)->where('operation', self::INVALIDATE)->where('state', 'bound')
            ->where('vendor_pk', $pk)->exists() ? null : $pk;
    }

    private function invalidatePinsStillLive(?Client $client, mixed $orgPk, mixed $codePk): void
    {
        if ($client === null || ! is_string($orgPk) || ! is_string($codePk) || $client->controld_org_id !== $orgPk
            || self::boundCodePk($client) !== $codePk) {
            throw new ControlDClientException('The client\'s Control D organization or stored provisioning code no longer matches what was approved; nothing was written.');
        }
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

    public const RECONCILE_ACTION = 'controld_reconcile_intent';

    public const RECONCILED_BOUND = 'bound';

    public const RECONCILED_RELEASED = 'released';

    public const RECONCILED_REFUSED = 'refused';

    /** Fixed refusal texts (status only, never vendor text). Each must be true on every path that emits it (G-14). */
    private const RECONCILE_REFUSALS = [
        'ineligible' => 'Only an uncertain organization, global-profile or code intent of this client that still holds its onboarding lock can be reconciled here; nothing was changed. An uncertain invalidate intent, or a posted intent (its write may still be in flight, or the process stopped after it), is held for a by-hand ruling: there is no in-app way out for it yet.',
        'unavailable' => 'Control D is disabled or unconfigured, so nothing was read; nothing was changed.',
        'read-failed' => 'Control D could not be read (a failed, unconfirmed or malformed response), or this intent\'s stored payload could not be read; nothing was changed and the intent keeps its lock.',
        'setting-missing' => 'The configured global profile setting is missing or malformed, so the organization could not be compared; Control D was not read, nothing was changed and the intent keeps its lock.',
        'ambiguous' => 'Control D shows more than one candidate, or something besides what this intent would have created; nothing was changed and the intent keeps its lock.',
        'mismatch' => 'Control D shows a record that differs on what reconcile compares for this step (organization: the name, the recorded PK and the currently configured global profile; global profile: the profile this intent sent; code: the fields this intent sent), or this intent lacks what that comparison needs; nothing was changed and the intent keeps its lock.',
        'inactive' => 'Control D lists this intent\'s code record, but not as an active code: its status is not 1 (active), or its expired flag is not 0 (an invalidated code, for example, has status -1). Why it is not active is not determined here; nothing was changed, the intent keeps its lock and is held for a by-hand ruling.',
        'cannot-reconstruct' => 'This code intent was recorded before the exact fields it sent were kept, so a match cannot be rebuilt exactly from a read; nothing was changed and the intent keeps its lock (cannot reconstruct; leave held).',
        'not-saved' => 'The outcome could not be saved locally, so it was rolled back and nothing was changed by this reconcile. Possible causes: the intent, the client or your Admin rights changed while Control D was being read; the organization is already mapped to another client; or the client record or the audit row could not be written.',
        'unlisted' => 'Reconcile refused this intent; nothing was changed.',
    ];

    /** The bound message names, per operation, exactly what was compared (G-14). */
    private const RECONCILE_BOUND_MATCH = [
        'organization' => 'Control D lists exactly one sub-organization with this intent\'s name (and its recorded PK, when one was recorded), carrying the currently configured global profile, so it was bound. The contact email, MFA and stats settings this intent sent were not compared.',
        self::GLOBAL_PROFILE => 'Control D shows the organization\'s global profile equal to the profile this intent sent, so it was bound.',
        'code' => 'Control D lists this intent\'s code record once, active, with the enforced profile, device limit, expiry, analytics level, intercept mode, icon, prefix and PIN this intent sent, so it was bound.',
    ];

    /**
     * P7A74iGD (a): Admin-only, audited, GET-only reconcile of ONE uncertain intent of
     * $clientId that still holds the client's lock. A posted intent is refused: admit() sets
     * posted just before the write and finish() records the outcome after it, so a read could
     * race a write still in flight; it needs a ruling by hand. NO vendor write is made on any arm and
     * nothing is retried. Every list below is the one a single GET returns, treated as the
     * complete list: no paging or truncation marker is checked (none appears in the recorded
     * fixtures). Per operation (reads only):
     *  - organization: the parent's sub-organization list (ControlDSubOrganizations::rows()).
     *    With a recorded vendor PK: exactly one row with that PK, named as the payload, carrying
     *    the configured global profile, and no other row with the payload name = match; no row
     *    with the PK or the name = absent. Without one: exactly one row with the payload name (and
     *    the configured global profile) = match; none = absent; more than one = ambiguous.
     *  - global-profile: the org's row listed exactly once (ControlDSubOrganizations::row());
     *    parent_profile === the payload's profile_pk = match; unset = absent; any other = refuse.
     *  - code: the org's provisioning list (ControlDProvisioning::provisions()). With a
     *    recorded vendor PK: exactly one row with it, active and equal to the fields this intent
     *    sent (ControlDProvisioning::confirmExisting(), the create() read-back checks) = match; no
     *    row with it = absent; listed but not active (status not 1 or expired flag not 0, for
     *    example invalidated, status -1) = refused as not active, cause not named (held). Without one:
     *    a well-formed EMPTY list = absent; any row = ambiguous.
     *    A failed, unconfirmed or malformed list always refuses: a lost code is worse than a held
     *    lock. An intent that did not keep its sent fields cannot be matched (refused, held).
     * Match: bind()'s own end state (applyBoundEndState()) in ONE transaction that locks the
     * intent and the client FOR UPDATE and re-checks state, lock, client and payload identity.
     * Absent: released, through the guarded-UPDATE pattern of releaseNeverAdmitted() with state
     * uncertain. Anything else: refused, nothing changed. A bound or released outcome writes
     * one TechnicianActionLog row (ids and status only) in the same transaction as the change;
     * a refusal writes its own row, and a failure to write that refusal row is logged (ids and
     * exception class), not retried (ticketed).
     *
     * @return array{outcome: string, message: string}
     */
    public function reconcile(#[\SensitiveParameter] User $actor, int $clientId, string $intentId): array
    {
        $this->independent();
        $this->authorize($actor);
        $this->authorize(User::find($actor->getKey()));
        $actorId = (int) $actor->getKey();
        $intent = ControlDOnboardingIntent::whereKey($intentId)->where('client_id', $clientId)->first();
        if ($intent === null || $intent->state !== 'uncertain' || (int) $intent->active_client_id !== $clientId
            || ! in_array($intent->operation, ['organization', self::GLOBAL_PROFILE, 'code'], true)) {
            return $this->reconcileRefused($intentId, $clientId, $actorId, $intent?->operation ?? 'unknown', 'ineligible');
        }
        $operation = (string) $intent->operation;
        if (! ControlDConfig::isEnabled() || ! $this->vendor->isConfigured()) {
            return $this->reconcileRefused($intentId, $clientId, $actorId, $operation, 'unavailable');
        }
        $snapshot = $this->intentIdentity($intent);
        try {
            $payload = $intent->payload;
            [$verdict, $orgPk, $created] = match ($operation) {
                'organization' => $this->reconcileOrganization($intent, is_array($payload) ? $payload : []),
                self::GLOBAL_PROFILE => $this->reconcileGlobalProfile($intent, is_array($payload) ? $payload : []),
                default => $this->reconcileCode($intent, is_array($payload) ? $payload : []),
            };
        } catch (\Throwable) {
            return $this->reconcileRefused($intentId, $clientId, $actorId, $operation, 'read-failed');
        }
        if ($verdict !== 'match' && $verdict !== 'absent') {
            return $this->reconcileRefused($intentId, $clientId, $actorId, $operation, $verdict);
        }

        $connection = (new Client)->getConnection();
        $connection->beginTransaction();
        try {
            $locked = ControlDOnboardingIntent::whereKey($intentId)->lockForUpdate()->first();
            $client = Client::query()->lockForUpdate()->find($clientId);
            if ($locked === null || $client === null || $this->intentIdentity($locked) !== $snapshot
                || $locked->state !== 'uncertain' || (int) $locked->active_client_id !== $clientId) {
                throw new ControlDClientException('not-saved');
            }
            $this->authorize(User::query()->lockForUpdate()->find($actorId));
            if ($verdict === 'match') {
                $this->applyBoundEndState($locked, $client, $operation, $orgPk, $created, ['uncertain']);
            } else {
                $released = ControlDOnboardingIntent::whereKey($intentId)->where('client_id', $clientId)
                    ->where('active_client_id', $clientId)->where('state', 'uncertain')
                    ->update(['state' => self::RELEASED, 'active_client_id' => null, 'reason' => 'reconciled absent by admin #'.$actorId, 'updated_at' => now()]);
                if ($released !== 1) {
                    throw new ControlDClientException('not-saved');
                }
            }
            $outcome = $verdict === 'match' ? self::RECONCILED_BOUND : self::RECONCILED_RELEASED;
            $this->reconcileAudit($intentId, $clientId, $actorId, $operation, $outcome, 'executed', $verdict === 'match' ? 'vendor state matches the compared fields' : 'vendor list shows nothing from the intent');
            $connection->commit();
        } catch (\Throwable) {
            $connection->rollBack();

            return $this->reconcileRefused($intentId, $clientId, $actorId, $operation, 'not-saved');
        }

        return ['outcome' => $outcome, 'message' => $outcome === self::RECONCILED_BOUND
            ? self::RECONCILE_BOUND_MATCH[$operation].' The client record now holds the same state a confirmed write would have written. No Control D write was made.'
            : 'Control D\'s list (one read, treated as complete) shows nothing from this intent, so it was released and the client\'s onboarding lock is free. No Control D write was made; stage the step again to retry it.'];
    }

    /** What a reconcile must find unchanged under its lock: identity, operation, payload ciphertext, PKs. */
    private function intentIdentity(ControlDOnboardingIntent $intent): array
    {
        return [(string) $intent->id, (int) $intent->client_id, (string) $intent->operation, (string) $intent->getRawOriginal('payload'),
            $intent->getRawOriginal('org_pk'), $intent->getRawOriginal('vendor_pk')];
    }

    /** @return array{0: string, 1: ?string, 2: ?array} */
    private function reconcileOrganization(ControlDOnboardingIntent $intent, array $payload): array
    {
        $name = $payload['name'] ?? null;
        if (! is_string($name) || $name === '') {
            return ['mismatch', null, null];
        }
        try {
            $global = $this->globalProfileSetting();
        } catch (ControlDClientException) {
            return ['setting-missing', null, null];
        }
        $recorded = $intent->vendor_pk;
        $byName = [];
        $byPk = [];
        foreach ((new ControlDSubOrganizations($this->vendor))->rows() as $row) {
            if ($row->name === $name) {
                $byName[] = $row;
            }
            if ($recorded !== null && $row->PK === $recorded) {
                $byPk[] = $row;
            }
        }
        if ($recorded !== null) {
            if ($byPk === [] && $byName === []) {
                return ['absent', null, null];
            }
            if (count($byPk) !== 1 || count($byName) !== 1 || $byPk[0] !== $byName[0]) {
                return ['ambiguous', null, null];
            }
            $row = $byPk[0];
        } else {
            if ($byName === []) {
                return ['absent', null, null];
            }
            if (count($byName) !== 1) {
                return ['ambiguous', null, null];
            }
            $row = $byName[0];
        }
        // organization()'s read-back requirements before its bind(): the configured global
        // profile on the row, and a PK the client column holds.
        if (ControlDSubOrganizations::parentProfile($row) !== $global || strlen($row->PK) > 50
            || ! preg_match('/\A[A-Za-z0-9_-]{1,255}\z/', $row->PK)) {
            return ['mismatch', null, null];
        }

        return ['match', $row->PK, null];
    }

    /** @return array{0: string, 1: ?string, 2: ?array} */
    private function reconcileGlobalProfile(ControlDOnboardingIntent $intent, array $payload): array
    {
        $orgPk = $payload['org_pk'] ?? null;
        $profilePk = $payload['profile_pk'] ?? null;
        if (! is_string($orgPk) || ! is_string($profilePk) || $orgPk === '' || $profilePk === ''
            || ($intent->org_pk !== null && $intent->org_pk !== $orgPk)) {
            return ['mismatch', null, null];
        }
        $current = ControlDSubOrganizations::parentProfile((new ControlDSubOrganizations($this->vendor))->row($orgPk));
        if ($current === null) {
            return ['absent', null, null];
        }

        return $current === $profilePk ? ['match', $orgPk, null] : ['mismatch', null, null];
    }

    /** @return array{0: string, 1: ?string, 2: ?array} */
    private function reconcileCode(ControlDOnboardingIntent $intent, #[\SensitiveParameter] array $payload): array
    {
        $orgPk = $intent->org_pk;
        if (! is_string($orgPk) || $orgPk === '') {
            return ['mismatch', null, null];
        }
        $rows = $this->provisioning->provisions($orgPk);
        $recorded = $intent->vendor_pk;
        if ($recorded === null) {
            // Strict absence (Jeeves addition 2): only a well-formed EMPTY list (the single
            // GET, treated as complete; no paging marker is checked) releases; any row at all could be this intent's code and is ambiguous.
            return $rows === [] ? ['absent', null, null] : ['ambiguous', null, null];
        }
        $matches = array_values(array_filter($rows, static fn (\stdClass $row): bool => $row->PK === $recorded));
        if ($matches === []) {
            return ['absent', null, null];
        }
        if (count($matches) !== 1) {
            return ['ambiguous', null, null];
        }
        if (! is_array($payload['fields'] ?? null) || ! array_key_exists('pin', $payload)) {
            return ['cannot-reconstruct', null, null];
        }
        try {
            $created = $this->provisioning->confirmExisting($matches[0], $recorded, $payload['fields'], $payload['pin']);
        } catch (ControlDClientException) {
            return [($matches[0]->status ?? null) !== 1 || ($matches[0]->expired ?? null) !== 0 ? 'inactive' : 'mismatch', null, null];
        }

        return ['match', $orgPk, $created];
    }

    /** @return array{outcome: string, message: string} */
    private function reconcileRefused(string $intentId, int $clientId, int $actorId, string $operation, string $why): array
    {
        $why = array_key_exists($why, self::RECONCILE_REFUSALS) ? $why : 'unlisted';
        try {
            $this->reconcileAudit($intentId, $clientId, $actorId, $operation, self::RECONCILED_REFUSED, 'blocked', $why);
        } catch (\Throwable $e) {
            // C-56: ids and the exception class only.
            \Illuminate\Support\Facades\Log::error('[ControlDOnboardingStaged] Reconcile refusal audit row failed', ['intent_id' => $intentId, 'client_id' => $clientId, 'exception' => $e::class]);
        }

        return ['outcome' => self::RECONCILED_REFUSED, 'message' => self::RECONCILE_REFUSALS[$why]];
    }

    private function reconcileAudit(string $intentId, int $clientId, int $actorId, string $operation, string $outcome, string $status, string $why): void
    {
        TechnicianActionLog::create([
            'actor_id' => $actorId, 'approver_user_id' => $actorId,
            'actor_label' => 'staff:'.$actorId, 'action_type' => self::RECONCILE_ACTION,
            'tier' => TechnicianTier::Approve->value, 'result_status' => $status,
            'client_id' => Client::whereKey($clientId)->exists() ? $clientId : null,
            'content_hash' => hash('sha256', self::RECONCILE_ACTION.':'.$intentId.':'.$outcome),
            'summary' => mb_substr("controld-intent:{$intentId}: reconcile {$operation} for client #{$clientId} by admin #{$actorId}: {$outcome} ({$why}); no Control D write", 0, 1000),
            'correlation_id' => (string) Str::uuid(),
        ]);
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
                || $client->getRawOriginal('controld_provisioning_code') !== null || $client->getRawOriginal('controld_deactivation_pin') !== null))
            || ($operation === self::INVALIDATE && (! is_string($client->controld_org_id) || trim($client->controld_org_id) === ''
                || $client->getRawOriginal('controld_provisioning_code') === null))
            || ! in_array($operation, ['organization', self::GLOBAL_PROFILE, 'code', self::INVALIDATE], true)) {
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
            } elseif ($intent->operation === self::INVALIDATE) {
                $this->invalidateCode($intent, $actor);
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

    /**
     * P7A74iGD (a): invalidate the client's bound provisioning code. Pre-admission (definite,
     * no write): the pinned org PK and code PK must still be the client's mapping and its
     * bound code PK, and one GET of the org's provisioning list must show that PK exactly once
     * carrying the code the client stores (invalidateTargetIsStoredCode()). Then one PUT
     * provision/{PK}/invalidate and the read-back that must show
     * status -1 (ControlDProvisioning::invalidate()). Any failure after admission (a vendor
     * error or refusal of the PUT, a failed or unconfirmed read-back) is uncertain: the
     * client's code and PIN are KEPT and nothing is retried. Only a confirmed read-back
     * reaches bind(), which nulls both columns in its one guarded transaction.
     */
    private function invalidateCode(ControlDOnboardingIntent $intent, #[\SensitiveParameter] User $actor): void
    {
        $payload = $intent->payload;
        $client = Client::find($intent->client_id);
        $this->invalidatePinsStillLive($client, $payload['org_pk'] ?? null, $payload['code_pk'] ?? null);
        $orgPk = $payload['org_pk'];
        $codePk = $payload['code_pk'];
        // P7A74iGD (a): the recorded PK alone does not prove the stored code is that record (a
        // code stored by another path keeps the old PK). Pre-admission, so a refusal is definite.
        $stored = $this->invalidateTargetIsStoredCode($client, $orgPk, $codePk);
        $intent->org_pk = $orgPk;
        $intent->saveOrFail();
        $this->admit($intent);
        try {
            $intent->forceFill(['vendor_pk' => $codePk])->saveOrFail();
            $this->provisioning->invalidate($orgPk, $codePk);
        } catch (\Throwable) {
            throw new ControlDWriteUncertainException($orgPk, $codePk, 'post');
        }
        $intent->phase = 'local-persistence';
        $this->bind($intent, $actor, $orgPk, ['PK' => $codePk, 'code' => $stored]);
    }

    public const INVALIDATE_TARGET_REFUSAL = 'Control D did not confirm that the code record on this card carries the provisioning code stored on the client record; nothing was written.';

    /**
     * Pre-admission, read-only (one GET provision): the org's provisioning list shows
     * $codePk exactly once, carrying exactly the code value the client record stores. Throws
     * INVALIDATE_TARGET_REFUSAL on any other read, a failed or malformed one included, so the
     * refusal is definite and precedes any write. Returns the stored value for bind()'s locked
     * re-check that the client still stores it; it is never logged or written anywhere.
     */
    private function invalidateTargetIsStoredCode(Client $client, string $orgPk, string $codePk): string
    {
        $stored = $client->controld_provisioning_code;
        try {
            $rows = array_values(array_filter($this->provisioning->provisions($orgPk), static fn (\stdClass $row): bool => $row->PK === $codePk));
        } catch (\Throwable) {
            $rows = [];
        }
        if (! is_string($stored) || $stored === '' || count($rows) !== 1 || ! is_string($rows[0]->code ?? null) || ! hash_equals($stored, $rows[0]->code)) {
            throw new ControlDClientException(self::INVALIDATE_TARGET_REFUSAL);
        }

        return $stored;
    }

    private function code(ControlDOnboardingIntent $intent, #[\SensitiveParameter] User $actor): void
    {
        $client = Client::find($intent->client_id);
        $payload = $intent->payload;
        $fields = $this->codeFields($client, $payload);
        $intent->org_pk = $client->controld_org_id;
        // P7A74iGD (a): the exact wire fields (no secret: the PIN is not among them) are kept on
        // the encrypted payload BEFORE admission, so reconcile() can compare a later GET
        // against what was actually sent, ts_exp included. Pre-admission: a failed save here
        // is a definite refusal.
        $intent->payload = [...$payload, 'fields' => $fields];
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
            $this->applyBoundEndState($locked, $client, $intent->operation, $orgPk, $created);
            $connection->commit();
        } catch (\Throwable) {
            $connection->rollBack();
            if ($created === null) {
                throw new ControlDOrganizationUncertainException($orgPk, 'local-persistence');
            }
            throw new ControlDWriteUncertainException($orgPk, $created['PK'], 'local-persistence');
        }
    }

    /**
     * THE bound end state, shared by bind() (after a confirmed write) and reconcile() (after a
     * GET-only match), so both write exactly the same thing. Called inside the caller's
     * transaction, with $locked and $client already read FOR UPDATE; throws on any re-check
     * failure and the caller rolls back. Per operation:
     *  - organization: the client is still unmapped and no client (deleted ones included)
     *    holds the org; the client's controld_org_id = the org PK.
     *  - global-profile: the mapping is still the org; nothing local changes.
     *  - code: the mapping is still the org and no code or PIN is stored; the code and PIN
     *    columns = $created['code'] and $created['deactivation_pin'].
     *  - invalidate: the mapping is still the org, $created['PK'] is still the client's
     *    bound code PK and the client still stores $created['code'] (the value
     *    invalidateCode() confirmed at Control D); the code and PIN columns are nulled.
     * Then the intent, by a guarded UPDATE (state in $fromStates, still holding the lock) that
     * must affect exactly one row: bound, phase local-persistence, org_pk, vendor_pk
     * ($created['PK'] or the org PK), lock released, reason and reason_code cleared.
     */
    private function applyBoundEndState(ControlDOnboardingIntent $locked, ?Client $client, string $operation, string $orgPk, #[\SensitiveParameter] ?array $created, array $fromStates = ['posted']): void
    {
        $this->eligible($client, $operation);
        if ($operation === self::GLOBAL_PROFILE) {
            // Nothing local changes; the mapping must still be the org that was PUT.
            if ($client->controld_org_id !== $orgPk) {
                throw new ControlDClientException('Control D organization mapping changed.');
            }
        } elseif ($operation === 'organization') {
            if ($created !== null || Client::withTrashed()->where('controld_org_id', $orgPk)->exists()) {
                throw new ControlDClientException('Control D organization is already mapped.');
            }
            $client->controld_org_id = $orgPk;
        } elseif ($operation === self::INVALIDATE) {
            if ($client->controld_org_id !== $orgPk || ! is_string($created['PK'] ?? null) || self::boundCodePk($client) !== $created['PK']
                || ! is_string($created['code'] ?? null) || ! is_string($client->controld_provisioning_code) || ! hash_equals($created['code'], $client->controld_provisioning_code)) {
                throw new ControlDClientException('Control D organization mapping or stored code changed.');
            }
            $client->controld_provisioning_code = null;
            $client->controld_deactivation_pin = null;
        } else {
            if ($created === null || $client->controld_org_id !== $orgPk) {
                throw new ControlDClientException('Control D organization mapping changed.');
            }
            $client->controld_provisioning_code = $created['code'];
            $client->controld_deactivation_pin = $created['deactivation_pin'];
        }
        if ($operation !== self::GLOBAL_PROFILE && ! $client->save()) {
            throw new ControlDClientException('Control D persistence was refused.');
        }
        // Guarded on the states the caller re-checked under its lock and on the lock itself;
        // anything but exactly one affected row throws and the caller rolls back.
        $updated = ControlDOnboardingIntent::whereKey($locked->id)->whereIn('state', $fromStates)
            ->where('active_client_id', $locked->client_id)->update(['state' => 'bound', 'phase' => 'local-persistence', 'org_pk' => $orgPk,
                'vendor_pk' => $created['PK'] ?? $orgPk, 'active_client_id' => null,
                'reason' => null, 'reason_code' => null, 'updated_at' => now()]);
        if ($updated !== 1) {
            throw new ControlDClientException('Control D intent ownership changed.');
        }
    }

    /**
     * The wire fields for the code POST. A payload carrying `pins` (staged by the cockpit
     * approval, P7A74iGD (a) item E) executes EXACTLY those pinned values: what was approved
     * is what runs, even if a setting or the asset count moved since (the approval refuses
     * on that drift before staging; this method never silently swaps in live values). A
     * payload without pins (a direct service caller) derives them live through codePins().
     * Only ts_exp is computed here, from the pinned expiry days.
     */
    private function codeFields(Client $client, #[\SensitiveParameter] array $payload): array
    {
        if (array_key_exists('pins', $payload)) {
            if (! self::wellFormedCodePins($payload['pins'])) {
                throw new ControlDClientException('The pinned provisioning code values are malformed; nothing was written.');
            }
            $pins = $payload['pins'];
            if ($pins['max'] < 1 || $pins['max'] > 10000 || $pins['expiry_days'] < 1 || ! in_array($pins['stats'], [0, 1, 2], true)) {
                throw new ControlDClientException('The pinned provisioning code values are out of range; nothing was written.');
            }
        } else {
            $pins = self::codePins($client);
        }
        $days = $pins['expiry_days'];
        $now = now()->getTimestamp();
        $ceiling = min(PHP_INT_MAX, 253402300799);
        if ($now < 0 || $now >= $ceiling || $days > intdiv($ceiling - $now, 86400)) {
            throw new ControlDClientException('controld_code_expiry_days exceeds the supported timestamp range.');
        }
        $fields = ['icon' => $payload['icon'], 'profile_id' => $pins['profile_id'], 'max' => $pins['max'],
            'ts_exp' => $now + $days * 86400, 'stats' => $pins['stats'], 'intercept_mode' => $pins['intercept_mode']];
        if ($payload['name_prefix'] !== null) {
            $fields['name_prefix'] = $payload['name_prefix'];
        }

        return $fields;
    }
}
