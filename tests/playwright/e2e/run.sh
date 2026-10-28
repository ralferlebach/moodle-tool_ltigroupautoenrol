#!/bin/bash
# Runs the two-site LTI E2E acceptance test of tool_ltigroupautoenrol against the shared stack (/home/claude/e2e).
# The whole run (fixture changes on both sites, Playwright, one temporary config change that is restored) holds
# /tmp/e2e_stack.lock, as the stack contract requires. Results: /tmp/ltigae-e2e (report.json, test-results, state.json).
#   E2E_HOME (default /home/claude/e2e), LTIGAE_E2E_STATE, LTIGAE_E2E_REPORT, LTIGAE_E2E_OUTPUT, extra args go to Playwright.
set -uo pipefail
HERE=$(cd "$(dirname "$0")" && pwd)
E2E_HOME=${E2E_HOME:-/home/claude/e2e}
mkdir -p /tmp/ltigae-e2e
exec flock /tmp/e2e_stack.lock bash -c '
  . "$0/bin/e2e-env.sh"
  "$0/bin/e2e-status" >/dev/null || { echo "E2E stack is not up (e2e-up)"; exit 1; }
  cd "$1" && NODE_PATH="$0/node_modules" E2E_LIB="$0/lib/e2e.js" "$0/node_modules/.bin/playwright" test -c "$1/playwright.config.js" "${@:2}"
' "$E2E_HOME" "$HERE" "$@"
