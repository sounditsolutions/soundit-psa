<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-client PowerDMARC API key (ops 440/442): a client-portal token that
 * authorizes the end-user platform routes the account-level MSSP key is
 * refused on. One key per client (UNIQUE on client_id); absence means the
 * client's reads fall back to the account-level key from Settings.
 *
 * verified_at records the last successful per-client Test Connection — a
 * saved-but-never-verified key reads as untested, not as working.
 */
class ClientPowerdmarcKey extends Model
{
    protected $fillable = ['client_id', 'api_key', 'verified_at'];

    /**
     * The `encrypted` cast DECRYPTS api_key on toArray()/toJson(), so the
     * attribute is hidden outright — no serialization path may carry the
     * plaintext token (same guard as TeamsPersona::bot_client_secret).
     */
    protected $hidden = ['api_key'];

    protected $casts = [
        'api_key' => 'encrypted',
        'verified_at' => 'datetime',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
