<?php

namespace App\Services\Wiki\Mining;

class WikiRedactor
{
    /**
     * Secret-shape corpus (spec §5.2 layer 1). Order matters: PEM and connection
     * strings before generic keyword forms. Known gaps (documented in spec §5.2):
     * dictated character-by-character secrets, base32 TOTP seeds.
     */
    private const SECRET_PATTERNS = [
        // PEM blocks (multi-line)
        '/-----BEGIN [A-Z ]+-----.*?-----END [A-Z ]+-----/s',
        // connection strings with embedded credentials
        '/\b[a-z][a-z0-9+.-]*:\/\/[^\s:@\/]+:[^\s@\/]+@[^\s]+/i',
        // keyword = / : value forms (password, pass, pwd, secret, token, api key, license)
        '/\b(?:password|passwd|pass|pwd|secret|api[_\s-]?key|access[_\s-]?key|auth[_\s-]?token|token|license[_\s-]?key)\s*(?:is|was|[:=])\s*\S+/i',
        // conversational: "set the X password to VALUE", "credentials are user / pass"
        '/\b(?:password|passphrase|pin)\s+(?:to|is now|set to)\s+\S+/i',
        '/\bcredentials?\s+(?:are|is)\s+\S+(?:\s*\/\s*\S+)?/i',
        // JWT-shaped tokens (three base64url segments)
        '/\beyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{5,}(?:\.[A-Za-z0-9_-]+)?/',
        // Long base64-DISTINCTIVE runs only. Security review C1: the old rule
        // /[A-Za-z0-9+\/_-]{32,}={0,2}/ also ate 32-char hardware serials, unhyphenated
        // GUIDs, and RMM/asset IDs — the exact durable identifiers the wiki captures —
        // silently corrupting real facts. Require a base64-distinctive character (+, /,
        // or a trailing =) so plain alphanumeric serials/GUIDs (which lack them) survive.
        // Documented residual gap (accepted v1): base32 TOTP seeds.
        '/\b[A-Za-z0-9+\/_-]{24,}[+\/]+[A-Za-z0-9+\/_-]*={0,2}\b/',
        '/\b[A-Za-z0-9+\/_-]{32,}={1,2}\b/',
    ];

    private const INJECTION_PATTERNS = [
        '/\bignore\s+(?:all\s+)?(?:previous|prior|above)\s+instructions\b/i',
        '/\bdisregard\s+(?:all\s+)?(?:previous|prior)\s+instructions\b/i',
        '/^\s*(?:system|assistant)\s*:/im',
        '/\[\s*\/?INST\s*\]/i',
        '/<\s*\/?(?:system|instructions?)\s*>/i',
        '/\byou\s+must\s+always\b/i',
        '/\bnew\s+(?:system\s+)?prompt\b/i',
    ];

    // Composed pages delimit fact blocks with these; a statement containing one
    // would corrupt splicing (spec carry-over: marker-string guard).
    private const MARKER_PATTERN = '/<!--\s*wiki:facts:[a-z0-9-]*:(?:start|end)\s*-->/i';

    /** Layer 1: rewrite untrusted input before the AI sees it. */
    public function redact(string $text): string
    {
        foreach (self::SECRET_PATTERNS as $pattern) {
            $text = preg_replace($pattern, '[REDACTED:credential]', $text);
        }

        return $text;
    }

    /**
     * Layer 3 + injection + marker guard: scan AI OUTPUT before storage.
     * Any violation quarantines the run.
     *
     * @return array<int, array{class: string, pattern: string}>
     */
    public function scan(string $text): array
    {
        $violations = [];

        foreach (self::SECRET_PATTERNS as $pattern) {
            if (preg_match($pattern, $text)) {
                $violations[] = ['class' => 'credential', 'pattern' => $pattern];
            }
        }
        foreach (self::INJECTION_PATTERNS as $pattern) {
            if (preg_match($pattern, $text)) {
                $violations[] = ['class' => 'injection', 'pattern' => $pattern];
            }
        }
        if (preg_match(self::MARKER_PATTERN, $text)) {
            $violations[] = ['class' => 'marker', 'pattern' => self::MARKER_PATTERN];
        }

        return $violations;
    }
}
