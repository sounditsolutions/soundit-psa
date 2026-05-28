<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Str;

/**
 * MCP server config helpers. Currently exposes a staff-scope token used by
 * the Claude Teams Teammate; future client-portal server will add a sibling.
 */
class McpConfig
{
    public static function staffToken(): ?string
    {
        return Setting::getEncrypted('mcp_staff_token');
    }

    public static function isStaffEnabled(): bool
    {
        return ! empty(self::staffToken());
    }

    /**
     * Generate and store a new staff token. Returns the new token (only
     * shown once — caller is responsible for handing it to the bot operator).
     */
    public static function rotateStaffToken(): string
    {
        $token = 'psa-mcp-' . Str::random(48);
        Setting::setEncrypted('mcp_staff_token', $token);

        return $token;
    }
}
