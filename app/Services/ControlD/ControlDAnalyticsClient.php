<?php

namespace App\Services\ControlD;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;

class ControlDAnalyticsClient
{
    private Client $http;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $statsEndpoint,
    ) {
        $this->http = new Client([
            'base_uri' => "https://{$this->statsEndpoint}.analytics.controld.com/",
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
        ]);
    }

    /**
     * Get DNS activity log for a sub-organization.
     *
     * @param  string       $orgPk       Sub-organization PK
     * @param  string       $startTime   RFC3339 timestamp
     * @param  string       $endTime     RFC3339 timestamp
     * @param  string|null  $endpointId  Filter to specific device resolver ID
     * @param  int          $page        Page number (0-indexed, 100 per page)
     * @return array        Array of DNS query records
     */
    public function getActivityLog(
        string $orgPk,
        string $startTime,
        string $endTime,
        ?string $endpointId = null,
        int $page = 0,
    ): array {
        $query = [
            'startTime' => $startTime,
            'endTime' => $endTime,
        ];

        if ($endpointId) {
            $query['endpointId'] = $endpointId;
        }

        if ($page > 0) {
            $query['page'] = $page;
        }

        try {
            $response = $this->http->request('GET', 'v2/activity-log', [
                'query' => $query,
                'headers' => [
                    'X-Force-Org-Id' => $orgPk,
                ],
            ]);
        } catch (GuzzleException $e) {
            Log::error("[ControlDAnalytics] Activity log query failed (org: {$orgPk}): {$e->getMessage()}");
            throw new ControlDClientException(
                "Control D Analytics error: {$e->getMessage()}", $e->getCode(), $e
            );
        }

        $body = (string) $response->getBody();
        $data = json_decode($body, true) ?? [];

        return $data['body']['queries'] ?? [];
    }

    /**
     * Check if the analytics endpoint is reachable with the configured token.
     */
    public function isHealthy(string $orgPk): bool
    {
        try {
            $now = now();
            $this->getActivityLog(
                $orgPk,
                $now->subMinute()->toIso8601ZuluString(),
                $now->toIso8601ZuluString(),
            );

            return true;
        } catch (ControlDClientException) {
            return false;
        }
    }
}
