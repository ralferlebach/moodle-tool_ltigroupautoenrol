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
 * Upgrade helpers for tool_ltigroupautoenrol.
 *
 * Supported upgrade baselines (see README, section "Upgrade"):
 * - 0.1 (2024050100 - 2024090802): schema inherited from tool_groupautoenrol
 *   (rolelist, enrol_method, profile_field, use_groupslist, groupslist, balises), no "settings" field.
 *   Version 0.2 replaced these fields in install.xml without an upgrade step.
 * - 0.2 - 1.1 (2024092701 - 2026030700): current fields, but no unique key on courseid.
 *
 * All helpers are idempotent and only act on the structure they actually find.
 *
 * @package    tool_ltigroupautoenrol
 * @copyright  2026 Ralf Erlebach
 * @author     Ralf Erlebach - https://github.com/ralferlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/** Fields of the 0.1 schema that no later version uses. */
const TOOL_LTIGROUPAUTOENROL_LEGACY_FIELDS = [
    'rolelist', 'enrol_method', 'profile_field', 'use_groupslist', 'groupslist', 'balises',
];

/** Default values of the 0.1 fields; rows that still hold them were never configured and are not reported. */
const TOOL_LTIGROUPAUTOENROL_LEGACY_DEFAULTS = [
    'rolelist' => '5', 'enrol_method' => '0', 'profile_field' => '0', 'use_groupslist' => '0',
];

/**
 * Brings a 0.1 schema to the current field layout.
 *
 * Version 0.1 never evaluated its stored configuration (group assignment was hard-coded), so the
 * legacy values carry no tool to group semantics and can not be migrated into "settings".
 * They are reported in the upgrade output before the fields are dropped, so nothing is lost silently.
 * "enable_enrol" is kept; with an empty mapping it has no effect until the course is configured.
 *
 * @return string[] Report lines (no personal data).
 */
function tool_ltigroupautoenrol_upgrade_repair_legacy_schema(): array {
    global $DB;
    $dbman = $DB->get_manager();
    $table = new xmldb_table('tool_ltigroupautoenrol');
    $report = [];

    $settings = new xmldb_field('settings', XMLDB_TYPE_TEXT, null, null, null, null, null, 'enable_enrol');
    if (!$dbman->field_exists($table, $settings)) {
        $dbman->add_field($table, $settings);
        $report[] = 'Added missing field "settings" (legacy 0.1 schema).';
    }

    $present = array_values(array_filter(
        TOOL_LTIGROUPAUTOENROL_LEGACY_FIELDS,
        fn(string $name): bool => $dbman->field_exists($table, new xmldb_field($name))
    ));
    if (!$present) {
        return $report;
    }

    $rs = $DB->get_recordset('tool_ltigroupautoenrol', null, 'id', 'id, courseid, ' . implode(', ', $present));
    foreach ($rs as $record) {
        $values = [];
        foreach ($present as $name) {
            $default = TOOL_LTIGROUPAUTOENROL_LEGACY_DEFAULTS[$name] ?? null;
            if ($record->$name !== null && $record->$name !== '' && (string) $record->$name !== $default) {
                $values[$name] = $record->$name;
            }
        }
        if ($values) {
            $report[] = "Legacy values of course {$record->courseid} (row {$record->id}) not migrated: " . json_encode($values);
        }
    }
    $rs->close();

    foreach ($present as $name) {
        $dbman->drop_field($table, new xmldb_field($name));
        $report[] = "Dropped legacy field \"{$name}\".";
    }
    return $report;
}

/**
 * Removes orphaned and duplicate configuration rows and adds the unique key on courseid.
 *
 * Conflict strategy for duplicates: the row with the lowest id is kept. Runtime code used
 * get_record() without ordering, which in practice returned the oldest row, so this keeps the
 * configuration that was effective. Removed rows are reported in full (course settings only).
 * Rows of courses that no longer exist are removed as well.
 *
 * @return string[] Report lines (no personal data).
 */
function tool_ltigroupautoenrol_upgrade_enforce_unique_course(): array {
    global $DB;
    $dbman = $DB->get_manager();
    $table = new xmldb_table('tool_ltigroupautoenrol');
    $report = [];

    $orphans = $DB->get_records_sql(
        "SELECT t.id, t.courseid, t.enable_enrol, t.settings
           FROM {tool_ltigroupautoenrol} t
      LEFT JOIN {course} c ON c.id = t.courseid
          WHERE c.id IS NULL
       ORDER BY t.id"
    );
    foreach ($orphans as $orphan) {
        $report[] = "Removed configuration of deleted course {$orphan->courseid}: " . json_encode($orphan);
    }
    if ($orphans) {
        $DB->delete_records_list('tool_ltigroupautoenrol', 'id', array_keys($orphans));
    }

    $duplicates = $DB->get_records_sql(
        "SELECT courseid, MIN(id) AS keepid, COUNT(1) AS cnt
           FROM {tool_ltigroupautoenrol}
       GROUP BY courseid
         HAVING COUNT(1) > 1"
    );
    foreach ($duplicates as $duplicate) {
        $rows = $DB->get_records_select(
            'tool_ltigroupautoenrol',
            'courseid = :courseid AND id <> :keepid',
            ['courseid' => $duplicate->courseid, 'keepid' => $duplicate->keepid],
            'id',
            'id, courseid, enable_enrol, settings'
        );
        foreach ($rows as $row) {
            $report[] = "Removed duplicate configuration of course {$row->courseid} (kept row {$duplicate->keepid}): "
                . json_encode($row);
        }
        $DB->delete_records_list('tool_ltigroupautoenrol', 'id', array_keys($rows));
    }

    $index = new xmldb_index('courseid', XMLDB_INDEX_UNIQUE, ['courseid']);
    if (!$dbman->index_exists($table, $index)) {
        $dbman->add_key($table, new xmldb_key('courseid', XMLDB_KEY_FOREIGN_UNIQUE, ['courseid'], 'course', ['id']));
        $report[] = 'Added unique key on courseid.';
    }
    return $report;
}

/**
 * Rewrites stored mappings into the canonical format (integer ids, no empty lists).
 *
 * Corrupt mappings are reported and left unchanged for manual diagnosis; at runtime they are
 * treated as "no mapping".
 *
 * @return string[] Report lines (no personal data).
 */
function tool_ltigroupautoenrol_upgrade_normalise_mappings(): array {
    global $DB;
    $report = [];
    $rs = $DB->get_recordset('tool_ltigroupautoenrol', null, 'id', 'id, courseid, settings');
    foreach ($rs as $record) {
        try {
            $json = \tool_ltigroupautoenrol\local\mapping::from_json($record->settings)->to_json();
        } catch (\tool_ltigroupautoenrol\local\invalid_mapping_exception $e) {
            $report[] = "Invalid mapping of course {$record->courseid} left unchanged: {$e->debuginfo}";
            continue;
        }
        if ($json !== $record->settings) {
            $DB->set_field('tool_ltigroupautoenrol', 'settings', $json, ['id' => $record->id]);
        }
    }
    $rs->close();
    return $report;
}
