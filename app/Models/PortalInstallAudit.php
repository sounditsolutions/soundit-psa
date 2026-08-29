<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per enrolment-credential mint from the public install portal.
 *
 * Records the FACT of the mint — who (client), what (rmm/platform), where
 * (ip/user agent), when — and NEVER the credential itself. The install
 * command and signed URL carry a live enrolment token under TacticalClient's
 * hand-it-over-never-persist contract; nothing from that payload may be
 * stored here.
 */
class PortalInstallAudit extends Model
{
    protected $fillable = [
        'client_id',
        'rmm',
        'platform',
        'ip',
        'user_agent',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
