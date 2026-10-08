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
 * Backfill: preview (read-only) and confirmed queueing of the assignment of existing LTI participants.
 *
 * GET shows the preview and never writes. POST (sesskey, confirmed mapping fingerprint) queues
 * an adhoc task; if the configuration changed after the preview, the user has to confirm again.
 *
 * @package    tool_ltigroupautoenrol
 * @copyright  2026 Ralf Erlebach
 * @author     Ralf Erlebach - https://github.com/ralferlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use tool_ltigroupautoenrol\local\access;
use tool_ltigroupautoenrol\local\assignment_service;
use tool_ltigroupautoenrol\local\backfill_controller;
use tool_ltigroupautoenrol\local\backfill_status;
use tool_ltigroupautoenrol\local\config_repository;
use tool_ltigroupautoenrol\local\invalid_mapping_exception;
use tool_ltigroupautoenrol\task\backfill_task;

require_once(__DIR__ . '/../../../config.php');

$courseid = required_param('id', PARAM_INT);
$url = new moodle_url('/admin/tool/ltigroupautoenrol/backfill.php', ['id' => $courseid]);
$settingsurl = new moodle_url('/admin/tool/ltigroupautoenrol/manage_lti_group_auto_enrol.php', ['id' => $courseid]);
$PAGE->set_url($url);

$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
require_login($course);
$context = access::require_manage($course);

$PAGE->set_context($context);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('backfill_heading', 'tool_ltigroupautoenrol'));
$PAGE->set_heading($course->fullname);

try {
    $config = config_repository::get_or_default($course->id);
} catch (invalid_mapping_exception $e) {
    redirect(
        $settingsurl,
        get_string('error_storedmappinginvalid', 'tool_ltigroupautoenrol'),
        null,
        \core\output\notification::NOTIFY_ERROR
    );
}
$pending = backfill_task::is_pending($course->id);

if (data_submitted()) {
    require_sesskey();
    $outcome = backfill_controller::confirm(
        $course->id,
        required_param('hash', PARAM_ALPHANUM),
        required_param('previewtime', PARAM_INT)
    );
    switch ($outcome) {
        case backfill_controller::CHANGED:
            redirect(
                $url,
                get_string('backfill_changed', 'tool_ltigroupautoenrol'),
                null,
                \core\output\notification::NOTIFY_WARNING
            );
            break;
        case backfill_controller::DISABLED:
            redirect($settingsurl);
            break;
        default:
            redirect(
                $settingsurl,
                get_string('backfill_queued', 'tool_ltigroupautoenrol'),
                null,
                \core\output\notification::NOTIFY_SUCCESS
            );
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('backfill_heading', 'tool_ltigroupautoenrol'));

if (!$config->enabled || $config->mapping->is_empty()) {
    echo $OUTPUT->notification(
        get_string('backfill_notenabled', 'tool_ltigroupautoenrol'),
        \core\output\notification::NOTIFY_INFO
    );
    echo $OUTPUT->continue_button($settingsurl);
    echo $OUTPUT->footer();
    exit;
}

echo html_writer::tag('p', get_string('backfill_intro', 'tool_ltigroupautoenrol'));

$rows = assignment_service::preview($config);
$table = new html_table();
$table->caption = get_string('backfill_tablecaption', 'tool_ltigroupautoenrol');
$table->head = [
    get_string('backfill_col_tool', 'tool_ltigroupautoenrol'),
    get_string('backfill_col_groups', 'tool_ltigroupautoenrol'),
    get_string('backfill_col_eligible', 'tool_ltigroupautoenrol'),
    get_string('backfill_col_missing', 'tool_ltigroupautoenrol'),
];
$table->data = [];
$totalmissing = 0;
foreach ($rows as $row) {
    $groupnames = array_map(fn($g) => format_string($g->name, true, ['context' => $context]), $row->groups);
    $table->data[] = [$row->name, implode(', ', $groupnames), $row->eligible, $row->missing];
    $totalmissing += $row->missing;
}
// Responsive wrapper: the table scrolls on its own at narrow widths instead of the page (WCAG 1.4.10).
echo html_writer::div(html_writer::table($table), 'table-responsive');

$status = backfill_status::get_message($course->id);
if ($status) {
    echo $OUTPUT->notification($status[0], $status[1], false);
}

if ($pending) {
    echo $OUTPUT->notification(
        get_string('backfill_pending', 'tool_ltigroupautoenrol'),
        \core\output\notification::NOTIFY_INFO
    );
    echo $OUTPUT->continue_button($settingsurl);
} else if ($totalmissing === 0) {
    echo $OUTPUT->notification(
        get_string('backfill_nothingtodo', 'tool_ltigroupautoenrol'),
        \core\output\notification::NOTIFY_SUCCESS
    );
    echo $OUTPUT->continue_button($settingsurl);
} else {
    echo $OUTPUT->confirm(
        get_string('backfill_confirm', 'tool_ltigroupautoenrol', $totalmissing),
        new single_button(
            new moodle_url($url, [
                'hash' => $config->mapping->get_hash(),
                'previewtime' => time(),
                'sesskey' => sesskey(),
            ]),
            get_string('backfill_run', 'tool_ltigroupautoenrol'),
            'post',
            single_button::BUTTON_PRIMARY
        ),
        $settingsurl
    );
}

echo $OUTPUT->footer();
