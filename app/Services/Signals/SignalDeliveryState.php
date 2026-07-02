<?php

namespace App\Services\Signals;

use App\Models\SignalDelivery;
use App\Models\SignalDestination;
use GuzzleHttp\Exception\ConnectException;
use Illuminate\Support\Str;

class SignalDeliveryState
{
    public const TERMINAL_STATUSES = ['acked', 'suppressed', 'timed_out', 'failed'];

    public static function safeFailureMessage(\Throwable|string $error, string $fallback): string
    {
        if ($error instanceof ConnectException) {
            return 'connect failed';
        }

        $message = trim($error instanceof \Throwable ? $error->getMessage() : $error);
        if ($message === 'connect failed') {
            return $message;
        }

        if (preg_match('/^HTTP\s+\d{3}(?:\s+[-A-Za-z0-9 ._]+)?$/', $message) === 1) {
            return Str::limit($message, 500, '');
        }

        return $fallback;
    }

    public static function markDelivered(SignalDestination $destination, SignalDelivery $delivery): void
    {
        $now = now();

        $delivery->forceFill([
            'status' => 'delivered',
            'delivered_at' => $now,
            'error' => null,
        ])->save();

        $destination->forceFill([
            'last_delivery_at' => $now,
            'last_delivery_status' => 'delivered',
            'last_error' => null,
        ])->save();
    }

    public static function markFailed(SignalDestination $destination, SignalDelivery $delivery, string $error): void
    {
        $now = now();
        $error = Str::limit($error, 500, '');

        $delivery->forceFill([
            'status' => 'failed',
            'error' => $error,
        ])->save();

        $destination->forceFill([
            'last_delivery_at' => $now,
            'last_delivery_status' => 'failed',
            'last_error' => $error,
        ])->save();
    }

    public static function markSuppressed(SignalDelivery $delivery, string $reason): void
    {
        $delivery->forceFill([
            'status' => 'suppressed',
            'error' => $reason,
        ])->save();
    }
}
