<?php

namespace App\Support;

use App\Models\Setting;
use App\Services\Carbon\CarbonMcpConnector;

/**
 * Carbon Connect MCP (card PEYvh4NL) switches.
 *
 * `carbon_mcp_enabled` is the operator's switch (default off). The tools are
 * LIVE only while it is on AND a Carbon sign-in is stored; the publication site
 * (McpToolSurface), the executor and the catalogue schedule all ask isLive(), so
 * publish and dispatch answer one question (psa-wzjzz).
 */
final class CarbonMcpConfig
{
    public const ENABLED_SETTING = 'carbon_mcp_enabled';

    public static function isEnabled(): bool
    {
        return Setting::getValue(self::ENABLED_SETTING, '0') === '1';
    }

    public static function isConnected(): bool
    {
        return (string) Setting::getValue(CarbonMcpConnector::REFRESH_TOKEN_SETTING, '') !== '';
    }

    /** Enabled AND connected. The enabled read is first so the default-off case costs one query. */
    public static function isLive(): bool
    {
        try {
            return self::isEnabled() && self::isConnected();
        } catch (\Throwable) {
            return false;
        }
    }
}
