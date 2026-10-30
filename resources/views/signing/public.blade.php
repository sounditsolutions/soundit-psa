<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ __('signing.agreement.title') }}</title>@include('signing._style')</head>
<body>
<header>{{ __('signing.agreement.header') }}</header>
<div class="sign-pad">{{ \App\Services\Signing\SigningService::senderName() }}</div>
<main>
    <h1>{{ __('signing.agreement.title') }}</h1>
    <p>{{ __('signing.agreement.intro') }}</p>
    <section class="sign-card"><div class="sign-bar">{{ __('signing.agreement.document_bar') }}</div><div class="sign-pad">
    <object data="{{ route('signing.pdf', $token) }}" type="application/pdf" width="100%" height="600" aria-label="{{ __('signing.agreement.pdf_label') }}">
        <p>{{ __('signing.agreement.pdf_fallback') }}</p>
    </object>
    <p><a class="sign-link" href="{{ route('signing.pdf', $token) }}" rel="noreferrer">{{ __('signing.agreement.open_pdf') }}</a></p>
    </div></section>
    <section class="sign-card"><div class="sign-pad">
    <form method="post" action="{{ route('signing.public', $token) }}">
        <input type="hidden" name="pdf_sha256" value="{{ $hash }}">
        <label for="typed-name">{{ __('signing.agreement.name_label') }}</label>
        <input id="typed-name" name="typed_name" maxlength="255" required autocomplete="off">
        <p>{{ __('signing.agreement.consent') }}</p>
        <button type="submit">{{ __('signing.agreement.button') }}</button>
    </form>
    </div></section>
</main>
</body></html>
