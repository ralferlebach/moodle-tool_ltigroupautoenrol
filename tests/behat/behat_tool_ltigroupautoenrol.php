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
     * Looks up a group membership by user name and group name.
     *
     * @param string $username
     * @param string $groupname
     * @return bool
     */
    private function is_member(string $username, string $groupname): bool {
        global $DB;
        $userid = $DB->get_field('user', 'id', ['username' => $username], MUST_EXIST);
        $groupid = $DB->get_field('groups', 'id', ['name' => $groupname], MUST_EXIST);
        return $DB->record_exists('groups_members', ['userid' => $userid, 'groupid' => $groupid]);
    }
}
