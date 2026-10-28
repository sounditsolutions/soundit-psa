<?php

namespace Tests\Unit;

use App\Enums\CallStatus;
use App\Models\PhoneCall;
use App\Models\TicketNote;
use PHPUnit\Framework\TestCase;

class BillableTimeAccessorsTest extends TestCase
{
    public function test_note_null_uses_actual_including_adjustment(): void
    {
        $note = new TicketNote(['time_minutes' => 60, 'billable_minutes' => null]);
        $note->time_adjustment_minutes = 15;
        $this->assertSame(75, $note->billedMinutes());
        $this->assertSame(60, $note->time_minutes);
    }

    public function test_note_override_clamps_when_actual_is_lowered(): void
    {
        $note = new TicketNote(['time_minutes' => 90, 'billable_minutes' => '60']);
        $this->assertSame(60, $note->billedMinutes());
        $note->time_minutes = 45;
        $this->assertSame(45, $note->billedMinutes());
        $this->assertSame(60, $note->billable_minutes);
        $note->billable_minutes = 0;
        $this->assertSame(0, $note->billedMinutes());
        $note->billable_minutes = null;
        $this->assertSame(45, $note->billedMinutes());
    }

    public function test_call_null_uses_actual_seconds_and_recording_fallback(): void
    {
        $call = new PhoneCall(['billable_minutes' => null]);
        $call->duration = 702;
        $this->assertSame(702, $call->billedSeconds());
        $call->duration = null;
        $call->recording_duration = 123;
        $this->assertSame(123, $call->billedSeconds());
    }

    public function test_call_override_clamps_to_actual_seconds(): void
    {
        $call = new PhoneCall(['billable_minutes' => '12']);
        $call->duration = 702;
        $this->assertSame(702, $call->billedSeconds());
        $this->assertSame(702, $call->duration);
        $call->billable_minutes = 5;
        $this->assertSame(300, $call->billedSeconds());
        $call->duration = 120;
        $this->assertSame(120, $call->billedSeconds());
        $call->billable_minutes = 0;
        $this->assertSame(0, $call->billedSeconds());
    }

    public function test_no_positive_duration_cannot_be_created_by_an_override(): void
    {
        foreach ([null, 0, -1] as $duration) {
            $call = new PhoneCall(['status' => CallStatus::Voicemail, 'billable_minutes' => 12]);
            $call->duration = $duration;
            $this->assertNull($call->billedSeconds());
            $this->assertSame($duration, $call->duration);
        }
    }
}
