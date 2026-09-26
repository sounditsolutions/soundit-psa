<?php

namespace Tests\Feature\Mcp;

use App\Enums\TechnicianRunState;
use App\Models\Client;
use App\Models\Setting;
use App\Models\TechnicianActionLog;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Cipp\CippRestWriteClient;
use App\Services\Mcp\StaffCippWriteToolExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * #3929 remainder: the "already released" arms of the CIPP quarantine release.
 *
 * Both the direct path and the approval path read the tenant's live quarantine
 * listing (verifiedQuarantineRow) before they can learn the message is already
 * released, so "no upstream call was made" and "without an upstream call" were
 * false there. The duplicate arm of the direct path is reached before that read
 * and sends nothing; it keeps its sentence and is pinned below.
 *
 * The REAL CippRestWriteClient runs under Http::fake(), and Http::recorded()
 * is the request log. Every case asserts the exact list of CIPP API requests
 * (the OAuth token fetch is excluded; it is not a CIPP call), and the direct
 * duplicate case first shows the log records the release itself.
 */
class CippQuarantineAlreadyWordingTest extends TestCase
{
    use RefreshDatabase;

    private const IDENTITY = 'aaaaaaaa-1111-2222-3333-444444444444\bbbbbbbb-5555-6666-7777-888888888888';

    private const LIST_READ = 'GET https://cipp.example.test/api/ListMailQuarantine?tenantFilter=acme.onmicrosoft.com';

    private const RELEASE = 'POST https://cipp.example.test/api/ExecQuarantineManagement';

    private string $releaseStatus = 'NOTRELEASED';

    private Client $client;

    private Ticket $ticket;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::setValue('cipp_enabled', '1');
        Setting::setValue('cipp_api_url', 'https://cipp.example.test');
        Setting::setValue('cipp_tenant_id', 'tenant-1');
        Setting::setValue('cipp_client_id', 'client-1');
        Setting::setEncrypted('cipp_client_secret', 'secret');
        $actor = User::factory()->create();
        Setting::setValue('triage_system_user_id', (string) $actor->id);

        $this->client = Client::factory()->create(['name' => 'Acme', 'cipp_tenant_domain' => 'acme.onmicrosoft.com']);
        $this->ticket = Ticket::factory()->for($this->client)->create(['subject' => 'Quarantined invoice']);

        $this->app->instance(CippRestWriteClient::class, new CippRestWriteClient([
            'api_url' => 'https://cipp.example.test', 'tenant_id' => 'tenant-1',
            'client_id' => 'client-1', 'client_secret' => 'secret',
        ], Cache::store(), fn (string $host): array => ['93.184.216.34']));

        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            $url = $request->url();
            if (str_contains($url, 'login.microsoftonline.com')) {
                return Http::response(['access_token' => 'WRITE-TOKEN', 'expires_in' => 3600]);
            }
            if (str_contains($url, '/api/ListMailQuarantine')) {
                return Http::response(['Results' => [[
                    'Identity' => self::IDENTITY,
                    'SenderAddress' => 'billing@vendor.example',
                    'RecipientAddress' => ['alex@acme.example'],
                    'Subject' => 'Vendor invoice 4321',
                    'ReleaseStatus' => $this->releaseStatus,
                ]]]);
            }
            if (str_contains($url, '/api/ExecQuarantineManagement')) {
                return Http::response(['Results' => 'Released']);
            }

            return Http::response('unrouted', 599);
        });
    }

    /** @return list<string> CIPP API requests only, in order */
    private function cippRequests(): array
    {
        $out = [];
        foreach (Http::recorded() as [$request]) {
            if (! str_contains($request->url(), 'login.microsoftonline.com')) {
                $out[] = $request->method().' '.$request->url();
            }
        }

        return $out;
    }

    /** @return list<string> */
    private function newRequestsSince(int $before): array
    {
        return array_slice($this->cippRequests(), $before);
    }

    private function args(): array
    {
        return [
            'quarantine_identity' => self::IDENTITY,
            'confirm_sender' => 'billing@vendor.example',
            'reason' => 'Confirmed false positive.',
        ];
    }

    private function latestSummary(string $tool, string $status): string
    {
        return (string) TechnicianActionLog::query()
            ->where('action_type', $tool)->where('result_status', $status)
            ->latest('id')->value('summary');
    }

    /**
     * TRUE arm, and the seam's positive control: the first call is recorded
     * sending the listing read and the release, and the identical second call
     * is answered from the audit log before any CIPP request.
     */
    public function test_direct_duplicate_sends_nothing_and_keeps_its_sentence(): void
    {
        $executor = app(StaffCippWriteToolExecutor::class);

        $first = $executor->execute('cipp_release_quarantine_message', $this->args(), $this->client->id, 'wording-test');
        $this->assertSame('Quarantine release executed for all original recipients.', $first['message'] ?? null, json_encode($first));
        $this->assertSame([self::LIST_READ, self::RELEASE], $this->cippRequests(), 'the recorder must see the first call\'s read and release');

        $before = count($this->cippRequests());
        $second = $executor->execute('cipp_release_quarantine_message', $this->args(), $this->client->id, 'wording-test');

        $this->assertSame([], $this->newRequestsSince($before), 'the duplicate must send no CIPP request');
        $this->assertTrue($second['idempotent'] ?? false);
        $this->assertSame('Already executed identical CIPP write recently; no upstream call was made.', $second['message'] ?? null);
        $this->assertSame(
            'quarantine #8cd2f3dc612b: Duplicate cipp_release_quarantine_message suppressed before upstream call.',
            $this->latestSummary('cipp_release_quarantine_message', 'blocked'),
        );
    }

    public function test_direct_already_released_answer_follows_a_listing_read_and_sends_no_release(): void
    {
        $this->releaseStatus = 'RELEASED';

        $result = app(StaffCippWriteToolExecutor::class)
            ->execute('cipp_release_quarantine_message', $this->args(), $this->client->id, 'wording-test');

        $this->assertSame([self::LIST_READ], $this->cippRequests(), 'the listing is read before the arm; no release follows');
        $this->assertTrue($result['idempotent'] ?? false);
        $this->assertTrue($result['already_released'] ?? false);
        $this->assertSame('Message is already released upstream.', $result['message'] ?? null);
        $this->assertSame(
            'quarantine #8cd2f3dc612b: Message already released upstream; no release was sent.',
            $this->latestSummary('cipp_release_quarantine_message', 'executed'),
        );
    }

    public function test_approval_already_released_answer_follows_a_listing_read_and_sends_no_release(): void
    {
        $executor = app(StaffCippWriteToolExecutor::class);
        $staged = $executor->execute('cipp_stage_release_quarantine_message', $this->args() + ['ticket_id' => $this->ticket->id], $this->client->id, 'wording-test');
        $run = TechnicianRun::findOrFail($staged['run_id'] ?? 0);

        $this->releaseStatus = 'RELEASED';
        $before = count($this->cippRequests());
        $approver = User::factory()->create();
        $result = $executor->approveStagedRun($run, $approver->id);

        $this->assertSame([self::LIST_READ], $this->newRequestsSince($before), 'approval re-reads the listing; no release follows');
        $this->assertSame('executed', $result->status);
        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);
        $this->assertSame(
            'quarantine #8cd2f3dc612b: Message already released upstream — approved release satisfied; no release was sent.',
            $this->latestSummary('cipp_stage_release_quarantine_message', 'executed'),
        );
    }
}
