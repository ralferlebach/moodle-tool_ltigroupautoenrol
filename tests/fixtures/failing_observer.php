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

namespace tool_ltigroupautoenrol\tests;

/**
 * Observer with injectable failures, registered for the real enrolment event in observer_failure_test.
 *
 * @package    tool_ltigroupautoenrol
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @author     Ralf Erlebach - https://github.com/ralferlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class failing_observer extends \tool_ltigroupautoenrol\observer {
    /** @var \Throwable|null Thrown instead of assigning the user. */
    public static $assignfailure = null;

    /** @var bool Whether recording the failure fails as well. */
    public static $recordfails = false;

    /**
     * Throws the configured failure, otherwise assigns as usual.
     *
     * @param int $courseid
     * @param \stdClass $userenrolment
     * @return \tool_ltigroupautoenrol\local\assignment_result|null
     */
    protected static function assign(int $courseid, \stdClass $userenrolment): ?\tool_ltigroupautoenrol\local\assignment_result {
        if (self::$assignfailure) {
            throw self::$assignfailure;
        }
        return parent::assign($courseid, $userenrolment);
    }

    /**
     * Fails if configured, otherwise records as usual.
     *
     * @param int $courseid
     * @return void
     */
    protected static function record_failure(int $courseid): void {
        if (self::$recordfails) {
            throw new \dml_write_exception('simulated status persistence failure');
        }
        parent::record_failure($courseid);
    }
}
