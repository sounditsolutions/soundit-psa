# Granular AI Controls — Phased Build Plan

**Date:** 2026-07-09
**Bead:** psa-28j4
**Design (authoritative):** the `design` field of bead **psa-28j4** (`bd show psa-28j4`). This plan mirrors it; read the design first.
**Status:** Draft — build is convergence-gated (do NOT start Phase 1 until the owner approves and the freeze lifts).

## Context (verified against the tree, 2026-07-09)
PSA-native AI functions have inconsistent enable/disable gating. The motivating case: disable auto-ticket-from-calls so GC Chet wins the call→ticket race, WITHOUT dropping email intake — impossible today because both share `intake_enabled`.

**Immediate mitigation (already flagged on the bead, non-gated, no deploy):** set `intake_enabled=0` — stops all call→ticket auto-creation (double-gated at `TranscriptionService.php:335` + `CallIntakePipeline.php:50`; proven by green test `CallIntakePipelineTest::test_dormant_does_nothing_even_for_a_resolved_call`). Caveat: shared gate also disables email intake routing. This plan makes the control call-specific.

## Gap summary (what the build closes)
- **Shared-gate defect:** `intake_enabled` conflates call + email.
- **No-toggle functions** (gated only by `AiConfig::isConfigured()`): reply-draft (`ReplyDraftService:27`), asset-health AI (`AssetHealthService:260`), client-report AI (`ClientReportService:229`), contract-doc summary (`ContractDocumentService:117`).
- Already well-controlled: triage (master + 6 per-stage + auto-review + auto-close), agent, transcription (`auto_transcribe_calls`), assistant, briefings, wiki, portal chatbot, MCP.

## Phases
**Phase 1 — Split the intake gate (delivers the durable call-specific control; behavior-preserving via fallback).**
- Add `AgentConfig::intakeCallEnabled()` / `intakeEmailEnabled()`, each falling back to `intakeEnabled()` when its own key is unset.
- Repoint gates: call → `TranscriptionService:335`, `CallIntakePipeline:50`; email → `EmailService:741`.
- Tests: call-off/email-on ⇒ transcribed call creates no ticket AND email still routes; inverse; legacy `intake_enabled`-only ⇒ both follow it.

**Phase 2 — Master kill-switch + `AiControls` helper.**
- New `app/Support/AiControls.php`: `masterEnabled()` (`ai_master_enabled`), `enabled(fn)` (= master && per-fn), and a `REGISTRY` (key→label→group→default).
- Thread `AiControls::masterEnabled()` into each existing `*Config` gate (one-line `&&`). Test: master off ⇒ every function no-ops regardless of its own toggle.

**Phase 3 — Close the gaps.**
- Add per-function toggles (`reply_draft_enabled`, `asset_health_ai_enabled`, `client_report_ai_enabled`, `contract_doc_summary_enabled`); guard each service entry (+ master). Default = preserve-current (open question §6.1 of design). Per-function dormancy tests.

**Phase 4 — Control surface UI.**
- New `settings/ai-controls` page + `AiControlsController` (recommended over the 4045-line integrations blade). Reuse the generic toggle idiom (`IntegrationsController:421 Setting::setValue($key,$enabled)`) and grouped `foreach` (`:238`). Nav + route. Settings round-trip test.

**Phase 5 — Docs & data migration.**
- Optional one-time seed of `intake_call_enabled`/`intake_email_enabled` from current `intake_enabled`. Update `docs/INSTALL.md` (new settings) per the living-docs rule.

## Rails
- Held-only: ADD enable/disable switches ONLY. Introduce NO new auto-act thresholds; leave `propose_close_auto_threshold` / `intake_attach_auto_threshold` / `intake_spam_block_auto_threshold` null-default and untouched.
- TDD: failing test first for every gate.

## Open questions
See design §6 (gap-function default, UI placement, master scope, `intake_enabled` deprecation).
