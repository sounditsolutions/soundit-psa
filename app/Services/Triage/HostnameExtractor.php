<?php

namespace App\Services\Triage;

/**
 * Extracts candidate hostnames, emails, and device name tokens from text.
 * Ported from HaloClaude triage/user_matcher.py regex patterns.
 */
class HostnameExtractor
{
    // Known computer name prefixes for bare (non-hyphenated) hostname matching.
    private const BARE_HOSTNAME_PREFIXES = [
        'DESKTOP', 'LAPTOP', 'NOTEBOOK', 'SERVER', 'SRV', 'PC',
        'WORKSTATION', 'WKST', 'WS',
    ];

    // System/noreply email prefixes to filter out.
    private const SYSTEM_EMAIL_PREFIXES = [
        'noreply@', 'no-reply@', 'mailer-daemon@', 'postmaster@',
        'donotreply@', 'do-not-reply@', 'notifications@', 'alert@', 'alerts@',
    ];

    // Stopwords for all-caps token extraction (never hostnames).
    private const CAPS_STOPWORDS = [
        // HTML remnants
        'HTML', 'HEAD', 'BODY', 'DIV', 'SPAN', 'TABLE', 'TBODY', 'THEAD',
        'STYLE', 'SCRIPT', 'FONT', 'CENTER', 'STRONG', 'BLOCKQUOTE',
        'FORM', 'INPUT', 'BUTTON', 'LABEL', 'SECTION', 'HEADER', 'FOOTER',
        // Common English
        'THE', 'AND', 'FOR', 'NOT', 'BUT', 'ALL', 'NEW', 'NOW', 'ARE',
        'WAS', 'HAS', 'HAD', 'CAN', 'DID', 'GET', 'GOT', 'MAY', 'SAY',
        'THIS', 'THAT', 'WITH', 'FROM', 'HAVE', 'BEEN', 'WILL', 'DOES',
        'THEY', 'WHAT', 'WHEN', 'WHICH', 'THEIR', 'THERE', 'YOUR',
        'WOULD', 'COULD', 'SHOULD', 'ABOUT', 'AFTER', 'OTHER',
        'THESE', 'THOSE', 'SOME', 'THAN', 'INTO', 'JUST', 'ALSO',
        'EACH', 'EVEN', 'ONLY', 'OVER', 'SUCH', 'VERY', 'LIKE',
        'THEN', 'MAKE', 'MADE', 'FIND', 'HERE', 'KNOW', 'TAKE',
        'COME', 'WANT', 'LOOK', 'NEED', 'WORK', 'CALL', 'BACK',
        'MUCH', 'MUST', 'WELL', 'STILL', 'SINCE', 'BOTH',
        'SURE', 'SAME', 'MOST', 'SENT', 'DEAR', 'HELP', 'PLEASE',
        'THANKS', 'THANK', 'HELLO', 'REGARDS',
        // Ticket/status terms
        'OPEN', 'CLOSED', 'PENDING', 'RESOLVED', 'ASSIGNED', 'TICKET',
        'NOTE', 'ACTION', 'UPDATE', 'STATUS', 'EMAIL', 'USER', 'CLIENT',
        'AGENT', 'SUBJECT', 'DETAILS', 'SUMMARY', 'PRIORITY',
        'HIGH', 'LOW', 'MEDIUM', 'CRITICAL', 'URGENT',
        // IT/OS/vendor terms
        'CPU', 'RAM', 'SSD', 'HDD', 'USB', 'GPU', 'DNS', 'DHCP',
        'HTTP', 'HTTPS', 'FTP', 'SSH', 'SSL', 'TLS', 'VPN', 'RDP',
        'BIOS', 'RAID', 'MFA', 'SSO', 'MDM', 'API', 'SQL', 'PDF',
        'WINDOWS', 'LINUX', 'MACOS', 'MICROSOFT', 'GOOGLE', 'APPLE',
        'DELL', 'LENOVO', 'OUTLOOK', 'TEAMS', 'OFFICE', 'AZURE',
        'INTUNE', 'DEFENDER', 'SENTINEL', 'ERROR', 'ALERT', 'WARNING',
        'FAILED', 'SUCCESS', 'OFFLINE', 'ONLINE', 'NETWORK', 'SERVICE',
        'MEMORY', 'DISK', 'SYSTEM', 'SOFTWARE', 'INSTALL', 'VERSION',
        'DEVICE', 'COMPUTER', 'PHONE', 'MOBILE', 'PRINTER', 'MONITOR',
        'DOMAIN', 'LOCAL', 'ADMIN', 'PASSWORD', 'RESET', 'ACCESS',
        'ACCOUNT', 'LOCKED', 'DISABLED', 'ENABLED',
        // Workstation/issue words
        'WORKSTATION', 'REBOOT', 'RESTART', 'ISSUE', 'PROBLEM', 'BROKEN',
        'NEEDS', 'RUNNING', 'SLOW', 'CRASH', 'FROZEN', 'STUCK',
        'SCREEN', 'BLUE', 'BLACK', 'LOGIN', 'LOGON', 'LOGOUT',
        'STARTUP', 'SHUTDOWN', 'BOOT', 'DRIVE', 'FILE', 'FOLDER',
        'BACKUP', 'RESTORE', 'WIFI', 'INTERNET', 'BROWSER', 'CHROME',
        'FIREFOX', 'EDGE', 'ADOBE', 'ZOOM', 'SLACK',
        // Vendor/tool names
        'NINJARMM', 'NINJA', 'DATTO', 'SENTINELONE', 'TODYL',
        'CONNECTWISE', 'AUTOMATE', 'ZORUS', 'MESH',
    ];

    /**
     * Extract candidate device hostnames from text using three regex patterns.
     *
     * @return string[] Deduplicated, order-preserved
     */
    public static function extractHostnames(string $text): array
    {
        if (! $text) {
            return [];
        }

        // Strip HTML and email header noise before hostname extraction
        $text = strip_tags($text);
        $text = self::stripEmailNoise($text);
        $upperText = strtoupper($text);

        $matches = [];

        // Pattern 1: Hyphenated — DESKTOP-ABC123, SRV-DC01, KM-FRONTDESK
        if (preg_match_all('/\b[A-Z][A-Z0-9]+(?:-[A-Z0-9]+)+\b/', $upperText, $m)) {
            $matches = array_merge($matches, $m[0]);
        }

        // Pattern 2: Underscore — BACK_OFFICE, BEN_LAPTOP
        if (preg_match_all('/\b[A-Z][A-Z0-9]+(?:_[A-Z0-9]+)+\b/', $upperText, $m)) {
            $matches = array_merge($matches, $m[0]);
        }

        // Pattern 3: Known-prefix bare — PC12, SRVSQL01, DESKTOPJOHN
        $prefixPattern = implode('|', self::BARE_HOSTNAME_PREFIXES);
        if (preg_match_all('/\b(?:'.$prefixPattern.')[A-Z0-9]+\b/', $upperText, $m)) {
            $matches = array_merge($matches, $m[0]);
        }

        // Deduplicate preserving order
        return array_values(array_unique($matches));
    }

    /**
     * Extract unique non-system email addresses from text.
     *
     * @return string[] Deduplicated, order-preserved
     */
    public static function extractEmails(string $text): array
    {
        if (! $text) {
            return [];
        }

        preg_match_all('/[\w.+-]+@[\w.-]+\.\w{2,}/', $text, $m);

        $seen = [];
        $result = [];
        foreach ($m[0] as $email) {
            $lower = strtolower($email);
            if (isset($seen[$lower])) {
                continue;
            }
            if (self::isSystemEmail($lower)) {
                continue;
            }
            $seen[$lower] = true;
            $result[] = $email;
        }

        return $result;
    }

    /**
     * Extract all-caps tokens as broad hostname candidates.
     * Min 4 chars, filters stopwords, skips if text is >40% uppercase.
     *
     * @param  string[]  $exclude  Tokens already tried (from hostname extraction)
     * @return string[] Up to 15 candidates
     */
    public static function extractAllCapsTokens(string $text, array $exclude = []): array
    {
        if (! $text) {
            return [];
        }

        // Check caps ratio — skip if >40% uppercase
        $alphaChars = preg_replace('/[^a-zA-Z]/', '', $text);
        if ($alphaChars) {
            $upperCount = preg_match_all('/[A-Z]/', $alphaChars);
            $ratio = $upperCount / strlen($alphaChars);
            if ($ratio > 0.40) {
                return [];
            }
        }

        // Strip HTML, then uppercase
        $cleaned = strip_tags($text);
        $upperText = strtoupper($cleaned);

        // Tokens: 4-30 uppercase alphanumeric chars starting with a letter
        preg_match_all('/\b[A-Z][A-Z0-9]{3,29}\b/', $upperText, $m);

        $stopwords = array_flip(self::CAPS_STOPWORDS);
        $excludeSet = array_flip(array_map('strtoupper', $exclude));

        $seen = [];
        $result = [];
        foreach ($m[0] as $token) {
            if (isset($seen[$token]) || isset($stopwords[$token]) || isset($excludeSet[$token])) {
                continue;
            }
            $seen[$token] = true;
            $result[] = $token;
            if (count($result) >= 15) {
                break;
            }
        }

        return $result;
    }

    /**
     * Check if an email address is a system/noreply address.
     */
    public static function isSystemEmail(string $email): bool
    {
        $lower = strtolower($email);
        foreach (self::SYSTEM_EMAIL_PREFIXES as $prefix) {
            if (str_starts_with($lower, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Strip email headers, base64 chunks, and MIME boundaries from text.
     * Prevents false hostname matches from Exchange header noise.
     */
    private static function stripEmailNoise(string $text): string
    {
        $lines = explode("\n", $text);
        $clean = [];
        $inHeader = false;

        foreach ($lines as $line) {
            $stripped = trim($line);

            // Skip email header lines (X-MS-Exchange-*, Content-Type:, etc.)
            if (preg_match('/^[A-Za-z][A-Za-z0-9-]*:\s?/', $stripped)) {
                $inHeader = true;

                continue;
            }

            // Continuation lines after a header
            if ($inHeader && $stripped && isset($line[0]) && in_array($line[0], [' ', "\t"])) {
                continue;
            }

            $inHeader = false;

            // Skip base64 chunks
            if (preg_match('/^[A-Za-z0-9+\/=]{40,}$/', $stripped)) {
                continue;
            }

            // Skip MIME boundaries
            if (preg_match('/^--[\w.+=-]{10,}$/', $stripped)) {
                continue;
            }

            $clean[] = $line;
        }

        return implode("\n", $clean);
    }
}
