<?php

namespace App\Services\Triage;

use App\Models\Asset;
use App\Models\Client;
use App\Models\Person;

class ContactResolution
{
    public function __construct(
        public readonly ?Person $person = null,
        public readonly ?Client $client = null,
        public readonly ?Asset $asset = null,
        public readonly string $method = 'unknown',
    ) {}

    public function toArray(): array
    {
        return [
            'person_id' => $this->person?->id,
            'person_name' => $this->person?->full_name,
            'client_id' => $this->client?->id,
            'client_name' => $this->client?->name,
            'asset_id' => $this->asset?->id,
            'asset_hostname' => $this->asset?->hostname,
            'method' => $this->method,
        ];
    }
}
