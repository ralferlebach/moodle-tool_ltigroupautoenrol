# Moodle-admin_tool_ltigroupautoenrol

Version 1.0 (stable version) for Moodle 4.1 onwards

Plugin to automatically enrol users to pre-defined group(s) when enroling in a course that is shared via LTI. This allows moodle administrators and teachers who act as LTI providers to only maintain one course but differentiating the users coming from different consumers.

This plugin is derived from tool_autoenrolingroups plugin.

## Things to know :
- The plugin uses \core\event\user_enrolment_created (user_enrolled) Moodle event
- If a selected group is deleted, the plugin will ignore it.

## In this stable version (1.0) :
- GDPR implementation
- you can choose to enable the plugin in each course
- you can choose to auto-enrol students in one or more groups each LTI tool deployment#
- only works with LTI 1.3 Advance

## Compatibility :
- Tested with Moodle 4.4 and LTI 1.3 Advance

# Installation
* Copy the directory 'ltigroupautoenrol' into the `moodledir/admin/tool` directory.
* Connect to moodle as an administrator and install the plugin.
* Go to a course, create at least one group
* Enable the plugin for the course with the new link "Course administration > Users > LTI-enrol in groups"
Note : this link appears even if the plugin is not enabled for the course

# License
* @copyright  2024 Ralf Erlebach
* @author     Ralf Erlebach -https://github.com/ralferlebach
* @author     Pascal M - https://github.com/pascal-my
* @author     Luuk Verhoeven - https://github.com/luukverhoeven

This program is free software: you can redistribute it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version.

This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for more details.

You should have received a copy of the GNU General Public License along with this program. If not, see https://www.gnu.org/licenses/.
