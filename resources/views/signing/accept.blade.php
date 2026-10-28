<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ __('signing.proposal.title') }}</title>@include('signing._style')</head>
<body>
<header>{{ __('signing.agreement.header') }}</header>
<main>
<h1>{{ __('signing.proposal.title') }}</h1>
<p>{{ __('signing.proposal.intro') }}</p>
<section class="sign-card"><div class="sign-bar">{{ __('signing.proposal.document_bar') }}</div><div class="sign-pad">
<object data="{{ route('signing.pdf', $token) }}" type="application/pdf" width="100%" height="600" aria-label="{{ __('signing.proposal.pdf_label') }}"><p>{{ __('signing.proposal.pdf_fallback') }}</p></object>
<p><a class="sign-link" href="{{ route('signing.pdf', $token) }}" rel="noreferrer">{{ __('signing.proposal.open_pdf') }}</a></p>
</div></section>
<section class="sign-card"><div class="sign-pad">
<form method="post" action="{{ route('signing.public', $token) }}">
<input type="hidden" name="pdf_sha256" value="{{ $hash }}">
<label for="typed-name">{{ __('signing.proposal.name_label') }}</label>
<input id="typed-name" name="typed_name" maxlength="255" required autocomplete="off">
<p>{{ __('signing.proposal.non_binding') }}</p>
<button type="submit">{{ __('signing.proposal.button') }}</button>
</form>
</div></section>
</main>
</body></html>
