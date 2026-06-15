<?php

namespace Tests\Unit\Tactical\Actions;

use App\Services\Tactical\Actions\ActionRedactor;
use Tests\TestCase;

/**
 * Task 5 / amendment B1: argv-aware audit redaction.
 *
 * WikiRedactor::redact() only catches `key=value` / `key: value` shapes — it does
 * NOT catch the `-Flag <secret>` argv shape this app actually uses
 * (-Password, -ApiKey, -ServosityCredPass, …). The fixtures here MUST use the
 * real flag style, or a test passes green while leaking.
 *
 * The redactor: (a) redacts a value token when its PRECEDING argv flag matches
 * /(?:cred|pass|pwd|secret|key|token|user)/i, AND (b) runs each string value
 * through WikiRedactor::redact(). Applied per-value BEFORE building the JSON
 * column (never redact(json_encode(...))).
 */
class ActionRedactorTest extends TestCase
{
    private ActionRedactor $redactor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->redactor = new ActionRedactor;
    }

    public function test_redacts_value_after_a_secret_flag_in_an_argv_array(): void
    {
        $argv = ['-Username', 'svc-acct', '-Password', 'hunter2-SUPER-SECRET-xyz'];

        $clean = $this->redactor->redactArgv($argv);

        // The flag token survives; the secret value is gone.
        $this->assertContains('-Password', $clean);
        $this->assertNotContains('hunter2-SUPER-SECRET-xyz', $clean);
        // -Username also matches /user/i, so its value is redacted too.
        $this->assertNotContains('svc-acct', $clean);
        $this->assertContains('-Username', $clean);
    }

    public function test_non_secret_flags_keep_their_values(): void
    {
        $argv = ['-Path', 'C:\\Temp', '-Force'];

        $clean = $this->redactor->redactArgv($argv);

        $this->assertSame(['-Path', 'C:\\Temp', '-Force'], $clean);
    }

    public function test_various_secret_flag_spellings(): void
    {
        $argv = [
            '-ServosityCredPass', 'cred-aaa',
            '-ApiKey', 'key-bbb',
            '-AuthToken', 'tok-ccc',
            '-Secret', 'sec-ddd',
            '-Pwd', 'pwd-eee',
        ];

        $clean = $this->redactor->redactArgv($argv);

        foreach (['cred-aaa', 'key-bbb', 'tok-ccc', 'sec-ddd', 'pwd-eee'] as $secret) {
            $this->assertNotContains($secret, $clean, "leaked: {$secret}");
        }
    }

    public function test_redacts_keyword_value_shape_within_a_string_via_wikiredactor(): void
    {
        // A single string value carrying an inline key=value secret -> WikiRedactor catches it.
        $clean = $this->redactor->redactString('connecting with password=hunter2longsecret now');

        $this->assertStringNotContainsString('hunter2longsecret', $clean);
    }

    public function test_redact_params_walks_nested_args_and_scalars(): void
    {
        $params = [
            'script' => 201,
            'args' => ['-Password', 'top-secret-value-123'],
            'note' => 'token=abcd1234efgh5678ijkl',
        ];

        $clean = $this->redactor->redactParams($params);

        // Structure preserved.
        $this->assertSame(201, $clean['script']);
        $this->assertIsArray($clean['args']);
        // Argv secret gone.
        $this->assertNotContains('top-secret-value-123', $clean['args']);
        // Inline key=value secret in a sibling string gone.
        $this->assertStringNotContainsString('abcd1234efgh5678ijkl', $clean['note']);
    }

    public function test_redact_output_truncates_long_text(): void
    {
        $long = str_repeat('A', 50_000);

        $clean = $this->redactor->redactOutput($long);

        $this->assertLessThan(50_000, strlen($clean));
        $this->assertStringContainsString('truncated', strtolower($clean));
    }

    public function test_redact_output_handles_null(): void
    {
        $this->assertNull($this->redactor->redactOutput(null));
    }

    public function test_redact_output_scrubs_secrets(): void
    {
        $out = "Result:\npassword=supersecretvalue1234\nDone.";

        $clean = $this->redactor->redactOutput($out);

        $this->assertStringNotContainsString('supersecretvalue1234', $clean);
    }

    public function test_redact_output_scrubs_a_bare_token_with_no_adjacent_keyword(): void
    {
        // Code-review (critic BLOCKER): the immutable audit output must scrub a
        // bare credential a script prints on its own line — WikiRedactor only
        // catches keyword-ADJACENT shapes, so the audit-path backstop must catch
        // a long high-entropy token standing alone.
        $token = 'a1b2c3d4e5f607182930a4b5c6d7e8f901234567'; // 40-char, no keyword

        $clean = $this->redactor->redactOutput("recovered:\n{$token}\nok");

        $this->assertStringNotContainsString($token, $clean);
    }

    public function test_redact_output_scrubs_an_aws_access_key_id(): void
    {
        // Built by concatenation so the diff carries no contiguous AKIA+16 literal
        // (the CI secret-guard regex AKIA[0-9A-Z]{16} flags even a dummy key);
        // at runtime it's a full AWS-shaped id that Rule B must redact.
        $key = 'AKIA'.'1234567890ABCDEF';

        $clean = $this->redactor->redactOutput("aws creds: {$key}");

        $this->assertStringNotContainsString($key, $clean);
    }

    public function test_redact_output_scrubs_a_space_delimited_bearer_token(): void
    {
        // "Bearer <token>" / "Token <token>" use a space, not =, so WikiRedactor's
        // keyword=value rule misses them.
        $token = 'abcDEF123456ghiJKL789mnoPQR';

        $clean = $this->redactor->redactOutput("Authorization: Bearer {$token}");

        $this->assertStringNotContainsString($token, $clean);
    }

    public function test_redact_output_keeps_a_dashed_uuid_readable(): void
    {
        // The backstop must not nuke a normal UUID (dash-structured, segments < 32)
        // — those are legitimate identifiers an operator reads in the audit trail.
        $uuid = '550e8400-e29b-41d4-a716-446655440000';

        $clean = $this->redactor->redactOutput("correlation: {$uuid}");

        $this->assertStringContainsString($uuid, $clean);
    }

    public function test_redacts_value_in_an_equals_joined_secret_flag_token(): void
    {
        // The `-Flag=value` single-token form (vs `-Flag value` two tokens): the
        // value after `=` on a sensitive flag must be scrubbed; the flag survives.
        $argv = ['-ServosityCredPass=hunter2-SUPER-SECRET-xyz', '-Verbose'];

        $clean = $this->redactor->redactArgv($argv);

        $joined = implode(' ', $clean);
        $this->assertStringNotContainsString('hunter2-SUPER-SECRET-xyz', $joined);
        $this->assertStringContainsString('-ServosityCredPass', $joined);
        $this->assertContains('-Verbose', $clean);
    }
}
