<?php

namespace App\Services\Mcp;

use App\Models\Client;
use App\Services\Carbon\CarbonHardwareService;
use App\Support\CarbonHardwareConfig;
use App\Support\CarbonMcpConfig;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class StaffCarbonHardwareExecutor
{
    public function __construct(private readonly CarbonHardwareService $hardware) {}

    public function execute(string $name, array $arguments, int $clientId): array
    {
        if (! CarbonMcpConfig::isLive() || ! CarbonHardwareConfig::isEnabled()) {
            return ['error' => __('carbon_hardware.unavailable')];
        }
        if (! StaffCarbonHardwareTools::handles($name)) {
            return ['error' => 'Unknown hardware tool.'];
        }
        $client = Client::find($clientId);
        if (! $client) {
            return ['error' => 'Client not found.'];
        }
        try {
            $data = Validator::make($arguments, [
                'mfg_part_no' => [$name === 'hardware_add_approved_model' ? 'required' : 'sometimes', 'string', 'max:255'],
                'model_id' => [in_array($name, ['hardware_set_sell_price', 'hardware_remove_approved_model'], true) ? 'required' : 'sometimes', 'integer', 'min:1'],
                'sell_price' => [in_array($name, ['hardware_add_approved_model', 'hardware_set_sell_price'], true) ? 'required' : 'sometimes', 'regex:/^\d{1,8}(\.\d{1,2})?$/'],
                'confirm_below_cost' => ['sometimes', 'boolean'],
            ])->validate();
            $this->hardware->assertAvailable($client);
            $confirmed = (bool) ($data['confirm_below_cost'] ?? false);

            return match ($name) {
                'hardware_list_approved_models' => ['models' => $client->hardwareModels()->orderBy('id')->get()->toArray()],
                'hardware_add_approved_model' => ['model' => $this->hardware->add($client, $data['mfg_part_no'], (string) $data['sell_price'], $confirmed)->toArray()],
                'hardware_set_sell_price' => ['model' => $this->hardware->price($client, (int) $data['model_id'], (string) $data['sell_price'], $confirmed)->toArray()],
                'hardware_remove_approved_model' => $this->remove($client, (int) $data['model_id']),
            };
        } catch (ValidationException $e) {
            return ['error' => implode(' ', array_merge(...array_values($e->errors())))];
        } catch (\Throwable $e) {
            return ['error' => 'Hardware operation failed.', 'status' => (int) $e->getCode(), 'class' => class_basename($e)];
        }
    }

    private function remove(Client $client, int $modelId): array
    {
        $this->hardware->remove($client, $modelId);

        return ['removed' => true];
    }
}
