<?php

namespace Tests\Unit\Transcription;

use App\Services\TranscriptionService;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * Asserts the recording download timeout strategy: no hard total cURL timeout,
 * stall-abort via CURLOPT_LOW_SPEED_LIMIT/CURLOPT_LOW_SPEED_TIME, short
 * connect_timeout, and streaming via sink.
 *
 * Root cause (fixed): `new GuzzleClient(['timeout' => 300])` in downloadRecording()
 * set a hard 300 s total-transfer deadline. A 12.8 MB file at ~24 KB/s needs
 * ~533 s — the hard ceiling aborted it at 7.25 MB. Replaced with a stall-abort
 * that allows slow-but-progressing downloads to finish.
 */
class DownloadTimeoutTest extends TestCase
{
    /** @var array<int, array{request: \Psr\Http\Message\RequestInterface, options: array<string, mixed>}> */
    private array $history = [];

    /**
     * Build a GuzzleClient backed by MockHandler that records every transfer's
     * resolved options (including curl options merged in by Guzzle).
     *
     * @param  array<int, Response|\Throwable>  $queue
     */
    private function mockClient(array $queue): GuzzleClient
    {
        $this->history = [];

        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(Middleware::history($this->history));

        return new GuzzleClient(['handler' => $stack, 'timeout' => 0]);
    }

    /**
     * Expose the private downloadRecording() method via reflection so we can
     * pass an injected Guzzle client for testing without hitting the real CDN.
     */
    private function callDownload(TranscriptionService $service, string $url, GuzzleClient $client): string
    {
        $ref = new \ReflectionMethod($service, 'downloadRecording');

        return $ref->invoke($service, $url, $client);
    }

    // -------------------------------------------------------------------------
    // connect_timeout
    // -------------------------------------------------------------------------

    public function test_download_sets_connect_timeout(): void
    {
        $client = $this->mockClient([new Response(200, [], 'x')]);

        $service = new TranscriptionService;
        $path = $this->callDownload($service, 'https://cdn.example.com/fake.mp3', $client);

        @unlink($path);

        $opts = $this->history[0]['options'];
        $this->assertArrayHasKey('connect_timeout', $opts,
            'downloadRecording() must set connect_timeout');
        $this->assertIsInt($opts['connect_timeout']);
        $this->assertGreaterThan(0, $opts['connect_timeout']);
        // Should be short (≤ 30 s) — long enough to establish a connection,
        // short enough to fail fast if the host is unreachable.
        $this->assertLessThanOrEqual(30, $opts['connect_timeout']);
    }

    // -------------------------------------------------------------------------
    // No hard total timeout
    // -------------------------------------------------------------------------

    public function test_download_does_not_set_a_hard_total_timeout(): void
    {
        $client = $this->mockClient([new Response(200, [], 'x')]);

        $service = new TranscriptionService;
        $path = $this->callDownload($service, 'https://cdn.example.com/fake.mp3', $client);

        @unlink($path);

        $opts = $this->history[0]['options'];

        // 'timeout' 0 means unlimited — must not be a positive integer that would
        // impose a total-transfer ceiling and abort a slow-but-progressing download.
        $timeout = $opts['timeout'] ?? 0;
        $this->assertTrue(
            $timeout === 0 || $timeout === null || $timeout === false,
            "Expected no hard total timeout (timeout=0/null/false), got: {$timeout}. "
            .'A positive total timeout will abort large recordings at slow CDN speeds.'
        );
    }

    // -------------------------------------------------------------------------
    // CURLOPT_LOW_SPEED_LIMIT + CURLOPT_LOW_SPEED_TIME (stall-abort)
    // -------------------------------------------------------------------------

    public function test_download_sets_low_speed_stall_abort(): void
    {
        $client = $this->mockClient([new Response(200, [], 'x')]);

        $service = new TranscriptionService;
        $path = $this->callDownload($service, 'https://cdn.example.com/fake.mp3', $client);

        @unlink($path);

        $opts = $this->history[0]['options'];

        $this->assertArrayHasKey('curl', $opts,
            'downloadRecording() must pass curl options for stall-abort');

        $curl = $opts['curl'];
        $this->assertArrayHasKey(CURLOPT_LOW_SPEED_LIMIT, $curl,
            'CURLOPT_LOW_SPEED_LIMIT must be set to abort stalled transfers');
        $this->assertArrayHasKey(CURLOPT_LOW_SPEED_TIME, $curl,
            'CURLOPT_LOW_SPEED_TIME must be set to define the stall window');

        // Limit: must be > 0 (real threshold) but not so high it rejects a slow CDN.
        // Production observed ~24 KB/s; 1 KB/s is the safe floor.
        $this->assertGreaterThan(0, $curl[CURLOPT_LOW_SPEED_LIMIT]);
        $this->assertLessThanOrEqual(8192, $curl[CURLOPT_LOW_SPEED_LIMIT],
            'LOW_SPEED_LIMIT should be a conservative floor (≤ 8 KB/s) to tolerate slow CDNs');

        // Time window: long enough for CDN bursts/lulls, short enough to fail fast on stalls.
        $this->assertGreaterThanOrEqual(10, $curl[CURLOPT_LOW_SPEED_TIME]);
        $this->assertLessThanOrEqual(120, $curl[CURLOPT_LOW_SPEED_TIME]);
    }

    // -------------------------------------------------------------------------
    // Streaming sink
    // -------------------------------------------------------------------------

    public function test_download_uses_sink_streaming_not_in_memory_body(): void
    {
        $client = $this->mockClient([new Response(200, [], 'hello recording')]);

        $service = new TranscriptionService;
        $path = $this->callDownload($service, 'https://cdn.example.com/fake.mp3', $client);

        @unlink($path);

        $opts = $this->history[0]['options'];

        $this->assertArrayHasKey('sink', $opts,
            'downloadRecording() must stream to a sink file, not accumulate in memory');
        $this->assertIsString($opts['sink'],
            'sink option must be a file path string');
        $this->assertNotEmpty($opts['sink']);
    }
}
