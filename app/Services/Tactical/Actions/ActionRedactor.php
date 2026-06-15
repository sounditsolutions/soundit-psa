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

    /**
     * Output-path backstop (code-review C1, immutable audit store).
     *
     * WikiRedactor only catches keyword-ADJACENT secrets (`password=…`, `token:
     * …`) and DELIBERATELY preserves bare alphanumeric runs (serials/GUIDs/RMM
     * ids are real wiki facts there — see WikiRedactor::SECRET_PATTERNS). But the
     * audit `output` column is a different context: a curated/recovery script
     * that prints a bare credential on its own line would otherwise land it
     * VERBATIM in an append-only row. These stricter patterns apply ONLY to
     * audit output (never to wiki mining), trading a redacted serial for never
     * persisting a leaked credential:
     *   A. auth-scheme + space (`Bearer <tok>` / `Token <tok>` — no `=`, so the
     *      WikiRedactor keyword=value rule misses them); keep the scheme word.
     *   B. AWS access-key ids (AKIA…/ASIA… + ≥12 chars).
     *   C. a long (≥32) contiguous high-entropy alnum run — a hex/base64url API
     *      token standing alone. Dash-structured UUIDs split into <32 segments,
     *      so they survive; file paths split on `/`/`.`, so they survive too.
     */
    private const OUTPUT_SECRET_PATTERNS = [
        '/\b(bearer|token|basic|negotiate)\s+[A-Za-z0-9+\/=_.\-]{12,}/i' => '$1 '.self::REDACTED,
        '/\b(?:AKIA|ASIA|AGPA|AIDA|AROA|AIPA|ANPA|ANVA|ASCA)[A-Z0-9]{12,}\b/' => self::REDACTED,
        '/(?<![A-Za-z0-9])[A-Za-z0-9]{32,}(?![A-Za-z0-9])/' => self::REDACTED,
    ];

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
                $isFlag = $this->looksLikeFlag($token);

                // "-Flag=value" single-token form: scrub the value, keep the flag.
                if ($isFlag && str_contains($token, '=')) {
                    [$flag, $value] = explode('=', $token, 2);
                    if ($value !== '' && preg_match(self::SENSITIVE_FLAG, $flag)) {
                        $out[] = $flag.'='.self::REDACTED;

                        continue;
                    }
                }

                // "-Flag value" two-token form: a flag like "-Password" /
                // "--api-key" arms the NEXT token.
                if ($isFlag && preg_match(self::SENSITIVE_FLAG, $token)) {
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

        // Truncate FIRST, then redact: bound the regex work, and keep the
        // truncation marker visible even when redaction collapses a long run.
        $truncated = mb_strlen($output) > self::OUTPUT_MAX;
        if ($truncated) {
            $output = mb_substr($output, 0, self::OUTPUT_MAX);
        }

        // WikiRedactor (keyword-adjacent shapes) + the stricter output-path
        // backstop for bare credentials a script may print.
        $clean = $this->redactOutputSecrets($this->redactString($output));

        if ($truncated) {
            $clean .= "\n…[truncated]";
        }

        return $clean;
    }

    /**
     * Command-line credential shapes that the keyword=value (WikiRedactor) and
     * bare-token (OUTPUT_SECRET_PATTERNS) layers BOTH miss, because an ad-hoc
     * `cmd` is one opaque free-text string with no argv-token structure to walk.
     * These target the well-known glued/positional credential forms a tech might
     * type inline (amendment B2 names the first two as binding):
     *   D. mysql/mariadb glued password: `-p<secret>` / `--password=<secret>`
     *      (the `-p` short form has NO separator, so the keyword rule can't see it).
     *   E. Windows `net user <name> <password>` — the password is the 4th
     *      positional token, recognizable only by the `net user` command shape.
     * Applied ONLY to the command-string redaction path (never wiki/output).
     */
    private const COMMAND_SECRET_PATTERNS = [
        // `--password=<secret>` long form (any case): keep the flag, scrub the value.
        '/(--password=)(["\']?)[^\s"\']+\2/i' => '$1'.self::REDACTED,
        // mysql-family glued short password `-p<secret>`: case-SENSITIVE lowercase
        // `-p` (MySQL's password flag; `-P` is the PORT) and the `-p` must START a
        // token (preceded by whitespace/start) so it never matches mid-word — e.g.
        // PowerShell `Get-Process`/`-Path` (a letter precedes their `-P`) survive.
        // The optional-quoted value runs to the next whitespace.
        '/(?<=\s|^)(-p)(["\']?)[^\s"\']+\2/' => '$1'.self::REDACTED,
        // `net user <name> <password> [/add|/flags]`: scrub the 3rd token (password).
        // Anchored on `net user` (NOT `net use <drive>`, a different command).
        '/(\bnet\s+user\s+\S+\s+)\S+/i' => '$1'.self::REDACTED,
    ];

    /**
     * Redact a free-text COMMAND string for the confirm summary / audit / ticket
     * note (amendment B1). An ad-hoc cmd is one opaque string with no argv
     * structure, so the argv-flag layer (redactArgv) cannot help — instead run:
     *   1. the command-line credential shapes (glued -p / net user — B2),
     *   2. WikiRedactor (inline keyword=value / connection-string / PEM / JWT),
     *   3. the bare-credential backstop (OUTPUT_SECRET_PATTERNS: auth-scheme,
     *      AWS key ids, a lone 32+ high-entropy run).
     *
     * Residual (documented, accepted per B2): short/low-entropy POSITIONAL
     * secrets outside the recognized command shapes (e.g. a bare `Hunter2`) may
     * still pass — args are best-effort redacted; techs must avoid inline secrets.
     */
    public function redactCommandString(string $command): string
    {
        foreach (self::COMMAND_SECRET_PATTERNS as $pattern => $replacement) {
            $command = preg_replace($pattern, $replacement, $command);
        }

        // WikiRedactor (keyword-adjacent) + the bare-credential output backstop.
        return $this->redactOutputSecrets($this->redactString($command));
    }

    /** Apply the audit-output-only secret backstop (see OUTPUT_SECRET_PATTERNS). */
    private function redactOutputSecrets(string $output): string
    {
        foreach (self::OUTPUT_SECRET_PATTERNS as $pattern => $replacement) {
            $output = preg_replace($pattern, $replacement, $output);
        }

        return $output;
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
