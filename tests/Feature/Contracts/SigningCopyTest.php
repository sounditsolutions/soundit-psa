<?php

namespace Tests\Feature\Contracts;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SigningCopyTest extends TestCase
{
    public function test_public_views_render_byte_identically_to_base(): void
    {
        Http::preventStrayRequests();
        $data = ['token' => str_repeat('A', 48), 'hash' => str_repeat('b', 64)];
        $base = <<<'BASE'
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>DRAFT — Review and sign</title>@include('signing._style')</head>
<body>
<header>DRAFT — Document signing</header>
<main>
    <h1>DRAFT — Review and sign</h1>
    <p>DRAFT — Review the document before entering your name.</p>
    <section class="sign-card"><div class="sign-bar">DRAFT — DOCUMENT</div><div class="sign-pad">
    <object data="{{ route('signing.pdf', $token) }}" type="application/pdf" width="100%" height="600" aria-label="DRAFT — Agreement PDF">
        <p>DRAFT — Use the PDF link below if the document does not appear.</p>
    </object>
    <p><a class="sign-link" href="{{ route('signing.pdf', $token) }}" rel="noreferrer">DRAFT — Open PDF</a></p>
    </div></section>
    <section class="sign-card"><div class="sign-pad">
    <form method="post" action="{{ route('signing.public', $token) }}">
        <input type="hidden" name="pdf_sha256" value="{{ $hash }}">
        <label for="typed-name">DRAFT — Full name</label>
        <input id="typed-name" name="typed_name" maxlength="255" required autocomplete="off">
        <p>DRAFT — By selecting “Sign agreement”, I agree to the document shown above and intend my typed name to be my electronic signature.</p>
        <button type="submit">DRAFT — Sign agreement</button>
    </form>
    </div></section>
</main>
</body></html>
BASE;
        $this->assertSame(Blade::render($base."\n", $data), view('signing.public', $data)->render());
        $base = <<<'BASE'
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>DRAFT — Link unavailable</title>@include('signing._style')</head>
<body><header>DRAFT — Document signing</header><main><section class="sign-card"><div class="sign-pad"><h1>DRAFT — Link unavailable</h1><p>DRAFT — This signing link cannot be used. Contact the sender if you need help.</p></div></section></main></body></html>
BASE;
        $this->assertSame(Blade::render($base."\n", $data), view('signing.neutral', $data)->render());
        $base = <<<'BASE'
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>DRAFT — Signature recorded</title>@include('signing._style')</head>
<body><header>DRAFT — Document signing</header><main><section class="sign-card"><div class="sign-pad"><h1>DRAFT — Signature recorded</h1><p>DRAFT — Your signature has been recorded. This link has now been used and will not open the document again. Contact the sender if you need a copy of the agreement.</p></div></section></main></body></html>
BASE;
        $this->assertSame(Blade::render($base."\n", $data), view('signing.signed', $data)->render());
    }

    public function test_all_copy_leaves_are_draft_and_agreement_mail_is_unchanged(): void
    {
        Http::preventStrayRequests();
        $copy = require base_path('lang/en/signing.php');
        array_walk_recursive($copy, function ($value): void {
            $this->assertStringStartsWith('DRAFT — ', $value);
        });
        $this->assertSame('DRAFT — Agreement ready for review', __('signing.mail.agreement.subject'));
        $this->assertSame("DRAFT — Review your agreement using this link:\nhttps://example.test/sign/example", __('signing.mail.agreement.body', ['url' => 'https://example.test/sign/example']));
    }
}
