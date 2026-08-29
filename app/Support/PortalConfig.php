<?php

namespace App\Support;

use App\Models\Setting;

class PortalConfig
{
    public static function isEnabled(): bool
    {
        return Setting::getValue('portal_enabled', '0') === '1';
    }

    /**
     * Whether the self-service product catalog ("Shop") is enabled in the
     * portal. Opt-in per deployment; defaults off. Even when enabled, the
     * shop only appears if the operator has published orderable SKUs.
     */
    public static function shopEnabled(): bool
    {
        return Setting::getValue('portal_shop_enabled', '0') === '1';
    }

    /**
     * Whether the client-facing AI chatbot is turned on (operator opt-in,
     * default off). Whether it is actually usable also depends on the AI
     * provider being configured — see PortalChatbotService::isAvailable().
     */
    public static function chatbotEnabled(): bool
    {
        return Setting::getValue('portal_chatbot_enabled', '0') === '1';
    }

    /**
     * Lifetime, in days, stamped onto a self-service install link when it is
     * generated or rotated (#864). Operator-tunable; the default keeps a link
     * useful across the observed onboarding dawdle without leaving a
     * forwarded copy valid forever. A stored value of 0 or garbage falls back
     * to the default rather than minting an already-expired link.
     */
    public static function installTokenTtlDays(): int
    {
        $days = (int) Setting::getValue('portal_install_token_ttl_days', '30');

        return $days > 0 ? $days : 30;
    }

    public static function companyName(): string
    {
        return Setting::getValue('portal_company_name', config('app.name', 'Sound PSA'));
    }

    public static function logoUrl(): ?string
    {
        return Setting::getValue('portal_logo_url');
    }

    public static function supportEmail(): ?string
    {
        return Setting::getValue('graph_mailbox');
    }

    public static function supportPhone(): ?string
    {
        $phone = Setting::getValue('portal_support_phone');

        return $phone ?: null;
    }

    public static function billingUrl(): ?string
    {
        return Setting::getValue('portal_billing_url');
    }

    public static function billingLabel(): string
    {
        return Setting::getValue('portal_billing_label', 'Billing Portal');
    }

    public static function orderUrl(): ?string
    {
        return Setting::getValue('portal_order_url');
    }

    /**
     * Resolve the order URL with the client ID placeholder replaced.
     */
    public static function orderUrlForClient(int $clientId): ?string
    {
        $url = self::orderUrl();

        if (! $url) {
            return null;
        }

        return str_replace('{client_id}', (string) $clientId, $url);
    }
}
