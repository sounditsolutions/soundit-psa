<?php

namespace Tests\Feature\Contracts;

use App\Services\Signing\PdfRenderer;
use Tests\TestCase;

class SigningFontConfinementTest extends TestCase
{
    public function test_render_preserves_vendor_font_listing_contents_and_mtimes(): void
    {
        $renderer = new PdfRenderer;
        $vendor = realpath(base_path('vendor/dompdf/dompdf/lib/fonts'));
        $options = $renderer->instance()->getOptions();
        // Fail before rendering a mutant: a test must not contaminate shared vendor bytes.
        $this->assertSame(storage_path('app/private/signing-fonts'), $options->getFontCache());
        $this->assertSame(storage_path('app/private/signing-fonts'), $options->getFontDir());
        $inventory = function () use ($vendor): array {
            clearstatcache();
            $files = [];
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($vendor, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->isFile()) {
                    $files[$file->getPathname()] = [$file->getMTime(), $file->getSize(), hash_file('sha256', $file->getPathname())];
                }
            }
            ksort($files);

            return $files;
        };
        $before = $inventory();
        $this->assertNotEmpty($before);
        $bytes = $renderer->render('<h1>DRAFT — Example</h1><p>DRAFT — Regular <strong>bold</strong> <em>italic</em></p>');
        $this->assertStringStartsWith('%PDF-', $bytes);
        $this->assertSame($before, $inventory());
    }
}
