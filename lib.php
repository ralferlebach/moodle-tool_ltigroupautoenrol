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
 * Lib functions
 *
 * @package    tool_ltigroupautoenrol
 * @copyright  2024 Ralf Erlebach
 * @author     Ralf Erlebach - https://github.com/ralferlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Extend the navigation for course.
 *
 * The link is shown in the "Users" node of real courses (not the site course) and only to users
 * who hold tool/ltigroupautoenrol:manage, the same capability the target page requires.
 *
 * @param navigation_node $navigation
 * @param stdClass $course
 * @param context $context
 *
 * @return void
 */
function tool_ltigroupautoenrol_extend_navigation_course(navigation_node $navigation, stdClass $course, context $context): void {
    if (!$context instanceof context_course || (int) $context->instanceid !== (int) $course->id) {
        return;
    }
    if (!\tool_ltigroupautoenrol\local\access::can_manage($course)) {
        return;
    }

    $usermenu = $navigation->get('users');
    if (!$usermenu) {
        return;
    }

    $url = new moodle_url('/admin/tool/ltigroupautoenrol/manage_lti_group_auto_enrol.php', ['id' => $course->id]);
    $usermenu->add(
        get_string('menu_auto_groups', 'tool_ltigroupautoenrol'),
        $url,
        navigation_node::TYPE_SETTING,
        null,
        'tool_ltigroupautoenrol'
    );
}
