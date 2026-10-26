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
 * The single access contract of the plugin.
 *
 * The navigation link ({@see can_manage()}) and both pages, for GET and POST
 * ({@see require_manage()}), use this class, so they can not drift apart.
 *
 * @package    tool_ltigroupautoenrol
 * @copyright  2026 Ralf Erlebach
 * @author     Ralf Erlebach - https://github.com/ralferlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class access {
    /** @var string Capability required to configure the plugin and to run the backfill. */
    public const CAPABILITY = 'tool/ltigroupautoenrol:manage';

    /**
     * Whether a user may configure the plugin in a course (used for the navigation link).
     *
     * @param \stdClass $course
     * @param int|null $userid Defaults to the current user.
     * @return bool
     */
    public static function can_manage(\stdClass $course, ?int $userid = null): bool {
        if ((int) $course->id === (int) SITEID) {
            return false;
        }
        return has_capability(self::CAPABILITY, \context_course::instance($course->id), $userid);
    }

    /**
     * Ensures the current user may configure the plugin in a course (used by both pages, GET and POST).
     *
     * The caller must have called require_login($course) before.
     *
     * @param \stdClass $course
     * @return \context_course
     * @throws \moodle_exception For the site course.
     * @throws \required_capability_exception Without the capability.
     */
    public static function require_manage(\stdClass $course): \context_course {
        if ((int) $course->id === (int) SITEID) {
            throw new \moodle_exception('invalidcourseid');
        }
        $context = \context_course::instance($course->id);
        require_capability(self::CAPABILITY, $context);
        return $context;
    }
}
