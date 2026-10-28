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

namespace tool_ltigroupautoenrol\event;

/**
 * Common contract of the group assignment events of this plugin.
 *
 * Context: the course. objectid: the enrol_lti instance (table "enrol") the enrolment belongs to.
 * relateduserid: the LTI-enrolled user (may be missing for a failure of a whole backfill run).
 * "other" holds ids, counters and short machine codes only: the set of keys is fixed by
 * {@see get_other_keys()} and any additional key is rejected, so no name, e-mail address or
 * group name can end up in the log.
 *
 * @package    tool_ltigroupautoenrol
 * @copyright  2026 Ralf Erlebach
 * @author     Ralf Erlebach - https://github.com/ralferlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class group_assignment_base extends \core\event\base {
    /** @var string Source: the enrolment observer (new LTI enrolment). */
    public const SOURCE_EVENT = 'event';

    /** @var string Source: the backfill ("Assign existing LTI participants"). */
    public const SOURCE_BACKFILL = 'backfill';

    /**
     * Keys of "other" with their type ("int" or "string"); no other key is allowed.
     *
     * @return array<string, string>
     */
    abstract protected static function get_other_keys(): array;

    /**
     * Initialise the event data.
     *
     * @return void
     */
    protected function init() {
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_OTHER;
        $this->data['objecttable'] = 'enrol';
    }

    /**
     * Returns the settings page of the course.
     *
     * @return \moodle_url
     */
    public function get_url() {
        return new \moodle_url('/admin/tool/ltigroupautoenrol/manage_lti_group_auto_enrol.php', ['id' => $this->courseid]);
    }

    /**
     * Validates the event data against the contract described in the class comment.
     *
     * @return void
     * @throws \coding_exception
     */
    protected function validate_data() {
        parent::validate_data();
        if ($this->contextlevel != CONTEXT_COURSE || empty($this->courseid)) {
            throw new \coding_exception('The context must be a course context.');
        }
        if (!isset($this->objectid) || !is_number($this->objectid)) {
            throw new \coding_exception('The \'objectid\' (enrol instance id) must be set.');
        }
        self::validate_other(is_array($this->other) ? $this->other : [], static::get_other_keys());
    }

    /**
     * Checks "other": exactly the given keys, each of its type (int: not negative), and a known source.
     *
     * @param array $other
     * @param array $keys Allowed keys with their type ("int" or "string").
     * @return void
     * @throws \coding_exception
     */
    private static function validate_other(array $other, array $keys): void {
        $unexpected = array_diff(array_keys($other), array_keys($keys));
        if ($unexpected) {
            throw new \coding_exception('Unexpected keys in \'other\': ' . implode(', ', $unexpected));
        }
        foreach ($keys as $key => $type) {
            if (!array_key_exists($key, $other) || !self::is_of_type($other[$key], $type)) {
                throw new \coding_exception("The '{$key}' value must be set in other and be of type {$type}.");
            }
        }
        if (!in_array($other['source'], [self::SOURCE_EVENT, self::SOURCE_BACKFILL], true)) {
            throw new \coding_exception('The \'source\' value in other must be \'event\' or \'backfill\'.');
        }
    }

    /**
     * Whether a value is a string or a non-negative int.
     *
     * @param mixed $value
     * @param string $type "int" or "string".
     * @return bool
     */
    private static function is_of_type($value, string $type): bool {
        return $type === 'int' ? is_int($value) && $value >= 0 : is_string($value);
    }

    /**
     * Restore mapping of the objectid (enrol instance).
     *
     * @return array
     */
    public static function get_objectid_mapping() {
        return ['db' => 'enrol', 'restore' => 'enrol'];
    }

    /**
     * Restore mapping of "other": the tool id is not mapped (no restore mapping exists for enrol_lti_tools).
     *
     * @return array
     */
    public static function get_other_mapping() {
        return ['toolid' => self::NOT_MAPPED];
    }
}
