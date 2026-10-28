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

namespace tool_ltigroupautoenrol\event;

/**
 * Group memberships of an LTI-enrolled user could not be added, or a backfill run of a tool failed.
 *
 * The enrolment itself is never affected. relateduserid is the user if known; it is missing for a
 * failure of a whole backfill run of a tool.
 *
 * @property-read array $other {
 *      - string source: 'event' (new enrolment) or 'backfill'
 *      - string code: short machine code (see the CODE_* constants) or the basename of the exception class
 *      - int errors: memberships that could not be added (1 if unknown, e.g. for an exception)
 *      - int toolid: enrol_lti_tools.id, 0 if the failure happened before the tool was resolved
 * }
 *
 * @package    tool_ltigroupautoenrol
 * @copyright  2026 Ralf Erlebach
 * @author     Ralf Erlebach - https://github.com/ralferlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class group_assignment_failed extends group_assignment_base {
    /** @var string groups_add_member() did not add the membership (e.g. the user is not enrolled any more). */
    public const CODE_ADD_MEMBER_FAILED = 'add_member_failed';

    /** @var string The tool does not belong to the configured course (invariant violation, nothing was added). */
    public const CODE_TOOL_COURSE_MISMATCH = 'tool_course_mismatch';

    /**
     * Keys of "other".
     *
     * @return array<string, string>
     */
    protected static function get_other_keys(): array {
        return ['source' => 'string', 'code' => 'string', 'errors' => 'int', 'toolid' => 'int'];
    }

    /**
     * Event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('event_group_assignment_failed', 'tool_ltigroupautoenrol');
    }

    /**
     * Event description (ids, counters and machine code only).
     *
     * @return string
     */
    public function get_description() {
        $user = isset($this->relateduserid) ? "the user with id '{$this->relateduserid}'" : 'a backfill run';
        return "Group assignment for {$user} through the LTI tool with id '{$this->other['toolid']}' (enrolment method " .
            "with id '{$this->objectid}', source: {$this->other['source']}) failed: {$this->other['code']}, " .
            "errors {$this->other['errors']}.";
    }

    /**
     * Validates the event data; the code is a short machine code (letters, digits, underscore).
     *
     * @return void
     * @throws \coding_exception
     */
    protected function validate_data() {
        parent::validate_data();
        if (!preg_match('/^[A-Za-z0-9_]{1,100}$/', $this->other['code'])) {
            throw new \coding_exception('The \'code\' value in other must be a short machine code.');
        }
    }
}
