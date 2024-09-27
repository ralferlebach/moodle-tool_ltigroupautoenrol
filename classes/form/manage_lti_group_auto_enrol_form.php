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
 * Manage ltigroupautoenrol form
 *
 * @package    tool_ltigroupautoenrol
 * @copyright  2024 Ralf Erlebach
 * @author     Ralf Erlebach - https://github.com/ralferlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_ltigroupautoenrol\form;

use html_writer;
use moodle_url;
use moodleform;

defined('MOODLE_INTERNAL') || die;

global $CFG;
require_once("$CFG->libdir/formslib.php");

/**
 * Class manage_auto_group_enrol_form
 *
 * @package    tool_ltigroupautoenrol
 * @copyright  2024 Ralf Erlebach
 * @author     Ralf Erlebach - https://github.com/ralferlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class manage_lti_group_auto_enrol_form extends moodleform {

    /**
     * Definition
     *
     * @return void
     */
    public function definition(): void {
        $this->auto_group_enrol_form();
        $this->add_action_buttons();
    }

    /**
     * Displays form
     *
     * @return void
     * @throws \coding_exception
     * @throws \dml_exception
     */
    public function auto_group_enrol_form(): void {
        global $DB;
        $mform = &$this->_form;
        $course = $this->_customdata['course'];
        $allgroupscourse = groups_get_all_groups($course->id);

        $mform->addElement('header', 'enrol', get_string('settings'));

        // Group(s) must be created first.
        if (empty($allgroupscourse)) {

            $groupurl = new moodle_url('/group/index.php', ['id' => $course->id]);
            $link = html_writer::link(
                $groupurl,
                get_string('auto_group_enrol_form_no_group_found', 'tool_ltigroupautoenrol')
            );
            $mform->addElement('static', 'no_group_found', '', $link);

            return;
        }

        $instance = $DB->get_record('tool_ltigroupautoenrol', ['courseid' => $course->id]);
        $mform->addElement(
            'checkbox',
            'enable_enrol',
            get_string('auto_group_form_enable_enrol', 'tool_ltigroupautoenrol')
        );
        $mform->setDefault('enable_enrol', $instance->enable_enrol ?? 0);

        $fields = [];
        foreach ($allgroupscourse as $group) {
            $fields[$group->id] = $group->name;
        }

        $ltitoolcount = \enrol_lti\helper::count_lti_tools([
            'courseid' => $course->id,
            'ltiversion' => 'LTI-1p3',
            ]);

        $ltitools = \enrol_lti\helper::get_lti_tools([
            'courseid' => $course->id,
            'ltiversion' => 'LTI-1p3',
            ]);

        $ltitoolgroup = json_decode($instance->settings, true);

        $i = 0;
        foreach ($ltitools as $toolid => $ltitool) {

            $mform->addElement('hidden', 'ltitoolid_'.$i, $ltitool->id);
            $mform->setType('ltitoolid_'.$i, PARAM_TEXT);

            $select = $mform->addElement(
                'select',
                'groupslist_'.$i,
                get_string('auto_group_form_groupslist', 'tool_ltigroupautoenrol'). $ltitool->name,
                $fields
            );
            $select->setMultiple(true);
            $mform->disabledIf('groupslist_'.$i, 'enable_enrol');
            $mform->setDefault('groupslist_'.$i, $ltitoolgroup[$toolid] ?? []);

            $i++;
        }
        $mform->addElement('hidden', 'ltitoolcount', $ltitoolcount);
        $mform->setType('ltitoolcount', PARAM_TEXT);
    }
}
