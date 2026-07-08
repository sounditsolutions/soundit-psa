# QA run — tester broad scenario sweep 2026-07-05 09:09 UTC

**Summary:** 12 pass · 2 fail · 1 error

## Scenarios
- ✓ tix-create-manual: created T-44 with subject, description, Vandelay client, and P2 priority visible on detail (pass)
- ✓ tix-statuses: T-44 moved New -> In Progress -> Pending Client -> Resolved -> Closed -> In Progress with audit notes recorded (pass)
- ✓ tix-resolve: dedicated Resolve modal captured and displayed a resolution on T-44; empty-resolution gap is already open (pass)
- ✓ tix-reopen: T-44 reopened, accepted a new note, and preserved the prior resolution (pass)
- ✓ client-search-create: found Vandelay by search, created QA Tester Client Retry 20260705090834, and found it in the client list (pass)
- ✓ client-overview: Vandelay overview surfaces people, assets, tickets, contracts, and billing links; known count/phone issues already open (pass)
- ✓ client-contacts: opened primary person George Costanza and confirmed email/primary/client context; blank phone/mobile already open (pass)
- ✓ asset-inventory: searched VAN-APP01 and opened its asset detail (pass)
- ✗ asset-ticket-link: ticket T-1 links to VAN-APP01, but /assets/8/tickets returns 500 with undefined $cometJobData; duplicate of open QA findings (fail)
- ✓ invoice-list: invoice list shows INV-2026107 as Overdue with client, totals, and status visible (pass)
- ✗ invoice-detail: INV-2026107 detail renders line items and totals, but status reads Posted while the list marks it Overdue; duplicate of open QA finding (fail)
- ✓ contract-to-invoice: contract #4 shows terms, monthly billing, profile, and generated invoices (pass)
- ✓ wiki-gate: disabling the Client Wiki module makes /wiki 404, and re-enabling restores /wiki (pass)
- ✓ wiki-client-nav: Vandelay wiki index, network/infrastructure/m365 pages, and search for QA-SW-A are reachable (pass)
- ⚠ wiki-fact-actions: provenance panel opens and Correct/Retire controls are reachable, but no unverified fact was available to confirm in current dev data (error)

## Findings
No findings.
