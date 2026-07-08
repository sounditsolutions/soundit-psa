#!/usr/bin/env bash
# Office DoltLite maintenance — flatten + gc, PLUS (when ARMED) the mandatory REINDEX guard.
#
# TWO-KEY: the guarded chain (REINDEX + integrity_check gate) runs ONLY when BOTH
#   .gc/doltlite-cadence.enabled  AND  .gc/doltlite-reindex-guard.enabled  exist.
# UNARMED (either flag missing) = bare flatten+gc = IDENTICAL to the current pack floor (zero change).
# So installing this as the floor override changes NOTHING until deliberately armed; revert = rm a flag
# (-> bare) or rm the local order (-> pack floor resumes).
#
# This is the ONLY maintenance path (the hourly floor AND, post-flip, the burst-flush order both call
# it) -> when armed, flatten NEVER runs without the REINDEX that follows it.
#
# gascity#3930: flatten (VACUUM + checkpoint) DETERMINISTICALLY leaves SQLite secondary indexes STALE
# -> silent wrong reads. REINDEX-after-flatten repairs it; integrity_check is the ONLY trustworthy proof
# (quick_check LIES); on gate fail -> exit 1 (order.failed, LOUD) rather than serve wrong data.
# Authorized pre-flip as data-correctness hygiene (so-ppqk, manager 2026-07-08 00:11Z); dev ga-jb9l proven.
set -uo pipefail
CITY=/home/charlie/soundit-office
DB="$CITY/.beads/doltlite/so.db"
LOG="$CITY/.gc/doltlite-cadence.log"
REASON="${1:-floor}"
export PATH="/home/charlie/.local/bin:/home/charlie/go/bin:/usr/bin:/bin:/usr/local/bin:$PATH"
cd "$CITY" 2>/dev/null || { echo "cannot cd $CITY"; exit 3; }
ts(){ date -u +%Y-%m-%dT%H:%M:%SZ; }
log(){ echo "[$(ts)] [$REASON] $*" >> "$LOG"; }

GUARDED=0
[ -e "$CITY/.gc/doltlite-cadence.enabled" ] && [ -e "$CITY/.gc/doltlite-reindex-guard.enabled" ] && GUARDED=1

SZ0=$(stat -c%s "$DB" 2>/dev/null || echo 0)
log "start; so.db=$SZ0 B; guarded=$GUARDED"

# flatten (VACUUM + WAL checkpoint) + gc (compact deleted rows) — the existing floor behavior.
FLAT=$(timeout 300 gc beads-doltlite flatten --json 2>&1); log "flatten: $(echo "$FLAT" | tr '\n' ' ' | cut -c1-140)"
GC=$(timeout 300 gc beads-doltlite gc --json 2>&1);        log "gc: $(echo "$GC" | tr '\n' ' ' | cut -c1-140)"
if echo "$GC" | grep -qiE "mark.?fail|mark phase failed"; then
  log "ALERT: dolt_gc MARK-FAIL ($(echo "$GC" | grep -oiE 'source=[a-z]+' | head -1)) — #1565 residual."
fi

if [ "$GUARDED" = 1 ]; then
  # MANDATORY REINDEX after flatten (gascity#3930) + integrity_check gate. Fail LOUD if not ok.
  RIDX=$(timeout 300 bd sql 'REINDEX;' 2>&1); log "REINDEX: $(echo "$RIDX" | tr '\n' ' ' | cut -c1-120)"
  INTEG=$(timeout 120 bd sql 'PRAGMA integrity_check;' 2>&1)
  if echo "$INTEG" | grep -qw ok; then
    log "integrity_check=ok (post-REINDEX)"
  else
    log "FAIL: integrity_check NOT ok post-REINDEX -> exit 1 (order.failed; refusing stale reads). out: $(echo "$INTEG" | tr '\n' ' ' | cut -c1-140)"
    exit 1
  fi
else
  log "UNARMED (bare flatten+gc; no REINDEX) = current pack floor. Arm: touch .gc/doltlite-cadence.enabled + .gc/doltlite-reindex-guard.enabled"
fi

SZ1=$(stat -c%s "$DB" 2>/dev/null || echo 0); D=$((SZ0 - SZ1))
[ "$D" -gt 0 ] && log "RECLAIMED $SZ0 -> $SZ1 (freed $D)" || log "no reclaim $SZ0 -> $SZ1 (delta $D)"
log "done ok"
