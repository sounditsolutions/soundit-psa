<?php

namespace App\Console\Commands;

use App\Enums\InvoiceStatus;
use App\Models\InvoiceLineDescriptionRepair;
use App\Models\Sku;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Repair invoice line descriptions corrupted by the pre-psa-951q SKU picker
 * (psa-946hr).
 *
 * The invoice create/edit views ran e() inside {{ }} on the SKU name, so the
 * data-description attribute was HTML-escaped twice. The browser decodes one
 * level when parsing the attribute, onSkuSelected() copied the still-encoded
 * remainder into the description input, and it was saved: what PERSISTS is the
 * once-encoded form — 'Acme & Co' landed as 'Acme &amp; Co'. The repair is
 * therefore exactly ONE htmlspecialchars_decode(ENT_QUOTES) pass, the precise
 * inverse of one e() application, applied AT MOST ONCE per line ever (enforced
 * by the invoice_line_description_repairs ledger — a legitimately
 * entity-looking result must never be decoded a second time).
 *
 * Charlie's option-(b) ruling: issued/finalized invoices are the historical
 * record — printed for clients and mirrored into QBO/Stripe — and must never
 * be rewritten. The selector is a fail-closed ALLOWLIST: only lines whose
 * invoice is byte-exactly status 'draft', not soft-deleted, and free of any
 * external billing id are ever written. Empty, NULL, or unknown statuses are
 * simply never equal to 'draft', so they fall on the untouched side.
 *
 * Dry-run by default (deliberately inverted from the --dry-run convention of
 * the billing:backfill-* commands: for a command that rewrites financial rows,
 * mutation is what must be opted INTO). The no-flag run doubles as the prod
 * sizing query: it surveys every matching line across all statuses and sync
 * states without touching anything.
 */
class RepairDoubleEscapedInvoiceDescriptions extends Command
{
    protected $signature = 'invoices:repair-double-escaped-descriptions
        {--write : Apply the repairs. Without this flag the command is a DRY RUN and changes nothing.}
        {--yes : Skip the interactive confirmation before writing (for scripted runs).}
        {--revert : Restore ledger-recorded pre-repair descriptions instead of repairing (also a dry run unless --write).}';

    protected $description = 'Repair invoice line descriptions double-escaped by the pre-psa-951q SKU picker (draft, unsynced invoices only; dry-run by default)';

    /**
     * The exact entity set e() (htmlspecialchars, ENT_QUOTES) emits — the only
     * strings the double-escape bug could have persisted. No LIKE wildcards in
     * any of them. Deliberately narrow: other entities (&eacute;, &mdash;, …)
     * are not products of this bug and must not be touched.
     */
    private const SIGNATURES = ['&amp;', '&#039;', '&quot;', '&lt;', '&gt;'];

    private const LOG_PREFIX = '[InvoiceDescriptionRepair]';

    public function handle(): int
    {
        $write = (bool) $this->option('write');

        if ($this->option('revert')) {
            return $this->handleRevert($write);
        }

        return $this->handleRepair($write);
    }

    // -------------------------------------------------------------------------
    // Repair
    // -------------------------------------------------------------------------

    private function handleRepair(bool $write): int
    {
        $matches = $this->collectMatches();

        $this->printSurvey($matches);

        // Fail-closed allowlist, applied byte-strictly in PHP where comparison
        // semantics are certain (MariaDB's ci collation would let SQL equality
        // match a hypothetical 'DRAFT'): only a draft, never soft-deleted,
        // never carrying an external billing id, is repairable.
        $eligible = $matches->filter(fn ($row) => $row->invoice_status === InvoiceStatus::Draft->value
            && $row->invoice_deleted_at === null
            && $row->qbo_invoice_id === null
            && $row->stripe_invoice_id === null);

        $anomalies = $matches->filter(fn ($row) => $row->invoice_status === InvoiceStatus::Draft->value
            && $row->invoice_deleted_at === null
            && ($row->qbo_invoice_id !== null || $row->stripe_invoice_id !== null));

        if ($anomalies->isNotEmpty()) {
            $this->warn(sprintf(
                '%d matching line(s) sit on DRAFT invoices that already carry an external billing id — excluded (fail closed), review manually: line id(s) %s',
                $anomalies->count(),
                $anomalies->pluck('line_id')->implode(', '),
            ));
        }

        $this->info(sprintf('%d line(s) eligible for repair (draft, unsynced, not deleted).', $eligible->count()));

        $ledgered = InvoiceLineDescriptionRepair::whereIn('invoice_line_id', $eligible->pluck('line_id'))
            ->pluck('invoice_line_id')
            ->all();

        [$alreadyRepaired, $repairable] = $eligible->partition(fn ($row) => in_array($row->line_id, $ledgered, true));

        if ($alreadyRepaired->isNotEmpty()) {
            $this->line(sprintf(
                '%d line(s) already repaired in a previous run (ledger) — skipped, never decoded twice: line id(s) %s',
                $alreadyRepaired->count(),
                $alreadyRepaired->pluck('line_id')->implode(', '),
            ));
        }

        if ($repairable->isEmpty()) {
            $this->info('Nothing to repair.');

            return self::SUCCESS;
        }

        $skuNames = Sku::whereIn('id', $repairable->pluck('sku_id')->filter()->unique())
            ->pluck('name', 'id');

        foreach ($repairable as $row) {
            $annotations = [];
            if ($row->sku_id !== null
                && isset($skuNames[$row->sku_id])
                && e($skuNames[$row->sku_id]) === $row->description) {
                $annotations[] = 'matches once-escaped SKU name — certain picker corruption';
            }
            if ($this->containsSignature($row->after)) {
                $annotations[] = 'still entity-looking after one decode — the ledger will prevent any further decode; review manually';
            }

            $this->line(sprintf(
                '  line #%d (invoice %s): "%s" -> "%s"%s',
                $row->line_id,
                $row->invoice_number,
                $row->description,
                $row->after,
                $annotations === [] ? '' : ' ['.implode('; ', $annotations).']',
            ));
        }

        if (! $write) {
            $this->info(sprintf('[DRY RUN] Would repair %d line(s). Re-run with --write to apply.', $repairable->count()));

            return self::SUCCESS;
        }

        if (! $this->option('yes')
            && ! $this->confirm(sprintf('Apply %d repair(s) to draft invoice lines?', $repairable->count()))) {
            $this->info('Refused — nothing written. Pass --yes to skip the confirmation.');

            return self::SUCCESS;
        }

        $repaired = 0;
        foreach ($repairable as $row) {
            $done = DB::transaction(function () use ($row) {
                // Guarded write: only lands if the description is still the
                // exact value the dry-run reported (no concurrent edit).
                $updated = DB::table('invoice_lines')
                    ->where('id', $row->line_id)
                    ->where('description', $row->description)
                    ->update(['description' => $row->after, 'updated_at' => now()]);

                if ($updated !== 1) {
                    return false;
                }

                InvoiceLineDescriptionRepair::create([
                    'invoice_line_id' => $row->line_id,
                    'invoice_id' => $row->invoice_id,
                    'description_before' => $row->description,
                    'description_after' => $row->after,
                    'invoice_status_at_repair' => $row->invoice_status,
                ]);

                return true;
            });

            if (! $done) {
                $this->warn(sprintf('  line #%d changed concurrently — skipped.', $row->line_id));

                continue;
            }

            Log::info(self::LOG_PREFIX." Repaired line #{$row->line_id} (invoice {$row->invoice_number}): \"{$row->description}\" -> \"{$row->after}\"");
            $repaired++;
        }

        $this->info(sprintf('Repaired %d line(s). Every change is recorded in invoice_line_description_repairs.', $repaired));

        return self::SUCCESS;
    }

    // -------------------------------------------------------------------------
    // Revert
    // -------------------------------------------------------------------------

    private function handleRevert(bool $write): int
    {
        $pending = InvoiceLineDescriptionRepair::whereNull('reverted_at')->orderBy('id')->get();

        if ($pending->isEmpty()) {
            $this->info('Nothing to revert — no un-reverted ledger entries.');

            return self::SUCCESS;
        }

        $lines = DB::table('invoice_lines')
            ->join('invoices', 'invoices.id', '=', 'invoice_lines.invoice_id')
            ->whereIn('invoice_lines.id', $pending->pluck('invoice_line_id'))
            ->select([
                'invoice_lines.id as line_id',
                'invoice_lines.description',
                'invoices.invoice_number',
                'invoices.status as invoice_status',
                'invoices.deleted_at as invoice_deleted_at',
                'invoices.qbo_invoice_id',
                'invoices.stripe_invoice_id',
            ])
            ->get()
            ->keyBy('line_id');

        $revertable = collect();
        foreach ($pending as $entry) {
            $line = $lines->get($entry->invoice_line_id);

            if ($line === null) {
                $this->warn(sprintf('  ledger #%d: line #%d no longer exists — cannot revert.', $entry->id, $entry->invoice_line_id));

                continue;
            }

            // Byte-strict: a repair is only undone onto the exact value it wrote.
            if ($line->description !== $entry->description_after) {
                $this->warn(sprintf('  ledger #%d: line #%d edited since repair — skipped.', $entry->id, $entry->invoice_line_id));

                continue;
            }

            // The option-(b) ruling cuts both ways: once the invoice is
            // finalized, even our own earlier repair is part of the record.
            if ($line->invoice_status !== InvoiceStatus::Draft->value
                || $line->invoice_deleted_at !== null
                || $line->qbo_invoice_id !== null
                || $line->stripe_invoice_id !== null) {
                $this->warn(sprintf('  ledger #%d: invoice %s is no longer a repairable draft — skipped.', $entry->id, $line->invoice_number));

                continue;
            }

            $this->line(sprintf(
                '  line #%d (invoice %s): "%s" -> "%s"',
                $entry->invoice_line_id,
                $line->invoice_number,
                $entry->description_after,
                $entry->description_before,
            ));
            $revertable->push($entry);
        }

        if ($revertable->isEmpty()) {
            $this->info('Nothing revertable.');

            return self::SUCCESS;
        }

        if (! $write) {
            $this->info(sprintf('[DRY RUN] Would revert %d repair(s). Re-run with --revert --write to apply.', $revertable->count()));

            return self::SUCCESS;
        }

        if (! $this->option('yes')
            && ! $this->confirm(sprintf('Revert %d repair(s) to their pre-repair descriptions?', $revertable->count()))) {
            $this->info('Refused — nothing written. Pass --yes to skip the confirmation.');

            return self::SUCCESS;
        }

        $reverted = 0;
        foreach ($revertable as $entry) {
            $done = DB::transaction(function () use ($entry) {
                $updated = DB::table('invoice_lines')
                    ->where('id', $entry->invoice_line_id)
                    ->where('description', $entry->description_after)
                    ->update(['description' => $entry->description_before, 'updated_at' => now()]);

                if ($updated !== 1) {
                    return false;
                }

                $entry->update(['reverted_at' => now()]);

                return true;
            });

            if (! $done) {
                $this->warn(sprintf('  line #%d changed concurrently — skipped.', $entry->invoice_line_id));

                continue;
            }

            Log::info(self::LOG_PREFIX." Reverted line #{$entry->invoice_line_id}: \"{$entry->description_after}\" -> \"{$entry->description_before}\"");
            $reverted++;
        }

        $this->info(sprintf('Reverted %d repair(s).', $reverted));

        return self::SUCCESS;
    }

    // -------------------------------------------------------------------------
    // Collection + survey
    // -------------------------------------------------------------------------

    /**
     * Every invoice line whose description decodes differently — i.e. contains
     * at least one entity e() could have emitted. Manual join so soft-deleted
     * invoices are INCLUDED here (the survey must size the full blast radius;
     * the repair gates exclude them later). No model hydration: statuses are
     * compared as raw bytes, so an invalid stored status can neither crash an
     * enum cast nor sneak past a lenient comparison.
     *
     * @return Collection<int, object>
     */
    private function collectMatches(): Collection
    {
        $query = DB::table('invoice_lines')
            ->join('invoices', 'invoices.id', '=', 'invoice_lines.invoice_id')
            ->where(function ($q) {
                foreach (self::SIGNATURES as $signature) {
                    $q->orWhere('invoice_lines.description', 'like', '%'.$signature.'%');
                }
            })
            ->select([
                'invoice_lines.id as line_id',
                'invoice_lines.invoice_id',
                'invoice_lines.description',
                'invoice_lines.sku_id',
                'invoices.invoice_number',
                'invoices.status as invoice_status',
                'invoices.deleted_at as invoice_deleted_at',
                'invoices.qbo_invoice_id',
                'invoices.stripe_invoice_id',
            ])
            ->orderBy('invoice_lines.id');

        $matches = collect();
        foreach ($query->cursor() as $row) {
            // LIKE is case-insensitive on both MariaDB and SQLite, so the SQL
            // above is a superset prefilter. The decode itself is the precise
            // predicate: it changes the string iff a decodable entity (exact
            // case) is present, and it is the exact inverse of one e() pass.
            $after = htmlspecialchars_decode($row->description, ENT_QUOTES);
            if ($after === $row->description) {
                continue;
            }

            $row->after = $after;
            $matches->push($row);
        }

        return $matches;
    }

    /**
     * The sizing report Charlie was promised: every matching line, bucketed by
     * invoice status and external sync state, including the rows the command
     * will refuse to touch.
     */
    private function printSurvey(Collection $matches): void
    {
        $this->info(sprintf('%d matching line(s) found across all invoices (any status, incl. soft-deleted).', $matches->count()));

        if ($matches->isEmpty()) {
            return;
        }

        $known = array_column(InvoiceStatus::cases(), 'value');

        $rows = $matches
            ->groupBy(fn ($row) => (string) $row->invoice_status)
            ->sortKeys()
            ->map(function (Collection $group, string $status) use ($known) {
                $label = $status === '' ? '(empty)' : $status;
                if (! in_array($status, $known, true)) {
                    $label .= ' (INVALID)';
                }

                return [
                    $label,
                    $group->count(),
                    $group->whereNotNull('qbo_invoice_id')->count(),
                    $group->whereNotNull('stripe_invoice_id')->count(),
                    $group->whereNotNull('invoice_deleted_at')->count(),
                ];
            })
            ->values()
            ->all();

        $this->table(['Invoice status', 'Lines', 'QBO-synced', 'Stripe-synced', 'Soft-deleted'], $rows);
    }

    private function containsSignature(string $value): bool
    {
        foreach (self::SIGNATURES as $signature) {
            if (str_contains($value, $signature)) {
                return true;
            }
        }

        return false;
    }
}
