<?php

namespace Tests\Feature\Security;

use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * psa-6vfw1 (#5): `secret:scan` is the recurrence guard wired into gc-verify.sh
 * (CI) and the pre-push hook. It must FAIL when a secret-bearing file is tracked,
 * and — per this repo's "a degraded read must scream" rule — FAIL CLOSED when it
 * cannot even enumerate the files, never report a false "clean".
 */
class SecretScanCommandTest extends TestCase
{
    public function test_passes_when_only_safe_files_are_tracked(): void
    {
        Process::fake([
            'git ls-files' => Process::result(
                output: "app/Support/SecretScanner.php\nREADME.md\n.env.example\ncomposer.json"
            ),
        ]);

        $this->artisan('secret:scan')->assertExitCode(0);
    }

    public function test_fails_when_a_secret_bearing_file_is_tracked(): void
    {
        Process::fake([
            'git ls-files' => Process::result(
                output: "app/Http/Kernel.php\n.env.bak-pre-msgraph-20260708T195933Z\nREADME.md"
            ),
        ]);

        $this->artisan('secret:scan')
            ->expectsOutputToContain('.env.bak-pre-msgraph-20260708T195933Z')
            ->assertExitCode(1);
    }

    public function test_fails_closed_when_git_enumeration_fails(): void
    {
        Process::fake([
            'git ls-files' => Process::result(output: '', errorOutput: 'fatal: not a git repository', exitCode: 128),
        ]);

        // Must NOT read as "clean" — a scanner that cannot list files must fail.
        $this->artisan('secret:scan')->assertExitCode(1);
    }

    public function test_staged_mode_scans_the_staged_diff(): void
    {
        Process::fake([
            'git diff --cached --name-only --diff-filter=ACMR' => Process::result(output: 'server.pem'),
        ]);

        $this->artisan('secret:scan', ['--staged' => true])
            ->expectsOutputToContain('server.pem')
            ->assertExitCode(1);
    }

    /**
     * Integration: the REAL tracked tree must be clean — proves no false positives
     * on the actual codebase and stands as a live regression guard.
     */
    public function test_the_real_repository_tree_is_clean(): void
    {
        $this->artisan('secret:scan')->assertExitCode(0);
    }
}
