<?php

namespace App\Support;

use App\Models\Setting;

class AiConfig
{
    private const DEFAULTS = [
        'anthropic' => 'claude-sonnet-4-6',
        'openai' => 'gpt-4o',
    ];

    private static array $map = [
        'provider' => ['ai_provider', 'services.ai.provider', false],
        'api_key' => ['ai_api_key', 'services.ai.api_key', true],
        'model' => ['ai_model', 'services.ai.model', false],
    ];

    public static function get(string $key): ?string
    {
        if (! isset(self::$map[$key])) {
            return config("services.ai.{$key}");
        }

        [$settingKey, $configKey, $encrypted] = self::$map[$key];

        return Setting::settingOrConfig($settingKey, $configKey, $encrypted);
    }

    public static function provider(): string
    {
        return self::get('provider') ?? 'anthropic';
    }

    public static function model(): string
    {
        return self::get('model') ?? self::defaultModel();
    }

    public static function defaultModel(?string $provider = null): string
    {
        $provider ??= self::provider();

        return self::DEFAULTS[$provider] ?? self::DEFAULTS['anthropic'];
    }

    public static function isEnabled(): bool
    {
        return Setting::getValue('ai_enabled', '1') === '1';
    }

    public static function isConfigured(): bool
    {
        return (bool) self::get('api_key');
    }

    /**
     * MSP-authored communication guidelines injected into AI draft replies.
     * Returns null when empty/unset so callers can gracefully skip.
     */
    public static function replyGuidelines(): ?string
    {
        $value = Setting::getValue('ai_reply_guidelines');

        return ($value && trim($value) !== '') ? trim($value) : null;
    }
}
