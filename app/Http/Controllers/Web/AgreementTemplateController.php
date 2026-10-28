<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\AgreementTemplateRequest;
use App\Models\AgreementTemplate;

class AgreementTemplateController extends Controller implements \Illuminate\Routing\Controllers\HasMiddleware
{
    public static function middleware(): array
    {
        return [function ($request, $next) {
            abort_unless(config('signing.enabled'), 404);

            return $next($request);
        }];
    }

    public function index()
    {
        return view('settings.agreement-templates.index', ['templates' => AgreementTemplate::orderBy('name')->get()]);
    }

    public function create()
    {
        return view('settings.agreement-templates.form', ['template' => new AgreementTemplate]);
    }

    public function edit(AgreementTemplate $template)
    {
        return view('settings.agreement-templates.form', compact('template'));
    }

    public function store(AgreementTemplateRequest $request)
    {
        $template = AgreementTemplate::create($request->templateAttributes());

        return redirect()->route('settings.agreement-templates.edit', $template);
    }

    public function update(AgreementTemplateRequest $request, AgreementTemplate $template)
    {
        $template->update($request->templateAttributes());

        return redirect()->route('settings.agreement-templates.edit', $template);
    }

    public function activate(AgreementTemplate $template)
    {
        $template->update(['is_active' => true]);

        return redirect()->route('settings.agreement-templates.index');
    }

    public function deactivate(AgreementTemplate $template)
    {
        $template->update(['is_active' => false]);

        return redirect()->route('settings.agreement-templates.index');
    }
}
