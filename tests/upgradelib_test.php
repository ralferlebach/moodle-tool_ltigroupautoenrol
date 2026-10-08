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

/**
 * Upgrade path tests against the real historical schemas.
 *
 * Each test rebuilds a historical schema of the plugin table with DDL, runs the upgrade helpers
 * and compares the result with install.xml. The helpers are idempotent, and tearDown() runs them
 * again, so the table is always left in the current schema for other tests.
 *
 * @package    tool_ltigroupautoenrol
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @author     Ralf Erlebach - https://github.com/ralferlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     ::tool_ltigroupautoenrol_upgrade_repair_legacy_schema
 * @covers     ::tool_ltigroupautoenrol_upgrade_enforce_unique_course
 * @covers     ::tool_ltigroupautoenrol_upgrade_normalise_mappings
 * @covers     ::xmldb_tool_ltigroupautoenrol_upgrade
 */
final class upgradelib_test extends \advanced_testcase {
    /** @var \xmldb_table */
    private $table;

    /**
     * Loads the upgrade library.
     *
     * @return void
     */
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        $this->resetAfterTest();
        require_once($CFG->dirroot . '/admin/tool/ltigroupautoenrol/db/upgradelib.php');
        require_once($CFG->dirroot . '/admin/tool/ltigroupautoenrol/db/upgrade.php');
        require_once($CFG->libdir . '/upgradelib.php');
        $this->table = new \xmldb_table('tool_ltigroupautoenrol');
    }

    /**
     * Restores the current schema whatever a test did.
     *
     * @return void
     */
    protected function tearDown(): void {
        global $DB;
        $DB->delete_records('tool_ltigroupautoenrol');
        tool_ltigroupautoenrol_upgrade_repair_legacy_schema();
        tool_ltigroupautoenrol_upgrade_enforce_unique_course();
        parent::tearDown();
    }

    /**
     * Removes the unique key, giving the 0.2 - 1.1 schema.
     *
     * @return void
     */
    private function create_schema_1_1(): void {
        global $DB;
        $DB->get_manager()->drop_key(
            $this->table,
            new \xmldb_key('courseid', XMLDB_KEY_FOREIGN_UNIQUE, ['courseid'], 'course', ['id'])
        );
    }

    /**
     * Builds the 0.1 schema (tool_groupautoenrol fields, no "settings", no unique key).
     *
     * @return void
     */
    private function create_schema_0_1(): void {
        global $DB;
        $dbman = $DB->get_manager();
        $this->create_schema_1_1();
        $dbman->drop_field($this->table, new \xmldb_field('settings'));
        $fields = [
            new \xmldb_field('rolelist', XMLDB_TYPE_CHAR, '255', null, null, null, '5'),
            new \xmldb_field('enrol_method', XMLDB_TYPE_INTEGER, '3', null, XMLDB_NOTNULL, null, '0'),
            new \xmldb_field('profile_field', XMLDB_TYPE_INTEGER, '3', null, null, null, '0'),
            new \xmldb_field('use_groupslist', XMLDB_TYPE_INTEGER, '1', null, null, null, '0'),
            new \xmldb_field('groupslist', XMLDB_TYPE_TEXT),
            new \xmldb_field('balises', XMLDB_TYPE_CHAR, '255'),
        ];
        foreach ($fields as $field) {
            $dbman->add_field($this->table, $field);
        }
    }

    /**
     * Asserts that the table matches install.xml exactly.
     *
     * @return void
     */
    private function assert_schema_matches_install_xml(): void {
        global $CFG, $DB;
        $file = new \xmldb_file($CFG->dirroot . '/admin/tool/ltigroupautoenrol/db/install.xml');
        $file->loadXMLStructure();
        $structure = $file->getStructure();
        $errors = $DB->get_manager()->check_database_schema($structure, ['extratables' => false]);
        $this->assertSame([], $errors);
        $this->assertTrue($DB->get_manager()->index_exists(
            $this->table,
            new \xmldb_index('courseid', XMLDB_INDEX_UNIQUE, ['courseid'])
        ));
    }

    /**
     * 0.1 schema: missing field is added, legacy fields are reported and dropped, enable flag kept.
     */
    public function test_upgrade_from_0_1(): void {
        global $DB;
        $this->create_schema_0_1();
        $course = $this->getDataGenerator()->create_course();
        $DB->insert_record('tool_ltigroupautoenrol', (object) [
            'courseid' => $course->id, 'enable_enrol' => 1, 'use_groupslist' => 1, 'groupslist' => '4,5', 'enrol_method' => 0,
        ]);

        $report = tool_ltigroupautoenrol_upgrade_repair_legacy_schema();
        $report = array_merge($report, tool_ltigroupautoenrol_upgrade_enforce_unique_course());

        $this->assert_schema_matches_install_xml();
        $this->assertStringContainsString('"groupslist":"4,5"', implode("\n", $report));
        $record = $DB->get_record('tool_ltigroupautoenrol', ['courseid' => $course->id], '*', MUST_EXIST);
        $this->assertEquals(1, $record->enable_enrol);
        $this->assertNull($record->settings);
        $this->assertTrue(\tool_ltigroupautoenrol\local\config_repository::get($course->id)->mapping->is_empty());
    }

    /**
     * 1.1 schema with duplicates and orphans: lowest id per course is kept, the rest is reported.
     */
    public function test_upgrade_from_1_1_with_duplicates(): void {
        global $DB;
        $this->create_schema_1_1();
        $course = $this->getDataGenerator()->create_course();
        $keep = $DB->insert_record(
            'tool_ltigroupautoenrol',
            (object) ['courseid' => $course->id, 'enable_enrol' => 1, 'settings' => '{"7":["8"]}']
        );
        $DB->insert_record(
            'tool_ltigroupautoenrol',
            (object) ['courseid' => $course->id, 'enable_enrol' => 0, 'settings' => '{"7":["9"]}']
        );
        $DB->insert_record(
            'tool_ltigroupautoenrol',
            (object) ['courseid' => -5, 'enable_enrol' => 1, 'settings' => '{}']
        );

        $report = implode("\n", tool_ltigroupautoenrol_upgrade_enforce_unique_course());

        $this->assert_schema_matches_install_xml();
        $this->assertSame([$keep], array_keys($DB->get_records('tool_ltigroupautoenrol', null, '', 'id')));
        $this->assertStringContainsString('duplicate configuration of course ' . $course->id, $report);
        $this->assertStringContainsString('deleted course -5', $report);
    }

    /**
     * Mappings are rewritten canonically; corrupt ones are reported and kept for diagnosis.
     */
    public function test_normalise_mappings(): void {
        global $DB;
        $course1 = $this->getDataGenerator()->create_course();
        $course2 = $this->getDataGenerator()->create_course();
        $DB->insert_record('tool_ltigroupautoenrol', (object) ['courseid' => $course1->id, 'settings' => '{"7":["9","8"]}']);
        $DB->insert_record('tool_ltigroupautoenrol', (object) ['courseid' => $course2->id, 'settings' => '{"7":[']);

        $report = tool_ltigroupautoenrol_upgrade_normalise_mappings();

        $this->assertSame('{"7":[8,9]}', $DB->get_field('tool_ltigroupautoenrol', 'settings', ['courseid' => $course1->id]));
        $this->assertSame('{"7":[', $DB->get_field('tool_ltigroupautoenrol', 'settings', ['courseid' => $course2->id]));
        $this->assertCount(1, $report);
    }

    /**
     * The upgrade step itself runs on the current schema without changes (fresh install = upgrade).
     */
    public function test_upgrade_step_is_noop_on_current_schema(): void {
        set_config('version', 2026030700, 'tool_ltigroupautoenrol');
        $this->assertTrue(xmldb_tool_ltigroupautoenrol_upgrade(2026030700));
        $this->assertEquals(2026100800, get_config('tool_ltigroupautoenrol', 'version'));
        $this->assert_schema_matches_install_xml();
    }
}
