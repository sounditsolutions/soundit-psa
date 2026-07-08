<?php

namespace App\Enums;

enum PrepayTransactionSource: string
{
    case HaloSync = 'halo_sync';
    case InvoiceDeposit = 'invoice_deposit';
    case InvoiceReversal = 'invoice_reversal';
    case ManualCredit = 'manual_credit';
    case ManualDebit = 'manual_debit';
    case TicketTime = 'ticket_time';
    case PhoneCallTime = 'phone_call_time';
    case Expiration = 'expiration';

    public function label(): string
    {
        return match ($this) {
            self::HaloSync => 'Halo Sync',
            self::InvoiceDeposit => 'Invoice Deposit',
            self::InvoiceReversal => 'Invoice Reversal',
            self::ManualCredit => 'Manual Credit',
            self::ManualDebit => 'Manual Debit',
            self::TicketTime => 'Ticket Time',
            self::PhoneCallTime => 'Phone Call Time',
            self::Expiration => 'Expiration',
        };
    }

    public function isCredit(): bool
    {
        return in_array($this, [self::HaloSync, self::InvoiceDeposit, self::ManualCredit]);
    }
}
