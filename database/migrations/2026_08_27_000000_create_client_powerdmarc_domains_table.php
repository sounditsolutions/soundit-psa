<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #689: PowerDMARC client->domain mapping, one-to-MANY. A client with
 * several mail domains maps to several PowerDMARC domains. Domain->client stays
 * <=1 (the UNIQUE on powerdmarc_domain_id). Greenfield — there are no legacy
 * per-client columns to backfill (unlike client_unifi_sites).
 *
 * powerdmarc_domain_id is the vendor's numeric domain id (DomainResource.id);
 * domain_name is a display copy resolved server-side from the live vendor
 * listing at save time, never from the submitted form.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_powerdmarc_domains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('powerdmarc_domain_id')->unique(); // a domain maps to <=1 client
            $table->string('domain_name');
            $table->timestamps();
            $table->index('client_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_powerdmarc_domains');
    }
};
