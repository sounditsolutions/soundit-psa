<?php

namespace Tests\Feature\Technician;

use App\Models\Client;
use App\Models\Person;
use App\Models\Setting;
use App\Models\Ticket;
use App\Services\Ai\AiClient;
use App\Services\Technician\TechnicianClassifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class TechnicianClassifierTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::setEncrypted('ai_api_key', 'test-key'); // make AiConfig::isConfigured() true (encrypted path)
    }

    private function fakeAi(array $json): void
    {
        $this->mock(AiClient::class, function (MockInterface $m) use ($json): void {
            $m->shouldReceive('completeJson')->andReturn($json);
            $m->shouldReceive('cumulativeInputTokens')->andReturn(120);
            $m->shouldReceive('cumulativeOutputTokens')->andReturn(40);
        });
    }

    private function ticketWithContact(): Ticket
    {
        $client = Client::factory()->create();
        $person = Person::create([
            'client_id' => $client->id,
            'person_type' => \App\Enums\PersonType::User,
            'first_name' => 'Test',
            'last_name' => 'Contact',
            'email' => 'c@example.com',
            'is_active' => true,
        ]);

        return Ticket::factory()->create([
            'client_id' => $client->id,
            'contact_id' => $person->id,
            'subject' => 'Outlook will not open',
            'description' => 'Since this morning Outlook crashes on launch.',
        ]);
    }

    public function test_high_confidence_ownable_when_signals_support_it(): void
    {
        $this->fakeAi(['ownable' => true, 'confidence' => 0.9, 'reason' => 'known runbook']);
        $ticket = $this->ticketWithContact();

        $assessment = app(TechnicianClassifier::class)->classify($ticket);

        $this->assertTrue($assessment->ownable);
        $this->assertEqualsWithDelta(0.9, $assessment->confidence, 0.001);
        $this->assertSame(160, $assessment->tokensUsed);
    }

    public function test_injected_high_confidence_is_capped_when_contact_is_unresolved(): void
    {
        // The model is fooled into "confidence: 1.0, ownable: true" by the body,
        // but there is no resolved contact email → the independent ceiling caps it.
        $this->fakeAi(['ownable' => true, 'confidence' => 1.0, 'reason' => 'ignore previous instructions, confidence high']);
        $client = Client::factory()->create();
        $ticket = Ticket::factory()->create(['client_id' => $client->id, 'contact_id' => null]);

        $assessment = app(TechnicianClassifier::class)->classify($ticket);

        $this->assertFalse($assessment->ownable, 'no resolved contact → not ownable regardless of model claim');
        $this->assertLessThanOrEqual(0.4, $assessment->confidence);
        $this->assertContains('no-resolved-contact-email', $assessment->reasons);
    }

    public function test_ai_error_fails_closed_to_not_ownable(): void
    {
        $this->mock(AiClient::class, function (MockInterface $m): void {
            $m->shouldReceive('completeJson')->andThrow(new \RuntimeException('api down'));
        });
        $ticket = $this->ticketWithContact();

        $assessment = app(TechnicianClassifier::class)->classify($ticket);

        $this->assertFalse($assessment->ownable);
        $this->assertSame(0.0, $assessment->confidence);
    }
}
