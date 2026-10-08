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
 * Immutable plugin configuration of one course.
 *
 * @package    tool_ltigroupautoenrol
 * @copyright  2026 Ralf Erlebach
 * @author     Ralf Erlebach - https://github.com/ralferlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class course_config {
    /**
     * Constructor.
     *
     * @param int $courseid Course id.
     * @param bool $enabled Whether automatic group assignment is enabled for the course.
     * @param mapping $mapping Tool to group mapping.
     */
    public function __construct(
        /** @var int Course id. */
        public readonly int $courseid,
        /** @var bool Whether automatic group assignment is enabled. */
        public readonly bool $enabled,
        /** @var mapping Tool to group mapping. */
        public readonly mapping $mapping
    ) {
    }

    /**
     * Returns the default configuration (disabled, empty mapping) for a course without stored settings.
     *
     * @param int $courseid
     * @return self
     */
    public static function create_default(int $courseid): self {
        return new self($courseid, false, new mapping([]));
    }
}
