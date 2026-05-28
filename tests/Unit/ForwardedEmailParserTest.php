<?php

namespace Tests\Unit;

use App\Models\Email;
use App\Services\Email\ForwardedEmailParser;
use Tests\TestCase;

class ForwardedEmailParserTest extends TestCase
{
    public function test_detects_outlook_forward_and_parses_sender(): void
    {
        $email = new Email([
            'subject'   => 'FW: Printer offline [T-123]',
            'body_text' => "FYI below.\n\nFrom: Jane Doe <jane@acme.com>\nSent: Thursday, May 28, 2026 9:14 AM\nTo: Charlie Coutts <charlie@couttspnw.com>\nSubject: Printer offline\n\nHi, the printer is still offline.",
        ]);

        $this->assertTrue(ForwardedEmailParser::isForwarded($email));

        $sender = ForwardedEmailParser::parseOriginalSender($email);
        $this->assertSame('Jane Doe', $sender['name']);
        $this->assertSame('jane@acme.com', $sender['email']);
    }

    public function test_detects_gmail_forward_and_parses_sender(): void
    {
        $email = new Email([
            'subject'   => 'Fwd: Printer offline',
            'body_text' => "---------- Forwarded message ---------\nFrom: Jane Doe <jane@acme.com>\nDate: Thu, May 28, 2026 at 9:14 AM\nSubject: Printer offline\nTo: Charlie Coutts <charlie@couttspnw.com>\n\nHi, the printer is still offline.",
        ]);

        $this->assertTrue(ForwardedEmailParser::isForwarded($email));

        $sender = ForwardedEmailParser::parseOriginalSender($email);
        $this->assertSame('Jane Doe', $sender['name']);
        $this->assertSame('jane@acme.com', $sender['email']);
    }

    public function test_normal_reply_is_not_detected_as_forward(): void
    {
        $email = new Email([
            'subject'   => 'Re: Printer offline [T-123]',
            'body_text' => "Thanks, that fixed it!",
        ]);

        $this->assertFalse(ForwardedEmailParser::isForwarded($email));
    }

    public function test_forward_prefix_without_parseable_sender_returns_null(): void
    {
        $email = new Email([
            'subject'   => 'FW: Printer offline [T-123]',
            'body_text' => "See below.\n\n-------- Forwarded message --------\n(no headers survived the copy/paste)",
        ]);

        $this->assertNull(ForwardedEmailParser::parseOriginalSender($email));
    }

    public function test_email_only_from_line_has_null_name(): void
    {
        $email = new Email([
            'subject'   => 'FW: Help [T-9]',
            'body_text' => "From: jane@acme.com\nSent: today\nSubject: Help\n\nbody",
        ]);

        $sender = ForwardedEmailParser::parseOriginalSender($email);
        $this->assertNull($sender['name']);
        $this->assertSame('jane@acme.com', $sender['email']);
    }
}
