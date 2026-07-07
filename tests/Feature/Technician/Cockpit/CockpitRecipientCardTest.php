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
}
