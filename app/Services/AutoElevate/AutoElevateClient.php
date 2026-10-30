<?php

namespace App\Services\AutoElevate;

use App\Support\AutoElevateConfig;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * Read-only Partner API client. Stage 1: a status-only connection check. Stage 2 adds
 * `getPage()`, a single validated list-page read used by AutoElevateReadService, which
 * retries a vendor 429 a bounded number of times before failing, outside web requests only
 * (see MAX_ATTEMPTS).
 * Never retains company data or vendor errors; never writes at the vendor.
 *
 * Vendor contract: AutoElevate Partner API (Beta) 1.0.0 OpenAPI
 * (https://partner-api-docs.autoelevate.com/openapi.json, verified 2026-09-17):
 * every list endpoint takes `take` (1..200, default 50) + `skip` (>= 0) and returns the
 * `{items: [...], totalCount: N}` envelope (both required). `totalCount` is the total
 * ignoring pagination and is documented to be 0 when `skip` is past the end.
 */
class AutoElevateClient
{
    public const BASE_URL = 'https://partner-api.autoelevate.com';

    /** Documented maximum page size. Larger values are capped server-side, so never ask for more. */
    public const MAX_TAKE = 200;

    /**
     * HTTP 429 handling for getPage() (card fh7wGneF). Measured 2026-09-24 on production: 57
     * back-to-back computersForCompany() reads returned 24 ok / 33 http_429; spaced 4 s or 8 s
     * apart, 8 of 8 ok. So a 429 is transient pacing, not a failure: wait and retry, bounded.
     *
     * MAX_ATTEMPTS counts requests in total (1 initial + 3 retries). The waits are
     * RETRY_BACKOFF_SECONDS in order. A vendor Retry-After that is a plain integer of at most
     * RETRY_AFTER_CEILING_SECONDS can LENGTHEN a wait but never shorten it (#3415): a
     * `Retry-After: 0` would otherwise spend every attempt back to back, which is the pacing
     * measured to fail. After the last attempt the read fails as `http_429` exactly as before,
     * so a degraded read still SCREAMS (C-56). Only 429 is retried; every other status fails on
     * its first answer.
     *
     * Worst case: 35 s of waiting with no Retry-After, 180 s if the vendor sends the 60 s
     * ceiling each time. The waits go through the Sleep facade so tests fake them.
     *
     * Retries only run when the app is running in the console (the artisan sync). A web request
     * (the client panel or the company mapping screens) makes one request and fails as
     * `http_429` straight away, so it never holds a worker through those waits.
     */
    public const MAX_ATTEMPTS = 4;

    /** @var list<int> seconds to wait before retry 1, 2, 3 */
    public const RETRY_BACKOFF_SECONDS = [5, 10, 20];

    public const RETRY_AFTER_CEILING_SECONDS = 60;

    public function checkConnection(): string
    {
        $key = $this->storedKey();
        if ($key === null) {
            return 'configuration';
        }

        try {
            $response = $this->pending($key)->get('/api/v1/companies', ['take' => 1]);
        } catch (\Throwable) {
            // Transport exceptions may contain credentials: no logging or chained exception.
            return 'transport';
        }

        // HTTP status alone is authoritative. Do not parse or persist any response body/header.
        return match ($response->status()) {
            200 => 'ok',
            400 => '400',
            401 => '401',
            403 => '403',
            406 => '406',
            429 => '429',
            default => 'error',
        };
    }

    /**
     * One list page, validated to the documented envelope.
     *
     * @param  array<string, int|string>  $query
     * @return array{items: list<array<string, mixed>>, totalCount: int}
     *
     * @throws AutoElevateReadException on configuration, transport, non-200 status or envelope drift
     */
    public function getPage(string $path, array $query): array
    {
        $key = $this->storedKey();
        if ($key === null) {
            throw new AutoElevateReadException('configuration');
        }

        // Interactive reads must fail fast and scream http_429 (C-56), not outlive a proxy timeout.
        $maxAttempts = app()->runningInConsole() ? self::MAX_ATTEMPTS : 1;

        for ($attempt = 1; ; $attempt++) {
            try {
                $response = $this->pending($key)->get($path, $query);
            } catch (\Throwable) {
                // Never chain: a transport exception may quote the request, including the key.
                throw new AutoElevateReadException('transport');
            }

            if ($response->status() !== 429 || $attempt >= $maxAttempts) {
                break;
            }

            // The Retry-After integer is the ONLY thing read from a 429; nothing is logged.
            Sleep::for(self::retryDelaySeconds($response->header('Retry-After'), $attempt))->seconds();
        }

        if ($response->status() !== 200) {
            throw new AutoElevateReadException('http_'.$response->status(), retryAfterS: $response->status() === 429
                ? self::cooldownSeconds($response->header('Retry-After')) : null);
        }

        $body = json_decode($response->body(), true);

        // Envelope proof: an object with `items` (list) and `totalCount` (non-negative int).
        // A bare array, a missing key, or a non-list `items` is drift — never "zero rows".
        if (! is_array($body) || array_is_list($body)
            || ! array_key_exists('items', $body) || ! is_array($body['items']) || ! array_is_list($body['items'])
            || ! array_key_exists('totalCount', $body) || ! is_int($body['totalCount']) || $body['totalCount'] < 0) {
            throw new AutoElevateReadException('envelope_drift');
        }

        foreach ($body['items'] as $item) {
            if (! is_array($item) || array_is_list($item)) {
                throw new AutoElevateReadException('row_drift');
            }
        }

        return ['items' => $body['items'], 'totalCount' => $body['totalCount']];
    }

    /**
     * One item read (`GET <path>`, e.g. /api/v1/computers/{id}): exactly ONE request, never a
     * retry or a sleep, in any process. Its only caller is the asset page's Sync now, which
     * runs inside a web request (the getPage() web rule, applied unconditionally).
     *
     * Errors are classified like getPage(): transport → `transport`; any non-200 →
     * `http_<status>` (a 429 also carries cooldownSeconds() of its Retry-After); a body that
     * is not a JSON object → `body_drift`. Never chains or logs vendor text (C-56).
     *
     * @return array<string, mixed>
     *
     * @throws AutoElevateReadException
     */
    public function getOne(string $path): array
    {
        $key = $this->storedKey();
        if ($key === null) {
            throw new AutoElevateReadException('configuration');
        }

        try {
            $response = $this->pending($key)->get($path);
        } catch (\Throwable) {
            // Never chain: a transport exception may quote the request, including the key.
            throw new AutoElevateReadException('transport');
        }

        if ($response->status() !== 200) {
            throw new AutoElevateReadException('http_'.$response->status(), retryAfterS: $response->status() === 429
                ? self::cooldownSeconds($response->header('Retry-After')) : null);
        }

        $body = json_decode($response->body(), true);
        if (! is_array($body) || array_is_list($body)) {
            throw new AutoElevateReadException('body_drift');
        }

        return $body;
    }

    /** Retry-After window used for the shared vendor cooldown: a plain integer 0..300, otherwise 60 (Q-2 B). */
    public const COOLDOWN_CAP_SECONDS = 300;

    public const COOLDOWN_FALLBACK_SECONDS = 60;

    /**
     * The shared vendor cooldown a 429 starts (design.md Item 3 step 8): the vendor's
     * Retry-After when it is a plain integer from 0 to COOLDOWN_CAP_SECONDS, otherwise
     * COOLDOWN_FALLBACK_SECONDS. Only this integer is ever read from the response.
     */
    public static function cooldownSeconds(string $retryAfter): int
    {
        $retryAfter = trim($retryAfter);
        if ($retryAfter !== '' && strlen($retryAfter) <= 3 && ctype_digit($retryAfter)
            && (int) $retryAfter <= self::COOLDOWN_CAP_SECONDS) {
            return (int) $retryAfter;
        }

        return self::COOLDOWN_FALLBACK_SECONDS;
    }

    /**
     * Seconds to wait before retry number $attempt (1-based): the fixed schedule step, raised
     * to the vendor's Retry-After when that is a plain non-negative integer of at most
     * RETRY_AFTER_CEILING_SECONDS. The header is a floor, never a replacement (#3415). An HTTP
     * date, a larger value or anything else is ignored and the schedule step is used.
     */
    public static function retryDelaySeconds(string $retryAfter, int $attempt): int
    {
        $schedule = self::RETRY_BACKOFF_SECONDS;
        $step = $schedule[max(1, min($attempt, count($schedule))) - 1];

        $retryAfter = trim($retryAfter);
        if ($retryAfter !== '' && strlen($retryAfter) <= 3 && ctype_digit($retryAfter)
            && (int) $retryAfter <= self::RETRY_AFTER_CEILING_SECONDS) {
            return max((int) $retryAfter, $step);
        }

        return $step;
    }

    private function storedKey(): ?string
    {
        try {
            $key = AutoElevateConfig::get('api_key');
        } catch (\Throwable) {
            return null;
        }
        if ($key === null || trim($key) === '' || strlen($key) > 4096
            || preg_match('/[\x00-\x1F\x7F]/', $key)) {
            return null;
        }

        return $key;
    }

    private function pending(string $key): PendingRequest
    {
        return Http::baseUrl(self::BASE_URL)
            ->acceptJson()->withToken($key)
            ->withHeaders(['X-Acknowledgment' => 'i-understand-this-is-beta-and-may-change'])
            ->connectTimeout(3)->timeout(10)
            ->withOptions(['allow_redirects' => false]);
    }
}
