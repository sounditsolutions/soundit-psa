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
     * @param  bool  $noStatusRecorded  the HTTP client threw before a Mesh
     *                                  status was recorded, and whether
     *                                  Mesh answered is not known (#6104)
     */
    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
        private readonly bool $clientDetected = false,
        private readonly bool $nothingSent = false,
        private readonly bool $noStatusRecorded = false,
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
     * answered the rule list read with HTTP 503"; with a 3xx (#6220): "the
     * Mesh host (or something in front of it) answered the rule list read
     * with HTTP 302". An upstream failure without
     * one: "the rule list read failed without an HTTP status from Mesh",
     * followed by "; nothing was sent" when the request never left the PSA —
     * nothing from its message. An HTTP-client failure with no status
     * recorded (#6104): "the rule list read failed in the PSA's HTTP client
     * with no Mesh status recorded". A failure the client detected itself: its own
     * message, which is the real cause and carries no vendor text.
     *
     * @param  string  $what  the call, as a noun phrase ("the rule list read")
     */
    public function statusPhrase(string $what): string
    {
        $status = (int) $this->getCode();

        if ($status >= 300 && $status < 400) {
            // #6220: a 3xx is not followed, and the configured host may be
            // Mesh or something in front of it (a proxy, a WAF); which one
            // answered is not measured, so the phrase does not say Mesh.
            return "the Mesh host (or something in front of it) answered {$what} with HTTP {$status}";
        }

        if ($status > 0) {
            return "Mesh answered {$what} with HTTP {$status}";
        }

        if ($this->clientDetected && trim($this->getMessage()) !== '') {
            return $this->getMessage();
        }

        if ($this->noStatusRecorded) {
            // #6104: not 'without an HTTP status from Mesh': the client
            // threw before recording one, and Mesh may have answered.
            return "{$what} failed in the PSA's HTTP client with no Mesh status recorded";
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
     * stack trace, whose frames show string arguments when
     * zend.exception_ignore_args is Off (#6051). #6108, #6154, #6155: the
     * Mesh clients' endpoint, tenant id, filter, sender, comment, rule id
     * and patch-field parameters, get()'s $params and both request()
     * methods' $options (API-KEY header, json body, query) are
     * #[\SensitiveParameter], so with ignore_args Off the clients' own
     * frames in getTrace() show a SensitiveParameterValue object for
     * each. Measured per parameter in MeshTraceArgumentRedactionTest,
     * through the public methods that throw a MeshClientException
     * (ruleAbsent() catches it, so its $ruleId is measured in
     * MeshTraceFrameHardeningTest with a handler-thrown RuntimeException,
     * as are both constructors' $config, #6221). The static key helpers
     * (MeshClient::apiKeyRefusal(), headerValueRefusal(), the MeshConfig
     * key predicates) are not marked: they take the key as mixed and
     * contain no throw a test could drive, so a mark there would be
     * unmeasured. MeshUncaughtRenderTest measures (string) $e
     * for MeshClient::get() and getCustomer() at a 1000-byte
     * zend.exception_string_param_max_len. #6214: only the clients' own
     * frames are measured, and only they are covered. Guzzle's frames
     * below request() are not marked; read in the vendored Guzzle, not
     * driven, they take request()'s options array (the API-KEY header and
     * a write's json body). They are not in this exception's trace, which
     * request() builds after Guzzle returned or threw, but a throwable
     * from the HTTP client that request() does not catch (anything but a
     * GuzzleException or an InvalidArgumentException) leaves with them.
     * Frames of callers outside the Mesh clients are not covered either
     * (#6211).
     */
    public function report(): bool
    {
        Log::error('[Mesh] '.static::class.' reported: '.$this->statusPhrase('the request'));

        return true;
    }
}
