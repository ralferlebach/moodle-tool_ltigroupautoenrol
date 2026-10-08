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
     * @param course_config $config
     * @return void
     */
    public static function save(course_config $config): void {
        self::upsert((object) [
            'courseid' => $config->courseid,
            'enable_enrol' => $config->enabled ? 1 : 0,
            'settings' => $config->mapping->to_json(),
        ]);
    }

    /**
     * Writes the row of a course, resolving a concurrent first save deterministically.
     *
     * The table has a unique key on courseid. If a concurrent request inserted the row between
     * our read and our insert, the insert fails on the unique key and the row is updated instead,
     * so the last writer wins and no duplicate row can exist. Any other write error is rethrown.
     *
     * @param \stdClass $record Fields to write; must contain courseid.
     * @param bool $assumenew Skip the existence check (used by tests to exercise the conflict path).
     * @return int Row id.
     */
    public static function upsert(\stdClass $record, bool $assumenew = false): int {
        global $DB;
        $existingid = $assumenew ? false : $DB->get_field(self::TABLE, 'id', ['courseid' => $record->courseid]);
        if ($existingid) {
            $record->id = $existingid;
            $DB->update_record(self::TABLE, $record);
            return (int) $existingid;
        }
        try {
            return (int) $DB->insert_record(self::TABLE, $record);
        } catch (\dml_write_exception $e) {
            // Lost the race against a concurrent first save; the row exists now.
            $existingid = $DB->get_field(self::TABLE, 'id', ['courseid' => $record->courseid]);
            if (!$existingid) {
                throw $e;
            }
            $record->id = $existingid;
            $DB->update_record(self::TABLE, $record);
            return (int) $existingid;
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

    /** @var string Backfill status: confirmed and queued, waiting for cron. */
    public const BACKFILL_QUEUED = 'queued';

    /** @var string Backfill status: the task is running. */
    public const BACKFILL_RUNNING = 'running';

    /** @var string Backfill status: finished; counters are available. */
    public const BACKFILL_DONE = 'done';

    /** @var string Backfill status: not executed because the configuration changed or was disabled. */
    public const BACKFILL_SKIPPED = 'skipped';

    /**
     * Records the status of the backfill of a course (status, time, counters; no user data).
     *
     * @param int $courseid
     * @param string $status One of the BACKFILL_* constants.
     * @param assignment_result|null $result Counters, for BACKFILL_DONE.
     * @param int|null $time Defaults to time().
     * @return void
     */
    public static function record_backfill_status(
        int $courseid,
        string $status,
        ?assignment_result $result = null,
        ?int $time = null
    ): void {
        global $DB;
        $id = $DB->get_field(self::TABLE, 'id', ['courseid' => $courseid]);
        if (!$id) {
            return;
        }
        $data = ['status' => $status] + ($result ?? new assignment_result())->to_array();
        $DB->update_record(self::TABLE, (object) [
            'id' => $id,
            'backfill_time' => $time ?? time(),
            'backfill_result' => json_encode($data),
        ]);
    }

    /**
     * Records a finished backfill run.
     *
     * @param int $courseid
     * @param assignment_result $result
     * @param int|null $time Defaults to time().
     * @return void
     */
    public static function record_backfill(int $courseid, assignment_result $result, ?int $time = null): void {
        global $DB;
        self::record_backfill_status($courseid, self::BACKFILL_DONE, $result, $time);
        if ($result->errors === 0) {
            // A complete backfill has assigned every eligible participant, including those the observer missed.
            $DB->execute(
                'UPDATE {' . self::TABLE . '} SET event_errors = 0, event_errortime = 0 WHERE courseid = ?',
                [$courseid]
            );
        }
    }

    /**
     * Adds failed memberships of the enrolment observer to the unresolved failures of a course.
     *
     * @param int $courseid
     * @param int $count
     * @param int|null $time Defaults to time().
     * @return void
     */
    public static function record_event_errors(int $courseid, int $count, ?int $time = null): void {
        global $DB;
        if ($count <= 0) {
            return;
        }
        $DB->execute(
            'UPDATE {' . self::TABLE . '}
                SET event_errors = event_errors + ?,
                    event_errortime = CASE WHEN event_errortime = 0 THEN ? ELSE event_errortime END
              WHERE courseid = ?',
            [$count, $time ?? time(), $courseid]
        );
    }

    /**
     * Returns the unresolved failures of the enrolment observer of a course.
     *
     * @param int $courseid
     * @return \stdClass|null Object with "count" and "time" (first failure), or null if there are none.
     */
    public static function get_event_errors(int $courseid): ?\stdClass {
        global $DB;
        $record = $DB->get_record(self::TABLE, ['courseid' => $courseid], 'id, event_errors, event_errortime');
        if (!$record || (int) $record->event_errors === 0) {
            return null;
        }
        return (object) ['count' => (int) $record->event_errors, 'time' => (int) $record->event_errortime];
    }

    /**
     * Returns the status of the last backfill of a course.
     *
     * @param int $courseid
     * @return \stdClass|null Object with "status", "time" (int) and the counters of {@see assignment_result::to_array()}.
     */
    public static function get_last_backfill(int $courseid): ?\stdClass {
        global $DB;
        $record = $DB->get_record(self::TABLE, ['courseid' => $courseid], 'id, backfill_time, backfill_result');
        if (!$record || empty($record->backfill_time)) {
            return null;
        }
        $data = json_decode((string) $record->backfill_result, true);
        $data = is_array($data) ? $data : [];
        $counters = new assignment_result();
        foreach (array_keys($counters->to_array()) as $key) {
            $counters->$key = (int) ($data[$key] ?? 0);
        }
        $statuses = [self::BACKFILL_QUEUED, self::BACKFILL_RUNNING, self::BACKFILL_DONE, self::BACKFILL_SKIPPED];
        $status = in_array($data['status'] ?? null, $statuses, true) ? $data['status'] : self::BACKFILL_DONE;
        return (object) (['status' => $status, 'time' => (int) $record->backfill_time] + $counters->to_array());
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
