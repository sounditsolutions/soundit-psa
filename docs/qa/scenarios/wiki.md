## wiki-gate: Wiki master switch gates the UI
- goal: the wiki UI appears only when wiki_enabled is on
- setup: logged in as staff; note current wiki_enabled state in Settings
- steps:
  1. With the wiki disabled, try to open /wiki and a client's Wiki tab
  2. Enable the wiki in Settings > General > Client Wiki
  3. Re-open /wiki and the client Wiki tab
- expect:
  - Disabled: /wiki and the client Wiki routes 404 (or are hidden)
  - Enabled: the global wiki index and the client Wiki tab are reachable
- watch:
  - Is the master switch (and its relationship to the mining toggle) clear in Settings?
  - When disabled, is the absence confusing (dead link) or cleanly hidden?

## wiki-client-nav: Navigate a client's wiki
- goal: a tech can get to client environment knowledge quickly
- setup: wiki enabled; a client with seeded skeleton pages (and ideally some mined facts)
- steps:
  1. From a client, open its Wiki
  2. Reach the network, infrastructure, and m365 pages
  3. Use the wiki search for a known term
- expect:
  - The client's pages are listed and open correctly; search returns relevant pages/facts
- watch:
  - How many clicks to read the client's environment? Is jumping between pages cumbersome for a tech under pressure? (Known UX concern — characterize it concretely with click counts and a screenshot.)
  - Is search prominent and fast, or buried below a page grid?

## wiki-mine-verify: Mined facts appear with provenance
- goal: resolving a fact-rich ticket populates the client wiki with verifiable facts
- setup: wiki enabled + auto-mine on; queue worker running; a client with a resolvable ticket
- steps:
  1. Resolve a ticket for the client with a fact-rich resolution (hardware/config/issue)
  2. Wait for mining, then open the client's relevant wiki page
  3. Open the provenance panel
- expect:
  - New facts appear on the right pages, born unverified, with source links to the ticket
  - The provenance panel renders cleanly (counts, badges, source refs)
- watch:
  - Did good facts get extracted, or silently discarded? (Check stage_results if zero.)
  - Is the provenance panel legible at a glance, or noisy? Are badges color+text (not color-only)?

## wiki-fact-actions: Confirm / correct / retire a fact
- goal: a tech can verify or fix a mined fact in one or two clicks
- setup: a client wiki page with at least one unverified mined fact
- steps:
  1. Open the provenance panel and Confirm an unverified fact
  2. Correct another fact's wording
  3. Retire a third fact
- expect:
  - Confirm is one click; correct edits in place; retire has a lightweight confirm; statuses update
- watch:
  - Are the actions cheap enough that a busy tech would actually use them?
  - Any action that is heavier than it should be, or unclear in effect?
