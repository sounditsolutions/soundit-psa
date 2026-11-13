<?php

namespace App\Services\Carbon;

use App\Enums\NoteType;
use App\Enums\TicketPriority;
use App\Enums\TicketSource;
use App\Enums\TicketStatus;
use App\Enums\TicketType;
use App\Enums\WhoType;
use App\Models\Client;
use App\Models\ClientHardwareOrder;
use App\Models\Person;
use App\Models\TicketNote;
use App\Services\TicketService;
use App\Support\CarbonHardwareConfig;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CarbonHardwareOrderService
{
    public function __construct(private readonly CarbonMcpClient $carbon, private readonly TicketService $tickets) {}

    public function address(Client $client): array
    {
        return ['name' => trim($client->name ?? ''), 'addressOne' => trim($client->address_line1 ?? ''),
            'addressTwo' => trim($client->address_line2 ?? ''), 'city' => trim($client->city ?? ''),
            'state' => trim($client->state ?? ''), 'postalCode' => trim($client->postcode ?? ''),
            'country' => trim(CarbonHardwareConfig::country())];
    }

    /** OFF=OFF choke point for the portal page, its submit and its nav link. */
    public static function available(Client $client): bool
    {
        return CarbonHardwareConfig::isEnabled() && (bool) $client->carbon_customer_id && (bool) $client->carbon_hardware_ordering_enabled;
    }

    public function reason(Client $client, Person $person): ?string
    {
        if (! CarbonHardwareConfig::isEnabled()) {
            return 'portal_unavailable';
        }
        if (! $client->carbon_customer_id || ! $client->carbon_hardware_ordering_enabled) {
            return 'portal_unmapped';
        }
        if (! $person->company_wide_access) {
            return 'personal';
        }
        foreach (['name', 'addressOne', 'city', 'state', 'postalCode', 'country'] as $key) {
            if ($this->address($client)[$key] === '') {
                return 'address_incomplete';
            }
        }

        return null;
    }

    /** Ticket text prints money as the portal page does; stored values are unchanged. */
    private function money(string|int|float $amount): string
    {
        return '$'.number_format((float) $amount, 2);
    }

    private function refuse(string $key): never
    {
        throw ValidationException::withMessages(['hardware' => __('carbon_hardware.'.$key)]);
    }

    public function submit(Person $person, array $input): ClientHardwareOrder
    {
        // No request client id, price, customer, or address participates in scope/payload.
        $client = Client::findOrFail($person->client_id);
        if ($reason = $this->reason($client, $person)) {
            $this->refuse($reason);
        }
        if (Validator::make($input, ['idempotency_key' => 'required|uuid'])->fails()) {
            $this->refuse('invalid_request');
        }
        $existing = ClientHardwareOrder::where('idempotency_key', $input['idempotency_key'])->first();
        if ($existing) {
            abort_unless($existing->client_id === $client->id, 403);

            return $existing;
        }
        if (Validator::make($input, ['lines' => 'required|array|min:1|max:100',
            'lines.*.model_id' => 'required|integer|distinct', 'lines.*.quantity' => 'required|integer|min:1|max:1000000',
            'endUserPoNumber' => 'nullable|string|max:255'])->fails()) {
            $this->refuse('invalid_request');
        }
        $checked = [];
        foreach ($input['lines'] as $line) {
            $model = $client->hardwareModels()->find($line['model_id']);
            if (! $model) {
                $this->refuse('invalid_request');
            }
            $checked[$model->id] = [$model->mfg_part_no, $model->carbon_component_ids, $model->carbon_price];
            if ($model->carbon_component_ids !== null) {
                try {
                    $product = app(CarbonHardwareService::class)->product($model->mfg_part_no);
                    $drift = match (true) {
                        $product['carbon_component_ids'] !== $model->carbon_component_ids => 'components_moved',
                        $model->carbon_price === null => 'no_cached_price',
                        bccomp(number_format((float) $product['price'], 2, '.', ''), $model->carbon_price, 2) !== 0 => 'price_moved',
                        default => null,
                    };
                } catch (CarbonMcpException) {
                    $this->refuse('portal_unavailable');
                } catch (ValidationException $e) {
                    // A product-status refusal logs its own reason; every other refusal stays unconfirmed.
                    $drift = in_array(__('carbon_hardware.not_active'), $e->errors()['hardware'] ?? [], true) ? 'not_active' : 'unconfirmed';
                }
                if ($drift !== null) {
                    // Saved build not confirmed at submit: one warning log, then portal_unavailable.
                    Log::warning('[CarbonHardwareOrder] Saved build refused at submit', ['reason' => $drift, 'model_id' => $model->id, 'client_id' => $client->id]);
                    $this->refuse('portal_unavailable');
                }
            }
        }
        // Serialize reservation with staff edits. The durable reservation commits BEFORE draft HTTP.
        [$order, $arguments, $created] = DB::transaction(function () use ($client, $person, $input, $checked) {
            $client = Client::lockForUpdate()->findOrFail($client->id);
            if ($reason = $this->reason($client, $person)) {
                $this->refuse($reason);
            }
            $existing = ClientHardwareOrder::where('idempotency_key', $input['idempotency_key'])->first();
            if ($existing) {
                abort_unless($existing->client_id === $client->id, 403);

                return [$existing, [], false];
            }
            $lines = [];
            $items = [];
            $total = '0.00';
            foreach ($input['lines'] as $line) {
                $model = $client->hardwareModels()->find($line['model_id']);
                // Staff may remove/re-add an approval while the vendor read is in flight.
                if (! $model || ($checked[$model->id] ?? null) !== [$model->mfg_part_no, $model->carbon_component_ids, $model->carbon_price]) {
                    $this->refuse('invalid_request');
                }
                $quantity = (int) $line['quantity'];
                $total = bcadd($total, bcmul($model->sell_price, (string) $quantity, 2), 2);
                $lines[] = ['mfgPartNo' => $model->mfg_part_no, 'label' => $model->label, 'quantity' => $quantity, 'sell_price' => $model->sell_price];
                $item = ['mfgPartNo' => $model->mfg_part_no, 'quantity' => $quantity];
                if ($model->carbon_component_ids !== null) {
                    $item['components'] = array_map(fn (int $id) => ['id' => $id], $model->carbon_component_ids);
                }
                $items[] = $item;
            }
            if (bccomp($total, '99999999.99', 2) > 0) {
                $this->refuse('invalid_request');
            }
            $arguments = ['customerId' => (int) $client->carbon_customer_id, 'shippingAddress' => $this->address($client), 'items' => $items];
            if (! empty($input['endUserPoNumber'])) {
                $arguments['endUserPoNumber'] = $input['endUserPoNumber'];
            }
            $order = ClientHardwareOrder::create(['client_id' => $client->id, 'person_id' => $person->id,
                'idempotency_key' => $input['idempotency_key'], 'status' => 'pending', 'lines' => $lines, 'estimated_total' => $total]);

            return [$order, $arguments, true];
        });
        if (! $created) {
            return $order;
        }
        $result = [];
        try {
            $result = $this->carbon->createDraftOrderOnce($arguments);
        } catch (CarbonMcpException) {
            // Ambiguous delivery remains reserved, including preflight auth failures.
        } catch (\Throwable $e) {
            // Any other exception from the draft call is also unread: the draft may have gone out,
            // so it is never retried. Class and order id only, never the message text.
            Log::warning('[CarbonHardwareOrder] Draft submit threw', ['exception' => class_basename(get_debug_type($e)), 'order_id' => $order->id]);
        }
        $data = $result['structuredContent'] ?? null;
        $code = $data instanceof \stdClass ? ($data->confirmationCode ?? null) : null;
        $code = is_string($code) ? trim($code) : null;
        $read = ($result['isError'] ?? false) !== true && is_string($code) && $code !== ''
            && mb_strlen($code) <= 64 && preg_match('/^[\x20-\x7E]+$/D', $code) === 1;
        $keys = $data instanceof \stdClass ? array_keys(get_object_vars($data)) : [];
        sort($keys, SORT_STRING);
        $types = [];
        foreach (is_array($result['content'] ?? null) ? $result['content'] : [] as $entry) {
            // Only protocol type labels, never arbitrary response text.
            if (is_array($entry) && in_array($entry['type'] ?? null, ['text', 'image', 'audio', 'resource', 'resource_link'], true)) {
                $types[] = $entry['type'];
            }
        }
        $order->update(['status' => $read ? 'submitted' : 'unread', 'confirmation_code' => $read ? $code : null,
            'response_keys' => $read ? null : ['structuredContent' => array_slice($keys, 0, 20), 'content_types' => $types]]);
        DB::transaction(function () use ($order, $arguments, $read, $code) {
            $description = [];
            foreach ($order->lines as $line) {
                $description[] = $line['quantity'].' x '.$line['label'].' ('.$line['mfgPartNo'].') @ '.$this->money($line['sell_price']);
            }
            $description[] = __('carbon_hardware.estimate').': '.$this->money($order->estimated_total);
            $description[] = __('carbon_hardware.ship_to').': '.implode(', ', array_filter($arguments['shippingAddress']));
            $description[] = $read ? __('carbon_hardware.confirmation', ['code' => $code]) : __('carbon_hardware.ticket_unread');
            $ticket = $this->tickets->createTicket(['client_id' => $order->client_id, 'contact_id' => $order->person_id,
                'subject' => __('carbon_hardware.ticket_subject'), 'description' => implode("\n", $description),
                'source' => TicketSource::Portal, 'type' => TicketType::ServiceRequest, 'priority' => TicketPriority::P3,
                'status' => TicketStatus::New, 'opened_at' => now()], null);
            if (! $read) {
                // Staff-only: the description is portal-visible, this private note is not.
                TicketNote::create(['ticket_id' => $ticket->id, 'author_id' => null, 'author_name' => 'System',
                    'who_type' => WhoType::System, 'body' => __('carbon_hardware.check_carbon'),
                    'note_type' => NoteType::Note, 'is_private' => true, 'noted_at' => now()]);
            }
            $order->update(['ticket_id' => $ticket->id]);
        });

        return $order;
    }
}
