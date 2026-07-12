<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\McpAuditLog;
use App\Models\McpToken;
use App\Models\SignalConfigLog;
use App\Models\SignalDelivery;
use App\Models\SignalDestination;
use App\Models\SignalInboxEntry;
use App\Support\McpConfig;
use App\Support\McpToolInstructions;
use App\Support\McpToolModes;
use App\Support\McpToolRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class McpTokensController extends Controller
{
    public function index()
    {
        return $this->indexView();
    }

    public function show(McpToken $token)
    {
        $newToken = session('mcp_new_token');

        // Stored grant entries may carry mode suffixes (name:staged /
        // name:immediate) and legacy staged-alias names; the view renders the
        // parsed form — plain canonical names plus the per-tool mode map.
        $grantState = McpToolModes::parseGrants($token->tools ?? []);

        return view('settings.mcp-tokens.show', [
            'token' => $token,
            'grantedTools' => $grantState['tools'],
            'grantModes' => $grantState['modes'],
            'integrationGroups' => McpToolRegistry::integrationGroups(),
            'toolInstructions' => McpToolInstructions::all(),
            'newToken' => is_string($newToken) ? $newToken : null,
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

    /**
     * Create a token with safe defaults: an inactive draft with zero tools and
     * an auto-generated "untitled" name. It cannot authenticate until it is
     * deliberately activated, so it is never briefly live with wrong perms.
     * Redirect into its detail page, where the one-time secret is revealed and
     * the operator names + configures it.
     */
    public function store(Request $request)
    {
        $label = $this->uniqueDraftLabel();
        $plain = McpConfig::mintDraftToken($label);
        $token = McpToken::query()->where('label', $label)->firstOrFail();

        $this->audit($request, 'token/mint', $label, [
            'tools' => [],
            'ai_actor' => false,
            'require_explicit_client_scope' => true,
            'draft' => true,
        ]);

        return redirect()
            ->route('settings.mcp-tokens.show', $token)
            ->with('mcp_new_token', $plain);
    }

    public function rename(Request $request, McpToken $token)
    {
        $request->validate([
            'label' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9_.:-]+$/'],
        ]);

        $old = $token->label;
        $new = McpConfig::normalizeLabel($request->input('label'));

        if ($new !== $old && McpToken::query()->where('label', $new)->where('id', '!=', $token->id)->exists()) {
            if ($request->wantsJson()) {
                return response()->json(['ok' => false, 'message' => 'That name is already taken.'], 422);
            }

            return back()->withErrors(['label' => 'That name is already taken.']);
        }

        if ($new !== $old) {
            DB::transaction(function () use ($token, $old, $new): void {
                $token->forceFill(['label' => $new])->save();
                // Keep linked Alerts Hub destinations attached across the rename.
                SignalDestination::query()
                    ->where('mcp_token_label', $old)
                    ->update(['mcp_token_label' => $new]);
            });

            $this->audit($request, 'token/rename', $new, ['from' => $old]);
        }

        return $this->respond($request, $token->fresh(), 'Token renamed.');
    }

    public function activate(Request $request, McpToken $token)
    {
        if ($token->isRevoked()) {
            return $this->respond($request, $token, 'A revoked token cannot be activated.', ok: false);
        }

        $token->forceFill([
            'activated_at' => $token->activated_at ?? now(),
            'paused_at' => null,
        ])->save();

        $this->audit($request, 'token/activate', $token->label, []);

        return $this->respond($request, $token->fresh(), 'Token activated.');
    }

    public function pause(Request $request, McpToken $token)
    {
        if (! $token->isActive()) {
            return $this->respond($request, $token, 'Only an active token can be paused.', ok: false);
        }

        $token->forceFill(['paused_at' => now()])->save();

        $this->audit($request, 'token/pause', $token->label, []);

        return $this->respond($request, $token->fresh(), 'Token paused.');
    }

    public function resume(Request $request, McpToken $token)
    {
        if (! $token->isPaused()) {
            return $this->respond($request, $token, 'Only a paused token can be resumed.', ok: false);
        }

        $token->forceFill(['paused_at' => null])->save();

        $this->audit($request, 'token/resume', $token->label, []);

        return $this->respond($request, $token->fresh(), 'Token resumed.');
    }

    public function regenerate(Request $request, McpToken $token)
    {
        if ($token->isRevoked()) {
            return redirect()->route('settings.mcp-tokens.show', $token)
                ->with('error', "Revoked tokens can't be regenerated — create a new one instead.");
        }

        $plain = McpConfig::regenerateSecret($token);

        $this->audit($request, 'token/regenerate', $token->label, [
            'tools' => $token->tools,
        ]);

        return redirect()
            ->route('settings.mcp-tokens.show', $token)
            ->with('mcp_new_token', $plain);
    }

    public function updateTools(Request $request, McpToken $token)
    {
        $validated = $request->validate([
            'tools' => ['sometimes', 'array'],
            'tools.*' => ['string', 'max:150'],
        ]);

        // Entries may be plain tool names, `name:staged` / `name:immediate`
        // mode grants for stageable capabilities, or legacy staged-alias
        // names. Normalize to canonical storage form and reject anything that
        // does not resolve to a grantable tool.
        $normalized = McpToolModes::normalizeGrantEntries($validated['tools'] ?? []);
        if ($normalized['unknown'] !== []) {
            throw ValidationException::withMessages([
                'tools' => 'Unknown tool grant(s): '.implode(', ', $normalized['unknown']),
            ]);
        }

        // Grants auto-save on every toggle, so an empty set is valid (a token
        // may grant nothing). Activation is a separate, deliberate flip.
        $tools = $normalized['entries'];
        $token->forceFill(['tools' => $tools])->save();

        $this->audit($request, 'token/tools', $token->label, ['tools' => $tools]);

        return $this->respond($request, $token->fresh(), 'Tool grants saved.');
    }

    public function updateDirective(Request $request, McpToken $token)
    {
        $validated = $request->validate([
            'directive' => ['nullable', 'string', 'max:20000'],
        ]);

        $directive = trim((string) ($validated['directive'] ?? ''));
        $token->forceFill(['directive' => $directive !== '' ? $directive : null])->save();

        $this->audit($request, 'token/directive', $token->label, [
            'directive_length' => mb_strlen($directive),
        ]);

        return $this->respond($request, $token->fresh(), 'Directive saved.');
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

        return $this->respond($request, $token->fresh(), 'Trust controls saved.');
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

        if ($request->wantsJson()) {
            return response()->json(['ok' => true, 'message' => 'Shared instruction saved.']);
        }

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

        $wasDraft = $token->isDraft();
        $token->forceFill(['revoked_at' => now()])->save();
        $this->clearPendingSignalInboxForLabel($label);
        $this->disableSignalDestinationsForRevokedLabel($request, $label);

        $this->audit($request, 'token/revoke', $label, ['tools' => $tools, 'was_draft' => $wasDraft]);

        return redirect()->route('settings.mcp-tokens.index')
            ->with('success', $wasDraft
                ? 'Draft "'.$label.'" discarded.'
                : 'Token "'.$label.'" revoked.');
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

    /**
     * A JSON response for AJAX auto-save, or a redirect back to the detail page
     * for the no-JS fallback.
     */
    private function respond(Request $request, McpToken $token, string $message, bool $ok = true)
    {
        if ($request->wantsJson()) {
            return response()->json([
                'ok' => $ok,
                'message' => $message,
                'state' => $token->state(),
                'granted_count' => is_array($token->tools) ? count($token->tools) : 0,
            ], $ok ? 200 : 422);
        }

        return redirect()->route('settings.mcp-tokens.show', $token)
            ->with($ok ? 'success' : 'error', $message);
    }

    private function uniqueDraftLabel(): string
    {
        $base = 'untitled';
        if (! McpToken::query()->where('label', $base)->exists()) {
            return $base;
        }

        for ($i = 2; $i < 10000; $i++) {
            $candidate = $base.'-'.$i;
            if (! McpToken::query()->where('label', $candidate)->exists()) {
                return $candidate;
            }
        }

        return $base.'-'.Str::lower(Str::random(6));
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

    /**
     * Revocation is terminal: the token can never authenticate to poll again, so
     * any Alerts Hub MCP destination pointing at this label is now unpollable.
     * Disable those destinations (the router already suppresses disabled ones) and
     * leave a breadcrumb so the operator can see why it went dark. Non-MCP
     * destinations and destinations targeting other labels are untouched.
     */
    private function disableSignalDestinationsForRevokedLabel(Request $request, string $label): void
    {
        SignalDestination::query()
            ->where('type', 'mcp')
            ->where('mcp_token_label', $label)
            ->where('enabled', true)
            ->get()
            ->each(function (SignalDestination $destination) use ($request, $label): void {
                $destination->forceFill([
                    'enabled' => false,
                    'last_error' => 'Linked MCP token "'.$label.'" was revoked',
                ])->save();

                SignalConfigLog::record(
                    $request->user()?->id,
                    'disabled',
                    $destination,
                    ['enabled' => false, 'reason' => 'mcp-token-revoked'],
                );
            });
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

    private function indexView()
    {
        return view('settings.mcp-tokens.index', [
            'tokens' => McpToken::query()
                ->orderByRaw('(revoked_at IS NULL) DESC')
                ->orderByDesc('created_at')
                ->get(),
        ]);
    }
}
