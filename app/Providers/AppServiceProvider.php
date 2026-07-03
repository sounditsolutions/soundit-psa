<?php

namespace App\Providers;

use App\Auth\PortalUserProvider;
use App\Models\Asset;
use App\Models\Invoice;
use App\Models\Person;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Observers\AssetObserver;
use App\Observers\InvoiceObserver;
use App\Observers\PersonObserver;
use App\Observers\TicketNoteObserver;
use App\Observers\TicketObserver;
use App\Services\Agent\SignificanceGate;
use App\Services\Agent\TechnicianAgent;
use App\Services\Cipp\CippClient;
use App\Services\Cipp\CippMcpClient;
use App\Services\Cipp\CippRestWriteClient;
use App\Services\Graph\GraphClient;
use App\Services\Level\LevelClient;
use App\Services\Mesh\MeshClient;
use App\Services\Ninja\NinjaClient;
use App\Services\Tactical\TacticalClient;
use App\Support\AppTimezone;
use App\Support\CippConfig;
use App\Support\MeshConfig;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use SocialiteProviders\Manager\SocialiteWasCalled;
use SocialiteProviders\Microsoft\MicrosoftExtendSocialite;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Bind SignificanceGate to a Haiku-configured instance for production use.
        // Tests override this with $this->mock(SignificanceGate::class).
        $this->app->bind(SignificanceGate::class, fn () => SignificanceGate::haiku());

        // Bind TechnicianAgent to an Opus-configured instance (AgentConfig::agentModel()) for production.
        // Tests inject a mock AiClient directly (new TechnicianAgent($mock)) or rebind this.
        $this->app->bind(TechnicianAgent::class, fn () => TechnicianAgent::withConfiguredModel());

        // Bind TeamsReplyService to an Opus-configured instance for production (Teams E2a).
        // Tests override this with $this->mock(TeamsReplyService::class).
        $this->app->bind(\App\Services\Teams\TeamsReplyService::class, fn () => \App\Services\Teams\TeamsReplyService::withConfiguredModel());

        // Bind ChimeInGate to a Haiku-configured instance for production (Teams E2b ambient).
        // Tests inject a mock AiClient directly (new ChimeInGate($mock)) or mock the gate.
        $this->app->bind(\App\Services\Teams\ChimeInGate::class, fn () => \App\Services\Teams\ChimeInGate::haiku());

        $this->app->singleton(NinjaClient::class, function ($app) {
            $config = config('services.ninja');

            try {
                $config['client_id'] = Setting::getValue('ninja_client_id') ?? $config['client_id'];

                $encSecret = Setting::getValue('ninja_client_secret');
                $config['client_secret'] = $encSecret
                    ? Setting::getEncrypted('ninja_client_secret')
                    : $config['client_secret'];
            } catch (\Throwable) {
                // Settings table may not exist yet (fresh deploy)
            }

            return new NinjaClient(
                $config,
                $app->make(\Illuminate\Contracts\Cache\Repository::class),
            );
        });

        $this->app->singleton(GraphClient::class, function ($app) {
            return new GraphClient(
                config('services.graph'),
                $app->make(\Illuminate\Contracts\Cache\Repository::class),
            );
        });

        $this->app->singleton(LevelClient::class, function ($app) {
            $config = config('services.level');

            try {
                $config['api_key'] = Setting::settingOrConfig('level_api_key', 'services.level.api_key', true);
            } catch (\Throwable $e) {
                Log::warning('[LevelClient] Could not load credentials from settings', ['error' => $e->getMessage()]);
            }

            return new LevelClient($config);
        });

        $this->app->singleton(MeshClient::class, function () {
            return new MeshClient([
                'api_key' => MeshConfig::get('api_key'),
                'base_url' => MeshConfig::get('base_url'),
            ]);
        });

        // TacticalClient reads its (encrypted) config from Settings in its own
        // constructor, so a plain zero-arg singleton mirrors NinjaClient/MeshClient.
        $this->app->singleton(TacticalClient::class);

        $this->app->singleton(CippClient::class, function ($app) {
            return new CippClient(
                [
                    'api_url' => CippConfig::get('api_url'),
                    'tenant_id' => CippConfig::get('tenant_id'),
                    'client_id' => CippConfig::get('client_id'),
                    'client_secret' => CippConfig::get('client_secret'),
                    'application_id' => CippConfig::get('application_id'),
                ],
                $app->make(\Illuminate\Contracts\Cache\Repository::class),
            );
        });

        $this->app->singleton(CippMcpClient::class, function ($app) {
            return new CippMcpClient(
                [
                    'api_url' => CippConfig::get('api_url'),
                    'tenant_id' => CippConfig::get('tenant_id'),
                    'client_id' => CippConfig::get('mcp_client_id'),
                    'client_secret' => CippConfig::get('mcp_client_secret'),
                ],
                $app->make(\Illuminate\Contracts\Cache\Repository::class),
            );
        });

        $this->app->singleton(CippRestWriteClient::class, function ($app) {
            return new CippRestWriteClient(
                [
                    'api_url' => CippConfig::get('api_url'),
                    'tenant_id' => CippConfig::get('tenant_id'),
                    'client_id' => CippConfig::get('client_id'),
                    'client_secret' => CippConfig::get('client_secret'),
                    'application_id' => CippConfig::get('application_id'),
                ],
                $app->make(\Illuminate\Contracts\Cache\Repository::class),
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Paginator::useBootstrapFive();

        Event::listen(SocialiteWasCalled::class, MicrosoftExtendSocialite::class);

        // Portal guard + portal password broker resolve people through this
        // provider, which only ever returns Active-stage clients' contacts.
        // Keeps prospects structurally out of login and the reset broker.
        Auth::provider('portal-eloquent', function ($app, array $config) {
            return new PortalUserProvider($app['hash'], $config['model']);
        });

        // Register model observers
        Asset::observe(AssetObserver::class);
        Invoice::observe(InvoiceObserver::class);
        Person::observe(PersonObserver::class);
        Ticket::observe(TicketObserver::class);
        TicketNote::observe(TicketNoteObserver::class);

        // Register timezone display helpers.
        // DB always stores UTC; toAppTz() converts to the configured display timezone.
        // Resolves the timezone at call time (not boot time) so changing the setting
        // takes effect on the next request without process restart.
        try {
            Carbon::macro('toAppTz', fn () => $this->copy()->setTimezone(AppTimezone::get()));
            View::share('appTz', AppTimezone::get());
        } catch (\Throwable) {
            // Settings table not yet available (fresh deploy before first migrate).
            Carbon::macro('toAppTz', fn () => $this->copy());
            View::share('appTz', 'UTC');
        }
    }
}
