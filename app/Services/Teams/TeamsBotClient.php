<?php

namespace App\Services\Teams;

use App\Support\TeamsBotConfig;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Outbound Teams send (E2a). Replies to a conversation by POSTing an activity to
 * the channel's serviceUrl, authenticated with a Bot Framework token acquired via
 * the bot's single-tenant authority (client-credentials, scope api.botframework.com).
 *
 * THE SECURITY CRUX (E1 reviewer's note): a reply is sent ONLY to a TRUSTED Bot
 * Framework channel serviceUrl host, FAIL-CLOSED. The serviceUrl in an inbound
 * activity is attacker-influenceable, so we refuse any host that is not the real
 * channel — BEFORE acquiring a token or making any request. (The controller adds a
 * second guard: it pins the serviceUrl to the validated JWT serviceUrl claim.)
 */
class TeamsBotClient
{
    private const TOKEN_CACHE_KEY = 'teams_bot_bf_token';

    private const TOKEN_SCOPE = 'https://api.botframework.com/.default';

    /** Trusted Bot Framework channel host suffixes (exact host, or a *.suffix subdomain). */
    private const TRUSTED_HOST_SUFFIXES = ['botframework.com', 'smba.trafficmanager.net'];

    public function isTrustedServiceUrl(?string $url): bool
    {
        if (! is_string($url) || $url === '') {
            return false;
        }

        $parts = parse_url($url);
        if (($parts['scheme'] ?? '') !== 'https') {
            return false;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '') {
            return false;
        }

        foreach (self::TRUSTED_HOST_SUFFIXES as $suffix) {
            // Exact host, or a real subdomain (dot-boundary) — never a lookalike suffix.
            if ($host === $suffix || str_ends_with($host, '.'.$suffix)) {
                return true;
            }
        }

        return false;
    }

    public function sendMessage(string $serviceUrl, string $conversationId, string $text): bool
    {
        return $this->sendActivity($serviceUrl, $conversationId, ['type' => 'message', 'text' => $text]);
    }

    /**
     * Best-effort GET of a specific member from a conversation (Bot Framework
     * `GET /v3/conversations/{conversationId}/members/{aadObjectId}`).
     *
     * Returns the member array (containing `id` = the conversation-scoped mention
     * id, and `name`) or null on any failure. Fail-soft: never throws.
     *
     * Reuses the same fail-closed trusted-serviceUrl guard as sendActivity — the
     * Bot Framework API host is validated before any token is acquired.
     */
    public function getConversationMember(string $serviceUrl, string $conversationId, string $aadObjectId): ?array
    {
        if (! $this->isTrustedServiceUrl($serviceUrl)) {
            Log::warning('[Teams Bot] Refusing getConversationMember for untrusted serviceUrl', ['service_url' => $serviceUrl]);

            return null;
        }

        $token = $this->token();
        if ($token === null) {
            return null;
        }

        try {
            $url = rtrim($serviceUrl, '/').'/v3/conversations/'.rawurlencode($conversationId).'/members/'.rawurlencode($aadObjectId);
            $response = Http::withToken($token)->get($url);

            if (! $response->successful()) {
                Log::info('[Teams Bot] getConversationMember non-2xx', [
                    'status' => $response->status(),
                    'conversation_id' => $conversationId,
                ]);

                return null;
            }

            return $response->json();
        } catch (\Throwable $e) {
            Log::warning('[Teams Bot] getConversationMember failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Post a message to a conversation, optionally with @mention entities pre-built
     * by the caller. Each element of `$mentions` must have:
     *   - `mentionId`: the conversation-scoped member id (e.g. `29:…`)
     *   - `name`: the display name rendered as `<at>name</at>` in `$text`
     *
     * When `$mentions` is empty, behaves exactly like `sendMessage` (plain text).
     * Reuses the fail-closed trusted-serviceUrl guard and token path via sendActivity.
     *
     * @param  array<int, array{mentionId: string, name: string}>  $mentions
     */
    public function sendMessageWithMentions(string $serviceUrl, string $conversationId, string $text, array $mentions): bool
    {
        $activity = ['type' => 'message', 'text' => $text];

        if (! empty($mentions)) {
            $entities = [];
            foreach ($mentions as $m) {
                $entities[] = [
                    'type' => 'mention',
                    'mentioned' => ['id' => $m['mentionId'], 'name' => $m['name']],
                    'text' => '<at>'.$m['name'].'</at>',
                ];
            }
            $activity['entities'] = $entities;
        }

        return $this->sendActivity($serviceUrl, $conversationId, $activity);
    }

    public function sendTyping(string $serviceUrl, string $conversationId): void
    {
        // Best-effort: a failed typing indicator must never break the actual reply.
        try {
            $this->sendActivity($serviceUrl, $conversationId, ['type' => 'typing']);
        } catch (\Throwable $e) {
            Log::info('[Teams Bot] Typing indicator failed (non-fatal)', ['error' => $e->getMessage()]);
        }
    }

    private function sendActivity(string $serviceUrl, string $conversationId, array $activity): bool
    {
        // FAIL-CLOSED, before any network call: never send to an untrusted serviceUrl.
        if (! $this->isTrustedServiceUrl($serviceUrl)) {
            Log::warning('[Teams Bot] Refusing to send to an untrusted serviceUrl', ['service_url' => $serviceUrl]);

            return false;
        }

        $token = $this->token();
        if ($token === null) {
            return false;
        }

        $url = rtrim($serviceUrl, '/').'/v3/conversations/'.rawurlencode($conversationId).'/activities';
        $response = Http::withToken($token)->asJson()->post($url, $activity);

        return $response->successful();
    }

    /**
     * Acquire (and cache) a Bot Framework token via the bot's SINGLE-TENANT authority
     * (AppType=SingleTenant). Mirrors GraphClient::getToken — cached for expires_in − 60s.
     */
    private function token(): ?string
    {
        if (! TeamsBotConfig::configured()) {
            return null;
        }

        // Key the cache per bot App ID so a second MSP's bot (multi-tenant product)
        // can never be served another's token.
        $cacheKey = self::TOKEN_CACHE_KEY.':'.TeamsBotConfig::appId();

        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $response = Http::asForm()->post(
            'https://login.microsoftonline.com/'.TeamsBotConfig::tenantId().'/oauth2/v2.0/token',
            [
                'grant_type' => 'client_credentials',
                'client_id' => TeamsBotConfig::appId(),
                'client_secret' => TeamsBotConfig::clientSecret(),
                'scope' => self::TOKEN_SCOPE,
            ],
        );

        if (! $response->successful()) {
            Log::warning('[Teams Bot] Bot Framework token request failed', ['status' => $response->status()]);

            return null;
        }

        $token = $response->json('access_token');
        if (! is_string($token) || $token === '') {
            return null;
        }

        $ttl = max(60, (int) ($response->json('expires_in') ?? 3600) - 60);
        Cache::put($cacheKey, $token, $ttl);

        return $token;
    }
}
