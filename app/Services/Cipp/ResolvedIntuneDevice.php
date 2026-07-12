<?php

namespace App\Services\Cipp;

use App\Models\Asset;

final readonly class ResolvedIntuneDevice
{
    public function __construct(
        public Asset $asset,
        public string $deviceId,
        public string $hostname,
    ) {}
}
