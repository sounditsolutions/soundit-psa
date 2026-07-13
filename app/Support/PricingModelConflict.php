<?php

namespace App\Support;

use App\Enums\QuantityType;
use App\Models\RecurringInvoiceProfileLine;
use App\Models\Sku;
use Illuminate\Support\Collection;

/**
 * The one place that decides whether a profile line's GRADUATED bands and its
 * SKU's VOLUME rate card are both in play at once.
 *
 * Those two models bill DIFFERENT MONEY from identical numbers. 300 GB over the
 * rates 1.00 / 0.80 / 0.60:
 *
 *   graduated → 100 @ 1.00 + 200 @ 0.80          = $260
 *   volume    → the whole 300 @ the 0.80 band    = $240
 *
 * BillingService::priceLineSegments() resolves the collision deterministically
 * (graduated wins, and it says so in the log and in the invoice line's
 * quantity_source) — but a code-precedence rule and a server log are invisible
 * to the person making the billing decision. So the combination is REFUSED
 * before it can be saved. The runtime precedence stays as defence in depth for
 * rows that predate this guard: validation stops new conflicts, it cannot
 * un-write old ones.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHY A PREDICATE AND NOT AN `if` AT EACH FORM
 *
 * There is more than one door into this conflict, and they are not all on the
 * profile-line form:
 *
 *   1. RecurringProfileController::store()      — line gains bands on a card SKU
 *   2. RecurringProfileController::update()     — ditto, on edit
 *   3. RecurringProfileController::bulkAction() — lines bulk-flipped onto
 *                                                 Backup Storage (GB), which is
 *                                                 what puts the SKU's card in play
 *   4. SkuController::syncBackupStorageTiers()  — the *product* gains a volume
 *                                                 card under an already-graduated
 *                                                 line (the other direction)
 *
 * Every one of them asks this class. A rule copied into four places is a rule
 * that will drift in three of them.
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * The predicate is deliberately NARROW — it needs all three facts:
 *
 *   (a) the line carries graduated bands, AND
 *   (b) its SKU carries a backup-storage volume rate card, AND
 *   (c) the line's quantity type is Backup Storage (GB).
 *
 * (c) matters: the volume card is only ever consulted for backup-storage lines
 * (see BillingService::resolveUnitPrice()). A graduated per-user line on a SKU
 * that happens to own a storage rate card overrides nothing, bills exactly what
 * its bands say, and must not be blocked. Guarding wider than the ambiguity
 * would just teach operators that the block is noise.
 */
class PricingModelConflict
{
    /**
     * What the operator is told at the profile-line form. Their two ways out are
     * both named, and both are always open: clearing the SKU's tiers and turning
     * off the line's graduated pricing are never themselves refused.
     */
    public const MESSAGE = 'This SKU already uses backup storage volume tiers. Remove those SKU tiers or turn off graduated pricing on this line.';

    /** Does this SKU carry a backup-storage volume rate card at all? */
    public static function skuHasVolumeRateCard(?Sku $sku): bool
    {
        if (! $sku) {
            return false;
        }

        // Mirrors Sku::priceForStorageGb(): work from the loaded relation when
        // there is one, so a caller that eager-loaded does not re-query, and one
        // that did not still gets a correct answer.
        return $sku->relationLoaded('backupStorageTiers')
            ? $sku->backupStorageTiers->isNotEmpty()
            : $sku->backupStorageTiers()->exists();
    }

    /**
     * Would the SKU's volume rate card price a line of this quantity type? Only
     * Backup Storage (GB) lines ever read it.
     */
    public static function volumeRateCardApplies(?QuantityType $quantityType, ?Sku $sku): bool
    {
        return $quantityType === QuantityType::PerBackupStorageGb
            && self::skuHasVolumeRateCard($sku);
    }

    /**
     * THE predicate. Both rate cards in play on one line — graduated bands over
     * a volume card that the line's quantity type would otherwise have used.
     *
     * @param  array<int, mixed>  $graduatedTiers  raw `pricing_tiers` (form input or stored JSON)
     */
    public static function exists(?QuantityType $quantityType, array $graduatedTiers, ?Sku $sku): bool
    {
        return TieredPricing::normalize($graduatedTiers) !== []
            && self::volumeRateCardApplies($quantityType, $sku);
    }

    /**
     * The same predicate for a persisted line — used by the billing runtime and
     * by the profile UI, so what the operator is shown and what the invoice does
     * cannot disagree.
     */
    public static function onLine(RecurringInvoiceProfileLine $line): bool
    {
        return self::exists($line->quantity_type, $line->pricing_tiers ?? [], $line->sku);
    }

    /**
     * Persisted lines that would conflict if this SKU carried a volume rate card:
     * they price it with graduated bands AND their quantity type reads the card.
     *
     * Not scoped to active profiles. An inactive profile can be reactivated in
     * bulk without its lines ever passing back through form validation, so a
     * conflict parked in one is a conflict waiting to happen — and the operator
     * has a way out either way (the message names the profiles).
     *
     * @return Collection<int, RecurringInvoiceProfileLine>
     */
    public static function graduatedBackupLinesForSku(Sku $sku): Collection
    {
        return RecurringInvoiceProfileLine::query()
            ->with('profile.contract.client')
            ->where('sku_id', $sku->id)
            ->where('quantity_type', QuantityType::PerBackupStorageGb->value)
            ->whereNotNull('pricing_tiers')
            ->get()
            // whereNotNull is only a coarse filter — isTiered() is the real one
            // (a stored list that normalizes away to nothing is not graduated).
            ->filter(fn (RecurringInvoiceProfileLine $line) => $line->isTiered())
            ->values();
    }

    /**
     * What the operator is told at the SKU form: which profiles are pricing this
     * product with graduated bands, so they know exactly where to go to clear it.
     *
     * @param  Collection<int, RecurringInvoiceProfileLine>  $lines
     */
    public static function skuMessage(Collection $lines): string
    {
        $names = self::profileNames($lines);

        return 'This SKU is priced with graduated tiers on '.$lines->count().' recurring profile line(s), '
            .'so it cannot also carry backup storage volume tiers — the two bill different amounts for the same usage. '
            .'Turn off graduated pricing on those lines first, or leave these tiers empty. Affected: '
            .$names->join(', ').'.';
    }

    /**
     * What the operator is told when a bulk quantity-type change would create the
     * conflict. The whole action is refused rather than partly applied: a bulk
     * edit that silently skipped some rows is its own trust problem — you would
     * have to diff the database to find out what it actually did.
     *
     * @param  Collection<int, RecurringInvoiceProfileLine>  $lines
     */
    public static function bulkMessage(Collection $lines): string
    {
        $names = self::profileNames($lines);

        return 'Cannot switch to '.QuantityType::PerBackupStorageGb->label().': '
            .$lines->count().' line(s) price this SKU with graduated tiers, and the SKU carries backup storage '
            .'volume tiers — the two bill different amounts for the same usage. Remove the SKU\'s volume tiers, '
            .'or turn off graduated pricing on those lines first. Affected: '.$names->join(', ').'.';
    }

    /**
     * "Profile name (Client)" for each conflicting line — so every refusal points
     * at the config the operator has to go and change, rather than leaving them
     * to hunt for it.
     *
     * @param  Collection<int, RecurringInvoiceProfileLine>  $lines
     * @return Collection<int, string>
     */
    private static function profileNames(Collection $lines): Collection
    {
        return $lines
            ->map(function (RecurringInvoiceProfileLine $line): string {
                $profile = $line->profile?->name ?? "profile #{$line->profile_id}";
                $client = $line->profile?->contract?->client?->name;

                return $client ? "{$profile} ({$client})" : $profile;
            })
            ->unique()
            ->sort()
            ->values();
    }
}
