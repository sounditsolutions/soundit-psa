<?php

namespace App\Services\Cipp;

use App\Models\License;
use App\Models\LicenseType;

final readonly class ResolvedCippLicense
{
    public function __construct(
        public LicenseType $licenseType,
        public License $license,
        public string $skuId,
    ) {}
}
