<?php

namespace App\Services\Wiki;

use App\Enums\WikiFactVolatility;
use App\Enums\WikiRunStatus;
use App\Enums\WikiRunType;
use App\Models\Client;
use App\Models\WikiPage;
use App\Models\WikiRun;
use App\Support\WikiConfig;
use Illuminate\Support\Facades\Log;

class SyncFactWriter
{
    public function __construct(
        private readonly WikiSkeletonService $skeleton,
        private readonly WikiFactService $facts,
        private readonly WikiComposerService $composer,
    ) {}

    public function safeWriteAssetFacts(Client $client): void
    {
        try {
            $this->writeAssetFacts($client);
        } catch (\Throwable $e) {
            Log::warning('wiki: asset fact write failed', ['client_id' => $client->id ?? null, 'error' => $e->getMessage()]);
        }
    }

    public function safeWriteM365Facts(Client $client): void
    {
        try {
            $this->writeM365Facts($client);
        } catch (\Throwable $e) {
            Log::warning('wiki: m365 fact write failed', ['client_id' => $client->id ?? null, 'error' => $e->getMessage()]);
        }
    }

    /** @return WikiRun|null Null only when the wiki is disabled; failures throw (callers use the safe* wrappers). */
    public function writeAssetFacts(Client $client): ?WikiRun
    {
        if (! WikiConfig::isEnabled()) {
            return null;
        }

        return $this->run($client, function (WikiPage $infra) use ($client) {
            $count = 0;
            $assets = $client->assets()
                ->select(['id', 'client_id', 'hostname', 'os', 'cpu', 'ram_gb', 'asset_type', 'serial_number', 'ip_address'])
                ->get();
            foreach ($assets as $asset) {
                $host = $asset->hostname;
                $key = strtolower($host);
                // ram_gb is cast decimal:2 → string "32.00"; cast to int for display.
                $ramDisplay = $asset->ram_gb !== null ? (int) $asset->ram_gb : null;
                $map = array_filter([
                    "asset:{$key}:type" => $asset->asset_type ? "{$host} is a {$asset->asset_type}" : null,
                    "asset:{$key}:os" => $asset->os ? "{$host} runs {$asset->os}" : null,
                    "asset:{$key}:ram" => $ramDisplay !== null ? "{$host} has {$ramDisplay} GB RAM" : null,
                    "asset:{$key}:cpu" => $asset->cpu ? "{$host} CPU: {$asset->cpu}" : null,
                    "asset:{$key}:serial" => $asset->serial_number ? "{$host} serial number is {$asset->serial_number}" : null,
                    "asset:{$key}:ip" => $asset->ip_address ? "{$host} last reported IP {$asset->ip_address}" : null,
                ]);
                foreach ($map as $subjectKey => $statement) {
                    $volatility = str_ends_with($subjectKey, ':ip') ? WikiFactVolatility::Volatile : WikiFactVolatility::Durable;
                    $this->facts->upsertSyncFact($infra, 'assets', $subjectKey, $statement, $volatility, [['type' => 'sync', 'id' => 'assets']]);
                    $count++;
                }
            }

            $this->composer->composeSection($infra->fresh(), 'assets');

            return ['facts_written' => $count];
        }, 'infrastructure');
    }

    /** @return WikiRun|null Null only when the wiki is disabled; failures throw (callers use the safe* wrappers). */
    public function writeM365Facts(Client $client): ?WikiRun
    {
        if (! WikiConfig::isEnabled()) {
            return null;
        }

        return $this->run($client, function (WikiPage $m365) use ($client) {
            $counts = array_filter([
                'm365:ca-policies' => $this->countStatement($client->cipp_conditional_access_policies, 'conditional access policy', 'conditional access policies'),
                'm365:transport-rules' => $this->countStatement($client->cipp_transport_rules, 'mail transport rule', 'mail transport rules'),
                'm365:safe-links' => $this->countStatement($client->cipp_safe_links_policy, 'Safe Links policy', 'Safe Links policies'),
                'm365:compliance-policies' => $this->countStatement($client->cipp_compliance_policies, 'compliance policy', 'compliance policies'),
            ]);

            foreach ($counts as $subjectKey => $statement) {
                $this->facts->upsertSyncFact($m365, 'security-posture', $subjectKey, $statement, WikiFactVolatility::Volatile, [['type' => 'sync', 'id' => 'cipp']]);
            }

            $this->composer->composeSection($m365->fresh(), 'security-posture');

            return ['facts_written' => count($counts)];
        }, 'm365');
    }

    private function countStatement(mixed $items, string $singular, string $plural): ?string
    {
        if (! is_array($items)) {
            return null;
        }
        $n = count($items);

        return "M365 tenant has {$n} ".($n === 1 ? $singular : $plural);
    }

    /** Shared run scaffolding: skeleton, page lookup, wiki_runs ledger. */
    private function run(Client $client, callable $work, string $pageSlug): WikiRun
    {
        $run = WikiRun::create([
            'run_type' => WikiRunType::SyncFacts,
            'subject_type' => 'client',
            'subject_id' => $client->id,
            'status' => WikiRunStatus::Running,
            'triggered_by' => 'auto',
        ]);

        try {
            $this->skeleton->ensureForClient($client);
            $page = WikiPage::forClient($client->id)->where('slug', $pageSlug)->firstOrFail();
            $results = $work($page);
            $run->update([
                'status' => WikiRunStatus::Completed,
                'stages_completed' => ['gather', 'write_facts', 'compose'],
                'stage_results' => [$pageSlug => $results],
            ]);
        } catch (\Throwable $e) {
            $run->update([
                'status' => WikiRunStatus::Failed,
                'errors' => [['stage' => $pageSlug, 'message' => $e->getMessage()]],
            ]);
            throw $e;
        }

        return $run;
    }
}
