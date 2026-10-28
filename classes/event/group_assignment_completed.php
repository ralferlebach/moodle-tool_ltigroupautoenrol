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
 * The groups mapped to an LTI tool were processed for one LTI-enrolled user.
 *
 * Triggered by the enrolment observer for every eligible new LTI enrolment of a configured tool, and
 * by the backfill for every user for whom at least one membership was added or failed.
 *
 * @property-read array $other {
 *      - string source: 'event' (new enrolment) or 'backfill'
 *      - int added: memberships added
 *      - int existing: memberships that already existed
 *      - int errors: memberships that could not be added
 *      - int toolid: enrol_lti_tools.id
 * }
 *
 * @package    tool_ltigroupautoenrol
 * @copyright  2026 Ralf Erlebach
 * @author     Ralf Erlebach - https://github.com/ralferlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class group_assignment_completed extends group_assignment_base {
    /**
     * Keys of "other".
     *
     * @return array<string, string>
     */
    protected static function get_other_keys(): array {
        return ['source' => 'string', 'added' => 'int', 'existing' => 'int', 'errors' => 'int', 'toolid' => 'int'];
    }

    /**
     * Event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('event_group_assignment_completed', 'tool_ltigroupautoenrol');
    }

    /**
     * Event description (ids and counters only).
     *
     * @return string
     */
    public function get_description() {
        return "The groups of the LTI tool with id '{$this->other['toolid']}' (enrolment method with id '{$this->objectid}') " .
            "were processed for the user with id '{$this->relateduserid}' (source: {$this->other['source']}): " .
            "added {$this->other['added']}, already member {$this->other['existing']}, errors {$this->other['errors']}.";
    }

    /**
     * Validates the event data; the user is mandatory.
     *
     * @return void
     * @throws \coding_exception
     */
    protected function validate_data() {
        parent::validate_data();
        if (!isset($this->relateduserid)) {
            throw new \coding_exception('The \'relateduserid\' must be set.');
        }
    }
}
