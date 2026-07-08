# CIPP Password-Reset MCP Tool (psa-h186) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Wrap CIPP `ExecResetPass` as a grantable, sensitive, direct MCP tool (`cipp_reset_user_password`) in the `cipp_write` group that resets one server-derived M365 user's password and returns the newly-generated temporary password to the calling technician — while guaranteeing the credential never lands in any log or audit sink.

**Architecture:** Mirror the existing curated `cipp_write` safety pattern (server-derived tenant/person via `CippWriteScopeResolver`, `confirm_upn` typed-confirm, kill-switch, cooldown, `TechnicianActionLog` audit, ungranted-by-default). This is the *first* cipp_write tool that must read back an upstream response value, so `CippRestWriteClient::send()` gains an **opt-in** per-call `captureBody` path, and the executor gets a **dedicated direct method** (`executeResetPassword`) instead of the generic void `executeUpstream`/`executeDirect` — keeping the credential's blast radius minimal. The temp password flows only into the synchronous MCP tool result (delivered to the caller in `content.0.text`); every audit/log sink records the action + target UPN only.

**Tech Stack:** PHP 8.3, Laravel 12, PHPUnit (`RefreshDatabase`), Mockery, `Http::fake`. No new dependencies.

## Global Constraints

- **Direct-only tool.** No staged/cockpit twin, no `STAGED_TO_DIRECT` entry, no cockpit `match` arm. (Handoff plan specifies a single synchronous tool; a staged twin would surface the credential in the cockpit UI — a new sink.)
- **Credential redaction (hard requirement).** The temp password must NEVER appear in `TechnicianActionLog`, `McpAuditLog`, or the Laravel log. It appears only in the tool result returned to the caller. Every task's tests must preserve this; the executor task adds an explicit redaction test-lock.
- **`MustChange` default = true.** The temp-password method. Exposed as an optional `must_change` boolean param (CIPP supports both modes — source-verified). Absent → true.
- **Source-verified ExecResetPass shape** (from KelvinTegelaar/CIPP-API): `POST api/ExecResetPass`, JSON body `{tenantFilter, ID: <upn>, MustChange: <bool>}`; success `HTTP 200` body `{Results: {copyField: <temp-password>, resultText, state: "success"|"warning"}}`. `state == "warning"` ⇒ user is directory/AD-synced (cloud reset may not stick). `copyField` may be a PwPush one-time link if the CIPP instance has PwPush enabled. HTTP failure ⇒ `CippClientException` (already thrown by `send()`).
- **No new upstream identifiers accepted from the caller.** `person_id` + `confirm_upn` + `reason` only (plus optional `must_change`, optional `ticket_id`). Server derives tenant + UPN.
- **Ungranted-by-default.** Tool is only callable by an MCP token whose `tools` array explicitly lists `cipp_reset_user_password`. No code change grants it.
- `vendor/bin/pint` must be clean; the full `tests/Feature/Mcp` + `tests/Unit/Cipp` suites must stay green.

---

### Task 1: `CippRestWriteClient` — opt-in `captureBody` + `resetUserPassword()`

**Files:**
- Modify: `app/Services/Cipp/CippRestWriteClient.php` (add `$captureBody` param to `send()` at :191-209; add `resetUserPassword()` public method after :151)
- Test: `tests/Unit/Cipp/CippRestWriteClientTest.php` (add two tests; extend the curated-methods assertion)

**Interfaces:**
- Consumes: nothing new.
- Produces:
  - `public function resetUserPassword(string $tenantFilter, string $userPrincipalName, bool $mustChange): array` — POSTs `api/ExecResetPass` and returns `['success' => true, 'status' => int, 'body' => array]` where `body` is the decoded CIPP JSON (`['Results' => ['copyField' => ..., 'state' => ..., ...]]`).
  - `private function send(string $endpoint, array $body, bool $captureBody = false): array` — unchanged for existing callers (default `false` → returns `['success' => true, 'status' => int]`); `true` → also includes `'body' => $response->json()`.

- [ ] **Step 1: Write the failing unit tests**

Add to `tests/Unit/Cipp/CippRestWriteClientTest.php`:

```php
public function test_reset_password_posts_exec_reset_pass_shape_and_captures_the_password_body(): void
{
    Http::fake([
        'login.microsoftonline.com/*' => Http::response([
            'access_token' => 'WRITE-TOKEN',
            'expires_in' => 3600,
        ]),
        'cipp.example.test/api/ExecResetPass' => Http::response([
            'Results' => [
                'resultText' => 'Successfully reset the password for Alex, alex@acme.example. The new password is Temp-P@ss-9x!',
                'copyField' => 'Temp-P@ss-9x!',
                'state' => 'success',
            ],
        ]),
    ]);

    $client = new CippRestWriteClient([
        'api_url' => 'https://cipp.example.test',
        'tenant_id' => 'tenant-1',
        'client_id' => 'write-client',
        'client_secret' => 'write-secret',
        'application_id' => 'write-app',
    ], Cache::store(), fn (string $host): array => ['93.184.216.34']);

    $result = $client->resetUserPassword('acme.onmicrosoft.com', 'alex@acme.example', true);

    // captureBody path returns the decoded body so the temp password is available to the executor.
    $this->assertTrue($result['success']);
    $this->assertSame(200, $result['status']);
    $this->assertSame('Temp-P@ss-9x!', $result['body']['Results']['copyField']);
    $this->assertSame('success', $result['body']['Results']['state']);

    Http::assertSent(fn ($request) => $request->url() === 'https://cipp.example.test/api/ExecResetPass'
        && $request->method() === 'POST'
        && $request->hasHeader('Authorization', 'Bearer WRITE-TOKEN')
        && $request->data() === [
            'tenantFilter' => 'acme.onmicrosoft.com',
            'ID' => 'alex@acme.example',
            'MustChange' => true,
        ]);
}

public function test_reset_password_forwards_must_change_false_when_requested(): void
{
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'WRITE-TOKEN', 'expires_in' => 3600]),
        'cipp.example.test/api/ExecResetPass' => Http::response(['Results' => ['copyField' => 'pw', 'state' => 'success']]),
    ]);

    $client = new CippRestWriteClient([
        'api_url' => 'https://cipp.example.test',
        'tenant_id' => 'tenant-1',
        'client_id' => 'write-client',
        'client_secret' => 'write-secret',
    ], Cache::store(), fn (string $host): array => ['93.184.216.34']);

    $client->resetUserPassword('acme.onmicrosoft.com', 'alex@acme.example', false);

    Http::assertSent(fn ($request) => $request->url() === 'https://cipp.example.test/api/ExecResetPass'
        && $request->data() === [
            'tenantFilter' => 'acme.onmicrosoft.com',
            'ID' => 'alex@acme.example',
            'MustChange' => false,
        ]);
}
```

Also extend the existing `test_exposes_curated_methods_only_no_arbitrary_endpoint_post` assertion block:

```php
        $this->assertContains('resetUserPassword', $methods);
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test tests/Unit/Cipp/CippRestWriteClientTest.php`
Expected: FAIL — `Error: Call to undefined method ...::resetUserPassword()` (and the curated-methods assertion fails).

- [ ] **Step 3: Add the `captureBody` path to `send()`**

In `app/Services/Cipp/CippRestWriteClient.php`, replace the `send()` method (currently :187-209) with:

```php
    /**
     * @param  array<int|string, mixed>  $body
     * @return array<int|string, mixed>
     */
    private function send(string $endpoint, array $body, bool $captureBody = false): array
    {
        $url = $this->endpointUrl($endpoint);
        $options = $this->safeRequestOptions($url);
        $token = $this->getToken();

        $response = Http::timeout(60)
            ->acceptJson()
            ->asJson()
            ->withOptions($options)
            ->withToken($token)
            ->post($url, $body);

        if ($response->failed()) {
            throw new CippClientException("CIPP write {$endpoint} failed: HTTP {$response->status()}");
        }

        if ($captureBody) {
            // Opt-in: only the password-reset wrapper reads the upstream body (the temp
            // password comes back in Results.copyField). All other callers discard it.
            return ['success' => true, 'status' => $response->status(), 'body' => $response->json()];
        }

        return ['success' => true, 'status' => $response->status()];
    }
```

- [ ] **Step 4: Add the `resetUserPassword()` wrapper**

In `app/Services/Cipp/CippRestWriteClient.php`, immediately after `setMailboxGalVisibility()` (ends at :151), insert:

```php
    /**
     * Reset one user's M365 password. Returns the captured response body so the
     * caller can read the server-generated temp password from Results.copyField.
     *
     * @return array<int|string, mixed>
     */
    public function resetUserPassword(string $tenantFilter, string $userPrincipalName, bool $mustChange): array
    {
        return $this->send('api/ExecResetPass', [
            'tenantFilter' => $tenantFilter,
            'ID' => $userPrincipalName,
            'MustChange' => $mustChange,
        ], captureBody: true);
    }
```

- [ ] **Step 5: Run the full client unit suite to verify pass + no regression**

Run: `php artisan test tests/Unit/Cipp/CippRestWriteClientTest.php`
Expected: PASS — new tests green AND the existing body-discard tests (`assertSame(['success' => true, 'status' => 200], $result)`) still pass because `captureBody` defaults to `false`.

- [ ] **Step 6: Pint + commit**

```bash
vendor/bin/pint app/Services/Cipp/CippRestWriteClient.php tests/Unit/Cipp/CippRestWriteClientTest.php
git add app/Services/Cipp/CippRestWriteClient.php tests/Unit/Cipp/CippRestWriteClientTest.php
git commit -m "psa-h186 (1/3): CippRestWriteClient opt-in captureBody + resetUserPassword"
```

---

### Task 2: `StaffCippWriteToolExecutor` — the `cipp_reset_user_password` tool + redaction lock

**Files:**
- Modify: `app/Services/Mcp/StaffCippWriteToolExecutor.php` (add to `COOLDOWNS` :45-68; add to `definitions()` :143-169; add routing branch in `execute()` :193-204; add `executeResetPassword()` after `executeDirect()` :337; add `resetUserPasswordTool()` + `resetUserPasswordProperties()` builders near the other builders)
- Test: `tests/Feature/Mcp/CippWritePasswordResetPr3Test.php` (new)

**Interfaces:**
- Consumes (from Task 1): `CippRestWriteClient::resetUserPassword(string, string, bool): array` returning `['success' => true, 'status' => int, 'body' => ['Results' => ['copyField' => string, 'state' => string]]]`.
- Consumes (existing, unchanged): `context(tool, arguments, clientId, actorLabel, requireTicket)`, `booleanValue(mixed, field)`, `contentHash(...)`, `cooldownActive(...)`, `auditAttempt(...)`, `safeFailureSummary(tool, e)`, `personProperties()`, `tool(name, description, properties, required)`.
- Produces: a working direct MCP tool named `cipp_reset_user_password`. Result on success:
  `['success' => true, 'tool' => 'cipp_reset_user_password', 'person_id' => int, 'user_principal_name' => string, 'temporary_password' => string, 'must_change_at_next_logon' => bool, 'ad_synced_warning' => bool, 'message' => string, 'guidance' => string]`.

- [ ] **Step 1: Write the failing feature test file**

Create `tests/Feature/Mcp/CippWritePasswordResetPr3Test.php`. It reuses the PR2 harness shape (`configureCipp`, `configureAiActor`, `token`, `callTool`, `listTools`, `decodedResult`, a fixture). Full file:

```php
<?php

namespace Tests\Feature\Mcp;

use App\Enums\PersonType;
use App\Models\Client;
use App\Models\McpAuditLog;
use App\Models\Person;
use App\Models\Setting;
use App\Models\TechnicianActionLog;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Cipp\CippRestWriteClient;
use App\Support\McpConfig;
use App\Support\McpToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Mockery;
use Tests\TestCase;

class CippWritePasswordResetPr3Test extends TestCase
{
    use RefreshDatabase;

    private const TOOL = 'cipp_reset_user_password';

    private function configureCipp(): void
    {
        Setting::setValue('cipp_enabled', '1');
        Setting::setValue('cipp_api_url', 'https://cipp.example.test');
        Setting::setValue('cipp_tenant_id', 'tenant-1');
        Setting::setValue('cipp_client_id', 'client-1');
        Setting::setEncrypted('cipp_client_secret', 'secret');
    }

    private function configureAiActor(): User
    {
        $actor = User::factory()->create(['name' => 'AI Actor']);
        Setting::setValue('triage_system_user_id', (string) $actor->id);

        return $actor;
    }

    private function token(array $tools): string
    {
        return McpConfig::rotateStaffToken(allowedTools: $tools, label: 'opsbot');
    }

    private function legacyToken(): string
    {
        return McpConfig::rotateStaffToken();
    }

    private function callTool(string $token, string $name, array $arguments = []): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/mcp/staff', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => ['name' => $name, 'arguments' => $arguments],
            ]);
    }

    /** @return array<int, array<string, mixed>> */
    private function listTools(string $token): array
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/mcp/staff', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/list',
                'params' => [],
            ])
            ->json('result.tools') ?? [];
    }

    /** @return array<string, mixed> */
    private function decodedResult(TestResponse $response): array
    {
        return json_decode((string) $response->json('result.content.0.text'), true) ?? [];
    }

    /** @return array{client: Client, person: Person, ticket: Ticket} */
    private function cippFixture(): array
    {
        $client = Client::factory()->create([
            'name' => 'Acme',
            'cipp_tenant_domain' => 'acme.onmicrosoft.com',
        ]);

        $person = Person::create([
            'client_id' => $client->id,
            'person_type' => PersonType::User,
            'first_name' => 'Alex',
            'last_name' => 'Acme',
            'email' => 'alex@acme.example',
            'cipp_user_id' => 'user-123',
            'cipp_upn' => 'alex@acme.example',
            'is_active' => true,
        ]);

        $ticket = Ticket::factory()->for($client)->create([
            'contact_id' => $person->id,
            'subject' => 'Password reset',
        ]);

        return compact('client', 'person', 'ticket');
    }

    private function mockReset(string $copyField, string $state = 'success'): Mockery\MockInterface
    {
        $client = Mockery::mock(CippRestWriteClient::class);
        $client->shouldReceive('resetUserPassword')
            ->once()
            ->andReturn(['success' => true, 'status' => 200, 'body' => [
                'Results' => [
                    'resultText' => "The new password is {$copyField}",
                    'copyField' => $copyField,
                    'state' => $state,
                ],
            ]]);
        $this->app->instance(CippRestWriteClient::class, $client);

        return $client;
    }

    public function test_tool_is_sensitive_grant_only_and_schema_is_safe(): void
    {
        $this->configureCipp();

        $groups = McpToolRegistry::groups();
        $writeNames = array_column($groups['cipp_write']['tools'], 'name');
        $this->assertTrue($groups['cipp_write']['sensitive']);
        $this->assertContains(self::TOOL, $writeNames, 'reset tool must be in the sensitive cipp_write group');
        $this->assertContains(self::TOOL, McpToolRegistry::allToolNames(), 'reset tool must be token-grantable');

        // No staged twin exists.
        $this->assertNotContains('cipp_stage_reset_user_password', $writeNames);

        // Ungranted-by-default: a legacy full-surface token never gains it.
        $legacyNames = array_column($this->listTools($this->legacyToken()), 'name');
        $this->assertNotContains(self::TOOL, $legacyNames);

        $scoped = collect($this->listTools($this->token([self::TOOL])))->keyBy('name');
        $reset = $scoped[self::TOOL];
        $this->assertContains('client_id', $reset['inputSchema']['required']);
        $this->assertContains('person_id', $reset['inputSchema']['required']);
        $this->assertContains('confirm_upn', $reset['inputSchema']['required']);
        $this->assertContains('reason', $reset['inputSchema']['required']);
        $this->assertArrayHasKey('must_change', $reset['inputSchema']['properties']);
        $this->assertSame('boolean', $reset['inputSchema']['properties']['must_change']['type']);
        // Never exposes upstream identifiers.
        $this->assertArrayNotHasKey('tenantFilter', $reset['inputSchema']['properties']);
        $this->assertArrayNotHasKey('ID', $reset['inputSchema']['properties']);
    }

    public function test_ungranted_token_is_denied(): void
    {
        $this->configureCipp();
        $this->configureAiActor();
        $fixture = $this->cippFixture();

        $blocked = Mockery::mock(CippRestWriteClient::class);
        $blocked->shouldNotReceive('resetUserPassword');
        $this->app->instance(CippRestWriteClient::class, $blocked);

        // Token granted a DIFFERENT cipp_write tool, not the reset tool.
        $response = $this->callTool($this->token(['cipp_disable_user_sign_in']), self::TOOL, [
            'client_id' => $fixture['client']->id,
            'person_id' => $fixture['person']->id,
            'confirm_upn' => 'alex@acme.example',
            'reason' => 'Attempt without grant.',
        ]);

        $this->assertTrue((bool) $response->json('result.isError'));
    }

    public function test_returns_temp_password_to_caller_and_never_persists_it(): void
    {
        $this->configureCipp();
        $this->configureAiActor();
        $fixture = $this->cippFixture();
        $secret = 'S3cret-Temp-9x!';

        $client = $this->mockReset($secret);
        $client->shouldHaveReceived('resetUserPassword')
            ->with('acme.onmicrosoft.com', 'alex@acme.example', true);

        // Capture every Laravel log line emitted during the call.
        $logged = [];
        Log::listen(function (MessageLogged $m) use (&$logged) {
            $logged[] = $m->message.' '.json_encode($m->context);
        });

        $response = $this->callTool($this->token([self::TOOL]), self::TOOL, [
            'client_id' => $fixture['client']->id,
            'person_id' => $fixture['person']->id,
            'ticket_id' => $fixture['ticket']->id,
            'confirm_upn' => 'alex@acme.example',
            'reason' => 'User forgot password; verified identity over the phone.',
        ]);

        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));

        // The temp password IS returned to the caller (that is the whole point).
        $result = $this->decodedResult($response);
        $this->assertSame($secret, $result['temporary_password']);
        $this->assertTrue($result['must_change_at_next_logon']);
        $this->assertFalse($result['ad_synced_warning']);
        $this->assertStringContainsString('Relay it', $result['message']);

        // An 'executed' audit row exists...
        $this->assertSame(1, TechnicianActionLog::where('action_type', self::TOOL)->where('result_status', 'executed')->count());

        // ...but the credential is in NO persistent sink and NO log line.
        $this->assertStringNotContainsString($secret, json_encode(TechnicianActionLog::all()->toArray()));
        $this->assertStringNotContainsString($secret, json_encode(McpAuditLog::all()->toArray()));
        foreach ($logged as $line) {
            $this->assertStringNotContainsString($secret, $line, 'temp password leaked into a log line');
        }
    }

    public function test_honors_must_change_false(): void
    {
        $this->configureCipp();
        $this->configureAiActor();
        $fixture = $this->cippFixture();

        $client = $this->mockReset('pw-x');
        $client->shouldHaveReceived('resetUserPassword')
            ->with('acme.onmicrosoft.com', 'alex@acme.example', false);

        $response = $this->callTool($this->token([self::TOOL]), self::TOOL, [
            'client_id' => $fixture['client']->id,
            'person_id' => $fixture['person']->id,
            'confirm_upn' => 'alex@acme.example',
            'must_change' => false,
            'reason' => 'Permanent reset for a shared service account.',
        ]);

        $response->assertOk();
        $this->assertFalse($this->decodedResult($response)['must_change_at_next_logon']);
    }

    public function test_surfaces_ad_sync_warning(): void
    {
        $this->configureCipp();
        $this->configureAiActor();
        $fixture = $this->cippFixture();

        $this->mockReset('pw-y', state: 'warning');

        $response = $this->callTool($this->token([self::TOOL]), self::TOOL, [
            'client_id' => $fixture['client']->id,
            'person_id' => $fixture['person']->id,
            'confirm_upn' => 'alex@acme.example',
            'reason' => 'Reset for hybrid-identity user.',
        ]);

        $response->assertOk();
        $result = $this->decodedResult($response);
        $this->assertTrue($result['ad_synced_warning']);
        $this->assertStringContainsString('AD-synced', $result['message']);
    }

    public function test_rejects_caller_supplied_upstream_identifiers(): void
    {
        $this->configureCipp();
        $this->configureAiActor();
        $fixture = $this->cippFixture();

        $blocked = Mockery::mock(CippRestWriteClient::class);
        $blocked->shouldNotReceive('resetUserPassword');
        $this->app->instance(CippRestWriteClient::class, $blocked);

        $response = $this->callTool($this->token([self::TOOL]), self::TOOL, [
            'client_id' => $fixture['client']->id,
            'person_id' => $fixture['person']->id,
            'tenantFilter' => 'attacker.onmicrosoft.com',
            'ID' => 'attacker@evil.example',
            'confirm_upn' => 'alex@acme.example',
            'reason' => 'Reject upstream identity injection.',
        ]);

        $this->assertTrue((bool) $response->json('result.isError'));
        $this->assertStringContainsString('upstream CIPP identifiers are not accepted', (string) $response->json('result.content.0.text'));
    }

    public function test_requires_confirm_upn_match(): void
    {
        $this->configureCipp();
        $this->configureAiActor();
        $fixture = $this->cippFixture();

        $blocked = Mockery::mock(CippRestWriteClient::class);
        $blocked->shouldNotReceive('resetUserPassword');
        $this->app->instance(CippRestWriteClient::class, $blocked);

        $response = $this->callTool($this->token([self::TOOL]), self::TOOL, [
            'client_id' => $fixture['client']->id,
            'person_id' => $fixture['person']->id,
            'confirm_upn' => 'wrong@acme.example',
            'reason' => 'Confirm mismatch must cancel.',
        ]);

        $this->assertTrue((bool) $response->json('result.isError'));
        $this->assertStringContainsString('confirm_upn', (string) $response->json('result.content.0.text'));
    }

    public function test_kill_switch_blocks_reset(): void
    {
        $this->configureCipp();
        $this->configureAiActor();
        Setting::setValue('technician_kill_switch', '1');
        $fixture = $this->cippFixture();

        $blocked = Mockery::mock(CippRestWriteClient::class);
        $blocked->shouldNotReceive('resetUserPassword');
        $this->app->instance(CippRestWriteClient::class, $blocked);

        $response = $this->callTool($this->token([self::TOOL]), self::TOOL, [
            'client_id' => $fixture['client']->id,
            'person_id' => $fixture['person']->id,
            'confirm_upn' => 'alex@acme.example',
            'reason' => 'Kill switch must refuse.',
        ]);

        $this->assertTrue((bool) $response->json('result.isError'));
        $this->assertStringContainsString('kill-switch', (string) $response->json('result.content.0.text'));
    }
}
```

> NOTE (confirmed): `TechnicianConfig::killSwitchEngaged()` reads `Setting::getValue('technician_kill_switch')`; `Setting::setValue('technician_kill_switch', '1')` engages it — identical to `tests/Feature/Mcp/CippWriteUserLifecyclePr1Test.php::test_kill_switch_blocks_revoke_before_upstream_call`.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test tests/Feature/Mcp/CippWritePasswordResetPr3Test.php`
Expected: FAIL — the tool is unregistered, so `tools/list` omits it and calls return an error / the schema assertions fail.

- [ ] **Step 3: Register the tool name in `COOLDOWNS` and `definitions()`**

In `app/Services/Mcp/StaffCippWriteToolExecutor.php`:

Add to the `COOLDOWNS` array (after the last entry, before the closing `];` at :68):

```php
        'cipp_reset_user_password' => 300,
```

Add to the `definitions()` return array (after `self::stageSetMailboxOutOfOfficeTool(),` at :167):

```php
            self::resetUserPasswordTool(),
```

- [ ] **Step 4: Add the schema builders**

In `app/Services/Mcp/StaffCippWriteToolExecutor.php`, add near the other `*Properties()` / `*Tool()` builders (e.g. after `galVisibilityProperties()` :1199 and after `setMailboxGalVisibilityTool()` respectively — placement is cosmetic, keep them adjacent):

```php
    /** @return array<string, mixed> */
    private static function resetUserPasswordProperties(): array
    {
        return [
            'must_change' => [
                'type' => 'boolean',
                'description' => 'Whether the user must change the password at next sign-in. Defaults to true (the temporary-password method). Set false only for a deliberate permanent reset.',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function resetUserPasswordTool(): array
    {
        return self::tool(
            'cipp_reset_user_password',
            'Reset the Microsoft 365 password for one server-derived CIPP user and return a newly generated temporary password. The password is generated by CIPP/Microsoft and returned only in this tool result — it is never written to any log or audit record. Defaults to must-change-at-next-sign-in. Relay the password to the user over a secure channel and have them change it at first sign-in. Requires an explicit token grant, reason, confirm_upn friction, kill-switch, cooldown, and TechnicianActionLog audit. Consequential: performs a live credential reset immediately.',
            array_merge(self::personProperties(), self::resetUserPasswordProperties()),
            ['person_id', 'confirm_upn', 'reason'],
        );
    }
```

- [ ] **Step 5: Route the tool in `execute()` and add `executeResetPassword()`**

In `execute()` (:193-204), add the routing branch BEFORE the staged/direct dispatch:

```php
    /** @return array<string, mixed> */
    public function execute(string $name, array $arguments, int $clientId, string $actorLabel): array
    {
        if (! CippConfig::isEnabled() || ! CippConfig::isConfigured()) {
            return ['error' => 'CIPP is not enabled or configured'];
        }

        if ($name === 'cipp_reset_user_password') {
            return $this->executeResetPassword($name, $arguments, $clientId, $actorLabel);
        }

        if (isset(self::STAGED_TO_DIRECT[$name])) {
            return $this->stageAction($name, $arguments, $clientId, $actorLabel);
        }

        return $this->executeDirect($name, $arguments, $clientId, $actorLabel);
    }
```

Add `executeResetPassword()` immediately after `executeDirect()` (after :337):

```php
    /**
     * Dedicated direct path for the password reset — the only cipp_write tool that reads
     * back an upstream value (the temp password). Reuses every context() gate; skips the
     * idempotent alreadyExecuted() short-circuit (a password reset is NON-idempotent — a
     * second reset must generate a new password, not return a stale "already done"). A
     * cooldown still guards runaway repeats. The credential lives ONLY in the returned
     * result; auditAttempt() records the action + target UPN, never the password.
     *
     * @return array<string, mixed>
     */
    private function executeResetPassword(string $tool, array $arguments, int $clientId, string $actorLabel): array
    {
        $context = $this->context($tool, $arguments, $clientId, $actorLabel, requireTicket: false);
        if (isset($context['error'])) {
            return ['error' => $context['error']];
        }

        /** @var Client $client */
        $client = $context['client'];
        /** @var string $tenant */
        $tenant = $context['tenant'];
        /** @var ResolvedCippPerson $person */
        $person = $context['person'];
        /** @var Ticket|null $ticket */
        $ticket = $context['ticket'];
        $reason = (string) $context['reason'];

        try {
            $mustChange = array_key_exists('must_change', $arguments)
                ? $this->booleanValue($arguments['must_change'], 'must_change')
                : true;
        } catch (CippWriteScopeException $e) {
            $this->auditAttempt($tool, 'rejected', $client->id, $ticket, $person, null, $this->contentHash($tool, $client->id, $person->person->id, $ticket?->id, []), $e->getMessage(), $actorLabel);

            return ['error' => $e->getMessage()];
        }

        $contentHash = $this->contentHash($tool, $client->id, $person->person->id, $ticket?->id, ['must_change' => $mustChange]);

        if ($this->cooldownActive($tool, $client->id, $person, null, self::COOLDOWNS[$tool] ?? 300)) {
            $this->auditAttempt($tool, 'blocked', $client->id, $ticket, $person, null, $contentHash, "{$tool} cooldown active; upstream call refused.", $actorLabel);

            return ['error' => "{$tool} cooldown active for this target; no reset was performed. Wait before retrying a password reset."];
        }

        try {
            $upstream = $this->client->resetUserPassword($tenant, $person->userPrincipalName, $mustChange);
        } catch (CippClientException $e) {
            $this->auditAttempt($tool, 'error', $client->id, $ticket, $person, null, $contentHash, $this->safeFailureSummary($tool, $e), $actorLabel);

            return ['error' => "CIPP password reset failed for {$tool}; no password was returned."];
        }

        // Audit records the action + target only. The summary carries NO credential.
        $this->auditAttempt($tool, 'executed', $client->id, $ticket, $person, null, $contentHash, "{$tool} executed: {$reason}", $actorLabel);

        $results = is_array($upstream['body']['Results'] ?? null) ? $upstream['body']['Results'] : [];
        $password = (isset($results['copyField']) && is_string($results['copyField']) && $results['copyField'] !== '')
            ? $results['copyField']
            : null;
        $state = isset($results['state']) && is_string($results['state']) ? $results['state'] : null;

        if ($password === null) {
            return [
                'success' => true,
                'tool' => $tool,
                'person_id' => $person->person->id,
                'password_returned' => false,
                'message' => 'CIPP reported a successful reset but returned no password value. Verify in CIPP; if PwPush is configured the value may be delivered as a link instead.',
            ];
        }

        $adSynced = $state === 'warning';

        return [
            'success' => true,
            'tool' => $tool,
            'person_id' => $person->person->id,
            'user_principal_name' => $person->userPrincipalName,
            'temporary_password' => $password,
            'must_change_at_next_logon' => $mustChange,
            'ad_synced_warning' => $adSynced,
            'message' => 'Temporary password generated. Relay it to the user over a secure channel and instruct them to change it at first sign-in.'
                .($adSynced ? ' WARNING: this account appears to be directory-synced (AD-synced); a cloud password reset may not take effect if on-prem Active Directory is authoritative — verify with the on-prem/hybrid identity source.' : ''),
            'guidance' => 'If your CIPP instance has PwPush enabled, the temporary_password value may be a one-time secure link rather than the literal password.',
        ];
    }
```

- [ ] **Step 6: Run the feature suite to verify it passes**

Run: `php artisan test tests/Feature/Mcp/CippWritePasswordResetPr3Test.php`
Expected: PASS — all tests green, including the redaction lock (no password in any TechnicianActionLog/McpAuditLog row or log line).

- [ ] **Step 7: Run the full CIPP-write feature + unit regression**

Run: `php artisan test tests/Feature/Mcp/CippWriteMailboxPr2Test.php tests/Feature/Mcp/CippWriteUserLifecyclePr1Test.php tests/Unit/Cipp/CippRestWriteClientTest.php`
Expected: PASS — no regressions to the existing 22 tools.

- [ ] **Step 8: Pint + commit**

```bash
vendor/bin/pint app/Services/Mcp/StaffCippWriteToolExecutor.php tests/Feature/Mcp/CippWritePasswordResetPr3Test.php
git add app/Services/Mcp/StaffCippWriteToolExecutor.php tests/Feature/Mcp/CippWritePasswordResetPr3Test.php
git commit -m "psa-h186 (2/3): cipp_reset_user_password direct MCP tool + redaction test-lock"
```

---

### Task 3: `McpStaffController` — audit allowlist for `must_change` + `Cache-Control: no-store`

**Files:**
- Modify: `app/Http/Controllers/Api/McpStaffController.php` (add `'must_change'` to the `auditCippWriteArguments()` allowlist :1618-1634; add `cipp_reset_user_password` to the `no-store` tool list :819)
- Test: `tests/Feature/Mcp/CippWritePasswordResetPr3Test.php` (add two assertions/tests)

**Interfaces:**
- Consumes: the working tool from Task 2.
- Produces: `must_change` recorded (as a safe boolean) in `McpAuditLog.arguments`; the tool-call HTTP response carries `Cache-Control: no-store` so the secret-bearing body is not cached by any intermediary.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/Mcp/CippWritePasswordResetPr3Test.php`:

```php
public function test_success_response_is_marked_no_store(): void
{
    $this->configureCipp();
    $this->configureAiActor();
    $fixture = $this->cippFixture();
    $this->mockReset('pw-nostore');

    $response = $this->callTool($this->token([self::TOOL]), self::TOOL, [
        'client_id' => $fixture['client']->id,
        'person_id' => $fixture['person']->id,
        'confirm_upn' => 'alex@acme.example',
        'reason' => 'Reset; response must not be cached.',
    ]);

    $response->assertOk();
    $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
}

public function test_must_change_is_recorded_as_safe_boolean_in_audit(): void
{
    $this->configureCipp();
    $this->configureAiActor();
    $fixture = $this->cippFixture();
    $this->mockReset('pw-audit');

    $this->callTool($this->token([self::TOOL]), self::TOOL, [
        'client_id' => $fixture['client']->id,
        'person_id' => $fixture['person']->id,
        'confirm_upn' => 'alex@acme.example',
        'must_change' => true,
        'reason' => 'Audit must record must_change.',
    ]);

    $args = McpAuditLog::where('tool_name', self::TOOL)->latest('id')->firstOrFail()->arguments;
    $this->assertTrue($args['must_change']);
    $this->assertSame('[withheld]', $args['confirm_upn']);
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test tests/Feature/Mcp/CippWritePasswordResetPr3Test.php --filter 'no_store|safe_boolean'`
Expected: FAIL — `Cache-Control` lacks `no-store`; `must_change` is absent from audited arguments (dropped by the allowlist).

- [ ] **Step 3: Add `must_change` to the audit allowlist**

In `app/Http/Controllers/Api/McpStaffController.php`, `auditCippWriteArguments()` (:1618-1632), add `'must_change'` to the allowlisted keys array:

```php
            if (in_array($normalized, [
                'client_id',
                'person_id',
                'license_type_id',
                'ticket_id',
                'state',
                'mailbox_type',
                'mode',
                'target_person_id',
                'keep_copy',
                'hidden',
                'must_change',
                'start_time',
                'end_time',
                'timezone',
            ], true)) {
                $safe[$normalized] = $value;
            }
```

- [ ] **Step 4: Add the tool to the `no-store` response list**

In `app/Http/Controllers/Api/McpStaffController.php` (:819), add `cipp_reset_user_password` to the tool-name list that sets `Cache-Control: no-store`:

```php
            if (in_array((string) $name, ['tactical_open_remote_control', 'tactical_get_or_create_installer', 'tactical_generate_installer', 'cipp_reset_user_password'], true) && ! $isError) {
                $response->headers->set('Cache-Control', 'no-store');
            }
```

- [ ] **Step 5: Run the feature suite to verify pass**

Run: `php artisan test tests/Feature/Mcp/CippWritePasswordResetPr3Test.php`
Expected: PASS — all tests including the two new ones.

- [ ] **Step 6: Pint + commit**

```bash
vendor/bin/pint app/Http/Controllers/Api/McpStaffController.php tests/Feature/Mcp/CippWritePasswordResetPr3Test.php
git add app/Http/Controllers/Api/McpStaffController.php tests/Feature/Mcp/CippWritePasswordResetPr3Test.php
git commit -m "psa-h186 (3/3): audit must_change safely + no-store on password-reset response"
```

---

## Final Verification (after all tasks)

- [ ] `vendor/bin/pint --test` clean across all changed files.
- [ ] `php artisan test tests/Feature/Mcp tests/Unit/Cipp` fully green.
- [ ] Grep the diff for accidental credential handling: `git diff main -- app | grep -iE 'password|copyField'` — confirm the password only flows into the returned result, never into a `Log::`, `auditAttempt` summary, `McpAuditLog`, or `TechnicianRun` payload.
- [ ] Run `/soundpsa-review-pr` on the branch; address findings.
- [ ] MERGE_READY nudge to the Mayor, explicitly flagging the two design decisions for veto: **(a) direct-only (no staged twin)**, **(b) cooldown-yes / idempotent-dedup-no (non-idempotent action)**. Note the first live use must be on a test/Charlie account (constraint #7) — Mayor's call, post-merge.
```
