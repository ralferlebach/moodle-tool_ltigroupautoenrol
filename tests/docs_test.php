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
 * Documentation smoke test: the shipped documentation matches the code it describes.
 *
 * Checks the files of the release ZIP (README.md, docs/CHANGES.md, version.php) and, in a repository
 * checkout, the references to the CI workflows.
 *
 * @package    tool_ltigroupautoenrol
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @author     Ralf Erlebach - https://github.com/ralferlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class docs_test extends \advanced_testcase {
    /**
     * The newest change log entry is the current version and release; the README names the supported range.
     */
    public function test_changelog_and_readme_match_version(): void {
        $dir = dirname(__DIR__);
        $plugin = new \stdClass();
        require($dir . '/version.php');

        $changes = file_get_contents($dir . '/docs/CHANGES.md');
        $this->assertSame(1, preg_match('/^## (\S+) \((\d{10})\)$/m', $changes, $first));
        $this->assertSame([$plugin->release, (string) $plugin->version], [$first[1], $first[2]]);

        $readme = file_get_contents($dir . '/README.md');
        $this->assertStringContainsString('docs/CHANGES.md', $readme);
        $this->assertStringContainsString('supported up to Moodle 5.3', $readme);
        $this->assertSame([405, 503], $plugin->supported);
    }

    /**
     * Every CI workflow the documentation refers to exists, and the README has a CI badge for main and development.
     *
     * The workflows, .gitattributes and docs/ (except CHANGES.md) are not part of the release ZIP; the test checks
     * them in a repository checkout (the CI) and is skipped in an installed release. Historical entries of the
     * change log may name removed workflows and are not checked.
     */
    public function test_workflow_references_exist(): void {
        $dir = dirname(__DIR__);
        $workflows = $dir . '/.github/workflows';
        if (!is_dir($workflows)) {
            $this->markTestSkipped('No repository checkout (release ZIP): .github/workflows is not shipped.');
        }
        $references = [];
        $files = array_merge([$dir . '/README.md', $dir . '/.gitattributes'], glob($dir . '/docs/*.md') ?: []);
        foreach ($files as $file) {
            if (!is_file($file) || basename($file) === 'CHANGES.md') {
                continue;
            }
            foreach (file($file) as $number => $line) {
                $where = basename($file) . ':' . ($number + 1);
                // Paths and URLs of workflows, and workflow names in comments of .gitattributes.
                preg_match_all('~(?:\.github|actions)/workflows/([\w.-]+\.ya?ml)~', $line, $matches);
                foreach ($matches[1] as $name) {
                    $references[] = [$name, $where];
                }
                if (basename($file) === '.gitattributes' && preg_match('/^\s*#/', $line)) {
                    preg_match_all('/([\w.-]+\.ya?ml)/', $line, $matches);
                    foreach ($matches[1] as $name) {
                        $references[] = [$name, $where];
                    }
                }
            }
        }
        $this->assertNotEmpty($references);
        foreach ($references as [$name, $where]) {
            $this->assertFileExists("$workflows/$name", "$where refers to a workflow that does not exist");
        }

        $readme = file_get_contents($dir . '/README.md');
        foreach (['main', 'development'] as $branch) {
            $this->assertMatchesRegularExpression(
                '~actions/workflows/[\w.-]+\.ya?ml/badge\.svg\?branch=' . $branch . '\)~',
                $readme,
                "README: CI badge of $branch"
            );
        }
    }

    /**
     * Every status the backfill can record has a message in English and German.
     */
    public function test_every_backfill_status_has_a_message(): void {
        $dir = dirname(__DIR__);
        $reflection = new \ReflectionClass(local\config_repository::class);
        foreach ($reflection->getConstants() as $name => $status) {
            if (strpos($name, 'BACKFILL_') !== 0 || $status === local\config_repository::BACKFILL_DONE) {
                continue;
            }
            foreach (['en', 'de'] as $lang) {
                $string = [];
                include($dir . "/lang/{$lang}/tool_ltigroupautoenrol.php");
                $this->assertArrayHasKey("backfill_status_{$status}", $string, "$lang: $name");
            }
        }
    }
}
