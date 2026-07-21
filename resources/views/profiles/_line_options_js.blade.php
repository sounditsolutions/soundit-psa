{{--
    Shared <option>-building helper for the recurring-profile line editors
    (profiles/create.blade.php and profiles/show.blade.php).

    psa-951q: both views used to build their option markup as JavaScript TEMPLATE
    LITERALS holding Blade-rendered HTML — a backtick-delimited string whose
    contents came from a Blade echo of the SKU code and name.

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
    per-view, because the two screens genuinely differ (create's SKU options carry
    data-cost and its lines have a license-type select; show's do not).

    Guarded by Tests\Feature\Profiles\ProfileLineOptionsJsContextTest, which
    renders both screens with hostile labels (a backtick, a dollar-brace
    substitution, quotes, a closing script tag) and asserts every byte of them
    lands inside an inert JSON string rather than in JavaScript code position.
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
