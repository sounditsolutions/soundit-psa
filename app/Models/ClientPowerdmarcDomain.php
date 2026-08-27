<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pivot mapping a PSA client to a PowerDMARC domain (issue #689: one client ->
 * MANY domains, for clients with several mail domains). A domain maps to at
 * most one client — the UNIQUE on powerdmarc_domain_id enforces it.
 * powerdmarc_domain_id is the vendor's numeric domain id; domain_name is the
 * display copy resolved server-side from the live vendor listing at save time.
 */
class ClientPowerdmarcDomain extends Model
{
    protected $fillable = ['client_id', 'powerdmarc_domain_id', 'domain_name'];

    protected $casts = [
        'powerdmarc_domain_id' => 'integer',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
