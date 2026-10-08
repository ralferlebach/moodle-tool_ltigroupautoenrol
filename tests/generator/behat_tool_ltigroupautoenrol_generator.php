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
 * Behat data generator for tool_ltigroupautoenrol.
 *
 * Entities:
 * - "tool_ltigroupautoenrol > lti tools": course, name (creates an LTI 1.3 enrol_lti instance),
 * - "tool_ltigroupautoenrol > lti enrolments": user, tool (enrols through enrol_lti, fires the real event).
 *
 * @package    tool_ltigroupautoenrol
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @author     Ralf Erlebach - https://github.com/ralferlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_tool_ltigroupautoenrol_generator extends behat_generator_base {
    /**
     * Get a list of the entities that Behat can create using the generator step.
     *
     * @return array
     */
    protected function get_creatable_entities(): array {
        return [
            'lti tools' => [
                'singular' => 'lti tool',
                'datagenerator' => 'behat_lti_tool',
                'required' => ['course', 'name'],
                'switchids' => ['course' => 'courseid'],
            ],
            'lti enrolments' => [
                'singular' => 'lti enrolment',
                'datagenerator' => 'behat_lti_enrolment',
                'required' => ['user', 'tool'],
                'switchids' => ['user' => 'userid'],
            ],
        ];
    }
}
