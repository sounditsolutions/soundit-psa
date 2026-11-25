<?php

namespace Tests\Feature\Tags;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * TagService is the only sanctioned writer of the tag pivots, because it is the
 * only place that writes tag_assignment_logs: a pivot write anywhere else would
 * change a link without a log row. Nothing stops another file from writing a
 * pivot, and a belongsToMany attach/detach/sync fires no model event, so an
 * observer cannot catch such a writer; this source scan looks for one instead.
 *
 * It fails when a PHP file in app/ or routes/, other than app/Services/TagService.php,
 * contains one of these shapes:
 *  - tags()->attach/detach/sync/syncWithoutDetaching/toggle/updateExistingPivot(;
 *  - ->tags->(one of the same verbs)(;
 *  - table('person_tag'), table('client_tag') or table('asset_tag'), quoted with ' or ";
 *  - raw SQL with INTO, UPDATE or FROM followed by one of the three pivot names,
 *    bare or in backticks.
 *
 * It does NOT see: a table name held in a variable or built by concatenation;
 * from('person_tag') or any builder entry other than table(); a double-quoted SQL
 * identifier ("person_tag"); TRUNCATE or DELETE without FROM; the save, saveMany,
 * create, createMany and syncWithPivotValues writers; a relation reached under
 * another name, through an alias or a helper (TagService's own relation()), or held
 * in a variable; and pivot models. A clean scan means none of the shapes above
 * appears, not that no other writer exists.
 *
 * The matcher is proven to fire on planted text first, so a clean scan is not a
 * matcher that cannot match.
 */
class TagWriterGuardTest extends TestCase
{
    private const ALLOWED = 'app/Services/TagService.php';

    private const PATTERNS = [
        'tags relation writer' => '/\btags\(\)\s*->\s*(attach|detach|sync|syncWithoutDetaching|toggle|updateExistingPivot)\s*\(/',
        'tags property writer' => '/->\s*tags\s*->\s*(attach|detach|sync|syncWithoutDetaching|toggle|updateExistingPivot)\s*\(/',
        'query builder on a tag pivot' => '/\btable\(\s*[\'"](person|client|asset)_tag[\'"]/',
        'raw SQL on a tag pivot' => '/\b(into|update|from)\s+`?(person|client|asset)_tag\b/i',
    ];

    /** @return list<string> pattern labels that match $source */
    private function writerHits(string $source): array
    {
        $hits = [];
        foreach (self::PATTERNS as $label => $regex) {
            if (preg_match($regex, $source) === 1) {
                $hits[] = $label;
            }
        }

        return $hits;
    }

    public function test_the_matcher_fires_on_every_planted_writer_shape(): void
    {
        $planted = [
            '$person->tags()->attach($tag->id);' => 'tags relation writer',
            '$asset->tags() ->detach();' => 'tags relation writer',
            '$client->tags()->sync([1, 2]);' => 'tags relation writer',
            '$p->tags()->syncWithoutDetaching([3]);' => 'tags relation writer',
            '$p->tags()->toggle([3]);' => 'tags relation writer',
            '$p->tags()->updateExistingPivot(3, []);' => 'tags relation writer',
            '$p->tags->sync([1]);' => 'tags property writer',
            "DB::table('person_tag')->insert(['person_id' => 1, 'tag_id' => 2]);" => 'query builder on a tag pivot',
            'DB::table("asset_tag")->delete();' => 'query builder on a tag pivot',
            "DB::statement('INSERT INTO client_tag (client_id, tag_id) VALUES (1, 2)');" => 'raw SQL on a tag pivot',
        ];
        foreach ($planted as $source => $label) {
            $this->assertContains($label, $this->writerHits($source), "The guard's matcher missed: {$source}");
        }

        // ...and it does not fire on reads or on the relation definition.
        foreach ([
            "return \$this->belongsToMany(Tag::class, 'person_tag')->withTimestamps();",
            '$person->tags()->pluck(\'tags.id\');',
            '$asset->tags->pluck(\'name\');',
            '$person->contracts()->detach();',
        ] as $source) {
            $this->assertSame([], $this->writerHits($source), "The guard's matcher fired on a non-writer: {$source}");
        }
    }

    public function test_no_tag_pivot_writer_exists_outside_tag_service(): void
    {
        $offenders = [];
        $scanned = 0;
        $sawAllowed = false;
        foreach (['app', 'routes'] as $root) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($root), RecursiveDirectoryIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $relative = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen(base_path()))), '/');
                $scanned++;
                if ($relative === self::ALLOWED) {
                    $sawAllowed = true;

                    continue;
                }
                foreach ($this->writerHits(file_get_contents($file->getPathname())) as $label) {
                    $offenders[] = "{$relative}: {$label}";
                }
            }
        }

        $this->assertTrue($sawAllowed, 'The scan never reached '.self::ALLOWED.'; it is not walking app/.');
        $this->assertGreaterThan(100, $scanned, 'The scan read too few files to be walking app/ and routes/.');
        $this->assertSame([], $offenders, 'Tag links must be written only through App\Services\TagService, which logs each change.');
    }

    public function test_tag_service_writes_through_a_helper_the_matcher_cannot_see(): void
    {
        // The allowed file really writes the pivots, but through relation(), a helper
        // that returns $entity->tags(). That is a blind spot of this scan: the matcher
        // finds nothing in TagService, and would find nothing in any other file that
        // wrote through such a helper. The exclusion of TagService is a choice; this
        // test pins that the scan alone could not have caught it.
        $source = file_get_contents(base_path(self::ALLOWED));
        $this->assertStringContainsString('return $entity->tags();', $source);
        $this->assertStringContainsString('$this->relation($entity)->attach(', $source);
        $this->assertSame([], $this->writerHits($source), 'The matcher now sees TagService; update this comment and the docblock.');
    }
}
