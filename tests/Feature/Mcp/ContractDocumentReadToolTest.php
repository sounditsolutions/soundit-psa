<?php

namespace Tests\Feature\Mcp;

use App\Enums\ContractStatus;
use App\Enums\ContractType;
use App\Enums\DocumentSummaryStatus;
use App\Models\Client;
use App\Models\Contract;
use App\Models\ContractDocument;
use App\Services\Assistant\AssistantToolExecutor;
use App\Support\McpConfig;
use App\Support\McpToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * list_contract_documents / get_contract_document: grant-gated, client-scoped,
 * read-only psa_read tools. Metadata, then one document's stored text, capped.
 * Never file bytes, disk_path or a URL. Synthetic data only. No HTTP is
 * faked: Http::preventStrayRequests() makes any outbound request through the
 * Http client throw.
 */
class ContractDocumentReadToolTest extends TestCase
{
    use RefreshDatabase;

    private const TOOLS = ['list_contract_documents', 'get_contract_document'];

    private const DISK_PATH = 'contract-documents/synthetic/stored-file-marker.pdf';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Storage::fake('local');
    }

    private function token(array $tools = self::TOOLS, string $label = 'chet'): string
    {
        return McpConfig::rotateStaffToken(allowedTools: $tools, label: $label);
    }

    /** @param  array<string, mixed>  $arguments */
    private function callTool(string $token, string $name, array $arguments): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/mcp/staff', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => ['name' => $name, 'arguments' => $arguments],
            ]);
    }

    /** @return list<string> */
    private function listedToolNames(string $token): array
    {
        $tools = $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/mcp/staff', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => []])
            ->json('result.tools') ?? [];

        return array_column($tools, 'name');
    }

    /** @return array<string, mixed> */
    private function ok(TestResponse $response): array
    {
        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));

        return json_decode((string) $response->json('result.content.0.text'), true) ?? [];
    }

    private function refusal(TestResponse $response): string
    {
        $response->assertOk();
        $this->assertTrue((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));

        return (string) $response->json('result.content.0.text');
    }

    private function contract(Client $client, string $name = 'Synthetic Coverage'): Contract
    {
        return Contract::create([
            'client_id' => $client->id,
            'name' => $name,
            'type' => ContractType::Managed,
            'status' => ContractStatus::Active,
            'start_date' => now()->subYear()->toDateString(),
        ]);
    }

    /** @param  array<string, mixed>  $overrides */
    private function document(Contract $contract, array $overrides = []): ContractDocument
    {
        return ContractDocument::create(array_merge([
            'contract_id' => $contract->id,
            'original_filename' => 'synthetic-agreement.pdf',
            'disk_path' => self::DISK_PATH,
            'mime_type' => 'application/pdf',
            'file_size' => 12345,
            'extracted_text' => 'SYNTHETIC-TEXT-MARKER clause one.',
            'ai_summary' => 'SYNTHETIC-SUMMARY-MARKER.',
            'summary_status' => DocumentSummaryStatus::Completed,
        ], $overrides));
    }

    public function test_list_returns_the_contracts_documents_with_the_closed_key_set(): void
    {
        $client = Client::factory()->create();
        $contract = $this->contract($client);
        $first = $this->document($contract);
        $second = $this->document($contract, [
            'original_filename' => 'synthetic-schedule.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size' => 678,
            'summary_status' => DocumentSummaryStatus::Pending,
            'ai_summary' => null,
        ]);

        $response = $this->callTool($this->token(), 'list_contract_documents', ['client_id' => $client->id, 'contract_id' => $contract->id]);
        $result = $this->ok($response);

        $this->assertSame(['contract_id', 'count', 'documents'], array_keys($result));
        $this->assertSame($contract->id, $result['contract_id']);
        $this->assertSame(2, $result['count']);
        $this->assertSame([
            ['id' => $first->id, 'filename' => 'synthetic-agreement.pdf', 'mime' => 'application/pdf', 'size' => 12345, 'summary_status' => 'completed'],
            ['id' => $second->id, 'filename' => 'synthetic-schedule.docx', 'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'size' => 678, 'summary_status' => 'pending'],
        ], $result['documents']);
        // Metadata only: no stored text in the list.
        $this->assertStringNotContainsString('SYNTHETIC-TEXT-MARKER', (string) $response->getContent());
        $this->assertStringNotContainsString('SYNTHETIC-SUMMARY-MARKER', (string) $response->getContent());
        $this->assertNoStorageLeak($response);
    }

    public function test_get_returns_the_stored_text_and_summary_with_the_closed_key_set(): void
    {
        $client = Client::factory()->create();
        $contract = $this->contract($client);
        $document = $this->document($contract);

        $response = $this->callTool($this->token(), 'get_contract_document', ['client_id' => $client->id, 'document_id' => $document->id]);
        $result = $this->ok($response);

        $this->assertSame([
            'id', 'contract_id', 'filename', 'mime', 'summary_status', 'text_cap_chars',
            'extracted_text', 'extracted_text_truncated', 'ai_summary', 'ai_summary_truncated', 'notes',
        ], array_keys($result));
        $this->assertSame($document->id, $result['id']);
        $this->assertSame($contract->id, $result['contract_id']);
        $this->assertSame('synthetic-agreement.pdf', $result['filename']);
        $this->assertSame('application/pdf', $result['mime']);
        $this->assertSame('completed', $result['summary_status']);
        $this->assertSame(AssistantToolExecutor::CONTRACT_DOCUMENT_TEXT_CAP, $result['text_cap_chars']);
        $this->assertSame('SYNTHETIC-TEXT-MARKER clause one.', $result['extracted_text']);
        $this->assertFalse($result['extracted_text_truncated']);
        $this->assertSame('SYNTHETIC-SUMMARY-MARKER.', $result['ai_summary']);
        $this->assertFalse($result['ai_summary_truncated']);
        $this->assertSame([], $result['notes']);
        $this->assertNoStorageLeak($response);
    }

    public function test_list_refuses_another_clients_contract_exactly_as_get_contract_and_not_found_do(): void
    {
        $owner = Client::factory()->create();
        $other = Client::factory()->create();
        $contract = $this->contract($owner);
        $this->document($contract);
        $token = $this->token([...self::TOOLS, 'get_contract']);

        $crossResponse = $this->callTool($token, 'list_contract_documents', ['client_id' => $other->id, 'contract_id' => $contract->id]);
        $cross = $this->refusal($crossResponse);
        $missing = $this->refusal($this->callTool($token, 'list_contract_documents', ['client_id' => $other->id, 'contract_id' => $contract->id + 1000]));
        $getContract = $this->refusal($this->callTool($token, 'get_contract', ['client_id' => $other->id, 'contract_id' => $contract->id]));

        $this->assertStringContainsString('Contract not found at this client', $cross);
        $this->assertSame($missing, $cross);
        $this->assertSame($getContract, $cross);
        $raw = (string) $crossResponse->getContent();
        $this->assertStringNotContainsString('synthetic-agreement', $raw);
        $this->assertStringNotContainsString('count', $raw);
        $this->assertStringNotContainsString('documents', $raw);

        // The owner reads it: the refusal is the fence, not a broken tool.
        $this->assertSame(1, $this->ok($this->callTool($token, 'list_contract_documents', ['client_id' => $owner->id, 'contract_id' => $contract->id]))['count']);
    }

    public function test_get_refuses_another_clients_document_exactly_as_a_missing_one(): void
    {
        $owner = Client::factory()->create();
        $other = Client::factory()->create();
        $document = $this->document($this->contract($owner));
        $this->contract($other);
        $token = $this->token();

        $crossResponse = $this->callTool($token, 'get_contract_document', ['client_id' => $other->id, 'document_id' => $document->id]);
        $cross = $this->refusal($crossResponse);
        $missing = $this->refusal($this->callTool($token, 'get_contract_document', ['client_id' => $other->id, 'document_id' => $document->id + 1000]));

        $this->assertStringContainsString('Document not found at this client', $cross);
        $this->assertSame($missing, $cross);
        $raw = (string) $crossResponse->getContent();
        foreach (['synthetic-agreement', 'SYNTHETIC-TEXT-MARKER', 'SYNTHETIC-SUMMARY-MARKER', 'extracted_text', 'ai_summary', 'contract_id'] as $leak) {
            $this->assertStringNotContainsString($leak, $raw);
        }

        $this->assertSame($document->id, $this->ok($this->callTool($token, 'get_contract_document', ['client_id' => $owner->id, 'document_id' => $document->id]))['id']);
    }

    public function test_list_shows_only_the_named_contracts_documents_and_get_reports_each_documents_own_contract(): void
    {
        $client = Client::factory()->create();
        $first = $this->contract($client, 'Synthetic First');
        $second = $this->contract($client, 'Synthetic Second');
        $mine = $this->document($first);
        $sibling = $this->document($second, ['original_filename' => 'synthetic-sibling.pdf']);
        $foreign = $this->document($this->contract(Client::factory()->create(), 'Synthetic Foreign'), ['original_filename' => 'synthetic-foreign.pdf']);
        $token = $this->token();

        $response = $this->callTool($token, 'list_contract_documents', ['client_id' => $client->id, 'contract_id' => $first->id]);
        $list = $this->ok($response);
        $this->assertSame(1, $list['count']);
        $this->assertSame([$mine->id], array_column($list['documents'], 'id'));
        $raw = (string) $response->getContent();
        $this->assertStringNotContainsString('synthetic-sibling', $raw);
        $this->assertStringNotContainsString('synthetic-foreign', $raw);

        // get takes no contract_id: its fence is the client. A sibling contract's
        // document is this client's and reads, carrying its own contract_id.
        $this->assertSame($second->id, $this->ok($this->callTool($token, 'get_contract_document', ['client_id' => $client->id, 'document_id' => $sibling->id]))['contract_id']);
        $this->assertSame(
            ['error' => 'Document not found at this client'],
            json_decode($this->refusal($this->callTool($token, 'get_contract_document', ['client_id' => $client->id, 'document_id' => $foreign->id])), true),
        );
    }

    public function test_a_soft_deleted_contract_is_refused_by_both_calls(): void
    {
        $client = Client::factory()->create();
        $contract = $this->contract($client);
        $document = $this->document($contract);
        $token = $this->token();

        // Readable before the delete: the refusal below is the soft-delete scope.
        $this->assertSame(1, $this->ok($this->callTool($token, 'list_contract_documents', ['client_id' => $client->id, 'contract_id' => $contract->id]))['count']);
        $this->assertSame($document->id, $this->ok($this->callTool($token, 'get_contract_document', ['client_id' => $client->id, 'document_id' => $document->id]))['id']);

        $contract->delete();
        $this->assertNotNull(Contract::withTrashed()->find($contract->id)?->deleted_at);
        $this->assertNull($document->fresh()->deleted_at);

        $this->assertSame(
            ['error' => 'Contract not found at this client'],
            json_decode($this->refusal($this->callTool($token, 'list_contract_documents', ['client_id' => $client->id, 'contract_id' => $contract->id])), true),
        );
        $this->assertSame(
            ['error' => 'Document not found at this client'],
            json_decode($this->refusal($this->callTool($token, 'get_contract_document', ['client_id' => $client->id, 'document_id' => $document->id])), true),
        );
    }

    public function test_a_lower_id_client_is_refused_by_both_calls(): void
    {
        // Created first, so the caller's client has the LOWER id than the owner.
        $lower = Client::factory()->create();
        $owner = Client::factory()->create();
        $this->assertLessThan($owner->id, $lower->id);
        $contract = $this->contract($owner);
        $document = $this->document($contract);
        $token = $this->token();

        $this->assertSame(
            ['error' => 'Contract not found at this client'],
            json_decode($this->refusal($this->callTool($token, 'list_contract_documents', ['client_id' => $lower->id, 'contract_id' => $contract->id])), true),
        );
        $this->assertSame(
            ['error' => 'Document not found at this client'],
            json_decode($this->refusal($this->callTool($token, 'get_contract_document', ['client_id' => $lower->id, 'document_id' => $document->id])), true),
        );

        $this->assertSame(1, $this->ok($this->callTool($token, 'list_contract_documents', ['client_id' => $owner->id, 'contract_id' => $contract->id]))['count']);
        $this->assertSame($document->id, $this->ok($this->callTool($token, 'get_contract_document', ['client_id' => $owner->id, 'document_id' => $document->id]))['id']);
    }

    public function test_list_never_shows_a_lower_id_contracts_documents_from_this_or_another_client(): void
    {
        // Lower ids first: another client's contract and a sibling contract here, both with documents.
        $foreignContract = $this->contract(Client::factory()->create(), 'Synthetic Foreign');
        $foreign = $this->document($foreignContract, ['original_filename' => 'synthetic-foreign-lower.pdf']);
        $client = Client::factory()->create();
        $lowerContract = $this->contract($client, 'Synthetic Lower');
        $lower = $this->document($lowerContract, ['original_filename' => 'synthetic-sibling-lower.pdf']);
        $contract = $this->contract($client, 'Synthetic Named');
        $mine = $this->document($contract);
        $this->assertLessThan($contract->id, $foreignContract->id);
        $this->assertLessThan($contract->id, $lowerContract->id);
        $this->assertLessThan($mine->id, $foreign->id);
        $this->assertLessThan($mine->id, $lower->id);

        $response = $this->callTool($this->token(), 'list_contract_documents', ['client_id' => $client->id, 'contract_id' => $contract->id]);
        $list = $this->ok($response);

        $this->assertSame($contract->id, $list['contract_id']);
        $this->assertSame(1, $list['count']);
        $this->assertSame([$mine->id], array_column($list['documents'], 'id'));
        $raw = (string) $response->getContent();
        $this->assertStringNotContainsString('synthetic-foreign-lower', $raw);
        $this->assertStringNotContainsString('synthetic-sibling-lower', $raw);
    }

    public function test_list_reads_the_named_contract_and_refuses_an_unknown_id_at_a_client_with_contracts(): void
    {
        $client = Client::factory()->create();
        // An id that existed and is gone, below the client's live contracts.
        $vanished = $this->contract($client, 'Synthetic Vanished');
        $unknownId = $vanished->id;
        $vanished->forceDelete();
        $first = $this->contract($client, 'Synthetic First');
        $second = $this->contract($client, 'Synthetic Second');
        $this->document($first, ['original_filename' => 'synthetic-first.pdf']);
        $secondDoc = $this->document($second, ['original_filename' => 'synthetic-second.pdf']);
        $this->assertLessThan($first->id, $unknownId);
        $this->assertLessThan($second->id, $first->id);
        $this->assertNull(Contract::withTrashed()->find($unknownId));
        $token = $this->token();

        $response = $this->callTool($token, 'list_contract_documents', ['client_id' => $client->id, 'contract_id' => $second->id]);
        $list = $this->ok($response);
        $this->assertSame($second->id, $list['contract_id']);
        $this->assertSame([$secondDoc->id], array_column($list['documents'], 'id'));
        $this->assertStringNotContainsString('synthetic-first', (string) $response->getContent());

        $refused = $this->callTool($token, 'list_contract_documents', ['client_id' => $client->id, 'contract_id' => $unknownId]);
        $this->assertSame(['error' => 'Contract not found at this client'], json_decode($this->refusal($refused), true));
        $this->assertStringNotContainsString('synthetic-', (string) $refused->getContent());
    }

    public function test_get_reads_the_named_document_and_refuses_a_deleted_id_between_two_documents(): void
    {
        $client = Client::factory()->create();
        $contract = $this->contract($client);
        $low = $this->document($contract, ['original_filename' => 'synthetic-low.pdf', 'extracted_text' => 'SYNTHETIC-LOW-TEXT']);
        $middle = $this->document($contract, ['original_filename' => 'synthetic-middle.pdf']);
        $high = $this->document($contract, ['original_filename' => 'synthetic-high.pdf', 'extracted_text' => 'SYNTHETIC-HIGH-TEXT']);
        $middle->delete();
        $this->assertLessThan($middle->id, $low->id);
        $this->assertLessThan($high->id, $middle->id);
        $token = $this->token();

        $result = $this->ok($this->callTool($token, 'get_contract_document', ['client_id' => $client->id, 'document_id' => $low->id]));
        $this->assertSame($low->id, $result['id']);
        $this->assertSame('SYNTHETIC-LOW-TEXT', $result['extracted_text']);

        $refused = $this->callTool($token, 'get_contract_document', ['client_id' => $client->id, 'document_id' => $middle->id]);
        $this->assertSame(['error' => 'Document not found at this client'], json_decode($this->refusal($refused), true));
        $this->assertStringNotContainsString('SYNTHETIC-HIGH-TEXT', (string) $refused->getContent());
        $this->assertStringNotContainsString('synthetic-high', (string) $refused->getContent());
    }

    public function test_a_whitespace_only_extracted_text_is_returned_as_null_with_a_note(): void
    {
        $client = Client::factory()->create();
        $document = $this->document($this->contract($client), ['extracted_text' => " \t\n\r \n"]);

        $result = $this->ok($this->callTool($this->token(), 'get_contract_document', ['client_id' => $client->id, 'document_id' => $document->id]));

        $this->assertNull($result['extracted_text']);
        $this->assertFalse($result['extracted_text_truncated']);
        $this->assertSame(['This document has no extracted text: the stored field is missing or blank.'], $result['notes']);
        $this->assertSame('SYNTHETIC-SUMMARY-MARKER.', $result['ai_summary']);
        $this->assertSame(" \t\n\r \n", $document->fresh()->extracted_text);
    }

    public function test_a_nul_and_vertical_tab_only_extracted_text_is_returned_as_null_with_a_note(): void
    {
        $stored = "\0\x0B\0\x0B";
        $client = Client::factory()->create();
        $document = $this->document($this->contract($client), ['extracted_text' => $stored]);
        // The stored row really holds NUL and vertical tab, nothing else.
        $this->assertSame($stored, $document->fresh()->extracted_text);

        $result = $this->ok($this->callTool($this->token(), 'get_contract_document', ['client_id' => $client->id, 'document_id' => $document->id]));

        $this->assertNull($result['extracted_text']);
        $this->assertFalse($result['extracted_text_truncated']);
        $this->assertSame(['This document has no extracted text: the stored field is missing or blank.'], $result['notes']);
        $this->assertSame('SYNTHETIC-SUMMARY-MARKER.', $result['ai_summary']);
        $this->assertSame($stored, $document->fresh()->extracted_text);
    }

    public function test_a_form_feed_only_or_nbsp_only_text_is_returned_as_text(): void
    {
        $client = Client::factory()->create();
        $contract = $this->contract($client);
        $token = $this->token();

        foreach (["\f", "\f\f", "\u{00A0}", "\u{00A0}\u{00A0}"] as $stored) {
            $document = $this->document($contract, ['extracted_text' => $stored]);
            $this->assertSame($stored, $document->fresh()->extracted_text);

            $result = $this->ok($this->callTool($token, 'get_contract_document', ['client_id' => $client->id, 'document_id' => $document->id]));

            $this->assertSame($stored, $result['extracted_text'], bin2hex($stored));
            $this->assertFalse($result['extracted_text_truncated']);
            $this->assertSame([], $result['notes'], bin2hex($stored));
        }
    }

    public function test_a_read_past_the_cap_leaves_the_stored_row_unchanged(): void
    {
        $cap = AssistantToolExecutor::CONTRACT_DOCUMENT_TEXT_CAP;
        $long = str_repeat('t', $cap + 40);
        $longSummary = str_repeat('u', $cap + 9);
        $client = Client::factory()->create();
        $document = $this->document($this->contract($client), ['extracted_text' => $long, 'ai_summary' => $longSummary]);
        $before = $document->fresh();
        $updatedAtBefore = $before->getRawOriginal('updated_at');
        $this->assertSame($long, $before->extracted_text);
        // Move the clock so any write would move updated_at.
        $this->travel(5)->minutes();

        $result = $this->ok($this->callTool($this->token(), 'get_contract_document', ['client_id' => $client->id, 'document_id' => $document->id]));
        $this->assertTrue($result['extracted_text_truncated']);
        $this->assertTrue($result['ai_summary_truncated']);

        $after = $document->fresh();
        $this->assertSame($long, $after->extracted_text);
        $this->assertSame($longSummary, $after->ai_summary);
        $this->assertSame($updatedAtBefore, $after->getRawOriginal('updated_at'));
    }

    public function test_the_cut_and_null_branches_return_the_closed_key_set(): void
    {
        $cap = AssistantToolExecutor::CONTRACT_DOCUMENT_TEXT_CAP;
        $client = Client::factory()->create();
        $contract = $this->contract($client);
        $cut = $this->document($contract, ['extracted_text' => str_repeat('c', $cap + 1), 'ai_summary' => str_repeat('d', $cap + 1)]);
        $empty = $this->document($contract, ['extracted_text' => null, 'ai_summary' => null]);
        $token = $this->token();

        $cutResponse = $this->callTool($token, 'get_contract_document', ['client_id' => $client->id, 'document_id' => $cut->id]);
        $this->assertSame([
            'id', 'contract_id', 'filename', 'mime', 'summary_status', 'text_cap_chars',
            'extracted_text', 'extracted_text_truncated', 'extracted_text_total_chars', 'extracted_text_returned_chars',
            'ai_summary', 'ai_summary_truncated', 'ai_summary_total_chars', 'ai_summary_returned_chars',
            'notes',
        ], array_keys($this->ok($cutResponse)));
        $this->assertNoStorageLeak($cutResponse);

        $nullResponse = $this->callTool($token, 'get_contract_document', ['client_id' => $client->id, 'document_id' => $empty->id]);
        $this->assertSame([
            'id', 'contract_id', 'filename', 'mime', 'summary_status', 'text_cap_chars',
            'extracted_text', 'extracted_text_truncated', 'ai_summary', 'ai_summary_truncated', 'notes',
        ], array_keys($this->ok($nullResponse)));
        $this->assertNoStorageLeak($nullResponse);
    }

    public function test_no_client_context_and_missing_ids_are_refused(): void
    {
        $client = Client::factory()->create();
        $token = $this->token();

        $this->assertStringContainsString('No client context', $this->refusal($this->callTool($token, 'list_contract_documents', ['contract_id' => 1])));
        $this->assertStringContainsString('No client context', $this->refusal($this->callTool($token, 'get_contract_document', ['document_id' => 1])));
        $this->assertStringContainsString('contract_id is required', $this->refusal($this->callTool($token, 'list_contract_documents', ['client_id' => $client->id, 'contract_id' => 0])));
        $this->assertStringContainsString('document_id is required', $this->refusal($this->callTool($token, 'get_contract_document', ['client_id' => $client->id, 'document_id' => -3])));
    }

    public function test_a_missing_summary_is_reported_never_generated(): void
    {
        $client = Client::factory()->create();
        $contract = $this->contract($client);
        $none = $this->document($contract, ['ai_summary' => null, 'summary_status' => DocumentSummaryStatus::Skipped]);
        $blank = $this->document($contract, ['ai_summary' => '   ', 'summary_status' => DocumentSummaryStatus::Failed]);
        $token = $this->token();

        foreach ([[$none, 'skipped'], [$blank, 'failed']] as [$document, $status]) {
            $result = $this->ok($this->callTool($token, 'get_contract_document', ['client_id' => $client->id, 'document_id' => $document->id]));
            $this->assertNull($result['ai_summary']);
            $this->assertFalse($result['ai_summary_truncated']);
            $this->assertSame($status, $result['summary_status']);
            $this->assertContains('This document has no AI summary: the stored field is missing or blank.', $result['notes']);
            $this->assertSame('SYNTHETIC-TEXT-MARKER clause one.', $result['extracted_text']);
        }

        // Reading summarised nothing: the stored rows are unchanged.
        $this->assertNull($none->fresh()->ai_summary);
        $this->assertSame(DocumentSummaryStatus::Skipped, $none->fresh()->summary_status);
        $this->assertSame('   ', $blank->fresh()->ai_summary);
    }

    public function test_missing_extracted_text_is_said_plainly(): void
    {
        $client = Client::factory()->create();
        $document = $this->document($this->contract($client), ['extracted_text' => null]);

        $result = $this->ok($this->callTool($this->token(), 'get_contract_document', ['client_id' => $client->id, 'document_id' => $document->id]));

        $this->assertNull($result['extracted_text']);
        $this->assertFalse($result['extracted_text_truncated']);
        $this->assertSame(['This document has no extracted text: the stored field is missing or blank.'], $result['notes']);
    }

    public function test_text_over_the_cap_is_cut_and_labelled_with_both_lengths(): void
    {
        $cap = AssistantToolExecutor::CONTRACT_DOCUMENT_TEXT_CAP;
        $this->assertSame(60000, $cap);
        // Multibyte so a byte-based cut would show: each é is two bytes.
        $long = str_repeat('é', $cap + 250);
        $longSummary = str_repeat('s', $cap + 7);
        $client = Client::factory()->create();
        $document = $this->document($this->contract($client), ['extracted_text' => $long, 'ai_summary' => $longSummary]);

        $result = $this->ok($this->callTool($this->token(), 'get_contract_document', ['client_id' => $client->id, 'document_id' => $document->id]));

        $this->assertTrue($result['extracted_text_truncated']);
        $this->assertSame($cap + 250, $result['extracted_text_total_chars']);
        $this->assertSame($cap, $result['extracted_text_returned_chars']);
        $this->assertSame($cap, mb_strlen($result['extracted_text']));
        $this->assertSame(mb_substr($long, 0, $cap), $result['extracted_text']);
        $this->assertContains('extracted_text is cut at 60000 of 60250 characters; this is not the whole document.', $result['notes']);

        $this->assertTrue($result['ai_summary_truncated']);
        $this->assertSame($cap + 7, $result['ai_summary_total_chars']);
        $this->assertSame($cap, $result['ai_summary_returned_chars']);
        $this->assertSame($cap, mb_strlen($result['ai_summary']));
        $this->assertContains('ai_summary is cut at 60000 of 60007 characters; this is not the whole summary.', $result['notes']);
    }

    public function test_text_at_or_under_the_cap_is_whole_and_says_so(): void
    {
        $cap = AssistantToolExecutor::CONTRACT_DOCUMENT_TEXT_CAP;
        $exact = str_repeat('x', $cap);
        $client = Client::factory()->create();
        $document = $this->document($this->contract($client), ['extracted_text' => $exact]);

        $result = $this->ok($this->callTool($this->token(), 'get_contract_document', ['client_id' => $client->id, 'document_id' => $document->id]));

        $this->assertFalse($result['extracted_text_truncated']);
        $this->assertSame($exact, $result['extracted_text']);
        $this->assertArrayNotHasKey('extracted_text_total_chars', $result);
        $this->assertArrayNotHasKey('extracted_text_returned_chars', $result);
        $this->assertSame([], $result['notes']);
    }

    public function test_soft_deleted_documents_are_excluded_from_both_calls(): void
    {
        $client = Client::factory()->create();
        $contract = $this->contract($client);
        $kept = $this->document($contract);
        $gone = $this->document($contract, ['original_filename' => 'synthetic-removed.pdf']);
        $gone->delete();
        $token = $this->token();

        $listResponse = $this->callTool($token, 'list_contract_documents', ['client_id' => $client->id, 'contract_id' => $contract->id]);
        $list = $this->ok($listResponse);
        $this->assertSame(1, $list['count']);
        $this->assertSame([$kept->id], array_column($list['documents'], 'id'));
        $this->assertStringNotContainsString('synthetic-removed', (string) $listResponse->getContent());

        $this->assertSame(
            ['error' => 'Document not found at this client'],
            json_decode($this->refusal($this->callTool($token, 'get_contract_document', ['client_id' => $client->id, 'document_id' => $gone->id])), true),
        );
    }

    public function test_tools_are_grant_gated_psa_reads(): void
    {
        foreach (self::TOOLS as $name) {
            $this->assertContains($name, array_column(McpToolRegistry::groups()['psa_read']['tools'], 'name'));
            $this->assertContains($name, AssistantToolExecutor::readTools());
            $this->assertNotContains($name, AssistantToolExecutor::writeTools());
        }

        $client = Client::factory()->create();
        $contract = $this->contract($client);
        $document = $this->document($contract);

        $granted = $this->token();
        $ungranted = $this->token(['get_contract'], 'other');
        $legacy = McpConfig::rotateStaffToken();

        $listed = $this->listedToolNames($granted);
        foreach (self::TOOLS as $name) {
            $this->assertContains($name, $listed);
        }
        foreach ([$ungranted, $legacy] as $token) {
            $listed = $this->listedToolNames($token);
            foreach (self::TOOLS as $name) {
                $this->assertNotContains($name, $listed);
            }
            $this->assertStringContainsString('not allowed for this token', $this->refusal($this->callTool($token, 'list_contract_documents', ['client_id' => $client->id, 'contract_id' => $contract->id])));
            $this->assertStringContainsString('not allowed for this token', $this->refusal($this->callTool($token, 'get_contract_document', ['client_id' => $client->id, 'document_id' => $document->id])));
        }
    }

    public function test_descriptions_state_read_only_scope_no_bytes_and_the_cap(): void
    {
        foreach ([McpToolRegistry::listContractDocumentsTool(), McpToolRegistry::getContractDocumentTool()] as $tool) {
            $this->assertStringContainsString('Read-only. Requires an explicit token grant.', $tool['description']);
            $this->assertStringContainsString('Client-scoped', $tool['description']);
            $this->assertStringContainsString('no file bytes', strtolower($tool['description']));
        }
        $this->assertStringContainsString('capped at 60000 characters', McpToolRegistry::getContractDocumentTool()['description']);
        $this->assertSame(['client_id', 'contract_id'], McpToolRegistry::listContractDocumentsTool()['input_schema']['required']);
        $this->assertSame(['client_id', 'document_id'], McpToolRegistry::getContractDocumentTool()['input_schema']['required']);
    }

    public function test_reads_write_nothing(): void
    {
        $client = Client::factory()->create();
        $contract = $this->contract($client);
        $document = $this->document($contract, ['ai_summary' => null, 'summary_status' => DocumentSummaryStatus::Pending]);
        $before = [$contract->fresh()->toArray(), ContractDocument::withTrashed()->orderBy('id')->get()->toArray()];
        $token = $this->token();

        $this->ok($this->callTool($token, 'list_contract_documents', ['client_id' => $client->id, 'contract_id' => $contract->id]));
        $this->ok($this->callTool($token, 'get_contract_document', ['client_id' => $client->id, 'document_id' => $document->id]));

        $this->assertSame($before, [$contract->fresh()->toArray(), ContractDocument::withTrashed()->orderBy('id')->get()->toArray()]);
    }

    /** The raw response must never carry the stored path or a URL. */
    private function assertNoStorageLeak(TestResponse $response): void
    {
        $raw = (string) $response->getContent();
        $this->assertStringNotContainsString('disk_path', $raw);
        $this->assertStringNotContainsString('stored-file-marker', $raw);
        $this->assertStringNotContainsString('http:', $raw);
        $this->assertStringNotContainsString('https:', $raw);
        $this->assertStringNotContainsString('download', strtolower($raw));
    }
}
