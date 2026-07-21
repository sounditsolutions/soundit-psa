<?php

namespace Tests\Feature\Profiles;

use App\Enums\QuantityType;
use App\Models\Client;
use App\Models\Contract;
use App\Models\LicenseType;
use App\Models\RecurringInvoiceProfile;
use App\Models\Sku;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * psa-951q — the recurring-profile line editors (profiles/create + profiles/show)
 * build the SKU / license-type / quantity-type <option> lists in JavaScript, so
 * the "Add Line" button can stamp out another line client-side.
 *
 * Blade's {{ }} escapes for HTML. It does NOT escape for JavaScript, and a
 * backtick-delimited `template literal` is a JS STRING context stacked on top of
 * the HTML one. htmlspecialchars() leaves ` and $ and { untouched, so a persisted
 * SKU name carrying a backtick CLOSES the literal, and one carrying ${...} is
 * EVALUATED the moment the string is constructed — in the staff browser.
 *
 * SKU and license-type labels are operator-entered (staff create SKUs), so this
 * is staff-to-staff stored XSS: real, but not anonymous-user-reachable.
 *
 * These tests assert the JAVASCRIPT-context layer specifically. Asserting that
 * the labels are HTML-escaped is NOT sufficient — that already held while the
 * defect was live, which is exactly how it survived review.
 */
class ProfileLineOptionsJsContextTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A needle of characters that survive HTML-escaping, JSON-escaping and
     * template-literal-escaping completely unchanged, so it can be located in the
     * rendered page no matter which encoder the page ends up using. The hostile
     * characters hang off it.
     */
    private const MARK = 'PSA951QMARK';

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

    #[\PHPUnit\Framework\Attributes\DataProvider('hostileLabels')]
    public function test_profile_create_form_keeps_hostile_sku_labels_out_of_js_code_position(string $label): void
    {
        $contract = $this->contract();
        $sku = $this->sku($label);

        $this->actingAs(User::factory()->create());
        $html = $this->get(route('profiles.create', $contract))->assertOk()->getContent();

        // The label AND the description data attribute both carry the SKU name.
        $this->assertLabelIsInertJsData($html, [$sku->sku_code.' — '.$label, $label], 'profiles.create');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('hostileLabels')]
    public function test_profile_show_form_keeps_hostile_sku_labels_out_of_js_code_position(string $label): void
    {
        $profile = $this->profile();
        $sku = $this->sku($label);

        $this->actingAs(User::factory()->create());
        $html = $this->get(route('profiles.show', $profile))->assertOk()->getContent();

        // The label AND the description data attribute both carry the SKU name.
        $this->assertLabelIsInertJsData($html, [$sku->sku_code.' — '.$label, $label], 'profiles.show');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('hostileLabels')]
    public function test_profile_forms_keep_hostile_license_type_labels_out_of_js_code_position(string $label): void
    {
        $contract = $this->contract();
        $profile = $this->profile($contract);
        $licenseType = $this->licenseType($label);

        $this->actingAs(User::factory()->create());

        foreach ([
            'profiles.create' => route('profiles.create', $contract),
            'profiles.show' => route('profiles.show', $profile),
        ] as $screen => $url) {
            $html = $this->get($url)->assertOk()->getContent();

            $this->assertLabelIsInertJsData($html, [$label.' ('.$licenseType->vendor.')'], $screen);
        }
    }

    /**
     * The quantity-type list is enum-sourced rather than operator-entered, so it
     * is not an injection vector today — but it is built by the same code path,
     * and a label there must be inert data for the same reason. This pins the
     * whole option surface, not just the two lists an attacker can reach now.
     */
    public function test_quantity_type_options_are_inert_js_data_too(): void
    {
        $contract = $this->contract();

        $this->actingAs(User::factory()->create());
        $html = $this->get(route('profiles.create', $contract))->assertOk()->getContent();

        $label = QuantityType::PerBackupStorageGb->label();

        // This label carries no hostile characters, so it survives every encoder
        // verbatim and can locate itself.
        $this->assertLabelIsInertJsData($html, [$label], 'profiles.create', $label);
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
                "{$screen}: a <script> block carrying operator data did not lex to a balanced JS source — ".
                'a label almost certainly closed a string literal early.'
            );

            foreach ($this->offsetsOf($js, $needle) as $at) {
                $found++;

                $contexts = array_unique(str_split(substr($map, $at, strlen($needle))));
                $this->assertSame(
                    ['d'],
                    array_values($contexts),
                    "{$screen}: operator-entered label reached JS context '".implode('', $contexts)."' ".
                    "(expected 'd' = inert double-quoted JSON string). ".
                    'Context legend: c=code, t=template literal, s/d=quoted string, r=regex, #=comment. '.
                    'Near: '.var_export(substr($js, max(0, $at - 90), 200), true)
                );

                // ...and the enclosing literal must still decode to the real text,
                // so "safe" can never be bought by silently mangling the data.
                $decoded = json_decode($this->enclosingRun($js, $map, $at, 'd'), true);
                $decodings[] = $decoded;

                $this->assertContains(
                    $decoded,
                    $expected,
                    "{$screen}: a JSON string carrying operator text decoded to something unexpected — ".
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

        // Every field that should carry the operator text must actually carry it,
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

    // ------------------------------------------------------------------ fixtures

    private function contract(): Contract
    {
        return Contract::create([
            'client_id' => Client::factory()->create()->id,
            'name' => 'Managed Services',
            'type' => 'managed',
            'status' => 'active',
            'billing_source' => 'psa',
            'billing_period' => 'monthly',
            'billing_day' => 1,
            'payment_terms_days' => 30,
            'start_date' => '2026-01-01',
        ]);
    }

    private function profile(?Contract $contract = null): RecurringInvoiceProfile
    {
        return RecurringInvoiceProfile::create([
            'contract_id' => ($contract ?? $this->contract())->id,
            'name' => 'Monthly services',
            'is_active' => true,
            'billing_period' => 'monthly',
            'billing_day' => 1,
            'payment_terms_days' => 30,
            'next_run_date' => '2026-08-01',
        ]);
    }

    /** A SKU whose operator-entered name is hostile. */
    private function sku(string $name): Sku
    {
        return Sku::create([
            'name' => $name,
            'sku_code' => 'PSA951Q-SKU',
            'unit_price' => '10.00',
            'unit_cost' => '4.00',
            'default_quantity_type' => QuantityType::Fixed,
            'is_taxable' => true,
            'is_active' => true,
        ]);
    }

    /** A license type whose operator-entered name is hostile. */
    private function licenseType(string $name): LicenseType
    {
        return LicenseType::create([
            'name' => $name,
            'vendor' => 'psa951q',
            'is_active' => true,
        ]);
    }
}
