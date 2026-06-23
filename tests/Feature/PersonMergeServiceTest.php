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
use App\Models\Person;
use App\Models\PersonEmail;
use App\Models\PhoneCall;
use App\Models\Ticket;
use App\Models\User;
use App\Services\PersonService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PersonMergeServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Ticket/Person creation fires observers that dispatch queued work — contain it.
        Bus::fake();
    }

    private function service(): PersonService
    {
        return app(PersonService::class);
    }

    private function client(string $name = 'Acme'): Client
    {
        return Client::create(['name' => $name]);
    }

    private function person(Client $client, array $overrides = []): Person
    {
        return Person::create(array_merge([
            'client_id' => $client->id,
            'person_type' => PersonType::User,
            'first_name' => 'Test',
            'last_name' => 'Person',
            'is_active' => true,
        ], $overrides));
    }

    private function ticketFor(Person $p, Client $c): Ticket
    {
        return Ticket::create([
            'client_id' => $c->id,
            'contact_id' => $p->id,
            'subject' => 'Help',
            'type' => TicketType::ServiceRequest,
            'status' => TicketStatus::New,
            'priority' => TicketPriority::P3,
            'opened_at' => now(),
        ]);
    }

    private function callFor(Person $p, string $uuid): PhoneCall
    {
        // person_id is not mass-assignable on PhoneCall — set it directly.
        $call = new PhoneCall(['call_uuid' => $uuid, 'from_number' => '+15555550100']);
        $call->person_id = $p->id;
        $call->save();

        return $call;
    }

    private function emailFor(Person $p, Client $c, string $graphId): Email
    {
        return Email::create([
            'graph_id' => $graphId,
            'from_address' => 'sender@ext.test',
            'subject' => 'Hi',
            'received_at' => now(),
            'person_id' => $p->id,
            'client_id' => $c->id,
        ]);
    }

    private function staffUser(): User
    {
        return User::factory()->create();
    }

    public function test_repoints_tickets_calls_and_emails_to_survivor(): void
    {
        $c = $this->client();
        $survivor = $this->person($c, ['first_name' => 'Keep', 'email' => 'keep@acme.test']);
        $dup = $this->person($c, ['first_name' => 'Dupe', 'email' => 'dupe@acme.test']);

        $t1 = $this->ticketFor($dup, $c);
        $t2 = $this->ticketFor($dup, $c);
        $call = $this->callFor($dup, 'uuid-1');
        $email = $this->emailFor($dup, $c, 'g-1');

        $summary = $this->service()->mergePeople($survivor, $dup, $this->staffUser()->id);

        $this->assertSame($survivor->id, $t1->fresh()->contact_id);
        $this->assertSame($survivor->id, $t2->fresh()->contact_id);
        $this->assertSame($survivor->id, $call->fresh()->person_id);
        $this->assertSame($survivor->id, $email->fresh()->person_id);
        $this->assertSame(2, $summary['tickets']);
        $this->assertSame(1, $summary['calls']);
        $this->assertSame(1, $summary['emails']);
        $this->assertSoftDeleted('people', ['id' => $dup->id]);
        $this->assertNotNull(Person::find($survivor->id));
    }

    public function test_moves_contract_and_asset_assignments_with_dedup(): void
    {
        $c = $this->client();
        $survivor = $this->person($c, ['email' => 'keep@acme.test']);
        $dup = $this->person($c, ['email' => 'dupe@acme.test']);

        $shared = Contract::create(['client_id' => $c->id, 'name' => 'Shared', 'type' => 'managed', 'start_date' => '2026-01-01']);
        $dupOnly = Contract::create(['client_id' => $c->id, 'name' => 'DupOnly', 'type' => 'managed', 'start_date' => '2026-01-01']);
        $survivor->contracts()->attach($shared->id, ['assignment_source' => 'manual', 'assigned_at' => now()]);
        $dup->contracts()->attach($shared->id, ['assignment_source' => 'manual', 'assigned_at' => now()]);
        $dup->contracts()->attach($dupOnly->id, ['assignment_source' => 'rule', 'assigned_at' => now()]);

        $sharedAsset = Asset::create(['client_id' => $c->id, 'name' => 'SharedPC']);
        $dupAsset = Asset::create(['client_id' => $c->id, 'name' => 'DupPC']);
        $survivor->assets()->attach($sharedAsset->id, ['is_primary' => false, 'assignment_source' => 'auto', 'last_seen_at' => now()]);
        $dup->assets()->attach($sharedAsset->id, ['is_primary' => true, 'assignment_source' => 'auto', 'last_seen_at' => now()]);
        $dup->assets()->attach($dupAsset->id, ['is_primary' => true, 'assignment_source' => 'auto', 'last_seen_at' => now()]);

        $summary = $this->service()->mergePeople($survivor, $dup, $this->staffUser()->id);

        $survivorContractIds = $survivor->fresh()->contracts()->pluck('contracts.id')->sort()->values()->all();
        $expectedContractIds = collect([$shared->id, $dupOnly->id])->sort()->values()->all();
        $this->assertSame($expectedContractIds, $survivorContractIds); // shared not duplicated, dup-only moved
        $this->assertSame(1, $summary['contracts']); // only the dup-only contract is newly moved

        $survivorAssetIds = $survivor->fresh()->assets()->pluck('assets.id')->all();
        $this->assertContains($dupAsset->id, $survivorAssetIds);
        $this->assertContains($sharedAsset->id, $survivorAssetIds);
        $this->assertSame(1, $summary['assets']); // only the dup-only asset is newly moved

        // Moved assignment is stored as manual so rule reconciliation can't strip it
        $movedSource = DB::table('contract_person')
            ->where('contract_id', $dupOnly->id)->where('person_id', $survivor->id)->value('assignment_source');
        $this->assertSame('manual', $movedSource);
    }

    public function test_carries_email_addresses_and_removes_duplicate_rows(): void
    {
        $c = $this->client();
        $survivor = $this->person($c, ['email' => 'keep@acme.test']);
        $dup = $this->person($c, ['email' => 'dupe@acme.test']);
        PersonEmail::create(['person_id' => $dup->id, 'email' => 'alias@acme.test', 'is_primary' => false, 'source' => 'manual']);
        // A shared address already on the survivor must not duplicate
        PersonEmail::create(['person_id' => $survivor->id, 'email' => 'shared@acme.test', 'is_primary' => false, 'source' => 'manual']);
        PersonEmail::create(['person_id' => $dup->id, 'email' => 'shared@acme.test', 'is_primary' => false, 'source' => 'manual']);

        $this->service()->mergePeople($survivor, $dup, $this->staffUser()->id);

        $survivorEmails = $survivor->fresh()->allEmailAddresses();
        $this->assertContains('dupe@acme.test', $survivorEmails);   // dup's primary preserved as an address
        $this->assertContains('alias@acme.test', $survivorEmails);  // dup's extra preserved
        $this->assertContains('keep@acme.test', $survivorEmails);
        $this->assertContains('shared@acme.test', $survivorEmails);
        // No duplicate of the shared address
        $this->assertSame(1, PersonEmail::where('person_id', $survivor->id)->where('email', 'shared@acme.test')->count());
        // Duplicate's own rows are gone
        $this->assertSame(0, PersonEmail::where('person_id', $dup->id)->count());
    }

    public function test_carries_portal_access_to_survivor_with_differing_login_email(): void
    {
        // The MF1 must-pass test: absorbed record's login email differs from the survivor's.
        $c = $this->client();
        $survivor = $this->person($c, ['email' => 'keep@acme.test']); // no portal
        $dup = $this->person($c, ['email' => 'dupe@acme.test', 'portal_enabled' => true, 'company_wide_access' => true]);
        $dup->password = 's3cret-pw';
        $dup->save();

        $summary = $this->service()->mergePeople($survivor, $dup, $this->staffUser()->id);

        $survivor->refresh();
        $this->assertTrue((bool) $survivor->portal_enabled);
        $this->assertTrue((bool) $survivor->company_wide_access);
        $this->assertTrue($summary['portal_login_email_changed']);

        // The end user authenticates via the SURVIVOR's email with the carried password
        $this->assertTrue(Auth::guard('portal')->attempt([
            'email' => 'keep@acme.test', 'password' => 's3cret-pw', 'portal_enabled' => true, 'is_active' => true,
        ]));
        Auth::guard('portal')->logout();
        // The duplicate's old login email no longer authenticates (intentional migration)
        $this->assertFalse(Auth::guard('portal')->attempt([
            'email' => 'dupe@acme.test', 'password' => 's3cret-pw', 'portal_enabled' => true, 'is_active' => true,
        ]));
    }

    public function test_merge_does_not_grant_portal_to_a_prospect_survivor(): void
    {
        // Invariant: no path may set portal_enabled/password on a Person whose
        // client is a Prospect. A duplicate provisioned while Active and then
        // reclassified to Prospect must NOT carry its grant onto the survivor.
        $c = Client::factory()->prospect()->create();
        $survivor = $this->person($c, ['email' => 'keep@lead.test']); // no portal
        $dup = $this->person($c, ['email' => 'dupe@lead.test', 'portal_enabled' => true, 'company_wide_access' => true]);
        $dup->password = 's3cret-pw';
        $dup->save();

        $this->service()->mergePeople($survivor, $dup, $this->staffUser()->id);

        $survivor->refresh();
        $this->assertFalse((bool) $survivor->portal_enabled);
        $this->assertNull($survivor->password);
        $this->assertFalse((bool) $survivor->company_wide_access);
    }

    public function test_does_not_clobber_existing_survivor_portal_credentials(): void
    {
        $c = $this->client();
        $survivor = $this->person($c, ['email' => 'keep@acme.test', 'portal_enabled' => true]);
        $survivor->password = 'survivor-pw';
        $survivor->save();
        $dup = $this->person($c, ['email' => 'dupe@acme.test', 'portal_enabled' => true]);
        $dup->password = 'dup-pw';
        $dup->save();

        $this->service()->mergePeople($survivor, $dup, $this->staffUser()->id);

        // Survivor keeps its own credentials
        $this->assertTrue(Auth::guard('portal')->attempt([
            'email' => 'keep@acme.test', 'password' => 'survivor-pw', 'portal_enabled' => true, 'is_active' => true,
        ]));
        Auth::guard('portal')->logout();
        $this->assertFalse(Auth::guard('portal')->attempt([
            'email' => 'keep@acme.test', 'password' => 'dup-pw', 'portal_enabled' => true, 'is_active' => true,
        ]));
    }

    public function test_moves_cipp_identity_and_nulls_it_on_duplicate(): void
    {
        $c = $this->client();
        $survivor = $this->person($c, ['email' => 'keep@acme.test']); // no cipp id
        $dup = $this->person($c, ['email' => 'dupe@acme.test', 'cipp_user_id' => 'azure-oid-123', 'cipp_upn' => 'dupe@acme.test']);

        $this->service()->mergePeople($survivor, $dup, $this->staffUser()->id);

        $this->assertSame('azure-oid-123', $survivor->fresh()->cipp_user_id);
        // Loser's identity cleared so re-sync binds to the survivor, not the tombstone
        $this->assertNull(Person::withTrashed()->find($dup->id)->cipp_user_id);
        $this->assertNull(Person::withTrashed()->find($dup->id)->cipp_upn);
    }

    public function test_promotes_primary_and_fills_only_blank_fields(): void
    {
        $c = $this->client();
        $survivor = $this->person($c, ['email' => 'keep@acme.test', 'is_primary' => false, 'job_title' => 'Owner', 'phone' => null]);
        $dup = $this->person($c, ['email' => 'dupe@acme.test', 'is_primary' => true, 'job_title' => 'Manager', 'phone' => '+15555550111']);

        $this->service()->mergePeople($survivor, $dup, $this->staffUser()->id);

        $survivor->refresh();
        $this->assertTrue((bool) $survivor->is_primary);            // promoted (dup was primary)
        $this->assertSame('Owner', $survivor->job_title);           // not overwritten
        $this->assertNotNull($survivor->phone);                     // blank field filled from dup
    }

    public function test_duplicate_has_no_remaining_references_across_all_fk_tables(): void
    {
        $c = $this->client();
        $survivor = $this->person($c, ['email' => 'keep@acme.test']);
        $dup = $this->person($c, ['email' => 'dupe@acme.test']);

        $this->ticketFor($dup, $c);
        $this->callFor($dup, 'uuid-x');
        $this->emailFor($dup, $c, 'g-x');
        $contract = Contract::create(['client_id' => $c->id, 'name' => 'C', 'type' => 'managed', 'start_date' => '2026-01-01']);
        $dup->contracts()->attach($contract->id, ['assignment_source' => 'manual', 'assigned_at' => now()]);
        $asset = Asset::create(['client_id' => $c->id, 'name' => 'PC']);
        $dup->assets()->attach($asset->id, ['is_primary' => false, 'assignment_source' => 'auto', 'last_seen_at' => now()]);
        PersonEmail::create(['person_id' => $dup->id, 'email' => 'alias@acme.test', 'is_primary' => false, 'source' => 'manual']);

        $this->service()->mergePeople($survivor, $dup, $this->staffUser()->id);

        $this->assertSame(0, Ticket::where('contact_id', $dup->id)->count());
        $this->assertSame(0, PhoneCall::where('person_id', $dup->id)->count());
        $this->assertSame(0, Email::where('person_id', $dup->id)->count());
        $this->assertSame(0, DB::table('contract_person')->where('person_id', $dup->id)->count());
        $this->assertSame(0, DB::table('asset_person')->where('person_id', $dup->id)->count());
        $this->assertSame(0, PersonEmail::where('person_id', $dup->id)->count());
    }

    public function test_rejects_self_merge(): void
    {
        $c = $this->client();
        $p = $this->person($c, ['email' => 'p@acme.test']);

        $this->expectException(\InvalidArgumentException::class);
        $this->service()->mergePeople($p, $p, $this->staffUser()->id);
    }

    public function test_rejects_cross_client_merge(): void
    {
        $a = $this->client('Acme');
        $b = $this->client('Beta');
        $survivor = $this->person($a, ['email' => 'a@acme.test']);
        $dup = $this->person($b, ['email' => 'b@beta.test']);

        $this->expectException(\InvalidArgumentException::class);
        $this->service()->mergePeople($survivor, $dup, $this->staffUser()->id);
    }
}
