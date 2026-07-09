<?php

namespace Tests\Feature\Portal;

use App\Enums\PersonType;
use App\Enums\TicketStatus;
use App\Models\Client;
use App\Models\Person;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * Confidential tickets (psa-v9w).
 *
 * A confidential ticket must be visible in the client portal ONLY to the
 * specific contact it is assigned to — even when a coworker at the same
 * company holds company-wide portal access. The canonical use case: a
 * business owner opening a ticket about terminating an employee. A manager
 * with company-wide access must never see it; the owner always sees their own.
 *
 * These tests exercise every portal surface that lists or exposes tickets
 * (index, detail, dashboard, attachment upload) plus the staff toggle and
 * the staff-side visual indicator.
 */
class ConfidentialTicketTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private Person $owner;    // company-wide; owns the confidential ticket

    private Person $manager;  // company-wide coworker who must be blocked

    private Person $employee; // regular contact, no company-wide access

    private Ticket $confidential;   // owner's confidential ticket

    private Ticket $normalOfOwner;  // owner's ordinary ticket (control)

    private Ticket $employeeTicket; // employee's ordinary ticket (control)

    protected function setUp(): void
    {
        parent::setUp();

        // Ticket/Person creation fires observers that dispatch queued work.
        Bus::fake();
        // Public portal routes are gated by the portal_enabled setting.
        Setting::setValue('portal_enabled', '1');

        $this->client = Client::factory()->create(); // stage defaults to Active

        $this->owner = $this->makeContact('owner@example.test', companyWide: true);
        $this->manager = $this->makeContact('manager@example.test', companyWide: true);
        $this->employee = $this->makeContact('employee@example.test', companyWide: false);

        $this->confidential = $this->makeTicket($this->owner, 'ZZTOKEN-CONFIDENTIAL-HR', confidential: true);
        $this->normalOfOwner = $this->makeTicket($this->owner, 'ZZTOKEN-OWNER-PRINTER', confidential: false);
        $this->employeeTicket = $this->makeTicket($this->employee, 'ZZTOKEN-EMPLOYEE-VPN', confidential: false);
    }

    private function makeContact(string $email, bool $companyWide): Person
    {
        return Person::create([
            'client_id' => $this->client->id,
            'person_type' => PersonType::User, // canHavePortal() === true
            'first_name' => 'Test',
            'last_name' => ucfirst(explode('@', $email)[0]),
            'email' => $email,
            'is_active' => true,
            'portal_enabled' => true,
            'company_wide_access' => $companyWide,
        ]);
    }

    private function makeTicket(Person $contact, string $subject, bool $confidential): Ticket
    {
        return Ticket::factory()->create([
            'client_id' => $this->client->id,
            'contact_id' => $contact->id,
            'subject' => $subject,
            'confidential' => $confidential,
            'status' => TicketStatus::New,
        ]);
    }

    // ── Portal ticket list ──────────────────────────────────────────────────

    public function test_company_wide_coworker_does_not_see_owners_confidential_ticket_in_list(): void
    {
        $this->actingAs($this->manager, 'portal')
            ->get(route('portal.tickets.index', ['tab' => 'all']))
            ->assertOk()
            ->assertDontSee('ZZTOKEN-CONFIDENTIAL-HR') // blocked
            ->assertSee('ZZTOKEN-OWNER-PRINTER')       // normal ticket still visible
            ->assertSee('ZZTOKEN-EMPLOYEE-VPN');       // company-wide sees all non-confidential
    }

    public function test_assigned_owner_sees_their_own_confidential_ticket_in_list(): void
    {
        $this->actingAs($this->owner, 'portal')
            ->get(route('portal.tickets.index', ['tab' => 'all']))
            ->assertOk()
            ->assertSee('ZZTOKEN-CONFIDENTIAL-HR')
            ->assertSee('ZZTOKEN-OWNER-PRINTER');
    }

    public function test_regular_contact_sees_only_their_own_tickets(): void
    {
        $this->actingAs($this->employee, 'portal')
            ->get(route('portal.tickets.index', ['tab' => 'all']))
            ->assertOk()
            ->assertSee('ZZTOKEN-EMPLOYEE-VPN')
            ->assertDontSee('ZZTOKEN-CONFIDENTIAL-HR')
            ->assertDontSee('ZZTOKEN-OWNER-PRINTER');
    }

    // ── Portal ticket detail ────────────────────────────────────────────────

    public function test_company_wide_coworker_cannot_open_confidential_ticket_detail(): void
    {
        $this->actingAs($this->manager, 'portal')
            ->get(route('portal.tickets.show', $this->confidential))
            ->assertForbidden();
    }

    public function test_company_wide_coworker_can_open_a_normal_ticket_detail(): void
    {
        $this->actingAs($this->manager, 'portal')
            ->get(route('portal.tickets.show', $this->normalOfOwner))
            ->assertOk();
    }

    public function test_assigned_owner_can_open_their_confidential_ticket_detail(): void
    {
        $this->actingAs($this->owner, 'portal')
            ->get(route('portal.tickets.show', $this->confidential))
            ->assertOk();
    }

    public function test_regular_contact_cannot_open_confidential_ticket_detail(): void
    {
        $this->actingAs($this->employee, 'portal')
            ->get(route('portal.tickets.show', $this->confidential))
            ->assertForbidden();
    }

    // ── Portal dashboard ────────────────────────────────────────────────────

    public function test_dashboard_hides_confidential_ticket_from_company_wide_coworker(): void
    {
        $this->actingAs($this->manager, 'portal')
            ->get(route('portal.dashboard'))
            ->assertOk()
            ->assertDontSee('ZZTOKEN-CONFIDENTIAL-HR')
            ->assertSee('ZZTOKEN-OWNER-PRINTER');
    }

    // ── Portal attachment upload ────────────────────────────────────────────

    public function test_company_wide_coworker_cannot_attach_to_confidential_ticket(): void
    {
        // The visibility gate runs before file validation, so an empty POST
        // still returns 403 for a ticket the contact may not see.
        $this->actingAs($this->manager, 'portal')
            ->post(route('portal.tickets.attachments.store', $this->confidential), [])
            ->assertForbidden();
    }

    // ── Staff toggle ────────────────────────────────────────────────────────

    public function test_staff_can_mark_a_ticket_confidential(): void
    {
        $staff = User::factory()->create();

        $this->actingAs($staff)
            ->patch(route('tickets.update', $this->normalOfOwner), ['confidential' => 1])
            ->assertRedirect(route('tickets.show', $this->normalOfOwner));

        $this->assertTrue($this->normalOfOwner->fresh()->confidential);
    }

    public function test_staff_can_clear_the_confidential_flag(): void
    {
        $staff = User::factory()->create();

        $this->actingAs($staff)
            ->patch(route('tickets.update', $this->confidential), ['confidential' => 0])
            ->assertRedirect(route('tickets.show', $this->confidential));

        $this->assertFalse($this->confidential->fresh()->confidential);
    }

    public function test_updating_an_unrelated_field_does_not_change_confidentiality(): void
    {
        $staff = User::factory()->create();

        // A form that submits only the assignee must leave confidential intact.
        $this->actingAs($staff)
            ->patch(route('tickets.update', $this->confidential), ['assignee_id' => $staff->id]);

        $this->assertTrue($this->confidential->fresh()->confidential);
    }

    // ── Staff visual indicator ──────────────────────────────────────────────

    public function test_staff_ticket_detail_shows_confidential_badge(): void
    {
        $staff = User::factory()->create();

        // The header badge's tooltip is unique to the confidential state.
        $this->actingAs($staff)
            ->get(route('tickets.show', $this->confidential))
            ->assertOk()
            ->assertSee('only the assigned contact can see this ticket');

        $this->actingAs($staff)
            ->get(route('tickets.show', $this->normalOfOwner))
            ->assertOk()
            ->assertDontSee('only the assigned contact can see this ticket');
    }
}
