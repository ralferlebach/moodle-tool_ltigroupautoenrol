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
}
