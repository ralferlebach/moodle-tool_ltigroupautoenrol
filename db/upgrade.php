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
 * Upgrade steps.
 *
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @package   tool_ltigroupautoenrol
 * @copyright 04/04/2024 Mfreak.nl | LdesignMedia.nl - Luuk Verhoeven
 * @author    Luuk Verhoeven
 **/

/**
 * xmldb_tool_ltigroupautoenrol_upgrade
 *
 * @param int $oldversion
 * @return bool true
 */
function xmldb_tool_ltigroupautoenrol_upgrade(int $oldversion): bool {

    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2024040400) {

        // Changing type of field groupslist on table tool_ltigroupautoenrol to text.
        $table = new xmldb_table('tool_ltigroupautoenrol');
        $field = new xmldb_field('groupslist', XMLDB_TYPE_TEXT, null, null, null, null, null, 'use_groupslist');

        // Launch change of type for field groupslist.
        $dbman->change_field_type($table, $field);

        // Groupautoenrol savepoint reached.
        upgrade_plugin_savepoint(true, 2024040400, 'tool', 'ltigroupautoenrol');
    }
    return true;
}
