<?php

namespace Tests\Feature\Technician\Notify;

use App\Enums\TechnicianRunState;
use App\Models\Client;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Services\Technician\Notify\DigestBuilder;
use App\Services\Technician\Notify\TeamsText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Injection hardening (psa-uvuy): the Teams MessageCard sink renders MARKDOWN, and
 * operator-facing alert/digest bodies legitimately use markdown. But they embed
 * UNTRUSTED client data — the ticket SUBJECT and the client/contact NAME. A subject
 * like `[click me](http://evil)`, `<b>`, or `*spoof*` must NOT inject a live link /
 * raw HTML / emphasis into the operator's Teams card. The email path is already
 * HtmlSanitizer-sanitized; the Teams sink is the only raw one, so the untrusted
 * fields are neutralised AT the point they are interpolated into Teams-bound text
 * (we do NOT blanket-escape the body — that would break the operators' formatting).
 */
class TeamsTextEscapeTest extends TestCase
{
    use RefreshDatabase;

    public function test_escape_defangs_markdown_link_html_and_emphasis(): void
    {
        $out = TeamsText::escape('[click me](http://evil) <b>x</b> *spoof* _u_ `code`');

        // No live markdown link: the ]( pairing must be broken.
        $this->assertStringNotContainsString('](http://evil)', $out);
        // No raw angle brackets (HTML defanged).
        $this->assertStringNotContainsString('<b>', $out);
        $this->assertStringNotContainsString('</b>', $out);
        $this->assertStringNotContainsString('<', $out);
        $this->assertStringNotContainsString('>', $out);
        // No live emphasis / code controls.
        $this->assertStringNotContainsString('*', $out);
        $this->assertStringNotContainsString('_', $out);
        $this->assertStringNotContainsString('`', $out);
        $this->assertStringNotContainsString('[', $out);
        $this->assertStringNotContainsString(']', $out);
        $this->assertStringNotContainsString('(', $out);
        $this->assertStringNotContainsString(')', $out);

        // The human-readable words survive (still legible to the operator).
        $this->assertStringContainsString('click me', $out);
        $this->assertStringContainsString('spoof', $out);
        $this->assertStringContainsString('code', $out);
    }

    public function test_escape_leaves_a_normal_subject_unchanged(): void
    {
        $this->assertSame('VPN is down at the Acme office', TeamsText::escape('VPN is down at the Acme office'));
    }

    public function test_digest_neutralizes_a_malicious_subject_and_client_name(): void
    {
        $client = Client::factory()->create(['name' => '[Evil](http://evil) Corp']);
        $ticket = Ticket::factory()->create([
            'client_id' => $client->id,
            'subject' => '*urgent* <script>alert(1)</script> [pwn](http://evil)',
        ]);
        TechnicianRun::create([
            'ticket_id' => $ticket->id, 'client_id' => $client->id, 'action_type' => 'send_reply',
            'content_hash' => str_repeat('a', 64), 'state' => TechnicianRunState::AwaitingApproval, 'proposed_content' => 'd',
        ]);

        $body = app(DigestBuilder::class)->build()->body;

        // The injection primitives are gone from the Teams-bound body.
        $this->assertStringNotContainsString('](http://evil)', $body, 'no live markdown link from the subject');
        $this->assertStringNotContainsString('<script>', $body, 'no raw HTML from the subject');
        $this->assertStringNotContainsString('<', $body);
        $this->assertStringNotContainsString('>', $body);

        // The operator can still read the (defanged) subject/client text.
        $this->assertStringContainsString('urgent', $body);
        $this->assertStringContainsString('pwn', $body);
        $this->assertStringContainsString('Evil', $body);
    }

    public function test_digest_does_not_blanket_escape_its_own_formatting(): void
    {
        // A benign pending item: the digest's own punctuation/structure is intact —
        // we only escaped the untrusted fields, not the whole body.
        $client = Client::factory()->create(['name' => 'Acme']);
        $ticket = Ticket::factory()->create(['client_id' => $client->id, 'subject' => 'VPN down']);
        TechnicianRun::create([
            'ticket_id' => $ticket->id, 'client_id' => $client->id, 'action_type' => 'send_reply',
            'content_hash' => str_repeat('a', 64), 'state' => TechnicianRunState::AwaitingApproval, 'proposed_content' => 'd',
        ]);

        $body = app(DigestBuilder::class)->build()->body;

        $this->assertStringContainsString('Acme', $body);
        $this->assertStringContainsString('VPN down', $body);
        // The bullet + em-dash structure the operator expects is untouched.
        $this->assertStringContainsString('• Acme — VPN down', $body);
    }
}
