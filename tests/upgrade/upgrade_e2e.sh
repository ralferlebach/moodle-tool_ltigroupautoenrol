#!/bin/bash
# Real site upgrade from the published 1.0.1, following docs/CHANGES.md ("Upgrading"):
#   1. install Moodle with tool_ltigroupautoenrol 1.0.1 (git fd456f3, 2024100600),
#   2. store configurations in the 1.0.1 format: a valid mapping, a newer duplicate of the same course
#      and the configuration of a deleted course,
#   3. put the new code in place and run the read-only check (it must not change anything),
#   4. upgrade and require: schema identical to a fresh install (check_database_schema), one row per
#      course with the oldest row's mapping in canonical form, unique key present, every removed row backed up.
#
# Usage: upgrade_e2e.sh <moodle dir> <plugin git checkout with full history>
# config.php of <moodle dir> must point to an empty database and an empty dataroot.
set -euo pipefail
M=$(cd "$1" && pwd)
SRC=$(cd "$2" && pwd)
BASELINE=fd456f3
if [ -d "$M/public" ]; then P="$M/public/admin/tool/ltigroupautoenrol"; else P="$M/admin/tool/ltigroupautoenrol"; fi
cd "$M"
tmp=$(mktemp -d)

echo "== Install with the published 1.0.1"
rm -rf "$P"; mkdir -p "$P"
git -C "$SRC" archive "$BASELINE" | tar -x -C "$P"
php admin/cli/install_database.php --agree-license --adminpass='Admin!23' --adminemail=admin@example.invalid \
  --fullname=E2E --shortname=E2E > "$tmp/install.log" 2>&1 || { tail -n 30 "$tmp/install.log"; exit 1; }
installed=$(php admin/cli/cfg.php --component=tool_ltigroupautoenrol --name=version)
echo "installed version: $installed"
[ "$installed" = 2024100600 ]

echo "== Data in the 1.0.1 format"
php <<PHP
<?php
define('CLI_SCRIPT', 1);
require('config.php');
require_once(\$CFG->libdir . '/testing/generator/lib.php');
\$gen = new testing_data_generator();
\$course = \$gen->create_course(['shortname' => 'E2E1']);
\$g1 = \$gen->create_group(['courseid' => \$course->id]);
\$g2 = \$gen->create_group(['courseid' => \$course->id]);
\$DB->insert_record('tool_ltigroupautoenrol', (object) ['courseid' => \$course->id, 'enable_enrol' => 1,
    'settings' => json_encode(['7' => [(string) \$g1->id, (string) \$g2->id]])]);
\$DB->insert_record('tool_ltigroupautoenrol', (object) ['courseid' => \$course->id, 'enable_enrol' => 0,
    'settings' => json_encode(['7' => [(string) \$g2->id]])]);
\$DB->insert_record('tool_ltigroupautoenrol', (object) ['courseid' => 987654, 'enable_enrol' => 1, 'settings' => '{}']);
file_put_contents('$tmp/expect.json', json_encode(['courseid' => (int) \$course->id, 'groups' => [(int) \$g1->id, (int) \$g2->id]]));
echo "course {\$course->id}: valid row, newer duplicate; orphan of deleted course 987654\n";
PHP

echo "== New code and read-only pre-upgrade check"
rm -rf "$P"; mkdir -p "$P"
tar -C "$SRC" --exclude=.git --exclude=node_modules -cf - . | tar -C "$P" -xf -
php "$P/cli/check_upgrade.php" | tee "$tmp/check.log"
grep -q "Configurations of deleted courses (will be backed up and removed): 1" "$tmp/check.log"
grep -q "rows to be backed up and removed: 1" "$tmp/check.log"
rows=$(php -r 'define("CLI_SCRIPT", 1); require "config.php"; echo $DB->count_records("tool_ltigroupautoenrol");')
[ "$rows" = 3 ] || { echo "FAIL: the read-only check changed data"; exit 1; }

echo "== Upgrade"
php admin/cli/upgrade.php --non-interactive > "$tmp/upgrade.log" 2>&1 || { tail -n 40 "$tmp/upgrade.log"; exit 1; }
grep "tool_ltigroupautoenrol:" "$tmp/upgrade.log" || true
upgraded=$(php admin/cli/cfg.php --component=tool_ltigroupautoenrol --name=version)
current=$(sed -n 's/.*\$plugin->version *= *\([0-9]*\).*/\1/p' "$SRC/version.php")
echo "upgraded version: $upgraded"
[ "$upgraded" = "$current" ]

echo "== Schema of the upgraded site against install.xml"
php admin/cli/check_database_schema.php | tee "$tmp/schema.log"
grep -qx "Database structure is ok." "$tmp/schema.log"

echo "== Data"
php <<PHP
<?php
define('CLI_SCRIPT', 1);
require('config.php');
\$expect = json_decode(file_get_contents('$tmp/expect.json'), true);
\$rows = array_values(\$DB->get_records('tool_ltigroupautoenrol', null, 'id'));
\$backups = glob(\$CFG->dataroot . '/tool_ltigroupautoenrol/upgrade-*.json');
\$backedup = 0;
foreach (\$backups as \$file) {
    \$backedup += count(json_decode(file_get_contents(\$file), true)['rows'] ?? []);
}
\$unique = \$DB->get_manager()->index_exists(new xmldb_table('tool_ltigroupautoenrol'),
    new xmldb_index('courseid', XMLDB_INDEX_UNIQUE, ['courseid']));
echo 'rows: ' . count(\$rows) . ', kept row ' . (\$rows[0]->id ?? '-') . ': ' . (\$rows[0]->settings ?? '-') . "\n";
echo 'unique key: ' . (\$unique ? 'yes' : 'no') . ', backup files: ' . count(\$backups) . ", rows backed up: \$backedup\n";
\$ok = count(\$rows) === 1 && (int) \$rows[0]->courseid === \$expect['courseid'] && (int) \$rows[0]->enable_enrol === 1
    && json_decode(\$rows[0]->settings, true) === [7 => \$expect['groups']] && \$unique && \$backedup === 2;
echo \$ok ? "RESULT: OK\n" : "RESULT: FAIL\n";
exit(\$ok ? 0 : 1);
PHP
