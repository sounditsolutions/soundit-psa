<?php

namespace App\Services\Hdb;

use App\Support\HdbApiConfig;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

/**
 * Reports API reader. Field source: vendor swagger.yaml, v1.0.0-beta,
 * ReportDetail, ReportSummary, Pagination and ApiError (November 2026).
 * Synthetic tests prove handling of that contract, not live vendor behaviour.
 */
final class HdbApiReportClient
{
    public const SPRITE_FILENAME = 'instant-replay.png';

    public const MAX_PAGES = 10;

    public const MAX_ITEMS = 500;

    public const MAX_BODY_BYTES = 33_554_432;

    public const REQUIRED_SECTIONS = ['system', 'hardware', 'network', 'security', 'software', 'processes', 'events'];

    private const ERROR_CODES = ['authentication_required', 'forbidden', 'account_disabled', 'api_version_forbidden', 'internal_error', 'invalid_report_data', 'report_storage_unavailable', 'authentication_backend_unavailable', 'rate_limit_backend_unavailable', 'report_not_found', 'report_data_not_found', 'unsupported_report_os', 'instant_replay_redacted', 'invalid_request', 'invalid_cursor', 'endpoint_not_found', 'rate_limited', 'request_body_too_large'];

    private array $memo = [];

    private string $spriteBucket = 'none';

    private array $memoBuckets = [];

    public function spriteSizeBucket(): string
    {
        return $this->spriteBucket;
    }

    /** Read-only measurement seam; returns the HTTP client's decoded response. */
    public function detail(string $reportId): array
    {
        return $this->request('/reports/'.rawurlencode($reportId));
    }

    public function report(string $reportId, int $ticketId): HdbReportResult
    {
        return $this->readReport($reportId, $ticketId)->fromApi($this->reportHttpStatus, $this->reportCode);
    }

    private ?int $reportHttpStatus = null;

    private ?string $reportCode = null;

    private function readReport(string $reportId, int $ticketId): HdbReportResult
    {
        $this->reportHttpStatus = null;
        $this->reportCode = null;
        $this->spriteBucket = 'none';
        $memoKey = $ticketId.':'.$reportId;
        if (isset($this->memo[$memoKey])) {
            $this->spriteBucket = $this->memoBuckets[$memoKey] ?? 'none';
            $this->reportHttpStatus = $this->memo[$memoKey]->httpStatus;
            $this->reportCode = $this->memo[$memoKey]->apiCode;

            return $this->memo[$memoKey];
        }
        $read = $this->detail($reportId);
        $this->reportHttpStatus = $read['http_status'] ?? null;
        $this->reportCode = $read['code'] ?? null;
        if (isset($read['failure'])) {
            return $read['failure'];
        }
        $data = $read['data'];
        $bindings = array_filter([$data['ticket_id'] ?? null, $data['ticket_number'] ?? null], static fn ($value) => $value !== null);
        if ($bindings === [] || count(array_filter($bindings, fn ($value) => $this->matchesTicket($value, $ticketId))) !== count($bindings)) {
            return HdbReportResult::apiFailure(HdbReportStatus::Refused, 'report_ticket_mismatch');
        }
        $diagnostic = $data['diagnostics_redacted'] ?? null;
        $screenshots = $data['instant_replay']['redacted'] ?? null;
        $advertised = is_string($data['instant_replay']['sprite'] ?? null) && $data['instant_replay']['sprite'] !== '';
        if (! is_bool($diagnostic) || ! is_bool($screenshots)) {
            return HdbReportResult::apiFailure(HdbReportStatus::Refused, 'redaction_flag_unreadable');
        }
        $status = $data['status'] ?? null;
        if ($status !== 'complete') {
            return HdbReportResult::incomplete($reportId, [], in_array($status, ['pending', 'requested', 'pressed'], true) ? 'upload_incomplete' : 'upload_gate_missing')->withSpriteOutcome($screenshots ? 'redacted' : ($advertised ? null : 'not_advertised'));
        }
        $redaction = HdbReportRedaction::fromTicket(['redactDiagnostic' => $diagnostic, 'redactScreenshots' => $screenshots]);
        $metadata = array_intersect_key($data, array_flip(['ticket', 'user', 'status', 'created_at', 'os']));
        $report = $diagnostic ? [] : ($data['diagnostics'] ?? null);
        if (! is_array($report)) {
            return HdbReportResult::malformed($reportId);
        }
        unset($report['breach_exposure'], $report['identity_verification']);
        $sprite = null;
        $frames = [];
        $spriteDetail = null;
        $spriteReason = $screenshots ? 'redacted' : 'not_advertised';
        if ($screenshots === false && $advertised) {
            $image = $this->request('/reports/'.rawurlencode($reportId).'/instant-replay/sprite', [], true);
            [$sprite, $frames, $spriteReason, $spriteDetail] = isset($image['sprite_reason'])
                ? [null, [], $image['sprite_reason'], 'the sprite download is over the '.self::MAX_BODY_BYTES.'-byte limit; it was not kept']
                : (isset($image['failure']) ? [null, [], 'fetch_failed', null] : $this->sprite($image['bytes'] ?? null));
        }
        $missing = $diagnostic ? [] : array_values(array_diff(self::REQUIRED_SECTIONS, array_keys($report)));
        if ($missing !== []) {
            return HdbReportResult::degraded($reportId, $metadata, $report, $missing, $redaction, $sprite)->withFrames($frames)->withSpriteOutcome($spriteReason, $spriteDetail);
        }

        $result = HdbReportResult::fetched($reportId, $metadata, $report, $redaction, $sprite)->withFrames($frames)->withSpriteOutcome($spriteReason, $spriteDetail);
        if ($spriteReason === 'fetch_failed') {
            return $result;
        }
        $this->memoBuckets[$memoKey] = $this->spriteBucket;

        return $this->memo[$memoKey] = $result->fromApi($this->reportHttpStatus, $this->reportCode);
    }

    /**
     * The sprite as either the newest frames (when its PNG carries the
     * `hdbc` frame layout chunk and they can be cropped) or, otherwise, the
     * whole image under the attachment byte and pixel ceilings, as before
     * frames existed. An omission carries a reason (the frame path's, when a
     * layout was present) and, where bytes arrived, a detail with the byte
     * count and W x H.
     *
     * @return array{?string, list<array{png: string, width: int, height: int, index: int, total: int}>, ?string, ?string}
     */
    private function sprite(?string $bytes): array
    {
        if ($bytes === null) {
            return [null, [], 'fetch_failed', null];
        }
        $size = @getimagesizefromstring($bytes);
        if ($size !== false) {
            $pixels = $size[0] * $size[1];
            $this->spriteBucket = $pixels <= 1_000_000 ? '<=1MP' : ($pixels <= 4_000_000 ? '<=4MP' : '>4MP');
        }
        $info = HdbSpriteFrames::inspect($bytes);
        $facts = static fn (?array $info) => strlen($bytes).' bytes'.($info === null ? '' : ', '.$info['width'].'x'.$info['height'].' px');
        $refused = null;
        if ($info !== null && $info['layouts'] > 0) {
            $out = HdbSpriteFrames::extract($bytes, $info);
            if (isset($out['frames'])) {
                return [null, $out['frames'], null, null];
            }
            // Card 6Xiehny3 condition 4: frames that cannot be cropped fall back to
            // the whole image exactly as before frames existed. Only when that is
            // omitted too does the frame path's reason come back.
            $refused = [null, [], $out['reason'], $out['detail'].' (sprite: '.$facts($info).')'];
        }
        if (strlen($bytes) > \App\Services\Assistant\AssistantToolExecutor::MAX_ATTACHMENT_BYTES) {
            return $refused ?? [null, [], 'oversize', 'the sprite carries no frame layout and is over the '.\App\Services\Assistant\AssistantToolExecutor::MAX_ATTACHMENT_BYTES.'-byte whole-image limit (sprite: '.$facts($info).')'];
        }
        if ($size === false || ($size['mime'] ?? '') !== 'image/png') {
            return $refused ?? [null, [], 'not_image', 'the sprite is not a readable PNG (sprite: '.$facts($info).')'];
        }
        if ($size[0] * $size[1] > \App\Services\Assistant\AssistantToolExecutor::MAX_IMAGE_PIXELS) {
            return $refused ?? [null, [], 'oversize', 'the sprite carries no frame layout and is over the '.\App\Services\Assistant\AssistantToolExecutor::MAX_IMAGE_PIXELS.'-pixel whole-image limit (sprite: '.$facts($info).')'];
        }
        $encoded = app(\App\Services\AttachmentService::class)->resizeImageBytesForAi($bytes, 'image/png');

        return $encoded === null
            ? ($refused ?? [null, [], 'not_image', 'the sprite could not be decoded (sprite: '.$facts($info).')'])
            : [base64_decode($encoded, true), [], null, null];
    }

    public function matchesTicket(mixed $value, int $ticketId): bool
    {
        return (is_int($value) || is_string($value)) && (string) $value === (string) $ticketId;
    }

    /** Bounded walk: exhaustion is explicit, including after empty pages. */
    public function listReports(array $filters): array
    {
        $items = [];
        $filters = array_intersect_key($filters, array_flip(['group', 'since', 'before', 'lookup_id', 'endpoint_id', 'endpoints']));
        $filters['limit'] = self::MAX_ITEMS;
        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $read = $this->request('/reports', $filters);
            if (isset($read['failure'])) {
                return $read + ['items' => $items, 'exhausted' => false];
            }
            $data = $read['data'];
            if (! is_array($data['data'] ?? null) || ! array_is_list($data['data']) || ! is_array($data['pagination'] ?? null) || ! array_key_exists('next_cursor', $data['pagination'])) {
                return ['items' => $items, 'exhausted' => false, 'failure' => HdbReportResult::malformed('')];
            }
            foreach ($data['data'] as $item) {
                if (! is_array($item)) {
                    return ['items' => $items, 'exhausted' => false, 'failure' => HdbReportResult::malformed('')];
                }
                unset($item['breach_exposure'], $item['identity_verification']);
                if (count($items) >= self::MAX_ITEMS) {
                    return ['items' => $items, 'exhausted' => false];
                }
                $items[] = $item;
            }
            $cursor = $data['pagination']['next_cursor'];
            if ($cursor === null) {
                return ['items' => $items, 'exhausted' => true];
            }
            if (! is_string($cursor) || $cursor === '') {
                return ['items' => $items, 'exhausted' => false, 'failure' => HdbReportResult::malformed('')];
            }
            $filters['cursor'] = $cursor;
        }

        return ['items' => $items, 'exhausted' => false];
    }

    private function request(string $path, array $query = [], bool $binary = false): array
    {
        $key = HdbApiConfig::apiKey();
        if ($key === null) {
            return ['failure' => HdbReportResult::unauthenticated('', 'api_key_missing')];
        }
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $response = Http::withToken($key)->accept($binary ? 'image/png' : 'application/json')->connectTimeout(5)->timeout(30)
                    ->withoutRedirecting()->withOptions(['on_headers' => static function ($response) {
                        if ((int) $response->getHeaderLine('Content-Length') > self::MAX_BODY_BYTES) {
                            throw new \LengthException;
                        }
                    }, 'progress' => static function ($total, $downloaded) {
                        if ($downloaded > self::MAX_BODY_BYTES) {
                            throw new \LengthException;
                        }
                    }])->get(HdbApiConfig::BASE_URL.$path, $query);
            } catch (\Throwable $e) {
                for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
                    if ($binary && $cause instanceof \LengthException) {
                        return ['sprite_reason' => 'oversize'];
                    }
                }
                Log::warning('hdb_api', ['exception_class' => $e::class]);

                return ['failure' => HdbReportResult::unreachable('')];
            }
            $status = $response->status();
            $seconds = ctype_digit((string) $response->header('Retry-After')) ? (int) $response->header('Retry-After') : null;
            if ($status === 429 && $attempt === 0 && $seconds !== null && $seconds <= 5) {
                Sleep::for($seconds)->seconds();

                continue;
            }
            break;
        }
        $body = $response->body();
        $decoded = strlen($body) <= self::MAX_BODY_BYTES ? json_decode($body, true) : null;
        $rawCode = is_array($decoded) ? ($decoded['error']['code'] ?? null) : null;
        $code = in_array($rawCode, self::ERROR_CODES, true) ? $rawCode : null;
        if ($status !== 200) {
            Log::warning('hdb_api', ['http_status' => $status, 'code' => $code, 'request_id' => $response->header('X-Request-ID')]);
            $failure = match (true) {
                $status === 401 => HdbReportResult::unauthenticated('', 'api_key_rejected'),
                $status === 403 => HdbReportResult::unauthenticated('', 'api_permission_missing'),
                $status === 404 => HdbReportResult::unreachable('', 'report_not_found'),
                $status === 429 => HdbReportResult::apiFailure(HdbReportStatus::Unreachable, 'rate_limited', $seconds),
                $status === 422 => HdbReportResult::unreachable('', 'unsupported_report_os'),
                $status >= 500 => HdbReportResult::unreachable(''),
                default => HdbReportResult::malformed('', 'unexpected_response'),
            };

            return ['failure' => $failure, 'http_status' => $status, 'code' => $code];
        }
        if (strlen($body) > self::MAX_BODY_BYTES) {
            return ['failure' => HdbReportResult::malformed(''), 'http_status' => $status];
        }
        if ($binary) {
            return ['bytes' => $body, 'http_status' => $status];
        }
        if (! is_array($decoded) || array_is_list($decoded)) {
            return ['failure' => HdbReportResult::malformed(''), 'http_status' => $status];
        }
        unset($decoded['breach_exposure'], $decoded['identity_verification']);

        return ['data' => $decoded, 'http_status' => $status];
    }
}
