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

namespace tool_ltigroupautoenrol;

use core_privacy\tests\provider_testcase;
use tool_ltigroupautoenrol\task\backfill_task;

/**
 * Tests that back the null_provider decision with the actual data flows.
 *
 * Data flow inventory (see README, section "Data privacy"):
 * - plugin table: course id, enabled flag, tool id => group ids, time and counters of the
 *   last backfill run (no user data),
 * - group memberships: core tables, created via the group API, exported/deleted by core_group,
 * - backfill task custom data: course id, mapping fingerprint and preview time only,
 * - upgrade backups in moodledata: course settings only,
 * - events: the plugin triggers no events of its own; core_group triggers group_member_added for
 *   every added membership, logged by the core logstore (covered by the logstore privacy provider),
 * - logs/debugging: course ids and counters only.
 *
 * @package    tool_ltigroupautoenrol
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @author     Ralf Erlebach - https://github.com/ralferlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_ltigroupautoenrol\privacy\provider
 */
final class privacy_provider_test extends provider_testcase {
    /**
     * The provider is a null provider with an existing reason string.
     */
    public function test_null_provider(): void {
        $this->assertContains(\core_privacy\local\metadata\null_provider::class, class_implements(privacy\provider::class));
        $reason = privacy\provider::get_reason();
        $this->assertTrue(get_string_manager()->string_exists($reason, 'tool_ltigroupautoenrol'));
    }

    /**
     * The plugin table has no column that could hold a user reference.
     */
    public function test_plugin_table_holds_no_user_columns(): void {
        global $DB;
        $columns = array_keys($DB->get_columns('tool_ltigroupautoenrol'));
        sort($columns);
        $this->assertSame(
            ['backfill_result', 'backfill_time', 'courseid', 'enable_enrol', 'event_errors', 'event_errortime', 'id', 'settings'],
            $columns
        );
    }

    /**
     * After event assignment and backfill, user ids exist only in core group memberships,
     * neither in the plugin table nor in the backfill task payload.
     */
    public function test_assignment_persists_no_user_data(): void {
        global $DB;
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $plugingen = $gen->get_plugin_generator('tool_ltigroupautoenrol');
        $course = $gen->create_course();
        $tool = $plugingen->create_lti_tool($course->id);
        $groupid = $gen->create_group(['courseid' => $course->id])->id;
        $config = $plugingen->create_config($course->id, [$tool->id => [$groupid]]);
        $user = $gen->create_user(['idnumber' => 'privacy-probe']);
        $plugingen->enrol_via_lti($tool, $user->id);

        $task = backfill_task::create($course->id, $config->mapping->get_hash());
        $this->assertSame(['courseid', 'hash', 'previewtime'], array_keys((array) $task->get_custom_data()));

        $this->assertTrue(groups_is_member($groupid, $user->id));
        $row = $DB->get_record('tool_ltigroupautoenrol', ['courseid' => $course->id], '*', MUST_EXIST);
        $this->assertSame([(int) $tool->id => [(int) $groupid]], json_decode($row->settings, true));
        $membership = $DB->get_record('groups_members', ['groupid' => $groupid, 'userid' => $user->id], '*', MUST_EXIST);
        $this->assertSame('', (string) $membership->component, 'Memberships stay manageable by teachers and core privacy');
    }
}
