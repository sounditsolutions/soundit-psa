# QA run — Clients, Assets & Billing

**Summary:** 5 pass · 4 fail · 0 error

## Scenarios
- ✗ client-overview (fail)
- ✓ client-search-create (pass)
- ✓ client-contacts (pass)
- ✓ asset-inventory (pass)
- ✗ asset-detail (fail)
- ✗ asset-ticket-link (fail)
- ✗ invoice-list (fail)
- ✓ invoice-detail (pass)
- ✓ contract-to-invoice (pass)

## Findings
- [bug] Asset detail 500s when Tactical local_ips is a string (count() TypeError) — both VAN-DC01 and VAN-APP01 [high/P1] (psa-aayf)
- [bug] Client sub-tab pages throw JS TypeError (activity-tab listener on null) [medium/P2] (psa-d0yp)
- [ux] Invoice list never shows 'Overdue' — past-due Posted invoices look identical to current ones [medium/P2] (psa-bpd6)
- [ux] Asset 'Alerts & Tickets' tab hides resolved tickets by default, shows (0) / 'No tickets found' [medium/P2] (psa-o1go)
