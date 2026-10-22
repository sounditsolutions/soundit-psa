<?php

namespace App\Services\Signing;

use App\Models\AgreementTemplate;
use App\Models\Contract;
use App\Models\SignableDocument;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class SigningService
{
    public function __construct(private PdfRenderer $renderer, private TemplateMerge $merge) {}

    public function send(SignableDocument $document): void
    {
        DB::transaction(function () use ($document): void {
            $document = SignableDocument::query()->lockForUpdate()->findOrFail($document->id);
            if (! config('signing.enabled') || $document->status !== 'draft') {
                throw new RuntimeException('signing_send_unavailable');
            }
            $token = Str::random(48);
            $document->update(['token_hash' => hash('sha256', $token), 'token_expires_at' => now()->addDays(config('signing.token_ttl_days', 14))]);
            $url = url('/sign/'.$token);
            try {
                app(\App\Services\EmailService::class)->sendNew(
                    $document->recipient_email, 'DRAFT — Agreement ready for review',
                    "DRAFT — Review your agreement using this link:\n".$url,
                    $document->recipient_name, redact: [$url, htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')],
                );
            } catch (Throwable $error) {
                $document->update(['token_hash' => null, 'token_expires_at' => null]);
                throw new RuntimeException('signing_mail_failed');
            }
            $document->update(['status' => 'sent', 'sent_at' => now()]);
        });
    }

    private function liveToken(string $token): SignableDocument
    {
        if (! config('signing.enabled') || ! preg_match('/^[A-Za-z0-9]{48}$/D', $token)) {
            throw new RuntimeException('signing_link_unavailable');
        }
        $hash = hash('sha256', $token);
        $document = SignableDocument::query()->where('token_hash', $hash)->lockForUpdate()->first();
        if (! $document || ! hash_equals($document->token_hash, $hash)
            || ! in_array($document->status, ['sent', 'viewed'], true)
            || ! $document->token_expires_at || $document->token_expires_at->isPast()) {
            throw new RuntimeException('signing_link_unavailable');
        }

        return $document;
    }

    public function view(string $token): SignableDocument
    {
        return DB::transaction(function () use ($token) {
            $document = $this->liveToken($token);
            if ($document->viewed_at === null) {
                $document->update(['viewed_at' => now(), 'status' => 'viewed']);
            }

            return $document;
        });
    }

    public function bytes(SignableDocument $document): string
    {
        $bytes = Storage::disk('local')->get($document->pdf_path);
        if (! hash_equals($document->pdf_sha256, hash('sha256', $bytes))) {
            throw new RuntimeException('signing_pdf_mismatch');
        }

        return $bytes;
    }

    public function sign(string $token, string $typedName, string $shownHash, string $ip, string $userAgent): void
    {
        DB::transaction(function () use ($token, $typedName, $shownHash, $ip, $userAgent): void {
            $document = $this->liveToken($token);
            $this->bytes($document);
            if (! hash_equals($document->pdf_sha256, $shownHash) || trim($typedName) === '' || mb_strlen($typedName) > 255) {
                throw new RuntimeException('signing_form_invalid');
            }
            $this->snapshot($document, 'client', $document->recipient_name, $document->recipient_email, $typedName, $ip, $userAgent);
            $document->update(['status' => 'signed', 'token_hash' => null, 'token_expires_at' => null]);
        });
    }

    private function snapshot(SignableDocument $document, string $role, string $name, string $email, string $typedName, string $ip, string $agent, ?int $userId = null): void
    {
        $document->snapshots()->create([
            'role' => $role, 'lines' => $document->rendered_lines,
            'lines_sha256' => $document->rendered_lines_sha256, 'pdf_sha256' => $document->pdf_sha256,
            'signer_name' => $name, 'signer_email' => $email, 'typed_name' => $typedName,
            'ip' => $ip, 'user_agent' => mb_substr($agent, 0, 512), 'user_id' => $userId, 'signed_at' => now(),
        ]);
    }

    public function countersign(SignableDocument $document, \App\Models\User $user, string $ip, string $agent): void
    {
        if (! $user->isAdmin() || ! config('signing.enabled')) {
            throw new RuntimeException('signing_countersign_forbidden');
        }
        DB::transaction(function () use ($document, $user, $ip, $agent): void {
            $document = SignableDocument::query()->lockForUpdate()->findOrFail($document->id);
            if ($document->status !== 'signed' || ! $document->requires_countersign) {
                throw new RuntimeException('signing_countersign_unavailable');
            }
            $this->bytes($document);
            $this->snapshot($document, 'msp', $user->name, $user->email, $user->name, $ip, $agent, $user->id);
            $document->update(['status' => 'countersigned']);
        });
    }

    public function void(SignableDocument $document): void
    {
        if (! config('signing.enabled')) {
            throw new RuntimeException('signing_disabled');
        }
        DB::transaction(function () use ($document): void {
            $document = SignableDocument::query()->lockForUpdate()->findOrFail($document->id);
            $document->update(['status' => 'void', 'voided_at' => now(), 'token_hash' => null, 'token_expires_at' => null]);
        });
    }

    public function generate(Contract $contract, AgreementTemplate $template, array $recipient): SignableDocument
    {
        if (! config('signing.enabled')) {
            throw new RuntimeException('signing_disabled');
        }
        $path = null;
        try {
            return DB::transaction(function () use ($contract, $template, $recipient, &$path) {
                $contract = Contract::query()->lockForUpdate()->findOrFail($contract->id);
                $template = AgreementTemplate::query()->lockForUpdate()->findOrFail($template->id);
                if ($template->signing_steps > 1) {
                    throw new RuntimeException('signing_multistep_unsupported');
                }
                if (! $template->is_active || $template->kind !== 'agreement' || $template->contract_type !== $contract->type->value) {
                    throw new RuntimeException('signing_template_unavailable');
                }
                $linesVersion = $contract->lines()->max('version');
                $lines = $contract->lines()->where('version', $linesVersion)->orderBy('sort_order')->orderBy('id')->get()->map(fn ($line) => $line->attributesToArray())->all();
                if ($lines === []) {
                    throw new RuntimeException('signing_lines_missing');
                }
                foreach ($lines as $line) {
                    if (($line['unit_price'] ?? null) === null && empty($line['pricing_tiers'])) {
                        throw new RuntimeException('signing_line_price_missing');
                    }
                }
                $json = json_encode($lines, JSON_THROW_ON_ERROR);
                $version = 1 + (int) SignableDocument::where('contract_id', $contract->id)->where('kind', 'agreement')->max('version');
                $document = new SignableDocument([
                    'contract_id' => $contract->id, 'agreement_template_id' => $template->id,
                    'kind' => 'agreement', 'version' => $version, 'lines_version' => $linesVersion,
                    'status' => 'draft', 'rendered_lines' => $lines,
                    'rendered_lines_sha256' => hash('sha256', $json),
                    'requires_countersign' => $template->requires_countersign,
                    'recipient_name' => $recipient['name'], 'recipient_email' => $recipient['email'],
                    'created_by' => auth()->id(),
                ]);
                $html = $this->merge->merge($template->body, [
                    'client.name' => $contract->client->name, 'contract.name' => $contract->name,
                    'contract.type' => $contract->type->value, 'today' => now()->toDateString(),
                    'signer.name' => $recipient['name'],
                ], $document)['html'];
                $bytes = $this->renderer->render($html);
                $document->pdf_sha256 = hash('sha256', $bytes);
                $path = 'signing/'.Str::uuid().'-v'.$version.'.pdf';
                $document->pdf_path = $path;
                if (! Storage::disk('local')->put($path, $bytes)) {
                    throw new RuntimeException('signing_pdf_write_failed');
                }
                $document->save();
                SignableDocument::where('contract_id', $contract->id)->where('kind', 'agreement')
                    ->where('id', '!=', $document->id)->whereIn('status', ['draft', 'sent', 'viewed'])
                    ->update(['status' => 'void', 'voided_at' => now(), 'token_hash' => null, 'token_expires_at' => null]);

                return $document;
            });
        } catch (Throwable $error) {
            if ($path !== null) {
                Storage::disk('local')->delete($path);
            }
            throw $error;
        }
    }
}
