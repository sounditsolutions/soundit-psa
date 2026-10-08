<?php

namespace Tests\Feature\Mesh;

use App\Services\Mesh\MeshClient;
use App\Services\Mesh\MeshClientException;
use App\Services\Mesh\MeshWriteClient;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Debug\ExceptionHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Tests\Support\RecordsGuzzleRejections;
use Tests\TestCase;

/**
 * #5978: a MeshClientException that escapes every catch reaches surfaces
 * report() does not govern: the console renderer (Handler::renderForConsole,
 * which walks getPrevious()) and (string) $e, which a queue worker stores in
 * failed_jobs.exception and which prints every previous exception. The
 * request() throw sites no longer chain Guzzle's exception, so neither
 * surface can carry its URI, user-info or vendor body.
 *
 * Positive control: every marker is on the raw Guzzle exception the client
 * caught (RecordsGuzzleRejections). G-5: MockHandler only. Synthetic data.
 */
class MeshUncaughtRenderTest extends TestCase
{
    use RecordsGuzzleRejections;

    private const USER = 'synthuser-5978';

    private const PASS = 'SYNTHPASS-5978';

    private const HOST = 'mesh-render.example.test';

    private const BODY = 'VENDOR-BODY-MARKER-5978';

    /** @return array<string, array{0: string, 1: int}> */
    public static function cases(): array
    {
        return [
            'read client, HTTP 503' => ['read', 503],
            'read client, HTTP 401' => ['read', 401],
            'write client, HTTP 503' => ['write', 503],
            'write client, HTTP 401' => ['write', 401],
        ];
    }

    #[DataProvider('cases')]
    public function test_an_uncaught_mesh_exception_renders_and_stringifies_without_vendor_text(string $which, int $status): void
    {
        $guzzle = new GuzzleClient([
            'base_uri' => 'https://'.self::USER.':'.self::PASS.'@'.self::HOST.'/',
            'handler' => $this->recordRejections(HandlerStack::create(new MockHandler([
                new Response($status, ['Content-Type' => 'text/plain'], self::BODY),
            ]))),
        ]);

        $thrown = null;
        try {
            if ($which === 'read') {
                $client = new MeshClient(['api_key' => 'test-placeholder-not-a-key', 'base_url' => 'https://'.self::HOST]);
                (new \ReflectionProperty($client, 'http'))->setValue($client, $guzzle);
                $client->getCustomers();
            } else {
                (new MeshWriteClient(['api_key' => 'test-placeholder-not-a-key'], $guzzle))
                    ->listCustomerRules('11111111-2222-3333-4444-555555555555');
            }
        } catch (MeshClientException $e) {
            $thrown = $e;
        }
        $this->assertNotNull($thrown, 'the scripted read must fail');

        // Positive control: the raw exception, rendered the same way, does
        // carry the markers (the password on the request URI it holds:
        // Guzzle masks a parsed password in its own message).
        $raw = $this->lastRejection();
        $rawText = $this->renderForConsole($raw).' '.$raw.' '.$raw->getRequest()->getUri();
        foreach ([self::USER, self::PASS, self::HOST, self::BODY] as $marker) {
            $this->assertStringContainsString($marker, $rawText, "positive control: the raw exception carries '{$marker}'");
        }

        $this->assertNull($thrown->getPrevious(), 'not chained');
        $surfaces = ['console render' => $this->renderForConsole($thrown), '(string) / failed_jobs' => (string) $thrown];
        $this->assertStringContainsString('failed with HTTP '.$status, $surfaces['console render'], 'positive control: the render holds the status-only message');
        foreach ($surfaces as $where => $text) {
            foreach ([self::USER, self::PASS, self::HOST, self::BODY, 'resulted in'] as $leak) {
                $this->assertStringNotContainsString($leak, $text, "{$where} carries '{$leak}'");
            }
        }
    }

    private function renderForConsole(\Throwable $e): string
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_VERBOSE);
        $this->app->make(ExceptionHandler::class)->renderForConsole($output, $e);

        return $output->fetch();
    }
}
