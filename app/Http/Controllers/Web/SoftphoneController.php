<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\PhoneCall;
use App\Services\Plivo\PlivoCallControlService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SoftphoneController extends Controller
{
    public function __construct(
        private readonly PlivoCallControlService $callControl,
    ) {}

    /**
     * The softphone popup window (Plivo Browser SDK client).
     */
    public function index()
    {
        return view('softphone.index');
    }

    /**
     * Put the active call on hold (play hold music to the remote caller).
     */
    public function hold(Request $request): JsonResponse
    {
        return $this->toggle($request, hold: true);
    }

    /**
     * Take the active call off hold (stop hold music, restore the bridge).
     */
    public function unhold(Request $request): JsonResponse
    {
        return $this->toggle($request, hold: false);
    }

    private function toggle(Request $request, bool $hold): JsonResponse
    {
        $validated = $request->validate([
            // Plivo call UUIDs are hex + hyphens; the pattern also keeps the
            // value safe to interpolate into the Plivo API URL path.
            'call_uuid' => ['required', 'string', 'min:8', 'max:100', 'regex:/^[A-Za-z0-9\-]+$/'],
        ]);

        $callUuid = $validated['call_uuid'];

        // Best-effort association with the local call record for logging only.
        // Never a hard dependency: for inbound PHLO calls the browser SDK's leg
        // UUID can differ from the caller-leg UUID we stored, and hold must still
        // work in that case.
        $callId = PhoneCall::where('call_uuid', $callUuid)->value('id');

        $ok = $hold
            ? $this->callControl->hold($callUuid)
            : $this->callControl->unhold($callUuid);

        Log::info('[Softphone] '.($hold ? 'hold' : 'unhold'), [
            'call_uuid' => $callUuid,
            'call_id' => $callId,
            'user_id' => $request->user()?->id,
            'ok' => $ok,
        ]);

        return response()->json(['success' => $ok]);
    }
}
