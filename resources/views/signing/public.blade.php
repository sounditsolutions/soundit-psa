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
        @csrf
        <input type="hidden" name="pdf_sha256" value="{{ $hash }}">
        <label for="typed-name">DRAFT — Full name</label>
        <input id="typed-name" name="typed_name" maxlength="255" required autocomplete="off">
        <p>DRAFT — By selecting “Sign agreement”, I agree to the document shown above and intend my typed name to be my electronic signature.</p>
        <button type="submit">DRAFT — Sign agreement</button>
    </form>
    </div></section>
</main>
</body></html>
