<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\McpAuditLog;
use App\Models\McpToken;
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

    public function store(Request $request)
    {
        $validated = $request->validate([
            'label' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9_.:-]+$/'],
            'tools' => ['required', 'array', 'min:1'],
            'tools.*' => ['string', Rule::in(McpToolRegistry::allToolNames())],
        ]);

        $tools = array_values($validated['tools']);
        $isRotate = McpConfig::hasScopedStaffTokenLabel($validated['label']);

        $token = McpConfig::rotateStaffToken(
            allowedTools: $tools,
            label: $validated['label'],
        );

        $this->audit(
            $request,
            $isRotate ? 'token/rotate' : 'token/mint',
            $validated['label'],
            ['tools' => $tools],
        );

        return redirect()->route('settings.mcp-tokens.index')
            ->with('mcp_new_token', $token)
            ->with('mcp_new_token_label', $validated['label'])
            ->with('success', 'Token "'.$validated['label'].'" created. Copy it now - it will not be shown again.');
    }

    public function revoke(Request $request, McpToken $token)
    {
        $label = $token->label;
        $tools = $token->tools;

        $token->forceFill(['revoked_at' => now()])->save();

        $this->audit($request, 'token/revoke', $label, ['tools' => $tools]);

        return redirect()->route('settings.mcp-tokens.index')
            ->with('success', 'Token "'.$label.'" revoked.');
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
}
