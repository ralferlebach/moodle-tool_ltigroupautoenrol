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
 * Version information for tool_ltigroupautoenrol.
 *
 * @package    tool_ltigroupautoenrol
 * @copyright  2024 Ralf Erlebach
 * @author     Ralf Erlebach <https://github.com/ralferlebach>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * Based upon tool_groupautoenrol by Pascal M.
 * @see https://moodle.org/plugins/tool_groupautoenrol
 * @see https://github.com/pascal-my/moodle-admin_tool_groupautoenrol
 */

defined('MOODLE_INTERNAL') || die();

$plugin->version   = 2026030700;        // The current plugin version (Date: YYYYMMDDXX).
$plugin->requires  = 2024100700;        // Requires this Moodle version (4.5).
$plugin->supported = [405, 502];        // Supported Moodle versions (4.5 to 5.2).
$plugin->component = 'tool_ltigroupautoenrol'; // Full name of the plugin (used for diagnostics).
$plugin->release   = '1.2';             // Human-readable version name.
$plugin->maturity  = MATURITY_STABLE;   // This version's maturity level.
