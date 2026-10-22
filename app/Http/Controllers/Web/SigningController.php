<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AgreementTemplate;
use App\Models\Contract;
use App\Models\SignableDocument;
use App\Services\Signing\SigningService;
use Illuminate\Http\Request;
use Throwable;

class SigningController extends Controller
{
    public function publicPage(Request $request, string $token, SigningService $service)
    {
        try {
            if ($request->isMethod('post')) {
                $service->sign($token, (string) $request->input('typed_name'), (string) $request->input('pdf_sha256'), (string) $request->ip(), (string) $request->userAgent());

                return $this->neutral();
            }
            $document = $service->view($token);
            $service->bytes($document);

            return response()->view('signing.public', ['token' => $token, 'hash' => $document->pdf_sha256])->withHeaders($this->headers());
        } catch (Throwable) {
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
        } catch (Throwable) {
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

    public function generate(Request $request, Contract $contract, SigningService $service)
    {
        abort_unless(config('signing.enabled'), 404);
        $data = $request->validate(['template_id' => 'required|integer', 'name' => 'required|string|max:255', 'email' => 'required|email|max:255']);
        $service->generate($contract, AgreementTemplate::findOrFail($data['template_id']), $data);

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
            } catch (Throwable) {
                return response('', 409)->withHeaders($this->headers() + ['X-Content-Type-Options' => 'nosniff']);
            }

            return response($bytes)->withHeaders($this->headers() + ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="agreement.pdf"', 'X-Content-Type-Options' => 'nosniff']);
        }
        match ($action) {
            'send' => $service->send($document),
            'void' => $service->void($document),
            'countersign' => $service->countersign($document, $request->user(), (string) $request->ip(), (string) $request->userAgent()),
            default => abort(404),
        };

        return redirect()->route('contracts.show', $contract);
    }
}
