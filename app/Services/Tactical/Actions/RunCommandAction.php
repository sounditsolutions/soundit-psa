<?php

namespace App\Services\Tactical\Actions;

use App\Services\Tactical\TacticalClient;

/**
 * Ad-hoc command — the headline DESTRUCTIVE action and the most dangerous
 * capability in the integration: it runs an arbitrary command on an endpoint
 * (arbitrary remote code execution). isDestructive() === true, so the bus
 * requires a confirm token; and because cmd has free-text payload, the token is
 * bound to payloadHash() = sha256 of the canonical {shell,cmd,timeout} (spec
 * §11.3 / amendment A2) — a confirm minted for `whoami` cannot be replayed to
 * run `format C:`.
 *
 * Defenses (all server-side, fail-closed):
 *   - shell is allow-listed to exactly Tactical's set (C2); absent/empty rejects.
 *   - timeout is bounded 10..600 (C2); 0/huge rejects (a huge timeout ties up a
 *     web worker on the NATS round-trip).
 *   - the command is a DISCRETE opaque field — NO PSA-side tokenization or shell
 *     concatenation (A2); only an outer trim() for the empty-check. The trimmed
 *     string is what is hashed, displayed, AND executed (displayed==hashed==run).
 *   - the dangerous body fields (custom_shell / env_vars / run_as_user) are
 *     pinned in TacticalClient::cmd, never threaded from input (C1).
 *
 * Side-effect-free w.r.t. PSA models (m5): it talks only to TacticalClient; the
 * bus owns audit (redacted) and the controller owns any ticket-note side effect.
 */
class RunCommandAction implements TacticalAction
{
    private const TIMEOUT_MIN = 10;

    private const TIMEOUT_MAX = 600;

    /** Tactical's exact accepted shell set (parity, no PSA-side OS narrowing). */
    private const ALLOWED_SHELLS = ['cmd', 'powershell', 'shell'];

    public function __construct(
        private readonly ActionRedactor $redactor = new ActionRedactor,
    ) {}

    public function key(): string
    {
        return 'tactical.run_command';
    }

    public function isDestructive(): bool
    {
        return true;
    }

    /**
     * Fail-closed validation → the ONE canonical {shell, cmd, timeout} the
     * payloadHash, confirm token, display, and execution all derive from
     * (amendment A1/A2). Order is canonical so json_encode is stable.
     *
     * @param  array<string, mixed>  $params
     * @return array{cmd: string, shell: string, timeout: int}
     */
    public function validateParams(array $params): array
    {
        $shell = $params['shell'] ?? null;
        if (! is_string($shell) || ! in_array($shell, self::ALLOWED_SHELLS, true)) {
            throw new InvalidActionParams(
                'shell must be one of: '.implode(', ', self::ALLOWED_SHELLS).'.'
            );
        }

        $rawCmd = $params['cmd'] ?? null;
        if (! is_string($rawCmd)) {
            throw new InvalidActionParams('A command is required.');
        }
        // A2: an OUTER trim only — the command content is NOT otherwise altered.
        $cmd = trim($rawCmd);
        if ($cmd === '') {
            throw new InvalidActionParams('A command is required.');
        }

        $timeout = $params['timeout'] ?? null;
        if (! is_numeric($timeout)) {
            throw new InvalidActionParams('timeout must be an integer.');
        }
        $timeout = (int) $timeout;
        if ($timeout < self::TIMEOUT_MIN || $timeout > self::TIMEOUT_MAX) {
            throw new InvalidActionParams(
                'timeout must be between '.self::TIMEOUT_MIN.' and '.self::TIMEOUT_MAX.' seconds.'
            );
        }

        // Exactly the canonical triplet — the dangerous body keys (custom_shell /
        // env_vars / run_as_user) are dropped here and pinned in the client (C1/C2).
        return [
            'cmd' => $cmd,
            'shell' => $shell,
            'timeout' => $timeout,
        ];
    }

    /**
     * The confirm-token binding (amendment A2): sha256 over the canonical typed
     * array [shell, cmd, timeout]. Stable for a given command; changes the instant
     * the command (or shell/timeout) changes, so a token can't be reused for a
     * different command. Receives post-validateParams params.
     *
     * @param  array<string, mixed>  $params
     */
    public function payloadHash(array $params): ?string
    {
        $shell = (string) ($params['shell'] ?? '');
        $cmd = (string) ($params['cmd'] ?? '');
        $timeout = (int) ($params['timeout'] ?? 0);

        return hash('sha256', json_encode([$shell, $cmd, $timeout]));
    }

    /**
     * The exact resolved command, secret-redacted (B1/B2). Routed through
     * ActionRedactor::redactCommandString — WikiRedactor + the bare-credential
     * backstop + the command-line credential shapes — because a free-text cmd is
     * one opaque string the argv-flag layer can't help with.
     *
     * @param  array<string, mixed>  $params  post-validateParams
     */
    public function summary(array $params): string
    {
        $shell = (string) ($params['shell'] ?? '?');
        $cmd = (string) ($params['cmd'] ?? '');

        return '['.$shell.'] '.$this->redactor->redactCommandString($cmd);
    }

    public function execute(TacticalClient $client, string $agentId, array $params): TacticalActionResult
    {
        $raw = $client->cmd(
            $agentId,
            (string) $params['cmd'],
            (string) $params['shell'],
            (int) $params['timeout'],
        );

        // D1: the cmd endpoint returns a bare STRING as the PRIMARY shape (spec
        // §3), so a non-array result is the EXPECTED case — normalize it to stdout.
        // An empty string is a command that printed nothing: still `ok`, never a
        // falsy-triggered error. An object reply is the secondary/defensive case.
        if (is_array($raw)) {
            $stdout = $raw['stdout'] ?? $raw['output'] ?? $raw['results'] ?? '';
            if (! is_scalar($stdout)) {
                $stdout = '';
            }
            $stderr = $raw['stderr'] ?? null;
            if (! is_scalar($stderr)) {
                $stderr = null;
            }

            return TacticalActionResult::ok((string) $stdout, $this->retcode($raw), $stderr !== null && (string) $stderr !== '' ? (string) $stderr : null);
        }

        return TacticalActionResult::ok(is_scalar($raw) ? (string) $raw : '');
    }

    /** @param array<string, mixed> $payload */
    private function retcode(array $payload): ?int
    {
        foreach (['retcode', 'return_code', 'exit_code'] as $key) {
            if (array_key_exists($key, $payload) && is_numeric($payload[$key])) {
                return (int) $payload[$key];
            }
        }

        return null;
    }
}
