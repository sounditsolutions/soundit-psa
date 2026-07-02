<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\McpAuditLog;
use App\Models\McpToken;
use App\Models\SignalDelivery;
use App\Models\SignalInboxEntry;
use App\Support\McpConfig;
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

        return $this->indexView($token, $validated['label']);
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
            'newToken' => $newToken,
            'newTokenLabel' => $newTokenLabel,
        ]);
    }
}
