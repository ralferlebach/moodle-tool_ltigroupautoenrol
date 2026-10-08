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

use core\output\notification;

/**
 * Human readable status of the backfill of a course (queued, running, done, skipped).
 *
 * The settings page and the preview page show the same message, rendered as a Moodle
 * notification, which carries role="alert" for assistive technology.
 *
 * @package    tool_ltigroupautoenrol
 * @copyright  2026 Ralf Erlebach
 * @author     Ralf Erlebach - https://github.com/ralferlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class backfill_status {
    /**
     * Returns the status message of the last backfill of a course.
     *
     * @param int $courseid
     * @return array{0: string, 1: string}|null Message and notification type, or null if there was no backfill yet.
     */
    public static function get_message(int $courseid): ?array {
        $last = config_repository::get_last_backfill($courseid);
        if (!$last) {
            return null;
        }
        $last->time = userdate($last->time);
        switch ($last->status) {
            case config_repository::BACKFILL_QUEUED:
                return [get_string('backfill_status_queued', 'tool_ltigroupautoenrol', $last), notification::NOTIFY_INFO];
            case config_repository::BACKFILL_RUNNING:
                return [get_string('backfill_status_running', 'tool_ltigroupautoenrol', $last), notification::NOTIFY_INFO];
            case config_repository::BACKFILL_SKIPPED:
                return [get_string('backfill_status_skipped', 'tool_ltigroupautoenrol', $last), notification::NOTIFY_WARNING];
            default:
                return [
                    get_string('backfill_lastrun', 'tool_ltigroupautoenrol', $last),
                    $last->errors ? notification::NOTIFY_WARNING : notification::NOTIFY_SUCCESS,
                ];
        }
    }
}
