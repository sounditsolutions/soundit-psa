<?php

namespace App\Services\Graph;

use App\Models\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class GraphWebhookManager
{
    private const SUBSCRIPTION_LIFETIME_MINUTES = 4230; // Graph max for mail
    private const RENEWAL_BUFFER_HOURS = 24;

    public function __construct(
        private readonly GraphClient $graphClient,
    ) {}

    /**
     * Ensure an active webhook subscription exists.
     * Creates a new one if missing, renews if expiring within 24 hours.
     */
    public function ensureSubscription(): void
    {
        $subscriptionId = Setting::getValue('graph_subscription_id');
        $expiry = Setting::getValue('graph_subscription_expiry');

        if (!$subscriptionId || !$expiry) {
            $this->createSubscription();
            return;
        }

        $expiresAt = Carbon::parse($expiry);

        if ($expiresAt->diffInHours(now(), true) <= self::RENEWAL_BUFFER_HOURS || $expiresAt->isPast()) {
            try {
                $this->renewSubscription($subscriptionId);
            } catch (GraphClientException $e) {
                Log::warning('[GraphWebhook] Renewal failed, creating new subscription', [
                    'error' => $e->getMessage(),
                ]);
                $this->createSubscription();
            }
        }
    }

    /**
     * Create a new webhook subscription for inbox messages.
     */
    public function createSubscription(): array
    {
        $mailbox = Setting::getValue('graph_mailbox');
        if (!$mailbox) {
            throw new GraphClientException('No mailbox configured (graph_mailbox setting is empty).');
        }

        $clientState = Str::random(40);
        $expiresAt = now()->addMinutes(self::SUBSCRIPTION_LIFETIME_MINUTES)->toIso8601String();
        $notificationUrl = rtrim(config('app.url'), '/') . '/api/webhooks/graph/mail';

        try {
            $subscription = $this->graphClient->post('subscriptions', [
                'changeType'         => 'created',
                'notificationUrl'    => $notificationUrl,
                'resource'           => "users/{$mailbox}/mailFolders('inbox')/messages",
                'expirationDateTime' => $expiresAt,
                'clientState'        => $clientState,
            ]);
        } catch (GraphClientException $e) {
            Log::error('[GraphWebhook] Failed to create subscription', [
                'mailbox' => $mailbox,
                'error'   => $e->getMessage(),
            ]);
            throw $e;
        }

        Setting::setValue('graph_subscription_id', $subscription['id']);
        Setting::setValue('graph_subscription_expiry', $subscription['expirationDateTime']);
        Setting::setValue('graph_webhook_client_state', $clientState);

        Log::info('[GraphWebhook] Subscription created', [
            'id'      => $subscription['id'],
            'expires' => $subscription['expirationDateTime'],
        ]);

        return $subscription;
    }

    /**
     * Renew an existing subscription.
     */
    public function renewSubscription(string $subscriptionId): array
    {
        $expiresAt = now()->addMinutes(self::SUBSCRIPTION_LIFETIME_MINUTES)->toIso8601String();

        try {
            $subscription = $this->graphClient->patch("subscriptions/{$subscriptionId}", [
                'expirationDateTime' => $expiresAt,
            ]);
        } catch (GraphClientException $e) {
            Log::error('[GraphWebhook] Failed to renew subscription', [
                'subscription_id' => $subscriptionId,
                'error'           => $e->getMessage(),
            ]);
            throw $e;
        }

        Setting::setValue('graph_subscription_expiry', $subscription['expirationDateTime']);

        Log::info('[GraphWebhook] Subscription renewed', [
            'id'      => $subscriptionId,
            'expires' => $subscription['expirationDateTime'],
        ]);

        return $subscription;
    }

    /**
     * Delete the current subscription.
     */
    public function deleteSubscription(): void
    {
        $subscriptionId = Setting::getValue('graph_subscription_id');
        if (!$subscriptionId) {
            return;
        }

        try {
            $this->graphClient->delete("subscriptions/{$subscriptionId}");
        } catch (GraphClientException $e) {
            Log::warning('[GraphWebhook] Failed to delete subscription', [
                'subscription_id' => $subscriptionId,
                'error'           => $e->getMessage(),
            ]);
        }

        Setting::setValue('graph_subscription_id', null);
        Setting::setValue('graph_subscription_expiry', null);
        Setting::setValue('graph_webhook_client_state', null);

        Log::info('[GraphWebhook] Subscription deleted', ['id' => $subscriptionId]);
    }
}
