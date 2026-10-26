#!/bin/bash
# Release gate: judges job results given as name=result pairs.
# Passes (exit 0) only if at least one result is given and every result is "success"; a failed, cancelled,
# skipped, empty or malformed result fails the gate (fail closed). Used by the jobs maturity-gate and
# ci-complete, and checked by gate-selftest.
set -u
if [ $# -eq 0 ]; then
  echo "Keine Gate-Ergebnisse übergeben - das Gate kann nicht bewertet werden."
  exit 1
fi
rc=0
for entry in "$@"; do
  name=${entry%%=*}
  if [ "$name" = "$entry" ]; then
    echo "  $entry: <kein Ergebnis>"
    rc=1
    continue
  fi
  result=${entry#*=}
  echo "  $name: ${result:-<leer>}"
  if [ "$result" != "success" ]; then
    rc=1
  fi
done
if [ $rc -ne 0 ]; then
  echo "Mindestens ein Release-Gate ist nicht grün (siehe Liste oben)."
  exit 1
fi
echo "Alle Release-Gates sind grün."
