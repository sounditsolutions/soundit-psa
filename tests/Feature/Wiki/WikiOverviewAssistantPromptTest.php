<?php

namespace Tests\Feature\Wiki;

use App\Enums\WikiPageKind;
use App\Models\AssistantConversation;
use App\Models\Client;
use App\Models\Setting;
use App\Models\User;
use App\Models\WikiPage;
use App\Services\Assistant\AssistantService;
use App\Services\Wiki\WikiSkeletonService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Locks the chat-side (Assistant) client-context wiring to the same §4.6
 * injection point as triage. There is no public seam: AssistantService's only
 * prompt-building entry is the private buildSystemPrompt(), reached solely via
 * sendMessage(), which does `new AiClient` (not container-resolved) and would
 * make a live API call. So we invoke buildSystemPrompt() via reflection to
 * assert the wiring without coupling to the AI transport.
 */
class WikiOverviewAssistantPromptTest extends TestCase
{
    use RefreshDatabase;

    private function clientConversation(Client $client): AssistantConversation
    {
        return AssistantConversation::create([
            'user_id' => User::factory()->create()->id,
            'context_type' => 'client',
            'context_id' => $client->id,
        ]);
    }

    private function overview(Client $client, string $body, bool $composed = true): void
    {
        $page = WikiPage::factory()->forClient($client)->create([
            'slug' => 'overview', 'kind' => WikiPageKind::Overview, 'body_md' => $body,
        ]);
        if ($composed) {
            $page->update(['meta' => ['composed_at' => now()->toIso8601String()]]);
        }
    }

    private function buildPrompt(AssistantConversation $conversation): string
    {
        $method = new ReflectionMethod(AssistantService::class, 'buildSystemPrompt');
        $method->setAccessible(true);

        // psa-uw2o.2: $hasClient is now passed in rather than re-derived inside
        // the method, so the prompt and the tool list cannot disagree. Mirror
        // how sendMessage computes it.
        return $method->invoke(new AssistantService, $conversation, $conversation->resolveClientId() !== null);
    }

    public function test_client_prompt_injects_composed_overview(): void
    {
        Setting::setValue('wiki_enabled', '1');
        $client = Client::factory()->create(['site_notes' => 'Legacy notes: gateway 10.0.0.1.']);
        $this->overview($client, str_repeat('Windows-shop; DC01 on Server 2022; standard onboarding. ', 6));

        $prompt = $this->buildPrompt($this->clientConversation($client));

        $this->assertStringContainsString('## Client Environment Overview', $prompt);
        $this->assertStringNotContainsString('Legacy notes', $prompt);
    }

    public function test_client_prompt_falls_back_to_site_notes_when_wiki_off(): void
    {
        Setting::setValue('wiki_enabled', '0');
        $client = Client::factory()->create(['site_notes' => 'Legacy notes: gateway 10.0.0.1.']);
        $this->overview($client, str_repeat('Windows-shop; DC01 on Server 2022; standard onboarding. ', 6));

        $prompt = $this->buildPrompt($this->clientConversation($client));

        $this->assertStringContainsString('## Client Site Notes', $prompt);
        $this->assertStringContainsString('Legacy notes', $prompt);
        $this->assertStringNotContainsString('## Client Environment Overview', $prompt);
    }

    public function test_client_prompt_falls_back_to_site_notes_when_overview_uncomposed(): void
    {
        Setting::setValue('wiki_enabled', '1');
        $client = Client::factory()->create(['site_notes' => 'Legacy notes: gateway 10.0.0.1.']);
        $this->overview($client, WikiSkeletonService::OVERVIEW_PLACEHOLDER_BODY, composed: false);

        $prompt = $this->buildPrompt($this->clientConversation($client));

        $this->assertStringContainsString('## Client Site Notes', $prompt);
        $this->assertStringContainsString('Legacy notes', $prompt);
        $this->assertStringNotContainsString('## Client Environment Overview', $prompt);
    }
}
