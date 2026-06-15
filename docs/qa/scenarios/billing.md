## invoice-list: Browse invoices and their status
- goal: a tech/owner can browse invoices and tell each one's state (draft/sent/paid/overdue) at a glance
- setup: logged in as staff; dev has seeded invoices/contracts
- steps:
  1. Go to the invoices/billing area
  2. Filter by client and/or status; open one invoice
- expect:
  - The list shows each invoice's client, amount, and status
  - Filtering by status/client narrows correctly
- watch:
  - Is status (draft vs sent vs paid vs overdue) visually unambiguous, with color+text (not color alone)?
  - Are money totals legible and aligned? Any obvious mis-formatting?

## invoice-detail: An invoice reads correctly
- goal: an invoice's detail shows line items, the client, totals, and status, and its actions are clear
- setup: an existing invoice with line items
- steps:
  1. Open an invoice's detail
  2. Read the line items, subtotal/tax/total, client, and status; locate the primary action (send/edit/mark-paid)
- expect:
  - Line items and totals render and add up; the client and status are correct
  - The primary action for the invoice's state is obvious
- watch:
  - Do the totals visibly reconcile (line items → subtotal → total)? Any number that looks wrong?
  - For a synced/auto-pushed invoice, is it clear what's editable vs locked, and why?

## contract-to-invoice: Contracts and recurring billing
- goal: a tech can see a client's contract/agreement and understand how it relates to invoices
- setup: a client with a contract/agreement
- steps:
  1. Open a client's contract/agreement
  2. Find its terms (recurring amount, period) and any invoices it generated
- expect:
  - The contract shows its terms; the link between contract and the invoices it drives is discoverable
- watch:
  - Can the tech answer "what is this client paying, and is it invoiced?" without guesswork?
  - Any cumbersome flow to go from a contract to its invoices (or vice-versa)?
