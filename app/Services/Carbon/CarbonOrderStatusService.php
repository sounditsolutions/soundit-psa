<?php

namespace App\Services\Carbon;

use Illuminate\Support\Facades\Log;

class CarbonOrderStatusService
{
    public const OPEN = ['draft', 'quote', 'payment_authorized'];

    public const STATUSES = ['draft', 'quote', 'payment_authorized', 'paid', 'partial_refund', 'canceled'];

    // get_order: one read-only capture, keys/types only (per the reviewer's ruling).
    // structuredContent is deliberately refused until its shape is ruled.
    public function parse(array $reply, string $code, int $orderId): ?string
    {
        $reason = null;
        $content = $reply['content'] ?? null;
        if (($reply['isError'] ?? null) === true) {
            $reason = 'is_error';
        } elseif (array_key_exists('structuredContent', $reply)) {
            $reason = 'shape';
        } elseif (! is_array($content) || ! array_is_list($content) || count($content) !== 1
            || ! is_array($content[0]) || ($content[0]['type'] ?? null) !== 'text' || ! is_string($content[0]['text'] ?? null)) {
            $reason = 'shape';
        } else {
            try {
                $body = json_decode($content[0]['text'], true, flags: JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $body = null;
            }
            if (! is_array($body) || array_is_list($body)) {
                $reason = 'shape';
            } elseif (($body['confirmationCode'] ?? null) !== $code) {
                $reason = 'code_mismatch';
            } elseif (! is_string($body['status'] ?? null)) {
                $reason = 'status_type';
            } elseif (! in_array($body['status'], self::STATUSES, true)) {
                $reason = 'unknown_status';
            } else {
                return $body['status'];
            }
        }

        Log::warning('[CarbonOrderStatus] Status not read', ['order_id' => $orderId, 'reason' => $reason]);

        return null;
    }
}
