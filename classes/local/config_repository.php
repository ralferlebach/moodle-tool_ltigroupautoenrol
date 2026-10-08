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

namespace tool_ltigroupautoenrol\local;

/**
 * Persistence of the per-course configuration (table tool_ltigroupautoenrol, one row per course).
 *
 * @package    tool_ltigroupautoenrol
 * @copyright  2026 Ralf Erlebach
 * @author     Ralf Erlebach - https://github.com/ralferlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class config_repository {
    /** @var string Plugin table. */
    public const TABLE = 'tool_ltigroupautoenrol';

    /**
     * Loads the configuration of a course.
     *
     * @param int $courseid
     * @return course_config|null Null if the course has never been configured.
     * @throws invalid_mapping_exception If the stored mapping is corrupt.
     */
    public static function get(int $courseid): ?course_config {
        global $DB;
        $record = $DB->get_record(self::TABLE, ['courseid' => $courseid]);
        if (!$record) {
            return null;
        }
        return new course_config($courseid, !empty($record->enable_enrol), mapping::from_json($record->settings));
    }

    /**
     * Loads the configuration of a course or returns the default (disabled) configuration.
     *
     * @param int $courseid
     * @return course_config
     * @throws invalid_mapping_exception If the stored mapping is corrupt.
     */
    public static function get_or_default(int $courseid): course_config {
        return self::get($courseid) ?? course_config::create_default($courseid);
    }

    /**
     * Inserts or updates the configuration of a course.
     *
     * The table has a unique key on courseid. If a concurrent request inserted the row between
     * our read and our insert, the insert fails on the unique key and the row is updated instead,
     * so the last writer wins deterministically and no duplicate row can exist.
     *
     * @param course_config $config
     * @return void
     */
    public static function save(course_config $config): void {
        global $DB;
        $record = (object) [
            'courseid' => $config->courseid,
            'enable_enrol' => $config->enabled ? 1 : 0,
            'settings' => $config->mapping->to_json(),
        ];
        $existingid = $DB->get_field(self::TABLE, 'id', ['courseid' => $config->courseid]);
        if ($existingid) {
            $record->id = $existingid;
            $DB->update_record(self::TABLE, $record);
            return;
        }
        try {
            $DB->insert_record(self::TABLE, $record);
        } catch (\dml_write_exception $e) {
            // Lost the race against a concurrent first save; the row exists now. Any other write error is rethrown.
            $existingid = $DB->get_field(self::TABLE, 'id', ['courseid' => $config->courseid]);
            if (!$existingid) {
                throw $e;
            }
            $record->id = $existingid;
            $DB->update_record(self::TABLE, $record);
        }
    }

    /**
     * Removes stale tool and group ids from the stored mapping of a course.
     *
     * Only the plugin's own configuration is touched; existing group memberships are never removed.
     *
     * @param int $courseid
     * @return bool True if the stored mapping was changed.
     */
    public static function prune(int $courseid): bool {
        try {
            $config = self::get($courseid);
        } catch (invalid_mapping_exception $e) {
            // A corrupt mapping is left untouched for diagnosis; the observer treats it as "no mapping".
            return false;
        }
        if (!$config || $config->mapping->is_empty()) {
            return false;
        }
        $pruned = $config->mapping->restrict_to(
            array_keys(lti_resolver::get_course_tools($courseid)),
            array_keys(groups_get_all_groups($courseid))
        );
        if ($pruned->to_json() === $config->mapping->to_json()) {
            return false;
        }
        self::save(new course_config($courseid, $config->enabled, $pruned));
        return true;
    }

    /**
     * Deletes the configuration of a course.
     *
     * @param int $courseid
     * @return void
     */
    public static function delete_for_course(int $courseid): void {
        global $DB;
        $DB->delete_records(self::TABLE, ['courseid' => $courseid]);
    }
}
