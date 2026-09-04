<?php

namespace Tests\Feature\Email;

use App\Models\Email;
use App\Models\Setting;
use App\Services\EmailService;
use App\Services\Graph\GraphClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Poison-message guards on the mailbox poll (#557).
 *
 * Measured on production 2026-09-04: two messages each had TWO rows in `emails`
 * sharing one internet_message_id — one row with graph_id NULL, its sibling holding
 * the real graph id. The poll found the NULL row first, tried to write the graph id
 * onto it, and collided with the sibling on the UNIQUE index. Two errors every poll,
 * 288 polls a day since 8/21. Because the cursor only advanced on an error-free poll,
 * graph_last_poll_at stayed frozen at 2026-07-01 for two months and every poll
 * re-walked the same window.
 */
class EmailPollPoisonMessageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::setValue('graph_mailbox', 'support@example.test');
        Setting::setValue('graph_last_poll_at', '2026-07-01T00:00:00+00:00');
    }

    /** Build EmailService over a GraphClient that returns exactly $messages. */
    private function serviceReturning(array $messages): EmailService
    {
        $graph = Mockery::mock(GraphClient::class);
        $graph->shouldReceive('getMailboxMessages')->andReturn($messages);
        $this->app->instance(GraphClient::class, $graph);

        return $this->app->make(EmailService::class);
    }

    /**
     * A message shaped like Graph's, dismissed-side rows keep the poll on the light
     * path (no sender resolution, no ticket creation) so these assertions are about
     * the dedup and the cursor only.
     */
    private function message(string $graphId, string $internetMessageId, string $receivedAt): array
    {
        return [
            'id' => $graphId,
            'internetMessageId' => $internetMessageId,
            'conversationId' => 'conv-1',
            'from' => ['emailAddress' => ['address' => 'sender@example.test', 'name' => 'Sender']],
            'toRecipients' => [['emailAddress' => ['address' => 'support@example.test', 'name' => 'Support']]],
            'subject' => 'Subject',
            'bodyPreview' => 'preview',
            'body' => ['content' => '<p>body</p>'],
            'hasAttachments' => false,
            'importance' => 'normal',
            'receivedDateTime' => $receivedAt,
        ];
    }

    private function existingRow(?string $graphId, string $internetMessageId): Email
    {
        return Email::create([
            'graph_id' => $graphId,
            'internet_message_id' => $internetMessageId,
            'direction' => 'inbound',
            'from_address' => 'sender@example.test',
            'subject' => 'Subject',
            'received_at' => '2026-08-21 10:00:00',
            // Dismissed so the poll's reprocess-unticketed branch stays out of the way.
            'dismissed_at' => '2026-08-21 10:05:00',
        ]);
    }

    public function test_duplicate_rows_sharing_an_internet_message_id_do_not_fail_the_import(): void
    {
        // Insertion order matters: the NULL row is created first, so an unguarded
        // ->first() picks it and tries to steal the sibling's graph id.
        $nullRow = $this->existingRow(null, 'imid-dup');
        $ownerRow = $this->existingRow('graph-AAA', 'imid-dup');

        $result = $this->serviceReturning([
            $this->message('graph-AAA', 'imid-dup', '2026-09-04T10:00:00Z'),
        ])->pollMailbox();

        $this->assertSame(0, $result->errors, 'duplicate rows must not throw on every poll');
        $this->assertNull($nullRow->fresh()->graph_id, 'the duplicate row must not claim the graph id');
        $this->assertSame('graph-AAA', $ownerRow->fresh()->graph_id, 'the graph id must stay on the row that holds it');
        $this->assertSame('2026-09-04T10:00:00Z', Setting::getValue('graph_last_poll_at'));
    }

    public function test_a_recycled_graph_id_is_moved_off_the_stale_row(): void
    {
        // Graph recycles internal ids when a message is deleted or moved: the id now
        // belongs to a different message, which already has a row of its own.
        $staleRow = $this->existingRow('graph-BBB', 'imid-old');
        $currentRow = $this->existingRow(null, 'imid-new');

        $result = $this->serviceReturning([
            $this->message('graph-BBB', 'imid-new', '2026-09-04T11:00:00Z'),
        ])->pollMailbox();

        $this->assertSame(0, $result->errors);
        $this->assertNull($staleRow->fresh()->graph_id, 'the stale row must give the recycled id up');
        $this->assertSame('graph-BBB', $currentRow->fresh()->graph_id);
    }

    public function test_the_poll_cursor_advances_past_a_message_that_cannot_import(): void
    {
        $this->existingRow('graph-GOOD', 'imid-good');

        // A payload the import cannot handle stands in for any per-message failure:
        // whatever throws, one message must not stop every message behind it.
        $poison = $this->message('graph-POISON', 'imid-poison', '2026-09-04T09:00:00Z');
        $poison['toRecipients'] = 'not-an-array';

        $result = $this->serviceReturning([
            $poison,
            $this->message('graph-GOOD', 'imid-good', '2026-09-04T10:00:00Z'),
        ])->pollMailbox();

        $this->assertSame(1, $result->errors, 'the poison message still counts as an error');
        $this->assertSame(
            '2026-09-04T10:00:00Z',
            Setting::getValue('graph_last_poll_at'),
            'a message that cannot import must not freeze the cursor'
        );
    }

    public function test_the_cursor_does_not_move_when_no_message_imported(): void
    {
        $poison = $this->message('graph-POISON', 'imid-poison', '2026-09-04T09:00:00Z');
        $poison['toRecipients'] = 'not-an-array';

        $result = $this->serviceReturning([$poison])->pollMailbox();

        $this->assertSame(1, $result->errors);
        $this->assertSame(
            '2026-07-01T00:00:00+00:00',
            Setting::getValue('graph_last_poll_at'),
            'nothing imported cleanly, so there is no proven point to advance to'
        );
    }
}
