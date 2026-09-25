<?php

namespace Tests\Feature\ContactIntake;

use App\Models\ContactSubmission;
use App\Models\Setting;
use App\Services\ContactIntake\SubmissionAuthenticator;
use App\Services\ContactIntake\SubmissionLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SubmissionLedgerTest extends TestCase
{
    use RefreshDatabase;

    private function payload(): array
    {
        return ['submission_id' => (string) Str::uuid(), 'name' => 'Synthetic Visitor',
            'email' => 'visitor@example.test', 'message' => 'Synthetic inquiry',
            'submitted_at' => '2026-09-24T12:00:00Z'];
    }

    public function test_non_bmp_payload_exceeds_text_but_fits_declared_mysql_column(): void
    {
        Setting::setValue('contact_intake_enabled', '1');
        $data = $this->payload();
        $data['message'] = str_repeat('😀', 16000);
        $data['inquiry'] = str_repeat('a', 64);
        app(SubmissionLedger::class)->accept('website', $data);
        $stored = DB::table('contact_submissions')->value('payload');
        $this->assertGreaterThan(65535, strlen($stored)); // TEXT is insufficient.
        $this->assertSame($data['message'], ContactSubmission::firstOrFail()->payload['message']);
        $connection = new \Illuminate\Database\MySqlConnection(new \PDO('sqlite::memory:'));
        $connection->setSchemaGrammar(new \Illuminate\Database\Schema\Grammars\MySqlGrammar($connection));
        $schema = \Mockery::mock(\Illuminate\Database\Schema\Builder::class);
        $schema->shouldReceive('create')->twice()->andReturnUsing(function ($name, $callback) use ($connection, $stored) {
            $blueprint = new \Illuminate\Database\Schema\Blueprint($connection, $name);
            $blueprint->create();
            $callback($blueprint);
            if ($name === 'contact_submissions') {
                $sql = implode(' ', $blueprint->toSql());
                $this->assertStringContainsString('`payload` mediumtext', $sql);
                $this->assertLessThanOrEqual(16777215, strlen($stored));
            }
        });
        \Illuminate\Support\Facades\Schema::swap($schema);
        (require database_path('migrations/2026_09_24_221000_create_contact_submission_ledger.php'))->up();
    }

    public function test_inquiry_is_preserved_and_changes_conflict(): void
    {
        Setting::setValue('contact_intake_enabled', '1');
        $data = $this->payload() + ['inquiry' => 'future_unknown-slug'];
        $first = app(SubmissionLedger::class)->accept('website', $data);
        $this->assertFalse($first['conflict']);
        $this->assertSame('future_unknown-slug', ContactSubmission::firstOrFail()->payload['inquiry']);
        $data['inquiry'] = 'different';
        $this->assertTrue(app(SubmissionLedger::class)->accept('website', $data)['conflict']);
        $this->assertSame('future_unknown-slug', ContactSubmission::firstOrFail()->payload['inquiry']);
    }

    public function test_flag_off_refuses_ledger_write(): void
    {
        try {
            app(SubmissionLedger::class)->accept('website', $this->payload());
            $this->fail('Disabled ledger accepted input');
        } catch (\DomainException $e) {
            $this->assertSame('Contact intake is disabled.', $e->getMessage());
        }
        $this->assertDatabaseCount('contact_submissions', 0);
    }

    public function test_retries_reuse_receipt_conflicts_preserve_payload_and_distinct_inquiries_share_identity(): void
    {
        Setting::setValue('contact_intake_enabled', '1');
        $service = app(SubmissionLedger::class);
        $data = $this->payload();
        $first = $service->accept('website', $data);
        $this->assertFalse($first['conflict']);
        $this->assertFalse($first['duplicate']);
        $this->assertSame(array_replace($first, ['duplicate' => true]), $service->accept('website', array_reverse($data, true)));
        $changed = $data;
        $changed['message'] = 'Changed message';
        $this->assertSame(['receipt' => $first['receipt'], 'conflict' => true, 'duplicate' => true], $service->accept('website', $changed));
        $this->assertSame($data['message'], ContactSubmission::firstOrFail()->payload['message']);
        $this->assertSame(1, ContactSubmission::firstOrFail()->conflicts);
        $this->assertStringNotContainsString('Synthetic inquiry', DB::table('contact_submissions')->value('payload'));
        $data['submission_id'] = (string) Str::uuid();
        $second = $service->accept('website', $data);
        $this->assertNotSame($first['receipt'], $second['receipt']);
        $this->assertDatabaseCount('contact_submissions', 2);
        $this->assertDatabaseCount('contact_intake_identities', 1);
        $this->assertDatabaseCount('tickets', 0);
    }

    public function test_authentication_binds_raw_body_path_method_key_and_timestamp_and_revocation(): void
    {
        $this->freezeTime();
        $secret = str_repeat('synthetic-only-', 4);
        Setting::setEncrypted('contact_intake_keys', json_encode(['website' => ['secret' => $secret, 'revoked' => false]]));
        $body = json_encode($this->payload());
        $timestamp = (string) now()->timestamp;
        $path = SubmissionAuthenticator::PATH;
        $signature = hash_hmac('sha256', implode("\n", ['POST', $path, 'website', $timestamp, hash('sha256', $body)]), $secret);
        $auth = app(SubmissionAuthenticator::class);
        $this->assertFalse($auth->accepts('POST', $path, $body, 'website', $timestamp, $signature));
        Setting::setValue('contact_intake_enabled', '1');
        $this->assertTrue($auth->accepts('POST', $path, $body, 'website', $timestamp, $signature));
        $this->assertFalse($auth->accepts('GET', $path, $body, 'website', $timestamp, $signature));
        $this->assertFalse($auth->accepts('POST', $path.'/other', $body, 'website', $timestamp, $signature));
        $this->assertFalse($auth->accepts('POST', $path, $body.' ', 'website', $timestamp, $signature));
        $this->assertFalse($auth->accepts('POST', $path, $body, 'other', $timestamp, $signature));
        $this->assertFalse($auth->accepts('POST', $path, $body, 'website', (string) (now()->timestamp - 301), $signature));
        Setting::setEncrypted('contact_intake_keys', json_encode(['website' => ['secret' => $secret, 'revoked' => true]]));
        $this->assertFalse($auth->accepts('POST', $path, $body, 'website', $timestamp, $signature));
    }
}
