<?php

namespace App\Services\Portal;

/**
 * Everything the public landing page needs to render for a single client:
 * the set of per-platform installers and the MSP branding/contact info.
 */
final class InstallerPackage
{
    /**
     * @param  array<string, InstallerInfo>  $platforms  Keyed by platform slug
     *        ('windows', 'mac', 'linux'). Missing keys mean the RMM doesn't
     *        support that platform.
     */
    public function __construct(
        public readonly string $clientName,
        public readonly string $rmmLabel,
        public readonly array $platforms,
        public readonly string $mspName,
        public readonly ?string $mspLogoUrl,
        public readonly ?string $supportEmail,
        public readonly ?string $supportPhone,
    ) {}

    /** @return array<int, string> */
    public function availablePlatforms(): array
    {
        return array_keys($this->platforms);
    }

    public function for(string $platform): ?InstallerInfo
    {
        return $this->platforms[$platform] ?? null;
    }
}
