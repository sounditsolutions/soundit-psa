<?php

namespace App\Services\Portal;

use App\Models\Client;
use App\Services\Level\LevelClient;
use App\Services\Ninja\NinjaClient;
use App\Services\Tactical\TacticalClient;
use App\Support\PortalConfig;
use Illuminate\Support\Facades\Log;

class PortalInstallService
{
    /**
     * Platforms we check against each RMM. Each RMM returns ?InstallerInfo
     * per platform; nulls are dropped from the final package.
     */
    private const PLATFORMS = ['windows', 'mac', 'linux'];

    /**
     * Look up a client by install token, then build its InstallerPackage.
     * Returns null if the token doesn't resolve.
     */
    public function findByToken(string $token): ?Client
    {
        if (empty($token)) {
            return null;
        }

        return Client::where('portal_install_token', $token)->first();
    }

    /**
     * Build the package for a client. Returns null if the client has no
     * usable RMM mapping or the chosen RMM returned no installers.
     */
    public function buildPackage(Client $client): ?InstallerPackage
    {
        $rmm = $client->effectiveInstallRmm();
        if (! $rmm) {
            return null;
        }

        $platforms = [];
        foreach (self::PLATFORMS as $platform) {
            $info = $this->resolveInstaller($client, $rmm, $platform);
            if ($info !== null) {
                $platforms[$platform] = $info;
            }
        }

        if (empty($platforms)) {
            return null;
        }

        return new InstallerPackage(
            clientName: $client->name,
            rmmLabel: $this->rmmLabel($rmm),
            platforms: $platforms,
            mspName: PortalConfig::companyName(),
            mspLogoUrl: PortalConfig::logoUrl(),
            supportEmail: PortalConfig::supportEmail(),
            supportPhone: PortalConfig::supportPhone(),
        );
    }

    private function resolveInstaller(Client $client, string $rmm, string $platform): ?InstallerInfo
    {
        try {
            return match ($rmm) {
                'level' => app(LevelClient::class)->getInstallerInfo((string) $client->level_group_id, $platform),
                'ninja' => app(NinjaClient::class)->getInstallerInfo((int) $client->ninja_org_id, $platform),
                'tactical' => app(TacticalClient::class)->getInstallerInfo((string) $client->tactical_site_id, $platform),
                default => null,
            };
        } catch (\Throwable $e) {
            Log::warning('[PortalInstall] RMM installer lookup failed', [
                'client_id' => $client->id,
                'rmm' => $rmm,
                'platform' => $platform,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function rmmLabel(string $rmm): string
    {
        return match ($rmm) {
            'ninja' => 'NinjaRMM Agent',
            'level' => 'Level Agent',
            'tactical' => 'Tactical RMM Agent',
            default => 'Management Agent',
        };
    }
}
