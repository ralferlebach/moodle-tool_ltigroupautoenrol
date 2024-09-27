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
 * Manage auto group enrolment
 *
 * Params page for auto group enrollment as defined by Comete
 *
 * @package    tool_ltigroupautoenrol
 * @copyright  2024 Ralf Erlebach
 * @author     Ralf Erlebach - https://github.com/ralferlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use enrol_lti\helper;
require_once('../../../config.php');

defined('MOODLE_INTERNAL') || die;

$courseid = required_param('id', PARAM_INT);
$url = new moodle_url('/admin/tool/ltigroupautoenrol/manage_lti_group_auto_enrol.php', ['id' => $courseid]);
$PAGE->set_url($url);

$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);

require_login($course);

$coursecontext = context_course::instance($course->id);
require_capability('moodle/course:update', $coursecontext);

$PAGE->set_context($coursecontext);
$PAGE->set_pagelayout('admin');
$PAGE->set_heading($course->fullname);

$form = new \tool_ltigroupautoenrol\form\manage_lti_group_auto_enrol_form($url, [
    'course' => $course,
]);

if ($form->is_cancelled()) {
    // Form is cancelled, return to course.
    redirect(new moodle_url('/course/view.php', ['id' => $course->id]));
} else if ($data = $form->get_data()) {
    // Form submitted, now get the data.

    if (empty($data->enable_enrol)) {
        $data->enable_enrol = 0;
    }

    $ltigroupautoenrol = new stdClass();
    $ltigroupautoenrol->courseid = $course->id;
    $ltigroupautoenrol->enable_enrol = $data->enable_enrol;

    if (isset($data->ltitoolcount)) {

        $ltitoolcourses = [];
        for ($i = 0; $i < $data->ltitoolcount; $i++) {
            if (isset($data->{"ltitoolid_".$i}) && isset($data->{"groupslist_".$i})) {
                $ltitoolcourses[$data->{"ltitoolid_".$i}] = $data->{"groupslist_".$i};
            }
        }
        $ltigroupautoenrol->settings = json_encode($ltitoolcourses);
    }

    $record = $DB->get_record('tool_ltigroupautoenrol', ['courseid' => $course->id], 'id');
    if (!$record) {
        $DB->insert_record('tool_ltigroupautoenrol', $ltigroupautoenrol);
    } else {
        $ltigroupautoenrol->id = $record->id;
        $DB->update_record('tool_ltigroupautoenrol', $ltigroupautoenrol);
    }

    redirect(new moodle_url('/admin/tool/ltigroupautoenrol/manage_lti_group_auto_enrol.php', ['id' => $course->id]));
}

echo $OUTPUT->header();

echo $OUTPUT->heading(get_string('auto_group_form_page_title', 'tool_ltigroupautoenrol'));

echo $form->render();
echo $OUTPUT->footer();
