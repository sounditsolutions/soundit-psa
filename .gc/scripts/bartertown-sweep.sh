#!/bin/sh
# bartertown-sweep.sh — heartbeat pull + detect-only digest (staged by the
# house-staff artificer, bead hs-sd3qf, 2026-07-02; from the pack's
# examples/heartbeat-sweep.example.sh reference).
# TWO-KEY DEFAULT-DENY — no-ops unless BOTH markers exist:
#   .gc/bartertown.enabled         pack master switch (reviewed enable)
#   .gc/bartertown-sweep.enabled   THIS wiring — the city's manager (GM) arms it:
#                                  touch .gc/bartertown-sweep.enabled
# Detect-only: pulls from the hub (the reliable sync carrier) and prints the
# digest to the order log; advances no cursors, posts nothing. Reversible:
# rm .gc/bartertown-sweep.enabled (or delete orders/bartertown-sweep.toml).
set -u
CITY="${BARTERTOWN_SWEEP_CITY:-/home/charlie/soundit-office}"
GC_BIN="${BARTERTOWN_SWEEP_GC:-/home/charlie/go/bin/gc}"
cd "$CITY" || exit 0
[ -f "$CITY/.gc/bartertown.enabled" ] || { echo 'bartertown-sweep: pack not enabled — no-op'; exit 0; }
[ -f "$CITY/.gc/bartertown-sweep.enabled" ] || { echo 'bartertown-sweep: wiring not armed (manager: touch .gc/bartertown-sweep.enabled) — no-op'; exit 0; }
BARTERTOWN_CITY_ROOT="$CITY" timeout 90 "$GC_BIN" bartertown sweep || true
