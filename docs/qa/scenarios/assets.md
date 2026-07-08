## asset-inventory: Browse and find an asset
- goal: a tech can browse the asset/device inventory and narrow to a specific device under load
- setup: logged in as staff; dev has seeded assets (some with RMM/Tactical data)
- steps:
  1. Go to the assets area
  2. Search/filter to a specific device (by name, client, or type)
  3. Open that asset's detail
- expect:
  - The list shows assets with enough to identify them (name, client, type/status)
  - Search/filter narrows correctly; the opened asset is the one selected
- watch:
  - Is the list usable at scale (filter by client/type, sort), or a flat dump? How many clicks to a specific device?
  - Are online/offline or stale-sync states visually clear, or do you have to read carefully?

## asset-detail: An asset shows its full context
- goal: an asset's detail gives the tech the device's client, type, status, and related tickets in one place
- setup: an asset linked to a client (ideally with RMM/Tactical status and a related ticket)
- steps:
  1. Open an asset's detail
  2. Locate its client, its live status (online/offline, last seen), and any linked tickets
- expect:
  - Client, type, and status render; RMM/Tactical status (if present) is shown with recency
  - Linked tickets (if any) are reachable from the asset
- watch:
  - "Whole picture in one place": can the tech tell what this device is, whose it is, whether it's healthy, and what's open on it — without leaving the page?
  - Is missing/stale data signposted ("never seen", "no RMM agent"), or silently blank?

## asset-ticket-link: Assets ↔ tickets are linked both ways
- goal: the relationship between an asset and a ticket is discoverable from either side
- setup: a ticket with a linked asset (the ticket UI supports linking assets)
- steps:
  1. From the ticket, confirm the linked asset is shown and clickable
  2. From that asset, confirm the ticket is shown/reachable
- expect:
  - The asset is visible on the ticket and the ticket is visible on the asset (bidirectional)
- watch:
  - Is the link obvious in both directions, or only one? Does linking/unlinking give feedback?
