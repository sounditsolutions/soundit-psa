<?php

namespace App\Services\Signing;

use Dompdf\Dompdf;
use Dompdf\Options;
use RuntimeException;

class PdfRenderer
{
    public function instance(): Dompdf
    {
        $directory = storage_path('app/private/signing-fonts');
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('signing_font_directory_failed');
        }
        chmod($directory, 0700);
        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $options->setChroot(resource_path('signing'));
        $options->setFontDir($directory);
        $options->setFontCache($directory);
        $options->setDefaultFont('DejaVu Sans');

        return new Dompdf($options);
    }

    public function render(string $html): string
    {
        $pdf = $this->instance();
        $pdf->loadHtml('<!doctype html><html><head><meta charset="utf-8"></head><body>'.$html.'</body></html>');
        $pdf->setPaper('A4');
        $pdf->render();

        return $pdf->output();
    }
}
