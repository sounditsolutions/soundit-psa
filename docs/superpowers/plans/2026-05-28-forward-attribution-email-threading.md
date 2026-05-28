# Forward Attribution on Token-Threaded Emails — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** When a forwarded customer email threads onto an existing ticket via a `[T-123]` subject token, attribute the resulting note to the original customer (not the forwarding technician) and prepend a provenance line.

**Architecture:** A new pure static parser (`App\Services\Email\ForwardedEmailParser`) detects forwards and extracts the original sender from the forwarded header block. `EmailService::linkEmailToTicket()` — the single chokepoint all threading paths flow through — calls the parser and rewrites the note's `author_name` plus body provenance when a forward is detected. No schema change, no new setting, always-on with a safe fallback to current behavior.

**Tech Stack:** Laravel 12, PHP 8.3, PHPUnit 11, SQLite `:memory:` for tests.

**Spec:** `docs/superpowers/specs/2026-05-28-forward-attribution-email-threading-design.md`

---

## File Structure

| File | Responsibility |
| --- | --- |
| `app/Services/Email/ForwardedEmailParser.php` | **New.** Pure detection + parsing of forwarded emails. No DB. |
| `app/Services/EmailService.php` | **Modify.** `linkEmailToTicket()` reattributes forwarded notes; fix stale docblock on `matchToExistingTicket()`. |
| `tests/Unit/ForwardedEmailParserTest.php` | **New.** Parser unit tests (in-memory `Email`, no DB). |
| `tests/Feature/ForwardAttributionTest.php` | **New.** Integration: attribution, guard, no-new-ticket (RefreshDatabase). |

No `docs/INSTALL.md` change — this feature adds no env var, dependency, setting, or scheduled command.

---

## Task 1: ForwardedEmailParser (pure parser + unit tests)

**Files:**
- Create: `app/Services/Email/ForwardedEmailParser.php`
- Test: `tests/Unit/ForwardedEmailParserTest.php`

- [ ] **Step 1: Write the failing unit test**

Create `tests/Unit/ForwardedEmailParserTest.php`:

```php
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
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=ForwardedEmailParserTest`
Expected: FAIL — `Class "App\Services\Email\ForwardedEmailParser" not found`.

- [ ] **Step 3: Implement the parser**

Create `app/Services/Email/ForwardedEmailParser.php`:

```php
<?php

namespace App\Services\Email;

use App\Models\Email;

/**
 * Detects forwarded emails and extracts the original sender.
 *
 * When a technician forwards a customer's direct email into the helpdesk
 * mailbox (so it threads onto an existing ticket via a [T-123] subject token),
 * the forward's envelope sender is the technician, not the customer. This
 * parser recovers the original sender from the forwarded header block so the
 * ticket note can be attributed correctly.
 *
 * Best-effort, English-locale only (FW:/Fwd:/Forwarded: prefixes; Outlook and
 * Gmail header blocks). Anything it cannot parse yields null/false and callers
 * fall back to attributing the note to the forwarder.
 */
class ForwardedEmailParser
{
    /**
     * True when the email looks like a forward: a forward subject prefix AND a
     * recognizable forwarded header block in the body. The subject-prefix guard
     * is what keeps normal replies (whose quoted history also contains a
     * "From:/Sent:/Subject:" block) from being treated as forwards.
     */
    public static function isForwarded(Email $email): bool
    {
        $subject = $email->subject ?? '';
        if (! preg_match('/(^|\s)(fwd?|forwarded)\s*:/i', $subject)) {
            return false;
        }

        return self::hasForwardBlock(self::text($email));
    }

    /**
     * Extract the original sender from the topmost forwarded "From:" line.
     *
     * @return array{name: ?string, email: string}|null
     */
    public static function parseOriginalSender(Email $email): ?array
    {
        $text = self::text($email);

        if (! preg_match('/^\s*from\s*:\s*(.+)$/im', $text, $m)) {
            return null;
        }

        $line = trim($m[1]);

        if (! preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $line, $em)) {
            return null;
        }

        $address = strtolower($em[0]);

        // Name is whatever precedes the address / angle-bracket, de-quoted.
        $name = trim(preg_replace('/<[^>]*>/', '', $line));
        $name = trim($name, " \t\"'");
        if ($name === '' || strcasecmp($name, $address) === 0) {
            $name = null;
        }

        return ['name' => $name, 'email' => $address];
    }

    private static function hasForwardBlock(string $text): bool
    {
        // Gmail-style banner.
        if (preg_match('/-{2,}\s*forwarded message\s*-{2,}/i', $text)) {
            return true;
        }

        // Outlook-style header block: From: + (Sent:|Date:) + Subject:.
        return (bool) (preg_match('/^\s*from\s*:/im', $text)
            && preg_match('/^\s*(sent|date)\s*:/im', $text)
            && preg_match('/^\s*subject\s*:/im', $text));
    }

    private static function text(Email $email): string
    {
        $text = $email->body_text;
        if ($text === null || trim($text) === '') {
            $text = strip_tags((string) $email->body_html);
        }

        return (string) $text;
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --filter=ForwardedEmailParserTest`
Expected: PASS — 5 tests, all green.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Email/ForwardedEmailParser.php tests/Unit/ForwardedEmailParserTest.php
git commit -m "Add ForwardedEmailParser for forward detection and sender extraction"
```

---

## Task 2: Wire attribution into linkEmailToTicket + fix docblock

**Files:**
- Modify: `app/Services/EmailService.php` (add import; `linkEmailToTicket()` ~595-606; docblock ~491-498)
- Test: `tests/Feature/ForwardAttributionTest.php`

- [ ] **Step 1: Write the failing feature test**

Create `tests/Feature/ForwardAttributionTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Enums\TicketType;
use App\Enums\WhoType;
use App\Models\Client;
use App\Models\Email;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Services\EmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class ForwardAttributionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Neutralize stray queued jobs (triage on ticket create, email-add
        // notification). linkEmailToTicket/processInbound run synchronously.
        Bus::fake();
    }

    private function makeTicket(): Ticket
    {
        $client = Client::create(['name' => 'Acme Corp']);

        // No assignee_id on purpose: notifyEmailAdded() no-ops without one.
        return Ticket::create([
            'client_id' => $client->id,
            'subject'   => 'Printer offline',
            'type'      => TicketType::Incident,
            'status'    => TicketStatus::New,
            'priority'  => TicketPriority::P3,
        ]);
    }

    public function test_forwarded_email_is_attributed_to_original_sender(): void
    {
        $ticket = $this->makeTicket();

        $email = Email::create([
            'direction'    => 'inbound',
            'from_address' => 'charlie@couttspnw.com',
            'from_name'    => 'Charlie Coutts',
            'subject'      => "FW: Printer offline [{$ticket->display_id}]",
            'body_text'    => "FYI\n\nFrom: Jane Doe <jane@acme.com>\nSent: Thursday, May 28, 2026\nTo: Charlie Coutts\nSubject: Printer offline\n\nThe printer is still offline.",
            'received_at'  => now(),
        ]);

        app(EmailService::class)->linkEmailToTicket($email, $ticket);

        $note = TicketNote::where('ticket_id', $ticket->id)
            ->where('email_id', $email->id)
            ->first();

        $this->assertNotNull($note);
        $this->assertSame('Jane Doe', $note->author_name);
        $this->assertSame(WhoType::EndUser, $note->who_type);
        $this->assertStringContainsString("[Forwarded into {$ticket->display_id} by Charlie Coutts]", $note->body);
        $this->assertStringContainsString('The printer is still offline.', $note->body);
    }

    public function test_normal_reply_is_not_reattributed(): void
    {
        $ticket = $this->makeTicket();

        $email = Email::create([
            'direction'    => 'inbound',
            'from_address' => 'jane@acme.com',
            'from_name'    => 'Jane Doe',
            'subject'      => "RE: Printer offline [{$ticket->display_id}]",
            'body_text'    => "Thanks, that worked!",
            'received_at'  => now(),
        ]);

        app(EmailService::class)->linkEmailToTicket($email, $ticket);

        $note = TicketNote::where('email_id', $email->id)->first();
        $this->assertSame('Jane Doe', $note->author_name);
        $this->assertStringNotContainsString('Forwarded into', $note->body);
    }

    public function test_forwarded_email_does_not_create_a_new_ticket(): void
    {
        $ticket = $this->makeTicket();

        $email = Email::create([
            'direction'    => 'inbound',
            'from_address' => 'charlie@couttspnw.com',
            'from_name'    => 'Charlie Coutts',
            'subject'      => "FW: Printer offline [{$ticket->display_id}]",
            'body_text'    => "FYI\n\nFrom: Jane Doe <jane@acme.com>\nSent: today\nSubject: Printer offline\n\nstill broken",
            'received_at'  => now(),
        ]);

        $before = Ticket::count();
        app(EmailService::class)->processInbound($email);

        $this->assertSame($before, Ticket::count());
        $this->assertSame($ticket->id, $email->fresh()->ticket_id);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=ForwardAttributionTest`
Expected: FAIL — `test_forwarded_email_is_attributed_to_original_sender` fails because `author_name` is `Charlie Coutts` (current behavior) and the body has no provenance line. (`test_normal_reply_is_not_reattributed` and the no-new-ticket test should already pass — that is fine.)

- [ ] **Step 3: Add the import to EmailService**

In `app/Services/EmailService.php`, add to the `use` block at the top of the file (alongside the other `use App\Services\...` imports):

```php
use App\Services\Email\ForwardedEmailParser;
```

- [ ] **Step 4: Reattribute forwarded notes in linkEmailToTicket**

In `app/Services/EmailService.php`, find the note-creation block inside `linkEmailToTicket()` (currently ~595-606). Replace this:

```php
            $note = TicketNote::create([
                'ticket_id'   => $ticket->id,
                'author_id'   => null,
                'author_name' => $email->from_name ?? $email->from_address,
                'who_type'    => WhoType::EndUser,
                'email_id'    => $email->id,
                'body'        => $body ?: '[see attachments]',
                'body_html'   => $bodyHtml,
                'note_type'   => NoteType::Reply,
                'is_private'  => false,
                'noted_at'    => $email->received_at,
            ]);
```

with this:

```php
            // Forwarded customer emails arrive with the forwarder (a technician)
            // as the envelope sender. Recover the original sender so the note is
            // attributed to the customer, with a provenance line naming the forwarder.
            $authorName = $email->from_name ?? $email->from_address;
            if (ForwardedEmailParser::isForwarded($email)) {
                $sender = ForwardedEmailParser::parseOriginalSender($email);
                if ($sender && $sender['email'] !== strtolower($email->from_address)) {
                    $authorName = $sender['name'] ?? $sender['email'];
                    $forwarder  = $email->from_name ?? $email->from_address;
                    $provenance = "[Forwarded into {$ticket->display_id} by {$forwarder}]";
                    $body = $provenance . "\n\n" . ($body !== '' ? $body : '[see attachments]');
                    if ($bodyHtml !== null) {
                        $bodyHtml = '<p>' . e($provenance) . '</p>' . $bodyHtml;
                    }
                }
            }

            $note = TicketNote::create([
                'ticket_id'   => $ticket->id,
                'author_id'   => null,
                'author_name' => $authorName,
                'who_type'    => WhoType::EndUser,
                'email_id'    => $email->id,
                'body'        => $body ?: '[see attachments]',
                'body_html'   => $bodyHtml,
                'note_type'   => NoteType::Reply,
                'is_private'  => false,
                'noted_at'    => $email->received_at,
            ]);
```

- [ ] **Step 5: Fix the stale docblock on matchToExistingTicket**

In `app/Services/EmailService.php`, replace the docblock above `private function matchToExistingTicket` (currently ~491-499):

```php
    /**
     * Match an inbound email to an existing ticket via two strategies:
     * 1. conversation_id — same Graph conversation thread (most reliable)
     * 2. In-Reply-To — RFC 5322 header chain
     *
     * Subject matching excluded — too noisy for MVP.
     * Known gap: client emails with Re: [T-123] in subject but no In-Reply-To header
     * will miss the match.
     */
```

with:

```php
    /**
     * Match an inbound email to an existing ticket, in priority order:
     *  1. conversation_id  — same Graph conversation thread (most reliable)
     *  2. In-Reply-To      — RFC 5322 header chain
     *  3. Subject [T-123]  — Sound PSA ticket ID
     *  4. Subject [ID:123] — Halo ticket ID (legacy)
     *  5. Subject [#123]   — Halo display ID (legacy)
     *
     * Subject-token matching lets staff thread a forwarded email onto an
     * existing ticket by putting the ticket's display ID in the subject — the
     * basis of the forward-attribution flow (see ForwardedEmailParser).
     */
```

- [ ] **Step 6: Run the feature test to verify it passes**

Run: `php artisan test --filter=ForwardAttributionTest`
Expected: PASS — 3 tests green.

- [ ] **Step 7: Run the full new suite together**

Run: `php artisan test --filter=ForwardedEmailParserTest --filter=ForwardAttributionTest`
(or run both files) Expected: PASS — 8 tests total.

- [ ] **Step 8: Commit**

```bash
git add app/Services/EmailService.php tests/Feature/ForwardAttributionTest.php
git commit -m "Attribute forwarded customer emails to the original sender on threaded tickets"
```

---

## Task 3: Manual verification on the dev server (optional but recommended)

**Files:** none (manual check).

- [ ] **Step 1: Exercise the path via tinker**

Run a one-off that mimics a forwarded email hitting an existing ticket. Replace `1` with a real ticket ID from your dev DB (`php artisan tinker --execute="echo \App\Models\Ticket::value('id');"`):

```bash
php artisan tinker --execute='
$t = \App\Models\Ticket::firstOrFail();
$e = \App\Models\Email::create([
  "direction" => "inbound",
  "from_address" => "tech@couttspnw.com",
  "from_name" => "Tech",
  "subject" => "FW: test [".$t->display_id."]",
  "body_text" => "FYI\n\nFrom: Real Customer <cust@example.com>\nSent: today\nSubject: test\n\nplease help",
  "received_at" => now(),
]);
app(\App\Services\EmailService::class)->processInbound($e);
$n = \App\Models\TicketNote::where("email_id", $e->id)->first();
echo "author=".$n->author_name." | who=".$n->who_type->value."\n";
echo $n->body."\n";
'
```

Expected: `author=Real Customer | who=2`, and the body begins with `[Forwarded into <display_id> by Tech]`. Confirm **no** new ticket was created (the email's `ticket_id` equals the existing ticket).

- [ ] **Step 2: Clean up the test record**

```bash
php artisan tinker --execute='
$e = \App\Models\Email::where("from_address","tech@couttspnw.com")->latest("id")->first();
\App\Models\TicketNote::where("email_id",$e->id)->forceDelete();
$e->forceDelete();
echo "cleaned\n";
'
```

---

## Self-Review

**1. Spec coverage:**
- New `ForwardedEmailParser` with `isForwarded` + `parseOriginalSender` → Task 1. ✓
- Narrow trigger (subject prefix + forward block + sender differs) → parser `isForwarded` guard + the `!==` check in Task 2 Step 4. ✓
- Attribution to original sender, `who_type` stays `EndUser` → Task 2 Step 4. ✓
- Provenance line prepend (text + HTML) → Task 2 Step 4. ✓
- Safe fallback to forwarder attribution → default `$authorName` + null-returning parser; covered by `test_forward_prefix_without_parseable_sender_returns_null` and `test_normal_reply_is_not_reattributed`. ✓
- No schema change / no setting → none added. ✓
- Audit via `email_id` → unchanged note field. ✓
- Fix stale docblock → Task 2 Step 5. ✓
- Tests: parser unit (Outlook/Gmail/non-forward/unparseable/email-only) → Task 1; feature (attribution / guard / no-new-ticket) → Task 2. ✓

**2. Placeholder scan:** No TBD/TODO; every code step shows full code and exact commands. ✓

**3. Type consistency:** Parser returns `array{name: ?string, email: string}|null`; consumer reads `$sender['email']` / `$sender['name']`. `$sender['email']` is pre-lowercased; compared to `strtolower($email->from_address)`. `who_type` asserted as `WhoType::EndUser` (enum, value `2`). Ticket/Email/TicketNote field names match their `$fillable`. ✓

**Risk note:** `RefreshDatabase` runs the full migration set on SQLite. The app supports SQLite for local dev (per CLAUDE.md), so this should be clean; if a specific migration proves SQLite-incompatible, report it rather than altering migrations to work around it.
