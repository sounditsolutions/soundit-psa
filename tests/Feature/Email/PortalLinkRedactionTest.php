<?php

namespace Tests\Feature\Email;

use App\Enums\PersonType;
use App\Models\Client;
use App\Models\Email;
use App\Models\Person;
use App\Models\Setting;
use App\Models\User;
use App\Services\Graph\GraphClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class PortalLinkRedactionTest extends TestCase
{
    use RefreshDatabase;

    private array $history = [];

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        Http::preventStrayRequests();
        Http::fake();
        $handler = HandlerStack::create(new MockHandler([new Response(202)]));
        $handler->push(Middleware::history($this->history));
        $cache = new Repository(new ArrayStore);
        $cache->put('graph_api_token', 'synthetic-test-token', 3600);
        $this->app->instance(GraphClient::class, new GraphClient([
            'request_timeout' => 1,
            'token_timeout' => 1,
            'handler' => $handler,
        ], $cache));
        Setting::setValue('graph_mailbox', 'sender@example.test');
        Setting::setValue('portal_enabled', '1');
    }

    private function contact(): Person
    {
        return Person::create([
            'client_id' => Client::factory()->create()->id,
            'person_type' => PersonType::User,
            'first_name' => 'Synthetic',
            'last_name' => 'Contact',
            'email' => 'recipient@example.test',
            'is_active' => true,
            'portal_enabled' => false,
        ]);
    }

    public function test_access_verification_redacts_storage_but_not_graph(): void
    {
        $this->freezeTime();
        $person = $this->contact();
        $url = URL::temporarySignedRoute('portal.verify-access', now()->addMinutes(60), ['person' => $person->id]);
        parse_str(parse_url($url, PHP_URL_QUERY), $query);

        $this->post('/portal/request-access', ['email' => $person->email])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertLinkRedacted($url, $query['signature']);
    }

    public function test_staff_invitation_redacts_storage_but_not_graph(): void
    {
        $person = $this->contact();
        $this->actingAs(User::factory()->create())
            ->post(route('clients.portal.invite', $person->client_id), ['person_id' => $person->id])
            ->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('success');

        $html = $this->graphHtml();
        $this->assertSame(1, preg_match('~https?://[^\s"<>]+/portal/reset-password/[^\s"<>]+~', html_entity_decode($html), $matches));
        $url = $matches[0];
        $token = basename(parse_url($url, PHP_URL_PATH));
        $this->assertNotEmpty($token);
        $this->assertTrue(Password::broker('portal')->tokenExists($person->fresh(), $token), 'the invite link carries the live broker token');
        $this->assertSame(url(route('portal.password.reset', ['token' => $token, 'email' => $person->email], false)), $url);
        $this->assertLinkRedacted($url, $token);
    }

    public function test_password_notification_redacts_storage_but_not_graph(): void
    {
        $person = $this->contact();
        $token = str_repeat('c', 64);
        $url = url(route('portal.password.reset', ['token' => $token, 'email' => $person->email], false));

        $person->sendPasswordResetNotification($token);

        $this->assertLinkRedacted($url, $token);
    }

    private function graphHtml(): string
    {
        $this->assertCount(1, $this->history);
        $payload = json_decode((string) $this->history[0]['request']->getBody(), true, flags: JSON_THROW_ON_ERROR);

        return $payload['message']['body']['content'];
    }

    private function assertLinkRedacted(string $url, string $token): void
    {
        $this->assertStringContainsString($url, html_entity_decode($this->graphHtml()));
        $email = Email::sole();
        foreach (['body_text', 'body_html', 'body_preview'] as $column) {
            $this->assertStringNotContainsString($token, $email->$column, $column);
            $this->assertStringNotContainsString($url, $email->$column, $column);
            $this->assertStringContainsString('[portal link]', $email->$column, $column);
        }
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        foreach ($query as $key => $value) {
            $this->assertStringNotContainsString($key.'=', $email->body_html);
            $this->assertStringNotContainsString(rawurlencode($value), $email->body_html);
        }
        $this->assertStringNotContainsString($token, $email->makeVisible('body_html')->toJson());
    }
}
