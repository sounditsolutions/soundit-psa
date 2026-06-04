<?php

namespace App\Services\Zorus;

use App\Models\Client;
use App\Models\Email;
use Illuminate\Support\Facades\Log;

/**
 * Detects and parses Zorus domain unblock request notification emails.
 *
 * When an end user's DNS request is blocked by Zorus Filtering, they can
 * request that the domain be unblocked. Zorus sends a notification email
 * to the MSP from no-reply@zorustech.com with subject like:
 *   "Acme Corp Requests to Unblock Domain"
 *
 * Body format:
 *   Acme Corp on DESKTOP-ABC123 requests to unblock https://example.com/path
 *
 *   End user reason: No reason provided
 *
 *   [Click here to approve or deny the request](https://portal.zorustech.com/unblock-requests)
 */
class ZorusEmailParser
{
    /**
     * Check if an email is a Zorus domain unblock request notification.
     */
    public static function isZorusUnblockRequest(Email $email): bool
    {
        $from = strtolower($email->from_address ?? '');
        $subject = strtolower($email->subject ?? '');

        return $from === 'no-reply@zorustech.com'
            && str_contains($subject, 'requests to unblock');
    }

    /**
     * Parse structured fields from a Zorus unblock request email.
     *
     * @return array{company_name: ?string, hostname: ?string, blocked_url: ?string, reason: ?string, portal_url: ?string}|null
     */
    public static function parse(Email $email): ?array
    {
        if (! self::isZorusUnblockRequest($email)) {
            return null;
        }

        $body = $email->body_text ?? '';
        $subject = $email->subject ?? '';

        $fields = [
            'company_name' => self::extractCompanyName($subject),
            'hostname' => self::extractHostname($body),
            'blocked_url' => self::extractBlockedUrl($body),
            'reason' => self::extractReason($body),
            'portal_url' => 'https://portal.zorustech.com/unblock-requests',
        ];

        if (! $fields['company_name']) {
            Log::warning('[ZorusEmailParser] Failed to extract company name from unblock request', [
                'email_id' => $email->id,
                'subject' => $subject,
            ]);
        }

        // HTML-escape all string values for safe use in ticket descriptions
        return array_map(
            fn ($v) => $v !== null ? e($v) : null,
            $fields,
        );
    }

    /**
     * Extract company name from subject: "Acme Corp Requests to Unblock Domain"
     */
    private static function extractCompanyName(string $subject): ?string
    {
        if (preg_match('/^(.+?)\s+Requests to Unblock/i', $subject, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }

    /**
     * Extract hostname from body: "Acme Corp on DESKTOP-ABC123 requests to unblock ..."
     */
    private static function extractHostname(string $body): ?string
    {
        if (preg_match('/\bon\s+(\S+)\s+requests?\s+to\s+unblock/i', $body, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }

    /**
     * Extract the blocked URL from body: "... requests to unblock https://example.com/path"
     */
    private static function extractBlockedUrl(string $body): ?string
    {
        if (preg_match('/requests?\s+to\s+unblock\s+(https?:\/\/\S+)/i', $body, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }

    /**
     * Extract end user reason from body: "End user reason: some text"
     */
    private static function extractReason(string $body): ?string
    {
        if (preg_match('/End user reason:\s*(.+)$/mi', $body, $matches)) {
            $reason = trim($matches[1]);

            return ($reason !== '' && strtolower($reason) !== 'no reason provided') ? $reason : null;
        }

        return null;
    }

    /**
     * Resolve the Zorus company name to a PSA client via the Zorus API.
     *
     * Fetches the customer list from Zorus, finds the customer whose name
     * matches, then looks up the PSA client by zorus_customer_id.
     */
    public static function resolveClient(string $companyName): ?Client
    {
        if (! \App\Support\ZorusConfig::isConfigured()) {
            return null;
        }

        try {
            $zorusClient = new ZorusClient(['api_key' => \App\Support\ZorusConfig::get('api_key')]);
            $customers = $zorusClient->searchCustomers([], 1, 500);
            $items = $customers['items'] ?? $customers;

            foreach ($items as $customer) {
                $zorusName = $customer['name'] ?? $customer['companyName'] ?? '';
                if (strcasecmp($zorusName, $companyName) === 0) {
                    $uuid = $customer['customerUuid'] ?? $customer['uuid'] ?? null;
                    if ($uuid) {
                        return Client::where('zorus_customer_id', $uuid)->first();
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning('[ZorusEmailParser] Failed to resolve client via Zorus API', [
                'company_name' => $companyName,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Build a formatted ticket description from parsed Zorus fields.
     *
     * @param  array  $parsed  Output from self::parse() (already HTML-escaped)
     */
    public static function buildDescription(array $parsed): string
    {
        $lines = ['**Zorus Domain Unblock Request**'];

        if ($parsed['company_name']) {
            $lines[] = '- Company: '.$parsed['company_name'];
        }
        if ($parsed['hostname']) {
            $lines[] = '- Device: '.$parsed['hostname'];
        }
        if ($parsed['blocked_url']) {
            $lines[] = '- Blocked URL: '.$parsed['blocked_url'];
        }
        if ($parsed['reason']) {
            $lines[] = '- End user reason: '.$parsed['reason'];
        }
        if ($parsed['portal_url']) {
            $lines[] = '- [Approve/Deny in Zorus Portal]('.$parsed['portal_url'].')';
        }

        return implode("\n", $lines);
    }
}
