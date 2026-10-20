<?php

namespace Tests\Feature\Contracts;

use App\Models\Client;
use App\Models\Contract;
use App\Models\ContractLine;
use App\Models\RecurringInvoiceProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ContractLinesDriftTest extends TestCase
{
    use RefreshDatabase;

    private RecurringInvoiceProfile $profile;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Http::preventStrayRequests();
        $contract = Contract::withoutEvents(fn () => Contract::create([
            'client_id' => Client::factory()->create()->id, 'name' => 'Synthetic contract',
            'type' => 'managed', 'status' => 'active', 'start_date' => '2026-01-01',
        ]));
        $this->profile = RecurringInvoiceProfile::create([
            'contract_id' => $contract->id, 'name' => 'Synthetic profile', 'is_active' => true,
            'next_run_date' => '2026-11-01', 'billing_period' => 'monthly', 'billing_day' => 1, 'payment_terms_days' => 30,
        ]);
    }

    private function line(array $attributes = [])
    {
        return $this->profile->lines()->create($attributes + [
            'description' => 'Synthetic private description', 'unit_price' => '91.23',
            'quantity_type' => 'fixed', 'fixed_quantity' => '2.00', 'is_taxable' => true, 'sort_order' => 0,
        ]);
    }

    private function report(): string
    {
        $this->assertSame(0, Artisan::call('contract-lines:drift'));

        return Artisan::output();
    }

    public function test_clean_backfill_and_empty_profile_have_zero_drift_and_report_is_read_only(): void
    {
        $this->assertStringContainsString('drift=0', $this->report());
        $this->line();
        Artisan::call('contract-lines:backfill-v0', ['--commit' => true]);
        $writes = [];
        DB::listen(function ($query) use (&$writes) {
            if (preg_match('/^\s*(insert|update|delete|replace|create|drop|alter)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });
        $this->assertStringContainsString('drift=0', $this->report());
        $this->assertSame([], $writes);
        DB::table('recurring_invoice_profile_lines')->where('profile_id', $this->profile->id)->update(['sort_order' => 9]);
        $this->assertCount(1, $writes, 'write detector positive control');
    }

    public function test_changed_added_removed_report_only_ids_and_column_names(): void
    {
        $changed = $this->line();
        $removed = $this->line(['description' => 'Synthetic removed']);
        Artisan::call('contract-lines:backfill-v0', ['--commit' => true]);
        $changed->update(['description' => 'Synthetic new private text', 'unit_price' => '82.34']);
        $removed->delete();
        $this->line(['description' => 'Synthetic added']);
        $output = $this->report();
        foreach (['changed: description, unit_price', 'added', 'removed', 'drift=3'] as $expected) {
            $this->assertStringContainsString($expected, $output);
        }
        foreach (['Synthetic', '91.23', '82.34'] as $private) {
            $this->assertStringNotContainsString($private, $output);
        }
        $this->assertMatchesRegularExpression('/\|\s*'.$this->profile->contract_id.'\s*\|\s*'.$this->profile->id.'\s*\|/', $output);
    }

    public function test_billing_internals_and_normalized_tier_equivalence_are_not_drift(): void
    {
        $line = $this->line(['pricing_tiers' => [['up_to' => 10, 'unit_price' => '2.00']]]);
        Artisan::call('contract-lines:backfill-v0', ['--commit' => true]);
        $licenseId = DB::table('license_types')->insertGetId(['name' => 'Synthetic license', 'vendor' => 'synthetic']);
        $line->update([
            'license_type_id' => $licenseId, 'usage_license_type_id' => $licenseId, 'base_license_type_id' => $licenseId,
            'unit_cost_override' => 5, 'prepaid_time_override' => 12, 'halo_id' => 101,
            'included_per_base_unit' => 3, 'overage_divisor' => 4, 'sort_order' => 99,
            'pricing_tiers' => [['up_to' => null, 'unit_price' => 2]],
        ]);
        $this->assertStringContainsString('drift=0', $this->report());
    }

    public function test_real_identical_update_replaces_ids_without_drift(): void
    {
        $line = $this->line();
        Artisan::call('contract-lines:backfill-v0', ['--commit' => true]);
        $this->actingAs(User::factory()->create());
        $this->patch(route('profiles.update', $this->profile), [
            'name' => $this->profile->name, 'is_active' => true, 'billing_period' => 'monthly',
            'billing_day' => 1, 'payment_terms_days' => 30, 'next_run_date' => '2026-11-01',
            'lines' => [[
                'id' => $line->id, 'description' => $line->description, 'unit_price' => '91.23',
                'quantity_type' => 'fixed', 'fixed_quantity' => '2.00', 'is_taxable' => true,
            ]],
        ])->assertSessionHasNoErrors()->assertRedirect(route('profiles.show', $this->profile));
        $this->assertDatabaseMissing('recurring_invoice_profile_lines', ['id' => $line->id]);
        $this->assertNull(ContractLine::sole()->source_profile_line_id);
        $this->assertStringContainsString('drift=0', $this->report());
    }

    public function test_latest_version_and_all_client_facing_columns_are_compared(): void
    {
        $line = $this->line();
        Artisan::call('contract-lines:backfill-v0', ['--commit' => true]);
        $latest = ContractLine::sole()->replicate();
        $latest->version = 1;
        $latest->save();
        DB::table('contract_lines')->where('version', 0)->update(['description' => 'Synthetic old version']);
        $this->assertStringContainsString('drift=0', $this->report());
        $line->update(['quantity_type' => 'per_user', 'fixed_quantity' => 3, 'is_taxable' => false,
            'pricing_tiers' => [['up_to' => null, 'unit_price' => 1]]]);
        $output = $this->report();
        foreach (['quantity_type', 'fixed_quantity', 'pricing_tiers', 'is_taxable', 'drift=1'] as $column) {
            $this->assertStringContainsString($column, $output);
        }
        $this->assertSame(0, Artisan::call('contract-lines:drift', ['--contract' => 'invalid']));
        $this->assertStringContainsString('status=invalid_contract', Artisan::output());
    }
}
