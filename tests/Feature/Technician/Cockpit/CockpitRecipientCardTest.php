<?php

namespace Tests\Feature\Technician\Cockpit;

use App\Enums\EmailDirection;
use App\Enums\PersonType;
use App\Enums\TechnicianRunState;
use App\Enums\TicketStatus;
use App\Models\Client;
use App\Models\Email;
use App\Models\Person;
use App\Models\Setting;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Models\User;
use App\Services\EmailService;
use App\Services\Technician\Cockpit\CockpitRecipientView;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class CockpitRecipientCardTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: TechnicianRun, 1: Ticket} */
    private function seedSendReplyRunWithThread(User $actor): array
    {
        Setting::setValue('triage_system_user_id', (string) $actor->id);
        Setting::setValue('technician_action_tiers', json_encode([]));
        $client = Client::factory()->create();
        $contact = Person::create([
            'client_id' => $client->id, 'person_type' => PersonType::User,
            'first_name' => 'Client', 'last_name' => 'Contact', 'email' => 'client@thread.test', 'is_active' => true,
        ]);
        $ticket = Ticket::factory()->create([
            'client_id' => $client->id, 'contact_id' => $contact->id,
            'status' => TicketStatus::InProgress, 'closed_at' => null,
        ]);
        // graph_mailbox after ticket create so TicketObserver::notifyTicketCreated has no
        // mailbox to send through in tests; we only need it for resolver self-exclusion.
        Setting::setValue('graph_mailbox', 'support@msp.test');
        Email::create([
            'graph_id' => 'thr-1', 'direction' => EmailDirection::Inbound,
            'from_address' => 'client@thread.test', 'from_name' => 'Client',
            'to_recipients' => [['address' => 'support@msp.test', 'name' => 'Support'], ['address' => 'vendor@thread.test', 'name' => 'Vendor']],
            'subject' => 'Re: Issue', 'body_preview' => 'x', 'body_text' => 'x', 'body_html' => '<p>x</p>',
            'has_attachments' => false, 'importance' => 'normal', 'received_at' => now()->subMinutes(3),
            'is_read' => true, 'client_id' => $client->id, 'person_id' => $contact->id, 'ticket_id' => $ticket->id,
        ]);
        $run = TechnicianRun::create([
            'ticket_id' => $ticket->id, 'client_id' => $client->id, 'action_type' => 'send_reply',
            'content_hash' => str_repeat('b', 64), 'state' => TechnicianRunState::AwaitingApproval,
            'proposed_content' => 'Original draft.',
        ]);

        return [$run, $ticket];
    }

    public function test_recipient_view_exposes_resolved_default_replyall_and_candidates(): void
    {
        $actor = User::factory()->create();
        [$run, $ticket] = $this->seedSendReplyRunWithThread($actor);

        $view = app(CockpitRecipientView::class)->for($run);
        $this->assertSame('client@thread.test', $view['to']['email']);
        $this->assertContains('vendor@thread.test', $view['reply_all']);
        $emails = array_column($view['candidates'], 'email');
        $this->assertContains('client@thread.test', $emails);
        $this->assertContains('vendor@thread.test', $emails);

        // null for a non-email action type.
        $closeRun = TechnicianRun::create([
            'ticket_id' => $ticket->id, 'client_id' => $ticket->client_id, 'action_type' => 'propose_close',
            'content_hash' => str_repeat('c', 64), 'state' => TechnicianRunState::AwaitingApproval,
            'proposed_content' => 'Close it.',
        ]);
        $this->assertNull(app(CockpitRecipientView::class)->for($closeRun));
    }

    public function test_cockpit_renders_resolved_recipient_block_for_reply_cards(): void
    {
        $actor = User::factory()->create();
        $this->seedSendReplyRunWithThread($actor);

        $this->actingAs($actor)->get(route('cockpit.index'))->assertOk()
            ->assertSee('client@thread.test')     // resolved default To / contact candidate
            ->assertSee('vendor@thread.test')     // resolvable reply-all / thread candidate
            ->assertSee('Reply all');
    }

    public function test_approving_with_edited_cc_sends_to_that_resolved_set(): void
    {
        $actor = User::factory()->create();
        [$run] = $this->seedSendReplyRunWithThread($actor);

        $captured = null;
        $this->mock(EmailService::class, function (MockInterface $m) use (&$captured) {
            $m->shouldReceive('sendTicketReplyNote')->once()->andReturnUsing(
                function ($t, $n, $to, $cc) use (&$captured) {
                    $captured = [$to, $cc];

                    return null;
                });
        });

        $this->actingAs($actor)->post(route('cockpit.approve', $run), [
            'body' => 'Ok.', 'to' => ['client@thread.test'], 'cc' => ['vendor@thread.test'],
        ])->assertRedirect();

        $this->assertSame(['client@thread.test', ['vendor@thread.test']], $captured);
    }

    public function test_approve_route_rejects_a_tampered_arbitrary_recipient(): void
    {
        // Gate 3 at the route: the server re-validates the posted cc independent of the
        // card JS — a tampered arbitrary address is refused, nothing sends, run untouched.
        $actor = User::factory()->create();
        [$run, $ticket] = $this->seedSendReplyRunWithThread($actor);
        $this->mock(EmailService::class, fn (MockInterface $m) => $m->shouldReceive('sendTicketReplyNote')->never());

        $this->actingAs($actor)->post(route('cockpit.approve', $run), [
            'body' => 'Ok.', 'cc' => ['stranger@evil.test'],
        ])->assertRedirect();

        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->fresh()->state);
        $this->assertSame(0, TicketNote::where('ticket_id', $ticket->id)->where('ai_authored', true)->count());
    }

    // ── psa-w4e0: staged custom To/CC (agent-proposed, knob-gated, exfil-safe readout) ──

    /** @return array{0: TechnicianRun, 1: Ticket} */
    private function seedStagedEmailRunWithCustomProposal(User $actor): array
    {
        [$sendReplyRun, $ticket] = $this->seedSendReplyRunWithThread($actor);
        $sendReplyRun->delete(); // keep only the stage_email card for these tests

        $run = TechnicianRun::create([
            'ticket_id' => $ticket->id, 'client_id' => $ticket->client_id, 'action_type' => 'stage_email',
            'content_hash' => str_repeat('d', 64), 'state' => TechnicianRunState::AwaitingApproval,
            'proposed_content' => 'Audit summary draft.',
            'proposed_meta' => [
                'reasons' => ['Send the audit summary to the external auditor.'],
                'drafted_by' => 'mcp-staff:chet',
                'to' => 'auditor@partner.test',
                'cc' => ['vendor@thread.test'],
                'custom_recipients' => ['auditor@partner.test'],
            ],
        ]);

        return [$run, $ticket];
    }

    public function test_recipient_view_exposes_staged_proposal_candidate_emails_and_policy(): void
    {
        $actor = User::factory()->create();
        [$run] = $this->seedStagedEmailRunWithCustomProposal($actor);

        $view = app(CockpitRecipientView::class)->for($run);
        // The agent's proposed set prefills the card so the approver reviews exactly
        // what was staged — including the outside-known-contacts To.
        $this->assertSame('auditor@partner.test', $view['proposed']['to']);
        $this->assertSame(['vendor@thread.test'], $view['proposed']['cc']);
        // The candidate rows feed the live "outside known contacts" highlight (the
        // Alpine component derives its flat email list from them).
        $candidateEmails = array_column($view['candidates'], 'email');
        $this->assertContains('client@thread.test', $candidateEmails);
        $this->assertNotContains('auditor@partner.test', $candidateEmails);
        $this->assertFalse($view['arbitrary_allowed']);

        Setting::setValue('allow_arbitrary_email_recipients_staged', '1');
        $this->assertTrue(app(CockpitRecipientView::class)->for($run)['arbitrary_allowed']);
    }

    public function test_recipient_view_has_no_proposal_for_send_reply_runs(): void
    {
        $actor = User::factory()->create();
        [$run] = $this->seedSendReplyRunWithThread($actor);

        $view = app(CockpitRecipientView::class)->for($run);
        $this->assertNull($view['proposed']);
    }

    public function test_recipient_view_tolerates_legacy_stage_email_meta_without_recipient_keys(): void
    {
        // Runs staged before psa-w4e0 carry no to/cc meta — the card must fall back
        // to the contact default, never error or fabricate a proposal.
        $actor = User::factory()->create();
        [$run] = $this->seedStagedEmailRunWithCustomProposal($actor);
        $run->update(['proposed_meta' => ['reasons' => ['Old draft.'], 'drafted_by' => 'mcp-staff:chet']]);

        $view = app(CockpitRecipientView::class)->for($run->fresh());
        $this->assertNull($view['proposed']);

        // Malformed meta shapes collapse the same way.
        $run->update(['proposed_meta' => ['to' => ['not-a-string'], 'cc' => 'not-an-array']]);
        $this->assertNull(app(CockpitRecipientView::class)->for($run->fresh())['proposed']);
    }

    public function test_cockpit_arms_the_approve_form_data_for_staged_custom_proposals(): void
    {
        // The approve form's arming is driven client-side from the same live custom
        // computation as the warning (x-effect syncArm) — server-side we can assert
        // the pieces it consumes are all on the page: the component receives the form
        // id and the proposal, and the submit handler honours dataset.arm.
        $actor = User::factory()->create();
        [$run] = $this->seedStagedEmailRunWithCustomProposal($actor);

        $page = $this->actingAs($actor)->get(route('cockpit.index'))->assertOk();
        $page->assertSee('syncArm(customRecipients().length)', false);
        $page->assertSee('cockpitRecipients(', false);
        $page->assertSee('approve-'.$run->id, false);
    }

    public function test_cockpit_renders_staged_custom_recipient_and_warning_copy(): void
    {
        $actor = User::factory()->create();
        $this->seedStagedEmailRunWithCustomProposal($actor);

        $page = $this->actingAs($actor)->get(route('cockpit.index'))->assertOk();
        // The proposed custom address reaches the card data (prefill + highlight)…
        $page->assertSee('auditor@partner.test', false);
        // …and the prominent outside-known-contacts warning block is present.
        $page->assertSee('outside this client', false);
    }

    public function test_approving_staged_email_with_custom_recipients_sends_and_audits_when_knob_on(): void
    {
        Setting::setValue('allow_arbitrary_email_recipients_staged', '1');
        $actor = User::factory()->create();
        [$run, $ticket] = $this->seedStagedEmailRunWithCustomProposal($actor);

        $captured = null;
        $this->mock(EmailService::class, function (MockInterface $m) use (&$captured) {
            $m->shouldReceive('sendTicketReplyNote')->once()->andReturnUsing(
                function ($t, $n, $to, $cc) use (&$captured) {
                    $captured = [$to, $cc];

                    return null;
                });
        });

        $this->actingAs($actor)->post(route('cockpit.approve', $run), [
            'body' => 'Audit summary draft.',
            'to' => ['auditor@partner.test'],
            'cc' => ['vendor@thread.test'],
        ])->assertRedirect();

        $this->assertSame(['auditor@partner.test', ['vendor@thread.test']], $captured);
        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);

        // The executed action log flags the custom recipient by count, never by address.
        $log = \App\Models\TechnicianActionLog::where('ticket_id', $ticket->id)
            ->where('action_type', 'stage_email')->where('result_status', 'executed')->firstOrFail();
        $this->assertStringContainsString('1 outside known contacts', (string) $log->summary);
        $this->assertStringNotContainsString('auditor@partner.test', (string) $log->summary);
    }

    public function test_approving_staged_email_with_custom_recipient_fails_closed_when_knob_off(): void
    {
        // Gate 3: the knob is re-checked at approval — staged-then-disabled proposals
        // (or tampered posts) are refused; the run stays approvable, nothing sends.
        $actor = User::factory()->create();
        [$run, $ticket] = $this->seedStagedEmailRunWithCustomProposal($actor);
        $this->mock(EmailService::class, fn (MockInterface $m) => $m->shouldReceive('sendTicketReplyNote')->never());

        $this->actingAs($actor)->post(route('cockpit.approve', $run), [
            'body' => 'Audit summary draft.',
            'to' => ['auditor@partner.test'],
        ])->assertRedirect();

        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->fresh()->state);
        $this->assertSame(0, TicketNote::where('ticket_id', $ticket->id)->where('ai_authored', true)->count());
    }

    public function test_approving_send_reply_with_custom_cc_honours_the_staged_knob(): void
    {
        // The staged knob governs the shared human-approved path: send_reply approval
        // cards accept an operator-added custom CC under the same policy.
        Setting::setValue('allow_arbitrary_email_recipients_staged', '1');
        $actor = User::factory()->create();
        [$run] = $this->seedSendReplyRunWithThread($actor);

        $captured = null;
        $this->mock(EmailService::class, function (MockInterface $m) use (&$captured) {
            $m->shouldReceive('sendTicketReplyNote')->once()->andReturnUsing(
                function ($t, $n, $to, $cc) use (&$captured) {
                    $captured = [$to, $cc];

                    return null;
                });
        });

        $this->actingAs($actor)->post(route('cockpit.approve', $run), [
            'body' => 'Ok.', 'to' => ['client@thread.test'], 'cc' => ['consultant@partner.test'],
        ])->assertRedirect();

        $this->assertSame(['client@thread.test', ['consultant@partner.test']], $captured);
        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);
    }
}
