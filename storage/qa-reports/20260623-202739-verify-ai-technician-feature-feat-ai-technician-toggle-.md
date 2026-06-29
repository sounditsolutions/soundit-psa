# QA run — Verify AI Technician feature (feat/ai-technician-toggle)

**Summary:** 5 pass · 0 fail · 0 error

## Scenarios
- ✓ step1: toggle renders (Disabled badge) + persists (Active, both switches checked after save+reload) (pass)
- ✓ step2: auto-ack fires within seconds via live worker + body contains BOTH disclosure lines, AI-authored (ai_authored=1, who_type=Agent) (pass)
- ✓ step3: idempotent — exactly ONE ack note after re-dispatching the loop twice more (1 run/1 append-only log) (pass)
- ✓ step4: disabled = no ack — feature OFF, new ticket gets 0 runs / 0 ack notes (loop never dispatched) (pass)
- ✓ step5: design audit of AI Technician card (impeccable + axe + 3 viewports) — 19/20 Excellent, no fileable card-specific design defect (pass)

## Findings
No findings.
