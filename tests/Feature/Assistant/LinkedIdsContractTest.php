<?php

namespace Tests\Feature\Assistant;

use App\Models\Asset;
use App\Models\Client;
use App\Models\Ticket;
use App\Services\Assistant\AssistantToolDefinitions;
use App\Services\Assistant\AssistantToolExecutor;
use App\Services\Assistant\LinkedIds;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * KKaM3bWS item 3: the schema contract for linked_ids.
 *
 * Every *_id column and every declared foreign-key column on clients, assets
 * and tickets — READ FROM THE MIGRATED SCHEMA AT TEST TIME, never a hard-coded
 * list — must be published in its detail tool's linked_ids, or sit on
 * LinkedIds::DENYLIST with a non-empty reason. A new column with neither fails
 * here, which is the point: on 9/30 two ids existed and no read exposed them.
 */
class LinkedIdsContractTest extends TestCase
{
    use RefreshDatabase;

    /** table => [published-columns constant, detail tool] */
    private const SURFACES = [
        'clients' => LinkedIds::CLIENT_COLUMNS,
        'assets' => LinkedIds::ASSET_COLUMNS,
        'tickets' => LinkedIds::TICKET_COLUMNS,
    ];

    public function test_get_asset_description_names_every_linked_id(): void
    {
        $tools = array_column(AssistantToolDefinitions::getTools(true), null, 'name');
        $this->assertArrayHasKey('get_asset', $tools);
        $this->assertNotEmpty(LinkedIds::ASSET_COLUMNS);
        foreach (LinkedIds::ASSET_COLUMNS as $column) {
            $this->assertMatchesRegularExpression('/\\b'.preg_quote($column, '/').'\\b/', $tools['get_asset']['description'], $column);
        }
        $list = self::linkedIdsList($tools['get_asset']['description'], 'device');
        foreach (LinkedIds::ASSET_COLUMNS as $column) {
            $this->assertMatchesRegularExpression('/\\b'.preg_quote($column, '/').'\\b/', $list, "{$column} must be named inside get_asset's parenthesised linked_ids list, not only in the prose around it");
        }
    }

    public function test_get_client_description_names_every_linked_id(): void
    {
        $tools = array_column(AssistantToolDefinitions::getTools(true), null, 'name');
        $this->assertArrayHasKey('get_client', $tools);
        $this->assertNotEmpty(LinkedIds::CLIENT_COLUMNS);
        foreach (LinkedIds::CLIENT_COLUMNS as $column) {
            $this->assertMatchesRegularExpression('/\\b'.preg_quote($column, '/').'\\b/', $tools['get_client']['description'], $column);
        }
        $list = self::linkedIdsList($tools['get_client']['description'], 'client');
        foreach (LinkedIds::CLIENT_COLUMNS as $column) {
            $this->assertMatchesRegularExpression('/\\b'.preg_quote($column, '/').'\\b/', $list, "{$column} must be named inside get_client's parenthesised linked_ids list, not only in the prose around it");
        }
    }

    public function test_only_top_level_columns_are_listed_without_truncating_after_nested_asides(): void
    {
        $list = self::linkedIdsList('linked_ids: every PSA, foreign-key and integration id on the device (id, client_id (see also synthetic_only_id (and synthetic_deep_id)), site_id, asset_id (see synthetic_other_id), ticket_id)', 'device');

        foreach (['synthetic_only_id', 'synthetic_deep_id', 'synthetic_other_id'] as $column) {
            $this->assertDoesNotMatchRegularExpression('/\\b'.preg_quote($column, '/').'\\b/', $list, "{$column} is mentioned only inside an aside, not listed");
        }
        foreach (['id', 'client_id', 'site_id', 'asset_id', 'ticket_id'] as $column) {
            $this->assertMatchesRegularExpression('/\\b'.preg_quote($column, '/').'\\b/', $list, "{$column} is listed at the top level, including after nested asides");
        }
        $this->assertSame('id, client_id , site_id, asset_id , ticket_id', $list);
    }

    /**
     * The list's top-level text after "linked_ids: every PSA, foreign-key and
     * integration id on the <subject> (", with nested parenthesised asides
     * removed, including their parentheses. A column named only inside an
     * aside is mentioned, not listed (#7219). Balanced capture still ends at
     * the list's own closing parenthesis: an aside does not end it early
     * (#7206). get_asset also names merged_into_asset_id and tactical_asset_id
     * in prose outside that list, so a whole-description match cannot tell a
     * listed column from a mentioned one.
     */
    private static function linkedIdsList(string $description, string $subject): string
    {
        $found = preg_match('/linked_ids: every PSA, foreign-key and integration id on the '.$subject.' \\(((?:[^()]++|\\((?1)\\))*)\\)/', $description, $m);
        self::assertSame(1, $found, "the description must introduce linked_ids with a parenthesised list for the {$subject}");

        return preg_replace('/\\((?:[^()]++|(?R))*\\)/', '', $m[1]);
    }

    /** The columns the contract governs, derived from the live schema. */
    private static function governedColumns(string $table): array
    {
        $idShaped = array_filter(
            Schema::getColumnListing($table),
            fn (string $c) => str_ends_with($c, '_id'),
        );
        $foreignKeys = [];
        foreach (Schema::getForeignKeys($table) as $fk) {
            foreach ($fk['columns'] as $c) {
                $foreignKeys[] = $c;
            }
        }

        $all = array_values(array_unique(array_merge($idShaped, $foreignKeys)));
        sort($all);

        return $all;
    }

    /** Governed columns that are neither published nor denylisted. */
    private static function uncovered(string $table, array $published): array
    {
        $denied = array_keys(LinkedIds::DENYLIST[$table] ?? []);

        return array_values(array_diff(self::governedColumns($table), $published, $denied));
    }

    public function test_the_schema_read_is_not_vacuous(): void
    {
        // Positive control: the instrument must see columns we know exist,
        // or every "nothing uncovered" below would be a zero from a blind read.
        $this->assertContains('huntress_organization_id', self::governedColumns('clients'));
        $this->assertContains('screenconnect_session_id', self::governedColumns('assets'));
        $this->assertContains('contact_id', self::governedColumns('tickets'));
        // FK-only (not *_id shaped) columns are reached through getForeignKeys.
        $this->assertContains('created_by', self::governedColumns('tickets'));
        $this->assertContains('credentials_updated_by', self::governedColumns('clients'));
    }

    public function test_every_id_column_is_published_or_denylisted(): void
    {
        foreach (self::SURFACES as $table => $published) {
            $this->assertSame([], self::uncovered($table, $published),
                "{$table}: id/FK column(s) neither in linked_ids nor on LinkedIds::DENYLIST with a reason");
        }
    }

    public function test_every_denylist_entry_names_a_real_column_and_a_reason(): void
    {
        foreach (LinkedIds::DENYLIST as $table => $entries) {
            $this->assertArrayHasKey($table, self::SURFACES);
            foreach ($entries as $column => $reason) {
                $this->assertTrue(Schema::hasColumn($table, $column), "{$table}.{$column} is denylisted but does not exist");
                $this->assertIsString($reason);
                $this->assertGreaterThanOrEqual(20, mb_strlen(trim($reason)), "{$table}.{$column}: a denylist entry needs a real reason");
                $this->assertNotContains($column, self::SURFACES[$table], "{$table}.{$column} is both denylisted and published");
            }
        }
    }

    public function test_every_published_column_exists(): void
    {
        foreach (self::SURFACES as $table => $published) {
            foreach ($published as $column) {
                $this->assertTrue(Schema::hasColumn($table, $column), "{$table}.{$column} is published but is not a column");
            }
        }
    }

    public function test_a_new_id_column_without_a_decision_fails_the_contract(): void
    {
        // The guard must be able to fail: add an undecided integration column
        // and the uncovered set must name it.
        Schema::table('clients', fn (Blueprint $t) => $t->string('acme_widget_customer_id')->nullable());

        $this->assertSame(['acme_widget_customer_id'], self::uncovered('clients', LinkedIds::CLIENT_COLUMNS));
    }

    public function test_the_tools_actually_emit_every_published_column(): void
    {
        // Binds the constants to the tool OUTPUT: a column listed above but
        // dropped by the tool's builder must fail here, not just in review.
        $client = Client::factory()->create();
        $asset = Asset::factory()->create(['client_id' => $client->id]);
        $ticket = Ticket::factory()->create(['client_id' => $client->id]);
        $x = new AssistantToolExecutor(clientId: $client->id);

        $outputs = [
            'clients' => $x->execute('get_client', [])['linked_ids'],
            'assets' => $x->execute('get_asset', ['asset_id' => $asset->id])['linked_ids'],
            'tickets' => $x->execute('get_ticket_detail', ['ticket_id' => $ticket->id])['linked_ids'],
        ];

        foreach (self::SURFACES as $table => $published) {
            foreach ($published as $column) {
                $this->assertArrayHasKey($column, $outputs[$table], "{$table}.{$column} missing from its tool's linked_ids");
            }
            foreach (array_keys(LinkedIds::DENYLIST[$table] ?? []) as $denied) {
                $this->assertArrayNotHasKey($denied, $outputs[$table], "{$table}.{$denied} is denylisted but served");
            }
        }
    }
}
