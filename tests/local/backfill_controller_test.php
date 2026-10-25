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
 * @covers     \tool_ltigroupautoenrol\local\preview_checkpoint
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

    /**
     * The confirmation uses the time and fingerprint stored with the preview, not values of the request.
     */
    public function test_token_queues_the_stored_preview(): void {
        $hash = $this->config->mapping->get_hash();
        $token = preview_checkpoint::create($this->course->id, $hash, 1000);

        $this->assertSame(backfill_controller::QUEUED, backfill_controller::confirm_token($this->course->id, $token, 1500));
        $tasks = \core\task\manager::get_adhoc_tasks(backfill_task::class);
        $this->assertCount(1, $tasks);
        $this->assertSame(1000, reset($tasks)->get_custom_data()->previewtime);
    }

    /**
     * Forged, replayed, expired and foreign tokens queue nothing.
     */
    public function test_invalid_tokens_are_rejected(): void {
        $hash = $this->config->mapping->get_hash();
        $other = $this->getDataGenerator()->create_course();

        $this->assertSame(backfill_controller::EXPIRED, backfill_controller::confirm_token($this->course->id, 'forged', 1100));

        $expired = preview_checkpoint::create($this->course->id, $hash, 1000);
        $this->assertSame(
            backfill_controller::EXPIRED,
            backfill_controller::confirm_token($this->course->id, $expired, 1000 + preview_checkpoint::LIFETIME + 1)
        );

        $foreign = preview_checkpoint::create($other->id, $hash, 1000);
        $this->assertSame(backfill_controller::EXPIRED, backfill_controller::confirm_token($this->course->id, $foreign, 1100));
        $this->assertCount(0, \core\task\manager::get_adhoc_tasks(backfill_task::class));

        // One-time use: the second confirmation of the same preview is rejected (no replay).
        $token = preview_checkpoint::create($this->course->id, $hash, 1000);
        $this->assertSame(backfill_controller::QUEUED, backfill_controller::confirm_token($this->course->id, $token, 1100));
        $this->assertSame(backfill_controller::EXPIRED, backfill_controller::confirm_token($this->course->id, $token, 1100));
        $this->assertCount(1, \core\task\manager::get_adhoc_tasks(backfill_task::class));
    }

    /**
     * A mapping changed between preview and confirmation is detected through the stored fingerprint.
     */
    public function test_token_with_changed_mapping(): void {
        $token = preview_checkpoint::create($this->course->id, $this->config->mapping->get_hash(), 1000);
        $group = $this->getDataGenerator()->create_group(['courseid' => $this->course->id])->id;
        $toolid = $this->config->mapping->get_toolids()[0];
        config_repository::save(new course_config($this->course->id, true, new mapping([$toolid => [$group]])));

        $this->assertSame(backfill_controller::CHANGED, backfill_controller::confirm_token($this->course->id, $token, 1100));
        $this->assertCount(0, \core\task\manager::get_adhoc_tasks(backfill_task::class));
    }

    /**
     * The checkpoint keeps a bounded number of open previews per session.
     */
    public function test_checkpoint_is_bounded(): void {
        $hash = $this->config->mapping->get_hash();
        $first = preview_checkpoint::create($this->course->id, $hash, 1000);
        for ($i = 0; $i < 25; $i++) {
            preview_checkpoint::create($this->course->id, $hash, 1000);
        }
        $this->assertNull(preview_checkpoint::consume($this->course->id, $first, 1100));
    }

    /**
     * A queued run is reported as pending, not as newly queued; its status shows as queued.
     */
    public function test_pending_outcome(): void {
        $hash = $this->config->mapping->get_hash();
        $first = preview_checkpoint::create($this->course->id, $hash, 1000);
        $second = preview_checkpoint::create($this->course->id, $hash, 1000);
        $this->assertSame(backfill_controller::QUEUED, backfill_controller::confirm_token($this->course->id, $first, 1100));
        $this->assertSame(backfill_controller::PENDING, backfill_controller::confirm_token($this->course->id, $second, 1200));
        $this->assertCount(1, \core\task\manager::get_adhoc_tasks(backfill_task::class));
    }

    /**
     * A queued, running or failed run whose task is gone is shown as interrupted.
     */
    public function test_status_of_vanished_task_is_interrupted(): void {
        global $DB;
        $statuses = [config_repository::BACKFILL_QUEUED, config_repository::BACKFILL_RUNNING, config_repository::BACKFILL_FAILED];
        foreach ($statuses as $status) {
            config_repository::record_backfill_status($this->course->id, $status);
            $this->assertSame(0, $DB->count_records('task_adhoc'));
            [$message, $type] = backfill_status::get_message($this->course->id);
            $this->assertStringContainsString('did not finish', $message, $status);
            $this->assertSame(\core\output\notification::NOTIFY_ERROR, $type);
        }
    }
}
