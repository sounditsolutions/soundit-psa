<?php

namespace Tests\Feature\ContactIntake;

use App\Models\Client;
use App\Models\Person;
use App\Models\Ticket;
use App\Services\ContactIntake\ContactMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class ContactMatcherTest extends TestCase
{
    use RefreshDatabase;

    public function test_complete_matching_order_and_ambiguity(): void
    {
        Bus::fake();
        $matcher = app(ContactMatcher::class);
        $email = 'synthetic@example.test';
        $this->assertSame('new_prospect', $matcher->match($email)['kind']);
        $client = Client::factory()->create(['is_active' => true]);
        $person = Person::create(['first_name' => 'Synthetic', 'last_name' => 'Contact', 'client_id' => $client->id, 'email' => $email, 'is_active' => true]);
        $this->assertSame('existing_client', $matcher->match(strtoupper($email))['kind']);
        $one = Ticket::factory()->create(['client_id' => $client->id, 'contact_id' => $person->id, 'status' => 'new']);
        $this->assertSame('existing_ticket', $matcher->match($email)['kind']);
        $two = Ticket::factory()->create(['client_id' => $client->id, 'contact_id' => $person->id, 'status' => 'new']);
        $matched = $matcher->match($email);
        $this->assertSame('existing_client', $matched['kind']);
        $this->assertEqualsCanonicalizing([$one->id, $two->id], $matched['ticket_ids']);
        $this->assertSame('domain_only', $matcher->match('other@example.test')['reason']);
        Person::create(['first_name' => 'Synthetic', 'last_name' => 'Consumer', 'client_id' => $client->id, 'email' => 'synthetic-existing@gmail.com']);
        $this->assertSame('new_prospect', $matcher->match('synthetic-new@gmail.com')['kind']);
        $person->update(['is_active' => false]);
        $this->assertSame('inactive_identity', $matcher->match($email)['reason']);
        Person::create(['first_name' => 'Synthetic', 'last_name' => 'Other', 'client_id' => Client::factory()->create()->id, 'email' => $email]);
        $this->assertSame('multiple_email_contacts', $matcher->match($email)['reason']);
    }

    public function test_prospect_and_phone_conflict_do_not_create_anything(): void
    {
        Bus::fake();
        $prospect = Client::factory()->prospect()->create(['is_active' => true]);
        $person = Person::create(['first_name' => 'Synthetic', 'last_name' => 'Prospect', 'client_id' => $prospect->id, 'email' => 'prospect@example.test', 'is_active' => true]);
        $matcher = app(ContactMatcher::class);
        $this->assertSame('existing_prospect', $matcher->match($person->email)['kind']);
        Person::create(['first_name' => 'Synthetic', 'last_name' => 'Phone', 'client_id' => $prospect->id, 'phone' => '+12025550123']);
        $this->assertSame('conflicting_phone', $matcher->match($person->email, '+12025550123')['reason']);
        $this->assertDatabaseCount('tickets', 0);
    }
}
