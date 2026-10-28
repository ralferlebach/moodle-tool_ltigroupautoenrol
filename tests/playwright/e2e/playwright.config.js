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
 * Playwright configuration of the two-site LTI E2E acceptance test (real launches site A -> site B).
 *
 * The spec ends in .e2e.js so the single-site browser tests in tests/playwright (CI job "playwright") never pick it up.
 *
 * @module     tool_ltigroupautoenrol/e2e_playwright_config
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const { defineConfig } = require('@playwright/test');

module.exports = defineConfig({
  testDir: '.',
  testMatch: '*.e2e.js',
  timeout: 180000,
  expect: { timeout: 20000 },
  workers: 1,
  fullyParallel: false,
  retries: 0,
  reporter: [['list'], ['json', { outputFile: process.env.LTIGAE_E2E_REPORT || '/tmp/ltigae-e2e/report.json' }]],
  outputDir: process.env.LTIGAE_E2E_OUTPUT || '/tmp/ltigae-e2e/test-results',
  use: {
    ignoreHTTPSErrors: false,
    launchOptions: { args: ['--proxy-bypass-list=*.platform-a.test;*.tool-b.test;localhost;127.0.0.1'] },
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    viewport: { width: 1280, height: 900 },
  },
});
