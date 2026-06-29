# QA run — ai-technician-operator-surfaces 2026-06-25

**Summary:** 4 pass · 1 fail · 0 error

## Scenarios
- ✓ ships-dormant-by-default (agent_enabled off; cockpit approval queue empty) (pass)
- ✓ backlog-agent → AI Technician rename (no 'backlog agent' string anywhere in DOM) (pass)
- ✓ cockpit render + empty states + fail-closed approve (desktop & mobile) (pass)
- ✓ AI Technician settings card renders & functions (copy-jargon findings filed) (pass)
- ✗ sidebar 'Recent' links resolve correctly across hosts (fail)

## Findings
- [bug] 'Recent' sidebar stores absolute host-qualified URLs — links rot on domain change / break across multiple hosts (low) (psa-1xlr)
- [design] [copy] AI Technician card internal jargon — broadened existing bead to also cover Phase 0 (3141), Phase 2 (3188), CO-3 (3245) via comment (minor) (psa-uabb)
