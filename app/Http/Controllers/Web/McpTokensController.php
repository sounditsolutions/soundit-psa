<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\McpToken;
use App\Support\McpConfig;
use App\Support\McpToolRegistry;
use Illuminate\Http\Request;
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

        $token = McpConfig::rotateStaffToken(
            allowedTools: array_values($validated['tools']),
            label: $validated['label'],
        );

        return redirect()->route('settings.mcp-tokens.index')
            ->with('mcp_new_token', $token)
            ->with('mcp_new_token_label', $validated['label'])
            ->with('success', 'Token "'.$validated['label'].'" created. Copy it now - it will not be shown again.');
    }

    public function revoke(McpToken $token)
    {
        $label = $token->label;

        $token->forceFill(['revoked_at' => now()])->save();

        return redirect()->route('settings.mcp-tokens.index')
            ->with('success', 'Token "'.$label.'" revoked.');
    }
}
