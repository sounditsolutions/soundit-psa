<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Reads the served commit, and separately caches update availability.
 *
 * current() is NOT cached. It reads the git plumbing files directly; a cache
 * would add staleness: the served commit changes on every deploy, and a cached
 * answer survives it. The previous version
 * cached this for 24h and scripts/deploy.sh never cleared the key (the manual
 * update in INSTALL section 10 did, by running version:refresh), so after a
 * deploy.sh deploy a WORKING read reported the PREVIOUS commit for up to a day
 * -- a plausible 40-hex sha that is simply wrong, which is worse than an
 * obvious blank.
 *
 * current() deliberately does NOT shell out to `git`:
 * - it needs no `git` binary on PATH for the PHP process user
 * - it needs no safe.directory exception when the checkout is owned by a
 *   different user than PHP-FPM (git refuses such a repo with a "dubious
 *   ownership" fatal, which is what produced the blank badge in production)
 *
 * checkForUpdates() still needs the binary AND network access; it answers a
 * different question (what is available upstream) and keeps its own cache.
 */
class VersionService
{
    private const CACHE_KEY_UPDATES = 'psa_version_updates';

    private const CACHE_TTL_UPDATES = 3600;   // 1 hour

    private const FETCH_THROTTLE_SECONDS = 300; // 5 minutes

    /**
     * The sentinel every failed read returns. Never the empty string: an array of
     * empty strings is truthy in PHP, so a blank propagates to a surface as though
     * it were an answer. A consumer can test for this value; it cannot test for "".
     */
    public const UNKNOWN = 'unknown';

    /**
     * Get the currently served commit. Read fresh every call, never cached.
     */
    public function current(): array
    {
        return $this->readCurrentFromPlumbing();
    }

    /**
     * Get cached update availability info. No git calls — returns empty state if never checked.
     */
    public function updates(): array
    {
        return Cache::get(self::CACHE_KEY_UPDATES, [
            'commits_behind' => 0,
            'available_commits' => [],
            'recent_history' => [],
            'checked_at' => null,
            'error' => null,
        ]);
    }

    /**
     * Check for updates by fetching from origin and comparing.
     * Throttled: returns cached result if checked within FETCH_THROTTLE_SECONDS.
     */
    public function checkForUpdates(): array
    {
        $cached = Cache::get(self::CACHE_KEY_UPDATES);
        if ($cached && ! empty($cached['checked_at']) && empty($cached['error'])) {
            $checkedAt = \Carbon\Carbon::parse($cached['checked_at']);
            if ($checkedAt->diffInSeconds(now()) < self::FETCH_THROTTLE_SECONDS) {
                return $cached;
            }
        }

        $repoPath = base_path();

        try {
            // Fetch latest from origin
            $fetch = Process::path($repoPath)->timeout(30)->run('git fetch origin --quiet');
            if ($fetch->failed()) {
                return $this->cacheUpdateError('Git fetch failed: '.trim($fetch->errorOutput()));
            }

            // Count total commits behind
            $countResult = Process::path($repoPath)->timeout(10)->run('git rev-list HEAD..origin/main --count');
            $commitsBehind = $countResult->successful() ? (int) trim($countResult->output()) : 0;

            // Available updates (capped at 50)
            $availableCommits = [];
            if ($commitsBehind > 0) {
                $logResult = Process::path($repoPath)->timeout(10)
                    ->run('git log HEAD..origin/main --format="%h|%s|%cr" -50');
                if ($logResult->successful()) {
                    $availableCommits = $this->parseCommitLog($logResult->output());
                }
            }

            // Recent history (last 20 installed commits)
            $recentHistory = [];
            $historyResult = Process::path($repoPath)->timeout(10)
                ->run('git log HEAD --format="%h|%s|%cr" -20');
            if ($historyResult->successful()) {
                $recentHistory = $this->parseCommitLog($historyResult->output());
            }

            $data = [
                'commits_behind' => $commitsBehind,
                'available_commits' => $availableCommits,
                'recent_history' => $recentHistory,
                'checked_at' => now()->toDateTimeString(),
                'error' => null,
            ];

            Cache::put(self::CACHE_KEY_UPDATES, $data, self::CACHE_TTL_UPDATES);

            return $data;
        } catch (\Throwable $e) {
            Log::warning('[Version] Update check failed: '.$e->getMessage());

            return $this->cacheUpdateError($e->getMessage());
        }
    }

    /**
     * Re-read the served commit. Kept for the `version:refresh` command; since
     * current() is no longer cached there is nothing to invalidate first.
     */
    public function refreshCurrent(): array
    {
        return $this->current();
    }

    /**
     * Clear the update-availability cache. The served commit is not cached.
     */
    public function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY_UPDATES);
    }

    /**
     * Resolve the served commit from the git plumbing files, with no `git` binary.
     *
     * Reads base_path().'/.git', which is a DIRECTORY in an ordinary clone but a
     * FILE containing "gitdir: <path>" in a linked worktree (git-worktree(1), and
     * the same shape a submodule uses). Resolving that pointer is not a nicety:
     * every review worktree in this pipeline is a linked worktree, so a reader that
     * assumes a directory fails on the very checkouts we test in, and would report
     * "unknown" there while working in production.
     */
    private function readCurrentFromPlumbing(): array
    {
        try {
            $gitDir = $this->resolveGitDir(base_path());
            if ($gitDir === null) {
                return $this->unknown('no .git directory or worktree pointer at base path');
            }

            // HEAD is per-worktree; refs may live in the SHARED git directory.
            $refDir = $this->resolveCommonDir($gitDir);

            $head = $this->readFile($gitDir.'/HEAD');
            if ($head === null) {
                return $this->unknown('.git/HEAD is unreadable');
            }
            $head = trim($head);

            // A detached HEAD holds the sha itself; otherwise "ref: refs/heads/<branch>".
            if (! str_starts_with($head, 'ref: ')) {
                if (! $this->looksLikeSha($head)) {
                    return $this->unknown('.git/HEAD holds neither a ref nor a commit id');
                }

                return $this->describe($head, 'detached at '.substr($head, 0, 7), $gitDir);
            }

            $ref = trim(substr($head, 5));
            $why = null;
            $sha = $this->resolveRef($refDir, $ref, $why);
            if ($sha === null) {
                return $this->unknown("HEAD names {$ref}, which does not resolve to a commit id: {$why}");
            }

            return $this->describe($sha, $this->branchFromRef($ref), $gitDir);
        } catch (\Throwable $e) {
            return $this->unknown('unexpected failure: '.$e->getMessage());
        }
    }

    /**
     * base_path().'/.git' is either the git directory itself or a pointer file.
     */
    private function resolveGitDir(string $basePath): ?string
    {
        $dotGit = $basePath.'/.git';

        if (is_dir($dotGit)) {
            return $dotGit;
        }

        $pointer = $this->readFile($dotGit);
        if ($pointer === null) {
            return null;
        }

        foreach (preg_split('/\R/', $pointer) ?: [] as $line) {
            if (str_starts_with($line, 'gitdir: ')) {
                $target = trim(substr($line, 8));
                if ($target === '') {
                    return null;
                }
                // A relative gitdir is resolved against the directory holding the pointer.
                if (! str_starts_with($target, '/')) {
                    $target = $basePath.'/'.$target;
                }

                return is_dir($target) ? $target : null;
            }
        }

        return null;
    }

    /**
     * Where refs live for this checkout.
     *
     * A linked worktree keeps its own HEAD but NOT its own refs: its git directory
     * holds a `commondir` file pointing at the shared git directory, and
     * refs/heads/<branch> exists only there (git-worktree(1), "DETAILS"). Measured
     * in this project's own review worktree on 2026-09-27: HEAD resolved locally
     * while refs/heads/<branch> lived two levels up, so resolving refs against the
     * per-worktree directory reported "unknown" on a perfectly healthy checkout.
     * An ordinary clone has no commondir and resolves against itself.
     */
    private function resolveCommonDir(string $gitDir): string
    {
        $common = $this->readFile($gitDir.'/commondir');
        if ($common === null) {
            return $gitDir;
        }

        $target = trim($common);
        if ($target === '') {
            return $gitDir;
        }
        if (! str_starts_with($target, '/')) {
            $target = $gitDir.'/'.$target;
        }

        return is_dir($target) ? $target : $gitDir;
    }

    /**
     * Resolve a ref name to a commit id, LOOSE REF FIRST.
     *
     * The order is the whole correctness argument, not a preference. git itself
     * prefers $GIT_DIR/<ref> over the packed-refs entry, and later commits
     * update only the loose file. Measured on this project's production checkout
     * on 2026-09-27: packed-refs still named a commit 628 commits behind the
     * loose ref, and that stale value is a real historical commit -- so reading
     * packed-refs first returns a plausible sha for a tree that is not served.
     */
    private function resolveRef(string $gitDir, string $ref, ?string &$why = null, int $depth = 0): ?string
    {
        // A loose ref file that EXISTS is authoritative even when it is unusable: a
        // symref ("ref: <other>") is followed, and an empty, truncated or unreadable
        // file is a failure. Only when no loose file is visible does the read fall
        // through to packed-refs; falling through on a broken loose file would hand
        // back the stale packed entry as a clean answer. Limit: a loose file inside a
        // directory the PHP user cannot search is invisible to file_exists() and so
        // is treated as absent here.
        $loosePath = $gitDir.'/'.$ref;
        if (file_exists($loosePath) && ! is_dir($loosePath)) {
            $loose = $this->readFile($loosePath);
            if ($loose === null) {
                $why = "the loose ref file for {$ref} exists but could not be read";

                return null;
            }
            $loose = trim($loose);
            if ($this->looksLikeSha($loose)) {
                return $loose;
            }
            if (str_starts_with($loose, 'ref: ')) {
                // Bounded so a symref cycle fails instead of recursing without end.
                if ($depth >= 5) {
                    $why = "the symref chain through {$ref} is deeper than 5";

                    return null;
                }

                return $this->resolveRef($gitDir, trim(substr($loose, 5)), $why, $depth + 1);
            }
            $why = "the loose ref file for {$ref} holds neither a commit id nor a symref";

            return null;
        }

        $packed = $this->readFile($gitDir.'/packed-refs');
        if ($packed === null) {
            $why = "{$ref} has no loose ref file and no packed-refs entry";

            return null;
        }

        foreach (preg_split('/\R/', $packed) ?: [] as $line) {
            // "<sha> <refname>"; skip the leading "# pack-refs" header and any
            // "^<sha>" peeled-tag line, neither of which names a ref.
            //
            // The '^' arm is REDUNDANT, deliberately: a peeled line is a single
            // field, so the count($parts) === 2 test below already rejects it, and
            // a mutation removing this check does not change any result. Kept
            // because it states the intent at the point of reading, but no control
            // can fail if it is deleted -- do not cite it as load-bearing.
            if ($line === '' || $line[0] === '#' || $line[0] === '^') {
                continue;
            }
            $parts = preg_split('/\s+/', $line, 2);
            if (count($parts) === 2 && $parts[1] === $ref && $this->looksLikeSha($parts[0])) {
                return $parts[0];
            }
        }

        $why = "{$ref} has no loose ref file and no packed-refs entry";

        return null;
    }

    private function describe(string $sha, string $branch, string $gitDir): array
    {
        return [
            'commit_hash' => $sha,
            'commit_short' => substr($sha, 0, 7),
            // Read from the commit object when it is LOOSE, null when it is not. The
            // earlier comment here said the date "cannot be read without git" and gave
            // that as the reason for returning null; that is false for a loose object,
            // which is a plain zlib stream PHP inflates (#4016). A packed object needs
            // pack-index and delta resolution, which this reader does not do, so it
            // returns null there rather than pretending to a general answer.
            'commit_date' => $this->commitDateFromLooseObject($sha, $gitDir),
            'branch' => $branch,
            // When this answer was READ, not when anything was deployed. The old name
            // said "deploy_timestamp" while holding now(), which claimed a deploy time
            // it never measured.
            'read_at' => now()->toDateTimeString(),
            'source' => 'git-plumbing',
            'error' => null,
        ];
    }

    /**
     * The committer date of $sha, read from a LOOSE object without the git binary.
     *
     * A loose object at objects/xx/yyyy... is a zlib stream whose inflated form is
     * "commit <len>\0...\ncommitter <name> <email> <epoch> <tz>\n...". gzuncompress()
     * reads it; no binary, no safe.directory exception.
     *
     * It returns null for every case it cannot establish rather than guessing:
     *  - the object is packed or absent (the pack format needs an index and delta
     *    resolution, which is a different instrument, not a longer version of this one);
     *  - the inflate fails, or the header does not say "commit" (a tag or a blob at that
     *    path is not a commit date);
     *  - no committer line before the blank line that ends the header, or its trailing
     *    "<epoch> <tz>" does not parse.
     *
     * MEASURED on the production checkout 2026-09-27 as the PHP-FPM user: HEAD's object
     * was loose and inflated to a commit whose committer epoch matched `git log -1 %cI`
     * exactly; 11 of the 12 most recently deployed shas on main were loose there. The
     * packed one is why this may still answer null after a deploy, and why the About
     * view must keep its Unknown fallback instead of assuming a value arrives.
     *
     * The epoch is UTC by definition, so the trailing zone offset on the committer line
     * is deliberately discarded: it says where the commit was made, not when. Carbon
     * renders the instant in the app zone downstream.
     */
    private function commitDateFromLooseObject(string $sha, string $gitDir): ?string
    {
        if (! $this->looksLikeSha($sha)) {
            return null;
        }

        // Objects live in the SHARED directory for a linked worktree, same as refs.
        $objectDir = $this->resolveCommonDir($gitDir).'/objects';
        $raw = $this->readFile($objectDir.'/'.substr($sha, 0, 2).'/'.substr($sha, 2));
        if ($raw === null) {
            return null;
        }

        // A corrupt or non-zlib file must be a null, not a warning-shaped answer.
        //
        // This check is REDUNDANT with the header test below and no control can fail
        // if it is deleted: measured, a cast of gzuncompress()'s false gives '', which
        // has no NUL and does not start with "commit ", so the next guard returns null
        // too. Kept because it names the failure at the point it happens rather than
        // letting a corrupt object reach a parser -- but do not cite it as
        // load-bearing, and do not add a control that only appears to cover it.
        $inflated = @gzuncompress($raw);
        if ($inflated === false) {
            return null;
        }

        $nul = strpos($inflated, "\0");
        if ($nul === false || ! str_starts_with($inflated, 'commit ')) {
            return null;
        }

        // Split on "\n" only: git separates header lines with LF alone, and names are
        // raw bytes. Without /u, PCRE's \R also matches the lone byte 0x85 (NEL), which
        // is the UTF-8 continuation byte of Å, ą, х and others -- it would cut the
        // committer line mid-name and turn a healthy commit into a null date. /u is not
        // the fix either: a name in a legacy encoding is not valid UTF-8 and would fail
        // the split outright.
        foreach (explode("\n", substr($inflated, $nul + 1)) as $line) {
            // Stop at the blank line that ends the header. Without this the commit
            // MESSAGE is scanned too, and a body line beginning "committer <name>
            // <email> <epoch> <tz>" is read as the header -- a WRONG VALUE, not a null,
            // which is the one outcome this row must never produce.
            //
            // REACHABILITY, stated honestly: no object git writes can trigger this.
            // `git commit` and `git commit-tree` always emit a committer header, and
            // `git fsck` REFUSES a commit without one ("missingCommitter"), so for any
            // real commit the loop returned at the genuine header before ever reaching
            // the message. The reproduction needed an object forged with
            // `git hash-object --literally`. So this is hardening against a forged or
            // corrupt object, not a defect reachable through ordinary git use. It is two
            // lines and it makes the parser's bound match what the docblock claims, so
            // it is worth having -- but do not cite it as a live wrong-value fix.
            if ($line === '') {
                return null;
            }

            if (! str_starts_with($line, 'committer ')) {
                continue;
            }
            // Anchored at the END so an email or a name containing digits cannot be
            // read as the timestamp.
            if (preg_match('/ (\d{9,})\s+[+-]\d{4}$/', $line, $m) !== 1) {
                return null;
            }

            return gmdate('Y-m-d\TH:i:s\Z', (int) $m[1]);
        }

        return null;
    }

    /**
     * Every failure returns the same shape with the UNKNOWN sentinel and logs why.
     * The reason is carried in 'error' so a caller can surface it instead of a blank.
     */
    private function unknown(string $reason): array
    {
        Log::warning('[Version] Could not read the served commit: '.$reason);

        return [
            'commit_hash' => self::UNKNOWN,
            'commit_short' => self::UNKNOWN,
            'commit_date' => null,
            'branch' => self::UNKNOWN,
            'read_at' => now()->toDateTimeString(),
            'source' => 'git-plumbing',
            'error' => $reason,
        ];
    }

    private function readFile(string $path): ?string
    {
        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $contents = @file_get_contents($path);

        return $contents === false ? null : $contents;
    }

    private function looksLikeSha(string $value): bool
    {
        return preg_match('/^[0-9a-f]{40}$/', $value) === 1;
    }

    private function branchFromRef(string $ref): string
    {
        return str_starts_with($ref, 'refs/heads/') ? substr($ref, 11) : $ref;
    }

    private function parseCommitLog(string $output): array
    {
        $commits = [];
        foreach (explode("\n", trim($output)) as $line) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }
            $parts = explode('|', $line, 3);
            if (count($parts) === 3) {
                $commits[] = [
                    'hash' => $parts[0],
                    'subject' => $parts[1],
                    'date' => $parts[2],
                ];
            }
        }

        return $commits;
    }

    private function cacheUpdateError(string $message): array
    {
        $data = [
            'commits_behind' => 0,
            'available_commits' => [],
            'recent_history' => [],
            'checked_at' => now()->toDateTimeString(),
            'error' => $message,
        ];

        Cache::put(self::CACHE_KEY_UPDATES, $data, self::CACHE_TTL_UPDATES);

        return $data;
    }
}
