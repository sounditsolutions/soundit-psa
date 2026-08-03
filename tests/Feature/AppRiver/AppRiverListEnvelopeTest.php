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
