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

use tool_ltigroupautoenrol\local\config_repository;
use tool_ltigroupautoenrol\local\course_config;
use tool_ltigroupautoenrol\local\lti_resolver;
use tool_ltigroupautoenrol\local\mapping;

/**
 * Course backup/restore and duplication carry the mapping over with translated tool and group ids.
 *
 * @package    tool_ltigroupautoenrol
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @author     Ralf Erlebach - https://github.com/ralferlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \backup_tool_ltigroupautoenrol_plugin
 * @covers     \restore_tool_ltigroupautoenrol_plugin
 */
final class backup_restore_test extends \advanced_testcase {
    /** @var \stdClass Source course. */
    private $course;

    /**
     * Loads the backup libraries and creates a configured course with two tools and three groups.
     *
     * @return void
     */
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
        require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
        require_once($CFG->dirroot . '/course/externallib.php');
        $this->resetAfterTest();
        $this->setAdminUser();
        // Sites using this plugin have enrol_lti enabled; core restores enrolment instances of enabled plugins only.
        \core\plugininfo\enrol::enable_plugin('lti', 1);
        $gen = $this->getDataGenerator();
        $plugingen = $gen->get_plugin_generator('tool_ltigroupautoenrol');
        $this->course = $gen->create_course(['shortname' => 'SRC']);
        $alpha = $plugingen->create_lti_tool($this->course->id, ['name' => 'Alpha']);
        $beta = $plugingen->create_lti_tool($this->course->id, ['name' => 'Beta']);
        $groups = [];
        foreach (['Consumer A', 'Consumer B', 'Unmapped'] as $name) {
            $groups[$name] = (int) $gen->create_group(['courseid' => $this->course->id, 'name' => $name])->id;
        }
        $plugingen->create_config($this->course->id, [
            $alpha->id => [$groups['Consumer A'], $groups['Consumer B']],
            $beta->id => [$groups['Consumer B']],
        ]);
    }

    /**
     * Returns the stored mapping of a course by tool name and group names.
     *
     * @param int $courseid
     * @return array<string, string[]>
     */
    private function mapping_by_name(int $courseid): array {
        $config = config_repository::get($courseid);
        $this->assertNotNull($config, 'configuration restored');
        $tools = lti_resolver::get_course_tools($courseid);
        $groups = groups_get_all_groups($courseid);
        $result = [];
        foreach ($config->mapping->to_array() as $toolid => $groupids) {
            $this->assertArrayHasKey($toolid, $tools, 'tool id belongs to the restored course');
            $names = [];
            foreach ($groupids as $groupid) {
                $this->assertArrayHasKey($groupid, $groups, 'group id belongs to the restored course');
                $names[] = $groups[$groupid]->name;
            }
            sort($names);
            $result[lti_resolver::get_tool_name($tools[$toolid])] = $names;
        }
        ksort($result);
        return $result;
    }

    /**
     * Backs up the source course and restores it into a new course.
     *
     * @param bool $withenrolmentmethods Whether the enrolment methods (and with them the LTI tools) are restored.
     * @return int Id of the new course.
     */
    private function backup_and_restore(bool $withenrolmentmethods): int {
        global $CFG, $USER;
        $bc = new \backup_controller(
            \backup::TYPE_1COURSE,
            $this->course->id,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $USER->id
        );
        // Enrolment methods (and with them the LTI tools) are always part of a course backup.
        $bc->get_plan()->get_setting('users')->set_value(false);
        $bc->execute_plan();
        $file = $bc->get_results()['backup_destination'];
        $bc->destroy();

        $dir = 'tool_ltigroupautoenrol_' . uniqid();
        $file->extract_to_pathname(get_file_packer('application/vnd.moodle.backup'), $CFG->tempdir . '/backup/' . $dir);
        $newcourseid = \restore_dbops::create_new_course('Restored', 'RESTORED' . uniqid(), $this->course->category);
        $rc = new \restore_controller(
            $dir,
            $newcourseid,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $USER->id,
            \backup::TARGET_NEW_COURSE
        );
        $rc->get_plan()->get_setting('enrolments')->set_value(
            $withenrolmentmethods ? \backup::ENROL_ALWAYS : \backup::ENROL_NEVER
        );
        $this->assertTrue($rc->execute_precheck());
        $rc->execute_plan();
        $rc->destroy();
        return (int) $newcourseid;
    }

    /**
     * Backup and restore into a new course: tool and group ids point to the restored tools and groups.
     */
    public function test_backup_and_restore(): void {
        $newcourseid = $this->backup_and_restore(true);

        $this->assertNotEquals($this->course->id, $newcourseid);
        $this->assertSame(
            ['Alpha' => ['Consumer A', 'Consumer B'], 'Beta' => ['Consumer B']],
            $this->mapping_by_name($newcourseid)
        );
        $this->assertTrue(config_repository::get($newcourseid)->enabled);
        // The source course is unchanged.
        $this->assertSame(
            ['Alpha' => ['Consumer A', 'Consumer B'], 'Beta' => ['Consumer B']],
            $this->mapping_by_name((int) $this->course->id)
        );
    }

    /**
     * Without the enrolment methods no LTI tool is restored, so no configuration is created.
     */
    public function test_restore_without_tools_creates_no_configuration(): void {
        $newcourseid = $this->backup_and_restore(false);
        $this->assertSame([], lti_resolver::get_course_tools($newcourseid));
        $this->assertNull(config_repository::get($newcourseid));
    }

    /**
     * Course duplication (same site) carries the mapping over. Core copies enrolment methods, and with them the LTI
     * tools, only when users are included; without them the copy has no LTI tool and therefore no mapping.
     */
    public function test_duplicate_course(): void {
        $result = \core_course_external::duplicate_course(
            $this->course->id,
            'Duplicate',
            'DUP' . uniqid(),
            $this->course->category,
            1,
            [['name' => 'users', 'value' => 1]]
        );
        $this->assertSame(
            ['Alpha' => ['Consumer A', 'Consumer B'], 'Beta' => ['Consumer B']],
            $this->mapping_by_name((int) $result['id'])
        );
    }

    /**
     * Restoring into an existing course with its own configuration ("overwrite course configuration") keeps its
     * enabled flag and adds the restored pairs.
     */
    public function test_restore_merges_into_existing_configuration(): void {
        global $CFG, $USER;
        $bc = new \backup_controller(
            \backup::TYPE_1COURSE,
            $this->course->id,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $USER->id
        );
        $bc->get_plan()->get_setting('users')->set_value(false);
        $bc->execute_plan();
        $file = $bc->get_results()['backup_destination'];
        $bc->destroy();
        $dir = 'tool_ltigroupautoenrol_' . uniqid();
        $file->extract_to_pathname(get_file_packer('application/vnd.moodle.backup'), $CFG->tempdir . '/backup/' . $dir);

        $gen = $this->getDataGenerator();
        $target = $gen->create_course();
        $owntool = $gen->get_plugin_generator('tool_ltigroupautoenrol')->create_lti_tool($target->id, ['name' => 'Own']);
        $owngroup = (int) $gen->create_group(['courseid' => $target->id, 'name' => 'Own group'])->id;
        config_repository::save(new course_config((int) $target->id, false, new mapping([(int) $owntool->id => [$owngroup]])));

        $rc = new \restore_controller(
            $dir,
            $target->id,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $USER->id,
            \backup::TARGET_EXISTING_ADDING
        );
        $rc->get_plan()->get_setting('enrolments')->set_value(\backup::ENROL_ALWAYS);
        // Like other course settings, the configuration is only restored into an existing course with "overwrite_conf".
        $rc->get_plan()->get_setting('overwrite_conf')->set_value(true);
        $this->assertTrue($rc->execute_precheck());
        $rc->execute_plan();
        $rc->destroy();

        $this->assertFalse(config_repository::get($target->id)->enabled, 'enabled flag of the target is kept');
        $this->assertSame(
            ['Alpha' => ['Consumer A', 'Consumer B'], 'Beta' => ['Consumer B'], 'Own' => ['Own group']],
            $this->mapping_by_name((int) $target->id)
        );
    }
}
