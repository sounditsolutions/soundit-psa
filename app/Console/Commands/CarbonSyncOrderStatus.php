<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\ClientHardwareOrder;
use App\Services\Carbon\CarbonMcpClient;
use App\Services\Carbon\CarbonOrderStatusService;
use App\Support\CarbonHardwareConfig;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CarbonSyncOrderStatus extends Command
{
    protected $signature = 'carbon:sync-order-status';

    protected $description = 'Read Carbon hardware request status';

    private const DEFERRED = 'carbon-order-status-deferred';

    private const SETTLED = ['paid', 'partial_refund'];

    public function handle(CarbonMcpClient $client, CarbonOrderStatusService $parser): int
    {
        if (! CarbonHardwareConfig::isEnabled()) {
            return self::SUCCESS;
        }

        $saved = (array) Cache::get(self::DEFERRED, []);
        $deferred = array_filter($saved, fn ($time) => is_int($time) && $time > now()->subDay()->getTimestamp());
        $selectable = ClientHardwareOrder::where('status', 'submitted')
            ->whereNotNull('confirmation_code')
            ->whereIn('client_id', Client::where('carbon_hardware_ordering_enabled', true)->select('id'))
            ->where(fn ($q) => $q->whereNull('carbon_status')->orWhereIn('carbon_status', CarbonOrderStatusService::OPEN)
                ->orWhere(fn ($q) => $q->whereIn('carbon_status', self::SETTLED)
                    ->where('carbon_status_checked_at', '<=', now()->subDay())
                    ->where('created_at', '>', now()->subDays(90))));

        // Prune against eligibility before applying the cap or failed-read ranking.
        $ids = (clone $selectable)->whereIn('id', array_keys($deferred))->pluck('id')->all();
        $deferred = array_intersect_key($deferred, array_flip($ids));
        $this->saveDeferred($deferred, $saved);
        ksort($deferred);
        asort($deferred);
        $rank = [];
        foreach (array_keys($deferred) as $i => $id) {
            array_push($rank, (int) $id, $i + 1);
        }

        $orders = $selectable
            ->orderByRaw('CASE WHEN carbon_status IN ('.implode(', ', array_fill(0, count(self::SETTLED), '?')).') THEN 1 ELSE 0 END', self::SETTLED)
            ->when($rank !== [], fn ($q) => $q->orderByRaw('CASE id'.str_repeat(' WHEN ? THEN ?', count($deferred)).' ELSE 0 END', $rank))
            ->orderByRaw('carbon_status_checked_at IS NOT NULL')
            ->orderBy('carbon_status_checked_at')->orderBy('id')->limit(25)->get();

        foreach ($orders as $order) {
            try {
                $reply = $client->callTool('get_order', ['confirmationCode' => $order->confirmation_code]);
            } catch (\Throwable $e) {
                Log::warning('[CarbonOrderStatus] Status not read', ['order_id' => $order->id, 'exception' => class_basename($e)]);
                $deferred[$order->id] = now()->getTimestamp();
                $this->saveDeferred($deferred, $saved);

                return self::FAILURE;
            }
            $status = $parser->parse($reply, $order->confirmation_code, $order->id);
            if ($status === null) {
                $deferred[$order->id] = now()->getTimestamp();
            } else {
                unset($deferred[$order->id]);
            }
            $this->saveDeferred($deferred, $saved);
            if ($status !== null) {
                // Compare-and-set avoids overwriting a concurrently checked row. Only these two columns change.
                DB::table('client_hardware_orders')->where('id', $order->id)
                    ->where('status', 'submitted')->where('confirmation_code', $order->confirmation_code)
                    ->where('carbon_status', $order->carbon_status)
                    ->where('carbon_status_checked_at', $order->getRawOriginal('carbon_status_checked_at'))
                    ->update(['carbon_status' => $status, 'carbon_status_checked_at' => now()]);
            }
        }

        return self::SUCCESS;
    }

    private function saveDeferred(array $deferred, array &$saved): void
    {
        if ($deferred != $saved) {
            Cache::put(self::DEFERRED, $deferred, now()->addDay());
            $saved = $deferred;
        }
    }
}
