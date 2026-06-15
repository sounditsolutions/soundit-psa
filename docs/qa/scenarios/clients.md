## client-overview: See a client's whole picture
- goal: a tech opens a client and can take in the key picture — contacts, assets, open tickets, contract/billing status — without bouncing across many pages
- setup: logged in as staff; a client with some contacts, assets, and tickets exists (dev has seeded clients)
- steps:
  1. Go to the clients area and open an active client (e.g. the one with the most activity)
  2. From the client detail, locate: the primary contact, the client's assets, its open tickets, and any contract/agreement
- expect:
  - The client detail surfaces (or links clearly to) contacts, assets, tickets, and contract/billing
  - Counts/links are accurate (the open-tickets link goes to that client's open tickets, etc.)
- watch:
  - PRODUCT.md "the whole picture in one place": how many clicks to understand who/what/where for this client? Is anything important buried or missing from the detail?
  - Any tab/section that's empty-but-unsignposted, or a count that doesn't match the linked list?

## client-search-create: Find and create clients
- goal: a tech can find an existing client fast and create a new one without friction
- setup: logged in as staff
- steps:
  1. From the clients list, search/filter to a specific client by name
  2. Start creating a new client; fill the obvious required fields and save
  3. Open the created client
- expect:
  - Search/filter narrows to the client quickly
  - The new client saves and shows the entered fields; it appears in the list
- watch:
  - Is search the primary affordance, or hidden? Is the create flow obvious from the list?
  - Required fields signposted before submit, or only errored after? Any silent failure (cf. the ticket Change-Status bug)?

## client-contacts: Reach a client's contacts
- goal: from a client, a tech can find the primary contact and their details (email/phone/role)
- setup: a client with at least one contact/person
- steps:
  1. Open the client and navigate to its contacts/people
  2. Open the primary contact's detail
- expect:
  - The contact's details render; the primary contact is identifiable as primary
- watch:
  - Is "who do I call for this client?" answerable in 1–2 clicks? Is the primary contact distinguished, or just a flat list?
