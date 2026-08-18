<?php

namespace Tests\Feature\AppRiver;

use App\Models\Setting;
use App\Services\AppRiver\AppRiverClient;
use App\Services\AppRiver\AppRiverClientException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Absent is not empty.
 *
 * getSubscriptions() fell through to $response when the Subscriptions key was
 * missing, handing the sync an envelope as if it were a subscription list.
 * getCustomers() fell through to [] and to TotalCount 0, so an unrecognised page
 * read as "this reseller has no customers" — and the mapping page's save treats
 * its submission as the complete world, clearing every mapping and permanently
 * zeroing the licences of anyone who was not rendered.
 *
 * Both now raise rather than return a list of none.
 */
class AppRiverListEnvelopeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Requests the mocked client actually sent, in order — Guzzle history entries
     * with a PSR-7 request under 'request'. The probe and pagination tests assert
     * on the query string here because the defect they pin was in the PARAMS we
     * sent (limit=1, locally computed offset), not in how we read the body.
     */
    private array $requestHistory = [];

    private function clientReturning(string ...$jsons): AppRiverClient
    {
        Setting::setEncrypted('appriver_access_token', 'live-token');
        Setting::setValue('appriver_token_expires_at', now()->addHour()->toDateTimeString());

        $stack = HandlerStack::create(new MockHandler(
            array_map(static fn (string $json) => new Response(200, [], $json), $jsons),
        ));
        $this->requestHistory = [];
        $stack->push(Middleware::history($this->requestHistory));

        return new AppRiverClient([
            'base_url' => 'https://appriver.test',
            'handler' => $stack,
        ]);
    }

    public function test_an_unrecognised_subscription_envelope_raises(): void
    {
        $this->expectException(AppRiverClientException::class);
        $this->expectExceptionMessageMatches('/no Subscriptions array/');

        $this->clientReturning('{"Message":"Something went sideways"}')
            ->getSubscriptions('customer-1');
    }

    public function test_a_present_but_empty_subscription_list_is_still_empty(): void
    {
        $this->assertSame(
            [],
            $this->clientReturning('{"Subscriptions":[]}')->getSubscriptions('customer-1'),
            'A genuinely empty list must still read as empty — the fix distinguishes absent from empty, it does not reject empty.'
        );
    }

    public function test_a_bare_list_response_is_still_accepted(): void
    {
        $this->assertCount(
            1,
            $this->clientReturning('[{"SubscriptionKey":"sub-1"}]')->getSubscriptions('customer-1'),
            'The bare-list shape is the other form this endpoint returns and must keep working.'
        );
    }

    /**
     * `{}` decodes to `[]` in PHP and array_is_list([]) is true, so the bare-list
     * branch used to accept an unrecognised empty envelope as "no subscriptions" —
     * which is the data-loss path this class exists to close: the client reads as
     * fully observed and stale cleanup zeroes every one of its licences.
     */
    public function test_an_empty_object_envelope_is_not_no_subscriptions(): void
    {
        $this->expectException(AppRiverClientException::class);
        $this->expectExceptionMessageMatches('/empty or unparseable/');

        $this->clientReturning('{}')->getSubscriptions('customer-1');
    }

    public function test_an_empty_body_is_not_no_subscriptions(): void
    {
        $this->expectException(AppRiverClientException::class);
        $this->expectExceptionMessageMatches('/empty or unparseable/');

        $this->clientReturning('')->getSubscriptions('customer-1');
    }

    public function test_an_unparseable_body_is_not_no_subscriptions(): void
    {
        $this->expectException(AppRiverClientException::class);
        $this->expectExceptionMessageMatches('/empty or unparseable/');

        $this->clientReturning('<html>502 Bad Gateway</html>')->getSubscriptions('customer-1');
    }

    /**
     * A bare `[]` is a list of none — the ordinary answer for a customer whose
     * subscriptions have all been cancelled — and it must read as empty. Refusing it
     * would exclude that client from stale cleanup on every run forever, leaving its
     * cancelled licences billing at their old quantity, and exit the nightly command
     * FAILURE with no operator remedy. It is distinguishable from `{}` / an empty body
     * / garbage at the JSON level (which the three tests above still pin as raising),
     * and that is where getSubscriptions() draws the line.
     */
    public function test_a_bare_empty_list_is_an_empty_subscription_list(): void
    {
        $this->assertSame(
            [],
            $this->clientReturning('[]')->getSubscriptions('customer-1'),
            'A customer with no subscriptions must sync cleanly so its cancelled licences are cleaned up.'
        );
    }

    /**
     * A valid-JSON scalar decodes to neither an object nor a list. getSubscriptionDetail()
     * has no envelope check, so returning [] there would write quantity 0 over a live
     * licence with nothing counted unobserved and the run still exiting SUCCESS — the same
     * zeroed-for-want-of-an-observation outcome the envelope guards exist to prevent.
     */
    public function test_a_json_scalar_body_is_not_a_readable_response(): void
    {
        $this->expectException(AppRiverClientException::class);
        $this->expectExceptionMessageMatches('/JSON scalar/');

        $this->clientReturning('"Subscription not found"')
            ->getSubscriptionDetail('customer-1', 'sub-1');
    }

    public function test_a_customer_page_without_a_customers_array_raises(): void
    {
        $this->expectException(AppRiverClientException::class);
        $this->expectExceptionMessageMatches('/no Customers array/');

        $this->clientReturning('{"Message":"Something went sideways"}')->getCustomers();
    }

    public function test_a_customer_page_without_a_total_raises(): void
    {
        $this->expectException(AppRiverClientException::class);
        $this->expectExceptionMessageMatches('/no numeric Meta\.Page\.Total/');

        $this->clientReturning('{"Customers":[{"Id":"c1"}]}')->getCustomers();
    }

    /**
     * `TotalCount` existed only in our own fixtures — the live API never sent it
     * (captured from prod 2026-08-18: top-level keys are exactly Customers, Links,
     * Meta, with the total at Meta.Page.Total). An envelope carrying only the
     * fixture-era key must refuse like any other unrecognised envelope, so the
     * invented field can never quietly come back as an accepted shape.
     */
    public function test_the_fixture_era_total_count_is_not_a_total(): void
    {
        $this->expectException(AppRiverClientException::class);
        $this->expectExceptionMessageMatches('/no numeric Meta\.Page\.Total/');

        $this->clientReturning('{"Customers":[{"Id":"c1"}],"TotalCount":1}')->getCustomers();
    }

    public function test_the_live_customer_envelope_parses(): void
    {
        $this->assertSame(
            [['Id' => 'c1'], ['Id' => 'c2']],
            $this->clientReturning(
                '{"Customers":[{"Id":"c1"},{"Id":"c2"}],"Links":{"NextPageLimit":100,"NextPageOffset":100},"Meta":{"Page":{"Total":2}}}'
            )->getCustomers()
        );
    }

    public function test_a_genuinely_empty_customer_page_is_still_empty(): void
    {
        $this->assertSame(
            [],
            $this->clientReturning('{"Customers":[],"Links":{},"Meta":{"Page":{"Total":0}}}')->getCustomers()
        );
    }

    public function test_pagination_follows_links_next_page_offset(): void
    {
        $client = $this->clientReturning(
            '{"Customers":[{"Id":"c1"}],"Links":{"NextPageLimit":1,"NextPageOffset":7},"Meta":{"Page":{"Total":2}}}',
            '{"Customers":[{"Id":"c2"}],"Links":{},"Meta":{"Page":{"Total":2}}}'
        );

        $this->assertSame([['Id' => 'c1'], ['Id' => 'c2']], $client->getCustomers());

        parse_str($this->requestHistory[1]['request']->getUri()->getQuery(), $secondPageQuery);
        $this->assertSame('7', $secondPageQuery['offset'], 'The second page must be requested at Links.NextPageOffset, not a locally computed offset.');
        $this->assertSame('1', $secondPageQuery['limit'], 'The second page must honour Links.NextPageLimit.');
    }

    public function test_more_pages_owed_without_a_next_offset_raises(): void
    {
        $this->expectException(AppRiverClientException::class);
        $this->expectExceptionMessageMatches('/no numeric Links\.NextPageOffset/');

        $this->clientReturning('{"Customers":[{"Id":"c1"}],"Links":{},"Meta":{"Page":{"Total":5}}}')->getCustomers();
    }

    public function test_a_non_advancing_next_offset_raises_rather_than_loops(): void
    {
        $this->expectException(AppRiverClientException::class);
        $this->expectExceptionMessageMatches('/does not advance/');

        $this->clientReturning(
            '{"Customers":[{"Id":"c1"}],"Links":{"NextPageLimit":100,"NextPageOffset":0},"Meta":{"Page":{"Total":5}}}'
        )->getCustomers();
    }

    public function test_an_empty_page_while_more_are_owed_raises(): void
    {
        $this->expectException(AppRiverClientException::class);
        $this->expectExceptionMessageMatches('/empty page while Meta\.Page\.Total/');

        $this->clientReturning(
            '{"Customers":[],"Links":{"NextPageLimit":100,"NextPageOffset":100},"Meta":{"Page":{"Total":5}}}'
        )->getCustomers();
    }

    /**
     * AppRiver rejects small pages outright — measured against the live API
     * 2026-08-18: limit=1 and limit=5 → "The request is invalid", limit=25 → 200.
     * A probe sending a rejected page size fails on a perfectly healthy token, and
     * Settings > Integrations renders that failure as a credential problem.
     */
    public function test_the_health_probe_sends_a_page_size_the_vendor_accepts(): void
    {
        $client = $this->clientReturning('{"Customers":[],"Links":{},"Meta":{"Page":{"Total":0}}}');

        $this->assertTrue($client->isHealthy());

        parse_str($this->requestHistory[0]['request']->getUri()->getQuery(), $query);
        $this->assertSame((string) AppRiverClient::PROBE_PAGE_SIZE, $query['limit']);
        $this->assertGreaterThanOrEqual(25, (int) $query['limit'], 'The smallest page size measured as accepted is 25; anything below is unproven against the live API.');
    }
}
