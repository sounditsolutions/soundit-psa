# QA run — Verify-a-change: Prospect intake (PRs #51–54) 2026-06-23

**Summary:** 17 pass · 1 fail · 0 error

## Scenarios
- ✓ capture: search-first 'Attach to existing client' control renders on unresolved call (PR #51) (pass)
- ✓ capture: '+ New client' provisions Client(stage=Prospect)+Person+Ticket, lands on ticket (PR #51) (pass)
- ✓ gates: prospect Person is portal-inert (portal_enabled=0, password=null); phone normalized+seeded (PR #51) (pass)
- ✓ gates: prospect ticket is inert — 0 triage runs, contract_id/due_at null (no SLA) (PR #51) (pass)
- ✓ badge: 'Prospect' badge on client detail + ticket detail headers (PR #51) (pass)
- ✓ client-list: stage filter chips All/Active/Prospects (PR #53) (pass)
- ✓ client-list: gold 'Prospect' badge on list row under Prospects filter (PR #53) (pass)
- ✓ calls: 'Unknown caller' facet — correct predicate (client_id NULL & followed_up_at NULL); surfaces answered too (PR #51) (pass)
- ✓ search-select: clicking a search result populates the caller form and does NOT navigate (PR #52) (pass)
- ✓ dedup: repeat-caller number surfaces 'already on [X]' confirm + 'Create new client anyway' (PR #51) (pass)
- ✓ dismiss: 'Not a lead / dismiss' removes call from facet, preserves Call Log history, creates nothing (PR #51) (pass)
- ✓ convert: backend POST prospects.convert flips stage prospect->active, redirects to success screen (PR #51) (pass)
- ✗ convert: a UI trigger exists to reach the convert flow (PR #51) (fail)
- ✓ converted-screen: echoes open tickets ('Original captured request') + onboarding checklist (PR #51) (pass)
- ✓ design-audit: mobile tickets-list stacks into cards, no horizontal overflow, triage signal visible (PR #54 / psa-6zs7) (pass)
- ✓ design-audit: mobile ticket-detail leads with content (subject+resolution+notes), sidebar stacks below (PR #54 / psa-i2e7) (pass)
- ✓ design-audit: prospect capture UI — search-first hierarchy holds across mobile/tablet/desktop (pass)
- ✓ design-audit: converted success screen — clean checklist + captured-request echo across breakpoints (pass)

## Findings
- [bug] Prospect 'Convert to client' has no UI trigger — convert flow is unreachable from the product (psa-s6ov)
- [ux] Dedup warning asks 'attach to that client instead?' but offers no attach action (psa-wjlv)
