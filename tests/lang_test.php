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

namespace tool_ltigroupautoenrol;

/**
 * Consistency of the English and German language packs.
 *
 * @package    tool_ltigroupautoenrol
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @author     Ralf Erlebach - https://github.com/ralferlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class lang_test extends \basic_testcase {
    /**
     * Loads the strings of a language file.
     *
     * @param string $lang
     * @return array
     */
    private function strings(string $lang): array {
        global $CFG;
        $string = [];
        include($CFG->dirroot . "/admin/tool/ltigroupautoenrol/lang/{$lang}/tool_ltigroupautoenrol.php");
        return $string;
    }

    /** @var array English strings of the published releases (1.0.1 - 1.1), translated in AMOS. */
    private const PUBLISHED = [
        'auto_group_enrol_form_no_group_found' => 'Create groups first!',
        'auto_group_form_enable_enrol' => 'Enable automatic enrolment in groups for this course',
        'auto_group_form_groupslist' => 'Choose groups for: ',
        'auto_group_form_page_title' => 'LTI-enrol settings',
        'coursemenu_item' => 'LTI-enrol in groups',
        'menu_auto_groups' => 'LTI-enrol in groups',
        'pluginname' => 'Automatic enrolment in groups by LTI tool',
        'privacy:null_reason' => 'No userdata collected by this plugin.',
    ];

    /**
     * A published string keeps its text; a changed text needs a new identifier.
     *
     * Community translations from AMOS (language packs in moodledata) override the plugin's own
     * language files. A changed text under an old identifier would therefore keep showing the old
     * translation, e.g. "Gruppe wählen für:" without the tool name for every group select.
     */
    public function test_published_strings_keep_their_meaning(): void {
        $en = $this->strings('en');
        foreach (self::PUBLISHED as $key => $text) {
            if (array_key_exists($key, $en)) {
                $this->assertSame($text, $en[$key], "Text of published string $key changed: use a new identifier");
            }
        }
    }

    /**
     * Both packs define the same keys with the same placeholders, and every string used in the code exists.
     */
    public function test_en_and_de_are_complete_and_consistent(): void {
        global $CFG;
        $en = $this->strings('en');
        $de = $this->strings('de');
        $this->assertSame([], array_diff(array_keys($en), array_keys($de)), 'missing in de');
        $this->assertSame([], array_diff(array_keys($de), array_keys($en)), 'missing in en');
        foreach ($en as $key => $text) {
            preg_match_all('/\{\$a(->\w+)?\}/', $text, $enplaceholders);
            preg_match_all('/\{\$a(->\w+)?\}/', $de[$key], $deplaceholders);
            sort($enplaceholders[0]);
            sort($deplaceholders[0]);
            $this->assertSame($enplaceholders[0], $deplaceholders[0], "placeholders of $key");
            $this->assertNotSame('', trim($de[$key]), "empty de string $key");
        }

        $dir = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($CFG->dirroot . '/admin/tool/ltigroupautoenrol'));
        foreach ($dir as $file) {
            if ($file->getExtension() !== 'php' || str_contains($file->getPathname(), '/tests/')) {
                continue;
            }
            preg_match_all("/get_string\(\s*'([a-z0-9_:]+)',\s*'tool_ltigroupautoenrol'/", file_get_contents($file), $used);
            foreach ($used[1] as $key) {
                $this->assertArrayHasKey($key, $en, "string $key used in {$file->getFilename()}");
            }
        }
    }
}
