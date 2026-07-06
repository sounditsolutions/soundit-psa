# QA run — tester client-overview contract-to-invoice 2026-07-06 10:30 UTC

**Summary:** 1 pass · 1 fail · 0 error

## Scenarios
- ✓ client-overview: Vandelay detail and linked people/assets/tickets/contracts pages loaded and exposed expected sections; ticket-count and blank-phone issues are already open (pass)
- ✗ contract-to-invoice: contract #4, its invoices tab, and INV-2026103 detail rendered expected client/status/totals, but the contract detail did not flag an overdue recurring run (fail)

## Findings
- [ux] Contract detail does not flag overdue recurring profile runs (psa-fbyq)
