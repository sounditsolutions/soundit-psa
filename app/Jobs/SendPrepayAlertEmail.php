<?php

namespace App\Jobs;

use App\Services\EmailService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendPrepayAlertEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 60;

    public function __construct(
        private string $recipientEmail,
        private string $recipientName,
        private string $alertType, // 'low_balance' or 'auto_topup'
        private string $contextJson,
    ) {}

    public function handle(EmailService $emailService): void
    {
        $ctx = json_decode($this->contextJson, true) ?? [];
        $subject = $this->buildSubject($ctx);
        $body = $this->buildBody($ctx);

        try {
            $emailService->sendNew(
                to: $this->recipientEmail,
                subject: $subject,
                body: $body,
            );
        } catch (\Throwable $e) {
            Log::warning('[PrepayAlert] Failed to send email', [
                'recipient' => $this->recipientEmail,
                'type' => $this->alertType,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function buildSubject(array $ctx): string
    {
        $contract = $ctx['contract'] ?? 'your contract';

        return match ($this->alertType) {
            'low_balance' => "Low prepaid balance on {$contract} — {$ctx['balance']}h remaining",
            'auto_topup' => "Prepaid time invoice generated — {$contract}",
            default => "Prepaid time update — {$contract}",
        };
    }

    private function buildBody(array $ctx): string
    {
        $lines = ["Hi {$this->recipientName},", ''];

        if ($this->alertType === 'low_balance') {
            $lines[] = "Your prepaid support hours on {$ctx['contract']} are running low.";
            $lines[] = '';
            $lines[] = "Current Balance: {$ctx['balance']}h";
            $lines[] = "Alert Threshold: {$ctx['threshold']}h";
            $lines[] = '';
            if ($ctx['contract_id'] ?? null) {
                $lines[] = 'You can purchase additional time in the client portal:';
                $lines[] = route('portal.contracts.show', $ctx['contract_id']);
            }
        } elseif ($this->alertType === 'auto_topup') {
            $lines[] = "A prepaid time invoice has been automatically generated for {$ctx['contract']}.";
            $lines[] = '';
            $lines[] = 'Hours: ' . ($ctx['hours'] ?? '?') . 'h';
            $lines[] = 'Amount: $' . number_format($ctx['amount'] ?? 0, 2);
            $lines[] = 'Invoice: #' . ($ctx['invoice_number'] ?? '?');
            $lines[] = '';
            $lines[] = 'Please pay this invoice to add the hours to your balance.';
        }

        $lines[] = '';
        $lines[] = 'Thank you,';
        $lines[] = \App\Support\PortalConfig::companyName();

        return implode("\n", $lines);
    }
}
