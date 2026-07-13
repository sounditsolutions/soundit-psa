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

    public function test_resolve_defaults_to_contact_with_no_cc_when_nothing_supplied(): void
    {
        $ticket = $this->seedThreadTicket();
        $r = app(\App\Services\Email\EmailRecipientResolver::class)->resolve(
            $ticket, [], [], \App\Services\Email\RecipientContext::Direct, false, false,
        );
        $this->assertSame('ada@acme.test', $r->to);
        $this->assertSame([], $r->cc);
    }

    public function test_resolve_accepts_thread_participant_cc_on_direct_path(): void
    {
        $ticket = $this->seedThreadTicket();
        $r = app(\App\Services\Email\EmailRecipientResolver::class)->resolve(
            $ticket, [], ['carl@acme.test'], \App\Services\Email\RecipientContext::Direct, false, false,
        );
        $this->assertSame(['carl@acme.test'], $r->cc);
    }

    public function test_resolve_rejects_arbitrary_address_when_knob_off(): void
    {
        $ticket = $this->seedThreadTicket();
        $this->expectException(\App\Services\Email\RecipientValidationException::class);
        app(\App\Services\Email\EmailRecipientResolver::class)->resolve(
            $ticket, [], ['attacker@evil.test'], \App\Services\Email\RecipientContext::Staged, false, false,
        );
    }

    public function test_resolve_allows_arbitrary_address_when_knob_on_but_requires_valid_email(): void
    {
        $ticket = $this->seedThreadTicket();
        $resolver = app(\App\Services\Email\EmailRecipientResolver::class);
        $r = $resolver->resolve($ticket, [], ['outsider@partner.test'], \App\Services\Email\RecipientContext::Staged, true, false);
        $this->assertSame(['outsider@partner.test'], $r->cc);

        $this->expectException(\App\Services\Email\RecipientValidationException::class);
        $resolver->resolve($ticket, [], ['not-an-email'], \App\Services\Email\RecipientContext::Staged, true, false);
    }

    public function test_resolve_direct_refuses_offthread_client_contact_unless_knob_on(): void
    {
        $ticket = $this->seedThreadTicket();
        $resolver = app(\App\Services\Email\EmailRecipientResolver::class);
        // bob@acme.test is a client contact (source b) but NOT on the thread.
        try {
            $resolver->resolve($ticket, [], ['bob@acme.test'], \App\Services\Email\RecipientContext::Direct, false, false);
            $this->fail('expected refusal');
        } catch (\App\Services\Email\RecipientValidationException $e) {
            $this->assertStringContainsString('stage_email', $e->getMessage());
        }
        // Staged path allows it.
        $staged = $resolver->resolve($ticket, [], ['bob@acme.test'], \App\Services\Email\RecipientContext::Staged, false, false);
        $this->assertSame(['bob@acme.test'], $staged->cc);
        // Direct with the new-recipients knob on allows it.
        $direct = $resolver->resolve($ticket, [], ['bob@acme.test'], \App\Services\Email\RecipientContext::Direct, false, true);
        $this->assertSame(['bob@acme.test'], $direct->cc);
    }

    public function test_resolve_dedups_cc_against_to_and_our_mailbox_and_resolves_person_id(): void
    {
        $ticket = $this->seedThreadTicket();
        $bob = \App\Models\Person::where('email', 'bob@acme.test')->firstOrFail();
        $r = app(\App\Services\Email\EmailRecipientResolver::class)->resolve(
            $ticket,
            ['ada@acme.test'],
            ['ada@acme.test', 'support@msp.test', $bob->id],   // To dup, our mailbox, + person_id
            \App\Services\Email\RecipientContext::Staged, false, false,
        );
        $this->assertSame('ada@acme.test', $r->to);
        $this->assertSame(['bob@acme.test'], $r->cc); // ada (==To) and support (ours) dropped; person_id resolved
    }

    private function seedNoThreadTicket(): \App\Models\Ticket
    {
        \App\Models\Setting::setValue('graph_mailbox', 'support@msp.test');
        $client = \App\Models\Client::factory()->create();
        $contact = \App\Models\Person::create([
            'client_id' => $client->id, 'person_type' => \App\Enums\PersonType::User,
            'first_name' => 'Nora', 'last_name' => 'NoThread', 'email' => 'nora@acme.test', 'is_active' => true,
        ]);

        return \App\Models\Ticket::factory()->for($client)->create([
            'contact_id' => $contact->id, 'status' => \App\Enums\TicketStatus::InProgress, 'closed_at' => null,
        ])->fresh('contact');
    }

    public function test_resolve_direct_allows_the_ticket_contact_even_when_not_on_any_thread(): void
    {
        // M3: no inbound email, so the contact is NOT a thread participant. Naming the
        // ticket's own contact explicitly on the direct path must NOT be refused.
        $ticket = $this->seedNoThreadTicket();
        $r = app(\App\Services\Email\EmailRecipientResolver::class)->resolve(
            $ticket, ['nora@acme.test'], [], \App\Services\Email\RecipientContext::Direct, false, false,
        );
        $this->assertSame('nora@acme.test', $r->to);
    }

    public function test_resolve_direct_refuses_arbitrary_offthread_even_when_arbitrary_allowed(): void
    {
        // M4: allow_arbitrary=ON must not bypass the direct off-thread gate. An arbitrary
        // address is off-thread, so the direct path refuses it unless direct_new is ON.
        $ticket = $this->seedThreadTicket();
        $resolver = app(\App\Services\Email\EmailRecipientResolver::class);

        try {
            $resolver->resolve($ticket, [], ['outsider@partner.test'], \App\Services\Email\RecipientContext::Direct, true, false);
            $this->fail('expected off-thread refusal on the direct path');
        } catch (\App\Services\Email\RecipientValidationException $e) {
            $this->assertStringContainsString('stage_email', $e->getMessage());
        }
        // direct_new ON allows it; Staged allows it too.
        $direct = $resolver->resolve($ticket, [], ['outsider@partner.test'], \App\Services\Email\RecipientContext::Direct, true, true);
        $this->assertSame(['outsider@partner.test'], $direct->cc);
        $staged = $resolver->resolve($ticket, [], ['outsider@partner.test'], \App\Services\Email\RecipientContext::Staged, true, false);
        $this->assertSame(['outsider@partner.test'], $staged->cc);
    }

    public function test_resolve_rejects_non_scalar_recipient_ref_cleanly(): void
    {
        // M5: a nested/object element must raise a clean RecipientValidationException,
        // never a TypeError.
        $ticket = $this->seedThreadTicket();
        $this->expectException(\App\Services\Email\RecipientValidationException::class);
        app(\App\Services\Email\EmailRecipientResolver::class)->resolve(
            $ticket, [], [['address' => 'x@evil.test']], \App\Services\Email\RecipientContext::Staged, false, false,
        );
    }

    public function test_resolve_surfaces_custom_recipients_outside_known_sources(): void
    {
        // psa-w4e0: when arbitrary addresses are allowed, the resolved set names which
        // of them sit OUTSIDE sources a/b/c so approval readouts and audits can flag them.
        $ticket = $this->seedThreadTicket();
        $resolver = app(\App\Services\Email\EmailRecipientResolver::class);

        $r = $resolver->resolve(
            $ticket, ['outsider@partner.test'], ['carl@acme.test', 'second@partner.test'],
            \App\Services\Email\RecipientContext::Staged, true, false,
        );
        $this->assertSame('outsider@partner.test', $r->to);
        $this->assertEqualsCanonicalizing(['outsider@partner.test', 'second@partner.test'], $r->custom);
        $this->assertSame('To 1, CC 2, 2 outside known contacts', $r->auditDescriptor());

        // A known-sources-only resolution carries no customs; the descriptor is unchanged.
        $known = $resolver->resolve(
            $ticket, [], ['carl@acme.test'], \App\Services\Email\RecipientContext::Staged, false, false,
        );
        $this->assertSame([], $known->custom);
        $this->assertSame('To 1, CC 1', $known->auditDescriptor());
    }
}
