<?php

namespace App\Services\Unifi;

use App\Support\UnifiConfig;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;

/**
 * UniFi Site Manager API client (psa-1ynqc). READ-ONLY.
 *
 * SHAPE SOURCE — do not re-derive these field names from memory or from vendor docs
 * prose. Every endpoint, parameter and field below is taken from the vendor's own
 * machine-readable spec:
 *
 *   https://developer.ui.com/site-manager/v1.0.0/openapi.json   (OpenAPI 3.0.3, v1.0.0)
 *
 * The developer portal renders client-side, so fetching the HTML gives an empty shell —
 * the JSON above is the producer. Vendor example payloads from that same spec are
 * committed verbatim as tests/Fixtures/unifi/*.json and are what the tests assert on.
 *
 * FOUR SHAPE FACTS A NAIVE WRAPPER GETS WRONG:
 *  1. Envelope. Every response is {data, httpStatusCode, traceId, nextToken}. The
 *     pagination cursor is the TOP-LEVEL `nextToken` — not nested under `pagination`
 *     (Huntress's shape) and not an offset. Page size is the `pageSize` query param.
 *  2. GET /v1/devices is grouped BY HOST. data[] rows are HOSTS ({hostId, hostName,
 *     updatedAt, devices[]}); the actual devices are the nested array. Iterating data[]
 *     as devices yields rows with no mac/model/status whatsoever.
 *  3. isp-metrics wan{} MIXES casing inside one object: avgLatency, maxLatency,
 *     packetLoss, ispName, ispAsn, downtime, uptime are camelCase — but download_kbps
 *     and upload_kbps are snake_case. Never "normalize" these.
 *  4. Auth is the X-API-Key request header (components.securitySchemes).
 *
 * DEGRADED READS FAIL LOUD. An envelope that arrives without its `data` key is drift
 * or an upstream fault, never "no results" — it throws rather than returning []. For a
 * network-health surface a confident empty answer is worse than an error (CLAUDE.md).
 *
 * WRITES ARE OUT OF SCOPE. The spec also exposes /v1/connector/consoles/{id}/*path,
 * a generic passthrough to a console's local Network API that supports POST/PUT/PATCH/
 * DELETE. This client deliberately does not implement it in any form.
 */
class UnifiClient
{
    /** Documented values for the isp-metrics {type} path segment. */
    public const METRIC_TYPES = ['5m', '1h'];

    private Client $http;

    /**
     * @param  array{api_key?: string, base_url?: string}  $config
     * @param  Client|null  $http  Injectable transport (test seam). When null the
     *                             default API-key Guzzle client is built from config.
     */
    public function __construct(
        private readonly array $config,
        ?Client $http = null,
    ) {
        $this->http = $http ?? new Client([
            'base_uri' => rtrim($this->config['base_url'] ?? UnifiConfig::DEFAULT_BASE_URL, '/').'/',
            'timeout' => 30,
        ]);
    }

    public static function fromConfig(): self
    {
        return new self([
            'api_key' => UnifiConfig::get('api_key') ?? '',
            'base_url' => UnifiConfig::baseUrl(),
        ]);
    }

    /**
     * List UniFi consoles (hosts) the account administers.
     *
     * Rows: id, hardwareId, type, ipAddress, owner, isBlocked, registrationTime,
     * lastConnectionStateChange, latestBackupTime, userData, reportedState.
     *
     * @return array<string, mixed>
     */
    public function listHosts(array $params = []): array
    {
        return $this->getEnveloped('v1/hosts', $params);
    }

    /** @return array<string, mixed> */
    public function getHost(string $id, array $params = []): array
    {
        return $this->getEnveloped('v1/hosts/'.rawurlencode($id), $params);
    }

    /**
     * List sites across all administered consoles.
     *
     * Rows: siteId, hostId, permission, isOwner, meta{desc,gatewayMac,name,timezone},
     * statistics{counts{...}, gateway{...}, internetIssues[], ispInfo{name,organization},
     * percentages{wanUptime}}.
     *
     * @return array<string, mixed>
     */
    public function listSites(array $params = []): array
    {
        return $this->getEnveloped('v1/sites', $params);
    }

    /**
     * List devices, GROUPED BY HOST (see shape fact 2). Use flattenDevices() to get a
     * flat device list that still carries its host attribution.
     *
     * @return array<string, mixed>
     */
    public function listDevices(array $params = []): array
    {
        return $this->getEnveloped('v1/devices', $params);
    }

    /**
     * ISP / WAN telemetry.
     *
     * @param  string  $type  '5m' or '1h'. 5m samples are retained >=24h, 1h >=30d.
     * @param  array<string, mixed>  $params  Either `duration` (24h for 5m; 7d|30d for 1h)
     *                                        OR beginTimestamp+endTimestamp (RFC3339) —
     *                                        the vendor documents these as mutually exclusive.
     * @return array<string, mixed>
     */
    public function getIspMetrics(string $type, array $params = []): array
    {
        if (! in_array($type, self::METRIC_TYPES, true)) {
            throw new UnifiClientException(
                "Unsupported isp-metrics type '{$type}'; expected one of: ".implode(', ', self::METRIC_TYPES)
            );
        }

        return $this->getEnveloped('v1/isp-metrics/'.$type, $params);
    }

    public function isHealthy(): bool
    {
        try {
            $this->listSites(['pageSize' => '1']);

            return true;
        } catch (UnifiClientException) {
            return false;
        }
    }

    /**
     * Flatten the host-grouped /v1/devices payload into a device list, carrying the
     * owning host's id and name down onto each row so a flat device stays attributable.
     *
     * @param  array<int, mixed>  $groups  The `data` array from listDevices().
     * @return array<int, array<string, mixed>>
     */
    public function flattenDevices(array $groups): array
    {
        $devices = [];

        foreach ($groups as $group) {
            if (! is_array($group)) {
                continue;
            }

            foreach ((array) ($group['devices'] ?? []) as $device) {
                if (! is_array($device)) {
                    continue;
                }

                $devices[] = $device + [
                    'hostId' => $group['hostId'] ?? null,
                    'hostName' => $group['hostName'] ?? null,
                    'hostUpdatedAt' => $group['updatedAt'] ?? null,
                ];
            }
        }

        return $devices;
    }

    /**
     * GET an enveloped endpoint and hand back the decoded envelope.
     *
     * The `data` key is REQUIRED: its absence means the response drifted or the
     * upstream degraded, and returning [] there would read to an agent as a clean
     * "nothing found" on a security/health surface. Fail loud instead.
     *
     * @return array<string, mixed>
     */
    private function getEnveloped(string $endpoint, array $params = []): array
    {
        $response = $this->request('GET', $endpoint, ['query' => $params]);

        if (! array_key_exists('data', $response)) {
            throw new UnifiClientException(
                "UniFi API response for {$endpoint} carried no `data` envelope key ".
                '(keys: '.(implode(', ', array_keys($response)) ?: 'none').'). '.
                'Treating this as drift rather than an empty result.'
            );
        }

        return $response;
    }

    /**
     * Internal request method. The API key is sent as X-API-Key and is never logged.
     *
     * @return array<string, mixed>
     */
    private function request(string $method, string $endpoint, array $options = []): array
    {
        $options['headers'] = array_merge($options['headers'] ?? [], [
            'Accept' => 'application/json',
            'X-API-Key' => $this->config['api_key'] ?? '',
        ]);

        // 429 is a documented response on every list endpoint. A bump is transient —
        // honor Retry-After (falling back to exponential backoff) and retry a bounded
        // number of times rather than surfacing the first one as an error.
        $maxAttempts = 3;
        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                $response = $this->http->request($method, $endpoint, $options);
                break;
            } catch (GuzzleException $e) {
                $status = $e instanceof RequestException && $e->getResponse() !== null
                    ? $e->getResponse()->getStatusCode()
                    : 0;

                if ($status === 429 && $attempt < $maxAttempts) {
                    $retryAfter = 2 ** $attempt;
                    // Distinguish an absent Retry-After from a present "0" — PHP's ?:
                    // treats the string "0" as falsy, which would wrongly ignore a
                    // server asking us to retry immediately.
                    if ($e instanceof RequestException && $e->getResponse() !== null) {
                        $header = $e->getResponse()->getHeaderLine('Retry-After');
                        if (is_numeric($header)) {
                            $retryAfter = (int) $header;
                        }
                    }
                    Log::info("[UnifiClient] Rate limited on {$endpoint}, retrying in {$retryAfter}s");
                    if ($retryAfter > 0) {
                        sleep($retryAfter);
                    }

                    continue;
                }

                Log::error("[UnifiClient] {$method} {$endpoint} failed: {$e->getMessage()}");
                throw new UnifiClientException(
                    "UniFi API error: {$e->getMessage()}", $e->getCode(), $e
                );
            }
        }

        $decoded = json_decode((string) $response->getBody(), true);

        return is_array($decoded) ? $decoded : [];
    }
}
