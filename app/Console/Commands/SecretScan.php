<?php

namespace App\Console\Commands;

use App\Support\SecretScanner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

/**
 * so-ssoj / psa-6vfw1 (#5): fail if a secret-bearing file is tracked, so the
 * `.env.bak-*` leak class cannot recur. Wired into scripts/gc-verify.sh (which
 * CI runs on every PR) and .githooks/pre-push.
 *
 * Fails CLOSED: if it cannot even enumerate the files it errors rather than
 * reporting a false "clean" — a security guard that can't see must not wave
 * work through (this repo's "a degraded read must scream" rule).
 */
class SecretScan extends Command
{
    protected $signature = 'secret:scan {--staged : Scan only the staged diff (for a pre-push / pre-commit hook)}';

    protected $description = 'Fail if a secret-bearing file (env, backup, key) is tracked — the so-ssoj recurrence guard.';

    public function handle(): int
    {
        $command = $this->option('staged')
            ? 'git diff --cached --name-only --diff-filter=ACMR'
            : 'git ls-files';

        $result = Process::path(base_path())->run($command);

        if (! $result->successful()) {
            $this->error('secret:scan — could not enumerate files via git (failing closed): '.trim($result->errorOutput()));

            return self::FAILURE;
        }

        $paths = array_values(array_filter(
            preg_split('/\R/', trim($result->output())) ?: [],
            static fn (string $p): bool => $p !== ''
        ));

        $offenders = SecretScanner::scan($paths);

        if ($offenders === []) {
            $this->info('secret:scan — clean ('.count($paths).' tracked file(s) checked)');

            return self::SUCCESS;
        }

        $this->error('secret:scan — '.count($offenders).' secret-bearing file(s) must never be committed (so-ssoj):');
        foreach ($offenders as $path => $reason) {
            $this->line("  {$path}  —  {$reason}");
        }
        $this->newLine();
        $this->line('Fix: add the pattern to .gitignore, then `git rm --cached <file>`. Never commit a real secret file.');

        return self::FAILURE;
    }
}
