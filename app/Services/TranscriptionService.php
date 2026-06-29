<?php

namespace App\Services;

use App\Enums\CallStatus;
use App\Enums\ChargeClassification;
use App\Enums\TranscriptionStatus;
use App\Models\PhoneCall;
use App\Support\AiConfig;
use App\Support\PlivoConfig;
use App\Support\TranscriptionConfig;
use GuzzleHttp\Client as GuzzleClient;
use Illuminate\Support\Facades\Log;

class TranscriptionService
{
    /** Whisper API file size limit. */
    private const MAX_WHISPER_SIZE = 25 * 1024 * 1024;

    /** Duration per chunk when splitting large files (seconds). */
    private const CHUNK_DURATION = 600; // 10 minutes

    /**
     * Ported from HaloClaude mcp_server/prompts.py — CALL_TRANSCRIPTION_PROMPT.
     * Public so the always-clean contract can be asserted in tests.
     */
    public const CALL_TRANSCRIPTION_PROMPT = <<<'PROMPT'
# Identity
You are a call analysis assistant specialized in analyzing technical IT support calls for a managed service provider (MSP). These calls involve desktop/laptop troubleshooting, Microsoft 365 administration, networking, software and hardware errors, and support ticket escalations.

Your responsibilities:
- Analyze the raw transcript for key information, action items, and sentiment
- Identify and summarize troubleshooting steps, configuration changes, and diagnostic commands
- Extract ticket numbers, customer names, and action items
- Highlight sentiment shifts, urgency indicators, and escalation triggers

You are concise, neutral in tone, and avoid speculation. You do not generate fictional content or hallucinate missing information.

# Instructions
Analyze the provided call transcript and return a structured markdown-formatted report:

## Call Summary
- A clear summary of the call's purpose, outcome, and resolution (up to ~300 words)
- Include key technical topics, troubleshooting steps, ticket references, and configuration changes

## Caller Identity
- Identify the CALLER — the customer/end-user on the call seeking support, NOT the MSP technician.
- Determine their identity from what is actually said on the call: self-identification ("this is John from
  Acme"), how other speakers address them, an email/company mentioned, or clear context. Do NOT just repeat the
  expected participant from the call context — report who the transcript shows actually called.
- Name: <caller's full name as stated, or "Unknown">
- Company: <caller's company/organization as stated, or "Unknown">
- Confidence: <a number 0.0-1.0 — how certain the transcript makes this identification; 1.0 = explicit
  self-identification, 0.0 = no identifying information at all>
- Signals: <one brief line on what identified them, or "none">

## Sentiment Score
- Score from 1-10 (1 = extremely negative, 10 = extremely positive)
- Consider tone, cooperation, frustration, and emotional cues as inferred from the text
- Brief explanation citing observed behaviors
- Omit this section if insufficient data

## Next Steps
- Bullet list of follow-up action items
- Include who is responsible, what action, and when (if mentioned)
- Omit this section if no action items

## Charge Classification
- Classify this call as one of the following:
  - **Billable** — Active technical support, troubleshooting, configuration, or hands-on work that goes beyond what's included in a standard managed services contract
  - **No Charge** — Sales/discovery call, referral, scheduling, pre-support consultation, voicemail, brief check-in with no technical work, wrong number/misdial, or a call about a managed service that would be covered under their existing agreement (e.g. routine monitoring questions, account inquiries, service status updates)
- Write EXACTLY one of: `Billable` or `No Charge`
- Brief one-line justification

## Coaching
- Talk-to-listen ratio imbalances (based on relative amount of text per speaker)
- Missed empathy or escalation opportunities
- Clarity of technical explanations
- If unresolved, suggest other troubleshooting avenues

## Transcription
- Always include the full cleaned transcript, regardless of call length
- Clean up the raw transcript for readability
- On first mention include role: Name (Agent):, Name (Customer):
- Preserve the original wording as closely as possible
PROMPT;

    /**
     * Speaker context template (appended when call metadata is available).
     * Ported from HaloClaude mcp_server/prompts.py — SPEAKER_CONTEXT_TEMPLATE.
     */
    private const SPEAKER_CONTEXT_TEMPLATE = <<<'TEMPLATE'

# Call Context
%s
# Speaker Identification
The following are the expected participants on this call based on the ticket and phone system records. Note that the actual speakers may differ (e.g. a different technician may have taken the call, or a colleague may be on the line instead of the ticket's end-user).

%s
Use these as a starting point, but determine actual speaker identity from context clues: self-identification, name references, and conversational role (providing IT support vs. receiving help). Label each speaker consistently throughout. If additional speakers are present, identify them by first name with role if discernible.
TEMPLATE;

    /**
     * Speaker context template for pre-diarized transcripts (stereo channel split).
     * Instructs the AI to trust the existing speaker labels.
     */
    private const DIARIZED_SPEAKER_CONTEXT_TEMPLATE = <<<'TEMPLATE'

# Call Context
%s
# Speaker Identification
This transcript has been pre-diarized using stereo audio channel separation. Each line is labeled "Name: text" based on the phone system's channel mapping and call metadata. These labels are reliable.

Expected participants:
%s
IMPORTANT: Use the speaker labels exactly as they appear in the transcript. Do NOT re-assign or swap speakers. The labels are determined by audio channel separation, not AI inference. In the cleaned-up Transcription section, preserve these speaker labels as-is.
TEMPLATE;

    /**
     * Transcribe a phone call recording: Whisper STT → AI analysis → save.
     *
     * If ffmpeg is available and the recording is stereo, splits channels for
     * speaker-separated transcription. Falls back to mono on any failure.
     */
    public function transcribe(PhoneCall $call): void
    {
        // Guard: skip if already transcribed or no recording
        if ($call->isTranscribed() || ! $call->recording_url) {
            return;
        }

        $apiKey = TranscriptionConfig::whisperApiKey();
        if (! $apiKey) {
            throw new \RuntimeException('OpenAI API key not configured for Whisper transcription');
        }

        $call->update(['transcription_status' => TranscriptionStatus::Processing]);

        $tempFiles = [];

        try {
            // 1. Download recording to temp file
            $tempFile = $this->downloadRecording($call->recording_url);
            $tempFiles[] = $tempFile;
            $fileSize = filesize($tempFile);

            $needsChunking = $fileSize > self::MAX_WHISPER_SIZE;

            if ($needsChunking && ! $this->isFfmpegAvailable()) {
                throw new \RuntimeException(
                    sprintf('Recording too large for Whisper (%.1f MB) and ffmpeg is not available for splitting',
                        $fileSize / 1024 / 1024)
                );
            }

            Log::info('[Transcription] Audio downloaded', [
                'call_id' => $call->id,
                'file_size' => $fileSize,
                'needs_chunking' => $needsChunking,
            ]);

            // 2. Transcribe with Whisper
            // Hybrid stereo diarization: transcribe with word-level timestamps,
            // then use per-channel audio energy to assign each word to a speaker.
            // For large files, split into chunks first and concatenate results.
            $rawTranscript = null;
            $isDiarized = false;
            $isStereo = false;

            if ($this->isFfmpegAvailable()) {
                $channels = $this->detectChannelCount($tempFile);
                $isStereo = ($channels === 2);
            }

            // Ensure participant relations are loaded (needed by both the name hint
            // and, later, buildAnalysisPrompt + resolveSpeakerLabels).
            $call->loadMissing(['person', 'client', 'answeredBy']);

            // Build Whisper name-bias hint once (null when no participants are known).
            // When null the Whisper request is byte-identical to the base case.
            $nameHint = $this->buildWhisperNameHint($call);

            if ($isStereo) {
                try {
                    // Get word-level timestamps (chunked if needed)
                    $allWords = $this->whisperTranscribeAllWords($tempFile, $apiKey, $tempFiles, $nameHint);

                    if (! empty($allWords)) {
                        // Build per-channel energy profiles from full file (ffmpeg, no size limit)
                        $energyProfiles = $this->buildEnergyProfiles($tempFile);
                        $labels = $this->resolveSpeakerLabels($call);

                        if ($energyProfiles !== null) {
                            $rawTranscript = $this->buildDiarizedTranscript(
                                $allWords,
                                $energyProfiles['left'],
                                $energyProfiles['right'],
                                $labels['left'],
                                $labels['right']
                            );
                            $isDiarized = true;

                            Log::info('[Transcription] Stereo diarization complete', [
                                'call_id' => $call->id,
                                'words' => count($allWords),
                            ]);
                        }
                    }
                } catch (\Throwable $e) {
                    Log::warning('[Transcription] Stereo diarization failed, falling back to mono', [
                        'call_id' => $call->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // 3. Fallback: mono transcription (chunked if needed)
            if ($rawTranscript === null) {
                $rawTranscript = $this->whisperTranscribeAll($tempFile, $apiKey, $tempFiles, $nameHint);
            }

            // Save raw transcript immediately (partial success if AI analysis fails)
            $call->update(['transcription' => $rawTranscript]);

            Log::info('[Transcription] Whisper complete', [
                'call_id' => $call->id,
                'transcript_length' => strlen($rawTranscript),
                'diarized' => $isDiarized,
            ]);

            // 4. AI analysis (optional — raw transcript is the primary value)
            try {
                if (AiConfig::isConfigured()) {
                    $prompt = $this->buildAnalysisPrompt($call, $rawTranscript, $isDiarized);
                    $analysis = $this->analyzeWithAi($prompt);

                    $identity = $this->parseCallerIdentity($analysis);

                    $call->update([
                        'transcription_summary' => $analysis,
                        'call_summary' => $this->parseSection($analysis, 'Call Summary'),
                        'next_steps' => $this->parseSection($analysis, 'Next Steps'),
                        'cleaned_transcript' => $this->parseSection($analysis, 'Transcription'),
                        'sentiment_score' => $this->parseSentimentScore($analysis),
                        'charge_classification' => $this->parseChargeClassification($analysis),
                        'coaching_notes' => $this->parseCoachingNotes($analysis),
                        'caller_identified_name' => $identity['name'],
                        'caller_identified_company' => $identity['company'],
                        'caller_identity_confidence' => $identity['confidence'],
                    ]);

                    Log::info('[Transcription] AI analysis complete', ['call_id' => $call->id]);
                }
            } catch (\Throwable $e) {
                Log::warning('[Transcription] AI analysis failed (transcript saved)', [
                    'call_id' => $call->id,
                    'error' => $e->getMessage(),
                ]);
            }

            // 5. Mark completed
            $call->update([
                'transcription_status' => TranscriptionStatus::Completed,
                'transcribed_at' => now(),
                'transcription_error' => null,
            ]);

            // 6. AI call-intake front-door (psa-xcyo): when enabled, hand the now-transcribed
            //    call to the CallIntakePipeline (resolve → attach/create) on the success path
            //    only. Gating the dispatch on intakeEnabled keeps transcription byte-identical
            //    when intake is off (no job churn); the pipeline re-checks dormancy as defence
            //    in depth. afterCommit() so the queued job sees the completed row.
            if (\App\Support\AgentConfig::intakeEnabled()) {
                \App\Jobs\CallIntakeJob::dispatch($call->id)->afterCommit();
            }
        } catch (\Throwable $e) {
            $call->update([
                'transcription_status' => TranscriptionStatus::Failed,
                'transcription_error' => substr($e->getMessage(), 0, 500),
            ]);

            throw $e;
        } finally {
            foreach ($tempFiles as $f) {
                if ($f && file_exists($f)) {
                    @unlink($f);
                }
            }

            // Voicemail notification deferred until here so the email body
            // includes the AI summary and transcript when available. The
            // immediate dispatch in PlivoWebhookController is skipped when
            // this transcription path runs.
            if ($call->status === CallStatus::Voicemail) {
                try {
                    app(NotificationService::class)->notifyNewVoicemail($call->fresh());
                } catch (\Throwable $e) {
                    Log::warning('[Transcription] Voicemail notification failed', [
                        'call_id' => $call->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
    }

    /**
     * Connect timeout for recording downloads (seconds).
     * Short: we only need to establish the TCP connection.
     */
    private const DOWNLOAD_CONNECT_TIMEOUT = 15;

    /**
     * Stall-abort threshold: abort if transfer rate drops below this many
     * bytes per second for DOWNLOAD_LOW_SPEED_TIME consecutive seconds.
     * 1 KB/s for 30 s catches a genuinely dead transfer while tolerating
     * a slow-but-progressing CDN (~24 KB/s observed in production).
     */
    private const DOWNLOAD_LOW_SPEED_LIMIT = 1024;  // bytes/s

    private const DOWNLOAD_LOW_SPEED_TIME = 30;     // seconds

    /**
     * Generous total-transfer backstop (seconds), kept just UNDER the
     * TranscribePhoneCall job timeout (600 s). A slow-but-progressing large file
     * still finishes (~529 s for a 12.8 MB recording at ~24 KB/s), but a slow-drip
     * transfer that never trips the stall-abort aborts GRACEFULLY here (catchable
     * Guzzle timeout) instead of running uncapped until the queue worker hard-kills
     * (SIGKILL) the whole job — which would skip the temp-file cleanup in the caller's
     * finally{}. Pairs with the stall-abort above (which catches dead transfers far
     * sooner). NOTE: this is the download bound only; the *end-to-end* job budget for
     * very large recordings is a separate follow-up (see psa-7jt9 review).
     */
    private const DOWNLOAD_MAX_TIMEOUT = 540;       // seconds

    /**
     * Download recording to a temp file. Returns the temp file path.
     *
     * Uses streaming (sink) so the file is never held in PHP memory. Timeout strategy:
     * a short connect timeout + a stall-abort (CURLOPT_LOW_SPEED_LIMIT /
     * CURLOPT_LOW_SPEED_TIME) so a slow-but-progressing transfer finishes while a truly
     * stalled one fails promptly, PLUS a generous total-transfer backstop
     * (DOWNLOAD_MAX_TIMEOUT, just under the job timeout) so a slow-drip aborts gracefully
     * rather than running until the queue worker hard-kills the job. The previous
     * `['timeout' => 300]` aborted large recordings mid-transfer: at ~24 KB/s a 12.8 MB
     * file needs ~529 s, which exceeded the 300 s ceiling.
     *
     * @param  GuzzleClient|null  $client  Injectable for tests; production uses a fresh client.
     */
    private function downloadRecording(string $url, ?GuzzleClient $client = null): string
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'psa_recording_');

        $options = [
            'sink' => $tempFile,
            // Short connection timeout — we only need to complete the TCP handshake.
            'connect_timeout' => self::DOWNLOAD_CONNECT_TIMEOUT,
            // Stall-abort via cURL low-speed thresholds instead of a hard total timeout.
            // This aborts if the transfer rate stays below DOWNLOAD_LOW_SPEED_LIMIT bytes/s
            // for DOWNLOAD_LOW_SPEED_TIME consecutive seconds, while allowing a slow-but-
            // progressing large file to finish.
            'curl' => [
                CURLOPT_LOW_SPEED_LIMIT => self::DOWNLOAD_LOW_SPEED_LIMIT,
                CURLOPT_LOW_SPEED_TIME => self::DOWNLOAD_LOW_SPEED_TIME,
            ],
        ];

        // Plivo media URLs require HTTP Basic Auth (auth_id:auth_token)
        if (str_contains($url, 'media.plivo.com')) {
            $authId = PlivoConfig::get('auth_id');
            $authToken = PlivoConfig::get('auth_token');
            if ($authId && $authToken) {
                $options['auth'] = [$authId, $authToken];
            }
        }

        // Generous total-transfer backstop so a slow-drip aborts gracefully before the
        // job's hard kill; the per-request connect timeout + stall-abort above catch the
        // common failures (dead transfer, unreachable host) far sooner.
        $client ??= new GuzzleClient(['timeout' => self::DOWNLOAD_MAX_TIMEOUT]);

        // Retry with backoff — Plivo's CDN may not have the MP3 ready immediately
        $maxRetries = 4;
        $lastException = null;
        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                $client->get($url, $options);
                break;
            } catch (\GuzzleHttp\Exception\ClientException $e) {
                $lastException = $e;
                if ($attempt < $maxRetries && $e->getResponse()->getStatusCode() === 403) {
                    $delay = $attempt * 15; // 15s, 30s, 45s
                    Log::info('[Transcription] Recording not ready, retrying', [
                        'url' => $url,
                        'attempt' => $attempt,
                        'delay' => $delay,
                    ]);
                    sleep($delay);

                    continue;
                }
                throw $e;
            }
        }

        if ($lastException && (! file_exists($tempFile) || filesize($tempFile) === 0)) {
            @unlink($tempFile);
            throw $lastException;
        }

        if (! file_exists($tempFile) || filesize($tempFile) === 0) {
            @unlink($tempFile);
            throw new \RuntimeException('Downloaded recording is empty (0 bytes)');
        }

        return $tempFile;
    }

    /**
     * Send audio file to OpenAI Whisper for speech-to-text transcription.
     *
     * @param  string|null  $namePrompt  Optional name-bias hint for Whisper's `prompt` field.
     *                                   When null/empty the multipart request is byte-identical
     *                                   to the base case (no `prompt` field appended).
     */
    private function whisperTranscribe(string $filePath, string $apiKey, ?string $namePrompt = null): string
    {
        $client = new GuzzleClient(['timeout' => 300]);

        $multipart = [
            [
                'name' => 'file',
                'contents' => fopen($filePath, 'r'),
                'filename' => 'recording.mp3',
            ],
            [
                'name' => 'model',
                'contents' => 'whisper-1',
            ],
            [
                'name' => 'response_format',
                'contents' => 'text',
            ],
        ];

        if ($namePrompt !== null && $namePrompt !== '') {
            $multipart[] = ['name' => 'prompt', 'contents' => $namePrompt];
        }

        $response = $client->post('https://api.openai.com/v1/audio/transcriptions', [
            'headers' => [
                'Authorization' => "Bearer {$apiKey}",
            ],
            'multipart' => $multipart,
        ]);

        $transcript = trim((string) $response->getBody());

        if ($transcript === '') {
            throw new \RuntimeException('Whisper returned an empty transcription');
        }

        return $transcript;
    }

    /**
     * Build the analysis prompt with speaker context from call metadata.
     *
     * @param  bool  $isDiarized  Whether the transcript has pre-applied speaker labels from stereo channel splitting
     */
    public function buildAnalysisPrompt(PhoneCall $call, string $transcript, bool $isDiarized = false): string
    {
        $call->loadMissing(['client', 'person', 'answeredBy']);

        $prompt = self::CALL_TRANSCRIPTION_PROMPT;

        // Add speaker context if available
        $participants = [];
        $directionText = '';

        if ($call->direction === \App\Enums\CallDirection::Inbound) {
            $directionText = 'This was an inbound call to the MSP helpdesk.';
        } elseif ($call->direction === \App\Enums\CallDirection::Outbound) {
            $directionText = 'This was an outbound call from the MSP to the client.';
        }

        $customerName = $call->person?->fullName ?? $call->client?->name;
        if ($customerName) {
            $participants[] = "- **Customer**: {$customerName}";
        }

        $agentName = $call->answeredBy?->name;
        if ($agentName) {
            $participants[] = "- **Agent (Technician)**: {$agentName}";
        }

        if ($directionText || $participants) {
            $template = $isDiarized
                ? self::DIARIZED_SPEAKER_CONTEXT_TEMPLATE
                : self::SPEAKER_CONTEXT_TEMPLATE;

            $prompt .= sprintf(
                $template,
                $directionText ?: 'Call direction is unknown.',
                $participants ? implode("\n", $participants)."\n" : "No participant information available.\n"
            );
        }

        // Add duration context
        $prompt .= "\n\n";
        if ($call->duration) {
            $minutes = (int) floor($call->duration / 60);
            $seconds = $call->duration % 60;
            $prompt .= sprintf("Call duration: %d:%02d\n\n", $minutes, $seconds);
        }

        $prompt .= "---\n\n# Raw Transcript\n\n{$transcript}";

        return $prompt;
    }

    /**
     * Check if ffmpeg and ffprobe binaries are available on the system.
     */
    private function isFfmpegAvailable(): bool
    {
        static $available = null;
        if ($available !== null) {
            return $available;
        }

        exec('which ffmpeg 2>/dev/null', $_, $ffmpegCode);
        exec('which ffprobe 2>/dev/null', $_, $ffprobeCode);

        $available = ($ffmpegCode === 0 && $ffprobeCode === 0);

        if (! $available) {
            Log::debug('[Transcription] ffmpeg/ffprobe not available — stereo splitting disabled');
        }

        return $available;
    }

    /**
     * Detect the number of audio channels in a file using ffprobe.
     */
    private function detectChannelCount(string $filePath): ?int
    {
        $cmd = sprintf(
            'ffprobe -v error -select_streams a:0 -show_entries stream=channels -of csv=p=0 %s 2>/dev/null',
            escapeshellarg($filePath)
        );

        exec($cmd, $output, $exitCode);

        if ($exitCode !== 0 || empty($output)) {
            return null;
        }

        return (int) trim($output[0]);
    }

    /**
     * Transcribe audio with Whisper using verbose_json format with word-level timestamps.
     *
     * Returns both segment-level and word-level data. Word timestamps enable
     * fine-grained speaker assignment for stereo diarization.
     *
     * @param  string|null  $namePrompt  Optional name-bias hint; see whisperTranscribe().
     * @return array{text: string, words: array<array{start: float, end: float, word: string}>}
     */
    private function whisperTranscribeWithWords(string $filePath, string $apiKey, ?string $namePrompt = null): array
    {
        $client = new GuzzleClient(['timeout' => 300]);

        $multipart = [
            [
                'name' => 'file',
                'contents' => fopen($filePath, 'r'),
                'filename' => 'recording.mp3',
            ],
            [
                'name' => 'model',
                'contents' => 'whisper-1',
            ],
            [
                'name' => 'response_format',
                'contents' => 'verbose_json',
            ],
            [
                'name' => 'timestamp_granularities[]',
                'contents' => 'word',
            ],
        ];

        if ($namePrompt !== null && $namePrompt !== '') {
            $multipart[] = ['name' => 'prompt', 'contents' => $namePrompt];
        }

        $response = $client->post('https://api.openai.com/v1/audio/transcriptions', [
            'headers' => [
                'Authorization' => "Bearer {$apiKey}",
            ],
            'multipart' => $multipart,
        ]);

        $data = json_decode((string) $response->getBody(), true);

        return [
            'text' => trim($data['text'] ?? ''),
            'words' => array_map(fn (array $w) => [
                'start' => (float) ($w['start'] ?? 0),
                'end' => (float) ($w['end'] ?? 0),
                'word' => $w['word'] ?? '',
            ], $data['words'] ?? []),
        ];
    }

    /**
     * Transcribe audio with Whisper, splitting into chunks if over the size limit.
     * Returns concatenated plain text transcript.
     *
     * @param  string|null  $namePrompt  Optional name-bias hint; see whisperTranscribe().
     */
    private function whisperTranscribeAll(string $filePath, string $apiKey, array &$tempFiles, ?string $namePrompt = null): string
    {
        if (filesize($filePath) <= self::MAX_WHISPER_SIZE) {
            return $this->whisperTranscribe($filePath, $apiKey, $namePrompt);
        }

        $chunks = $this->splitAudioIntoChunks($filePath, $tempFiles);
        $transcripts = [];

        foreach ($chunks as $i => $chunkPath) {
            Log::info('[Transcription] Transcribing chunk', ['chunk' => $i + 1, 'total' => count($chunks)]);
            $transcripts[] = $this->whisperTranscribe($chunkPath, $apiKey, $namePrompt);
        }

        return implode(' ', $transcripts);
    }

    /**
     * Transcribe audio with word-level timestamps, splitting into chunks if over the size limit.
     * Returns a single array of words with timestamps adjusted for chunk offsets.
     *
     * @param  string|null  $namePrompt  Optional name-bias hint; see whisperTranscribe().
     * @return array<array{start: float, end: float, word: string}>
     */
    private function whisperTranscribeAllWords(string $filePath, string $apiKey, array &$tempFiles, ?string $namePrompt = null): array
    {
        if (filesize($filePath) <= self::MAX_WHISPER_SIZE) {
            return $this->whisperTranscribeWithWords($filePath, $apiKey, $namePrompt)['words'];
        }

        $chunks = $this->splitAudioIntoChunks($filePath, $tempFiles);
        $allWords = [];

        foreach ($chunks as $i => $chunkPath) {
            $offset = $i * self::CHUNK_DURATION;
            Log::info('[Transcription] Transcribing chunk (words)', ['chunk' => $i + 1, 'total' => count($chunks), 'offset' => $offset]);

            $result = $this->whisperTranscribeWithWords($chunkPath, $apiKey, $namePrompt);

            foreach ($result['words'] as $word) {
                $allWords[] = [
                    'start' => $word['start'] + $offset,
                    'end' => $word['end'] + $offset,
                    'word' => $word['word'],
                ];
            }
        }

        return $allWords;
    }

    /**
     * Split an audio file into chunks of CHUNK_DURATION seconds using ffmpeg.
     * Returns array of chunk file paths. Adds paths to $tempFiles for cleanup.
     *
     * @return string[]
     */
    private function splitAudioIntoChunks(string $filePath, array &$tempFiles): array
    {
        // Get total duration
        $cmd = sprintf(
            'ffprobe -v error -show_entries format=duration -of csv=p=0 %s 2>/dev/null',
            escapeshellarg($filePath)
        );
        exec($cmd, $output, $exitCode);

        if ($exitCode !== 0 || empty($output)) {
            throw new \RuntimeException('Failed to determine audio duration for chunking');
        }

        $totalDuration = (float) trim($output[0]);
        $chunkCount = (int) ceil($totalDuration / self::CHUNK_DURATION);

        Log::info('[Transcription] Splitting audio into chunks', [
            'duration' => round($totalDuration),
            'chunks' => $chunkCount,
            'chunk_duration' => self::CHUNK_DURATION,
        ]);

        $chunks = [];
        for ($i = 0; $i < $chunkCount; $i++) {
            $startTime = $i * self::CHUNK_DURATION;
            $chunkPath = sys_get_temp_dir().'/psa_chunk_'.uniqid().'_'.$i.'.mp3';

            $cmd = sprintf(
                'ffmpeg -y -i %s -ss %d -t %d -c copy %s 2>/dev/null',
                escapeshellarg($filePath),
                $startTime,
                self::CHUNK_DURATION,
                escapeshellarg($chunkPath)
            );
            exec($cmd, $_, $exitCode);

            if ($exitCode !== 0 || ! file_exists($chunkPath) || filesize($chunkPath) === 0) {
                // Clean up any chunks created so far
                foreach ($chunks as $c) {
                    @unlink($c);
                }
                throw new \RuntimeException("Failed to split audio at chunk {$i} (offset {$startTime}s)");
            }

            $chunks[] = $chunkPath;
            $tempFiles[] = $chunkPath;
        }

        return $chunks;
    }

    /**
     * Build per-channel energy profiles from a stereo audio file.
     *
     * Runs a single ffmpeg pass using channelsplit + astats to produce
     * fine-grained RMS energy data for both channels.
     *
     * @return array{left: array, right: array}|null
     */
    private function buildEnergyProfiles(string $filePath): ?array
    {
        $leftEnergyFile = sys_get_temp_dir().'/psa_energy_left_'.uniqid().'.txt';
        $rightEnergyFile = sys_get_temp_dir().'/psa_energy_right_'.uniqid().'.txt';

        try {
            $cmd = sprintf(
                'ffmpeg -i %s -filter_complex '
                .'"channelsplit=channel_layout=stereo[left][right];'
                .'[left]astats=metadata=1:reset=1,ametadata=print:key=lavfi.astats.Overall.RMS_level:file=%s[l];'
                .'[right]astats=metadata=1:reset=1,ametadata=print:key=lavfi.astats.Overall.RMS_level:file=%s[r]" '
                .'-map "[l]" -f null /dev/null -map "[r]" -f null /dev/null 2>/dev/null',
                escapeshellarg($filePath),
                escapeshellarg($leftEnergyFile),
                escapeshellarg($rightEnergyFile)
            );

            exec($cmd, $_, $exitCode);

            if ($exitCode !== 0 || ! file_exists($leftEnergyFile) || ! file_exists($rightEnergyFile)) {
                Log::warning('[Transcription] ffmpeg energy profiling failed');

                return null;
            }

            return [
                'left' => $this->parseEnergyProfile($leftEnergyFile),
                'right' => $this->parseEnergyProfile($rightEnergyFile),
            ];
        } finally {
            @unlink($leftEnergyFile);
            @unlink($rightEnergyFile);
        }
    }

    /**
     * Build a speaker-labeled transcript from word-level timestamps and energy profiles.
     *
     * For each word, determines which channel is louder at that word's midpoint
     * to assign the speaker. Consecutive words from the same speaker are grouped
     * into paragraphs.
     */
    private function buildDiarizedTranscript(
        array $words,
        array $leftProfile,
        array $rightProfile,
        string $leftLabel,
        string $rightLabel
    ): string {
        $lines = [];
        $currentSpeaker = null;
        $currentText = '';

        foreach ($words as $w) {
            if (trim($w['word']) === '') {
                continue;
            }

            // Use the midpoint of the word for energy lookup
            $midpoint = ($w['start'] + $w['end']) / 2;
            $leftEnergy = $this->energyAtTime($leftProfile, $midpoint);
            $rightEnergy = $this->energyAtTime($rightProfile, $midpoint);

            $speaker = ($leftEnergy >= $rightEnergy) ? $leftLabel : $rightLabel;

            if ($speaker === $currentSpeaker) {
                $currentText .= ' '.$w['word'];
            } else {
                if ($currentSpeaker !== null) {
                    $lines[] = "{$currentSpeaker}: ".trim($currentText);
                }
                $currentSpeaker = $speaker;
                $currentText = $w['word'];
            }
        }

        if ($currentSpeaker !== null) {
            $lines[] = "{$currentSpeaker}: ".trim($currentText);
        }

        return implode("\n\n", $lines);
    }

    /**
     * Get the energy (dB) at a specific time from an energy profile.
     *
     * Finds the closest entry to the given time.
     */
    private function energyAtTime(array $profile, float $time): float
    {
        if (empty($profile)) {
            return -120.0;
        }

        $closest = null;
        $closestDist = PHP_FLOAT_MAX;

        foreach ($profile as $entry) {
            $dist = abs($entry['time'] - $time);
            if ($dist < $closestDist) {
                $closestDist = $dist;
                $closest = $entry;
            }
            // Profile is sorted by time — once we start moving away, stop
            if ($entry['time'] > $time + 0.1) {
                break;
            }
        }

        return $closest ? $closest['db'] : -120.0;
    }

    /**
     * Parse an ffmpeg ametadata energy output file into a time-indexed array.
     *
     * File format (alternating lines):
     *   frame:N    pts:P       pts_time:0.123456
     *   lavfi.astats.Overall.RMS_level=-30.123456
     *
     * @return array<array{time: float, db: float}>
     */
    private function parseEnergyProfile(string $filePath): array
    {
        $profile = [];
        $currentTime = null;

        foreach (file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (preg_match('/pts_time:([\d.]+)/', $line, $m)) {
                $currentTime = (float) $m[1];
            } elseif ($currentTime !== null && preg_match('/RMS_level=(-?[\d.]+|-inf)/', $line, $m)) {
                $db = ($m[1] === '-inf') ? -120.0 : (float) $m[1];
                $profile[] = ['time' => $currentTime, 'db' => $db];
                $currentTime = null;
            }
        }

        return $profile;
    }

    /**
     * Resolve speaker labels for left and right audio channels based on call direction.
     *
     * Plivo stereo channel mapping:
     *   A-leg (left)  = call initiator
     *   B-leg (right) = call destination
     *
     * @return array{left: string, right: string}
     */
    private function resolveSpeakerLabels(PhoneCall $call): array
    {
        $call->loadMissing(['person', 'answeredBy', 'client']);

        $agentName = $call->answeredBy?->name ?? 'Agent';
        $customerName = $call->person?->fullName
            ?? $call->client?->name
            ?? 'Customer';

        if ($call->direction === \App\Enums\CallDirection::Outbound) {
            // Outbound: A-leg (left) = Agent placed the call, B-leg (right) = Customer
            return ['left' => $agentName, 'right' => $customerName];
        }

        // Inbound: A-leg (left) = Customer called in, B-leg (right) = Agent answered
        return ['left' => $customerName, 'right' => $agentName];
    }

    /**
     * Send transcript to AI provider for structured analysis.
     */
    private function analyzeWithAi(string $prompt): string
    {
        $provider = AiConfig::provider();
        $apiKey = AiConfig::get('api_key');
        $model = AiConfig::model();

        $client = new GuzzleClient(['timeout' => 300]);

        if ($provider === 'anthropic') {
            $response = $client->post('https://api.anthropic.com/v1/messages', [
                'headers' => [
                    'x-api-key' => $apiKey,
                    'anthropic-version' => '2023-06-01',
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'model' => $model,
                    // High cap so even long calls get a FULL cleaned transcript in
                    // one pass (Claude Sonnet 4.x supports up to 64k output tokens).
                    // max_tokens is a ceiling, not a target — short calls are unaffected.
                    'max_tokens' => 32000,
                    'messages' => [
                        ['role' => 'user', 'content' => $prompt],
                    ],
                ],
            ]);

            $data = json_decode((string) $response->getBody(), true);
            $text = $data['content'][0]['text'] ?? '';

            $usage = $data['usage'] ?? [];
            Log::info('[Transcription] Anthropic usage', [
                'input_tokens' => $usage['input_tokens'] ?? 0,
                'output_tokens' => $usage['output_tokens'] ?? 0,
            ]);
        } else {
            $response = $client->post('https://api.openai.com/v1/chat/completions', [
                'headers' => [
                    'Authorization' => "Bearer {$apiKey}",
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'model' => $model,
                    // gpt-4o caps output at 16k tokens (~80 min of cleaned transcript).
                    'max_tokens' => 16000,
                    'messages' => [
                        ['role' => 'user', 'content' => $prompt],
                    ],
                ],
            ]);

            $data = json_decode((string) $response->getBody(), true);
            $text = $data['choices'][0]['message']['content'] ?? '';

            $usage = $data['usage'] ?? [];
            Log::info('[Transcription] OpenAI usage', [
                'prompt_tokens' => $usage['prompt_tokens'] ?? 0,
                'completion_tokens' => $usage['completion_tokens'] ?? 0,
            ]);
        }

        if (trim($text) === '') {
            throw new \RuntimeException('AI provider returned an empty analysis');
        }

        return trim($text);
    }

    /**
     * Parse sentiment score (1-10) from AI analysis.
     * Regex adapted from HaloClaude's parsing pattern.
     */
    private function parseSentimentScore(string $analysis): ?int
    {
        if (preg_match('/##\s*Sentiment\s*Score\s*\n.*?(\b(?:10|[1-9])\b)/si', $analysis, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    /**
     * Parse charge classification from AI analysis.
     * Same approach as HaloClaude's _parse_charge_classification().
     */
    private function parseChargeClassification(string $analysis): ?ChargeClassification
    {
        if (preg_match('/##\s*Charge Classification\s*\n(.*?)(?=\n##|\z)/si', $analysis, $matches)) {
            $section = strtolower($matches[1]);
            if (str_contains($section, 'no charge')) {
                return ChargeClassification::NoCharge;
            }
            if (str_contains($section, 'billable')) {
                return ChargeClassification::Billable;
            }
        }

        return null;
    }

    /**
     * Parse coaching notes from AI analysis.
     */
    private function parseCoachingNotes(string $analysis): ?string
    {
        return $this->parseSection($analysis, 'Coaching');
    }

    /**
     * Extract a named ## section from the AI analysis markdown.
     */
    private function parseSection(string $analysis, string $heading): ?string
    {
        if (preg_match('/##\s*'.preg_quote($heading, '/').'\s*\n(.*?)(?=\n##|\z)/si', $analysis, $m)) {
            $text = trim($m[1]);

            return $text !== '' ? $text : null;
        }

        return null;
    }

    /**
     * Parse the structured Caller Identity block from the AI analysis.
     *
     * Tolerantly matches `Name:`/`Company:`/`Confidence:` lines with optional leading
     * `- `, `* `, or `**` decorators emitted by the model. Maps sentinel values
     * ("Unknown", "N/A", "none", "") to null.
     *
     * @return array{name: ?string, company: ?string, confidence: ?float}
     */
    private function parseCallerIdentity(string $analysis): array
    {
        $section = $this->parseSection($analysis, 'Caller Identity');

        if ($section === null) {
            return ['name' => null, 'company' => null, 'confidence' => null];
        }

        $nullSentinels = ['unknown', 'n/a', 'none', ''];

        // Name
        $name = null;
        if (preg_match('/Name\**\s*:\s*(.+)/i', $section, $m)) {
            $raw = trim(trim($m[1]), '*');
            if (! in_array(strtolower($raw), $nullSentinels, true)) {
                $name = $raw;
            }
        }

        // Company
        $company = null;
        if (preg_match('/Company\**\s*:\s*(.+)/i', $section, $m)) {
            $raw = trim(trim($m[1]), '*');
            if (! in_array(strtolower($raw), $nullSentinels, true)) {
                $company = $raw;
            }
        }

        // Confidence: matches 0, 1, 0.X, 1.X — clamped to [0, 1]
        $confidence = null;
        if (preg_match('/Confidence\**\s*:\s*([01](?:\.\d+)?)/i', $section, $m)) {
            $confidence = min(1.0, max(0.0, (float) $m[1]));
        }

        return ['name' => $name, 'company' => $company, 'confidence' => $confidence];
    }

    /**
     * Build a Whisper name-bias hint from call metadata.
     *
     * Caller must ensure person/client/answeredBy are loaded before calling
     * (transcribe() calls loadMissing() immediately before this method).
     *
     * Returns a comma-joined string of the available participant names
     * (person → client → answeredBy), or null when none are known.
     * When null, the Whisper `multipart` request is byte-identical to the base
     * case — no `prompt` field is appended.
     */
    private function buildWhisperNameHint(PhoneCall $call): ?string
    {
        $names = array_filter([
            $call->person?->fullName,
            $call->client?->name,
            $call->answeredBy?->name,
        ]);

        return $names !== [] ? implode(', ', $names) : null;
    }
}
