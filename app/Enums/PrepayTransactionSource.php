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
    // Balance moved between two contracts of the same client. TransferOut is the
    // debit on the source contract; TransferIn is the matching credit on the
    // destination. Always created as a pair by PrepayService::transfer().
    case TransferOut = 'transfer_out';
    case TransferIn = 'transfer_in';

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
            self::TransferOut => 'Transfer Out',
            self::TransferIn => 'Transfer In',
        };
    }

    public function isCredit(): bool
    {
        return in_array($this, [self::HaloSync, self::InvoiceDeposit, self::ManualCredit, self::TransferIn]);
    }
}
