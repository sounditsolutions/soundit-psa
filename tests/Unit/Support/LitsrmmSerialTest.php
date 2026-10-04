<?php

namespace Tests\Unit\Support;

use App\Support\LitsrmmSerial;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A serial match is an identity claim that writes into the billing system of
 * record, so it must only be made on a real manufacturer identity.
 *
 * The failure this guards is NOT several machines sharing a placeholder — the
 * matcher sees ambiguity there and refuses. It is the quiet opposite: exactly
 * one asset and exactly one incoming device both carrying "Default string"
 * joining AS CERTAIN, silently merging two different machines into one asset.
 */
class LitsrmmSerialTest extends TestCase
{
    /**
     * THE TWO THAT ARE LIVE IN THIS ESTATE TODAY. BandCDesktop reports
     * "Default string" and Zachary-PC reports "System Serial Number". They do
     * not collide only because those two placeholders happen to differ — a
     * third machine reporting "Default string" would merge into BandCDesktop.
     */
    #[Test]
    public function it_refuses_the_placeholders_this_estate_actually_reports(): void
    {
        $this->assertNull(LitsrmmSerial::identity('Default string'));
        $this->assertNull(LitsrmmSerial::identity('System Serial Number'));
    }

    public static function placeholders(): array
    {
        return [
            ['To Be Filled By O.E.M.'],
            ['TO BE FILLED BY OEM'],
            ['tobefilledbyoem'],
            ['Not Applicable'],
            ['unknown'],
            ['N/A'],
            ['none'],
            ['0123456789'],
        ];
    }

    #[Test]
    #[DataProvider('placeholders')]
    public function it_refuses_every_spelling_of_a_non_value(string $raw): void
    {
        // Punctuation and case are ignored when testing the placeholder
        // family, because the goal there is catching every spelling of the
        // same non-value.
        $this->assertNull(LitsrmmSerial::identity($raw));
    }

    #[Test]
    public function it_accepts_a_real_service_tag(): void
    {
        $this->assertSame('7QXY2M3', LitsrmmSerial::identity('7QXY2M3'));
    }

    #[Test]
    public function it_keeps_punctuation_because_vendors_distinguish_it(): void
    {
        // ABC-123 and ABC123 are different serials on some vendors.
        // Collapsing them would invent matches.
        $this->assertSame('ABC-123X', LitsrmmSerial::identity('ABC-123X'));
        $this->assertNotSame(
            LitsrmmSerial::identity('ABC-123X'),
            LitsrmmSerial::identity('ABC123X'),
        );
    }

    #[Test]
    public function it_normalises_case_and_surrounding_whitespace(): void
    {
        $this->assertSame('ABC-123', LitsrmmSerial::identity('  abc-123  '));
        $this->assertSame('ABC 123', LitsrmmSerial::identity("abc   123"));
    }

    #[Test]
    public function it_refuses_null_and_blank(): void
    {
        $this->assertNull(LitsrmmSerial::identity(null));
        $this->assertNull(LitsrmmSerial::identity(''));
        $this->assertNull(LitsrmmSerial::identity('   '));
    }

    #[Test]
    public function it_refuses_a_value_too_short_to_be_an_identity(): void
    {
        // Real service tags start at seven characters (Dell) and run longer.
        $this->assertNull(LitsrmmSerial::identity('123'));
        $this->assertNull(LitsrmmSerial::identity('-'));
    }

    #[Test]
    public function it_refuses_one_character_repeated(): void
    {
        // 0000000 and XXXXXXX are long enough to pass a length check and are
        // still not identities.
        $this->assertNull(LitsrmmSerial::identity('0000000'));
        $this->assertNull(LitsrmmSerial::identity('XXXXXXX'));
    }

    /**
     * The property that matters most, stated directly: two different machines
     * that both report a placeholder must NOT produce the same match key,
     * because a shared key is what merges them.
     */
    #[Test]
    public function two_placeholder_machines_never_share_a_match_key(): void
    {
        $a = LitsrmmSerial::identity('Default string');
        $b = LitsrmmSerial::identity('Default string');

        $this->assertNull($a);
        $this->assertNull($b);
        // Null is not a key. Both fall through to hostname matching instead,
        // which is weaker and knows it is weaker.
    }
}
