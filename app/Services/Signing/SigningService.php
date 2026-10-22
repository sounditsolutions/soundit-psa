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
