<?php

namespace Tests\Feature\Agent\Intake;

use App\Models\Client;
use App\Models\Email;
use App\Models\McpToken;
use App\Models\Setting;
use App\Models\SignalDestination;
use App\Models\SignalEvent;
use App\Models\SignalInboxEntry;
use App\Models\SignalRoute;
use App\Models\SignalRouteStep;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Agent\Intake\EmailTriageWatch;
use App\Services\EmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * psa-28j4.3 (so-4nd9) — STOP auto-email→ticket, but the inbox must never go unwatched.
 *
 * Charlie's ask, in his words:
 *   (a) emails must still LAND in PSA,
 *   (b) no ticket is auto-created, and
 *   (c) CHET IS NOTIFIED, so he reviews and creates-or-attaches.
 *
 * Clause (c) is the one that matters, and it is the easy one to fake. Ship (a)+(b) without
 * it and inbound support email silently piles up unhandled — strictly WORSE than the
 * duplicate tickets he has today, because a duplicate is VISIBLE and an unread email is not.
 *
 * Two disciplines are load-bearing here, and both were learned the hard way:
 *
 *   1. ASSERT THE BEHAVIOUR, NOT THE SHAPE. Emitting a SignalEvent row is NOT notifying
 *      Chet. The row is a notification only if it is ROUTED to an enabled MCP destination
 *      and lands in the inbox he actually polls. The first draft of this suite asserted
 *      "a signal row exists" and passed green against a repo where the signal fired into a
 *      void — 0 routes, 0 destinations, 0 deliveries, 0 inbox rows.
 *
 *   2. ANCHOR AT THE REAL CONFIG. These tests run at Charlie's TARGET config
 *      (email_auto_ticket=0), not a pristine one. A test that passes because the default
 *      state already produces the expected outcome proves nothing.
 */
class EmailIntakeNotifyTest extends TestCase
{
    use RefreshDatabase;

    private function inboundEmail(?Client $client = null): Email
    {
        return Email::create([
            'direction' => 'inbound',
            'from_address' => 'alice@acme.test',
            'to_address' => 'support@msp.test',
            'subject' => 'Printer is offline again',
            'body' => 'The main office printer stopped responding this morning.',
            'client_id' => $client?->id,
            'received_at' => now(),
        ]);
    }

    /** Charlie's target config: PSA-native auto-ticketing OFF. */
    private function autoTicketOff(): void
    {
        Setting::setValue('email_auto_ticket', '0');
    }

    /**
     * Wire the notify path the way an operator would in Settings → Alerts:
     * an enabled route for the intake signal → an enabled MCP destination → a live token.
     */
    private function wireChetsInbox(
        array $eventFilter = ['types' => [EmailTriageWatch::SIGNAL]],
        ?array $tokenTools = ['poll_signals'],
    ): SignalDestination {
        // $tokenTools mirrors McpToken::$tools: null = legacy full-surface token, an array =
        // scoped grant list. poll_signals is the tool Chet uses to drain the signal inbox, so
        // it is what makes this destination genuinely consumable (see the grant regressions).
        McpToken::create(['label' => 'chet', 'token_hash' => 'h1', 'tools' => $tokenTools]);

        $destination = SignalDestination::create([
            'label' => 'Chet inbox',
            'type' => 'mcp',
            'mcp_token_label' => 'chet',
            'enabled' => true,
        ]);

        $route = SignalRoute::create([
            'label' => 'Untriaged support email → Chet',
            'event_filter' => $eventFilter,
            'enabled' => true,
        ]);

        SignalRouteStep::create([
            'route_id' => $route->id,
            'step_order' => 1,
            'destination_id' => $destination->id,
        ]);

        return $destination;
    }

    private function watch(): EmailTriageWatch
    {
        return app(EmailTriageWatch::class);
    }

    // ── Charlie's clauses (a) and (b) ────────────────────────────────────────

    public function test_with_auto_ticket_off_the_email_still_lands_and_is_reachable(): void
    {
        $this->autoTicketOff();
        $email = $this->inboundEmail(Client::factory()->create());

        app(EmailService::class)->processInbound($email);

        $fresh = Email::find($email->id);
        $this->assertNotNull($fresh, 'inbound email must still LAND in PSA');
        $this->assertNull($fresh->dismissed_at, 'a real support email must not be dismissed');
    }

    public function test_with_auto_ticket_off_no_ticket_is_auto_created(): void
    {
        $this->autoTicketOff();
        $email = $this->inboundEmail(Client::factory()->create());

        app(EmailService::class)->processInbound($email);

        $this->assertSame(0, Ticket::count(), 'no ticket may be auto-created when email_auto_ticket is off');
        $this->assertNull(Email::find($email->id)->ticket_id);
    }

    public function test_with_auto_ticket_off_the_email_raises_an_untriaged_signal(): void
    {
        $this->autoTicketOff();
        $email = $this->inboundEmail(Client::factory()->create());

        app(EmailService::class)->processInbound($email);

        $this->assertContains(
            EmailTriageWatch::SIGNAL,
            SignalEvent::where('entity_id', $email->id)->pluck('type_key')->all()
        );
    }

    // ── Clause (c): CHET IS ACTUALLY NOTIFIED — end to end ───────────────────

    public function test_with_the_route_wired_the_untriaged_email_lands_in_chets_mcp_inbox(): void
    {
        $this->autoTicketOff();
        $destination = $this->wireChetsInbox();
        $email = $this->inboundEmail(Client::factory()->create());

        app(EmailService::class)->processInbound($email);

        // The whole chain, for real: emit → route → deliver → McpSink → the inbox Chet polls.
        $entry = SignalInboxEntry::where('destination_id', $destination->id)->first();

        $this->assertNotNull($entry, 'CLAUSE 3: an untriaged inbound email must reach the MCP inbox Chet polls');
        $this->assertSame(EmailTriageWatch::SIGNAL, $entry->payload['event']);
        $this->assertSame(
            $email->id,
            $entry->payload['entity']['id'],
            'the payload must carry the email id — it is the handle Chet needs for get_email_item'
        );
    }

    // ── The guard: an unwatched inbox must be impossible to enter SILENTLY ───

    public function test_an_inbox_with_no_route_at_all_is_a_pile_up_risk(): void
    {
        $this->autoTicketOff();

        $this->assertFalse($this->watch()->isWatchedByAgent());
        $this->assertTrue($this->watch()->isPileUpRisk(), 'auto-ticket off + no route = nobody is watching the inbox');
    }

    public function test_a_correctly_wired_route_is_reported_watched(): void
    {
        $this->autoTicketOff();
        $this->wireChetsInbox();

        $this->assertTrue($this->watch()->isWatchedByAgent());
        $this->assertFalse($this->watch()->isPileUpRisk());
    }

    public function test_auto_ticketing_still_on_is_not_a_pile_up_risk(): void
    {
        Setting::setValue('email_auto_ticket', '1');

        $this->assertFalse($this->watch()->isPileUpRisk(), 'PSA still makes the ticket, so nothing is stranded');
    }

    /**
     * THE LANDMINE TEST — the whole reason the guard asks the real router.
     *
     * The Chet-reactive-pipeline spec's own recommended seed carries min_priority: 2. But
     * intake emissions carry NO priority in context (only client_id), and
     * SignalRouter::matches() hard-fails a null context key. So that route matches ZERO
     * emails while looking perfectly configured in the UI.
     *
     * A naive guard ("is there an enabled route naming this type?") reports WATCHED here and
     * hands the operator a false all-clear on an inbox that is silently dying. The guard must
     * say UNWATCHED.
     */
    public function test_a_route_carrying_min_priority_is_unwatched_because_it_matches_no_intake_email(): void
    {
        $this->autoTicketOff();
        $this->wireChetsInbox([
            'types' => [EmailTriageWatch::SIGNAL],
            'min_priority' => 2,
        ]);

        $this->assertFalse(
            $this->watch()->isWatchedByAgent(),
            'a route filtered on a context key intake emails never carry matches NOTHING — it must not read as watched'
        );
        $this->assertTrue($this->watch()->isPileUpRisk());
    }

    /**
     * Same class of trap from the other end: filtering by client_ids drops exactly the
     * emails that most need triage, because an unresolved email frequently has no client.
     */
    public function test_a_route_filtered_by_client_ids_does_not_watch_a_clientless_email(): void
    {
        $this->autoTicketOff();
        $this->wireChetsInbox([
            'types' => [EmailTriageWatch::SIGNAL],
            'client_ids' => [42],
        ]);

        $this->assertFalse($this->watch()->isWatchedByAgent());
        $this->assertTrue($this->watch()->isPileUpRisk());
    }

    /** A revoked token can never poll again, so its inbox is a black hole. */
    public function test_a_route_whose_mcp_token_is_revoked_is_unwatched(): void
    {
        $this->autoTicketOff();
        $this->wireChetsInbox();
        McpToken::where('label', 'chet')->update(['revoked_at' => now()]);

        $this->assertFalse($this->watch()->isWatchedByAgent(), 'nobody can poll a revoked token — the inbox is a black hole');
        $this->assertTrue($this->watch()->isPileUpRisk());
    }

    /**
     * REGRESSION (psa-28j4.3.2 SECURITY / .3.1 ARCHITECTURE gate): a live, non-revoked token
     * is NOT enough. The staff MCP boundary (McpStaffController::toolAllowed(), bridge branch)
     * only lets a SCOPED token call poll_signals — the exact tool Chet uses to drain the signal
     * inbox. A token not granted poll_signals can never read the queued rows, so the destination
     * is a black hole however live its label is. The watch predicate must prove CONSUMABILITY
     * (can Chet poll it?), not merely deliverability — else it hands back a false all-clear on a
     * dying inbox, the exact failure class this whole guard exists to prevent.
     */
    public function test_a_route_whose_mcp_token_cannot_poll_signals_is_unwatched(): void
    {
        $this->autoTicketOff();
        // Enabled route + enabled MCP destination + ACTIVE, non-revoked token — but its grant
        // list omits poll_signals, so Chet cannot consume the inbox.
        $this->wireChetsInbox(tokenTools: ['find_staff']);

        $this->assertFalse(
            $this->watch()->isWatchedByAgent(),
            'a token that cannot call poll_signals can never drain the inbox — it must not read as watched'
        );
        $this->assertTrue($this->watch()->isPileUpRisk());
    }

    /**
     * The subtle half of the same rule: a LEGACY full-surface token (tools = null) looks
     * maximally privileged but is DENIED the sensitive bridge tools by the same boundary
     * (`allowedTools !== null && allows()`), so it too cannot poll_signals. A naive
     * `allows('poll_signals')` check (null = full surface = allowed) gets this WRONG.
     */
    public function test_a_route_whose_mcp_token_is_legacy_full_surface_is_unwatched(): void
    {
        $this->autoTicketOff();
        $this->wireChetsInbox(tokenTools: null);

        $this->assertFalse(
            $this->watch()->isWatchedByAgent(),
            'a legacy full-surface token is denied poll_signals by the staff MCP boundary — not watched'
        );
        $this->assertTrue($this->watch()->isPileUpRisk());
    }

    /**
     * And the scream must still fire end to end for the poll_signals-less config — the warning
     * the gate required must not vanish just because a route and a live token happen to exist.
     */
    public function test_an_email_whose_only_route_cannot_poll_signals_still_screams(): void
    {
        $this->autoTicketOff();
        $this->wireChetsInbox(tokenTools: ['find_staff']);
        $email = $this->inboundEmail(Client::factory()->create());

        $warnings = $this->warningsDuring(fn () => app(EmailService::class)->processInbound($email));

        $this->assertCount(
            1,
            $this->unwatchedAlarms($warnings),
            'a live token that cannot poll_signals is still an unwatched inbox — it must SCREAM'
        );
    }

    /** A disabled route is not a notify path, however correct its filter looks. */
    public function test_a_disabled_route_is_unwatched(): void
    {
        $this->autoTicketOff();
        $this->wireChetsInbox();
        SignalRoute::query()->update(['enabled' => false]);

        $this->assertFalse($this->watch()->isWatchedByAgent());
    }

    // ── The scream ───────────────────────────────────────────────────────────

    /**
     * Captures real warnings off the log channel.
     *
     * Deliberately NOT Log::spy() + shouldNotHaveReceived('warning', [closure]): a raw closure
     * is not a Mockery argument matcher, so it is compared by identity, never matches, and the
     * negative assertion becomes VACUOUSLY TRUE. The first draft of the cry-wolf test below was
     * written that way and passed happily against a mutant whose alarm fired on every single
     * email. A test that cannot fail is not a test. This listener can fail.
     *
     * @return array<int, string> warning messages logged while $work ran
     */
    private function warningsDuring(callable $work): array
    {
        $warnings = [];
        Log::listen(function ($log) use (&$warnings): void {
            if ($log->level === 'warning') {
                $warnings[] = (string) $log->message;
            }
        });

        $work();

        return $warnings;
    }

    private function unwatchedAlarms(array $warnings): array
    {
        return array_values(array_filter(
            $warnings,
            fn (string $m): bool => str_contains($m, 'UNWATCHED SUPPORT EMAIL')
        ));
    }

    public function test_an_unwatched_support_email_is_logged_loudly(): void
    {
        $this->autoTicketOff();
        $email = $this->inboundEmail(Client::factory()->create());

        $warnings = $this->warningsDuring(fn () => app(EmailService::class)->processInbound($email));

        $this->assertCount(
            1,
            $this->unwatchedAlarms($warnings),
            'an email nobody is watching must SCREAM — a silent support inbox looks exactly like a quiet one'
        );
    }

    public function test_a_watched_support_email_does_not_cry_wolf(): void
    {
        $this->autoTicketOff();
        $this->wireChetsInbox();
        $email = $this->inboundEmail(Client::factory()->create());

        $warnings = $this->warningsDuring(fn () => app(EmailService::class)->processInbound($email));

        $this->assertSame(
            [],
            $this->unwatchedAlarms($warnings),
            'Chet IS being notified here — an alarm that fires anyway trains the operator to ignore it'
        );
    }

    // ── The operator has to SEE it ───────────────────────────────────────────
    //
    // A warning that only reaches laravel.log is invisible to the person actually deciding.
    // The alarm has to appear where the toggle is, at the moment it is being flipped.

    public function test_the_settings_page_warns_when_nobody_is_triaging_inbound_email(): void
    {
        $this->autoTicketOff();

        $this->actingAs(User::factory()->create())
            ->get(route('settings.integrations'))
            ->assertOk()
            ->assertSee('Nobody is triaging inbound email.');
    }

    public function test_the_settings_page_does_not_warn_once_the_notify_path_is_wired(): void
    {
        $this->autoTicketOff();
        $this->wireChetsInbox();

        $this->actingAs(User::factory()->create())
            ->get(route('settings.integrations'))
            ->assertOk()
            ->assertDontSee('Nobody is triaging inbound email.');
    }

    public function test_the_settings_page_does_not_warn_while_auto_ticketing_is_still_on(): void
    {
        Setting::setValue('email_auto_ticket', '1');

        $this->actingAs(User::factory()->create())
            ->get(route('settings.integrations'))
            ->assertOk()
            ->assertDontSee('Nobody is triaging inbound email.');
    }
}
