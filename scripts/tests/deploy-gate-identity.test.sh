#!/usr/bin/env bash
#
# Tests for the GATE IDENTITY RECORD in scripts/deploy.sh (card 6a9a2420).
#
# What is under test: deploy.sh resolves PSA_GATE from the gitignored
# scripts/deploy.env and, until this block existed, never printed the resolved
# path on the success path — the operator learned which gate ran only when it
# did NOT run. These tests pin the record's content, its destinations, and the
# property that matters most: NOTHING in the recording step may refuse a
# deploy. It runs under `set -e`, and the gate-missing refusal further down is
# the intended refusal point, not this block.
#
# Hermetic: every case runs a COPY of deploy.sh in a throwaway directory whose
# REPO_DIR is a scratch git repo with a local-path origin. A stub `ssh` and a
# stub `git` are NOT used — the real ones are, against scratch paths — but a
# stub `ssh` that always fails IS placed first on PATH so no case can reach a
# real host even if the script's control flow changes underneath these tests.
#
# Run from anywhere:
#   scripts/tests/deploy-gate-identity.test.sh
#
# Exits non-zero if any assertion fails.
set -u

HERE="$(cd "$(dirname "$0")" && pwd)"
DEPLOY_SH="$HERE/../deploy.sh"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

PASS=0
FAIL=0

if [ ! -f "$DEPLOY_SH" ]; then
    echo "FATAL: $DEPLOY_SH not found"
    exit 1
fi

# --- stub PATH -------------------------------------------------------------
# `ssh` fails instantly: a belt-and-braces stop so no case can touch a host.
STUB="$TMP/stub"
mkdir -p "$STUB"
printf '#!/bin/sh\necho "STUB SSH REFUSED" >&2\nexit 1\n' >"$STUB/ssh"
chmod +x "$STUB/ssh"
export PATH="$STUB:$PATH"

# --- sandbox builder -------------------------------------------------------
# new_box <name> — a scratch repo at $TMP/<name> with scripts/deploy.sh copied
# in and a real (empty, bare) local origin, so deploy.sh's `git fetch origin`
# succeeds without a network. Echoes the box path.
new_box() {
    # Separate statements deliberately: under `set -u`, a second assignment in
    # the same `local` cannot rely on the first having landed.
    local name="$1"
    local box="$TMP/$name"
    mkdir -p "$box/scripts"
    cp "$DEPLOY_SH" "$box/scripts/deploy.sh"
    git init -q "$box"
    git init -q --bare "$TMP/$name-origin.git"
    git -C "$box" remote add origin "$TMP/$name-origin.git"
    printf '%s\n' "$box"
}

# box_commit <box> — give the box one real commit on origin so a deploy target
# RESOLVES, which is how a case reaches the gate-missing refusal further down.
# Echoes the sha.
box_commit() {
    local box="$1"
    git -C "$box" -c user.email=t@t -c user.name=t commit -q --allow-empty -m fixture
    git -C "$box" push -q origin HEAD:refs/heads/main
    git -C "$box" rev-parse HEAD
}

# write_env <box> [extra lines...] — the deploy.env deploy.sh sources.
write_env() {
    local box="$1"; shift
    {
        echo 'DEPLOY_HOST=stub-host'
        echo 'DEPLOY_PATH=/nonexistent/deploy/path'
        echo 'DEPLOY_DOMAIN=stub.invalid'
        local line
        for line in "$@"; do echo "$line"; done
    } >"$box/scripts/deploy.env"
}

# run_deploy <name> <box> [args...] — capture stdout/stderr/exit without a pipe
# so the real exit code is observed. Never fails the test run itself.
run_deploy() {
    local name="$1" box="$2"
    shift 2
    local rc=0
    ( cd "$box" && timeout 60 bash scripts/deploy.sh "$@" ) \
        >"$TMP/$name.out" 2>"$TMP/$name.err" || rc=$?
    printf '%s\n' "$rc" >"$TMP/$name.rc"
}

ok()   { PASS=$((PASS + 1)); }
bad()  { FAIL=$((FAIL + 1)); echo "FAIL [$1] $2"; }

# assert_out <name> <label> <fixed-string>
assert_out() {
    if grep -qF -- "$3" "$TMP/$1.out"; then ok; else
        bad "$1" "$2 — missing from stdout: $3"
        sed 's/^/    out: /' "$TMP/$1.out"
    fi
}
# assert_not_out <name> <label> <fixed-string>
assert_not_out() {
    if grep -qF -- "$3" "$TMP/$1.out"; then
        bad "$1" "$2 — unexpectedly present on stdout: $3"
    else ok; fi
}
# assert_err <name> <label> <fixed-string>
assert_err() {
    if grep -qF -- "$3" "$TMP/$1.err"; then ok; else
        bad "$1" "$2 — missing from stderr: $3"
        sed 's/^/    err: /' "$TMP/$1.err"
    fi
}
# assert_file <name> <label> <file> <fixed-string>
assert_file() {
    if [ -f "$3" ] && grep -qF -- "$4" "$3"; then ok; else
        bad "$1" "$2 — missing from $3: $4"
    fi
}
# assert_rc <name> <label> <expected>
assert_rc() {
    local got; got="$(cat "$TMP/$1.rc")"
    if [ "$got" = "$3" ]; then ok; else bad "$1" "$2 — exit expected $3, got $got"; fi
}

echo "== deploy.sh gate identity record =="

# ---------------------------------------------------------------------------
# t1 — the happy shape. A readable gate is named on stdout with its real
# sha256 and BOTH resolved audit destinations, and the same line is appended
# to the audit log. The deploy still refuses at its own refusal point (an
# unresolvable ref, exit 2) — the record changed no control flow.
# ---------------------------------------------------------------------------
BOX="$(new_box t1)"
GATE="$TMP/t1-gate.sh"
printf '#!/bin/sh\nexit 0\n' >"$GATE"; chmod +x "$GATE"
GATE_SHA="$(sha256sum -- "$GATE" | cut -d' ' -f1)"
AUDIT="$TMP/t1-audit.log"; FB="$TMP/t1-fallback.log"
write_env "$BOX" "PSA_DEPLOY_GATE=$GATE" "PSA_DEPLOY_GATE_AUDIT=$AUDIT" \
    "PSA_DEPLOY_GATE_AUDIT_FALLBACK=$FB"
run_deploy t1 "$BOX" no-such-ref
assert_out t1 "record emitted"        "GATE-RESOLVED"
assert_out t1 "names the gate"        "gate=$GATE"
assert_out t1 "carries the real hash" "sha256=$GATE_SHA"
assert_out t1 "names the audit path"  "audit=$AUDIT"
assert_out t1 "names the fallback"    "audit_fallback=$FB"
assert_out t1 "correlates the ref"    "ref=no-such-ref"
assert_file t1 "line reached the audit log" "$AUDIT" "sha256=$GATE_SHA"
assert_rc  t1 "unresolvable ref still refuses at its own point" 2
# The record must precede the pin: it has to survive a ref that never resolves.
assert_not_out t1 "did not get as far as pinning" "Pinned target:"

# ---------------------------------------------------------------------------
# t2 — a gate that is not there hashes as ABSENT, is still recorded, and the
# HARD REFUSAL at the gate-missing check is still what stops the deploy. This
# is the case the record exists for: a deploy log that can say which file was
# missing. The ref resolves here, so the run reaches that refusal.
# ---------------------------------------------------------------------------
BOX="$(new_box t2)"
SHA="$(box_commit "$BOX")"
AUDIT="$TMP/t2-audit.log"
write_env "$BOX" "PSA_DEPLOY_GATE=$TMP/t2-no-such-gate.sh" "PSA_DEPLOY_GATE_AUDIT=$AUDIT"
run_deploy t2 "$BOX" "$SHA"
assert_out t2 "record emitted for a missing gate" "GATE-RESOLVED"
assert_out t2 "hash degrades to ABSENT"           "sha256=ABSENT"
assert_out t2 "names the missing gate"            "gate=$TMP/t2-no-such-gate.sh"
assert_err t2 "gate-missing refusal still fires"  "DEPLOY REFUSED: review gate not found"
assert_rc  t2 "refusal is the refusal, not the record" 2
assert_file t2 "ABSENT reached the audit log" "$AUDIT" "sha256=ABSENT"

# ---------------------------------------------------------------------------
# t3 — a broken hasher degrades to UNHASHED and does NOT abort. sha256sum is
# resolved through PATH, and PATH is assignable from deploy.env under `set -a`;
# the code comment says plainly that this makes the line detection and not a
# boundary. What it must never do is turn a shadowed hasher into a failed
# deploy, so both a non-zero hasher and a silent zero-exit one are pinned.
# ---------------------------------------------------------------------------
BOX="$(new_box t3)"
GATE="$TMP/t3-gate.sh"; printf '#!/bin/sh\nexit 0\n' >"$GATE"; chmod +x "$GATE"
BROKEN="$TMP/broken-bin"; mkdir -p "$BROKEN"
printf '#!/bin/sh\nexit 1\n' >"$BROKEN/sha256sum"; chmod +x "$BROKEN/sha256sum"
write_env "$BOX" "PSA_DEPLOY_GATE=$GATE" "PSA_DEPLOY_GATE_AUDIT=$TMP/t3-audit.log" \
    "PATH=$BROKEN:$PATH"
run_deploy t3 "$BOX" no-such-ref
assert_out t3 "record survives a failing hasher" "GATE-RESOLVED"
assert_out t3 "hash degrades to UNHASHED"        "sha256=UNHASHED"
assert_rc  t3 "a broken hasher does not refuse the deploy" 2

BOX="$(new_box t3b)"
QUIET="$TMP/quiet-bin"; mkdir -p "$QUIET"
printf '#!/bin/sh\nexit 0\n' >"$QUIET/sha256sum"; chmod +x "$QUIET/sha256sum"
write_env "$BOX" "PSA_DEPLOY_GATE=$GATE" "PSA_DEPLOY_GATE_AUDIT=$TMP/t3b-audit.log" \
    "PATH=$QUIET:$PATH"
run_deploy t3b "$BOX" no-such-ref
assert_out t3b "a zero-exit silent hasher is not a blank hash" "sha256=UNHASHED"
assert_rc  t3b "still no refusal from the record" 2

# ---------------------------------------------------------------------------
# t4 — an unwritable primary audit log falls back and warns, and the deploy is
# not refused by it. The unwritable path is a child of a REGULAR FILE, which
# fails the append for root as well as for an unprivileged user; a mode-based
# fixture would silently pass under the root the pipeline actually runs as.
# ---------------------------------------------------------------------------
BOX="$(new_box t4)"
GATE="$TMP/t4-gate.sh"; printf '#!/bin/sh\nexit 0\n' >"$GATE"; chmod +x "$GATE"
: >"$TMP/t4-not-a-dir"
FB="$TMP/t4-fallback.log"
write_env "$BOX" "PSA_DEPLOY_GATE=$GATE" \
    "PSA_DEPLOY_GATE_AUDIT=$TMP/t4-not-a-dir/audit.log" \
    "PSA_DEPLOY_GATE_AUDIT_FALLBACK=$FB"
run_deploy t4 "$BOX" no-such-ref
assert_out  t4 "record still on stdout" "GATE-RESOLVED"
assert_err  t4 "fallback is announced"  "gate identity recorded to fallback"
assert_file t4 "line reached the fallback" "$FB" "GATE-RESOLVED"
assert_rc   t4 "an unwritable audit log does not refuse the deploy" 2

# ---------------------------------------------------------------------------
# t5 — both destinations unwritable: the deploy STILL runs to its own refusal,
# and the failure to record is loud. Stdout is the destination deploy.env
# cannot reach, so it is the one that must survive.
# ---------------------------------------------------------------------------
BOX="$(new_box t5)"
GATE="$TMP/t5-gate.sh"; printf '#!/bin/sh\nexit 0\n' >"$GATE"; chmod +x "$GATE"
: >"$TMP/t5-not-a-dir"
write_env "$BOX" "PSA_DEPLOY_GATE=$GATE" \
    "PSA_DEPLOY_GATE_AUDIT=$TMP/t5-not-a-dir/audit.log" \
    "PSA_DEPLOY_GATE_AUDIT_FALLBACK=$TMP/t5-not-a-dir/fallback.log"
run_deploy t5 "$BOX" no-such-ref
assert_out t5 "stdout keeps the record when disk cannot" "GATE-RESOLVED"
assert_err t5 "total recording failure is loud"          "GATE IDENTITY NOT RECORDED ON DISK"
assert_rc  t5 "and it still does not refuse the deploy"  2

# ---------------------------------------------------------------------------
# t6 — the gate-missing OVERRIDE path still records its own line. The audit
# destinations moved above that branch when the identity record was added, so
# this pins that the override recorder was not broken by the hoist: both lines
# land in the same log, for the same run.
# ---------------------------------------------------------------------------
BOX="$(new_box t6)"
SHA="$(box_commit "$BOX")"
AUDIT="$TMP/t6-audit.log"
write_env "$BOX" "PSA_DEPLOY_GATE=$TMP/t6-no-such-gate.sh" "PSA_DEPLOY_GATE_AUDIT=$AUDIT"
( cd "$BOX" && PSA_DEPLOY_GATE_OVERRIDE="fixture: t6" timeout 60 bash scripts/deploy.sh "$SHA" ) \
    >"$TMP/t6.out" 2>"$TMP/t6.err" || true
printf '0\n' >"$TMP/t6.rc"
assert_file t6 "identity line recorded" "$AUDIT" "GATE-RESOLVED"
assert_file t6 "override line recorded" "$AUDIT" "OVERRIDE-GATE-MISSING target=$SHA"
assert_file t6 "override names its reason" "$AUDIT" "reason=fixture: t6"
# The override is accepted, so the run proceeds — and the stub ssh stops it
# there. That the stub was reached is itself the proof the override path ran.
assert_err  t6 "run proceeded past the gate to the deploy step" "STUB SSH REFUSED"

# ---------------------------------------------------------------------------
# t7 — relocating the audit log is visible on stdout. This is the property the
# card turned on: PSA_DEPLOY_GATE_AUDIT is set by the same deploy.env the
# record is a control on, so a record only that file can route is not a control
# on it. Two runs, two different audit paths, each named on stdout.
# ---------------------------------------------------------------------------
BOX="$(new_box t7)"
GATE="$TMP/t7-gate.sh"; printf '#!/bin/sh\nexit 0\n' >"$GATE"; chmod +x "$GATE"
write_env "$BOX" "PSA_DEPLOY_GATE=$GATE" "PSA_DEPLOY_GATE_AUDIT=$TMP/t7-first.log"
run_deploy t7a "$BOX" no-such-ref
assert_out t7a "first destination is named" "audit=$TMP/t7-first.log"
write_env "$BOX" "PSA_DEPLOY_GATE=$GATE" "PSA_DEPLOY_GATE_AUDIT=$TMP/t7-moved.log"
run_deploy t7b "$BOX" no-such-ref
assert_out     t7b "the move is visible on stdout" "audit=$TMP/t7-moved.log"
assert_not_out t7b "and the old destination is gone" "audit=$TMP/t7-first.log"

echo
echo "deploy gate identity tests: $PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
