<?php

namespace App\Services\Tactical\Actions;

use App\Services\Tactical\TacticalClient;

/**
 * Run a curated-library Tactical script as a bus action (spec §5.1).
 *
 * Non-destructive (curated scripts ⇒ no confirm). Side-effect-free w.r.t. PSA
 * models (amendment m5): it talks ONLY to TacticalClient — the controller owns
 * resolving the TacticalScript and any ticket-note side effect.
 *
 * Expected params (post-controller resolution):
 *   - tactical_script_id (int, required) — the TRMM script id
 *   - args (string, optional)           — raw arg string, argv-tokenized here
 *   - timeout (int, optional 10..600)   — seconds
 *
 * Fire-and-forget mode ($fireAndForget, spec §5.1 amendment M7 / bd psa-nfqd):
 * the default synchronous path waits for the agent's output (output=wait). A
 * long deploy — e.g. the ~10-minute Servosity backup installer — cannot run
 * that way: the TacticalClient holds a 30s HTTP timeout, so a still-running
 * install would abort the request and the bus would mis-audit it `offline`.
 * In fire-and-forget mode the agent runs the script detached (output=forget)
 * and execute() returns `ok` with no captured output, so the bus authorizes +
 * audits the single dispatch without blocking on the run. Dispatched via the
 * queued RunTacticalScriptJob (the async run-script path).
 */
class RunScriptAction implements TacticalAction
{
    private const TIMEOUT_MIN = 10;

    private const TIMEOUT_MAX = 600;

    private const TIMEOUT_DEFAULT = 120;

    public function __construct(
        private readonly ActionRedactor $redactor = new ActionRedactor,
        private readonly bool $fireAndForget = false,
    ) {}

    public function key(): string
    {
        return 'tactical.run_script';
    }

    public function isDestructive(): bool
    {
        return false;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{tactical_script_id: int, args: array<int, string>, timeout: int}
     */
    public function validateParams(array $params): array
    {
        $scriptId = $params['tactical_script_id'] ?? null;
        if (! is_numeric($scriptId) || (int) $scriptId <= 0) {
            throw new InvalidActionParams('A valid tactical_script_id is required.');
        }

        $timeout = $params['timeout'] ?? self::TIMEOUT_DEFAULT;
        if (! is_numeric($timeout)) {
            throw new InvalidActionParams('timeout must be an integer.');
        }
        $timeout = (int) $timeout;
        if ($timeout < self::TIMEOUT_MIN || $timeout > self::TIMEOUT_MAX) {
            throw new InvalidActionParams('timeout must be between '.self::TIMEOUT_MIN.' and '.self::TIMEOUT_MAX.' seconds.');
        }

        $rawArgs = $params['args'] ?? '';
        if (! is_string($rawArgs)) {
            throw new InvalidActionParams('args must be a string.');
        }

        return [
            'tactical_script_id' => (int) $scriptId,
            'args' => $this->tokenize($rawArgs),
            'timeout' => $timeout,
        ];
    }

    public function summary(array $params): string
    {
        $args = $params['args'] ?? [];
        $argList = is_array($args) ? $args : [];
        $redactedArgs = implode(' ', $this->redactor->redactArgv($argList));
        $scriptId = $params['tactical_script_id'] ?? '?';

        return trim("Run library script #{$scriptId} {$redactedArgs}");
    }

    public function execute(TacticalClient $client, string $agentId, array $params): TacticalActionResult
    {
        // Fire-and-forget: the agent runs the script detached (output=forget)
        // and we do not wait for output. Used for long deploys that would
        // otherwise trip the client's 30s HTTP timeout in wait mode. The bus
        // still audits this one dispatch (result `ok`, no stdout/retcode).
        if ($this->fireAndForget) {
            $client->runScriptAsync(
                $agentId,
                (int) $params['tactical_script_id'],
                $params['args'] ?? [],
                (int) ($params['timeout'] ?? self::TIMEOUT_DEFAULT),
            );

            return TacticalActionResult::ok();
        }

        $raw = $client->runScript(
            $agentId,
            (int) $params['tactical_script_id'],
            $params['args'] ?? [],
            (int) ($params['timeout'] ?? self::TIMEOUT_DEFAULT),
        );

        $result = $this->responsePayload($raw);

        $stdout = $result['stdout'] ?? $result['output'] ?? '';
        if (! is_scalar($stdout)) {
            $stdout = '';
        }

        $stderr = $result['stderr'] ?? null;
        if (! is_scalar($stderr)) {
            $stderr = null;
        }

        return TacticalActionResult::ok((string) $stdout, $this->retcode($result), $stderr !== null && (string) $stderr !== '' ? (string) $stderr : null);
    }

    /** @return array<string, mixed> */
    private function responsePayload(mixed $raw): array
    {
        if (! is_array($raw)) {
            return ['stdout' => is_scalar($raw) ? (string) $raw : ''];
        }

        foreach (['script_results', 'result', 'results'] as $key) {
            if (isset($raw[$key]) && is_array($raw[$key])) {
                return $raw[$key];
            }
        }

        return $raw;
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

    /**
     * Argv-tokenize a raw arg string, respecting single + double quotes (so
     * "C:\Program Files\x" and 'New Folder' stay single tokens). Replaces the
     * old explode(' ') which split paths/quoted values mid-token.
     *
     * @return array<int, string>
     */
    private function tokenize(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }

        $tokens = [];
        $current = '';
        $inToken = false;
        $quote = null; // null | '"' | "'"
        $len = strlen($raw);

        for ($i = 0; $i < $len; $i++) {
            $ch = $raw[$i];

            if ($quote !== null) {
                if ($ch === $quote) {
                    $quote = null; // close quote; an empty quoted value is still a token
                } else {
                    $current .= $ch;
                }

                continue;
            }

            if ($ch === '"' || $ch === "'") {
                $quote = $ch;
                $inToken = true;

                continue;
            }

            if ($ch === ' ' || $ch === "\t") {
                if ($inToken) {
                    $tokens[] = $current;
                    $current = '';
                    $inToken = false;
                }

                continue;
            }

            $current .= $ch;
            $inToken = true;
        }

        if ($inToken) {
            $tokens[] = $current;
        }

        return $tokens;
    }
}
