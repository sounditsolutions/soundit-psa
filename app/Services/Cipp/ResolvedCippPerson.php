<?php

namespace App\Services\Cipp;

use App\Models\Person;

final readonly class ResolvedCippPerson
{
    public function __construct(
        public Person $person,
        public string $userId,
        public string $userPrincipalName,
    ) {}
}
