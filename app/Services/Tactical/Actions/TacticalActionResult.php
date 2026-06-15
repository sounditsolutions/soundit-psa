<?php

namespace App\Services\Tactical\Actions;

/**
 * The normalized outcome of a Tactical action dispatch (spec §5.1/§5.2).
 *
 * Every path through the bus — happy, offline, auth-denied, param-rejected,
 * confirm-blocked, or upstream error — returns one of these, never an
 * unhandled exception to the caller. The status set (amendment m2):
 *
 *   ok       — executed, agent responded
 *   offline  — transport/NATS failure; the agent is unreachable (a normal,
 *              safe result, NOT an error page)
 *   error    — an upstream/HTTP error (e.g. 4xx/5xx) — distinct from offline so
 *              auth failures / key compromise are never masked
 *   denied   — the actor failed the capability gate
 *   rejected — params failed validation (action NOT executed)
 *   blocked  — a destructive action lacked a valid confirm token (NOT executed)
 *
 * It is immutable and side-effect-free.
 */
final class TacticalActionResult
{
    public function __construct(
        public readonly string $status,
        public readonly ?string $stdout = null,
        public readonly ?int $retcode = null,
        public readonly ?string $message = null,
        public readonly ?string $stderr = null,
    ) {}

    public static function ok(?string $stdout = null, int $retcode = 0, ?string $stderr = null): self
    {
        return new self('ok', $stdout, $retcode, null, $stderr);
    }

    public static function offline(string $message): self
    {
        return new self('offline', null, null, $message);
    }

    public static function error(string $message): self
    {
        return new self('error', null, null, $message);
    }

    public static function denied(string $message): self
    {
        return new self('denied', null, null, $message);
    }

    /** Params failed validation — the action was not executed. */
    public static function rejected(string $message): self
    {
        return new self('rejected', null, null, $message);
    }

    /** A destructive action lacked a valid confirm token — not executed. */
    public static function blocked(string $message): self
    {
        return new self('blocked', null, null, $message);
    }

    public function isOk(): bool
    {
        return $this->status === 'ok';
    }

    public function isOffline(): bool
    {
        return $this->status === 'offline';
    }

    /**
     * The columns the bus (T5) writes onto the tactical_action_logs row,
     * keyed to match the table. The bus is responsible for redacting/truncating
     * `output` before persistence; the keys here are the canonical shape.
     *
     * The audit table has no stderr column, so stderr is folded into `output`
     * under a clear marker — it is still redacted + persisted as one blob.
     *
     * @return array{result_status: string, output: ?string, retcode: ?int, message: ?string}
     */
    public function audit(): array
    {
        $output = $this->stdout;
        if ($this->stderr !== null && $this->stderr !== '') {
            $output = ($output ?? '')."\n[stderr]\n".$this->stderr;
        }

        return [
            'result_status' => $this->status,
            'output' => $output,
            'retcode' => $this->retcode,
            'message' => $this->message,
        ];
    }
}
