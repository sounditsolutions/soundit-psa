<?php

namespace Tests\Feature\Invoices;

use App\Enums\QuantityType;
use App\Models\Asset;
use App\Models\BackupStorageTier;
use App\Models\Client;
use App\Models\Contract;
use App\Models\RecurringInvoiceProfile;
use App\Models\RecurringInvoiceProfileLine;
use App\Models\Sku;
use App\Models\User;
use App\Services\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * GRADUATED (profile line) and VOLUME (SKU) rate cards bill DIFFERENT MONEY from
 * identical numbers: 300 GB over the rates 1.00 / 0.80 / 0.60 is $260 graduated
 * (100 @ 1.00 + 200 @ 0.80) but $240 volume (the whole 300 at the single 0.80
 * band that covers it).
 *
 * A real-money ambiguity like that must not be settled by a code-precedence rule
 * and a server log the operator will never read. So the combination is REFUSED at
 * every door that can create it — and there is more than one:
 *
 *   1. profile line gains graduated bands on a volume-card SKU  (store)
 *   2. ditto                                                    (update)
 *   3. lines are bulk-flipped onto Backup Storage (GB)          (bulkAction)
 *   4. a SKU gains volume tiers under an already-graduated line (SKU form)
 *
 * Validation only stops NEW conflicts, so a row that predates the guard must
 * still bill deterministically (graduated wins, loudly) — and the profile page
 * must say so before anyone presses Generate.
 */
class TieredPricingConflictTest extends TestCase
{
    use RefreshDatabase;

    private const GB = 1073741824; // 1024^3 bytes

    /** The bands the profile line carries. 300 GB → $260. */
    private const GRADUATED = [
        ['up_to' => 100, 'unit_price' => 1.00],
        ['up_to' => 500, 'unit_price' => 0.80],
        ['up_to' => null, 'unit_price' => 0.60],
    ];

    private function actor(): User
    {
        return User::factory()->create();
    }

    /** A client with exactly 300 GB of measured backup usage. */
    private function client(): Client
    {
        $client = Client::factory()->create();
        Asset::factory()->create(['client_id' => $client->id, 'backup_cloud_bytes' => 200 * self::GB]);
        Asset::factory()->create(['client_id' => $client->id, 'backup_cloud_bytes' => 100 * self::GB]);

        return $client;
    }

    private function contract(?Client $client = null): Contract
    {
        return Contract::create([
            'client_id' => ($client ?? Client::factory()->create())->id,
            'name' => 'Managed Services',
            'type' => 'managed',
            'status' => 'active',
            'billing_source' => 'psa',
            'billing_period' => 'monthly',
            'billing_day' => 1,
            'payment_terms_days' => 30,
            'start_date' => '2026-01-01',
        ]);
    }

    private function profile(Contract $contract): RecurringInvoiceProfile
    {
        return RecurringInvoiceProfile::create([
            'contract_id' => $contract->id,
            'name' => 'Backup profile',
            'is_active' => true,
            'billing_period' => 'monthly',
            'billing_day' => 1,
            'payment_terms_days' => 30,
            'next_run_date' => '2026-08-01',
        ]);
    }

    /** A backup-storage SKU. Carries the VOLUME rate card only when asked. */
    private function backupSku(bool $withVolumeTiers = true): Sku
    {
        $sku = Sku::create([
            'name' => 'Cloud Backup Storage',
            'sku_code' => 'BKP-GB-'.fake()->unique()->numerify('####'),
            'unit_price' => '0.50',
            'unit_cost' => '0.00',
            'default_quantity_type' => QuantityType::PerBackupStorageGb,
            'is_taxable' => true,
            'is_active' => true,
        ]);

        if ($withVolumeTiers) {
            foreach ([
                ['up_to_gb' => 100, 'unit_price' => '1.00'],
                ['up_to_gb' => 500, 'unit_price' => '0.80'],
                ['up_to_gb' => null, 'unit_price' => '0.60'],
            ] as $i => $tier) {
                BackupStorageTier::create([
                    'sku_id' => $sku->id,
                    'up_to_gb' => $tier['up_to_gb'],
                    'unit_price' => $tier['unit_price'],
                    'sort_order' => $i,
                ]);
            }
        }

        return $sku;
    }

    /** The valid part of a SKU update payload (everything the form requires). */
    private function skuPayload(Sku $sku, array $overrides = []): array
    {
        return array_merge([
            'name' => $sku->name,
            'sku_code' => $sku->sku_code,
            'unit_price' => '0.50',
            'unit_cost' => '0.00',
            'default_quantity_type' => QuantityType::PerBackupStorageGb->value,
            'is_taxable' => '1',
            'is_active' => '1',
        ], $overrides);
    }

    /** One 300 GB backup-storage line, written straight to the DB (no validation). */
    private function conflictingLine(RecurringInvoiceProfile $profile, Sku $sku): RecurringInvoiceProfileLine
    {
        return RecurringInvoiceProfileLine::create([
            'profile_id' => $profile->id,
            'sku_id' => $sku->id,
            'description' => 'Cloud backup storage',
            'unit_price' => '1.00',
            'pricing_tiers' => self::GRADUATED,
            'quantity_type' => QuantityType::PerBackupStorageGb,
            'is_taxable' => true,
            'sort_order' => 0,
        ]);
    }

    // ── Door 1: the profile-line form, create ──

    public function test_store_refuses_graduated_bands_on_a_line_whose_sku_carries_a_volume_rate_card(): void
    {
        $contract = $this->contract();
        $sku = $this->backupSku();
        $this->actingAs($this->actor());

        $response = $this->from(route('profiles.create', $contract))
            ->post(route('profiles.store', $contract), [
                'name' => 'Backup billing',
                'billing_period' => 'monthly',
                'billing_day' => 1,
                'payment_terms_days' => 30,
                'next_run_date' => '2026-09-01',
                'lines' => [
                    [
                        'sku_id' => $sku->id,
                        'description' => 'Cloud backup storage',
                        'unit_price' => 1.00,
                        'quantity_type' => QuantityType::PerBackupStorageGb->value,
                        'is_taxable' => 1,
                        'pricing_tiers' => [
                            ['up_to' => 100, 'unit_price' => 1.00],
                            ['up_to' => 500, 'unit_price' => 0.80],
                            ['up_to' => '', 'unit_price' => 0.60],
                        ],
                    ],
                ],
            ]);

        $response->assertSessionHasErrors('lines.0.pricing_tiers');
        $this->assertStringContainsString(
            'volume tiers',
            session('errors')->first('lines.0.pricing_tiers'),
        );

        // Nothing was written — not the profile, not the line.
        $this->assertSame(0, RecurringInvoiceProfile::count());
        $this->assertSame(0, RecurringInvoiceProfileLine::count());
    }

    /** The feature itself must still work: graduated bands on a SKU with no volume card. */
    public function test_store_allows_graduated_bands_when_the_sku_has_no_volume_rate_card(): void
    {
        $contract = $this->contract();
        $sku = $this->backupSku(withVolumeTiers: false);
        $this->actingAs($this->actor());

        $this->post(route('profiles.store', $contract), [
            'name' => 'Backup billing',
            'billing_period' => 'monthly',
            'billing_day' => 1,
            'payment_terms_days' => 30,
            'next_run_date' => '2026-09-01',
            'lines' => [
                [
                    'sku_id' => $sku->id,
                    'description' => 'Cloud backup storage',
                    'unit_price' => 1.00,
                    'quantity_type' => QuantityType::PerBackupStorageGb->value,
                    'is_taxable' => 1,
                    'pricing_tiers' => [
                        ['up_to' => 100, 'unit_price' => 1.00],
                        ['up_to' => '', 'unit_price' => 0.80],
                    ],
                ],
            ],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $line = RecurringInvoiceProfileLine::firstOrFail();
        $this->assertTrue($line->isTiered());
        $this->assertCount(2, $line->pricingTiers());
    }

    // ── Door 2: the profile-line form, edit ──

    public function test_update_refuses_the_conflict_and_leaves_the_existing_line_untouched(): void
    {
        $contract = $this->contract();
        $profile = $this->profile($contract);
        $sku = $this->backupSku();

        // Currently a safe, flat backup line billing the volume rate card.
        $existing = RecurringInvoiceProfileLine::create([
            'profile_id' => $profile->id,
            'sku_id' => $sku->id,
            'description' => 'Cloud backup storage',
            'unit_price' => '0.50',
            'quantity_type' => QuantityType::PerBackupStorageGb,
            'is_taxable' => true,
            'sort_order' => 0,
        ]);

        $this->actingAs($this->actor());

        $this->from(route('profiles.show', $profile))
            ->patch(route('profiles.update', $profile), [
                'name' => $profile->name,
                'billing_period' => 'monthly',
                'billing_day' => 1,
                'payment_terms_days' => 30,
                'next_run_date' => '2026-08-01',
                'lines' => [
                    [
                        'sku_id' => $sku->id,
                        'description' => 'Cloud backup storage',
                        'unit_price' => 1.00,
                        'quantity_type' => QuantityType::PerBackupStorageGb->value,
                        'is_taxable' => 1,
                        'pricing_tiers' => [
                            ['up_to' => 100, 'unit_price' => 1.00],
                            ['up_to' => '', 'unit_price' => 0.80],
                        ],
                    ],
                ],
            ])
            ->assertSessionHasErrors('lines.0.pricing_tiers');

        // update() replaces every line, so the refusal has to land BEFORE the
        // destructive rewrite — the original flat line must survive intact.
        $this->assertSame(1, RecurringInvoiceProfileLine::count());

        $line = $existing->fresh();
        $this->assertNotNull($line);
        $this->assertFalse($line->isTiered());
        $this->assertNull($line->pricing_tiers);
        $this->assertSame(0.50, (float) $line->unit_price);
    }

    /** Turning graduated pricing OFF must always be possible — it is a way out of an existing conflict. */
    public function test_update_can_always_turn_graduated_pricing_off_on_a_conflicting_line(): void
    {
        $contract = $this->contract();
        $profile = $this->profile($contract);
        $sku = $this->backupSku();
        $this->conflictingLine($profile, $sku);

        $this->actingAs($this->actor());

        $this->patch(route('profiles.update', $profile), [
            'name' => $profile->name,
            'billing_period' => 'monthly',
            'billing_day' => 1,
            'payment_terms_days' => 30,
            'next_run_date' => '2026-08-01',
            'lines' => [
                [
                    'sku_id' => $sku->id,
                    'description' => 'Cloud backup storage',
                    'unit_price' => 0.50,
                    'quantity_type' => QuantityType::PerBackupStorageGb->value,
                    'is_taxable' => 1,
                ],
            ],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $line = $profile->fresh()->lines()->firstOrFail();
        $this->assertFalse($line->isTiered());
    }

    // ── Door 3: bulk "set quantity type" ──

    /**
     * The door the profile form does not cover. Bulk-flipping lines onto Backup
     * Storage (GB) puts the SKU's volume card in play under bands that were
     * configured while it was not — the same ambiguity, no form validation in
     * sight.
     */
    public function test_bulk_set_quantity_type_refuses_to_create_the_conflict(): void
    {
        $profile = $this->profile($this->contract());
        $sku = $this->backupSku();

        // A graduated line that does NOT currently consult the volume card
        // (per-user lines never do) — flipping it to Backup Storage (GB) would.
        $line = RecurringInvoiceProfileLine::create([
            'profile_id' => $profile->id,
            'sku_id' => $sku->id,
            'description' => 'Cloud backup storage',
            'unit_price' => '1.00',
            'pricing_tiers' => self::GRADUATED,
            'quantity_type' => QuantityType::PerUser,
            'is_taxable' => true,
            'sort_order' => 0,
        ]);

        $this->actingAs($this->actor());

        $this->post(route('profiles.bulk-action'), [
            'action' => 'set_quantity_type',
            'profile_ids' => [$profile->id],
            'target_sku_id' => $sku->id,
            'new_quantity_type' => QuantityType::PerBackupStorageGb->value,
        ])->assertRedirect();

        $this->assertNotNull(session('error'));
        $this->assertStringContainsString('graduated', strtolower(session('error')));
        // The refusal points at the config to change, by name.
        $this->assertStringContainsString($profile->name, session('error'));

        // The flip did not happen.
        $this->assertSame(QuantityType::PerUser, $line->fresh()->quantity_type);
    }

    /** The bulk action must still work when it creates no conflict. */
    public function test_bulk_set_quantity_type_still_works_without_a_volume_rate_card(): void
    {
        $profile = $this->profile($this->contract());
        $sku = $this->backupSku(withVolumeTiers: false);

        $line = RecurringInvoiceProfileLine::create([
            'profile_id' => $profile->id,
            'sku_id' => $sku->id,
            'description' => 'Cloud backup storage',
            'unit_price' => '1.00',
            'pricing_tiers' => self::GRADUATED,
            'quantity_type' => QuantityType::PerUser,
            'is_taxable' => true,
            'sort_order' => 0,
        ]);

        $this->actingAs($this->actor());

        $this->post(route('profiles.bulk-action'), [
            'action' => 'set_quantity_type',
            'profile_ids' => [$profile->id],
            'target_sku_id' => $sku->id,
            'new_quantity_type' => QuantityType::PerBackupStorageGb->value,
        ])->assertRedirect();

        $this->assertSame(QuantityType::PerBackupStorageGb, $line->fresh()->quantity_type);
    }

    // ── Door 4: the SKU form (the other side of the same conflict) ──

    /**
     * The reviewer named only the profile-line door. This is the other one: the
     * operator edits the *product* and adds a volume rate card while a recurring
     * line already prices that product with graduated bands. Guarding only the
     * profile form leaves the invoice silently billing a model nobody chose.
     */
    public function test_sku_update_refuses_volume_tiers_while_a_line_prices_that_sku_with_graduated_bands(): void
    {
        $sku = $this->backupSku(withVolumeTiers: false);
        $profile = $this->profile($this->contract());
        $this->conflictingLine($profile, $sku);

        $this->actingAs($this->actor());

        $this->from(route('skus.edit', $sku))
            ->patch(route('skus.update', $sku), $this->skuPayload($sku, [
                'tiers' => [
                    ['up_to_gb' => '100', 'unit_price' => '1.00'],
                    ['up_to_gb' => '', 'unit_price' => '0.60'],
                ],
            ]))
            ->assertSessionHasErrors('tiers');

        // The volume rate card was NOT created.
        $this->assertSame(0, $sku->fresh()->backupStorageTiers()->count());

        // And the operator is told where the conflict is, by name.
        $this->assertStringContainsString(
            $profile->name,
            session('errors')->first('tiers'),
        );
    }

    /** Clearing the volume tiers must always be possible — the other way out of an existing conflict. */
    public function test_sku_can_always_clear_its_volume_tiers_even_under_a_graduated_line(): void
    {
        $sku = $this->backupSku(); // has the volume card
        $profile = $this->profile($this->contract());
        $this->conflictingLine($profile, $sku); // pre-existing conflict

        $this->actingAs($this->actor());

        $this->patch(route('skus.update', $sku), $this->skuPayload($sku))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertSame(0, $sku->fresh()->backupStorageTiers()->count());
    }

    /**
     * Precision, not a blunt instrument: a graduated line that is NOT a
     * backup-storage line never consults the volume card, so it is not a
     * conflict and must not block the operator from configuring the rate card.
     */
    public function test_sku_update_allows_volume_tiers_when_the_graduated_line_is_not_a_backup_storage_line(): void
    {
        $sku = $this->backupSku(withVolumeTiers: false);
        $profile = $this->profile($this->contract());

        RecurringInvoiceProfileLine::create([
            'profile_id' => $profile->id,
            'sku_id' => $sku->id,
            'description' => 'Per-user thing',
            'unit_price' => '1.00',
            'pricing_tiers' => self::GRADUATED,
            'quantity_type' => QuantityType::PerUser, // never reads the volume card
            'is_taxable' => true,
            'sort_order' => 0,
        ]);

        $this->actingAs($this->actor());

        $this->patch(route('skus.update', $sku), $this->skuPayload($sku, [
            'tiers' => [['up_to_gb' => '100', 'unit_price' => '1.00']],
        ]))->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame(1, $sku->fresh()->backupStorageTiers()->count());
    }

    /** Flat backup lines are the normal case for a volume rate card — never block them. */
    public function test_sku_update_allows_volume_tiers_when_lines_price_that_sku_flat(): void
    {
        $sku = $this->backupSku(withVolumeTiers: false);
        $profile = $this->profile($this->contract());

        RecurringInvoiceProfileLine::create([
            'profile_id' => $profile->id,
            'sku_id' => $sku->id,
            'description' => 'Cloud backup storage',
            'unit_price' => '0.50',
            'quantity_type' => QuantityType::PerBackupStorageGb,
            'is_taxable' => true,
            'sort_order' => 0,
        ]);

        $this->actingAs($this->actor());

        $this->patch(route('skus.update', $sku), $this->skuPayload($sku, [
            'tiers' => [['up_to_gb' => '100', 'unit_price' => '1.00']],
        ]))->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame(1, $sku->fresh()->backupStorageTiers()->count());
    }

    // ── Defence in depth: a row that predates the guard ──

    /**
     * Validation only stops NEW conflicts. A row written before this guard
     * existed (or straight through the model, as here) must still bill
     * deterministically — and say which card priced it.
     *
     * The money is the whole assertion: $260 is graduated, $240 is volume. A
     * test that only checked "two lines exist" would pass either way.
     */
    public function test_a_preexisting_conflicting_row_still_bills_deterministically_and_says_so(): void
    {
        Log::spy();

        $profile = $this->profile($this->contract($this->client()));
        $this->conflictingLine($profile, $this->backupSku());

        $invoice = app(BillingService::class)->generateInvoice($profile->fresh())['invoice'];

        // Graduated wins: 100 GB @ $1.00 + 200 GB @ $0.80 = $260. Volume would be $240.
        $this->assertSame(260.00, round((float) $invoice->subtotal, 2));
        $this->assertNotSame(240.00, round((float) $invoice->subtotal, 2));
        $this->assertCount(2, $invoice->lines);
        $this->assertSame([100.00, 160.00], $invoice->lines->map(fn ($l) => round((float) $l->amount, 2))->all());
        $this->assertSame([1.00, 0.80], $invoice->lines->map(fn ($l) => round((float) $l->unit_price, 2))->all());

        // The audit snapshot names the card that actually priced it, never the one it overrode.
        $this->assertStringContainsString('[graduated: 3 bands]', $invoice->lines[0]->quantity_source);
        $this->assertStringNotContainsString('volume tier rate', $invoice->lines[0]->quantity_source);

        // And the runtime still shouts about the ambiguity it had to resolve.
        Log::shouldHaveReceived('warning')->withArgs(
            fn ($message, $context = []) => str_contains($message, 'volume rate card')
        )->atLeast()->once();
    }

    // ── Visibility: the operator must see it before pressing Generate ──

    public function test_profile_show_flags_a_preexisting_conflict_and_names_the_applied_rate_card(): void
    {
        $profile = $this->profile($this->contract($this->client()));
        $this->conflictingLine($profile, $this->backupSku());

        $this->actingAs($this->actor());

        $this->get(route('profiles.show', $profile))
            ->assertOk()
            ->assertSee('Graduated')                 // the card that will price it
            ->assertSee('volume tiers', false)       // ...over the one it overrides
            ->assertSee('Graduated pricing wins');   // stated outright, not left to a log
    }

    /** A profile with no conflict must not be papered with a scary warning. */
    public function test_profile_show_does_not_warn_when_there_is_no_conflict(): void
    {
        $profile = $this->profile($this->contract($this->client()));
        $this->conflictingLine($profile, $this->backupSku(withVolumeTiers: false));

        $this->actingAs($this->actor());

        $this->get(route('profiles.show', $profile))
            ->assertOk()
            ->assertDontSee('Graduated pricing wins');
    }
}
