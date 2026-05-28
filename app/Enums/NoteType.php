<?php

namespace App\Enums;

enum NoteType: string
{
    case Note = 'note';
    case Reply = 'reply';
    case StatusChange = 'status_change';
    case PhoneCall = 'phone_call';
    case Resolution = 'resolution';
    case System = 'system';
    case Escalation = 'escalation';
    case AiTriage = 'ai_triage';

    public function label(): string
    {
        return match ($this) {
            self::Note => 'Note',
            self::Reply => 'Reply',
            self::StatusChange => 'Status Change',
            self::PhoneCall => 'Phone Call',
            self::Resolution => 'Resolution',
            self::System => 'System',
            self::Escalation => 'Escalation',
            self::AiTriage => 'AI Triage',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Note => 'bi-sticky',
            self::Reply => 'bi-envelope',
            self::StatusChange => 'bi-arrow-left-right',
            self::PhoneCall => 'bi-telephone',
            self::Resolution => 'bi-check-circle',
            self::System => 'bi-gear',
            self::Escalation => 'bi-arrow-up-circle',
            self::AiTriage => 'bi-robot',
        };
    }

    public function isSystemGenerated(): bool
    {
        return match ($this) {
            self::System, self::StatusChange, self::AiTriage, self::Escalation => true,
            default => false,
        };
    }

    /**
     * Note types that should be hidden from the client portal.
     *
     * @return array<NoteType>
     */
    public static function systemGenerated(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $type) => $type->isSystemGenerated(),
        ));
    }
}
