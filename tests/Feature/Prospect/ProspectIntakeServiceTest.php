<?php

namespace Tests\Feature\Prospect;

use App\Enums\ClientStage;
use App\Models\PhoneCall;
use App\Services\Prospect\ProspectIntakeService;
use App\Support\PhoneNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class ProspectIntakeServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
    }

    public function test_provision_seeds_the_normalized_number_and_repeat_calls_match(): void
    {
        $call = new PhoneCall([
            'call_uuid' => uniqid('test_', true),
            'from_number' => '+1 (555) 010-2030',
            'status' => 'completed',
        ]);
        $call->client_id = null;
        $call->save();

        $svc = app(ProspectIntakeService::class);

        $out = $svc->provisionFromCall($call, 'Cascade Dental');

        $this->assertSame(ClientStage::Prospect, $out['client']->stage);
        $this->assertSame(PhoneNumber::normalize('+1 (555) 010-2030'), $out['person']->phone);
        $this->assertFalse($out['person']->portal_enabled);
        $this->assertNull($out['person']->password);

        // a second call from the same number resolves to the SAME prospect (no duplicate)
        $this->assertTrue($svc->matchByNumber('555-010-2030')?->is($out['client']));
    }

    public function test_match_by_number_returns_null_when_no_person_has_that_number(): void
    {
        $svc = app(ProspectIntakeService::class);

        $this->assertNull($svc->matchByNumber('+15559999999'));
    }

    public function test_provision_wraps_in_a_transaction_and_returns_all_three_keys(): void
    {
        $call = new PhoneCall([
            'call_uuid' => uniqid('test_', true),
            'from_number' => '+15550203040',
            'status' => 'completed',
        ]);
        $call->client_id = null;
        $call->save();

        $svc = app(ProspectIntakeService::class);
        $out = $svc->provisionFromCall($call, 'Test Corp');

        $this->assertArrayHasKey('client', $out);
        $this->assertArrayHasKey('person', $out);
        $this->assertArrayHasKey('ticket', $out);

        // Verify all three entities are persisted
        $this->assertNotNull($out['client']->id);
        $this->assertNotNull($out['person']->id);
        $this->assertNotNull($out['ticket']->id);

        // Ticket must be linked to the client and person
        $this->assertSame($out['client']->id, $out['ticket']->client_id);
        $this->assertSame($out['person']->id, $out['ticket']->contact_id);
    }

    public function test_match_by_number_finds_client_via_mobile_column(): void
    {
        $call = new PhoneCall([
            'call_uuid' => uniqid('test_', true),
            'from_number' => '+15550203040',
            'status' => 'completed',
        ]);
        $call->client_id = null;
        $call->save();

        $svc = app(ProspectIntakeService::class);
        $out = $svc->provisionFromCall($call, 'Mobile Corp');

        // Store the normalized number in mobile instead to simulate the mobile-column path
        $out['person']->phone = null;
        $out['person']->mobile = PhoneNumber::normalize('+15550203040');
        $out['person']->save();

        $found = $svc->matchByNumber('+1-555-020-3040');
        $this->assertTrue($found?->is($out['client']));
    }
}
