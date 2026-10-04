<?php

namespace Tests\Unit\Litsrmm;

use App\Services\Litsrmm\LitsrmmClientException;
use App\Services\Litsrmm\LitsrmmDevice;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The /v1/devices row parser, against the vendor's own capture.
 *
 * The fixture is the verbatim 3-device production capture relayed on card
 * 6ab46339 (24 Sep 2026), customer ids replaced by the vendor. Variants below
 * start from a captured row and change only the fields they name, so each test
 * grades the parser on the vendor's shape rather than on a shape we imagined.
 */
class LitsrmmDeviceParserTest extends TestCase
{
    private static function capture(): array
    {
        return json_decode(
            (string) file_get_contents(__DIR__.'/../../Fixtures/litsrmm/devices-capture-2026-09-24.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }

    /** Captured row $i with $changes applied. */
    private static function row(int $i, array $changes = []): array
    {
        return array_merge(self::capture()['devices'][$i], $changes);
    }

    public function test_every_captured_row_parses_including_the_one_without_an_agent(): void
    {
        $devices = array_map(LitsrmmDevice::fromArray(...), self::capture()['devices']);

        $this->assertCount(3, $devices);
        $this->assertSame(
            ['8f14e45f-ceea-467a-9f38-000000000001', '8f14e45f-ceea-467a-9f38-000000000002', '8f14e45f-ceea-467a-9f38-000000000003'],
            array_map(fn (LitsrmmDevice $d) => $d->id, $devices),
        );

        // Row 3 is the vendor's normal no-agent machine: all seven agent-only
        // fields null and enrollmentState "pending". It is a row, not a fault.
        $pending = $devices[2];
        $this->assertSame('pending', $pending->enrollmentState);
        $this->assertSame('EXAMPLE-LAPTOP-3', $pending->hostname);
        $this->assertSame('c0a80101-0000-4000-8000-000000000002', $pending->clientId);
        $this->assertSame('Example Client B', $pending->clientName);
        foreach (['osName', 'osVersion', 'osBuild', 'agentVersion', 'lastUser', 'firstSeen', 'lastSeen', 'retiredAt'] as $f) {
            $this->assertNull($pending->{$f}, "{$f} is null in the capture and must stay null, not become '' or a default");
        }
    }

    // ---- point 1: lastUser is a bare username, never split on '\' ----

    public function test_last_user_is_kept_verbatim_from_the_capture(): void
    {
        $this->assertSame('exampleuser', LitsrmmDevice::fromArray(self::row(0))->lastUser);
    }

    public function test_last_user_is_never_split_on_a_backslash(): void
    {
        // The vendor says lastUser has no domain and no backslash. If one ever
        // appears, it is data we were not told about, and the parser must not
        // manufacture a DOMAIN\user split out of it.
        $device = LitsrmmDevice::fromArray(self::row(0, ['lastUser' => 'EXAMPLE\\exampleuser']));

        $this->assertSame('EXAMPLE\\exampleuser', $device->lastUser,
            'lastUser must be kept verbatim; splitting on a backslash invents a domain the contract says is absent');
    }

    // ---- point 2: placeholder serials are real data, never identifiers ----

    public function test_the_captured_placeholder_serial_is_kept_but_is_not_matchable(): void
    {
        $device = LitsrmmDevice::fromArray(self::row(0));

        $this->assertSame('System Serial Number', $device->serial, 'the placeholder is real data and is kept');
        $this->assertNull($device->matchableSerial(),
            'a firmware placeholder serial must never be offered as an identifier: unrelated machines would merge');
    }

    #[DataProvider('placeholderSerials')]
    public function test_every_placeholder_form_is_withheld_from_matching(string $serial): void
    {
        $this->assertNull(LitsrmmDevice::fromArray(self::row(2, ['serial' => $serial]))->matchableSerial(),
            "placeholder serial '{$serial}' must not be matchable");
    }

    public static function placeholderSerials(): array
    {
        return [
            'captured form' => ['System Serial Number'],
            'the other vendor-named placeholder' => ['Default string'],
            'case and padding' => ['  DEFAULT STRING '],
            'blank' => ['   '],
            // The vendor's own RMM refuses these too (server/src/rmm/serial.ts);
            // App\Support\LitsrmmSerial is the same list, so both sides agree.
            'OEM filler' => ['To be filled by O.E.M.'],
            'not specified' => ['Not Specified'],
            'a lone zero' => ['0'],
            'too short to identify anything' => ['A1B'],
            'one character repeated' => ['0000000000'],
        ];
    }

    public function test_a_real_serial_is_matchable_and_a_null_serial_is_not(): void
    {
        $this->assertSame('REDACTED0003', LitsrmmDevice::fromArray(self::row(2))->matchableSerial());
        $this->assertNull(LitsrmmDevice::fromArray(self::row(1))->matchableSerial());
    }

    // ---- point 3: ISO 8601 UTC with OPTIONAL fractional seconds ----

    public function test_both_captured_timestamp_forms_parse_to_the_exact_instant(): void
    {
        $whole = LitsrmmDevice::fromArray(self::row(0));
        $fractional = LitsrmmDevice::fromArray(self::row(1));

        $this->assertSame('2026-09-22T03:47:40.000000Z', $whole->firstSeen?->format('Y-m-d\TH:i:s.u\Z'));
        $this->assertSame('2026-09-22T18:20:53.331000Z', $fractional->firstSeen?->format('Y-m-d\TH:i:s.u\Z'),
            'a fractional-second timestamp from the capture must parse, with its milliseconds kept');
        $this->assertSame('2026-09-24T17:47:04.024000Z', $fractional->lastSeen?->format('Y-m-d\TH:i:s.u\Z'));
        $this->assertSame('UTC', $fractional->lastSeen?->getTimezone()->getName());
    }

    #[DataProvider('malformedTimestamps')]
    public function test_a_timestamp_outside_the_documented_form_is_refused(string $value): void
    {
        $this->expectException(LitsrmmClientException::class);
        $this->expectExceptionMessage('unparseable lastSeen');

        LitsrmmDevice::fromArray(self::row(0, ['lastSeen' => $value]));
    }

    public static function malformedTimestamps(): array
    {
        return [
            'offset instead of Z' => ['2026-09-24T01:00:50+00:00'],
            'no zone' => ['2026-09-24T01:00:50'],
            'date only' => ['2026-09-24'],
            'calendar-invalid (PHP would roll it to March)' => ['2026-02-30T00:00:00Z'],
            'empty fraction' => ['2026-09-24T01:00:50.Z'],
        ];
    }

    #[DataProvider('longFractions')]
    public function test_a_fraction_longer_than_microseconds_is_truncated_not_refused(string $value, string $expected): void
    {
        // The relayed notes set no limit on fraction digits. Six is PHP's
        // limit, not the vendor's, so extra digits are dropped rather than
        // failing the row and, with it, the whole walk.
        $device = LitsrmmDevice::fromArray(self::row(0, ['lastSeen' => $value]));

        $this->assertSame($expected, $device->lastSeen?->format('Y-m-d\TH:i:s.u\Z'));
    }

    public static function longFractions(): array
    {
        return [
            'six digits' => ['2026-09-24T01:00:50.123456Z', '2026-09-24T01:00:50.123456Z'],
            'seven digits (.NET round-trip form)' => ['2026-09-22T18:20:53.3310000Z', '2026-09-22T18:20:53.331000Z'],
            'nine digits, truncated not rounded' => ['2026-09-24T01:00:50.123456789Z', '2026-09-24T01:00:50.123456Z'],
        ];
    }

    // ---- point 4: retired devices are listed, retiredAt shape is PROPOSED ----

    public function test_a_retired_device_parses_and_exposes_its_state(): void
    {
        // No production row has ever carried retiredAt; this value's FORM is
        // the vendor's proposal, borrowed from firstSeen/lastSeen.
        $device = LitsrmmDevice::fromArray(self::row(0, [
            'enrollmentState' => 'retired',
            'availabilityState' => 'retired',
            'retiredAt' => '2026-09-25T10:00:00Z',
        ]));

        $this->assertTrue($device->isRetired(), 'both states "retired" is how the vendor lists a retired device');
        $this->assertFalse($device->hasSplitRetiredState());
        $this->assertSame('2026-09-25T10:00:00+00:00', $device->retiredAt?->format(DATE_ATOM));
    }

    public function test_one_retired_state_alone_is_not_read_as_retired(): void
    {
        $device = LitsrmmDevice::fromArray(self::row(0, ['availabilityState' => 'retired']));

        $this->assertFalse($device->isRetired(), 'the contract says a retired device carries BOTH states');
        $this->assertTrue($device->hasSplitRetiredState());
    }

    public function test_the_captured_rows_are_not_retired(): void
    {
        foreach (self::capture()['devices'] as $row) {
            $this->assertFalse(LitsrmmDevice::fromArray($row)->isRetired());
        }
    }

    // ---- contract edges ----

    #[DataProvider('brokenRows')]
    public function test_a_row_that_breaks_the_contract_is_refused(array $changes, string $message): void
    {
        $this->expectException(LitsrmmClientException::class);
        $this->expectExceptionMessage($message);

        LitsrmmDevice::fromArray(self::row(0, $changes));
    }

    public static function brokenRows(): array
    {
        return [
            'null id (34/34 non-null)' => [['id' => null], 'no usable id'],
            'empty hostname' => [['hostname' => ''], 'no usable hostname'],
            'numeric clientId' => [['clientId' => 7], 'no usable clientId'],
            'unknown availability' => [['availabilityState' => 'unknown'], 'availabilityState outside'],
            'boolean availability' => [['availabilityState' => true], 'no usable availabilityState'],
            'unknown enrollment' => [['enrollmentState' => 'active'], 'enrollmentState outside'],
            'non-string serial' => [['serial' => 12345], 'non-string serial'],
        ];
    }

    public function test_a_list_is_not_a_row(): void
    {
        $this->expectException(LitsrmmClientException::class);
        $this->expectExceptionMessage('not a JSON object');

        LitsrmmDevice::fromArray([self::row(0)]);
    }

    public function test_an_unknown_extra_field_does_not_refuse_the_row(): void
    {
        $this->assertSame('EXAMPLE-WORKSTATION-1',
            LitsrmmDevice::fromArray(self::row(0, ['newVendorField' => 'x']))->hostname);
    }
}
