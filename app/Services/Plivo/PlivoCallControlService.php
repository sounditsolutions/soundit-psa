<?php

namespace App\Services\Plivo;

use App\Support\PlivoConfig;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Live-call control for the browser softphone via the Plivo Voice API.
 *
 * The Plivo Browser SDK (v2) exposes mute/unmute but has no hold primitive, so
 * hold-with-music is implemented server-side with the Play API:
 *
 *   Hold   → POST   /Call/{uuid}/Play/   play looping music to the other leg
 *   Unhold → DELETE /Call/{uuid}/Play/   stop the audio and restore the bridge
 *
 * `legs` is interpreted relative to the posted call_uuid: `bleg` is always the
 * party bridged to the agent. The browser SDK always reports the agent's own leg
 * UUID (for both inbound and outbound calls), so targeting `bleg` reliably plays
 * hold music to the customer regardless of call direction. `mix=false` mutes the
 * live bridge audio for that leg while the music plays — the caller hears only
 * hold music — and `loop=true` keeps it playing until unhold.
 *
 * Fail-soft throughout: a network error or non-2xx response returns false rather
 * than throwing, so a failed hold degrades to "button did nothing" rather than a
 * 500 on the softphone.
 */
class PlivoCallControlService
{
    /**
     * Upper bound (seconds) on a single hold. Plivo stops the loop after this,
     * so an abandoned hold cannot play music forever. One hour is far longer
     * than any real hold; unhold normally stops the audio well before this.
     */
    private const PLAY_MAX_SECONDS = 3600;

    /**
     * Put the remote caller on hold by playing looping hold music to their leg.
     */
    public function hold(string $callUuid): bool
    {
        $musicUrl = PlivoConfig::holdMusicUrl();
        if ($musicUrl === '') {
            Log::warning('[Plivo] Hold requested but no hold music URL configured', [
                'call_uuid' => $callUuid,
            ]);

            return false;
        }

        return $this->send('post', $callUuid, [
            'urls' => $musicUrl,
            'legs' => 'bleg',
            'loop' => true,
            'mix' => false,
            'length' => self::PLAY_MAX_SECONDS,
        ]);
    }

    /**
     * Take the remote caller off hold by stopping the audio on their leg.
     */
    public function unhold(string $callUuid): bool
    {
        return $this->send('delete', $callUuid);
    }

    /**
     * @param  'post'|'delete'  $method
     * @param  array<string, mixed>  $payload
     */
    private function send(string $method, string $callUuid, array $payload = []): bool
    {
        if (! PlivoConfig::isConfigured()) {
            return false;
        }

        $authId = (string) PlivoConfig::get('auth_id');
        $authToken = (string) PlivoConfig::get('auth_token');
        $url = "https://api.plivo.com/v1/Account/{$authId}/Call/".rawurlencode($callUuid).'/Play/';

        try {
            $response = Http::withBasicAuth($authId, $authToken)
                ->timeout(10)
                ->{$method}($url, $payload);

            if (! $response->successful()) {
                Log::warning('[Plivo] Call control request failed', [
                    'action' => $method === 'post' ? 'hold' : 'unhold',
                    'call_uuid' => $callUuid,
                    'status' => $response->status(),
                ]);
            }

            return $response->successful();
        } catch (\Throwable $e) {
            Log::warning('[Plivo] Call control request threw', [
                'action' => $method === 'post' ? 'hold' : 'unhold',
                'call_uuid' => $callUuid,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
