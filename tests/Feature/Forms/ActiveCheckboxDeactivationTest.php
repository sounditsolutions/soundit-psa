<?php

namespace Tests\Feature\Forms;

use App\Models\Asset;
use App\Models\Client;
use App\Models\Person;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers #991 — the asset and client edit forms could activate but never
 * deactivate.
 *
 * An unchecked HTML checkbox submits no key at all. Both forms rendered a bare
 * checkbox, `is_active` is validated as optional (`['boolean']`) so an absent
 * key drops silently out of validated(), and both controllers mass-assign
 * validated() — so unchecking "Active" was a no-op that still flashed
 * "updated successfully".
 *
 * The fix is the hidden-input fallback the repo already uses on the staff,
 * people and contracts forms: `<input type="hidden" name="is_active" value="0">`
 * ahead of the checkbox, so the browser always submits the field.
 *
 * Deliberately NOT fixed server-side. Normalizing with $request->boolean() in
 * the controller would make an absent key mean false on a PATCH route, turning
 * a silent failure-to-deactivate into a silent unwanted deactivation for any
 * future partial-update caller. test_*_patch_without_the_field_leaves_it
 * _unchanged locks that choice in.
 */
class ActiveCheckboxDeactivationTest extends TestCase
{
    use RefreshDatabase;

    private function staff(): User
    {
        return User::factory()->create();
    }

    /**
     * Rebuild the payload a browser would actually submit for the rendered
     * form, with the named checkboxes left UNCHECKED.
     *
     * This is the point of the fix: an unchecked checkbox contributes no key
     * at all, so only a preceding hidden input can carry the "0". Asserting on
     * the hidden input's markup guards the string; going through this helper
     * guards the behaviour.
     */
    private function browserPayload(string $html, array $unchecked = []): array
    {
        preg_match_all('/<input\b[^>]*>/i', $html, $tags);

        $payload = [];

        foreach ($tags[0] as $tag) {
            preg_match('/\bname="([^"]*)"/i', $tag, $n);
            $name = $n[1] ?? null;

            if ($name === null || $name === '_token' || $name === '_method') {
                continue;
            }

            preg_match('/\btype="([^"]*)"/i', $tag, $t);
            $type = strtolower($t[1] ?? 'text');

            preg_match('/\bvalue="([^"]*)"/i', $tag, $v);
            $value = $v[1] ?? '';

            if ($type === 'checkbox' || $type === 'radio') {
                // An unchecked box submits nothing — that is the whole defect.
                if (in_array($name, $unchecked, true) || ! preg_match('/\bchecked\b/i', $tag)) {
                    continue;
                }
            }

            // Later inputs win, exactly as PHP parses a repeated key: the
            // checkbox's "1" overrides the hidden "0" when it IS checked.
            $payload[$name] = $value;
        }

        return $payload;
    }

    // ---------------------------------------------------------------- assets

    public function test_asset_edit_form_renders_the_hidden_is_active_fallback(): void
    {
        $asset = Asset::factory()->create(['is_active' => true]);

        $this->actingAs($this->staff())
            ->get(route('assets.edit', $asset))
            ->assertOk()
            ->assertSee('<input type="hidden" name="is_active" value="0">', false);
    }

    public function test_asset_can_be_deactivated_from_the_edit_form(): void
    {
        $asset = Asset::factory()->create(['is_active' => true]);

        // Exactly what the browser posts with the box unchecked: the hidden
        // input only, because the checkbox contributes nothing.
        $this->actingAs($this->staff())
            ->patch(route('assets.update', $asset), [
                'name' => $asset->name,
                'is_active' => '0',
            ])
            ->assertRedirect(route('assets.show', $asset));

        $this->assertFalse($asset->fresh()->is_active);
    }

    public function test_asset_can_be_reactivated_from_the_edit_form(): void
    {
        $asset = Asset::factory()->create(['is_active' => false]);

        // Box checked: the checkbox's value="1" wins over the earlier hidden input.
        $this->actingAs($this->staff())
            ->patch(route('assets.update', $asset), [
                'name' => $asset->name,
                'is_active' => '1',
            ]);

        $this->assertTrue($asset->fresh()->is_active);
    }

    public function test_asset_patch_without_the_field_leaves_it_unchanged(): void
    {
        $asset = Asset::factory()->create(['is_active' => true]);

        $this->actingAs($this->staff())
            ->patch(route('assets.update', $asset), ['name' => 'Renamed workstation']);

        $fresh = $asset->fresh();
        $this->assertSame('Renamed workstation', $fresh->name);
        $this->assertTrue($fresh->is_active, 'an absent key must not deactivate');
    }

    public function test_unchecking_active_in_the_rendered_asset_form_deactivates(): void
    {
        $asset = Asset::factory()->create(['is_active' => true]);
        $staff = $this->staff();

        $html = $this->actingAs($staff)->get(route('assets.edit', $asset))->getContent();
        $payload = $this->browserPayload($html, unchecked: ['is_active']);

        // Precondition: the box really was rendered checked, so this test is
        // exercising an unchecking and not an already-off field.
        $this->assertTrue($asset->is_active);

        $this->actingAs($staff)->patch(route('assets.update', $asset), $payload);

        $this->assertFalse($asset->fresh()->is_active, 'unchecking Active must persist');
    }

    public function test_submitting_the_rendered_asset_form_untouched_keeps_it_active(): void
    {
        $asset = Asset::factory()->create(['is_active' => true]);
        $staff = $this->staff();

        $html = $this->actingAs($staff)->get(route('assets.edit', $asset))->getContent();

        $this->actingAs($staff)->patch(route('assets.update', $asset), $this->browserPayload($html));

        $this->assertTrue($asset->fresh()->is_active, 'a no-op save must not deactivate');
    }

    // --------------------------------------------------------------- clients

    public function test_client_edit_form_renders_the_hidden_is_active_fallback(): void
    {
        $client = Client::factory()->create(['is_active' => true]);

        $this->actingAs($this->staff())
            ->get(route('clients.edit', $client))
            ->assertOk()
            ->assertSee('<input type="hidden" name="is_active" value="0">', false);
    }

    public function test_client_can_be_deactivated_from_the_edit_form(): void
    {
        $client = Client::factory()->create(['is_active' => true]);

        $this->actingAs($this->staff())
            ->patch(route('clients.update', $client), [
                'name' => $client->name,
                'is_active' => '0',
            ])
            ->assertRedirect(route('clients.show', $client));

        $this->assertFalse($client->fresh()->is_active);
    }

    public function test_client_can_be_reactivated_from_the_edit_form(): void
    {
        $client = Client::factory()->create(['is_active' => false]);

        $this->actingAs($this->staff())
            ->patch(route('clients.update', $client), [
                'name' => $client->name,
                'is_active' => '1',
            ]);

        $this->assertTrue($client->fresh()->is_active);
    }

    public function test_client_patch_without_the_field_leaves_it_unchanged(): void
    {
        $client = Client::factory()->create(['is_active' => true]);

        $this->actingAs($this->staff())
            ->patch(route('clients.update', $client), ['name' => 'Renamed client']);

        $fresh = $client->fresh();
        $this->assertSame('Renamed client', $fresh->name);
        $this->assertTrue($fresh->is_active, 'an absent key must not deactivate');
    }

    public function test_unchecking_active_in_the_rendered_client_form_deactivates(): void
    {
        $client = Client::factory()->create(['is_active' => true]);
        $staff = $this->staff();

        $html = $this->actingAs($staff)->get(route('clients.edit', $client))->getContent();
        $payload = $this->browserPayload($html, unchecked: ['is_active']);

        $this->assertTrue($client->is_active);

        $this->actingAs($staff)->patch(route('clients.update', $client), $payload);

        $this->assertFalse($client->fresh()->is_active, 'unchecking Active must persist');
    }

    public function test_submitting_the_rendered_client_form_untouched_keeps_it_active(): void
    {
        $client = Client::factory()->create(['is_active' => true]);
        $staff = $this->staff();

        $html = $this->actingAs($staff)->get(route('clients.edit', $client))->getContent();

        $this->actingAs($staff)->patch(route('clients.update', $client), $this->browserPayload($html));

        $this->assertTrue($client->fresh()->is_active, 'a no-op save must not deactivate');
    }

    // ---------------------------------------------------------------- people
    //
    // #991's body lists the people form as broken on both is_primary and
    // is_active. It is not: people/_form.blade.php has carried the hidden-input
    // fallback for both fields since the initial public release, and the issue
    // cites the checkbox line rather than the hidden input one line above it.
    // These are regression guards for behaviour that already held at 424283fd,
    // not fixes — kept because the field the issue named is worth pinning.

    private function person(array $attrs = []): Person
    {
        return Person::create(array_merge([
            'client_id' => Client::factory()->create()->id,
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
        ], $attrs));
    }

    public function test_person_edit_form_renders_hidden_fallbacks_for_both_checkboxes(): void
    {
        $person = $this->person(['is_active' => true, 'is_primary' => true]);

        $this->actingAs($this->staff())
            ->get(route('people.edit', $person))
            ->assertOk()
            ->assertSee('<input type="hidden" name="is_active" value="0">', false)
            ->assertSee('<input type="hidden" name="is_primary" value="0">', false);
    }

    public function test_person_can_be_deactivated_and_demoted_from_the_edit_form(): void
    {
        $person = $this->person(['is_active' => true, 'is_primary' => true]);

        $this->actingAs($this->staff())
            ->patch(route('people.update', $person), [
                'client_id' => $person->client_id,
                'first_name' => $person->first_name,
                'last_name' => $person->last_name,
                'is_active' => '0',
                'is_primary' => '0',
            ]);

        $fresh = $person->fresh();
        $this->assertFalse($fresh->is_active);
        $this->assertFalse($fresh->is_primary);
    }
}
