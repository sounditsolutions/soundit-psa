<?php

namespace Tests\Unit\Cipp;

use App\Services\Cipp\CippToolContract;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class CippProjectionMessageTest extends TestCase
{
    public static function emptyRows(): array
    {
        // Synthetic contract counterexamples, not fixtures of a vendor response.
        return [
            'resolved null keys' => [[['User' => null, 'Permissions' => null]]],
            'absent keys' => [[['Unexpected' => 'synthetic']]],
            'mixed rows' => [[['User' => null, 'Permissions' => null], ['Unexpected' => 'synthetic']]],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('emptyRows')]
    public function test_empty_projection_does_not_assert_its_cause(array $rows): void
    {
        Http::preventStrayRequests();
        Log::spy();

        $result = app(CippToolContract::class)->shape('cipp_list_mailbox_permissions', $rows, [], null);

        $this->assertSame(array_fill(0, count($rows), []), $result);
        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn (string $message, array $context): bool => $message === '[CippTools] Every row projected empty'
                && $context === [
                    'tool' => 'cipp_list_mailbox_permissions',
                    'row_count' => count($rows),
                    'first_row_keys' => array_keys($rows[0]),
                ]
        );

        foreach (['emergency', 'alert', 'critical', 'error', 'notice', 'info', 'debug', 'log'] as $level) {
            Log::shouldNotHaveReceived($level);
        }
        Http::assertNothingSent();
    }

    public static function nonemptyRows(): array
    {
        return [
            'nonempty row' => [
                [['User' => null, 'Permissions' => 'FullAccess']],
                [['permissions' => 'FullAccess']],
            ],
            'empty and nonempty rows' => [
                [['User' => null, 'Permissions' => null], ['User' => null, 'Permissions' => 'FullAccess']],
                [[], ['permissions' => 'FullAccess']],
            ],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('nonemptyRows')]
    public function test_nonempty_projection_emits_nothing_at_any_level(array $rows, array $expected): void
    {
        Http::preventStrayRequests();
        Log::spy();

        $result = app(CippToolContract::class)->shape('cipp_list_mailbox_permissions', $rows, [], null);

        $this->assertSame($expected, $result);
        foreach (['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug', 'log'] as $level) {
            Log::shouldNotHaveReceived($level);
        }
        Http::assertNothingSent();
    }

    /**
     * The drift warning must not name a cause this function cannot establish.
     *
     * Four callers of projectRows() CAN filter rows before calling it:
     * shapeEvents only when filtered_by_days is an int, shapeMessageTrace on a
     * non-empty sender/recipient, shapeMailQuarantine on a non-empty recipient,
     * and shapeMailboxRules whenever a mailbox was requested. That last one is
     * armed on every call that arrives through the relay or cippQueryWithUser,
     * since both refuse the tool without a user_id -- but shape() is PUBLIC and
     * takes caller-supplied args, and this very file calls it directly, so
     * "always armed" is a property of those two transports, not of the
     * function. Treat it as conditional. A fifth caller, shapeTenantMailboxRules, drops an all-clear
     * sentinel row that DOES carry a tracked key ('name'); it is harmless only
     * because upstream writes that sentinel as the whole payload, never mixed
     * with real rules, so the drop leaves zero rows and the $rows !== [] guard
     * stops projectRows reporting on it.
     *
     * So when any one of those filters is active, the rows this
     * function inspects are a SUBSET of the upstream response, and a field
     * carried only by dropped rows never resolves here while DEFAULT_FIELDS
     * and FIELD_ALIASES are both correct -- the constants cannot be blamed
     * from inside this function.
     *
     * This control drives that exact case through the real filtering caller:
     * two message-trace rows, only one carrying FromIP/ToIP, filtered by
     * sender to the row that does not. The constants are untouched and
     * correct, and the warning still fires.
     */
    public function test_the_drift_warning_does_not_blame_the_constants_for_a_filtered_subset(): void
    {
        Http::preventStrayRequests();
        Log::spy();

        $rows = [
            [
                'MessageTraceId' => 'dropped-by-the-sender-filter',
                'Received' => '2026-09-01T00:00:00Z',
                'SenderAddress' => 'dropped@example.test',
                'RecipientAddress' => 'r@example.test',
                'Subject' => 'carries the IP fields',
                'Status' => 'Delivered',
                'FromIP' => '203.0.113.1',
                'ToIP' => '203.0.113.2',
            ],
            [
                'MessageTraceId' => 'survives-the-filter',
                'Received' => '2026-09-01T00:00:00Z',
                'SenderAddress' => 'kept@example.test',
                'RecipientAddress' => 'r@example.test',
                'Subject' => 'no IP fields',
                'Status' => 'Delivered',
            ],
        ];

        app(CippToolContract::class)->shape(
            'cipp_list_message_trace',
            $rows,
            ['sender' => 'kept@example.test'],
            null,
        );

        // Precondition: the warning really did fire on this submission, so the
        // assertions below cannot pass because nothing was logged at all.
        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn (string $message, array $context): bool => ($context['tool'] ?? null) === 'cipp_list_message_trace'
                && in_array('FromIP', $context['missing_fields'] ?? [], true)
                // row_count must be the POST-filter count (1 of the 2 submitted).
                // The code comment tells operators row_count is what separates
                // "filtered subset" from "constants wrong", so a mutant that
                // logged the upstream count would take that signal away while
                // every other assertion here stayed green.
                && ($context['row_count'] ?? null) === 1
        );

        // The claim under test: NO cause is named, at ANY level, in the
        // message OR anywhere in the context array. Scoping this to warning()
        // and to the message alone would let a restatement escape by moving
        // level (a notice) or by moving surface (a 'hint' key in the context),
        // and the whole point of the change is that the cause is not asserted.
        // G-14 is in STANDARDS at base 73b08287.

        // PIN the one call that is allowed -- exact message AND the whole
        // context by strict equality -- then pin the warning TOTAL at one and
        // forbid every other level, both with the no-argument form, which
        // matches any arity. The total pin is not redundant: a withArgs()
        // expectation only counts calls whose arguments match it, so once()
        // on the pinned call alone cannot see a SECOND warning() naming the
        // cause in any words. The arity matrix this control carried before
        // was a three-word DENYLIST, so a paraphrase naming the same cause in
        // other words passed it, and each matcher only matched its own arity.
        //
        // The context is pinned by VALUE, not just by key list. A key-list pin
        // leaves every value free: a cause appended to missing_fields or
        // first_row_keys, in any wording, would pass it. With the whole
        // context equal to what this fixture determines, no surface of the
        // permitted call -- message, key or value -- can carry a cause.
        //
        // The expected values follow from DEFAULT_FIELDS for this tool, which
        // declares both casings of the IP fields and has no FIELD_ALIASES
        // entry for them: the surviving row carries no casing of either, so
        // the case-insensitive hedge suppresses neither twin and all four are
        // reported, in DEFAULT_FIELDS order. first_row_keys is the surviving
        // row's own key order, and row_count the post-filter count.
        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(function (string $message, array $context): bool {
                return $message === '[CippTools] Field(s) never resolved in any row this call projected'
                    && $context === [
                        'tool' => 'cipp_list_message_trace',
                        'row_count' => 1,
                        'missing_fields' => ['FromIP', 'fromIP', 'ToIP', 'toIP'],
                        'first_row_keys' => ['MessageTraceId', 'Received', 'SenderAddress', 'RecipientAddress', 'Subject', 'Status'],
                    ];
            });

        // Exactly ONE warning() in total, whatever its arguments. Together with
        // the pin above, that one call IS the pinned call, and nothing else is
        // logged at this level.
        Log::shouldHaveReceived('warning')->once();

        foreach (['emergency', 'alert', 'critical', 'error', 'notice', 'info', 'debug', 'log', 'write'] as $level) {
            Log::shouldNotHaveReceived($level);
        }

        Http::assertNothingSent();
    }

    /**
     * The positive control for the one above, on the message-trace tool.
     *
     * It does NOT uniquely cover the unfiltered path -- the casing control
     * below drives cipp_list_users through the unfiltered default arm too,
     * and so does the relay suite. (An earlier version of this docblock
     * claimed it did; that claim was false, in the docblock of a control
     * enforcing a PR about unverified claims.) What it adds is the pairing:
     * the SAME tool and the SAME field set as the filtered control above,
     * differing only in whether a filter ran, so the two isolate the filter
     * as the variable. It also pins the full context shape (tool, row_count,
     * missing_fields, first_row_keys) that an operator reads instead of a cause.
     *
     * (The "deleting the warning outright" reason this docblock used to give
     * was already false: the control above asserts warning() once, so a
     * deletion fails it there.)
     */
    public function test_a_genuinely_absent_field_is_still_reported_on_an_unfiltered_call(): void
    {
        Http::preventStrayRequests();
        Log::spy();

        app(CippToolContract::class)->shape(
            'cipp_list_message_trace',
            [[
                'MessageTraceId' => 'only-row',
                'Received' => '2026-09-01T00:00:00Z',
                'SenderAddress' => 's@example.test',
                'RecipientAddress' => 'r@example.test',
                'Subject' => 'no IP fields',
                'Status' => 'Delivered',
            ]],
            [],
            null,
        );

        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn (string $message, array $context): bool => ($context['tool'] ?? null) === 'cipp_list_message_trace'
                && in_array('FromIP', $context['missing_fields'] ?? [], true)
                && in_array('ToIP', $context['missing_fields'] ?? [], true)
                && ($context['row_count'] ?? null) === 1
                && in_array('MessageTraceId', $context['first_row_keys'] ?? [], true)
        );

        Http::assertNothingSent();
    }

    /**
     * Round 1 contract:1, verified by execution: resolveKey() is an exact-case
     * array_key_exists over FIELD_ALIASES[$field] ?? [$field], so a row that
     * DOES carry the field under an unaliased casing is still reported. The
     * message must therefore say "never resolved", never "absent" - the latter
     * is a false claim about the data on precisely the alias-drift path this
     * guard exists to surface.
     */
    public function test_a_field_present_under_an_unaliased_casing_is_not_called_absent(): void
    {
        Log::spy();
        Http::fake();

        // EVERY row carries the field, under a casing FIELD_ALIASES does not map.
        $rows = [
            ['AccountEnabled' => true, 'JobTitle' => 'Tech', 'displayName' => 'A', 'userPrincipalName' => 'a@example.test'],
            ['AccountEnabled' => false, 'JobTitle' => 'Eng', 'displayName' => 'B', 'userPrincipalName' => 'b@example.test'],
        ];

        app(CippToolContract::class)->shape('cipp_list_users', $rows, [], null);

        // Precondition: the warning fired and named the unresolved field, so
        // the assertion below cannot pass because nothing was logged.
        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn (string $message, array $context): bool => ($context['tool'] ?? null) === 'cipp_list_users'
                && in_array('accountEnabled', $context['missing_fields'] ?? [], true)
                && in_array('AccountEnabled', $context['first_row_keys'] ?? [], true)
        );

        // The claim under test: the row carries the data, so the message must
        // not assert the field is absent from it.
        Log::shouldNotHaveReceived('warning', [
            \Mockery::pattern('/absent from every row/'),
            \Mockery::any(),
        ]);

        Http::assertNothingSent();
    }
}
