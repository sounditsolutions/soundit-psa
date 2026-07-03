<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\McpAuditLog;
use App\Models\McpToken;
use App\Models\SignalDelivery;
use App\Models\SignalDestination;
use App\Models\SignalInboxEntry;
use App\Support\McpConfig;
use App\Support\McpToolInstructions;
use App\Support\McpToolRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class McpTokensController extends Controller
{
    public function index()
    {
        return $this->indexView();
    }

    public function show(McpToken $token)
    {
        return view('settings.mcp-tokens.show', [
            'token' => $token,
            'groups' => McpToolRegistry::groups(),
            'auditLogs' => $this->auditLogsFor($token),
            'linkedSignalDestinations' => $token->signalDestinations()
                ->where('type', 'mcp')
                ->orderBy('label')
                ->get(),
            'availableSignalDestinations' => SignalDestination::query()
                ->where('type', 'mcp')
                ->whereNull('mcp_token_label')
                ->orderBy('label')
                ->get(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'label' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9_.:-]+$/'],
            'tools' => ['required', 'array', 'min:1'],
            'tools.*' => ['string', Rule::in(McpToolRegistry::allToolNames())],
            'ai_actor' => ['sometimes', 'boolean'],
            'require_explicit_client_scope' => ['sometimes', 'boolean'],
        ]);

        $tools = array_values($validated['tools']);
        $label = McpConfig::normalizeLabel($validated['label']);
        $existingToken = McpToken::query()->where('label', $label)->first();
        $isRotate = $existingToken !== null && ! $existingToken->isRevoked();
        $flags = $this->trustFlagsForMintOrRotate($request, $isRotate ? $existingToken : null);

        $token = McpConfig::rotateStaffToken(
            allowedTools: $tools,
            label: $label,
            aiActor: $flags['ai_actor'],
            requireExplicitClientScope: $flags['require_explicit_client_scope'],
        );

        $this->audit(
            $request,
            $isRotate ? 'token/rotate' : 'token/mint',
            $label,
            ['tools' => $tools] + $flags,
        );

        return $this->indexView($token, $label);
    }

    public function updateTools(Request $request, McpToken $token)
    {
        $validated = $request->validate([
            'tools' => ['required', 'array', 'min:1'],
            'tools.*' => ['string', Rule::in(McpToolRegistry::allToolNames())],
        ]);

        $tools = array_values($validated['tools']);
        $token->forceFill(['tools' => $tools])->save();

        $this->audit($request, 'token/tools', $token->label, ['tools' => $tools]);

        return redirect()->route('settings.mcp-tokens.show', $token)
            ->with('success', 'Token tools updated.');
    }

    public function updateDirective(Request $request, McpToken $token)
    {
        $validated = $request->validate([
            'directive' => ['nullable', 'string', 'max:5000'],
        ]);

        $directive = trim((string) ($validated['directive'] ?? ''));
        $token->forceFill(['directive' => $directive !== '' ? $directive : null])->save();

        $this->audit($request, 'token/directive', $token->label, [
            'directive_length' => mb_strlen($directive),
        ]);

        return redirect()->route('settings.mcp-tokens.show', $token)
            ->with('success', 'Token directive updated.');
    }

    public function updateTrustFlags(Request $request, McpToken $token)
    {
        $request->validate([
            'ai_actor' => ['sometimes', 'boolean'],
            'require_explicit_client_scope' => ['sometimes', 'boolean'],
        ]);

        $flags = $this->trustFlagsFromRequest($request, $token);
        $token->forceFill($flags)->save();

        $this->audit($request, 'token/trust_flags', $token->label, $flags);

        return redirect()->route('settings.mcp-tokens.show', $token)
            ->with('success', 'Token trust controls updated.');
    }

    public function updateToolInstructions(Request $request)
    {
        $validated = $request->validate([
            'tool_instructions' => ['nullable', 'array'],
            'tool_instructions.*' => ['nullable', 'string', 'max:'.McpToolInstructions::MAX_LENGTH],
        ]);

        McpToolInstructions::replaceAll($validated['tool_instructions'] ?? []);
        $instructions = McpToolInstructions::all();

        $this->audit($request, 'token/tool_instructions', 'mcp_tool_custom_instructions', [
            'tools' => array_keys($instructions),
            'total_length' => array_sum(array_map(fn (string $value): int => mb_strlen($value), $instructions)),
        ]);

        return redirect()->route('settings.mcp-tokens.index')
            ->with('success', 'Tool instructions updated.');
    }

    public function revoke(Request $request, McpToken $token)
    {
        $label = $token->label;
        $tools = $token->tools;

        if ($token->isRevoked()) {
            return redirect()->route('settings.mcp-tokens.index')
                ->with('success', 'Token "'.$label.'" is already revoked.');
        }

        $token->forceFill(['revoked_at' => now()])->save();
        $this->clearPendingSignalInboxForLabel($label);

        $this->audit($request, 'token/revoke', $label, ['tools' => $tools]);

        return redirect()->route('settings.mcp-tokens.index')
            ->with('success', 'Token "'.$label.'" revoked.');
    }

    public function linkSignalDestination(Request $request, McpToken $token)
    {
        $validated = $request->validate([
            'signal_destination_id' => [
                'required',
                'integer',
                Rule::exists('signal_destinations', 'id')->where(function ($query) {
                    $query->where('type', 'mcp')
                        ->whereNull('mcp_token_label');
                }),
            ],
        ]);

        $destination = SignalDestination::query()
            ->where('type', 'mcp')
            ->whereNull('mcp_token_label')
            ->findOrFail($validated['signal_destination_id']);
        $destination->forceFill(['mcp_token_label' => $token->label])->save();

        $this->audit($request, 'token/destination_link', $token->label, [
            'signal_destination_id' => $destination->id,
        ]);

        return redirect()->route('settings.mcp-tokens.show', $token)
            ->with('success', 'Destination linked.');
    }

    public function unlinkSignalDestination(Request $request, McpToken $token, SignalDestination $destination)
    {
        abort_unless($destination->type === 'mcp' && $destination->mcp_token_label === $token->label, 404);

        $destination->forceFill(['mcp_token_label' => null])->save();

        $this->audit($request, 'token/destination_unlink', $token->label, [
            'signal_destination_id' => $destination->id,
        ]);

        return redirect()->route('settings.mcp-tokens.show', $token)
            ->with('success', 'Destination unlinked.');
    }

    private function auditLogsFor(McpToken $token)
    {
        return McpAuditLog::query()
            ->where('server_name', 'staff')
            ->where(function ($query) use ($token) {
                $query->where('actor_label', 'mcp-staff:'.$token->label)
                    ->orWhere(function ($lifecycle) use ($token) {
                        $lifecycle->where('tool_name', $token->label)
                            ->where('method', 'like', 'token/%');
                    });
            })
            ->latest()
            ->limit(50)
            ->get();
    }

    /** @return array{ai_actor: bool, require_explicit_client_scope: bool} */
    private function trustFlagsForMintOrRotate(Request $request, ?McpToken $existingToken = null): array
    {
        if ($existingToken !== null) {
            return [
                'ai_actor' => (bool) $existingToken->ai_actor,
                'require_explicit_client_scope' => (bool) $existingToken->require_explicit_client_scope,
            ];
        }

        return $this->trustFlagsFromRequest($request);
    }

    /** @return array{ai_actor: bool, require_explicit_client_scope: bool} */
    private function trustFlagsFromRequest(Request $request, ?McpToken $existingToken = null): array
    {
        $aiActor = $request->has('ai_actor')
            ? $request->boolean('ai_actor')
            : (bool) ($existingToken?->ai_actor ?? false);

        $requireExplicitClientScope = $request->has('require_explicit_client_scope')
            ? $request->boolean('require_explicit_client_scope')
            : ($existingToken !== null ? (bool) $existingToken->require_explicit_client_scope : true);

        return [
            'ai_actor' => $aiActor,
            'require_explicit_client_scope' => $requireExplicitClientScope,
        ];
    }

    /** @param  array<string, mixed>  $arguments */
    private function audit(Request $request, string $method, string $label, array $arguments): void
    {
        try {
            McpAuditLog::create([
                'server_name' => 'staff',
                'method' => $method,
                'tool_name' => mb_substr($label, 0, 100),
                'arguments' => $arguments,
                'status' => 'success',
                'error_message' => null,
                'duration_ms' => 0,
                'actor_label' => mb_substr('web:'.((string) ($request->user()?->email ?? $request->user()?->id ?? 'unknown')), 0, 100),
                'source_ip' => $request->ip(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('[Settings/McpTokens] Audit write failed: '.$e->getMessage());
        }
    }

    private function clearPendingSignalInboxForLabel(string $label): void
    {
        DB::transaction(function () use ($label): void {
            $rows = SignalInboxEntry::query()
                ->whereNull('acked_at')
                ->whereHas('destination', fn ($query) => $query->where('mcp_token_label', $label))
                ->get(['id', 'delivery_id']);

            if ($rows->isEmpty()) {
                return;
            }

            SignalInboxEntry::query()
                ->whereIn('id', $rows->pluck('id')->all())
                ->delete();

            $deliveryIds = $rows->pluck('delivery_id')->filter()->unique()->values()->all();
            if ($deliveryIds === []) {
                return;
            }

            SignalDelivery::query()
                ->whereIn('id', $deliveryIds)
                ->whereNotIn('status', ['acked', 'suppressed', 'timed_out', 'failed'])
                ->update([
                    'status' => 'suppressed',
                    'error' => 'token-revoked',
                ]);
        });
    }

    private function indexView(?string $newToken = null, ?string $newTokenLabel = null)
    {
        return view('settings.mcp-tokens.index', [
            'tokens' => McpToken::query()
                ->orderByRaw('(revoked_at IS NULL) DESC')
                ->orderByDesc('created_at')
                ->get(),
            'groups' => McpToolRegistry::groups(),
            'toolInstructions' => McpToolInstructions::all(),
            'newToken' => $newToken,
            'newTokenLabel' => $newTokenLabel,
        ]);
    }
}
