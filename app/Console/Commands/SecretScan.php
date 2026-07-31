<?php

namespace App\Console\Commands;

use App\Support\SecretScanner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

/**
 * so-ssoj / psa-6vfw1 (#5): fail if a secret-bearing file (env, backup, key) —
 * or embedded private-key material — would reach a push, so the `.env.bak-*`
 * leak class cannot recur.
 *
 * TWO MODES, because prevention and detection are different jobs:
 *   • default  — scan the current tracked tree (git ls-files). A cheap "is the
 *                checked-out tree clean" backstop, run by scripts/gc-verify.sh.
 *   • --range  — scan every path INTRODUCED across a set of outbound commits
 *                (per-commit git diff-tree), reading each introduced blob AT THE
 *                COMMIT THAT ADDED IT. This is the PREVENTION path used by
 *                .githooks/pre-push: it sees a secret added and then deleted
 *                before the tip (the exact gap the security review proved with a
 *                two-commit probe), and it sees whatever ref is actually pushed —
 *                not just the current index.
 *
 * Fails CLOSED everywhere: any git enumeration error, or an introduced blob that
 * cannot be read, returns failure rather than a false "clean" (this repo's
 * degraded-read-must-scream rule). NOTE: a local hook is still bypassable with
 * `git push --no-verify`; the only control that truly blocks a push to a public
 * remote is server-side push protection, which is an infra/GitHub setting.
 */
class SecretScan extends Command
{
    protected $signature = 'secret:scan
        {--range= : Scan the commits in this rev-list spec (e.g. "origin/main..HEAD", or "<sha> --not --remotes" for a new branch) — every introduced path + blob, incl. add-then-delete}
        {--path= : Repository path to run git in (defaults to the app base path) — hook/testing seam}';

    protected $description = 'Fail if a secret-bearing file or private key would reach a push — the so-ssoj recurrence guard.';

    public function handle(): int
    {
        $range = (string) ($this->option('range') ?? '');

        return $range !== '' ? $this->scanRange($range) : $this->scanTrackedTree();
    }

    /** Cheap backstop: is the current tracked tree clean? (filename policy only). */
    private function scanTrackedTree(): int
    {
        // String form (no untrusted input) so it is trivially fakeable in tests;
        // the --range path below uses array/argv form because it carries input.
        $result = Process::path($this->repoPath())->run('git ls-files');
        if (! $result->successful()) {
            return $this->failClosed('git ls-files', $result->errorOutput());
        }

        $paths = $this->lines($result->output());
        $offenders = SecretScanner::scan($paths);

        return $this->report($offenders, count($paths).' tracked file(s)');
    }

    /**
     * Prevention: every path introduced across the outbound commits, filename AND
     * content, reading each blob at the commit that added it so an add-then-delete
     * secret is still inspected.
     */
    private function scanRange(string $range): int
    {
        $revArgs = $this->lines($range, '/\s+/'); // may be "A..B" or "<sha> --not --remotes"
        $revList = Process::path($this->repoPath())->run(array_merge(['git', 'rev-list'], $revArgs));
        if (! $revList->successful()) {
            return $this->failClosed('git rev-list '.$range, $revList->errorOutput());
        }

        $commits = $this->lines($revList->output());
        $offenders = [];
        $checked = 0;

        foreach ($commits as $sha) {
            // --root so the very first commit of a repo (no parent) is diffed too.
            // -m so a MERGE commit is diffed against each parent: without it git
            // prints NOTHING for a merge, so a secret introduced by the merge
            // itself (a conflict resolution) would never be inspected at all.
            $dt = Process::path($this->repoPath())->run(
                ['git', 'diff-tree', '--root', '--no-commit-id', '--name-only', '-r', '-m', '--diff-filter=ACMR', $sha]
            );
            if (! $dt->successful()) {
                return $this->failClosed("git diff-tree {$sha}", $dt->errorOutput());
            }

            // -m lists a path once per parent for a merge — inspect it once.
            foreach (array_unique($this->lines($dt->output())) as $path) {
                $checked++;
                $reason = SecretScanner::dangerousReason($path);

                if ($reason === null) {
                    // Read the blob AS OF this commit — works even if a later commit deletes it.
                    $show = Process::path($this->repoPath())->run(['git', 'show', "{$sha}:{$path}"]);
                    if (! $show->successful()) {
                        // Cannot verify an introduced blob -> fail closed, do not skip.
                        $offenders["{$sha} {$path}"] = 'unreadable introduced blob (cannot verify — failing closed)';

                        continue;
                    }
                    $reason = SecretScanner::dangerousContentReason($show->output());
                }

                if ($reason !== null) {
                    $offenders["{$sha} {$path}"] = $reason;
                }
            }
        }

        return $this->report($offenders, $checked.' introduced path(s) across '.count($commits).' outbound commit(s)');
    }

    private function repoPath(): string
    {
        $path = (string) ($this->option('path') ?? '');

        return $path !== '' ? $path : base_path();
    }

    /** @return list<string> */
    private function lines(string $out, string $pattern = '/\R/'): array
    {
        return array_values(array_filter(
            preg_split($pattern, trim($out)) ?: [],
            static fn (string $p): bool => $p !== ''
        ));
    }

    private function failClosed(string $what, string $stderr): int
    {
        $this->error("secret:scan — could not run `{$what}` (failing closed): ".trim($stderr));

        return self::FAILURE;
    }

    /** @param  array<string, string>  $offenders */
    private function report(array $offenders, string $scanned): int
    {
        if ($offenders === []) {
            $this->info("secret:scan — clean ({$scanned} checked)");

            return self::SUCCESS;
        }

        $this->error('secret:scan — '.count($offenders).' secret-bearing item(s) must never reach a push (so-ssoj):');
        foreach ($offenders as $where => $reason) {
            $this->line("  {$where}  —  {$reason}");
        }
        $this->newLine();
        $this->line('Fix: add the pattern to .gitignore + `git rm --cached <file>`. A secret already committed must have its history scrubbed — deleting it at the tip does NOT un-leak it. Never push a real secret to a public remote.');

        return self::FAILURE;
    }
}
