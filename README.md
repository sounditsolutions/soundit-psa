# Stranded work rescued from the soundit-office VM, 2026-07-28

These nine feature branches existed **only** on the soundit-office development VM, which was torn down
on 2026-07-28. They were never pushed to this remote and would have been permanently lost.

## Why patches and not branches

Each of these branches is cut from `e01f974` — the shared "office dev PSA code snapshot" base — and that
base commit contains **`.env.bak-pre-msgraph-20260708T195933Z`**, a developer's local `.env` backup.
Five branches already on this remote carry that file; the owner asked on 2026-07-28 for those to be
scrubbed for hygiene. Pushing these nine branches as-is would have added nine more copies of the same
file to a public repository on the same day the decision was made to remove it.

So the **work** was extracted and the **base** was left behind. Every patch here was checked and
contains **zero references to that file** — they carry only the feature commits.

To confirm that for yourself:

    grep -l 'env\.bak' patches/*.patch    # expect: no matches

## What is here

| patch | what it adds |
|---|---|
| `polecat-psa-g9l.patch` | **billing calendar for upcoming invoice dates** |
| `polecat-psa-7v9.patch` | QBO expense + bank balance sync |
| `polecat-psa-aci.patch` | weekly / bimonthly / semiannual billing frequencies |
| `polecat-psa-t8k.patch` | transfer prepaid time balance between contracts |
| `polecat-psa-hb9.patch` | `billing:backfill-prepaid-time` command for historical invoice lines |
| `polecat-psa-cvh.patch` | backup storage tier billing coverage |
| `polecat-psa-cbr.patch` | merge two clients into one |
| `polecat-psa-qq1.patch` | exact-match duplicate-order guard |
| `polecat-psa-zuj.patch` | AI asset health score (badge, filter, cached explanation) |

## ⚠ Read before applying the billing calendar

`polecat-psa-g9l.patch` **calls an API that has since been renamed to a *fractional* equivalent.**
A careless port compiles and runs, and yields **silently wrong billing dates**. Check the call sites
against current `main` before trusting the output — this one will not fail loudly.

## Applying

    git checkout -b <feature> origin/main
    git am -3 patches/<file>.patch

These were written against a July 8 snapshot and `main` has moved a long way since. Expect real
conflicts; a cherry-pick of the same work was measured as conflicting in 2–9 files per branch on
2026-07-28. **Treat these as a recoverable record of intent, not as mergeable code.** The question for
each is "do we still want this feature?" — the merge cost is owed either way.

## Provenance

Extracted by the soundit-office manager on 2026-07-28 during teardown, at the request of the incoming
soundit-dev point of contact. Branch names above are the original office branch names.
