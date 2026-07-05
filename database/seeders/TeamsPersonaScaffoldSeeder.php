<?php

namespace Database\Seeders;

use App\Models\TeamsPersona;
use Illuminate\Database\Seeder;

/**
 * SCAFFOLD for the Teams AI-Staff Personas P1 feel-test. Hand-registers a
 * single DISABLED "Gus" persona row so the read-only AI-Staff roster
 * (Settings > Integrations) has something to render during P1. This is
 * never the finished, blessed provisioning path — P2 re-provisions Gus (and
 * any further personas) through a proper wizard.
 *
 * Manual-only: intentionally NOT wired into DatabaseSeeder::run() (matches
 * the DevDataSeeder pattern). Idempotent — safe to run repeatedly via
 * `php artisan db:seed --class=TeamsPersonaScaffoldSeeder`, always leaves
 * exactly one `gus` row.
 *
 * Dormant by construction: enabled=false, bot_client_secret=null, and
 * mcp_token_label=null (a label would have to match an existing
 * McpToken.label or TeamsPersona's `saving` hook throws — null sidesteps
 * that validation entirely since there's no token to label yet).
 */
class TeamsPersonaScaffoldSeeder extends Seeder
{
    public function run(): void
    {
        TeamsPersona::updateOrCreate(
            ['persona_key' => 'gus'],
            [
                'display_name' => 'Gus',
                'role_blurb' => 'Helpdesk-voiced AI staffer — triages tickets and chats with the team in Teams.',
                'avatar_ref' => null,
                // Placeholder — not a real Entra App ID. Harmless: the row stays
                // dormant (enabled=false) until the P2 wizard replaces it with
                // real bot credentials.
                'bot_app_id' => 'gus-scaffold-pending',
                'bot_client_secret' => null,
                'tenant_id' => null,
                'mcp_token_label' => null,
                'actor_user_id' => null,
                'conversation_refs' => null,
                'enabled' => false,
            ],
        );
    }
}
