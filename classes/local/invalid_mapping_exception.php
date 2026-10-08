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
 * Thrown when a stored or submitted tool to group mapping is structurally invalid.
 *
 * The debug info names the structural problem only; it never contains user data.
 *
 * @package    tool_ltigroupautoenrol
 * @copyright  2026 Ralf Erlebach
 * @author     Ralf Erlebach - https://github.com/ralferlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class invalid_mapping_exception extends \moodle_exception {
    /**
     * Constructor.
     *
     * @param string $debuginfo Description of the structural problem.
     */
    public function __construct(string $debuginfo) {
        parent::__construct('error_invalidmapping', 'tool_ltigroupautoenrol', '', null, $debuginfo);
    }
}
