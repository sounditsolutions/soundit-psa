<?php

namespace Tests\Feature\Billing;

use App\Enums\AutoPushMode;
use App\Enums\InvoiceStatus;
use App\Jobs\PushInvoiceToBilling;
use App\Jobs\SendTicketNotification;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\RecurringInvoiceProfile;
use App\Models\Setting;
use App\Models\User;
use App\Services\EmailService;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * The "Invoice push failed" staff email renders its own subject and body,
 * through the real SendTicketNotification::handle(), instead of falling to the
 * blank ticket default. Synthetic data only; the mail send is captured.
 */
class InvoicePushFailedEmailTest extends TestCase
{
    use RefreshDatabase;

    private const PORTAL_LINE = 'A client ordered this in the portal and is waiting for a pay link. Push the invoice from its page, or send the client the pay link.';

    /** @var list<array{subject: string, body: string}> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake();
        Queue::fake();
        Setting::setValue('graph_mailbox', 'alerts@example.test');
        User::factory()->create(['is_active' => true]);

        $this->mock(EmailService::class, function (MockInterface $m): void {
            $m->shouldReceive('sendNew')->andReturnUsing(function (string $to, string $subject, string $body) {
                $this->sent[] = ['subject' => $subject, 'body' => $body];

                return new \App\Models\Email;
            });
        });
    }

    private function invoice(string $clientName = 'Example Widgets Co.', array $clientAttrs = []): Invoice
    {
        $client = Client::create(array_merge(['name' => $clientName], $clientAttrs));

        return Invoice::create([
            'client_id' => $client->id,
            'invoice_number' => 'EX-2002',
            'invoice_date' => '2026-11-01',
            'due_date' => '2026-11-01',
            'status' => InvoiceStatus::Posted,
        ]);
    }

    /** Run every queued notification through its real handle(). */
    private function deliver(): void
    {
        foreach (Queue::pushed(SendTicketNotification::class) as $job) {
            app()->call([$job, 'handle']);
        }
    }

    private function only(): array
    {
        $this->assertCount(1, $this->sent, 'exactly one email');

        return $this->sent[0];
    }

    public function test_portal_order_email_has_the_exact_subject_the_context_the_staff_link_and_the_portal_line(): void
    {
        $invoice = $this->invoice();
        app(NotificationService::class)->notifyInvoicePushFailed($invoice, 'QBO', 'Synthetic QBO refusal', true);
        $this->deliver();

        $mail = $this->only();
        $this->assertSame('Invoice push failed: Example Widgets Co. - Invoice #EX-2002 (QBO)', $mail['subject']);
        $this->assertStringContainsString('Invoice: #EX-2002', $mail['body']);
        $this->assertStringContainsString('Client: Example Widgets Co.', $mail['body']);
        $this->assertStringContainsString('Backend: QBO', $mail['body']);
        $this->assertStringContainsString('Error: Synthetic QBO refusal', $mail['body']);
        $this->assertStringContainsString(route('invoices.show', $invoice), $mail['body']);
        $this->assertStringContainsString(self::PORTAL_LINE, $mail['body']);
    }

    public function test_without_the_flag_the_portal_line_is_absent(): void
    {
        $invoice = $this->invoice();
        app(NotificationService::class)->notifyInvoicePushFailed($invoice, 'Stripe', 'Synthetic Stripe refusal');
        $this->deliver();

        $mail = $this->only();
        $this->assertSame('Invoice push failed: Example Widgets Co. - Invoice #EX-2002 (Stripe)', $mail['subject']);
        $this->assertStringContainsString('Error: Synthetic Stripe refusal', $mail['body']);
        $this->assertStringNotContainsString('waiting for a pay link', $mail['body']);
    }

    public function test_the_auto_push_job_email_is_no_longer_the_blank_default(): void
    {
        $client = Client::create(['name' => 'Example Unmapped Co.']);
        $contract = Contract::create([
            'client_id' => $client->id,
            'name' => 'Example Managed Services',
            'type' => 'managed',
            'status' => 'active',
            'start_date' => '2026-01-01',
        ]);
        $profile = RecurringInvoiceProfile::create([
            'contract_id' => $contract->id,
            'name' => 'Monthly managed',
            'is_active' => true,
            'billing_period' => 'monthly',
            'billing_day' => 1,
            'payment_terms_days' => 30,
            'next_run_date' => '2026-12-01',
            'auto_push_mode' => AutoPushMode::Push,
        ]);
        $invoice = Invoice::create([
            'client_id' => $client->id,
            'profile_id' => $profile->id,
            'invoice_number' => 'EX-3003',
            'invoice_date' => '2026-11-01',
            'due_date' => '2026-12-01',
            'status' => InvoiceStatus::Draft,
        ]);

        app()->call([new PushInvoiceToBilling($invoice), 'handle']);
        $this->deliver();

        $mail = $this->only();
        $this->assertSame('Invoice push failed: Example Unmapped Co. - Invoice #EX-3003 (None)', $mail['subject']);
        $this->assertStringNotContainsString('Update on:', $mail['subject']);
        $this->assertStringNotContainsString('updated this ticket', $mail['body']);
        $this->assertStringContainsString('Error: Client "Example Unmapped Co." is not mapped to QBO or Stripe.', $mail['body']);
        $this->assertStringContainsString(route('invoices.show', $invoice), $mail['body']);
        $this->assertStringNotContainsString('waiting for a pay link', $mail['body']);
    }

    public function test_context_values_are_escaped_for_the_html_body(): void
    {
        $invoice = $this->invoice('Example <b>Bold</b> & Co.');
        app(NotificationService::class)->notifyInvoicePushFailed($invoice, 'QBO', "<script>x</script> [link](https://example.test)\nsecond line", true);
        $this->deliver();

        $mail = $this->only();
        $this->assertStringContainsString('Client: Example &lt;b&gt;Bold&lt;/b&gt; &amp; Co.', $mail['body']);
        $this->assertStringContainsString('Error: &lt;script&gt;x&lt;/script&gt; \[link\](https://example.test) second line', $mail['body']);
        $this->assertStringNotContainsString('<script>', $mail['body']);
    }
}
