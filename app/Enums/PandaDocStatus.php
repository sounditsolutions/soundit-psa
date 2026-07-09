<?php

namespace App\Enums;

/**
 * Internal status for a document tracked through PandaDoc.
 *
 * PandaDoc's API returns dotted status strings (e.g. `document.draft`,
 * `document.completed`). `fromApi()` normalizes those — and the bare form
 * seen in some webhook payloads — into this compact internal set.
 *
 * @see https://developers.pandadoc.com/reference/document-status
 */
enum PandaDocStatus: string
{
    case Draft = 'draft';
    case Sent = 'sent';
    case Viewed = 'viewed';
    case Completed = 'completed';
    case Declined = 'declined';
    case Voided = 'voided';
    case Expired = 'expired';
    case Error = 'error';

    /**
     * Normalize a PandaDoc API status string into an internal case.
     * Unknown values fall back to Draft so a record is never lost.
     */
    public static function fromApi(?string $apiStatus): self
    {
        $normalized = strtolower(trim((string) $apiStatus));
        // Strip the `document.` prefix PandaDoc uses on the API.
        if (str_starts_with($normalized, 'document.')) {
            $normalized = substr($normalized, strlen('document.'));
        }

        return match ($normalized) {
            'draft', 'uploaded' => self::Draft,
            'sent', 'waiting_approval', 'waiting_pay', 'external_review' => self::Sent,
            'viewed' => self::Viewed,
            'completed', 'approved', 'paid' => self::Completed,
            'declined', 'rejected' => self::Declined,
            'voided' => self::Voided,
            'expired' => self::Expired,
            'error', 'creation_failed' => self::Error,
            default => self::Draft,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Sent => 'Sent for Signature',
            self::Viewed => 'Viewed',
            self::Completed => 'Signed',
            self::Declined => 'Declined',
            self::Voided => 'Voided',
            self::Expired => 'Expired',
            self::Error => 'Error',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Draft => 'bg-secondary',
            self::Sent => 'bg-info',
            self::Viewed => 'bg-primary',
            self::Completed => 'bg-success',
            self::Declined => 'bg-danger',
            self::Voided => 'bg-warning text-dark',
            self::Expired => 'bg-warning text-dark',
            self::Error => 'bg-danger',
        };
    }

    /**
     * A terminal status will not change further without operator action.
     */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Declined, self::Voided, self::Expired], true);
    }

    public function isSigned(): bool
    {
        return $this === self::Completed;
    }
}
