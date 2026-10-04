<?php

namespace App\Services\Litsrmm;

use App\Support\LitsrmmSerial;
use DateTimeImmutable;
use DateTimeZone;

/**
 * One row of LITSRMM `GET /v1/devices`, parsed. READ ONLY: nothing here writes
 * an asset, retires one, or decides anything about one.
 *
 * WHERE THE SHAPE COMES FROM (C-56)
 * ---------------------------------
 * The vendor is not open source, so the producer we can read is the vendor's
 * own capture: a verbatim 3-device response from their production, with
 * customer ids replaced by the vendor, relayed on card 6ab46339 on 24 Sep 2026.
 * It is committed as tests/Fixtures/litsrmm/devices-capture-2026-09-24.json and
 * the parser's tests read that file. The same relay gives the vendor's null
 * counts over their 34 devices, which is why nullability is split this way:
 *
 *  - never null in 34/34: id, clientId, clientName, hostname,
 *    enrollmentState, availabilityState. Absent or empty => the row is refused.
 *  - serial is null on 1 of 34.
 *  - osName, osVersion, osBuild, agentVersion, lastUser, firstSeen, lastSeen
 *    are non-null on 3 of 34 only (the machines running the vendor's agent).
 *    Null there is NORMAL, not a fault: nothing is logged and the row is kept.
 *
 * Unknown extra fields are ignored rather than refused, so the vendor can add
 * one without breaking this read.
 */
final class LitsrmmDevice
{
    /** The vendor's enrollment enum, as relayed on card 6ab46339 (24 Sep 2026). */
    public const ENROLLMENT_STATES = ['pending', 'enrolled', 'retired'];

    /** Five values, not a boolean: "offline" is a recorded fact, not "unknown". */
    public const AVAILABILITY_STATES = ['online', 'offline', 'overdue', 'dormant', 'retired'];


    private const REQUIRED = ['id', 'clientId', 'clientName', 'hostname', 'enrollmentState', 'availabilityState'];

    private const OPTIONAL_STRINGS = ['serial', 'osName', 'osVersion', 'osBuild', 'agentVersion', 'lastUser'];

    /** Every key the capture carries on every row. Used for drift detection. */
    public const DOCUMENTED_KEYS = [
        'id', 'clientId', 'clientName', 'hostname', 'serial', 'osName', 'osVersion', 'osBuild',
        'agentVersion', 'lastUser', 'firstSeen', 'lastSeen', 'enrollmentState', 'availabilityState',
        'retiredAt',
    ];

    private function __construct(
        public readonly string $id,
        public readonly string $clientId,
        public readonly string $clientName,
        public readonly string $hostname,
        public readonly ?string $serial,
        public readonly ?string $osName,
        public readonly ?string $osVersion,
        public readonly ?string $osBuild,
        public readonly ?string $agentVersion,
        public readonly ?string $lastUser,
        public readonly ?DateTimeImmutable $firstSeen,
        public readonly ?DateTimeImmutable $lastSeen,
        public readonly string $enrollmentState,
        public readonly string $availabilityState,
        public readonly ?DateTimeImmutable $retiredAt,
    ) {}

    /**
     * @throws LitsrmmClientException for a row that breaks the contract: a
     *                                required field absent or empty, a wrong type, an enum value outside
     *                                the relayed set, or an unparseable timestamp. The message names the
     *                                field, never the value, so no device data reaches a log through it.
     */
    public static function fromArray(mixed $row): self
    {
        if (! is_array($row) || ($row !== [] && array_is_list($row))) {
            throw new LitsrmmClientException('LITSRMM device row is not a JSON object');
        }

        foreach (self::REQUIRED as $key) {
            if (! isset($row[$key]) || ! is_string($row[$key]) || $row[$key] === '') {
                throw new LitsrmmClientException("LITSRMM device row has no usable {$key}");
            }
        }

        foreach (self::OPTIONAL_STRINGS as $key) {
            if (isset($row[$key]) && ! is_string($row[$key])) {
                throw new LitsrmmClientException("LITSRMM device row has a non-string {$key}");
            }
        }

        if (! in_array($row['enrollmentState'], self::ENROLLMENT_STATES, true)) {
            throw new LitsrmmClientException('LITSRMM device row has an enrollmentState outside the documented set');
        }

        if (! in_array($row['availabilityState'], self::AVAILABILITY_STATES, true)) {
            throw new LitsrmmClientException('LITSRMM device row has an availabilityState outside the documented set');
        }

        return new self(
            id: $row['id'],
            clientId: $row['clientId'],
            clientName: $row['clientName'],
            hostname: $row['hostname'],
            serial: $row['serial'] ?? null,
            osName: $row['osName'] ?? null,
            osVersion: $row['osVersion'] ?? null,
            osBuild: $row['osBuild'] ?? null,
            agentVersion: $row['agentVersion'] ?? null,
            // A bare username: the vendor stores no domain and no backslash.
            // It is kept VERBATIM. Splitting on '\' would invent a domain the
            // contract says is not there.
            lastUser: $row['lastUser'] ?? null,
            firstSeen: self::timestamp($row, 'firstSeen'),
            lastSeen: self::timestamp($row, 'lastSeen'),
            enrollmentState: $row['enrollmentState'],
            availabilityState: $row['availabilityState'],
            // PROPOSED SHAPE. The vendor has no production data for retiredAt
            // yet (non-null on 0 of 34) and calls its shape proposed. Parsing
            // it in the firstSeen/lastSeen form is OUR assumption, not a
            // measured fact. Re-check against the first real retired device
            // before anything acts on this value.
            retiredAt: self::timestamp($row, 'retiredAt'),
        );
    }

    /**
     * The serial, if it can identify a machine at all: null when the vendor
     * reports none, a blank string, a firmware placeholder ("System Serial
     * Number", "Default string", "To be filled by O.E.M." and their family),
     * or a value too short or too repetitive to be one. The rule is
     * App\Support\LitsrmmSerial, which is the list the vendor's own RMM
     * refuses, so both sides of the integration withhold the same values.
     * A placeholder is still REAL data and stays in $serial.
     *
     * Even a non-placeholder serial is NOT a key: the vendor says serial
     * uniqueness is luck, not a guarantee. Nothing may key a device on this
     * value; `id` is the identity. It may only propose a first link, which
     * the asset sync then refuses when it is ambiguous.
     */
    public function matchableSerial(): ?string
    {
        if (LitsrmmSerial::identity($this->serial) === null) {
            return null;
        }

        return trim((string) $this->serial);
    }

    /**
     * Whether the vendor reports this device retired: BOTH states read
     * `retired`, which is how the vendor says retired devices are listed.
     *
     * This only EXPOSES the state. Nothing in this class or its client acts on
     * it; retiring our asset rows is a later PR under its own ruling.
     */
    public function isRetired(): bool
    {
        return $this->enrollmentState === 'retired' && $this->availabilityState === 'retired';
    }

    /**
     * Whether exactly one of the two states reads `retired`. The relayed
     * contract says retired devices carry both, so this is a shape the vendor
     * has not described. It is exposed so a later consumer can refuse to
     * guess a meaning; nothing here interprets it.
     */
    public function hasSplitRetiredState(): bool
    {
        return ($this->enrollmentState === 'retired') !== ($this->availabilityState === 'retired');
    }

    /**
     * ISO 8601 UTC, always ending in `Z`, fractional seconds OPTIONAL. Both
     * forms occur in the same field in the vendor's capture
     * (`2026-09-22T03:47:40Z` and `2026-09-22T18:20:53.331Z`). The relayed
     * notes set no limit on how many fraction digits appear, so any number is
     * accepted. Digits past the sixth are dropped (truncated, not rounded),
     * because microseconds are the finest precision DateTimeImmutable holds.
     * Anything else,
     * including an offset form or a calendar-invalid date that PHP would
     * silently roll over, is refused.
     */
    private static function timestamp(array $row, string $key): ?DateTimeImmutable
    {
        $value = $row[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (! is_string($value)
            || preg_match('/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})(?:\.(\d+))?Z$/', $value, $m) !== 1) {
            throw new LitsrmmClientException("LITSRMM device row has an unparseable {$key}");
        }

        $fraction = str_pad(substr($m[2] ?? '', 0, 6), 6, '0');
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.u', $m[1].'.'.$fraction, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();

        if ($parsed === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new LitsrmmClientException("LITSRMM device row has an unparseable {$key}");
        }

        return $parsed;
    }
}
