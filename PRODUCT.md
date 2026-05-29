# Product

## Register

product

## Users

**Primary — MSP staff (internal console).** Owners and technicians at *small* managed service providers, typically a one-person shop or an owner with a few techs. There is minimal role compartmentalization: the same person triages tickets, checks an asset, looks up a contract, and sends an invoice, often in the same sitting. They are generalists who need to see relevant information *across* domains — billing, assets, ticketing, the client's environment — to make the right call, not specialists who live in one walled-off module. Their context is reactive and high-volume: triaging inbound tickets from many sources (email, phone, RMM/security webhooks, portal), checking asset and client state, managing contracts and billing, and reconciling vendor integrations. They live in the app all day and return to the same screens hundreds of times a week, so they need to move fast without re-reading the UI.

**Secondary — MSP clients (portal).** End-user contacts at the MSP's customers who occasionally sign in to the client portal to view their tickets, invoices, devices, and service agreements, reply to staff, submit requests, and buy prepaid time. They are non-experts visiting infrequently; the portal must be self-explanatory on first contact.

## Product Purpose

Sound PSA is a modern, self-hosted PSA (professional services automation) platform for managed service providers — a single system of record for tickets, clients, assets, contracts, billing, and the dozen+ vendor integrations an MSP runs (RMM, M365, email security, DNS, backup, EDR, payments). It is standalone-first: every module works natively rather than wrapping another vendor's API. It exists to replace heavy legacy PSAs with something an MSP can clone, brand, and run themselves. Success is a technician clearing a ticket queue and an owner trusting the billing numbers — both without fighting the tool.

## Brand Personality

Calm, trustworthy, professional. The voice is plain and direct — it names what a control does, never markets. Emotionally the interface should read as dependable and unhurried even when the queue is full: the steady system of record an MSP runs its business on, not a flashy app demanding attention. Confidence comes from consistency and accuracy, not decoration.

## Anti-references

- **Cluttered legacy PSA** (ConnectWise, Halo, Autotask): grey, cramped, every pixel a control, punishing learning curve. The thing we are replacing.
- **Generic SaaS template**: Inter-for-everything, purple/blue gradients, identical icon-card grids, eyebrow kickers above every section — the interchangeable startup/AI look.
- **Playful / consumer**: mascots, candy colors, emoji, bouncy motion. This handles real money and real incidents; it must read as a serious tool.
- **Over-designed / flashy**: gradient-soaked, glassmorphic, animation everywhere. Decoration that slows the work down is a defect here.

## Design Principles

- **The whole picture in one place.** The user is one generalist making cross-domain calls, not a specialist in a single module. Surface relevant context from adjacent domains (the ticket's client, their contract and prepay balance, their assets and environment) on the surface where a decision gets made, instead of forcing a hunt across siloed screens. Connect, don't compartmentalize.
- **Clarity under load.** The default user is scanning a busy queue under time pressure. Surface the signal (status, priority, who's waiting, what's overdue) and suppress chrome. Hierarchy and contrast do the work, not ornament.
- **Trust through consistency.** This is a system of record for billing and contracts. Patterns, labels, and numbers behave the same way everywhere; predictability is a feature and surprise is a bug.
- **Earn density, don't default to it.** Be dense where the expert workflow rewards it (tables, ticket detail, dashboards) and calm everywhere else. Reject legacy-PSA clutter without overcorrecting into sparse, click-heavy emptiness.
- **Say what it does.** Labels, buttons, and empty/error states use plain language that states the action or condition. No marketing voice — it's a tool the same people use every day.
- **Accessible because it's used all day.** Meet the bar by default (see below); long daily use makes contrast, focus states, keyboard paths, and reduced motion practical needs, not compliance checkboxes.

## Accessibility & Inclusion

Target **WCAG 2.1 AA**. Body text ≥4.5:1 against its background and large text ≥3:1; visible keyboard focus on every interactive element and full keyboard operability of core flows (ticket triage, forms, tables); honor `prefers-reduced-motion` with a non-animated alternative for every transition. Don't rely on color alone to convey status (priority, ticket state) — pair it with text or icon. The portal in particular must be operable by infrequent, non-expert users without training.
