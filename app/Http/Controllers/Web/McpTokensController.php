<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\McpAuditLog;
use App\Models\McpToken;
use App\Models\SignalDestination;
use App\Support\McpConfig;
use App\Support\McpToolRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class McpTokensController extends Controller
{
    public function index()
    {
        return view('settings.mcp-tokens.index', [
            'tokens' => McpToken::query()
                ->orderByRaw('(revoked_at IS NULL) DESC')
                ->orderByDesc('created_at')
                ->get(),
            'groups' => McpToolRegistry::groups(),
            'newToken' => session('mcp_new_token'),
            'newTokenLabel' => session('mcp_new_token_label'),
        ]);
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
                ->whereNull('mcp_token_id')
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
        ]);

        $tools = array_values($validated['tools']);
        $isRotate = McpConfig::hasScopedStaffTokenLabel($validated['label']);
        $token = McpConfig::rotateStaffToken(allowedTools: $tools, label: $validated['label']);

        $this->audit($request, $isRotate ? 'token/rotate' : 'token/mint', $validated['label'], [
            'tools' => $tools,
        ]);

        return redirect()->route('settings.mcp-tokens.index')
            ->with('mcp_new_token', $token)
            ->with('mcp_new_token_label', $validated['label'])
            ->with('success', 'Token created.');
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

    public function revoke(Request $request, McpToken $token)
    {
        $label = $token->label;
        $tools = $token->tools;
        $token->forceFill(['revoked_at' => now()])->save();

        $this->audit($request, 'token/revoke', $label, ['tools' => $tools]);

        return redirect()->route('settings.mcp-tokens.index')
            ->with('success', 'Token revoked.');
    }

    public function linkSignalDestination(Request $request, McpToken $token)
    {
        $validated = $request->validate([
            'signal_destination_id' => [
                'required',
                'integer',
                Rule::exists('signal_destinations', 'id')->where('type', 'mcp'),
            ],
        ]);

        $destination = SignalDestination::where('type', 'mcp')
            ->findOrFail($validated['signal_destination_id']);
        $destination->forceFill(['mcp_token_id' => $token->id])->save();

        $this->audit($request, 'token/destination_link', $token->label, [
            'signal_destination_id' => $destination->id,
        ]);

        return redirect()->route('settings.mcp-tokens.show', $token)
            ->with('success', 'Destination linked.');
    }

    public function unlinkSignalDestination(Request $request, McpToken $token, SignalDestination $destination)
    {
        abort_unless($destination->type === 'mcp' && (int) $destination->mcp_token_id === (int) $token->id, 404);

        $destination->forceFill(['mcp_token_id' => null])->save();

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

    /** @param array<string, mixed> $arguments */
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
            Log::warning('[MCP tokens] Audit write failed: '.$e->getMessage());
        }
    }
}
