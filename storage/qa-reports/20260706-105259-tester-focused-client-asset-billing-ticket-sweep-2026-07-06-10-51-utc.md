# QA run — tester focused client/asset/billing/ticket sweep 2026-07-06 10:51 UTC

**Summary:** 4 pass · 0 fail · 0 error

## Scenarios
- ✓ client-overview: opened Vandelay Industries, verified the overview exposes the primary contact and contracts, then verified tickets/assets/contracts/primary-contact detail are reachable from the client context (pass)
- ✓ asset-ticket-link: opened T-9, confirmed VAN-APP01 is visible and clickable from the ticket, then confirmed the asset Alerts & Tickets tab links back to T-9 (pass)
- ✓ invoice-list/invoice-detail: opened invoice list, status-filtered posted invoices, and verified INV-2026103 client/status/line totals on detail (pass)
- ✓ tix-create-manual: created T-102 for Vandelay Industries with subject QA create manual ticket 20260706105139, P2 priority, and a Markdown description; verified the saved detail and global queue search (pass)

## Findings
No findings.
