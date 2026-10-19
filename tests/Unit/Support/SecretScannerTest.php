<?php

namespace Tests\Unit\Support;

use App\Support\SecretScanner;
use PHPUnit\Framework\TestCase;

/**
 * psa-6vfw1 (#5): the dangerous-FILENAME guard — the gap that let
 * `.env.bak-pre-msgraph-20260708T195933Z` get tracked and pushed to the public
 * repo (so-ssoj). The existing gc-verify guard scans diff CONTENT for token
 * shapes; it never inspects filenames, so a secret-bearing file whose contents
 * don't match those shapes (APP_KEY / DB_PASSWORD / HALO / PLIVO) sailed through.
 */
class SecretScannerTest extends TestCase
{
    /**
     * @dataProvider dangerousPaths
     */
    public function test_flags_secret_bearing_files(string $path): void
    {
        $this->assertNotNull(
            SecretScanner::dangerousReason($path),
            "expected {$path} to be flagged as secret-bearing"
        );
    }

    public static function dangerousPaths(): array
    {
        return [
            'plain .env' => ['.env'],
            'env variant' => ['.env.production'],
            'the exact leaked file' => ['.env.bak-pre-msgraph-20260708T195933Z'],
            'nested env' => ['config/.env.local'],
            'deploy env (ends .env)' => ['scripts/deploy.env'],
            'rotation backup' => ['.env.rotate-bak-20260708'],
            'plain .bak' => ['database.bak'],
            'suffixed .bak' => ['dump.sql.bak-old'],
            'storage key' => ['storage/oauth-private.key'],
            'pkcs12' => ['cert.p12'],
            'ssh private key' => ['id_rsa'],
        ];
    }

    /**
     * @dataProvider safePaths
     */
    public function test_does_not_flag_safe_files(string $path): void
    {
        $this->assertNull(
            SecretScanner::dangerousReason($path),
            "expected {$path} NOT to be flagged"
        );
    }

    public static function safePaths(): array
    {
        return [
            'env example' => ['.env.example'],
            'docker env example' => ['.env.docker.example'],
            'production env example' => ['.env.production.example'],
            'deploy env example' => ['scripts/deploy.env.example'],
            'public ssh key' => ['id_rsa.pub'],
            // .pem is NOT flagged by name — it is as often a public cert / CA chain;
            // private-key MATERIAL is caught by content instead (dangerousContentReason).
            'public certificate (pem)' => ['certs/fullchain.pem'],
            'ordinary php' => ['app/Support/SecretScanner.php'],
            'readme' => ['README.md'],
            'composer' => ['composer.json'],
            'a php file that merely mentions env' => ['app/Support/AppTimezone.php'],
        ];
    }

    public function test_scan_returns_only_offenders_keyed_by_path(): void
    {
        $offenders = SecretScanner::scan([
            'README.md',
            '.env.bak-pre-msgraph-20260708T195933Z',
            'app/Support/SecretScanner.php',
            'storage/app.key',
            '.env.example',
        ]);

        $this->assertSame(
            ['.env.bak-pre-msgraph-20260708T195933Z', 'storage/app.key'],
            array_keys($offenders)
        );
        $this->assertIsString($offenders['storage/app.key']);
    }

    public function test_flags_embedded_private_key_content(): void
    {
        // Built by concatenation so this test's OWN source never contains the
        // contiguous marker (which gc-verify.sh's GUARD_RE would flag in the diff).
        $marker = '-----BEGIN RSA '.'PRIVATE KEY-----';
        $this->assertNotNull(SecretScanner::dangerousContentReason($marker."\nMIIE...redacted...\n"));
        $this->assertNotNull(SecretScanner::dangerousContentReason('-----BEGIN '.'PRIVATE KEY-----'));
    }

    public function test_does_not_flag_ordinary_or_absent_content(): void
    {
        $this->assertNull(SecretScanner::dangerousContentReason('class Foo { public function bar() {} }'));
        $this->assertNull(SecretScanner::dangerousContentReason('-----BEGIN '.'CERTIFICATE-----')); // a public cert body is safe
        $this->assertNull(SecretScanner::dangerousContentReason(null));
        $this->assertNull(SecretScanner::dangerousContentReason(''));
    }
}
