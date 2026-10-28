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
 * Fixture of the two-site E2E acceptance test (GA-01): two LTI 1.3 consumers (site a and site a2, separate
 * issuers) and one shared course on the tool site b.
 *
 * Run on the site given by --site with the stack's moodle_cli (e2e-env.sh), from any directory:
 *   php fixture.php --site=a|a2|b --config=<config.php of the site> --step=<step> [--state=<json>] [...]
 * Steps (all idempotent; everything they create carries the prefix "ltigae"/"LTIGAE"; nothing of the shared seed is changed):
 *   a, a2: types     one LTI 1.3 tool type "LTIGAE Consumer <n>" pointing at site b (--n=1 on a, --n=2 on a2)
 *   b:     provider  course LTIGAEE2E with groups, a teacher, four published LTI 1.3 resources, one platform
 *                    registration per consumer and the plugin mapping (Consumer A/B tools)
 *   a, a2: consumer  course LTIGAECONSUMER with the consumer's users and one activity per launched resource (--n)
 *   a, a2: activity  one more activity (--n, --name, --uuid), e.g. for a resource of a copied course
 *   b:     course    tools, groups and mapping of a course (--shortname), e.g. a copy
 *   b:     state     memberships and enrolments (by user id on b), logged events and diagnostics observations
 * Output: JSON on stdout; the steps that build the fixture also merge their result into --state.
 *
 * @package    tool_ltigroupautoenrol
 * @copyright  2026 Ralf Erlebach
 * @author     Ralf Erlebach - https://github.com/ralferlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);
// The config.php to load is a CLI argument: the same script serves both sites of the stack.
// phpcs:disable moodle.Files.MoodleInternal.MoodleInternalGlobalState
$configpath = null;
foreach ($argv as $arg) {
    if (strpos($arg, '--config=') === 0) {
        $configpath = substr($arg, 9);
    }
}
if (!$configpath || !is_file($configpath)) {
    fwrite(STDERR, "--config=<path of the site's config.php> is required\n");
    exit(2);
}
require($configpath);
// phpcs:enable
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/user/lib.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/group/lib.php');

[$options] = cli_get_params([
    'site' => '', 'config' => '', 'step' => '', 'state' => '/tmp/ltigae-e2e/state.json', 'shortname' => 'LTIGAEE2E',
    'name' => '', 'n' => 1, 'uuid' => '', 'burl' => 'https://b.tool-b.test',
], []);
$CFG->noemailever = true;
\core\session\manager::set_user(get_admin());

/** Password of all users of the fixture. */
const LTIGAE_E2E_PASSWORD = 'Ltigae!E2e-2026';

/**
 * Reads the state file.
 *
 * @param string $file
 * @return array
 */
function ltigae_state(string $file): array {
    return is_file($file) ? (json_decode(file_get_contents($file), true) ?: []) : [];
}

/**
 * Merges data into the state file and prints it.
 *
 * @param string $file
 * @param array $data
 * @return void
 */
function ltigae_out(string $file, array $data): void {
    @mkdir(dirname($file), 0777, true);
    file_put_contents($file, json_encode(array_merge(ltigae_state($file), $data), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    echo json_encode($data, JSON_UNESCAPED_SLASHES) . "\n";
}

/**
 * Creates (or recreates) a manual user.
 *
 * @param string $username
 * @param string $firstname
 * @param string $lastname
 * @return stdClass
 */
function ltigae_user(string $username, string $firstname, string $lastname): stdClass {
    global $DB, $CFG;
    if ($old = $DB->get_record('user', ['username' => $username, 'mnethostid' => $CFG->mnet_localhost_id])) {
        delete_user($old);
        $DB->delete_records('user', ['id' => $old->id]);
    }
    $id = user_create_user((object) ['username' => $username, 'firstname' => $firstname, 'lastname' => $lastname,
        'email' => $username . '@example.invalid', 'auth' => 'manual', 'confirmed' => 1, 'lang' => 'en',
        'mnethostid' => $CFG->mnet_localhost_id, 'password' => LTIGAE_E2E_PASSWORD], true, false);
    return $DB->get_record('user', ['id' => $id], '*', MUST_EXIST);
}

/**
 * Enrols a user manually with a role.
 *
 * @param stdClass $course
 * @param stdClass $user
 * @param string $role Role short name.
 * @return void
 */
function ltigae_enrol(stdClass $course, stdClass $user, string $role): void {
    global $DB;
    $instance = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual'], '*', MUST_EXIST);
    enrol_get_plugin('manual')->enrol_user($instance, $user->id, $DB->get_field('role', 'id', ['shortname' => $role]));
}

/**
 * Deletes the courses whose short name starts with a prefix, and on site B the LTI users enrolled in them.
 *
 * @param string $prefix
 * @return void
 */
function ltigae_drop_courses(string $prefix): void {
    global $DB;
    $courses = $DB->get_records_select('course', $DB->sql_like('shortname', ':p'), ['p' => $prefix . '%']);
    foreach ($courses as $course) {
        $ltiusers = $DB->get_records_sql(
            "SELECT DISTINCT u.* FROM {user} u JOIN {user_enrolments} ue ON ue.userid = u.id
               JOIN {enrol} e ON e.id = ue.enrolid WHERE e.courseid = ? AND u.auth = 'lti' AND u.deleted = 0",
            [$course->id]
        );
        ob_start();
        delete_course($course, false);
        ob_end_clean();
        foreach ($ltiusers as $user) {
            $DB->delete_records('auth_lti_linked_login', ['userid' => $user->id]);
            delete_user($user);
        }
    }
}

$state = $options['state'];
$burl = rtrim($options['burl'], '/');
$site = $options['site'] === 'b' ? 'b' : 'a';
$step = $site . ':' . $options['step'];
$n = (int) $options['n'];

if ($step === 'a:types') {
    require_once($CFG->dirroot . '/mod/lti/locallib.php');
    foreach ($DB->get_records('lti_types', ['name' => "LTIGAE Consumer $n"]) as $old) {
        lti_delete_type($old->id);
    }
    $typeid = lti_add_type((object) ['state' => LTI_TOOL_STATE_CONFIGURED], (object) [
        'lti_typename' => "LTIGAE Consumer $n", 'lti_toolurl' => "$burl/enrol/lti/launch.php",
        'lti_description' => 'tool_ltigroupautoenrol E2E', 'lti_ltiversion' => LTI_VERSION_1P3,
        'lti_keytype' => LTI_JWK_KEYSET, 'lti_publickeyset' => "$burl/enrol/lti/jwks.php",
        'lti_initiatelogin' => "$burl/enrol/lti/login.php",
        'lti_redirectionuris' => "$burl/enrol/lti/launch.php\n$burl/enrol/lti/launch_deeplink.php",
        'lti_contentitem' => 0, 'lti_coursevisible' => LTI_COURSEVISIBLE_PRECONFIGURED,
        'lti_sendname' => LTI_SETTING_ALWAYS, 'lti_sendemailaddr' => LTI_SETTING_ALWAYS,
        'lti_acceptgrades' => LTI_SETTING_NEVER, 'lti_launchcontainer' => LTI_LAUNCH_CONTAINER_REPLACE_MOODLE_WINDOW,
        'lti_customparameters' => '',
    ]);
    $type = $DB->get_record('lti_types', ['id' => $typeid], '*', MUST_EXIST);
    $types = ltigae_state($state)['types'] ?? [];
    $types[$n] = ['site' => $options['site'], 'issuer' => $CFG->wwwroot, 'type_id' => (int) $typeid,
        'client_id' => $type->clientid, 'deployment_id' => (string) $typeid];
    ltigae_out($state, ['types' => $types]);
    exit(0);
}

if ($step === 'b:provider') {
    $s = ltigae_state($state);
    \core\plugininfo\enrol::enable_plugin('lti', 1);
    \core\plugininfo\auth::enable_plugin('lti', 1);
    ltigae_drop_courses('LTIGAEE2E');
    $regrepo = new \enrol_lti\local\ltiadvantage\repository\application_registration_repository();
    $deprepo = new \enrol_lti\local\ltiadvantage\repository\deployment_repository();
    $regservice = new \enrol_lti\local\ltiadvantage\service\application_registration_service(
        $regrepo,
        $deprepo,
        new \enrol_lti\local\ltiadvantage\repository\resource_link_repository(),
        new \enrol_lti\local\ltiadvantage\repository\context_repository(),
        new \enrol_lti\local\ltiadvantage\repository\user_repository()
    );
    foreach ($regrepo->find_all() as $reg) {
        if (strpos($reg->get_name(), 'LTIGAE Consumer') === 0) {
            $regservice->delete_application_registration($reg->get_id());
        }
    }
    $course = create_course((object) ['fullname' => 'LTIGAE shared course', 'shortname' => 'LTIGAEE2E', 'category' => 1,
        'format' => 'topics', 'numsections' => 1]);
    $teacher = ltigae_user('ltigaeteacher', 'Gina', 'Teacher');
    ltigae_enrol($course, $teacher, 'editingteacher');
    $groups = [];
    foreach (['Uni A', 'Uni A lab', 'Uni B', 'All consumers', 'Late group', 'Short group'] as $name) {
        $groups[$name] = (int) groups_create_group((object) ['courseid' => $course->id, 'name' => $name]);
    }
    $registrations = [];
    foreach ($s['types'] as $n => $type) {
        $reg = \enrol_lti\local\ltiadvantage\entity\application_registration::create(
            "LTIGAE Consumer $n",
            bin2hex(random_bytes(30)),
            new moodle_url($type['issuer']),
            $type['client_id'],
            new moodle_url($type['issuer'] . '/mod/lti/auth.php'),
            new moodle_url($type['issuer'] . '/mod/lti/certs.php'),
            new moodle_url($type['issuer'] . '/mod/lti/token.php')
        );
        $reg = $regrepo->save($reg);
        $deprepo->save($reg->add_tool_deployment("LTIGAE deployment $n", $type['deployment_id']));
        $registrations[$n] = ['uniqueid' => $reg->get_uniqueid(),
            'login_url' => "$CFG->wwwroot/enrol/lti/login.php?id={$reg->get_uniqueid()}"];
    }
    $plugin = enrol_get_plugin('lti');
    $tools = [];
    $context = context_course::instance($course->id);
    foreach (['Consumer A tool' => 0, 'Consumer B tool' => 0, 'Late tool' => 0, 'Short tool' => 20] as $name => $period) {
        $instanceid = $plugin->add_instance($course, [
            'name' => $name, 'courseid' => $course->id, 'contextid' => $context->id, 'status' => ENROL_INSTANCE_ENABLED,
            'ltiversion' => 'LTI-1p3', 'roleinstructor' => $DB->get_field('role', 'id', ['shortname' => 'teacher']),
            'rolelearner' => $DB->get_field('role', 'id', ['shortname' => 'student']), 'cmid' => 0,
            'provisioningmodelearner' => 1, 'provisioningmodeinstructor' => 2, 'gradesync' => 0, 'gradesynccompletion' => 0,
            'membersync' => 0, 'membersyncmode' => 1, 'maxenrolled' => 0, 'enrolperiod' => $period,
        ]);
        $tool = $DB->get_record('enrol_lti_tools', ['enrolid' => $instanceid], '*', MUST_EXIST);
        $tools[$name] = ['id' => (int) $tool->id, 'enrolid' => (int) $instanceid, 'uuid' => $tool->uuid];
    }
    $config = new \tool_ltigroupautoenrol\local\course_config((int) $course->id, true, new \tool_ltigroupautoenrol\local\mapping([
        $tools['Consumer A tool']['id'] => [$groups['Uni A'], $groups['Uni A lab'], $groups['All consumers']],
        $tools['Consumer B tool']['id'] => [$groups['Uni B'], $groups['All consumers']],
    ]));
    \tool_ltigroupautoenrol\local\config_repository::save($config);
    ltigae_out($state, ['provider' => ['course' => (int) $course->id, 'shortname' => 'LTIGAEE2E', 'groups' => $groups,
        'tools' => $tools, 'registrations' => $registrations, 'url' => $CFG->wwwroot,
        'teacher' => ['username' => 'ltigaeteacher', 'password' => LTIGAE_E2E_PASSWORD]]]);
    exit(0);
}

/**
 * Site A: adds an LTI activity of a fixture type launching a resource of site B.
 *
 * @param stdClass $course
 * @param array $s State.
 * @param int $consumer 1 or 2 (type of this site).
 * @param string $name Activity name.
 * @param string $uuid Resource uuid on site B.
 * @param string $burl
 * @return array cmid and view URL.
 */
function ltigae_activity(stdClass $course, array $s, int $consumer, string $name, string $uuid, string $burl): array {
    global $CFG, $DB;
    require_once($CFG->dirroot . '/course/modlib.php');
    require_once($CFG->dirroot . '/mod/lti/locallib.php');
    $cm = add_moduleinfo((object) [
        'modulename' => 'lti', 'module' => $DB->get_field('modules', 'id', ['name' => 'lti'], MUST_EXIST),
        'course' => $course->id, 'section' => 1, 'visible' => 1, 'name' => $name,
        'introeditor' => ['text' => '', 'format' => FORMAT_HTML, 'itemid' => 0], 'showdescription' => 0,
        'typeid' => $s['types'][$consumer]['type_id'], 'toolurl' => "$burl/enrol/lti/launch.php", 'securetoolurl' => '',
        'instructorcustomparameters' => "id=$uuid", 'launchcontainer' => LTI_LAUNCH_CONTAINER_REPLACE_MOODLE_WINDOW,
        'instructorchoicesendname' => 1, 'instructorchoicesendemailaddr' => 1, 'instructorchoiceacceptgrades' => 0,
        'instructorchoiceallowroster' => 0, 'instructorchoiceallowsetting' => 0, 'grade' => 0, 'resourcekey' => '',
        'password' => '', 'visibleoncoursepage' => 1, 'completion' => 0,
    ], $course);
    return ['cmid' => (int) $cm->coursemodule, 'view_url' => "$CFG->wwwroot/mod/lti/view.php?id={$cm->coursemodule}"];
}

if ($step === 'a:consumer') {
    $s = ltigae_state($state);
    $DB->set_field(
        'lti_types_config',
        'value',
        $s['provider']['registrations'][$n]['login_url'],
        ['typeid' => $s['types'][$n]['type_id'], 'name' => 'initiatelogin']
    );
    ltigae_drop_courses('LTIGAECONSUMER');
    $course = create_course((object) ['fullname' => "LTIGAE consumer $n", 'shortname' => 'LTIGAECONSUMER', 'category' => 1,
        'format' => 'topics', 'numsections' => 1]);
    // Consumer 1 (site a): alice, carol (enrolled through two tools of the provider), erin (late tool).
    // Consumer 2 (site a2): bob, dave (short tool), frank, gina.
    $names = $n === 1 ? ['alice', 'carol', 'erin'] : ['bob', 'dave', 'frank', 'gina'];
    $users = [];
    foreach ($names as $name) {
        $user = ltigae_user('ltigae' . $name, ucfirst($name), 'Learner');
        ltigae_enrol($course, $user, 'student');
        $users[$name] = ['username' => $user->username, 'password' => LTIGAE_E2E_PASSWORD, 'id' => (int) $user->id,
            'site' => $options['site'], 'url' => $CFG->wwwroot];
    }
    $tools = $s['provider']['tools'];
    $resources = $n === 1
        ? ['shared' => 'Consumer A tool', 'second' => 'Consumer B tool', 'late' => 'Late tool']
        : ['shared' => 'Consumer B tool', 'short' => 'Short tool'];
    $activities = [];
    foreach ($resources as $key => $toolname) {
        $activities[$key] = ltigae_activity($course, $s, $n, "Consumer $n: $toolname", $tools[$toolname]['uuid'], $burl);
    }
    $consumers = $s['consumers'] ?? [];
    $consumers[$n] = ['site' => $options['site'], 'url' => $CFG->wwwroot, 'course' => (int) $course->id, 'users' => $users,
        'activities' => $activities];
    ltigae_out($state, ['consumers' => $consumers]);
    exit(0);
}

if ($step === 'a:activity') {
    $s = ltigae_state($state);
    $course = $DB->get_record('course', ['shortname' => 'LTIGAECONSUMER'], '*', MUST_EXIST);
    echo json_encode(ltigae_activity($course, $s, $n, $options['name'], $options['uuid'], $burl)) . "\n";
    exit(0);
}

if ($step === 'b:course' || $step === 'b:state') {
    $course = $DB->get_record('course', ['shortname' => $options['shortname']], '*', MUST_EXIST);
    $result = ['course' => (int) $course->id];
    $result['tools'] = [];
    foreach (\enrol_lti\helper::get_lti_tools(['courseid' => $course->id]) as $tool) {
        $result['tools'][$tool->name] = ['id' => (int) $tool->id, 'enrolid' => (int) $tool->enrolid, 'uuid' => $tool->uuid,
            'provisioningmodelearner' => $tool->provisioningmodelearner === null ? null : (int) $tool->provisioningmodelearner];
    }
    $groups = groups_get_all_groups($course->id);
    $result['groups'] = array_map(fn($g) => (int) $g->id, array_column($groups, null, 'name'));
    $config = \tool_ltigroupautoenrol\local\config_repository::get((int) $course->id);
    $result['mapping'] = [];
    if ($config) {
        $toolnames = array_column(\enrol_lti\helper::get_lti_tools(['courseid' => $course->id]), 'name', 'id');
        foreach ($config->mapping->to_array() as $toolid => $groupids) {
            $names = array_map(fn($id) => $groups[$id]->name ?? "group $id", $groupids);
            $result['mapping'][$toolnames[$toolid] ?? "tool $toolid"] = array_values($names);
        }
        $result['enabled'] = $config->enabled;
    }
    if ($step === 'b:state') {
        // Members by user id on site b (the spec learns the ids of its users after their launches).
        $result['members'] = [];
        foreach ($groups as $group) {
            $members = array_map('strval', $DB->get_fieldset_select('groups_members', 'userid', 'groupid = ?', [$group->id]));
            sort($members);
            $result['members'][$group->name] = $members;
        }
        $result['enrolments'] = array_values($DB->get_records_sql(
            "SELECT ue.id, e.name AS tool, ue.userid, ue.status, ue.timestart, ue.timeend
               FROM {user_enrolments} ue JOIN {enrol} e ON e.id = ue.enrolid
              WHERE e.courseid = ? AND e.enrol = 'lti' ORDER BY ue.id",
            [$course->id]
        ));
        $result['events'] = array_values(array_map(function ($row) {
            return [
                'event' => substr($row->eventname, strrpos($row->eventname, '\\') + 1),
                'user' => (string) $row->relateduserid, 'objectid' => (int) $row->objectid,
                'other' => json_decode($row->other, true), 'time' => (int) $row->timecreated,
            ];
        }, $DB->get_records_select(
            'logstore_standard_log',
            'courseid = ? AND component = ?',
            [$course->id, 'tool_ltigroupautoenrol'],
            'id'
        )));
        $dbman = $DB->get_manager();
        $result['diagnostics'] = ['installed' => $dbman->table_exists('tool_ltidiagnostics_obs')];
        if ($result['diagnostics']['installed']) {
            $result['diagnostics']['observations'] = $DB->get_records_sql_menu(
                "SELECT eventtype, COUNT(1) FROM {tool_ltidiagnostics_obs}
                  WHERE courseid = ? AND eventtype IN ('groupassign', 'groupassign_failed') GROUP BY eventtype",
                [$course->id]
            );
        }
    }
    echo json_encode($result, JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
}

fwrite(STDERR, "Unknown step {$step}\n");
exit(2);
