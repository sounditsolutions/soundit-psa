<?php

namespace Tests\Feature\Triage;

use App\Models\Client;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Agent\Steering\CorrectionRecorder;
use App\Services\Triage\ContextBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cockpit Steering Task 3: ContextBuilder injects ticket corrections as a
 * trusted OPERATOR DIRECTIVE block outside any untrusted fence.
 */
class ContextBuilderCorrectionTest extends TestCase
{
    use RefreshDatabase;

    private function makeTicket(): Ticket
    {
        return Ticket::factory()->for(Client::factory())->create();
    }

    /** (a) A ticket_correction conversation causes an OPERATOR DIRECTIVE section to appear. */
    public function test_operator_directive_section_appears_when_correction_exists(): void
    {
        $ticket = $this->makeTicket();
        $operator = User::factory()->create();
        $correctionText = 'this client is on a no-auto-close contract';

        app(CorrectionRecorder::class)->record($ticket, $operator, $correctionText);

        $context = ContextBuilder::buildForTicket($ticket);

        $this->assertStringContainsString('=== OPERATOR DIRECTIVE', $context);
        $this->assertStringContainsString($correctionText, $context);
    }

    /** (b) No correction → no OPERATOR DIRECTIVE section. */
    public function test_no_directive_section_when_no_corrections_exist(): void
    {
        $ticket = $this->makeTicket();

        $context = ContextBuilder::buildForTicket($ticket);

        $this->assertStringNotContainsString('=== OPERATOR DIRECTIVE', $context);
    }

    /**
     * (c) The correction text must NOT be inside an untrusted fence.
     * Since buildForTicket does not wrap ticket data in UNTRUSTED fences for
     * this minimal fixture, we assert the correction is contained within the
     * OPERATOR DIRECTIVE block boundaries instead.
     */
    public function test_correction_text_is_inside_operator_directive_block_not_untrusted_fence(): void
    {
        $ticket = $this->makeTicket();
        $operator = User::factory()->create();
        $correctionText = 'this client is on a no-auto-close contract';

        app(CorrectionRecorder::class)->record($ticket, $operator, $correctionText);

        $context = ContextBuilder::buildForTicket($ticket);

        $correctionPos = strpos($context, $correctionText);
        $this->assertNotFalse($correctionPos, 'Correction text must be present in context');

        $lastUntrustedPos = strrpos($context, '=== UNTRUSTED');

        if ($lastUntrustedPos !== false) {
            // If there are UNTRUSTED fences, correction must appear after the last one.
            $this->assertGreaterThan(
                $lastUntrustedPos,
                $correctionPos,
                'Correction text must appear after the last UNTRUSTED fence marker'
            );
        } else {
            // No UNTRUSTED fence for this fixture — assert correction is inside the
            // OPERATOR DIRECTIVE block (between the opening and END markers).
            $directiveStart = strpos($context, '=== OPERATOR DIRECTIVE');
            $directiveEnd = strpos($context, '=== END OPERATOR DIRECTIVE');

            $this->assertNotFalse($directiveStart, 'OPERATOR DIRECTIVE start marker must be present');
            $this->assertNotFalse($directiveEnd, 'OPERATOR DIRECTIVE end marker must be present');
            $this->assertGreaterThan(
                $directiveStart,
                $correctionPos,
                'Correction text must appear inside the OPERATOR DIRECTIVE block'
            );
            $this->assertLessThan(
                $directiveEnd,
                $correctionPos,
                'Correction text must appear before the END OPERATOR DIRECTIVE marker'
            );
        }
    }
}
