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
 * Manage form
 *
 * @package    tool_ltigroupautoenrol
 * @copyright  2016 Pascal
 * @author     Pascal M - https://github.com/pascal-my
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
 * @copyright  2016 Pascal
 * @author     Pascal M - https://github.com/pascal-my
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
        $allltideployments = groups_get_all_groups($course->id);

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

        $mform->addElement(
            'checkbox',
            'use_groupslist',
            get_string('auto_group_form_usegroupslist', 'tool_ltigroupautoenrol')
        );
        $mform->disabledIf('use_groupslist', 'enable_enrol');

        $fields = [];
        foreach ($allgroupscourse as $group) {
            $fields[$group->id] = $group->name;
        }

        $select = $mform->addElement(
            'select',
            'groupslist',
            get_string('auto_group_form_groupslist', 'tool_ltigroupautoenrol'),
            $fields
        );
        $select->setMultiple(true);

        $mform->disabledIf('groupslist', 'enable_enrol');
        $mform->disabledIf('groupslist', 'use_groupslist');

        $mform->setDefault('use_groupslist', $instance->use_groupslist ?? 0);
        $mform->setDefault('groupslist', explode(",", $instance->groupslist ?? ''));
        $mform->setDefault('enable_enrol', $instance->enable_enrol ?? 0);

    }

}
