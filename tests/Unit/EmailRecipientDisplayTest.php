<?php

namespace Tests\Unit;

use App\Models\Email;
use Tests\TestCase;

/**
 * Guards Email::primaryRecipientDisplay() / primaryRecipientAddress() against
 * the two recipient shapes that exist in the wild:
 *
 *   - Graph ingestion writes maps:  [['name' => 'X', 'address' => 'x@y.com']]
 *   - Seeded / legacy data writes plain strings:  ['x@y.com']
 *
 * Regression for the /emails?preset=all 500 (TypeError "Cannot access offset
 * of type string on string" at Email::primaryRecipientDisplay) — the string
 * shape used to hit $first['name'] on a string.
 */
class EmailRecipientDisplayTest extends TestCase
{
    public function test_map_recipient_with_name_shows_name(): void
    {
        $email = new Email(['to_recipients' => [['name' => 'Jane Doe', 'address' => 'jane@example.com']]]);

        $this->assertSame('Jane Doe', $email->primaryRecipientDisplay());
        $this->assertSame('jane@example.com', $email->primaryRecipientAddress());
    }

    public function test_map_recipient_without_name_falls_back_to_address(): void
    {
        $nullName = new Email(['to_recipients' => [['name' => null, 'address' => 'noname@example.com']]]);
        $this->assertSame('noname@example.com', $nullName->primaryRecipientDisplay());
        $this->assertSame('noname@example.com', $nullName->primaryRecipientAddress());

        $missingName = new Email(['to_recipients' => [['address' => 'missing@example.com']]]);
        $this->assertSame('missing@example.com', $missingName->primaryRecipientDisplay());
        $this->assertSame('missing@example.com', $missingName->primaryRecipientAddress());
    }

    public function test_plain_string_recipient_does_not_crash(): void
    {
        $email = new Email(['to_recipients' => ['plain@example.com']]);

        // Previously threw: TypeError "Cannot access offset of type string on string".
        $this->assertSame('plain@example.com', $email->primaryRecipientDisplay());
        $this->assertSame('plain@example.com', $email->primaryRecipientAddress());
    }

    public function test_uses_first_of_multiple_string_recipients(): void
    {
        $email = new Email(['to_recipients' => ['first@example.com', 'second@example.com']]);

        $this->assertSame('first@example.com', $email->primaryRecipientDisplay());
        $this->assertSame('first@example.com', $email->primaryRecipientAddress());
    }

    public function test_empty_recipients_render_placeholder(): void
    {
        $empty = new Email(['to_recipients' => []]);
        $this->assertSame('—', $empty->primaryRecipientDisplay());
        $this->assertNull($empty->primaryRecipientAddress());

        $null = new Email(['to_recipients' => null]);
        $this->assertSame('—', $null->primaryRecipientDisplay());
        $this->assertNull($null->primaryRecipientAddress());
    }

    public function test_blank_string_recipient_renders_placeholder(): void
    {
        $email = new Email(['to_recipients' => ['']]);

        $this->assertSame('—', $email->primaryRecipientDisplay());
        $this->assertNull($email->primaryRecipientAddress());
    }
}
