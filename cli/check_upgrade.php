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
 * Read-only check of the production data before upgrading to 1.2.
 *
 * Run it with the NEW plugin code in place but BEFORE admin/cli/upgrade.php:
 *   php admin/tool/ltigroupautoenrol/cli/check_upgrade.php
 * It reports legacy 0.1 fields, configurations of deleted courses, duplicate configurations per
 * course and invalid mappings, i.e. everything the upgrade will back up, remove or keep. It
 * changes nothing.
 *
 * @package    tool_ltigroupautoenrol
 * @copyright  2026 Ralf Erlebach
 * @author     Ralf Erlebach - https://github.com/ralferlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/admin/tool/ltigroupautoenrol/db/upgradelib.php');

[$options, $unrecognised] = cli_get_params(['help' => false], ['h' => 'help']);
if ($options['help'] || $unrecognised) {
    cli_writeln('Read-only check of tool_ltigroupautoenrol data before the upgrade to 1.2.');
    cli_writeln('Usage: php admin/tool/ltigroupautoenrol/cli/check_upgrade.php');
    exit(0);
}

cli_writeln('Installed version: ' . (get_config('tool_ltigroupautoenrol', 'version') ?: 'not installed'));
foreach (tool_ltigroupautoenrol_upgrade_analyse() as $line) {
    cli_writeln($line);
}
