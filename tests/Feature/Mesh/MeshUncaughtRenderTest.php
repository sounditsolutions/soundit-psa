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
 * surface can carry ITS message: URI, user-info or vendor body.
 *
 * #6051: (string) $e also prints the stack trace. Its frames show string
 * arguments whenever zend.exception_ignore_args is Off (PHP's built-in
 * default), cut to zend.exception_string_param_max_len bytes. The rows
 * above pass under the ini the suite runs with; the last test measures
 * both settings instead of assuming one, and pins that not chaining does
 * NOT cover trace arguments.
 *
 * Positive control: every marker is on the raw Guzzle exception the client
 * caught (RecordsGuzzleRejections). G-5: MockHandler only, stray Http
 * requests prevented. Synthetic data.
 */
class MeshUncaughtRenderTest extends TestCase
{
    use RecordsGuzzleRejections;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Http::preventStrayRequests();
    }

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

    /**
     * #6051, measured: a refused endpoint's frame in (string) $e. With
     * exception_ignore_args On no argument is printed; with it Off and the
     * built-in 15-byte cut, the first 15 bytes of the endpoint are.
     */
    public function test_trace_arguments_in_the_string_form_depend_on_exception_ignore_args(): void
    {
        $endpoint = '//synthuser-6051:SYNTHPASS-6051@'.self::HOST.':99999/api/customers/';
        $saved = [ini_get('zend.exception_ignore_args'), ini_get('zend.exception_string_param_max_len')];
        $strings = [];
        try {
            foreach (['1', '0'] as $ignore) {
                ini_set('zend.exception_ignore_args', $ignore);
                ini_set('zend.exception_string_param_max_len', '15');
                try {
                    (new MeshClient(['api_key' => 'test-placeholder-not-a-key', 'base_url' => 'https://'.self::HOST]))->get($endpoint);
                    $this->fail('the endpoint must be refused');
                } catch (MeshClientException $e) {
                    $strings[$ignore] = (string) $e;
                }
            }
        } finally {
            ini_set('zend.exception_ignore_args', (string) $saved[0]);
            ini_set('zend.exception_string_param_max_len', (string) $saved[1]);
        }

        $this->assertStringNotContainsString('synthuser-6051', $strings['1'], 'ignore_args On: no argument in the trace');
        $this->assertStringContainsString("'//synthuser-605...'", $strings['0'], 'ignore_args Off: the endpoint prefix is in the trace; not chaining does not cover it');
        $this->assertStringNotContainsString('SYNTHPASS-6051', $strings['0'], 'the 15-byte cut stops before the password');
    }

    private function renderForConsole(\Throwable $e): string
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_VERBOSE);
        $this->app->make(ExceptionHandler::class)->renderForConsole($output, $e);

        return $output->fetch();
    }
}
