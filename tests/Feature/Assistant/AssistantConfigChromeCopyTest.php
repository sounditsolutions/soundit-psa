<?php

namespace Tests\Feature\Assistant;

use App\Models\Setting;
use App\Support\AssistantConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * psa-uw2o.20: the disabled-Assistant notice now exists in TWO lengths, and
 * they must never disagree about the state.
 *
 * The long form — disabledSummary() + disabledRecovery() — is a sentence, and
 * it goes where someone is actually reaching for the Assistant: the ticket
 * action row and the ticket timeline. The short form —
 * disabledChromeLabel() + disabledChromePointer() — goes in the topbar, which
 * is global chrome on every page, where a sentence would nag the whole product.
 *
 * Two lengths is two places for copy to live, and psa-uw2o.13 F2 is the
 * standing lesson about exactly that: three views restating the same predicate
 * in their own words, two of which drifted. So the agreement is asserted rather
 * than assumed. If someone reworded one form and not the other, the topbar
 * could say "disabled" while the ticket page said "unavailable" — and the
 * operator would have no way to tell which one to believe.
 */
class AssistantConfigChromeCopyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The word that distinguishes the two ineligible causes, per state. This is
     * the thing the operator acts on: "disabled" means flip a switch,
     * "unavailable" means the switch will not help.
     */
    private const DISTINGUISHING_WORD = [
        AssistantConfig::REASON_SWITCHED_OFF => 'disabled',
        AssistantConfig::REASON_WRONG_PROVIDER => 'unavailable',
    ];

    private function switchedOff(): void
    {
        Setting::setValue('ai_provider', 'anthropic');
        Setting::setEncrypted('ai_api_key', 'test-key');
        Setting::setValue('assistant_enabled', '0');
    }

    private function wrongProvider(): void
    {
        Setting::setValue('ai_provider', 'openai');
        Setting::setEncrypted('ai_api_key', 'test-key');
        Setting::setValue('assistant_enabled', '1');
    }

    private function noProvider(): void
    {
        Setting::setValue('assistant_enabled', '0');
    }

    private function running(): void
    {
        Setting::setValue('ai_provider', 'anthropic');
        Setting::setEncrypted('ai_api_key', 'test-key');
        Setting::setValue('assistant_enabled', '1');
    }

    public function test_the_short_and_long_forms_name_the_same_state(): void
    {
        foreach (['switchedOff' => AssistantConfig::REASON_SWITCHED_OFF, 'wrongProvider' => AssistantConfig::REASON_WRONG_PROVIDER] as $state => $reason) {
            $this->{$state}();

            // Control: without this the loop could be asserting against a state
            // the settings above never actually produced.
            $this->assertSame($reason, AssistantConfig::disabledReason(), "state {$state} did not produce the reason under test");

            $word = self::DISTINGUISHING_WORD[$reason];
            $label = (string) AssistantConfig::disabledChromeLabel();
            $summary = (string) AssistantConfig::disabledSummary();

            $this->assertStringContainsString($word, $label, "the chrome label '{$label}' does not name the {$reason} state");
            $this->assertStringContainsString($word, $summary, "the summary '{$summary}' does not name the {$reason} state");
        }
    }

    /**
     * The negative half. Sharing a word proves agreement only if the OTHER
     * state's word is absent — otherwise a label reading "disabled or
     * unavailable" would satisfy the test above in both states while telling the
     * operator nothing.
     */
    public function test_neither_form_borrows_the_other_states_word(): void
    {
        foreach ([AssistantConfig::REASON_SWITCHED_OFF => 'switchedOff', AssistantConfig::REASON_WRONG_PROVIDER => 'wrongProvider'] as $reason => $state) {
            $this->{$state}();

            $otherWord = self::DISTINGUISHING_WORD[array_key_first(array_diff_key(self::DISTINGUISHING_WORD, [$reason => null]))];

            $this->assertStringNotContainsString($otherWord, (string) AssistantConfig::disabledChromeLabel());
            $this->assertStringNotContainsString($otherWord, (string) AssistantConfig::disabledSummary());
        }
    }

    /**
     * Chrome copy has to stay chrome-sized. The reason the topbar carried a
     * hard-coded "AI off" in the first place was a real constraint — a fixed
     * 56px bar shared with the page title, a search chip and a user menu — and
     * the fix must not answer the accessibility defect by dropping a sentence
     * into every page of the product.
     */
    public function test_the_chrome_label_stays_short_enough_for_a_topbar(): void
    {
        foreach (['switchedOff', 'wrongProvider'] as $state) {
            $this->{$state}();

            $label = (string) AssistantConfig::disabledChromeLabel();

            $this->assertNotSame('', $label, 'control: there must be a label to measure');
            $this->assertLessThanOrEqual(
                24,
                mb_strlen($label),
                "the chrome label '{$label}' is too long for a 56px topbar shared with the page title — the ticket sites are where the sentence belongs"
            );
            $this->assertLessThan(
                mb_strlen((string) AssistantConfig::disabledSummary().' '.AssistantConfig::disabledRecovery()),
                mb_strlen($label.' '.AssistantConfig::disabledChromePointer()),
                'the chrome form must be shorter than the sentence it stands in for, or it is not a chrome form'
            );
        }
    }

    /**
     * The chrome accessors must obey the SAME silence rule as the notice
     * predicate. An install that never configured AI is not missing anything,
     * and a topbar chip is the most visible place to nag it from.
     */
    public function test_the_chrome_copy_is_silent_wherever_the_notice_predicate_is(): void
    {
        foreach (['noProvider', 'running'] as $state) {
            $this->{$state}();

            $this->assertFalse(AssistantConfig::shouldShowDisabledNotice(), "control: state {$state} must not warrant a notice");
            $this->assertNull(AssistantConfig::disabledChromeLabel(), "state {$state} must produce no chrome label");
            $this->assertNull(AssistantConfig::disabledChromePointer(), "state {$state} must produce no chrome pointer");
        }

        // Control for the two assertions above: they must be null for the reason
        // under test, not null unconditionally.
        $this->switchedOff();
        $this->assertNotNull(AssistantConfig::disabledChromeLabel());
        $this->assertNotNull(AssistantConfig::disabledChromePointer());
    }

    /**
     * The pointer has to name somewhere the operator can actually go, and the
     * same somewhere both recovery sentences send them.
     */
    public function test_the_chrome_pointer_names_a_place_both_recovery_paths_lead(): void
    {
        foreach (['switchedOff', 'wrongProvider'] as $state) {
            $this->{$state}();

            $pointer = (string) AssistantConfig::disabledChromePointer();

            $this->assertNotSame('', $pointer);
            $this->assertStringContainsString(
                $pointer,
                (string) AssistantConfig::disabledRecovery(),
                "state {$state}: the chrome pointer '{$pointer}' names a destination the full recovery path never mentions"
            );
        }
    }
}
