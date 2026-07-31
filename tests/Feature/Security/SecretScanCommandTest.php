<?php

namespace Tests\Feature\Security;

use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * psa-6vfw1 (#5): `secret:scan` is the recurrence guard wired into gc-verify.sh
 * (CI) and .githooks/pre-push.
 *
 * Two modes, two proofs:
 *   • default (git ls-files) — cheap "is the tracked tree clean" backstop.
 *   • --range — the PREVENTION path: scans the outbound commit history, so a
 *     secret ADDED THEN DELETED before the tip is still caught. The security
 *     review REVISE'd the first version precisely because a tip/index scan
 *     misses that, proven with a two-commit probe — so the range cases below use
 *     a REAL temp git repo (fixture from reality), not a mock.
 *
 * Fails CLOSED per the degraded-read-must-scream rule.
 */
class SecretScanCommandTest extends TestCase
{
    /** @var list<string> */
    private array $tmpDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tmpDirs as $dir) {
            $this->rmrf($dir);
        }
        parent::tearDown();
    }

    // ── default (tracked-tree) mode ──────────────────────────────────────────

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

        $this->artisan('secret:scan')->assertExitCode(1);
    }

    /** Integration: the REAL tracked tree is clean — live no-false-positive guard. */
    public function test_the_real_repository_tree_is_clean(): void
    {
        $this->artisan('secret:scan')->assertExitCode(0);
    }

    // ── --range (outbound history) mode — the prevention path ────────────────

    public function test_range_mode_catches_a_secret_file_added_then_deleted(): void
    {
        [$dir, $base] = $this->repoWithBase();

        // Add a secret file, then delete it in a later commit (the add-then-delete leak).
        $this->write($dir, '.env.bak-review-probe', "APP_KEY=base64:redacted\nDB_PASSWORD=redacted\n");
        $this->commit($dir, ['.env.bak-review-probe'], 'add secret');
        $this->git($dir, ['rm', '-q', '.env.bak-review-probe']);
        $this->commit($dir, [], 'remove secret');

        // The TIP tree is clean — the old index-only scan would (wrongly) pass:
        $this->artisan('secret:scan', ['--path' => $dir])->assertExitCode(0);

        // The RANGE catches it — the secret is in the pushed history regardless of the tip:
        $this->artisan('secret:scan', ['--range' => "{$base}..HEAD", '--path' => $dir])
            ->expectsOutputToContain('.env.bak-review-probe')
            ->assertExitCode(1);
    }

    public function test_range_mode_catches_an_embedded_private_key_added_then_deleted(): void
    {
        [$dir, $base] = $this->repoWithBase();

        // A private key in an innocuously-named file — caught by CONTENT, not name.
        // Marker built by concatenation so THIS source never carries the contiguous string.
        $key = '-----BEGIN RSA '.'PRIVATE KEY-----'."\nMIIE...redacted...\n".'-----END RSA '.'PRIVATE KEY-----'."\n";
        $this->write($dir, 'notes/handoff.txt', $key);
        $this->commit($dir, ['notes/handoff.txt'], 'oops paste');
        $this->git($dir, ['rm', '-q', 'notes/handoff.txt']);
        $this->commit($dir, [], 'remove paste');

        $this->artisan('secret:scan', ['--range' => "{$base}..HEAD", '--path' => $dir])
            ->expectsOutputToContain('notes/handoff.txt')
            ->assertExitCode(1);
    }

    /**
     * Regression: a path git would C-QUOTE (non-ASCII / special characters) must
     * still be READ and content-scanned. Before the -z fix, `git show` failed on the
     * quoted token and the absent-from-tree escape skipped the file silently — a
     * content-only secret in such a file reached the public remote.
     */
    public function test_range_mode_catches_a_private_key_in_a_non_ascii_path(): void
    {
        [$dir, $base] = $this->repoWithBase();

        // `.pem` is deliberately not flagged by NAME, so this depends entirely on the
        // content scan actually reading the blob.
        $key = '-----BEGIN RSA '.'PRIVATE KEY-----'."\nMIIE...redacted...\n".'-----END RSA '.'PRIVATE KEY-----'."\n";
        $this->write($dir, 'certs/Ünsigned-cert.pem', $key);
        $this->commit($dir, ['certs/Ünsigned-cert.pem'], 'oops paste');

        $this->artisan('secret:scan', ['--range' => "{$base}..HEAD", '--path' => $dir])
            ->expectsOutputToContain('nsigned-cert.pem')
            ->assertExitCode(1);
    }

    /**
     * psa-6vfw1 c3:v1:1 — the last fail-open. `git diff-tree -z` emits a NUL-separated
     * stream, and the shared lines() helper trim()s the WHOLE stream before splitting,
     * so whitespace on the outer edge of the first/last path is eaten. The mangled path
     * then reads as absent-from-tree and takes the silent-skip branch — the exact
     * "cannot verify" case that must fail closed. `.pem` is not flagged by NAME, so
     * this passes only if the blob is actually read.
     */
    public function test_range_mode_catches_a_private_key_in_a_whitespace_edged_path(): void
    {
        [$dir, $base] = $this->repoWithBase();

        $key = '-----BEGIN RSA '.'PRIVATE KEY-----'."\nMIIE...redacted...\n".'-----END RSA '.'PRIVATE KEY-----'."\n";
        $this->write($dir, ' leading-space.pem', $key);
        $this->commit($dir, [' leading-space.pem'], 'oops paste');

        $this->artisan('secret:scan', ['--range' => "{$base}..HEAD", '--path' => $dir])
            ->expectsOutputToContain('leading-space.pem')
            ->assertExitCode(1);
    }

    public function test_range_mode_passes_a_clean_range(): void
    {
        [$dir, $base] = $this->repoWithBase();

        $this->write($dir, 'src/Widget.php', "<?php\nclass Widget {}\n");
        $this->commit($dir, ['src/Widget.php'], 'ordinary work');

        $this->artisan('secret:scan', ['--range' => "{$base}..HEAD", '--path' => $dir])->assertExitCode(0);
    }

    public function test_range_mode_catches_a_secret_introduced_by_a_merge_commit(): void
    {
        [$dir, $base] = $this->repoWithBase();

        // Two diverging lines of work, then a merge whose OWN tree carries the
        // secret (an "evil merge" / conflict resolution). git diff-tree and
        // git log -p print nothing for a merge unless -m/-c is given, so this is
        // the case a plain per-commit diff silently skips.
        $this->git($dir, ['checkout', '-q', '-b', 'side']);
        $this->write($dir, 'src/Side.php', "<?php\nclass Side {}\n");
        $this->commit($dir, ['src/Side.php'], 'side work');

        $this->git($dir, ['checkout', '-q', '-']);
        $this->write($dir, 'src/Main.php', "<?php\nclass Main {}\n");
        $this->commit($dir, ['src/Main.php'], 'main work');

        $this->git($dir, ['merge', '-q', '--no-ff', '-m', 'merge side', 'side']);
        $this->write($dir, '.env.bak-merge-probe', "APP_KEY=base64:redacted\n");
        $this->git($dir, ['add', '.env.bak-merge-probe']);
        $this->git($dir, ['commit', '-q', '--amend', '--no-edit']);

        $this->artisan('secret:scan', ['--range' => "{$base}..HEAD", '--path' => $dir])
            ->expectsOutputToContain('.env.bak-merge-probe')
            ->assertExitCode(1);
    }

    public function test_range_mode_fails_closed_on_an_unresolvable_range(): void
    {
        [$dir] = $this->repoWithBase();

        // A bogus rev makes git rev-list error — must fail, not read as "clean".
        $this->artisan('secret:scan', ['--range' => 'deadbeefdeadbeef..HEAD', '--path' => $dir])
            ->assertExitCode(1);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @return array{0:string,1:string} [repoDir, baseSha] */
    private function repoWithBase(): array
    {
        $dir = sys_get_temp_dir().'/secretscan_'.bin2hex(random_bytes(6));
        mkdir($dir, 0700, true);
        $this->tmpDirs[] = $dir;

        $this->git($dir, ['init', '-q']);
        $this->git($dir, ['config', 'user.email', 'test@example.test']);
        $this->git($dir, ['config', 'user.name', 'Test']);
        $this->git($dir, ['config', 'commit.gpgsign', 'false']);

        $this->write($dir, 'README.md', "# temp\n");
        $this->commit($dir, ['README.md'], 'base');
        $base = trim(Process::path($dir)->run(['git', 'rev-parse', 'HEAD'])->output());

        return [$dir, $base];
    }

    private function write(string $dir, string $rel, string $content): void
    {
        $path = $dir.'/'.$rel;
        @mkdir(dirname($path), 0700, true);
        file_put_contents($path, $content);
    }

    /** @param  list<string>  $add */
    private function commit(string $dir, array $add, string $message): void
    {
        foreach ($add as $p) {
            $this->git($dir, ['add', $p]);
        }
        $this->git($dir, ['commit', '-q', '-m', $message]);
    }

    /** @param  list<string>  $args */
    private function git(string $dir, array $args): void
    {
        $r = Process::path($dir)->run(array_merge(['git'], $args));
        if (! $r->successful()) {
            $this->fail('git '.implode(' ', $args).' failed: '.$r->errorOutput());
        }
    }

    private function rmrf(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
}
