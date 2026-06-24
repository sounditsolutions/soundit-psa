<?php

namespace App\Services\Technician\Notify;

use App\Support\TechnicianConfig;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Posts a notification card to the operator's configured Teams webhook URL
 * (incoming-webhook / Power Automate Workflow). App-only Graph chat-posting is a
 * Microsoft Protected API, so a webhook the operator provisions once is the
 * realistic PSA-native path. Fail-soft: a missing/failing webhook never throws.
 */
class TeamsNotifier
{
    public function post(string $title, string $body): bool
    {
        $url = TechnicianConfig::teamsWebhookUrl();
        if ($url === null) {
            return false;
        }

        try {
            $response = Http::timeout(10)->post($url, [
                '@type' => 'MessageCard',
                '@context' => 'https://schema.org/extensions',
                'summary' => $title,
                'themeColor' => '0F6CBD',
                'title' => $title,
                'text' => $body,
            ]);

            return $response->successful();
        } catch (\Throwable $e) {
            Log::warning('[Technician] Teams webhook post failed', ['error' => $e->getMessage()]);

            return false;
        }
    }
}
