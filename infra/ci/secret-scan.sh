#!/usr/bin/env bash
# Fails when tracked files look like they contain secrets. Run from the repository root (CI and locally).
# Only reports file:line and the rule — never the matched value.
set -euo pipefail

status=0
report() { echo "secret-scan: $1: $2"; status=1; }

# 1. Environment / key files must never be tracked (examples are fine).
while IFS= read -r f; do
  case "$f" in
    *.example|*.example.*|*/.env.testing.example) ;;
    *) report "$f" "environment or key file is tracked" ;;
  esac
done < <(git ls-files | grep -E '(^|/)(\.env(\..+)?|.*\.pem|.*\.key|id_rsa|id_ed25519|.*\.p12|.*\.pfx|ricemill\.env|demo-credentials\.txt)$' || true)

# 2. Secret-looking content in any tracked text file.
patterns=(
  'private key block|-----BEGIN ([A-Z]+ )?PRIVATE KEY-----'
  'AWS access key id|AKIA[0-9A-Z]{16}'
  'GitHub token|gh[pousr]_[A-Za-z0-9]{36,}'
  'Slack token|xox[baprs]-[A-Za-z0-9-]{10,}'
  'Laravel APP_KEY value|APP_KEY=base64:[A-Za-z0-9+/]{40,}'
  'backup key value|RICEMILL_BACKUP_KEY=[A-Za-z0-9+/]{40,}'
  'password assignment|(DB|MAIL|REDIS)_PASSWORD=[A-Za-z0-9!@#%^&+/_-]{6,}'
)
for entry in "${patterns[@]}"; do
  name="${entry%%|*}"
  regex="${entry#*|}"
  # git grep: 0 = matches, 1 = none, anything else = the scan itself failed (must not pass silently).
  set +e
  hits="$(git grep -nIE -e "$regex" -- . ':!infra/ci/secret-scan.sh' ':!*.lock' ':!**/tests/**' ':!**/fixtures/**')"
  rc=$?
  set -e
  if [[ $rc -gt 1 ]]; then
    echo "secret-scan: git grep failed for rule '$name'" >&2
    exit 2
  fi
  while IFS=: read -r file line _; do
    if [[ -n "$file" ]]; then report "$file:$line" "$name"; fi
  done <<<"$hits"
done

if [[ $status -eq 0 ]]; then echo "secret-scan: no secrets found in tracked files."; fi
exit $status
