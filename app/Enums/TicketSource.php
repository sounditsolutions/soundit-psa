<?php

namespace App\Enums;

enum TicketSource: string
{
    case Manual = 'manual';
    case Email = 'email';
    case Phone = 'phone';
    case Chat = 'chat';
    case HaloSync = 'halo_sync';
    case HelpdeskButton = 'helpdesk_button';
    case Huntress = 'huntress';
    case Portal = 'portal';
    case Assistant = 'assistant';
    case NinjaAlert = 'ninja_alert';
    case TacticalRmm = 'tactical_rmm';
    case CometBackup = 'comet_backup';
    case Alert = 'alert';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Manual',
            self::Email => 'Email',
            self::Phone => 'Phone',
            self::Chat => 'Chat',
            self::HaloSync => 'Halo Sync',
            self::HelpdeskButton => 'Helpdesk Button',
            self::Huntress => 'Huntress',
            self::Portal => 'Client Portal',
            self::Assistant => 'AI Assistant',
            self::NinjaAlert => 'Ninja Alert',
            self::TacticalRmm => 'Tactical RMM',
            self::CometBackup => 'Comet Backup',
            self::Alert => 'Alert',
        };
    }
}
