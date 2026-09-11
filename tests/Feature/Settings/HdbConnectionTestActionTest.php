<?php

namespace Tests\Feature\Settings;

use App\Models\McpAuditLog;
use App\Models\Setting;
use App\Models\User;
use App\Services\Hdb\HdbAuthResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The Test Connection action behind the HDB Report Portal fieldset (psa #340).
 *
 * The action is deliberately not a copy of its siblings on this page, and the
 * two differences are what these tests pin:
 *
 * - testLevel() interpolates `$e->getMessage()` into the operator's message and
 *   the page renders that through `innerHTML`. This action returns only fixed
 *   strings, so a hostile or merely chatty portal cannot write into an admin's
 *   DOM.
 * - It is admin-only, because it is the one control here that spends a stored
 *   credential against a third party. That does NOT close psa #1344 — the rest
 *   of this page is still auth-only — and the coverage below says so rather than
 *   implying the page is gated.
 */
class HdbConnectionTestActionTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://portal.example.test';

    private const LOGIN_URL = 'portal.example.test/login';

    private const BEACON = 'VENDOR-TEXT-<script>alert(1)</script>-BEACON';

    protected function setUp(): void
    {
        parent::setUp();

        Setting::setValue('hdb_base_url', self::BASE);
        Setting::setValue('hdb_email', 'reports@example.test');
        Setting::setEncrypted('hdb_password', 'service-account-password');
    }

    private function loginPage(): string
    {
        return '<html><body><!-- '.self::BEACON.' --><form action="" method="post" id="theOnlyForm">'
            .'<input type="email" name="email"><input type="password" name="password">'
            .'<input type="hidden" name="g" value="g"><input type="submit" name="submit"></form></body></html>';
    }

    private function fakeSuccessfulLogin(): void
    {
        Http::fake([
            self::LOGIN_URL => Http::sequence()
                ->push($this->loginPage())
                ->push('<html><body><h1>Reports</h1><!-- '.self::BEACON.' --></body></html>'),
        ]);
    }

    private function fakeRefusedLogin(): void
    {
        Http::fake([
            self::LOGIN_URL => Http::sequence()
                ->push($this->loginPage())
                ->push($this->loginPage()),
        ]);
    }

    public function test_a_non_admin_cannot_spend_the_stored_credential(): void
    {
        Http::fake();

        $this->actingAs(User::factory()->tech()->create())
            ->postJson(route('settings.integrations.hdb.test'))
            ->assertForbidden();

        Http::assertNothingSent();
        $this->assertSame(0, McpAuditLog::count());
    }

    public function test_a_guest_cannot_reach_the_action(): void
    {
        Http::fake();

        $this->post(route('settings.integrations.hdb.test'))
            ->assertRedirect(route('login'));

        Http::assertNothingSent();
    }

    public function test_the_test_button_is_not_offered_to_a_non_admin(): void
    {
        $this->actingAs(User::factory()->tech()->create())
            ->get(route('settings.integrations'))
            ->assertOk()
            ->assertSee('HDB Report Portal')
            ->assertDontSee("testConnection('hdb')", false);
    }

    public function test_the_test_button_is_offered_to_an_admin(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('settings.integrations'))
            ->assertOk()
            ->assertSee("testConnection('hdb')", false)
            ->assertSee('id="test-result-hdb"', false);
    }

    public function test_a_successful_login_reports_success_and_stamps_connected_at(): void
    {
        $this->fakeSuccessfulLogin();

        $response = $this->actingAs(User::factory()->create())
            ->postJson(route('settings.integrations.hdb.test'))
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertNotNull(Setting::getValue('hdb_connected_at'));
        $this->assertStringNotContainsString(self::BEACON, $response->getContent());
        $this->assertStringNotContainsString('<script', $response->getContent());
    }

    public function test_a_refused_login_reports_failure_and_does_not_stamp_connected_at(): void
    {
        $this->fakeRefusedLogin();

        $response = $this->actingAs(User::factory()->create())
            ->postJson(route('settings.integrations.hdb.test'))
            ->assertOk()
            ->assertJson(['success' => false]);

        $this->assertNull(Setting::getValue('hdb_connected_at'));
        $this->assertStringNotContainsString(self::BEACON, $response->getContent());
    }

    public function test_the_operator_message_is_a_fixed_string_from_the_closed_vocabulary(): void
    {
        $this->fakeRefusedLogin();

        $message = $this->actingAs(User::factory()->create())
            ->postJson(route('settings.integrations.hdb.test'))
            ->assertOk()
            ->json('message');

        $this->assertSame(
            (new HdbAuthResult(
                \App\Services\Hdb\HdbAuthStatus::Rejected,
                HdbAuthResult::REASON_CREDENTIALS_REJECTED,
            ))->message(),
            $message,
        );
    }

    public function test_it_audits_who_tested_and_what_happened(): void
    {
        $this->fakeSuccessfulLogin();

        $admin = User::factory()->create(['email' => 'admin@example.test']);

        $this->actingAs($admin)
            ->postJson(route('settings.integrations.hdb.test'))
            ->assertOk();

        $row = McpAuditLog::where('method', 'hdb/test_connection')->sole();

        $this->assertSame('staff', $row->server_name);
        $this->assertSame('success', $row->status);
        $this->assertSame('web:admin@example.test', $row->actor_label);
        $this->assertNull($row->error_message);
        $this->assertSame(self::BASE, $row->arguments['base_url']);
    }

    public function test_the_audit_row_records_the_reason_symbol_not_vendor_text(): void
    {
        // This row is read back in a UI too, so the same no-vendor-text rule
        // applies to it as to the flash message.
        $this->fakeRefusedLogin();

        $this->actingAs(User::factory()->create())
            ->postJson(route('settings.integrations.hdb.test'))
            ->assertOk();

        $row = McpAuditLog::where('method', 'hdb/test_connection')->sole();

        $this->assertSame('error', $row->status);
        $this->assertSame(HdbAuthResult::REASON_CREDENTIALS_REJECTED, $row->error_message);
        $this->assertStringNotContainsString(self::BEACON, json_encode($row->toArray()));
    }

    public function test_it_never_stores_the_password_or_seed_in_the_audit_row(): void
    {
        Setting::setEncrypted('hdb_totp_secret', 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ');
        $this->fakeSuccessfulLogin();

        $this->actingAs(User::factory()->create())
            ->postJson(route('settings.integrations.hdb.test'))
            ->assertOk();

        $serialized = json_encode(McpAuditLog::where('method', 'hdb/test_connection')->sole()->toArray());

        $this->assertStringNotContainsString('service-account-password', $serialized);
        $this->assertStringNotContainsString('GEZDGNBVGY3TQOJQ', $serialized);
    }

    public function test_unconfigured_credentials_are_reported_without_touching_the_network(): void
    {
        Setting::setValue('hdb_email', '');
        Http::fake();

        $this->actingAs(User::factory()->create())
            ->postJson(route('settings.integrations.hdb.test'))
            ->assertOk()
            ->assertJson(['success' => false]);

        Http::assertNothingSent();
        $this->assertNull(Setting::getValue('hdb_connected_at'));
    }
}
