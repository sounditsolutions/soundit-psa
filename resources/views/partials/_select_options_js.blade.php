{{--
    Shared <option>-building helper for every line editor that adds rows
    client-side. Currently: the recurring-profile line editors
    (profiles/create, profiles/show) and the invoice line editors
    (invoices/create + invoices/edit, both via invoices/_line_scripts).

    psa-951q: those views used to build their option markup as JavaScript
    TEMPLATE LITERALS holding Blade-rendered HTML — a backtick-delimited string
    whose contents came from a Blade echo of the SKU code and name.

    A Blade echo escapes for HTML, but a backtick-delimited literal is ALSO a
    JavaScript string context, and htmlspecialchars() does not touch a backtick,
    a dollar sign or a brace. So a SKU or license-type name — operator-entered,
    hence staff-to-staff — that carried a backtick CLOSED the literal, and one
    that carried a dollar-brace substitution was EVALUATED as the string was
    built, in the staff browser, on "Add Line".

    The fix is to stop shipping server data as JS SOURCE at all. Each view emits
    its option lists as inert JSON data islands (the pattern profiles/index and
    contracts/_list already use) and this helper turns them into real option
    elements, writing every label with .textContent so the value never reaches an
    HTML parser either.

    This helper is SHARED rather than duplicated because it is the piece that
    carries the safety property: a drift on one screen would silently reintroduce
    the vulnerability on that screen alone. The data islands themselves stay
    per-view, because the screens genuinely differ (profiles/create's SKU options
    carry data-cost and its lines have a license-type select; profiles/show's do
    not; the invoice editors carry only the four fields onSkuSelected reads).

    ---------------------------------------------------------------------------
    HOW TO EMIT THE DATA ISLAND — and the one way to get it wrong
    ---------------------------------------------------------------------------
    Shape the array in a php block and pass the json directive a BARE VARIABLE.
    Never an inline expression containing a comma — not even a comma nested
    inside a closure's parameter list or an inner array.

    (Note this file never spells those directives with their leading sigil, even
    in prose: Blade compiles statements BEFORE it strips comments, so a bare
    sigil inside a comment is still executed.)

    The json directive (Illuminate\View\Compilers\Concerns\CompilesJson::
    compileJson) does not parse its argument; it explode()s the text on ',' and
    splices parts 0/1/2 back in as $value/$flags/$depth. Compiled against the
    installed Laravel 12.51.0, by number of commas anywhere in the expression:

        0  json( $var )                     -> json_encode($var, 15, 512)  CORRECT
        1  json( $x->map(fn ($s) => [       -> json_encode(<expr>, 512)    flags LOST
                  'value' => .., 'label' => ..]) )
        2  json( [$a, $b, $c] )             -> json_encode(<expr>)         flags LOST
        3+ json( [$a, $b, $c, $d] )         -> unbalanced parens, PARSE ERROR

    THE POINT: only the 3-or-more case fails loudly. The realistic mistake here —
    re-inlining a two-element ['value' => .., 'label' => ..] map, which is ONE
    comma — compiles, renders and PASSES EVERY TEST while silently swapping the
    intended JSON_HEX_TAG|HEX_AMP|HEX_APOS|HEX_QUOT (15) for 512. A direct
    closing-script-tag breakout stays shut because json_encode escapes / by
    default, but < and > then pass through RAW, reopening the double-escape
    tokenizer vector. So this is not a style rule; it is the escaping.

    Because that regression is silent, the guard is NOT this comment: the tests
    below assert the rendered island still hex-escapes < and > (see psa-28hr).

    Guarded by Tests\Feature\Profiles\ProfileLineOptionsJsContextTest and
    Tests\Feature\Invoices\InvoiceLineOptionsJsContextTest, which render each
    screen with hostile labels (a backtick, a dollar-brace substitution, quotes,
    a closing script tag) and assert every byte of them lands inside an inert,
    hex-escaped JSON string rather than in JavaScript code position.
--}}
<script>
/**
 * Replace a <select>'s options from an inert data array.
 *
 * @param {?HTMLSelectElement} select
 * @param {Array<{value: string, label: string, data?: Object}>} options
 * @param {?string} placeholder leading blank-valued option, or null for none
 */
function fillSelectOptions(select, options, placeholder) {
    if (!select) return;

    select.replaceChildren();

    if (placeholder !== null && placeholder !== undefined) {
        const blank = document.createElement('option');
        blank.value = '';
        blank.textContent = placeholder;
        select.appendChild(blank);
    }

    (options || []).forEach(function (item) {
        const opt = document.createElement('option');
        opt.value = item.value;
        // textContent, never innerHTML — this label is operator-entered.
        opt.textContent = item.label;
        Object.entries(item.data || {}).forEach(function (entry) {
            opt.dataset[entry[0]] = entry[1];
        });
        select.appendChild(opt);
    });
}
</script>
