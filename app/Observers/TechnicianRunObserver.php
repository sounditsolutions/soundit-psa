<?php

namespace App\Observers;

use App\Enums\TechnicianRunState;
use App\Jobs\NotifyStagedActionAwaitingApproval;
use App\Models\TechnicianRun;

/**
 * Notify the operator when a run enters AwaitingApproval (Tier-1, psa-2f0bg).
 *
 * WHY AN OBSERVER RATHER THAN THE CALL SITES. There is no single staging call site:
 * runs reach AwaitingApproval from at least six places — StaffCippWriteToolExecutor,
 * StaffTacticalActionToolExecutor, StaffTacticalAdminToolExecutor, DraftPipeline,
 * SendReplyTool, ProposeCloseTool and IntakeRecorder. Notifying at each one would be a
 * hand-maintained list that must agree with reality, and every future stage site would
 * silently miss it. psa-g4y9f hit that exact failure class twice in one night (the
 * cockpit approve match, then the cockpit badge map). One transition, one hook.
 *
 * WHY afterCommit(). CO-21 (ProposeCloseTool) forbids an external send from inside the
 * executor's DB transaction. afterCommit() honours that properly rather than by
 * avoidance: the job is only enqueued once the row is committed, so a stage that rolls
 * back never notifies, and the worker always sees a row that really exists. It is the
 * established idiom here (InvoiceObserver, TranscriptionService).
 *
 * Fires ONLY on the transition INTO AwaitingApproval — creation in that state, or an
 * update that moves into it (e.g. a superseded proposal being revived). Later
 * transitions out of it (Done, denied) must not re-notify.
 */
class TechnicianRunObserver
{
    public function created(TechnicianRun $run): void
    {
        if ($this->isAwaitingApproval($run)) {
            $this->dispatch($run);
        }
    }

    public function updated(TechnicianRun $run): void
    {
        // Only the transition, not every save while already awaiting approval.
        if (! $run->wasChanged('state')) {
            return;
        }

        if ($this->isAwaitingApproval($run)) {
            $this->dispatch($run);
        }
    }

    private function isAwaitingApproval(TechnicianRun $run): bool
    {
        $state = $run->state;

        return $state instanceof TechnicianRunState
            ? $state === TechnicianRunState::AwaitingApproval
            : $state === TechnicianRunState::AwaitingApproval->value;
    }

    private function dispatch(TechnicianRun $run): void
    {
        NotifyStagedActionAwaitingApproval::dispatch($run->id)->afterCommit();
    }
}
