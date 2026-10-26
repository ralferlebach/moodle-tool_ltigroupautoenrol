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
 * Query budgets that must not grow with the number of queued tasks or mapped groups.
 *
 * Measured timings and query plans for 1/100/1000 tasks and 1/10/100/1000 groups are in docs/performance.md
 * (tests/load/benchmark.php); these tests enforce the query counts and generous time and memory limits.
 *
 * @package    tool_ltigroupautoenrol
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @author     Ralf Erlebach - https://github.com/ralferlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_ltigroupautoenrol\task\backfill_task
 * @covers     \tool_ltigroupautoenrol\local\assignment_service
 */
final class scaling_test extends \advanced_testcase {
    /**
     * Queues backfill tasks for other courses directly (same custom data format as backfill_task::create()).
     *
     * @param int $count
     * @param int $firstcourseid
     * @return void
     */
    private function queue_foreign_tasks(int $count, int $firstcourseid): void {
        global $DB;
        $records = [];
        for ($i = 0; $i < $count; $i++) {
            $records[] = (object) [
                'component' => 'tool_ltigroupautoenrol',
                'classname' => '\\' . backfill_task::class,
                'nextruntime' => time(),
                'faildelay' => 0,
                'customdata' => json_encode([
                    'courseid' => $firstcourseid + $i,
                    'hash' => sha1((string) $i),
                    'previewtime' => 1000,
                ]),
                'blocking' => 0,
                'timecreated' => time(),
                'attemptsavailable' => 12,
            ];
        }
        $DB->insert_records('task_adhoc', $records);
    }

    /**
     * is_pending() needs exactly one query, whatever the number of queued tasks of other courses.
     */
    public function test_is_pending_query_budget(): void {
        global $DB;
        $this->resetAfterTest();
        $courseid = (int) $this->getDataGenerator()->create_course()->id;
        $queued = 0;
        foreach ([1, 100, 1000] as $count) {
            $this->queue_foreign_tasks($count - $queued, 1000000 + $queued);
            $queued = $count;
            $reads = $DB->perf_get_reads();
            $start = microtime(true);
            $this->assertFalse(backfill_task::is_pending($courseid));
            $elapsed = microtime(true) - $start;
            $this->assertSame(1, $DB->perf_get_reads() - $reads, "queries with $count queued tasks");
            $this->assertLessThan(1.0, $elapsed, "seconds with $count queued tasks");
        }
        $this->queue_foreign_tasks(1, $courseid);
        $this->assertTrue(backfill_task::is_pending($courseid));
    }

    /**
     * The preview needs the same number of queries for 1, 10, 100 and 1000 mapped groups of a tool.
     */
    public function test_preview_query_budget(): void {
        global $DB;
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $plugingen = $gen->get_plugin_generator('tool_ltigroupautoenrol');
        $course = $gen->create_course();
        $tool = $plugingen->create_lti_tool($course->id);
        for ($i = 0; $i < 20; $i++) {
            $plugingen->enrol_via_lti($tool, $gen->create_user()->id);
        }
        $groupids = [];
        $readsbysize = [];
        foreach ([1, 10, 100, 1000] as $size) {
            while (count($groupids) < $size) {
                $groupids[] = (int) $gen->create_group(['courseid' => $course->id])->id;
            }
            $config = new course_config((int) $course->id, true, new mapping([(int) $tool->id => $groupids]));
            // Warm-up after creating groups: the first call reloads the caches that creating groups invalidated.
            assignment_service::count_missing($config);
            $reads = $DB->perf_get_reads();
            $memory = memory_get_usage();
            $start = microtime(true);
            $missing = assignment_service::count_missing($config);
            $elapsed = microtime(true) - $start;
            $readsbysize[$size] = $DB->perf_get_reads() - $reads;
            $this->assertSame(20 * $size, $missing, "missing memberships with $size groups");
            $this->assertLessThan(5.0, $elapsed, "seconds with $size groups");
            $this->assertLessThan(16 * 1024 * 1024, memory_get_usage() - $memory, "memory with $size groups");
        }
        $this->assertCount(1, array_unique($readsbysize), 'queries: ' . json_encode($readsbysize));
    }
}
