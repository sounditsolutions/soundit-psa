<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AgreementTemplate;
use App\Models\Contract;
use App\Models\SignableDocument;
use App\Services\Signing\SigningService;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

class SigningController extends Controller
{
    private const REFUSALS = [
        'signing_multistep_unsupported' => 'Multi-step templates cannot be generated yet.',
        'signing_template_unavailable' => 'The template is not an active agreement template for this contract type.',
        'signing_lines_missing' => 'The contract has no lines to show.',
        'signing_line_price_missing' => 'A contract line has neither a unit price nor tiers.',
        'signing_line_price_inherited' => 'A backup storage line is priced by its SKU volume tiers, which agreements cannot show yet.',
        'signing_unknown_merge_token' => 'The template contains a merge field that is not supported.',
        'signing_lines_table_missing' => 'The template does not include the {{lines_table}} rate card.',
        'signing_pdf_write_failed' => 'The PDF could not be stored.',
        'signing_send_unavailable' => 'This document cannot be sent in its current state.',
        'signing_mail_failed' => 'The email send failed; the document stays a draft and its link was cancelled.',
        'signing_countersign_unavailable' => 'This document cannot be countersigned in its current state.',
        'signing_countersign_forbidden' => 'Countersigning is unavailable.',
        'signing_void_unavailable' => 'This document cannot be voided in its current state.',
        'signing_pdf_mismatch' => 'The stored PDF does not match its recorded hash.',
    ];

    public function publicPage(Request $request, string $token, SigningService $service)
    {
        try {
            if ($request->isMethod('post')) {
                $service->sign($token, (string) $request->input('typed_name'), (string) $request->input('pdf_sha256'), (string) $request->ip(), (string) $request->userAgent());

                return response()->view('signing.signed')->withHeaders($this->headers());
            }
            $document = $service->view($token);
            $service->bytes($document);

            return response()->view('signing.public', ['token' => $token, 'hash' => $document->pdf_sha256])->withHeaders($this->headers());
        } catch (Throwable $error) {
            \Illuminate\Support\Facades\Log::warning('signing_request_refused', ['exception_class' => $error::class]);

            return $this->neutral();
        }
    }

    public function pdf(string $token, SigningService $service)
    {
        try {
            $document = $service->view($token);

            return response($service->bytes($document))->withHeaders($this->headers() + [
                'Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="agreement.pdf"',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        } catch (Throwable $error) {
            \Illuminate\Support\Facades\Log::warning('signing_request_refused', ['exception_class' => $error::class]);

            return $this->neutral();
        }
    }

    private function headers(): array
    {
        return ['Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer'];
    }

    private function neutral()
    {
        return response()->view('signing.neutral')->withHeaders($this->headers());
    }

    private function refused(Contract $contract, RuntimeException $error)
    {
        $code = $error->getMessage();
        if (! isset(self::REFUSALS[$code])) {
            throw $error;
        }

        \Illuminate\Support\Facades\Log::warning('signing_staff_refused', ['exception_class' => $error::class]);

        return response('DRAFT — '.self::REFUSALS[$code].' ('.$code.')', 409)->withHeaders($this->headers());
    }

    public function generate(Request $request, Contract $contract, SigningService $service)
    {
        abort_unless(config('signing.enabled'), 404);
        $data = $request->validate(['template_id' => 'required|integer', 'recipient_name' => 'required|string|max:255', 'recipient_email' => 'required|email|max:255']);
        try {
            $service->generate($contract, AgreementTemplate::findOrFail($data['template_id']), ['name' => $data['recipient_name'], 'email' => $data['recipient_email']]);
        } catch (RuntimeException $error) {
            return $this->refused($contract, $error);
        }

        return redirect()->route('contracts.show', $contract);
    }

    public function action(Request $request, Contract $contract, SignableDocument $document, string $action, SigningService $service)
    {
        abort_unless($document->contract_id === $contract->id, 404);
        abort_unless(config('signing.enabled'), 404);
        if ($action === 'download') {
            abort_unless($document->isFinal(), 404);
            try {
                $bytes = $service->bytes($document);
            } catch (Throwable $error) {
                \Illuminate\Support\Facades\Log::warning('signing_request_refused', ['exception_class' => $error::class]);

                return response('', 409)->withHeaders($this->headers() + ['X-Content-Type-Options' => 'nosniff']);
            }

            return response($bytes)->withHeaders($this->headers() + ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="agreement.pdf"', 'X-Content-Type-Options' => 'nosniff']);
        }
        try {
            match ($action) {
                'send' => $service->send($document),
                'void' => $service->void($document),
                'countersign' => $service->countersign($document, $request->user(), (string) $request->ip(), (string) $request->userAgent()),
                default => abort(404),
            };
        } catch (RuntimeException $error) {
            return $this->refused($contract, $error);
        }

        return redirect()->route('contracts.show', $contract);
    }
}
