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
 * Version info
 *
 * @package    tool_ltigroupautoenrol
 * @copyright  2024 Ralf Erlebach
 * @author     Ralf Erlebach - https://github.com/ralferlebach
 * based upon tool_ltigroupautoenrol by Pascal M
 * tool_autoenrolingroups https://moodle.org/plugins/tool_groupautoenrol
 * https://github.com/pascal-my/moodle-admin_tool_groupautoenrol
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->version = 2024100600;    // The (date) version of this module + 2 extra digital for daily versions.
$plugin->requires = 2022112800;   // Requires this Moodle version - at least 4.1.0.
$plugin->supportedmoodles = [401, 402, 403, 404];
$plugin->cron = 0;
$plugin->component = 'tool_ltigroupautoenrol';
$plugin->release = '1.0.1';
$plugin->maturity = MATURITY_STABLE;
