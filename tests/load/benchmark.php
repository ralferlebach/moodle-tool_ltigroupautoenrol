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

/**
 * Benchmark of the pending check of the backfill task and of the backfill preview (developer tool).
 *
 * Measures on a development site, for 1/100/1000 queued backfill tasks of other courses and for
 * 1/10/100/1000 groups mapped to one LTI tool with a given number of participants: queries, median
 * time of 5 runs, memory growth and the query plan of the largest case. All data it creates is removed
 * afterwards. Results are recorded in docs/performance.md. Never run it on a production site.
 *
 * Usage: php tests/load/benchmark.php [--participants=300]   (from the Moodle root, 5.1+: public/admin/...)
 *
 * @package    tool_ltigroupautoenrol
 * @copyright  2026 Ralf Erlebach
 * @author     Ralf Erlebach - https://github.com/ralferlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use tool_ltigroupautoenrol\local\assignment_service;
use tool_ltigroupautoenrol\local\course_config;
use tool_ltigroupautoenrol\local\lti_resolver;
use tool_ltigroupautoenrol\local\mapping;
use tool_ltigroupautoenrol\task\backfill_task;

define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/testing/generator/lib.php');

[$options] = cli_get_params(['participants' => 300, 'help' => false], ['h' => 'help']);
if ($options['help']) {
    echo "php tests/load/benchmark.php [--participants=300]\n";
    exit(0);
}
$participants = (int) $options['participants'];

/**
 * Runs a callable 5 times and returns [median seconds, queries of one run, memory growth in bytes].
 *
 * @param callable $run
 * @return array
 */
function tool_ltigroupautoenrol_benchmark(callable $run): array {
    global $DB;
    $times = [];
    $queries = 0;
    $memory = 0;
    for ($i = 0; $i < 5; $i++) {
        \cache_helper::purge_by_event('groupdatachanged');
        $reads = $DB->perf_get_reads();
        $before = memory_get_usage();
        $start = microtime(true);
        $run();
        $times[] = microtime(true) - $start;
        $queries = $DB->perf_get_reads() - $reads;
        $memory = max($memory, memory_get_usage() - $before);
    }
    sort($times);
    return [$times[2], $queries, $memory];
}

/**
 * Prints the plan of a query.
 *
 * @param string $sql
 * @param array $params
 * @return void
 */
function tool_ltigroupautoenrol_explain(string $sql, array $params): void {
    global $DB;
    if ($DB->get_dbfamily() === 'postgres') {
        $lines = $DB->get_fieldset_sql('EXPLAIN ' . $sql, $params);
    } else {
        // MySQL/MariaDB: one JSON document with the whole plan.
        $raw = (string) $DB->get_field_sql('EXPLAIN FORMAT=JSON ' . $sql, $params);
        $plan = json_decode($raw, true);
        $lines = explode("\n", $plan === null ? $raw : json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
    foreach ($lines as $line) {
        // The IN list of 1000 group ids is shortened for the record.
        echo '    ' . (strlen($line) > 240 ? substr($line, 0, 240) . ' ...' : $line) . "\n";
    }
}

$CFG->noemailever = true;
\core\plugininfo\enrol::enable_plugin('lti', 1);
$gen = new testing_data_generator();
$plugingen = $gen->get_plugin_generator('tool_ltigroupautoenrol');
$dbinfo = $DB->get_server_info();
echo "Moodle {$CFG->release}, PHP " . PHP_VERSION . ", {$CFG->dbtype} {$dbinfo['version']}, participants: {$participants}\n\n";

$course = $gen->create_course(['shortname' => 'BENCH' . random_string(6)]);
$firstforeign = 900000000;
try {
    echo "is_pending() with N queued backfill tasks of other courses\n";
    echo "N | queries | median ms | memory KB\n";
    $queued = 0;
    foreach ([1, 100, 1000] as $count) {
        $records = [];
        for ($i = $queued; $i < $count; $i++) {
            $records[] = (object) [
                'component' => 'tool_ltigroupautoenrol',
                'classname' => '\\' . backfill_task::class,
                'nextruntime' => time() + 86400,
                'faildelay' => 0,
                'customdata' => json_encode(['courseid' => $firstforeign + $i, 'hash' => sha1((string) $i), 'previewtime' => 1]),
                'blocking' => 0,
                'timecreated' => time(),
            ];
        }
        $DB->insert_records('task_adhoc', $records);
        $queued = $count;
        [$seconds, $queries, $memory] = tool_ltigroupautoenrol_benchmark(fn() => backfill_task::is_pending((int) $course->id));
        printf("%d | %d | %.2f | %d\n", $count, $queries, $seconds * 1000, $memory / 1024);
    }
    [$select, $params] = backfill_task::get_pending_select((int) $course->id);
    echo "Plan (1000 tasks):\n";
    tool_ltigroupautoenrol_explain("SELECT 1 FROM {task_adhoc} WHERE $select", $params);

    echo "\nBackfill preview with N groups mapped to one tool, {$participants} participants\n";
    echo "N | queries | median ms | memory KB | missing memberships\n";
    $tool = $plugingen->create_lti_tool($course->id, ['name' => 'Benchmark tool']);
    for ($i = 0; $i < $participants; $i++) {
        $plugingen->enrol_via_lti($tool, $gen->create_user()->id);
    }
    $groupids = [];
    foreach ([1, 10, 100, 1000] as $size) {
        while (count($groupids) < $size) {
            $groupids[] = (int) $gen->create_group(['courseid' => $course->id])->id;
        }
        $config = new course_config((int) $course->id, true, new mapping([(int) $tool->id => $groupids]));
        $missing = 0;
        [$seconds, $queries, $memory] = tool_ltigroupautoenrol_benchmark(function () use ($config, &$missing) {
            $missing = assignment_service::count_missing($config);
        });
        printf("%d | %d | %.2f | %d | %d\n", $size, $queries, $seconds * 1000, $memory / 1024, $missing);
    }
    [$eligiblesql, $params] = lti_resolver::get_eligible_users_sql(lti_resolver::get_course_tools($course->id)[$tool->id], time());
    [$sql, $params] = assignment_service::get_missing_memberships_sql((int) $course->id, $groupids, $eligiblesql, $params);
    echo "Plan (1000 groups):\n";
    tool_ltigroupautoenrol_explain($sql, $params);
} finally {
    $DB->delete_records_select(
        'task_adhoc',
        'classname = ? AND ' . $DB->sql_like('customdata', '?'),
        ['\\' . backfill_task::class, '{"courseid":9%']
    );
    delete_course($course, false);
    echo "\nBenchmark data removed.\n";
}
