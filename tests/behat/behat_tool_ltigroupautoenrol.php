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

// NOTE: no MOODLE_INTERNAL test here, this file may be required by behat before including /config.php.

require_once(__DIR__ . '/../../../../../lib/behat/behat_base.php');

use Behat\Mink\Exception\ExpectationException;

/**
 * Behat steps and named pages for tool_ltigroupautoenrol.
 *
 * Named pages: "tool_ltigroupautoenrol > settings" and "tool_ltigroupautoenrol > backfill",
 * both identified by the course short name.
 *
 * @package    tool_ltigroupautoenrol
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @author     Ralf Erlebach - https://github.com/ralferlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_tool_ltigroupautoenrol extends behat_base {
    /**
     * Resolves the URL of a named page of this plugin.
     *
     * @param string $page Page type ("settings" or "backfill").
     * @param string $identifier Course short name.
     * @return moodle_url
     */
    protected function resolve_page_instance_url(string $page, string $identifier): moodle_url {
        $courseid = $this->get_course_id($identifier);
        switch (strtolower($page)) {
            case 'settings':
                return new moodle_url('/admin/tool/ltigroupautoenrol/manage_lti_group_auto_enrol.php', ['id' => $courseid]);
            case 'backfill':
                return new moodle_url('/admin/tool/ltigroupautoenrol/backfill.php', ['id' => $courseid]);
            default:
                throw new Exception("Unrecognised page type '{$page}'");
        }
    }

    /**
     * Checks that a user is a member of a group.
     *
     * @Then /^"(?P<username_string>(?:[^"]|\\")*)" should be a member of group "(?P<group_string>(?:[^"]|\\")*)"$/
     * @param string $username
     * @param string $groupname
     * @return void
     */
    public function user_should_be_member_of_group(string $username, string $groupname): void {
        if (!$this->is_member($username, $groupname)) {
            throw new ExpectationException("'$username' is not a member of '$groupname'", $this->getSession());
        }
    }

    /**
     * Checks that a user is not a member of a group.
     *
     * @Then /^"(?P<username_string>(?:[^"]|\\")*)" should not be a member of group "(?P<group_string>(?:[^"]|\\")*)"$/
     * @param string $username
     * @param string $groupname
     * @return void
     */
    public function user_should_not_be_member_of_group(string $username, string $groupname): void {
        if ($this->is_member($username, $groupname)) {
            throw new ExpectationException("'$username' is a member of '$groupname'", $this->getSession());
        }
    }

    /**
     * Checks that a user is a member of a group of a given course (group names may repeat after a course copy).
     *
     * @Then /^"([^"]*)" should be a member of group "([^"]*)" in course "([^"]*)"$/
     * @param string $username
     * @param string $groupname
     * @param string $shortname Course short name.
     * @return void
     */
    public function user_should_be_member_of_group_in_course(string $username, string $groupname, string $shortname): void {
        if (!$this->is_member($username, $groupname, $shortname)) {
            throw new ExpectationException("'$username' is not a member of '$groupname' in '$shortname'", $this->getSession());
        }
    }

    /**
     * Checks that a user is not a member of a group of a given course.
     *
     * @Then /^"([^"]*)" should not be a member of group "([^"]*)" in course "([^"]*)"$/
     * @param string $username
     * @param string $groupname
     * @param string $shortname Course short name.
     * @return void
     */
    public function user_should_not_be_member_of_group_in_course(string $username, string $groupname, string $shortname): void {
        if ($this->is_member($username, $groupname, $shortname)) {
            throw new ExpectationException("'$username' is a member of '$groupname' in '$shortname'", $this->getSession());
        }
    }

    /**
     * Copies a course like "Copy course" with "Include user data" and the enrolments of one role kept: the same
     * core API as the copy form (copy_helper) and the asynchronous copy task, run at once.
     *
     * @Given /^the course "([^"]*)" is copied as "([^"]*)" with the user data of role "([^"]*)"$/
     * @param string $shortname Source course.
     * @param string $copyshortname Short name of the copy.
     * @param string $role Role short name whose enrolments are kept.
     * @return void
     */
    public function the_course_is_copied(string $shortname, string $copyshortname, string $role): void {
        global $CFG, $DB, $USER;
        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
        require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
        $course = $DB->get_record('course', ['shortname' => $shortname], '*', MUST_EXIST);
        $roleid = (int) $DB->get_field('role', 'id', ['shortname' => $role], MUST_EXIST);
        $previous = $USER;
        \core\session\manager::set_user(get_admin());
        try {
            \copy_helper::create_copy(\copy_helper::process_formdata((object) [
                'courseid' => $course->id, 'fullname' => $copyshortname, 'shortname' => $copyshortname,
                'category' => $course->category, 'visible' => 1, 'startdate' => time(), 'enddate' => 0, 'idnumber' => '',
                'userdata' => 1, 'role_' . $roleid => $roleid,
            ]));
            while ($task = \core\task\manager::get_next_adhoc_task(time())) {
                if (!$task instanceof \core\task\asynchronous_copy_task) {
                    \core\task\manager::adhoc_task_failed($task);
                    continue;
                }
                ob_start();
                $task->execute();
                ob_end_clean();
                \core\task\manager::adhoc_task_complete($task);
            }
        } finally {
            \core\session\manager::set_user($previous);
        }
        $DB->get_record('course', ['shortname' => $copyshortname], 'id', MUST_EXIST);
    }

    /**
     * Looks up a group membership by user name and group name (and course short name, if given).
     *
     * @param string $username
     * @param string $groupname
     * @param string|null $shortname
     * @return bool
     */
    private function is_member(string $username, string $groupname, ?string $shortname = null): bool {
        global $DB;
        $userid = $DB->get_field('user', 'id', ['username' => $username], MUST_EXIST);
        $conditions = ['name' => $groupname];
        if ($shortname !== null) {
            $conditions['courseid'] = $DB->get_field('course', 'id', ['shortname' => $shortname], MUST_EXIST);
        }
        $groupid = $DB->get_field('groups', 'id', $conditions, MUST_EXIST);
        return $DB->record_exists('groups_members', ['userid' => $userid, 'groupid' => $groupid]);
    }
}
