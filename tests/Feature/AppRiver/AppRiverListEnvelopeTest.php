<?php

namespace Tests\Feature\AppRiver;

use App\Services\AppRiver\AppRiverClient;
use App\Services\AppRiver\AppRiverClientException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\Setting;
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

    private function clientReturning(string $json): AppRiverClient
    {
        Setting::setEncrypted('appriver_access_token', 'live-token');
        Setting::setValue('appriver_token_expires_at', now()->addHour()->toDateTimeString());

        return new AppRiverClient([
            'base_url' => 'https://appriver.test',
            'handler' => HandlerStack::create(new MockHandler([
                new Response(200, [], $json),
            ])),
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

    public function test_a_customer_page_without_a_customers_array_raises(): void
    {
        $this->expectException(AppRiverClientException::class);
        $this->expectExceptionMessageMatches('/no Customers array/');

        $this->clientReturning('{"Message":"Something went sideways"}')->getCustomers();
    }

    public function test_a_customer_page_without_a_total_count_raises(): void
    {
        $this->expectException(AppRiverClientException::class);
        $this->expectExceptionMessageMatches('/no TotalCount/');

        $this->clientReturning('{"Customers":[{"Id":"c1"}]}')->getCustomers();
    }

    public function test_a_genuinely_empty_customer_page_is_still_empty(): void
    {
        $this->assertSame(
            [],
            $this->clientReturning('{"Customers":[],"TotalCount":0}')->getCustomers()
        );
    }
}
