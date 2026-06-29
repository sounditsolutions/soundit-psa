# QA run — Functional scenario sweep (tickets/clients/assets/billing/wiki) 2026-06-23

**Summary:** 17 pass · 0 fail · 0 error

## Scenarios
- ✓ tix-create-manual: New Ticket -> form -> Create lands T-24 with entered client/subject/priority/type (pass)
- ✓ tix-resolve: Resolve modal captures a resolution (recommended, AI-draft offered); status->Resolved + audit note (pass)
- ✓ tix-statuses: New->Resolved->In Progress->Pending Client all persist with status-change audit notes (pass)
- ✓ tix-reopen: Reopen returns Resolved->In Progress, preserves prior resolution ('Prior resolution (ticket reopened)') (pass)
- ✓ client-overview: tabbed whole-picture (Tickets/People/Assets/Contracts/Licenses counts); Tickets(3) count matches list (pass)
- ✓ client-search-create: search narrows to one client; create needs only name, lands on detail with success alert (pass)
- ✓ client-contacts: primary contact 1 click from client, 'Primary' badge + full contact info + M365 staleness (pass)
- ✓ asset-inventory: 23 assets, sortable cols + Online/Offline/Unlinked filters, color+text status badges (pass)
- ✓ asset-detail: VAN-APP01 full context (identity, status/hardware, RMM recency, warranty, client/contract) (pass)
- ✓ asset-ticket-link: bidirectional — asset Alerts&Tickets tab shows #T-1/#T-9; ticket T-9 shows VAN-APP01 (primary) link (pass)
- ✓ invoice-list: client/status/date filters; status badges color+text; money right-aligned and legible (pass)
- ✓ invoice-detail: 7 line items reconcile to $3,337.04 subtotal + $308.68 tax = $3,645.72; cost/margin + sync state shown (pass)
- ✓ contract-to-invoice: contract terms visible; 'Invoices 3' tab lists the 3 driven invoices (count accurate) (pass)
- ✓ wiki-gate (enabled path): /wiki global index + client Wiki tab reachable; wiki pages carry a proper H1 (pass)
- ✓ wiki-client-nav: client wiki skeleton pages grouped (Environment/Notes/Overview) + per-page nav rail + search (pass)
- ✓ wiki-mine-verify: mined facts on correct pages (SonicWall firmware fix from ticket #4), provenance panel with badges + source refs (pass)
- ✓ wiki-fact-actions: Confirm(1-click)/Correct(inline)/Retire(confirm) present + correct; proven working in prior-QA data (pass)

## Findings
- [ux] Invoices have no 'Mark as Paid' — standalone (no Stripe/QBO) invoices can't be completed (psa-8yhp)
- [bug] asset-badge hover handler throws TypeError: e.target.closest is not a function (no Element guard) — app-wide console error (psa-txh4)
