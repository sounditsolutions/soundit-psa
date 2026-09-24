<?php

namespace App\Services\ContactIntake;

use App\Models\ContactSubmission;
use App\Support\ContactIntakeConfig;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/** Internal foundation only; no HTTP route or CRM writer calls this yet. */
final class SubmissionLedger
{
    /** @return array{receipt: string, conflict: bool} */
    public function accept(string $integrationId, array $input): array
    {
        if (! ContactIntakeConfig::enabled()) {
            throw new \DomainException('Contact intake is disabled.');
        }
        $data = Validator::make($input, [
            'submission_id' => ['required', 'uuid'],
            'name' => ['required', 'string', 'max:200'],
            'email' => ['required', 'email:rfc', 'max:254'],
            'message' => ['required', 'string', 'max:16000'],
            'phone' => ['nullable', 'string', 'max:50'],
            'company' => ['nullable', 'string', 'max:200'],
            'submitted_at' => ['required', 'date_format:Y-m-d\TH:i:s\Z'],
        ])->validate();
        if (! preg_match('/\A[a-zA-Z0-9_-]{1,64}\z/', $integrationId)
            || array_diff(array_keys($input), array_keys($data)) !== []) {
            throw new \InvalidArgumentException('Invalid submission envelope.');
        }
        // Canonical field order; the hash retains the original claimed values.
        ksort($data);
        $payloadHash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
        $identityHash = hash_hmac('sha256', mb_strtolower(trim($data['email'])), app('encrypter')->getKey());

        return DB::transaction(function () use ($integrationId, $data, $payloadHash, $identityHash) {
            // Unique insert arbitrates absent rows; lockForUpdate serializes existing rows.
            // Every future processor must lock this identity before lookup/create, too.
            try {
                DB::table('contact_intake_identities')->insert(['identity_hash' => $identityHash]);
            } catch (UniqueConstraintViolationException $e) {
                // Only duplicate identity is expected; all other database errors escape.
                if (! DB::table('contact_intake_identities')->where('identity_hash', $identityHash)->exists()) {
                    throw $e;
                }
            }
            DB::table('contact_intake_identities')->where('identity_hash', $identityHash)->lockForUpdate()->firstOrFail();

            $candidate = new ContactSubmission([
                'integration_id' => $integrationId,
                'submission_id' => strtolower($data['submission_id']),
                'receipt' => (string) Str::uuid(),
                'payload_hash' => $payloadHash,
                'identity_hash' => $identityHash,
                'payload' => $data,
                'state' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            // Model cast encrypts before query-builder insertion (which runs no casts).
            try {
                DB::table('contact_submissions')->insert($candidate->getAttributes());
            } catch (UniqueConstraintViolationException $e) {
                if (! ContactSubmission::where('integration_id', $integrationId)
                    ->where('submission_id', strtolower($data['submission_id']))->exists()) {
                    throw $e;
                }
            }
            $row = ContactSubmission::where('integration_id', $integrationId)
                ->where('submission_id', strtolower($data['submission_id']))->lockForUpdate()->firstOrFail();
            $conflict = ! hash_equals($row->payload_hash, $payloadHash);
            if ($conflict) {
                $row->increment('conflicts');
            }

            return ['receipt' => $row->receipt, 'conflict' => $conflict];
        }, 3);
    }
}
