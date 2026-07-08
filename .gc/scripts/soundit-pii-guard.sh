#!/usr/bin/env bash
# soundit-pii-guard.sh v2 — fail-closed SECRETS lint for Sound IT note flows (hs-s8ny1).
#
# POSTURE (v2, re-scoped per Charlie's vault client-data policy 2026-07-02): client
# names / incident context / pricing / tenant identifiers are ALLOWED in the vault.
# This guard fences only REAL SECRETS: credentials, keys, tokens, and sensitive numeric
# identifiers (SSN, payment-card numbers). It extends the household vault-secret-guard.sh
# pattern (hs-4p89); the banned-strings list mechanism remains for any term Jeeves wants
# fenced later, and ships holding only the self-test canary.
#
# Signals:
#   BLOCK  banned-string          — case-insensitive fixed-string hit from the off-vault list
#   BLOCK  private-key-block      — BEGIN … PRIVATE KEY
#   BLOCK  live-token-prefix      — known credential prefixes with a real body
#   BLOCK  credential-assignment  — password/secret/token = <high-entropy value>
#   BLOCK  credential-literal-md  — markdown-styled short credential literal (allowlistable)
#   BLOCK  ssn                    — US SSN shape (###-##-####)
#   BLOCK  card-number            — payment-card shape (grouped 4-4-4-4 or 15-16 contiguous)
#
# The banned-strings list lives OFF-vault, off-git, 0600 — default
# ~/.config/soundit-pii-guard/banned-strings.txt. '#' lines and blanks in it are ignored.
# A missing/empty list is a guard ERROR (exit 3, fail-closed): the guard exists to
# enforce that list, so absence must not silently pass.
#
# Allowlist (optional, 0600, default ~/.config/soundit-pii-guard/allowed-literals.txt):
# exact backtick-quoted values credential-literal-md must NOT flag — known template
# placeholders that are secret-shaped but confirmed non-credentials (e.g. the
# ScreenConnect client-email template value, Charlie-cleared 2026-07-02).
#
# Modes:
#   staged            scan git-staged (ACM) content of the CURRENT repo (cd first)
#   files <paths...>  scan the named files
#   tree <dir>        scan every file under <dir> (recursive)
#   - | stdin         scan stdin
#
# Exit: 0 = clean | 2 = BLOCK signal found | 3 = guard error (fail-closed)
# Env:  PII_GUARD=0            scan + record, but always exit 0 (log-only)
#       PII_GUARD_LIST=<path>  banned-strings list (default above)
#       PII_GUARD_STATE=<dir>  state dir (default ~/.local/state/soundit-pii-guard)
#       PII_GUARD_SCOPE_RE=<re> staged mode only: scan just the staged paths matching this
#                              regex (e.g. '^wiki/(sound-it|sound-psa)/'); default = all
#
# Reporting: banned-string findings record file:line + the matched term in the 0600
# marker (the term already exists in the scanned file on this same box). Secret-shape
# findings record file + signal type ONLY — secret VALUES are never printed anywhere.
set -uo pipefail

LIST="${PII_GUARD_LIST:-$HOME/.config/soundit-pii-guard/banned-strings.txt}"
ALLOWLIST="${PII_GUARD_ALLOWLIST:-$HOME/.config/soundit-pii-guard/allowed-literals.txt}"
STATE_DIR="${PII_GUARD_STATE:-$HOME/.local/state/soundit-pii-guard}"
BLOCK_MARKER="$STATE_DIR/PII-BLOCK"
ADVISE_MARKER="$STATE_DIR/PII-ADVISE"
ARMED="${PII_GUARD:-1}"

# Per-device / cruft paths never policed (mirrors vault-secret-guard.sh).
SKIP_RE='(^|/)(\.claudian|\.obsidian|\.raw|_attachments|\.git)(/|$)'

umask 077
mkdir -p "$STATE_DIR" 2>/dev/null || true
work="$(mktemp -d)" || exit 3
trap 'rm -rf "$work"' EXIT

# --- banned-strings list (load-bearing; fail-closed when absent) -----------------
if [ ! -f "$LIST" ]; then
  echo "pii-guard: ERROR — banned-strings list missing at $LIST (fail-closed)" >&2
  [ "$ARMED" = "0" ] && exit 0
  exit 3
fi
grep -v '^[[:space:]]*#' "$LIST" | grep -v '^[[:space:]]*$' > "$work/terms" || true
if [ ! -s "$work/terms" ]; then
  echo "pii-guard: ERROR — banned-strings list at $LIST has no terms (fail-closed)" >&2
  [ "$ARMED" = "0" ] && exit 0
  exit 3
fi

# --- collect candidate files into $work/files (one "label<TAB>content-path" per line) ---
add_file() { # $1=display label  $2=readable content path
  printf '%s\t%s\n' "$1" "$2" >> "$work/files"
}
: > "$work/files"

mode="${1:-staged}"
case "$mode" in
  staged)
    SCOPE_RE="${PII_GUARD_SCOPE_RE:-}"
    i=0
    while IFS= read -r -d '' f; do
      case "$f" in *$'\n'*) continue;; esac
      printf '%s' "$f" | grep -qE "$SKIP_RE" && continue
      if [ -n "$SCOPE_RE" ]; then printf '%s' "$f" | grep -qE "$SCOPE_RE" || continue; fi
      i=$((i+1))
      git show ":$f" > "$work/c$i" 2>/dev/null || continue
      add_file "$f" "$work/c$i"
    done < <(git diff --cached --name-only -z --diff-filter=ACM 2>/dev/null)
    ;;
  files)
    shift
    for f in "$@"; do
      printf '%s' "$f" | grep -qE "$SKIP_RE" && continue
      [ -f "$f" ] && add_file "$f" "$f"
    done
    ;;
  tree)
    shift
    root="${1:-}"
    [ -d "$root" ] || { echo "pii-guard: tree root '$root' not a directory" >&2; exit 3; }
    while IFS= read -r -d '' f; do
      printf '%s' "$f" | grep -qE "$SKIP_RE" && continue
      add_file "$f" "$f"
    done < <(find "$root" -type f -print0 2>/dev/null)
    ;;
  -|stdin)
    cat > "$work/c0" || exit 3
    add_file "(stdin)" "$work/c0"
    ;;
  *) echo "pii-guard: unknown mode '$mode'" >&2; exit 3 ;;
esac

[ -s "$work/files" ] || { rm -f "$BLOCK_MARKER" "$ADVISE_MARKER" 2>/dev/null || true; exit 0; }

# --- detection -------------------------------------------------------------------
# doc/placeholder filter for the secret signals (same posture as vault-secret-guard.sh)
DOC_RE='(\.\.\.|YOUR_|EXAMPLE|REDACTED|xxxx|XXXX|placeholder|_HERE|<[A-Za-z0-9_ .-]+>|store the key in|stored in|store the .* in|Keeper)'

block=0
: > "$work/block_report"
: > "$work/advise_report"

while IFS=$'\t' read -r label cpath; do
  [ -r "$cpath" ] || continue

  # 1) banned strings — case-insensitive fixed-string, line-anchored report
  while IFS= read -r term; do
    hits="$(grep -inF -- "$term" "$cpath" 2>/dev/null | cut -d: -f1 | paste -sd',' -)"
    if [ -n "$hits" ]; then
      block=1
      printf 'BLOCK banned-string   %s:%s  term=%s\n' "$label" "$hits" "$term" >> "$work/block_report"
    fi
  done < "$work/terms"

  # 2) private key blocks
  if grep -qIE 'BEGIN (OPENSSH|RSA|EC|DSA|PGP) PRIVATE KEY' "$cpath" 2>/dev/null; then
    block=1
    printf 'BLOCK private-key-block   %s\n' "$label" >> "$work/block_report"
  fi

  # 3) live-token prefixes with a real body (values never echoed)
  if grep -hoIE '(sk_live_|rk_live_|pk_live_|sk_test_|whsec_|xoxb-|xoxp-|ghp_|github_pat_|glpat-|AKIA[0-9A-Z]{16}|ya29\.|eyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,})[A-Za-z0-9_+/=.-]{12,}' "$cpath" 2>/dev/null \
     | grep -vE "$DOC_RE" | grep -q .; then
    block=1
    printf 'BLOCK live-token-prefix   %s\n' "$label" >> "$work/block_report"
  fi

  # 4) credential-field assignment with a high-entropy value (values never echoed).
  #    RHS that is a function CALL (name() / name(args)) is code, not a literal — skip it.
  if grep -hiIE '(password|passwd|secret|api[_-]?key|api[_-]?token|access[_-]?token|client[_-]?secret|auth[_-]?token|bearer)["'"'"' ]*[:=][ "'"'"']*[A-Za-z0-9_.+/-]{16,}' "$cpath" 2>/dev/null \
     | grep -vE "$DOC_RE" | grep -vF '…' \
     | grep -viE '[:=][ "'"'"']*[A-Za-z0-9_.]+\(' | grep -q .; then
    block=1
    printf 'BLOCK credential-assignment   %s\n' "$label" >> "$work/block_report"
  fi

  # 4b) markdown-styled credential literal — a cred word (possibly **bold**) with a short
  #     backtick-quoted literal value, the shape SOPs use ("- **Password:** `Hunter2!`").
  #     Catches what (4) can't: short/complex literals. Placeholders filtered by DOC_RE;
  #     Charlie-cleared template values filtered by the allowlist (exact value match).
  cred_md="$(grep -hiIE '(password|passwd|passphrase|secret|api[ _-]?key|auth code|token)[*_ ]*[:=][*_ ]* *`[^`<]{6,64}`' "$cpath" 2>/dev/null \
     | grep -vE "$DOC_RE" | grep -viE '`(yes|no|none|n/a|tbd|see .*|same as .*|your .*|in keeper.*)`' \
     | grep -vE '`[^`]*\([^`]*`|` *\+ *`| \+ `' || true)"
  if [ -n "$cred_md" ] && [ -f "$ALLOWLIST" ]; then
    while IFS= read -r allowed; do
      [ -n "$allowed" ] || continue
      case "$allowed" in \#*) continue;; esac
      cred_md="$(printf '%s\n' "$cred_md" | grep -vF -- "\`$allowed\`" || true)"
    done < "$ALLOWLIST"
  fi
  if [ -n "$cred_md" ]; then
    block=1
    printf 'BLOCK credential-literal-md   %s\n' "$label" >> "$work/block_report"
  fi

  # 5) US SSN shape (###-##-####) — sensitive numeric identifier, never belongs in notes
  if grep -hoIE '(^|[^0-9-])[0-9]{3}-[0-9]{2}-[0-9]{4}([^0-9-]|$)' "$cpath" 2>/dev/null \
     | grep -vE "$DOC_RE" | grep -q .; then
    block=1
    printf 'BLOCK ssn   %s\n' "$label" >> "$work/block_report"
  fi

  # 6) payment-card shape: grouped 4-4-4-4 or 15-16 contiguous digits (last-4 mentions
  #    like *1234 are fine; 13/17-19-digit ids — epochs, Discord snowflakes — don't match)
  if grep -hoIE '(^|[^0-9])([0-9]{4}[- ]){3}[0-9]{4}([^0-9]|$)|(^|[^0-9])[0-9]{15,16}([^0-9]|$)' "$cpath" 2>/dev/null \
     | grep -vE "$DOC_RE" | grep -q .; then
    block=1
    printf 'BLOCK card-number   %s\n' "$label" >> "$work/block_report"
  fi
done < "$work/files"

# --- verdict ---------------------------------------------------------------------
# (v2: the ticket-id advisory is retired — client/ticket references are allowed by
# policy. Clear any stale advisory marker from v1.)
rm -f "$ADVISE_MARKER" 2>/dev/null || true

if [ "$block" -eq 0 ]; then
  rm -f "$BLOCK_MARKER" 2>/dev/null || true
  exit 0
fi

{ echo "pii-guard BLOCK ($(date '+%Y-%m-%d %H:%M:%S %z'))"
  cat "$work/block_report"
  echo "resolve: move the secret off-vault (Keeper + a pointer) or drop the identifier,"
  echo "         then the next tick proceeds. Banned list: $LIST; allowlist for cleared"
  echo "         template placeholders: $ALLOWLIST (both 0600, off-vault)."
} > "$BLOCK_MARKER" 2>/dev/null || true

echo "pii-guard: BLOCK — $(grep -c '^BLOCK' "$work/block_report") signal(s); see $BLOCK_MARKER" >&2

if [ "$ARMED" = "0" ]; then
  echo "pii-guard: DISARMED (PII_GUARD=0) — allowing despite signals" >&2
  exit 0
fi
exit 2
