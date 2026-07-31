<?php

namespace App\Services\Assets;

use App\Models\Asset;
use App\Models\Client;
use App\Models\Person;
use App\Models\TacticalAsset;
use App\Services\Chet\ChetDataSurfaceTextSanitizer;
use Illuminate\Support\Collection;

/**
 * READ-ONLY cross-client mislink sweep (Chet archaeology relief).
 *
 * Finds assets whose PSA client_id is contradicted by another data source, so a
 * human can reassign each one. It NEVER writes, moves, or deactivates anything —
 * it only lists suspects with the evidence that contradicted the row.
 *
 * The detection criterion is CROSS-SOURCE CONTRADICTION, never
 * hostname-vs-client-name (a real fleet legitimately runs DESKTOP-…, PARTS-…,
 * SMARTS-… under one client). Two tiers are kept in SEPARATE collections and
 * never merged:
 *
 *   Tier A — a contradiction, not a guess:
 *     1. rmm_client_contradiction    — the asset's RMM linkage resolves to an
 *        agent whose RMM client/site maps to a DIFFERENT PSA client than the
 *        asset's own client_id. The RMM mapping is the authority; the PSA row is
 *        the suspect. Resolvable locally ONLY for Tactical (see rule 1 note).
 *     2. duplicate_serial_cross_client   — same serial_number under 2+ clients.
 *     3. duplicate_hostname_cross_client — same hostname under 2+ clients,
 *        deduped against rule 2 and SUPPRESSED when the colliding rows' serials
 *        are both present and differ (generic names collide honestly).
 *
 *   Tier B — suspect, human-eyes (never merged into A):
 *     4. last_user_foreign_contact    — last_user resolves, on local part AND
 *        domain, to a contact at another client and to none at its own.
 *        DOMAIN\user and user@domain are the same account; a last_user that
 *        carries no domain names no tenant and is never evidence.
 *     5. shared_public_ip_cross_client — a PUBLIC ip_address shared with another
 *        client's assets (weak — shared ISP/NAT is common).
 *     6. foreign_client_hostname_prefix — hostname carries another client's
 *        learned dominant prefix and none of its own.
 *
 * Rule 1 authority path (WHY only Tactical is resolvable locally): the Tactical
 * device sync keys clients.tactical_site_id to "ClientName|SiteName" and the
 * agent snapshot persists client_name + site_name per row (tactical_assets), so
 * the authoritative PSA client can be recomputed from stored data alone. Ninja
 * and Level persist only the per-device id (ninja_id / level_id) on the asset —
 * NOT the device's Ninja org / Level group — so their agent→PSA-client authority
 * cannot be reconstructed without calling the vendor API. This tool is a pure
 * local read, so it does not attempt Ninja/Level rule 1 rather than fake it; the
 * caveat in the response text names that gap.
 *
 * UNTRUSTED TEXT. Hostnames, asset names, serials and last_user values are written
 * by a vendor agent or by whoever controls the endpoint, and this is the only
 * FLEET-WIDE reader of those columns — one client's strings land in an operator's
 * agent context beside every other client's. They are therefore published through
 * ChetDataSurfaceTextSanitizer (normalize -> redact -> fence), the same treatment
 * TacticalReadOnlyToolset and ScreenConnectReadOnlyToolset give the same columns.
 * PSA-side names (client_name / other_client_name) are our own operator-set data
 * and are deliberately NOT fenced.
 */
class MislinkedAssetFinder
{
    /** A prefix shared by this many of a client's assets is a "learned dominant" prefix (rule 6, N threshold). */
    public const LEARNED_PREFIX_MIN = 3;

    public const DEFAULT_LIMIT = 100;

    public const MAX_LIMIT = 500;

    /**
     * Placeholder serials the vendors emit for un-serialised hardware. These
     * collide honestly across unrelated boxes, so they are never evidence of a
     * shared serial (mirrors NinjaSyncService::resolveSerial's junk list).
     *
     * @var array<int, string>
     */
    private const JUNK_SERIALS = [
        'standard', 'default string', 'to be filled by o.e.m.', 'none',
        'not specified', 'system serial number', 'o.e.m.', 'invalid', '0',
    ];

    /**
     * Leading DNS labels that name a host INSIDE a tenant (mail/AD/office plumbing),
     * never the tenant itself. tenantLabel() walks past them before it takes the
     * tenant name, so two unrelated clients on corp.* / mail.* do not collapse onto
     * one account key.
     *
     * @var array<int, string>
     */
    private const GENERIC_DOMAIN_LABELS = [
        'corp', 'corporate', 'mail', 'smtp', 'ad', 'ads', 'dc', 'office',
        'internal', 'intranet', 'exchange', 'email', 'local', 'lan', 'domain', 'hq',
    ];

    /**
     * Labels that belong to a public suffix, not to a tenant — the walk above must
     * never step into one.
     *
     * @var array<int, string>
     */
    private const PUBLIC_SUFFIX_LABELS = [
        'co', 'com', 'net', 'org', 'edu', 'gov', 'mil', 'ac', 'sch', 'or', 'ne', 'in',
    ];

    /**
     * Evidence leaves carrying VENDOR/TENANT-controlled text (an RMM hostname, a
     * logged-on username, a serial an agent reported): evidence key => fence label.
     * Not listed, because neither can carry instruction text: hostname_prefix is
     * regex-constrained to /^[A-Z]{2,}[A-Z0-9]*[-_]/ and shared_public_ip is
     * filter_var-validated. Rule/authority strings are our own constants.
     *
     * @var array<string, string>
     */
    private const UNTRUSTED_EVIDENCE_LEAVES = [
        'last_user' => 'Asset last user',
        'matched_account' => 'Asset last user account key',
        'duplicate_hostname' => 'Asset hostname',
        'duplicate_serial' => 'Asset serial number',
        'own_serial' => 'Asset serial number',
        'tactical_agent_id' => 'Tactical agent id',
        'tactical_site_key' => 'Tactical client site key',
    ];

    public function __construct(
        private readonly ChetDataSurfaceTextSanitizer $textSanitizer,
    ) {}

    /**
     * Run the sweep.
     *
     * @param  int|null  $clientId  Per-client when a positive id; fleet-wide when null.
     * @param  bool  $includeInactive  Include is_active=false rows on both sides (default false → active only).
     * @param  int  $limit  Max rows per tier (default 100, cap 500).
     * @return array<string, mixed>
     */
    public function find(?int $clientId, bool $includeInactive = false, int $limit = self::DEFAULT_LIMIT): array
    {
        $limit = max(1, min($limit, self::MAX_LIMIT));

        // A positive client scope must resolve to a real (non-soft-deleted)
        // client — fail closed rather than silently sweeping the whole fleet.
        if ($clientId !== null && Client::find($clientId) === null) {
            return [
                'error' => "client_id {$clientId} does not resolve to a client.",
            ];
        }

        // The universe is every considered asset across ALL clients — the
        // contradiction maps (shared serial/hostname/IP, learned prefixes) are
        // inherently cross-client, so they are always built fleet-wide even when
        // the SUBJECT set is scoped to one client.
        $universe = $this->loadUniverse($includeInactive);

        $clients = $this->clientMap();
        $tacticalSiteToClient = $this->tacticalSiteMap();
        $tacticalById = $this->tacticalAssetsById($universe);
        $peopleIndex = $this->peopleIndex();

        $serialMap = $this->buildSerialMap($universe);
        $hostnameMap = $this->buildHostnameMap($universe);
        $ipMap = $this->buildIpMap($universe);
        [$prefixOwner, $clientDominantPrefixes] = $this->buildPrefixOwners($universe);

        $subjects = $clientId === null
            ? $universe
            : $universe->where('client_id', $clientId)->values();

        $tierA = [];
        $tierB = [];

        foreach ($subjects as $asset) {
            // Rule 1 — RMM (Tactical) contradicts the PSA row.
            $this->evaluateRmmContradiction($asset, $tacticalById, $tacticalSiteToClient, $clients, $tierA);

            // Rule 2 — same serial under 2+ clients.
            $this->evaluateDuplicateSerial($asset, $serialMap, $clients, $tierA);

            // Rule 3 — same hostname under 2+ clients (deduped vs rule 2, suppressed on differing serials).
            $this->evaluateDuplicateHostname($asset, $hostnameMap, $clients, $tierA);

            // Rule 4 — last_user resolves to a contact at another client.
            $this->evaluateForeignLastUser($asset, $peopleIndex, $clients, $tierB);

            // Rule 5 — public IP shared with another client.
            $this->evaluateSharedPublicIp($asset, $ipMap, $clients, $tierB);

            // Rule 6 — hostname carries another client's learned dominant prefix.
            $this->evaluateForeignPrefix($asset, $prefixOwner, $clientDominantPrefixes, $clients, $tierB);
        }

        $tierATruncated = count($tierA) > $limit;
        $tierBTruncated = count($tierB) > $limit;

        return [
            'scope' => $clientId === null ? 'fleet' : 'client:'.$clientId,
            'client_id' => $clientId,
            'include_inactive' => $includeInactive,
            'limit' => $limit,
            'caveat' => 'Absence of a Tier A hit is not proof a client is clean — it only proves no contradicting source exists; an asset with no RMM id has nothing to contradict.',
            'rmm_authority_note' => 'Rule 1 (RMM contradicts the PSA row) is resolved locally only for Tactical, whose agent client|site is persisted per asset and maps to clients.tactical_site_id. Ninja and Level persist only the device id, not the device\'s org/group, so their agent→PSA-client authority cannot be reconstructed from local data — no Ninja/Level rule-1 hit is computed here, and that absence is not proof those links are correct.',
            'tier_a_count' => count($tierA),
            'tier_b_count' => count($tierB),
            'tier_a_truncated' => $tierATruncated,
            'tier_b_truncated' => $tierBTruncated,
            'tier_a' => array_slice($tierA, 0, $limit),
            'tier_b' => array_slice($tierB, 0, $limit),
        ];
    }

    /**
     * Load every considered asset with only the columns the sweep reads.
     *
     * @return Collection<int, Asset>
     */
    private function loadUniverse(bool $includeInactive): Collection
    {
        return Asset::query()
            ->when(! $includeInactive, fn ($q) => $q->where('is_active', true))
            ->get([
                'id', 'client_id', 'name', 'hostname', 'serial_number',
                'ip_address', 'last_user', 'is_active',
                'ninja_id', 'level_id', 'tactical_asset_id',
            ]);
    }

    /** @return array<int, array{name: string, is_active: bool}> */
    private function clientMap(): array
    {
        return Client::query()
            ->get(['id', 'name', 'is_active'])
            ->keyBy('id')
            ->map(fn (Client $c) => ['name' => $c->name, 'is_active' => (bool) $c->is_active])
            ->all();
    }

    /**
     * clients.tactical_site_id ("ClientName|SiteName") → PSA client_id. This is
     * the authority the Tactical device sync itself uses to place an agent, so it
     * must apply the SAME ->operational() filter that sync applies
     * (TacticalDeviceSyncService::syncDevices). Without it a churned/prospect
     * client carrying a stale duplicate of a live client's site key can win the
     * last-wins pluck() and manufacture a whole fleet of false Tier A
     * rmm_client_contradiction hits against a client the sync would never use.
     *
     * @return array<string, int>
     */
    private function tacticalSiteMap(): array
    {
        return Client::query()
            ->whereNotNull('tactical_site_id')
            ->operational()
            ->pluck('id', 'tactical_site_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * The TacticalAsset rows referenced by subjects' tactical_asset_id, keyed by
     * their id (the FK target the ingest sets on assets.tactical_asset_id).
     *
     * @param  Collection<int, Asset>  $universe
     * @return array<int, TacticalAsset>
     */
    private function tacticalAssetsById(Collection $universe): array
    {
        $ids = $universe->pluck('tactical_asset_id')->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return [];
        }

        return TacticalAsset::query()
            ->whereIn('id', $ids)
            ->get(['id', 'agent_id', 'client_name', 'site_name'])
            ->keyBy('id')
            ->all();
    }

    /**
     * Tenant-qualified account key (local part + domain label) of every contact's
     * cipp_upn / email → the clients they belong to. Used to resolve an asset's
     * last_user to a foreign contact (rule 4).
     *
     * Keyed on the whole address, NOT the local part: the domain is the only
     * half that distinguishes one client's admin@ from another's. See
     * accountKey().
     *
     * @return array<string, array<int, array{person_id: int, client_id: int}>>
     */
    private function peopleIndex(): array
    {
        $index = [];

        Person::query()
            ->whereNotNull('client_id')
            ->get(['id', 'client_id', 'cipp_upn', 'email'])
            ->each(function (Person $p) use (&$index) {
                foreach ([$p->cipp_upn, $p->email] as $addr) {
                    $key = $this->accountKey($addr);
                    if ($key === null) {
                        continue;
                    }
                    $index[$key][] = ['person_id' => (int) $p->id, 'client_id' => (int) $p->client_id];
                }
            });

        return $index;
    }

    /**
     * Serial (upper-cased, junk-filtered) → distinct client_ids that hold it.
     *
     * @param  Collection<int, Asset>  $universe
     * @return array<string, array<int, int>>  serial => list of distinct client_ids
     */
    private function buildSerialMap(Collection $universe): array
    {
        $map = [];

        foreach ($universe as $asset) {
            $serial = $this->normalizeSerial($asset->serial_number);
            if ($serial === null || $asset->client_id === null) {
                continue;
            }
            $map[$serial][(int) $asset->client_id] = (int) $asset->client_id;
        }

        return array_map('array_values', $map);
    }

    /**
     * Lower-cased hostname → the rows carrying it (with client + serial), so
     * rule 3 can compare colliding rows pairwise.
     *
     * @param  Collection<int, Asset>  $universe
     * @return array<string, array<int, array{client_id: int, serial: string|null}>>
     */
    private function buildHostnameMap(Collection $universe): array
    {
        $map = [];

        foreach ($universe as $asset) {
            $host = $this->normalizeHostname($asset->hostname);
            if ($host === null || $asset->client_id === null) {
                continue;
            }
            $map[$host][] = [
                'client_id' => (int) $asset->client_id,
                'serial' => $this->normalizeSerial($asset->serial_number),
            ];
        }

        return $map;
    }

    /**
     * Public IP → distinct client_ids that use it. Private/reserved ranges are
     * dropped: shared RFC1918 space says nothing about client identity.
     *
     * @param  Collection<int, Asset>  $universe
     * @return array<string, array<int, int>>
     */
    private function buildIpMap(Collection $universe): array
    {
        $map = [];

        foreach ($universe as $asset) {
            $ip = $this->publicIp($asset->ip_address);
            if ($ip === null || $asset->client_id === null) {
                continue;
            }
            $map[$ip][(int) $asset->client_id] = (int) $asset->client_id;
        }

        return array_map('array_values', $map);
    }

    /**
     * Learn each client's dominant hostname prefixes (>= N assets share it), then
     * keep only prefixes owned DISTINCTIVELY by a single client. A prefix that is
     * dominant for 2+ clients (DESKTOP-, LAPTOP-, WIN-, …) is generic noise, not
     * a client fingerprint, so it owns no one.
     *
     * @param  Collection<int, Asset>  $universe
     * @return array{0: array<string, int>, 1: array<int, array<string, int>>}
     *         [ prefix => sole-owner client_id, clientId => [prefix => count] ]
     */
    private function buildPrefixOwners(Collection $universe): array
    {
        /** @var array<int, array<string, int>> $perClient */
        $perClient = [];

        foreach ($universe as $asset) {
            if ($asset->client_id === null) {
                continue;
            }
            $prefix = $this->hostnamePrefix($asset->hostname);
            if ($prefix === null) {
                continue;
            }
            $perClient[(int) $asset->client_id][$prefix] = ($perClient[(int) $asset->client_id][$prefix] ?? 0) + 1;
        }

        /** @var array<int, array<string, int>> $clientDominant */
        $clientDominant = [];
        /** @var array<string, array<int, int>> $prefixClients */
        $prefixClients = [];

        foreach ($perClient as $cid => $prefixes) {
            foreach ($prefixes as $prefix => $count) {
                if ($count < self::LEARNED_PREFIX_MIN) {
                    continue;
                }
                $clientDominant[$cid][$prefix] = $count;
                $prefixClients[$prefix][$cid] = $count;
            }
        }

        // A prefix owns a client only when exactly one client has it dominant.
        $prefixOwner = [];
        foreach ($prefixClients as $prefix => $byClient) {
            if (count($byClient) === 1) {
                $prefixOwner[$prefix] = (int) array_key_first($byClient);
            }
        }

        return [$prefixOwner, $clientDominant];
    }

    // ── Rule evaluators ──

    /**
     * @param  array<int, TacticalAsset>  $tacticalById
     * @param  array<string, int>  $tacticalSiteToClient
     * @param  array<int, array{name: string, is_active: bool}>  $clients
     * @param  array<int, array<string, mixed>>  $tierA
     */
    private function evaluateRmmContradiction(Asset $asset, array $tacticalById, array $tacticalSiteToClient, array $clients, array &$tierA): void
    {
        if ($asset->tactical_asset_id === null || $asset->client_id === null) {
            return;
        }

        $ta = $tacticalById[(int) $asset->tactical_asset_id] ?? null;
        if ($ta === null) {
            return;
        }

        $siteKey = ($ta->client_name ?? '').'|'.($ta->site_name ?? '');
        $authoritativeClientId = $tacticalSiteToClient[$siteKey] ?? null;

        // No mapping → nothing to contradict (absence is not a hit).
        if ($authoritativeClientId === null || $authoritativeClientId === (int) $asset->client_id) {
            return;
        }

        $tierA[] = $this->finding($asset, 'rmm_client_contradiction',
            'RMM (Tactical) agent maps to a different PSA client than the asset row',
            $authoritativeClientId, $clients, [
                'source' => 'tactical',
                'tactical_agent_id' => $ta->agent_id,
                'tactical_site_key' => $siteKey,
                'authority' => 'clients.tactical_site_id',
            ]);
    }

    /**
     * @param  array<string, array<int, int>>  $serialMap
     * @param  array<int, array{name: string, is_active: bool}>  $clients
     * @param  array<int, array<string, mixed>>  $tierA
     */
    private function evaluateDuplicateSerial(Asset $asset, array $serialMap, array $clients, array &$tierA): void
    {
        $serial = $this->normalizeSerial($asset->serial_number);
        if ($serial === null || $asset->client_id === null) {
            return;
        }

        $clientIds = $serialMap[$serial] ?? [];
        foreach ($clientIds as $otherClientId) {
            if ($otherClientId === (int) $asset->client_id) {
                continue;
            }
            $tierA[] = $this->finding($asset, 'duplicate_serial_cross_client',
                'Same serial_number is active under another client — one row is wrong',
                $otherClientId, $clients, [
                    'duplicate_serial' => (string) $asset->serial_number,
                ]);
        }
    }

    /**
     * @param  array<string, array<int, array{client_id: int, serial: string|null}>>  $hostnameMap
     * @param  array<int, array{name: string, is_active: bool}>  $clients
     * @param  array<int, array<string, mixed>>  $tierA
     */
    private function evaluateDuplicateHostname(Asset $asset, array $hostnameMap, array $clients, array &$tierA): void
    {
        $host = $this->normalizeHostname($asset->hostname);
        if ($host === null || $asset->client_id === null) {
            return;
        }

        $ownSerial = $this->normalizeSerial($asset->serial_number);
        $reported = [];

        foreach ($hostnameMap[$host] ?? [] as $row) {
            $otherClientId = $row['client_id'];
            if ($otherClientId === (int) $asset->client_id || isset($reported[$otherClientId])) {
                continue;
            }

            $otherSerial = $row['serial'];

            // Both serials present and EQUAL → this is rule 2's territory; dedupe.
            if ($ownSerial !== null && $otherSerial !== null && $ownSerial === $otherSerial) {
                continue;
            }
            // Both serials present and DIFFERENT → honest generic-name collision; suppress.
            if ($ownSerial !== null && $otherSerial !== null && $ownSerial !== $otherSerial) {
                continue;
            }

            // At least one serial missing → serial evidence can neither confirm
            // nor deny; a hostname collision across clients is worth flagging.
            $reported[$otherClientId] = true;
            $tierA[] = $this->finding($asset, 'duplicate_hostname_cross_client',
                'Same hostname is active under another client and serials cannot disprove it',
                $otherClientId, $clients, [
                    'duplicate_hostname' => (string) $asset->hostname,
                    'own_serial' => $asset->serial_number,
                ]);
        }
    }

    /**
     * @param  array<string, array<int, array{person_id: int, client_id: int}>>  $peopleIndex
     * @param  array<int, array{name: string, is_active: bool}>  $clients
     * @param  array<int, array<string, mixed>>  $tierB
     */
    private function evaluateForeignLastUser(Asset $asset, array $peopleIndex, array $clients, array &$tierB): void
    {
        if ($asset->client_id === null) {
            return;
        }

        $key = $this->accountKey($asset->last_user);
        if ($key === null) {
            return;
        }

        $entries = $peopleIndex[$key] ?? [];
        if ($entries === []) {
            return;
        }

        // If the user also resolves to a contact at the asset's OWN client, there
        // is no contradiction — the login is legitimately local.
        foreach ($entries as $entry) {
            if ($entry['client_id'] === (int) $asset->client_id) {
                return;
            }
        }

        $reported = [];
        foreach ($entries as $entry) {
            $otherClientId = $entry['client_id'];
            if (isset($reported[$otherClientId])) {
                continue;
            }
            $reported[$otherClientId] = true;
            $tierB[] = $this->finding($asset, 'last_user_foreign_contact',
                'last_user resolves to a contact at another client (and none at its own)',
                $otherClientId, $clients, [
                    'last_user' => (string) $asset->last_user,
                    'matched_account' => $key,
                    'matched_person_id' => $entry['person_id'],
                ]);
        }
    }

    /**
     * @param  array<string, array<int, int>>  $ipMap
     * @param  array<int, array{name: string, is_active: bool}>  $clients
     * @param  array<int, array<string, mixed>>  $tierB
     */
    private function evaluateSharedPublicIp(Asset $asset, array $ipMap, array $clients, array &$tierB): void
    {
        $ip = $this->publicIp($asset->ip_address);
        if ($ip === null || $asset->client_id === null) {
            return;
        }

        foreach ($ipMap[$ip] ?? [] as $otherClientId) {
            if ($otherClientId === (int) $asset->client_id) {
                continue;
            }
            $tierB[] = $this->finding($asset, 'shared_public_ip_cross_client',
                'Public ip_address is shared with another client\'s assets (weak — shared ISP/NAT is common)',
                $otherClientId, $clients, [
                    'shared_public_ip' => $ip,
                ]);
        }
    }

    /**
     * @param  array<string, int>  $prefixOwner
     * @param  array<int, array<string, int>>  $clientDominantPrefixes
     * @param  array<int, array{name: string, is_active: bool}>  $clients
     * @param  array<int, array<string, mixed>>  $tierB
     */
    private function evaluateForeignPrefix(Asset $asset, array $prefixOwner, array $clientDominantPrefixes, array $clients, array &$tierB): void
    {
        if ($asset->client_id === null) {
            return;
        }

        $prefix = $this->hostnamePrefix($asset->hostname);
        if ($prefix === null) {
            return;
        }

        $owner = $prefixOwner[$prefix] ?? null;
        if ($owner === null || $owner === (int) $asset->client_id) {
            return;
        }

        // "and none of its own": the asset must not carry one of its own client's
        // learned prefixes. Its single prefix belongs to the foreign owner, so
        // this holds — but assert it explicitly against the own-client set.
        if (isset($clientDominantPrefixes[(int) $asset->client_id][$prefix])) {
            return;
        }

        $tierB[] = $this->finding($asset, 'foreign_client_hostname_prefix',
            'Hostname carries another client\'s learned dominant prefix and none of its own',
            $owner, $clients, [
                'hostname_prefix' => $prefix,
                'owner_asset_count' => $clientDominantPrefixes[$owner][$prefix] ?? null,
                'learned_prefix_min' => self::LEARNED_PREFIX_MIN,
            ]);
    }

    // ── Shaping + normalisation ──

    /**
     * @param  array<int, array{name: string, is_active: bool}>  $clients
     * @param  array<string, mixed>  $evidence
     * @return array<string, mixed>
     */
    private function finding(Asset $asset, string $rule, string $ruleLabel, int $otherClientId, array $clients, array $evidence): array
    {
        return [
            'asset_id' => (int) $asset->id,
            // Vendor/tenant-controlled free text — fenced as untrusted before it
            // crosses into agent context (see the class docblock). The PSA-side
            // client names below are our own data and stay as they are.
            'hostname' => $this->textSanitizer->sanitizeNullable('Asset hostname', $asset->hostname, 200),
            'name' => $this->textSanitizer->sanitizeNullable('Asset name', $asset->name, 200),
            'client_id' => (int) $asset->client_id,
            'client_name' => $clients[(int) $asset->client_id]['name'] ?? null,
            'is_active' => (bool) $asset->is_active,
            'rule' => $rule,
            'rule_label' => $ruleLabel,
            'other_client_id' => $otherClientId,
            'other_client_name' => $clients[$otherClientId]['name'] ?? null,
            'evidence' => $this->sanitizeEvidence($evidence),
        ];
    }

    /**
     * Fence the vendor/tenant-controlled evidence leaves. Keys absent from
     * UNTRUSTED_EVIDENCE_LEAVES are our own derived/validated values and pass
     * through unchanged.
     *
     * @param  array<string, mixed>  $evidence
     * @return array<string, mixed>
     */
    private function sanitizeEvidence(array $evidence): array
    {
        foreach (self::UNTRUSTED_EVIDENCE_LEAVES as $key => $label) {
            if (! array_key_exists($key, $evidence)) {
                continue;
            }
            $evidence[$key] = $this->textSanitizer->sanitizeNullable(
                $label,
                $evidence[$key],
                200,
                $key === 'last_user' ? ['None', '-'] : [],
            );
        }

        return $evidence;
    }

    private function normalizeSerial(?string $serial): ?string
    {
        $serial = trim((string) $serial);
        if ($serial === '') {
            return null;
        }
        if (in_array(mb_strtolower($serial), self::JUNK_SERIALS, true)) {
            return null;
        }

        return mb_strtoupper($serial);
    }

    private function normalizeHostname(?string $hostname): ?string
    {
        $hostname = trim((string) $hostname);

        return $hostname === '' ? null : mb_strtolower($hostname);
    }

    /**
     * The leading token of a hostname up to and including its first separator,
     * upper-cased (e.g. "SMARTS-02698R26" → "SMARTS-"). Requires 2+ leading
     * letters so numeric-only fragments never become a prefix. Null when the
     * hostname has no such separated prefix.
     */
    private function hostnamePrefix(?string $hostname): ?string
    {
        $hostname = mb_strtoupper(trim((string) $hostname));
        if ($hostname === '') {
            return null;
        }

        if (preg_match('/^([A-Z]{2,}[A-Z0-9]*[-_])/', $hostname, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    /**
     * The tenant-qualified account key for a last_user / UPN / email value —
     * "local@domain-label" — or null when the value carries no domain at all.
     *
     * Rule 4 compares accounts ACROSS clients, so the domain (the only half of
     * an address that names a tenant) must survive into the key. Matching on the
     * local part alone collapsed 'admin@acme.com' and 'admin@bravo.com' onto one
     * key, so every generic account an MSP fleet legitimately runs — admin,
     * info, scan, office, reception — emitted a Tier B row per foreign client
     * that happens to have the same account name. Asset::resolveLastUserPerson
     * may match on a bare local part precisely because it is already scoped to
     * ONE client (`cipp_upn like 'user@%'` inside `where client_id`); nothing
     * scopes the sweep, so the domain has to do that work here.
     *
     * PARSING. A NetBIOS prefix is stripped FIRST, but the remainder is NOT assumed
     * to be a bare local part: several RMM agents write DOMAIN\user@upn, and an
     * address form in the remainder names the tenant more precisely than the NetBIOS
     * label, so it wins. The address splits on its LAST '@' (a quoted local part may
     * carry its own). DOMAIN\user and user@domain.tld therefore still produce one key
     * (BRAVO\jdoe ≡ jdoe@bravo.com ≡ BRAVO\jdoe@bravo.com → 'jdoe@bravo'); parsing
     * the backslash form as local='jdoe@bravo.com' would have keyed it to something
     * no contact can ever carry, silently retiring rule 4 for those rows.
     *
     * THE TENANT LABEL is the leading DNS label, so acme.com / acme.co.uk / the AD
     * suffix agree and two tenants under one provider parent stay apart
     * (acmeco.onmicrosoft.com → 'acmeco'), EXCEPT that infrastructure labels
     * (corp., mail., ad., office. — GENERIC_DOMAIN_LABELS) are walked past first.
     * They name a host INSIDE a tenant, so keying on one would make every client on
     * corp.* a single account — the same generic-account flood, moved from the local
     * part to the domain. The walk never steps into a public suffix.
     *
     * KNOWN, DELIBERATE LIMITS. Two tenants whose leading label is genuinely the same
     * (acme.com vs acme.net) still agree — this is name-based matching and Tier B is
     * human-eyes. Values that name no tenant return null and can never be evidence: a
     * bare 'jdoe', and a domain that is nothing but generic labels ('CORP\jdoe',
     * corp.com). Both are false negatives by choice: an unmatchable key costs a missed
     * suspect, a colliding one accuses an innocent client.
     */
    private function accountKey(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        $domain = null;

        if (str_contains($value, '\\')) {
            $cut = (int) strrpos($value, '\\');
            $domain = substr($value, 0, $cut);
            $value = substr($value, $cut + 1);
        }

        if (str_contains($value, '@')) {
            $cut = (int) strrpos($value, '@');
            $local = substr($value, 0, $cut);
            $domain = substr($value, $cut + 1);
        } else {
            $local = $value;
        }

        if ($domain === null) {
            return null;
        }

        $local = trim(mb_strtolower($local));
        $tenant = $this->tenantLabel($domain);

        return ($local === '' || $tenant === null) ? null : $local.'@'.$tenant;
    }

    /**
     * The tenant label of a domain (or of a bare NetBIOS name): the leading DNS
     * label, after any infrastructure labels are walked past. Null when the value
     * names no tenant. Trailing dots and empty labels are ignored; comparison is on
     * the lowercased label, so an IDN keys on its stored a-label as-is.
     */
    private function tenantLabel(?string $domain): ?string
    {
        $labels = array_values(array_filter(
            array_map(
                static fn (string $label): string => trim($label),
                explode('.', mb_strtolower(trim((string) $domain))),
            ),
            static fn (string $label): bool => $label !== '',
        ));

        if ($labels === []) {
            return null;
        }

        // Walk past corp./mail./ad./office. — but never into the public suffix, so
        // 'corp.co.uk' is not read as the tenant 'co'.
        while (count($labels) > 1
            && in_array($labels[0], self::GENERIC_DOMAIN_LABELS, true)
            && ! in_array($labels[1], self::PUBLIC_SUFFIX_LABELS, true)) {
            array_shift($labels);
        }

        // Nothing but generic labels left → this value names no tenant.
        return in_array($labels[0], self::GENERIC_DOMAIN_LABELS, true) ? null : $labels[0];
    }

    /**
     * Return the IP only when it is a valid PUBLIC (non-private, non-reserved)
     * address; null otherwise. RFC1918/loopback/link-local space is not evidence.
     */
    private function publicIp(?string $ip): ?string
    {
        $ip = trim((string) $ip);
        if ($ip === '') {
            return null;
        }

        $valid = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);

        return $valid === false ? null : $ip;
    }
}
