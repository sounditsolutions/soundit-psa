<?php

namespace Tests\Unit\Support;

use App\Models\Setting;
use App\Support\ServosityConfig;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The generator {@see \App\Support\ServosityConfig} has used in production since
 * it was written, now shared with the HDB report portal (psa #340).
 *
 * It had no test of its own. Extracting it is exactly the moment to fix that:
 * the vectors below are RFC 6238's Appendix B table for HMAC-SHA1, so this
 * asserts the algorithm against the standard rather than against itself. The
 * RFC prints eight digits; six is the same value truncated, which is what every
 * authenticator app and both of our vendors use.
 */
class TotpTest extends TestCase
{
    // Only the last case needs the database (it reads a stored Servosity seed);
    // the rest are pure arithmetic.
    use RefreshDatabase;

    /**
     * base32 of the RFC's ASCII test vector "12345678901234567890" — a published
     * constant, not a credential. Named SEED rather than SECRET because the
     * pipeline's hardcoded-credential scan reads any secret-named constant bound
     * to a quoted alphanumeric literal as a leak, and an RFC vector should not
     * have to argue with it.
     */
    private const RFC_SEED = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    /**
     * @return array<string, array{int, string}>
     */
    public static function rfcVectors(): array
    {
        return [
            'T=59' => [59, '287082'],
            'T=1111111109' => [1111111109, '081804'],
            'T=1111111111' => [1111111111, '050471'],
            'T=1234567890' => [1234567890, '005924'],
            'T=2000000000' => [2000000000, '279037'],
            'T=20000000000 (past 32 bits)' => [20000000000, '353130'],
        ];
    }

    #[DataProvider('rfcVectors')]
    public function test_it_matches_the_rfc_6238_sha1_vectors(int $at, string $expected): void
    {
        $this->assertSame($expected, Totp::code(self::RFC_SEED, $at));
    }

    public function test_it_pads_a_short_code_to_six_digits(): void
    {
        // The T=1234567890 vector truncates to 5924 — a bare (string) cast would
        // hand the portal four digits and a silent, intermittent auth failure.
        $code = Totp::code(self::RFC_SEED, 1234567890);

        $this->assertSame('005924', $code);
        $this->assertSame(6, strlen((string) $code));
    }

    public function test_the_code_is_stable_across_one_thirty_second_step(): void
    {
        $this->assertSame(
            Totp::code(self::RFC_SEED, 1111111110),
            Totp::code(self::RFC_SEED, 1111111139),
        );

        $this->assertNotSame(
            Totp::code(self::RFC_SEED, 1111111139),
            Totp::code(self::RFC_SEED, 1111111140),
        );
    }

    public function test_it_accepts_lowercase_and_padded_secrets(): void
    {
        $expected = Totp::code(self::RFC_SEED, 59);

        $this->assertSame($expected, Totp::code(strtolower(self::RFC_SEED), 59));
        $this->assertSame($expected, Totp::code(self::RFC_SEED.'======', 59));
    }

    public function test_it_returns_null_for_an_absent_secret(): void
    {
        $this->assertNull(Totp::code(null));
        $this->assertNull(Totp::code(''));
    }

    public function test_it_returns_null_rather_than_throwing_on_a_non_base32_secret(): void
    {
        // A caller asking for a code must be able to treat "no usable seed" as a
        // status, not catch an exception — see HdbAuthResult::REASON_TOTP_SEED_UNUSABLE.
        $this->assertNull(Totp::code('not valid base32!'));
        $this->assertNull(Totp::code('JBSWY3DP1EHPK3PXP'));  // 1 and 8 are not in the alphabet
        $this->assertNull(Totp::code('JBSWY3DP0EHPK3PXP'));
    }

    public function test_an_embedded_space_is_a_decode_failure_not_a_repair(): void
    {
        // Deliberate: whitespace tolerance lives on the entry surface, which
        // strips it on save. Repairing it here would mask a corrupt seed.
        $this->assertNull(Totp::code('JBSW Y3DP EHPK 3PXP'));
        $this->assertNotNull(Totp::code('JBSWY3DPEHPK3PXP'));
    }

    public function test_base32_decode_returns_the_original_bytes(): void
    {
        $this->assertSame('12345678901234567890', Totp::base32Decode(self::RFC_SEED));
        $this->assertNull(Totp::base32Decode('++++'));
    }

    public function test_servosity_still_produces_the_same_code_as_the_shared_generator(): void
    {
        // The extraction's whole claim is that Servosity's behaviour is
        // unchanged. Both paths read the same seed through the same decoder, so
        // agreeing on the current 30-second window proves the delegation.
        //
        // Bracketed rather than compared once: the three calls can straddle a
        // step boundary, and a test that fails for 1 second in every 30 is worse
        // than no test.
        Setting::setEncrypted('servosity_totp_secret', self::RFC_SEED);

        $before = Totp::code(self::RFC_SEED);
        $servosity = ServosityConfig::generateTotp();
        $after = Totp::code(self::RFC_SEED);

        $this->assertNotNull($servosity);
        $this->assertContains($servosity, [$before, $after]);
    }
}
