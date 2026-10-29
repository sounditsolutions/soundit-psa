<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ __('signing.accepted.title') }}</title>@include('signing._style')</head>
<body><header>{{ __('signing.agreement.header') }}</header><div class="sign-pad">{{ \App\Services\Signing\SigningService::senderName() }}</div><main><section class="sign-card"><div class="sign-pad"><h1>{{ __('signing.accepted.title') }}</h1><p>{{ __('signing.accepted.body') }}</p></div></section></main></body></html>
