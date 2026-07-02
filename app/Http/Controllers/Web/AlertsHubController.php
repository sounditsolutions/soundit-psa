<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\McpToken;
use App\Models\SignalConfigLog;
use App\Models\SignalDelivery;
use App\Models\SignalDestination;
use App\Models\SignalEvent;
use App\Rules\SafeWebhookUrl;
use App\Services\Signals\Sinks\EmailSink;
use App\Services\Signals\Sinks\McpSink;
use App\Services\Signals\Sinks\WebhookSink;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AlertsHubController extends Controller
{
    private const SECRET_MASK = '••••••••';

    /** @var array<int, string> */
    private array $secretFields = ['address', 'wake_url', 'wake_secret', 'secret'];

    public function index()
    {
        return view('settings.alerts.index', [
            'destinations' => SignalDestination::query()
                ->orderBy('label')
                ->get()
                ->map(fn (SignalDestination $destination) => $this->decorateDestination($destination)),
            'mcpTokens' => McpToken::query()
                ->active()
                ->orderBy('label')
                ->get(['label']),
            'secretMask' => self::SECRET_MASK,
        ]);
    }

    public function store(Request $request)
    {
        $attributes = $this->validatedDestinationAttributes($request);
        $destination = SignalDestination::create($attributes);

        SignalConfigLog::record(
            $request->user()?->id,
            'created',
            $destination,
            $this->changes([], $this->snapshot($destination)),
        );

        return redirect()->route('settings.alerts.index')
            ->with('success', 'Destination created.');
    }

    public function update(Request $request, SignalDestination $destination)
    {
        $before = $this->snapshot($destination);
        $attributes = $this->validatedDestinationAttributes($request, $destination);

        $destination->forceFill($attributes)->save();

        SignalConfigLog::record(
            $request->user()?->id,
            'updated',
            $destination,
            $this->changes($before, $this->snapshot($destination)),
        );

        return redirect()->route('settings.alerts.index')
            ->with('success', 'Destination updated.');
    }

    public function toggle(Request $request, SignalDestination $destination)
    {
        $destination->forceFill(['enabled' => ! $destination->enabled])->save();

        SignalConfigLog::record(
            $request->user()?->id,
            $destination->enabled ? 'enabled' : 'disabled',
            $destination,
            ['enabled' => $destination->enabled],
        );

        return redirect()->route('settings.alerts.index')
            ->with('success', $destination->enabled ? 'Destination enabled.' : 'Destination disabled.');
    }

    public function test(Request $request, SignalDestination $destination)
    {
        $event = SignalEvent::create([
            'type_key' => 'system.test',
            'entity_type' => SignalDestination::class,
            'entity_id' => $destination->id,
            'summary' => 'Alerts Hub test delivery',
            'context' => ['category' => 'system'],
            'occurred_at' => now(),
        ]);

        $delivery = SignalDelivery::create([
            'event_id' => $event->id,
            'route_id' => null,
            'step_order' => 0,
            'destination_id' => $destination->id,
            'status' => 'pending',
        ]);

        try {
            $this->deliverToSink($destination, $event, $delivery);
            $this->markDeliveredIfStillPending($destination, $delivery);
        } catch (\Throwable $e) {
            $this->markFailedIfStillPending($destination, $delivery, $e);

            return redirect()->route('settings.alerts.index')
                ->with('error', 'Test signal failed: '.$delivery->fresh()->error);
        }

        return redirect()->route('settings.alerts.index')
            ->with('success', 'Test signal delivered.');
    }

    private function validatedDestinationAttributes(Request $request, ?SignalDestination $destination = null): array
    {
        $base = Validator::make($request->all(), [
            'label' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(['webhook', 'email', 'mcp'])],
        ])->validate();

        return match ($base['type']) {
            'webhook' => $this->webhookAttributes($request, $base, $destination),
            'email' => $this->emailAttributes($request, $base, $destination),
            'mcp' => $this->mcpAttributes($request, $base, $destination),
        };
    }

    /** @param  array<string, string>  $base */
    private function webhookAttributes(Request $request, array $base, ?SignalDestination $destination): array
    {
        $keepAddress = $this->keepsExisting($request->input('address'), $destination, 'address', 'webhook');

        Validator::make($request->all(), [
            'address' => [
                $keepAddress ? 'nullable' : 'required',
                'string',
                'max:2048',
                new SafeWebhookUrl('Alerts Hub webhook URL'),
            ],
        ])->validate();

        return [
            'label' => trim($base['label']),
            'type' => 'webhook',
            'address' => $keepAddress ? $destination?->address : $this->nullableTrim($request->input('address')),
            'mcp_token_label' => null,
            'wake_url' => null,
            'wake_secret' => null,
            'secret' => null,
            'enabled' => $destination?->enabled ?? true,
        ];
    }

    /** @param  array<string, string>  $base */
    private function emailAttributes(Request $request, array $base, ?SignalDestination $destination): array
    {
        $keepAddress = $this->keepsExisting($request->input('address'), $destination, 'address', 'email');

        Validator::make($request->all(), [
            'address' => [
                $keepAddress ? 'nullable' : 'required',
                'string',
                'max:254',
                'not_regex:/[\r\n]/',
                'email:rfc,strict',
            ],
        ])->validate();

        return [
            'label' => trim($base['label']),
            'type' => 'email',
            'address' => $keepAddress ? $destination?->address : $this->nullableTrim($request->input('address')),
            'mcp_token_label' => null,
            'wake_url' => null,
            'wake_secret' => null,
            'secret' => null,
            'enabled' => $destination?->enabled ?? true,
        ];
    }

    /** @param  array<string, string>  $base */
    private function mcpAttributes(Request $request, array $base, ?SignalDestination $destination): array
    {
        $keepWakeUrl = $this->keepsExisting($request->input('wake_url'), $destination, 'wake_url', 'mcp');
        $keepWakeSecret = $this->keepsExisting($request->input('wake_secret'), $destination, 'wake_secret', 'mcp');

        Validator::make($request->all(), [
            'mcp_token_label' => [
                'required',
                'string',
                'max:100',
                Rule::exists('mcp_tokens', 'label')->where(fn ($query) => $query->whereNull('revoked_at')),
            ],
            'wake_url' => [
                'nullable',
                'string',
                'max:2048',
                new SafeWebhookUrl('Alerts Hub wake URL'),
            ],
            'wake_secret' => ['nullable', 'string', 'max:1024'],
        ])->validate();

        $wakeUrl = $keepWakeUrl ? $destination?->wake_url : $this->nullableTrim($request->input('wake_url'));
        $wakeSecret = $keepWakeSecret ? $destination?->wake_secret : $this->nullableTrim($request->input('wake_secret'));
        if ($wakeUrl === null) {
            $wakeSecret = null;
        }

        if ($wakeUrl !== null && $wakeSecret === null) {
            throw ValidationException::withMessages([
                'wake_secret' => 'The wake secret field is required when wake url is present.',
            ]);
        }

        return [
            'label' => trim($base['label']),
            'type' => 'mcp',
            'address' => null,
            'mcp_token_label' => trim((string) $request->input('mcp_token_label')),
            'wake_url' => $wakeUrl,
            'wake_secret' => $wakeSecret,
            'secret' => null,
            'enabled' => $destination?->enabled ?? true,
        ];
    }

    private function deliverToSink(SignalDestination $destination, SignalEvent $event, SignalDelivery $delivery): void
    {
        match ($destination->type) {
            'webhook' => app(WebhookSink::class)->deliver($destination, $event, $delivery),
            'email' => app(EmailSink::class)->deliver($destination, $event, $delivery),
            'mcp' => app(McpSink::class)->deliver($destination, $event, $delivery),
            default => throw new \RuntimeException("Unsupported signal destination type {$destination->type}"),
        };
    }

    private function markDeliveredIfStillPending(SignalDestination $destination, SignalDelivery $delivery): void
    {
        if ($delivery->fresh()->status !== 'pending') {
            return;
        }

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

    private function markFailedIfStillPending(SignalDestination $destination, SignalDelivery $delivery, \Throwable $e): void
    {
        if ($delivery->fresh()->status !== 'pending') {
            return;
        }

        $error = Str::limit($e->getMessage(), 500, '');
        $delivery->forceFill([
            'status' => 'failed',
            'error' => $error,
        ])->save();
        $destination->forceFill([
            'last_delivery_status' => 'failed',
            'last_error' => $error,
        ])->save();
    }

    private function decorateDestination(SignalDestination $destination): SignalDestination
    {
        $destination->masked_address = $this->mask($destination->address);
        $destination->masked_wake_url = $this->mask($destination->wake_url);
        $destination->masked_wake_secret = $this->mask($destination->wake_secret);

        return $destination;
    }

    private function keepsExisting(mixed $input, ?SignalDestination $destination, string $field, string $type): bool
    {
        if ($destination === null || $destination->type !== $type) {
            return false;
        }

        $value = trim((string) $input);
        if (! in_array($value, ['', self::SECRET_MASK], true)) {
            return false;
        }

        $existing = $destination->{$field};

        return is_string($existing) && $existing !== '';
    }

    private function nullableTrim(mixed $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed === '' || $trimmed === self::SECRET_MASK ? null : $trimmed;
    }

    private function mask(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $host = parse_url($value, PHP_URL_HOST);
        if (! is_string($host) && filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $host = Str::afterLast($value, '@');
        }

        $tail = Str::substr($value, -4);

        return is_string($host) && $host !== ''
            ? "{$host} ...{$tail}"
            : "...{$tail}";
    }

    /** @return array<string, mixed> */
    private function snapshot(SignalDestination $destination): array
    {
        return [
            'label' => $destination->label,
            'type' => $destination->type,
            'address' => $destination->address,
            'mcp_token_label' => $destination->mcp_token_label,
            'wake_url' => $destination->wake_url,
            'wake_secret' => $destination->wake_secret,
            'secret' => $destination->secret,
            'enabled' => $destination->enabled,
        ];
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array<string, mixed>
     */
    private function changes(array $before, array $after): array
    {
        $changes = [];
        foreach ($after as $field => $value) {
            if (array_key_exists($field, $before) && $before[$field] === $value) {
                continue;
            }

            if (in_array($field, $this->secretFields, true)) {
                $changes[$field] = $value === null || $value === '' ? null : '[updated]';
            } else {
                $changes[$field] = $value;
            }
        }

        return $changes;
    }
}
