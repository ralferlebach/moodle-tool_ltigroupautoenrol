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
 * @copyright  2026 Ralf Erlebach
 * @author     Ralf Erlebach - https://github.com/ralferlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_ltigroupautoenrol\form;

use html_writer;
use moodle_url;
use moodleform;
use tool_ltigroupautoenrol\local\course_config;
use tool_ltigroupautoenrol\local\lti_resolver;
use tool_ltigroupautoenrol\local\mapping;

defined('MOODLE_INTERNAL') || die;

global $CFG;
require_once("$CFG->libdir/formslib.php");

/**
 * Settings form of one course.
 *
 * Expected custom data:
 * - course (stdClass): the course,
 * - tools (stdClass[]): LTI 1.3 tools of the course keyed by tool id (server-side allowlist),
 * - groups (stdClass[]): groups of the course keyed by group id (server-side allowlist),
 * - config (course_config): the stored configuration.
 *
 * The form contains one multi-select per allowlisted tool, named groups_<toolid>. It has no
 * hidden tool ids or counters; a submitted select of any other tool is rejected by validation().
 * The enable switch is always present, also without groups or tools, so the feature can be switched off.
 */
class manage_lti_group_auto_enrol_form extends moodleform {
    /** @var string Value core submits for a multi-select without selection. */
    private const EMPTY_MULTISELECT = '_qf__force_multiselect_submission';

    /**
     * Definition
     *
     * @return void
     */
    public function definition(): void {
        $this->auto_group_enrol_form();
    }

    /**
     * Returns the element name of the group select of a tool.
     *
     * @param int $toolid
     * @return string
     */
    public static function get_element_name(int $toolid): string {
        return 'groups_' . $toolid;
    }

    /**
     * Builds the form elements.
     *
     * @return void
     */
    public function auto_group_enrol_form(): void {
        $mform = $this->_form;
        $course = $this->_customdata['course'];
        $tools = $this->_customdata['tools'];
        $groups = $this->_customdata['groups'];
        /** @var course_config $config */
        $config = $this->_customdata['config'];

        $mform->addElement('header', 'enrol', get_string('settings'));
        $mform->setExpanded('enrol');

        $mform->addElement(
            'advcheckbox',
            'enable_enrol',
            get_string('auto_group_form_enable_enrol', 'tool_ltigroupautoenrol')
        );
        $mform->addHelpButton('enable_enrol', 'auto_group_form_enable_enrol', 'tool_ltigroupautoenrol');
        $mform->setDefault('enable_enrol', $config->enabled ? 1 : 0);

        // Without groups nothing can be mapped, but the feature can still be switched off and saved.
        if (empty($groups)) {
            $link = html_writer::link(
                new moodle_url('/group/index.php', ['id' => $course->id]),
                get_string('auto_group_enrol_form_no_group_found', 'tool_ltigroupautoenrol')
            );
            $mform->addElement('static', 'no_group_found', '', $link);
            $this->add_action_buttons();
            return;
        }

        if (empty($tools)) {
            $link = html_writer::link(
                new moodle_url('/enrol/instances.php', ['id' => $course->id]),
                get_string('auto_group_form_no_tools_link', 'tool_ltigroupautoenrol')
            );
            $mform->addElement(
                'static',
                'no_tools_found',
                '',
                get_string('auto_group_form_no_tools', 'tool_ltigroupautoenrol') . ' ' . $link
            );
            $this->add_action_buttons();
            return;
        }

        $options = [];
        foreach ($groups as $group) {
            $options[$group->id] = format_string($group->name, true, ['context' => \context_course::instance($course->id)]);
        }

        $mform->addElement(
            'static',
            'groupslist_intro',
            '',
            get_string('auto_group_form_groupslist_intro', 'tool_ltigroupautoenrol')
        );

        foreach ($tools as $toolid => $tool) {
            $label = get_string('form_groupsfortool', 'tool_ltigroupautoenrol', lti_resolver::get_tool_name($tool));
            if ((int) $tool->status !== ENROL_INSTANCE_ENABLED) {
                $label .= ' ' . get_string('auto_group_form_tool_disabled', 'tool_ltigroupautoenrol');
            }
            $name = self::get_element_name((int) $toolid);
            $select = $mform->addElement('select', $name, $label, $options, ['size' => min(10, max(3, count($options)))]);
            $select->setMultiple(true);
            $mform->setType($name, PARAM_INT);
            $mform->setDefault($name, $config->mapping->get_groupids((int) $toolid));
        }

        $this->add_action_buttons();
    }

    /**
     * Server-side validation against the allowlists of the current course.
     *
     * The select element silently drops unknown option values on export; this check inspects the
     * raw submission so that manipulated ids are reported instead of being ignored.
     *
     * @param array $data
     * @param array $files
     * @return array Errors keyed by element name.
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);
        // Group selects of tools that are not offered for this course (manipulated request) are rejected,
        // not silently dropped.
        if ($this->has_foreign_tool_select()) {
            $errors['enable_enrol'] = get_string('error_unknowntool', 'tool_ltigroupautoenrol');
        }
        foreach (array_keys($this->_customdata['tools']) as $toolid) {
            $name = self::get_element_name((int) $toolid);
            if (!self::is_valid_selection($this->_form->getSubmitValue($name), $this->_customdata['groups'])) {
                $errors[$name] = get_string('error_invalidgroups', 'tool_ltigroupautoenrol');
            }
        }
        return $errors;
    }

    /**
     * Whether the raw submission contains a group select of a tool that is not offered for this course.
     *
     * @return bool
     */
    private function has_foreign_tool_select(): bool {
        foreach (array_keys($this->_form->getSubmitValues()) as $field) {
            if (
                preg_match('/^groups_(\d+)$/', (string) $field, $matches)
                && !isset($this->_customdata['tools'][(int) $matches[1]])
            ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether the raw value of one group select only contains groups of the course (or nothing).
     *
     * @param mixed $raw Raw submitted value.
     * @param array $groups Groups of the course keyed by id.
     * @return bool
     */
    private static function is_valid_selection($raw, array $groups): bool {
        if (self::is_empty_selection($raw)) {
            return true;
        }
        if (!is_array($raw) || count($raw) > mapping::MAX_GROUPS_PER_TOOL) {
            return false;
        }
        foreach ($raw as $groupid) {
            if ($groupid !== self::EMPTY_MULTISELECT && !self::is_course_group($groupid, $groups)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Whether a raw value stands for "nothing selected".
     *
     * Core submits a placeholder (hidden input) for a multi-select with nothing selected; setType(PARAM_INT)
     * cleans it to 0 before validation.
     *
     * @param mixed $raw
     * @return bool
     */
    private static function is_empty_selection($raw): bool {
        return $raw === null || $raw === self::EMPTY_MULTISELECT || $raw === 0 || $raw === '0';
    }

    /**
     * Whether a submitted value is the id of a group of the course.
     *
     * @param mixed $groupid
     * @param array $groups Groups of the course keyed by id.
     * @return bool
     */
    private static function is_course_group($groupid, array $groups): bool {
        return is_scalar($groupid) && ctype_digit((string) $groupid) && isset($groups[(int) $groupid]);
    }

    /**
     * Builds the mapping from validated form data, restricted to the allowlisted tools and groups.
     *
     * @param \stdClass $data Data returned by get_data().
     * @return mapping
     */
    public function get_mapping(\stdClass $data): mapping {
        $toolgroups = [];
        foreach (array_keys($this->_customdata['tools']) as $toolid) {
            $name = self::get_element_name((int) $toolid);
            $toolgroups[(int) $toolid] = array_values(array_map('intval', (array) ($data->$name ?? [])));
        }
        return (new mapping($toolgroups))->restrict_to(
            array_keys($this->_customdata['tools']),
            array_keys($this->_customdata['groups'])
        );
    }
}
