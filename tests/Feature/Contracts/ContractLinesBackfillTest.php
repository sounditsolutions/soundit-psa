<?php

namespace Tests\Feature\Contracts;

use App\Models\Client;
use App\Models\Contract;
use App\Models\ContractActivity;
use App\Models\ContractLine;
use App\Models\RecurringInvoiceProfile;
use App\Models\RecurringInvoiceProfileLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class ContractLinesBackfillTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Http::preventStrayRequests();
    }

    private function profile(bool $withLine = true): RecurringInvoiceProfile
    {
        $contract = Contract::withoutEvents(fn () => Contract::create([
            'client_id' => Client::factory()->create()->id, 'name' => 'Synthetic contract',
            'type' => 'managed', 'status' => 'active', 'start_date' => '2026-01-01',
        ]));
        $profile = RecurringInvoiceProfile::create([
            'contract_id' => $contract->id, 'name' => 'Synthetic profile',
            'next_run_date' => '2026-11-01', 'is_active' => true,
        ]);
        if ($withLine) {
            $this->line($profile);
        }

        return $profile;
    }

    private function line(RecurringInvoiceProfile $profile): RecurringInvoiceProfileLine
    {
        return $profile->lines()->create([
            'description' => 'Synthetic commercial description', 'unit_price' => '17.23',
            'quantity_type' => 'fixed', 'fixed_quantity' => '2.25', 'is_taxable' => true, 'sort_order' => 3,
            'pricing_tiers' => [['up_to' => 99, 'unit_price' => '7.123'], ['up_to' => 5, 'unit_price' => '8.456']],
        ]);
    }

    private function counts(): array
    {
        $counts = [];
        foreach (Schema::getTableListing() as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }

    public function test_dry_run_has_zero_row_diff_and_commit_copies_raw_columns_without_billing_mutation(): void
    {
        $profile = $this->profile();
        $line = $profile->lines()->first();
        $before = $this->counts();
        $profileBytes = (array) DB::table('recurring_invoice_profiles')->find($profile->id);
        $lineBytes = (array) DB::table('recurring_invoice_profile_lines')->find($line->id);
        $this->assertSame(0, Artisan::call('contract-lines:backfill-v0'));
        $dryOutput = Artisan::output();
        $this->assertStringContainsString('contracts_to_write=1 lines_to_write=1 skipped=0', $dryOutput);
        $this->assertSame($before, $this->counts());
        $this->assertSame(0, Artisan::call('contract-lines:backfill-v0', ['--commit' => true]));
        $this->assertSame($dryOutput, Artisan::output());
        $after = $this->counts();
        foreach ($before as $table => $count) {
            $this->assertSame($count + (in_array(basename(str_replace('.', '/', $table)), ['contract_lines', 'contract_activities'], true) ? 1 : 0), $after[$table], $table);
        }
        $this->assertSame($profileBytes, (array) DB::table('recurring_invoice_profiles')->find($profile->id));
        $this->assertSame($lineBytes, (array) DB::table('recurring_invoice_profile_lines')->find($line->id));
        $copy = (array) DB::table('contract_lines')->first();
        foreach (['sku_id', 'description', 'quantity_type', 'fixed_quantity', 'unit_price', 'pricing_tiers', 'is_taxable', 'sort_order'] as $column) {
            $this->assertSame($lineBytes[$column], $copy[$column], $column);
        }
        $this->assertSame(0, $copy['version']);
        $this->assertSame('backfill_v0', $copy['source']);
        $this->assertSame($line->id, $copy['source_profile_line_id']);
        $this->assertSame($profile->contract_id, $copy['contract_id']);
        $activity = ContractActivity::where('action', 'contract_lines_backfilled_v0')->sole();
        $this->assertSame(['line_count' => 1, 'line_ids' => [$copy['id']]], $activity->changes);
        $this->assertNull($activity->user_id);
        $this->assertSame($profile->contract_id, $activity->contract_id);
        $this->assertTrue(ContractLine::first()->contract->is($profile->contract));
        $this->assertSame(1, $profile->contract->lines()->count());
        $this->assertStringNotContainsString($line->description, $dryOutput);
        $this->assertStringNotContainsString('17.23', $dryOutput);
        $this->assertSame(0, Artisan::call('contract-lines:backfill-v0', ['--commit' => true]));
        $output = Artisan::output();
        $this->assertStringContainsString('already backfilled', $output);
        $this->assertStringContainsString('lines_to_write=0', $output);
        $this->assertSame($after, $this->counts());
    }

    public function test_ineligible_profiles_and_empty_profile_then_later_backfill(): void
    {
        $inactive = $this->profile();
        $inactive->update(['is_active' => false]);
        $deleted = $this->profile();
        DB::table('contracts')->where('id', $deleted->contract_id)->update(['deleted_at' => now()]);
        $multi = $this->profile();
        $sibling = $multi->replicate();
        $sibling->save();
        $empty = $this->profile(false);
        $this->assertSame(0, Artisan::call('contract-lines:drift', ['--contract' => $empty->contract_id]));
        $this->assertStringContainsString('drift=0', Artisan::output());
        foreach ([false, true] as $commit) {
            $this->assertSame(0, Artisan::call('contract-lines:backfill-v0', ['--commit' => $commit]));
            $output = Artisan::output();
            foreach (['inactive profile', 'soft-deleted contract', 'multiple active profiles', 'empty profile'] as $reason) {
                $this->assertStringContainsString($reason, $output);
            }
            foreach ([$inactive, $deleted, $multi, $sibling, $empty] as $profile) {
                $this->assertMatchesRegularExpression('/\|\s*'.$profile->contract_id.'\s*\|\s*'.$profile->id.'\s*\|/', $output);
            }
            $this->assertStringContainsString('contracts_to_write=0 lines_to_write=0 skipped=5', $output);
            $this->assertDatabaseCount('contract_lines', 0);
            $this->assertDatabaseCount('contract_activities', 0);
        }
        $this->line($empty);
        $this->assertSame(0, Artisan::call('contract-lines:backfill-v0', ['--commit' => true]));
        $this->assertDatabaseCount('contract_lines', 1);
        $this->assertDatabaseCount('contract_activities', 1);
        $this->assertDatabaseHas('contract_lines', ['contract_id' => $empty->contract_id, 'version' => 0, 'source' => 'backfill_v0']);
    }

    public function test_rollback_is_dry_by_default_and_source_version_and_contract_scoped(): void
    {
        $a = $this->profile();
        $b = $this->profile();
        Artisan::call('contract-lines:backfill-v0', ['--commit' => true]);
        $copy = ContractLine::where('contract_id', $a->contract_id)->first();
        foreach ([['version' => 0, 'source' => 'synthetic_other'], ['version' => 1, 'source' => 'backfill_v0']] as $attributes) {
            $other = $copy->replicate();
            $other->fill($attributes + ['source_profile_line_id' => null]);
            $other->save();
        }
        ContractActivity::create(['contract_id' => $a->contract_id, 'action' => 'synthetic_other', 'changes' => [], 'created_at' => now()]);
        $before = $this->counts();
        $this->assertSame(0, Artisan::call('contract-lines:backfill-v0', ['--rollback' => true, '--contract' => $a->contract_id]));
        $this->assertStringContainsString('lines_to_delete=1 activities_to_delete=1', Artisan::output());
        $this->assertSame($before, $this->counts());
        $this->assertSame(0, Artisan::call('contract-lines:backfill-v0', ['--rollback' => true, '--commit' => true, '--contract' => $a->contract_id]));
        $this->assertDatabaseMissing('contract_lines', ['id' => $copy->id]);
        $this->assertDatabaseCount('contract_lines', 3);
        $this->assertDatabaseCount('contract_activities', 2);
        $this->assertDatabaseHas('contract_lines', ['contract_id' => $b->contract_id]);
        $this->assertSame(0, Artisan::call('contract-lines:backfill-v0', ['--rollback' => true, '--commit' => true]));
        $this->assertDatabaseCount('contract_lines', 2);
        $this->assertDatabaseCount('contract_activities', 1);
        $this->assertDatabaseHas('contract_activities', ['action' => 'synthetic_other']);
    }

    public function test_mid_run_failure_keeps_previous_contract_and_rolls_back_failed_contract(): void
    {
        $a = $this->profile();
        $b = $this->profile();
        ContractActivity::creating(function ($activity) use ($b) {
            if ($activity->contract_id === $b->contract_id) {
                throw new RuntimeException('synthetic private failure detail');
            }
        });
        try {
            $this->assertSame(1, Artisan::call('contract-lines:backfill-v0', ['--commit' => true]));
            $output = Artisan::output();
            $this->assertStringContainsString('status=failed class=RuntimeException', $output);
            $this->assertStringNotContainsString('synthetic private failure detail', $output);
            $this->assertDatabaseHas('contract_lines', ['contract_id' => $a->contract_id]);
            $this->assertDatabaseMissing('contract_lines', ['contract_id' => $b->contract_id]);
            $this->assertDatabaseCount('contract_activities', 1);
        } finally {
            ContractActivity::flushEventListeners();
        }
    }

    public function test_contract_filter_and_invalid_filter_do_not_widen_writes(): void
    {
        $a = $this->profile();
        $b = $this->profile();
        $this->assertSame(1, Artisan::call('contract-lines:backfill-v0', ['--commit' => true, '--contract' => 'invalid']));
        $this->assertDatabaseCount('contract_lines', 0);
        $this->assertSame(0, Artisan::call('contract-lines:backfill-v0', ['--commit' => true, '--contract' => $a->contract_id]));
        $this->assertDatabaseCount('contract_lines', 1);
        $this->assertDatabaseMissing('contract_lines', ['contract_id' => $b->contract_id]);
    }
}
