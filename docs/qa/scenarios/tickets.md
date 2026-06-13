## tix-create-manual: Create a ticket manually
- goal: a tech can create a ticket and it lands in the queue with the entered fields
- setup: logged in as staff; an active client exists
- steps:
  1. Go to the tickets area and start a new ticket
  2. Fill subject, description, pick a client and priority, save
  3. Open the created ticket
- expect:
  - The ticket shows the subject/description/client/priority just entered
  - The ticket appears in the ticket list
- watch:
  - Is the create flow obvious, or buried? How many clicks from the queue to a saved ticket?
  - Are required fields signposted before submit, or only errored after?

## tix-resolve: Resolve a ticket with a resolution
- goal: resolving captures a resolution and (wiki on) triggers mining
- setup: an open/in-progress ticket for an active client
- steps:
  1. Open the ticket
  2. Set status to Resolved and enter a resolution describing the fix
  3. Reload and confirm the resolution persisted
- expect:
  - The ticket shows Resolved with the resolution text
  - With the wiki enabled + auto-mine on and a queue worker running, a wiki_run completes for the client within ~1 min
- watch:
  - Does the Resolve action prompt for / require a resolution, or silently allow an empty one? (Known gap — confirm it is or isn't fixed.)
  - Is it clear that resolving with a resolution feeds the wiki? Any feedback that mining happened?

## tix-statuses: Move a ticket through its statuses
- goal: status transitions work and are reflected consistently
- setup: an open ticket
- steps:
  1. Move the ticket: open -> in progress -> pending client -> resolved -> closed
  2. Check the ticket history/notes after each
- expect:
  - Each status persists and shows in the ticket and the list
  - Status-change audit notes are recorded
- watch:
  - Is the difference between Resolved and Closed clear to the tech? (Owner almost always uses Resolved.)
  - Any transition that is unintuitive or lacks confirmation/feedback?

## tix-reopen: Reopen a resolved ticket
- goal: a resolved ticket can be reopened and worked again
- setup: a resolved ticket
- steps:
  1. Reopen the ticket
  2. Add a note, change status
- expect:
  - The ticket returns to an open/active status and is editable
- watch:
  - Is "reopen" discoverable? Does reopening lose or preserve the prior resolution?
