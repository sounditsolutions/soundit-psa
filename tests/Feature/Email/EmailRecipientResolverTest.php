<?php

namespace Tests\Feature\Email;

use App\Services\Email\RecipientCandidates;
use App\Services\Email\ResolvedRecipients;
use Tests\TestCase;

class EmailRecipientResolverTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    public function test_candidates_value_object_exposes_union_and_lookups(): void
    {
        $c = new RecipientCandidates(
            contactEmail: 'contact@acme.test',
            contactName: 'Contact Person',
            clientContacts: [['person_id' => 7, 'email' => 'other@acme.test', 'name' => 'Other']],
            threadParticipants: [['email' => 'sender@vendor.test', 'name' => 'Vendor']],
            ourAddresses: ['support@msp.test'],
        );

        $this->assertEqualsCanonicalizing(
            ['contact@acme.test', 'other@acme.test', 'sender@vendor.test'],
            $c->allEmails(),
        );
        $this->assertTrue($c->isThreadParticipant('SENDER@vendor.test'));
        $this->assertFalse($c->isThreadParticipant('contact@acme.test'));
        $this->assertSame('Other', $c->nameFor('other@acme.test'));
        $this->assertSame('other@acme.test', $c->personEmail(7));

        $r = new ResolvedRecipients('to@acme.test', 'To Name', ['cc1@acme.test', 'cc2@acme.test']);
        $this->assertSame('To 1, CC 2', $r->auditDescriptor());
    }

    private function seedThreadTicket(): \App\Models\Ticket
    {
        \App\Models\Setting::setValue('graph_mailbox', 'support@msp.test');
        $client = \App\Models\Client::factory()->create();
        $contact = \App\Models\Person::create([
            'client_id' => $client->id, 'person_type' => \App\Enums\PersonType::User,
            'first_name' => 'Ada', 'last_name' => 'Contact', 'email' => 'ada@acme.test', 'is_active' => true,
        ]);
        // A second, active client contact NOT on the thread (source b, off-thread).
        \App\Models\Person::create([
            'client_id' => $client->id, 'person_type' => \App\Enums\PersonType::User,
            'first_name' => 'Bob', 'last_name' => 'Boss', 'email' => 'bob@acme.test', 'is_active' => true,
        ]);
        $ticket = \App\Models\Ticket::factory()->for($client)->create([
            'contact_id' => $contact->id, 'status' => \App\Enums\TicketStatus::InProgress, 'closed_at' => null,
        ]);
        // Inbound: from Ada, To support (us) + Carl, CC Dana.
        \App\Models\Email::create([
            'graph_id' => 'g1', 'direction' => \App\Enums\EmailDirection::Inbound,
            'from_address' => 'ada@acme.test', 'from_name' => 'Ada',
            'to_recipients' => [['address' => 'support@msp.test', 'name' => 'Support'], ['address' => 'carl@acme.test', 'name' => 'Carl']],
            'cc_recipients' => [['address' => 'Dana@acme.test', 'name' => 'Dana']],
            'subject' => 'Help', 'body_preview' => 'hi', 'body_text' => 'hi', 'body_html' => '<p>hi</p>',
            'has_attachments' => false, 'importance' => 'normal', 'received_at' => now()->subMinutes(5),
            'is_read' => true, 'client_id' => $client->id, 'person_id' => $contact->id, 'ticket_id' => $ticket->id,
        ]);

        return $ticket->fresh('contact');
    }

    public function test_candidates_derive_thread_participants_excluding_our_mailbox(): void
    {
        $ticket = $this->seedThreadTicket();
        $c = app(\App\Services\Email\EmailRecipientResolver::class)->candidates($ticket);

        $this->assertSame('ada@acme.test', $c->contactEmail);
        // Thread participants = ada, carl, dana (lowercased); support@msp.test (us) excluded.
        $tp = array_map(fn ($p) => $p['email'], $c->threadParticipants);
        $this->assertEqualsCanonicalizing(['ada@acme.test', 'carl@acme.test', 'dana@acme.test'], $tp);
        $this->assertNotContains('support@msp.test', $tp);
        // Source b includes bob (off-thread client contact).
        $this->assertContains('bob@acme.test', array_map(fn ($p) => $p['email'], $c->clientContacts));
        $this->assertSame(['support@msp.test'], $c->ourAddresses);
    }

    public function test_reply_all_to_is_original_sender_cc_is_rest_minus_self(): void
    {
        $ticket = $this->seedThreadTicket();
        $r = app(\App\Services\Email\EmailRecipientResolver::class)->replyAll($ticket);

        $this->assertSame('ada@acme.test', $r['to']);            // original inbound sender
        $this->assertEqualsCanonicalizing(['carl@acme.test', 'dana@acme.test'], $r['cc']); // rest − To − self
        $this->assertNotContains('support@msp.test', $r['cc']);
    }
}
