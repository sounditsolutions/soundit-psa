<?php

namespace App\Services\Assistant;

use App\Models\Asset;
use App\Models\Client;
use App\Models\Ticket;
use Illuminate\Database\Eloquent\Model;

/**
 * The `linked_ids` block served by get_client, get_asset and get_ticket_detail.
 *
 * Why it exists: an agent answered "no Huntress org" and "no ScreenConnect
 * session" for records that HAD both ids, because no read tool published them.
 * Every foreign-key and external-integration id column on the row is emitted
 * here, and a column holding NULL is emitted as an explicit null meaning
 * "not mapped" — never omitted, so an agent can tell "not mapped" from
 * "this tool does not say". The one exception is tickets.parent_ticket_id,
 * which is also null when the parent sits outside the ticket's client fence
 * (see forTicket).
 *
 * The column lists are hand-maintained ON PURPOSE and pinned by
 * tests/Feature/Assistant/LinkedIdsContractTest, which reads the live schema:
 * every *_id column and every declared foreign key on clients/assets/tickets
 * must appear in its list or in DENYLIST with a reason. A new column added
 * without either fails that test, so a new integration cannot silently go
 * unpublished again — and a new SECRET-shaped column cannot silently be
 * published either, because someone has to decide which list it goes in.
 *
 * Lists below were taken from the migrated schema at 60cf6ca6 (which agrees
 * with the models' fillable/casts), not from the card's prose list.
 * assets.litsrmm_device_id (migration 2026_09_30_120000, #4485) and clients.default_contract_id (migration 2026_10_03_000001) were added after it; LinkedIdsContractTest holds every list to the schema the suite migrates.
 */
final class LinkedIds
{
    /** clients: every *_id column plus the FK/identifier columns not named *_id. */
    public const CLIENT_COLUMNS = [
        'halo_id',
        'halo_ninja_org_id',
        'ninja_org_id',
        'level_group_id',
        'litsrmm_client_id',
        'qbo_customer_id',
        'mesh_customer_id',
        // The CIPP tenant key. Named *_domain, but it is the identifier every
        // CIPP call is addressed by — the card's "CIPP tenant_id".
        'cipp_tenant_domain',
        'cipp_sync_group_id',
        'stripe_customer_id',
        'huntress_organization_id',
        'servosity_company_id',
        'controld_org_id',
        'zorus_customer_id',
        'appriver_customer_id',
        'printix_tenant_id',
        'tactical_site_id',
        'comet_group_id',
        // Legacy one-to-one UniFi mapping; the live mapping is the
        // client_unifi_sites pivot, served separately as unifi_sites.
        'unifi_site_id',
        'unifi_host_id',
        'autoelevate_company_id',
        'primary_tech_id',
        'reseller_id',
        // The contract new time belongs to when several are active (card I3EvQKUV).
        'default_contract_id',
        'site_notes_updated_by',
        'credentials_updated_by',
    ];

    /** assets: every *_id column plus the FK columns. */
    public const ASSET_COLUMNS = [
        'halo_id',
        'client_id',
        'ninja_id',
        'level_id',
        'tactical_asset_id',
        'controld_device_id',
        'zorus_endpoint_id',
        'm365_device_id',
        'screenconnect_session_id',
        'comet_device_id',
        'servosity_dr_backup_id',
        'autoelevate_computer_id',
        'litsrmm_device_id',
        'merged_into_asset_id',
    ];

    /** tickets: every *_id column plus the FK columns, minus DENYLIST. */
    public const TICKET_COLUMNS = [
        'halo_id',
        'client_id',
        'contact_id',
        'parent_ticket_id',
        'assignee_id',
        'created_by',
        'contract_id',
        'halo_contract_id',
        'category_id',
    ];

    /**
     * Columns deliberately NOT published, table => column => reason. A reason
     * is mandatory (the contract test refuses an empty one): "ids yes, secrets
     * never", and anything doubtful sits here until someone rules otherwise.
     *
     * Secret columns that are not *_id shaped (clients.credentials,
     * portal_install_token, comet_backup_password, controld_provisioning_code,
     * controld_deactivation_pin, assets.servosity_backup_password) are never in
     * any list above and never read by this class.
     */
    public const DENYLIST = [
        'clients' => [
            'carbon_customer_id' => 'Carbon hardware customer mapping (sL0cPqhk PR 1). Staff-side only in this PR; publishing it on assistant reads changes the get_client description and is left to its own decision.',
        ],
        'tickets' => [
            'hdb_press_id' => 'HelpDesk Buttons press uuid: the key the HDB API reader fetches after note authorization (HdbReportReader::fetchForNote). Authority to fetch flows only from a PSA-captured NOTE (HdbReportFetchAuthorizer, #1359); the ticket column is a last-note-wins display cache that is "never an authority". Publishing it on a read invites paste-to-fetch, so it stays off until ruled otherwise.',
        ],
    ];

    /** @return array<string, mixed> */
    public static function forClient(Client $client): array
    {
        return self::columns($client, self::CLIENT_COLUMNS) + [
            // The live UniFi mapping (one client -> many sites, psa-jpygj).
            'unifi_sites' => $client->unifiSites()->orderBy('id')
                ->get(['unifi_site_id', 'unifi_host_id'])
                ->map(fn ($s) => ['unifi_site_id' => $s->unifi_site_id, 'unifi_host_id' => $s->unifi_host_id])
                ->values()->all(),
            'powerdmarc_domain_ids' => $client->powerdmarcDomains()->orderBy('id')
                ->pluck('powerdmarc_domain_id')->map(fn ($v) => (int) $v)->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    public static function forAsset(Asset $asset): array
    {
        return self::columns($asset, self::ASSET_COLUMNS) + [
            // assets.tactical_asset_id is a LOCAL key to tactical_assets; the
            // vendor's own agent id lives on that row (DeviceAbsenceVerifier).
            'tactical_agent_id' => $asset->tacticalAsset()->value('agent_id'),
            'user_person_ids' => $asset->users()->orderBy('people.id')->pluck('people.id')->map(fn ($v) => (int) $v)->values()->all(),
        ];
    }

    /**
     * Ticket columns plus its direct relations. CLIENT-SCOPED READS ONLY: the
     * caller must never serve this on the unscoped cross-client read
     * (client_scoped_detail) until that boundary is ruled.
     *
     * There is deliberately no invoice_ids key: no table links an invoice to a
     * ticket at this schema (invoices hang off client + contract; prepay
     * ledger rows key to a ticket note but carry invoice_id only on invoice
     * deposits, never on ticket-time debits). An always-empty list would read
     * as "this ticket was never billed", which nobody measured.
     *
     * @return array<string, mixed>
     */
    public static function forTicket(Ticket $ticket): array
    {
        $ids = fn ($query, string $col) => $query->orderBy($col)->pluck($col)->map(fn ($v) => (int) $v)->values()->all();

        $out = self::columns($ticket, self::TICKET_COLUMNS);
        // The mirror of the child_ticket_ids fence: a parent link is not
        // re-checked when a ticket moves client, so the raw column can name
        // another client's ticket. Named only when it passes the same fence.
        $parent = $out['parent_ticket_id'] === null ? null
            : Ticket::automationVisible()->whereKey($out['parent_ticket_id'])->where('client_id', $ticket->client_id)->value('tickets.id');
        $out['parent_ticket_id'] = $parent === null ? null : (int) $parent;

        return $out + [
            'asset_ids' => $ids($ticket->assets()->toBase(), 'assets.id'),
            'primary_asset_id' => ($p = $ticket->assets()->wherePivot('is_primary', true)->orderBy('assets.id')->first(['assets.id'])) ? (int) $p->id : null,
            'phone_call_ids' => $ids(\App\Models\PhoneCall::where('ticket_id', $ticket->id), 'id'),
            'email_ids' => $ids(\App\Models\Email::where('ticket_id', $ticket->id), 'id'),
            // Staged actions are technician_runs rows, every state.
            'staged_action_ids' => $ids(\App\Models\TechnicianRun::where('ticket_id', $ticket->id), 'id'),
            // Same-client children only: a scoped read must not name a ticket
            // outside its own client fence, even as a bare id.
            'child_ticket_ids' => $ids(Ticket::automationVisible()->where('parent_ticket_id', $ticket->id)->where('client_id', $ticket->client_id), 'tickets.id'),
        ];
    }

    /**
     * Every listed column, null included. A column the loaded model does not
     * carry (a narrowed select upstream) is re-read rather than defaulted to
     * null, so a partial load can never masquerade as "not mapped".
     *
     * @param  list<string>  $columns
     * @return array<string, mixed>
     */
    private static function columns(Model $model, array $columns): array
    {
        $missing = array_diff($columns, array_keys($model->getAttributes()));
        if ($missing !== []) {
            $model = $model->newQueryWithoutScopes()->whereKey($model->getKey())->firstOrFail();
        }

        $out = [];
        foreach ($columns as $column) {
            $out[$column] = $model->getAttribute($column);
        }

        return $out;
    }
}
