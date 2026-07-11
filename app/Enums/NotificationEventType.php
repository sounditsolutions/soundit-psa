<?php

namespace App\Enums;

enum NotificationEventType: string
{
    case TicketCreated = 'ticket_created';
    case TicketNoteAdded = 'ticket_note_added';
    case TicketCallLogged = 'ticket_call_logged';
    case TicketEmailAdded = 'ticket_email_added';
    case TicketAssigned = 'ticket_assigned';
    case UnresolvedInboundEmail = 'unresolved_inbound_email';
    case TicketPriorityChanged = 'ticket_priority_changed';
    case TicketStatusChanged = 'ticket_status_changed';
    case TicketPortalReply = 'ticket_portal_reply';
    case PortalPrepayPurchase = 'portal_prepay_purchase';
    case PortalProductOrder = 'portal_product_order';
    case NewVoicemail = 'new_voicemail';
    case PrepayLowBalance = 'prepay_low_balance';
    case PrepayAutoTopUp = 'prepay_auto_topup';
    case InvoiceGenerationFailed = 'invoice_generation_failed';
    case InvoicePushFailed = 'invoice_push_failed';

    public function label(): string
    {
        return match ($this) {
            self::TicketCreated => 'New tickets created',
            self::TicketNoteAdded => 'New notes on my tickets',
            self::TicketCallLogged => 'Phone calls logged',
            self::TicketEmailAdded => 'Client email replies',
            self::TicketAssigned => 'Ticket assignments',
            self::UnresolvedInboundEmail => 'Unrecognized emails',
            self::TicketPriorityChanged => 'Priority changes',
            self::TicketStatusChanged => 'Status changes',
            self::TicketPortalReply => 'Client portal replies',
            self::PortalPrepayPurchase => 'Portal prepaid purchases',
            self::PortalProductOrder => 'Portal product orders',
            self::NewVoicemail => 'New voicemails',
            self::PrepayLowBalance => 'Low prepay balance alerts',
            self::PrepayAutoTopUp => 'Prepay auto top-up invoices',
            self::InvoiceGenerationFailed => 'Invoice generation failed',
            self::InvoicePushFailed => 'Invoice push failed',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::TicketCreated => 'When a new ticket is created from any source',
            self::TicketNoteAdded => 'When someone adds a note to a ticket assigned to you',
            self::TicketCallLogged => 'When a phone call is logged on your ticket',
            self::TicketEmailAdded => 'When a client replies by email on your ticket',
            self::TicketAssigned => 'When a ticket is assigned to you',
            self::UnresolvedInboundEmail => 'When an email arrives that can\'t be matched to a ticket or contact',
            self::TicketPriorityChanged => 'When the priority changes on your ticket',
            self::TicketStatusChanged => 'When the status changes on your ticket',
            self::TicketPortalReply => 'When a client replies via the portal on your ticket',
            self::PortalPrepayPurchase => 'When a client purchases prepaid time via the portal',
            self::PortalProductOrder => 'When a client places a product order via the portal shop',
            self::NewVoicemail => 'When a voicemail is left on an unanswered call',
            self::PrepayLowBalance => 'When a client\'s prepay balance drops below their alert threshold',
            self::PrepayAutoTopUp => 'When an auto top-up invoice is generated for a client',
            self::InvoiceGenerationFailed => 'When automatic invoice generation fails for a billing profile',
            self::InvoicePushFailed => 'When an invoice fails to push to QBO or Stripe',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::TicketCreated => 'bi-plus-circle',
            self::TicketNoteAdded => 'bi-sticky',
            self::TicketCallLogged => 'bi-telephone',
            self::TicketEmailAdded => 'bi-envelope',
            self::TicketAssigned => 'bi-person-check',
            self::UnresolvedInboundEmail => 'bi-envelope-exclamation',
            self::TicketPriorityChanged => 'bi-exclamation-triangle',
            self::TicketStatusChanged => 'bi-arrow-left-right',
            self::TicketPortalReply => 'bi-person-lines-fill',
            self::PortalPrepayPurchase => 'bi-cart-check',
            self::PortalProductOrder => 'bi-bag-check',
            self::NewVoicemail => 'bi-voicemail',
            self::PrepayLowBalance => 'bi-exclamation-diamond',
            self::PrepayAutoTopUp => 'bi-arrow-repeat',
            self::InvoiceGenerationFailed => 'bi-exclamation-triangle',
            self::InvoicePushFailed => 'bi-exclamation-triangle',
        };
    }

    public function defaultEnabled(): bool
    {
        return true;
    }
}
