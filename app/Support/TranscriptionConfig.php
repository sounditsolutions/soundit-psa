<?php

namespace App\Support;

use App\Models\Setting;

class TranscriptionConfig
{
    /**
     * Get the OpenAI API key for Whisper.
     * Falls back to the main AI key if the provider is OpenAI.
     */
    public static function whisperApiKey(): ?string
    {
        // Dedicated Whisper key takes priority
        $dedicated = Setting::getEncrypted('openai_api_key');
        if ($dedicated) {
            return $dedicated;
        }

        // Fall back to the main AI key if provider is OpenAI
        if (AiConfig::provider() === 'openai') {
            return AiConfig::get('api_key');
        }

        return null;
    }

    /**
     * Whether Whisper transcription is configured (has an OpenAI API key).
     */
    public static function isConfigured(): bool
    {
        return (bool) static::whisperApiKey();
    }

    /**
     * Whether auto-transcribe is enabled for incoming calls with recordings.
     */
    public static function autoTranscribeEnabled(): bool
    {
        return (bool) Setting::getValue('auto_transcribe_calls');
    }

    /**
     * Minimum call duration in seconds for auto-transcription.
     * Calls shorter than this are skipped when auto-transcribing.
     */
    public static function minDurationSeconds(): int
    {
        return (int) (Setting::getValue('auto_transcribe_min_seconds') ?? 30);
    }
}
