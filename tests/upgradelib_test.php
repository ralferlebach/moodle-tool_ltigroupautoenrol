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

/**
 * Upgrade path tests against the real historical schemas.
 *
 * The plugin table is recreated from the original install.xml of each supported baseline
 * (tests/fixtures/install_<version>.xml, taken unchanged from the git history: 0.1 = 2024050100,
 * the published 1.0.1 = 2024100600, 1.1 = 2026030700 and the pre-release
 * state of 2026100800 from pull request #11 = 2026100800_pr11), filled with data in the format that
 * version wrote, and upgraded with the real upgrade function. The result must equal a fresh install
 * (install.xml), keep every mapping and back up everything it removes. tearDown() recreates the
 * current table, so other tests always see the current schema.
 *
 * @package    tool_ltigroupautoenrol
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @author     Ralf Erlebach - https://github.com/ralferlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     ::xmldb_tool_ltigroupautoenrol_upgrade
 * @covers     ::tool_ltigroupautoenrol_upgrade_repair_legacy_schema
 * @covers     ::tool_ltigroupautoenrol_upgrade_enforce_unique_course
 * @covers     ::tool_ltigroupautoenrol_upgrade_normalise_mappings
 * @covers     ::tool_ltigroupautoenrol_upgrade_add_backfill_status
 * @covers     ::tool_ltigroupautoenrol_upgrade_backup
 * @covers     ::tool_ltigroupautoenrol_upgrade_analyse
 */
final class upgradelib_test extends \advanced_testcase {
    /**
     * Loads the upgrade libraries.
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
    }

    /**
     * Restores the current schema whatever a test did.
     *
     * @return void
     */
    protected function tearDown(): void {
        $this->recreate_table(__DIR__ . '/../db/install.xml');
        parent::tearDown();
    }

    /**
     * Drops the plugin table and creates it from an install.xml file.
     *
     * @param string $file
     * @return void
     */
    private function recreate_table(string $file): void {
        global $CFG, $DB;
        $dbman = $DB->get_manager();
        $table = new \xmldb_table('tool_ltigroupautoenrol');
        if ($dbman->table_exists($table)) {
            $dbman->drop_table($table);
        }
        // The fixtures are kept verbatim; their PATH attribute names db/, so they are parsed as if they were
        // db/install.xml (xmldb checks that PATH matches the file location).
        $structure = new \xmldb_structure($CFG->dirroot . '/admin/tool/ltigroupautoenrol/db/install.xml');
        $structure->arr2xmldb_structure($this->parse_xml(file_get_contents($file)));
        $this->assertTrue($structure->isLoaded(), "Cannot load $file: " . $structure->getError());
        $dbman->create_table($structure->getTable('tool_ltigroupautoenrol'));
    }

    /**
     * Parses an XMLDB file the way xmldb_file does on the running Moodle version.
     *
     * Moodle 5.1 deprecated xmlize() in favour of \core\xml_parser (MDL-86256); Moodle 4.5 only has xmlize().
     *
     * @param string $contents
     * @return array
     */
    private function parse_xml(string $contents): array {
        global $CFG;
        if (class_exists(\core\xml_parser::class)) {
            return (new \core\xml_parser())->parse($contents);
        }
        require_once($CFG->libdir . '/xmlize.php');
        return xmlize($contents);
    }

    /**
     * Installs a historical baseline and runs the real upgrade from it.
     *
     * @param int $version Baseline version (fixture name).
     * @return string Upgrade output.
     */
    private function upgrade_from(int $version): string {
        set_config('version', $version, 'tool_ltigroupautoenrol');
        ob_start();
        try {
            $this->assertTrue(xmldb_tool_ltigroupautoenrol_upgrade($version));
        } finally {
            $output = ob_get_clean();
        }
        $this->assertEquals(2026100801, get_config('tool_ltigroupautoenrol', 'version'));
        return $output;
    }

    /**
     * Asserts that the table equals a fresh install exactly (fields, types, keys).
     *
     * @return void
     */
    private function assert_schema_matches_install_xml(): void {
        global $CFG, $DB;
        $file = new \xmldb_file($CFG->dirroot . '/admin/tool/ltigroupautoenrol/db/install.xml');
        $file->loadXMLStructure();
        $dbman = $DB->get_manager();
        $this->assertSame([], $dbman->check_database_schema($file->getStructure(), ['extratables' => false]));
        $this->assertTrue($dbman->index_exists(
            new \xmldb_table('tool_ltigroupautoenrol'),
            new \xmldb_index('courseid', XMLDB_INDEX_UNIQUE, ['courseid'])
        ));
    }

    /**
     * Extracts the backup files named in the upgrade output.
     *
     * @param string $output
     * @return array[] Decoded backup files keyed by step name.
     */
    private function backups(string $output): array {
        preg_match_all('~: (/\S+\.json)~', $output, $matches);
        $backups = [];
        foreach ($matches[1] as $file) {
            $this->assertFileExists($file);
            $data = json_decode(file_get_contents($file), true);
            $backups[$data['step']] = $data;
        }
        return $backups;
    }

    /**
     * Upgrade from 0.1: missing field added, every legacy value backed up before the fields are dropped.
     */
    public function test_upgrade_from_0_1(): void {
        global $DB;
        $this->recreate_table(__DIR__ . '/fixtures/install_2024050100.xml');
        $configured = $this->getDataGenerator()->create_course();
        $untouched = $this->getDataGenerator()->create_course();
        $DB->insert_record('tool_ltigroupautoenrol', (object) [
            'courseid' => $configured->id, 'enable_enrol' => 1, 'use_groupslist' => 1, 'groupslist' => '4,5',
        ]);
        $DB->insert_record('tool_ltigroupautoenrol', (object) ['courseid' => $untouched->id, 'enable_enrol' => 0]);

        $output = $this->upgrade_from(2024050100);

        $this->assert_schema_matches_install_xml();
        $backup = $this->backups($output)['legacy-0.1-fields'];
        $this->assertCount(2, $backup['rows']);
        $this->assertSame('4,5', $backup['rows'][0]['groupslist']);
        $this->assertStringContainsString('"groupslist":"4,5"', $output);
        $this->assertStringNotContainsString("course {$untouched->id} (row", $output, 'Default values are not reported');
        $this->assertEquals(1, $DB->get_field('tool_ltigroupautoenrol', 'enable_enrol', ['courseid' => $configured->id]));
        $this->assertTrue(config_repository::get($configured->id)->mapping->is_empty());
    }

    /**
     * Data provider: the baselines with the "settings" field (published 1.0.1 and 1.1).
     *
     * @return array
     */
    public static function settings_baseline_provider(): array {
        return ['1.0.1 (published)' => [2024100600], '1.1' => [2026030700]];
    }

    /**
     * Upgrade from 1.0.1 / 1.1: mappings are kept (normalised), duplicates and orphans backed up and removed.
     *
     * @dataProvider settings_baseline_provider
     * @param int $version
     */
    public function test_upgrade_keeps_mappings_and_backs_up_removed_rows(int $version): void {
        global $DB;
        $this->recreate_table(__DIR__ . "/fixtures/install_{$version}.xml");
        $course = $this->getDataGenerator()->create_course();
        $other = $this->getDataGenerator()->create_course();
        // Format written by 1.0.1/1.1: tool ids as keys, group ids as strings.
        $keep = $DB->insert_record(
            'tool_ltigroupautoenrol',
            (object) ['courseid' => $course->id, 'enable_enrol' => 1, 'settings' => '{"7":["9","8"]}']
        );
        $DB->insert_record(
            'tool_ltigroupautoenrol',
            (object) ['courseid' => $course->id, 'enable_enrol' => 0, 'settings' => '{"7":["10"]}']
        );
        $DB->insert_record(
            'tool_ltigroupautoenrol',
            (object) ['courseid' => $other->id, 'enable_enrol' => 1, 'settings' => '{"3":["4"]}']
        );
        $DB->insert_record(
            'tool_ltigroupautoenrol',
            (object) ['courseid' => -5, 'enable_enrol' => 1, 'settings' => '{}']
        );

        $output = $this->upgrade_from($version);

        $this->assert_schema_matches_install_xml();
        $this->assertEquals(
            [$keep, $DB->get_field('tool_ltigroupautoenrol', 'id', ['courseid' => $other->id])],
            array_keys($DB->get_records('tool_ltigroupautoenrol', null, 'id', 'id'))
        );
        $this->assertSame([7 => [8, 9]], config_repository::get($course->id)->mapping->to_array());
        $this->assertTrue(config_repository::get($course->id)->enabled);
        $this->assertSame([3 => [4]], config_repository::get($other->id)->mapping->to_array());
        $backups = $this->backups($output);
        $this->assertSame('{"7":["10"]}', $backups['duplicates-course-' . $course->id]['rows'][0]['settings']);
        $this->assertEquals(-5, $backups['deleted-courses']['rows'][0]['courseid']);
    }

    /**
     * Corrupt mappings are reported and kept for diagnosis.
     */
    public function test_upgrade_keeps_corrupt_mapping(): void {
        global $DB;
        $this->recreate_table(__DIR__ . '/fixtures/install_2026030700.xml');
        $course = $this->getDataGenerator()->create_course();
        $DB->insert_record('tool_ltigroupautoenrol', (object) ['courseid' => $course->id, 'settings' => '{"7":[']);

        $output = $this->upgrade_from(2026030700);

        $this->assertSame('{"7":[', $DB->get_field('tool_ltigroupautoenrol', 'settings', ['courseid' => $course->id]));
        $this->assertStringContainsString("Invalid mapping of course {$course->id}", $output);
    }

    /**
     * Running the upgrade on the current schema changes nothing (fresh install = upgraded install).
     */
    public function test_upgrade_is_idempotent_on_current_schema(): void {
        $this->assertSame('', $this->upgrade_from(2026030700));
        $this->assert_schema_matches_install_xml();
    }

    /**
     * Upgrade from the pre-release state of 2026100800 (pull request #11): the missing status fields are added,
     * existing mappings are kept.
     */
    public function test_upgrade_from_prerelease_2026100800(): void {
        global $DB;
        $this->recreate_table(__DIR__ . '/fixtures/install_2026100800_pr11.xml');
        $course = $this->getDataGenerator()->create_course();
        $DB->insert_record('tool_ltigroupautoenrol', (object) [
            'courseid' => $course->id,
            'enable_enrol' => 1,
            'settings' => '{"7":[3]}',
        ]);

        $output = $this->upgrade_from(2026100800);

        foreach (['backfill_time', 'backfill_result', 'event_errors', 'event_errortime'] as $field) {
            $this->assertStringContainsString("Added field \"{$field}\".", $output);
        }
        $this->assert_schema_matches_install_xml();
        $record = $DB->get_record('tool_ltigroupautoenrol', ['courseid' => $course->id], '*', MUST_EXIST);
        $this->assertSame('{"7":[3]}', $record->settings);
        $this->assertEquals(0, $record->backfill_time);
        $this->assertEquals(0, $record->event_errors);

        // A site already on the final 2026100800 schema is not touched by the new step.
        $this->assertSame('', $this->upgrade_from(2026100800));
    }

    /**
     * The read-only analysis reports what the upgrade will do and changes nothing.
     */
    public function test_analyse_is_read_only(): void {
        global $DB;
        $this->recreate_table(__DIR__ . '/fixtures/install_2024050100.xml');
        $course = $this->getDataGenerator()->create_course();
        $DB->insert_record('tool_ltigroupautoenrol', (object) ['courseid' => $course->id, 'groupslist' => '4']);
        $DB->insert_record('tool_ltigroupautoenrol', (object) ['courseid' => $course->id]);
        $before = $DB->get_records('tool_ltigroupautoenrol');

        $report = implode("\n", tool_ltigroupautoenrol_upgrade_analyse());

        $this->assertEquals($before, $DB->get_records('tool_ltigroupautoenrol'));
        $this->assertStringContainsString('Legacy 0.1 fields present: rolelist', $report);
        $this->assertStringContainsString('Rows with configured legacy values (will be backed up and removed): 1', $report);
        $this->assertStringContainsString('Field "settings" present: no', $report);
        $this->assertStringContainsString("Courses with duplicate configurations: 1 ({$course->id})", $report);
    }
}
