<?php

namespace App\Console\Commands;

use App\Services\Mesh\MeshAllowRuleReaper;
use App\Support\MeshConfig;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * #1018 — expire the allow rules Mesh will not expire itself.
 *
 * Scheduled hourly (routes/console.php). Exits FAILURE when anything was left live, so a silent
 * scheduler does not become the reason an allow rule outlives its approval.
 */
class MeshReapAllowRules extends Command
{
    protected $signature = 'mesh:reap-allow-rules';

    protected $description = 'Delete Mesh allow rules past their PSA-recorded expiry, proving absence by re-read';

    public function handle(MeshAllowRuleReaper $reaper): int
    {
        if (! MeshConfig::isConfigured()) {
            // #6212: the schedule runs this command whenever Mesh is on and
            // a key is stored, usable or not, so a blank or unusable stored
            // key is logged here every hour, never skipped silently. #6213:
            // a stored '0' is set but unusable, not 'not configured'.
            $text = MeshConfig::notConfiguredText('Mesh is not configured. Add the API key in Settings → Integrations.');
            Log::warning('[MeshReapAllowRules] '.$text.' No allow rules were reaped; expired rules are not being removed and may still be live upstream.');
            $this->error($text);

            return self::FAILURE;
        }

        $counts = $reaper->reap();

        $this->info(sprintf(
            'Examined %d expired allow rule(s): %d reaped, %d unresolved, %d failed.',
            $counts['examined'],
            $counts['reaped'],
            $counts['unresolved'],
            $counts['failed'],
        ));

        return ($counts['unresolved'] + $counts['failed']) > 0 ? self::FAILURE : self::SUCCESS;
    }
}
