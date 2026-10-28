<?php

namespace App\Services\Signing;

use App\Models\SignableDocument;
use App\Models\SigningSnapshot;
use RuntimeException;

class SigningAcceptanceStale extends RuntimeException
{
    public function __construct(public readonly array $rows, public readonly SignableDocument $proposal, public readonly SigningSnapshot $acceptance)
    {
        parent::__construct('signing_acceptance_stale');
    }
}
