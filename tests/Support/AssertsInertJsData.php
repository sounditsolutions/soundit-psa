<?php

namespace Tests\Support;

use App\Enums\QuantityType;

/**
 * Assertions for the two halves of psa-951q's option-list fix:
 *
 *   1. untrusted label text (vendor-sync-reachable, not merely typed by staff)
 *      reaches the page as inert DATA, not as JavaScript source
 *      (assertLabelIsInertJsData and the lexer under it), and
 *   2. that data is actually BUILT INTO A DROPDOWN once it gets there
 *      (assertOptionBuilderReachesThePage, assertPlaceholderFloorInAddLineMarkup,
 *      assertQuantityTypeFloorInAddLineMarkup).
 *
 * Half 2 exists because half 1 alone is not evidence the screen works. A review
 * at e4f6f4e deleted @include('partials._select_options_js') from all three
 * consumers and ran tests/Feature/Invoices + tests/Feature/Profiles: 107 tests,
 * 902 assertions, ALL GREEN — while every "Add Line" row, and invoices/create's
 * only row, rendered with completely empty dropdowns. Every assertion covered
 * the inertness of the island; none covered its CONSUMER existing.
 *
 * So: a data island is only half a feature, and asserting it is inert is only
 * half a test.
 *
 * Several line editors in this app build <option> lists client-side so an "Add
 * Line" button can stamp out another row. Blade's {{ }} escapes for HTML. It
 * does NOT escape for JavaScript, and a backtick-delimited `template literal` is
 * a JS STRING context stacked on top of the HTML one. htmlspecialchars() leaves
 * ` and $ and { untouched, so a persisted SKU name carrying a backtick CLOSES
 * the literal, and one carrying ${...} is EVALUATED the moment the string is
 * constructed — in the staff browser.
 *
 * Asserting that such a label is HTML-escaped is NOT sufficient — that already
 * held while the defect was live, which is exactly how it survived review. So
 * these assertions work on the JAVASCRIPT-context layer, via a lexer rather than
 * a substring check.
 *
 * Shared by every screen that has this shape, so the corpus of hostile labels
 * and the definition of "inert" cannot drift apart between them:
 *   - Tests\Feature\Profiles\ProfileLineOptionsJsContextTest
 *   - Tests\Feature\Invoices\InvoiceLineOptionsJsContextTest
 */
trait AssertsInertJsData
{
    /**
     * A needle of characters that survive HTML-escaping, JSON-escaping and
     * template-literal-escaping completely unchanged, so it can be located in the
     * rendered page no matter which encoder the page ends up using. The hostile
     * characters hang off it.
     */
    private const MARK = 'PSA951QMARK';

    /**
     * The helper that turns a data island into real <option> elements. It reaches
     * a page ONLY via @include('partials._select_options_js'), so its presence in
     * the rendered output is exactly the include's presence.
     */
    private const BUILDER = 'fillSelectOptions';

    /** Each hostile label, keyed by the JS context it attacks. */
    public static function hostileLabels(): array
    {
        return [
            // Closes the template literal; everything after it becomes code.
            'backtick' => [self::MARK.'`BT'],
            // Template substitution — arbitrary evaluation, the live RCE-in-page.
            'interpolation' => [self::MARK.'${alert(1)}IP'],
            // Break out of a quoted JS string / an HTML attribute.
            'quotes' => [self::MARK.'\'"QT'],
            // Close the <script> element itself.
            'script_close' => [self::MARK.'</script>SC'],
            // All of them at once, which is what an attacker would actually send.
            'combined' => [self::MARK.'`${alert(1)}\'"</script>ALL'],
        ];
    }

    // ---------------------------------------------------------------- assertions

    /**
     * The load-bearing assertion: every byte of $label that reaches a <script>
     * block must sit inside a DOUBLE-QUOTED JSON string literal — the one JS
     * context where a backtick, a ${...} and a quote are all just characters —
     * and that literal must decode back to $label exactly.
     *
     * "Inside a template literal" and "in code position" both fail here, which is
     * the point: the defect put every one of these bytes inside a `...` literal.
     */
    private function assertLabelIsInertJsData(string $html, array $expected, string $screen, ?string $needle = null): void
    {
        $needle ??= self::MARK;
        $blocks = $this->scriptBlocks($html);
        $found = 0;
        $decodings = [];

        foreach ($blocks as $js) {
            if (! str_contains($js, $needle)) {
                continue;
            }

            [$map, $lexedCleanly] = $this->lexJs($js);

            // An unbalanced source means a string literal was terminated early —
            // i.e. a breakout — or that this lexer met JS it cannot model. Either
            // way it must fail loudly rather than silently mis-classify.
            $this->assertTrue(
                $lexedCleanly,
                "{$screen}: a <script> block carrying untrusted label data did not lex to a balanced JS source — ".
                'a label almost certainly closed a string literal early.'
            );

            foreach ($this->offsetsOf($js, $needle) as $at) {
                $found++;

                $contexts = array_unique(str_split(substr($map, $at, strlen($needle))));
                $this->assertSame(
                    ['d'],
                    array_values($contexts),
                    "{$screen}: untrusted label reached JS context '".implode('', $contexts)."' ".
                    "(expected 'd' = inert double-quoted JSON string). ".
                    'Context legend: c=code, t=template literal, s/d=quoted string, r=regex, #=comment. '.
                    'Near: '.var_export(substr($js, max(0, $at - 90), 200), true)
                );

                $run = $this->enclosingRun($js, $map, $at, 'd');

                // The island must still be HEX-ESCAPED, not merely well-formed.
                // This is the regression guard for the Blade json directive's
                // comma bug (psa-28hr): the directive splits its expression on
                // commas, so re-inlining the shaping expression compiles to
                // json_encode($x, 512) instead of json_encode($x, 15, 512) and
                // SILENTLY drops JSON_HEX_TAG. That degraded form still lexes to
                // 'd' and still decodes correctly, so every assertion above
                // passes while < and > pass through raw — reopening the
                // <!--<script> double-escape tokenizer vector. Only checking the
                // rendered bytes catches it.
                foreach (['<' => '<', '>' => '>'] as $raw => $escaped) {
                    $this->assertStringNotContainsString(
                        $raw,
                        $run,
                        "{$screen}: a JSON data island carrying untrusted text contains a RAW '{$raw}' where ".
                        "'{$escaped}' was expected. The json directive's encoding flags have been dropped — ".
                        'it was almost certainly given an inline expression containing a comma. Pass it a bare '.
                        'variable shaped in an @php block instead (see psa-28hr). Run: '.var_export($run, true)
                    );
                }

                // ...and the enclosing literal must still decode to the real text,
                // so "safe" can never be bought by silently mangling the data.
                $decoded = json_decode($run, true);
                $decodings[] = $decoded;

                $this->assertContains(
                    $decoded,
                    $expected,
                    "{$screen}: a JSON string carrying untrusted text decoded to something unexpected — ".
                    'the encoding is safe but lossy.'
                );
            }
        }

        $this->assertGreaterThan(
            0,
            $found,
            "{$screen}: the marked label never appeared in any <script> block, so this test proved nothing. ".
            'Either the option lists moved out of JS, or the fixture did not render.'
        );

        // Every field that should carry the label text must actually carry it,
        // byte for byte — a fix that quietly dropped the description data
        // attribute would otherwise pass everything above.
        foreach ($expected as $want) {
            $this->assertContains(
                $want,
                $decodings,
                "{$screen}: expected an option field decoding to ".var_export($want, true).', but none did.'
            );
        }
    }

    // -------------------------------------------------- the island's CONSUMER

    /**
     * A data island is inert on its own — it is JSON sitting in a const. What
     * turns it into a usable dropdown is fillSelectOptions(), which arrives on
     * the page only through @include('partials._select_options_js').
     *
     * This is the assertion whose absence let the include be deleted from all
     * three consumers with the whole suite green (see the class docblock). It
     * pins BOTH ends of the wiring, because either alone is satisfiable while the
     * screen is broken: the helper must be DEFINED (the include is present) and
     * it must be CALLED (the rows are actually populated from it).
     *
     * $expectedFillCalls is pinned exactly rather than as a minimum, so dropping
     * one fill call — leaving a single select permanently empty — fails too. If
     * you legitimately added a select that fillSelectOptions now populates, bump
     * the number in the calling test.
     */
    private function assertOptionBuilderReachesThePage(string $html, string $screen, int $expectedFillCalls): void
    {
        $definitions = 0;
        $calls = 0;

        foreach ($this->scriptBlocks($html) as $js) {
            if (! str_contains($js, self::BUILDER)) {
                continue;
            }

            [$map, $lexedCleanly] = $this->lexJs($js);

            $this->assertTrue(
                $lexedCleanly,
                "{$screen}: a <script> block referencing ".self::BUILDER.' did not lex to a balanced JS source.'
            );

            foreach ($this->offsetsOf($js, self::BUILDER) as $at) {
                // Only real JS counts — the views also name the helper in prose
                // inside // comments, and a comment populates nothing.
                if ($map[$at] !== 'c') {
                    continue;
                }

                if (str_ends_with(rtrim(substr($js, 0, $at)), 'function')) {
                    $definitions++;
                } else {
                    $calls++;
                }
            }
        }

        $this->assertSame(
            1,
            $definitions,
            "{$screen}: expected exactly one `function ".self::BUILDER."(` on the page, found {$definitions}. ".
            "0 means @include('partials._select_options_js') is missing, and every client-side row on this ".
            'screen renders with EMPTY dropdowns — silently, because the data island itself still looks fine. '.
            'More than 1 means the partial is included twice.'
        );

        $this->assertSame(
            $expectedFillCalls,
            $calls,
            "{$screen}: expected {$expectedFillCalls} ".self::BUILDER.'() call sites, found '.$calls.'. '.
            'A dropped call leaves that select permanently empty; an added one needs this count bumped.'
        );
    }

    /**
     * Placeholders ('-- Manual --', 'Select...', '(none — use 1)') are DEVELOPER
     * CONSTANTS, not untrusted label text, so they carry no XSS exposure and
     * belong in the static server markup where they survive a JS failure.
     *
     * fillSelectOptions() calls replaceChildren() and re-adds the placeholder, so
     * the success path is byte-identical — but if the helper never arrives, or
     * throws, the row degrades to a LABELLED empty select instead of a blank one.
     * Only the untrusted labels must go through the data island.
     *
     * Asserted in template-literal context specifically, which is what proves the
     * option is in the addLine() row template rather than merely somewhere on the
     * page (every screen also renders a server-side first-paint row).
     */
    private function assertPlaceholderFloorInAddLineMarkup(string $html, string $screen, array $placeholders): void
    {
        $blocks = [];

        foreach ($this->scriptBlocks($html) as $js) {
            $blocks[] = [$js, $this->lexJs($js)[0]];
        }

        foreach ($placeholders as $placeholder) {
            $option = '<option value="">'.$placeholder.'</option>';
            $found = false;

            foreach ($blocks as [$js, $map]) {
                foreach ($this->offsetsOf($js, $option) as $at) {
                    if ($map[$at] === 't') {
                        $found = true;
                        break 2;
                    }
                }
            }

            $this->assertTrue(
                $found,
                "{$screen}: the addLine() row template has no static ".var_export($option, true).' floor. '.
                'A placeholder is a developer constant, so it belongs in the markup — without it, a JS '.
                'failure leaves an unlabelled blank select instead of a labelled empty one. It must be '.
                'spelled exactly as the argument passed to '.self::BUILDER.'().'
            );
        }
    }

    /**
     * The quantity-type <option> list is ENUM-SOURCED — developer-authored
     * constants, no operator input — so like the placeholders above it belongs
     * in the static addLine() row markup, as a floor that survives a
     * fillSelectOptions failure. Unlike the placeholders it is the WHOLE list:
     * quantity type is a required billing control, and an operator under load
     * reads a blank one as missing data or a broken form (psa-951q.4, verified
     * in Chromium with a forced throw — the placeholder floors survived, this
     * select came up completely empty on both profile screens).
     *
     * fillSelectOptions() replaceChildren()s the floor away and rebuilds the
     * same list from the QUANTITY_TYPE_OPTIONS island, so the success path is
     * byte-identical. Only the untrusted lists (SKUs, license types —
     * vendor-sync-reachable) must stay data-island-only.
     *
     * Asserted in template-literal context ('t') for the same reason as the
     * placeholder floor: that is what proves the options sit in the addLine()
     * row template rather than merely in the server-rendered first-paint row.
     */
    private function assertQuantityTypeFloorInAddLineMarkup(string $html, string $screen): void
    {
        $blocks = [];

        foreach ($this->scriptBlocks($html) as $js) {
            $blocks[] = [$js, $this->lexJs($js)[0]];
        }

        foreach (QuantityType::cases() as $type) {
            $option = '<option value="'.$type->value.'">'.$type->label().'</option>';
            $found = false;

            foreach ($blocks as [$js, $map]) {
                foreach ($this->offsetsOf($js, $option) as $at) {
                    if ($map[$at] === 't') {
                        $found = true;
                        break 2;
                    }
                }
            }

            $this->assertTrue(
                $found,
                "{$screen}: the addLine() row template has no static ".var_export($option, true).' floor. '.
                'Quantity type is a required billing control and its labels are enum constants — developer-'.
                'authored, zero XSS exposure — so the full list belongs in the static row markup, where it '.
                'survives a fillSelectOptions failure instead of leaving the operator a blank control '.
                '(psa-951q.4). It must be spelled exactly as the server-rendered first row spells it.'
            );
        }
    }

    /**
     * addLine() must claim its row index BEFORE building the row, never after.
     *
     * profiles/* used to increment the shared counter at the END of the function,
     * so a throw mid-build (e.g. a missing fillSelectOptions) left the counter
     * unadvanced and the NEXT Add Line reused the index — colliding lines[N][...]
     * field names, which merge two rows on submit. On a billing form that is a
     * data-integrity bug, not a cosmetic one. Referencing the mutable counter
     * inside the template is the shape of that defect, so no screen may do it.
     */
    private function assertAddLineUsesACapturedIndex(string $html, string $screen): void
    {
        foreach ($this->scriptBlocks($html) as $js) {
            $this->assertStringNotContainsString(
                '${lineIndex}',
                $js,
                "{$screen}: addLine() interpolates the shared lineIndex counter directly. Capture it first ".
                '(`const i = lineIndex++;`) so a throw mid-build cannot hand the next row the same index '.
                'and collide its lines[N][...] field names.'
            );
        }
    }

    // ------------------------------------------------------- reading the island

    /**
     * Decode one entry out of a rendered `const <NAME> = [...];` data island.
     *
     * Shared so the screens cannot drift apart on how the island is located —
     * the same reason the hostile-label corpus is shared.
     *
     * @return array{value: string, label: string, data?: array<string, string>}
     */
    private function optionFromIsland(string $html, string $const, string $value, string $screen): array
    {
        $pattern = '/const '.preg_quote($const, '/').' = (\[.*?\]);/s';

        $this->assertMatchesRegularExpression(
            $pattern,
            $html,
            "{$screen}: no {$const} data island found — the option list is not being shipped as data."
        );

        preg_match($pattern, $html, $m);
        $options = json_decode($m[1], true);

        $this->assertIsArray($options, "{$screen}: {$const} did not decode as JSON.");

        foreach ($options as $option) {
            if (($option['value'] ?? null) === $value) {
                return $option;
            }
        }

        $this->fail("{$screen}: value {$value} was not present in the {$const} data island.");
    }

    // ------------------------------------------------------------------- lexing

    /** @return list<string> the body of every <script> element in the page */
    private function scriptBlocks(string $html): array
    {
        preg_match_all('#<script\b[^>]*>(.*?)</script>#si', $html, $m);

        return $m[1];
    }

    /** @return list<int> byte offsets of every occurrence of $needle */
    private function offsetsOf(string $haystack, string $needle): array
    {
        $out = [];
        $at = 0;

        while (($at = strpos($haystack, $needle, $at)) !== false) {
            $out[] = $at;
            $at += strlen($needle);
        }

        return $out;
    }

    /** The full run of context $kind surrounding offset $at, e.g. a whole string literal. */
    private function enclosingRun(string $js, string $map, int $at, string $kind): string
    {
        $from = $at;
        while ($from > 0 && $map[$from - 1] === $kind) {
            $from--;
        }

        $to = $at;
        while ($to < strlen($map) && $map[$to] === $kind) {
            $to++;
        }

        return substr($js, $from, $to - $from);
    }

    /**
     * Classify every byte of a JavaScript source by the lexical context it sits in.
     *
     * Returns [$map, $lexedCleanly] where $map is the same length as $js and each
     * byte is one of:
     *   c  code — executes
     *   t  template literal text (a `...` span)
     *   s  single-quoted string
     *   d  double-quoted string   <- where a JSON data island puts its payload
     *   r  regex literal
     *   #  comment
     *
     * Note the substitution rule: inside a template literal, `${ ... }` is CODE and
     * comes back as 'c'. That is precisely the vector — a label containing ${...}
     * lands in code position and runs.
     *
     * This is a pragmatic lexer, not a spec-complete one (in particular it uses the
     * usual prev-token heuristic to tell a regex literal from division). Callers
     * MUST assert $lexedCleanly so a source it cannot model fails loudly instead of
     * being quietly mis-classified.
     */
    private function lexJs(string $js): array
    {
        $len = strlen($js);
        $out = $len > 0 ? array_fill(0, $len, 'c') : [];

        $paint = function (int $from, int $to, string $c) use (&$out, $len): void {
            for ($k = max(0, $from); $k < min($to, $len); $k++) {
                $out[$k] = $c;
            }
        };

        // Stack of enclosing contexts. A 'tpl' frame is a `...` literal; a 'code'
        // frame with sub=true is a ${...} substitution inside one.
        $frames = [['kind' => 'code', 'depth' => 0, 'sub' => false]];
        $prev = '';
        $i = 0;

        while ($i < $len) {
            $top = count($frames) - 1;
            $ch = $js[$i];
            $next = $i + 1 < $len ? $js[$i + 1] : '';

            if ($frames[$top]['kind'] === 'tpl') {
                if ($ch === '\\') {
                    $paint($i, $i + 2, 't');
                    $i += 2;
                } elseif ($ch === '`') {
                    $paint($i, $i + 1, 't');
                    array_pop($frames);
                    $prev = '`';
                    $i++;
                } elseif ($ch === '$' && $next === '{') {
                    $paint($i, $i + 2, 'c');
                    $frames[] = ['kind' => 'code', 'depth' => 0, 'sub' => true];
                    $prev = '{';
                    $i += 2;
                } else {
                    $paint($i, $i + 1, 't');
                    $i++;
                }

                continue;
            }

            if ($ch === '/' && $next === '/') {
                $end = strpos($js, "\n", $i);
                $end = $end === false ? $len : $end;
                $paint($i, $end, '#');
                $i = $end;

                continue;
            }

            if ($ch === '/' && $next === '*') {
                $end = strpos($js, '*/', $i + 2);
                $end = $end === false ? $len : $end + 2;
                $paint($i, $end, '#');
                $i = $end;

                continue;
            }

            if ($ch === '/' && $this->regexCanStartAfter($prev)) {
                $j = $i + 1;
                $inClass = false;

                while ($j < $len) {
                    $c = $js[$j];
                    if ($c === '\\') {
                        $j += 2;

                        continue;
                    }
                    if ($c === "\n") {
                        break; // not a regex after all; treat what we scanned as code
                    }
                    if ($c === '[') {
                        $inClass = true;
                    } elseif ($c === ']') {
                        $inClass = false;
                    } elseif ($c === '/' && ! $inClass) {
                        $j++;

                        break;
                    }
                    $j++;
                }

                $paint($i, $j, 'r');
                $prev = '/';
                $i = $j;

                continue;
            }

            if ($ch === "'" || $ch === '"') {
                $j = $i + 1;
                while ($j < $len) {
                    if ($js[$j] === '\\') {
                        $j += 2;

                        continue;
                    }
                    if ($js[$j] === $ch) {
                        $j++;

                        break;
                    }
                    $j++;
                }

                $paint($i, $j, $ch === '"' ? 'd' : 's');
                $prev = $ch;
                $i = $j;

                continue;
            }

            if ($ch === '`') {
                $paint($i, $i + 1, 't');
                $frames[] = ['kind' => 'tpl', 'depth' => 0, 'sub' => false];
                $prev = '`';
                $i++;

                continue;
            }

            if ($ch === '{') {
                $frames[$top]['depth']++;
            } elseif ($ch === '}') {
                if ($frames[$top]['depth'] > 0) {
                    $frames[$top]['depth']--;
                } elseif ($frames[$top]['sub']) {
                    $paint($i, $i + 1, 'c');
                    array_pop($frames);
                    $prev = '}';
                    $i++;

                    continue;
                }
            }

            $paint($i, $i + 1, 'c');
            if (trim($ch) !== '') {
                $prev = $ch;
            }
            $i++;
        }

        return [implode('', $out), count($frames) === 1];
    }

    /** Standard heuristic: a '/' opens a regex unless the previous token could end an expression. */
    private function regexCanStartAfter(string $prev): bool
    {
        return $prev === '' || preg_match('/[A-Za-z0-9_$)\]`\'"]/', $prev) !== 1;
    }
}
