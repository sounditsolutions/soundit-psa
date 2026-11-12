<?php

namespace Tests\Feature\People;

use App\Enums\PersonType;
use App\Models\Client;
use App\Models\Person;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PeopleStatusFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->actingAs(User::factory()->create());
    }

    private function person(Client $client, bool $active): Person
    {
        return Person::create([
            'client_id' => $client->id,
            'first_name' => $active ? 'ExampleActive' : 'ExampleInactive',
            'last_name' => 'Contact',
            'person_type' => PersonType::User,
            'is_active' => $active,
        ]);
    }

    public static function statuses(): array
    {
        $cases = [];
        foreach (['people.index', 'clients.people'] as $route) {
            foreach ([null, 'active', 'inactive', 'all', 'unknown'] as $status) {
                $cases[$route.'.'.($status ?? 'default')] = [$route, $status];
            }
        }

        return $cases;
    }

    #[DataProvider('statuses')]
    public function test_status_filters_rows_and_badges(string $route, ?string $status): void
    {
        $client = Client::create(['name' => 'Example Company']);
        $active = $this->person($client, true);
        $inactive = $this->person($client, false);
        $other = $this->person(Client::create(['name' => 'Other Example Company']), false);
        $params = $route === 'clients.people' ? ['client' => $client->id] : [];
        if ($status !== null) {
            $params['status'] = $status;
        }
        $response = $this->get(route($route, $params))->assertOk();
        $chosen = in_array($status, ['inactive', 'all'], true) ? $status : 'active';
        $expected = match ($chosen) {
            'inactive' => [$inactive->id],
            'all' => [$active->id, $inactive->id],
            default => [$active->id],
        };
        if ($route === 'people.index' && $chosen !== 'active') {
            $expected[] = $other->id;
        }
        $this->assertEqualsCanonicalizing($expected, $response->viewData('people')->pluck('id')->all());
        $response->assertViewHas($route === 'people.index' ? 'status' : 'peopleStatus', $chosen);
        $dom = new \DOMDocument;
        @$dom->loadHTML($response->getContent());
        $xpath = new \DOMXPath($dom);
        $this->assertSame(1, $xpath->query('//select[@name="status"]/option[@value="'.$chosen.'" and @selected]')->length);
        foreach (['//tbody/tr', '//div[@class="data-row"]'] as $selector) {
            $rows = $xpath->query('//form[@id="bulkTypeForm"]'.$selector);
            $seen = 0;
            foreach ($rows as $row) {
                if (! str_contains($row->textContent, 'ExampleActive') && ! str_contains($row->textContent, 'ExampleInactive')) {
                    continue;
                }
                $seen++;
                $badges = $xpath->query('.//span[contains(@class,"badge") and normalize-space(.)="Inactive"]', $row);
                $this->assertSame(str_contains($row->textContent, 'ExampleInactive') ? 1 : 0, $badges->length);
            }
            $this->assertSame(count($expected), $seen);
        }
        if ($chosen !== 'active') {
            $response->assertSee('title="Clear"', false);
        }
    }

    public static function routes(): array
    {
        return [['people.index'], ['clients.people']];
    }

    #[DataProvider('routes')]
    public function test_pagination_retains_status(string $route): void
    {
        $client = Client::create(['name' => 'Example Company']);
        $this->person($client, false);
        $params = ['status' => 'inactive'];
        if ($route === 'clients.people') {
            $params['client'] = $client->id;
        }
        $response = $this->get(route($route, $params))->assertOk();
        parse_str(parse_url($response->viewData('people')->url(2), PHP_URL_QUERY), $query);
        $this->assertSame('inactive', $query['status'] ?? null);
    }

    #[DataProvider('routes')]
    public function test_status_only_empty_filter_has_clear_and_filtered_message(string $route): void
    {
        $client = Client::create(['name' => 'Example Company']);
        $params = ['status' => 'inactive'];
        if ($route === 'clients.people') {
            $params['client'] = $client->id;
        }
        $this->get(route($route, $params))->assertOk()
            ->assertSee('title="Clear"', false)
            ->assertSeeText('No contacts match your filters.');
    }
}
