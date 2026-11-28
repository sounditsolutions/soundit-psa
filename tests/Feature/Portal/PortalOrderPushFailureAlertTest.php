<?php

namespace Tests\Feature\Portal;

use App\Enums\InvoiceStatus;
use App\Enums\NotificationEventType;
use App\Enums\PersonType;
use App\Jobs\SendTicketNotification;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Person;
use App\Models\Setting;
use App\Models\Sku;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\Qbo\QboSyncService;
use App\Services\Stripe\StripeSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * A portal shop order whose billing push fails emails opted-in staff exactly
 * once through notifyInvoicePushFailed, flagged as a portal order, and the
 * order itself still completes. Synthetic data only; no vendor call is made.
 */
class PortalOrderPushFailureAlertTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        Setting::setValue('portal_enabled', '1');
        Setting::setValue('portal_shop_enabled', '1');
        User::factory()->create(['is_active' => true]);
    }

    private function order(array $clientAttrs = []): Invoice
    {
        $client = Client::create(array_merge(['name' => 'Example Widgets Co.'], $clientAttrs));
        $person = Person::create([
            'client_id' => $client->id,
            'person_type' => PersonType::User,
            'first_name' => 'Sam',
            'last_name' => 'Example',
            'email' => 'sam-'.uniqid().'@example.test',
            'is_active' => true,
            'portal_enabled' => true,
            'company_wide_access' => false,
        ]);
        $sku = Sku::create([
            'name' => 'Example Widget',
            'sku_code' => 'EX-WIDGET-'.uniqid(),
            'category' => 'Hardware',
            'unit_price' => 100.00,
            'unit_cost' => 60.00,
            'is_taxable' => true,
            'is_active' => true,
            'portal_orderable' => true,
        ]);

        $response = $this->actingAs($person, 'portal')->post(route('portal.shop.store'), [
            'quantities' => [$sku->id => 1],
            'expected_prices' => [$sku->id => '100.00'],
        ]);
        $invoice = Invoice::sole();
        $response->assertRedirect(route('portal.shop.confirmation', $invoice));
        $this->assertSame(InvoiceStatus::Posted, $invoice->status, 'the order still completes');

        return $invoice;
    }

    /** @return list<array<string, mixed>> context of every queued push-failed email */
    private function pushFailedAlerts(): array
    {
        return Queue::pushed(SendTicketNotification::class)
            ->filter(fn ($job) => (new \ReflectionProperty($job, 'eventType'))->getValue($job) === NotificationEventType::InvoicePushFailed->value)
            ->map(fn ($job) => json_decode((new \ReflectionProperty($job, 'extraContext'))->getValue($job), true))
            ->values()
            ->all();
    }

    public function test_a_stripe_push_exception_sends_exactly_one_portal_flagged_alert(): void
    {
        $this->mock(StripeSyncService::class, fn (MockInterface $m) => $m->shouldReceive('pushInvoiceToStripe')->once()->andThrow(new \RuntimeException('Synthetic Stripe refusal')));

        $invoice = $this->order(['stripe_customer_id' => 'cus_synthetic1']);

        $alerts = $this->pushFailedAlerts();
        $this->assertCount(1, $alerts);
        $this->assertSame($invoice->invoice_number, $alerts[0]['invoice_number']);
        $this->assertSame('Stripe', $alerts[0]['backend']);
        $this->assertSame('Synthetic Stripe refusal', $alerts[0]['error']);
        $this->assertTrue($alerts[0]['portal_order']);
    }

    public function test_a_qbo_push_exception_sends_exactly_one_alert(): void
    {
        $this->mock(QboSyncService::class, fn (MockInterface $m) => $m->shouldReceive('pushInvoiceToQbo')->once()->andThrow(new \RuntimeException('Synthetic QBO refusal')));

        $this->order(['qbo_customer_id' => 'qbo-synthetic-1']);

        $alerts = $this->pushFailedAlerts();
        $this->assertCount(1, $alerts);
        $this->assertSame('QBO', $alerts[0]['backend']);
        $this->assertTrue($alerts[0]['portal_order']);
    }

    public function test_an_unmapped_client_sends_exactly_one_alert_with_backend_none_and_logs_a_warning(): void
    {
        Log::spy();

        $this->order();

        $alerts = $this->pushFailedAlerts();
        $this->assertCount(1, $alerts);
        $this->assertSame('None', $alerts[0]['backend']);
        $this->assertStringContainsString('not mapped to QBO or Stripe', $alerts[0]['error']);
        $this->assertTrue($alerts[0]['portal_order']);
        Log::shouldHaveReceived('warning')->withArgs(fn ($msg) => str_starts_with($msg, '[PortalOrder] Client not mapped to any billing backend'))->once();
    }

    public function test_a_successful_push_sends_no_alert(): void
    {
        $this->mock(StripeSyncService::class, fn (MockInterface $m) => $m->shouldReceive('pushInvoiceToStripe')->once());

        $this->order(['stripe_customer_id' => 'cus_synthetic2']);

        $this->assertSame([], $this->pushFailedAlerts());
    }

    public function test_a_successful_qbo_push_sends_no_alert(): void
    {
        $this->mock(QboSyncService::class, fn (MockInterface $m) => $m->shouldReceive('pushInvoiceToQbo')->once());

        $this->order(['qbo_customer_id' => 'qbo-synthetic-2']);

        $this->assertSame([], $this->pushFailedAlerts());
    }

    public function test_a_notifier_throwing_an_error_not_an_exception_never_affects_the_order_and_logs_its_class(): void
    {
        Log::spy();
        $this->mock(StripeSyncService::class, fn (MockInterface $m) => $m->shouldReceive('pushInvoiceToStripe')->andThrow(new \RuntimeException('Synthetic Stripe refusal')));
        $this->partialMock(NotificationService::class, fn (MockInterface $m) => $m->shouldReceive('notifyInvoicePushFailed')->once()->andThrow(new \TypeError('Synthetic notifier type fault')));

        $this->order(['stripe_customer_id' => 'cus_synthetic4']);

        Log::shouldHaveReceived('warning')->withArgs(fn ($msg, $ctx = []) => $msg === '[PortalOrder] Invoice push-failure alert threw'
            && ($ctx['exception'] ?? null) === \TypeError::class
            && ($ctx['error'] ?? null) === 'Synthetic notifier type fault')->once();
    }

    public function test_a_throwing_notifier_never_affects_the_order_and_logs_a_warning(): void
    {
        Log::spy();
        $this->mock(StripeSyncService::class, fn (MockInterface $m) => $m->shouldReceive('pushInvoiceToStripe')->andThrow(new \RuntimeException('Synthetic Stripe refusal')));
        $this->partialMock(NotificationService::class, fn (MockInterface $m) => $m->shouldReceive('notifyInvoicePushFailed')->once()->andThrow(new \LogicException('Synthetic notifier fault')));

        $this->order(['stripe_customer_id' => 'cus_synthetic3']);

        Log::shouldHaveReceived('warning')->withArgs(fn ($msg, $ctx = []) => $msg === '[PortalOrder] Invoice push-failure alert threw'
            && ($ctx['exception'] ?? null) === \LogicException::class
            && ($ctx['error'] ?? null) === 'Synthetic notifier fault')->once();
    }

    public function test_a_throwing_notifier_on_the_unmapped_path_never_affects_the_order(): void
    {
        Log::spy();
        $this->partialMock(NotificationService::class, fn (MockInterface $m) => $m->shouldReceive('notifyInvoicePushFailed')->once()->andThrow(new \LogicException('Synthetic notifier fault')));

        $this->order();

        Log::shouldHaveReceived('warning')->withArgs(fn ($msg, $ctx = []) => $msg === '[PortalOrder] Invoice push-failure alert threw'
            && ($ctx['exception'] ?? null) === \LogicException::class
            && ($ctx['error'] ?? null) === 'Synthetic notifier fault')->once();
    }

    public function test_a_client_mapped_to_both_backends_is_pushed_to_stripe_and_alerts_with_backend_stripe(): void
    {
        $this->mock(StripeSyncService::class, fn (MockInterface $m) => $m->shouldReceive('pushInvoiceToStripe')->once()->andThrow(new \RuntimeException('Synthetic Stripe refusal')));
        $this->mock(QboSyncService::class, fn (MockInterface $m) => $m->shouldReceive('pushInvoiceToQbo')->never());

        $this->order(['stripe_customer_id' => 'cus_synthetic5', 'qbo_customer_id' => 'qbo-synthetic-5']);

        $alerts = $this->pushFailedAlerts();
        $this->assertCount(1, $alerts);
        $this->assertSame('Stripe', $alerts[0]['backend']);
        $this->assertSame('Synthetic Stripe refusal', $alerts[0]['error']);
    }
}
