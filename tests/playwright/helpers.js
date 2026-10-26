/*
 * This file is part of Moodle - https://moodle.org/
 *
 * Moodle is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Moodle is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Moodle.  If not, see <https://www.gnu.org/licenses/>.
 */

/*
 * Shared helpers for the tool_ltigroupautoenrol browser suites.
 *
 * Taken from ralferlebach/moodle-plugintemplate; each plugin carries its own copy, because a
 * Moodle plugin repository has to stand on its own.
 *
 * The awkward parts are deliberate and were each learned from a failing run - see the comments on
 * fillStable() and chooseCourse() in particular.
 */

const { expect } = require('@playwright/test');

/**
 * Fill a field and make sure the value survives.
 *
 * Moodle's login password uses the `toggle_sensitive` component, whose JavaScript initialises after
 * the markup is in place and resets the field. Filling before that happens silently produced an
 * empty password: the value looked right, and the server then answered "Invalid login".
 *
 * @param {import('@playwright/test').Locator} field The input to fill.
 * @param {string} value The value to enter.
 * @returns {Promise<void>}
 */
async function fillStable(field, value) {
  let current = '';
  for (let attempt = 0; attempt < 3; attempt++) {
    await field.fill(value);
    // Give a late-initialising component the chance to reset the field before trusting the value.
    await field.page().waitForTimeout(300);
    current = await field.inputValue();
    if (current === value) {
      return;
    }
  }
  throw new Error(`The field kept losing its value; it now holds "${current}".`);
}

/**
 * Log in through Moodle's login form.
 *
 * Scoped to the login form and with the entered values verified: filling `#password` page-wide can
 * land on a different element, and the empty value only surfaces later as "Invalid login" on the
 * server. Checking the field before submitting turns that into an immediate, obvious failure.
 *
 * @param {import('@playwright/test').Page} page The page under test.
 * @param {string} username The username to use.
 * @param {string} password The password to use.
 * @returns {Promise<void>}
 */
async function loginAs(page, username, password) {
  await page.goto('/login/index.php');
  // Let the page's JavaScript settle first, otherwise a component that initialises afterwards can
  // still discard what was typed.
  await page.waitForLoadState('domcontentloaded');
  const form = page.locator('form[action*="login/index.php"]').first();
  const user = form.locator('input[name="username"]');
  const pass = form.locator('input[name="password"]');
  await fillStable(user, username);
  await fillStable(pass, password);
  await form.locator('#loginbtn, button[type="submit"], input[type="submit"]').first().click();
}

/**
 * Fill a Moodle `passwordunmask` field.
 *
 * The element renders the real input behind an "click to enter text" anchor and keeps it hidden
 * until that anchor is used, so filling the input directly times out.
 *
 * @param {import('@playwright/test').Page} page The page under test.
 * @param {string} name The form field name.
 * @param {string} value The password to type.
 * @returns {Promise<void>}
 */
async function fillPasswordUnmask(page, name, value) {
  const input = page.locator(`input[name="${name}"]`);
  // The element hides the real input behind a "click to enter text" anchor until it is used.
  const edit = page.locator(
    `[data-passwordunmask="wrapper"]:has(input[name="${name}"]) [data-passwordunmask="edit"]`
  );
  if (await edit.count() && !(await input.isVisible())) {
    await edit.first().click();
  }
  await input.waitFor({ state: 'visible' });
  await fillStable(input, value);
}

/**
 * Open a page and assert it rendered rather than redirecting to login or erroring out.
 *
 * @param {import('@playwright/test').Page} page The page under test.
 * @param {string} url The URL to open.
 * @returns {Promise<void>}
 */
async function open(page, url) {
  const response = await page.goto(url);
  // Deliberately not 'networkidle': pages carrying an AJAX autocomplete keep polling and never
  // reach that state, which consumed the whole test timeout.
  await page.waitForLoadState('domcontentloaded');
  expect(response, `No response for ${url}`).not.toBeNull();
  expect(response.status(), `Unexpected HTTP status for ${url}`).toBe(200);
  await expect(page.locator('body')).not.toContainText(/Coding error|Exception|Debug info/i);
}

/**
 * Submit the form on the current page.
 *
 * Scoped to the form itself: a page-wide "first submit button" can pick up an unrelated control
 * from the header, which then never completes the action and only surfaces as a timeout.
 *
 * @param {import('@playwright/test').Page} page The page under test.
 * @returns {Promise<void>}
 */
async function submitForm(page) {
  // Moodle gives the primary submit of a moodleform the id `id_submitbutton`. Searching for "the
  // first form containing an input" instead picked up the search box in the page header, whose
  // button never completes the action and only showed up as a timeout.
  const button = page
    .locator('#id_submitbutton')
    .or(page.locator('[role="main"] button[type="submit"], [role="main"] input[type="submit"]'))
    .first();
  await button.waitFor({ state: 'visible' });
  await button.click();
}

/**
 * Choose a course in Moodle's AJAX course selector.
 *
 * The visible control is an autocomplete; the value that gets submitted lives in a hidden select
 * behind it. Calling selectOption on a plain `select[name="courseid"]` therefore silently did
 * nothing and left the required field empty.
 *
 * @param {import('@playwright/test').Page} page The page under test.
 * @param {string} courseId The course id to select.
 * @returns {Promise<void>}
 */
async function chooseCourse(page, courseId, courseName) {
  const hidden = page.locator('select[name="courseid"]');
  // The hidden select starts out EMPTY: Moodle fills it over AJAX once a search has run. Calling
  // selectOption on it therefore waits forever for an option that does not exist yet, so the
  // autocomplete has to be driven the way a user would.
  if (await hidden.locator(`option[value="${courseId}"]`).count()) {
    await hidden.selectOption(courseId, { force: true });
    return;
  }
  const input = page.locator('input[id^="form_autocomplete_input"]').first();
  await input.click();
  // Typed rather than set, so the widget's key handlers fire and the search actually starts.
  await input.pressSequentially(courseName);
  // Pick the suggestion that actually names the seeded course, not merely the first one the
  // search happened to return.
  const suggestion = page
    .locator('[id^="form_autocomplete_suggestions"] [role="option"]')
    .filter({ hasText: courseName })
    .first();
  await suggestion.waitFor({ state: 'visible', timeout: 15000 });
  await suggestion.click();
  // Confirm the choice reached the field that is actually submitted.
  await expect(hidden).toHaveValue(courseId);
}

module.exports = { fillStable, loginAs, fillPasswordUnmask, open, submitForm, chooseCourse };
