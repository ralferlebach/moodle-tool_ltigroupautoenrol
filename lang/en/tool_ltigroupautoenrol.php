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
 * EN language file for tool_ltigroupautoenrol
 *
 * @package    tool_ltigroupautoenrol
 * @copyright  2024 Ralf Erlebach
 * @author     Ralf Erlebach - https://github.com/ralferlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die;

$string['auto_group_enrol_form_no_group_found'] = 'Create groups first!';
$string['auto_group_form_enable_enrol'] = 'Enable automatic enrolment in groups for this course';
$string['auto_group_form_enable_enrol_help'] = 'When enabled, users who are newly enrolled through one of the LTI 1.3 tools below are added to the groups selected for that tool. Assignment is additive: users are never removed from groups, also not when you disable this option. Participants who were enrolled before can be assigned with "Assign existing LTI participants" after saving.';
$string['auto_group_form_groupslist_intro'] = 'Select one or more groups per LTI tool. Hold Ctrl (Cmd on macOS) or use Shift with the arrow keys to select several groups. The selection is kept when automatic enrolment is disabled.';
$string['auto_group_form_intro'] = 'Users enrolled in this course through a shared LTI 1.3 tool can be added to groups automatically, depending on the tool they come from.';
$string['auto_group_form_no_tools'] = 'This course does not publish any LTI 1.3 tool yet, so there is nothing to map.';
$string['auto_group_form_no_tools_link'] = 'Manage enrolment methods';
$string['auto_group_form_page_title'] = 'LTI-enrol settings';
$string['auto_group_form_tool_disabled'] = '(enrolment method disabled)';
$string['backfill_changed'] = 'The configuration was changed after the preview. Please review the preview again and confirm.';
$string['backfill_col_eligible'] = 'Active LTI enrolments';
$string['backfill_col_groups'] = 'Groups';
$string['backfill_col_missing'] = 'Missing group memberships';
$string['backfill_col_tool'] = 'LTI tool';
$string['backfill_confirm'] = 'Group memberships to be added: {$a}. Users are only added to groups, never removed. Continue?';
$string['backfill_heading'] = 'Assign existing LTI participants';
$string['backfill_intro'] = 'Participants who were enrolled through an LTI tool before the current configuration was saved are not assigned automatically. The preview shows how many active LTI enrolments are affected. Suspended, expired and not yet started enrolments are excluded.';
$string['backfill_lastrun'] = 'Last run: {$a->time}. Added: {$a->added}, already member: {$a->alreadymember}, skipped: {$a->skipped}, errors: {$a->errors}.';
$string['backfill_notenabled'] = 'Automatic enrolment in groups is not enabled for this course, or no LTI tool is mapped to a group.';
$string['backfill_nothingtodo'] = 'All active LTI participants are already members of their configured groups.';
$string['backfill_pending'] = 'An assignment run for this course is already scheduled. It will be processed by the next cron run.';
$string['backfill_preview'] = 'Assign existing LTI participants …';
$string['backfill_queued'] = 'The assignment of existing LTI participants has been scheduled and will be processed by the next cron run.';
$string['backfill_run'] = 'Assign participants';
$string['backfill_status_queued'] = 'Assignment of existing LTI participants scheduled on {$a->time}. It will be processed by the next cron run.';
$string['backfill_status_running'] = 'Assignment of existing LTI participants is running (started {$a->time}).';
$string['backfill_status_skipped'] = 'The assignment scheduled before {$a->time} was not carried out because the configuration was changed or disabled afterwards. Please check the preview and confirm again.';
$string['backfill_tablecaption'] = 'Preview per LTI tool';
$string['coursemenu_item'] = 'LTI-enrol in groups';
$string['error_backfilllocked'] = 'Another assignment run for course {$a} is in progress.';
$string['error_backfillpartial'] = '{$a} group memberships could not be added; the run will be retried.';
$string['error_invalidgroups'] = 'Only groups of this course can be selected.';
$string['error_invalidmapping'] = 'The LTI tool to group mapping is invalid.';
$string['error_storedmappinginvalid'] = 'The stored LTI tool to group mapping of this course is invalid and is ignored. Save the form to replace it.';
$string['error_upgradebackup'] = 'The upgrade backup file {$a} could not be written.';
$string['event_errors'] = 'Since {$a->time}, {$a->count} group membership(s) of new LTI enrolments could not be added. Use "Assign existing LTI participants" to assign the affected participants; this message disappears after a complete run.';
$string['form_groupsfortool'] = 'Groups for LTI tool: {$a}';
$string['ltigroupautoenrol:manage'] = 'Configure automatic group assignment of LTI participants';
$string['menu_auto_groups'] = 'LTI-enrol in groups';
$string['pluginname'] = 'Automatic enrolment in groups by LTI tool';
$string['privacy:reason'] = 'The plugin stores only course settings (which LTI tool maps to which groups) and the status and counters of the last assignment of existing LTI participants. It stores no personal data. Group memberships it adds are stored and exported by the Moodle groups subsystem (core_group).';
$string['task_backfill'] = 'Assign existing LTI participants to groups';
$string['tool_fallbackname'] = 'LTI tool {$a}';
