<?php

namespace Database\Seeders;

use App\Models\Alert;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Contract;
use App\Models\ContractDocument;
use App\Models\ContractorTimeTransaction;
use App\Models\Email;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\License;
use App\Models\LicenseType;
use App\Models\Person;
use App\Models\PersonEmail;
use App\Models\PhoneCall;
use App\Models\PrepayTransaction;
use App\Models\RecurringInvoiceProfile;
use App\Models\RecurringInvoiceProfileLine;
use App\Models\Setting;
use App\Models\Sku;
use App\Models\TacticalAsset;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Models\TriageRun;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Generates a complete demo dataset for "BlueTier IT Solutions" — a fictional
 * MSP — covering every feature surfaced in the PSA UI: clients, people, assets,
 * tickets (multiple sources), AI triage, voicemails with transcripts, merged
 * tickets, alerts, contracts, recurring billing, invoices, prepay, contractor
 * time pool, install link, contract documents, and licensing.
 *
 * SAFETY: aborts if APP_ENV != local or DB_DATABASE doesn't contain "_dev".
 */
class DevDataSeeder extends Seeder
{
    /** @var array<string, mixed> */
    private array $ctx = [];

    private const MSP_NAME = 'BlueTier IT Solutions';

    private const MSP_DOMAIN = 'bluetier-it.example.com';

    private const MSP_SUPPORT_EMAIL = 'support@bluetier-it.example.com';

    private const MSP_SUPPORT_PHONE = '(555) 020-3300';

    public function run(): void
    {
        $this->guardEnvironment();

        $this->truncateTables();
        $this->seedSettings();
        $this->seedUsers();
        $this->seedSkus();
        $this->seedLicenseTypes();
        $this->seedClients();
        $this->seedPeople();
        $this->seedAssets();
        $this->seedTacticalAssets();
        $this->seedLicenses();
        $this->seedContracts();
        $this->seedContractAssignments();
        $this->seedContractDocuments();
        $this->seedRecurringProfiles();
        $this->seedInvoices();
        $this->seedPrepayTransactions();
        $this->seedContractorTime();
        $this->seedTickets();
        $this->seedTicketNotes();
        $this->seedTriageRuns();
        $this->seedPhoneCalls();
        $this->seedEmails();
        $this->seedAlerts();

        $this->command?->info('Demo dataset loaded: BlueTier IT Solutions');
    }

    private function guardEnvironment(): void
    {
        $env = config('app.env');
        $db = config('database.connections.'.config('database.default').'.database');
        if ($env !== 'local' || ! str_contains((string) $db, '_dev')) {
            throw new \RuntimeException(
                "Refusing to seed: APP_ENV={$env}, DB={$db}. Demo data must only run on local dev (DB must contain '_dev')."
            );
        }
    }

    private function truncateTables(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        $tables = [
            'alerts',
            'contract_activities',
            'contract_assignment_rules',
            'contract_documents',
            'contract_asset', 'contract_license', 'contract_person',
            'contractor_time_transactions',
            'triage_runs',
            'ticket_notes', 'ticket_asset',
            'emails', 'phone_calls',
            'tickets',
            'tactical_assets',
            'asset_person',
            'assets',
            'invoice_lines', 'invoices',
            'recurring_invoice_profile_lines', 'recurring_invoice_profiles',
            'prepay_transactions',
            'licenses', 'license_types',
            'contracts',
            'person_emails', 'people',
            'clients',
            'skus',
            'users',
        ];
        foreach ($tables as $t) {
            DB::table($t)->truncate();
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    private function seedSettings(): void
    {
        $settings = [
            'app_company_name' => self::MSP_NAME,
            'app_timezone' => 'America/Los_Angeles',
            'portal_enabled' => '1',
            'portal_company_name' => self::MSP_NAME,
            'portal_support_email' => self::MSP_SUPPORT_EMAIL,
            'portal_support_phone' => self::MSP_SUPPORT_PHONE,
            'portal_billing_label' => 'Online Billing Portal',
            'auto_close_resolved_days' => '7',
            'billing_skip_zero_invoices' => '0',
            'triage_enabled' => '1',
            'triage_auto' => '0',
            'triage_auto_review' => '0',
        ];
        foreach ($settings as $k => $v) {
            Setting::updateOrCreate(['key' => $k], ['value' => $v]);
        }
    }

    private function seedUsers(): void
    {
        $users = [
            ['name' => 'Alex Morgan',    'email' => 'alex@bluetier-it.example.com',  'role' => 'admin',      'is_contractor' => false],
            ['name' => 'Priya Shah',     'email' => 'priya@bluetier-it.example.com', 'role' => 'technician', 'is_contractor' => false],
            ['name' => 'Devon Carter',   'email' => 'devon@bluetier-it.example.com', 'role' => 'technician', 'is_contractor' => false],
            ['name' => 'Sam Whitaker',   'email' => 'sam@bluetier-it.example.com',   'role' => 'technician', 'is_contractor' => false],
            ['name' => 'Riley Okafor',   'email' => 'riley@contractor.example.com',  'role' => 'technician', 'is_contractor' => true],
        ];
        foreach ($users as $u) {
            $user = User::create([
                'name' => $u['name'],
                'email' => $u['email'],
                'password' => Hash::make('demo'),
                'is_active' => true,
                'is_contractor' => $u['is_contractor'] ?? false,
                'email_verified_at' => now(),
            ]);
            $this->ctx['users'][Str::slug($u['name'], '_')] = $user->id;
        }
        $this->ctx['admin_id'] = $this->ctx['users']['alex_morgan'];
        $this->ctx['contractor_id'] = $this->ctx['users']['riley_okafor'];
    }

    private function seedSkus(): void
    {
        $skus = [
            ['sku_code' => 'MS-USER',           'name' => 'Managed Workstation',           'category' => 'managed_services', 'unit_price' => 95.00, 'unit_cost' => 32.00, 'default_quantity_type' => 'per_workstation'],
            ['sku_code' => 'MS-SERVER',         'name' => 'Managed Server',                'category' => 'managed_services', 'unit_price' => 250.00, 'unit_cost' => 75.00, 'default_quantity_type' => 'per_server'],
            ['sku_code' => 'M365-BP',           'name' => 'Microsoft 365 Business Premium', 'category' => 'licensing',        'unit_price' => 26.50, 'unit_cost' => 22.00, 'default_quantity_type' => 'per_license_type'],
            ['sku_code' => 'M365-BS',           'name' => 'Microsoft 365 Business Standard', 'category' => 'licensing',       'unit_price' => 15.50, 'unit_cost' => 12.50, 'default_quantity_type' => 'per_license_type'],
            ['sku_code' => 'BKP-WS',            'name' => 'Cloud Backup - Workstation',    'category' => 'backup',           'unit_price' => 12.00, 'unit_cost' => 4.50,  'default_quantity_type' => 'per_license_type'],
            ['sku_code' => 'BKP-SRV',           'name' => 'Cloud Backup - Server',         'category' => 'backup',           'unit_price' => 45.00, 'unit_cost' => 18.00, 'default_quantity_type' => 'per_license_type'],
            ['sku_code' => 'BKP-GB-OVERAGE',    'name' => 'Cloud Backup Overage (per GB)', 'category' => 'backup',           'unit_price' => 0.18,  'unit_cost' => 0.07,  'default_quantity_type' => 'overage'],
            ['sku_code' => 'EDR',               'name' => 'EDR - Endpoint',                'category' => 'security',         'unit_price' => 9.50,  'unit_cost' => 4.25,  'default_quantity_type' => 'per_license_type'],
            ['sku_code' => 'DNS-EP',            'name' => 'DNS Filtering - Endpoint',      'category' => 'security',         'unit_price' => 3.50,  'unit_cost' => 1.10,  'default_quantity_type' => 'per_license_type'],
            ['sku_code' => 'EMAIL-SEC',         'name' => 'Email Security - Mailbox',      'category' => 'security',         'unit_price' => 4.25,  'unit_cost' => 1.95,  'default_quantity_type' => 'per_license_type'],
            ['sku_code' => 'PREPAY-10',         'name' => 'Prepaid Time - 10 Hours',       'category' => 'project',          'unit_price' => 1450.00, 'unit_cost' => 0.00, 'default_quantity_type' => 'fixed', 'prepaid_time_minutes' => 600],
            ['sku_code' => 'PROJECT-HR',        'name' => 'Project Hour',                  'category' => 'project',          'unit_price' => 165.00, 'unit_cost' => 45.00, 'default_quantity_type' => 'fixed'],
            ['sku_code' => 'ONSITE',            'name' => 'Onsite Visit',                  'category' => 'project',          'unit_price' => 200.00, 'unit_cost' => 65.00, 'default_quantity_type' => 'fixed'],
            ['sku_code' => 'AFTERHOURS',        'name' => 'After-Hours Rate',              'category' => 'project',          'unit_price' => 245.00, 'unit_cost' => 65.00, 'default_quantity_type' => 'fixed'],
            ['sku_code' => 'HW-DEPLOY',         'name' => 'Hardware Deployment Fee',       'category' => 'hardware',         'unit_price' => 125.00, 'unit_cost' => 0.00,  'default_quantity_type' => 'fixed'],
        ];
        foreach ($skus as $s) {
            $row = array_merge($s, [
                'description' => '',
                'is_taxable' => true,
                'is_active' => true,
            ]);
            $sku = Sku::create($row);
            $this->ctx['skus'][$s['sku_code']] = $sku->id;
        }
    }

    private function seedLicenseTypes(): void
    {
        $types = [
            ['key' => 'M365_BP',     'name' => 'M365 Business Premium', 'vendor' => 'cipp_m365', 'sku' => 'M365-BP',    'cost' => 22.00],
            ['key' => 'M365_BS',     'name' => 'M365 Business Standard', 'vendor' => 'cipp_m365', 'sku' => 'M365-BS',    'cost' => 12.50],
            ['key' => 'BKP_WS',      'name' => 'Backup Workstation',    'vendor' => 'comet',     'sku' => 'BKP-WS',     'cost' => 4.50],
            ['key' => 'BKP_SRV',     'name' => 'Backup Server',         'vendor' => 'comet',     'sku' => 'BKP-SRV',    'cost' => 18.00],
            ['key' => 'BKP_USAGE',   'name' => 'Cloud Storage (GB)',    'vendor' => 'comet',     'sku' => 'BKP-GB-OVERAGE', 'cost' => 0.07],
            ['key' => 'EDR',         'name' => 'Huntress EDR',          'vendor' => 'huntress_edr', 'sku' => 'EDR',      'cost' => 4.25],
            ['key' => 'DNS_EP',      'name' => 'Control D DNS',         'vendor' => 'controld',  'sku' => 'DNS-EP',     'cost' => 1.10],
            ['key' => 'EMAIL_SEC',   'name' => 'Mesh Email Security',   'vendor' => 'mesh',      'sku' => 'EMAIL-SEC',  'cost' => 1.95],
        ];
        foreach ($types as $t) {
            $lt = LicenseType::create([
                'name' => $t['name'],
                'vendor' => $t['vendor'],
                'vendor_sku_id' => Str::lower($t['key']),
                'sku_id' => $this->ctx['skus'][$t['sku']],
                'default_unit_cost' => $t['cost'],
                'is_active' => true,
            ]);
            $this->ctx['license_types'][$t['key']] = $lt->id;
        }
    }

    private function seedClients(): void
    {
        $clients = [
            ['slug' => 'greenleaf', 'name' => 'Acme Dental Practice', 'city' => 'Portland', 'state' => 'OR', 'integrations' => ['ninja', 'm365', 'comet', 'mesh', 'huntress', 'controld'], 'managed' => true,  'prepay' => true],
            ['slug' => 'brightside', 'name' => 'Brightside Marketing Agency', 'city' => 'Seattle',  'state' => 'WA', 'integrations' => ['ninja', 'm365', 'comet'], 'managed' => true],
            ['slug' => 'vandelay',  'name' => 'Vandelay Industries',        'city' => 'Tacoma',   'state' => 'WA', 'integrations' => ['ninja', 'm365', 'comet', 'huntress', 'controld', 'tactical'], 'managed' => true, 'install_link' => true],
            ['slug' => 'pinnacle',  'name' => 'Pinnacle Insurance Group',   'city' => 'Bellevue', 'state' => 'WA', 'integrations' => ['level', 'm365', 'mesh', 'comet'], 'managed' => true],
            ['slug' => 'harborview', 'name' => 'HarborView Law Partners',    'city' => 'Olympia',  'state' => 'WA', 'integrations' => ['ninja', 'm365', 'huntress', 'mesh', 'zorus'], 'managed' => true, 'premium' => true],
            ['slug' => 'crestmont', 'name' => 'Crestmont Architects',       'city' => 'Spokane',  'state' => 'WA', 'integrations' => ['ninja', 'm365', 'controld'], 'managed' => true],
            ['slug' => 'sterling',  'name' => 'Sterling Realty Group',      'city' => 'Eugene',   'state' => 'OR', 'integrations' => ['ninja'], 'managed' => false],
            ['slug' => 'apex',      'name' => 'Apex Trading Co.',           'city' => 'Vancouver', 'state' => 'WA', 'integrations' => [], 'managed' => false, 'reseller_parent' => true],
            ['slug' => 'techflow',  'name' => 'TechFlow Distribution',      'city' => 'Vancouver', 'state' => 'WA', 'integrations' => ['ninja', 'm365'], 'managed' => true, 'reseller_of' => 'apex'],
            ['slug' => 'cascade',   'name' => 'Cascade Wellness Center',    'city' => 'Bend',     'state' => 'OR', 'integrations' => ['ninja', 'm365'], 'managed' => true, 'recent' => true],
        ];

        foreach ($clients as $i => $c) {
            $row = [
                'name' => $c['name'],
                'phone' => '555'.str_pad((string) (1000000 + $i * 7), 7, '0', STR_PAD_LEFT),
                'email' => 'office@'.$c['slug'].'.example.com',
                'website' => 'https://www.'.$c['slug'].'.example.com',
                'address_line1' => (100 + $i * 25).' Main Street',
                'city' => $c['city'],
                'state' => $c['state'],
                'postcode' => str_pad((string) (97000 + $i * 13), 5, '0', STR_PAD_LEFT),
                'is_active' => true,
                'primary_tech_id' => $this->ctx['users']['priya_shah'] ?? null,
                'notes' => $this->clientNotes($c['slug']),
                'site_notes' => $this->siteNotes($c['slug']),
                'site_notes_updated_at' => now()->subDays(30 - $i),
                'site_notes_updated_by' => $this->ctx['admin_id'],
            ];

            if (in_array('ninja', $c['integrations'])) {
                $row['ninja_org_id'] = 1000 + $i;
            }
            if (in_array('level', $c['integrations'])) {
                $row['level_group_id'] = 'Z2lkOi8vbGV2ZWwvRGV2aWNlR3JvdXAv'.(40000 + $i);
            }
            if (in_array('m365', $c['integrations'])) {
                $row['cipp_tenant_domain'] = $c['slug'].'.onmicrosoft.com';
            }
            if (in_array('comet', $c['integrations'])) {
                $row['comet_group_id'] = 'cg-'.Str::random(12);
                $row['comet_backup_user'] = 'svc-'.$c['slug'];
                $row['comet_backup_password'] = encrypt('demo-password-'.$c['slug']);
            }
            if (in_array('mesh', $c['integrations'])) {
                $row['mesh_customer_id'] = (string) Str::uuid();
            }
            if (in_array('huntress', $c['integrations'])) {
                $row['huntress_organization_id'] = 5000 + $i;
            }
            if (in_array('controld', $c['integrations'])) {
                $row['controld_org_id'] = 'cd-'.Str::random(8);
            }
            if (in_array('zorus', $c['integrations'])) {
                $row['zorus_customer_id'] = (string) Str::uuid();
            }
            if (in_array('tactical', $c['integrations'])) {
                $row['tactical_site_id'] = $c['name'].'|Main Office';
            }
            if (! empty($c['install_link'])) {
                $row['portal_install_token'] = Str::random(32);
                $row['portal_primary_rmm'] = 'ninja';
            }

            $client = Client::create($row);
            $this->ctx['clients'][$c['slug']] = $client->id;
            $this->ctx['client_meta'][$c['slug']] = $c;
        }

        foreach ($clients as $c) {
            if (! empty($c['reseller_of'])) {
                Client::where('id', $this->ctx['clients'][$c['slug']])
                    ->update(['reseller_id' => $this->ctx['clients'][$c['reseller_of']]]);
            }
        }
    }

    private function clientNotes(string $slug): string
    {
        return match ($slug) {
            'greenleaf' => "Front desk team has limited tech literacy - explain steps simply.\n8am-5pm M-F, closed 12-1 for lunch.\nDr. Patel is the primary decision-maker.",
            'vandelay' => "Three sites: HQ (Tacoma), warehouse, and remote sales reps.\nAlways CC accounting@vandelay.example.com on invoices.\nNo unscheduled after-hours work without approval.",
            'harborview' => "Premium-tier client. Response SLA is 30 min for P1/P2.\nAll partners can authorize work; associates require partner sign-off > \$500.\nStrict data retention rules - see site notes.",
            'pinnacle' => "Compliance-sensitive (HIPAA + state insurance regs).\nAll outbound email from BlueTier techs to Pinnacle staff must be encrypted.",
            default => '',
        };
    }

    private function siteNotes(string $slug): string
    {
        return match ($slug) {
            'greenleaf' => "# Acme Dental Practice\n\n## Site\n- Office at 425 Main, Portland\n- 8 workstations + 1 server in the back office\n- Eaglesoft practice management on Server-01\n\n## Key Contacts\n- **Dr. Patel** - Owner, primary decision-maker\n- **Lori** - Office manager, day-to-day point of contact\n\n## Access\n- Front door code in vault\n- Server room key with Dr. Patel\n\n## Known Quirks\n- Front desk wifi sometimes drops at 4pm (UPS battery test)\n- Reception printer needs paper realigned every other week",
            'vandelay' => "# Vandelay Industries\n\n## Sites\n- **HQ** (Tacoma) - 18 users, server room with 2 servers\n- **Warehouse** (Auburn) - 4 floor terminals, 1 shop laptop\n- **Remote sales** - 3 users with company laptops\n\n## Server inventory\n- VAN-DC01 (AD/file)\n- VAN-APP01 (ERP)\n\n## Backups\n- Comet to BlueTier cloud - 7d local, 30d cloud, monthly archive\n\n## Vendors\n- ISP: Centurion Fiber, account #44120\n- Phone: RingCentral, owned by client",
            'harborview' => "# HarborView Law Partners\n\n## Compliance\n- ABA tech requirements\n- Client-data retention: 10 years minimum\n- All laptops are BitLocker encrypted\n\n## After-hours\n- Trial weeks: Mon-Thu 11pm support window\n- Otherwise standard business hours",
            default => '',
        };
    }

    private function seedPeople(): void
    {
        $people = [
            // Greenleaf
            ['client' => 'greenleaf', 'first' => 'Anjali', 'last' => 'Patel',   'title' => 'Owner',          'primary' => true, 'portal' => true, 'm365' => true,  'mfa' => true],
            ['client' => 'greenleaf', 'first' => 'Lori',   'last' => 'Bennett', 'title' => 'Office Manager', 'portal' => true, 'm365' => true,  'mfa' => true],
            ['client' => 'greenleaf', 'first' => 'Marco',  'last' => 'Diaz',    'title' => 'Hygienist',      'm365' => true, 'mfa' => false],
            // Brightside
            ['client' => 'brightside', 'first' => 'Jordan', 'last' => 'Reeves',  'title' => 'Founder',        'primary' => true, 'portal' => true],
            ['client' => 'brightside', 'first' => 'Tess',  'last' => 'Nakamura', 'title' => 'Account Manager', 'm365' => true],
            // Vandelay
            ['client' => 'vandelay', 'first' => 'George',  'last' => 'Costanza', 'title' => 'Operations Lead', 'primary' => true, 'portal' => true, 'm365' => true, 'mfa' => true, 'company_wide' => true],
            ['client' => 'vandelay', 'first' => 'Eileen',  'last' => 'Park',    'title' => 'Office Admin',   'portal' => true, 'm365' => true],
            ['client' => 'vandelay', 'first' => 'Russ',    'last' => 'Hammond', 'title' => 'Warehouse Mgr',  'm365' => true],
            ['client' => 'vandelay', 'first' => 'Maya',    'last' => 'Whitfield', 'title' => 'Sales Rep',     'm365' => true],
            // Pinnacle
            ['client' => 'pinnacle', 'first' => 'Daniel',  'last' => 'OBrien',  'title' => 'IT Coordinator', 'primary' => true, 'portal' => true, 'm365' => true, 'mfa' => true],
            ['client' => 'pinnacle', 'first' => 'Hannah',  'last' => 'Williams', 'title' => 'Underwriter',    'm365' => true, 'mfa' => true],
            // HarborView
            ['client' => 'harborview', 'first' => 'Eleanor', 'last' => 'Tan',     'title' => 'Managing Partner', 'primary' => true, 'portal' => true, 'm365' => true, 'mfa' => true],
            ['client' => 'harborview', 'first' => 'Marcus', 'last' => 'Hale',    'title' => 'Associate',      'm365' => true, 'mfa' => true],
            // Crestmont
            ['client' => 'crestmont', 'first' => 'Iris',   'last' => 'Khoury',  'title' => 'Principal',      'primary' => true, 'portal' => true, 'm365' => true],
            ['client' => 'crestmont', 'first' => 'Theo',   'last' => 'Larsen',  'title' => 'Project Architect', 'm365' => true],
            // Sterling
            ['client' => 'sterling',  'first' => 'Beau',   'last' => 'Rivera',  'title' => 'Broker',         'primary' => true],
            // Apex
            ['client' => 'apex',      'first' => 'Vivian', 'last' => 'Holt',    'title' => 'Owner',          'primary' => true],
            // TechFlow
            ['client' => 'techflow',  'first' => 'Mason',  'last' => 'Greer',   'title' => 'Ops Director',   'primary' => true, 'portal' => true, 'm365' => true],
            // Cascade
            ['client' => 'cascade',   'first' => 'Naomi',  'last' => 'Brock',   'title' => 'Owner',          'primary' => true, 'portal' => true, 'm365' => true, 'mfa' => true],
            ['client' => 'cascade',   'first' => 'Ezra',   'last' => 'Mitchell', 'title' => 'Front Desk',     'm365' => true],
        ];

        foreach ($people as $p) {
            $clientId = $this->ctx['clients'][$p['client']];
            $row = [
                'client_id' => $clientId,
                'first_name' => $p['first'],
                'last_name' => $p['last'],
                'email' => Str::lower($p['first'].'.'.$p['last']).'@'.$p['client'].'.example.com',
                'phone' => '555'.str_pad((string) random_int(2000000, 9999999), 7, '0', STR_PAD_LEFT),
                'mobile' => '555'.str_pad((string) random_int(2000000, 9999999), 7, '0', STR_PAD_LEFT),
                'job_title' => $p['title'],
                'person_type' => 'user',
                'is_primary' => $p['primary'] ?? false,
                'is_active' => true,
                'company_wide_access' => $p['company_wide'] ?? false,
            ];

            if (! empty($p['portal'])) {
                $row['portal_enabled'] = true;
                $row['password'] = Hash::make('demo');
            }

            if (! empty($p['m365'])) {
                $row['cipp_user_id'] = (string) Str::uuid();
                $row['cipp_upn'] = $row['email'];
                $row['cipp_synced_at'] = now()->subDays(1);
                $row['cipp_enriched_at'] = now()->subDays(1);
                $row['m365_user_type'] = 'Member';
                $row['mfa_enabled'] = $p['mfa'] ?? false;
                $row['mailbox_size_bytes'] = random_int(500_000_000, 8_000_000_000);
                $row['mailbox_item_count'] = random_int(2_000, 30_000);
                $row['department'] = match ($p['client']) {
                    'vandelay' => ['Operations', 'Sales', 'Warehouse'][random_int(0, 2)],
                    'harborview' => 'Legal',
                    default => null,
                };
            }

            $row['notes'] = match (true) {
                $p['client'] === 'vandelay' && $p['first'] === 'George' => 'Senior contact - has authority to approve project work. Calls in for everything; prefers phone over email.',
                $p['client'] === 'greenleaf' && $p['first'] === 'Lori' => 'Lori is the practical day-to-day contact - Anjali only wants escalations.',
                default => null,
            };

            $person = Person::create($row);

            if ($p['client'] === 'vandelay' && $p['first'] === 'George') {
                PersonEmail::create([
                    'person_id' => $person->id,
                    'email' => 'george.personal@example.com',
                    'label' => 'Personal',
                ]);
            }

            $this->ctx['people'][$p['client']][] = $person->id;
            $this->ctx['people_by_name'][$p['client'].':'.$p['first']] = $person->id;
        }
    }

    private function seedAssets(): void
    {
        $assets = [
            // Greenleaf
            ['client' => 'greenleaf', 'hostname' => 'GREEN-SRV01',  'name' => 'Practice Server',     'type' => 'Server',      'os' => 'Windows Server 2022', 'ninja' => true, 'comet' => true],
            ['client' => 'greenleaf', 'hostname' => 'GREEN-WS01',   'name' => 'Reception PC',        'type' => 'Workstation', 'os' => 'Windows 11 Pro', 'ninja' => true, 'm365' => true, 'comet' => true, 'controld' => true, 'last_user' => 'lori.bennett'],
            ['client' => 'greenleaf', 'hostname' => 'GREEN-WS02',   'name' => 'Op1 PC',              'type' => 'Workstation', 'os' => 'Windows 11 Pro', 'ninja' => true, 'm365' => true, 'controld' => true],
            ['client' => 'greenleaf', 'hostname' => 'GREEN-DR-LAP', 'name' => 'Dr. Patel Laptop',    'type' => 'Laptop',      'os' => 'Windows 11 Pro', 'ninja' => true, 'm365' => true, 'controld' => true],
            // Brightside
            ['client' => 'brightside', 'hostname' => 'BRIGHT-MBP01', 'name' => 'Jordan MacBook',      'type' => 'Laptop',      'os' => 'macOS 14.5',     'ninja' => true, 'm365' => true],
            ['client' => 'brightside', 'hostname' => 'BRIGHT-WS01', 'name' => 'Tess Workstation',    'type' => 'Workstation', 'os' => 'Windows 11 Pro', 'ninja' => true, 'm365' => true, 'comet' => true],
            // Vandelay
            ['client' => 'vandelay', 'hostname' => 'VAN-DC01',      'name' => 'Domain Controller',   'type' => 'Server',      'os' => 'Windows Server 2022', 'ninja' => true, 'tactical' => true, 'comet' => true, 'huntress' => true],
            ['client' => 'vandelay', 'hostname' => 'VAN-APP01',     'name' => 'ERP Server',          'type' => 'Server',      'os' => 'Windows Server 2019', 'ninja' => true, 'tactical' => true, 'comet' => true, 'huntress' => true],
            ['client' => 'vandelay', 'hostname' => 'VAN-WS01',      'name' => 'George Costanza PC',  'type' => 'Workstation', 'os' => 'Windows 11 Pro', 'ninja' => true, 'm365' => true, 'huntress' => true, 'controld' => true, 'last_user' => 'george.costanza'],
            ['client' => 'vandelay', 'hostname' => 'VAN-WS02',      'name' => 'Eileen Park PC',      'type' => 'Workstation', 'os' => 'Windows 11 Pro', 'ninja' => true, 'm365' => true, 'huntress' => true, 'controld' => true],
            ['client' => 'vandelay', 'hostname' => 'VAN-SHOP-LAP',  'name' => 'Warehouse Laptop',    'type' => 'Laptop',      'os' => 'Windows 11 Pro', 'ninja' => true, 'huntress' => true],
            // Pinnacle
            ['client' => 'pinnacle', 'hostname' => 'PIN-SRV01',     'name' => 'File Server',         'type' => 'Server',      'os' => 'Windows Server 2022', 'level' => true, 'comet' => true],
            ['client' => 'pinnacle', 'hostname' => 'PIN-WS-OBR01',  'name' => 'D. OBrien PC',        'type' => 'Workstation', 'os' => 'Windows 11 Pro', 'level' => true, 'm365' => true],
            ['client' => 'pinnacle', 'hostname' => 'PIN-WS-WIL01',  'name' => 'H. Williams PC',      'type' => 'Workstation', 'os' => 'Windows 11 Pro', 'level' => true, 'm365' => true],
            // HarborView
            ['client' => 'harborview', 'hostname' => 'HV-SRV01',      'name' => 'Document Server',     'type' => 'Server',      'os' => 'Windows Server 2022', 'ninja' => true, 'huntress' => true],
            ['client' => 'harborview', 'hostname' => 'HV-PTNR-TAN',   'name' => 'E. Tan Laptop',       'type' => 'Laptop',      'os' => 'Windows 11 Pro', 'ninja' => true, 'm365' => true, 'huntress' => true, 'zorus' => true],
            ['client' => 'harborview', 'hostname' => 'HV-ASSOC-HALE', 'name' => 'M. Hale Laptop',      'type' => 'Laptop',      'os' => 'Windows 11 Pro', 'ninja' => true, 'm365' => true, 'huntress' => true, 'zorus' => true],
            // Crestmont
            ['client' => 'crestmont', 'hostname' => 'CREST-WS01',    'name' => 'Iris Workstation',    'type' => 'Workstation', 'os' => 'Windows 11 Pro', 'ninja' => true, 'm365' => true, 'controld' => true],
            ['client' => 'crestmont', 'hostname' => 'CREST-LAP01',   'name' => 'Theo Laptop',         'type' => 'Laptop',      'os' => 'Windows 11 Pro', 'ninja' => true, 'm365' => true, 'controld' => true, 'needs_reboot' => true],
            // Sterling
            ['client' => 'sterling', 'hostname' => 'STER-LAP01',     'name' => 'Beau Laptop',         'type' => 'Laptop',      'os' => 'Windows 11 Pro', 'ninja' => true],
            // TechFlow
            ['client' => 'techflow', 'hostname' => 'TF-WS01',        'name' => 'Mason PC',            'type' => 'Workstation', 'os' => 'Windows 11 Pro', 'ninja' => true, 'm365' => true],
            // Cascade
            ['client' => 'cascade', 'hostname' => 'CASC-FRONT',     'name' => 'Reception PC',        'type' => 'Workstation', 'os' => 'Windows 11 Pro', 'ninja' => true, 'm365' => true],
        ];

        foreach ($assets as $i => $a) {
            $clientId = $this->ctx['clients'][$a['client']];
            $row = [
                'client_id' => $clientId,
                'name' => $a['name'],
                'hostname' => $a['hostname'],
                'asset_type' => $a['type'],
                'serial_number' => strtoupper(Str::random(10)),
                'os' => $a['os'],
                'cpu' => $a['type'] === 'Server' ? 'Intel Xeon E-2336 @ 2.9GHz' : 'Intel Core i7-1265U @ 1.8GHz',
                'ram_gb' => $a['type'] === 'Server' ? 64 : 16,
                'disk_summary' => $a['type'] === 'Server' ? '2x 1TB NVMe RAID1' : '512GB NVMe',
                'ip_address' => '10.0.'.random_int(1, 20).'.'.random_int(10, 240),
                'last_user' => $a['last_user'] ?? null,
                'is_active' => true,
                'last_seen_at' => now()->subMinutes(random_int(0, 60)),
                'last_boot_at' => now()->subDays(random_int(0, 14)),
                'needs_reboot' => $a['needs_reboot'] ?? false,
                'rmm_online' => true,
                'warranty_start' => now()->subYears(random_int(1, 3))->toDateString(),
                'warranty_end' => now()->addYears(random_int(0, 2))->toDateString(),
            ];

            if (! empty($a['ninja'])) {
                $row['ninja_id'] = 700000 + $i;
                $row['ninja_url'] = 'https://app.ninjarmm.com/#/deviceDashboard/'.(700000 + $i);
                $row['ninja_synced_at'] = now()->subMinutes(15);
            }
            if (! empty($a['level'])) {
                $row['level_id'] = (string) Str::uuid();
                $row['level_url'] = 'https://app.level.io/devices/'.$row['level_id'];
                $row['level_synced_at'] = now()->subMinutes(20);
            }
            if (! empty($a['m365'])) {
                $row['m365_device_id'] = (string) Str::uuid();
                $row['m365_is_compliant'] = true;
                $row['m365_compliance_state'] = 'compliant';
                $row['m365_enrollment_type'] = 'AzureAD';
                $row['m365_os_version'] = '10.0.22631';
                $row['m365_synced_at'] = now()->subHours(8);
                $row['m365_last_sync_at'] = now()->subHours(8);
                $row['m365_device_owner_type'] = 'company';
                $row['m365_defender_status'] = 'protected';
                $row['m365_defender_version'] = '4.18.24090.11';
                $row['m365_last_scan_at'] = now()->subHours(random_int(1, 48));
            }
            if (! empty($a['controld'])) {
                $row['controld_device_id'] = 'cd-dev-'.Str::random(8);
                $row['controld_profile_name'] = 'Default Filtering';
                $row['controld_status'] = 1;
                $row['controld_agent_status'] = 1;
                $row['controld_agent_version'] = '1.3.4';
                $row['controld_last_seen_at'] = now()->subMinutes(10);
                $row['controld_synced_at'] = now()->subHours(2);
            }
            if (! empty($a['zorus'])) {
                $row['zorus_endpoint_id'] = (string) Str::uuid();
                $row['zorus_group_name'] = 'Attorneys';
                $row['zorus_filtering_enabled'] = true;
                $row['zorus_cybersight_enabled'] = true;
                $row['zorus_agent_version'] = '2024.10.1';
                $row['zorus_agent_state'] = 'online';
                $row['zorus_last_seen_at'] = now()->subMinutes(20);
                $row['zorus_synced_at'] = now()->subHours(4);
            }
            if (! empty($a['comet'])) {
                $row['comet_username'] = $a['client'].'-'.strtolower($a['hostname']);
                $row['comet_device_id'] = strtoupper(Str::random(8));
                $row['comet_backup_enabled'] = true;
                $row['backup_cloud_bytes'] = $a['type'] === 'Server' ? random_int(200_000_000_000, 600_000_000_000) : random_int(20_000_000_000, 80_000_000_000);
                $row['backup_local_bytes'] = $row['backup_cloud_bytes'];
                $row['backup_synced_at'] = now()->subHours(3);
            }

            $row['notes'] = match (true) {
                $a['hostname'] === 'VAN-APP01' => 'ERP server - do not reboot during business hours. Patches Sundays 02:00.',
                $a['hostname'] === 'GREEN-SRV01' => 'Hosts Eaglesoft DB. Eaglesoft service: Eaglesoft DB Server. Verify it is running after any reboot.',
                $a['hostname'] === 'CREST-LAP01' => 'Iris reports occasional bluescreens - replaced RAM 2026-04. Monitor.',
                default => null,
            };

            $asset = Asset::create($row);
            $this->ctx['assets'][$a['hostname']] = $asset->id;
            $this->ctx['assets_by_client'][$a['client']][] = $asset->id;
        }
    }

    private function seedTacticalAssets(): void
    {
        foreach (['VAN-DC01', 'VAN-APP01'] as $hostname) {
            $assetId = $this->ctx['assets'][$hostname];
            $ta = TacticalAsset::create([
                'asset_id' => $assetId,
                'agent_id' => (string) Str::uuid(),
                'hostname' => $hostname,
                'os' => 'Windows Server 2022',
                'os_version' => '10.0.20348',
                'public_ip' => '203.0.113.'.random_int(1, 254),
                'local_ips' => ['10.0.0.10'],
                'cpu' => 'Intel Xeon E-2336',
                'make_model' => 'Dell PowerEdge T350',
                'disk_summary' => '2x 1TB NVMe RAID1',
                'ram_gb' => 64,
                'serial_number' => strtoupper(Str::random(10)),
                'status' => 'online',
                'agent_version' => '2.6.0',
                'last_seen_at' => now()->subMinutes(2),
                'client_name' => 'Vandelay Industries',
                'site_name' => 'HQ',
                'needs_reboot' => false,
                'has_patches_pending' => false,
                'monitoring_type' => 'server',
                'synced_at' => now()->subMinutes(5),
            ]);
            Asset::where('id', $assetId)->update(['tactical_asset_id' => $ta->id]);
        }
    }

    private function seedLicenses(): void
    {
        $matrix = [
            'greenleaf' => ['M365_BP' => 8,  'EDR' => 8, 'DNS_EP' => 8, 'EMAIL_SEC' => 8, 'BKP_WS' => 3, 'BKP_SRV' => 1, 'BKP_USAGE' => 250],
            'brightside' => ['M365_BP' => 6,  'BKP_WS' => 2],
            'vandelay' => ['M365_BP' => 22, 'EDR' => 25, 'DNS_EP' => 22, 'BKP_WS' => 18, 'BKP_SRV' => 2, 'BKP_USAGE' => 1800],
            'pinnacle' => ['M365_BS' => 18, 'EMAIL_SEC' => 18, 'BKP_WS' => 8, 'BKP_SRV' => 1, 'BKP_USAGE' => 420],
            'harborview' => ['M365_BP' => 12, 'EDR' => 12, 'EMAIL_SEC' => 12],
            'crestmont' => ['M365_BP' => 7,  'DNS_EP' => 7],
            'techflow' => ['M365_BS' => 14],
            'cascade' => ['M365_BS' => 5],
        ];
        foreach ($matrix as $slug => $licenses) {
            foreach ($licenses as $key => $qty) {
                License::create([
                    'license_type_id' => $this->ctx['license_types'][$key],
                    'client_id' => $this->ctx['clients'][$slug],
                    'quantity' => $qty,
                    'assigned_quantity' => max(0, $qty - random_int(0, 2)),
                    'status' => 'active',
                    'synced_at' => now()->subHours(6),
                ]);
            }
        }
    }

    private function seedContracts(): void
    {
        $contracts = [
            ['client' => 'greenleaf', 'name' => 'Managed Services Agreement', 'type' => 'managed', 'start' => '-1 year'],
            ['client' => 'greenleaf', 'name' => 'Prepay Time Block',          'type' => 'custom',  'start' => '-3 months', 'prepay_total' => 20, 'prepay_used' => 12.5],
            ['client' => 'brightside', 'name' => 'Managed Services Agreement', 'type' => 'managed', 'start' => '-8 months'],
            ['client' => 'vandelay', 'name' => 'Managed Services Agreement', 'type' => 'managed', 'start' => '-2 years'],
            ['client' => 'vandelay', 'name' => 'Project Work - Q2',          'type' => 'custom', 'start' => '-2 months'],
            ['client' => 'pinnacle', 'name' => 'Managed Services Agreement', 'type' => 'managed', 'start' => '-1 year'],
            ['client' => 'harborview', 'name' => 'Premium Managed Agreement', 'type' => 'managed', 'start' => '-3 years'],
            ['client' => 'crestmont', 'name' => 'Managed Services Agreement', 'type' => 'managed', 'start' => '-6 months'],
            ['client' => 'sterling', 'name' => 'Break-Fix Agreement',        'type' => 'breakfix', 'start' => '-1 year'],
            ['client' => 'techflow', 'name' => 'Managed Services (via Apex)', 'type' => 'managed', 'start' => '-4 months'],
            ['client' => 'cascade',  'name' => 'Managed Services Agreement', 'type' => 'managed', 'start' => '-3 weeks'],
        ];
        foreach ($contracts as $c) {
            $row = [
                'client_id' => $this->ctx['clients'][$c['client']],
                'name' => $c['name'],
                'type' => $c['type'],
                'status' => 'active',
                'billing_source' => 'psa',
                'billing_period' => 'monthly',
                'billing_day' => 1,
                'payment_terms_days' => 15,
                'start_date' => Carbon::parse($c['start'])->toDateString(),
                'term_length_months' => 12,
                'auto_renew' => true,
            ];
            if (($c['prepay_total'] ?? 0) > 0) {
                $row['prepay_total'] = $c['prepay_total'];
                $row['prepay_used'] = $c['prepay_used'];
                $row['prepay_balance'] = $c['prepay_total'] - $c['prepay_used'];
                $row['prepay_as_amount'] = false;
                $row['prepay_alert_threshold'] = 5;
                $row['portal_prepay_sku_id'] = $this->ctx['skus']['PREPAY-10'];
            }
            $contract = Contract::create($row);
            $this->ctx['contracts'][$c['client'].':'.Str::slug($c['name'])] = $contract->id;
        }
    }

    private function seedContractAssignments(): void
    {
        foreach ($this->ctx['clients'] as $slug => $clientId) {
            $managedKey = collect($this->ctx['contracts'])
                ->filter(fn ($v, $k) => str_starts_with($k, $slug.':'))
                ->keys()->first();
            if (! $managedKey) {
                continue;
            }
            $contractId = $this->ctx['contracts'][$managedKey];
            foreach (($this->ctx['assets_by_client'][$slug] ?? []) as $assetId) {
                DB::table('contract_asset')->insert([
                    'contract_id' => $contractId,
                    'asset_id' => $assetId,
                    'assigned_at' => now(),
                    'assignment_source' => 'manual',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    private function seedContractDocuments(): void
    {
        $ctr = $this->ctx['contracts']['harborview:premium-managed-agreement'];
        ContractDocument::create([
            'contract_id' => $ctr,
            'uploaded_by' => $this->ctx['admin_id'],
            'original_filename' => 'HarborView-MSA-Signed-2024.pdf',
            'disk_path' => 'contract-documents/'.$ctr.'/HarborView-MSA-Signed-2024.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 248_320,
            'extracted_text' => '(Demo document - extracted text not stored.)',
            'ai_summary' => "**Term:** 36 months, auto-renews annually.\n**Scope:** All endpoints (laptops, desktops) + 1 server.\n**Response SLA:** 30 min (P1/P2), 4 hr (P3), next business day (P4).\n**Onsite:** Up to 4 visits/year included; additional billed at \$200/visit.\n**After-hours:** Included for partners; associates billed at after-hours rate.\n**Data retention:** Client data retained for minimum 10 years.\n**Termination:** 60-day notice; early termination fee = 3 months of fees.",
            'summary_status' => 'completed',
            'summary_tokens_used' => 1280,
            'summarized_at' => now()->subWeeks(2),
        ]);
    }

    private function seedRecurringProfiles(): void
    {
        $configs = [
            ['contract_key' => 'vandelay:managed-services-agreement', 'name' => 'Vandelay Monthly Managed', 'next_run' => now()->addDays(2),  'lines' => [
                ['sku' => 'MS-USER',   'qty_type' => 'per_workstation'],
                ['sku' => 'MS-SERVER', 'qty_type' => 'per_server'],
                ['sku' => 'M365-BP',   'qty_type' => 'per_license_type', 'license_type' => 'M365_BP'],
                ['sku' => 'EDR',       'qty_type' => 'per_license_type', 'license_type' => 'EDR'],
                ['sku' => 'BKP-WS',    'qty_type' => 'per_license_type', 'license_type' => 'BKP_WS'],
                ['sku' => 'BKP-SRV',   'qty_type' => 'per_license_type', 'license_type' => 'BKP_SRV'],
                ['sku' => 'BKP-GB-OVERAGE', 'qty_type' => 'overage',
                    'usage_license_type' => 'BKP_USAGE', 'base_license_type' => 'BKP_WS',
                    'included_per_base_unit' => 100, 'overage_divisor' => 1],
            ]],
            ['contract_key' => 'greenleaf:managed-services-agreement', 'name' => 'Greenleaf Monthly', 'next_run' => now()->addDays(5), 'lines' => [
                ['sku' => 'MS-USER',   'qty_type' => 'per_workstation'],
                ['sku' => 'MS-SERVER', 'qty_type' => 'per_server'],
                ['sku' => 'M365-BP',   'qty_type' => 'per_license_type', 'license_type' => 'M365_BP'],
                ['sku' => 'EMAIL-SEC', 'qty_type' => 'per_license_type', 'license_type' => 'EMAIL_SEC'],
            ]],
            ['contract_key' => 'harborview:premium-managed-agreement', 'name' => 'HarborView Monthly Premium', 'next_run' => now()->addDays(8), 'lines' => [
                ['sku' => 'MS-USER', 'qty_type' => 'per_workstation'],
                ['sku' => 'M365-BP', 'qty_type' => 'per_license_type', 'license_type' => 'M365_BP'],
                ['sku' => 'EDR',     'qty_type' => 'per_license_type', 'license_type' => 'EDR'],
            ]],
        ];

        foreach ($configs as $cfg) {
            $profile = RecurringInvoiceProfile::create([
                'contract_id' => $this->ctx['contracts'][$cfg['contract_key']],
                'name' => $cfg['name'],
                'is_active' => true,
                'billing_period' => 'monthly',
                'billing_day' => 1,
                'payment_terms_days' => 15,
                'auto_push_mode' => null,
                'next_run_date' => $cfg['next_run']->toDateString(),
                'last_run_date' => $cfg['next_run']->copy()->subMonth()->toDateString(),
            ]);
            foreach ($cfg['lines'] as $i => $l) {
                RecurringInvoiceProfileLine::create([
                    'profile_id' => $profile->id,
                    'sku_id' => $this->ctx['skus'][$l['sku']],
                    'description' => '',
                    'unit_price' => 0,
                    'license_type_id' => isset($l['license_type']) ? $this->ctx['license_types'][$l['license_type']] : null,
                    'usage_license_type_id' => isset($l['usage_license_type']) ? $this->ctx['license_types'][$l['usage_license_type']] : null,
                    'base_license_type_id' => isset($l['base_license_type']) ? $this->ctx['license_types'][$l['base_license_type']] : null,
                    'included_per_base_unit' => $l['included_per_base_unit'] ?? null,
                    'overage_divisor' => $l['overage_divisor'] ?? null,
                    'quantity_type' => $l['qty_type'],
                    'fixed_quantity' => 0,
                    'is_taxable' => true,
                    'sort_order' => $i,
                ]);
            }
        }
    }

    private function seedInvoices(): void
    {
        $invoices = [
            ['client' => 'vandelay',  'contract' => 'vandelay:managed-services-agreement',  'date' => '-2 months', 'status' => 'paid',   'lines' => 'managed_full'],
            ['client' => 'vandelay',  'contract' => 'vandelay:managed-services-agreement',  'date' => '-1 month',  'status' => 'paid',   'lines' => 'managed_full'],
            ['client' => 'vandelay',  'contract' => 'vandelay:managed-services-agreement',  'date' => '-1 week',   'status' => 'posted', 'lines' => 'managed_full'],
            ['client' => 'greenleaf', 'contract' => 'greenleaf:managed-services-agreement', 'date' => '-1 month',  'status' => 'paid',   'lines' => 'managed_small'],
            ['client' => 'greenleaf', 'contract' => 'greenleaf:prepay-time-block',          'date' => '-3 months', 'status' => 'paid',   'lines' => 'prepay'],
            ['client' => 'harborview', 'contract' => 'harborview:premium-managed-agreement', 'date' => '-1 month',  'status' => 'paid',   'lines' => 'managed_premium'],
            ['client' => 'pinnacle',  'contract' => 'pinnacle:managed-services-agreement', 'date' => '-1 month',  'status' => 'posted', 'lines' => 'managed_full'],
            ['client' => 'sterling',  'contract' => 'sterling:break-fix-agreement',        'date' => '-2 weeks',  'status' => 'draft',  'lines' => 'breakfix'],
        ];
        $num = 2026100;
        foreach ($invoices as $inv) {
            $contractId = $this->ctx['contracts'][$inv['contract']];
            $clientId = $this->ctx['clients'][$inv['client']];
            $date = Carbon::parse($inv['date']);
            $invoice = Invoice::create([
                'client_id' => $clientId,
                'contract_id' => $contractId,
                'invoice_number' => 'INV-'.++$num,
                'invoice_date' => $date->toDateString(),
                'due_date' => $date->copy()->addDays(15)->toDateString(),
                'status' => $inv['status'],
                'subtotal' => 0,
                'tax' => 0,
                'total' => 0,
                'total_cost' => 0,
                'margin' => 0,
            ]);
            $lines = $this->invoiceLines($inv['lines']);
            $subtotal = 0;
            $cost = 0;
            foreach ($lines as $j => $l) {
                $amt = $l['qty'] * $l['unit_price'];
                $costAmt = $l['qty'] * ($l['unit_cost'] ?? 0);
                $subtotal += $amt;
                $cost += $costAmt;
                InvoiceLine::create([
                    'invoice_id' => $invoice->id,
                    'sku_id' => $this->ctx['skus'][$l['sku']],
                    'description' => $l['description'] ?? null,
                    'quantity' => $l['qty'],
                    'unit_price' => $l['unit_price'],
                    'unit_cost' => $l['unit_cost'] ?? 0,
                    'amount' => $amt,
                    'cost_amount' => $costAmt,
                    'prepaid_time_minutes' => $l['prepaid_minutes'] ?? 0,
                    'quantity_source' => $l['source'] ?? 'fixed',
                    'is_taxable' => true,
                    'sort_order' => $j,
                ]);
            }
            $tax = round($subtotal * 0.0925, 2);
            $invoice->update([
                'subtotal' => $subtotal,
                'tax' => $tax,
                'total' => $subtotal + $tax,
                'total_cost' => $cost,
                'margin' => $subtotal - $cost,
            ]);
            $this->ctx['invoices'][] = $invoice->id;
            if ($inv['lines'] === 'prepay') {
                $this->ctx['prepay_deposit_invoice_id'] = $invoice->id;
            }
        }
    }

    private function invoiceLines(string $shape): array
    {
        return match ($shape) {
            'managed_full' => [
                ['sku' => 'MS-USER',   'qty' => 18, 'unit_price' => 95.00,  'unit_cost' => 32.00, 'description' => 'Managed Workstation', 'source' => 'per_workstation'],
                ['sku' => 'MS-SERVER', 'qty' => 2,  'unit_price' => 250.00, 'unit_cost' => 75.00, 'description' => 'Managed Server',      'source' => 'per_server'],
                ['sku' => 'M365-BP',   'qty' => 22, 'unit_price' => 26.50,  'unit_cost' => 22.00, 'description' => 'M365 Business Premium', 'source' => 'per_license_type'],
                ['sku' => 'EDR',       'qty' => 25, 'unit_price' => 9.50,   'unit_cost' => 4.25,  'description' => 'EDR - Endpoint',     'source' => 'per_license_type'],
                ['sku' => 'BKP-WS',    'qty' => 18, 'unit_price' => 12.00,  'unit_cost' => 4.50,  'description' => 'Cloud Backup - Workstation', 'source' => 'per_license_type'],
                ['sku' => 'BKP-SRV',   'qty' => 2,  'unit_price' => 45.00,  'unit_cost' => 18.00, 'description' => 'Cloud Backup - Server', 'source' => 'per_license_type'],
                ['sku' => 'BKP-GB-OVERAGE', 'qty' => 3, 'unit_price' => 0.18, 'unit_cost' => 0.07, 'description' => 'Backup storage overage (per GB)', 'source' => 'overage'],
            ],
            'managed_small' => [
                ['sku' => 'MS-USER',   'qty' => 3, 'unit_price' => 95.00, 'unit_cost' => 32.00, 'description' => 'Managed Workstation', 'source' => 'per_workstation'],
                ['sku' => 'MS-SERVER', 'qty' => 1, 'unit_price' => 250.00, 'unit_cost' => 75.00, 'description' => 'Managed Server',      'source' => 'per_server'],
                ['sku' => 'M365-BP',   'qty' => 8, 'unit_price' => 26.50, 'unit_cost' => 22.00, 'description' => 'M365 Business Premium', 'source' => 'per_license_type'],
                ['sku' => 'EMAIL-SEC', 'qty' => 8, 'unit_price' => 4.25,  'unit_cost' => 1.95,  'description' => 'Email Security',       'source' => 'per_license_type'],
            ],
            'managed_premium' => [
                ['sku' => 'MS-USER', 'qty' => 12, 'unit_price' => 130.00, 'unit_cost' => 32.00, 'description' => 'Managed Workstation (Premium tier)', 'source' => 'per_workstation'],
                ['sku' => 'M365-BP', 'qty' => 12, 'unit_price' => 26.50,  'unit_cost' => 22.00, 'description' => 'M365 Business Premium', 'source' => 'per_license_type'],
                ['sku' => 'EDR',     'qty' => 12, 'unit_price' => 9.50,   'unit_cost' => 4.25,  'description' => 'EDR - Endpoint',     'source' => 'per_license_type'],
                ['sku' => 'EMAIL-SEC', 'qty' => 12, 'unit_price' => 4.25,   'unit_cost' => 1.95,  'description' => 'Email Security',      'source' => 'per_license_type'],
            ],
            'prepay' => [
                ['sku' => 'PREPAY-10', 'qty' => 2, 'unit_price' => 1450.00, 'unit_cost' => 0.00, 'description' => 'Prepaid Time - 20 hours total', 'prepaid_minutes' => 1200],
            ],
            'breakfix' => [
                ['sku' => 'PROJECT-HR', 'qty' => 3, 'unit_price' => 165.00, 'unit_cost' => 45.00, 'description' => 'Onsite troubleshooting - printer + network'],
                ['sku' => 'ONSITE',     'qty' => 1, 'unit_price' => 200.00, 'unit_cost' => 65.00, 'description' => 'Onsite trip fee'],
            ],
        };
    }

    private function seedPrepayTransactions(): void
    {
        $contractId = $this->ctx['contracts']['greenleaf:prepay-time-block'];
        PrepayTransaction::create([
            'contract_id' => $contractId,
            'source' => 'invoice_deposit',
            'invoice_id' => $this->ctx['prepay_deposit_invoice_id'] ?? null,
            'user_id' => $this->ctx['admin_id'],
            'date' => now()->subMonths(3),
            'hours' => 20,
            'description' => 'Prepaid time block - INV-2026104',
        ]);
        $debits = [
            ['hours' => -1.5, 'date' => '-10 weeks', 'description' => 'Phone call on Ticket #T-DEMO: VPN troubleshooting'],
            ['hours' => -2.0, 'date' => '-8 weeks',  'description' => 'Ticket #T-DEMO: New workstation setup'],
            ['hours' => -0.75, 'date' => '-5 weeks',  'description' => 'Phone call on Ticket #T-DEMO: Printer driver'],
            ['hours' => -3.0, 'date' => '-3 weeks',  'description' => 'Ticket #T-DEMO: Eaglesoft upgrade prep'],
            ['hours' => -2.25, 'date' => '-2 weeks',  'description' => 'Ticket #T-DEMO: Server patching, after-hours'],
            ['hours' => -3.0, 'date' => '-1 week',   'description' => 'Ticket #T-DEMO: Eaglesoft upgrade execution'],
        ];
        foreach ($debits as $d) {
            PrepayTransaction::create([
                'contract_id' => $contractId,
                'source' => str_contains($d['description'], 'Phone call') ? 'phone_call_time' : 'ticket_time',
                'user_id' => $this->ctx['admin_id'],
                'date' => Carbon::parse($d['date']),
                'hours' => $d['hours'],
                'description' => $d['description'],
            ]);
        }
    }

    private function seedContractorTime(): void
    {
        ContractorTimeTransaction::create([
            'user_id' => $this->ctx['contractor_id'],
            'source' => 'initial_balance',
            'hours' => 40,
            'date' => now()->subMonths(2),
            'description' => 'Initial bench balance - onboarding',
            'recorded_by' => $this->ctx['admin_id'],
        ]);
        ContractorTimeTransaction::create([
            'user_id' => $this->ctx['contractor_id'],
            'source' => 'manual_credit',
            'hours' => 20,
            'date' => now()->subMonth(),
            'description' => 'Q2 top-up',
            'recorded_by' => $this->ctx['admin_id'],
        ]);
    }

    private function seedTickets(): void
    {
        $tickets = [
            ['key' => 't_van_p1', 'client' => 'vandelay', 'contact' => 'vandelay:George',
                'subject' => '[ALERT] VAN-APP01 - Server unreachable',
                'description' => "Tactical RMM agent went offline on VAN-APP01 at 09:42. Last check-in was 09:39.\nAlert severity: critical.",
                'source' => 'alert', 'status' => 'in_progress', 'priority' => 'p1', 'assignee' => 'devon_carter',
                'opened' => '-2 hours', 'category' => 'Infrastructure', 'subcategory' => 'Server',
                'asset' => 'VAN-APP01', 'keywords' => 'tactical alert server unreachable van-app01 erp vandelay agent-offline'],

            ['key' => 't_hv_outlook', 'client' => 'harborview', 'contact' => 'harborview:Marcus',
                'subject' => 'Outlook crashing every time I open it this morning',
                'description' => "Outlook 365 desktop is crashing within ~5 seconds of opening. I've rebooted twice. Can you take a look?",
                'source' => 'email', 'status' => 'in_progress', 'priority' => 'p2', 'assignee' => 'priya_shah',
                'opened' => '-4 hours', 'category' => 'Software', 'subcategory' => 'Outlook',
                'asset' => 'HV-ASSOC-HALE', 'keywords' => 'outlook crash m365 harborview hale email-client desktop'],

            ['key' => 't_bright_printer', 'client' => 'brightside', 'contact' => 'brightside:Tess',
                'subject' => 'Helpdesk Button - Printer not printing color',
                'description' => 'Color printer is only printing black & white now. Was fine yesterday.',
                'source' => 'helpdesk_button', 'status' => 'new', 'priority' => 'p3',
                'opened' => '-30 minutes', 'category' => 'Hardware', 'subcategory' => 'Printer',
                'keywords' => 'printer color brightside hardware'],

            ['key' => 't_crest_vpn', 'client' => 'crestmont', 'contact' => 'crestmont:Theo',
                'subject' => 'VPN keeps timing out when working from home',
                'description' => 'Caller reports VPN connects but disconnects every ~10 minutes. Started today.',
                'source' => 'phone', 'status' => 'in_progress', 'priority' => 'p2', 'assignee' => 'priya_shah',
                'opened' => '-1 day', 'category' => 'Network', 'subcategory' => 'VPN',
                'asset' => 'CREST-LAP01', 'keywords' => 'vpn timeout disconnect crestmont remote-work'],

            ['key' => 't_green_pwd', 'client' => 'greenleaf', 'contact' => 'greenleaf:Marco',
                'subject' => 'Password reset for M365 account',
                'description' => "Marco needs his M365 password reset, he's locked out.",
                'source' => 'email', 'status' => 'resolved', 'priority' => 'p3', 'assignee' => 'devon_carter',
                'opened' => '-3 days', 'resolved' => '-2 days', 'category' => 'Account', 'subcategory' => 'Password Reset',
                'resolution' => 'Reset password via CIPP, confirmed with Marco that he can log in. Recommended he set up MFA - sent the QR code.',
                'keywords' => 'password reset m365 cipp greenleaf account lockout'],

            ['key' => 't_van_newhire', 'client' => 'vandelay', 'contact' => 'vandelay:Eileen',
                'subject' => 'New hire setup - Trevor Quinn, starts Monday',
                'description' => "Please set up:\n- Email (trevor.quinn@vandelay.example.com)\n- Workstation\n- M365 BP license\n- Backup\n- EDR",
                'source' => 'portal', 'status' => 'in_progress', 'priority' => 'p3', 'assignee' => 'devon_carter',
                'opened' => '-5 days', 'category' => 'Account', 'subcategory' => 'New Hire',
                'keywords' => 'new-hire onboarding vandelay m365 license setup workstation'],

            ['key' => 't_pin_mesh', 'client' => 'pinnacle', 'contact' => 'pinnacle:Hannah',
                'subject' => 'Email Delivery Request: hannah.williams@pinnacle.example.com',
                'description' => "From: claims@trusted-vendor.example.com\nSubject: Q2 claim summary\nQueue ID: 8fa0c1\n\nQuarantined as suspected phishing. Hannah requested release.",
                'source' => 'email', 'status' => 'resolved', 'priority' => 'p3', 'assignee' => 'priya_shah',
                'opened' => '-2 days', 'resolved' => '-1 day', 'category' => 'Security', 'subcategory' => 'Email Security',
                'resolution' => 'Verified sender DKIM/SPF pass. Released from quarantine via Mesh. Whitelisted sender domain at Hannah\'s request.',
                'keywords' => 'mesh quarantine release pinnacle email-security phishing whitelist'],

            ['key' => 't_van_disk_dup', 'client' => 'vandelay', 'contact' => 'vandelay:George',
                'subject' => 'Server seems slow this afternoon',
                'description' => 'VAN-APP01 has been slow since lunch. Reports from sales team.',
                'source' => 'email', 'status' => 'closed', 'priority' => 'p3', 'assignee' => 'priya_shah',
                'opened' => '-3 days', 'resolved' => '-2 days', 'closed_at' => '-2 days',
                'resolution' => 'Merged into the disk-full ticket.',
                'category' => 'Performance', 'subcategory' => 'Server',
                'merged_into_key' => 't_van_disk',
                'keywords' => 'van-app01 slow performance vandelay'],

            ['key' => 't_van_disk', 'client' => 'vandelay', 'contact' => 'vandelay:George',
                'subject' => 'VAN-APP01 - C: drive low free space',
                'description' => 'Tactical alert: C: drive on VAN-APP01 dropped below 10% free.',
                'source' => 'alert', 'status' => 'in_progress', 'priority' => 'p2', 'assignee' => 'priya_shah',
                'opened' => '-3 days', 'category' => 'Infrastructure', 'subcategory' => 'Storage',
                'asset' => 'VAN-APP01',
                'keywords' => 'disk-full van-app01 server storage tactical-alert performance'],

            ['key' => 't_green_sw', 'client' => 'greenleaf', 'contact' => 'greenleaf:Lori',
                'subject' => 'Can we install Adobe Acrobat Pro on the reception PC?',
                'description' => 'Lori would like Acrobat Pro for handling intake forms. Confirming we have a license available before we proceed.',
                'source' => 'email', 'status' => 'pending_client', 'priority' => 'p4', 'assignee' => 'devon_carter',
                'opened' => '-1 week', 'category' => 'Software', 'subcategory' => 'Installation Request',
                'keywords' => 'adobe acrobat pro greenleaf software license install'],

            ['key' => 't_pin_backup', 'client' => 'pinnacle', 'contact' => 'pinnacle:Daniel',
                'subject' => '[Comet] Backup failed - PIN-SRV01',
                'description' => "Comet job 'PIN-SRV01 Nightly' failed at 02:14. Error: Quota exceeded.",
                'source' => 'alert', 'status' => 'in_progress', 'priority' => 'p3', 'assignee' => 'priya_shah',
                'opened' => '-12 hours', 'category' => 'Backup', 'subcategory' => 'Failed Job',
                'keywords' => 'comet backup failed pinnacle quota pin-srv01'],

            ['key' => 't_hv_huntress', 'client' => 'harborview', 'contact' => 'harborview:Eleanor',
                'subject' => '[Huntress Detection] CRITICAL - Isolated host HV-ASSOC-HALE',
                'description' => 'Huntress detected suspicious PowerShell activity on HV-ASSOC-HALE. Host isolated automatically.',
                'source' => 'huntress', 'status' => 'resolved', 'priority' => 'p1', 'assignee' => 'priya_shah',
                'opened' => '-2 weeks', 'resolved' => '-2 weeks', 'category' => 'Security', 'subcategory' => 'Incident Response',
                'asset' => 'HV-ASSOC-HALE',
                'resolution' => 'False positive - PowerShell ran from a legitimate IT scheduled task. Confirmed with M. Hale. Released host from isolation. Added the script signature to Huntress allowlist.',
                'keywords' => 'huntress ransomware harborview isolated powershell false-positive incident'],

            ['key' => 't_crest_kb', 'client' => 'crestmont', 'contact' => 'crestmont:Theo',
                'subject' => 'Keyboard replacement request',
                'description' => "Theo's keyboard W key is stuck. Please ship a replacement.",
                'source' => 'portal', 'status' => 'closed', 'priority' => 'p4', 'assignee' => 'sam_whitaker',
                'opened' => '-3 weeks', 'resolved' => '-2 weeks', 'closed_at' => '-1 week',
                'category' => 'Hardware', 'subcategory' => 'Peripheral',
                'resolution' => 'Shipped Logitech MX Keys, arrived 2 days later.',
                'keywords' => 'keyboard replacement crestmont peripheral hardware'],

            ['key' => 't_casc_onboard', 'client' => 'cascade', 'contact' => 'cascade:Naomi',
                'subject' => 'Onboarding - initial site assessment + agent deployment',
                'description' => "Initial onboarding for Cascade Wellness. Need to:\n1. Site walkthrough\n2. Inventory existing assets\n3. Deploy Ninja + EDR + DNS filtering\n4. Set up M365 tenant",
                'source' => 'portal', 'status' => 'in_progress', 'priority' => 'p3', 'assignee' => 'alex_morgan',
                'opened' => '-2 weeks', 'category' => 'Project', 'subcategory' => 'Onboarding',
                'keywords' => 'onboarding cascade wellness new-client deployment ninja edr m365'],

            ['key' => 't_old1', 'client' => 'greenleaf', 'contact' => 'greenleaf:Lori',
                'subject' => 'Front desk printer offline',
                'description' => 'Printer at reception is showing offline this morning.',
                'source' => 'email', 'status' => 'closed', 'priority' => 'p3',
                'opened' => '-2 months', 'resolved' => '-2 months', 'closed_at' => '-7 weeks',
                'resolution' => 'Power-cycled the printer; reconnected to network. Will replace battery in UPS next visit.',
                'keywords' => 'printer offline greenleaf reception ups network'],
            ['key' => 't_old2', 'client' => 'vandelay', 'contact' => 'vandelay:Russ',
                'subject' => 'Warehouse laptop running slow',
                'description' => 'Shop floor laptop is taking forever to boot.',
                'source' => 'email', 'status' => 'closed', 'priority' => 'p3',
                'opened' => '-6 weeks', 'resolved' => '-5 weeks', 'closed_at' => '-4 weeks',
                'resolution' => 'Replaced spinning HDD with SSD. Restored from Comet backup.',
                'keywords' => 'laptop slow boot warehouse vandelay hdd ssd performance'],
            ['key' => 't_old3', 'client' => 'pinnacle', 'contact' => 'pinnacle:Daniel',
                'subject' => 'New mailbox provisioning - H. Williams',
                'description' => 'Hannah is starting Monday - need her M365 mailbox set up.',
                'source' => 'email', 'status' => 'closed', 'priority' => 'p3',
                'opened' => '-3 months', 'resolved' => '-3 months', 'closed_at' => '-11 weeks',
                'resolution' => 'Created M365 BS license, assigned to hannah.williams@pinnacle.example.com. MFA set up day 1.',
                'keywords' => 'm365 mailbox provisioning pinnacle hannah williams license'],
        ];

        foreach ($tickets as $t) {
            $clientId = $this->ctx['clients'][$t['client']];
            $contactKey = $t['contact'] ?? null;
            $contactId = $contactKey ? ($this->ctx['people_by_name'][$contactKey] ?? null) : null;
            $assigneeId = isset($t['assignee']) ? $this->ctx['users'][$t['assignee']] : null;

            $opened = Carbon::parse($t['opened']);
            $resolvedAt = isset($t['resolved']) ? Carbon::parse($t['resolved']) : null;
            $closedAt = isset($t['closed_at']) ? Carbon::parse($t['closed_at']) : null;

            $row = [
                'client_id' => $clientId,
                'contact_id' => $contactId,
                'assignee_id' => $assigneeId,
                'created_by' => $this->ctx['admin_id'],
                'subject' => $t['subject'],
                'description' => $t['description'],
                'source' => $t['source'],
                'type' => 'service_request',
                'status' => $t['status'],
                'priority' => $t['priority'],
                'category' => $t['category'] ?? null,
                'subcategory' => $t['subcategory'] ?? null,
                'opened_at' => $opened,
                'resolved_at' => $resolvedAt,
                'closed_at' => $closedAt,
                'resolution' => $t['resolution'] ?? null,
                'search_keywords' => $t['keywords'] ?? null,
                'created_at' => $opened,
                'updated_at' => $resolvedAt ?? $closedAt ?? now(),
            ];

            $ticket = Ticket::create($row);
            $this->ctx['tickets'][$t['key']] = $ticket->id;
            $this->ctx['ticket_meta'][$t['key']] = $t;

            if (! empty($t['asset'])) {
                DB::table('ticket_asset')->insert([
                    'ticket_id' => $ticket->id,
                    'asset_id' => $this->ctx['assets'][$t['asset']],
                    'is_primary' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        foreach ($tickets as $t) {
            if (! empty($t['merged_into_key'])) {
                Ticket::where('id', $this->ctx['tickets'][$t['key']])
                    ->update(['parent_ticket_id' => $this->ctx['tickets'][$t['merged_into_key']]]);
            }
        }
    }

    private function seedTicketNotes(): void
    {
        // T1 alert + tech notes
        $this->note('t_van_p1', null, 'System', 'Auto-created from Tactical RMM alert: VAN-APP01 unreachable.', true, '-2 hours');
        $this->note('t_van_p1', 'devon_carter', 'Note', "Pinged the host and confirmed it's not responding. Pulling in Priya to check the UPS.", false, '-1 hour');

        // T2 Outlook
        $this->note('t_hv_outlook', 'priya_shah', 'Note', 'Asked Marcus to disable add-ins and run in safe mode. Reproduces immediately. Likely OST corruption - will recreate profile next.', false, '-3 hours');

        // T3 helpdesk button with user message
        $this->note('t_bright_printer', null, 'StatusChange',
            "Submitted via HelpDesk Button by Tess Nakamura from BRIGHT-WS01.\n\n**Message:**\n\n> Color printer is only printing black & white now. I checked the toner status in the menu and it says yellow is empty. Could that be why? It said yellow was at 40% on Monday.",
            true, '-30 minutes');

        // T4 VPN from voicemail
        $this->note('t_crest_vpn', null, 'System', 'Ticket created from phone call. Call summary: Theo reports VPN disconnects every ~10 minutes. Asked him to check if the same happens on his phone hotspot.', true, '-1 day');
        $this->note('t_crest_vpn', 'priya_shah', 'Note', 'Got Theo back on the phone - same disconnect on hotspot. Not his ISP. Going to check our VPN concentrator logs.', false, '-22 hours');

        // T5 resolved
        $this->note('t_green_pwd', 'devon_carter', 'Note', "Reset password via CIPP. Walked Marco through MFA setup. He's good.", false, '-2 days');
        $this->note('t_green_pwd', null, 'StatusChange', 'Status changed: in_progress → resolved', true, '-2 days');

        // T8/T9 merge audit
        $secondaryId = $this->ctx['tickets']['t_van_disk_dup'];
        $this->note('t_van_disk', 'priya_shah', 'System',
            "**Ticket merged:** T-{$secondaryId} - *Server seems slow this afternoon* merged into this ticket by Priya Shah. Moved: 0 notes, 0 calls, 0 emails.\n\n**Original message from T-{$secondaryId}:**\n\n> VAN-APP01 has been slow since lunch. Reports from sales team.",
            true, '-2 days');
        $primaryId = $this->ctx['tickets']['t_van_disk'];
        $this->note('t_van_disk_dup', 'priya_shah', 'System',
            "**Merged into T-{$primaryId}** by Priya Shah.",
            true, '-2 days');

        // T6 new hire
        $this->note('t_van_newhire', 'devon_carter', 'Note', 'Created M365 account, assigned BP license. Waiting on workstation prep - ETA Friday.', false, '-3 days');

        // T12 huntress AI triage tone
        $this->note('t_hv_huntress', null, 'AiTriage',
            "**Classification:** Managed services\n**Priority:** P1\n**Asset:** HV-ASSOC-HALE\n\n**Initial analysis:**\nHuntress isolated the host after detecting a PowerShell script chain. Comparing the script signature to recent activity on this host: the same hash ran at 02:00 on the previous Sunday (scheduled patching window). Likely false positive - this is BlueTier's patching script.\n\n**Recommended actions:**\n1. Confirm with M. Hale that no unusual activity occurred\n2. Compare script hash to allowlist\n3. Release isolation if confirmed\n4. Add hash to Huntress allowlist",
            true, '-2 weeks');
        $this->note('t_hv_huntress', 'priya_shah', 'Note', 'Confirmed FP - released. Added the hash to allowlist as recommended.', false, '-2 weeks');

        // T14 onboarding
        $this->note('t_casc_onboard', 'alex_morgan', 'Note', 'Site walkthrough complete. 5 workstations, 1 NAS, no server. Quoting Ninja + EDR + DNS deployment.', false, '-12 days');
        $this->note('t_casc_onboard', 'alex_morgan', 'Note', 'Naomi signed quote. Ninja agents deployed to 5/5 workstations.', false, '-7 days');
    }

    private function note(string $ticketKey, ?string $userSlug, string $type, string $body, bool $private, string $when): void
    {
        $ticketId = $this->ctx['tickets'][$ticketKey];
        $authorId = $userSlug ? $this->ctx['users'][$userSlug] : null;

        TicketNote::create([
            'ticket_id' => $ticketId,
            'author_id' => $authorId,
            'author_name' => $authorId ? null : 'System',
            'note_type' => Str::snake($type),
            'body' => $body,
            'body_html' => '<p>'.nl2br(e($body)).'</p>',
            'is_private' => $private,
            'noted_at' => Carbon::parse($when),
            'created_at' => Carbon::parse($when),
            'updated_at' => Carbon::parse($when),
        ]);
    }

    private function seedTriageRuns(): void
    {
        $runs = [
            't_van_p1', 't_hv_outlook', 't_bright_printer', 't_crest_vpn',
            't_pin_mesh', 't_pin_backup', 't_hv_huntress',
        ];
        foreach ($runs as $key) {
            $ticket = Ticket::find($this->ctx['tickets'][$key]);
            TriageRun::create([
                'ticket_id' => $ticket->id,
                'mode' => 'triage',
                'status' => 'completed',
                'stages_completed' => ['contact_resolution', 'junk_filter', 'classification', 'asset_assignment', 'technical_triage'],
                'stage_results' => [
                    'contact_resolution' => ['status' => 'completed'],
                    'junk_filter' => ['status' => 'completed', 'verdict' => 'not_junk'],
                    'classification' => [
                        'status' => 'completed',
                        'classification' => 'managed_services',
                        'work_covered_by_managed' => true,
                        'reasoning' => 'Issue type and client tier match the active managed services contract.',
                    ],
                    'asset_assignment' => ['status' => 'completed'],
                    'technical_triage' => ['status' => 'completed', 'note_length' => 480, 'tokens' => 12_400],
                ],
                'triggered_by' => 'system',
                'started_at' => $ticket->opened_at,
                'completed_at' => $ticket->opened_at->copy()->addSeconds(45),
                'duration_ms' => 45000,
                'ai_tokens_used' => ['input' => 8_200, 'output' => 4_200],
            ]);
        }
    }

    private function seedPhoneCalls(): void
    {
        // Voicemail with transcript
        $vmTime = now()->subDay();
        PhoneCall::create([
            'call_uuid' => (string) Str::uuid(),
            'direction' => 'inbound',
            'from_number' => '+15035551208',
            'to_number' => '+15550203300',
            'status' => 'voicemail',
            'client_id' => $this->ctx['clients']['crestmont'],
            'person_id' => $this->ctx['people_by_name']['crestmont:Theo'],
            'ticket_id' => $this->ctx['tickets']['t_crest_vpn'],
            'started_at' => $vmTime,
            'ended_at' => $vmTime->copy()->addSeconds(48),
            'duration' => 48,
            'recording_duration' => 47,
            'recording_url' => 'https://example.com/recordings/demo-vm-1.mp3',
            'transcription' => "Hey, it's Theo at Crestmont. My VPN keeps dropping every ten minutes or so. It connects fine, I can get to email and the file server, and then it just disconnects. I have to reconnect, and ten minutes later it does it again. I'm working from home today so I really need it to stay up. Can someone call me back? Thanks.",
            'cleaned_transcript' => "**Caller (Theo Larsen, Crestmont Architects):** Hey, it's Theo at Crestmont. My VPN keeps dropping every ten minutes or so. It connects fine — I can get to email and the file server — and then it just disconnects. I have to reconnect, and ten minutes later it does it again. I'm working from home today, so I really need it to stay up. Can someone call me back? Thanks.",
            'transcription_summary' => 'Caller: Theo Larsen, Crestmont Architects. VPN connects but disconnects every ~10 minutes. Working from home, needs callback.',
            'call_summary' => 'Theo at Crestmont reports his VPN disconnects roughly every 10 minutes after connecting successfully. He can reach email and the file server, then the session drops and he has to reconnect. Working from home and needs a callback.',
            'next_steps' => "- Call Theo back at the number on file.\n- Check the firewall VPN profile for an aggressive idle-timeout / dead-peer-detection setting.\n- Confirm whether other Crestmont remote users are affected.",
            'sentiment_score' => 5,
            'charge_classification' => 'no_charge',
            'transcription_status' => 'completed',
            'transcribed_at' => $vmTime->copy()->addMinutes(2),
            'is_billable' => false,
        ]);

        // Inbound answered
        $callTime = now()->subWeek();
        PhoneCall::create([
            'call_uuid' => (string) Str::uuid(),
            'direction' => 'inbound',
            'from_number' => '+15035551001',
            'to_number' => '+15550203300',
            'status' => 'completed',
            'answered_by' => $this->ctx['users']['priya_shah'],
            'client_id' => $this->ctx['clients']['greenleaf'],
            'person_id' => $this->ctx['people_by_name']['greenleaf:Lori'],
            'ticket_id' => $this->ctx['tickets']['t_green_pwd'],
            'started_at' => $callTime,
            'answered_at' => $callTime->copy()->addSeconds(8),
            'ended_at' => $callTime->copy()->addMinutes(7)->addSeconds(15),
            'duration' => 427,
            'recording_duration' => 425,
            'recording_url' => 'https://example.com/recordings/demo-call-green-pwd.mp3',
            'transcription' => "Priya: Thanks for calling BlueTier, this is Priya. Lori: Hi Priya, it's Lori at Acme Dental. I'm locked out of my Microsoft 365 account, it won't take my password no matter what I try. Priya: No problem, I can help with that. Before I reset anything, let me verify a couple of details with you. Okay, thanks, that all checks out. I'm sending a password reset now. You'll get a prompt to set a new password and re-register your authenticator. Lori: Okay, doing it now. Oh, it worked, I'm back in. Thank you so much. Priya: Glad that did it. One thing I'd recommend, we can turn on self-service password reset so you can do this yourself next time without waiting on us. Lori: That would be great. Priya: I'll make a note on the ticket and we'll get that set up. Anything else? Lori: No, that's everything, thanks Priya.",
            'cleaned_transcript' => "**Agent (Priya Shah):** Thanks for calling BlueTier, this is Priya.\n**Caller (Lori Bennett, Acme Dental):** Hi Priya, it's Lori at Acme Dental. I'm locked out of my Microsoft 365 account — it won't take my password no matter what I try.\n**Agent:** No problem, I can help with that. Before I reset anything, let me verify a couple of details with you... Okay, thanks, that all checks out. I'm sending a password reset now — you'll get a prompt to set a new password and re-register your authenticator.\n**Caller:** Okay, doing it now. Oh — it worked, I'm back in. Thank you so much.\n**Agent:** Glad that did it. One thing I'd recommend: we can turn on self-service password reset so you can do this yourself next time without waiting on us.\n**Caller:** That would be great.\n**Agent:** I'll make a note on the ticket and we'll get that set up. Anything else?\n**Caller:** No, that's everything — thanks, Priya.",
            'transcription_summary' => 'Lori Bennett (Acme Dental) was locked out of Microsoft 365. Priya verified identity, issued a reset, and Lori regained access on the call. SSPR recommended as a follow-up.',
            'call_summary' => "Caller was locked out of her Microsoft 365 account. Agent verified the caller's identity, issued a password reset, and confirmed access was restored while still on the call. Agent recommended enabling self-service password reset (SSPR) to prevent repeat lockout calls.",
            'next_steps' => "- Enable self-service password reset (SSPR) for Acme Dental users.\n- Confirm MFA / authenticator re-registered successfully.",
            'sentiment_score' => 8,
            'charge_classification' => 'no_charge',
            'coaching_notes' => "Strong call. Priya verified the caller's identity before resetting the password — good security hygiene — and proactively recommended SSPR to deflect future tickets. Opportunity: open a follow-up task for the SSPR rollout so the recommendation doesn't get lost.",
            'transcription_status' => 'completed',
            'transcribed_at' => $callTime->copy()->addMinutes(9),
            'is_billable' => false,
        ]);

        // Outbound callback
        $ob = now()->subHours(20);
        PhoneCall::create([
            'call_uuid' => (string) Str::uuid(),
            'direction' => 'outbound',
            'from_number' => '+15550203300',
            'to_number' => '+15035551208',
            'status' => 'completed',
            'answered_by' => $this->ctx['users']['priya_shah'],
            'client_id' => $this->ctx['clients']['crestmont'],
            'person_id' => $this->ctx['people_by_name']['crestmont:Theo'],
            'ticket_id' => $this->ctx['tickets']['t_crest_vpn'],
            'started_at' => $ob,
            'answered_at' => $ob->copy()->addSeconds(12),
            'ended_at' => $ob->copy()->addMinutes(14)->addSeconds(40),
            'duration' => 868,
            'recording_duration' => 866,
            'recording_url' => 'https://example.com/recordings/demo-call-crest-vpn.mp3',
            'transcription' => "Priya: Hi Theo, it's Priya at BlueTier returning your call about the VPN. Theo: Oh great, thanks for calling back. Yeah, it keeps dropping every ten minutes or so. Priya: That pattern usually points to an idle-timeout or dead-peer-detection setting on the firewall rather than your connection itself. Let me pull up your VPN profile. Okay, I can see the session idle-timeout is set pretty aggressively. I'm increasing that and enabling keep-alives now. Theo: Okay. Priya: Can you reconnect and stay on with me for a few minutes so we can confirm it holds? Theo: Sure, reconnecting now. Priya: Great, I see you connected. Theo: Yeah, it's been steady for a few minutes now, no drop. Priya: Good. Let's keep an eye on it through the afternoon. If it drops again, call me back and I'll escalate to a firmware check on the firewall. I'll leave the ticket open until end of day. Theo: Sounds good, thank you.",
            'cleaned_transcript' => "**Agent (Priya Shah):** Hi Theo, it's Priya at BlueTier returning your call about the VPN.\n**Caller (Theo Larsen, Crestmont Architects):** Oh great, thanks for calling back. Yeah, it keeps dropping every ten minutes or so.\n**Agent:** That pattern usually points to an idle-timeout or dead-peer-detection setting on the firewall rather than your connection itself. Let me pull up your VPN profile... Okay, I can see the session idle-timeout is set pretty aggressively. I'm increasing that and enabling keep-alives now.\n**Caller:** Okay.\n**Agent:** Can you reconnect and stay on with me for a few minutes so we can confirm it holds?\n**Caller:** Sure, reconnecting now.\n**Agent:** Great, I see you connected.\n**Caller:** Yeah, it's been steady for a few minutes now — no drop.\n**Agent:** Good. Let's keep an eye on it through the afternoon. If it drops again, call me back and I'll escalate to a firmware check on the firewall. I'll leave the ticket open until end of day.\n**Caller:** Sounds good, thank you.",
            'transcription_summary' => "Priya returned Theo's call about VPN disconnects. She traced it to an aggressive idle-timeout on the firewall VPN profile, raised the timeout, enabled keep-alives, and verified the connection held during the call.",
            'call_summary' => "Agent returned the customer's call about VPN sessions dropping every ~10 minutes. Diagnosed an aggressive idle-timeout / dead-peer-detection setting on the firewall VPN profile, increased the idle-timeout, and enabled keep-alives. Had the customer reconnect and confirmed the connection stayed up during the call. Ticket left open to monitor through end of day.",
            'next_steps' => "- Monitor Theo's VPN stability through end of day.\n- If drops recur, check the firewall firmware version and escalate.\n- Resolve the ticket if the connection is stable by EOD.",
            'sentiment_score' => 7,
            'charge_classification' => 'no_charge',
            'coaching_notes' => 'Good diagnostic reasoning — tied the symptom directly to idle-timeout/DPD instead of guessing. Kept the customer on the line to verify the fix held, which is excellent. Opportunity: document the firewall setting change in the asset notes so the next tech has the history.',
            'transcription_status' => 'completed',
            'transcribed_at' => $ob->copy()->addMinutes(16),
            'is_billable' => false,
        ]);

        // Missed
        $miss = now()->subHours(5);
        PhoneCall::create([
            'call_uuid' => (string) Str::uuid(),
            'direction' => 'inbound',
            'from_number' => '+15035558822',
            'to_number' => '+15550203300',
            'status' => 'missed',
            'client_id' => $this->ctx['clients']['vandelay'],
            'started_at' => $miss,
            'ended_at' => $miss->copy()->addSeconds(22),
        ]);

        // Transcribed voicemail with NO linked ticket — use this to demo "Create Ticket from Call" live.
        $newVm = now()->subHours(2);
        PhoneCall::create([
            'call_uuid' => (string) Str::uuid(),
            'direction' => 'inbound',
            'from_number' => '+12065550148',
            'to_number' => '+15550203300',
            'status' => 'voicemail',
            'client_id' => $this->ctx['clients']['brightside'],
            'person_id' => $this->ctx['people_by_name']['brightside:Tess'],
            'started_at' => $newVm,
            'ended_at' => $newVm->copy()->addSeconds(54),
            'duration' => 54,
            'recording_duration' => 53,
            'recording_url' => 'https://example.com/recordings/demo-vm-brightside.mp3',
            'transcription' => "Hi, this is Tess Nakamura at Brightside Marketing. We've got a new designer, Jordan, starting next Monday and I want to make sure they're ready to go on day one. They'll need a laptop set up, Adobe Creative Cloud installed, and a Microsoft 365 account with their email. Can someone get that going before the weekend? Let me know what you need from me. Thanks!",
            'cleaned_transcript' => "**Caller (Tess Nakamura, Brightside Marketing):** Hi, this is Tess Nakamura at Brightside Marketing. We've got a new designer, Jordan, starting next Monday and I want to make sure they're ready to go on day one. They'll need a laptop set up, Adobe Creative Cloud installed, and a Microsoft 365 account with their email. Can someone get that going before the weekend? Let me know what you need from me. Thanks!",
            'transcription_summary' => 'Tess Nakamura (Brightside Marketing) requesting new-hire onboarding for a designer (Jordan) starting Monday: laptop build, Adobe Creative Cloud, and an M365 account/mailbox. Wants it ready before the weekend.',
            'call_summary' => 'Caller is requesting new-hire onboarding for a designer starting next Monday. Needs a laptop provisioned, Adobe Creative Cloud installed, and a Microsoft 365 account with email created. Asked that everything be ready before the weekend.',
            'next_steps' => "- Confirm the new hire's full name and start date with Tess.\n- Provision a laptop and install Adobe Creative Cloud.\n- Create the M365 account and mailbox, assign a license.\n- Schedule the work to complete before the weekend.",
            'sentiment_score' => 7,
            'charge_classification' => 'billable',
            'transcription_status' => 'completed',
            'transcribed_at' => $newVm->copy()->addMinutes(3),
            'is_billable' => true,
        ]);
    }

    private function seedEmails(): void
    {
        $ticket = $this->ctx['tickets']['t_hv_outlook'];
        $clientId = $this->ctx['clients']['harborview'];
        $personId = $this->ctx['people_by_name']['harborview:Marcus'];
        Email::create([
            'graph_id' => (string) Str::uuid(),
            'ticket_id' => $ticket,
            'client_id' => $clientId,
            'person_id' => $personId,
            'direction' => 'inbound',
            'from_address' => 'marcus.hale@harborview.example.com',
            'from_name' => 'Marcus Hale',
            'to_recipients' => [self::MSP_SUPPORT_EMAIL],
            'subject' => 'Outlook crashing every time I open it this morning',
            'body_text' => "Hi BlueTier - Outlook 365 desktop is crashing within ~5 seconds of opening. I've rebooted twice. Can you take a look?\n\nThanks,\nMarcus",
            'body_html' => '<p>Hi BlueTier - Outlook 365 desktop is crashing within ~5 seconds of opening. I\'ve rebooted twice. Can you take a look?</p><p>Thanks,<br>Marcus</p>',
            'received_at' => now()->subHours(4),
        ]);
        Email::create([
            'graph_id' => null,
            'ticket_id' => $ticket,
            'client_id' => $clientId,
            'person_id' => $personId,
            'direction' => 'outbound',
            'from_address' => self::MSP_SUPPORT_EMAIL,
            'from_name' => self::MSP_NAME,
            'to_recipients' => ['marcus.hale@harborview.example.com'],
            'subject' => 'RE: Outlook crashing every time I open it this morning',
            'body_text' => "Hi Marcus,\n\nThanks for the report. Can you try opening Outlook in safe mode (hold Ctrl while clicking the Outlook icon)? If it loads cleanly that way, it's likely an add-in. Let me know what you see.\n\nPriya",
            'body_html' => '<p>Hi Marcus,</p><p>Thanks for the report. Can you try opening Outlook in safe mode (hold Ctrl while clicking the Outlook icon)? If it loads cleanly that way, it\'s likely an add-in. Let me know what you see.</p><p>Priya</p>',
            'received_at' => now()->subHours(3),
        ]);

        Email::create([
            'graph_id' => (string) Str::uuid(),
            'ticket_id' => $this->ctx['tickets']['t_pin_mesh'],
            'client_id' => $this->ctx['clients']['pinnacle'],
            'person_id' => $this->ctx['people_by_name']['pinnacle:Hannah'],
            'direction' => 'inbound',
            'from_address' => 'noreply@emailsecurity.app',
            'from_name' => 'Mesh Email Security',
            'to_recipients' => [self::MSP_SUPPORT_EMAIL],
            'subject' => 'Email Delivery Request: hannah.williams@pinnacle.example.com',
            'body_text' => "Sender: claims@trusted-vendor.example.com\nRecipient: hannah.williams@pinnacle.example.com\nSubject: Q2 claim summary\nQueue ID: 8fa0c1\nCategory: Suspected phishing\n\nThe recipient has requested delivery of this message.",
            'received_at' => now()->subDays(2),
        ]);

        Email::create([
            'graph_id' => (string) Str::uuid(),
            'ticket_id' => $this->ctx['tickets']['t_green_sw'],
            'client_id' => $this->ctx['clients']['greenleaf'],
            'person_id' => $this->ctx['people_by_name']['greenleaf:Lori'],
            'direction' => 'inbound',
            'from_address' => 'lori.bennett@greenleaf.example.com',
            'from_name' => 'Lori Bennett',
            'to_recipients' => [self::MSP_SUPPORT_EMAIL],
            'subject' => 'Can we install Adobe Acrobat Pro on the reception PC?',
            'body_text' => 'Hi BlueTier - Anjali asked me to set up intake forms in a PDF. Acrobat Pro would help. Can we add it to my workstation? Thanks.',
            'received_at' => now()->subWeek(),
        ]);
    }

    private function seedAlerts(): void
    {
        $alerts = [
            ['asset' => 'VAN-APP01', 'client' => 'vandelay', 'source' => 'tactical',
                'severity' => 'critical', 'status' => 'ticketed',
                'title' => 'Server unreachable', 'message' => 'Agent has not checked in for >10 minutes.',
                'ticket_key' => 't_van_p1', 'fired' => '-2 hours'],
            ['asset' => 'VAN-APP01', 'client' => 'vandelay', 'source' => 'tactical',
                'severity' => 'warning', 'status' => 'ticketed',
                'title' => 'C: drive low free space', 'message' => 'Free space dropped below 10% threshold.',
                'ticket_key' => 't_van_disk', 'fired' => '-3 days'],
            ['asset' => null, 'client' => 'pinnacle', 'source' => 'comet',
                'severity' => 'error', 'status' => 'ticketed',
                'title' => 'Backup job failed - PIN-SRV01', 'message' => 'Quota exceeded (status 7002).',
                'ticket_key' => 't_pin_backup', 'fired' => '-12 hours'],
            ['asset' => 'HV-ASSOC-HALE', 'client' => 'harborview', 'source' => 'huntress',
                'severity' => 'critical', 'status' => 'resolved',
                'title' => 'CRITICAL - Isolated host detected', 'message' => 'Suspicious PowerShell activity. Host auto-isolated.',
                'ticket_key' => 't_hv_huntress', 'fired' => '-2 weeks', 'resolved' => '-2 weeks'],
            ['asset' => 'BRIGHT-WS01', 'client' => 'brightside', 'source' => 'ninja',
                'severity' => 'info', 'status' => 'active',
                'title' => 'Pending OS reboot', 'message' => 'Device has been pending reboot for 6 days.',
                'fired' => '-6 hours'],
            ['asset' => 'CREST-LAP01', 'client' => 'crestmont', 'source' => 'ninja',
                'severity' => 'warning', 'status' => 'acknowledged',
                'title' => 'SMART status warning', 'message' => 'Disk reported elevated reallocated sector count.',
                'fired' => '-3 hours', 'acknowledged' => '-1 hour'],
        ];
        foreach ($alerts as $a) {
            $row = [
                'asset_id' => $a['asset'] ? $this->ctx['assets'][$a['asset']] : null,
                'client_id' => $this->ctx['clients'][$a['client']],
                'source' => $a['source'],
                'source_alert_id' => (string) Str::uuid(),
                'severity' => $a['severity'],
                'status' => $a['status'],
                'title' => $a['title'],
                'message' => $a['message'],
                'hostname' => $a['asset'],
                'ticket_id' => isset($a['ticket_key']) ? $this->ctx['tickets'][$a['ticket_key']] : null,
                'fired_at' => Carbon::parse($a['fired']),
            ];
            if (! empty($a['acknowledged'])) {
                $row['acknowledged_by'] = $this->ctx['users']['priya_shah'];
                $row['acknowledged_at'] = Carbon::parse($a['acknowledged']);
            }
            if (! empty($a['resolved'])) {
                $row['resolved_at'] = Carbon::parse($a['resolved']);
            }
            Alert::create($row);
        }
    }
}
