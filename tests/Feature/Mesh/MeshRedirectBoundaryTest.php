<?php

namespace Tests\Feature\Mesh;

use App\Services\Mcp\StaffMeshAdminToolExecutor;
use App\Services\Mesh\MeshClient;
use App\Services\Mesh\MeshClientException;
use App\Services\Mesh\MeshWriteClient;
use App\Services\Mesh\MeshWriteRejectedException;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * #6158, #6159: the 3xx arm of both Mesh clients at its boundaries, and
 * the executor's createMayHaveCommitted() at 300, 399 and 400.
 *
 *  - '(redirect not followed)' only for a redirect status (300, 301, 302,
 *    303, 307, 308) that carries a Location; 304, 305, 306, and any 3xx
 *    without a Location, name the status only (#6158). Every redirect
 *    status has a row (#6222), and an empty Location names no target
 *    (#6224), nor do several empty ones (#6284).
 *  - statusPhrase() words every status as the configured host (or
 *    something in front of it) answering (#6220, #6290).
 *  - each line is read with its level: the 3xx failure is an error
 *    (#6223, G-14 addendum: a demotion to info or debug fails).
 *  - 300 and 399 are failures by status in both clients; 299 is not a
 *    3xx and is decoded as an answer (#6159: kills `>= 300` -> `> 300`
 *    and `< 400` -> `< 399`).
 *  - createMayHaveCommitted(): 300 and 399 may have committed; a plain
 *    400 and 499 did not (#6159: kills `>= 300` -> `> 300` and `< 400` ->
 *    `<= 400`).
 *
 * G-5: MockHandler only, stray Http requests prevented. Synthetic data
 * (G-13): example.test hosts, a SECRET_FIXTURE key, a synthetic tenant.
 */
class MeshRedirectBoundaryTest extends TestCase
{
    private const HOST = 'mesh-3xx.example.test';

    private const KEY = 'SECRET_FIXTURE-6159';

    private const TENANT = '11111111-2222-3333-4444-555555555555';

    /** @var list<string> level, then message */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Event::listen(MessageLogged::class, function (MessageLogged $e): void {
            $this->logged[] = $e->level.' '.$e->message;
        });
    }

    /**
     * @return array<string, array{0: int, 1: bool, 2: string}> status, Location?, line suffix
     */
    public static function threeHundreds(): array
    {
        $note = ' (redirect not followed)';

        return [
            '300 with Location' => [300, true, $note],
            '300 without Location' => [300, false, ''],
            '302 with Location' => [302, true, $note],
            '302 without Location' => [302, false, ''],
            '301 with Location' => [301, true, $note],
            '303 with Location' => [303, true, $note],
            '307 with Location' => [307, true, $note],
            '307 without Location' => [307, false, ''],
            '304 with Location' => [304, true, ''],
            '305 with Location' => [305, true, ''],
            '306 with Location' => [306, true, ''],
            '308 with Location' => [308, true, $note],
            '399 with Location' => [399, true, ''],
        ];
    }

    private function guzzle(Response $answer): array
    {
        $mock = new MockHandler([$answer, new Response(200, [], '{"results":[]}')]);

        return [new GuzzleClient(['base_uri' => 'https://'.self::HOST.'/', 'handler' => HandlerStack::create($mock)]), $mock];
    }

    private static function answer(int $status, bool $location): Response
    {
        return new Response($status, $location ? ['Location' => 'https://elsewhere-6158.example.test/x'] : [], '{"results":[{"id":"from-3xx"}]}');
    }

    /**
     * Empty Location values as a Guzzle Response holds them. #6285: no
     * 'spaces only' row: PSR-7 trims spaces and tabs when the Response is
     * built, so '   ' is stored as '' and would only repeat 'empty'; the
     * trim is driven below through a response that does not trim.
     * #6284: several empty values, which getHeaderLine() joins as ', '.
     *
     * @return array<string, array{0: list<string>}>
     */
    public static function emptyLocations(): array
    {
        return ['empty' => [['']], 'two empty values' => [['', '']], 'three empty values' => [['', '', '']]];
    }

    /** #6224, #6284: a Location with no value, or several, offered no target to follow. */
    #[DataProvider('emptyLocations')]
    public function test_an_empty_location_is_not_named_a_redirect(array $values): void
    {
        foreach ([302, 307] as $status) {
            $response = new Response($status, ['Location' => $values]);
            $this->assertSame($values, $response->getHeader('Location'), 'premise: the values as built');
            $this->assertSame('', MeshClient::redirectNote($response), "HTTP {$status}");
        }
        $this->assertSame(' (redirect not followed)', MeshClient::redirectNote(new Response(302, ['Location' => '/x'])), 'positive control');
        $this->assertSame(' (redirect not followed)', MeshClient::redirectNote(new Response(302, ['Location' => ['', '/x']])), 'positive control: one non-empty value among empty ones');
    }

    /**
     * #6285: redirectNote() trims each value of spaces and tabs itself,
     * for a ResponseInterface that does not trim (a Guzzle Response
     * would). Driven with a double holding untrimmed blanks; a non-blank
     * value is still named a redirect.
     */
    public function test_a_blank_location_from_a_response_that_does_not_trim_is_not_named_a_redirect(): void
    {
        $untrimmed = function (array $values): \Psr\Http\Message\ResponseInterface {
            $response = \Mockery::mock(\Psr\Http\Message\ResponseInterface::class);
            $response->allows('getStatusCode')->andReturn(302);
            $response->allows('getHeader')->with('Location')->andReturn($values);
            $response->allows('getHeaderLine')->with('Location')->andReturn(implode(', ', $values));

            return $response;
        };

        $this->assertSame('', MeshClient::redirectNote($untrimmed(['   '])), 'spaces only');
        $this->assertSame('', MeshClient::redirectNote($untrimmed(["\t", ' '])), 'a tab and a space');
        $this->assertSame(' (redirect not followed)', MeshClient::redirectNote($untrimmed([' /x '])), 'positive control');
    }

    #[DataProvider('threeHundreds')]
    public function test_the_read_client_fails_every_3xx_and_names_a_redirect_only_when_one_was_offered(int $status, bool $location, string $suffix): void
    {
        [$guzzle, $mock] = $this->guzzle(self::answer($status, $location));
        $client = new MeshClient(['api_key' => self::KEY, 'base_url' => 'https://'.self::HOST]);
        (new \ReflectionProperty($client, 'http'))->setValue($client, $guzzle);

        try {
            $client->get('api/customers/', ['_size' => 1]);
            $this->fail("HTTP {$status} must fail the read");
        } catch (MeshClientException $e) {
            $this->assertSame($status, $e->getCode());
            $this->assertSame("Mesh API error: GET api/customers/ failed with HTTP {$status}{$suffix}", $e->getMessage());
        }
        $this->assertSame(["error [MeshClient] GET api/customers/ failed with HTTP {$status}{$suffix}"], $this->logged);
        $this->assertSame(1, $mock->count(), 'one request only');
    }

    #[DataProvider('threeHundreds')]
    public function test_the_write_client_fails_every_3xx_and_names_a_redirect_only_when_one_was_offered(int $status, bool $location, string $suffix): void
    {
        [$guzzle, $mock] = $this->guzzle(self::answer($status, $location));
        $client = new MeshWriteClient(['api_key' => self::KEY], $guzzle);

        try {
            $client->createAllowRule(self::TENANT, 'billing@vendor.example.test', 'PSA allow 6159', null);
            $this->fail("HTTP {$status} must fail the create");
        } catch (MeshClientException $e) {
            $this->assertSame($status, $e->getCode());
            $this->assertFalse($e->nothingWasSent());
            $this->assertSame("Mesh API error: POST api/rule-allows-blocks/ failed with HTTP {$status}{$suffix}", $e->getMessage());
        }
        $this->assertSame(["error [MeshWriteClient] POST api/rule-allows-blocks/ failed with HTTP {$status}{$suffix}"], $this->logged);
        $this->assertSame(1, $mock->count(), 'one request only');
    }

    /** 299 is below the 3xx arm: both clients decode it as an answer. */
    public function test_a_299_is_not_taken_by_the_3xx_arm(): void
    {
        [$guzzle] = $this->guzzle(new Response(299, [], '{"added_for":["'.self::TENANT.'"]}'));
        $created = (new MeshWriteClient(['api_key' => self::KEY], $guzzle))
            ->createAllowRule(self::TENANT, 'billing@vendor.example.test', 'PSA allow 6159', null);
        $this->assertSame(['added_for' => [self::TENANT]], $created);

        [$guzzle] = $this->guzzle(new Response(299, [], '{"results":[]}'));
        $client = new MeshClient(['api_key' => self::KEY, 'base_url' => 'https://'.self::HOST]);
        (new \ReflectionProperty($client, 'http'))->setValue($client, $guzzle);
        $this->assertSame(['results' => []], $client->get('api/customers/'));
        $this->assertSame([], $this->logged);
    }

    /** @return array<string, array{0: int, 1: string}> status, statusPhrase() */
    public static function phrases(): array
    {
        $host = 'the Mesh host (or something in front of it) answered the create with HTTP ';

        return [
            '299' => [299, $host.'299'],
            '300' => [300, $host.'300'],
            '399' => [399, $host.'399'],
            '400' => [400, $host.'400'],
            '502' => [502, $host.'502'],
        ];
    }

    /**
     * #6220, #6290: every status is worded as the host 'or something in
     * front of it' (who answered is not measured for any status).
     */
    #[DataProvider('phrases')]
    public function test_the_status_phrase_names_the_host_for_every_status(int $status, string $phrase): void
    {
        $this->assertSame($phrase, (new MeshClientException('x', $status))->statusPhrase('the create'));
    }

    /** @return array<string, array{0: MeshClientException, 1: bool}> */
    public static function createOutcomes(): array
    {
        return [
            '299 (not a 3xx)' => [new MeshClientException('x', 299), false],
            '300' => [new MeshClientException('x', 300), true],
            '399' => [new MeshClientException('x', 399), true],
            'plain 400' => [new MeshClientException('x', 400), false],
            'rejected 400' => [new MeshWriteRejectedException('Mesh refused the request (HTTP 400).'), false],
            '499' => [new MeshClientException('x', 499), false],
            '500' => [new MeshClientException('x', 500), true],
            '0, sent' => [new MeshClientException('x', 0), true],
            '0, nothing sent' => [new MeshClientException('x', 0, nothingSent: true), false],
        ];
    }

    #[DataProvider('createOutcomes')]
    public function test_create_may_have_committed_at_its_boundaries(MeshClientException $e, bool $mayHave): void
    {
        // The predicate reads only the exception; no client or database.
        $executor = (new \ReflectionClass(StaffMeshAdminToolExecutor::class))->newInstanceWithoutConstructor();

        $this->assertSame($mayHave, (new \ReflectionMethod($executor, 'createMayHaveCommitted'))->invoke($executor, $e));
    }
}
