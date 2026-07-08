# PSA Write-Surface Polish (psa-19s4) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Resolve the three tracked deferred-minors from the P2a/P2b MCP write-surface (PRs #151/#152): (1) `site_notes_updated_by` attribution under MCP auth, (2) a `create_client` duplicate guard, (3) cross-client pivot reconcile on a contact move.

**Architecture:** Small, targeted fixes to the existing PSA MCP write surface (`StaffPsaActionToolExecutor`) and its domain services (`ClientService`, `PersonService`). No new tools, no schema changes. Each minor follows an existing in-repo pattern.

**Tech Stack:** PHP 8.3, Laravel 12, PHPUnit (sqlite `:memory:`), Mockery. No new deps.

## Global Constraints

- **AI-actor attribution** for MCP-authored writes uses `TechnicianConfig::requiredAiActorUserId(): int` (strict; throws if unconfigured) — the same call the PSA executor already uses for every audit row. Never `auth()->id()` under MCP (no web session → null).
- **create_client dedup = honest refusal, NOT idempotent replay.** A duplicate returns an `['error' => ...]`, never `['success'=>true,'idempotent'=>true]`. Mirror the detection shape of `create_ticket` (pre-creation payload hash + `technician_action_logs` lookup, `self::DIRECT_DEDUP_HOURS` = 24h window), but scoped to `action_type='create_client'` + `content_hash` only (no `client_id` — a create is global/parentless).
- **Pivot reconcile = DETACH** (not reassign/dedupe) any `contract_person`/`asset_person` row whose linked contract/asset `client_id` differs from the person's new `client_id`. Rationale: `mergePeople` is structurally same-client (guarded throw) so sets no cross-client precedent; the real precedent is `TicketService::moveToClient` (detach-and-report). Fix in `PersonService::updatePerson` so the web UI benefits too. Surface counts in the tool result. (Flag this choice in the PR body for the Mayor.)
- `vendor/bin/pint` clean; `tests/Feature/Mcp` + `tests/Feature/PersonMergeServiceTest` + the ClientService/PersonService suites green.
- **Scope discipline:** exactly these three minors. Do NOT add dedup to `create_contact`/`create_asset` (they share the same latent post-creation-id hash defect — note it in the PR body as a follow-up, don't fix here).

---

### Task 1: Minor 1 — `site_notes_updated_by` actor attribution under MCP auth

**Files:**
- Modify: `app/Services/ClientService.php` (`updateSiteNotes` signature :38 + the `site_notes_updated_by` line :63)
- Modify: `app/Services/Mcp/StaffPsaActionToolExecutor.php:655` (the `updateSiteNotes` call in `updateClientSiteNotes`)
- Test: `tests/Feature/Mcp/PsaRecordsToolsTest.php` (`test_update_client_site_notes_writes_and_audits`)

**Interfaces:**
- Produces: `ClientService::updateSiteNotes(Client $client, ?string $siteNotes, ?string $expectedUpdatedAt = null, ?int $updatedByUserId = null): Client` — new optional 4th param; `null` falls back to `auth()->id()` (preserves the web caller verbatim).

- [ ] **Step 1: Failing test** — in `test_update_client_site_notes_writes_and_audits`, capture the actor and assert the column. Change `$this->configureAiActor();` to `$actor = $this->configureAiActor();`, and after the existing `$client->refresh();` block add:

```php
$this->assertSame($actor->id, $client->site_notes_updated_by);
```

- [ ] **Step 2: Run — expect FAIL** (`site_notes_updated_by` is null under MCP auth):
`php artisan test tests/Feature/Mcp/PsaRecordsToolsTest.php --filter test_update_client_site_notes_writes_and_audits`
Expected: FAIL — `Failed asserting that null matches expected <actor id>`.

- [ ] **Step 3: Add the param to the service.** In `app/Services/ClientService.php`, change the signature (:38):

```php
    public function updateSiteNotes(Client $client, ?string $siteNotes, ?string $expectedUpdatedAt = null, ?int $updatedByUserId = null): Client
```

and the update array's actor line (:63) from `'site_notes_updated_by' => auth()->id(),` to:

```php
            'site_notes_updated_by' => $updatedByUserId ?? auth()->id(),
```

- [ ] **Step 4: Thread the actor from the executor.** In `app/Services/Mcp/StaffPsaActionToolExecutor.php:655`, change:

```php
        $this->clientService->updateSiteNotes($client, $siteNotes, $expectedUpdatedAt, TechnicianConfig::requiredAiActorUserId());
```

(The method already calls `TechnicianConfig::requiredAiActorUserId()` a few lines below for its audit row, so no new import.)

- [ ] **Step 5: Run — expect PASS** (same filter). Then the full `PsaRecordsToolsTest`: `php artisan test tests/Feature/Mcp/PsaRecordsToolsTest.php` — all green (the web-path `updateClient`→`updateSiteNotes` 2-arg call still compiles; the 4th param is optional).

- [ ] **Step 6: Pint + commit**

```bash
vendor/bin/pint app/Services/ClientService.php app/Services/Mcp/StaffPsaActionToolExecutor.php tests/Feature/Mcp/PsaRecordsToolsTest.php
git add app/Services/ClientService.php app/Services/Mcp/StaffPsaActionToolExecutor.php tests/Feature/Mcp/PsaRecordsToolsTest.php
git commit -m "psa-19s4 (1/3): attribute site_notes_updated_by to the AI actor under MCP auth"
```

---

### Task 2: Minor 2 — `create_client` duplicate guard (honest refusal)

**Files:**
- Modify: `app/Services/Mcp/StaffPsaActionToolExecutor.php` (`createClient` :529-566; add two helpers near `alreadyCreatedTicketLog` ~:2086)
- Test: `tests/Feature/Mcp/PsaRecordsToolsTest.php` (new test)

**Interfaces:**
- Consumes: `self::DIRECT_DEDUP_HOURS` (=24, already defined :36), `TechnicianActionLog`, `auditEntityExecution`.
- Produces: two private helpers `createClientContentHash(array $validated): string` and `duplicateCreateClientRecently(string $contentHash): bool`.

- [ ] **Step 1: Failing test** — add to `PsaRecordsToolsTest.php` beside the other `create_client` tests:

```php
public function test_create_client_refuses_recent_duplicate_by_content_hash(): void
{
    $this->configureAiActor();
    $token = $this->token(['create_client'], 'chet');
    $payload = ['name' => 'Dup Co', 'email' => 'dup@example.test', 'city' => 'Portland'];

    $first = $this->callTool($token, 'create_client', $payload);
    $first->assertOk();
    $this->assertFalse((bool) $first->json('result.isError'), (string) $first->json('result.content.0.text'));

    $second = $this->callTool($token, 'create_client', $payload);
    $second->assertOk();
    $this->assertTrue((bool) $second->json('result.isError'), 'a duplicate create_client must be refused');
    $this->assertStringContainsString('already created', (string) $second->json('result.content.0.text'));
    // Honest refusal, not idempotent replay:
    $this->assertStringNotContainsString('idempotent', (string) $second->json('result.content.0.text'));

    $this->assertSame(1, Client::query()->count());
    $this->assertSame(1, TechnicianActionLog::where('action_type', 'create_client')->count());
}
```

- [ ] **Step 2: Run — expect FAIL** (`php artisan test tests/Feature/Mcp/PsaRecordsToolsTest.php --filter test_create_client_refuses_recent_duplicate_by_content_hash`): two clients created, count is 2, no refusal.

- [ ] **Step 3: Add the two helpers.** In `app/Services/Mcp/StaffPsaActionToolExecutor.php`, near `alreadyCreatedTicketLog` (~:2086), add:

```php
    /** @param  array<string, mixed>  $validated */
    private function createClientContentHash(array $validated): string
    {
        return hash('sha256', 'create_client:'.json_encode($validated, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function duplicateCreateClientRecently(string $contentHash): bool
    {
        return TechnicianActionLog::query()
            ->where('action_type', 'create_client')
            ->where('result_status', 'executed')
            ->where('content_hash', $contentHash)
            ->where('created_at', '>=', now()->subHours(self::DIRECT_DEDUP_HOURS))
            ->exists();
    }
```

- [ ] **Step 4: Wire the guard into `createClient`.** Replace the body from the `validateClientPayload` block through the audit call (:543-560) with:

```php
        $validated = $this->validateClientPayload($arguments, isCreate: true);
        if (isset($validated['error'])) {
            return $validated;
        }

        // Pre-creation payload hash (NOT the old post-creation hash that baked in
        // the new client id and could never match a prior row). Honest refusal on
        // a recent identical create — a client create is not a replayable idempotent op.
        $contentHash = $this->createClientContentHash($validated);
        if ($this->duplicateCreateClientRecently($contentHash)) {
            return ['error' => 'A client with identical details was already created recently. Change at least one field, or use find_clients to check for an existing match, before retrying.'];
        }

        $client = $this->clientService->createClient($validated);

        $this->auditEntityExecution(
            'create_client',
            'client',
            (int) $client->id,
            (int) $client->id,
            $actorLabel,
            $contentHash,
            'Client created: '.$client->name.'.',
            TechnicianConfig::requiredAiActorUserId(),
        );
```

(This replaces the prior `$this->mutationContentHash('create_client', (int) $client->id, $validated)` inline argument — the audit row now records the pre-creation payload hash so the dedup query can actually match it.)

- [ ] **Step 5: Run — expect PASS** (same filter), then the full `PsaRecordsToolsTest` (the existing `create_client` tests: the content-hash assertion is format-only `^[a-f0-9]{64}$`, still satisfied; the four non-duplicate tests use distinct payloads/single calls, unaffected).

- [ ] **Step 6: Pint + commit**

```bash
vendor/bin/pint app/Services/Mcp/StaffPsaActionToolExecutor.php tests/Feature/Mcp/PsaRecordsToolsTest.php
git add app/Services/Mcp/StaffPsaActionToolExecutor.php tests/Feature/Mcp/PsaRecordsToolsTest.php
git commit -m "psa-19s4 (2/3): refuse recent duplicate create_client via pre-creation content hash"
```

---

### Task 3: Minor 3 — cross-client pivot reconcile on contact move (DETACH)

**Files:**
- Modify: `app/Services/PersonService.php` (`updatePerson` :42-67; add two methods)
- Modify: `app/Services/Mcp/StaffPsaActionToolExecutor.php` (`moveContactToClient` :908-969 — pre-count + surface counts)
- Test: `tests/Feature/Mcp/PsaPeopleToolsTest.php` (tool-level), `tests/Feature/PersonMergeServiceTest.php` or a PersonService test (service-level shared behavior)

**Interfaces:**
- Produces on `PersonService`:
  - `crossClientPivotCounts(Person $person, int $clientId): array` → `['contracts'=>int,'assets'=>int]` — counts the person's `contract_person`/`asset_person` rows whose linked contract/asset `client_id !== $clientId`.
  - `detachCrossClientPivots(Person $person): array` → `['contracts'=>int,'assets'=>int]` — detaches those rows relative to the person's *current* `client_id`; idempotent; returns detached counts.
- Behavior: `updatePerson` calls `detachCrossClientPivots` when `client_id` changed (shared: web + MCP).

- [ ] **Step 1: Failing test (tool).** Add to `tests/Feature/Mcp/PsaPeopleToolsTest.php`:

```php
public function test_move_contact_detaches_cross_client_pivots_and_reports_counts(): void
{
    $this->configureAiActor();
    $from = Client::factory()->create(['name' => 'From Co']);
    $to = Client::factory()->create(['name' => 'To Co']);
    $person = Person::factory()->for($from)->create();

    // A manual contract link + a device link, both at the OLD client.
    $contract = Contract::factory()->for($from)->create();
    $person->contracts()->attach($contract->id, ['assignment_source' => 'manual', 'assigned_at' => now()]);
    $asset = Asset::factory()->for($from)->create();
    $person->assets()->attach($asset->id, ['assignment_source' => 'manual']);

    $token = $this->token(['move_contact_to_client'], 'chet');
    $response = $this->callTool($token, 'move_contact_to_client', [
        'contact_id' => $person->id,
        'new_client_id' => $to->id,
        'confirm_client_name' => 'To Co',
    ]);

    $response->assertOk();
    $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));

    $person->refresh();
    $this->assertSame($to->id, $person->client_id);
    // The old-client pivots are gone (they'd otherwise point at From Co's contract/device).
    $this->assertSame(0, $person->contracts()->count());
    $this->assertSame(0, $person->assets()->count());

    $result = $this->decodedResult($response);
    $this->assertSame(1, $result['contracts_detached']);
    $this->assertSame(1, $result['assets_detached']);
}
```

(Confirm the test file's imports include `Contract`, `Asset`; add if missing. Verify `Contract`/`Asset` factories support `->for($client)` — they use `client_id`; if a factory needs required fields, mirror how `PersonMergeServiceTest` builds contracts/assets.)

- [ ] **Step 2: Run — expect FAIL** (`--filter test_move_contact_detaches_cross_client_pivots_and_reports_counts`): pivots survive; `contracts_detached` key is absent (undefined index) or counts are 0.

- [ ] **Step 3: Add the two PersonService methods.** In `app/Services/PersonService.php`, add:

```php
    /**
     * Count the person's contract/device pivot links that point at a client
     * other than $clientId (i.e. would be cross-client after a move to it).
     *
     * @return array{contracts:int, assets:int}
     */
    public function crossClientPivotCounts(Person $person, int $clientId): array
    {
        return [
            'contracts' => $person->contracts()->where('contracts.client_id', '!=', $clientId)->count(),
            'assets' => $person->assets()->where('assets.client_id', '!=', $clientId)->count(),
        ];
    }

    /**
     * Detach the person's contract/device pivot links that point at a client
     * other than the person's CURRENT client — after a cross-client move such a
     * link is factually wrong (regardless of assignment_source). Idempotent.
     *
     * @return array{contracts:int, assets:int}
     */
    public function detachCrossClientPivots(Person $person): array
    {
        $contractIds = $person->contracts()->where('contracts.client_id', '!=', $person->client_id)->pluck('contracts.id')->all();
        $assetIds = $person->assets()->where('assets.client_id', '!=', $person->client_id)->pluck('assets.id')->all();

        if ($contractIds !== []) {
            $person->contracts()->detach($contractIds);
        }
        if ($assetIds !== []) {
            $person->assets()->detach($assetIds);
        }

        return ['contracts' => count($contractIds), 'assets' => count($assetIds)];
    }
```

- [ ] **Step 4: Call it from `updatePerson` on a client change.** In `updatePerson` (:50-66), after `$person->update($data);` and before `if ($additionalEmails !== null) {`, add:

```php
        if ($person->wasChanged('client_id')) {
            $this->detachCrossClientPivots($person);
        }
```

(This runs inside the existing `DB::transaction`, after `$person->update` has fired `PersonObserver::updated` — which already detaches *rule*-sourced old-client contract links and re-evaluates new-client rules; this catches the remaining cross-client links, i.e. manual contract links and all device links.)

- [ ] **Step 5: Surface counts in the tool.** In `app/Services/Mcp/StaffPsaActionToolExecutor.php::moveContactToClient`, replace the update + return block (the `$updated = ...updatePerson(...)` line through the final `return [...]`) with a pre-count and enriched result:

```php
        $reason = $this->optionalString($arguments, 'reason');
        $oldClientId = (int) $person->client_id;

        // Count the links that will become cross-client BEFORE updatePerson detaches them.
        $pivots = $this->personService->crossClientPivotCounts($person, $newClient->id);

        // Reparent as a non-primary in the target client — a moved contact must
        // not silently become a second primary there (the target keeps its own).
        $updated = $this->personService->updatePerson($person, ['client_id' => $newClient->id, 'is_primary' => false]);

        $this->auditEntityExecution(
            'move_contact_to_client',
            'person',
            (int) $updated->id,
            (int) $updated->client_id,
            $actorLabel,
            $this->mutationContentHash('move_contact_to_client', (int) $updated->id, ['new_client_id' => $newClient->id], $reason),
            'Contact '.$this->contactDisplayName($updated).' moved from client #'.$oldClientId.' to #'.$newClient->id
                .($pivots['contracts'] + $pivots['assets'] > 0 ? ' (detached '.$pivots['contracts'].' contract, '.$pivots['assets'].' device link(s))' : '')
                .($reason ? ' — '.$reason : '').'.',
            TechnicianConfig::requiredAiActorUserId(),
        );

        return [
            'success' => true,
            'contact_id' => $updated->id,
            'client_id' => $updated->client_id,
            'contracts_detached' => $pivots['contracts'],
            'assets_detached' => $pivots['assets'],
            'message' => 'Contact moved.'.($pivots['contracts'] + $pivots['assets'] > 0
                ? ' Detached '.$pivots['contracts'].' contract and '.$pivots['assets'].' device link(s) that pointed at the previous client.'
                : ''),
        ];
```

- [ ] **Step 6: Failing test (service, shared behavior).** Add to `tests/Feature/PersonMergeServiceTest.php` (it already has the Contract/Asset+pivot fixtures) a direct `updatePerson` test:

```php
public function test_update_person_detaches_cross_client_pivots_on_client_change(): void
{
    $from = Client::factory()->create();
    $to = Client::factory()->create();
    $person = Person::factory()->for($from)->create();
    $contract = Contract::factory()->for($from)->create();
    $person->contracts()->attach($contract->id, ['assignment_source' => 'manual', 'assigned_at' => now()]);
    $asset = Asset::factory()->for($from)->create();
    $person->assets()->attach($asset->id, ['assignment_source' => 'manual']);

    app(\App\Services\PersonService::class)->updatePerson($person, ['client_id' => $to->id]);

    $person->refresh();
    $this->assertSame($to->id, $person->client_id);
    $this->assertSame(0, $person->contracts()->count());
    $this->assertSame(0, $person->assets()->count());
}
```

- [ ] **Step 7: Run both new tests + regressions.**
`php artisan test tests/Feature/Mcp/PsaPeopleToolsTest.php tests/Feature/PersonMergeServiceTest.php` — all green. Confirm the existing `move_contact_to_client` tests (`test_move_contact_to_client_requires_typed_confirm_and_reparents`, `test_move_contact_does_not_create_duplicate_primary_in_target`) still pass (they don't create cross-client pivots, so counts are 0 and the base behavior is unchanged).

- [ ] **Step 8: Pint + commit**

```bash
vendor/bin/pint app/Services/PersonService.php app/Services/Mcp/StaffPsaActionToolExecutor.php tests/Feature/Mcp/PsaPeopleToolsTest.php tests/Feature/PersonMergeServiceTest.php
git add app/Services/PersonService.php app/Services/Mcp/StaffPsaActionToolExecutor.php tests/Feature/Mcp/PsaPeopleToolsTest.php tests/Feature/PersonMergeServiceTest.php
git commit -m "psa-19s4 (3/3): detach cross-client contact pivots on move + report counts"
```

---

## Final Verification

- [ ] `vendor/bin/pint --test` clean on all changed files.
- [ ] `php artisan test tests/Feature/Mcp tests/Feature/PersonMergeServiceTest.php` green; then full `php artisan test` green.
- [ ] `/soundpsa-review-pr` on the branch; address findings.
- [ ] PR + comment on psa-19s4 + notify Mayor; **hold merge**. In the PR body flag: (a) minor-3 DETACH-vs-reassign choice + that it changes shared `updatePerson` (web UI now reconciles too); (b) the noted-but-out-of-scope `create_contact`/`create_asset` post-creation-id hash defect as a follow-up.
