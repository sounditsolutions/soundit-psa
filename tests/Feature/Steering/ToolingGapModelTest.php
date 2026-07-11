<?php

namespace Tests\Feature\Steering;

use App\Enums\ToolingGapClassification;
use App\Enums\ToolingGapSource;
use App\Enums\ToolingGapStatus;
use App\Models\ToolingGap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ToolingGapModelTest extends TestCase
{
    use RefreshDatabase;

    /** record() persists the abstract/evidence split as two distinct fields. */
    public function test_record_persists_with_abstract_evidence_split(): void
    {
        $ticket = \App\Models\Ticket::factory()->create();
        $client = $ticket->client;

        $gap = ToolingGap::record(
            $ticket->id,
            $client->id,
            'agent should check recent ticket history for prior context',
            'ticket 22571: missed prior config in #142',
            ToolingGapClassification::ToolUnused,
            ToolingGapSource::Correction,
        );

        $this->assertDatabaseHas('tooling_gaps', ['id' => $gap->id]);
        $this->assertSame(ToolingGapStatus::Open, $gap->status);
        $this->assertSame('agent should check recent ticket history for prior context', $gap->capability_gap);
        $this->assertSame('ticket 22571: missed prior config in #142', $gap->evidence);
        $this->assertSame(ToolingGapClassification::ToolUnused, $gap->classification);
        $this->assertSame(ToolingGapSource::Correction, $gap->source);
        $this->assertNull($gap->agent_note);
    }

    /** Agent-sourced gap with a note and null ticket/client persists cleanly. */
    public function test_agent_sourced_with_note_and_null_fks(): void
    {
        $gap = ToolingGap::record(
            null,
            null,
            'needs a DNS lookup tool',
            null,
            ToolingGapClassification::ToolMissing,
            ToolingGapSource::Agent,
            'would have resolved the SPF question',
        );

        $this->assertNull($gap->ticket_id);
        $this->assertNull($gap->client_id);
        $this->assertSame('would have resolved the SPF question', $gap->agent_note);
    }

    /** A tool_broken report persists the tool_name alongside the abstract symptom. */
    public function test_record_persists_tool_name_for_broken_tool(): void
    {
        $gap = ToolingGap::record(
            null,
            null,
            'device lookup returned an empty list for a client that clearly has devices',
            null,
            ToolingGapClassification::ToolBroken,
            ToolingGapSource::Agent,
            null,
            'ninja_get_devices',
        );

        $fresh = ToolingGap::find($gap->id);
        $this->assertSame('ninja_get_devices', $fresh->tool_name);
        $this->assertSame(ToolingGapClassification::ToolBroken, $fresh->classification);
        // tool_name defaults to null for the non-broken classifications.
        $this->assertNull(
            ToolingGap::record(null, null, 'needs a DNS tool', null, ToolingGapClassification::ToolMissing, ToolingGapSource::Agent)->tool_name
        );
    }

    /** Enum casts round-trip through the database. */
    public function test_enum_casts_round_trip(): void
    {
        $gap = ToolingGap::record(
            null,
            null,
            'needs a reverse DNS tool',
            null,
            ToolingGapClassification::ToolMissing,
            ToolingGapSource::Agent,
        );

        $fresh = ToolingGap::find($gap->id);

        $this->assertInstanceOf(ToolingGapClassification::class, $fresh->classification);
        $this->assertInstanceOf(ToolingGapSource::class, $fresh->source);
        $this->assertInstanceOf(ToolingGapStatus::class, $fresh->status);
        $this->assertSame(ToolingGapClassification::ToolMissing, $fresh->classification);
        $this->assertSame(ToolingGapSource::Agent, $fresh->source);
        $this->assertSame(ToolingGapStatus::Open, $fresh->status);
    }

    /** fromInput() fails safe to the configured default for unknown/null values. */
    public function test_from_input_fail_safe(): void
    {
        $this->assertSame(ToolingGapClassification::ToolMissing, ToolingGapClassification::fromInput('garbage'));
        $this->assertSame(ToolingGapSource::Agent, ToolingGapSource::fromInput(null));
    }

    /** The tool_broken classification parses and labels correctly. */
    public function test_tool_broken_classification_parses_and_labels(): void
    {
        $this->assertSame(ToolingGapClassification::ToolBroken, ToolingGapClassification::fromInput('tool_broken'));
        $this->assertSame('Tool broken', ToolingGapClassification::ToolBroken->label());
    }

    /** Deleting the linked ticket nullifies ticket_id but the gap row survives. */
    public function test_fk_null_on_delete_ticket_survives(): void
    {
        $ticket = \App\Models\Ticket::factory()->create();

        $gap = ToolingGap::record(
            $ticket->id,
            null,
            'abstract capability gap outlives the ticket',
            'specific evidence from ticket',
            ToolingGapClassification::ToolUnused,
            ToolingGapSource::Correction,
        );

        // forceDelete because Ticket uses SoftDeletes; we need the row gone to trigger nullOnDelete.
        $ticket->forceDelete();

        $fresh = ToolingGap::find($gap->id);
        $this->assertNotNull($fresh, 'Gap row must survive ticket deletion');
        $this->assertNull($fresh->ticket_id);
    }
}
