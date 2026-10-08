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
 * Course settings page: enable the feature and map LTI 1.3 tools to groups.
 *
 * GET and POST require login to the course and tool/ltigroupautoenrol:manage (same as the navigation link).
 *
 * @package    tool_ltigroupautoenrol
 * @copyright  2026 Ralf Erlebach
 * @author     Ralf Erlebach - https://github.com/ralferlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use tool_ltigroupautoenrol\form\manage_lti_group_auto_enrol_form;
use tool_ltigroupautoenrol\local\access;
use tool_ltigroupautoenrol\local\backfill_status;
use tool_ltigroupautoenrol\local\config_repository;
use tool_ltigroupautoenrol\local\course_config;
use tool_ltigroupautoenrol\local\invalid_mapping_exception;
use tool_ltigroupautoenrol\local\lti_resolver;

require_once(__DIR__ . '/../../../config.php');

$courseid = required_param('id', PARAM_INT);
$url = new moodle_url('/admin/tool/ltigroupautoenrol/manage_lti_group_auto_enrol.php', ['id' => $courseid]);
$PAGE->set_url($url);

$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
require_login($course);
$coursecontext = access::require_manage($course);

$PAGE->set_context($coursecontext);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('auto_group_form_page_title', 'tool_ltigroupautoenrol'));
$PAGE->set_heading($course->fullname);

$corrupt = false;
try {
    $config = config_repository::get_or_default($course->id);
} catch (invalid_mapping_exception $e) {
    // Show the page with an empty mapping; saving overwrites the corrupt record.
    $corrupt = true;
    $config = course_config::create_default($course->id);
}

// Server-side allowlists: only tools and groups of this course can be configured.
$tools = lti_resolver::get_course_tools($course->id);
$groups = groups_get_all_groups($course->id);

$form = new manage_lti_group_auto_enrol_form($url, [
    'course' => $course,
    'tools' => $tools,
    'groups' => $groups,
    'config' => $config,
]);

if ($form->is_cancelled()) {
    redirect(new moodle_url('/course/view.php', ['id' => $course->id]));
} else if ($data = $form->get_data()) {
    $mapping = $tools ? $form->get_mapping($data) : $config->mapping->restrict_to([], []);
    config_repository::save(new course_config($course->id, !empty($data->enable_enrol), $mapping));
    redirect($url, get_string('changessaved'), null, \core\output\notification::NOTIFY_SUCCESS);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('auto_group_form_page_title', 'tool_ltigroupautoenrol'));

$eventerrors = config_repository::get_event_errors($course->id);
if ($eventerrors) {
    $eventerrors->time = userdate($eventerrors->time);
    echo $OUTPUT->notification(
        get_string('event_errors', 'tool_ltigroupautoenrol', $eventerrors),
        \core\output\notification::NOTIFY_WARNING
    );
}

if ($corrupt) {
    echo $OUTPUT->notification(
        get_string('error_storedmappinginvalid', 'tool_ltigroupautoenrol'),
        \core\output\notification::NOTIFY_ERROR
    );
}

echo html_writer::tag('p', get_string('auto_group_form_intro', 'tool_ltigroupautoenrol'));

$form->display();

if ($config->enabled && !$config->mapping->is_empty()) {
    echo $OUTPUT->box_start('generalbox', 'tool_ltigroupautoenrol_backfill');
    echo $OUTPUT->heading(get_string('backfill_heading', 'tool_ltigroupautoenrol'), 3);
    echo html_writer::tag('p', get_string('backfill_intro', 'tool_ltigroupautoenrol'));
    $status = backfill_status::get_message($course->id);
    if ($status) {
        echo $OUTPUT->notification($status[0], $status[1], false);
    }
    echo $OUTPUT->single_button(
        new moodle_url('/admin/tool/ltigroupautoenrol/backfill.php', ['id' => $course->id]),
        get_string('backfill_preview', 'tool_ltigroupautoenrol'),
        'get'
    );
    echo $OUTPUT->box_end();
}

echo $OUTPUT->footer();
