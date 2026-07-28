# soundit-office teardown — migration entry point (2026-07-28)

The soundit-office development VM was torn down on 2026-07-28. Its issue tracker (a local bead store)
**did not migrate** — the incoming environment runs a different work system. Everything below was
exported to GitHub because GitHub is the only surface that outlived the box.

Written by the soundit-office manager at teardown. **Every claim here was verified against git or the
GitHub API at the time of writing, not taken from the tracker** — that distinction turned out to matter
(see "A correction worth reading" at the end).

---

## 1. Where things live now

| what | where |
|---|---|
| Backlog audit — 256 items read, 117 keep / 139 drop | GitHub issue **#330** |
| Staff-MCP attachment-fetch gap | GitHub issue **#331** |
| Calendar Slice B — full review record, 3 lenses | PR **#329** (retitled DO NOT MERGE) |
| Backup posture (Comet + Servosity) — gate passed, needs rebase | PR **#316** |
| Invoice description repair — gate passed, owner-gated | PR **#299** |
| 9 office-only branches, extracted as patches | branch `preserve/2026-07-28/stranded-office-only-patches` |
| 10 office-only branches, pushed intact | branches `preserve/2026-07-28/*` |

---

## 2. Open decisions that need an owner

These were live questions when the office went away. None is urgent; all will be re-discovered
expensively if lost.

**Grant-default flip (bare grant = immediate).** A bare MCP grant currently means **immediate
execution**; holding every call for a human requires granting `name:staged` explicitly. The safer
default is arguably the opposite, **but flipping it breaks 73 tests across 8 suites**, one of which owns
the mode contract. It is a migration across the whole write surface, not a setting. It was assessed
2026-07-24 and deliberately held. **Evidence it is worth doing eventually:** on 2026-07-28 a competent
engineer documented the rule *backwards* in `docs/INSTALL.md`, 1,478 lines away from the correct
statement in the same file — people write down what they expect the default to be.

**Calendar slot-ranking layer.** Deliberately deferred out of Slice B, not forgotten. Read-side only:
turns the `get_schedule` grid into proposed working-hours slots.

**Staff-MCP attachment fetch (issue #331).** Agents cannot see image attachments on tickets; a client
screenshot of an error reads as an empty note, silently. Note the constraint recorded there: resolve
server-side under the token's existing ticket scope — **do not widen the attachment route to accept any
bearer**, or a read-a-screenshot feature becomes arbitrary file read.

**Tiered / graduated recurring-invoice pricing (`psa-ref`).** A complete feature stranded by a trunk
fork. Already on the remote as `psa-ref-tiered-pricing`; it needs a product call, not engineering.

**Invoice description repair (PR #299).** Gate passed. Deliberately **not merged** at teardown because
it touches client billing records. Merging the code does not run the repair — the command is dry-run by
default with a `--revert` and an audit ledger — but the decision of whether to rewrite descriptions on
invoices clients have already received is a finance call, not a dev one.

---

## 3. The five 07-08 snapshot branches carrying a dev `.env` backup

Five branches on this remote (`polecat/psa-2ab`, `psa-ebw`, `psa-hbh`, `psa-ref`, `psa-vif`) contain
`.env.bak-pre-msgraph-20260708T195933Z`, a developer's local `.env`.

**Assessed 2026-07-28 and it is a hygiene problem, not a breach.** The file's own contents say
`APP_ENV=local`, `APP_URL=http://localhost`, `DB_DATABASE=soundit_psa_dev`. The credentials in it are for
a localhost dev database and were **never production values** — production's key and password hash
differently, not because anything was rotated in time but because these were never prod. Only two fields
were populated at all (`APP_KEY`, `DB_PASSWORD`); the Halo and Plivo entries are present but empty.

Owner decision: scrub for hygiene. **`polecat/psa-hbh` is verified free to delete outright** — its
content is already in `main` and a cherry-pick produces zero changes. Note that deleting a branch does
not erase the objects; they stay reachable by exact SHA until garbage collection. Proportionate for
dev-only credentials.

**This is why the nine rescued branches were exported as patches rather than pushed** — they share that
snapshot base, and pushing them would have added nine more copies of the file on the same day the
decision was made to remove it.

---

## 4. A correction worth reading, because it will bite the next person

At teardown, five PRs looked like "finished work that never merged" — gate passed, CI green, and their
head SHAs were **not ancestors of `main`**. Four of their tickets even read *closed*.

**Two of those five had in fact already shipped.** Their work reached `main` through a *rebased*
lineage, so the branch SHA was genuinely absent from `main` while the *content* was fully present.
Merging them was a no-op: zero files changed.

**SHA ancestry tells you whether a specific commit was deployed. It does not tell you whether the work
landed.** For "did this ship?", check the commit *subject* or diff the branch against `main` —
`git diff --stat main...branch` returning empty is the answer. This repository has ~247 remote branches
and 35+ already-merged-but-still-open, so the trap is common here rather than exotic.

The genuinely unshipped work in that set was: the staff DOM-XSS fix (landed and deployed 2026-07-28),
backup posture (#316, conflicted), and the invoice repair (#299, owner-gated).
