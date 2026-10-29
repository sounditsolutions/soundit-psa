<?php

namespace App\Services\Signing;

use App\Models\AgreementTemplate;
use App\Models\Contract;
use App\Models\SignableDocument;
use App\Models\Sku;
use App\Support\PortalConfig;
use App\Support\PricingModelOverride;
use App\Support\TieredPricing;
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
                    $document->recipient_email, __('signing.mail.'.$document->kind.'.subject', ['company' => self::senderName()]),
                    __('signing.mail.'.$document->kind.'.body', ['url' => $url]),
                    $document->recipient_name, redact: [$url, htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')],
                );
            } catch (Throwable $error) {
                $document->update(['token_hash' => null, 'token_expires_at' => null]);
                throw new RuntimeException('signing_mail_failed', 0, $error);
            }
            $document->update(['status' => 'sent', 'sent_at' => now()]);
        });
    }

    /**
     * The MSP name for signing mail and pages: the Branding company name, trimmed.
     * Empty and whitespace-only names fall back to the app name here;
     * other PortalConfig consumers are unchanged.
     */
    public static function senderName(): string
    {
        $name = trim(PortalConfig::companyName());

        return $name !== '' ? $name : (string) config('app.name');
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
            if ($document->kind !== 'agreement') {
                throw new RuntimeException('signing_kind_mismatch');
            }
            $this->bytes($document);
            if (! hash_equals($document->pdf_sha256, $shownHash) || trim($typedName) === '' || mb_strlen($typedName) > 255) {
                throw new RuntimeException('signing_form_invalid');
            }
            $this->snapshot($document, 'client', $document->recipient_name, $document->recipient_email, $typedName, $ip, $userAgent);
            $document->update(['status' => 'signed', 'token_hash' => null, 'token_expires_at' => null]);
        });
    }

    public function accept(string $token, string $typedName, string $shownHash, string $ip, string $userAgent): void
    {
        DB::transaction(function () use ($token, $typedName, $shownHash, $ip, $userAgent): void {
            $document = $this->liveToken($token);
            if ($document->kind !== 'proposal') {
                throw new RuntimeException('signing_kind_mismatch');
            }
            $this->bytes($document);
            if (! hash_equals($document->pdf_sha256, $shownHash) || trim($typedName) === '' || mb_strlen($typedName) > 255) {
                throw new RuntimeException('signing_form_invalid');
            }
            $this->snapshot($document, 'acceptance', $document->recipient_name, $document->recipient_email, $typedName, $ip, $userAgent);
            $document->update(['status' => 'accepted', 'token_hash' => null, 'token_expires_at' => null]);
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
            if ($document->kind !== 'agreement' || $document->status !== 'signed' || ! $document->requires_countersign) {
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
            if (! $document->isVoidable()) {
                throw new RuntimeException('signing_void_unavailable');
            }
            $document->update(['status' => 'void', 'voided_at' => now(), 'token_hash' => null, 'token_expires_at' => null]);
        });
    }

    private function renderedLine(\App\Models\ContractLine $line): array
    {
        $attributes = $line->attributesToArray();
        $sku = $line->sku_id ? Sku::find($line->sku_id) : null;
        if (TieredPricing::normalize($line->pricing_tiers ?? []) === []
            && PricingModelOverride::volumeRateCardApplies($line->quantity_type, $sku)) {
            $attributes['sku_volume_tiers'] = $sku->backupStorageTiers->map(fn ($tier) => [
                'up_to_gb' => $tier->up_to_gb, 'unit_price' => $tier->unit_price,
            ])->all();
        }

        return $attributes;
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
                if (! in_array($template->signing_steps, [1, 2], true)) {
                    throw new RuntimeException('signing_multistep_unsupported');
                }
                if (! $template->is_active || ! in_array($template->kind, ['agreement', 'proposal'], true) || $template->contract_type !== $contract->type->value) {
                    throw new RuntimeException('signing_template_unavailable');
                }
                $linesVersion = $contract->lines()->max('version');
                $lines = $contract->lines()->where('version', $linesVersion)->orderBy('sort_order')->orderBy('id')->get();
                $acceptance = null;
                if ($template->kind === 'agreement' && $template->signing_steps === 2) {
                    $proposal = SignableDocument::where('contract_id', $contract->id)->where('kind', 'proposal')->orderByDesc('version')->lockForUpdate()->first();
                    if (! $proposal || $proposal->status !== 'accepted') {
                        throw new RuntimeException('signing_acceptance_missing');
                    }
                    $acceptance = $proposal->snapshots()->where('role', 'acceptance')->first();
                    if (! $acceptance || ! hash_equals($acceptance->lines_sha256, hash('sha256', $acceptance->getRawOriginal('lines')))
                        || ! is_string($proposal->rendered_lines_sha256) || ! hash_equals($proposal->rendered_lines_sha256, $acceptance->lines_sha256)) {
                        \Illuminate\Support\Facades\Log::warning('signing_acceptance_integrity', ['exception_class' => RuntimeException::class]);
                        throw new RuntimeException('signing_acceptance_integrity');
                    }
                    $diff = (new AcceptanceDiff)->compare($acceptance->lines, $lines->map(fn ($line) => $this->renderedLine($line))->all());
                    if ($diff !== []) {
                        throw new SigningAcceptanceStale($diff, $proposal, $acceptance);
                    }
                    $linesVersion = $proposal->lines_version;
                }
                if ($lines->isEmpty()) {
                    throw new RuntimeException('signing_lines_missing');
                }
                $renderedLines = $acceptance ? $acceptance->lines : $lines->map(fn ($line) => $this->renderedLine($line))->all();
                $lines = $renderedLines;
                $json = json_encode($lines, JSON_THROW_ON_ERROR);
                $version = 1 + (int) SignableDocument::where('contract_id', $contract->id)->where('kind', $template->kind)->max('version');
                $document = new SignableDocument([
                    'contract_id' => $contract->id, 'agreement_template_id' => $template->id,
                    'kind' => $template->kind, 'version' => $version, 'lines_version' => $linesVersion,
                    'accepted_snapshot_id' => $acceptance?->id,
                    'status' => 'draft', 'rendered_lines' => $lines,
                    'rendered_lines_sha256' => $acceptance ? $acceptance->lines_sha256 : hash('sha256', $json),
                    'requires_countersign' => $template->requires_countersign,
                    'recipient_name' => $recipient['name'], 'recipient_email' => $recipient['email'],
                    'created_by' => auth()->id(),
                ]);
                $merged = $this->merge->merge($template->body, [
                    'client.name' => $contract->client->name, 'contract.name' => $contract->name,
                    'contract.type' => $contract->type->value, 'today' => now()->toAppTz()->toDateString(),
                    'signer.name' => $recipient['name'],
                ], $document);
                if ($merged['unknown_tokens'] !== []) {
                    throw new RuntimeException('signing_unknown_merge_token');
                }
                if (! $merged['lines_table']) {
                    throw new RuntimeException('signing_lines_table_missing');
                }
                $bytes = $this->renderer->render($merged['html']);
                $document->pdf_sha256 = hash('sha256', $bytes);
                $path = 'signing/'.Str::uuid().'-v'.$version.'.pdf';
                $document->pdf_path = $path;
                if (! Storage::disk('local')->put($path, $bytes)) {
                    throw new RuntimeException('signing_pdf_write_failed');
                }
                $document->save();
                SignableDocument::where('contract_id', $contract->id)->where('kind', $template->kind)
                    ->where('id', '!=', $document->id)->lockForUpdate()->get()
                    ->filter(fn (SignableDocument $previous) => $previous->isVoidable())
                    ->each(fn (SignableDocument $previous) => $previous->update(['status' => 'void', 'voided_at' => now(), 'token_hash' => null, 'token_expires_at' => null]));

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
