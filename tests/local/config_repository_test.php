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
 * Tests of the configuration persistence and the one-row-per-course contract.
 *
 * @package    tool_ltigroupautoenrol
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @author     Ralf Erlebach - https://github.com/ralferlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_ltigroupautoenrol\local\config_repository
 */
final class config_repository_test extends \advanced_testcase {
    /**
     * Saving twice (double submit) updates the single row of the course.
     */
    public function test_double_save_keeps_one_row(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();

        config_repository::save(new course_config($course->id, true, new mapping([1 => [2]])));
        config_repository::save(new course_config($course->id, false, new mapping([1 => [3]])));

        $this->assertSame(1, $DB->count_records(config_repository::TABLE, ['courseid' => $course->id]));
        $config = config_repository::get($course->id);
        $this->assertFalse($config->enabled);
        $this->assertSame([1 => [3]], $config->mapping->to_array());
    }

    /**
     * The database itself rejects a second row for the same course (concurrent create).
     */
    public function test_unique_key_rejects_duplicates(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $record = (object) ['courseid' => $course->id, 'enable_enrol' => 0, 'settings' => '{}'];
        $DB->insert_record(config_repository::TABLE, $record);

        $this->expectException(\dml_write_exception::class);
        $DB->insert_record(config_repository::TABLE, $record);
    }

    /**
     * Concurrent create: the insert hits the unique key, the row of the other request is updated instead.
     */
    public function test_concurrent_create_updates_existing_row(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $first = config_repository::upsert((object) ['courseid' => $course->id, 'enable_enrol' => 0, 'settings' => '{}']);

        // Simulates the losing request: its existence check ran before the other insert.
        $second = config_repository::upsert(
            (object) ['courseid' => $course->id, 'enable_enrol' => 1, 'settings' => '{"1":[2]}'],
            true
        );

        $this->assertSame($first, $second);
        $this->assertSame(1, $DB->count_records(config_repository::TABLE, ['courseid' => $course->id]));
        $this->assertSame([1 => [2]], config_repository::get($course->id)->mapping->to_array());
    }

    /**
     * Backfill status: recorded per phase, robust against corrupt stored values.
     */
    public function test_backfill_status(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $this->assertNull(config_repository::get_last_backfill($course->id));
        config_repository::save(new course_config($course->id, true, new mapping([])));
        $this->assertNull(config_repository::get_last_backfill($course->id));

        config_repository::record_backfill_status($course->id, config_repository::BACKFILL_RUNNING, null, 100);
        $this->assertSame(config_repository::BACKFILL_RUNNING, config_repository::get_last_backfill($course->id)->status);

        $result = new assignment_result();
        $result->added = 3;
        config_repository::record_backfill($course->id, $result, 200);
        $last = config_repository::get_last_backfill($course->id);
        $this->assertSame([config_repository::BACKFILL_DONE, 200, 3], [$last->status, $last->time, $last->added]);

        $DB->set_field(config_repository::TABLE, 'backfill_result', 'garbage', ['courseid' => $course->id]);
        $last = config_repository::get_last_backfill($course->id);
        $this->assertSame([config_repository::BACKFILL_DONE, 0], [$last->status, $last->added]);
    }

    /**
     * Unconfigured courses get the disabled default; corrupt rows raise a defined exception.
     */
    public function test_default_and_corrupt(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $this->assertNull(config_repository::get($course->id));
        $this->assertFalse(config_repository::get_or_default($course->id)->enabled);

        $DB->insert_record(config_repository::TABLE, (object) ['courseid' => $course->id, 'enable_enrol' => 1, 'settings' => 'x']);
        $this->assertFalse(config_repository::prune($course->id));
        $this->expectException(invalid_mapping_exception::class);
        config_repository::get($course->id);
    }

    /**
     * Failure counting is a single atomic UPDATE: increments add up, the first and the last failure time are kept.
     */
    public function test_event_error_counting_is_cumulative(): void {
        global $DB;
        $this->resetAfterTest();
        $courseid = (int) $this->getDataGenerator()->create_course()->id;
        config_repository::save(course_config::create_default($courseid));

        config_repository::record_event_errors($courseid, 2, 2000);
        config_repository::record_event_errors($courseid, 1, 1000);
        config_repository::record_event_errors($courseid, 3, 3000);
        config_repository::record_event_errors($courseid, 0, 9000);

        $record = $DB->get_record('tool_ltigroupautoenrol', ['courseid' => $courseid]);
        $this->assertEquals([6, 2000, 3000], [$record->event_errors, $record->event_errortime, $record->event_errorlast]);
    }

    /**
     * Clearing keeps every failure recorded in the same second as the check or later (one-second resolution),
     * so a failure that happens while the check runs is never lost; only earlier failures are cleared.
     *
     * @dataProvider clear_provider
     * @param int $lastfailure Time of the last failure.
     * @param int $checktime Time taken before the check.
     * @param bool $cleared
     */
    public function test_clear_event_errors_boundaries(int $lastfailure, int $checktime, bool $cleared): void {
        $this->resetAfterTest();
        $courseid = (int) $this->getDataGenerator()->create_course()->id;
        config_repository::save(course_config::create_default($courseid));
        config_repository::record_event_errors($courseid, 1, 1000);
        config_repository::record_event_errors($courseid, 1, $lastfailure);

        config_repository::clear_event_errors($courseid, $checktime);

        $this->assertSame($cleared, config_repository::get_event_errors($courseid) === null);
    }

    /**
     * Cases for {@see test_clear_event_errors_boundaries()}.
     *
     * @return array
     */
    public static function clear_provider(): array {
        return [
            'last failure before the check' => [1999, 2000, true],
            'last failure in the same second as the check' => [2000, 2000, false],
            'failure during or after the check' => [2001, 2000, false],
        ];
    }

    /**
     * A failure recorded after the check but before the clearing statement (the race window) survives the clearing.
     */
    public function test_failure_in_race_window_survives(): void {
        $this->resetAfterTest();
        $courseid = (int) $this->getDataGenerator()->create_course()->id;
        config_repository::save(course_config::create_default($courseid));
        config_repository::record_event_errors($courseid, 1, 1000);

        $checktime = 2000;
        // The check found nothing missing; meanwhile the observer fails for a new enrolment.
        config_repository::record_event_errors($courseid, 1, $checktime + 1);
        config_repository::clear_event_errors($courseid, $checktime);

        $errors = config_repository::get_event_errors($courseid);
        $this->assertNotNull($errors);
        $this->assertSame(2, $errors->count);
    }
}
