<?php

namespace Tests\Feature\Assistant;

use App\Enums\PersonType;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Person;
use App\Services\Assistant\AssistantToolExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * psa-826, the rider on psa-842a: get_person's related.assets carried no
 * completeness signal.
 *
 * The trap is the one psa-823 was written to close, reintroduced on a different
 * path. A person assigned one device that was DEACTIVATED during a swap returned
 * `related.assets: []` — byte-identical to a person who has never been assigned
 * hardware — and the assistant reported them as having none. get_client already
 * pairs its capped fleet list with an uncapped assets_count; get_person had no
 * analogue, and `include_inactive` (which the caller can set on this very call)
 * was parsed for the SUBJECT lookup and never threaded into the asset queries.
 *
 * The active-only fence itself is deliberate and stays. What changes is that a
 * clean zero now has to explain itself.
 */
class PersonAssetCompletenessTest extends TestCase
{
    use RefreshDatabase;

    private function personWithDeactivatedDevice(): array
    {
        $client = Client::factory()->create();

        $person = Person::create([
            'client_id' => $client->id,
            'person_type' => PersonType::User,
            'first_name' => 'Swap',
            'last_name' => 'Subject',
            'email' => 'swap.subject@example.test',
            'is_active' => true,
        ]);

        $retired = Asset::factory()->create([
            'client_id' => $client->id,
            'hostname' => 'SWAP-OLD-01',
            'name' => 'SWAP-OLD-01',
            'is_active' => false,
        ]);
        $person->assets()->attach($retired->id);

        return [$client, $person, $retired];
    }

    public function test_empty_related_assets_is_no_longer_indistinguishable_from_no_hardware(): void
    {
        [$client, $person] = $this->personWithDeactivatedDevice();

        $result = (new AssistantToolExecutor(clientId: $client->id))
            ->execute('get_person', ['person_id' => $person->id]);

        $this->assertSame([], $result['related']['assets'], 'the active-only fence is deliberate and stays');
        $this->assertSame(
            1,
            $result['related']['assets_inactive_excluded'],
            'an empty list must say that a device was WITHHELD — otherwise the assistant reports the person as having no hardware',
        );
    }

    public function test_a_person_with_genuinely_no_hardware_reads_differently(): void
    {
        $client = Client::factory()->create();
        $person = Person::create([
            'client_id' => $client->id,
            'person_type' => PersonType::User,
            'first_name' => 'No',
            'last_name' => 'Hardware',
            'email' => 'no.hardware@example.test',
            'is_active' => true,
        ]);

        $result = (new AssistantToolExecutor(clientId: $client->id))
            ->execute('get_person', ['person_id' => $person->id]);

        $this->assertSame([], $result['related']['assets']);
        $this->assertSame(0, $result['related']['assets_count']);
        $this->assertSame(0, $result['related']['assets_inactive_excluded'], 'this is the reading the swap case must NOT be identical to');
    }

    public function test_include_inactive_is_threaded_into_the_related_asset_list(): void
    {
        [$client, $person, $retired] = $this->personWithDeactivatedDevice();

        $result = (new AssistantToolExecutor(clientId: $client->id))
            ->execute('get_person', ['person_id' => $person->id, 'include_inactive' => true]);

        $this->assertCount(1, $result['related']['assets'], 'include_inactive must reach the related block, not just the subject lookup');
        $this->assertSame($retired->id, $result['related']['assets'][0]['id']);
        $this->assertFalse($result['related']['assets'][0]['is_active']);
        $this->assertSame(1, $result['related']['assets_count']);
        $this->assertSame(0, $result['related']['assets_inactive_excluded'], 'nothing is excluded once the caller asked for everything');
    }

    public function test_assets_count_is_computed_over_the_same_fence_as_the_list(): void
    {
        [$client, $person] = $this->personWithDeactivatedDevice();

        $live = Asset::factory()->create([
            'client_id' => $client->id,
            'hostname' => 'SWAP-NEW-01',
            'name' => 'SWAP-NEW-01',
            'is_active' => true,
        ]);
        $person->assets()->attach($live->id);

        $fenced = (new AssistantToolExecutor(clientId: $client->id))
            ->execute('get_person', ['person_id' => $person->id]);

        // A count over a DIFFERENT fence than the list it explains is worse than
        // no count: 1 row listed alongside "2" reads as truncation, not exclusion.
        $this->assertCount(1, $fenced['related']['assets']);
        $this->assertSame(1, $fenced['related']['assets_count']);
        $this->assertSame(1, $fenced['related']['assets_inactive_excluded']);

        $unfenced = (new AssistantToolExecutor(clientId: $client->id))
            ->execute('get_person', ['person_id' => $person->id, 'include_inactive' => true]);

        $this->assertCount(2, $unfenced['related']['assets']);
        $this->assertSame(2, $unfenced['related']['assets_count']);
    }

    public function test_expanded_assets_honours_include_inactive_too(): void
    {
        [$client, $person] = $this->personWithDeactivatedDevice();

        $shallow = (new AssistantToolExecutor(clientId: $client->id))
            ->execute('get_person', ['person_id' => $person->id, 'expand' => ['assets']]);
        $this->assertSame([], $shallow['expanded']['assets']);

        $deep = (new AssistantToolExecutor(clientId: $client->id))
            ->execute('get_person', ['person_id' => $person->id, 'include_inactive' => true, 'expand' => ['assets']]);
        $this->assertCount(
            1,
            $deep['expanded']['assets'],
            'the expansion must not silently re-apply the fence the caller just lifted',
        );
    }

    public function test_description_states_the_related_list_is_active_only(): void
    {
        $tool = collect(\App\Services\Assistant\AssistantToolDefinitions::getTools(hasClient: true))
            ->firstWhere('name', 'get_person');

        $this->assertStringContainsString('ACTIVE-ONLY', $tool['description']);
        $this->assertStringContainsString('assets_inactive_excluded', $tool['description']);
    }
}
