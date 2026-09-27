<?php

namespace Tests\Feature\ContactIntake;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/** r2 must-fix control for the staff workflow (card sPMnZ1l4, r1 review 01a0d620). */
class IntakeR2StaffTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
    }

    /** diff:13 — OFF=OFF: the staff draft endpoint never calls the provider when AI is disabled. */
    public function test_staff_draft_refuses_when_ai_globally_disabled(): void
    {
        // The 409 is the discriminator. AiClient calls Guzzle directly, so Http::fake() cannot
        // observe a provider call and is deliberately not used as evidence here.
        Setting::setValue('contact_intake_enabled', '1');
        config(['services.ai.api_key' => 'synthetic-test-key']);
        $this->assertTrue(\App\Support\AiConfig::isConfigured());
        Setting::setValue('ai_enabled', '0');
        app(\App\Services\ContactIntake\SubmissionLedger::class)->accept('website', [
            'submission_id' => (string) \Illuminate\Support\Str::uuid(), 'name' => 'Synthetic',
            'email' => 'visitor@example.test', 'message' => 'SYNTHETIC', 'submitted_at' => '2026-09-24T12:00:00Z']);
        $row = app(\App\Services\ContactIntake\SubmissionProcessor::class)
            ->process(\App\Models\ContactSubmission::latest('id')->firstOrFail()->id);
        $staff = User::factory()->admin()->create(['is_active' => true]);
        $this->actingAs($staff)->post(route('contact-intake.draft', $row->id))->assertStatus(409);
        $this->assertDatabaseMissing('contact_intake_audits', ['action' => 'draft']);
    }
}
