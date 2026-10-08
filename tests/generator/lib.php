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

use tool_ltigroupautoenrol\local\config_repository;
use tool_ltigroupautoenrol\local\course_config;
use tool_ltigroupautoenrol\local\mapping;

/**
 * Test data generator for tool_ltigroupautoenrol.
 *
 * Creates real enrol_lti instances (LTI 1.3 published resources) and real enrolments through
 * the enrol_lti plugin, so tests exercise the production path including the enrolment event.
 *
 * @package    tool_ltigroupautoenrol
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @author     Ralf Erlebach - https://github.com/ralferlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tool_ltigroupautoenrol_generator extends component_generator_base {
    /**
     * Creates an LTI tool (enrol_lti instance) for a course.
     *
     * @param int $courseid
     * @param array $data Optional overrides, e.g. ['ltiversion' => 'LTI-1p0/LTI-2p0', 'status' => ENROL_INSTANCE_DISABLED].
     * @return stdClass Tool record as returned by enrol_lti\helper::get_lti_tool().
     */
    public function create_lti_tool(int $courseid, array $data = []): stdClass {
        $data = (object) array_merge(['courseid' => $courseid, 'ltiversion' => 'LTI-1p3'], $data);
        $tool = $this->datagenerator->create_lti_tool($data);
        return \enrol_lti\helper::get_lti_tool($tool->id);
    }

    /**
     * Behat: creates an LTI 1.3 tool from a data table row (course, name).
     *
     * @param array $data
     * @return stdClass
     */
    public function create_behat_lti_tool(array $data): stdClass {
        return $this->create_lti_tool((int) $data['courseid'], ['name' => $data['name']]);
    }

    /**
     * Behat: enrols a user through the LTI tool with the given name (user, tool).
     *
     * @param array $data
     * @return void
     */
    public function create_behat_lti_enrolment(array $data): void {
        global $DB;
        $enrolid = $DB->get_field('enrol', 'id', ['enrol' => 'lti', 'name' => $data['tool']], MUST_EXIST);
        $tool = $DB->get_record('enrol_lti_tools', ['enrolid' => $enrolid], '*', MUST_EXIST);
        $this->enrol_via_lti($tool, (int) $data['userid']);
    }

    /**
     * Stores the plugin configuration of a course.
     *
     * @param int $courseid
     * @param array $toolgroups Tool id => group ids.
     * @param bool $enabled
     * @return course_config
     */
    public function create_config(int $courseid, array $toolgroups, bool $enabled = true): course_config {
        $config = new course_config($courseid, $enabled, new mapping($toolgroups));
        config_repository::save($config);
        return $config;
    }

    /**
     * Enrols a user through the enrol_lti instance of a tool (fires user_enrolment_created).
     *
     * @param stdClass $tool
     * @param int $userid
     * @param int $status ENROL_USER_ACTIVE or ENROL_USER_SUSPENDED.
     * @param int $timestart
     * @param int $timeend
     * @return void
     */
    public function enrol_via_lti(
        stdClass $tool,
        int $userid,
        int $status = ENROL_USER_ACTIVE,
        int $timestart = 0,
        int $timeend = 0
    ): void {
        global $DB;
        $instance = $DB->get_record('enrol', ['id' => $tool->enrolid], '*', MUST_EXIST);
        enrol_get_plugin('lti')->enrol_user($instance, $userid, $instance->roleid ?: null, $timestart, $timeend, $status);
    }
}
