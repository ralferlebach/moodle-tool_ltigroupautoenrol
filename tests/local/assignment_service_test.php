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

namespace tool_ltigroupautoenrol\local;

/**
 * Tests of the backfill (preview and execution) of the assignment service.
 *
 * Existing participants are created while the feature is disabled, so the enrolment observer
 * does not assign them; the backfill then has to pick them up.
 *
 * @package    tool_ltigroupautoenrol
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @author     Ralf Erlebach - https://github.com/ralferlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_ltigroupautoenrol\local\assignment_service
 * @covers     \tool_ltigroupautoenrol\local\lti_resolver
 * @covers     \tool_ltigroupautoenrol\local\assignment_result
 */
final class assignment_service_test extends \advanced_testcase {
    /** @var \tool_ltigroupautoenrol_generator */
    private $plugingenerator;

    /** @var \stdClass */
    private $course;

    /** @var \stdClass Mapped tool 1. */
    private $tool1;

    /** @var \stdClass Mapped tool 2. */
    private $tool2;

    /** @var \stdClass Unmapped tool. */
    private $tool3;

    /** @var int[] Group ids A, B, C. */
    private $groups;

    /** @var \stdClass[] Users by label. */
    private $users;

    /**
     * Creates a course with three tools, three groups and a set of existing participants.
     *
     * @return void
     */
    protected function setUp(): void {
        global $CFG, $DB;
        parent::setUp();
        $this->resetAfterTest(true);
        require_once($CFG->dirroot . '/group/lib.php');
        $gen = $this->getDataGenerator();
        $this->plugingenerator = $gen->get_plugin_generator('tool_ltigroupautoenrol');
        $this->course = $gen->create_course();
        $this->tool1 = $this->plugingenerator->create_lti_tool($this->course->id);
        $this->tool2 = $this->plugingenerator->create_lti_tool($this->course->id);
        $this->tool3 = $this->plugingenerator->create_lti_tool($this->course->id);
        foreach (['A', 'B', 'C'] as $name) {
            $this->groups[$name] = $gen->create_group(['courseid' => $this->course->id, 'name' => $name])->id;
        }
        foreach (['t1a', 't1b', 't2', 't3', 'manual', 'suspended', 'expired', 'deleted'] as $label) {
            $this->users[$label] = $gen->create_user();
        }
        $this->plugingenerator->enrol_via_lti($this->tool1, $this->users['t1a']->id);
        $this->plugingenerator->enrol_via_lti($this->tool1, $this->users['t1b']->id);
        $this->plugingenerator->enrol_via_lti($this->tool2, $this->users['t2']->id);
        $this->plugingenerator->enrol_via_lti($this->tool3, $this->users['t3']->id);
        $gen->enrol_user($this->users['manual']->id, $this->course->id, 'student', 'manual');
        $this->plugingenerator->enrol_via_lti($this->tool1, $this->users['suspended']->id, ENROL_USER_SUSPENDED);
        $this->plugingenerator->enrol_via_lti(
            $this->tool1,
            $this->users['expired']->id,
            ENROL_USER_ACTIVE,
            time() - 2 * DAYSECS,
            time() - DAYSECS
        );
        $this->plugingenerator->enrol_via_lti($this->tool1, $this->users['deleted']->id);
        // Flag the user as deleted without delete_user(), which would also remove the enrolment,
        // so that the "u.deleted = 0" condition itself is exercised.
        $DB->set_field('user', 'deleted', 1, ['id' => $this->users['deleted']->id]);
        // User t1a already is in group A.
        groups_add_member($this->groups['A'], $this->users['t1a']->id);
    }

    /**
     * Stores the mapping used by most tests: tool 1 => A, B; tool 2 => C.
     *
     * @param bool $enabled
     * @return course_config
     */
    private function configure(bool $enabled = true): course_config {
        return $this->plugingenerator->create_config($this->course->id, [
            $this->tool1->id => [$this->groups['A'], $this->groups['B']],
            $this->tool2->id => [$this->groups['C']],
        ], $enabled);
    }

    /**
     * Preview counts active LTI enrolments and missing memberships and writes nothing.
     */
    public function test_preview_is_read_only_and_counts(): void {
        global $DB;
        $config = $this->configure();
        $before = $DB->count_records('groups_members');

        $rows = assignment_service::preview($config);

        $this->assertSame($before, $DB->count_records('groups_members'));
        $this->assertSame([(int) $this->tool1->id, (int) $this->tool2->id], array_keys($rows));
        $this->assertSame(2, $rows[$this->tool1->id]->eligible);
        // A: t1b missing (t1a already member); B: t1a and t1b missing.
        $this->assertSame(3, $rows[$this->tool1->id]->missing);
        $this->assertSame(1, $rows[$this->tool2->id]->eligible);
        $this->assertSame(1, $rows[$this->tool2->id]->missing);
    }

    /**
     * Backfill adds exactly the missing memberships, excluding other tools, methods and inactive enrolments.
     */
    public function test_backfill_assigns_existing_participants(): void {
        $config = $this->configure();

        $result = assignment_service::backfill($config, 1);

        $this->assertSame(
            ['eligible' => 3, 'alreadymember' => 1, 'added' => 4, 'skipped' => 0, 'errors' => 0],
            $result->to_array()
        );
        $g = $this->groups;
        $u = $this->users;
        $this->assertTrue(groups_is_member($g['A'], $u['t1b']->id));
        $this->assertTrue(groups_is_member($g['B'], $u['t1a']->id));
        $this->assertTrue(groups_is_member($g['B'], $u['t1b']->id));
        $this->assertTrue(groups_is_member($g['C'], $u['t2']->id));
        foreach (['t3', 'manual', 'suspended', 'expired', 'deleted'] as $label) {
            foreach ($g as $groupid) {
                $this->assertFalse(groups_is_member($groupid, $u[$label]->id), "$label must not be assigned");
            }
        }
        $this->assertSame(0, assignment_service::preview($config)[$this->tool1->id]->missing);
    }

    /**
     * A second run changes nothing (idempotency).
     */
    public function test_backfill_is_idempotent(): void {
        global $DB;
        $config = $this->configure();
        assignment_service::backfill($config);
        $count = $DB->count_records('groups_members');

        $result = assignment_service::backfill($config);

        $this->assertSame(0, $result->added);
        $this->assertSame(5, $result->alreadymember);
        $this->assertSame($count, $DB->count_records('groups_members'));
    }

    /**
     * A disabled configuration does nothing.
     */
    public function test_backfill_disabled(): void {
        global $DB;
        $config = $this->configure(false);
        $count = $DB->count_records('groups_members');

        $this->assertSame(0, assignment_service::backfill($config)->eligible);
        $this->assertSame($count, $DB->count_records('groups_members'));
    }

    /**
     * A tool of another course is never used, even with a manipulated configuration object.
     */
    public function test_tool_of_other_course_is_rejected(): void {
        $othercourse = $this->getDataGenerator()->create_course();
        $foreigntool = $this->plugingenerator->create_lti_tool($othercourse->id);
        $config = new course_config($this->course->id, true, new mapping([$foreigntool->id => [$this->groups['A']]]));

        $result = assignment_service::assign_user($config, $foreigntool, $this->users['t3']->id);
        $this->assertSame(1, $result->errors);
        $this->assertFalse(groups_is_member($this->groups['A'], $this->users['t3']->id));
        $this->assertSame([], assignment_service::preview($config));
        $this->assertSame(0, assignment_service::backfill($config)->eligible);
    }

    /**
     * A user enrolled through two tools gets the groups of both.
     */
    public function test_multiple_enrolments_of_one_user(): void {
        $this->plugingenerator->enrol_via_lti($this->tool2, $this->users['t1b']->id);
        $config = $this->configure();

        assignment_service::backfill($config);

        foreach (['A', 'B', 'C'] as $name) {
            $this->assertTrue(groups_is_member($this->groups[$name], $this->users['t1b']->id));
        }
    }
}
