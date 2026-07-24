<?php

namespace App\Support;

/**
 * The SINGLE source of operator-facing labels for staged/held action types
 * (psa-2f0bg UX review psa-90ix3).
 *
 * A raw action_type like `cipp_stage_reset_user_password` is implementation
 * language; an operator scanning a phone notification or a cockpit card needs
 * "CIPP password reset". This map is that translation, and it lives in ONE place
 * on purpose: the cockpit badge (resources/views/cockpit/index.blade.php) and the
 * staged-action notification job both consume it, so the operator sees the same
 * name in both surfaces and neither can drift. Adding a second label list — the
 * thing the review explicitly warned against — is exactly the N-lists-that-must-
 * agree failure this codebase keeps paying for (the #306 badge map that rendered a
 * password reset as "Reply").
 *
 * An unknown type falls back to a de-slugged, title-cased form rather than the raw
 * slug, so a newly added action is at least readable before it earns a curated
 * label. StagedActionLabelsTest asserts every currently-stageable type has a
 * curated (non-fallback) entry, so "readable but ugly" can never quietly ship.
 */
class StagedActionLabels
{
    /**
     * @var array<string, string> action_type => operator-facing label.
     *
     * These MIRROR the cockpit badge labels exactly (the cockpit consumes this same
     * map via $badgeFor), so the operator sees one consistent name across the cockpit
     * card and the notification. StagedActionLabelsTest asserts the cockpit blade and
     * this map cannot drift.
     */
    private const LABELS = [
        // PSA-native
        'propose_close' => 'Proposed close',
        'propose_merge' => 'Proposed merge',
        'propose_resolution' => 'Proposed resolution',
        'stage_email' => 'Staged email',
        'send_email' => 'Staged email',
        'stage_public_note' => 'Staged public note',
        'write_public_note' => 'Staged public note',
        'direct_close' => 'Closed directly',

        // Tactical
        'tactical_stage_script' => 'Tactical script',
        'tactical_stage_command' => 'Tactical command',
        'tactical_stage_reboot' => 'Tactical reboot',
        'tactical_stage_shutdown' => 'Tactical shutdown',
        'tactical_stage_recover_mesh' => 'Tactical recovery',
        'tactical_stage_maintenance' => 'Tactical maintenance',
        'tactical_stage_stop_service' => 'Tactical stop service',
        'tactical_stage_restart_service' => 'Tactical restart service',
        'tactical_stage_install_approved_patches' => 'Tactical patch install',
        'tactical_stage_reset_patch_policies' => 'Tactical policy reset',
        'tactical_stage_run_policy_task_all' => 'Tactical policy task',

        // CIPP / Microsoft 365
        'cipp_stage_reset_user_password' => 'CIPP password reset',
        'cipp_stage_disable_user_sign_in' => 'CIPP disable sign-in',
        'cipp_stage_enable_user_sign_in' => 'CIPP enable sign-in',
        'cipp_stage_revoke_user_sessions' => 'CIPP revoke sessions',
        'cipp_stage_remove_user_mfa_methods' => 'CIPP remove MFA',
        'cipp_stage_set_legacy_per_user_mfa' => 'CIPP legacy MFA',
        'cipp_stage_assign_user_license' => 'CIPP assign license',
        'cipp_stage_remove_user_license' => 'CIPP remove license',
        'cipp_stage_convert_mailbox' => 'CIPP mailbox convert',
        'cipp_stage_set_mailbox_forwarding' => 'CIPP mailbox forwarding',
        'cipp_stage_set_mailbox_gal_visibility' => 'CIPP GAL visibility',
        'cipp_stage_set_mailbox_out_of_office' => 'CIPP out of office',
        'cipp_stage_set_mailbox_delegate' => 'CIPP mailbox delegate',
        'cipp_stage_remove_directory_role' => 'CIPP directory role removal',
        'cipp_stage_release_quarantine_message' => 'CIPP quarantine release',
        'cipp_stage_add_tenant_allow_entry' => 'CIPP tenant allow-list',
        'cipp_stage_wipe_device' => 'CIPP device wipe',
        'cipp_stage_reassign_onedrive' => 'CIPP OneDrive handover',
        'cipp_stage_create_user' => 'CIPP create user',
        'cipp_stage_edit_user' => 'CIPP edit user',
        'cipp_stage_set_group_membership' => 'CIPP group membership',
    ];

    /** Operator-facing label for a staged/held action type. Never returns a raw slug. */
    public static function humanLabel(string $actionType): string
    {
        return self::LABELS[$actionType] ?? self::deslug($actionType);
    }

    /** Whether this type has a curated label (vs the de-slug fallback). */
    public static function hasCuratedLabel(string $actionType): bool
    {
        return array_key_exists($actionType, self::LABELS);
    }

    private static function deslug(string $actionType): string
    {
        // cipp_stage_foo_bar -> "Foo bar" (strip vendor/stage prefixes, title-case first word).
        $slug = preg_replace('/^(cipp|tactical)_(stage_)?/', '', $actionType) ?? $actionType;
        $words = str_replace('_', ' ', (string) $slug);

        return ucfirst(trim($words)) ?: $actionType;
    }
}
