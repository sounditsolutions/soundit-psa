<?php

namespace Tests\Feature\ContactIntake;

use App\Models\Client;
use App\Models\ContactSubmission;
use App\Models\Email;
use App\Models\Person;
use App\Models\Setting;
use App\Models\SignalDelivery;
use App\Models\SignalDestination;
use App\Models\SignalEvent;
use App\Models\SignalInboxEntry;
use App\Models\SignalRoute;
use App\Models\Ticket;
use App\Models\User;
use App\Services\ContactIntake\IntakeNotifications;
use App\Services\ContactIntake\StaffWorkflow;
use App\Services\ContactIntake\SubmissionLedger;
use App\Services\ContactIntake\SubmissionProcessor;
use App\Services\EmailService;
use App\Services\Signals\Sinks\McpSink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Card JyBDeHPz: contact-intake Alerts Hub signals, plain-English staff alert emails, the
 * processing_failed must-fix and the Settings > General owner picker. Synthetic data only.
 */
class IntakeAlertsTest extends TestCase
{
    use RefreshDatabase;

    /** A unique marker in every visitor field the ledger accepts. */
    private const MARK = 'zqvisitormarkerzq';

    /** @var list<array{0: string, 1: string, 2: string}> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Setting::setValue('contact_intake_enabled', '1');
        Setting::setValue('portal_company_name', 'Northwind MSP');
        $this->mock(EmailService::class, fn ($m) => $m->shouldReceive('sendNew')->andReturnUsing(function (...$args) {
            $this->sent[] = [$args[0], $args[1], $args[2]];

            return new Email;
        }));
    }

    private function accept(string $email = 'zqvisitormarkerzq@example.test', ?string $submissionId = null, string $message = self::MARK.' message', string $company = self::MARK.' Company'): ContactSubmission
    {
        app(SubmissionLedger::class)->accept('website', [
            'submission_id' => $submissionId ?? (string) Str::uuid(), 'name' => self::MARK.' Name',
            'email' => $email, 'message' => $message,
            'phone' => '555-'.self::MARK, 'company' => $company, 'inquiry' => self::MARK,
            'submitted_at' => '2026-10-30T12:00:00Z',
        ]);

        return ContactSubmission::latest('id')->firstOrFail();
    }

    private function owner(): User
    {
        $owner = User::factory()->admin()->create(['is_active' => true, 'name' => 'Synthetic Owner']);
        Setting::setValue('contact_intake_owner_id', (string) $owner->id);

        return $owner;
    }

    private function drainOne(): array
    {
        $this->sent = [];
        app(IntakeNotifications::class)->drain();
        $this->assertCount(1, $this->sent, 'exactly one alert email');

        return $this->sent[0];
    }

    private function assertNoVisitorText(string $haystack, string $where): void
    {
        $this->assertStringNotContainsStringIgnoringCase(self::MARK, $haystack, "visitor text reached {$where}");
    }

    private function review(ContactSubmission $row): string
    {
        return route('contact-intake.show', $row->id);
    }

    public function test_processed_new_ticket_email_is_the_approved_new_ticket_variant(): void
    {
        $this->owner();
        $row = app(SubmissionProcessor::class)->process($this->accept()->id);
        $this->assertNotNull($row->ticket_watermark, 'fixture: this row created its ticket');
        [, $subject, $body] = $this->drainOne();
        $this->assertSame("Web form inquiry to verify: ticket #{$row->ticket_id}", $subject);
        $this->assertSame(implode("\n\n", [
            "A visitor used the Northwind MSP website contact form. Their inquiry is now ticket #{$row->ticket_id}.",
            'The ticket is hidden from AI agents, automation and the client portal until a staff member verifies it.',
            'Next step: open the review page, check that the sender is who they say they are, then choose "Mark this submission verified".',
            'Review it: '.$this->review($row),
            "This email does not include the visitor's message.",
        ]), $body);
        $this->assertNoVisitorText($subject.$body, 'the processed (new ticket) email');
    }

    public function test_processed_follow_up_email_is_the_approved_note_variant(): void
    {
        $this->owner();
        $client = Client::factory()->create();
        $person = Person::create(['client_id' => $client->id, 'first_name' => 'Synthetic', 'email' => 'zqvisitormarkerzq@example.test', 'is_active' => true]);
        $ticket = Ticket::factory()->create(['client_id' => $client->id, 'contact_id' => $person->id, 'assignee_id' => null,
            'status' => \App\Enums\TicketStatus::New]);
        $row = app(SubmissionProcessor::class)->process($this->accept()->id);
        $this->assertSame($ticket->id, $row->ticket_id, 'fixture: a follow-up note on the existing ticket');
        $this->assertNull($row->ticket_watermark);
        [, $subject, $body] = $this->drainOne();
        $this->assertSame("Web form inquiry to verify: ticket #{$ticket->id}", $subject);
        $this->assertSame(implode("\n\n", [
            "A visitor used the Northwind MSP website contact form. Their inquiry was added to ticket #{$ticket->id} as a note.",
            'The note is hidden from AI agents, automation and the client portal until a staff member verifies it.',
            'Next step: open the review page, check that the sender is who they say they are, then choose "Mark this submission verified".',
            'Review it: '.$this->review($row),
            "This email does not include the visitor's message.",
        ]), $body);
        $this->assertNoVisitorText($subject.$body, 'the processed (follow-up) email');
    }

    public function test_quarantined_email_names_the_reason_line(): void
    {
        $this->owner();
        $client = Client::factory()->create();
        foreach (['One', 'Two'] as $first) {
            Person::create(['client_id' => $client->id, 'first_name' => $first, 'email' => 'zqvisitormarkerzq@example.test', 'is_active' => true]);
        }
        $row = app(SubmissionProcessor::class)->process($this->accept()->id);
        $this->assertSame('multiple_email_contacts', $row->exception_reason);
        [, $subject, $body] = $this->drainOne();
        $this->assertSame('Web form inquiry needs a client match', $subject);
        $this->assertSame(implode("\n\n", [
            'A visitor used the Northwind MSP website contact form. Their inquiry could not be matched to a client automatically, so no ticket has been created yet.',
            'Why: two or more contacts share this email address.',
            'Next step: open the review page and resolve it to the right client, approve it as a new prospect, or leave it on hold.',
            'Review it: '.$this->review($row),
            "This email does not include the visitor's message.",
        ]), $body);
        $this->assertNoVisitorText($subject.$body, 'the quarantined email');
    }

    public function test_conflict_email_is_the_approved_text(): void
    {
        $this->owner();
        $id = (string) Str::uuid();
        $this->accept(submissionId: $id);
        $row = $this->accept(submissionId: $id, message: self::MARK.' changed');
        $this->assertSame(1, $row->conflicts);
        [, $subject, $body] = $this->drainOne();
        $this->assertSame('Web form inquiry was resent with different content', $subject);
        $this->assertSame(implode("\n\n", [
            'The Northwind MSP website resent a contact form inquiry, but the new copy differs from the one already received. The first copy is kept and nothing was changed.',
            'Next step: open the review page and check the inquiry and its ticket. If they look right, no action is needed.',
            'Review it: '.$this->review($row),
            "This email does not include the visitor's message.",
        ]), $body);
        $this->assertNoVisitorText($subject.$body, 'the conflict email');
    }

    /** Must-fix 6ae4db12: processing_failed is recorded only by the failure that quarantines. */
    public function test_processing_failed_is_recorded_only_on_the_quarantining_attempt(): void
    {
        $this->owner();
        $row = $this->accept();
        DB::table('contact_submissions')->where('id', $row->id)->update(['payload' => 'not-decryptable']);
        $failed = fn () => DB::table('contact_intake_notifications')->where('contact_submission_id', $row->id)
            ->where('event', 'processing_failed')->count();
        // Literal attempts, not the constant: the control must fail on BEHAVIOUR.
        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $this->artisan('contact-intake:drain');
            $this->assertSame('pending', $row->fresh()->state, "attempt {$attempt} leaves the row pending");
            $this->assertSame(0, $failed(), "attempt {$attempt} records no processing_failed alert");
        }
        $this->sent = [];
        $this->artisan('contact-intake:drain');
        $this->assertSame('quarantined', $row->fresh()->state);
        $this->assertSame(1, $failed(), 'attempt 5 records exactly one processing_failed alert');
        $this->assertCount(1, $this->sent);
        [, $subject, $body] = $this->sent[0];
        $this->assertSame('Web form inquiry could not be processed', $subject);
        $this->assertSame(implode("\n\n", [
            'A Northwind MSP website contact form inquiry failed to process 5 times, so it was moved to the review queue.',
            'Next step: open the review page and choose "Replay" to try again. If it fails again, tell your PSA administrator.',
            'Review it: '.$this->review($row),
            "This email does not include the visitor's message.",
        ]), $body);
        $this->assertNoVisitorText($subject.$body, 'the processing_failed email');
    }

    /** An enabled MCP route subscribed to both web form types, delivering to the 'chet' inbox. */
    private function webformRoute(): SignalDestination
    {
        \App\Support\McpConfig::rotateStaffToken(allowedTools: ['poll_signals'], label: 'chet');
        $destination = SignalDestination::create(['label' => 'Synthetic inbox', 'type' => 'mcp', 'mcp_token_label' => 'chet', 'enabled' => true]);
        $route = SignalRoute::create(['label' => 'Web form', 'enabled' => true, 'cooldown_seconds' => 0,
            'event_filter' => ['types' => ['intake.webform_received', 'intake.webform_verified']]]);
        \App\Models\SignalRouteStep::create(['route_id' => $route->id, 'step_order' => 1, 'destination_id' => $destination->id]);

        return $destination;
    }

    private function assertWebformSignal(string $type, ContactSubmission $row): SignalEvent
    {
        $events = SignalEvent::where('type_key', $type)->get();
        $this->assertCount(1, $events, "exactly one {$type} signal");
        $event = $events->sole();
        $this->assertSame($row->getMorphClass(), $event->entity_type);
        $this->assertSame($row->id, (int) $event->entity_id);
        $this->assertSame(['ticket_id', 'age_seconds', 'review_url'], array_keys($event->context));
        $this->assertSame($row->ticket_id, $event->context['ticket_id']);
        $this->assertIsInt($event->context['age_seconds']);
        $this->assertSame($this->review($row), $event->context['review_url']);
        $this->assertNoVisitorText(json_encode([$event->summary, $event->context]), "the {$type} signal");

        $entry = SignalInboxEntry::where('event_id', $event->id)->sole();
        $this->assertSame(['event', 'entity', 'category', 'occurred_at', 'ticket_id', 'age_seconds', 'review_url'], array_keys($entry->payload));
        $this->assertSame($this->review($row), $entry->payload['review_url']);
        $this->assertNoVisitorText(json_encode($entry->payload), "the {$type} inbox payload");

        return $event;
    }

    public function test_received_signal_fires_for_a_new_ticket_with_only_metadata(): void
    {
        $this->webformRoute();
        $row = app(SubmissionProcessor::class)->process($this->accept()->id);
        $this->assertNotNull($row->ticket_watermark);
        $this->assertWebformSignal('intake.webform_received', $row);
        $this->assertSame(0, SignalEvent::where('type_key', 'intake.webform_verified')->count());
    }

    public function test_received_signal_fires_for_a_follow_up_note_with_only_metadata(): void
    {
        $this->webformRoute();
        $client = Client::factory()->create();
        $person = Person::create(['client_id' => $client->id, 'first_name' => 'Synthetic', 'email' => 'zqvisitormarkerzq@example.test', 'is_active' => true]);
        $ticket = Ticket::factory()->create(['client_id' => $client->id, 'contact_id' => $person->id, 'status' => \App\Enums\TicketStatus::New]);
        $row = app(SubmissionProcessor::class)->process($this->accept()->id);
        $this->assertSame($ticket->id, $row->ticket_id);
        $this->assertNull($row->ticket_watermark);
        $this->assertWebformSignal('intake.webform_received', $row);
    }

    public function test_quarantined_submission_emits_no_received_signal(): void
    {
        $this->webformRoute();
        $client = Client::factory()->create();
        foreach (['One', 'Two'] as $first) {
            Person::create(['client_id' => $client->id, 'first_name' => $first, 'email' => 'zqvisitormarkerzq@example.test', 'is_active' => true]);
        }
        $row = app(SubmissionProcessor::class)->process($this->accept()->id);
        $this->assertSame('quarantined', $row->state);
        $this->assertSame(0, SignalEvent::where('type_key', 'like', 'intake.webform_%')->count());
    }

    public function test_verified_signal_fires_only_once_the_whole_ticket_clears(): void
    {
        $this->webformRoute();
        $staff = User::factory()->tech()->create(['is_active' => true]);
        $processor = app(SubmissionProcessor::class);
        $first = $processor->process($this->accept()->id);
        $second = $processor->process($this->accept()->id);
        $this->assertSame($first->ticket_id, $second->ticket_id, 'fixture: two form notes on one ticket');
        app(StaffWorkflow::class)->act($first->id, $staff, 'verify', 'Synthetic call-back on file number');
        $this->assertSame(0, SignalEvent::where('type_key', 'intake.webform_verified')->count(), 'ticket still held by the second note');
        app(StaffWorkflow::class)->act($second->id, $staff, 'verify', 'Synthetic call-back on file number');
        $this->assertFalse(Ticket::findOrFail($second->ticket_id)->isUnverifiedContactIntake());
        $this->assertWebformSignal('intake.webform_verified', $second->fresh());
    }

    public function test_mcp_sink_adds_webform_keys_for_the_two_webform_types_only(): void
    {
        $destination = SignalDestination::create(['label' => 'Synthetic', 'type' => 'mcp', 'mcp_token_label' => 'synthetic', 'enabled' => true]);
        $route = SignalRoute::create(['label' => 'Synthetic', 'enabled' => true, 'event_filter' => ['types' => 'all']]);
        $context = ['ticket_id' => 7, 'age_seconds' => 30, 'review_url' => 'https://psa.example.test/contact-intake/7'];
        foreach (['intake.webform_received' => true, 'intake.webform_verified' => true, 'ticket.created' => false] as $type => $carries) {
            $event = SignalEvent::create(['type_key' => $type, 'entity_type' => 'synthetic', 'entity_id' => 7,
                'summary' => 'Synthetic', 'context' => $context, 'occurred_at' => now()]);
            $delivery = SignalDelivery::create(['event_id' => $event->id, 'route_id' => $route->id, 'step_order' => 1,
                'destination_id' => $destination->id, 'status' => 'pending']);
            app(McpSink::class)->deliver($destination, $event, $delivery);
            $payload = SignalInboxEntry::where('event_id', $event->id)->sole()->payload;
            foreach (array_keys($context) as $key) {
                $this->assertSame($carries, array_key_exists($key, $payload), "{$type} payload key {$key}");
            }
        }
    }

    private function saveOwner(mixed $id): \Illuminate\Testing\TestResponse
    {
        return $this->post(route('settings.general.update'), ['section' => 'contact_intake', 'contact_intake_owner_id' => $id]);
    }

    public function test_owner_picker_accepts_active_admin_and_tech_and_blank_keeps_the_default(): void
    {
        $chet = User::factory()->tech()->create(['is_active' => true, 'name' => 'Chet']);
        $admin = User::factory()->admin()->create(['is_active' => true]);
        $tech = User::factory()->tech()->create(['is_active' => true]);
        $this->actingAs($admin);
        $this->saveOwner($tech->id)->assertRedirect(route('settings.general'))->assertSessionHasNoErrors();
        $this->assertSame((string) $tech->id, Setting::getValue('contact_intake_owner_id'));
        $this->assertSame($tech->id, \App\Support\ContactIntakeConfig::ownerId());
        $this->saveOwner($admin->id)->assertSessionHasNoErrors();
        $this->assertSame($admin->id, \App\Support\ContactIntakeConfig::ownerId());
        $this->saveOwner('')->assertSessionHasNoErrors();
        $this->assertSame('', Setting::getValue('contact_intake_owner_id'));
        $this->assertSame($chet->id, \App\Support\ContactIntakeConfig::ownerId(), 'blank keeps the default owner');
    }

    public function test_owner_picker_rejects_inactive_and_non_staff_ids(): void
    {
        $admin = User::factory()->admin()->create(['is_active' => true]);
        $tech = User::factory()->tech()->create(['is_active' => true]);
        $this->actingAs($admin);
        $this->saveOwner($tech->id)->assertSessionHasNoErrors();
        $rejected = [
            'inactive tech' => User::factory()->tech()->create(['is_active' => false])->id,
            'inactive admin' => User::factory()->admin()->create(['is_active' => false])->id,
            'billing' => User::factory()->billing()->create(['is_active' => true])->id,
            'contractor' => User::factory()->contractor()->create(['is_active' => true])->id,
            'unknown id' => 999999,
        ];
        foreach ($rejected as $what => $id) {
            $this->saveOwner($id)->assertSessionHasErrors('contact_intake_owner_id');
            $this->assertSame((string) $tech->id, Setting::getValue('contact_intake_owner_id'), "{$what} is refused");
        }
    }

    public function test_settings_card_lists_only_active_admin_and_tech_with_the_approved_help(): void
    {
        $admin = User::factory()->admin()->create(['is_active' => true, 'name' => 'Synthetic Admin']);
        User::factory()->tech()->create(['is_active' => true, 'name' => 'Synthetic Tech']);
        User::factory()->tech()->create(['is_active' => false, 'name' => 'Synthetic Gone']);
        User::factory()->billing()->create(['is_active' => true, 'name' => 'Synthetic Billing']);
        $page = $this->actingAs($admin)->get(route('settings.general'))->assertOk();
        $page->assertSee('Web form alerts go to')->assertSee('Default (the active staff user named Chet)')
            ->assertSee('Synthetic Admin (admin)')->assertSee('Synthetic Tech (tech)')
            ->assertDontSee('Synthetic Gone')->assertDontSee('Synthetic Billing')
            ->assertSee('New web form tickets are assigned to this person. They also get the alert email for any web form inquiry whose ticket has no assignee. Only active admin and tech staff can be chosen.', false)
            ->assertSee('Save Contact Intake');
    }

    public function test_queue_shows_the_guide_and_derived_next_step(): void
    {
        $staff = User::factory()->tech()->create(['is_active' => true]);
        $processor = app(SubmissionProcessor::class);
        $toVerify = $processor->process($this->accept('one@one.example.test', company: self::MARK.' one')->id);
        $verified = $processor->process($this->accept('two@two.example.test', company: self::MARK.' two')->id);
        app(StaffWorkflow::class)->act($verified->id, $staff, 'verify', 'Synthetic call-back on file number');
        $pending = $this->accept('three@three.example.test', company: self::MARK.' three');
        $failed = $this->accept('four@four.example.test', company: self::MARK.' four');
        $failed->update(['state' => 'quarantined', 'exception_reason' => 'processing_failed']);
        $held = $this->accept('five@five.example.test', company: self::MARK.' five');
        $held->update(['state' => 'quarantined', 'exception_reason' => 'staff_hold']);
        $page = $this->actingAs($staff)->get(route('contact-intake.index'))->assertOk();
        $page->assertSee('How this queue works')
            ->assertSee('Every inquiry from the Northwind MSP website contact form arrives here first. Its ticket stays hidden from AI agents, automation and the client portal until a staff member verifies it.')
            ->assertSee('waiting to be processed. This usually happens within a minute; nothing to do.')
            ->assertSee('it needs a staff decision; the reason is on its review page. Open it and resolve it to a client, approve it as a new prospect, replay it, or keep it on hold.')
            ->assertSee('Next step');
        $html = $page->getContent();
        $steps = [];
        foreach ([$toVerify, $verified, $pending, $failed, $held] as $row) {
            $this->assertMatchesRegularExpression('#'.preg_quote($row->receipt, '#').'</a>.*?<td data-next-step>([^<]+)</td>#s', $html);
            preg_match('#'.preg_quote($row->receipt, '#').'</a>.*?<td data-next-step>([^<]+)</td>#s', $html, $m);
            $steps[] = $m[1];
        }
        $this->assertSame(['Verify', 'Verified', 'None (processing)', 'Replay', 'Resolve or approve'], $steps);
        $this->assertNoVisitorText($html, 'the queue page');
    }

    public function test_review_page_shows_the_approved_action_lines(): void
    {
        $staff = User::factory()->tech()->create(['is_active' => true]);
        $row = app(SubmissionProcessor::class)->process($this->accept()->id);
        $this->actingAs($staff)->get(route('contact-intake.show', $row->id))->assertOk()
            ->assertSee('What each action does')
            ->assertSee("Use when you have confirmed the sender is genuine, for example by contacting them through details already on file. Once every web form inquiry on the ticket is verified, the ticket becomes visible to AI agents, automation and the client portal, and its contract is assigned (or you're asked to choose one). Processed inquiries only.", false)
            ->assertSee('The sender is a new organization. A prospect is created when the inquiry is processed again. This does not verify it.')
            ->assertSee('Say what you checked, for example "Called the client back on the number in their record". This is saved in the audit log.', false);
    }

    public function test_alerts_hub_route_form_shows_the_webform_descriptions(): void
    {
        $admin = User::factory()->admin()->create(['is_active' => true]);
        $this->actingAs($admin)->get(route('settings.alerts.routes.create'))->assertOk()
            ->assertSee('Web form inquiry received')
            ->assertSee('Sent when a web form inquiry is added to a ticket (new or existing), before anyone verifies it. Carries only the submission id, ticket id, age and review link.')
            ->assertSee('Web form inquiry verified')
            ->assertSee('Sent when a staff member verifies a web form inquiry and its ticket becomes visible to automation.');
    }

    public function test_poll_signals_returns_the_webform_metadata_keys(): void
    {
        $token = \App\Support\McpConfig::rotateStaffToken(allowedTools: ['poll_signals'], label: 'chet');
        $destination = SignalDestination::create(['label' => 'Synthetic inbox', 'type' => 'mcp', 'mcp_token_label' => 'chet', 'enabled' => true]);
        $row = app(SubmissionProcessor::class)->process($this->accept()->id);
        $event = SignalEvent::create(['type_key' => 'intake.webform_received', 'entity_type' => $row->getMorphClass(), 'entity_id' => $row->id,
            'summary' => 'Web form inquiry received', 'occurred_at' => now(),
            'context' => ['ticket_id' => $row->ticket_id, 'age_seconds' => 5, 'review_url' => $this->review($row)]]);
        $delivery = SignalDelivery::create(['event_id' => $event->id, 'route_id' => null, 'step_order' => 0, 'destination_id' => $destination->id, 'status' => 'pending']);
        app(McpSink::class)->deliver($destination, $event, $delivery);
        $response = $this->withHeaders(['Authorization' => 'Bearer '.$token])->postJson('/api/mcp/staff', ['jsonrpc' => '2.0', 'id' => 1,
            'method' => 'tools/call', 'params' => ['name' => 'poll_signals', 'arguments' => []]])->assertOk();
        $signal = json_decode((string) $response->json('result.content.0.text'), true)['signals'][0];
        $this->assertSame($row->ticket_id, $signal['ticket_id']);
        $this->assertSame(5, $signal['age_seconds']);
        $this->assertSame($this->review($row), $signal['review_url']);
        $this->assertNoVisitorText(json_encode($signal), 'poll_signals');
    }
}
