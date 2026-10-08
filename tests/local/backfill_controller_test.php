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

use tool_ltigroupautoenrol\task\backfill_task;

/**
 * Tests of the backfill confirmation (POST of the preview page).
 *
 * @package    tool_ltigroupautoenrol
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @author     Ralf Erlebach - https://github.com/ralferlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_ltigroupautoenrol\local\backfill_controller
 * @covers     \tool_ltigroupautoenrol\local\backfill_status
 */
final class backfill_controller_test extends \advanced_testcase {
    /** @var \stdClass */
    private $course;

    /** @var course_config */
    private $config;

    /**
     * Creates a configured course.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $plugingen = $gen->get_plugin_generator('tool_ltigroupautoenrol');
        $this->course = $gen->create_course();
        $tool = $plugingen->create_lti_tool($this->course->id);
        $group = $gen->create_group(['courseid' => $this->course->id])->id;
        $this->config = $plugingen->create_config($this->course->id, [$tool->id => [$group]]);
    }

    /**
     * A matching confirmation queues one task with the approved preview time and records "queued".
     */
    public function test_queued(): void {
        $hash = $this->config->mapping->get_hash();
        $this->assertSame(backfill_controller::QUEUED, backfill_controller::confirm($this->course->id, $hash, 1000, 2000));

        $tasks = \core\task\manager::get_adhoc_tasks(backfill_task::class);
        $this->assertCount(1, $tasks);
        $data = reset($tasks)->get_custom_data();
        $this->assertSame(['courseid' => (int) $this->course->id, 'hash' => $hash, 'previewtime' => 1000], (array) $data);
        $this->assertSame(config_repository::BACKFILL_QUEUED, config_repository::get_last_backfill($this->course->id)->status);
        $this->assertStringContainsString('scheduled', backfill_status::get_message($this->course->id)[0]);
    }

    /**
     * A second confirmation while a task is queued does not queue another one.
     */
    public function test_pending(): void {
        $hash = $this->config->mapping->get_hash();
        backfill_controller::confirm($this->course->id, $hash, 1000);
        $this->assertSame(backfill_controller::PENDING, backfill_controller::confirm($this->course->id, $hash, 1000));
        $this->assertCount(1, \core\task\manager::get_adhoc_tasks(backfill_task::class));
    }

    /**
     * A configuration changed after the preview requires a new confirmation; nothing is queued.
     */
    public function test_changed_configuration(): void {
        $hash = $this->config->mapping->get_hash();
        $group = $this->getDataGenerator()->create_group(['courseid' => $this->course->id])->id;
        $toolid = $this->config->mapping->get_toolids()[0];
        config_repository::save(new course_config($this->course->id, true, new mapping([$toolid => [$group]])));

        $this->assertSame(backfill_controller::CHANGED, backfill_controller::confirm($this->course->id, $hash, 1000));
        $this->assertCount(0, \core\task\manager::get_adhoc_tasks(backfill_task::class));
    }

    /**
     * A disabled feature queues nothing.
     */
    public function test_disabled(): void {
        $hash = $this->config->mapping->get_hash();
        config_repository::save(new course_config($this->course->id, false, $this->config->mapping));
        $this->assertSame(backfill_controller::DISABLED, backfill_controller::confirm($this->course->id, $hash, 1000));
        $this->assertCount(0, \core\task\manager::get_adhoc_tasks(backfill_task::class));
    }

    /**
     * A preview time in the future is clamped to now.
     */
    public function test_future_preview_time_is_clamped(): void {
        backfill_controller::confirm($this->course->id, $this->config->mapping->get_hash(), 5000, 2000);
        $tasks = \core\task\manager::get_adhoc_tasks(backfill_task::class);
        $this->assertSame(2000, reset($tasks)->get_custom_data()->previewtime);
    }
}
