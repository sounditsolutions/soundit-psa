<?php

namespace Tests\Feature;

use App\Models\Person;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PersonPhoneDisplayTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Regression for psa-klwu: a person row created outside PersonService
     * (seeder, import, pre-normalization sync) stores the raw phone/mobile
     * with a null display column. Every staff view renders only the display
     * column, so the contact's number showed blank. The model accessor now
     * falls back to formatting the raw number.
     */
    public function test_phone_and_mobile_display_fall_back_to_formatted_raw_number(): void
    {
        $person = Person::create([
            'first_name' => 'George',
            'last_name' => 'Costanza',
            'phone' => '5553997644',
            'mobile' => '5552577113',
        ]);

        // Reload so the accessor is exercised on a DB-rehydrated model — the
        // exact shape the bug reproduced with.
        $person->refresh();

        // Precondition: the denormalized display columns really are null.
        $this->assertNull($person->getRawOriginal('phone_display'));
        $this->assertNull($person->getRawOriginal('mobile_display'));

        // The tech can now see the number.
        $this->assertSame('(555) 399-7644', $person->phone_display);
        $this->assertSame('(555) 257-7113', $person->mobile_display);
    }

    /**
     * When PersonService has already populated the display column, that stored
     * value is preserved — the fallback only fills the gap, it never overrides.
     */
    public function test_stored_display_value_is_preferred_when_present(): void
    {
        $person = new Person([
            'phone' => '5553997644',
            'phone_display' => '(555) 399-7644 x12',
        ]);

        $this->assertSame('(555) 399-7644 x12', $person->phone_display);
    }

    /**
     * No number stored → no display. The fallback must not invent a value
     * (PhoneNumber::format() returns "Unknown" for empty input, which must
     * never leak into the UI).
     */
    public function test_display_is_null_when_no_number_stored(): void
    {
        $person = new Person([
            'first_name' => 'No',
            'last_name' => 'Phone',
        ]);

        $this->assertNull($person->phone_display);
        $this->assertNull($person->mobile_display);
    }
}
