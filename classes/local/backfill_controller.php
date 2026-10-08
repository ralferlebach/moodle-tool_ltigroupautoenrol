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

use tool_ltigroupautoenrol\task\backfill_task;

/**
 * Handles the confirmation (POST) of the backfill page.
 *
 * Kept out of backfill.php so every outcome can be tested. The page checks login, capability
 * and sesskey before calling {@see confirm()}.
 *
 * @package    tool_ltigroupautoenrol
 * @copyright  2026 Ralf Erlebach
 * @author     Ralf Erlebach - https://github.com/ralferlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class backfill_controller {
    /** @var string The backfill was queued. */
    public const QUEUED = 'queued';

    /** @var string The configuration changed after the preview; a new confirmation is required. */
    public const CHANGED = 'changed';

    /** @var string A backfill of the course is already queued. */
    public const PENDING = 'pending';

    /** @var string The feature is disabled or nothing is mapped. */
    public const DISABLED = 'disabled';

    /**
     * Queues the backfill a user confirmed on the preview page.
     *
     * @param int $courseid
     * @param string $confirmedhash Mapping fingerprint shown with the preview.
     * @param int $previewtime Time the preview was shown; only enrolments existing then are assigned.
     * @param int|null $now Defaults to time().
     * @return string One of the class constants.
     * @throws invalid_mapping_exception If the stored mapping is corrupt.
     */
    public static function confirm(int $courseid, string $confirmedhash, int $previewtime, ?int $now = null): string {
        $now = $now ?? time();
        $config = config_repository::get_or_default($courseid);
        if (!$config->enabled || $config->mapping->is_empty()) {
            return self::DISABLED;
        }
        if (!hash_equals($config->mapping->get_hash(), $confirmedhash)) {
            return self::CHANGED;
        }
        if (backfill_task::is_pending($courseid)) {
            return self::PENDING;
        }
        // A manipulated future time could only widen the set to enrolments the observer handles anyway.
        $previewtime = min($previewtime, $now);
        \core\task\manager::queue_adhoc_task(backfill_task::create($courseid, $confirmedhash, $previewtime), true);
        config_repository::record_backfill_status($courseid, config_repository::BACKFILL_QUEUED, null, $now);
        return self::QUEUED;
    }
}
