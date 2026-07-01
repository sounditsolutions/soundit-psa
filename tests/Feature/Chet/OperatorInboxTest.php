<?php

namespace Tests\Feature\Chet;

use App\Models\OperatorInbox;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class OperatorInboxTest extends TestCase
{
    use RefreshDatabase;

    public function test_casts_and_sender_relation(): void
    {
        $u = User::factory()->create();

        $row = OperatorInbox::create([
            'conversation_id' => 'c1',
            'sender_user_id' => $u->id,
            'text' => 'hi',
            'ts' => now(),
            'direct_mention' => 1,
            'authorized_steer' => 0,
            'delivered_at' => null,
        ]);

        $fresh = $row->fresh();
        $this->assertInstanceOf(Carbon::class, $fresh->ts);
        $this->assertNull($fresh->delivered_at);
        $this->assertTrue($fresh->direct_mention);
        $this->assertFalse($fresh->authorized_steer);
        $this->assertSame($u->id, $fresh->sender->id);
    }

    public function test_sender_is_nullable(): void
    {
        $row = OperatorInbox::create([
            'conversation_id' => 'c1',
            'sender_user_id' => null,
            'text' => 'chatter',
            'ts' => now(),
        ]);

        $this->assertNull($row->fresh()->sender);
    }
}
