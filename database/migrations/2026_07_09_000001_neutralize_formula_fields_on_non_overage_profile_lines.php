<?php

use App\Enums\QuantityType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Quantity resolution now applies the included-allowance / divisor formula
     * to every dynamic quantity type, not just Overage (GitHub #126). Before
     * this change the formula fields were only honoured for Overage lines, but
     * the profile form's SKU auto-fill could still populate
     * `included_per_base_unit` on a non-Overage line (a hidden, ignored value).
     *
     * Honouring those stale values now would silently change billed quantities
     * on existing profiles. Neutralise them so the generalisation starts from a
     * clean slate: any non-Overage line that never intentionally configured an
     * allowance keeps counting exactly as it did before.
     */
    public function up(): void
    {
        DB::table('recurring_invoice_profile_lines')
            ->where('quantity_type', '!=', QuantityType::Overage->value)
            ->whereNotNull('included_per_base_unit')
            ->update(['included_per_base_unit' => null]);
    }

    public function down(): void
    {
        // Irreversible: the neutralised values were UI auto-fill artifacts that
        // were never applied to billing, so there is nothing meaningful to
        // restore.
    }
};
