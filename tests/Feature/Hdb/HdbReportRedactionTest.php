<?php

namespace Tests\Feature\Hdb;

use App\Services\Hdb\HdbReportRedaction;
use Tests\TestCase;

class HdbReportRedactionTest extends TestCase
{
    /**
     * The redaction reader in isolation, both directions, so the refusal
     * cannot be satisfied by a reader that returns null for everything.
     */
    public function test_the_redaction_reader_accepts_booleans_and_refuses_everything_else(): void
    {
        $ok = HdbReportRedaction::fromTicket(['redactDiagnostic' => true, 'redactScreenshots' => false]);

        $this->assertNotNull($ok);
        $this->assertTrue($ok->diagnostic);
        $this->assertFalse($ok->screenshots);
        $this->assertTrue($ok->allowsScreenshots());

        $blocked = HdbReportRedaction::fromTicket(['redactDiagnostic' => false, 'redactScreenshots' => true]);

        $this->assertNotNull($blocked);
        $this->assertFalse($blocked->allowsScreenshots());

        $this->assertNull(HdbReportRedaction::fromTicket([]));
        $this->assertNull(HdbReportRedaction::fromTicket(['redactDiagnostic' => 'true', 'redactScreenshots' => true]));
    }
}
