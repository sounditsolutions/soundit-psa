<?php

namespace App\Services\Tactical\Actions;

use App\Services\Wiki\Mining\WikiRedactor;

/**
 * Argv-aware audit redaction for the action bus (spec §11 / amendment B1).
 *
 * Two layers, applied PER STRING VALUE before the value becomes a JSON column
 * (never redact(json_encode(...)) — JSON escaping slips PEM/connection-strings
 * past the patterns):
 *
 *   1. Argv-flag redaction — the secret is the token AFTER a sensitive flag
 *      (the `-Password <secret>` / `-ApiKey <secret>` / `-ServosityCredPass
 *      <secret>` shape this app actually uses). WikiRedactor only catches
 *      `key=value`/`key: value`, so it would miss these entirely.
 *   2. WikiRedactor::redact() — catches inline key=value / connection-string /
 *      PEM / JWT secret shapes inside a single string value.
 */
class ActionRedactor
{
    /** A preceding argv flag whose VALUE token must be redacted. */
    private const SENSITIVE_FLAG = '/(?:cred|pass|pwd|secret|key|token|user)/i';

    private const REDACTED = '[REDACTED:credential]';

    /** Cap stored output at a sane bound (the column is TEXT ~64KB). */
    public const OUTPUT_MAX = 8000;

    public function __construct(
        private readonly WikiRedactor $wiki = new WikiRedactor,
    ) {}

    /**
     * Redact an argv list: blank the value token following any sensitive flag,
     * then run each remaining string through WikiRedactor.
     *
     * @param  array<int, mixed>  $argv
     * @return array<int, mixed>
     */
    public function redactArgv(array $argv): array
    {
        $out = [];
        $redactNext = false;

        foreach ($argv as $token) {
            if ($redactNext && is_string($token)) {
                $out[] = self::REDACTED;
                $redactNext = false;

                continue;
            }
            $redactNext = false;

            if (is_string($token)) {
                // A flag like "-Password" / "--api-key" arms the next token.
                if ($this->looksLikeFlag($token) && preg_match(self::SENSITIVE_FLAG, $token)) {
                    $redactNext = true;
                    $out[] = $token; // keep the flag; only its value is secret

                    continue;
                }

                $out[] = $this->redactString($token);

                continue;
            }

            $out[] = $token;
        }

        return $out;
    }

    /** Run a single string value through the secret-shape patterns. */
    public function redactString(string $value): string
    {
        return $this->wiki->redact($value);
    }

    /**
     * Recursively redact a params array: argv arrays via redactArgv(), nested
     * arrays recursively, scalar strings via WikiRedactor. Structure (and
     * non-string scalars) are preserved.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function redactParams(array $params): array
    {
        $out = [];

        foreach ($params as $key => $value) {
            if (is_array($value)) {
                // Treat a list as argv (handles the secret-flag adjacency);
                // an assoc array is walked recursively.
                $out[$key] = array_is_list($value)
                    ? $this->redactArgv($value)
                    : $this->redactParams($value);

                continue;
            }

            $out[$key] = is_string($value) ? $this->redactString($value) : $value;
        }

        return $out;
    }

    /** Redact + truncate command output for the audit row. */
    public function redactOutput(?string $output): ?string
    {
        if ($output === null) {
            return null;
        }

        $clean = $this->redactString($output);

        if (mb_strlen($clean) > self::OUTPUT_MAX) {
            $clean = mb_substr($clean, 0, self::OUTPUT_MAX)."\n…[truncated]";
        }

        return $clean;
    }

    /**
     * Treat a token as a flag (so we only arm redaction off real flags, not a
     * positional value that merely contains "key"/"user").
     */
    private function looksLikeFlag(string $token): bool
    {
        return $token !== '' && ($token[0] === '-' || $token[0] === '/');
    }
}
