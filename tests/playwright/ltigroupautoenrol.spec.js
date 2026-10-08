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
 * Browser tests of the settings and backfill pages: page identity, axe, keyboard and focus,
 * reflow at 200 %/400 % zoom and on a phone, English and German.
 *
 * Requires a site seeded with seed.php (environment variables LTIGAE_*).
 *
 * @module     tool_ltigroupautoenrol/ltigroupautoenrol.spec
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const { execFileSync } = require('child_process');
const { loginAs, open } = require('./helpers');

const env = (name) => {
  const value = process.env[name];
  if (!value) {
    throw new Error(`Environment variable ${name} is missing - run "eval \"$(php seed.php)\"" first.`);
  }
  return value;
};

const settingsUrl = (courseid, lang = 'en') =>
  `/admin/tool/ltigroupautoenrol/manage_lti_group_auto_enrol.php?id=${courseid}&lang=${lang}`;
const backfillUrl = (courseid) => `/admin/tool/ltigroupautoenrol/backfill.php?id=${courseid}&lang=en`;

/**
 * Asserts that the plugin's part of the page has no WCAG 2.1 A/AA violations.
 *
 * Scoped to the main region: the theme and core navigation are outside this plugin's control.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} label
 */
async function expectAccessible(page, label) {
  const results = await new AxeBuilder({ page })
    .include('#region-main')
    .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
    .analyze();
  const summary = results.violations.map((v) => `${v.id}: ${v.nodes.map((n) => n.target.join(' ')).join(', ')}`);
  expect(summary, `axe violations on ${label}`).toEqual([]);
}

/**
 * Moves the focus with Tab until the locator has it (fails after a bounded number of steps).
 *
 * @param {import('@playwright/test').Page} page
 * @param {import('@playwright/test').Locator} target
 */
async function tabTo(page, target) {
  for (let i = 0; i < 40; i++) {
    if (await target.evaluate((el) => el === document.activeElement)) {
      return;
    }
    await page.keyboard.press('Tab');
  }
  throw new Error('Element not reachable with the Tab key');
}

/**
 * Asserts that the focused element shows a visible focus indicator.
 *
 * @param {import('@playwright/test').Locator} target
 */
async function expectVisibleFocus(target) {
  await expect(target).toBeFocused();
  const indicator = await target.evaluate((el) => {
    const style = window.getComputedStyle(el);
    return {
      outline: style.outlineStyle !== 'none' && parseFloat(style.outlineWidth) > 0,
      shadow: style.boxShadow && style.boxShadow !== 'none',
    };
  });
  expect(indicator.outline || indicator.shadow, 'visible focus indicator').toBeTruthy();
}

/**
 * Asserts that the page does not scroll horizontally (WCAG 1.4.10 reflow).
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} label
 */
async function expectNoHorizontalScroll(page, label) {
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
  expect(overflow, `horizontal overflow on ${label}`).toBeLessThanOrEqual(1);
}

/**
 * Runs the queued adhoc tasks of the plugin, like cron would.
 */
function runAdhocTasks() {
  execFileSync('php', [`${env('LTIGAE_MOODLE_DIR')}/admin/cli/adhoc_task.php`, '--execute'], { stdio: 'pipe' });
}

test.describe('tool_ltigroupautoenrol', () => {
  test.beforeEach(async ({ page }) => {
    await loginAs(page, env('LTIGAE_TEACHER'), env('LTIGAE_PASSWORD'));
  });

  test('settings page: identity, accessibility and keyboard operation', async ({ page }) => {
    await open(page, settingsUrl(env('LTIGAE_COURSE')));
    await expect(page.locator('#region-main h2')).toHaveText('LTI-enrol settings');
    await expect(page.locator('body')).toHaveAttribute('id', 'page-admin-tool-ltigroupautoenrol-manage_lti_group_auto_enrol');
    await expectAccessible(page, 'settings page');

    // Every select has a programmatic label naming its tool.
    const alpha = page.getByLabel('Groups for LTI tool: Tool Alpha');
    const beta = page.getByLabel('Groups for LTI tool: Tool Beta');
    await expect(alpha).toHaveAttribute('multiple', '');
    await expect(beta).toBeVisible();

    // Keyboard only: enable, choose a group, save.
    const enable = page.getByRole('checkbox', { name: 'Enable automatic enrolment in groups for this course' });
    await enable.focus();
    await expectVisibleFocus(enable);
    await page.keyboard.press('Space');
    await expect(enable).toBeChecked();
    await tabTo(page, alpha);
    await expectVisibleFocus(alpha);
    // Multi-selection with the keyboard: arrow key selects the first group, Shift+arrow extends.
    await page.keyboard.press('ArrowDown');
    await expect(alpha.locator('option:checked')).toHaveCount(1);
    await page.keyboard.press('Shift+ArrowDown');
    await expect(alpha.locator('option:checked')).toHaveText(['Consumer A', 'Consumer B']);
    const save = page.getByRole('button', { name: 'Save changes' });
    await tabTo(page, save);
    await expectVisibleFocus(save);
    await page.keyboard.press('Enter');
    await page.waitForLoadState('domcontentloaded');
    await expect(page.getByRole('alert').filter({ hasText: 'Changes saved' })).toBeVisible();
    await expect(page.getByLabel('Groups for LTI tool: Tool Alpha').locator('option:checked')).toHaveCount(2);
    await expect(page.getByLabel('Groups for LTI tool: Tool Beta').locator('option:checked')).toHaveCount(0);
    await expectAccessible(page, 'settings page after saving');
  });

  test('empty states are explained and accessible', async ({ page }) => {
    await open(page, settingsUrl(env('LTIGAE_NOGROUPS_COURSE')));
    const create = page.getByRole('link', { name: 'Create groups first!' });
    await expect(create).toBeVisible();
    await create.focus();
    await expectVisibleFocus(create);
    await expectAccessible(page, 'course without groups');

    await open(page, settingsUrl(env('LTIGAE_NOTOOLS_COURSE')));
    await expect(page.locator('#region-main')).toContainText('This course does not publish any LTI 1.3 tool yet');
    await expect(page.getByRole('link', { name: 'Manage enrolment methods' })).toBeVisible();
    await expectAccessible(page, 'course without LTI tools');
  });

  test('backfill: preview, keyboard confirmation and status', async ({ page }) => {
    const courseid = env('LTIGAE_BACKFILL_COURSE');
    await open(page, backfillUrl(courseid));
    await expect(page.locator('#region-main h2')).toHaveText('Assign existing LTI participants');
    const table = page.getByRole('table', { name: 'Preview per LTI tool' });
    await expect(table).toBeVisible();
    await expect(table.getByRole('row').nth(1)).toContainText('Tool Alpha');
    await expect(page.locator('#region-main')).toContainText('Group memberships to be added: 1.');
    await expectAccessible(page, 'backfill preview');

    const confirm = page.getByRole('button', { name: 'Assign participants' });
    await tabTo(page, confirm);
    await expectVisibleFocus(confirm);
    await page.keyboard.press('Enter');
    await page.waitForLoadState('domcontentloaded');
    await expect(page.locator('#region-main')).toContainText('has been scheduled');
    await expect(page.locator('#region-main')).toContainText('It will be processed by the next cron run.');
    await expectAccessible(page, 'settings page with queued status');

    runAdhocTasks();
    await open(page, backfillUrl(courseid));
    await expect(page.locator('#region-main')).toContainText('Added: 1, already member: 0, skipped: 0, errors: 0.');
    await expect(page.locator('#region-main')).toContainText('All active LTI participants are already members');
    await expectAccessible(page, 'backfill page with result');
  });

  for (const [label, viewport] of [
    ['200 % zoom', { width: 640, height: 900 }],
    ['400 % zoom', { width: 320, height: 900 }],
    ['phone', { width: 375, height: 812 }],
  ]) {
    test(`reflow without horizontal scrolling at ${label}`, async ({ page }) => {
      await page.setViewportSize(viewport);
      for (const url of [settingsUrl(env('LTIGAE_COURSE')), backfillUrl(env('LTIGAE_BACKFILL_COURSE'))]) {
        await open(page, url);
        await expectNoHorizontalScroll(page, `${url} at ${label}`);
        const main = await page.locator('#region-main').boundingBox();
        expect(main.x + main.width, `main region inside the viewport at ${label}`).toBeLessThanOrEqual(viewport.width + 1);
      }
    });
  }

  test('German user interface', async ({ page }) => {
    await open(page, settingsUrl(env('LTIGAE_COURSE'), 'de'));
    await expect(page.locator('html')).toHaveAttribute('lang', 'de');
    await expect(page.locator('#region-main h2')).toHaveText('Gruppeneinschreibungen via LTI bearbeiten');
    await expect(page.getByRole('checkbox', { name: 'Automatische Gruppenzuordnung via LTI für diesen Kurs aktivieren' })).toBeVisible();
    await expect(page.getByLabel('Gruppen für LTI-Tool: Tool Alpha')).toBeVisible();
    await expectAccessible(page, 'settings page (de)');
    await open(page, settingsUrl(env('LTIGAE_COURSE'), 'en'));
  });

  test('users without the capability get no access', async ({ page, context }) => {
    await context.clearCookies();
    await loginAs(page, env('LTIGAE_ASSISTANT'), env('LTIGAE_PASSWORD'));
    for (const url of [settingsUrl(env('LTIGAE_COURSE')), backfillUrl(env('LTIGAE_BACKFILL_COURSE'))]) {
      await page.goto(url);
      await expect(page.locator('body')).toContainText('Sorry, but you do not currently have permissions to do that');
      await expect(page.locator('form#mform1, [name="enable_enrol"]')).toHaveCount(0);
    }
  });
});
