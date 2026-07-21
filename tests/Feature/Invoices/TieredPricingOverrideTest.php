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
 * The SKU's pricing method is a DEFAULT, not a constraint. A line that carries
 * its own graduated bands OVERRIDES the SKU's volume rate card — the same
 * line-beats-product precedence as `unit_cost_override ?? sku->unit_cost` — and
 * every door that can set up the combination ALLOWS it:
 *
 *   1. profile line gains graduated bands on a volume-card SKU  (store)
 *   2. ditto                                                    (update)
 *   3. lines are bulk-flipped onto Backup Storage (GB)          (bulkAction)
 *   4. a SKU gains volume tiers under an already-graduated line (SKU form)
 *
 * What is NOT allowed is the override being invisible. A code-precedence rule
 * plus a server log is not something the person making the billing decision can
 * see, so the applied method must be stated where billing is set and reviewed:
 * beside the graduated toggle, on the profile line, in the invoice preview, and
 * in the invoice line's quantity_source audit record.
 */
class TieredPricingOverrideTest extends TestCase
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

    /** One 300 GB backup-storage line whose graduated bands override the SKU card. */
    private function overridingLine(RecurringInvoiceProfile $profile, Sku $sku): RecurringInvoiceProfileLine
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

    public function test_store_saves_graduated_bands_on_a_line_whose_sku_carries_a_volume_rate_card(): void
    {
        $contract = $this->contract();
        $sku = $this->backupSku();
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
                        ['up_to' => 500, 'unit_price' => 0.80],
                        ['up_to' => '', 'unit_price' => 0.60],
                    ],
                ],
            ],
        ])->assertSessionHasNoErrors()->assertRedirect();

        // The line saved exactly as configured: graduated bands on the line,
        // volume card still on the SKU. The line's bands win at billing time.
        $line = RecurringInvoiceProfileLine::firstOrFail();
        $this->assertTrue($line->isTiered());
        $this->assertCount(3, $line->pricingTiers());
        $this->assertSame(3, $sku->fresh()->backupStorageTiers()->count());
    }

    /** Graduated bands on a SKU with no volume card — the plain case, unchanged. */
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

    public function test_update_saves_graduated_bands_onto_an_existing_line_of_a_volume_card_sku(): void
    {
        $contract = $this->contract();
        $profile = $this->profile($contract);
        $sku = $this->backupSku();

        // Currently a flat backup line billing the SKU's volume rate card.
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

        // The operator's explicit line-level choice was saved over the SKU default.
        $line = $profile->fresh()->lines()->firstOrFail();
        $this->assertTrue($line->isTiered());
        $this->assertCount(2, $line->pricingTiers());
        $this->assertSame(3, $sku->fresh()->backupStorageTiers()->count());
    }

    /** Turning graduated pricing OFF hands the line back to the SKU's volume card. */
    public function test_update_can_turn_graduated_pricing_off_on_an_overriding_line(): void
    {
        $contract = $this->contract();
        $profile = $this->profile($contract);
        $sku = $this->backupSku();
        $this->overridingLine($profile, $sku);

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
     * Bulk-flipping graduated lines onto Backup Storage (GB) puts the SKU's
     * volume card in play under the lines' bands. That is now a supported
     * override, not a refusal — the flip applies and the bands keep winning.
     */
    public function test_bulk_set_quantity_type_flips_graduated_lines_onto_backup_storage(): void
    {
        $profile = $this->profile($this->contract());
        $sku = $this->backupSku();

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

        $this->assertNull(session('error'));
        $this->assertNotNull(session('success'));

        // The flip happened, and the operator's bands survived it.
        $fresh = $line->fresh();
        $this->assertSame(QuantityType::PerBackupStorageGb, $fresh->quantity_type);
        $this->assertTrue($fresh->isTiered());
    }

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

    // ── Door 4: the SKU form (the other side of the same combination) ──

    /**
     * The operator edits the *product* and adds a volume rate card while a
     * recurring line already prices that product with graduated bands. Allowed:
     * the card is this product's default for lines that do not bring their own
     * bands, and the already-graduated line keeps overriding it.
     */
    public function test_sku_update_saves_volume_tiers_while_a_line_prices_that_sku_with_graduated_bands(): void
    {
        $sku = $this->backupSku(withVolumeTiers: false);
        $profile = $this->profile($this->contract());
        $line = $this->overridingLine($profile, $sku);

        $this->actingAs($this->actor());

        $this->patch(route('skus.update', $sku), $this->skuPayload($sku, [
            'tiers' => [
                ['up_to_gb' => '100', 'unit_price' => '1.00'],
                ['up_to_gb' => '', 'unit_price' => '0.60'],
            ],
        ]))->assertSessionHasNoErrors()->assertRedirect();

        // The volume rate card was created; the line's bands are untouched.
        $this->assertSame(2, $sku->fresh()->backupStorageTiers()->count());
        $this->assertTrue($line->fresh()->isTiered());
    }

    /** Clearing the volume tiers hands non-graduated lines back to flat pricing. */
    public function test_sku_can_clear_its_volume_tiers_under_a_graduated_line(): void
    {
        $sku = $this->backupSku(); // has the volume card
        $profile = $this->profile($this->contract());
        $this->overridingLine($profile, $sku);

        $this->actingAs($this->actor());

        $this->patch(route('skus.update', $sku), $this->skuPayload($sku))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertSame(0, $sku->fresh()->backupStorageTiers()->count());
    }

    /** A graduated per-user line never reads the volume card; the SKU form is unaffected by it. */
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

    /** Flat backup lines are the normal case for a volume rate card. */
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

    // ── The money: the line's bands win over the SKU's card, and say so ──

    /**
     * The precedence the whole override rests on. The money is the whole
     * assertion: $260 is graduated, $240 is volume. A test that only checked
     * "two lines exist" would pass either way.
     */
    public function test_an_overriding_line_bills_graduated_money_and_names_the_applied_card(): void
    {
        Log::spy();

        $profile = $this->profile($this->contract($this->client()));
        $this->overridingLine($profile, $this->backupSku());

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

        // A supported, operator-visible configuration is not a warning. The
        // runtime still leaves an info breadcrumb naming the winner.
        Log::shouldHaveReceived('info')->withArgs(
            fn ($message, $context = []) => str_contains($message, 'overriding')
        )->atLeast()->once();
        Log::shouldNotHaveReceived('warning', [
            \Mockery::on(fn ($message) => str_contains((string) $message, 'volume rate card')),
            \Mockery::any(),
        ]);
    }

    // ── Visibility: the operator must see the override where they set and review it ──

    public function test_profile_show_states_the_override_on_an_overriding_line(): void
    {
        $profile = $this->profile($this->contract($this->client()));
        $this->overridingLine($profile, $this->backupSku());

        $this->actingAs($this->actor());

        $this->get(route('profiles.show', $profile))
            ->assertOk()
            ->assertSee('Graduated')                       // the card that prices it...
            ->assertSee('overrides SKU volume tiers')      // ...beside the toggle, on the line
            ->assertSee('a line-level setting wins');      // ...and stated outright in the notice
    }

    /** No volume card on the SKU → nothing is overridden → no override notice. */
    public function test_profile_show_shows_no_override_notice_when_the_sku_has_no_volume_card(): void
    {
        $profile = $this->profile($this->contract($this->client()));
        $this->overridingLine($profile, $this->backupSku(withVolumeTiers: false));

        $this->actingAs($this->actor());

        $this->get(route('profiles.show', $profile))
            ->assertOk()
            ->assertDontSee('overrides SKU volume tiers')
            ->assertDontSee('a line-level setting wins');
    }

    /**
     * The inline note beside the graduated toggle is driven by a per-SKU marker
     * on the option element — the operator must see "this overrides the SKU's
     * volume tiers" at the moment they flip the toggle, not after saving.
     *
     * Both screens deliver that marker twice over, and both are asserted here:
     *
     *   - as data-has-volume-tiers on the SERVER-RENDERED options (the first line
     *     on create, and one per existing line on show), and
     *   - as hasVolumeTiers in the SKU_OPTIONS data island, which is what
     *     fillSelectOptions stamps onto lines added client-side by "Add Line".
     *
     * psa-951q moved the second one: it used to be option markup spliced into a
     * JavaScript template literal, where an operator-entered SKU name could break
     * out of the string. It is now inert JSON, but it must still carry the marker
     * — a dynamically added line whose SKU is silently unmarked is exactly the
     * invisible-to-the-operator override the product lane rejected.
     */
    public function test_profile_forms_mark_volume_card_skus_for_the_inline_method_note(): void
    {
        $contract = $this->contract();
        $profile = $this->profile($contract);
        $withCard = $this->backupSku();
        $without = $this->backupSku(withVolumeTiers: false);

        $this->actingAs($this->actor());

        foreach ([route('profiles.create', $contract), route('profiles.show', $profile)] as $url) {
            $html = $this->get($url)->assertOk()->getContent();

            $options = $this->skuOptionData($html, $url);

            $this->assertSame(
                '1',
                $options[$withCard->id]['data']['hasVolumeTiers'] ?? null,
                "volume-card SKU must be marked in the option data on {$url}",
            );
            $this->assertSame(
                '0',
                $options[$without->id]['data']['hasVolumeTiers'] ?? null,
                "card-less SKU must not be marked in the option data on {$url}",
            );
        }

        // The server-rendered options carry the same marker as an attribute.
        // Only asserted on create, because show renders one select per existing
        // profile line and this profile deliberately has none.
        $html = $this->get(route('profiles.create', $contract))->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/value="'.$withCard->id.'"[^>]*data-has-volume-tiers="1"/s',
            $html,
            'volume-card SKU option must be marked in the server-rendered select',
        );
        $this->assertMatchesRegularExpression(
            '/value="'.$without->id.'"[^>]*data-has-volume-tiers="0"/s',
            $html,
            'card-less SKU option must not be marked in the server-rendered select',
        );
    }

    /**
     * The SKU_OPTIONS data island the line editor builds client-side options
     * from, decoded and keyed by SKU id.
     *
     * @return array<int, array{value: string, label: string, data: array<string, string>}>
     */
    private function skuOptionData(string $html, string $url): array
    {
        $this->assertSame(
            1,
            preg_match('/const SKU_OPTIONS = (\[.*?\]);\n/s', $html, $m),
            "no SKU_OPTIONS data island on {$url}",
        );

        $decoded = json_decode($m[1], true);
        $this->assertIsArray($decoded, "SKU_OPTIONS on {$url} is not valid JSON");

        return collect($decoded)->keyBy(fn ($o) => (int) $o['value'])->all();
    }

    /**
     * psa-x47y — CHARACTERISATION GUARD for the shared tier-editor partial.
     *
     * The graduated-tier editor and its override note were duplicated verbatim
     * between profiles/create and profiles/show (7 identical JS functions, 75
     * lines). That duplication is a billing-trust hazard rather than a cosmetic
     * one: updatePricingMethodNote is what tells an operator, at the moment they
     * flip the toggle, that this line's graduated bands will override the SKU's
     * volume rate card. Charlie's 2026-07-13 ruling is that the override is
     * ALLOWED but must never be SILENT — so a drift on ONE copy silently
     * reintroduces exactly the invisible-to-the-operator defect the product lane
     * originally rejected.
     *
     * This asserts the behaviour is present and identical on BOTH screens. It
     * passed before the extraction and must keep passing after it — that is the
     * point: the refactor is behaviour-preserving, and this is what proves it.
     */
    public function test_both_profile_forms_ship_the_same_tier_editor_behaviour(): void
    {
        $contract = $this->contract();
        $profile = $this->profile($contract);

        $this->actingAs($this->actor());

        // The whole tier-editor surface both screens must carry, including the
        // real-money note string itself.
        $required = [
            'function tierRowHtml',
            'function addTier',
            'function removeTier',
            'function toggleTiered',
            'function syncTierBasePrice',
            'function syncTierBasePriceFromLine',
            'function updatePricingMethodNote',
            // NB: the note lives inside a JS string literal, so the rendered HTML
            // carries an escaped apostrophe (SKU\'s). Match on a fragment either
            // side of it rather than the full sentence.
            'Overrides the SKU',
            'volume storage tiers',
            'pricing-method-note',
            'per_backup_storage_gb',
        ];

        foreach ([route('profiles.create', $contract), route('profiles.show', $profile)] as $url) {
            $html = $this->get($url)->assertOk()->getContent();

            foreach ($required as $needle) {
                $this->assertStringContainsString(
                    $needle,
                    $html,
                    "tier-editor surface must be present on {$url} — missing: {$needle}",
                );
            }
        }
    }

    /** The last screen before Generate says which card priced each row. */
    public function test_preview_names_the_graduated_card_on_an_overriding_line(): void
    {
        $profile = $this->profile($this->contract($this->client()));
        $this->overridingLine($profile, $this->backupSku());

        $this->actingAs($this->actor());

        $preview = $this->get(route('profiles.preview', $profile))
            ->assertOk()
            ->json();

        $this->assertSame(260.0, round((float) $preview['subtotal'], 2));
        foreach ($preview['lines'] as $row) {
            $this->assertStringContainsString('[graduated: 3 bands]', $row['quantity_source']);
            $this->assertStringNotContainsString('volume tier rate', $row['quantity_source']);
        }
    }

    /** The SKU form is the other place the decision is made — it names the lines that override its card. */
    public function test_sku_edit_page_lists_profile_lines_that_override_its_volume_tiers(): void
    {
        $sku = $this->backupSku();
        $profile = $this->profile($this->contract());
        $this->overridingLine($profile, $sku);

        $this->actingAs($this->actor());

        $this->get(route('skus.edit', $sku))
            ->assertOk()
            ->assertSee('override these volume tiers')
            ->assertSee($profile->name);
    }

    public function test_sku_edit_page_shows_no_override_note_without_graduated_backup_lines(): void
    {
        $sku = $this->backupSku();
        $profile = $this->profile($this->contract());

        // A flat backup line — priced by the SKU's card, overriding nothing.
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

        $this->get(route('skus.edit', $sku))
            ->assertOk()
            ->assertDontSee('override these volume tiers');
    }
}
