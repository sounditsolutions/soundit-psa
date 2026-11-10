<?php

namespace App\Services\Carbon;

use App\Models\Client;
use App\Models\ClientHardwareModel;
use App\Support\CarbonHardwareConfig;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CarbonHardwareService
{
    public function __construct(private readonly CarbonMcpClient $carbon) {}

    private function refuse(string $key): never
    {
        throw ValidationException::withMessages(['hardware' => __('carbon_hardware.'.$key)]);
    }

    public function assertAvailable(Client $client): void
    {
        if (! CarbonHardwareConfig::isEnabled()) {
            $this->refuse('unavailable');
        }
        if (! $client->carbon_customer_id) {
            $this->refuse('unmapped');
        }
    }

    /** Captured MCP contract: mcp-capture-20261109 19:37Z; only id/name leave this seam. */
    public function customers(): array
    {
        if (! CarbonHardwareConfig::isEnabled()) {
            $this->refuse('unavailable');
        }
        $customers = [];
        $expectedTotal = null;
        for ($page = 0; $page < 50; $page++) {
            $result = $this->carbon->callTool('list_customers', ['page' => $page, 'pageSize' => 100]);
            $data = $result['structuredContent'] ?? null;
            if (($result['isError'] ?? false) === true || ! is_array($data) || ! is_array($data['customers'] ?? null) || ! array_is_list($data['customers']) || ! is_array($data['meta'] ?? null)) {
                $this->refuse('failed');
            }
            $meta = $data['meta'];
            foreach (['page', 'pageSize', 'totalRecords', 'totalPages'] as $key) {
                if (! is_int($meta[$key] ?? null) || $meta[$key] < 0) {
                    $this->refuse('failed');
                }
            }
            $expectedTotal ??= $meta['totalRecords'];
            if ($meta['page'] !== $page || $meta['pageSize'] < 1 || $expectedTotal !== $meta['totalRecords']) {
                $this->refuse('failed');
            }
            foreach ($data['customers'] as $customer) {
                if (! is_array($customer) || ! is_int($customer['id'] ?? null) || $customer['id'] < 1 || ! is_string($customer['name'] ?? null) || isset($customers[$customer['id']])) {
                    $this->refuse('failed');
                }
                $customers[$customer['id']] = ['id' => $customer['id'], 'name' => $customer['name']];
            }
            if ($page + 1 >= $meta['totalPages']) {
                if (count($customers) !== $expectedTotal) {
                    $this->refuse('failed');
                }

                return array_values($customers);
            }
            if ($data['customers'] === []) {
                $this->refuse('failed');
            }
        }
        $this->refuse('failed');
    }

    public function map(Client $client, ?int $customerId, bool $enabled): void
    {
        if (! CarbonHardwareConfig::isEnabled()) {
            $this->refuse('unavailable');
        }
        if ($customerId !== null && ! in_array($customerId, array_column($this->customers(), 'id'), true)) {
            $this->refuse('customer_invalid');
        }
        DB::transaction(function () use ($client, $customerId, $enabled) {
            $locked = Client::query()->lockForUpdate()->findOrFail($client->id);
            $locked->carbon_customer_id = $customerId;
            $locked->carbon_hardware_ordering_enabled = $enabled;
            $locked->save();
        });
    }

    /** The r2 ruled contract deliberately refuses even false/null/empty configuration keys. */
    public function product(string $part): array
    {
        $result = $this->carbon->callTool('get_product', ['mfgPartNo' => $part]);
        $data = $result['structuredContent'] ?? null;
        // CarbonMcpClient decodes JSON objects as associative arrays. A product must
        // have named fields; a JSON list cannot satisfy this contract.
        if (($result['isError'] ?? false) === true || ! is_array($data) || array_is_list($data)
            || ($data['mfgPartNo'] ?? null) !== $part
            || ! (is_int($data['price'] ?? null) || is_float($data['price'] ?? null)) || (float) $data['price'] <= 0
            || array_key_exists('configs', $data)
            || array_key_exists('requiredConfigs', $data)
            || array_key_exists('isConfigurable', $data)) {
            $this->refuse('unconfirmed');
        }
        if (! is_finite((float) $data['price']) || (float) $data['price'] > 99999999.99) {
            $this->refuse('unconfirmed');
        }

        return $data;
    }

    private function confirmMargin(string $sell, ?string $cost, bool $confirmed): void
    {
        if ($cost !== null && bccomp($sell, $cost, 2) < 0 && ! $confirmed) {
            $this->refuse('margin_confirm');
        }
    }

    public function add(Client $client, string $part, string $sell, bool $confirmed = false): ClientHardwareModel
    {
        $this->assertAvailable($client);
        $product = $this->product($part);
        $cost = number_format((float) $product['price'], 2, '.', '');

        return DB::transaction(function () use ($client, $part, $sell, $confirmed, $product, $cost) {
            $locked = Client::query()->lockForUpdate()->findOrFail($client->id);
            $this->assertAvailable($locked);
            $this->confirmMargin($sell, $cost, $confirmed);
            if ($locked->hardwareModels()->where('mfg_part_no', $part)->exists()) {
                $this->refuse('duplicate');
            }

            return $locked->hardwareModels()->create([
                'mfg_part_no' => $part,
                'label' => is_string($product['title'] ?? null) ? mb_substr($product['title'], 0, 255) : $part,
                'sell_price' => $sell,
                'carbon_price' => $cost,
                // Ruled: availability shows unknown. get_product inventory is not stored.
                'availability' => 'unknown',
                'synced_at' => now(),
            ]);
        });
    }

    public function price(Client $client, int $modelId, string $sell, bool $confirmed = false): ClientHardwareModel
    {
        return DB::transaction(function () use ($client, $modelId, $sell, $confirmed) {
            $locked = Client::query()->lockForUpdate()->findOrFail($client->id);
            $this->assertAvailable($locked);
            $model = $locked->hardwareModels()->lockForUpdate()->findOrFail($modelId);
            $this->confirmMargin($sell, $model->carbon_price, $confirmed);
            $model->sell_price = $sell;
            $model->save();

            return $model;
        });
    }

    public function remove(Client $client, int $modelId): void
    {
        DB::transaction(function () use ($client, $modelId) {
            $locked = Client::query()->lockForUpdate()->findOrFail($client->id);
            $this->assertAvailable($locked);
            $locked->hardwareModels()->findOrFail($modelId)->delete();
        });
    }
}
