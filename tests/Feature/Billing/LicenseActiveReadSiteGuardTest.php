<?php

namespace Tests\Feature\Billing;

use Tests\TestCase;

/**
 * Source-scan guard for the vendor-billable predicate (License::vendorBillable()).
 *
 * The predicate cannot be delivered as an Eloquent scope — two of the billing read
 * sites are raw `DB::table('licenses')` builders a scope never reaches — so it is
 * applied EXPLICITLY at every site that reads PSA-active licences for billing or a
 * client-readable surface. That leaves one failure mode: a new read site added
 * without anyone deciding whether vendor-held rows belong in it.
 *
 * This test makes that decision mandatory. It counts, per file, the textual
 * predicates that mean "read PSA-active licences" and fails on ANY change. If you
 * added a site: decide whether it bills or is client-readable (apply
 * License::vendorBillable() or conditional aggregation over vendor_status), or
 * record WHY it is exempt, then update the allowlist alongside your change.
 *
 * Dispositions of the current sites:
 * - BillingService: quantity resolvers + staleness — vendorBillable() APPLIED.
 * - ResellerReportController: client-readable — conditional aggregation over
 *   vendor_status (held visible, out of totals).
 * - ClientReportService (via License scopeActive): client-readable — held rows
 *   visible and annotated via the `vendor_held` flag (asserted below).
 * - ClientIntegrationService: staff-facing integration panel counts — EXEMPT
 *   (internal visibility, not billing, not client-readable).
 * - DashboardController: internal license COST estimate — EXEMPT (what we pay a
 *   vendor during a suspension is undocumented; conservatively keep counting).
 * - ScreenConnectCountLicenses / AppRiverLicenseSyncService / License model
 *   deactivation helpers: sync-internal cleanup WRITES, not billing reads — EXEMPT
 *   (the AppRiver hold-out path marks held rows seen before cleanup ever runs).
 * - InvoiceController / PrepayService / ClientService / ContractAssignmentService /
 *   CippWriteScopeResolver: `status = 'active'` on CONTRACTS or other tables, not
 *   licences — EXEMPT.
 */
class LicenseActiveReadSiteGuardTest extends TestCase
{
    private const QUALIFIED = [
        'app/Http/Controllers/Web/ResellerReportController.php' => 1,
        'app/Services/ClientIntegrationService.php' => 1,
        'app/Services/BillingService.php' => 3,
    ];

    private const UNQUALIFIED = [
        'app/Http/Controllers/Web/DashboardController.php' => 1,
        'app/Services/PrepayService.php' => 2,
        'app/Http/Controllers/Web/InvoiceController.php' => 1,
        'app/Console/Commands/ScreenConnectCountLicenses.php' => 1,
        'app/Services/BillingService.php' => 5,
        'app/Services/ClientService.php' => 1,
        'app/Services/ContractAssignmentService.php' => 1,
        'app/Services/Cipp/CippWriteScopeResolver.php' => 2,
        'app/Services/AppRiver/AppRiverLicenseSyncService.php' => 1,
        'app/Models/License.php' => 3,
    ];

    public function test_no_unaccounted_active_license_read_site_exists(): void
    {
        $this->assertPatternCounts("'licenses.status', 'active'", self::QUALIFIED);
        $this->assertPatternCounts("'status', 'active'", self::UNQUALIFIED);
    }

    /**
     * The one active-licence surface that reads through scopeActive() rather than a
     * textual predicate is the client report; its guard is the vendor_held
     * annotation, so its presence is asserted directly.
     */
    public function test_client_report_carries_the_vendor_held_annotation(): void
    {
        $source = file_get_contents(base_path('app/Services/ClientReportService.php'));

        $this->assertStringContainsString(
            "'vendor_held' => \$l->isVendorHeld()",
            $source,
            'ClientReportService::gatherLicenses() no longer carries the vendor_held flag — '
            .'held rows must stay visible-and-annotated on the customer report.',
        );
    }

    private function assertPatternCounts(string $needle, array $expected): void
    {
        $found = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(base_path('app'), \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $count = substr_count(file_get_contents($file->getPathname()), $needle);

            if ($count > 0) {
                $relative = str_replace(base_path().'/', '', $file->getPathname());
                $found[$relative] = $count;
            }
        }

        ksort($found);
        $expectedSorted = $expected;
        ksort($expectedSorted);

        $this->assertSame(
            $expectedSorted,
            $found,
            "The set of `{$needle}` sites under app/ changed. A new PSA-active licence read "
            .'site must decide its vendor-held disposition: apply License::vendorBillable() '
            .'(billing), aggregate conditionally over vendor_status (client-readable surface), '
            .'or document WHY it is exempt — then update this allowlist in the same commit. '
            .'See the class docblock for the current dispositions.',
        );
    }
}
