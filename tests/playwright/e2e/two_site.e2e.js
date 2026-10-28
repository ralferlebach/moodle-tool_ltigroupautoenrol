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
 * Two-site end-user acceptance test (GA-01, GA-03): real LTI 1.3 launches from two consumers - site a and site a2,
 * two Moodle 4.5 platforms with their own issuers - into one shared course on site b (Moodle 5.3, tool with
 * tool_ltigroupautoenrol).
 *
 * Scenario ids E1..E7 are referenced by docs/ACCEPTANCE_SCENARIOS.md. Run with tests/playwright/e2e/run.sh (holds the
 * stack lock). Users are identified on b by the user id the launched page reports (M.cfg.userId), so the test does
 * not depend on how a source plugin forms the LTI "sub".
 *
 * @module     tool_ltigroupautoenrol/two_site_e2e
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const path = require('path');
const e2e = require(process.env.E2E_LIB || '/home/claude/e2e/lib/e2e.js');

const E2E_HOME = process.env.E2E_HOME || '/home/claude/e2e';
const STATE = process.env.LTIGAE_E2E_STATE || '/tmp/ltigae-e2e/state.json';
const FIXTURE = path.join(__dirname, 'fixture.php');
const CONFIG = {
  a: process.env.E2E_A_CONFIG || '/home/claude/moodle/config.php',
  a2: process.env.E2E_A_CONFIG || '/home/claude/moodle/config.php',
  b: process.env.E2E_B_CONFIG || '/home/claude/moodle53/config.php',
};
const COPY = 'LTIGAEE2E-COPY';

/**
 * Runs a command on a site with the stack's moodle_cli (correct wwwroot and proxy settings) and returns stdout.
 *
 * @param {string} site 'a' or 'b'
 * @param {string[]} args Script (relative to the tree root or absolute) and arguments.
 * @returns {string}
 */
function cli(site, args) {
  const quoted = args.map((a) => `'${String(a).replace(/'/g, "'\\''")}'`).join(' ');
  return execFileSync('bash', ['-c', `. ${E2E_HOME}/bin/e2e-env.sh; moodle_cli ${site} ${quoted}`],
    { stdio: ['ignore', 'pipe', 'pipe'], timeout: 600000 }).toString();
}

/**
 * Runs a fixture step and returns its JSON output.
 *
 * @param {string} site
 * @param {string} step
 * @param {object} extra Additional options.
 * @returns {object}
 */
function fixture(site, step, extra = {}) {
  const args = [FIXTURE, `--site=${site}`, `--config=${CONFIG[site]}`, `--step=${step}`, `--state=${STATE}`];
  for (const [k, v] of Object.entries(extra)) {
    args.push(`--${k}=${v}`);
  }
  const lines = cli(site, args).trim().split('\n');
  return JSON.parse(lines[lines.length - 1]);
}

/**
 * Runs the queued adhoc tasks of one class on site B (like cron, but only this class).
 *
 * @param {string} classname
 */
function runTasks(classname) {
  cli('b', ['admin/cli/adhoc_task.php', '--execute', `--classname=${classname}`]);
}

let s;
/** User ids on site b of the fixture users, learned from their launches. */
const bids = {};
const bid = (name) => {
  if (!bids[name]) {
    throw new Error(`${name} has not launched yet`);
  }
  return bids[name];
};
const ids = (...names) => names.map(bid).sort();
const stateOf = (shortname = 'LTIGAEE2E') => fixture('b', 'state', { shortname });
const consumerOf = (name) => (s.consumers[1].users[name] ? s.consumers[1] : s.consumers[2]);

/**
 * Logs a user of its consumer site in and launches an activity; returns when the user is in the course on b.
 *
 * @param {import('@playwright/test').Browser} browser
 * @param {string} name Fixture user (alice, bob, ...).
 * @param {string} viewurl Activity URL on the user's consumer site.
 * @returns {Promise<string>} User id on site b.
 */
async function launch(browser, name, viewurl) {
  const consumer = consumerOf(name);
  const ctx = await browser.newContext();
  const page = await ctx.newPage();
  try {
    await e2e.login(page, consumer.site, consumer.users[name], { urls: { [consumer.site]: consumer.url } });
    await page.goto(viewurl);
    await e2e.waitForLaunch(page, /b\.tool-b\.test\/course\/view\.php/);
    const userid = String(await page.evaluate(() => window.M.cfg.userId));
    expect(Number(userid)).toBeGreaterThan(2);
    if (bids[name]) {
      expect(userid, `${name} is the same user on b as before`).toBe(bids[name]);
    }
    bids[name] = userid;
    return userid;
  } finally {
    await ctx.close();
  }
}

e2e.screenshotOnFailure(test);

test.describe.serial('tool_ltigroupautoenrol: two LTI consumers, real launches', () => {
  test.beforeAll(() => {
    require('fs').rmSync(STATE, { force: true });
    fixture('a', 'types', { n: 1 });
    fixture('a2', 'types', { n: 2 });
    fixture('b', 'provider');
    fixture('a', 'consumer', { n: 1 });
    fixture('a2', 'consumer', { n: 2 });
    s = JSON.parse(require('fs').readFileSync(STATE, 'utf8'));
    expect(s.types[1].issuer).not.toBe(s.types[2].issuer);
  });

  test('E1 participants of two consumers land in the groups of their tool (multiple enrolment included)', async ({ browser }) => {
    const c1 = s.consumers[1].activities;
    const c2 = s.consumers[2].activities;
    await launch(browser, 'alice', c1.shared.view_url);
    await launch(browser, 'bob', c2.shared.view_url);
    // carol (consumer 1) uses two resources of the provider: enrolled through both tools.
    await launch(browser, 'carol', c1.shared.view_url);
    await launch(browser, 'carol', c1.second.view_url);

    const st = stateOf();
    expect(st.members['Uni A']).toEqual(ids('alice', 'carol'));
    expect(st.members['Uni A lab']).toEqual(ids('alice', 'carol'));
    expect(st.members['Uni B']).toEqual(ids('bob', 'carol'));
    expect(st.members['All consumers']).toEqual(ids('alice', 'bob', 'carol'));
    expect(st.members['Late group']).toEqual([]);
    expect(st.enrolments.filter((e) => String(e.userid) === bid('carol')).map((e) => e.tool).sort())
      .toEqual(['Consumer A tool', 'Consumer B tool']);
  });

  test('E2 the teacher on B sees the group sizes', async ({ browser }) => {
    const ctx = await browser.newContext();
    const page = await ctx.newPage();
    await e2e.login(page, 'b', s.provider.teacher);
    await page.goto(`${s.provider.url}/group/index.php?id=${s.provider.course}`);
    const options = await page.locator('select#groups option').allInnerTexts();
    for (const label of ['Uni A (2)', 'Uni A lab (2)', 'Uni B (2)', 'All consumers (3)', 'Late group (0)']) {
      expect(options.map((o) => o.trim())).toContain(label);
    }
    await ctx.close();
  });

  test('E3 every assignment is logged as group_assignment_completed with ids and counters only (GA-03)', async () => {
    const st = stateOf();
    const tools = s.provider.tools;
    const completed = st.events.filter((e) => e.event === 'group_assignment_completed');
    const bykey = (name, tool) => completed.filter((e) => e.user === bid(name) && e.objectid === tools[tool].enrolid);
    expect(bykey('alice', 'Consumer A tool').map((e) => e.other)).toEqual([
      { source: 'event', added: 3, existing: 0, errors: 0, toolid: tools['Consumer A tool'].id }]);
    expect(bykey('bob', 'Consumer B tool').map((e) => e.other)).toEqual([
      { source: 'event', added: 2, existing: 0, errors: 0, toolid: tools['Consumer B tool'].id }]);
    expect(bykey('carol', 'Consumer B tool').map((e) => e.other)).toEqual([
      { source: 'event', added: 1, existing: 1, errors: 0, toolid: tools['Consumer B tool'].id }]);
    expect(st.events.filter((e) => e.event === 'group_assignment_failed')).toEqual([]);
    for (const e of st.events) {
      expect(Object.keys(e.other).sort()).toEqual(['added', 'errors', 'existing', 'source', 'toolid']);
    }
  });

  test('E4 a second launch changes nothing', async ({ browser }) => {
    const before = stateOf();
    await launch(browser, 'alice', s.consumers[1].activities.shared.view_url);
    const after = stateOf();
    expect(after.members).toEqual(before.members);
    expect(after.events.length).toBe(before.events.length);
  });

  test('E5 later backfill assigns earlier participants, an expired enrolment is left out', async ({ browser }) => {
    // dave (consumer 2): enrolment period of the "Short tool" is 20 s; erin (consumer 1): "Late tool". Neither tool is
    // mapped yet.
    await launch(browser, 'dave', s.consumers[2].activities.short.view_url);
    await launch(browser, 'erin', s.consumers[1].activities.late.view_url);
    let st = stateOf();
    expect(st.members['Late group']).toEqual([]);
    const daveend = st.enrolments.find((e) => String(e.userid) === bid('dave') && e.tool === 'Short tool').timeend;
    expect(Number(daveend)).toBeGreaterThan(0);
    // Wait until dave's enrolment has expired.
    while (Date.now() / 1000 <= Number(daveend) + 1) {
      await new Promise((r) => setTimeout(r, 1000));
    }

    const ctx = await browser.newContext();
    const page = await ctx.newPage();
    await e2e.login(page, 'b', s.provider.teacher);
    await page.goto(`${s.provider.url}/admin/tool/ltigroupautoenrol/manage_lti_group_auto_enrol.php?id=${s.provider.course}`);
    await page.getByLabel('Groups for LTI tool: Late tool').selectOption([{ label: 'Late group' }]);
    await page.getByLabel('Groups for LTI tool: Short tool').selectOption([{ label: 'Short group' }]);
    await page.getByRole('button', { name: 'Save changes' }).click();
    await expect(page.getByRole('alert').filter({ hasText: 'Changes saved' })).toBeVisible();
    await page.getByRole('button', { name: /Assign existing LTI participants/ }).click();
    const table = page.getByRole('table', { name: 'Preview per LTI tool' });
    await expect(table.getByRole('row').filter({ hasText: 'Late tool' }).locator('td').nth(2)).toHaveText('1');
    await expect(table.getByRole('row').filter({ hasText: 'Late tool' }).locator('td').nth(3)).toHaveText('1');
    // dave's expired enrolment is neither counted nor missing.
    await expect(table.getByRole('row').filter({ hasText: 'Short tool' }).locator('td').nth(2)).toHaveText('0');
    await expect(table.getByRole('row').filter({ hasText: 'Short tool' }).locator('td').nth(3)).toHaveText('0');
    await expect(page.locator('#region-main')).toContainText('Group memberships to be added: 1.');
    await page.getByRole('button', { name: 'Assign participants' }).click();
    await expect(page.locator('#region-main')).toContainText('has been scheduled');
    runTasks('\\tool_ltigroupautoenrol\\task\\backfill_task');
    await page.goto(`${s.provider.url}/admin/tool/ltigroupautoenrol/backfill.php?id=${s.provider.course}`);
    await expect(page.locator('#region-main')).toContainText(/Added: 1, already member: \d+, skipped: 0, errors: 0\./);
    await expect(page.locator('#region-main')).toContainText('All active LTI participants are already members');
    await ctx.close();

    st = stateOf();
    expect(st.members['Late group']).toEqual(ids('erin'));
    expect(st.members['Short group']).toEqual([]);
    const backfill = st.events.filter((e) => e.other.source === 'backfill');
    expect(backfill.map((e) => [e.event, e.user, e.other.added])).toEqual([['group_assignment_completed', bid('erin'), 1]]);
  });

  test('E6 a course copy maps the copied tools to the copied groups; launches into the copy are assigned there', async ({ browser }) => {
    const ctx = await browser.newContext();
    const page = await ctx.newPage();
    await e2e.login(page, 'b', 'admin', e2e.loadSeed());
    await page.goto(`${s.provider.url}/backup/copy.php?id=${s.provider.course}`);
    // Role-based locators: the help icons carry "Help with <label>" as accessible name.
    await page.getByRole('textbox', { name: 'Course full name' }).fill('LTIGAE shared course copy');
    await page.getByRole('textbox', { name: 'Course short name' }).fill(COPY);
    await page.getByRole('combobox', { name: 'Include user data' }).selectOption('1');
    await page.getByRole('checkbox', { name: 'Student' }).check();
    await page.getByRole('button', { name: 'Copy and return' }).click();
    await page.waitForLoadState('domcontentloaded');
    await ctx.close();
    runTasks('\\core\\task\\asynchronous_copy_task');

    const copy = fixture('b', 'course', { shortname: COPY });
    expect(copy.course).not.toBe(s.provider.course);
    expect(copy.enabled).toBe(true);
    expect(copy.mapping).toEqual({
      'Consumer A tool': ['Uni A', 'Uni A lab', 'All consumers'],
      'Consumer B tool': ['Uni B', 'All consumers'],
      'Late tool': ['Late group'],
      'Short tool': ['Short group'],
    });
    for (const name of Object.keys(copy.tools)) {
      expect(copy.tools[name].uuid).not.toBe(s.provider.tools[name].uuid);
    }

    // Core defect (Moodle 4.5 and 5.3): backup_enrol_lti_plugin does not back up the provisioning modes, so the copied
    // LTI 1.3 tools have none and every launch into them fails in auth_plugin_lti::complete_login() until the enrolment
    // method is saved once. Workaround through the user interface (here as admin, the copy has no teacher): open and save the enrolment methods.
    for (const name of ['Consumer A tool', 'Consumer B tool']) {
      expect(copy.tools[name].provisioningmodelearner, `copied ${name}: provisioning mode (core defect)`).toBeNull();
    }
    const fix = await browser.newContext();
    const fixpage = await fix.newPage();
    await e2e.login(fixpage, 'b', 'admin', e2e.loadSeed());
    for (const name of ['Consumer A tool', 'Consumer B tool']) {
      await fixpage.goto(`${s.provider.url}/enrol/editinstance.php?type=lti&courseid=${copy.course}&id=${copy.tools[name].enrolid}`);
      await fixpage.getByRole('button', { name: 'Save changes' }).click();
      await fixpage.waitForLoadState('domcontentloaded');
    }
    await fix.close();
    const saved = fixture('b', 'course', { shortname: COPY });
    for (const name of ['Consumer A tool', 'Consumer B tool']) {
      expect(saved.tools[name].provisioningmodelearner).not.toBeNull();
    }

    // Core's course copy does not copy enrol_lti user enrolments, but it copies enrol_lti's own user records (with user
    // data) without their deployment binding. Core defect (4.5 and 5.3): a participant who already used the copied tool
    // (alice) can not launch into the copy ("Cannot create duplicate LTI user"). Recorded here; the assignment in the
    // copy is shown with participants who had not used that tool before (erin: consumer 1, frank: consumer 2).
    const actA = fixture('a', 'activity', { n: 1, name: 'Consumer 1: copied course', uuid: copy.tools['Consumer A tool'].uuid });
    const actB = fixture('a2', 'activity', { n: 2, name: 'Consumer 2: copied course', uuid: copy.tools['Consumer B tool'].uuid });
    expect(stateOf(COPY).enrolments).toEqual([]);
    await expect(launch(browser, 'alice', actA.view_url)).rejects.toThrow(/Cannot create duplicate LTI user/);
    await launch(browser, 'erin', actA.view_url);
    await launch(browser, 'frank', actB.view_url);
    const st = stateOf(COPY);
    expect(st.members['Uni A']).toEqual(ids('erin'));
    expect(st.members['Uni A lab']).toEqual(ids('erin'));
    expect(st.members['Uni B']).toEqual(ids('frank'));
    expect(st.members['All consumers']).toEqual(ids('erin', 'frank'));
    // The original course is untouched by the launch into the copy.
    const original = stateOf();
    expect(original.enrolments.map((e) => String(e.userid))).not.toContain(bid('frank'));
  });

  test('E7 the plugin works with diagnostics inert or active; diagnostics only reads the events (GA-03)', async ({ browser }, testInfo) => {
    const before = stateOf();
    testInfo.annotations.push({ type: 'diagnostics', description: JSON.stringify(before.diagnostics) });
    const cfg = ['admin/cli/cfg.php', '--component=tool_ltidiagnostics', '--name=recordevents'];
    // Previous value of the setting: null = not installed, '' = not set (cfg.php exits with 3), else the value.
    let previous = null;
    if (before.diagnostics.installed) {
      try {
        previous = cli('b', cfg).trim();
      } catch (e) {
        previous = '';
      }
      cli('b', [...cfg, '--set=0']);
    }
    try {
      await launch(browser, 'gina', s.consumers[2].activities.shared.view_url);
    } finally {
      // Restore the shared stack's configuration.
      if (previous === '') {
        cli('b', [...cfg, '--unset']);
      } else if (previous !== null) {
        cli('b', [...cfg, `--set=${previous}`]);
      }
    }
    const after = stateOf();
    // Without (inert) diagnostics: assigned and logged as usual.
    expect(after.members['Uni B']).toContain(bid('gina'));
    expect(after.events.filter((e) => e.user === bid('gina')).map((e) => e.other.added)).toEqual([2]);
    if (before.diagnostics.installed) {
      // Inert: no new observation; active (earlier launches): the assignments were observed.
      expect(after.diagnostics.observations).toEqual(before.diagnostics.observations);
      if (previous !== '0') {
        expect(Number(before.diagnostics.observations.groupassign || 0)).toBeGreaterThanOrEqual(4);
      }
    }
  });
});
