<?php

namespace Tests\Feature;

use App\Enums\PersonType;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Enums\TicketType;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Email;
use App\Models\Invoice;
use App\Models\License;
use App\Models\LicenseType;
use App\Models\Person;
use App\Models\PhoneCall;
use App\Models\Ticket;
use App\Models\User;
use App\Services\ClientService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ClientMergeServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Ticket/Person creation fires observers that dispatch queued work — contain it.
        Bus::fake();
    }

    private function service(): ClientService
    {
        return app(ClientService::class);
    }

    private function client(string $name = 'Acme', array $overrides = []): Client
    {
        return Client::create(array_merge(['name' => $name], $overrides));
    }

    private function person(Client $c, array $overrides = []): Person
    {
        return Person::create(array_merge([
            'client_id' => $c->id,
            'person_type' => PersonType::User,
            'first_name' => 'Test',
            'last_name' => 'Person',
            'is_active' => true,
        ], $overrides));
    }

    private function ticket(Client $c, array $overrides = []): Ticket
    {
        return Ticket::create(array_merge([
            'client_id' => $c->id,
            'subject' => 'Help',
            'type' => TicketType::ServiceRequest,
            'status' => TicketStatus::New,
            'priority' => TicketPriority::P3,
            'opened_at' => now(),
        ], $overrides));
    }

    private function asset(Client $c, string $name = 'PC'): Asset
    {
        return Asset::create(['client_id' => $c->id, 'name' => $name]);
    }

    private function phoneCall(Client $c, string $uuid): PhoneCall
    {
        // client_id is safest set directly (mirrors PhoneCall handling elsewhere).
        $call = new PhoneCall(['call_uuid' => $uuid, 'from_number' => '+15555550100']);
        $call->client_id = $c->id;
        $call->save();

        return $call;
    }

    private function email(Client $c, string $graphId): Email
    {
        return Email::create([
            'graph_id' => $graphId,
            'from_address' => 'sender@ext.test',
            'subject' => 'Hi',
            'received_at' => now(),
            'client_id' => $c->id,
        ]);
    }

    private function contract(Client $c, string $name = 'MSA'): Contract
    {
        return Contract::create(['client_id' => $c->id, 'name' => $name, 'type' => 'managed', 'start_date' => '2026-01-01']);
    }

    private function invoice(Client $c, string $number): Invoice
    {
        return Invoice::create([
            'client_id' => $c->id,
            'invoice_number' => $number,
            'invoice_date' => '2026-01-01',
            'due_date' => '2026-01-31',
            'status' => 'posted',
        ]);
    }

    private function licenseType(string $vendor = 'cipp_m365', string $name = 'M365 E3'): LicenseType
    {
        return LicenseType::create(['name' => $name, 'vendor' => $vendor]);
    }

    private function license(Client $c, LicenseType $lt, ?string $vendorRef, int $qty = 5): License
    {
        return License::create([
            'license_type_id' => $lt->id,
            'client_id' => $c->id,
            'quantity' => $qty,
            'vendor_ref' => $vendorRef,
            'status' => 'active',
        ]);
    }

    private function staffUser(): User
    {
        return User::factory()->create();
    }

    public function test_repoints_core_entities_to_survivor(): void
    {
        $survivor = $this->client('Keep Co');
        $dup = $this->client('Dupe Co');

        $ticket = $this->ticket($dup);
        $asset = $this->asset($dup);
        $person = $this->person($dup);
        $call = $this->phoneCall($dup, 'uuid-1');
        $email = $this->email($dup, 'g-1');
        $contract = $this->contract($dup);
        $invoice = $this->invoice($dup, 'INV-1');

        $summary = $this->service()->mergeClients($survivor, $dup, $this->staffUser()->id);

        $this->assertSame($survivor->id, $ticket->fresh()->client_id);
        $this->assertSame($survivor->id, $asset->fresh()->client_id);
        $this->assertSame($survivor->id, $person->fresh()->client_id);
        $this->assertSame($survivor->id, $call->fresh()->client_id);
        $this->assertSame($survivor->id, $email->fresh()->client_id);
        $this->assertSame($survivor->id, $contract->fresh()->client_id);
        $this->assertSame($survivor->id, $invoice->fresh()->client_id);

        $this->assertSame(1, $summary['tickets']);
        $this->assertSame(1, $summary['assets']);
        $this->assertSame(1, $summary['people']);
        $this->assertSame(1, $summary['calls']);
        $this->assertSame(1, $summary['emails']);
        $this->assertSame(1, $summary['contracts']);
        $this->assertSame(1, $summary['invoices']);

        $this->assertSoftDeleted('clients', ['id' => $dup->id]);
        $this->assertNotNull(Client::find($survivor->id));
    }

    public function test_moves_licenses_and_drops_colliding_duplicates(): void
    {
        $survivor = $this->client('Keep Co');
        $dup = $this->client('Dupe Co');
        $lt = $this->licenseType();

        // Survivor already holds this exact vendor record; the duplicate's copy is redundant.
        $this->license($survivor, $lt, 'vendor-ref-A', 3);
        $collidingDup = $this->license($dup, $lt, 'vendor-ref-A', 7);
        // Distinct vendor_ref → moves cleanly.
        $movableDup = $this->license($dup, $lt, 'vendor-ref-B', 2);

        $summary = $this->service()->mergeClients($survivor, $dup, $this->staffUser()->id);

        // Colliding row dropped, distinct row moved.
        $this->assertNull(License::find($collidingDup->id));
        $this->assertSame($survivor->id, $movableDup->fresh()->client_id);
        $this->assertSame(1, $summary['licenses']);
        // Survivor still has exactly one row for the colliding ref (not duplicated).
        $this->assertSame(1, License::where('client_id', $survivor->id)->where('vendor_ref', 'vendor-ref-A')->count());
        $this->assertSame(0, License::where('client_id', $dup->id)->count());
    }

    public function test_moves_null_vendor_ref_license_even_with_matching_type(): void
    {
        // NULLs are distinct in the (type, client, vendor_ref) unique index, so a
        // NULL-vendor_ref license never collides and always moves.
        $survivor = $this->client('Keep Co');
        $dup = $this->client('Dupe Co');
        $lt = $this->licenseType();
        $this->license($survivor, $lt, null, 4);
        $dupLicense = $this->license($dup, $lt, null, 9);

        $summary = $this->service()->mergeClients($survivor, $dup, $this->staffUser()->id);

        $this->assertSame($survivor->id, $dupLicense->fresh()->client_id);
        $this->assertSame(1, $summary['licenses']);
        $this->assertSame(2, License::where('client_id', $survivor->id)->count());
    }

    public function test_reparents_reseller_children_to_survivor(): void
    {
        $survivor = $this->client('Reseller Keep');
        $dup = $this->client('Reseller Dupe');
        $childA = $this->client('Child A', ['reseller_id' => $dup->id]);
        $childB = $this->client('Child B', ['reseller_id' => $dup->id]);

        $summary = $this->service()->mergeClients($survivor, $dup, $this->staffUser()->id);

        $this->assertSame($survivor->id, $childA->fresh()->reseller_id);
        $this->assertSame($survivor->id, $childB->fresh()->reseller_id);
        $this->assertSame(2, $summary['reseller_children']);
    }

    public function test_nulls_reseller_id_when_survivor_was_resold_by_duplicate(): void
    {
        // The duplicate is the survivor's reseller. After the merge the survivor
        // must not end up as its own reseller.
        $dup = $this->client('Reseller Dupe');
        $survivor = $this->client('Kept Child', ['reseller_id' => $dup->id]);

        $this->service()->mergeClients($survivor, $dup, $this->staffUser()->id);

        $this->assertNull($survivor->fresh()->reseller_id);
    }

    public function test_fills_only_blank_profile_fields(): void
    {
        $survivor = $this->client('Keep Co', ['website' => 'keep.example', 'city' => null]);
        $dup = $this->client('Dupe Co', ['website' => 'dupe.example', 'city' => 'Portland']);

        $this->service()->mergeClients($survivor, $dup, $this->staffUser()->id);

        $survivor->refresh();
        $this->assertSame('keep.example', $survivor->website); // existing value not overwritten
        $this->assertSame('Portland', $survivor->city);        // blank field filled from duplicate
    }

    public function test_writes_audit_notes_on_both_and_soft_deletes_duplicate(): void
    {
        $survivor = $this->client('Keep Co');
        $dup = $this->client('Dupe Co');
        $this->ticket($dup);
        $user = User::factory()->create(['name' => 'Casey Tech']);

        $this->service()->mergeClients($survivor, $dup, $user->id);

        $this->assertStringContainsString("Merged client 'Dupe Co'", (string) $survivor->fresh()->notes);
        $this->assertStringContainsString('Casey Tech', (string) $survivor->fresh()->notes);
        $this->assertStringContainsString('1 ticket', (string) $survivor->fresh()->notes);

        $dupRow = Client::withTrashed()->find($dup->id);
        $this->assertStringContainsString("Merged into 'Keep Co'", (string) $dupRow->notes);
        $this->assertNotNull($dupRow->deleted_at);
    }

    public function test_duplicate_has_no_remaining_client_references(): void
    {
        $survivor = $this->client('Keep Co');
        $dup = $this->client('Dupe Co');
        $this->ticket($dup);
        $this->asset($dup);
        $this->person($dup);
        $this->phoneCall($dup, 'uuid-x');
        $this->email($dup, 'g-x');
        $this->contract($dup);
        $this->invoice($dup, 'INV-X');
        $this->license($dup, $this->licenseType(), 'ref-x');

        $this->service()->mergeClients($survivor, $dup, $this->staffUser()->id);

        foreach (['tickets', 'assets', 'people', 'phone_calls', 'emails', 'contracts', 'invoices', 'licenses'] as $table) {
            $this->assertSame(0, DB::table($table)->where('client_id', $dup->id)->count(), "{$table} still references the duplicate");
        }
    }

    public function test_merge_does_not_block_on_open_tickets_active_contracts_or_unpaid_invoices(): void
    {
        // Unlike deleteClient(), merge consolidates active data rather than refusing it.
        $survivor = $this->client('Keep Co');
        $dup = $this->client('Dupe Co');
        $this->ticket($dup, ['status' => TicketStatus::InProgress]);
        $this->contract($dup)->update(['status' => 'active']);
        $this->invoice($dup, 'INV-OPEN'); // status 'posted' (issued, unpaid)

        $summary = $this->service()->mergeClients($survivor, $dup, $this->staffUser()->id);

        $this->assertSame(1, $summary['tickets']);
        $this->assertSame(1, $summary['contracts']);
        $this->assertSame(1, $summary['invoices']);
        $this->assertSoftDeleted('clients', ['id' => $dup->id]);
    }

    public function test_rejects_self_merge(): void
    {
        $c = $this->client('Acme');

        $this->expectException(\InvalidArgumentException::class);
        $this->service()->mergeClients($c, $c, $this->staffUser()->id);
    }
}
