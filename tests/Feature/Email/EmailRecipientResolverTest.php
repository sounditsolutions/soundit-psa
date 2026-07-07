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
}
