<?php

namespace App\Services\Technician\Notify;

final class TechnicianDigest
{
    public function __construct(
        public readonly string $subject,
        public readonly string $body,
        public readonly bool $isEmpty,
    ) {}
}
