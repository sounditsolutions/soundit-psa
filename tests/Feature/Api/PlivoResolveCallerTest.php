<?php

namespace Tests\Feature\Api;

use App\Enums\ContractStatus;
use App\Enums\ContractType;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Person;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pre-ring caller lookup (psa-asg / GitHub #58). The resolve-caller endpoint is
 * hit synchronously by a Plivo PHLO HTTP-request node during the IVR — before
 * the call is answered — so the flow can route on caller identity AND the
 * client's contract-type coverage tier.
 *
 * No plivo_webhook_secret is configured under RefreshDatabase, so
 * VerifyPlivoWebhookSecret runs in dev-mode (allow all) and the {secret}
 * segment is a placeholder.
 */
class PlivoResolveCallerTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/plivo/test-secret/resolve-caller';

    // ── helpers ──────────────────────────────────────────────────────────────

    private function makeContact(Client $client, string $phone): Person
    {
        return Person::create([
            'client_id' => $client->id,
            'first_name' => 'Dana',
            'last_name' => 'Reyes',
            'phone' => $phone,
            'is_active' => true,
        ]);
    }

    private function makeContract(Client $client, ContractType $type, ContractStatus $status = ContractStatus::Active): Contract
    {
        return Contract::create([
            'client_id' => $client->id,
            'name' => $type->label().' Agreement',
            'type' => $type->value,
            'status' => $status->value,
            'start_date' => now()->subYear(),
        ]);
    }

    // ── managed client → priority routing signal ─────────────────────────────

    public function test_managed_client_reports_managed_contract_type(): void
    {
        $client = Client::factory()->create(['name' => 'Managed Co']);
        $this->makeContact($client, '+15551230001');
        $this->makeContract($client, ContractType::Managed);

        $response = $this->postJson(self::ENDPOINT, [
            'From' => '+15551230001',
            'CallUUID' => 'uuid-managed',
        ]);

        $response->assertOk()->assertJson([
            'known' => true,
            'client' => true,
            'client_id' => $client->id,
            'has_active_contract' => true,
            'contract_type' => 'managed',
            'contract_type_label' => 'Managed Services',
            'managed' => true,
        ]);
    }

    // ── break-fix client → non-managed branch ────────────────────────────────

    public function test_breakfix_client_reports_breakfix_and_not_managed(): void
    {
        $client = Client::factory()->create(['name' => 'BreakFix Co']);
        $this->makeContact($client, '+15551230002');
        $this->makeContract($client, ContractType::BreakFix);

        $response = $this->postJson(self::ENDPOINT, [
            'From' => '+15551230002',
            'CallUUID' => 'uuid-breakfix',
        ]);

        $response->assertOk()->assertJson([
            'client' => true,
            'has_active_contract' => true,
            'contract_type' => 'breakfix',
            'contract_type_label' => 'Break-Fix',
            'managed' => false,
        ]);
    }

    // ── multiple active contracts → highest tier wins ────────────────────────

    public function test_client_with_managed_and_breakfix_resolves_to_managed(): void
    {
        $client = Client::factory()->create(['name' => 'Mixed Co']);
        $this->makeContact($client, '+15551230003');
        $this->makeContract($client, ContractType::BreakFix);
        $this->makeContract($client, ContractType::Managed);
        $this->makeContract($client, ContractType::Custom);

        $response = $this->postJson(self::ENDPOINT, [
            'From' => '+15551230003',
            'CallUUID' => 'uuid-mixed',
        ]);

        $response->assertOk()->assertJson([
            'contract_type' => 'managed',
            'managed' => true,
        ]);
    }

    // ── only inactive contracts → known client, no active coverage ───────────

    public function test_client_with_only_inactive_contracts_reports_no_active_contract(): void
    {
        $client = Client::factory()->create(['name' => 'Lapsed Co']);
        $this->makeContact($client, '+15551230004');
        $this->makeContract($client, ContractType::Managed, ContractStatus::Expired);
        $this->makeContract($client, ContractType::BreakFix, ContractStatus::Cancelled);

        $response = $this->postJson(self::ENDPOINT, [
            'From' => '+15551230004',
            'CallUUID' => 'uuid-lapsed',
        ]);

        $response->assertOk()->assertJson([
            'client' => true,
            'has_active_contract' => false,
            'contract_type' => null,
            'contract_type_label' => null,
            'managed' => false,
        ]);
    }

    // ── known client, no contracts at all ────────────────────────────────────

    public function test_client_with_no_contracts_reports_no_active_contract(): void
    {
        $client = Client::factory()->create(['name' => 'Contractless Co']);
        $this->makeContact($client, '+15551230005');

        $response = $this->postJson(self::ENDPOINT, [
            'From' => '+15551230005',
            'CallUUID' => 'uuid-none',
        ]);

        $response->assertOk()->assertJson([
            'client' => true,
            'has_active_contract' => false,
            'contract_type' => null,
            'managed' => false,
        ]);
    }

    // ── unknown caller → stable everything-false shape ───────────────────────

    public function test_unknown_caller_returns_stable_contract_fields(): void
    {
        $response = $this->postJson(self::ENDPOINT, [
            'From' => '+15559990000',
            'CallUUID' => 'uuid-unknown',
        ]);

        $response->assertOk()->assertJson([
            'known' => false,
            'client' => false,
            'has_active_contract' => false,
            'contract_type' => null,
            'contract_type_label' => null,
            'managed' => false,
        ]);
    }
}
