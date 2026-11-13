<?php

namespace Tests\Feature\Carbon;

use Tests\TestCase;

/** The portal is the MSP's own site: its Hardware text speaks as "we", never a third party. */
class CarbonPortalVoiceTest extends TestCase
{
    public function test_hardware_strings_speak_as_the_msp(): void
    {
        $this->assertSame('Your shipping address is incomplete. Contact us to update it.', __('carbon_hardware.address_incomplete'));
        $this->assertSame("Your request is recorded. We'll confirm it with you.", __('carbon_hardware.recorded'));
        $this->assertSame("Nothing is charged now; we'll confirm the final price.", __('carbon_hardware.not_charged'));
        $this->assertSame("Request recorded — we'll confirm", __('carbon_hardware.pending'));
        $this->assertSame("Submitted — we'll confirm", __('carbon_hardware.unread'));
    }

    public function test_no_hardware_string_names_a_third_party_provider(): void
    {
        foreach (require lang_path('en/carbon_hardware.php') as $key => $line) {
            $this->assertStringNotContainsStringIgnoringCase('IT provider', $line, $key);
        }
    }
}
