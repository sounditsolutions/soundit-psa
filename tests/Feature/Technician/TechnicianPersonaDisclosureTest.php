<?php

namespace Tests\Feature\Technician;

use App\Models\Setting;
use App\Models\User;
use App\Services\Technician\MissingDisclosureException;
use App\Services\Technician\TechnicianDisclosure;
use App\Support\TechnicianConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TechnicianPersonaDisclosureTest extends TestCase
{
    use RefreshDatabase;

    public function test_actor_name_falls_back_then_honours_config(): void
    {
        $first = User::factory()->create(['name' => 'First Admin']);
        $chet = User::factory()->create(['name' => 'Chet']);

        $this->assertSame('First Admin', TechnicianConfig::aiActorName());

        Setting::setValue('triage_system_user_id', (string) $chet->id);
        $this->assertSame('Chet', TechnicianConfig::aiActorName());
    }

    public function test_disclosure_uses_the_configured_name_but_sentinel_is_name_independent(): void
    {
        $disclosure = new TechnicianDisclosure;

        $chet = $disclosure->withDisclosure('Thanks for reaching out.', 'Chet');
        $robin = $disclosure->withDisclosure('Thanks for reaching out.', 'Robin');

        $this->assertStringContainsString('— Sent by Chet, an AI assistant for our team.', $chet);
        $this->assertStringContainsString('— Sent by Robin, an AI assistant for our team.', $robin);

        // The pre-send scan keys on the sentinel, not the name — both pass.
        $disclosure->assertPresent($chet);
        $disclosure->assertPresent($robin);
        $this->assertTrue(true);
    }

    public function test_blank_name_uses_a_safe_default(): void
    {
        $out = (new TechnicianDisclosure)->withDisclosure('Hi.', '   ');

        $this->assertStringContainsString('— Sent by our virtual assistant, an AI assistant for our team.', $out);
    }

    public function test_assert_present_rejects_a_human_signed_body(): void
    {
        $this->expectException(MissingDisclosureException::class);

        (new TechnicianDisclosure)->assertPresent('Thanks,\nJohn from the help desk');
    }
}
