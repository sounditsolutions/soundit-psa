<?php

namespace App\Support;

use App\Models\Setting;

class AssistantConfig
{
    public static function isEnabled(): bool
    {
        if (! AiConfig::isConfigured() || AiConfig::provider() !== 'anthropic') {
            return false;
        }

        $value = Setting::getValue('assistant_enabled');

        // Default to enabled if AI is configured with Anthropic
        return $value === null || (bool) $value;
    }

    public static function dailyTokenLimit(): int
    {
        return (int) (Setting::getValue('assistant_daily_token_limit') ?: 500_000);
    }

    public static function maxMessagesPerConversation(): int
    {
        return (int) (Setting::getValue('assistant_max_messages') ?: 50);
    }
}
