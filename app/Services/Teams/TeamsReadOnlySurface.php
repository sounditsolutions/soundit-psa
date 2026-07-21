<?php

namespace App\Services\Teams;

use App\Services\Assistant\AssistantToolExecutor;

/**
 * ONE Teams turn's read-only tool surface: the exact schema handed to the model,
 * and the executor that will run its calls — the same snapshot, carried together.
 *
 * psa-uw2o.21 / psa-uw2o.22 — WHY THIS IS AN OBJECT AND NOT TWO FUNCTIONS.
 *
 * TeamsReplyService used to build the two halves as separate calls:
 *
 *     $tools    = TeamsReadOnlyToolset::definitions();
 *     $executor = TeamsReadOnlyToolset::executor($userId);   // called definitions() AGAIN
 *
 * Both halves resolve the vendor availability probes AT THE MOMENT THEY ARE
 * ASKED, so they were two answers to the same question taken at two different
 * instants, and a lane that flipped in between made them disagree — in either
 * direction. Granted between them, the executor ran a tool the model was never
 * offered (proved with Mesh: a schema built without mesh_api_key, then
 * mesh_get_email_events executing and returning vendor event data). Revoked
 * between them, the executor refused a tool that WAS published (proved with
 * Ninja: published_first=true, ninja_probe_calls=2, executor_result=refusal).
 *
 * THE INVARIANT, AND WHY IT IS STRUCTURAL RATHER THAN MAINTAINED.
 *
 * $allowed is not filtered from the same sources as $tools — it is DERIVED FROM
 * $tools, the very array this object hands to AiClient. There is no second
 * derivation to keep in step, and nothing here re-resolves availability. The
 * published set and the runnable set are the same list read twice.
 *
 * That distinction is the whole point. The previous fix (a28cc29) filtered both
 * sides through the same predicate and asserted in a comment that they therefore
 * "cannot disagree" — which was false, because identical filtering over
 * DIFFERENT BASE SETS is not equality, and nineteen published names sat over
 * fifty-nine runnable ones. Deriving one from the other has no base set to
 * differ about.
 *
 * The read-classification check is kept as a second conjunct even though
 * TeamsReadOnlyToolset already publishes reads only. It is the property this
 * surface exists for, and it must not survive merely as a side effect of how the
 * publisher happens to be written today.
 */
final class TeamsReadOnlySurface
{
    /** What the executor returns for anything this surface will not run. */
    public const REFUSAL = ['error' => 'That tool is not available in chat (read-only).'];

    /**
     * @param  list<array<string, mixed>>  $tools  the schema published to the model
     * @param  list<string>  $allowed  names($tools) that are also read-classified
     */
    private function __construct(
        private readonly array $tools,
        private readonly array $allowed,
        private readonly ?int $userId,
    ) {}

    /**
     * Bind a turn to the schema it published.
     *
     * $reads is passed in rather than read here so that ONE snapshot of the
     * executor's classification covers both the publishing filter and this
     * allowlist — see TeamsReadOnlyToolset::forTurn(), which takes it once.
     *
     * @param  list<array<string, mixed>>  $tools  the array that will be handed to AiClient, verbatim
     * @param  list<string>  $reads  AssistantToolExecutor::readTools() for this turn
     */
    public static function of(array $tools, array $reads, ?int $userId): self
    {
        return new self(
            $tools,
            array_values(array_intersect(array_column($tools, 'name'), $reads)),
            $userId,
        );
    }

    /**
     * The schema for this turn. Must be handed to AiClient AS IS — the executor's
     * allowlist was derived from this array, so publishing anything else breaks
     * the only property this class provides.
     *
     * @return list<array<string, mixed>>
     */
    public function tools(): array
    {
        return $this->tools;
    }

    /** Will this turn run $name? True only for names this turn published. */
    public function allows(string $name): bool
    {
        return in_array($name, $this->allowed, true);
    }

    /**
     * The executor for this turn: refuses anything outside the published set
     * BEFORE the inner executor is reached.
     *
     * This guard is load-bearing, not belt-and-braces. AiClient::executeToolLoop()
     * dispatches whatever tool NAME comes back from the model without checking it
     * against the schema it sent (hardening that seam is filed separately as
     * psa-ejzjd, since AiClient is shared with triage and the portal chatbot). So
     * every arm the shared AssistantToolExecutor can dispatch — the MCP staff
     * server's reads, every vendor lane, every writer — is reachable by name here,
     * and this allowlist is what makes an unpublished capability unrunnable.
     *
     * @return callable(string, array<string, mixed>): mixed
     */
    public function executor(): callable
    {
        $inner = new AssistantToolExecutor(null, null, $this->userId);

        return function (string $name, array $input) use ($inner): mixed {
            if (! $this->allows($name)) {
                return self::REFUSAL;
            }

            return $inner->execute($name, $input);
        };
    }
}
