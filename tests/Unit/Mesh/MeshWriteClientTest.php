<?php

namespace Tests\Unit\Mesh;

use App\Services\Mesh\MeshClientException;
use App\Services\Mesh\MeshWriteClient;
use App\Services\Mesh\MeshWriteRejectedException;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

/**
 * MeshWriteClient — the write lane, unit-tested over an injected Guzzle client
 * backed by a MockHandler. No network, no container.
 *
 * The load-bearing facts under test:
 *   1. The create body is assembled by the client from four arguments; `edge`,
 *      `customers` and a false `ab` are structurally unreachable. The fourth,
 *      the expiry, is the caller's and may be null for a permanent rule (#1133),
 *      in which case no date_expiry is sent.
 *   2. A scoped read pages the partner-wide list and returns ONLY the target
 *      tenant's rows — the raw list cannot escape (#1018 criterion 7).
 *   3. A 400 becomes MeshWriteRejectedException carrying the vendor's own text
 *      (criterion 9); everything else is a MeshClientException.
 *   4. ruleAbsent() answers true only on a measured 404; anything unmeasurable
 *      is null, never a pass.
 */
class MeshWriteClientTest extends TestCase
{
    /** @var array<int, array{request: RequestInterface}> */
    private array $history = [];

    /** @param array<int, Response|\Throwable> $queue */
    private function clientReturning(array $queue, array $config = ['api_key' => 'k', 'base_url' => 'https://hub-us.example.test']): MeshWriteClient
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(Middleware::history($this->history));

        $http = new GuzzleClient([
            'base_uri' => 'https://hub-us.example.test/',
            'handler' => $stack,
            'http_errors' => true,
        ]);

        return new MeshWriteClient($config, $http);
    }

    private function lastRequest(): RequestInterface
    {
        return $this->history[array_key_last($this->history)]['request'];
    }

    /** @return array<string, mixed> */
    private function lastBody(): array
    {
        return json_decode((string) $this->lastRequest()->getBody(), true) ?? [];
    }

    private function rule(string $id, string $customerId, string $sender, string $comment): array
    {
        return [
            'id' => $id,
            'sender' => $sender,
            'comment' => $comment,
            'ab' => true,
            'active' => true,
            // The representation measured on live rows: normalised to
            // organization_level true / customer_id null, with the real tenant
            // only in the nested customer object.
            'organization_level' => true,
            'customer_id' => null,
            'customer' => ['id' => $customerId, 'name' => 'Tenant'],
            'created_by' => 'keyowner@example.test',
        ];
    }

    public function test_create_sends_only_the_four_argument_body_and_never_widening_fields(): void
    {
        $client = $this->clientReturning([
            new Response(201, [], json_encode(['detail' => 'Allow/Block Rules added', 'added_for' => ['tenant-1']])),
        ]);

        $created = $client->createAllowRule('tenant-1', 'sender@example.test', 'PSA allow ABCDE12345', '2026-12-01T00:00:00+00:00');

        $this->assertSame(['tenant-1'], $created['added_for']);

        $request = $this->lastRequest();
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('/api/rule-allows-blocks/', $request->getUri()->getPath());
        $this->assertSame('k', $request->getHeaderLine('API-KEY'));

        $body = $this->lastBody();
        $this->assertTrue($body['ab'], 'ab must be the ALLOW_RULE constant');
        $this->assertSame(MeshWriteClient::ALLOW_RULE, $body['ab']);
        $this->assertSame('tenant-1', $body['customer_id']);
        $this->assertFalse($body['organization_level']);
        $this->assertSame('sender@example.test', $body['sender']);
        $this->assertSame('PSA allow ABCDE12345', $body['comment']);
        $this->assertSame('2026-12-01T00:00:00+00:00', $body['date_expiry'], 'the caller\'s expiry is sent verbatim (#1133)');
        $this->assertTrue($body['active']);
        $this->assertSame([], $body['users']);
        $this->assertSame([], $body['domains']);

        // The three ways this write could be made wider than approved.
        $this->assertArrayNotHasKey('edge', $body, 'edge would apply the rule at connection level');
        $this->assertArrayNotHasKey('customers', $body, 'customers[] is the partner-wide form');
        $this->assertArrayNotHasKey('partner_id', $body);
    }

    public function test_create_never_binds_the_partner_wide_route(): void
    {
        $client = $this->clientReturning([
            new Response(201, [], json_encode(['detail' => 'Allow/Block Rules added', 'added_for' => ['tenant-1']])),
        ]);

        $client->createAllowRule('tenant-1', 'a@example.test', 'PSA allow AAAAAAAAAA', '2026-12-01T00:00:00+00:00');

        $this->assertStringNotContainsString('global-allow-block-rules', (string) $this->lastRequest()->getUri());
    }

    /**
     * #1133 — a permanent rule sends NO date_expiry at all.
     *
     * Not an empty string, not a null literal, not a far-future date: the key
     * is absent from the body. Mesh's own date_expiry is display-only (measured
     * 2026-09-01, nothing upstream reaps on it), so what this field says is a
     * statement to whoever reads the portal. For a rule the PSA will never
     * remove, the honest statement is silence, not a date that will pass.
     */
    public function test_create_sends_no_date_expiry_for_a_permanent_rule(): void
    {
        $client = $this->clientReturning([
            new Response(201, [], json_encode(['detail' => 'Allow/Block Rules added', 'added_for' => ['tenant-1']])),
        ]);

        $client->createAllowRule('tenant-1', 'forever@example.test', 'PSA allow BBBBBBBBBB', null);

        $body = $this->lastBody();
        $this->assertArrayNotHasKey('date_expiry', $body, 'a permanent rule must send no expiry field whatsoever');

        // The rest of the body is unchanged by permanence — same narrow scope.
        $this->assertSame(MeshWriteClient::ALLOW_RULE, $body['ab']);
        $this->assertSame('tenant-1', $body['customer_id']);
        $this->assertFalse($body['organization_level']);
        $this->assertSame('forever@example.test', $body['sender']);
        $this->assertArrayNotHasKey('edge', $body);
        $this->assertArrayNotHasKey('customers', $body);
    }

    public function test_create_without_a_key_fails_closed_before_any_http(): void
    {
        $client = $this->clientReturning([], ['api_key' => null]);

        $this->expectException(MeshClientException::class);
        $this->expectExceptionMessage('nothing was sent');

        $client->createAllowRule('tenant-1', 'a@example.test', 'PSA allow AAAAAAAAAA', '2026-12-01T00:00:00+00:00');
    }

    public function test_scoped_list_pages_the_partner_wide_list_and_returns_only_this_tenants_rules(): void
    {
        $page1 = array_map(
            fn (int $i): array => $this->rule("other-{$i}", 'tenant-OTHER', "other{$i}@example.test", 'someone else'),
            range(1, MeshWriteClient::LIST_PAGE_SIZE),
        );
        $page1[7] = $this->rule('mine-1', 'tenant-1', 'a@example.test', 'PSA allow AAAAAAAAAA');

        $client = $this->clientReturning([
            new Response(200, [], json_encode(['count' => 201, 'results' => $page1])),
            new Response(200, [], json_encode(['count' => 201, 'results' => [$this->rule('mine-2', 'tenant-1', 'b@example.test', 'PSA allow BBBBBBBBBB')]])),
        ]);

        $rules = $client->listCustomerRules('tenant-1');

        $this->assertCount(2, $rules, 'only the target tenant’s rules may be returned');
        $this->assertSame(['mine-1', 'mine-2'], array_column($rules, 'id'));

        // Page two was requested with the paging offset, not a customer filter —
        // `customer_id` is ignored as a filter on this route (measured).
        parse_str((string) $this->lastRequest()->getUri()->getQuery(), $query);
        $this->assertSame((string) MeshWriteClient::LIST_PAGE_SIZE, (string) $query['_from']);
    }

    public function test_scoped_list_never_matches_rows_on_a_null_tenant(): void
    {
        // Every live row carries customer_id: null. A naive equality check would
        // match all of them for a caller with an empty tenant id.
        $client = $this->clientReturning([
            new Response(200, [], json_encode(['results' => [$this->rule('r1', 'tenant-1', 'a@example.test', 'x')]])),
        ]);

        $this->expectException(MeshClientException::class);
        $client->listCustomerRules('  ');
    }

    public function test_find_by_comment_matches_sender_and_comment_case_insensitively(): void
    {
        $client = $this->clientReturning([
            new Response(200, [], json_encode(['results' => [
                $this->rule('r1', 'tenant-1', 'a@example.test', 'PSA allow AAAAAAAAAA'),
                $this->rule('r2', 'tenant-1', 'b@example.test', 'PSA allow BBBBBBBBBB'),
            ]])),
        ]);

        $match = $client->findRuleByComment('tenant-1', 'B@Example.test', 'psa allow bbbbbbbbbb');

        $this->assertSame('r2', $match['id']);
    }

    public function test_find_by_comment_returns_null_when_the_token_matches_a_different_sender(): void
    {
        $client = $this->clientReturning([
            new Response(200, [], json_encode(['results' => [
                $this->rule('r1', 'tenant-1', 'a@example.test', 'PSA allow AAAAAAAAAA'),
            ]])),
        ]);

        $this->assertNull($client->findRuleByComment('tenant-1', 'someone-else@example.test', 'PSA allow AAAAAAAAAA'));
    }

    public function test_a_400_becomes_a_rejection_carrying_the_vendor_text(): void
    {
        $body = ['detail' => 'No Allow/Block Rules added', 'errors' => ['Invalid sender: special-use or reserved domain']];
        $client = $this->clientReturning([new Response(400, [], json_encode($body))]);

        try {
            $client->createAllowRule('tenant-1', 'root@localhost', 'PSA allow AAAAAAAAAA', '2026-12-01T00:00:00+00:00');
            $this->fail('a 400 must raise MeshWriteRejectedException');
        } catch (MeshWriteRejectedException $e) {
            $this->assertStringContainsString('No Allow/Block Rules added', $e->getMessage());
            $this->assertStringContainsString('Invalid sender', $e->getMessage());
            $this->assertSame($body, $e->vendorBody());
            $this->assertSame(400, $e->getCode());
        }
    }

    public function test_a_field_map_400_is_also_passed_through(): void
    {
        $client = $this->clientReturning([new Response(400, [], json_encode(['comment' => ['String invalid']]))]);

        try {
            $client->createAllowRule('tenant-1', 'a@example.test', 'PSA allow # 1', '2026-12-01T00:00:00+00:00');
            $this->fail('a 400 must raise MeshWriteRejectedException');
        } catch (MeshWriteRejectedException $e) {
            $this->assertStringContainsString('comment: String invalid', $e->getMessage());
        }
    }

    public function test_a_500_is_a_transport_class_failure_not_a_rejection(): void
    {
        $client = $this->clientReturning([new Response(500, [], 'boom')]);

        $this->expectException(MeshClientException::class);

        try {
            $client->createAllowRule('tenant-1', 'a@example.test', 'PSA allow AAAAAAAAAA', '2026-12-01T00:00:00+00:00');
        } catch (MeshWriteRejectedException $e) {
            $this->fail('a 500 must not be reported as a vendor rejection');
        }
    }

    public function test_delete_targets_the_detail_route(): void
    {
        $client = $this->clientReturning([new Response(200, [], json_encode(['detail' => 'deleted']))]);

        $client->deleteRule('rule-1');

        $this->assertSame('DELETE', $this->lastRequest()->getMethod());
        $this->assertSame('/api/rule-allows-blocks/rule-1/', $this->lastRequest()->getUri()->getPath());
    }

    public function test_rule_absent_is_true_only_on_a_measured_404(): void
    {
        $this->assertTrue($this->clientReturning([new Response(404)])->ruleAbsent('rule-1'));
        $this->assertFalse($this->clientReturning([new Response(200, [], json_encode(['id' => 'rule-1']))])->ruleAbsent('rule-1'));

        // Unmeasurable is null, never a pass — the reap post-condition must not
        // be satisfied by "we could not tell".
        $this->assertNull($this->clientReturning([new Response(500)])->ruleAbsent('rule-1'));
        $this->assertNull($this->clientReturning([new Response(403)])->ruleAbsent('rule-1'));
        $this->assertNull($this->clientReturning([], ['api_key' => null])->ruleAbsent('rule-1'));
    }

    // ---- #1134: find-by-id is a SCOPE check, not a lookup ---------------------

    public function test_find_by_id_returns_this_tenants_rule(): void
    {
        $client = $this->clientReturning([
            new Response(200, [], json_encode(['results' => [
                $this->rule('r1', 'tenant-1', 'a@example.test', 'PSA allow AAAAAAAAAA'),
                $this->rule('r2', 'tenant-1', 'b@example.test', 'PSA allow BBBBBBBBBB'),
            ]])),
        ]);

        $match = $client->findRuleById('tenant-1', 'r2');

        $this->assertSame('r2', $match['id']);
        $this->assertSame('b@example.test', $match['sender']);
    }

    /**
     * THE red-check for the scope guard. A rule id that exists — and that the
     * API key can read perfectly well on the un-scoped detail route — must be
     * ABSENT when it belongs to another customer. An implementation that
     * dropped the rowBelongsTo() filter (or that resolved the id by GETting
     * `api/rule-allows-blocks/{id}/` directly) would return this row, and the
     * remove verb would then delete another customer's mail filtering.
     */
    public function test_find_by_id_does_not_resolve_a_rule_belonging_to_another_tenant(): void
    {
        $client = $this->clientReturning([
            new Response(200, [], json_encode(['results' => [
                $this->rule('mine', 'tenant-1', 'a@example.test', 'PSA allow AAAAAAAAAA'),
                $this->rule('theirs', 'tenant-OTHER', 'b@example.test', 'someone else'),
            ]])),
        ]);

        $this->assertNull($client->findRuleById('tenant-1', 'theirs'));

        // And the read that answered it was the SCOPED list route, never the
        // un-scoped detail route the id would have resolved on.
        $this->assertSame('GET', $this->lastRequest()->getMethod());
        $this->assertSame('/api/rule-allows-blocks/', $this->lastRequest()->getUri()->getPath());
    }

    public function test_find_by_id_matches_the_id_exactly_and_never_on_case_or_whitespace_alone(): void
    {
        $rows = json_encode(['results' => [$this->rule('Rule-ABC', 'tenant-1', 'a@example.test', 'x')]]);

        // Trimmed, so a pasted id with surrounding whitespace still resolves.
        $this->assertSame('Rule-ABC', $this->clientReturning([new Response(200, [], $rows)])->findRuleById('tenant-1', '  Rule-ABC ')['id']);

        // But NOT case-folded: ids are vendor uuids echoed back verbatim, and
        // widening an identity check on a delete lane needs a measured reason.
        $this->assertNull($this->clientReturning([new Response(200, [], $rows)])->findRuleById('tenant-1', 'rule-abc'));
    }

    public function test_find_by_id_refuses_an_empty_id_without_reading_anything(): void
    {
        // Empty queue: any HTTP call at all would raise from the MockHandler.
        $this->assertNull($this->clientReturning([])->findRuleById('tenant-1', '   '));
    }

    // ---- #1135: the update lane's allow-list lives in the CLIENT -------------

    public function test_patch_targets_the_detail_route_with_the_partial_method(): void
    {
        $client = $this->clientReturning([new Response(200, [], json_encode(['id' => 'rule-1', 'comment' => 'PSA allow BBBBBBBBBB']))]);

        $client->patchRule('rule-1', ['comment' => 'PSA allow BBBBBBBBBB']);

        // PATCH, never PUT: the schema marks `sender` required on PUT, and
        // sending a sender is the one thing an "edit" must never do.
        $this->assertSame('PATCH', $this->lastRequest()->getMethod());
        $this->assertSame('/api/rule-allows-blocks/rule-1/', $this->lastRequest()->getUri()->getPath());
        $this->assertSame(['comment' => 'PSA allow BBBBBBBBBB'], $this->lastBody());
    }

    public function test_patch_sends_an_explicit_null_to_clear_an_expiry(): void
    {
        $client = $this->clientReturning([new Response(200, [], json_encode(['id' => 'rule-1']))]);

        $client->patchRule('rule-1', ['date_expiry' => null]);

        // Deliberately UNLIKE createAllowRule(), where a null expiry omits the
        // field. On a partial update an omitted field means "leave unchanged",
        // so clearing one requires the key to be present and null.
        $this->assertArrayHasKey('date_expiry', $this->lastBody());
        $this->assertNull($this->lastBody()['date_expiry']);
    }

    /**
     * THE red-check for the client-side allow-list, and the mutant is the one
     * named in #1135 build-shape item 7: a call site that tries to send `ab`.
     * Each of these fields is writable on the route per its own OPTIONS
     * schema, so nothing upstream would stop them — the refusal has to be ours
     * and it has to happen before any byte is sent.
     */
    public function test_patch_refuses_every_scope_widening_field_without_sending_anything(): void
    {
        foreach ([
            ['ab' => false],
            ['organization_level' => true],
            ['customer_id' => 'tenant-OTHER'],
            ['partner_id' => 'partner-1'],
            ['global_id' => 'g-1'],
            ['edge' => true],
            ['active' => false],
            ['sender' => 'attacker@example.test'],
            ['comment' => 'ok', 'edge' => true],
        ] as $fields) {
            // Empty queue: reaching the transport at all fails the MockHandler.
            $client = $this->clientReturning([]);

            try {
                $client->patchRule('rule-1', $fields);
                $this->fail('patchRule accepted '.implode(', ', array_keys($fields)));
            } catch (MeshClientException $e) {
                $this->assertStringContainsString('nothing was sent', $e->getMessage());
            }
        }
    }

    public function test_patch_refuses_an_empty_field_set(): void
    {
        $this->expectException(MeshClientException::class);

        $this->clientReturning([])->patchRule('rule-1', []);
    }

    public function test_patch_refuses_an_empty_rule_id(): void
    {
        $this->expectException(MeshClientException::class);

        $this->clientReturning([])->patchRule('   ', ['comment' => 'x']);
    }

    public function test_patch_reports_a_vendor_400_as_a_rejection_with_its_own_text(): void
    {
        $client = $this->clientReturning([
            new Response(400, [], json_encode(['comment' => ['String invalid']])),
        ]);

        try {
            $client->patchRule('rule-1', ['comment' => 'bad#comment']);
            $this->fail('a 400 must surface as a vendor rejection');
        } catch (MeshWriteRejectedException $e) {
            $this->assertStringContainsString('String invalid', $e->getMessage());
        }
    }
}
