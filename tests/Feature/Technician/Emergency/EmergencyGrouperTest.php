<?php

namespace Tests\Feature\Technician\Emergency;

use App\Models\Client;
use App\Models\TechnicianEmergency;
use App\Models\Ticket;
use App\Services\Technician\Emergency\EmergencyDetector;
use App\Services\Technician\Emergency\EmergencyGrouper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmergencyGrouperTest extends TestCase
{
    use RefreshDatabase;

    public function test_same_signature_within_window_groups_into_one(): void
    {
        $client = Client::factory()->create();
        $t1 = Ticket::factory()->create(['client_id' => $client->id, 'subject' => 'OUTAGE site A', 'opened_at' => now()]);
        $t2 = Ticket::factory()->create(['client_id' => $client->id, 'subject' => 'OUTAGE site A', 'opened_at' => now()]);

        $det = app(EmergencyDetector::class);
        $grp = app(EmergencyGrouper::class);

        $e1 = $grp->groupOrCreate($t1, $det->assess($t1));
        $e2 = $grp->groupOrCreate($t2, $det->assess($t2));

        $this->assertSame($e1->id, $e2->id);                 // one group
        $this->assertCount(2, $e2->fresh()->ticket_ids);
        $this->assertSame(1, TechnicianEmergency::count());
    }

    public function test_different_signature_creates_separate(): void
    {
        $client = Client::factory()->create();
        $a = Ticket::factory()->create(['client_id' => $client->id, 'subject' => 'OUTAGE site A', 'opened_at' => now()]);
        $b = Ticket::factory()->create(['client_id' => $client->id, 'subject' => 'ransomware site B', 'opened_at' => now()]);
        $det = app(EmergencyDetector::class);
        $grp = app(EmergencyGrouper::class);
        $grp->groupOrCreate($a, $det->assess($a));
        $grp->groupOrCreate($b, $det->assess($b));
        $this->assertSame(2, TechnicianEmergency::count());
    }
}
