<?php

namespace App\Services\Mesh;

use App\Models\Client;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * The two Mesh read tools, mesh_search_email_logs and mesh_get_email_events, in
 * ONE place for every surface that runs them: the staff Assistant, staff MCP
 * (which reaches them through AssistantToolExecutor) and the triage loop (card
 * 6abdcac2, the Huntress pattern from #4486 applied to Mesh).
 *
 * client_id is the key. It resolves through the client's stored Mesh mapping,
 * clients.mesh_customer_id, and through nothing else: there is no customer,
 * domain or mailbox fallback. from/to/subject/status only narrow the rows of
 * that one customer.
 *
 * WHAT THE VENDOR GIVES US, and what it does not. Mesh is closed-source and its
 * OpenAPI 1.0 document declares both routes as "200: No response body", so the
 * row shape is not specified anywhere we can read. GET api/emaillogs/ takes no
 * customer parameter: it answers with a partner-wide page, which PSA filters to
 * the client by each row's customer id (the `list` envelope and the
 * Customer-ID / "Customer Id" keys are what this code has read since the
 * initial release). GET api/emaillogs/events takes only queue_id, so the vendor
 * call itself cannot be bound to a customer. The binding is therefore made
 * here, in PSA:
 *   - search serves only rows whose customer id is this client's, and records
 *     the queue id of each row it served under this client's key;
 *   - events accepts a queue id only if this client's own search served it
 *     (QUEUE_TTL_SECONDS), and refuses an events answer naming another customer.
 *
 * Fails closed, with an error that says why, never with a cross-client read:
 *   - no client_id; a client_id that does not resolve to the client held;
 *   - a client not mapped to Mesh;
 *   - a Mesh customer id another PSA client also carries (trimmed,
 *     case-insensitive), because the rows could not be attributed to one client;
 *   - a search answer without a `list` array, or carrying an error;
 *   - a queue id this client's search did not serve, an empty or error events
 *     answer, or one naming another customer id.
 *
 * Key names are matched case- and separator-insensitively (Customer-ID,
 * "Customer Id", customer_id are one key), because the vendor's casing is not
 * documented and a missed key must never read as "no mail".
 */
final class MeshReadTools
{
    public const KEY_NOTE = 'Customer scope: client_id is the key (on a ticket, the ticket\'s client). The Mesh customer is resolved from that client\'s stored Mesh mapping (clients.mesh_customer_id); there is no customer, domain or mailbox fallback, and a customer id cannot be passed. A client not mapped to Mesh, or a Mesh customer mapped to more than one PSA client, is an error, never a cross-client search.';

    public const EVENTS_NOTE = 'queue_id must be one that mesh_search_email_logs returned for the SAME client_id within the last 24 hours; any other queue id is refused, because Mesh cannot tell PSA whose message it is. If you have a queue id from a ticket or a delivery-request email, search this client first (for example by the recipient in to) so the message is found in this client\'s own logs.';

    public const QUEUE_TTL_SECONDS = 86400;

    private const QUEUE_CACHE_PREFIX = 'mesh-read-scope:queue:';

    /**
     * The Mesh customer key for this client, normalised (trimmed, lowercased), or
     * an error payload.
     *
     * @return string|array{error: string}
     */
    public static function resolve(?Client $client, ?int $clientId): string|array
    {
        if ($clientId === null) {
            return ['error' => 'client_id is required: a Mesh read resolves its customer through the PSA client\'s stored Mesh mapping and does not search other customers.'];
        }

        if ($client === null || (int) $client->getKey() !== $clientId) {
            return ['error' => "PSA client {$clientId} was not found."];
        }

        $key = self::normaliseId($client->mesh_customer_id);
        if ($key === '') {
            return ['error' => "PSA client {$clientId} is not mapped to Mesh (it has no Mesh customer id). Map it in Settings > Mesh Customer Mapping; this read does not search other customers' mail."];
        }

        if (self::mappedToAnotherClient($key, $clientId)) {
            return ['error' => "PSA client {$clientId}'s Mesh customer is also mapped to another PSA client, so its mail cannot be attributed to one client. Fix the duplicate mapping in Settings > Mesh Customer Mapping."];
        }

        return $key;
    }

    /**
     * Whether any OTHER PSA client's stored Mesh customer id equals this key
     * (trimmed, case-insensitive). The unique index compares raw strings, so
     * "ABC" and " abc " can both be stored.
     */
    public static function mappedToAnotherClient(string $key, int $clientId): bool
    {
        return Client::whereKeyNot($clientId)
            ->whereNotNull('mesh_customer_id')
            ->pluck('mesh_customer_id')
            ->contains(fn (mixed $other): bool => self::normaliseId($other) === $key);
    }

    public static function normaliseId(mixed $value): string
    {
        return is_string($value) || is_int($value) ? mb_strtolower(trim((string) $value)) : '';
    }

    /**
     * mesh_search_email_logs: this client's rows of one partner-wide page.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function search(?Client $client, ?int $clientId, array $input, string $logPrefix): array
    {
        $key = self::resolve($client, $clientId);
        if (is_array($key)) {
            return $key;
        }

        $size = max(1, min((int) ($input['size'] ?? 20), 50));

        // Date range is required — Mesh returns empty without it
        $params = [
            '_from' => 0,
            '_size' => $size,
            'start' => gmdate('Y-m-d\TH:i:s', strtotime('-7 days')),
            'end' => gmdate('Y-m-d\TH:i:s'),
        ];
        foreach (['from', 'to', 'subject', 'status'] as $field) {
            if (! empty($input[$field])) {
                $params[$field] = $input[$field];
            }
        }

        try {
            $result = app(MeshClient::class)->get('api/emaillogs/', $params);
        } catch (\Throwable $e) {
            $reason = self::failureReason($e, 'the email-log search');
            Log::warning("{$logPrefix} Mesh log search failed", ['reason' => $reason, 'exception' => $e::class]);

            return ['error' => 'Mesh query failed: '.$reason];
        }

        $rows = $result['list'] ?? null;
        if (array_key_exists('error', $result) || ! is_array($rows) || ! array_is_list($rows)) {
            Log::warning("{$logPrefix} Mesh log search answered an unrecognised envelope", ['keys' => array_slice(array_keys($result), 0, 10)]);

            return ['error' => 'Mesh answered the email-log search in a shape PSA does not recognise (no list of rows), so nothing was read. This is not "no mail"; retry, and report it if it persists.'];
        }

        $served = [];
        $otherCustomer = 0;
        $unattributed = 0;
        foreach ($rows as $row) {
            $ids = is_array($row) ? self::customerIds($row) : [];
            if ($ids === []) {
                $unattributed++;
            } elseif (in_array($key, $ids, true)) {
                $served[] = $row;
            } else {
                $otherCustomer++;
            }
        }

        if ($rows !== [] && $unattributed === count($rows)) {
            Log::warning("{$logPrefix} Mesh log rows carry no customer id", ['rows' => count($rows)]);

            return ['error' => 'Mesh returned email-log rows without a customer id, so PSA cannot tell which client they belong to and served none. This is not "no mail"; report it.'];
        }

        $emails = array_slice($served, 0, $size);
        $this->rememberQueueIds((int) $clientId, $key, $emails, $logPrefix);

        $out = [
            'total' => count($served),
            'emails' => $emails,
        ];
        if ($otherCustomer > 0 || $unattributed > 0) {
            $out['withheld_other_customers'] = $otherCustomer;
            $out['withheld_unattributed'] = $unattributed;
            $out['scope_note'] = 'Mesh has no customer filter: it answered one partner-wide page of '.count($rows).' row(s), and PSA withheld every row that is not this client\'s. Rows of this client beyond that page were not read, so a short or empty list is not proof that no such mail exists. Narrow with to, from or subject.';
        }

        return $out;
    }

    /**
     * mesh_get_email_events: the trace of ONE message this client's search served.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function events(?Client $client, ?int $clientId, array $input, string $logPrefix): array
    {
        $key = self::resolve($client, $clientId);
        if (is_array($key)) {
            return $key;
        }

        $raw = $input['queue_id'] ?? null;
        $queueId = is_string($raw) || is_int($raw) ? trim((string) $raw) : '';
        if ($queueId === '') {
            return ['error' => 'queue_id is required'];
        }

        if (! $this->servedQueueId((int) $clientId, $key, $queueId)) {
            return ['error' => "queue_id {$queueId} was not returned by mesh_search_email_logs for PSA client {$clientId} in the last 24 hours, so it was not read: Mesh cannot tell PSA whose message a queue id is. Search this client's logs first (for example by recipient in to), then ask for the events of a queue_id from those results."];
        }

        try {
            $result = app(MeshClient::class)->get('api/emaillogs/events', ['queue_id' => $queueId]);
        } catch (\Throwable $e) {
            $reason = self::failureReason($e, 'the email-events read');
            Log::warning("{$logPrefix} Mesh events query failed", ['queue_id' => $queueId, 'reason' => $reason, 'exception' => $e::class]);

            return ['error' => 'Mesh query failed: '.$reason];
        }

        if ($result === [] || array_key_exists('error', $result)) {
            Log::warning("{$logPrefix} Mesh events answered an empty or error body", ['queue_id' => $queueId, 'keys' => array_slice(array_keys($result), 0, 10)]);

            return ['error' => "Mesh returned no usable events answer for queue_id {$queueId} (empty, or an error body), so nothing was read. This is not \"no events\"; retry, and report it if it persists."];
        }

        $ids = self::customerIds($result, 6);
        if ($ids !== [] && ! in_array($key, $ids, true)) {
            Log::warning("{$logPrefix} Mesh events named another customer", ['queue_id' => $queueId]);

            return ['error' => "Mesh's events answer for queue_id {$queueId} names a Mesh customer that is not PSA client {$clientId}'s, so it was withheld."];
        }

        return $result;
    }

    /**
     * Every customer id a row (or, with $depth, a nested answer) names, normalised.
     * A key counts when, lowercased with separators removed, it is "customerid".
     *
     * @param  array<mixed>  $node
     * @return list<string>
     */
    private static function customerIds(array $node, int $depth = 1): array
    {
        $ids = [];
        foreach ($node as $name => $value) {
            if (is_string($name) && preg_replace('/[^a-z]/', '', strtolower($name)) === 'customerid') {
                foreach ((array) $value as $id) {
                    if (($id = self::normaliseId($id)) !== '') {
                        $ids[] = $id;
                    }
                }
            } elseif ($depth > 1 && is_array($value)) {
                array_push($ids, ...self::customerIds($value, $depth - 1));
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Record, under this client and its customer key, the queue id of each row
     * served, so events can be bound to this client. A cache failure leaves the
     * id unrecorded, and events then refuses it: fail closed.
     *
     * @param  list<mixed>  $rows
     */
    private function rememberQueueIds(int $clientId, string $key, array $rows, string $logPrefix): void
    {
        foreach ($rows as $row) {
            foreach (is_array($row) ? $row : [] as $name => $value) {
                if (! is_string($name) || preg_replace('/[^a-z]/', '', strtolower($name)) !== 'queueid') {
                    continue;
                }
                $queueId = is_string($value) || is_int($value) ? trim((string) $value) : '';
                if ($queueId === '') {
                    continue;
                }
                try {
                    Cache::put($this->queueCacheKey($clientId, $queueId).':release', [
                        'customer' => $key,
                        // Measured emaillogs row fields, FkMQAfQG. Retain only
                        // delivery inputs, not subject/sender/body.
                        'Timestamp' => $row['Timestamp'] ?? null,
                        'Service' => $row['Service'] ?? null,
                        'recipients' => array_key_exists('Recipient', $row) ? $row['Recipient'] : ($row['To'] ?? null),
                    ], self::QUEUE_TTL_SECONDS);
                    Cache::put($this->queueCacheKey($clientId, $queueId), $key, self::QUEUE_TTL_SECONDS);
                } catch (\Throwable $e) {
                    Log::warning("{$logPrefix} Mesh queue id could not be recorded", ['exception' => $e::class]);
                }
            }
        }
    }

    /**
     * A failed read as a phrase safe to hand the caller and the log (C-56). A
     * MeshClientException reports through statusPhrase(): the HTTP status, or
     * that there was none. Its message is never used, because MeshClient
     * builds it from Guzzle's, which quotes the request URI, the host and a
     * summary of the vendor's body. Any other Throwable is reported by none of
     * its text either: the log line names its class.
     */
    private static function failureReason(\Throwable $e, string $what): string
    {
        return $e instanceof MeshClientException
            ? $e->statusPhrase($what)
            : "{$what} failed with an unexpected error; the PSA log names its class";
    }

    /** Delivery inputs are bound to the same served-queue/customer proof as events. */
    public function servedDeliveryRow(int $clientId, string $key, string $queueId): ?array
    {
        if (! $this->servedQueueId($clientId, $key, $queueId)) {
            return null;
        }
        try {
            $row = Cache::get($this->queueCacheKey($clientId, $queueId).':release');

            return is_array($row) && ($row['customer'] ?? null) === $key ? $row : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function servedQueueId(int $clientId, string $key, string $queueId): bool
    {
        try {
            return Cache::get($this->queueCacheKey($clientId, $queueId)) === $key;
        } catch (\Throwable) {
            return false;
        }
    }

    private function queueCacheKey(int $clientId, string $queueId): string
    {
        return self::QUEUE_CACHE_PREFIX.$clientId.':'.sha1(mb_strtolower($queueId));
    }
}
