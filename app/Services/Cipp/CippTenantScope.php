<?php

namespace App\Services\Cipp;

use App\Models\Client;
use App\Support\CippConfig;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * The ONE place a CIPP read learns which tenant it may touch (card 6abdcac2, the
 * Huntress pattern from #4486 applied to CIPP).
 *
 * client_id is the key. It resolves through the client's stored integration column,
 * clients.cipp_tenant_domain, and through nothing else: there is no tenant-name
 * fallback, because a tenant the agent types is not bound to any PSA client. Every
 * CIPP read path consults this resolver — the direct REST path and the MCP relay
 * (HandlesCippTools / CippMcpToolRelay) and the dynamic catalog executor — so the
 * three cannot drift into different answers for the same client.
 *
 * Fails closed, with an error that says why, never with a cross-client read:
 *   - no client_id at all;
 *   - a client_id that does not resolve to the client the caller holds;
 *   - a client not mapped to CIPP;
 *   - a tenant key another PSA client also carries (compared trimmed and
 *     case-insensitively, the way CIPP's Get-Tenants matches a domain), because the
 *     rows could not be attributed to one client;
 *   - a tenant another PSA client carries under ANOTHER of its aliases (customerId,
 *     defaultDomainName, initialDomainName: the keys Get-Tenants matches), checked
 *     against CIPP's tenant list whenever any other PSA client is mapped;
 *   - that tenant list unreadable, or not naming the client's tenant exactly once,
 *     or naming it on a row without customerId and defaultDomainName values,
 *     because then the alias check above could not be made.
 *
 * Only the client's OWN row has to be a well-formed tenant (#4581). Another tenant's
 * malformed row does not block this client, but its aliases still count when it
 * shares one with the client's row (sameTenantAliases()).
 */
final class CippTenantScope
{
    public const KEY_NOTE = 'Tenant scope: client_id is the primary key (on a ticket, the ticket\'s client). The CIPP tenant is resolved from that client\'s stored CIPP mapping (clients.cipp_tenant_domain); there is no tenant-name fallback, and a tenant name or domain cannot be passed. A client not mapped to CIPP, or a tenant mapped to more than one PSA client, is an error, never a cross-client read.';

    private const TENANT_LIST_CACHE_KEY = 'cipp-tenant-scope:tenant-list';

    private const TENANT_LIST_TTL_SECONDS = 300;

    /**
     * The tenant key to send upstream for this client, or an error payload.
     *
     * @return string|array{error: string}
     */
    public static function resolve(?Client $client, ?int $clientId, ?float $deadline = null): string|array
    {
        if ($clientId === null) {
            return ['error' => 'client_id is required: a CIPP read resolves its tenant through the PSA client\'s stored CIPP mapping and does not search tenants by name.'];
        }

        if ($client === null || (int) $client->getKey() !== $clientId) {
            return ['error' => "PSA client {$clientId} was not found."];
        }

        $tenant = trim((string) $client->cipp_tenant_domain);
        if ($tenant === '') {
            return ['error' => "PSA client {$clientId} is not mapped to CIPP (it has no CIPP tenant mapping). Map it in Settings > CIPP Tenants; this read does not search other clients' tenants."];
        }

        if (self::mappedToAnotherClient($tenant, $clientId)) {
            return ['error' => "PSA client {$clientId}'s CIPP tenant is also mapped to another PSA client, so its data cannot be attributed to one client. Fix the duplicate mapping in Settings > CIPP Tenants."];
        }

        $aliasError = self::aliasSharedWithAnotherClient($tenant, $clientId, $deadline);
        if ($aliasError !== null) {
            return ['error' => $aliasError];
        }

        return $tenant;
    }

    /**
     * Whether any OTHER PSA client's stored tenant equals one of these keys (trimmed,
     * case-insensitive). Used for the client's own key and for every alias CIPP reports
     * for a tenant row.
     *
     * @param  string|array<int, string>  $keys
     */
    public static function mappedToAnotherClient(string|array $keys, int $clientId): bool
    {
        $wanted = array_filter(array_map(
            fn (mixed $key): string => mb_strtolower(trim((string) $key)),
            (array) $keys,
        ), fn (string $key): bool => $key !== '');

        if ($wanted === []) {
            return false;
        }

        return Client::whereKeyNot($clientId)
            ->whereNotNull('cipp_tenant_domain')
            ->pluck('cipp_tenant_domain')
            ->contains(fn (mixed $other): bool => in_array(mb_strtolower(trim((string) $other)), $wanted, true));
    }

    /**
     * The keys CIPP's Get-Tenants matches a tenant row on, trimmed and lowercased.
     *
     * @param  array<string, mixed>  $row
     * @return array<int, string>
     */
    public static function tenantAliases(array $row): array
    {
        $aliases = [];
        foreach (['customerId', 'defaultDomainName', 'initialDomainName'] as $key) {
            if (is_string($row[$key] ?? null) && trim($row[$key]) !== '') {
                $aliases[] = mb_strtolower(trim($row[$key]));
            }
        }

        return $aliases;
    }

    /**
     * Whether a ListTenants row is a tenant: non-empty customerId and defaultDomainName
     * strings. CIPP's "could not list tenants" answer carries both keys, empty
     * ({Results: 'Failed to retrieve tenants…', defaultDomainName: '', customerId: ''}).
     */
    public static function isTenantRow(mixed $row): bool
    {
        return is_array($row)
            && is_string($row['customerId'] ?? null) && trim($row['customerId']) !== ''
            && is_string($row['defaultDomainName'] ?? null) && trim($row['defaultDomainName']) !== '';
    }

    /**
     * Why the client's tenant cannot be attributed to it once CIPP's own aliases for
     * that tenant are counted, or null when it can. Client B mapped to Client A's
     * initialDomainName or customerId is A's tenant to CIPP, so comparing stored
     * strings alone would serve A's rows under B. With no other PSA client mapped
     * there is nothing to share, and CIPP is not asked.
     */
    private static function aliasSharedWithAnotherClient(string $tenant, int $clientId, ?float $deadline = null): ?string
    {
        $anotherClientMapped = Client::whereKeyNot($clientId)
            ->whereNotNull('cipp_tenant_domain')
            ->pluck('cipp_tenant_domain')
            ->contains(fn (mixed $other): bool => trim((string) $other) !== '');
        if (! $anotherClientMapped) {
            return null;
        }

        $cached = self::cachedTenantList();
        [$tenants, $failure] = $cached !== null ? [$cached, null] : self::readTenantList($deadline);
        if ($tenants === null) {
            return self::unreadableMessage($clientId, $failure);
        }

        $match = self::matchTenantRow($tenants, $tenant, $clientId);
        if (in_array($match['status'], ['unusable', 'none', 'many', 'malformed'], true) && $cached !== null) {
            // Settings > CIPP Tenants maps a client from CIPP's live list, so a tenant
            // added (or finished onboarding) since this copy was cached is missing or
            // incomplete in it. Re-read before the mapping is reported as wrong.
            [$tenants, $failure] = self::readTenantList($deadline);
            if ($tenants === null) {
                return self::unreadableMessage($clientId, $failure);
            }
            $match = self::matchTenantRow($tenants, $tenant, $clientId);
        }

        return match ($match['status']) {
            'ok' => null,
            'unusable' => self::unreadableMessage($clientId, null),
            'none' => "CIPP's tenant list has no tenant matching PSA client {$clientId}'s CIPP mapping, so its other domains cannot be checked against other PSA clients' mappings and this read was not run. Check the mapping in Settings > CIPP Tenants.",
            'many' => "CIPP's tenant list has more than one tenant matching PSA client {$clientId}'s CIPP mapping, so it is ambiguous and this read was not run. Fix the mapping in Settings > CIPP Tenants.",
            'malformed' => "CIPP's tenant list row for PSA client {$clientId}'s CIPP mapping has no customerId or defaultDomainName value (CIPP may still be onboarding that tenant), so its other domains cannot be checked against other PSA clients' mappings and this read was not run.",
            default => "PSA client {$clientId}'s CIPP tenant is also mapped, under another of its domains, to another PSA client, so its data cannot be attributed to one client. Fix the duplicate mapping in Settings > CIPP Tenants.",
        };
    }

    /**
     * Pick the client's own row out of CIPP's tenant list and say whether it may be
     * served. The one implementation behind the resolver's alias check and the
     * cipp_list_tenants narrowing (CippMcpDynamicToolExecutor::clientTenantRow()).
     *
     * Status:
     *   - unusable: not a list of tenants at all. Empty, not a list, no well-formed
     *     tenant row, or a row carrying CIPP's own failure marker (Invoke-ListTenants.ps1
     *     catch block: {Results: 'Failed to retrieve tenants…', customerId: '',
     *     defaultDomainName: ''}). That is a whole-list failure, never one bad row.
     *   - none / many: not exactly one row carries the mapping among its aliases.
     *     Every array row is matched, well-formed or not, so a malformed row that
     *     carries the mapping makes it ambiguous rather than being ignored.
     *   - malformed: the one matching row has no customerId or defaultDomainName.
     *   - shared: the row, or a row that shares an alias with it, also carries
     *     another PSA client's mapping.
     *   - ok: the row, which is returned.
     *
     * A malformed row that matches nothing is skipped (#4581): it is another tenant's
     * problem and blocks no one else, unless it shares an alias with this client's row.
     *
     * @param  array<int|string, mixed>  $rows
     * @return array{status: 'unusable'|'none'|'many'|'malformed'|'shared'|'ok', row?: array<string, mixed>}
     */
    public static function matchTenantRow(array $rows, string $tenant, int $clientId): array
    {
        if (! self::isUsableTenantList($rows)) {
            return ['status' => 'unusable'];
        }

        $arrays = array_values(array_filter($rows, 'is_array'));
        $wanted = mb_strtolower(trim($tenant));
        $matches = array_values(array_filter($arrays, fn (array $row): bool => in_array($wanted, self::tenantAliases($row), true)));

        if (count($matches) !== 1) {
            return ['status' => count($matches) === 0 ? 'none' : 'many'];
        }

        if (! self::isTenantRow($matches[0])) {
            return ['status' => 'malformed'];
        }

        if (self::mappedToAnotherClient(self::sameTenantAliases($arrays, $matches[0]), $clientId)) {
            return ['status' => 'shared'];
        }

        return ['status' => 'ok', 'row' => $matches[0]];
    }

    /**
     * Whether CIPP's ListTenants answer is a list of tenants at all: a non-empty list
     * with at least one well-formed tenant row and no row carrying CIPP's failure
     * marker (a string Results on a row that is not a tenant; Invoke-ListTenants.ps1).
     * Other malformed rows do not make the whole list unusable (#4581).
     *
     * @param  array<int|string, mixed>  $rows
     */
    public static function isUsableTenantList(array $rows): bool
    {
        if ($rows === [] || ! array_is_list($rows)) {
            return false;
        }

        $tenantRows = 0;
        foreach ($rows as $row) {
            if (self::isTenantRow($row)) {
                $tenantRows++;
            } elseif (is_array($row) && is_string($row['Results'] ?? null)) {
                return false;
            }
        }

        return $tenantRows > 0;
    }

    /**
     * The aliases of $row plus those of every row that shares an alias with it,
     * transitively, whatever shape that row is. Get-Tenants matches a tenantFilter on
     * any of these keys, so a row sharing one with the client's row is the same
     * tenant to CIPP; if it also carries another PSA client's mapping, that client
     * can read this tenant. Malformed rows count here: a missing customerId does
     * not make their domains any less matchable.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $row
     * @return array<int, string>
     */
    private static function sameTenantAliases(array $rows, array $row): array
    {
        $aliases = self::tenantAliases($row);
        do {
            $grew = false;
            foreach ($rows as $other) {
                $otherAliases = self::tenantAliases($other);
                if (array_intersect($otherAliases, $aliases) !== [] && array_diff($otherAliases, $aliases) !== []) {
                    $aliases = array_values(array_unique(array_merge($aliases, $otherAliases)));
                    $grew = true;
                }
            }
        } while ($grew);

        return $aliases;
    }

    /**
     * The refusal when no usable tenant list could be read, naming the cause when
     * one is known. The cause is PSA-authored, never the upstream exception text.
     *
     * @param  array{cause: string, signIn: bool, timeout?: bool}|null  $failure
     */
    private static function unreadableMessage(int $clientId, ?array $failure): string
    {
        $message = 'PSA could not read a usable CIPP tenant list';
        if ($failure !== null && $failure['cause'] !== '') {
            $message .= " ({$failure['cause']})";
        }
        $message .= ", so it cannot check PSA client {$clientId}'s CIPP tenant's other domains against other PSA clients' mappings, and this read was not run.";

        if ($failure !== null && ($failure['timeout'] ?? false)) {
            $message .= ' CIPP did not answer within 20 s (upstream timeout; this read is safe to retry).';
        }

        if ($failure !== null && $failure['signIn']) {
            $message .= ' CIPP sign-in failed: this is a problem with PSA\'s CIPP credentials, not with the client\'s mapping. Check the CIPP connection in Settings.';
        }

        return $message;
    }

    /**
     * The tenant rows PSA last read from CIPP, or null when none are cached.
     *
     * @return array<int, array<string, mixed>>|null
     */
    private static function cachedTenantList(): ?array
    {
        $cached = Cache::get(self::TENANT_LIST_CACHE_KEY);

        return is_array($cached) ? $cached : null;
    }

    /**
     * CIPP's tenant rows read now, or null with the cause when no usable list could
     * be read.
     *
     * Transport: REST when its credentials are set, else ExecMCP, which can run on
     * its own credentials (isMcpRelayEnabled() does not need the REST ones). When the
     * REST read throws (CippClient throws CippClientException for a failed sign-in
     * and for an HTTP or transport error), or its settings cannot be read (a
     * cipp_client_secret that no longer decrypts), and the relay is enabled,
     * ListTenants is asked over MCP instead (#4580). That is the failover
     * HandlesCippTools::cippDispatch() already makes for the curated reads, in the
     * other direction (AssistantToolExecutor::cippMcpRelay(): MCP could not sign in,
     * so REST answers): a transport that did not get CIPP's answer hands over to the
     * one that can. An answer CIPP DID give is not re-asked elsewhere: its failure
     * object (REST get() unwraps the string Results, which the array return type
     * refuses) and a list that is not usable both fail closed here, as before.
     *
     * Caching: a usable list is cached as read, malformed rows included. A foreign
     * malformed row blocks no other client (matchTenantRow() skips it), and its
     * aliases must stay in the copy for sameTenantAliases() to count them; dropping
     * the row before caching would hide them. A client whose OWN row is malformed
     * is refused, and that refusal re-reads past the cached copy
     * (aliasSharedWithAnotherClient()), so a tenant that finishes onboarding is seen
     * on the next call rather than after the TTL. A list that is not usable is never
     * cached, so a failed read is retried on the next call.
     *
     * @return array{0: array<int, array<string, mixed>>|null, 1: array{cause: string, signIn: bool, timeout?: bool}|null}
     */
    private static function readTenantList(?float $deadline = null): array
    {
        $causes = [];
        $signIn = false;
        $rows = null;

        $restConfigured = false;
        try {
            $restConfigured = CippConfig::isConfigured();
        } catch (\Throwable $e) {
            // e.g. cipp_client_secret no longer decrypts under this APP_KEY: CIPP was
            // not asked, so MCP may be.
            self::logReadFailure('REST settings', $e);
            $causes[] = 'PSA could not read its CIPP REST API settings';
        }

        if ($restConfigured) {
            try {
                $rows = app(CippClient::class)->get('api/ListTenants', [], $deadline);
            } catch (\TypeError $e) {
                // get() returned CIPP's unwrapped string Results: CIPP answered, with
                // its failure object. Not re-asked over MCP.
                self::logReadFailure('REST', $e);

                return [null, ['cause' => 'CIPP answered ListTenants with something other than a tenant list', 'signIn' => false]];
            } catch (\Throwable $e) {
                self::logReadFailure('REST', $e);
                if (CippClient::isDeadlineFailure($e)) {
                    // The tool's one budget is spent: no MCP read after it, and no
                    // sign-in diagnosis from the code of the attempt before it.
                    return [null, ['cause' => '', 'signIn' => false, 'timeout' => true]];
                }
                $signIn = self::isSignInFailure($e);
                $causes[] = $signIn ? 'CIPP REST API sign-in failed' : 'the CIPP REST API request failed';
            }
        }

        if ($rows === null) {
            $mcpEnabled = false;
            try {
                $mcpEnabled = CippConfig::isMcpRelayEnabled();
            } catch (\Throwable $e) {
                // e.g. cipp_mcp_client_secret no longer decrypts under this APP_KEY.
                self::logReadFailure('MCP settings', $e);
            }

            if ($mcpEnabled) {
                try {
                    $rows = app(CippMcpClient::class)->callTool('ListTenants', []);
                } catch (CippMcpAuthException $e) {
                    self::logReadFailure('MCP', $e);
                    $signIn = true;
                    $causes[] = 'CIPP MCP sign-in failed';
                } catch (\Throwable $e) {
                    self::logReadFailure('MCP', $e);
                    $causes[] = 'the CIPP MCP request failed';
                }
            } elseif ($causes === []) {
                $causes[] = 'neither the CIPP REST API nor CIPP MCP is configured';
            }
        }

        if ($rows === null) {
            return [null, ['cause' => implode('; ', $causes), 'signIn' => $signIn]];
        }

        if (! self::isUsableTenantList($rows)) {
            return [null, ['cause' => 'CIPP answered ListTenants with something other than a tenant list', 'signIn' => false]];
        }

        Cache::put(self::TENANT_LIST_CACHE_KEY, $rows, self::TENANT_LIST_TTL_SECONDS);

        return [$rows, null];
    }

    /**
     * A failed CIPP sign-in rather than a failed request: MCP's own auth exception,
     * an HTTP 401/403, or CippClient::getToken()'s OAuth failure.
     */
    private static function isSignInFailure(\Throwable $e): bool
    {
        return $e instanceof CippMcpAuthException
            || in_array((int) $e->getCode(), [401, 403], true)
            || str_starts_with($e->getMessage(), 'CIPP OAuth ');
    }

    /** The upstream text goes to the log only: it can describe our credentials. */
    private static function logReadFailure(string $transport, \Throwable $e): void
    {
        Log::warning("[CippTenantScope] CIPP tenant list could not be read over {$transport} for the alias check", [
            'exception' => $e::class,
            'error' => mb_substr($e->getMessage(), 0, 300),
        ]);
    }

    /**
     * A key that selects a tenant (or reads across tenants) on a CIPP endpoint. The
     * dynamic catalog publishes the vendor's own parameter list, and the vendor's read
     * catalog carries tenantFilter / TenantFilter / tenantId / TenantId / customerId /
     * AllTenantSelector / includeAllTenants / ReverseTenantLookup / vendorTenantIds
     * (CIPP-API Config/openapi.json). QueueId is here too: Get-GraphRequestList.ps1
     * reads cached rows by QueueId alone, with no tenant term, so a queue id from
     * another tenant returns that tenant's rows. Matching is case-insensitive because
     * PowerShell's $Request.Query is.
     */
    public static function isTenantSelectorKey(string $key): bool
    {
        return preg_match('/tenant|customerid|^queueid$/i', $key) === 1;
    }
}
