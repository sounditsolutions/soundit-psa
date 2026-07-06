# QA run — tester focused client ticket billing QA 2026-07-06 14:03 UTC

**Summary:** 2 pass · 1 fail · 0 error

## Scenarios
- ✓ client-search-create: created QA Search Create Client 20260706140042, confirmed detail fields, and searched the client list back to the created record (pass)
- ✓ tix-create-manual: created ticket T-103 for Vandelay Industries, confirmed subject/description/client/priority on detail, and found it from ticket-list search (pass)
- ✗ invoice-detail: INV-2026107 totals/client rendered, but detail showed Posted while the invoice list showed Overdue; duplicate of open psa-oujh, not refiled (fail)

## Findings
- [bug] Invoice detail shows Posted for an invoice the list marks Overdue (existing open finding, not refiled) (psa-oujh)
