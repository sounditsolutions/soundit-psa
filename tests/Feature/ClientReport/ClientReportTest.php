<?php

namespace Tests\Feature\ClientReport;

use App\Enums\TicketStatus;
use App\Models\Client;
use App\Models\Email;
use App\Models\Person;
use App\Models\Ticket;
use App\Models\User;
use App\Services\EmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery;
use Tests\TestCase;

class ClientReportTest extends TestCase
{
    use RefreshDatabase;

    private function weekStart(): Carbon
    {
        return Carbon::parse('2026-03-04')->startOfWeek();
    }

    public function test_weekly_report_page_renders_for_staff(): void
    {
        $user = User::factory()->create();
        $client = Client::create(['name' => 'Acme Corp']);
        $start = $this->weekStart();

        Ticket::factory()->create([
            'client_id' => $client->id,
            'subject' => 'Mailbox full',
            'status' => TicketStatus::Resolved->value,
            'opened_at' => $start->copy()->addDay(),
            'resolved_at' => $start->copy()->addDays(2),
        ]);

        $response = $this->actingAs($user)->get(route('clients.weekly-report', [
            'client' => $client,
            'week' => $start->toDateString(),
        ]));

        $response->assertOk();
        $response->assertSee('Weekly Service Report');
        $response->assertSee('Mailbox full');
        $response->assertSee('At a glance');
    }

    public function test_report_is_scoped_to_the_client(): void
    {
        $user = User::factory()->create();
        $client = Client::create(['name' => 'Acme Corp']);
        $other = Client::create(['name' => 'Zzz Other']);
        $start = $this->weekStart();

        Ticket::factory()->create([
            'client_id' => $other->id,
            'subject' => 'SecretOtherTicket',
            'status' => TicketStatus::Resolved->value,
            'opened_at' => $start->copy()->addDay(),
            'resolved_at' => $start->copy()->addDays(2),
        ]);

        $response = $this->actingAs($user)->get(route('clients.weekly-report', [
            'client' => $client,
            'week' => $start->toDateString(),
        ]));

        $response->assertOk();
        $response->assertDontSee('SecretOtherTicket');
    }

    public function test_report_requires_authentication(): void
    {
        $client = Client::create(['name' => 'Acme Corp']);

        $this->get(route('clients.weekly-report', $client))->assertRedirect();
    }

    public function test_email_without_primary_contact_returns_error(): void
    {
        $user = User::factory()->create();
        $client = Client::create(['name' => 'Acme Corp']);

        $response = $this->actingAs($user)->post(route('clients.weekly-report.email', $client), [
            'week' => '2026-03-04',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error');
    }

    public function test_email_sends_report_to_primary_contact(): void
    {
        $user = User::factory()->create();
        $client = Client::create(['name' => 'Acme Corp']);

        Person::create([
            'client_id' => $client->id,
            'first_name' => 'Pat',
            'last_name' => 'Primary',
            'email' => 'pat@example.test',
            'is_primary' => true,
            'is_active' => true,
        ]);

        $mock = Mockery::mock(EmailService::class);
        $mock->shouldReceive('sendNew')
            ->once()
            ->with('pat@example.test', Mockery::type('string'), Mockery::type('string'), 'Pat Primary')
            ->andReturn(new Email);
        $this->app->instance(EmailService::class, $mock);

        $response = $this->actingAs($user)->post(route('clients.weekly-report.email', $client), [
            'week' => '2026-03-04',
        ]);

        $response->assertRedirect(route('clients.weekly-report', [
            'client' => $client,
            'week' => $this->weekStart()->toDateString(),
        ]));
        $response->assertSessionHas('success');
    }

    public function test_inactive_primary_contact_is_not_emailed(): void
    {
        $user = User::factory()->create();
        $client = Client::create(['name' => 'Acme Corp']);

        Person::create([
            'client_id' => $client->id,
            'first_name' => 'Ina',
            'last_name' => 'Ctive',
            'email' => 'ina@example.test',
            'is_primary' => true,
            'is_active' => false,
        ]);

        // EmailService must never be invoked for an inactive contact.
        $mock = Mockery::mock(EmailService::class);
        $mock->shouldNotReceive('sendNew');
        $this->app->instance(EmailService::class, $mock);

        $response = $this->actingAs($user)->post(route('clients.weekly-report.email', $client), [
            'week' => '2026-03-04',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error');
    }
}
