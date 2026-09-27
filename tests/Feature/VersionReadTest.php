<?php

namespace Tests\Feature;

use App\Services\VersionService;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Controls for the served-commit read (#3980).
 *
 * Every case drives the REAL service against a REAL git plumbing layout built on
 * disk, never a mock of our own expectations: the defect these controls exist for
 * was a read that returned a clean empty answer, and a fake that returns what the
 * code wants cannot fail that way. base_path() is repointed at the fixture so the
 * service resolves the fixture's own .git.
 */
class VersionReadTest extends TestCase
{
    private string $fixture;

    protected function setUp(): void
    {
        parent::setUp();

        $realBase = base_path();

        $this->fixture = sys_get_temp_dir().'/psa-version-'.bin2hex(random_bytes(6));
        mkdir($this->fixture.'/.git/refs/heads', 0755, true);
        app()->setBasePath($this->fixture);

        // setBasePath moves public_path() too, and the app layout stamps asset URLs
        // with filemtime(public_path(...)) -- so a view test would die on a missing
        // asset rather than on anything this service does. Point public/ back at the
        // real one: the fixture exists to control .git, not to model the app tree.
        @symlink($realBase.'/public', $this->fixture.'/public');
    }

    protected function tearDown(): void
    {
        // #4096: guard the read, do not assume setUp got this far. $fixture is a typed
        // property with no default and PHPUnit calls tearDown even when setUp threw, so
        // on any failure before the assignment above the unguarded read raises "Typed
        // property ... must not be accessed before initialization".
        //
        // WHAT THAT ERROR ACTUALLY COSTS, corrected by measurement -- an earlier version
        // of this comment said it REPLACES the real failure in the report, and that is
        // FALSE for PHPUnit 11.5.53. runBare() records a teardown Throwable only
        // `if (!isset($e) || $e instanceof SkippedWithMessageException)`, so when setUp
        // throws, the Error is discarded and the real failure is what gets reported.
        // MEASURED with a throwaway test: setUp threw "THE REAL SETUP FAILURE" and that
        // is exactly what PHPUnit printed.
        //
        // The real harm is that the Error fires BEFORE parent::tearDown(), so Laravel's
        // teardown never runs -- no $app->flush(), no Mockery::close(), no
        // HandleExceptions::flushState() -- and that state leaks into the next test.
        // MEASURED: with the unguarded read, a probe's post-read teardown line was never
        // reached. The one path where the Error DOES replace the outcome is a setUp that
        // calls markTestSkipped(): measured, that turns a clean skip into an error.
        //
        // isset() is false for an uninitialised typed property, which is why it is the
        // honest test here rather than a null comparison.
        if (! isset($this->fixture) || $this->fixture === '') {
            parent::tearDown();

            return;
        }

        // Remove the symlink BEFORE the recursive delete. `rm -rf` does NOT follow a
        // symlinked directory (verified: the real public/ survived), so this is not a
        // live bug. It is removed because the fixture holds a link to the repository's
        // real public/, and any future tearDown rewritten with a recursive PHP delete
        // that tests is_dir() without is_link() WOULD follow it and empty that
        // directory (measured: that shape emptied the target).
        //
        // Deleting the link first REDUCES THE CHANCE of that; it does not prevent it.
        // Nothing enforces these three lines -- dropping them survives every control in
        // this file, which is recorded as a deliberate mutant survivor -- so the same
        // rewrite that introduces the destructive shape could delete them too. Treat this
        // as a convention, not a guarantee.
        if (is_link($this->fixture.'/public')) {
            unlink($this->fixture.'/public');
        }

        exec('rm -rf '.escapeshellarg($this->fixture));
        parent::tearDown();
    }

    private function service(): VersionService
    {
        return new VersionService;
    }

    private const SHA_A = '03005ea8e33f53dfe1be832d50ed6735043899c3';

    private const SHA_B = '5fd10881f5d4a43f51544c86dcbae8035c025df3';

    // ---------------------------------------------------------------- success

    public function test_it_reads_the_served_commit_from_a_loose_ref_without_the_git_binary(): void
    {
        file_put_contents($this->fixture.'/.git/HEAD', "ref: refs/heads/main\n");
        file_put_contents($this->fixture.'/.git/refs/heads/main', self::SHA_A."\n");

        // Precondition: no `git` binary is reachable at all, so a pass cannot come
        // from shelling out. Without this the control would not discriminate
        // between the plumbing read and the old Process call.
        $path = getenv('PATH');
        putenv('PATH=/nonexistent');
        try {
            $v = $this->service()->current();
        } finally {
            putenv('PATH='.$path);
        }

        $this->assertSame(self::SHA_A, $v['commit_hash']);
        $this->assertSame('03005ea', $v['commit_short']);
        $this->assertSame('main', $v['branch']);
        $this->assertNull($v['error']);
    }

    public function test_a_detached_head_reports_the_commit_it_holds(): void
    {
        file_put_contents($this->fixture.'/.git/HEAD', self::SHA_A."\n");

        $v = $this->service()->current();

        $this->assertSame(self::SHA_A, $v['commit_hash']);
        $this->assertStringContainsString('detached', $v['branch']);
        $this->assertNull($v['error']);
    }

    // ------------------------------------------------- loose beats packed-refs

    public function test_a_stale_packed_ref_does_not_override_the_loose_ref(): void
    {
        // Measured on production 2026-09-27: packed-refs named a commit 628 behind
        // the loose ref. Reading packed-refs first returns a REAL OLD COMMIT, so the
        // answer is a plausible 40-hex sha for a tree that is not served -- the one
        // failure mode a caller cannot detect.
        file_put_contents($this->fixture.'/.git/HEAD', "ref: refs/heads/main\n");
        file_put_contents($this->fixture.'/.git/refs/heads/main', self::SHA_A."\n");
        file_put_contents(
            $this->fixture.'/.git/packed-refs',
            "# pack-refs with: peeled fully-peeled sorted \n".self::SHA_B." refs/heads/main\n"
        );

        $this->assertSame(self::SHA_A, $this->service()->current()['commit_hash']);
    }

    public function test_packed_refs_is_used_when_no_loose_ref_exists(): void
    {
        // Positive control for the fallback: without this, a reader that ignored
        // packed-refs entirely would pass the test above for the wrong reason.
        file_put_contents($this->fixture.'/.git/HEAD', "ref: refs/heads/main\n");
        file_put_contents(
            $this->fixture.'/.git/packed-refs',
            "# pack-refs with: peeled fully-peeled sorted \n".self::SHA_B." refs/heads/main\n"
        );

        $this->assertSame(self::SHA_B, $this->service()->current()['commit_hash']);
    }

    public function test_a_peeled_tag_line_is_not_mistaken_for_a_ref(): void
    {
        file_put_contents($this->fixture.'/.git/HEAD', "ref: refs/heads/main\n");
        file_put_contents(
            $this->fixture.'/.git/packed-refs',
            "# pack-refs with: peeled fully-peeled sorted \n"
            .self::SHA_B." refs/tags/v1\n^".self::SHA_A."\n"
            .self::SHA_A." refs/heads/main\n"
        );

        $this->assertSame(self::SHA_A, $this->service()->current()['commit_hash']);
    }

    public function test_a_broken_loose_ref_does_not_fall_back_to_a_stale_packed_entry(): void
    {
        // An existing loose ref is authoritative even when it is unusable. An empty
        // (truncated) loose file beside a stale packed entry must fail, not return
        // the stale packed commit as a clean answer.
        file_put_contents($this->fixture.'/.git/HEAD', "ref: refs/heads/main\n");
        file_put_contents($this->fixture.'/.git/refs/heads/main', '');
        file_put_contents(
            $this->fixture.'/.git/packed-refs',
            "# pack-refs with: peeled fully-peeled sorted \n".self::SHA_B." refs/heads/main\n"
        );

        $v = $this->service()->current();

        $this->assertSame(VersionService::UNKNOWN, $v['commit_hash']);
        $this->assertStringContainsString('neither a commit id nor a symref', (string) $v['error']);
    }

    public function test_a_loose_symref_is_followed_rather_than_bypassed_to_packed_refs(): void
    {
        file_put_contents($this->fixture.'/.git/HEAD', "ref: refs/heads/main\n");
        file_put_contents($this->fixture.'/.git/refs/heads/main', "ref: refs/heads/release\n");
        file_put_contents($this->fixture.'/.git/refs/heads/release', self::SHA_A."\n");
        file_put_contents(
            $this->fixture.'/.git/packed-refs',
            "# pack-refs with: peeled fully-peeled sorted \n".self::SHA_B." refs/heads/main\n"
        );

        $this->assertSame(self::SHA_A, $this->service()->current()['commit_hash']);
    }

    public function test_a_symref_cycle_is_a_failure_with_a_reason(): void
    {
        file_put_contents($this->fixture.'/.git/HEAD', "ref: refs/heads/main\n");
        file_put_contents($this->fixture.'/.git/refs/heads/main', "ref: refs/heads/main\n");

        $v = $this->service()->current();

        $this->assertSame(VersionService::UNKNOWN, $v['commit_hash']);
        $this->assertStringContainsString('deeper than 5', (string) $v['error']);
    }

    // ------------------------------------------------------- linked worktree

    public function test_it_follows_a_gitdir_pointer_file_in_a_linked_worktree(): void
    {
        // Every review worktree in this pipeline has .git as a FILE, so a reader
        // that assumes a directory reports "unknown" on the checkouts we test in.
        $real = $this->fixture.'/realgit';
        mkdir($real.'/refs/heads', 0755, true);
        file_put_contents($real.'/HEAD', "ref: refs/heads/main\n");
        file_put_contents($real.'/refs/heads/main', self::SHA_A."\n");

        exec('rm -rf '.escapeshellarg($this->fixture.'/.git'));
        file_put_contents($this->fixture.'/.git', "gitdir: {$real}\n");

        $this->assertSame(self::SHA_A, $this->service()->current()['commit_hash']);
    }

    public function test_a_linked_worktree_resolves_its_ref_through_commondir(): void
    {
        // The real layout, which the fixture above did NOT model: a linked worktree
        // keeps its own HEAD but holds NO refs/ of its own -- refs live in the shared
        // git directory named by `commondir`. Found by running version:refresh inside
        // this very worktree and getting "unknown" from a healthy checkout, after the
        // looser fixture above had already passed.
        $shared = $this->fixture.'/shared.git';
        mkdir($shared.'/refs/heads', 0755, true);
        file_put_contents($shared.'/refs/heads/topic', self::SHA_A."\n");

        $wt = $shared.'/worktrees/leg';
        mkdir($wt, 0755, true);
        file_put_contents($wt.'/HEAD', "ref: refs/heads/topic\n");
        file_put_contents($wt.'/commondir', "../..\n");   // relative, as git writes it

        exec('rm -rf '.escapeshellarg($this->fixture.'/.git'));
        file_put_contents($this->fixture.'/.git', "gitdir: {$wt}\n");

        $v = $this->service()->current();

        $this->assertSame(self::SHA_A, $v['commit_hash'], 'refs must resolve through commondir');
        $this->assertSame('topic', $v['branch']);
    }

    // ---------------------------------------------------------- failure path

    public function test_a_failed_read_returns_the_unknown_sentinel_and_never_a_blank(): void
    {
        // The shipped defect: Process::run() RETURNS on non-zero exit instead of
        // throwing, so the catch arm never ran and empty strings were returned and
        // cached. Asserting "not blank" is the point -- a blank is truthy and so
        // reaches a surface as though it were an answer.
        exec('rm -rf '.escapeshellarg($this->fixture.'/.git'));

        Log::spy();

        $v = $this->service()->current();

        foreach (['commit_hash', 'commit_short', 'branch'] as $key) {
            $this->assertSame(VersionService::UNKNOWN, $v[$key], "{$key} must carry the sentinel");
            $this->assertNotSame('', $v[$key], "{$key} must never be an empty string");
        }
        $this->assertNotNull($v['error'], 'a failed read must say why');

        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($m) => is_string($m) && str_contains($m, '[Version]'))
            ->once();
    }

    public function test_an_unresolvable_ref_is_a_failure_not_a_blank(): void
    {
        // HEAD names a branch with neither a loose ref nor a packed entry.
        file_put_contents($this->fixture.'/.git/HEAD', "ref: refs/heads/main\n");

        $v = $this->service()->current();

        $this->assertSame(VersionService::UNKNOWN, $v['commit_hash']);
        $this->assertStringContainsString('packed-refs', (string) $v['error']);
    }

    public function test_a_garbage_head_is_a_failure_not_a_partial_answer(): void
    {
        file_put_contents($this->fixture.'/.git/HEAD', "not-a-sha-or-a-ref\n");

        $this->assertSame(VersionService::UNKNOWN, $this->service()->current()['commit_hash']);
    }

    // ------------------------------------------------------------- no cache

    public function test_a_moved_head_is_reported_with_no_refresh_step(): void
    {
        // This is the control for the ruling's second acceptance check. The old code
        // cached the sha for 24h and scripts/deploy.sh never cleared the key, so after
        // such a deploy a WORKING read reported the previous commit until the TTL
        // expired. Two calls with no
        // intervening refresh must disagree.
        file_put_contents($this->fixture.'/.git/HEAD', "ref: refs/heads/main\n");
        file_put_contents($this->fixture.'/.git/refs/heads/main', self::SHA_A."\n");

        $first = $this->service()->current();
        $this->assertSame(self::SHA_A, $first['commit_hash']);

        file_put_contents($this->fixture.'/.git/refs/heads/main', self::SHA_B."\n");

        $second = $this->service()->current();
        $this->assertSame(self::SHA_B, $second['commit_hash'], 'the served commit must not be cached');
    }

    // --------------------------------------------------------------- footer

    public function test_the_footer_renders_the_badge_from_the_service(): void
    {
        file_put_contents($this->fixture.'/.git/HEAD', "ref: refs/heads/main\n");
        file_put_contents($this->fixture.'/.git/refs/heads/main', self::SHA_A."\n");

        $html = view('components.footer')->render();

        $this->assertStringContainsString('v03005ea', $html);
    }

    public function test_the_footer_withholds_the_badge_rather_than_rendering_a_bare_v(): void
    {
        // The production symptom. The old guard tested truthiness, and an array of
        // empty strings is truthy, so it rendered "v" with nothing after it. Asserting
        // the absence of a bare marker is what a truthiness guard cannot satisfy.
        exec('rm -rf '.escapeshellarg($this->fixture.'/.git'));

        $html = view('components.footer')->render();

        $this->assertStringNotContainsString('>v<', $html);
        $this->assertStringNotContainsString('v'.VersionService::UNKNOWN, $html);
        $this->assertStringNotContainsString('route(\'about\')', $html);
        // Positive control: the footer still rendered, so the assertions above are
        // about the badge being withheld and not about an exception being thrown.
        $this->assertStringContainsString('site-footer', $html);
    }

    public function test_the_served_commit_is_not_written_to_the_cache(): void
    {
        // Asserts the mechanism, not just the effect: if any key holds the sha, a
        // future reader can pick it up and reintroduce the staleness.
        file_put_contents($this->fixture.'/.git/HEAD', "ref: refs/heads/main\n");
        file_put_contents($this->fixture.'/.git/refs/heads/main', self::SHA_A."\n");

        $this->service()->current();

        $this->assertNull(\Illuminate\Support\Facades\Cache::get('psa_version_current'));
    }

    // ---------------------------------------------------- commit date (#4025)

    /**
     * Write $sha as a LOOSE object whose committer epoch is $epoch.
     *
     * Built the way git writes it -- zlib-deflated "commit <len>\0<body>" -- so the
     * control exercises the real inflate-and-parse path. The object's own content is
     * not hashed to $sha and does not need to be: nothing in the read verifies the
     * hash, and a fixture that did would be testing git rather than this service.
     */
    private function writeLooseCommit(string $sha, int $epoch, string $tz = '+0000', ?string $committerLine = null): void
    {
        $dir = $this->fixture.'/.git/objects/'.substr($sha, 0, 2);
        mkdir($dir, 0755, true);

        $committer = $committerLine ?? "committer Someone <someone@example.com> {$epoch} {$tz}";
        $body = "tree 4b825dc642cb6eb9a060e54bf8d69288fbee4904\n"
            ."author Someone <someone@example.com> {$epoch} {$tz}\n"
            .$committer."\n\nsubject line\n";
        $raw = 'commit '.strlen($body)."\0".$body;

        file_put_contents($dir.'/'.substr($sha, 2), gzcompress($raw));
    }

    private function headAt(string $sha): void
    {
        file_put_contents($this->fixture.'/.git/HEAD', "ref: refs/heads/main\n");
        file_put_contents($this->fixture.'/.git/refs/heads/main', $sha."\n");
    }

    public function test_the_commit_date_is_read_from_a_loose_object_without_the_git_binary(): void
    {
        // #4025: the row was structurally always Unknown because both return paths
        // hardcoded null, on the stated ground that the date "cannot be read without
        // git". A loose object is a plain zlib stream (#4016), and PHP inflates it.
        $this->headAt(self::SHA_A);
        $this->writeLooseCommit(self::SHA_A, 1790485588);       // 2026-09-27T05:06:28Z

        // Precondition, as the sibling success control does: with no `git` on PATH a
        // pass cannot have come from shelling out.
        $path = getenv('PATH');
        putenv('PATH=/nonexistent');
        try {
            $v = $this->service()->current();
        } finally {
            putenv('PATH='.$path);
        }

        $this->assertSame('2026-09-27T05:06:28Z', $v['commit_date']);
        // Positive control: the surrounding read still worked, so the assertion above
        // is about the date and not about the whole call having failed into nulls.
        $this->assertSame(self::SHA_A, $v['commit_hash']);
    }

    public function test_the_committer_zone_offset_does_not_shift_the_instant(): void
    {
        // The epoch is UTC by definition and the trailing offset on the committer line
        // is discarded. A reader that applied it would move a real instant by hours --
        // the wrong-value hazard this row is allowed to carry at all only because the
        // value is read rather than manufactured.
        $this->headAt(self::SHA_A);
        $this->writeLooseCommit(self::SHA_A, 1790485588, '-0700');

        $this->assertSame('2026-09-27T05:06:28Z', $this->service()->current()['commit_date']);
    }

    public function test_a_packed_commit_object_reports_no_date_rather_than_a_wrong_one(): void
    {
        // MEASURED on production 2026-09-27: 11 of the 12 most recently deployed shas
        // were loose, and one was not. So the not-loose case is reachable rather than
        // hypothetical, and it is why the About view keeps its Unknown fallback.
        $this->headAt(self::SHA_A);   // ref resolves; NO object written

        $v = $this->service()->current();

        $this->assertSame(self::SHA_A, $v['commit_hash'], 'the sha must still be reported');
        $this->assertNull($v['commit_date'], 'an unreadable object is a null, never a guess');
        $this->assertNull($v['error'], 'a missing date is not a failed read of the commit');
    }

    public function test_a_corrupt_loose_object_reports_no_date_rather_than_failing_the_read(): void
    {
        $this->headAt(self::SHA_A);
        $dir = $this->fixture.'/.git/objects/'.substr(self::SHA_A, 0, 2);
        mkdir($dir, 0755, true);
        file_put_contents($dir.'/'.substr(self::SHA_A, 2), 'this is not a zlib stream');

        $v = $this->service()->current();

        $this->assertSame(self::SHA_A, $v['commit_hash']);
        $this->assertNull($v['commit_date']);
    }

    public function test_a_tag_object_at_the_commit_path_is_not_read_as_a_commit_date(): void
    {
        // The header decides. Without the "commit " test a tag or blob body carrying a
        // tagger line would be read as though it were the commit's own date.
        $this->headAt(self::SHA_A);
        $dir = $this->fixture.'/.git/objects/'.substr(self::SHA_A, 0, 2);
        mkdir($dir, 0755, true);
        $body = "object 4b825dc642cb6eb9a060e54bf8d69288fbee4904\ntype commit\n"
            ."committer Someone <someone@example.com> 1790485588 +0000\n";
        file_put_contents($dir.'/'.substr(self::SHA_A, 2), gzcompress('tag '.strlen($body)."\0".$body));

        $this->assertNull($this->service()->current()['commit_date']);
    }

    public function test_digits_in_a_committer_name_are_not_read_as_the_timestamp(): void
    {
        $this->headAt(self::SHA_A);
        $this->writeLooseCommit(
            self::SHA_A,
            1790485588,
            '+0000',
            'committer Agent 1234567890123 <bot123456789@example.com> 1790485588 +0000'
        );

        $this->assertSame('2026-09-27T05:06:28Z', $this->service()->current()['commit_date']);
    }

    public function test_a_committer_name_containing_byte_0x85_still_yields_the_date(): void
    {
        // Å is C3 85 and Cyrillic х is D1 85 in UTF-8. A split on PCRE's \R without
        // /u treats the lone 0x85 as a line break, cuts the committer line mid-name,
        // and the anchored match then answers null on a healthy loose commit.
        $name = "\u{00C5}sa Berg \u{0445}";
        $this->assertStringContainsString("\x85", $name, 'precondition: the name carries the NEL byte');

        $this->headAt(self::SHA_A);
        $this->writeLooseCommit(
            self::SHA_A,
            1790485588,
            '+0200',
            "committer {$name} <asa@example.com> 1790485588 +0200"
        );

        $this->assertSame('2026-09-27T05:06:28Z', $this->service()->current()['commit_date']);
    }

    public function test_a_commit_with_no_committer_line_reports_no_date(): void
    {
        $this->headAt(self::SHA_A);
        $dir = $this->fixture.'/.git/objects/'.substr(self::SHA_A, 0, 2);
        mkdir($dir, 0755, true);
        $body = "tree 4b825dc642cb6eb9a060e54bf8d69288fbee4904\n\nsubject only\n";
        file_put_contents($dir.'/'.substr(self::SHA_A, 2), gzcompress('commit '.strlen($body)."\0".$body));

        $this->assertNull($this->service()->current()['commit_date']);
    }

    public function test_a_committer_line_in_the_message_is_not_read_as_the_header(): void
    {
        // #4095. The scan used to walk the whole object, so a MESSAGE line beginning
        // "committer ..." was taken as the header and produced a WRONG VALUE rather than
        // an Unknown. REPRODUCED before the fix: this exact object yielded
        // 2023-11-14T22:13:20Z.
        //
        // This object has NO committer header at all, which is why the message line is
        // reachable -- so null is its ONLY correct answer. (An earlier version of this
        // comment called 2026 its "real date"; 1790485588 is the AUTHOR epoch, and
        // keeping author and committer apart is the whole point of this file.)
        //
        // SCOPE: git never AUTHORS such a commit, but it does WRITE one it received --
        // fsck is an audit, not a write gate, and fetch/receive fsckObjects default to
        // false. Measured: a plain clone+fetch stored exactly this shape as a loose
        // object. So it models a forged object delivered by ordinary git use, not a
        // squashed or quoted commit, which always carries its own header.
        $this->headAt(self::SHA_A);
        $dir = $this->fixture.'/.git/objects/'.substr(self::SHA_A, 0, 2);
        mkdir($dir, 0755, true);
        $body = "tree 4b825dc642cb6eb9a060e54bf8d69288fbee4904\n"
            ."author Someone <someone@example.com> 1790485588 +0000\n"
            ."\n"
            ."committer bot <b@x> 1700000000 +0000\n";
        file_put_contents($dir.'/'.substr(self::SHA_A, 2), gzcompress('commit '.strlen($body)."\0".$body));

        $date = $this->service()->current()['commit_date'];

        $this->assertNull($date, 'the header ends at the blank line; the message is not the header');
    }

    public function test_a_real_committer_header_is_still_read_when_the_message_also_has_one(): void
    {
        // Positive control for the stop above: stopping at the blank line must not make
        // the reader miss the genuine header that precedes it. Without this, deleting the
        // whole loop body would also pass the test above.
        $this->headAt(self::SHA_A);
        $dir = $this->fixture.'/.git/objects/'.substr(self::SHA_A, 0, 2);
        mkdir($dir, 0755, true);
        $body = "tree 4b825dc642cb6eb9a060e54bf8d69288fbee4904\n"
            ."author Someone <someone@example.com> 1790485588 +0000\n"
            ."committer Someone <someone@example.com> 1790485588 +0000\n"
            ."\n"
            ."committer bot <b@x> 1700000000 +0000\n";
        file_put_contents($dir.'/'.substr(self::SHA_A, 2), gzcompress('commit '.strlen($body)."\0".$body));

        $this->assertSame('2026-09-27T05:06:28Z', $this->service()->current()['commit_date']);
    }

    public function test_a_crlf_forged_object_does_not_have_its_message_read_as_the_header(): void
    {
        // #4095 follow-up, found by review round 1b on PR #4105 and MEASURED before
        // fixing: the stop originally tested `$line === ''`, but a CRLF-separated object
        // leaves "\r" as the separator line, which is not '', so the scan continued into
        // the message. An LF-only message line then matched and returned
        // 2023-11-14T22:13:20Z -- the same wrong value the LF control above forbids,
        // through a one-byte variation of the same forged object.
        $this->headAt(self::SHA_A);
        $dir = $this->fixture.'/.git/objects/'.substr(self::SHA_A, 0, 2);
        mkdir($dir, 0755, true);
        $body = "tree 4b825dc642cb6eb9a060e54bf8d69288fbee4904\r\n"
            ."author A <a@x> 1790485588 +0000\r\n"
            ."\r\n"
            ."committer bot <b@x> 1700000000 +0000\n";
        file_put_contents($dir.'/'.substr(self::SHA_A, 2), gzcompress('commit '.strlen($body)."\0".$body));

        $this->assertNull(
            $this->service()->current()['commit_date'],
            'the header ends at the first empty line whether it is LF- or CRLF-separated'
        );
    }

    public function test_teardown_does_not_read_the_fixture_path_before_setup_assigned_it(): void
    {
        // #4096. PHPUnit calls tearDown even when setUp throws, and $fixture is a typed
        // property with no default, so an unguarded read raises "must not be accessed
        // before initialization" -- which skips the rest of tearDown, including
        // parent::tearDown() and Laravel's whole state flush. (It does NOT replace the
        // reported failure except when setUp skips; see the tearDown comment.)
        //
        // WHAT THIS CONTROL DOES AND DOES NOT PROVE: it asserts the property of the
        // guard -- that isset() is false on an uninitialised typed property and that the
        // guarded tearDown returns without throwing. It does NOT run PHPUnit's runBare(),
        // so it cannot observe what gets reported; the reporting behaviour above was
        // established by running a throwaway test in a child process, and a control that
        // asserts on such a subprocess report is the honest way to cover it (tracked,
        // not built here). This instrument is enough to kill the remove-guard and
        // compare-to-null mutants, which is its purpose.
        $fresh = new \ReflectionClass(self::class);
        $uninitialised = $fresh->newInstanceWithoutConstructor();
        $prop = new \ReflectionProperty(self::class, 'fixture');

        $this->assertSame('string', (string) $prop->getType(), 'a typed property is what makes this fail');
        $this->assertFalse(
            $prop->isInitialized($uninitialised),
            'precondition: the property really is uninitialised before setUp assigns it'
        );

        // The guarded tearDown must not throw on that object. Unguarded, this line is
        // the Error the issue describes.
        $tearDown = new \ReflectionMethod(self::class, 'tearDown');
        $tearDown->setAccessible(true);

        // Match on the MESSAGE, not merely on \Error: parent::tearDown() running against
        // a half-built object can itself raise an unrelated \Error (a TypeError, or
        // another uninitialised property inside PHPUnit or Laravel), and blaming that on
        // this defect would point a maintainer at a guard that is working correctly.
        try {
            $tearDown->invoke($uninitialised);
        } catch (\Throwable $e) {
            if (str_contains($e->getMessage(), 'must not be accessed before initialization')
                && str_contains($e->getMessage(), 'fixture')) {
                $this->fail('tearDown read the fixture path before setUp assigned it: '.$e->getMessage());
            }
            // Anything else is collateral from tearing down a half-built object, not #4096.
        }
    }

    public function test_a_linked_worktree_reads_its_commit_object_through_commondir(): void
    {
        // Objects live in the shared directory for the same reason refs do. Without
        // this the date would be null in every review worktree -- the exact class of
        // miss that made refs/ read "unknown" on a healthy checkout.
        $shared = $this->fixture.'/shared.git';
        mkdir($shared.'/refs/heads', 0755, true);
        file_put_contents($shared.'/refs/heads/topic', self::SHA_A."\n");
        mkdir($shared.'/objects/'.substr(self::SHA_A, 0, 2), 0755, true);
        $body = "tree 4b825dc642cb6eb9a060e54bf8d69288fbee4904\n"
            ."committer Someone <someone@example.com> 1790485588 +0000\n\nsubject\n";
        file_put_contents(
            $shared.'/objects/'.substr(self::SHA_A, 0, 2).'/'.substr(self::SHA_A, 2),
            gzcompress('commit '.strlen($body)."\0".$body)
        );

        $wt = $shared.'/worktrees/leg';
        mkdir($wt, 0755, true);
        file_put_contents($wt.'/HEAD', "ref: refs/heads/topic\n");
        file_put_contents($wt.'/commondir', "../..\n");

        exec('rm -rf '.escapeshellarg($this->fixture.'/.git'));
        file_put_contents($this->fixture.'/.git', "gitdir: {$wt}\n");

        $v = $this->service()->current();

        $this->assertSame(self::SHA_A, $v['commit_hash']);
        $this->assertSame('2026-09-27T05:06:28Z', $v['commit_date'], 'objects resolve through commondir');
    }

    public function test_a_failed_read_still_carries_a_null_date(): void
    {
        exec('rm -rf '.escapeshellarg($this->fixture.'/.git'));

        $v = $this->service()->current();

        $this->assertSame(VersionService::UNKNOWN, $v['commit_hash']);
        $this->assertNull($v['commit_date']);
    }

    // -------------------------------------------------- the About view (#4025)

    public function test_the_about_page_shows_the_commit_date_it_read(): void
    {
        $this->headAt(self::SHA_A);
        $this->writeLooseCommit(self::SHA_A, 1790485588);

        $html = $this->aboutHtml();

        $this->assertStringContainsString('Commit Date', $html);
        // Rendered in the app zone by the view, so assert the DATE rather than a
        // zone-specific clock time the fixture does not control.
        $this->assertMatchesRegularExpression('/Commit Date.*Sep\s+2[67],\s+2026/s', $html);
    }

    public function test_the_about_page_has_no_row_that_can_never_hold_a_value(): void
    {
        // #4025: the Deployed row read deploy_timestamp, which both return paths set
        // to null, so it printed Unknown for every user on every deploy. Asserting the
        // row's absence is the control; asserting "not Unknown" would pass while the
        // row still shipped, because Commit Date legitimately falls back to Unknown.
        $this->headAt(self::SHA_A);
        $this->writeLooseCommit(self::SHA_A, 1790485588);

        $html = $this->aboutHtml();

        $this->assertStringNotContainsString('Deployed', $html);
        $this->assertStringNotContainsString('deploy_timestamp', $html);
        // Positive control: the page rendered and still carries its other rows, so the
        // absence above is the row being gone and not the view failing to render.
        $this->assertStringContainsString('Branch', $html);
    }

    public function test_the_about_page_still_says_unknown_when_the_date_cannot_be_read(): void
    {
        // The packed case. The fallback has to survive, or a gc'd object turns into a
        // blank cell instead of an honest Unknown.
        $this->headAt(self::SHA_A);   // no object written

        $html = $this->aboutHtml();

        $this->assertMatchesRegularExpression('/Commit Date.*Unknown/s', $html);
    }

    /**
     * Render the About view directly with the same data the controller passes.
     *
     * A route call would need an authenticated staff user and the middleware stack;
     * this control is about what the template does with a null date, so it drives
     * the template. The view() call is the same one AboutController makes.
     */
    private function aboutHtml(): string
    {
        $service = $this->service();

        return view('about.index', [
            'current' => $service->current(),
            'updates' => $service->updates(),
        ])->render();
    }
}
