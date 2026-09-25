<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ContactIntake\SubmissionAuthenticator;
use App\Services\ContactIntake\SubmissionLedger;
use App\Support\ContactIntakeConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

final class ContactSubmissionController extends Controller
{
    public function __invoke(Request $request, SubmissionAuthenticator $auth, SubmissionLedger $ledger)
    {
        if (! ContactIntakeConfig::enabled()) {
            return response()->json(['status' => 'not_found'], 404);
        }
        $body = $request->getContent();
        if (strlen($body) > SubmissionAuthenticator::MAX_BODY_BYTES) {
            return response()->json(['status' => 'too_large'], 413);
        }
        $keyId = $request->header('X-Intake-Key-Id', '');
        try {
            $authenticated = $request->getQueryString() === null
                && $auth->accepts($request->method(), $request->getPathInfo(), $body, $keyId,
                    $request->header('X-Intake-Timestamp', ''), $request->header('X-Intake-Signature', ''));
        } catch (\Throwable) {
            return response()->json(['status' => 'unavailable'], 503)->header('Retry-After', '60');
        }
        if (! $authenticated) {
            return response()->json(['status' => 'unauthorized'], 401);
        }
        $limitKey = 'contact-intake:'.hash('sha256', $keyId);
        if (RateLimiter::tooManyAttempts($limitKey, 60)) {
            return response()->json(['status' => 'rate_limited'], 429)
                ->header('Retry-After', (string) RateLimiter::availableIn($limitKey));
        }
        RateLimiter::hit($limitKey, 60);
        try {
            if (strtolower(trim(explode(';', $request->header('Content-Type', ''))[0])) !== 'application/json') {
                throw new \InvalidArgumentException;
            }
            $input = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
            $fields = ['submission_id', 'name', 'email', 'message', 'phone', 'company', 'inquiry', 'submitted_at'];
            if (! is_array($input) || array_diff($fields, array_keys($input)) || array_diff(array_keys($input), $fields)) {
                throw new \InvalidArgumentException;
            }
            if (! is_string($input['submission_id']) || ! preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i', $input['submission_id'])) {
                return response()->json(['status' => 'invalid', 'fields' => ['submission_id']], 422);
            }
            $receipt = $ledger->accept($keyId, $input);
            if ($receipt['conflict']) {
                return response()->json(['status' => 'conflict'], 409);
            }

            return response()->json(['status' => $receipt['duplicate'] ? 'duplicate' : 'accepted',
                'submission_id' => $input['submission_id'], 'receipt' => $receipt['receipt']], $receipt['duplicate'] ? 200 : 201);
        } catch (ValidationException $e) {
            return response()->json(['status' => 'invalid', 'fields' => array_keys($e->errors())], 422);
        } catch (\JsonException|\InvalidArgumentException) {
            return response()->json(['status' => 'invalid', 'fields' => ['envelope']], 422);
        } catch (\Throwable) {
            // Do not report SQL exception bindings or raw input. A failed durable write
            // never earns a receipt; a transport retry remains safe via uniqueness.
            return response()->json(['status' => 'unavailable'], 503)->header('Retry-After', '60');
        }
    }
}
