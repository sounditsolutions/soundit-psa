<?php

namespace Tests\Unit\Security;

use Composer\InstalledVersions;
use Tests\TestCase;

/**
 * Regression guard for CVE-2025-45769 (firebase/php-jwt < 7.0.0).
 *
 * php-jwt v6.x lacks a minimum-key-size check ("weak encryption", advisory
 * PKSA-y2cr-5h3j-g3ys); the fix landed in v7.0.0. The library is pulled in
 * TRANSITIVELY — it is not a direct entry in composer.json — via
 * socialiteproviders/microsoft (Entra SSO) and is also exercised directly by
 * the Teams Bot Framework JWT middleware (VerifyBotFrameworkJwt).
 *
 * Because it is transitive, a future change to socialiteproviders/microsoft's
 * constraint (or a `composer update` that re-resolves the tree) could silently
 * drag php-jwt back onto the vulnerable 6.x line. CI does not currently run
 * `composer audit`, so nothing else would catch that. This test pins the floor
 * so the security fix cannot regress unnoticed.
 */
class PhpJwtVersionFloorTest extends TestCase
{
    private const PACKAGE = 'firebase/php-jwt';

    /** The first release carrying the CVE-2025-45769 fix. */
    private const MIN_SAFE_VERSION = '7.0.0';

    public function test_php_jwt_is_on_or_above_the_cve_2025_45769_patched_line(): void
    {
        $this->assertTrue(
            InstalledVersions::isInstalled(self::PACKAGE),
            self::PACKAGE.' must be installed (it backs Entra SSO and the Teams Bot Framework JWT gate).'
        );

        $installed = InstalledVersions::getVersion(self::PACKAGE);

        $this->assertNotNull($installed, 'Could not resolve the installed '.self::PACKAGE.' version.');
        $this->assertTrue(
            version_compare($installed, self::MIN_SAFE_VERSION, '>='),
            self::PACKAGE." is $installed, below the CVE-2025-45769 floor of ".self::MIN_SAFE_VERSION
            .'. A dependency change re-pinned it onto the vulnerable 6.x line — do not ship this.'
        );
    }
}
