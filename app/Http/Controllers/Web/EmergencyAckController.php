<?php

namespace App\Http\Controllers\Web;

use App\Enums\EmergencyState;
use App\Http\Controllers\Controller;
use App\Models\TechnicianActionLog;
use App\Models\TechnicianEmergency;
use App\Services\Technician\Emergency\EmergencyAckToken;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * One-tap emergency acknowledgement endpoint (Phase 2).
 *
 * The signed token IS the auth — this route lives OUTSIDE the auth/SSO group so
 * an away operator clicking the link is NOT bounced to SSO (CO-16). The token is
 * an unauthenticated bearer credential, so the security invariants are:
 *
 *   1. claims() decodes the claimed {em, u} but does NOT trust them;
 *   2. verify() must pass (HMAC + TTL) before ANY state mutation — a tampered or
 *      expired token can never reach the CAS update (returns 403);
 *   3. the CAS update (state=open ⇒ acknowledged) is the single-use latch: a
 *      second tap, or an out-of-band ack, matches 0 rows ⇒ idempotent 200.
 *
 * CO-5(a)/(d): an explicit ack is a SNOOZE, not a permanent stop. This controller
 * only flips open ⇒ acknowledged + audits; the deterministic sweep (Task 10)
 * keeps watching until a human actually touches the ticket. Nothing here halts
 * escalation permanently. The ack URL must never be sent over SMS (enforced in
 * the escalation service, not here).
 */
class EmergencyAckController extends Controller
{
    public function ack(Request $request, string $token): Response
    {
        // 1. Decode the claimed {em, u} WITHOUT trusting them (CO-16).
        $claims = EmergencyAckToken::claims($token);
        if ($claims === null) {
            abort(403);
        }

        $emergencyId = $claims['em'];
        $userId = $claims['u'];

        // 2. Verify the HMAC + TTL against the claimed tuple. A tampered/expired
        //    token fails here and never touches the row.
        if (! EmergencyAckToken::verify($token, $emergencyId, $userId)) {
            abort(403);
        }

        // 3. Single-use CAS: only an OPEN emergency flips to acknowledged. A second
        //    tap (or an out-of-band ack) matches 0 rows ⇒ idempotent, no overwrite.
        $affected = TechnicianEmergency::query()
            ->where('id', $emergencyId)
            ->where('state', EmergencyState::Open->value)
            ->update([
                'state' => EmergencyState::Acknowledged->value,
                'acknowledged_at' => now(),
                'acknowledged_by' => $userId,
            ]);

        $emergency = TechnicianEmergency::find($emergencyId);

        if ($affected > 0) {
            $this->audit($emergency, $userId);
        }

        return response()->view('technician.emergency.ack', [
            'ticketId' => $emergency?->ticket_id,
        ]);
    }

    /**
     * Append-only audit row for the acknowledgement. Supplies the FULL NOT-NULL
     * column set so the INSERT succeeds on MariaDB (prod), not just SQLite.
     */
    private function audit(?TechnicianEmergency $emergency, int $userId): void
    {
        $emergencyId = $emergency?->id;

        TechnicianActionLog::create([
            'actor_id' => $userId,
            'actor_label' => 'operator',
            'action_type' => 'emergency_ack',
            'tier' => 'auto',
            'result_status' => 'executed',
            'ticket_id' => $emergency?->ticket_id,
            'client_id' => $emergency?->client_id,
            'run_id' => null,
            'content_hash' => hash('sha256', 'emergency_ack:'.$emergencyId.':'.$userId),
            'summary' => 'Operator acknowledged emergency #'.$emergencyId.' via one-tap link.',
            'correlation_id' => 'emergency:'.$emergencyId,
        ]);
    }
}
