<?php

namespace Tests\Feature\Web;

use App\Enums\InvoiceStatus;
use App\Models\Asset;
use App\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Tests\TestCase;

/**
 * Covers the shared configurability contract added to the reusable list
 * partials (invoices, assets, people, contracts, licenses): optional
 * $columns / $showFilters / $showBulkActions inputs, backwards compatible
 * with null $columns showing every column. Mirrors the pattern already in
 * tickets/_list.blade.php.
 */
class ListPartialConfigTest extends TestCase
{
    use RefreshDatabase;

    private function emptyPaginator(): LengthAwarePaginator
    {
        return new LengthAwarePaginator(collect([]), 0, 15, 1, ['path' => '/']);
    }

    /**
     * Every partial renders cleanly with filters, bulk actions, and columns
     * all disabled. This is also a directive-balance guard: an unbalanced
     * if/endif directive introduced by the wrapping would throw at compile time.
     */
    public function test_all_partials_render_with_filters_and_bulk_actions_disabled(): void
    {
        $off = ['showFilters' => false, 'showBulkActions' => false, 'columns' => []];

        $this->assertStringContainsString('No invoices found', view('invoices._list', array_merge($off, [
            'invoices' => $this->emptyPaginator(),
        ]))->render());

        $this->assertStringContainsString('No assets found', view('assets._list', array_merge($off, [
            'assets' => $this->emptyPaginator(),
        ]))->render());

        $this->assertStringContainsString('No contacts found', view('people._list', array_merge($off, [
            'people' => $this->emptyPaginator(),
            'search' => null,
            'clientId' => null,
            'personType' => null,
        ]))->render());

        $this->assertStringContainsString('No contracts found', view('contracts._list', array_merge($off, [
            'contracts' => $this->emptyPaginator(),
        ]))->render());

        $this->assertStringContainsString('No licenses found', view('licenses._list', array_merge($off, [
            'licenses' => $this->emptyPaginator(),
        ]))->render());
    }

    /**
     * With no options passed, every partial behaves exactly as before —
     * filters and (where present) bulk actions render. Guards backwards
     * compatibility for the existing index pages.
     */
    public function test_partials_default_to_showing_filters_and_bulk_actions(): void
    {
        $invoices = view('invoices._list', [
            'invoices' => $this->emptyPaginator(),
            'clients' => collect([]),
            'statuses' => InvoiceStatus::cases(),
            'filters' => [],
        ])->render();

        // Filter card and bulk action bar both present by default.
        $this->assertStringContainsString('All Statuses', $invoices);
        $this->assertStringContainsString('Push to Billing', $invoices);
    }

    public function test_invoices_partial_can_hide_filters_and_bulk_actions_independently(): void
    {
        $base = [
            'invoices' => $this->emptyPaginator(),
            'clients' => collect([]),
            'statuses' => InvoiceStatus::cases(),
            'filters' => [],
        ];

        // showFilters=false removes the filter card but keeps bulk actions.
        $noFilters = view('invoices._list', array_merge($base, ['showFilters' => false]))->render();
        $this->assertStringNotContainsString('All Statuses', $noFilters);
        $this->assertStringContainsString('Push to Billing', $noFilters);

        // showBulkActions=false removes the bulk bar but keeps filters.
        $noBulk = view('invoices._list', array_merge($base, ['showBulkActions' => false]))->render();
        $this->assertStringNotContainsString('Push to Billing', $noBulk);
        $this->assertStringContainsString('All Statuses', $noBulk);
    }

    public function test_assets_partial_hides_unlisted_columns_and_filters(): void
    {
        $client = Client::factory()->create(['name' => 'Acme Widgets']);
        $asset = Asset::factory()->create([
            'client_id' => $client->id,
            'hostname' => 'ACME-WKS-01',
        ]);

        $base = [
            'assets' => new LengthAwarePaginator(collect([$asset]), 1, 15, 1, ['path' => '/']),
            'filters' => [],
            'clients' => collect([$client]),
            'assetTypes' => collect(['Workstation']),
        ];

        // Defaults: all columns and the filter UI render.
        $default = view('assets._list', $base)->render();
        $this->assertStringContainsString('ACME-WKS-01', $default);
        $this->assertStringContainsString('Last Seen', $default);
        $this->assertStringContainsString('Primary User', $default);
        $this->assertStringContainsString('Search hostname', $default);

        // columns=['device'] keeps only the device column.
        $deviceOnly = view('assets._list', array_merge($base, ['columns' => ['device']]))->render();
        $this->assertStringContainsString('ACME-WKS-01', $deviceOnly);
        $this->assertStringNotContainsString('Last Seen', $deviceOnly);
        $this->assertStringNotContainsString('Primary User', $deviceOnly);

        // showFilters=false drops the filter UI but keeps the table.
        $noFilters = view('assets._list', array_merge($base, ['showFilters' => false]))->render();
        $this->assertStringNotContainsString('Search hostname', $noFilters);
        $this->assertStringContainsString('ACME-WKS-01', $noFilters);
    }
}
