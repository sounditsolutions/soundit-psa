<?php

namespace App\Services\Mesh;

use Illuminate\Support\Facades\Log;

/**
 * A Mesh call that did not succeed. The code is the HTTP status Mesh answered
 * with, or 0 when there is none (see MeshWriteClient::request()).
 *
 * Two kinds share this class, told apart by HOW the exception was built, never
 * by what its message says (#5271):
 *
 *  - A failure the client DETECTS ITSELF (a page ceiling, a row-count
 *    mismatch, a missing tenant or key, a refused field) is written by the
 *    PSA, quotes no vendor text, and may have happened after every call
 *    answered HTTP 200. Its throw site passes `clientDetected: true`.
 *  - Everything else is treated as UPSTREAM. Since #5761 and #5884, the
 *    request() throw sites of MeshClient and MeshWriteClient build their
 *    message ("Mesh API error: …" or "Mesh API unreachable: …") from the
 *    method, a redacted path, the HTTP status or cURL errno, and the
 *    exception class, never from Guzzle's message, and since #5978 they do
 *    not chain the Guzzle exception. The message is still not surfaced:
 *    it names a route, and statusPhrase() is the reporting contract.
 *
 * The default is upstream on purpose: a throw site that forgets the flag loses
 * its own diagnosis (the report falls back to the status), but it can never
 * pass its message through statusPhrase(). Callers that report a failure to a person, a stored field
 * or a log line use statusPhrase(); callers that branch on whether a request
 * left the PSA use nothingWasSent(), never the message.
 */
class MeshClientException extends \RuntimeException
{
    /**
     * @param  bool  $clientDetected  the PSA wrote this message itself and it
     *                                quotes no vendor text
     * @param  bool  $nothingSent  no request reached the wire: a pre-flight
     *                             refusal, or a connect-phase failure decided
     *                             before any request bytes were sent
     */
    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
        private readonly bool $clientDetected = false,
        private readonly bool $nothingSent = false,
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * True when no request for this call ever reached Mesh, so nothing can
     * have been committed upstream. Set at construction by the throw sites
     * that know it; never read from the message.
     */
    public function nothingWasSent(): bool
    {
        return $this->nothingSent;
    }

    /**
     * The failure as a phrase safe to report. With an HTTP status: "Mesh
     * answered the rule list read with HTTP 503". An upstream failure without
     * one: "the rule list read failed without an HTTP status from Mesh",
     * followed by "; nothing was sent" when the request never left the PSA —
     * nothing from its message. A failure the client detected itself: its own
     * message, which is the real cause and carries no vendor text.
     *
     * @param  string  $what  the call, as a noun phrase ("the rule list read")
     */
    public function statusPhrase(string $what): string
    {
        $status = (int) $this->getCode();

        if ($status > 0) {
            return "Mesh answered {$what} with HTTP {$status}";
        }

        if ($this->clientDetected && trim($this->getMessage()) !== '') {
            return $this->getMessage();
        }

        return "{$what} failed without an HTTP status from Mesh"
            .($this->nothingSent ? '; nothing was sent' : '');
    }

    /**
     * How Laravel's exception handler reports this exception when it is
     * uncaught or passed to report() (#5282). Without it the handler logs
     * getMessage() as the record message and the exception itself as context,
     * and Monolog's formatters print the message of every exception down the
     * getPrevious() chain. The request() throw sites no longer chain the
     * Guzzle exception (#5978), but a future throw site might, and its
     * message quotes the request URI, the host and a summary of the vendor's
     * body. So this writes one status-only line by class and statusPhrase(),
     * and returns true so the handler does not also write its default record.
     * report() does not cover the console renderer or (string) $e; not
     * chaining covers the MESSAGES those print. (string) $e also prints the
     * stack trace, and its frames show string arguments (a method, an
     * endpoint, a tenant id) cut to zend.exception_string_param_max_len
     * bytes whenever zend.exception_ignore_args is Off, which is PHP's
     * built-in default (#6051, measured in MeshUncaughtRenderTest). Not
     * chaining does not cover those; only that setting does.
     */
    public function report(): bool
    {
        Log::error('[Mesh] '.static::class.' reported: '.$this->statusPhrase('the request'));

        return true;
    }
}
