#!/usr/bin/env bash
# boot-watchdog-sweep.sh — periodic deacon health watchdog.
#
# Replaces the suspended always-on gastown.boot agent (so-vhn6). Boot re-primed a fresh
# Opus context every ~2min (~99% of city compute) just to answer ONE question each wake:
# "is the deacon stuck?" This restores that single function as a cheap deterministic exec
# order instead of an always-on LLM agent.
#
# LOGIC (scripted heuristic — the controller still owns deacon PROCESS liveness; a dead
# deacon is auto-restarted, NOT our job):
#   - deacon absent / not active            -> no-op (controller handles it), clear incident
#   - deacon active, idle < STALE_AFTER      -> healthy, no-op, clear incident
#   - deacon active, idle >= STALE_AFTER     -> soft-stuck: nudge it ONCE (per incident)
#   - still stale >= ESCALATE_AFTER after a  -> escalate to manager ONCE (human look;
#     nudge                                     deliberately NOT an auto-warrant/restart)
# deacon's exponential backoff caps at 300s, so >20min with no activity is abnormal.
#
# DEFAULT OFF (house two-key pattern): registering this order does nothing until armed via
#   touch /home/charlie/soundit-office/.gc/boot-watchdog-sweep.enabled
# (reversible: rm the marker). Near-instant no-op every tick while unarmed.
set -uo pipefail

CITY="$HOME/soundit-office"
ENABLE_KEY="$CITY/.gc/boot-watchdog-sweep.enabled"
GC_BIN="${GC_BIN:-$HOME/go/bin/gc}"      # office gc lives in ~/go/bin (PATH gotcha)
TARGET="gastown.deacon"
STATE_DIR="$HOME/.local/state/boot-watchdog"
STALE_FLAG="$STATE_DIR/deacon-stale-since"   # epoch of first stale detection this incident
NUDGED_FLAG="$STATE_DIR/deacon-nudged"       # nudged this incident
ESC_FLAG="$STATE_DIR/deacon-escalated"       # escalated to manager this incident
STALE_AFTER=1200                             # 20min idle -> soft-stuck (backoff caps at 300s)
ESCALATE_AFTER=2400                          # 40min continuously stale after a nudge -> escalate

[ -f "$ENABLE_KEY" ] || exit 0               # unarmed: near-instant no-op
command -v python3 >/dev/null 2>&1 || exit 0 # no parser -> safe no-op
mkdir -p "$STATE_DIR" 2>/dev/null || true

clear_incident() { rm -f "$STALE_FLAG" "$NUDGED_FLAG" "$ESC_FLAG" 2>/dev/null || true; }

# 1. Machine-readable deacon state + last_active
JSON="$("$GC_BIN" --city "$CITY" session list --json 2>/dev/null)" || exit 0
read -r STATE LAST_ACTIVE <<EOF2
$(printf '%s' "$JSON" | python3 -c "
import sys,json
try: d=json.load(sys.stdin)
except Exception: sys.exit(0)
rows=d if isinstance(d,list) else d.get('sessions',d.get('items',[]))
for s in rows:
    if s.get('template')=='$TARGET':
        print((s.get('state') or ''), (s.get('last_active') or '')); break
" 2>/dev/null)
EOF2

# 2. Deacon absent or not active -> controller owns liveness; clear incident, no-op
if [ -z "${STATE:-}" ] || [ "$STATE" != "active" ]; then clear_incident; exit 0; fi

# 3. Idle age
NOW=$(date -u +%s)
LA_EPOCH=$(date -u -d "$LAST_ACTIVE" +%s 2>/dev/null || echo "$NOW")
AGE=$(( NOW - LA_EPOCH ))

# 4. Healthy (recently active) -> clear incident, no-op
if [ "$AGE" -lt "$STALE_AFTER" ]; then clear_incident; exit 0; fi

# 5. Soft-stuck. Record incident start; nudge once.
[ -f "$STALE_FLAG" ] || printf '%s' "$NOW" > "$STALE_FLAG"
STALE_SINCE=$(cat "$STALE_FLAG" 2>/dev/null || echo "$NOW")
STALE_DUR=$(( NOW - STALE_SINCE ))

if [ ! -f "$NUDGED_FLAG" ]; then
  # --delivery immediate: a stale-but-active deacon may be busy-wedged (not idle),
  # so the default wait-idle nudge could queue forever and never reach it. last_active
  # is frozen here (no progress), so there is nothing productive to interrupt.
  "$GC_BIN" --city "$CITY" session nudge --delivery immediate "$TARGET" \
    "boot-watchdog: no activity in ${AGE}s (>${STALE_AFTER}s). Are you making progress? Run 'gc prime' if stuck." 2>/dev/null
  touch "$NUDGED_FLAG"
  echo "boot-watchdog: nudged $TARGET (idle ${AGE}s)" >&2
  exit 0
fi

# 6. Already nudged; still stale beyond ESCALATE_AFTER -> escalate to manager once (human look)
if [ "$STALE_DUR" -ge "$ESCALATE_AFTER" ] && [ ! -f "$ESC_FLAG" ]; then
  "$GC_BIN" --city "$CITY" session nudge manager \
    "boot-watchdog: gastown.deacon still idle ${AGE}s after a nudge (stale ${STALE_DUR}s) — may be stuck, needs a look (warrant/restart)." 2>/dev/null
  touch "$ESC_FLAG"
  echo "boot-watchdog: escalated $TARGET to manager (stale ${STALE_DUR}s)" >&2
fi
exit 0
