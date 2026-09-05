<?php

namespace Tests\Unit\Support;

use App\Support\HdbPressId;
use Tests\TestCase;

/**
 * The three "not a press id" cases below are the measured ones: on prod, 51 of
 * 675 notes across the 50 most recent helpdesk_button tickets contain a UUID and
 * THREE of them are not press ids. A "first UUID wins" parser mis-keys ~6% of
 * button tickets, which is what these tests exist to prevent regressing to.
 */
class HdbPressIdTest extends TestCase
{
    private const UUID = '780d16b2-76f4-4931-837b-c2917fb8db9a';

    private const OTHER_UUID = '2f9c1a04-8b1e-4d77-9a3c-55e0b6d21f88';

    public function test_parses_press_id_from_press_view_link(): void
    {
        $body = 'Report: https://beta.helpdeskbuttons.com/pressView.php?pressID='.self::UUID;

        $this->assertSame(self::UUID, HdbPressId::fromBody($body));
    }

    public function test_parses_press_id_from_connect_link(): void
    {
        $body = 'Connect: https://beta.helpdeskbuttons.com/connect?pressID='.self::UUID;

        $this->assertSame(self::UUID, HdbPressId::fromBody($body));
    }

    public function test_both_links_together_yield_one_press_id(): void
    {
        // This is the real note shape: 48 of 50 tickets carry BOTH links.
        $body = "A HelpDesk Button ticket was submitted.\n"
            .'View report: https://beta.helpdeskbuttons.com/pressView.php?pressID='.self::UUID."\n"
            .'Connect to user: https://beta.helpdeskbuttons.com/connect?pressID='.self::UUID;

        $this->assertSame(self::UUID, HdbPressId::fromBody($body));
        $this->assertSame([self::UUID], HdbPressId::allInBody($body));
    }

    public function test_bare_uuid_is_not_a_press_id(): void
    {
        // Measured false positive 1: an account-enabled-state note.
        $this->assertNull(HdbPressId::fromBody('Account enabled. Reference '.self::UUID));

        // Measured false positive 3: a Tactical list_devices session uuid.
        $this->assertNull(HdbPressId::fromBody('list_devices session '.self::UUID.' returned 4 agents'));
    }

    public function test_prose_mention_of_the_remote_link_without_a_press_id_is_not_matched(): void
    {
        // Measured false positive 2: a technician note referring to the remote
        // link in prose, carrying a uuid that is not a pressID parameter.
        $body = 'Used the remote link on helpdeskbuttons.com to connect. Session was '.self::UUID;

        $this->assertNull(HdbPressId::fromBody($body));
    }

    public function test_lookalike_host_is_rejected(): void
    {
        $this->assertNull(HdbPressId::fromBody(
            'https://helpdeskbuttons.com.evil.test/pressView.php?pressID='.self::UUID
        ));
        $this->assertNull(HdbPressId::fromBody(
            'https://nothelpdeskbuttons.com/pressView.php?pressID='.self::UUID
        ));
    }

    public function test_subdomain_and_html_escaped_ampersand_are_accepted(): void
    {
        $this->assertSame(self::UUID, HdbPressId::fromBody(
            'https://beta.helpdeskbuttons.com/pressView.php?foo=1&amp;pressID='.self::UUID
        ));
        $this->assertSame(self::UUID, HdbPressId::fromBody(
            'https://helpdeskbuttons.com/pressView.php?pressID='.self::UUID
        ));
    }

    public function test_absent_is_a_normal_state_not_an_error(): void
    {
        $this->assertNull(HdbPressId::fromBody('Customer called back.'));
        $this->assertNull(HdbPressId::fromBody(null));
        $this->assertNull(HdbPressId::fromBody(''));
    }

    public function test_two_different_press_ids_in_one_body_name_no_endpoint(): void
    {
        // The only ambiguity left under the note model, and it is a real one:
        // this single note names two endpoints, so it names neither. Two press
        // ids on one TICKET is no longer ambiguous and is no longer this
        // class's business — each note carries its own key.
        $body = 'https://beta.helpdeskbuttons.com/pressView.php?pressID='.self::UUID."\n"
            .'https://beta.helpdeskbuttons.com/pressView.php?pressID='.self::OTHER_UUID;

        $this->assertNull(HdbPressId::fromBody($body));
        $this->assertSame([self::UUID, self::OTHER_UUID], HdbPressId::allInBody($body));
    }

    public function test_the_same_press_id_twice_in_one_body_is_the_normal_shape(): void
    {
        // The real vendor note carries the SAME press id on both links — the
        // pressView one and the connect one — and casing must not split them.
        $body = 'https://beta.helpdeskbuttons.com/pressView.php?pressID='.self::UUID."\n"
            .'https://beta.helpdeskbuttons.com/connect?pressID='.strtoupper(self::UUID);

        $this->assertSame(self::UUID, HdbPressId::fromBody($body));
        $this->assertSame([self::UUID], HdbPressId::allInBody($body));
    }
}
