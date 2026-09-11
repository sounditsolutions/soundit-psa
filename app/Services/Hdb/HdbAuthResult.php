<?php

namespace App\Services\Hdb;

/**
 * The status-only outcome of one HDB portal login attempt.
 *
 * A status alone cannot tell an operator whether to re-enter the password or
 * re-enrol two-factor, so a `reason` rides along — but it is drawn from the
 * closed list below, never from the portal. The pair (status, reason) is the
 * ENTIRE observable output of the handshake: no status line, no response body,
 * no redirect target, no exception message.
 */
final readonly class HdbAuthResult
{
    /** Login succeeded, including the second factor if one was demanded. */
    public const REASON_OK = 'ok';

    /** No email or no password stored — nothing was sent anywhere. */
    public const REASON_MISSING_CREDENTIALS = 'missing_credentials';

    /** The portal re-served its login form: email/password pair refused. */
    public const REASON_CREDENTIALS_REJECTED = 'credentials_rejected';

    /** Password accepted, a second factor was demanded, no seed is stored. */
    public const REASON_TOTP_REQUIRED_NO_SEED = 'totp_required_no_seed';

    /** A seed is stored but it is not decodable base32. */
    public const REASON_TOTP_SEED_UNUSABLE = 'totp_seed_unusable';

    /**
     * A second factor was demanded and the page did not carry a field this
     * client recognises. Distinct from a rejected code on purpose — see the
     * challenge-shape caveat on {@see HdbAuthClient}.
     */
    public const REASON_TOTP_CHALLENGE_UNRECOGNISED = 'totp_challenge_unrecognised';

    /** The generated code was submitted and refused. */
    public const REASON_TOTP_REJECTED = 'totp_rejected';

    /** DNS, TLS, connect or read timeout — nothing was learned about the credentials. */
    public const REASON_TRANSPORT_ERROR = 'transport_error';

    /** The portal answered, but not with anything this client can classify. */
    public const REASON_UNEXPECTED_RESPONSE = 'unexpected_response';

    /** The attempt hit its own request ceiling, most likely a redirect loop. */
    public const REASON_REQUEST_BUDGET_EXHAUSTED = 'request_budget_exhausted';

    /**
     * The stored Portal URL is not a destination this integration will post a
     * credential to — not https, or a host that is an IP literal, a bare name or
     * a reserved internal suffix. Nothing was sent anywhere.
     */
    public const REASON_PORTAL_URL_REFUSED = 'portal_url_refused';

    public function __construct(
        public HdbAuthStatus $status,
        public string $reason,
        /** HTTP requests actually issued, for the audit row and the budget tests. */
        public int $requests = 0,
    ) {}

    public function ok(): bool
    {
        return $this->status === HdbAuthStatus::Authenticated;
    }

    /**
     * The operator-facing sentence for this outcome.
     *
     * Fixed strings selected by symbol. The integrations page renders a test
     * result through `innerHTML`, so this method existing — rather than the
     * caller interpolating anything — is what keeps vendor text out of the DOM.
     */
    public function message(): string
    {
        return match ($this->reason) {
            self::REASON_OK => 'Signed in to the HDB report portal successfully.',
            self::REASON_MISSING_CREDENTIALS => 'Enter the service subaccount email and password first, then save.',
            self::REASON_CREDENTIALS_REJECTED => 'The portal refused the service subaccount email and password.',
            self::REASON_TOTP_REQUIRED_NO_SEED => 'The password was accepted but the portal asked for a two-factor code, and no seed is stored. Paste the enrollment seed into the Two-Factor Seed field.',
            self::REASON_TOTP_SEED_UNUSABLE => 'The stored two-factor seed is not valid base32 — re-enrol two-factor and paste the seed exactly as shown.',
            self::REASON_TOTP_CHALLENGE_UNRECOGNISED => 'The password was accepted but the two-factor prompt was not in a form this integration recognises. The portal may have changed; nothing was retried.',
            self::REASON_TOTP_REJECTED => 'The generated two-factor code was refused. The stored seed is probably from a superseded enrollment.',
            self::REASON_TRANSPORT_ERROR => 'Could not reach the HDB portal. Check the Portal URL and outbound network access.',
            self::REASON_UNEXPECTED_RESPONSE => 'The HDB portal answered with something this integration could not classify. Nothing was retried.',
            self::REASON_REQUEST_BUDGET_EXHAUSTED => 'The HDB portal kept redirecting and the attempt was stopped at its request limit.',
            self::REASON_PORTAL_URL_REFUSED => 'The stored Portal URL is not an https:// address with a public hostname, so nothing was sent. Fix the Portal URL, or clear it to use the default portal host.',
            default => 'The HDB portal login attempt did not complete.',
        };
    }
}
