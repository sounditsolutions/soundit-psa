<?php

namespace Tests\Feature\Technician;

use App\Services\Technician\MissingDisclosureException;
use App\Services\Technician\TechnicianDisclosure;
use Tests\TestCase;

class TechnicianDisclosureTest extends TestCase
{
    public function test_with_disclosure_appends_banner_and_human_affordance(): void
    {
        $out = (new TechnicianDisclosure)->withDisclosure('Thanks for reaching out.', 'Chet');

        $this->assertStringContainsString('Thanks for reaching out.', $out);
        $this->assertStringContainsString(TechnicianDisclosure::DISCLOSURE_SENTINEL, $out);
        $this->assertStringContainsString('prefer to work with a person', $out);
    }

    public function test_assert_present_passes_for_a_disclosed_body(): void
    {
        $disclosure = new TechnicianDisclosure;
        $body = $disclosure->withDisclosure('Hello.', 'Chet');

        $disclosure->assertPresent($body); // must not throw
        $this->assertTrue(true);
    }

    public function test_assert_present_rejects_a_body_without_disclosure(): void
    {
        $this->expectException(MissingDisclosureException::class);

        (new TechnicianDisclosure)->assertPresent('Hello, this is John from the help desk.');
    }
}
