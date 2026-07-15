<?php

namespace App\Support;

use App\Enums\QuantityType;
use App\Models\RecurringInvoiceProfileLine;
use App\Models\Sku;
use Illuminate\Support\Collection;

/**
 * The one place that decides whether a profile line's GRADUATED bands are
 * overriding its SKU's VOLUME rate card.
 *
 * Those two models bill DIFFERENT MONEY from identical numbers. 300 GB over the
 * rates 1.00 / 0.80 / 0.60:
 *
 *   graduated → 100 @ 1.00 + 200 @ 0.80          = $260
 *   volume    → the whole 300 @ the 0.80 band    = $240
 *
 * Both are legitimate, and the combination is ALLOWED. The SKU's pricing method
 * is a DEFAULT — convenience for the common case — not a constraint; a line
 * that carries its own graduated bands overrides it, exactly like every other
 * line-level override on the generation path (`unit_cost_override ??
 * sku->unit_cost`, `prepaid_time_override ?? sku->prepaid_time_minutes`).
 * BillingService::priceLineSegments() encodes that precedence.
 *
 * What is NOT allowed is the override being invisible. Real money must not
 * hang on a code-precedence rule the operator never sees, so every surface
 * where billing is set or reviewed states which card applies — the tier editor
 * beside the graduated toggle, the profile line on the profile page, the SKU
 * tiers form, the invoice preview, and the invoice line's `quantity_source`
 * audit record. All of them consult THIS predicate: a rule copied into five
 * surfaces is a rule that will drift in four of them, and a UI that disagrees
 * with the invoice about which card priced a line is its own trust problem.
 *
 * The predicate is deliberately NARROW — the override needs all three facts:
 *
 *   (a) the line carries graduated bands, AND
 *   (b) its SKU carries a backup-storage volume rate card, AND
 *   (c) the line's quantity type is Backup Storage (GB).
 *
 * (c) matters: the volume card is only ever consulted for backup-storage lines
 * (see BillingService::resolveUnitPrice()). A graduated per-user line on a SKU
 * that happens to own a storage rate card overrides nothing and bills exactly
 * what its bands say — announcing an override there would teach operators the
 * notice is noise.
 */
class PricingModelOverride
{
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
     * THE predicate. Graduated bands on the line over a volume card that the
     * line's quantity type would otherwise have used — the line's bands win.
     *
     * @param  array<int, mixed>  $graduatedTiers  raw `pricing_tiers` (form input or stored JSON)
     */
    public static function active(?QuantityType $quantityType, array $graduatedTiers, ?Sku $sku): bool
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
        return self::active($line->quantity_type, $line->pricing_tiers ?? [], $line->sku);
    }

    /**
     * Persisted lines that override (or would override) this SKU's volume rate
     * card: they price it with graduated bands AND their quantity type reads
     * the card. The SKU tiers form names them, so the operator configuring the
     * product's default sees exactly which lines will not follow it.
     *
     * Not scoped to active profiles. An inactive profile can be reactivated in
     * bulk without its lines ever being reviewed again, so its overrides are
     * part of the picture too.
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
     * "Profile name (Client)" for each overriding line — so the SKU form can
     * point at the lines that will not follow the card being configured.
     *
     * @param  Collection<int, RecurringInvoiceProfileLine>  $lines
     * @return Collection<int, string>
     */
    public static function profileNames(Collection $lines): Collection
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
