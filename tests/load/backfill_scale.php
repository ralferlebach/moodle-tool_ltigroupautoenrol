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
 * Scale benchmark of the backfill and the event path (developer tool, GA-02; never run it on a production site).
 *
 * Modes (one per call, so tests/load/run_scale.sh can run the backfill and new enrolments in parallel):
 *   --setup [--users=10000] [--groups=3]  creates a course with two LTI 1.3 tools, 2 x groups groups (one shared),
 *                                          the users and their LTI enrolments directly in the database (existing
 *                                          participants, no events): 10 % also through the second tool, 5 %
 *                                          expired, 5 % suspended, 10 % already in their first group. Prints JSON.
 *   --run --course=ID                      queues and executes the real backfill task (lock, status, final check),
 *                                          then a second, idempotent run. Prints reads, writes, time, memory and
 *                                          the number of events per run as JSON.
 *   --enrol --course=ID [--count=200]      new LTI enrolments through enrol_lti (event path) during a run;
 *                                          prints latency percentiles and failures as JSON.
 *   --lockprobe --course=ID                tries to take the course lock of the backfill (as a second task would);
 *                                          prints whether it was free.
 *   --explain --course=ID                  plans of the batch and prefetch queries.
 *   --cleanup --course=ID                  removes everything the benchmark created.
 *
 * Usage from the Moodle root (5.1+: public/admin/...): php admin/tool/ltigroupautoenrol/tests/load/backfill_scale.php --setup
 *
 * @package    tool_ltigroupautoenrol
 * @copyright  2026 Ralf Erlebach
 * @author     Ralf Erlebach - https://github.com/ralferlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use tool_ltigroupautoenrol\local\assignment_service;
use tool_ltigroupautoenrol\local\config_repository;
use tool_ltigroupautoenrol\local\lti_resolver;
use tool_ltigroupautoenrol\task\backfill_task;

define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/testing/generator/lib.php');
require_once($CFG->dirroot . '/group/lib.php');

[$options] = cli_get_params([
    'setup' => false, 'run' => false, 'enrol' => false, 'explain' => false, 'cleanup' => false, 'lockprobe' => false,
    'course' => 0, 'users' => 10000, 'groups' => 3, 'count' => 200, 'help' => false,
], ['h' => 'help']);
if ($options['help']) {
    echo "See the comment at the top of this file.\n";
    exit(0);
}
$CFG->noemailever = true;
\core\session\manager::set_user(get_admin());

/** Prefix of the user names and the course short name created by this benchmark. */
const TOOL_LTIGROUPAUTOENROL_SCALE_PREFIX = 'ltigaescale';

/**
 * Returns the benchmark course and its two tools.
 *
 * @param int $courseid
 * @return array [course, tool 1 or null, tool 2 or null]
 */
function tool_ltigroupautoenrol_scale_course(int $courseid): array {
    global $DB;
    $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
    if (strpos($course->shortname, TOOL_LTIGROUPAUTOENROL_SCALE_PREFIX) !== 0) {
        throw new moodle_exception('notabenchmarkcourse', 'error', '', null, $course->shortname);
    }
    // Tools may be missing after an interrupted setup; only --cleanup works then.
    $tools = array_values(lti_resolver::get_course_tools($courseid));
    return [$course, $tools[0] ?? null, $tools[1] ?? null];
}

/**
 * Inserts user enrolments directly (existing participants: no events, no observer).
 *
 * @param stdClass $tool
 * @param int[] $userids
 * @param array $overrides Field values, e.g. status or timeend.
 * @return void
 */
function tool_ltigroupautoenrol_scale_enrol(stdClass $tool, array $userids, array $overrides = []): void {
    global $DB;
    $now = time() - 3600;
    $rows = [];
    foreach ($userids as $userid) {
        $rows[] = (object) ($overrides + [
            'status' => ENROL_USER_ACTIVE, 'enrolid' => $tool->enrolid, 'userid' => $userid, 'timestart' => 0,
            'timeend' => 0, 'modifierid' => 2, 'timecreated' => $now, 'timemodified' => $now,
        ]);
    }
    foreach (array_chunk($rows, 1000) as $chunk) {
        $DB->insert_records('user_enrolments', $chunk);
    }
}

/**
 * Measures a callable: time, reads, writes, peak memory growth.
 *
 * @param callable $run
 * @return array
 */
function tool_ltigroupautoenrol_scale_measure(callable $run): array {
    global $DB;
    gc_collect_cycles();
    if (function_exists('memory_reset_peak_usage')) {
        memory_reset_peak_usage();
    }
    $memory = memory_get_usage();
    $reads = $DB->perf_get_reads();
    $writes = $DB->perf_get_writes();
    $start = microtime(true);
    $value = $run();
    return [
        'seconds' => round(microtime(true) - $start, 2),
        'reads' => $DB->perf_get_reads() - $reads,
        'writes' => $DB->perf_get_writes() - $writes,
        'peak_memory_mb' => round((memory_get_peak_usage() - $memory) / 1048576, 1),
        'value' => $value,
    ];
}

/**
 * Prints the plan of a query (PostgreSQL: EXPLAIN ANALYZE; MariaDB: ANALYZE FORMAT=JSON, shortened).
 *
 * @param string $title
 * @param string $sql
 * @param array $params
 * @return void
 */
function tool_ltigroupautoenrol_scale_explain(string $title, string $sql, array $params): void {
    global $DB;
    echo "== {$title}\n";
    if ($DB->get_dbfamily() === 'postgres') {
        $lines = $DB->get_fieldset_sql('EXPLAIN ANALYZE ' . $sql, $params);
    } else {
        $raw = (string) $DB->get_field_sql('ANALYZE FORMAT=JSON ' . $sql, $params);
        $lines = explode("\n", json_encode(json_decode($raw, true), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $lines = array_values(array_filter($lines, fn($l) => preg_match(
            '/"(table_name|access_type|key|rows|r_rows|r_total_time_ms|attached_condition|using_index)"/',
            $l
        )));
    }
    foreach ($lines as $line) {
        echo '    ' . (strlen($line) > 200 ? substr($line, 0, 200) . ' ...' : $line) . "\n";
    }
}

set_exception_handler(function (\Throwable $e) {
    // Show the database message of a failed write (default CLI output hides the debug information).
    fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . ' ' . ($e->debuginfo ?? '') . "\n");
    exit(1);
});
$dbinfo = $DB->get_server_info();
$env = "Moodle {$CFG->release}, PHP " . PHP_VERSION . ", {$CFG->dbtype} {$dbinfo['version']}";

if ($options['setup']) {
    $gen = new testing_data_generator();
    $plugingen = $gen->get_plugin_generator('tool_ltigroupautoenrol');
    \core\plugininfo\enrol::enable_plugin('lti', 1);
    $suffix = random_string(6);
    $course = $gen->create_course(['shortname' => TOOL_LTIGROUPAUTOENROL_SCALE_PREFIX . $suffix]);
    $tool1 = $plugingen->create_lti_tool($course->id, ['name' => 'Scale tool 1']);
    $tool2 = $plugingen->create_lti_tool($course->id, ['name' => 'Scale tool 2']);
    $scalegroupids = [];
    for ($i = 0; $i < 2 * (int) $options['groups'] - 1; $i++) {
        $scalegroupids[] = (int) $gen->create_group(['courseid' => $course->id, 'name' => "Scale group $i"])->id;
    }
    $g = (int) $options['groups'];
    // Tool 1 => groups 0..g-1, tool 2 => groups g-1..2g-2 (group g-1 is shared by both consumers).
    $plugingen->create_config($course->id, [
        $tool1->id => array_slice($scalegroupids, 0, $g),
        $tool2->id => array_slice($scalegroupids, $g - 1),
    ]);
    $total = (int) $options['users'];
    $rows = [];
    for ($i = 0; $i < $total; $i++) {
        $rows[] = (object) [
            'auth' => 'manual', 'confirmed' => 1, 'mnethostid' => $CFG->mnet_localhost_id,
            'username' => TOOL_LTIGROUPAUTOENROL_SCALE_PREFIX . $suffix . '_' . $i, 'password' => 'x',
            'firstname' => 'Scale', 'lastname' => "User $i", 'email' => "scale{$i}@example.invalid",
            'timecreated' => time(), 'timemodified' => time(),
        ];
    }
    foreach (array_chunk($rows, 1000) as $chunk) {
        $DB->insert_records('user', $chunk);
    }
    $userids = array_map('intval', $DB->get_fieldset_select(
        'user',
        'id',
        $DB->sql_like('username', ':prefix') . ' ORDER BY id',
        ['prefix' => TOOL_LTIGROUPAUTOENROL_SCALE_PREFIX . $suffix . '\_%']
    ));
    $tenth = intdiv($total, 10);
    $twentieth = intdiv($total, 20);
    $half = intdiv($total, 2);
    // First half through tool 1, second half through tool 2.
    $active1 = array_slice($userids, 0, $half - 2 * $twentieth);
    $active2 = array_slice($userids, $half);
    tool_ltigroupautoenrol_scale_enrol($tool1, $active1);
    $expired = array_slice($userids, $half - 2 * $twentieth, $twentieth);
    $suspended = array_slice($userids, $half - $twentieth, $twentieth);
    tool_ltigroupautoenrol_scale_enrol($tool1, $expired, ['timeend' => time() - 60]);
    tool_ltigroupautoenrol_scale_enrol($tool1, $suspended, ['status' => ENROL_USER_SUSPENDED]);
    tool_ltigroupautoenrol_scale_enrol($tool2, $active2);
    // 10 % of the tool 2 users are also enrolled through tool 1 (multiple enrolment).
    tool_ltigroupautoenrol_scale_enrol($tool1, array_slice($active2, 0, $tenth));
    // 10 % of the tool 1 users are already members of their first group.
    if (empty($scalegroupids[0])) {
        throw new coding_exception('benchmark groups lost: ' . json_encode($scalegroupids));
    }
    $members = [];
    foreach (array_slice($active1, 0, $tenth) as $userid) {
        $members[] = (object) [
            'groupid' => $scalegroupids[0], 'userid' => $userid, 'timeadded' => time(), 'component' => '', 'itemid' => 0,
        ];
    }
    $DB->insert_records('groups_members', $members);
    \cache_helper::purge_by_event('groupdatachanged');
    $config = config_repository::get($course->id);
    echo json_encode([
        'env' => $env, 'course' => (int) $course->id, 'users' => $total, 'groups' => count($scalegroupids),
        'enrolments' => $DB->count_records_select('user_enrolments', 'enrolid IN (?, ?)', [$tool1->enrolid, $tool2->enrolid]),
        'missing_memberships' => assignment_service::count_missing($config),
        'mapping_hash' => $config->mapping->get_hash(),
    ]) . "\n";
    exit(0);
}

$courseid = (int) $options['course'];
[$course, $tool1, $tool2] = tool_ltigroupautoenrol_scale_course($courseid);

if ($options['run']) {
    $config = config_repository::get($courseid);
    $countevents = fn() => $DB->count_records_select(
        'logstore_standard_log',
        'courseid = ? AND component = ?',
        [$courseid, 'tool_ltigroupautoenrol']
    );
    $result = ['env' => $env, 'course' => $courseid, 'batch' => assignment_service::BATCH_SIZE,
        'logstores' => get_config('tool_log', 'enabled_stores'), 'missing_before' => assignment_service::count_missing($config)];
    foreach (['first_run', 'repeated_run'] as $label) {
        $events = $countevents();
        $task = backfill_task::create($courseid, $config->mapping->get_hash(), time() + 1);
        ob_start();
        $measured = tool_ltigroupautoenrol_scale_measure(function () use ($task) {
            $task->execute();
            return null;
        });
        $output = trim(ob_get_clean());
        unset($measured['value']);
        // Flush the buffered log store before counting the logged events.
        get_log_manager()->dispose();
        get_log_manager(true);
        $status = config_repository::get_last_backfill($courseid);
        $result[$label] = $measured + [
            'status' => $status->status, 'added' => $status->added, 'alreadymember' => $status->alreadymember,
            'errors' => $status->errors, 'eligible_users' => $status->eligible,
            'plugin_events_logged' => $countevents() - $events, 'task_output' => $output,
        ];
        sleep(1);
    }
    $result['missing_after'] = assignment_service::count_missing($config);
    echo json_encode($result, JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
}

if ($options['enrol']) {
    $gen = new testing_data_generator();
    $plugingen = $gen->get_plugin_generator('tool_ltigroupautoenrol');
    $latencies = [];
    $assigned = 0;
    $failed = 0;
    for ($i = 0; $i < (int) $options['count']; $i++) {
        $user = $gen->create_user(['username' => strtolower(TOOL_LTIGROUPAUTOENROL_SCALE_PREFIX . substr($course->shortname, -6)
            . '_new' . $i . '_' . random_string(4))]);
        $start = microtime(true);
        try {
            $plugingen->enrol_via_lti($i % 2 ? $tool2 : $tool1, $user->id);
        } catch (Throwable $e) {
            $failed++;
        }
        $latencies[] = (microtime(true) - $start) * 1000;
        $assigned += $DB->record_exists_sql(
            'SELECT 1 FROM {groups_members} gm JOIN {groups} g ON g.id = gm.groupid WHERE g.courseid = ? AND gm.userid = ?',
            [$courseid, $user->id]
        ) ? 1 : 0;
    }
    sort($latencies);
    $pct = fn(float $p) => round($latencies[(int) floor($p * (count($latencies) - 1))], 1);
    echo json_encode(['enrolments' => count($latencies), 'assigned' => $assigned, 'failed' => $failed,
        'latency_ms' => ['p50' => $pct(0.5), 'p95' => $pct(0.95), 'max' => $pct(1.0)]]) . "\n";
    exit(0);
}

if ($options['lockprobe']) {
    $start = microtime(true);
    $lock = \core\lock\lock_config::get_lock_factory('tool_ltigroupautoenrol_backfill')->get_lock('course_' . $courseid, 0);
    $acquired = (bool) $lock;
    if ($lock) {
        $lock->release();
    }
    echo json_encode(['lock_acquired' => $acquired, 'ms' => round((microtime(true) - $start) * 1000, 1),
        'running_status' => config_repository::get_last_backfill($courseid)->status ?? null]) . "\n";
    exit(0);
}

if ($options['explain']) {
    $config = config_repository::get($courseid);
    [$eligiblesql, $params] = lti_resolver::get_eligible_users_sql($tool1, time(), time());
    tool_ltigroupautoenrol_scale_explain(
        'batch query (keyset, LIMIT 500)',
        "SELECT DISTINCT eligible.userid FROM ($eligiblesql) eligible WHERE eligible.userid > :lastuserid " .
            "ORDER BY eligible.userid LIMIT 500",
        $params + ['lastuserid' => 0]
    );
    $userids = array_keys($DB->get_records_sql(
        "SELECT DISTINCT eligible.userid FROM ($eligiblesql) eligible ORDER BY eligible.userid",
        $params,
        0,
        500
    ));
    [$usersql, $userparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'ltiusr');
    [$groupsql, $groupparams] = $DB->get_in_or_equal($config->mapping->get_groupids((int) $tool1->id), SQL_PARAMS_NAMED, 'ltigrp');
    tool_ltigroupautoenrol_scale_explain(
        'membership prefetch of one batch (500 users x mapped groups)',
        "SELECT gm.id, gm.userid, gm.groupid FROM {groups_members} gm WHERE gm.userid $usersql AND gm.groupid $groupsql",
        $userparams + $groupparams
    );
    exit(0);
}

if ($options['cleanup']) {
    $prefix = ['prefix' => TOOL_LTIGROUPAUTOENROL_SCALE_PREFIX . substr($course->shortname, -6) . '\_%'];
    $like = $DB->sql_like('username', ':prefix', false);
    $usersql = "SELECT id FROM {user} WHERE $like";
    // Direct deletes: unenrolling 10,000 users one by one through delete_course() would take minutes.
    $DB->delete_records_select('groups_members', "userid IN ($usersql)", $prefix);
    $DB->delete_records_select('user_enrolments', "userid IN ($usersql)", $prefix);
    $DB->delete_records_select('logstore_standard_log', 'courseid = ?', [$courseid]);
    $DB->delete_records_select('task_adhoc', 'classname = ? AND ' . $DB->sql_like('customdata', '?'), [
        '\\' . backfill_task::class, '{"courseid":' . $courseid . ',%',
    ]);
    ob_start();
    delete_course($course, false);
    ob_end_clean();
    $deleted = $DB->count_records_select('user', $like, $prefix);
    $DB->delete_records_select(
        'context',
        "contextlevel = :ctxlevel AND instanceid IN ($usersql)",
        ['ctxlevel' => CONTEXT_USER] + $prefix
    );
    $DB->delete_records_select('user_preferences', "userid IN ($usersql)", $prefix);
    $DB->delete_records_select('user', $like, $prefix);
    purge_all_caches();
    echo json_encode(['course_deleted' => $courseid, 'users_deleted' => $deleted]) . "\n";
    exit(0);
}

echo "No mode given; see --help.\n";
exit(1);
