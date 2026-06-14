<?php

namespace Tests\Feature\Wiki;

use App\Enums\WikiComposeOutcome;
use App\Jobs\ComposeClientOverview;
use App\Models\Client;
use App\Models\Setting;
use App\Services\Wiki\WikiOverviewComposer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class WikiOverviewJobAndCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::setValue('wiki_enabled', '1');
    }

    public function test_job_invokes_composer(): void
    {
        $client = Client::factory()->create();
        $this->mock(WikiOverviewComposer::class, fn (MockInterface $m) => $m->shouldReceive('compose')->once()
            ->with(\Mockery::on(fn ($c) => $c->id === $client->id))
            ->andReturn(WikiComposeOutcome::Composed));
        (new ComposeClientOverview($client->id))->handle(app(WikiOverviewComposer::class));
    }

    public function test_job_noop_when_client_missing(): void
    {
        // A deleted client between enqueue and run must not blow up; composer never called.
        $this->mock(WikiOverviewComposer::class, fn (MockInterface $m) => $m->shouldReceive('compose')->never());
        (new ComposeClientOverview(999999))->handle(app(WikiOverviewComposer::class));
    }

    public function test_command_all_composes_every_client(): void
    {
        Client::factory()->count(3)->create();
        $this->mock(WikiOverviewComposer::class, fn (MockInterface $m) => $m->shouldReceive('compose')->times(3)
            ->andReturn(WikiComposeOutcome::Composed));
        $this->artisan('wiki:overview', ['--all' => true])->assertExitCode(0);
    }

    public function test_command_single_client_composes_one(): void
    {
        $target = Client::factory()->create();
        Client::factory()->create(); // a second client that must NOT be composed
        $this->mock(WikiOverviewComposer::class, fn (MockInterface $m) => $m->shouldReceive('compose')->once()
            ->with(\Mockery::on(fn ($c) => $c->id === $target->id))
            ->andReturn(WikiComposeOutcome::Composed));
        $this->artisan('wiki:overview', ['client' => $target->id])->assertExitCode(0);
    }

    public function test_command_no_match_fails(): void
    {
        $this->mock(WikiOverviewComposer::class, fn (MockInterface $m) => $m->shouldReceive('compose')->never());
        $this->artisan('wiki:overview', ['client' => 999999])->assertExitCode(1);
    }
}
