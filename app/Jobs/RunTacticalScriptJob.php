<?php

namespace App\Jobs;

use App\Models\Asset;
use App\Models\User;
use App\Services\Tactical\Actions\RunScriptAction;
use App\Services\Tactical\TacticalActionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Run one curated-library Tactical script against a single asset, asynchronously,
 * THROUGH the audited action bus (TacticalActionService + a fire-and-forget
 * RunScriptAction).
 *
 * This is the async/queued run-script path the P2 action-bus work scoped out
 * (design spec §5.1 amendment M7 → bd psa-nfqd). The bus's execute() is
 * synchronous, so a long fire-and-forget deploy — e.g. the ~10-minute Servosity
 * backup installer — can't run through it on the web request. Dispatching this
 * job keeps the request non-blocking, while the bus still routes the run through
 * the one authorize → validate → execute → audit chokepoint: the dispatch is
 * capability-gated and lands exactly one immutable tactical_action_logs row,
 * replacing the raw TacticalClient::runScriptAsync() call that bypassed the bus.
 *
 * Fire-and-forget (output=forget) is deliberate: the agent runs the installer
 * detached and PSA does not wait for output — a wait-mode run would trip the
 * client's 30s HTTP timeout on a 10-minute install and mis-audit it `offline`.
 * $tries = 1 mirrors the old no-retry fire-and-forget semantics: re-running a
 * software installer on retry is unsafe, and provisioning is reconciled
 * separately by ServosityProvisionAsset.
 */
class RunTacticalScriptJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    // A fire-and-forget PUT returns as soon as Tactical accepts it (the agent
    // runs the script detached), so it completes well inside the client's 30s
    // HTTP timeout — 60s of job headroom is ample.
    public int $timeout = 60;

    /**
     * @param  int  $assetId  PSA asset whose linked Tactical agent runs the script
     * @param  int  $scriptId  TRMM script id (tactical_scripts.tactical_script_id)
     * @param  string  $args  raw arg string, argv-tokenized by RunScriptAction
     * @param  int  $scriptTimeout  the agent-side script timeout in seconds (10..600)
     * @param  int|null  $actorId  staff user who initiated the run (audit attribution)
     * @param  string|null  $actorLabel  system attribution used when no user resolves
     * @param  int|null  $ticketId  optional ticket link recorded on the audit row
     */
    public function __construct(
        private readonly int $assetId,
        private readonly int $scriptId,
        private readonly string $args,
        private readonly int $scriptTimeout,
        private readonly ?int $actorId = null,
        private readonly ?string $actorLabel = null,
        private readonly ?int $ticketId = null,
    ) {}

    public function handle(TacticalActionService $bus): void
    {
        $asset = Asset::with('tacticalAsset')->find($this->assetId);

        if (! $asset) {
            Log::warning('[RunTacticalScriptJob] asset no longer exists; skipping', [
                'asset_id' => $this->assetId,
                'script_id' => $this->scriptId,
            ]);

            return;
        }

        // Prefer the initiating user for attribution; fall back to the system
        // label. The bus denies a null-actor + null-label dispatch, so exactly
        // one of them must resolve here.
        $actor = $this->actorId !== null ? User::find($this->actorId) : null;
        $label = $actor !== null ? null : ($this->actorLabel ?? 'system');

        $result = $bus->dispatch(
            new RunScriptAction(fireAndForget: true),
            $asset,
            $actor,
            [
                'tactical_script_id' => $this->scriptId,
                'args' => $this->args,
                'timeout' => $this->scriptTimeout,
            ],
            null,
            $label,
            $this->ticketId,
        );

        if (! $result->isOk()) {
            Log::warning('[RunTacticalScriptJob] bus reported a non-ok dispatch', [
                'asset_id' => $asset->id,
                'script_id' => $this->scriptId,
                'status' => $result->status,
                'message' => $result->message,
            ]);
        }
    }
}
