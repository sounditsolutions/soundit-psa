<?php

namespace Tests\Feature\Support;

use App\Support\EmailRedactor;
use Tests\TestCase;

class EmailRedactorTest extends TestCase
{
    public function test_redacts_all_email_addresses(): void
    {
        $this->assertSame(
            'Forward to [external address withheld] and cc [external address withheld].',
            EmailRedactor::redact('Forward to Alice@Acme.test and cc bob@vendor.co.uk.'),
        );
        $this->assertSame('No addresses here.', EmailRedactor::redact('No addresses here.'));
    }
}
