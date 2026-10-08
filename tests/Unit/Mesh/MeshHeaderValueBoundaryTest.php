<?php

namespace Tests\Unit\Mesh;

use App\Services\Mesh\MeshClient;
use App\Services\Mesh\MeshClientException;
use App\Services\Mesh\MeshWriteClient;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

/**
 * #6055: MeshClient::isHeaderValue() at its boundaries, graded against
 * PSR-7 itself (the property, not a hand-kept list): for every value the
 * preflight check and PSR-7's own Request constructor must agree. A
 * trailing "\n" alone (the /D modifier), a tab, a space, DEL, NUL, a high
 * byte (accepted) and the scalar types are all rows.
 *
 * #6054: a non-string scalar key is accepted, as PSR-7 accepts and casts
 * it; a value that is not a scalar is refused with a reason that says so,
 * not 'holds a character'. An array is refused although PSR-7 reads it as
 * a list of values: one key is one value.
 *
 * G-5: MockHandler only, stray Http requests prevented. Synthetic data.
 */
class MeshHeaderValueBoundaryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    /** @return array<string, array{0: mixed}> */
    public static function values(): array
    {
        return [
            'plain ASCII' => ['SECRET_FIXTURE-6055'],
            'empty' => [''],
            'trailing LF only' => ["SECRET_FIXTURE-6055\n"],
            'trailing CR only' => ["SECRET_FIXTURE-6055\r"],
            'inner CRLF' => ["SECRET_FIXTURE\r\n6055"],
            'inner tab' => ["SECRET_FIXTURE\t6055"],
            'inner space' => ['SECRET_FIXTURE 6055'],
            'leading and trailing space' => [' SECRET_FIXTURE-6055 '],
            'DEL' => ["SECRET_FIXTURE\x7F6055"],
            'NUL' => ["SECRET_FIXTURE\x006055"],
            'unit separator' => ["SECRET_FIXTURE\x1F6055"],
            'high byte 0x80' => ["SECRET_FIXTURE\x806055"],
            'high byte 0xFF' => ["SECRET_FIXTURE\xFF6055"],
            'UTF-8 text' => ['SECRET_FIXTURE-é-6055'],
            'int' => [60556055],
            'float' => [6055.5],
            'true' => [true],
            'null' => [null],
            'object' => [new \stdClass],
        ];
    }

    #[DataProvider('values')]
    public function test_is_header_value_agrees_with_psr7(mixed $value): void
    {
        try {
            new Request('GET', 'https://mesh-header.example.test/', ['API-KEY' => $value]);
            $psr7 = true;
        } catch (\InvalidArgumentException) {
            $psr7 = false;
        }

        $this->assertSame($psr7, MeshClient::isHeaderValue($value), 'preflight and PSR-7 disagree');
        $this->assertSame($psr7, MeshClient::headerValueRefusal($value) === null);
    }

    /** #6054: the reason names what was measured, for each kind of refusal. */
    public function test_the_refusal_reason_fits_the_value(): void
    {
        $this->assertSame('holds a character an HTTP header cannot carry', MeshClient::headerValueRefusal("SECRET_FIXTURE\n"));
        $this->assertSame('is not a value an HTTP header can carry', MeshClient::headerValueRefusal(new \stdClass));
        // Stricter than PSR-7 on purpose: PSR-7 reads an array as a LIST of
        // header values; one API key is one value.
        $this->assertSame('is not a value an HTTP header can carry', MeshClient::headerValueRefusal(['SECRET_FIXTURE-6055']));
        $this->assertNull(MeshClient::headerValueRefusal(60556055), 'an int key is not refused');
    }

    /**
     * #6055 end to end: a key that is "\n"-terminated only is refused in
     * preflight with nothingSent, not left to PSR-7's class-only arm; an
     * int key reaches the handler as its decimal text (#6054).
     */
    public function test_the_write_client_refuses_a_trailing_lf_key_and_sends_an_int_key(): void
    {
        $mock = new MockHandler([new Response(200, [], '{"results":[]}')]);
        try {
            (new MeshWriteClient(['api_key' => "SECRET_FIXTURE-6055\n"], new GuzzleClient(['handler' => HandlerStack::create($mock)])))
                ->listCustomerRules('11111111-2222-3333-4444-555555555555');
            $this->fail('the key must be refused');
        } catch (MeshClientException $e) {
            $this->assertSame('The Mesh API key holds a character an HTTP header cannot carry; nothing was sent.', $e->getMessage());
            $this->assertTrue($e->nothingWasSent());
        }
        $this->assertSame(1, $mock->count(), 'no request reached the handler');

        $seen = null;
        $mock = new MockHandler([function (RequestInterface $request) use (&$seen) {
            $seen = $request->getHeaderLine('API-KEY');

            return new Response(200, [], '{"results":[],"count":0}');
        }]);
        (new MeshWriteClient(['api_key' => 60556055], new GuzzleClient(['handler' => HandlerStack::create($mock)])))
            ->listCustomerRules('11111111-2222-3333-4444-555555555555');
        $this->assertSame('60556055', $seen, 'the int key was sent as PSR-7 casts it');
    }
}
