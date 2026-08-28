<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-client PowerDMARC API keys (ops 440/442). An MSSP/partner account key is
 * refused (403) on the end-user platform routes PowerDmarcClient speaks
 * (/domains, dns-timeline, aggregate reports); client-portal tokens minted via
 * the MSSP portal's Login-as-client are the working theory for those reads. The
 * account-level key on Settings > Integrations stays exactly as it is — this
 * table only adds an optional per-client override, keyed one key per client.
 *
 * api_key is TEXT because it stores the CIPHERTEXT of Laravel's `encrypted`
 * model cast, and the plaintext itself is a vendor JWT (>1300 chars today,
 * vendor-free to grow — the #751 lesson).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_powerdmarc_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('api_key');
            $table->timestamp('verified_at')->nullable(); // last successful per-client Test Connection
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_powerdmarc_keys');
    }
};
