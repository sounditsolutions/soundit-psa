<?php

namespace App\Support;

use App\Models\Setting;

class PortalConfig
{
    public static function isEnabled(): bool
    {
        return Setting::getValue('portal_enabled', '0') === '1';
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
