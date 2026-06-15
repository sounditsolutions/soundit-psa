<?php

namespace Tests\Unit\Tactical;

use App\Services\Tactical\TacticalActionConfirmToken;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Task 4 (P2): scoped + payload-bound confirm tokens for destructive actions
 * (spec §11.3 / amendments M8, m3).
 *
 * A token is bound to {action_key, agent_id, actor_id, payloadHash, expiresAt}
 * and signed with APP_KEY. It must NOT verify if ANY bound field differs, if it
 * has expired (~10 min TTL), or if it has been tampered with.
 */
class TacticalActionConfirmTokenTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_valid_token_verifies_within_ttl(): void
    {
        $token = TacticalActionConfirmToken::issue('tactical.reboot', 'AGENT-1', 7);

        $this->assertTrue(
            TacticalActionConfirmToken::verify($token, 'tactical.reboot', 'AGENT-1', 7)
        );
    }

    public function test_token_is_opaque_and_nonempty(): void
    {
        $token = TacticalActionConfirmToken::issue('tactical.reboot', 'AGENT-1', 7);

        $this->assertNotEmpty($token);
        // Opaque: the raw tuple values are not plainly readable in the token.
        $this->assertStringNotContainsString('tactical.reboot', $token);
        $this->assertStringNotContainsString('AGENT-1', $token);
    }

    public function test_wrong_action_key_fails(): void
    {
        $token = TacticalActionConfirmToken::issue('tactical.reboot', 'AGENT-1', 7);

        $this->assertFalse(
            TacticalActionConfirmToken::verify($token, 'tactical.shutdown', 'AGENT-1', 7)
        );
    }

    public function test_wrong_agent_fails(): void
    {
        $token = TacticalActionConfirmToken::issue('tactical.reboot', 'AGENT-1', 7);

        $this->assertFalse(
            TacticalActionConfirmToken::verify($token, 'tactical.reboot', 'AGENT-2', 7)
        );
    }

    public function test_wrong_actor_fails(): void
    {
        $token = TacticalActionConfirmToken::issue('tactical.reboot', 'AGENT-1', 7);

        $this->assertFalse(
            TacticalActionConfirmToken::verify($token, 'tactical.reboot', 'AGENT-1', 99)
        );
    }

    public function test_null_actor_does_not_match_a_real_actor(): void
    {
        $token = TacticalActionConfirmToken::issue('tactical.reboot', 'AGENT-1', null);

        // A token issued for a null actor must not verify against a concrete actor id.
        $this->assertFalse(
            TacticalActionConfirmToken::verify($token, 'tactical.reboot', 'AGENT-1', 7)
        );
        // ...and vice versa.
        $token2 = TacticalActionConfirmToken::issue('tactical.reboot', 'AGENT-1', 7);
        $this->assertFalse(
            TacticalActionConfirmToken::verify($token2, 'tactical.reboot', 'AGENT-1', null)
        );
    }

    public function test_payload_hash_must_match(): void
    {
        $token = TacticalActionConfirmToken::issue('tactical.cmd', 'AGENT-1', 7, hash('sha256', 'shutdown -r'));

        $this->assertTrue(
            TacticalActionConfirmToken::verify($token, 'tactical.cmd', 'AGENT-1', 7, hash('sha256', 'shutdown -r'))
        );

        // M8: a token for one payload must NOT verify for a different command.
        $this->assertFalse(
            TacticalActionConfirmToken::verify($token, 'tactical.cmd', 'AGENT-1', 7, hash('sha256', 'rm -rf /'))
        );

        // A token issued WITH a payloadHash must not verify when none is supplied.
        $this->assertFalse(
            TacticalActionConfirmToken::verify($token, 'tactical.cmd', 'AGENT-1', 7)
        );
    }

    public function test_expired_token_fails(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00'));
        $token = TacticalActionConfirmToken::issue('tactical.reboot', 'AGENT-1', 7);

        // 11 minutes later — past the ~10 min TTL.
        Carbon::setTestNow(Carbon::parse('2026-06-15 12:11:00'));
        $this->assertFalse(
            TacticalActionConfirmToken::verify($token, 'tactical.reboot', 'AGENT-1', 7)
        );
    }

    public function test_token_valid_just_before_expiry(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00'));
        $token = TacticalActionConfirmToken::issue('tactical.reboot', 'AGENT-1', 7);

        // 9 minutes later — still within a ~10 min TTL.
        Carbon::setTestNow(Carbon::parse('2026-06-15 12:09:00'));
        $this->assertTrue(
            TacticalActionConfirmToken::verify($token, 'tactical.reboot', 'AGENT-1', 7)
        );
    }

    public function test_tampered_token_fails(): void
    {
        $token = TacticalActionConfirmToken::issue('tactical.reboot', 'AGENT-1', 7);

        // Flip a character in the middle of the token.
        $mid = intdiv(strlen($token), 2);
        $tampered = substr($token, 0, $mid).($token[$mid] === 'A' ? 'B' : 'A').substr($token, $mid + 1);

        $this->assertFalse(
            TacticalActionConfirmToken::verify($tampered, 'tactical.reboot', 'AGENT-1', 7)
        );
    }

    public function test_garbage_token_fails_gracefully(): void
    {
        $this->assertFalse(
            TacticalActionConfirmToken::verify('not-a-real-token', 'tactical.reboot', 'AGENT-1', 7)
        );
        $this->assertFalse(
            TacticalActionConfirmToken::verify('', 'tactical.reboot', 'AGENT-1', 7)
        );
    }
}
