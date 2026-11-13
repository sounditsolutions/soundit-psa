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

    protected $description = 'Read Carbon status for open hardware requests';

    private const DEFERRED = 'carbon-order-status-deferred';

    public function handle(CarbonMcpClient $client, CarbonOrderStatusService $parser): int
    {
        if (! CarbonHardwareConfig::isEnabled()) {
            return self::SUCCESS;
        }

        // A row whose read threw is read after the others, least recently failed first, until a read of it
        // does not throw; the list is dropped a day after it last changed.
        $deferred = array_filter((array) Cache::get(self::DEFERRED, []), 'is_int');
        ksort($deferred);
        asort($deferred);
        $rank = [];
        foreach (array_keys($deferred) as $i => $id) {
            array_push($rank, (int) $id, $i + 1);
        }

        $orders = ClientHardwareOrder::where('status', 'submitted')
            ->whereNotNull('confirmation_code')
            ->whereIn('client_id', Client::where('carbon_hardware_ordering_enabled', true)->select('id'))
            ->where(fn ($q) => $q->whereNull('carbon_status')->orWhereIn('carbon_status', CarbonOrderStatusService::OPEN))
            ->when($rank !== [], fn ($q) => $q->orderByRaw('CASE id'.str_repeat(' WHEN ? THEN ?', count($deferred)).' ELSE 0 END', $rank))
            ->orderByRaw('carbon_status_checked_at IS NOT NULL')
            ->orderBy('carbon_status_checked_at')->orderBy('id')->limit(25)->get();

        foreach ($orders as $order) {
            try {
                $reply = $client->callTool('get_order', ['confirmationCode' => $order->confirmation_code]);
            } catch (\Throwable $e) {
                Log::warning('[CarbonOrderStatus] Status not read', ['order_id' => $order->id, 'exception' => class_basename($e)]);
                $deferred[$order->id] = now()->getTimestamp();
                Cache::put(self::DEFERRED, $deferred, now()->addDay());

                return self::FAILURE;
            }
            if (isset($deferred[$order->id])) {
                unset($deferred[$order->id]);
                Cache::put(self::DEFERRED, $deferred, now()->addDay());
            }
            $status = $parser->parse($reply, $order->confirmation_code, $order->id);
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
}
