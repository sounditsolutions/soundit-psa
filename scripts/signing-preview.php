<?php

// Local fictional mockup fixture only; use the same confined renderer as signing.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$bytes = $app->make(\App\Services\Signing\PdfRenderer::class)->render(
    '<h1>DRAFT — Example agreement</h1><p>DRAFT — Fictional design fixture only.</p>'
);
file_put_contents(__DIR__.'/../design/mockups/contracts/signing-preview.pdf', $bytes);
