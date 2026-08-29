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
     * Platforms we check against each RMM. Each RMM reports availability
     * per platform; unsupported platforms are dropped from the page.
     */
    private const PLATFORMS = ['windows', 'mac', 'linux'];

    /**
     * Look up a client by install token. Returns null if the token doesn't
     * resolve — or resolved but has expired (#864). An expired token is
     * indistinguishable from an invalid one on purpose: answering
     * differently would let an unauthenticated scanner distinguish a real
     * client link from a guess. A NULL expiry means a deliberate per-row
     * "no expiry" exception and stays valid.
     */
    public function findByToken(string $token): ?Client
    {
        if (empty($token)) {
            return null;
        }

        $client = Client::where('portal_install_token', $token)->first();
        if (! $client) {
            return null;
        }

        $expiresAt = $client->portal_install_token_expires_at;
        if ($expiresAt !== null && $expiresAt->isPast()) {
            Log::info('[PortalInstall] Expired token presented', [
                'client_id' => $client->id,
                'expired_at' => $expiresAt->toIso8601String(),
            ]);

            return null;
        }

        return $client;
    }

    /**
     * Platforms the client's RMM can install on, WITHOUT minting anything
     * (#857): availability is answered from configuration and read-only
     * lookups only. Empty array means no usable RMM mapping.
     *
     * @return array<int, string>
     */
    public function supportedPlatforms(Client $client): array
    {
        $rmm = $client->effectiveInstallRmm();
        if (! $rmm) {
            return [];
        }

        $platforms = [];
        foreach (self::PLATFORMS as $platform) {
            if ($this->platformSupported($client, $rmm, $platform)) {
                $platforms[] = $platform;
            }
        }

        return $platforms;
    }

    /**
     * Resolve the installer for ONE platform. For Tactical this mints a live
     * enrolment credential upstream, so callers are the mint points: the
     * explicit "show my install command" POST and the signed download
     * redirect — never a bare page GET.
     */
    public function buildInstaller(Client $client, string $platform): ?InstallerInfo
    {
        $rmm = $client->effectiveInstallRmm();
        if (! $rmm || ! in_array($platform, self::PLATFORMS, true)) {
            return null;
        }

        return $this->resolveInstaller($client, $rmm, $platform);
    }

    /**
     * Assemble the page model: branding plus the platform map. Values are
     * InstallerInfo for a platform whose credential has been minted this
     * request, null for a platform that is available but unminted.
     *
     * @param  array<string, InstallerInfo|null>  $platforms
     */
    public function package(Client $client, array $platforms): InstallerPackage
    {
        return new InstallerPackage(
            clientName: $client->name,
            rmmLabel: $this->rmmLabel($client->effectiveInstallRmm() ?? ''),
            platforms: $platforms,
            mspName: PortalConfig::companyName(),
            mspLogoUrl: PortalConfig::logoUrl(),
            supportEmail: PortalConfig::supportEmail(),
            supportPhone: PortalConfig::supportPhone(),
        );
    }

    private function platformSupported(Client $client, string $rmm, string $platform): bool
    {
        try {
            return match ($rmm) {
                'level' => app(LevelClient::class)->supportsInstall((string) $client->level_group_id, $platform),
                'ninja' => app(NinjaClient::class)->supportsInstall((int) $client->ninja_org_id, $platform),
                'tactical' => app(TacticalClient::class)->supportsInstall((string) $client->tactical_site_id, $platform),
                default => false,
            };
        } catch (\Throwable $e) {
            Log::warning('[PortalInstall] RMM availability lookup failed', [
                'client_id' => $client->id,
                'rmm' => $rmm,
                'platform' => $platform,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
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
