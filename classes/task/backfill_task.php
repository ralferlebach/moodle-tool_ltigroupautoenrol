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

namespace tool_ltigroupautoenrol\task;

use tool_ltigroupautoenrol\local\assignment_service;
use tool_ltigroupautoenrol\local\config_repository;
use tool_ltigroupautoenrol\local\invalid_mapping_exception;

/**
 * Assigns already LTI-enrolled users of a course to the configured groups (backfill).
 *
 * Custom data: {courseid: int, hash: string}. The hash is the fingerprint of the mapping the
 * user confirmed; if the configuration changed meanwhile, the task does nothing. The task holds
 * a per-course lock, works in batches and is idempotent, so a retry after a failure is safe.
 * Neither custom data nor log output contain user ids.
 *
 * @package    tool_ltigroupautoenrol
 * @copyright  2026 Ralf Erlebach
 * @author     Ralf Erlebach - https://github.com/ralferlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backfill_task extends \core\task\adhoc_task {
    /**
     * Creates a task for a course and a confirmed mapping fingerprint.
     *
     * @param int $courseid
     * @param string $hash
     * @return self
     */
    public static function create(int $courseid, string $hash): self {
        $task = new self();
        $task->set_component('tool_ltigroupautoenrol');
        $task->set_custom_data(['courseid' => $courseid, 'hash' => $hash]);
        return $task;
    }

    /**
     * Returns the pending backfill task of a course, if any.
     *
     * @param int $courseid
     * @return bool
     */
    public static function is_pending(int $courseid): bool {
        foreach (\core\task\manager::get_adhoc_tasks(self::class) as $task) {
            if ((int) ($task->get_custom_data()->courseid ?? 0) === $courseid) {
                return true;
            }
        }
        return false;
    }

    /**
     * Name of the task.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_backfill', 'tool_ltigroupautoenrol');
    }

    /**
     * Runs the backfill.
     *
     * @return void
     * @throws \moodle_exception If the lock is busy or memberships could not be added (task is retried).
     */
    public function execute(): void {
        global $DB;
        $data = $this->get_custom_data();
        $courseid = (int) ($data->courseid ?? 0);
        $hash = (string) ($data->hash ?? '');

        if (!$courseid || !$DB->record_exists('course', ['id' => $courseid])) {
            mtrace("tool_ltigroupautoenrol: course {$courseid} does not exist, backfill skipped.");
            return;
        }

        $factory = \core\lock\lock_config::get_lock_factory('tool_ltigroupautoenrol_backfill');
        // No waiting: a busy course makes the task fail and the task API retries it later.
        $lock = $factory->get_lock('course_' . $courseid, 0);
        if (!$lock) {
            throw new \moodle_exception('error_backfilllocked', 'tool_ltigroupautoenrol', '', $courseid);
        }
        try {
            try {
                $config = config_repository::get($courseid);
            } catch (invalid_mapping_exception $e) {
                mtrace("tool_ltigroupautoenrol: invalid mapping in course {$courseid}, backfill skipped.");
                return;
            }
            if (!$config || !$config->enabled || $config->mapping->get_hash() !== $hash) {
                mtrace("tool_ltigroupautoenrol: configuration of course {$courseid} changed or disabled, backfill skipped.");
                return;
            }
            $result = assignment_service::backfill($config);
            mtrace("tool_ltigroupautoenrol: backfill course {$courseid}: " . json_encode($result->to_array()));
            if ($result->errors > 0) {
                throw new \moodle_exception('error_backfillpartial', 'tool_ltigroupautoenrol', '', $result->errors);
            }
        } finally {
            $lock->release();
        }
    }
}
