<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\McpToken;
use App\Models\SignalConfigLog;
use App\Models\SignalDelivery;
use App\Models\SignalDestination;
use App\Models\SignalEvent;
use App\Models\SignalRoute;
use App\Rules\SafeWebhookUrl;
use App\Services\Signals\DerivedRecipients;
use App\Services\Signals\SignalEventTypes;
use App\Services\Signals\Sinks\EmailSink;
use App\Services\Signals\Sinks\McpSink;
use App\Services\Signals\Sinks\WebhookSink;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
                ->manual()
                ->orderBy('label')
                ->get()
                ->map(fn (SignalDestination $destination) => $this->decorateDestination($destination)),
            'routes' => SignalRoute::query()
                ->with('steps.destination')
                ->orderBy('label')
                ->get()
                ->map(fn (SignalRoute $route) => $this->decorateRoute($route)),
            'recentDeliveries' => SignalDelivery::query()
                ->with(['destination', 'event'])
                ->latest()
                ->limit(100)
                ->get(),
            'recentConfigLogs' => SignalConfigLog::query()
                ->latest()
                ->limit(20)
                ->get(),
            'hasStalePendingDelivery' => SignalDelivery::query()
                ->where('status', 'pending')
                ->where('created_at', '<', now()->subMinutes(10))
                ->exists(),
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

        return redirect()->route('settings.alerts.destinations.show', $destination)
            ->with('success', 'Destination created.');
    }

    public function createDestination()
    {
        return view('settings.alerts.destinations.create', [
            'mcpTokens' => McpToken::query()->active()->orderBy('label')->get(['label']),
            'secretMask' => self::SECRET_MASK,
        ]);
    }

    public function showDestination(SignalDestination $destination)
    {
        return view('settings.alerts.destinations.show', [
            'destination' => $this->decorateDestination($destination),
            'recentDeliveries' => SignalDelivery::query()
                ->where('destination_id', $destination->id)
                ->with('event')->latest()->limit(20)->get(),
            'mcpTokens' => McpToken::query()->active()->orderBy('label')->get(['label']),
            'secretMask' => self::SECRET_MASK,
        ]);
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

        return redirect()->route('settings.alerts.destinations.show', $destination)
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

        return redirect()->back()
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

            return redirect()->route('settings.alerts.destinations.show', $destination)
                ->with('error', 'Test signal failed: '.$delivery->fresh()->error);
        }

        return redirect()->route('settings.alerts.destinations.show', $destination)
            ->with('success', 'Test signal delivered.');
    }

    public function storeRoute(Request $request)
    {
        [$attributes, $steps] = $this->validatedRoutePayload($request);

        $route = DB::transaction(function () use ($request, $attributes, $steps): SignalRoute {
            $route = SignalRoute::create([
                ...$attributes,
                'enabled' => false,
            ]);

            $this->replaceRouteSteps($route, $steps);

            SignalConfigLog::record(
                $request->user()?->id,
                'created',
                $route,
                $this->routeChanges($attributes, $steps),
            );

            return $route;
        });

        return redirect()->route('settings.alerts.routes.show', $route)
            ->with('success', 'Route created.');
    }

    public function createRoute()
    {
        return view('settings.alerts.routes.create', [
            'routeDestinations' => SignalDestination::query()->manual()->orderBy('label')->get(['id', 'label', 'type']),
            'derivedRecipients' => DerivedRecipients::all(),
            'eventTypeGroups' => $this->eventTypeGroups(),
        ]);
    }

    public function showRoute(SignalRoute $route)
    {
        return view('settings.alerts.routes.show', [
            'route' => $this->decorateRoute($route->load('steps.destination')),
            'routeDestinations' => SignalDestination::query()->manual()->orderBy('label')->get(['id', 'label', 'type']),
            'derivedRecipients' => DerivedRecipients::all(),
            'eventTypeGroups' => $this->eventTypeGroups(),
            'recentFires' => SignalDelivery::query()->where('route_id', $route->id)
                ->with(['destination', 'event'])->latest()->limit(20)->get(),
        ]);
    }

    public function updateRoute(Request $request, SignalRoute $route)
    {
        [$attributes, $steps] = $this->validatedRoutePayload($request);

        DB::transaction(function () use ($request, $route, $attributes, $steps): void {
            $route->forceFill($attributes)->save();
            $this->replaceRouteSteps($route, $steps);

            SignalConfigLog::record(
                $request->user()?->id,
                'updated',
                $route,
                $this->routeChanges($attributes, $steps),
            );
        });

        return redirect()->route('settings.alerts.routes.show', $route)
            ->with('success', 'Route updated.');
    }

    public function toggleRoute(Request $request, SignalRoute $route)
    {
        $route->forceFill(['enabled' => ! $route->enabled])->save();

        SignalConfigLog::record(
            $request->user()?->id,
            $route->enabled ? 'enabled' : 'disabled',
            $route,
            ['enabled' => $route->enabled],
        );

        return redirect()->back()
            ->with('success', $route->enabled ? 'Route enabled.' : 'Route disabled.');
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

    private function validatedRoutePayload(Request $request): array
    {
        $input = $this->routeInput($request);

        $validated = Validator::make($input, [
            'label' => ['required', 'string', 'max:255'],
            'event_filter.types' => ['required'],
            'event_filter.categories' => ['nullable'],
            'event_filter.min_priority' => ['nullable', 'integer', 'min:0', 'max:5'],
            'event_filter.client_ids' => ['nullable'],
            'cooldown_seconds' => ['nullable', 'integer', 'min:0', 'max:604800'],
            'steps' => ['required', 'array', 'min:1'],
            'steps.*.destination_id' => ['required', $this->stepDestinationRule()],
            'steps.*.wait_for_ack_seconds' => ['nullable', 'integer', 'min:0', 'max:604800'],
            'steps.*.resolve_within_seconds' => ['nullable', 'integer', 'min:0', 'max:604800'],
            'steps.*.non_suppressible' => ['nullable', 'boolean'],
            'steps.*.simultaneous' => ['nullable', 'boolean'],
        ])->validate();

        $filter = [
            'types' => $this->normalizeRouteTypes(data_get($validated, 'event_filter.types')),
        ];

        $categories = $this->normalizeTextList(data_get($validated, 'event_filter.categories'));
        if ($categories !== []) {
            $filter['categories'] = $categories;
        }

        $minPriority = data_get($validated, 'event_filter.min_priority');
        if ($minPriority !== null && $minPriority !== '') {
            $filter['min_priority'] = (int) $minPriority;
        }

        $clientIds = $this->normalizeIntList(data_get($validated, 'event_filter.client_ids'));
        if ($clientIds !== []) {
            $filter['client_ids'] = $clientIds;
        }

        return [
            [
                'label' => trim((string) $validated['label']),
                'event_filter' => $filter,
                'cooldown_seconds' => (int) ($validated['cooldown_seconds'] ?? 300),
            ],
            $this->normalizeRouteSteps($validated['steps']),
        ];
    }

    private function routeInput(Request $request): array
    {
        $input = $request->all();
        $input['steps'] = array_values(array_filter(
            (array) ($input['steps'] ?? []),
            fn (mixed $step): bool => is_array($step) && trim((string) ($step['destination_id'] ?? '')) !== '',
        ));

        return $input;
    }

    private function normalizeRouteTypes(mixed $raw): string|array
    {
        $values = $this->normalizeTextList($raw);
        if (in_array('all', $values, true)) {
            return 'all';
        }

        $routable = array_keys(array_filter(
            SignalEventTypes::all(),
            fn (array $definition): bool => $definition['routable'],
        ));
        if ($values === [] || array_diff($values, $routable) !== []) {
            throw ValidationException::withMessages([
                'event_filter.types' => 'Choose only routable signal event types.',
            ]);
        }

        return array_values(array_unique($values));
    }

    private function normalizeTextList(mixed $raw): array
    {
        $parts = [];
        foreach ((array) $raw as $value) {
            foreach (explode(',', (string) $value) as $part) {
                $trimmed = trim($part);
                if ($trimmed !== '') {
                    $parts[$trimmed] = true;
                }
            }
        }

        return array_keys($parts);
    }

    private function normalizeIntList(mixed $raw): array
    {
        return array_values(array_unique(array_map(
            fn (string $value): int => (int) $value,
            array_filter($this->normalizeTextList($raw), fn (string $value): bool => (int) $value > 0),
        )));
    }

    private function normalizeRouteSteps(array $steps): array
    {
        $order = 1;
        $normalized = [];

        foreach (array_values($steps) as $index => $step) {
            $simultaneous = filter_var($step['simultaneous'] ?? false, FILTER_VALIDATE_BOOLEAN);
            if ($index > 0 && ! $simultaneous) {
                $order++;
            }

            [$destinationId, $derivedFrom] = $this->parseStepDestination((string) ($step['destination_id'] ?? ''));

            $normalized[] = [
                'step_order' => $order,
                'destination_id' => $destinationId,
                'derived_from' => $derivedFrom,
                'wait_for_ack_seconds' => $this->nullableInt($step['wait_for_ack_seconds'] ?? null),
                'resolve_within_seconds' => $this->nullableInt($step['resolve_within_seconds'] ?? null),
                'non_suppressible' => filter_var($step['non_suppressible'] ?? false, FILTER_VALIDATE_BOOLEAN),
            ];
        }

        return $normalized;
    }

    /**
     * Split a step's submitted destination value into a fixed destination id or
     * a derived-recipient kind. Derived values arrive as `derived:<kind>`.
     *
     * @return array{0: ?int, 1: ?string} [destination_id, derived_from]
     */
    private function parseStepDestination(string $raw): array
    {
        if (str_starts_with($raw, 'derived:')) {
            return [null, substr($raw, strlen('derived:'))];
        }

        return [(int) $raw, null];
    }

    private function stepDestinationRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            $value = (string) $value;

            if (str_starts_with($value, 'derived:')) {
                if (! DerivedRecipients::has(substr($value, strlen('derived:')))) {
                    $fail('The selected derived recipient is invalid.');
                }

                return;
            }

            if (! ctype_digit($value) || ! SignalDestination::whereKey($value)->exists()) {
                $fail('The selected destination is invalid.');
            }
        };
    }

    private function nullableInt(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    private function replaceRouteSteps(SignalRoute $route, array $steps): void
    {
        $route->steps()->delete();

        foreach ($steps as $step) {
            $route->steps()->create($step);
        }
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

    private function decorateRoute(SignalRoute $route): SignalRoute
    {
        $route->event_filter_summary = $this->routeFilterSummary($route);
        $route->steps_summary = $this->routeStepsSummary($route);

        return $route;
    }

    private function routeFilterSummary(SignalRoute $route): string
    {
        $filter = $route->event_filter ?? [];
        $types = $filter['types'] ?? [];
        $parts = [
            $types === 'all' ? 'All routable events' : implode(', ', (array) $types),
        ];

        if (! empty($filter['categories'])) {
            $parts[] = 'categories: '.implode(', ', (array) $filter['categories']);
        }
        if (isset($filter['min_priority'])) {
            $parts[] = 'P'.$filter['min_priority'].'+';
        }
        if (! empty($filter['client_ids'])) {
            $parts[] = 'clients: '.implode(', ', (array) $filter['client_ids']);
        }

        return implode(' | ', $parts);
    }

    private function routeStepsSummary(SignalRoute $route): string
    {
        if ($route->steps->isEmpty()) {
            return 'No destinations';
        }

        return $route->steps
            ->groupBy('step_order')
            ->map(function ($steps): string {
                $labels = $steps
                    ->map(fn ($step): string => $step->derived_from !== null
                        ? DerivedRecipients::label($step->derived_from)
                        : ($step->destination?->label ?? "Destination #{$step->destination_id}"))
                    ->implode(' + ');
                $wait = $steps->max('wait_for_ack_seconds');

                return $wait ? "{$labels} ({$this->secondsLabel((int) $wait)} ack)" : $labels;
            })
            ->implode(' -> ');
    }

    private function secondsLabel(int $seconds): string
    {
        return $seconds % 60 === 0 ? ((int) ($seconds / 60)).'m' : $seconds.'s';
    }

    private function eventTypeGroups(): array
    {
        $groups = [];
        foreach (SignalEventTypes::all() as $key => $definition) {
            if (! $definition['routable']) {
                continue;
            }

            $prefix = Str::before($key, '.').'.*';
            $groups[$prefix][] = [
                'key' => $key,
                'label' => $definition['label'],
            ];
        }

        return $groups;
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

    private function routeChanges(array $attributes, array $steps): array
    {
        return [
            'label' => $attributes['label'],
            'event_filter' => $attributes['event_filter'],
            'cooldown_seconds' => $attributes['cooldown_seconds'],
            'steps' => $steps,
        ];
    }
}
