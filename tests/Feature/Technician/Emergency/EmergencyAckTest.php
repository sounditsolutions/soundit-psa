<?php

namespace Tests\Feature\Technician\Emergency;

use App\Enums\EmergencyState;
use App\Models\Client;
use App\Models\TechnicianEmergency;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Technician\Emergency\EmergencyAckToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmergencyAckTest extends TestCase
{
    use RefreshDatabase;

    private function emergency(): TechnicianEmergency
    {
        $client = Client::factory()->create();

        return TechnicianEmergency::create([
            'ticket_id' => Ticket::factory()->create(['client_id' => $client->id])->id,
            'client_id' => $client->id, 'signature' => 's', 'severity' => 3, 'reasons' => ['age'],
            'detected_by' => 'rules', 'state' => EmergencyState::Open, 'escalation_step' => 0,
            'ticket_ids' => [], 'alerted_at' => now(),
        ]);
    }

    public function test_valid_token_acks_once_and_is_idempotent(): void
    {
        $user = User::factory()->create();
        $e = $this->emergency();
        $token = EmergencyAckToken::issue($e->id, $user->id);

        $this->get(route('emergency.ack', ['token' => $token]))->assertOk();
        $e->refresh();
        $this->assertSame(EmergencyState::Acknowledged, $e->state);
        $this->assertSame($user->id, $e->acknowledged_by);
        $this->assertNotNull($e->acknowledged_at);

        // second tap: idempotent, no error, no state change
        $this->get(route('emergency.ack', ['token' => $token]))->assertOk();
        $this->assertDatabaseHas('technician_action_logs', ['action_type' => 'emergency_ack']);
    }

    public function test_tampered_token_is_rejected(): void
    {
        $e = $this->emergency();
        $this->get(route('emergency.ack', ['token' => 'garbage']))->assertForbidden();
        $this->assertSame(EmergencyState::Open, $e->fresh()->state);
    }

    public function test_signature_mismatch_token_is_rejected(): void
    {
        // A well-formed envelope whose HMAC does not verify must be rejected (403)
        // and must NOT mutate the row. This proves a tampered (but decodeable)
        // token can never reach the CAS update.
        $user = User::factory()->create();
        $e = $this->emergency();

        $valid = EmergencyAckToken::issue($e->id, $user->id);
        // Flip one base64url character in the body to break the signature while
        // keeping it decodeable JSON shape (claims() should still parse, verify() must fail).
        $forged = $this->corruptSignature($valid);

        $this->get(route('emergency.ack', ['token' => $forged]))->assertForbidden();
        $this->assertSame(EmergencyState::Open, $e->fresh()->state);
        $this->assertNull($e->fresh()->acknowledged_by);
        $this->assertDatabaseMissing('technician_action_logs', ['action_type' => 'emergency_ack']);
    }

    public function test_already_acknowledged_emergency_is_idempotent_via_cas(): void
    {
        // CAS 0-rows path: a VALID token whose emergency is already acknowledged
        // (by a DIFFERENT user / out of band) must return 200, not error, and must
        // NOT overwrite the existing acknowledger.
        $first = User::factory()->create();
        $second = User::factory()->create();
        $e = $this->emergency();

        $e->update([
            'state' => EmergencyState::Acknowledged,
            'acknowledged_at' => now()->subMinute(),
            'acknowledged_by' => $first->id,
        ]);

        $token = EmergencyAckToken::issue($e->id, $second->id);
        $this->get(route('emergency.ack', ['token' => $token]))->assertOk();

        $e->refresh();
        $this->assertSame(EmergencyState::Acknowledged, $e->state);
        // CAS matched 0 rows ⇒ acknowledger unchanged (still the first user).
        $this->assertSame($first->id, $e->acknowledged_by);
    }

    public function test_token_for_missing_emergency_does_not_error(): void
    {
        // A validly-signed token whose emergency id no longer exists must not 500;
        // the CAS simply matches 0 rows ⇒ idempotent 200, no audit row.
        $user = User::factory()->create();
        $token = EmergencyAckToken::issue(999999, $user->id);

        $this->get(route('emergency.ack', ['token' => $token]))->assertOk();
        $this->assertDatabaseMissing('technician_action_logs', ['action_type' => 'emergency_ack']);
    }

    public function test_expired_token_is_rejected_and_leaves_emergency_open(): void
    {
        // CO-5(c): the ack link is a SHORT-LIVED bearer credential — TTL is
        // clamp(escalationTimeoutMinutes, 15, 30) minutes. A leaked/forwarded link
        // that arrives after the window must be DEAD: verify() fails on TTL ⇒ 403,
        // and the row never mutates (state stays Open, no emergency_ack audit row),
        // so the deterministic sweep keeps watching.
        $user = User::factory()->create();
        $e = $this->emergency();
        $token = EmergencyAckToken::issue($e->id, $user->id);

        // Jump safely past the 30-minute TTL ceiling so the token has expired.
        \Illuminate\Support\Carbon::setTestNow(now()->addMinutes(31));

        try {
            $this->get(route('emergency.ack', ['token' => $token]))->assertForbidden();

            $e->refresh();
            $this->assertSame(EmergencyState::Open, $e->state);
            $this->assertNull($e->acknowledged_by);
            $this->assertDatabaseMissing('technician_action_logs', ['action_type' => 'emergency_ack']);
        } finally {
            \Illuminate\Support\Carbon::setTestNow();
        }
    }

    public function test_garbled_token_renders_branded_recovery_page_not_bare_403(): void
    {
        // A garbled link (claims() === null path) must land the AWAY operator on a
        // friendly, on-brand page — NOT the bare Laravel "403 FORBIDDEN" — while
        // keeping the 403 status and mutating nothing.
        $e = $this->emergency();

        $response = $this->get(route('emergency.ack', ['token' => 'garbage']));

        $response->assertForbidden();
        $response->assertSee('This link is no longer valid');
        $response->assertSee('still tracking this');
        $response->assertSee('on-call coordinator');
        $response->assertDontSee('Forbidden');

        $this->assertSame(EmergencyState::Open, $e->fresh()->state);
    }

    public function test_expired_token_renders_branded_recovery_page_not_bare_403(): void
    {
        // The verify()-fail path (here: TTL expiry) is the operator's single most
        // likely real failure — a late tap. It must also reach the branded page,
        // stay 403, and leave the emergency Open for the sweep.
        $user = User::factory()->create();
        $e = $this->emergency();
        $token = EmergencyAckToken::issue($e->id, $user->id);

        \Illuminate\Support\Carbon::setTestNow(now()->addMinutes(31));

        try {
            $response = $this->get(route('emergency.ack', ['token' => $token]));

            $response->assertForbidden();
            $response->assertSee('This link is no longer valid');
            $response->assertSee('still tracking this');
            $response->assertDontSee('Forbidden');

            $this->assertSame(EmergencyState::Open, $e->fresh()->state);
            $this->assertDatabaseMissing('technician_action_logs', ['action_type' => 'emergency_ack']);
        } finally {
            \Illuminate\Support\Carbon::setTestNow();
        }
    }

    /**
     * Flip a character in the signature portion of the base64url envelope so the
     * HMAC no longer verifies, while the envelope still base64url-decodes to JSON.
     */
    private function corruptSignature(string $token): string
    {
        $decoded = base64_decode(strtr($token, '-_', '+/'), true);
        $envelope = json_decode($decoded, true);
        // Corrupt the hex signature deterministically (swap a hex digit).
        $sig = $envelope['s'];
        $envelope['s'] = ($sig[0] === '0' ? '1' : '0').substr($sig, 1);

        return rtrim(strtr(base64_encode(json_encode($envelope)), '+/', '-_'), '=');
    }
}
