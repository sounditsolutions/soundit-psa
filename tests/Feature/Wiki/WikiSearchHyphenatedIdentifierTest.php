<?php

namespace Tests\Feature\Wiki;

use App\Models\Client;
use App\Models\Setting;
use App\Models\WikiFact;
use App\Models\WikiPage;
use App\Services\Wiki\WikiSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Regression: wiki search must find exact hyphenated identifiers — switch tags,
 * hostnames, serials such as "QA-SW-A". (bd psa-qxu1)
 *
 * Root cause was MariaDB-only. FULLTEXT natural-language mode tokenizes on the
 * hyphen ("QA-SW-A" -> QA / SW / A) and drops every token shorter than
 * innodb_ft_min_token_size (default 3), so MATCH ... AGAINST returned zero rows
 * while a plain word from the same fact ("confirm") matched. The fix adds a
 * literal-term LIKE fallback so the exact string is always searchable.
 *
 * The test DB is SQLite (see phpunit.xml) with no FULLTEXT index, so a plain
 * behavioral assertion cannot exercise the buggy MariaDB path. We therefore pin
 * BOTH sides: the engine-agnostic behavioral contract on the default connection,
 * and the compiled MariaDB SQL — proving the literal LIKE fallback is emitted
 * beside MATCH ... AGAINST for both the facts and pages queries.
 */
class WikiSearchHyphenatedIdentifierTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::setValue('wiki_enabled', '1');
    }

    public function test_search_finds_fact_by_exact_hyphenated_identifier(): void
    {
        $client = Client::factory()->create();
        $page = WikiPage::factory()->forClient($client)->create([
            'slug' => 'network', 'title' => 'Network', 'body_md' => 'Core switching.',
        ]);
        WikiFact::factory()->create([
            'client_id' => $client->id, 'page_id' => $page->id,
            'subject_key' => 'asset:qa-sw-a:role',
            'statement' => 'QA run confirm target uses switch QA-SW-A.',
        ]);

        $results = app(WikiSearchService::class)->search('QA-SW-A', $client->id);

        $this->assertCount(1, $results['facts'], 'exact hyphenated identifier should return its fact');
        $this->assertStringContainsString('QA-SW-A', $results['facts']->first()->statement);
    }

    public function test_mariadb_query_emits_literal_like_fallback_beside_fulltext(): void
    {
        // Compile against the MariaDB grammar without a live server: toSql() never
        // opens a PDO connection, so this guards the MariaDB-only code path even
        // in the SQLite CI environment (which the FULLTEXT bug can't otherwise reach).
        config(['database.connections.mariadb_probe' => [
            'driver' => 'mariadb',
            'host' => '127.0.0.1',
            'port' => 3306,
            'database' => 'unused_for_compilation',
            'username' => 'probe',
            'password' => 'probe',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
        ]]);

        $service = new WikiSearchService;
        $textMatch = new ReflectionMethod($service, 'textMatch');
        $textMatch->setAccessible(true);

        foreach ([['wiki_facts', ['statement']], ['wiki_pages', ['title', 'body_md']]] as [$table, $columns]) {
            $builder = DB::connection('mariadb_probe')->table($table)
                ->where(fn ($q) => $textMatch->invoke($service, $q, $columns, 'QA-SW-A'));

            $sql = strtolower($builder->toSql());

            $this->assertStringContainsString('match', $sql, "{$table} query should still use FULLTEXT");
            $this->assertStringContainsString('against', $sql, "{$table} query should still use FULLTEXT");
            $this->assertStringContainsString('like', $sql, "{$table} query needs a literal LIKE fallback for hyphenated identifiers");
            $this->assertContains('%QA-SW-A%', $builder->getBindings(), "{$table} LIKE fallback must bind the literal identifier");
        }
    }

    public function test_like_fallback_escapes_wildcards_and_backslash(): void
    {
        // A user-supplied term must be matched literally: LIKE wildcards (% and _) and
        // the escape char (\) all have to be escaped, or the fallback would over-match.
        $service = new WikiSearchService;
        $textMatch = new ReflectionMethod($service, 'textMatch');
        $textMatch->setAccessible(true);

        $builder = DB::table('wiki_facts')
            ->where(fn ($q) => $textMatch->invoke($service, $q, ['statement'], 'A%B_C\\D'));

        $bindings = implode('|', array_map('strval', $builder->getBindings()));

        $this->assertStringContainsString('\\%', $bindings, 'percent wildcard must be escaped');
        $this->assertStringContainsString('\\_', $bindings, 'underscore wildcard must be escaped');
        $this->assertStringContainsString('\\\\', $bindings, 'backslash must be escaped before the wildcards');
    }
}
