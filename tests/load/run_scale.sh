#!/bin/bash
# Scale benchmark of tool_ltigroupautoenrol (GA-02) on a local development site. Never run it on a production site.
#
# Usage: tests/load/run_scale.sh <moodle-root> <pgsql|mariadb> [users] [groups]
#   <moodle-root>  directory with config.php (Moodle 5.1+: the directory above public/)
#   Environment: LTI_DB is exported for config.php files that read it; DBNAME/DBPORT/DBUSER/DBPASS select the
#   database the lock sampler connects to (defaults: moodle, 5432 or 3306, moodle, moodle).
#
# Steps: setup (existing participants), backfill task run while 200 new LTI enrolments arrive through the event
# path in parallel, lock sampling every 0.5 s during the run, a repeated (idempotent) run, query plans, cleanup.
# Results: one JSON/text file per step in $OUT (default ./scale-results).
set -uo pipefail
ROOT=$1; DBTYPE=$2; USERS=${3:-10000}; NGROUPS=${4:-3}  # not GROUPS: a bash builtin array
export LTI_DB=$DBTYPE
WEB=$ROOT; [ -d "$ROOT/public" ] && WEB=$ROOT/public
SCRIPT=$WEB/admin/tool/ltigroupautoenrol/tests/load/backfill_scale.php
OUT=${OUT:-./scale-results}; mkdir -p "$OUT"
PHPBIN=${PHPBIN:-php}
DBNAME=${DBNAME:-moodle}; DBUSER=${DBUSER:-moodle}; DBPASS=${DBPASS:-moodle}
if [ "$DBTYPE" = pgsql ]; then DBPORT=${DBPORT:-5432}; else DBPORT=${DBPORT:-3306}; fi

sample_locks() { # prints: waiting lock requests, granted locks (PostgreSQL) / lock waits, open transactions (MariaDB)
  if [ "$DBTYPE" = pgsql ]; then
    PGPASSWORD=$DBPASS psql -h 127.0.0.1 -p "$DBPORT" -U "$DBUSER" "$DBNAME" -Atc \
      "SELECT count(*) FILTER (WHERE NOT l.granted), count(*) FILTER (WHERE l.granted AND l.mode LIKE '%Exclusive%')
         FROM pg_locks l JOIN pg_database d ON d.oid = l.database WHERE d.datname = current_database()" | tr '|' ' '
  else
    mysql -h 127.0.0.1 -P "$DBPORT" -u"$DBUSER" -p"$DBPASS" "$DBNAME" -Nse \
      "SELECT (SELECT COUNT(*) FROM information_schema.INNODB_LOCK_WAITS), (SELECT COUNT(*) FROM information_schema.INNODB_TRX)"
  fi
}

cd "$ROOT" || exit 2
$PHPBIN "$SCRIPT" --setup --users="$USERS" --groups="$NGROUPS" | tee "$OUT/setup.json"
COURSE=$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["course"])' "$OUT/setup.json") || exit 1

$PHPBIN "$SCRIPT" --run --course="$COURSE" > "$OUT/run.json" 2> "$OUT/run.err" &
RUNPID=$!
sleep 2
$PHPBIN "$SCRIPT" --enrol --course="$COURSE" --count=200 > "$OUT/enrol.json" 2> "$OUT/enrol.err" &
ENROLPID=$!
sleep 1
# A second backfill of the same course while the first runs: the course lock must be busy (the task API retries).
$PHPBIN "$SCRIPT" --lockprobe --course="$COURSE" > "$OUT/lockprobe.json" 2>&1
: > "$OUT/locks.txt"
while kill -0 $RUNPID 2>/dev/null; do
  echo "$(date +%s.%N | cut -c1-14) $(sample_locks)" >> "$OUT/locks.txt"
  sleep 0.5
done
wait $RUNPID; RUNRC=$?
wait $ENROLPID; ENROLRC=$?
cat "$OUT/run.json" "$OUT/enrol.json" "$OUT/lockprobe.json"
python3 - "$OUT/locks.txt" <<'PY' | tee "$OUT/locks-summary.json"
import json, sys
rows = [l.split() for l in open(sys.argv[1]) if len(l.split()) == 3]
print(json.dumps({'samples': len(rows), 'max_waiting': max((int(r[1]) for r in rows), default=0),
                  'max_second_column': max((int(r[2]) for r in rows), default=0)}))
PY
$PHPBIN "$SCRIPT" --explain --course="$COURSE" > "$OUT/explain.txt" 2>&1
$PHPBIN "$SCRIPT" --cleanup --course="$COURSE" | tee "$OUT/cleanup.json"
echo "run rc=$RUNRC enrol rc=$ENROLRC"
[ $RUNRC -eq 0 ] && [ $ENROLRC -eq 0 ]
