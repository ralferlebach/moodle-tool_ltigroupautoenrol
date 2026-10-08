<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Upgrade steps.
 *
 * @package    tool_ltigroupautoenrol
 * @copyright  2026 Ralf Erlebach
 * @author     Ralf Erlebach - https://github.com/ralferlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * xmldb_tool_ltigroupautoenrol_upgrade
 *
 * The former step "$oldversion < 2024040400" (inherited from tool_groupautoenrol) was removed:
 * no version of this component was ever older than 2024050100, so it could never run.
 *
 * @param int $oldversion
 * @return bool true
 */
function xmldb_tool_ltigroupautoenrol_upgrade(int $oldversion): bool {
    global $CFG;
    require_once($CFG->dirroot . '/admin/tool/ltigroupautoenrol/db/upgradelib.php');

    if ($oldversion < 2026100800) {
        // Repair the 0.1 schema, normalise mappings, remove duplicates, add the unique key on courseid
        // and the fields keeping the outcome of the last backfill run (counters only).
        $report = array_merge(
            tool_ltigroupautoenrol_upgrade_repair_legacy_schema(),
            tool_ltigroupautoenrol_upgrade_enforce_unique_course(),
            tool_ltigroupautoenrol_upgrade_normalise_mappings(),
            tool_ltigroupautoenrol_upgrade_add_backfill_status()
        );
        foreach ($report as $line) {
            mtrace('tool_ltigroupautoenrol: ' . $line);
        }

        upgrade_plugin_savepoint(true, 2026100800, 'tool', 'ltigroupautoenrol');
    }
    return true;
}
