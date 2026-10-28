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

namespace tool_ltigroupautoenrol\tests;

/**
 * Observers for events_test: records events and simulates a PHP error inside the group API.
 *
 * Used where an event sink can not be used, because a sink also stops the observers whose failure is simulated.
 *
 * @package    tool_ltigroupautoenrol
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @author     Ralf Erlebach - https://github.com/ralferlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class event_recorder {
    /** @var \core\event\base[] Recorded events. */
    public static $events = [];

    /**
     * Records an event.
     *
     * @param \core\event\base $event
     * @return void
     */
    public static function record(\core\event\base $event): void {
        self::$events[] = $event;
    }

    /**
     * Throws a PHP error (core catches only exceptions of observers, so it leaves groups_add_member()).
     *
     * @param \core\event\base $event
     * @return void
     */
    public static function fail(\core\event\base $event): void {
        throw new \TypeError('simulated');
    }
}
