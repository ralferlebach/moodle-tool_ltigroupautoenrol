# Moodle-admin_tool_groupautoenrol

Version 4.4.0 (stable version) for Moodle 3.9.0 to 4.4

Plugin to randomly auto enrol students in Moodle courses groups when they are enrolled into the course (whatever the enrol methods : auto-enrol by key, cohorts sync or manual enrol)

## Things to know :
- The plugin uses \core\event\user_enrolment_created (user_enrolled) Moodle event
- If a selected group is deleted, the plugin will ignore it.

## In this stable version (4.4.0) :
- GDPR implementation
- you can choose to enable the plugin in each course
- you can choose to auto-enrol students in all existing course or specific ones

## Compatibility :
- Tested with Moodle 3.9, Moodle 4.2, Moodle 4.3 and Moodle 4.4
- Another version of the plugin exist and works with Moodle 2.5 and 2.7 (I did not test it with the others versions but should work with all 2.x).
It's a local plugin because adding link into "Course administration" menu was not possible for admin tool before Moodle 3.0
You can get it here : https://github.com/pascal-my/moodle-local_groupautoenrol/tree/STABLE

# Installation
* Copy the directory 'groupautoenrol' into the `moodledir/admin/tool` directory.
* Connect to moodle as an administrator and install the plugin.
* Go to a course, create at least one group
* Enable the plugin for the course with the new link "Course administration > Users > Auto-enrol in groups"
Note : this link appears even if the plugin is not enabled for the course

# Credits
* @copyright  2016 Pascal
* @author     Pascal M - https://github.com/pascal-my
* @author     Luuk Verhoeven - https://github.com/luukverhoeven

From Moodle 3.9 onwards this plugin will be maintained by Gemma Lesterhuis, Lesterhuis Training & Consultancy - https://ltnc.nl/
Donations for continuous support are welcome.
https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=Y5DQB9F8AJBKJ&source=url

# Version history :
- 1.1 : stable version not working (bug)
- 1.1.1 : stable version working as local plugin for Moodle 2.x (see https://github.com/pascal-my/moodle-local_groupautoenrol)
- 1.1.2 : stable version working as admin tool plugin for Moodle 3.x
- 3.9.0 : Moodle 3.9 & PHP7.2 version | Moodle 4.0 & PHP 7.4 version | Moodle 4.1 & PHP 8.0 version
- 4.2.0 : Moodle 4.2 & PHP 8.0 version
- 4.2.1 : Fix (https://github.com/Lesterhuis-Training-en-Consultancy/moodle-tool_groupautoenrol/issues/2)  Increase size of groupslist col #2
- 4.2.0 : Moodle 4.2 & PHP 8.1 version
- 4.3.0 : Moodle
- 4.4.0 : Moodle
