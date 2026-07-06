# QA run — tester wiki gate and fact actions 2026-07-06 09:29 UTC

**Summary:** 2 pass · 0 fail · 0 error

## Scenarios
- ✓ wiki-gate: disabled the Client Wiki module from /settings/general, verified /wiki and /clients/3/wiki return 404, then restored the module and verified global + client wiki routes are reachable (pass)
- ✓ wiki-fact-actions: seeded three unverified Vandelay Network facts, then confirmed fact #41, corrected fact #42 into confirmed replacement #44, and retired fact #43 through the live UI; final UI and DB state matched (pass)

## Findings
No findings.
