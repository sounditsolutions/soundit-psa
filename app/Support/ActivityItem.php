<?php

namespace App\Support;

use Carbon\Carbon;

class ActivityItem
{
    public function __construct(
        public readonly object $model,
        public readonly string $type,
        public readonly Carbon $timestamp,
        public readonly string $url,
    ) {}
}
