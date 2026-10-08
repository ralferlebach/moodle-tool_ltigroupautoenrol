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
 * Tests of the access contract shared by the navigation link and both pages (GET and POST).
 *
 * @package    tool_ltigroupautoenrol
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @author     Ralf Erlebach - https://github.com/ralferlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_ltigroupautoenrol\local\access
 */
final class access_test extends \advanced_testcase {
    /**
     * Data provider: role => allowed.
     *
     * @return array
     */
    public static function role_provider(): array {
        return [
            'editing teacher' => ['editingteacher', true],
            'manager' => ['manager', true],
            'non-editing teacher' => ['teacher', false],
            'student' => ['student', false],
        ];
    }

    /**
     * Pages deny users without the capability and allow users with it.
     *
     * @dataProvider role_provider
     * @param string $role
     * @param bool $allowed
     */
    public function test_require_manage(string $role, bool $allowed): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, $role);
        $this->setUser($user);

        $this->assertSame($allowed, access::can_manage($course));
        if (!$allowed) {
            $this->expectException(\required_capability_exception::class);
        }
        $this->assertSame(\context_course::instance($course->id)->id, access::require_manage($course)->id);
    }

    /**
     * Users from outside the course and guests are denied.
     */
    public function test_not_enrolled_user_is_denied(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $this->setUser($this->getDataGenerator()->create_user());
        $this->assertFalse(access::can_manage($course));
        $this->expectException(\required_capability_exception::class);
        access::require_manage($course);
    }

    /**
     * The site course is never configurable, not even for administrators.
     */
    public function test_site_course_is_rejected(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $site = get_site();
        $this->assertFalse(access::can_manage($site));
        $this->expectException(\moodle_exception::class);
        access::require_manage($site);
    }

    /**
     * Both pages use the shared contract for every request method (static guard against drift).
     */
    public function test_pages_use_the_shared_contract(): void {
        global $CFG;
        foreach (['manage_lti_group_auto_enrol.php', 'backfill.php'] as $page) {
            $code = file_get_contents($CFG->dirroot . '/admin/tool/ltigroupautoenrol/' . $page);
            $login = strpos($code, 'require_login($course);');
            $check = strpos($code, 'access::require_manage($course)');
            $firstpost = min(array_filter([strpos($code, 'data_submitted()'), strpos($code, 'get_data()')]) ?: [PHP_INT_MAX]);
            $this->assertNotFalse($login, $page);
            $this->assertNotFalse($check, $page);
            $this->assertLessThan($check, $login, "$page: login before capability check");
            $this->assertLessThan($firstpost, $check, "$page: capability check before any POST handling");
        }
    }
}
