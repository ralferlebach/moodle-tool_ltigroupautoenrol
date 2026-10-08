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
 * Supported upgrade baselines (see docs/CHANGES.md, section "Upgrading"); the original install.xml of each
 * baseline is kept in tests/fixtures and the upgrade is tested from each of them:
 * - 0.1 (2024050100 - 2024090802): schema inherited from tool_groupautoenrol
 *   (rolelist, enrol_method, profile_field, use_groupslist, groupslist, balises), no "settings" field.
 *   Version 0.2 replaced these fields in install.xml without an upgrade step.
 * - 0.2 - 1.1 (2024092701 - 2026030700, incl. the published 1.0.1 = 2024100600): "settings" field,
 *   but no unique key on courseid.
 *
 * All helpers are idempotent and only act on the structure they actually find. Nothing is deleted
 * without a backup: removed values and rows are written as JSON to
 * $CFG->dataroot/tool_ltigroupautoenrol/ before the change, and the file name is reported.
 *
 * @package    tool_ltigroupautoenrol
 * @copyright  2026 Ralf Erlebach
 * @author     Ralf Erlebach - https://github.com/ralferlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// The pre-upgrade check (cli/check_upgrade.php) and the upgrade run while Moodle's class cache still describes
// the installed version; versions up to 1.1 had no classes/local, so these classes are loaded explicitly.
require_once(__DIR__ . '/../classes/local/invalid_mapping_exception.php');
require_once(__DIR__ . '/../classes/local/mapping.php');

/** Fields of the 0.1 schema that no later version uses. */
const TOOL_LTIGROUPAUTOENROL_LEGACY_FIELDS = [
    'rolelist', 'enrol_method', 'profile_field', 'use_groupslist', 'groupslist', 'balises',
];

/** Default values of the 0.1 fields; rows that still hold them were never configured and are not reported. */
const TOOL_LTIGROUPAUTOENROL_LEGACY_DEFAULTS = [
    'rolelist' => '5', 'enrol_method' => '0', 'profile_field' => '0', 'use_groupslist' => '0',
];

/**
 * Writes rows that an upgrade step is about to remove or change to a JSON backup file.
 *
 * @param string $name Short name of the step (part of the file name).
 * @param array $rows Rows (course settings only, no user data).
 * @return string Path of the backup file.
 */
function tool_ltigroupautoenrol_upgrade_backup(string $name, array $rows): string {
    global $CFG;
    $dir = make_writable_directory($CFG->dataroot . '/tool_ltigroupautoenrol');
    $file = $dir . '/upgrade-' . $name . '-' . date('Ymd-His') . '-' . random_string(6) . '.json';
    $payload = [
        'component' => 'tool_ltigroupautoenrol',
        'step' => $name,
        'created' => time(),
        'rows' => array_values(array_map(fn($row) => (array) $row, $rows)),
    ];
    if (file_put_contents($file, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false) {
        throw new moodle_exception('error_upgradebackup', 'tool_ltigroupautoenrol', '', $file);
    }
    return $file;
}

/**
 * Read-only analysis of the plugin table before an upgrade (used by cli/check_upgrade.php).
 *
 * Reports what the upgrade to 1.2 will change: legacy 0.1 fields and configured legacy values,
 * configurations of deleted courses, duplicate configurations per course and invalid mappings.
 *
 * @return string[] Report lines (no personal data).
 */
function tool_ltigroupautoenrol_upgrade_analyse(): array {
    global $DB;
    $dbman = $DB->get_manager();
    $table = new xmldb_table('tool_ltigroupautoenrol');
    if (!$dbman->table_exists($table)) {
        return ['Table tool_ltigroupautoenrol does not exist (plugin not installed).'];
    }
    $report = [];
    $present = array_values(array_filter(
        TOOL_LTIGROUPAUTOENROL_LEGACY_FIELDS,
        fn(string $name): bool => $dbman->field_exists($table, new xmldb_field($name))
    ));
    $report[] = 'Rows: ' . $DB->count_records('tool_ltigroupautoenrol');
    $report[] = 'Legacy 0.1 fields present: ' . ($present ? implode(', ', $present) : 'none');
    if ($present) {
        $configured = 0;
        $rs = $DB->get_recordset('tool_ltigroupautoenrol', null, 'id', 'id, ' . implode(', ', $present));
        foreach ($rs as $record) {
            $configured += tool_ltigroupautoenrol_upgrade_legacy_values($record, $present) ? 1 : 0;
        }
        $rs->close();
        $report[] = "Rows with configured legacy values (will be backed up and removed): {$configured}";
    }
    $settingsexists = $dbman->field_exists($table, new xmldb_field('settings'));
    $report[] = 'Field "settings" present: ' . ($settingsexists ? 'yes' : 'no (will be added)');
    $orphans = $DB->count_records_sql(
        "SELECT COUNT(1) FROM {tool_ltigroupautoenrol} t LEFT JOIN {course} c ON c.id = t.courseid WHERE c.id IS NULL"
    );
    $report[] = "Configurations of deleted courses (will be backed up and removed): {$orphans}";
    $duplicates = $DB->get_records_sql(
        "SELECT courseid, COUNT(1) AS cnt FROM {tool_ltigroupautoenrol} GROUP BY courseid HAVING COUNT(1) > 1"
    );
    $extra = array_sum(array_map(fn($d) => (int) $d->cnt - 1, $duplicates));
    $report[] = 'Courses with duplicate configurations: ' . count($duplicates)
        . ($duplicates ? ' (' . implode(', ', array_keys($duplicates)) . ")" : '')
        . "; rows to be backed up and removed: {$extra}";
    if ($settingsexists) {
        $invalid = [];
        $rs = $DB->get_recordset('tool_ltigroupautoenrol', null, 'id', 'id, courseid, settings');
        foreach ($rs as $record) {
            try {
                \tool_ltigroupautoenrol\local\mapping::from_json($record->settings);
            } catch (\tool_ltigroupautoenrol\local\invalid_mapping_exception $e) {
                $invalid[] = $record->courseid;
            }
        }
        $rs->close();
        $report[] = 'Courses with invalid mappings (kept, ignored at runtime): ' . ($invalid ? implode(', ', $invalid) : 'none');
    }
    return $report;
}

/**
 * Returns the legacy values of a 0.1 row that differ from the 0.1 defaults (i.e. were configured).
 *
 * @param stdClass $record
 * @param string[] $present Legacy fields present in the table.
 * @return array
 */
function tool_ltigroupautoenrol_upgrade_legacy_values(stdClass $record, array $present): array {
    $values = [];
    foreach ($present as $name) {
        $default = TOOL_LTIGROUPAUTOENROL_LEGACY_DEFAULTS[$name] ?? null;
        if ($record->$name !== null && $record->$name !== '' && (string) $record->$name !== $default) {
            $values[$name] = $record->$name;
        }
    }
    return $values;
}

/**
 * Brings a 0.1 schema to the current field layout.
 *
 * Version 0.1 never evaluated its stored configuration (group assignment was hard-coded), so the
 * legacy values carry no tool to group semantics and can not be migrated into "settings".
 * All legacy values are written to a JSON backup file before the fields are dropped; configured
 * (non-default) values are also listed in the upgrade output, so nothing is lost.
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

    $rows = $DB->get_records('tool_ltigroupautoenrol', null, 'id', 'id, courseid, ' . implode(', ', $present));
    if ($rows) {
        $report[] = 'Backup of the legacy values of ' . count($rows) . ' row(s): '
            . tool_ltigroupautoenrol_upgrade_backup('legacy-0.1-fields', $rows);
    }
    foreach ($rows as $record) {
        $values = tool_ltigroupautoenrol_upgrade_legacy_values($record, $present);
        if ($values) {
            $report[] = "Legacy values of course {$record->courseid} (row {$record->id}) not migrated: " . json_encode($values);
        }
    }

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
 * configuration that was effective. Rows of courses that no longer exist are removed as well.
 * All removed rows are written to a JSON backup file first and reported in full (course settings only).
 *
 * @return string[] Report lines (no personal data).
 */
function tool_ltigroupautoenrol_upgrade_enforce_unique_course(): array {
    global $DB;
    $dbman = $DB->get_manager();
    $table = new xmldb_table('tool_ltigroupautoenrol');
    $report = [];

    $orphans = $DB->get_records_sql(
        "SELECT t.*
           FROM {tool_ltigroupautoenrol} t
      LEFT JOIN {course} c ON c.id = t.courseid
          WHERE c.id IS NULL
       ORDER BY t.id"
    );
    foreach ($orphans as $orphan) {
        $report[] = "Removed configuration of deleted course {$orphan->courseid}: " . json_encode($orphan);
    }
    if ($orphans) {
        $report[] = 'Backup of ' . count($orphans) . ' configuration(s) of deleted courses: '
            . tool_ltigroupautoenrol_upgrade_backup('deleted-courses', $orphans);
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
            'id'
        );
        $report[] = 'Backup of ' . count($rows) . " duplicate configuration(s) of course {$duplicate->courseid}: "
            . tool_ltigroupautoenrol_upgrade_backup('duplicates-course-' . $duplicate->courseid, $rows);
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
 * Adds the status fields of 1.2: last backfill run and unresolved failures of the enrolment
 * observer (counters and times only, no user data).
 *
 * @return string[] Report lines.
 */
function tool_ltigroupautoenrol_upgrade_add_backfill_status(): array {
    global $DB;
    $dbman = $DB->get_manager();
    $table = new xmldb_table('tool_ltigroupautoenrol');
    $report = [];
    $fields = [
        new xmldb_field('backfill_time', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'settings'),
        new xmldb_field('backfill_result', XMLDB_TYPE_TEXT, null, null, null, null, null, 'backfill_time'),
        new xmldb_field('event_errors', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'backfill_result'),
        new xmldb_field('event_errortime', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'event_errors'),
    ];
    foreach ($fields as $field) {
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
            $report[] = "Added field \"{$field->getName()}\".";
        }
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
