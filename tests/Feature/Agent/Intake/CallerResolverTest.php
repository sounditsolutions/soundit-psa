<?php

namespace Tests\Feature\Agent\Intake;

use App\Enums\CallStatus;
use App\Models\Client;
use App\Models\Person;
use App\Models\PhoneCall;
use App\Services\Agent\Intake\CallerResolution;
use App\Services\Agent\Intake\CallerResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CallerResolverTest extends TestCase
{
    use RefreshDatabase;

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * Create and persist a PhoneCall. `client_id` and `person_id` are not in the
     * model's $fillable (they're FKs set by the resolution pipeline), so we set
     * them directly on the instance and save.
     */
    private function makeCall(array $attrs = []): PhoneCall
    {
        $call = new PhoneCall([
            'call_uuid' => uniqid('test_', true),
            'from_number' => $attrs['from_number'] ?? '+15550100001',
            'status' => $attrs['status'] ?? CallStatus::Completed,
            'caller_identified_name' => $attrs['caller_identified_name'] ?? null,
            'caller_identified_company' => $attrs['caller_identified_company'] ?? null,
            'caller_identity_confidence' => $attrs['caller_identity_confidence'] ?? null,
        ]);

        $call->client_id = $attrs['client_id'] ?? null;
        $call->person_id = $attrs['person_id'] ?? null;
        if (isset($attrs['to_number'])) {
            $call->to_number = $attrs['to_number'];
        }
        $call->save();

        return $call;
    }

    /** Create and persist a Person with minimal required fields. */
    private function makePerson(Client $client, string $firstName, string $lastName): Person
    {
        return Person::create([
            'client_id' => $client->id,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'is_active' => true,
        ]);
    }

    private function resolver(): CallerResolver
    {
        return app(CallerResolver::class);
    }

    // ── Test 1: Stage 1 — existing ───────────────────────────────────────────

    /**
     * When a call already has client_id set (resolved at creation by ResolveCallerFromPeople),
     * the resolver must return 'existing' immediately with the stored client/person pair.
     */
    public function test_stage1_returns_existing_when_call_already_has_client_id(): void
    {
        $client = Client::factory()->create();
        $person = $this->makePerson($client, 'Alice', 'Bourne');
        $call = $this->makeCall([
            'client_id' => $client->id,
            'person_id' => $person->id,
            'from_number' => '+15551110001',
        ]);

        $result = $this->resolver()->resolve($call);

        $this->assertTrue($result->resolved);
        $this->assertSame('existing', $result->source);
        $this->assertSame($client->id, $result->clientId);
        $this->assertSame($person->id, $result->personId);
    }

    // ── Test 2: Stage 2 — prior-call history by from_number ─────────────────

    /**
     * An unresolved call whose from_number matches a prior resolved call's from_number
     * must be resolved via 'call_history' with the prior call's client/person.
     */
    public function test_stage2_resolves_via_prior_call_history_from_number(): void
    {
        $client = Client::factory()->create();
        $person = $this->makePerson($client, 'Bob', 'Crenshaw');
        $num = '+15552220001';

        // Prior resolved call
        $this->makeCall(['from_number' => $num, 'client_id' => $client->id, 'person_id' => $person->id]);

        // New unresolved call from the same number
        $call = $this->makeCall(['from_number' => $num]);

        $result = $this->resolver()->resolve($call);

        $this->assertTrue($result->resolved);
        $this->assertSame('call_history', $result->source);
        $this->assertSame($client->id, $result->clientId);
        $this->assertSame($person->id, $result->personId);
    }

    /**
     * Legacy insurance: a prior call that stored the customer number in to_number
     * (outbound-style seeded rows) is still matched.
     */
    public function test_stage2_matches_prior_call_via_to_number_insurance(): void
    {
        $client = Client::factory()->create();
        $person = $this->makePerson($client, 'Carol', 'Day');
        $num = '+15553330001';

        // Prior row stores the customer number in to_number (legacy outbound convention)
        $this->makeCall(['from_number' => '+19995550001', 'to_number' => $num, 'client_id' => $client->id, 'person_id' => $person->id]);

        // New call arrives with that number as from_number
        $call = $this->makeCall(['from_number' => $num]);

        $result = $this->resolver()->resolve($call);

        $this->assertTrue($result->resolved);
        $this->assertSame('call_history', $result->source);
        $this->assertSame($client->id, $result->clientId);
        $this->assertSame($person->id, $result->personId);
    }

    // ── Test 3: Stage 2 isolation — NULL person_id rows not used ────────────

    /**
     * A prior call with the same from_number but person_id = NULL must NOT be used;
     * the resolver should fall through to Stage 3 / unresolved.
     */
    public function test_stage2_does_not_use_prior_call_with_null_person_id(): void
    {
        $num = '+15554440001';

        // Prior call for same number but unresolved (person_id = null)
        $this->makeCall(['from_number' => $num, 'client_id' => null, 'person_id' => null]);

        $call = $this->makeCall(['from_number' => $num]);

        $result = $this->resolver()->resolve($call);

        $this->assertFalse($result->resolved);
        $this->assertSame('unresolved', $result->source);
    }

    // ── Test 4: Stage 3a+3b — company + name (corroborated) ─────────────────

    /**
     * When the company matches exactly 1 client AND a person of that name exists
     * within it, the call is resolved via 'content_name' at the base confidence
     * floor (0.60). Company corroboration means the 0.75 global floor does not apply.
     */
    public function test_stage3_resolves_content_name_when_company_and_name_corroborated(): void
    {
        $client = Client::factory()->create(['name' => 'Acme Corp']);
        $person = $this->makePerson($client, 'Diana', 'Earl');

        $call = $this->makeCall([
            'caller_identified_name' => 'Diana Earl',
            'caller_identified_company' => 'Acme Corp',
            'caller_identity_confidence' => 0.65,
        ]);

        $result = $this->resolver()->resolve($call);

        $this->assertTrue($result->resolved);
        $this->assertSame('content_name', $result->source);
        $this->assertSame($client->id, $result->clientId);
        $this->assertSame($person->id, $result->personId);
    }

    // ── Test 5: Stage 3b global — uncorroborated name, floor enforcement ─────

    /**
     * No company provided. A single global person match at confidence >= 0.75 → resolves.
     * The SAME setup at confidence 0.65 (below 0.75 global floor) → UNRESOLVED.
     */
    public function test_stage3_resolves_global_name_only_above_075_floor(): void
    {
        $client = Client::factory()->create(['name' => 'Unique Company ZZZ']);
        $person = $this->makePerson($client, 'Frank', 'Grant');

        // High confidence (0.80) → resolved
        $callHigh = $this->makeCall([
            'caller_identified_name' => 'Frank Grant',
            'caller_identity_confidence' => 0.80,
        ]);
        $resultHigh = $this->resolver()->resolve($callHigh);

        $this->assertTrue($resultHigh->resolved, 'Expected resolved at 0.80');
        $this->assertSame('content_name', $resultHigh->source);
        $this->assertSame($person->id, $resultHigh->personId);

        // Below floor (0.65) → UNRESOLVED
        $callLow = $this->makeCall([
            'caller_identified_name' => 'Frank Grant',
            'caller_identity_confidence' => 0.65,
        ]);
        $resultLow = $this->resolver()->resolve($callLow);

        $this->assertFalse($resultLow->resolved, 'Expected unresolved at 0.65 (below 0.75 global floor)');
        $this->assertSame('unresolved', $resultLow->source);
    }

    // ── Test 6: cross-client ambiguity → UNRESOLVED (key safety test) ────────

    /**
     * Two persons named "John Smith" in DIFFERENT clients, no company, high confidence.
     * The resolver must return unresolved — it cannot pick a client without certainty.
     * This is the cross-client safety guard: >1 match → never guess.
     */
    public function test_stage3_cross_client_ambiguity_returns_unresolved(): void
    {
        $clientA = Client::factory()->create(['name' => 'Client Alpha LLC']);
        $clientB = Client::factory()->create(['name' => 'Client Beta Inc']);

        $this->makePerson($clientA, 'John', 'Smith');
        $this->makePerson($clientB, 'John', 'Smith');

        $call = $this->makeCall([
            'caller_identified_name' => 'John Smith',
            'caller_identity_confidence' => 0.95,
        ]);

        $result = $this->resolver()->resolve($call);

        $this->assertFalse($result->resolved, 'Two "John Smith" in different clients must not resolve');
        $this->assertSame('unresolved', $result->source);
        $this->assertNull($result->clientId);
        $this->assertNull($result->personId);
    }

    // ── Test 7: Stage 3c — company-only (person absent or ambiguous) ─────────

    /**
     * Company matches exactly 1 client, but the named person either doesn't exist
     * or is ambiguous. The resolver returns 'content_company' (client known, person null).
     */
    public function test_stage3c_resolves_company_only_when_person_absent_or_ambiguous(): void
    {
        $client = Client::factory()->create(['name' => 'Dunder Mifflin']);
        // No person named "Invisible User" at this client

        $call = $this->makeCall([
            'caller_identified_name' => 'Invisible User',
            'caller_identified_company' => 'Dunder Mifflin',
            'caller_identity_confidence' => 0.70,
        ]);

        $result = $this->resolver()->resolve($call);

        $this->assertTrue($result->resolved);
        $this->assertSame('content_company', $result->source);
        $this->assertSame($client->id, $result->clientId);
        $this->assertNull($result->personId, 'Person must be null for company-only resolution');
    }

    /**
     * Company matches 1 client, but two people share the given name → ambiguous person.
     * Must still resolve 'content_company' (client is unambiguous even if person is not).
     */
    public function test_stage3c_resolves_company_only_when_person_is_ambiguous(): void
    {
        $client = Client::factory()->create(['name' => 'Initech Systems']);
        $this->makePerson($client, 'Sam', 'Jones');
        $this->makePerson($client, 'Samantha', 'Jones');

        $call = $this->makeCall([
            'caller_identified_name' => 'Sam Jones',
            'caller_identified_company' => 'Initech Systems',
            'caller_identity_confidence' => 0.70,
        ]);

        $result = $this->resolver()->resolve($call);

        $this->assertTrue($result->resolved);
        $this->assertSame('content_company', $result->source);
        $this->assertSame($client->id, $result->clientId);
        $this->assertNull($result->personId);
    }

    // ── Test 8: below floor and "Unknown" sentinel → Stage 3 skipped ─────────

    /**
     * Confidence 0.4 (below the 0.60 base floor) → Stage 3 is skipped → unresolved,
     * even when a matching person exists.
     */
    public function test_stage3_skipped_when_confidence_below_base_floor(): void
    {
        $client = Client::factory()->create();
        $this->makePerson($client, 'Grace', 'Hill');

        $call = $this->makeCall([
            'caller_identified_name' => 'Grace Hill',
            'caller_identity_confidence' => 0.40,
        ]);

        $result = $this->resolver()->resolve($call);

        $this->assertFalse($result->resolved);
        $this->assertSame('unresolved', $result->source);
    }

    /**
     * Name = "Unknown" (carrier placeholder) → treated as absent → Stage 3 skipped
     * when there is also no company → unresolved.
     */
    public function test_stage3_skipped_when_name_is_unknown_sentinel_and_no_company(): void
    {
        $client = Client::factory()->create();
        $this->makePerson($client, 'Unknown', 'Person');

        $call = $this->makeCall([
            'caller_identified_name' => 'Unknown',
            'caller_identity_confidence' => 0.90,
        ]);

        $result = $this->resolver()->resolve($call);

        $this->assertFalse($result->resolved);
        $this->assertSame('unresolved', $result->source);
    }

    // ── Test 9: full-name gotcha — token matching, not scopeSearch ───────────

    /**
     * A person stored as first_name=John / last_name=Smith IS matched when the caller
     * provides "John Smith" as caller_identified_name. This proves the resolver uses
     * token-aware separate-field matching, not Person::scopeSearch (which would try
     * the whole string "John Smith" against each single field and fail).
     */
    public function test_stage3_matches_full_name_with_token_aware_query(): void
    {
        $client = Client::factory()->create(['name' => 'Token Test Co']);
        $person = $this->makePerson($client, 'John', 'Smith');

        $call = $this->makeCall([
            'caller_identified_name' => 'John Smith',
            'caller_identified_company' => 'Token Test Co',
            'caller_identity_confidence' => 0.80,
        ]);

        $result = $this->resolver()->resolve($call);

        $this->assertTrue($result->resolved, 'first=John / last=Smith must match "John Smith" via token matching');
        $this->assertSame('content_name', $result->source);
        $this->assertSame($person->id, $result->personId);
        $this->assertSame($client->id, $result->clientId);
    }

    // ── Sanity: unresolved() factory ─────────────────────────────────────────

    public function test_unresolved_factory_returns_not_resolved(): void
    {
        $r = CallerResolution::unresolved();

        $this->assertFalse($r->resolved);
        $this->assertNull($r->clientId);
        $this->assertNull($r->personId);
        $this->assertSame('unresolved', $r->source);
    }
}
