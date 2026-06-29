# QA run — Comprehensive sweep 2026-06-21 (functional + design)

**Summary:** 20 pass · 3 fail · 0 error

## Scenarios
- ✓ tix-note-edit (date/time picker + re-sort + future-date reject) (pass)
- ✓ tix-create-manual (pass)
- ✗ tix-resolve (empty-resolution gap) (fail)
- ✓ wiki-mine-verify (resolve fact-rich -> mining -> facts+provenance) (pass)
- ✓ wiki-fact-actions (confirm / correct / retire) (pass)
- ✓ wiki-client-nav (search + page nav) (pass)
- ✓ wiki-gate (enabled path) (pass)
- ✓ client-overview (counts match lists, primary contact) (pass)
- ✓ client-search-create (search filter + create + required validation) (pass)
- ✓ client-contacts (primary identifiable + details) (pass)
- ✓ asset-inventory (filters + status recency) (pass)
- ✓ asset-detail (whole picture + stale signposted) (pass)
- ✓ asset-ticket-link (bidirectional) (pass)
- ✓ invoice-list (status clarity + money alignment) (pass)
- ✓ invoice-detail (totals reconcile + primary action) (pass)
- ✓ contract-to-invoice (terms + linked invoices) (pass)
- ✓ design-audit: ticket-detail (multi-viewport + axe + impeccable) (pass)
- ✗ design-audit: tickets-list (mobile table overflow) (fail)
- ✓ design-audit: asset-detail (pass)
- ✗ design-audit: client-detail (mobile table clip) (fail)
- ✓ design-audit: dashboard (pass)
- ✓ design-audit: invoice-detail (pass)
- ✓ design-audit: wiki-page (pass)

## Findings
- [ux] Resolve action accepts an empty resolution silently (no prompt/require) (psa-grjd)
- [design] [responsive] Data tables overflow mobile viewport — Tickets queue hides all triage columns behind horizontal scroll (psa-6zs7)
- [design] [color] Pervasive muted-text contrast below WCAG AA across console (psa-de8u)
- [design] [a11y] No H1 on primary console screens + unlabeled icon-buttons/selects (psa-4638)
