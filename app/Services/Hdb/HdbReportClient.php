<?php

namespace App\Services\Hdb;

use App\Support\HdbApiConfig;
use App\Support\HdbPortalConfig;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Read-only fetch of one HelpDesk Buttons press report (psa #340 / #1359).
 *
 * READS ONLY, and one endpoint family only: `gatekeeper_auth.php` with
 * `getFile=` one of the four whitelisted filenames. `/toggleverify.php` MUTATES
 * vendor state — it marks a report verified — and is never called from this
 * repo; do not add it to {@see FETCHABLE_FILES} or anywhere else.
 *
 * ── FIELD SOURCE, and what it does not prove (STANDARDS C-56(1), C-56(2)) ────
 *
 * HDB is not open source and publishes no OpenAPI spec, so the vendor's
 * producer cannot be read. The best source that exists is the household vault
 * plan `wiki/sound-psa/soundit-dev/plans/`
 * `2026-09-04-hdb-report-integration-build-guide.md`, §5 (endpoints, redirect
 * chain) and §6 (`ticket.json` keys, `report.json`'s 17 top-level sections),
 * written from a live capture of a real press on 2026-09-04 and marked
 * `[VERIFIED]` there. Every field name and every URL shape in this class is
 * cited to that note.
 *
 * 🔴 THAT NOTE IS A SECOND-HAND TRANSCRIPTION, NOT A CAPTURED PAYLOAD, and the
 * fixtures in {@see \Tests\Feature\Hdb\HdbReportClientTest} are therefore
 * SYNTHETIC — invented hostnames, users and addresses, written from the note's
 * key list. C-56(2) asks for fixtures copied from the vendor's real payload and
 * this slice CANNOT supply that: the only real capture contains a client's
 * hostnames, usernames, MACs, local addresses and a desktop screenshot, none of
 * which may enter this repo, and no live call to a third party's portal was
 * authorized for this build. So state the limit plainly rather than dress it up:
 *
 * - What the tests prove: this client's own decisions — the authorization gate,
 *   the upload gate, the redaction gate, the origin policy, the section check,
 *   fail-soft — hold on payloads shaped as the note describes.
 * - What they do NOT prove: that the note transcribed the vendor's key names
 *   and value TYPES correctly. If `uploadComplete` is really a string `"1"`, or
 *   `redactScreenshots` is really `redactScreenshot`, every guard here refuses
 *   rather than mis-imports — that is why each unreadable field fails closed
 *   with its own symbol — but it refuses on EVERY press, which is a loud
 *   failure on first live contact and is the intended shape of being wrong.
 * - What would close the gap: one capture from a real press, scrubbed of client
 *   identity, pinned as a fixture. That is an owner decision, not this
 *   client's.
 *
 * ── DEGRADED READS SCREAM (docs/ARCHITECTURE.md § Vendor response shapes) ────
 *
 * Nothing here ever fails closed into an empty array. A `report.json` that
 * arrives without `eventLog` is not a machine with no event log — it is a bug,
 * and it comes back as {@see HdbReportStatus::Degraded} naming the missing
 * sections, with `importable()` false so no caller can write a false all-clear
 * onto a ticket. A body that is not readable JSON is Malformed, not empty. A
 * missing `uploadComplete` is its own symbol rather than either default.
 *
 * ── FAIL-SOFT ────────────────────────────────────────────────────────────────
 *
 * {@see fetch()} NEVER throws: every failure is a status. The original HDB link
 * note is untouched on every path through this class — nothing here writes to a
 * ticket at all, which is the strongest form of that promise. Writing is the
 * import path's job, and it may only write on `importable()`.
 */
final class HdbReportClient
{
    /**
     * The only filenames the portal serves per press, and the only ones this
     * client will ask for. Vault §5: "Only these filenames are served per
     * press: report.json, ticket.json, screen.png, sprite.png. The viewer
     * whitelists exactly these. [VERIFIED]".
     */
    public const FILE_TICKET = 'ticket.json';

    public const FILE_REPORT = 'report.json';

    public const FILE_SCREENSHOT = 'screen.png';

    public const FILE_SPRITE = 'sprite.png';

    /** @var list<string> */
    public const FETCHABLE_FILES = [
        self::FILE_TICKET,
        self::FILE_REPORT,
        self::FILE_SCREENSHOT,
        self::FILE_SPRITE,
    ];

    /**
     * The `report.json` sections whose ABSENCE is a defect rather than a
     * no-value. Vault §6b lists 17 top-level sections; these are the ones the
     * triage summary in §6c is built from, so a report missing one of them
     * cannot produce the summary a technician would read as complete.
     *
     * The other sections (`hardwareScores`, `reliability`, `stability`,
     * `warranty`, `browser`, `timeDifference`, `mapNet`, `resourceUsage`,
     * `security`) are NOT listed here — not because their absence is fine, but
     * because the note itself records several of them as frequently empty or
     * deprecated (`warranty` is "mostly deprecated", `mapNet` "often empty"),
     * and a required-section list that alarms on every press is a list nobody
     * reads. Absence of those is carried in the payload and left to the import
     * path; absence of one of these six stops the import.
     *
     * @var list<string>
     */
    public const REQUIRED_REPORT_SECTIONS = [
        'info',
        'avStatus',
        'windowsFirewall',
        'hardware',
        'netStatus',
        'eventLog',
        'processList',
        'software',
    ];

    private const CONNECT_TIMEOUT_SECONDS = 5;

    /**
     * Longer than the auth client's 15s: a `report.json` is ~30 KB per the
     * vault note (§6b) but arrives from S3 after two redirect hops, and
     * `sprite.png` is an instant-replay sheet that is materially larger.
     */
    private const TIMEOUT_SECONDS = 30;

    /**
     * `gatekeeper_auth.php` 302s to API Gateway, which 302s to a presigned S3
     * object (vault §5, [VERIFIED]). Two hops is the measured chain; the budget
     * leaves room for the same doubling the login chain showed and still stops
     * a loop.
     */
    private const MAX_REDIRECTS = 6;

    /**
     * A ceiling on a single fetched body, so a vendor-side change cannot turn
     * one ticket import into an out-of-memory. 32 MB is far above the ~30 KB
     * JSON and comfortably above a desktop screenshot; it is a blast-radius
     * bound, not a shape assertion.
     */
    private const MAX_BODY_BYTES = 33_554_432;

    private readonly CookieJar $cookies;

    private readonly HdbAuthClient $auth;

    /**
     * Presses fetched in THIS process, keyed by press id.
     *
     * The idempotency this slice actually delivers, stated precisely rather
     * than generously: a press already fetched by this instance is not fetched
     * again, so a retried job, a double-rendered control or a note carrying the
     * same link twice costs one round trip, not several. Reports are immutable
     * once uploaded (vault §7), so re-serving a completed fetch is sound.
     *
     * What it is NOT: durable, cross-process or cross-request idempotency. That
     * belongs to the import path — a keyed note already on the ticket is the
     * real "already imported" test, and #1359's note key is what it would read.
     * Nothing is written to a cache store here on purpose: these payloads carry
     * a client's endpoint identity, and choosing where that may rest is not a
     * decision to make inside a fetch client.
     *
     * Incomplete and failed fetches are deliberately NOT memoised — an upload
     * that had not finished is precisely the case a retry exists for.
     *
     * @var array<string, HdbReportResult>
     */
    private array $fetched = [];

    /**
     * The one handshake this instance performs, memoised whatever it returned.
     *
     * MEASURED 2026-09-18 while building this slice, and it is the reason this
     * field exists rather than a nicety: {@see HdbAuthClient} counts its
     * requests per INSTANCE against {@see HdbAuthClient::MAX_REQUESTS} = 3 and
     * never resets the counter, so a second `authenticate()` on the same
     * instance spends the budget and returns
     * `request_budget_exhausted` — a healthy portal reported as a redirect
     * loop. A fetch client that signed in per press would have hit that on the
     * SECOND press of any process, and the symptom would have looked like a
     * portal fault rather than a client one.
     *
     * Memoising the FAILURE too is deliberate. A refused credential re-tried is
     * a lockout risk on a service subaccount — the auth client's own stated
     * reason for having no retry — and a fetch loop over several presses must
     * not turn one bad password into N login attempts. A new instance is how a
     * caller asks for a new handshake; that is a per-job decision, not a
     * per-press one.
     *
     * 🔴 WHAT THIS DOES NOT DO: re-establish a session that LAPSES mid-life.
     * The vault note's §4c sketches a re-login-and-retry-once on an auth
     * failure, and this slice does not implement it, because what a lapsed
     * session actually looks like on `gatekeeper_auth.php` — a redirect to
     * /login, a JSON error, a 403 — is item 5 on that note's own
     * confirm-on-build list and has never been observed. Guessing the signature
     * would mean guessing when to spend a credential. Today a lapse surfaces as
     * Malformed or as an unexpected response, both of which refuse the import
     * loudly and neither of which writes anything. Naming it here beats a
     * re-login triggered by a signature nobody has measured.
     */
    private ?HdbAuthResult $session = null;

    /**
     * There is deliberately NO injection seam for the auth client.
     *
     * The whole point of {@see HdbAuthClient}'s jar parameter is that the
     * handshake and every {@see get()} hold the SAME jar. An auth client handed
     * in from outside carries its own jar, so `authenticate()` would succeed,
     * `$session->ok()` would be true, and every gatekeeper request would still
     * go out cookieless — the portal would answer the login page and every
     * press would come back Malformed, which is the signature this class
     * attributes to a LAPSED SESSION. A seam whose two branches disagree about
     * where the session lives sends the investigation at the vendor instead of
     * at the wiring, so the jar is built here and passed to the one handshake
     * this instance performs. Container resolution of this class therefore has
     * exactly one shape, and it is the correct one.
     */
    public function __construct(
        private readonly HdbReportFetchAuthorizer $authorizer = new HdbReportFetchAuthorizer,
    ) {
        $this->cookies = new CookieJar;
        $this->auth = new HdbAuthClient($this->cookies);
    }

    /**
     * Fetch the report for $pressId in the context of the ticket being viewed.
     *
     * ORDER IS THE SECURITY PROPERTY, and it is asserted as such: the
     * authorization gate runs FIRST, before the session is established and
     * before any request is issued. A refused press costs zero round trips, so
     * a caller cannot use this client as an existence oracle for another
     * client's presses, and a refusal cannot be confused with a fetch that
     * failed.
     *
     * $ticketId is the ticket the operator is LOOKING AT. It is a parameter
     * rather than something derived from the press, for the reason
     * {@see HdbReportFetchAuthorizer::authorizeNote()} spells out: deriving it
     * would make the binding tautological and hand back the press's own
     * client's scope — which is the cross-client hole #1359 exists to close.
     *
     * Never throws. Never writes. Never calls `/toggleverify.php`.
     */
    public function fetch(?int $ticketId, ?string $pressId): HdbReportResult
    {
        $authorization = $this->authorizer->authorize($ticketId, $pressId);

        if ($authorization->refused()) {
            return HdbReportResult::refused($authorization->refusal ?? HdbReportFetchRefusal::NoKeyedNote);
        }

        return $this->fetchAfterAuthorization($authorization);
    }

    private ?HdbApiReportClient $api = null;

    private function fetchAfterAuthorization(HdbReportFetchAuthorization $authorization): HdbReportResult
    {
        // The authorizer's normalised id, never the caller's string: the gate
        // decided on the normalised value, so the fetch must spend that one.
        $press = (string) $authorization->pressId;

        if (HdbApiConfig::isConfigured()) {
            $result = ($this->api ??= new HdbApiReportClient)->report($press, (int) $authorization->ticketId);
            if (! $this->shouldFallback($result) || ! HdbPortalConfig::isConfigured()) {
                return $result;
            }
            Log::info('hdb_api_fallback', ['http_status' => $result->httpStatus, 'code' => $result->apiCode]);
        }

        if (isset($this->fetched[$press])) {
            return $this->fetched[$press];
        }

        $session = $this->session ??= $this->auth->authenticate();

        if (! $session->ok()) {
            // The auth REASON rides along so an operator is told which leg
            // failed, and it is a closed-vocabulary symbol from
            // {@see HdbAuthResult} — not portal text.
            return HdbReportResult::unauthenticated($press, $session->reason);
        }

        return $this->fetchAuthorized($press);
    }

    /**
     * The same fetch, offered against one keyed note — the "report" control the
     * ticket view renders. Routed through the authorizer's note entry point so
     * there is one gate, not two that can drift.
     */
    public function fetchForNote(?int $ticketId, ?int $noteId): HdbReportResult
    {
        $authorization = $this->authorizer->authorizeNote($ticketId, $noteId);

        if ($authorization->refused()) {
            return HdbReportResult::refused($authorization->refusal ?? HdbReportFetchRefusal::NoKeyedNote);
        }

        return $this->fetchAfterAuthorization($authorization);
    }

    private function shouldFallback(HdbReportResult $result): bool
    {
        if ($result->redaction !== null || in_array($result->status, [HdbReportStatus::Fetched, HdbReportStatus::Degraded, HdbReportStatus::Incomplete, HdbReportStatus::Refused], true)) {
            return false;
        }

        return match (true) {
            $result->httpStatus === 401 => true,
            $result->httpStatus === 403 => in_array($result->apiCode, ['forbidden', 'api_version_forbidden', 'account_disabled'], true),
            $result->httpStatus === 404 => $result->apiCode === 'report_not_found',
            $result->httpStatus === 422 => $result->apiCode === 'unsupported_report_os',
            $result->httpStatus >= 500 => true,
            $result->httpStatus === null => $result->reason === HdbReportResult::REASON_TRANSPORT_ERROR,
            $result->httpStatus === 200 => $result->reason === HdbReportResult::REASON_UNREADABLE_PAYLOAD,
            default => false,
        };
    }

    /**
     * Everything after the gate and the session: metadata, the two gates it
     * carries, then the diagnostics.
     *
     * `ticket.json` is fetched FIRST and that ordering is load-bearing twice
     * over. It carries `uploadComplete` — so an unfinished press is detected
     * before the 30 KB report is pulled — and it carries the redaction flags,
     * so `screen.png` is never requested for a press whose end user asked that
     * screenshots be withheld. Fetching the screenshot first and discarding it
     * later would satisfy the letter of the flag and break its point: the image
     * would already be in this process's memory.
     */
    private function fetchAuthorized(string $press): HdbReportResult
    {
        $ticketBody = $this->get($press, self::FILE_TICKET);

        if (! is_string($ticketBody)) {
            return $this->transportFailure($press, $ticketBody);
        }

        $ticket = $this->decodeJsonObject($ticketBody);

        if ($ticket === null) {
            return HdbReportResult::malformed($press);
        }

        // GATE 1 — the upload. Vault §6a: "uploadComplete | bool | gate on this
        // — if false, report files may be incomplete; retry later". Read
        // strictly: true proceeds, false retries, ANYTHING ELSE (absent, a
        // string, a number) is a shape this client has not measured and gets
        // its own symbol rather than either default.
        $complete = $ticket['uploadComplete'] ?? null;

        if (! is_bool($complete)) {
            return HdbReportResult::incomplete($press, $ticket, HdbReportResult::REASON_UPLOAD_GATE_MISSING);
        }

        if ($complete === false) {
            return HdbReportResult::incomplete($press, $ticket);
        }

        // GATE 2 — redaction, read BEFORE any diagnostic byte is fetched.
        // Null means the flags were not readable, which is a refusal and never
        // an assumption that redaction was not requested.
        $redaction = HdbReportRedaction::fromTicket($ticket);

        if ($redaction === null) {
            // THE PAYLOAD IS WITHHELD HERE, and only here, which is the one
            // place this class does not preserve what arrived.
            //
            // Everywhere else a refusal still carries its payload, because the
            // payload is the evidence (docs/ARCHITECTURE.md § Vendor response
            // shapes). This case is the exception because the thing that could
            // not be read is the END USER'S OWN INSTRUCTION about what may be
            // shown, and `ticket.json` itself carries endpoint identity —
            // hostname, username, MAC, local address, and the user's own words
            // in `msg` (vault §6a). Handing that to a caller while unable to
            // say whether the user asked for it to be limited would be the
            // redaction gate leaking through its own refusal.
            //
            // The symbol still names the failure exactly, so nothing is
            // silent: an operator learns the metadata was unreadable, and the
            // raw bytes stay in this method.
            return HdbReportResult::incomplete($press, [], HdbReportResult::REASON_REDACTION_FLAG_UNREADABLE);
        }

        $reportBody = $this->get($press, self::FILE_REPORT);

        if (! is_string($reportBody)) {
            return $this->transportFailure($press, $reportBody);
        }

        $report = $this->decodeJsonObject($reportBody);

        if ($report === null) {
            return HdbReportResult::malformed($press);
        }

        $screenshot = $redaction->allowsScreenshots() ? $this->screenshot($press) : null;

        // The section check. A missing section is a BUG, never "no data" —
        // docs/ARCHITECTURE.md § Vendor response shapes, rule 3 — so the
        // payload comes back carried by a status that refuses import and a
        // list that names what was absent.
        $missing = $this->missingSections($report);

        if ($missing !== []) {
            return HdbReportResult::degraded($press, $ticket, $report, $missing, $redaction, $screenshot);
        }

        return $this->fetched[$press] = HdbReportResult::fetched($press, $ticket, $report, $redaction, $screenshot);
    }

    /**
     * Which REQUIRED sections `report.json` did not deliver.
     *
     * A key PRESENT holding an empty array is a genuine no-value and is not
     * reported — the distinction docs/ARCHITECTURE.md draws between "key
     * missing" (drift) and "key present holding null" (a real answer). A key
     * ABSENT is drift, and drift is what screams.
     *
     * @param  array<string, mixed>  $report
     * @return list<string>
     */
    private function missingSections(array $report): array
    {
        $missing = [];

        foreach (self::REQUIRED_REPORT_SECTIONS as $section) {
            if (! array_key_exists($section, $report)) {
                $missing[] = $section;
            }
        }

        return $missing;
    }

    /**
     * The desktop screenshot, or null when it could not be fetched.
     *
     * Called ONLY when the redaction flags permit it — see
     * {@see fetchAuthorized()}. A screenshot that fails to arrive does not fail
     * the report: the diagnostics are the payload a technician triages from,
     * and losing an image is not a reason to withhold them. That is the
     * fail-soft rule applied one level down.
     */
    private function screenshot(string $press): ?string
    {
        $body = $this->get($press, self::FILE_SCREENSHOT);

        return is_string($body) ? $body : null;
    }

    /**
     * Which failure a non-string {@see get()} return means.
     *
     * null is the transport failing — nothing was learned, so Unreachable.
     * false is the portal ANSWERING with something this client will not read:
     * a non-2xx after redirects (a lapsed session shows up here), or a body
     * over the size ceiling. Something WAS learned there, so it is Malformed
     * rather than Unreachable, and it carries its own reason. Neither is ever
     * an empty payload.
     */
    private function transportFailure(string $press, ?bool $outcome): HdbReportResult
    {
        return $outcome === null
            ? HdbReportResult::unreachable($press)
            : HdbReportResult::malformed($press, HdbReportResult::REASON_UNEXPECTED_RESPONSE);
    }

    /**
     * One read of one whitelisted file for one press.
     *
     * URL SHAPE, cited: vault §5 gives
     * `/gatekeeper_auth.php?pressID=<id>&root=uploads&getFile=<file>`, with the
     * query built by {@see http_build_query} rather than concatenated so a
     * press id can never inject a further parameter — belt and braces on top of
     * the authorizer, which has already proved the id is a UUID.
     *
     * `$file` is compared against {@see FETCHABLE_FILES} and the method refuses
     * outright otherwise. That is not defensive decoration: the whole safety of
     * this surface rests on `getFile` naming a report artifact and never a
     * state-changing endpoint, and a whitelist in the one place the parameter
     * is built is what keeps a future caller from passing something else.
     *
     * REDIRECTS ARE FOLLOWED, because the payload only exists at the end of
     * them: `gatekeeper_auth.php` 302s to API Gateway which 302s to a presigned
     * S3 object (vault §5). That is why this method CANNOT reuse the auth
     * client's same-origin redirect pin — the real chain deliberately leaves
     * the portal origin, and pinning it would refuse every report. What IS
     * pinned instead, and it is the meaningful half:
     *
     * - the FIRST request goes to the configured, resolved portal origin, via
     *   the same {@see HdbPortalConfig::baseUrlVerdict()} the credential path
     *   uses — so a repointed Portal URL cannot aim a session at a host the
     *   integration would not post to;
     * - every hop must be https, so the session cookie never rides cleartext;
     * - the hop count is bounded ({@see MAX_REDIRECTS}).
     *
     * The residual is stated rather than hidden, and the thing holding the
     * line is NAMED because it is not this method. An off-origin hop carries
     * whatever Guzzle's cookie jar considers in scope for that host, so a
     * portal that redirected somewhere hostile would see a request arrive. The
     * portal's session cookie does NOT go with it: `CookieJar::withCookieHeader`
     * (vendor/guzzlehttp/guzzle/src/Cookie/CookieJar.php:301, read at this tip)
     * emits a cookie only when `matchesDomain($host)` holds for the request's
     * own host, and only over https when the cookie is Secure.
     *
     * That is the JAR's behaviour, not a check this method makes. A reader
     * should know the difference: if the redirect policy here were ever loosened
     * to allow http, or the jar swapped for one that does not scope by domain,
     * this method would not notice.
     *
     * @return string|false|null body; false = the portal answered unusably;
     *                           null = transport failed, nothing was learned
     */
    private function get(string $press, string $file): string|false|null
    {
        if (! in_array($file, self::FETCHABLE_FILES, true)) {
            return false;
        }

        if (HdbPortalConfig::baseUrlVerdict() !== HdbPortalConfig::BASE_URL_POSTABLE) {
            return null;
        }

        $url = HdbPortalConfig::baseUrl().'/gatekeeper_auth.php?'.http_build_query([
            'pressID' => $press,
            'root' => 'uploads',
            'getFile' => $file,
        ]);

        try {
            $response = Http::connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
                ->timeout(self::TIMEOUT_SECONDS)
                ->withOptions([
                    'cookies' => $this->cookies,
                    'allow_redirects' => [
                        'max' => self::MAX_REDIRECTS,
                        'strict' => false,
                        'referer' => true,
                        'protocols' => ['https'],
                    ],
                    'http_errors' => false,
                ])
                ->get($url);
        } catch (\Throwable) {
            // The exception never reaches the operator: a Guzzle message quotes
            // the URL, and a followed URL here is a PRESIGNED S3 link carrying
            // a signature and a security token. Only the fact of failure
            // escapes — the same rule {@see HdbAuthClient} applies, for a
            // sharper reason.
            return null;
        }

        if (! $response->successful()) {
            return false;
        }

        $body = $response->body();

        // Size ceiling, checked on what actually arrived.
        return strlen($body) > self::MAX_BODY_BYTES ? false : $body;
    }

    /**
     * Decode a body that must be a JSON OBJECT, or null.
     *
     * Null for anything that is not one — invalid JSON, a bare scalar, a JSON
     * `null`, or a top-level array. The caller turns that into Malformed, never
     * into an empty payload: a portal that answered with a login page instead
     * of JSON has told us the session lapsed, and reading that as "the report
     * is empty" is precisely the false all-clear C-56(3) forbids.
     *
     * An empty object `{}` decodes to an empty PHP array, which is
     * indistinguishable from an empty JSON list and is refused here too. That
     * is the correct outcome either way: metadata with no keys cannot answer
     * the upload or redaction gates, and diagnostics with no sections are not
     * a report.
     *
     * @return array<string, mixed>|null
     */
    private function decodeJsonObject(string $body): ?array
    {
        $decoded = json_decode($body, true, 512, JSON_INVALID_UTF8_SUBSTITUTE);

        if (! is_array($decoded) || $decoded === [] || array_is_list($decoded)) {
            return null;
        }

        return $decoded;
    }
}
