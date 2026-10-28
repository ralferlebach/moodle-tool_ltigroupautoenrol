@tool @tool_ltigroupautoenrol @tool_ltigroupautoenrol_acceptance
Feature: End-user acceptance of the automatic group assignment of LTI participants
  In order to separate the participants of several LTI consumers in one shared course
  As a teacher
  I need LTI participants to land in the groups of their consumer's tool, now and after a later backfill or a course copy

  # Scenario ids (A1..A6) are referenced by docs/ACCEPTANCE_SCENARIOS.md. Enrolments are created through the real
  # enrol_lti enrolment API, which fires the same event as an LTI 1.3 launch; real launches between two Moodle sites
  # are covered by tests/playwright/e2e.

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Teacher   | One      | teacher1@example.com |
      | alice    | Alice     | Consumer | alice@example.com    |
      | bob      | Bob       | Consumer | bob@example.com      |
      | carol    | Carol     | Both     | carol@example.com    |
      | dave     | Dave      | Expired  | dave@example.com     |
      | erin     | Erin      | Suspend  | erin@example.com     |
    And the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1        | 0        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
    And the following "groups" exist:
      | name             | course | idnumber |
      | University A     | C1     | UA       |
      | University A lab | C1     | UAL      |
      | University B     | C1     | UB       |
      | All consumers    | C1     | ALL      |
    And the following "tool_ltigroupautoenrol > lti tools" exist:
      | course | name            |
      | C1     | Consumer A tool |
      | C1     | Consumer B tool |

  # A1: two consumers, several groups per consumer, a group shared by both, multiple enrolment.
  Scenario: A1 Participants of two LTI consumers are separated into their groups
    Given I am on the "C1" "tool_ltigroupautoenrol > settings" page logged in as "teacher1"
    And I set the field "Enable automatic enrolment in groups for this course" to "1"
    And I set the field "Groups for LTI tool: Consumer A tool" to "University A, University A lab, All consumers"
    And I set the field "Groups for LTI tool: Consumer B tool" to "University B, All consumers"
    And I press "Save changes"
    And I should see "Changes saved"
    When the following "tool_ltigroupautoenrol > lti enrolments" exist:
      | user  | tool            |
      | alice | Consumer A tool |
      | bob   | Consumer B tool |
      | carol | Consumer A tool |
      | carol | Consumer B tool |
    Then "alice" should be a member of group "University A"
    And "alice" should be a member of group "University A lab"
    And "alice" should be a member of group "All consumers"
    And "alice" should not be a member of group "University B"
    And "bob" should be a member of group "University B"
    And "bob" should be a member of group "All consumers"
    And "bob" should not be a member of group "University A"
    And "bob" should not be a member of group "University A lab"
    And "carol" should be a member of group "University A"
    And "carol" should be a member of group "University A lab"
    And "carol" should be a member of group "University B"
    And "carol" should be a member of group "All consumers"
    # What the teacher sees in the course: group sizes on the groups page.
    And I am on the "C1" "groups" page
    And the "groups" select box should contain "University A (2)"
    And the "groups" select box should contain "University A lab (2)"
    And the "groups" select box should contain "University B (2)"
    And the "groups" select box should contain "All consumers (3)"

  # A2: participants enrolled before the configuration are assigned by the confirmed backfill (once, idempotent).
  Scenario: A2 Existing participants are assigned later by the backfill
    Given the following "tool_ltigroupautoenrol > lti enrolments" exist:
      | user  | tool            |
      | alice | Consumer A tool |
      | bob   | Consumer B tool |
      | carol | Consumer A tool |
      | carol | Consumer B tool |
    And I am on the "C1" "tool_ltigroupautoenrol > settings" page logged in as "teacher1"
    And I set the field "Enable automatic enrolment in groups for this course" to "1"
    And I set the field "Groups for LTI tool: Consumer A tool" to "University A, All consumers"
    And I set the field "Groups for LTI tool: Consumer B tool" to "University B, All consumers"
    And I press "Save changes"
    And "alice" should not be a member of group "University A"
    When I press "Assign existing LTI participants …"
    # Counted per tool: alice 2 + carol 2 (tool A) + bob 2 + carol 2 (tool B) = 8; carol joins "All consumers" once.
    Then I should see "Group memberships to be added: 8."
    And I press "Assign participants"
    And I should see "has been scheduled"
    And I run all adhoc tasks
    And "alice" should be a member of group "University A"
    And "alice" should be a member of group "All consumers"
    And "bob" should be a member of group "University B"
    And "bob" should not be a member of group "University A"
    And "carol" should be a member of group "University A"
    And "carol" should be a member of group "University B"
    And I am on the "C1" "tool_ltigroupautoenrol > backfill" page
    And I should see "All active LTI participants are already members of their configured groups."
    And I should see "Added: 7, already member: 1, skipped: 0, errors: 0."

  # A3: expired, not yet started and suspended enrolments are neither assigned on enrolment nor by the backfill.
  Scenario: A3 Expired and suspended enrolments are not assigned
    Given I am on the "C1" "tool_ltigroupautoenrol > settings" page logged in as "teacher1"
    And I set the field "Enable automatic enrolment in groups for this course" to "1"
    And I set the field "Groups for LTI tool: Consumer A tool" to "University A"
    And I press "Save changes"
    When the following "tool_ltigroupautoenrol > lti enrolments" exist:
      | user  | tool            | status    | timestart       | timeend         |
      | alice | Consumer A tool | active    | 0               | 0               |
      | dave  | Consumer A tool | active    | ##3 days ago##  | ##yesterday##   |
      | erin  | Consumer A tool | suspended | 0               | 0               |
      | bob   | Consumer A tool | active    | ##tomorrow##    | 0               |
    Then "alice" should be a member of group "University A"
    And "dave" should not be a member of group "University A"
    And "erin" should not be a member of group "University A"
    And "bob" should not be a member of group "University A"
    And I am on the "C1" "tool_ltigroupautoenrol > backfill" page
    And I should see "All active LTI participants are already members of their configured groups."
    And I run all adhoc tasks
    And "dave" should not be a member of group "University A"
    And "erin" should not be a member of group "University A"

  # A4: assignment is additive: a changed mapping does not remove memberships; the new group is filled by the backfill.
  Scenario: A4 Changing the mapping never removes memberships
    Given I am on the "C1" "tool_ltigroupautoenrol > settings" page logged in as "teacher1"
    And I set the field "Enable automatic enrolment in groups for this course" to "1"
    And I set the field "Groups for LTI tool: Consumer A tool" to "University A"
    And I press "Save changes"
    And the following "tool_ltigroupautoenrol > lti enrolments" exist:
      | user  | tool            |
      | alice | Consumer A tool |
    When I set the field "Groups for LTI tool: Consumer A tool" to "University A lab"
    And I press "Save changes"
    Then "alice" should be a member of group "University A"
    And "alice" should not be a member of group "University A lab"
    And I press "Assign existing LTI participants …"
    And I should see "Group memberships to be added: 1."
    And I press "Assign participants"
    And I run all adhoc tasks
    And "alice" should be a member of group "University A"
    And "alice" should be a member of group "University A lab"

  # A5: "Copy course" with user data (core's asynchronous course copy): the copy maps its own tools to its own groups.
  # Core does not copy enrol_lti user enrolments; participants are enrolled in the copy by their next launch.
  Scenario: A5 A course copy keeps the mapping for the copied tools and groups
    Given I am on the "C1" "tool_ltigroupautoenrol > settings" page logged in as "teacher1"
    And I set the field "Enable automatic enrolment in groups for this course" to "1"
    And I set the field "Groups for LTI tool: Consumer A tool" to "University A"
    And I set the field "Groups for LTI tool: Consumer B tool" to "University B"
    And I press "Save changes"
    And the following "tool_ltigroupautoenrol > lti enrolments" exist:
      | user  | tool            |
      | alice | Consumer A tool |
    # Core copies enrolment instances only of enabled enrolment plugins (sites publishing LTI tools have enrol_lti on).
    And I enable "lti" "enrol" plugin
    # Core's copy API as used by the "Copy course" form (the form itself is exercised in the browser by the two-site E2E).
    And the course "C1" is copied as "C1COPY" with the user data of role "student"
    And "alice" should not be a member of group "University A" in course "C1COPY"
    When the following "tool_ltigroupautoenrol > lti enrolments" exist:
      | user  | tool            | course |
      | alice | Consumer A tool | C1COPY |
      | bob   | Consumer B tool | C1COPY |
      | erin  | Consumer A tool | C1COPY |
    Then "alice" should be a member of group "University A" in course "C1COPY"
    And "bob" should be a member of group "University B" in course "C1COPY"
    And "bob" should not be a member of group "University B" in course "C1"
    And "erin" should be a member of group "University A" in course "C1COPY"
    And "erin" should not be a member of group "University B" in course "C1COPY"
    And I log out
    And I am on the "C1COPY" "tool_ltigroupautoenrol > settings" page logged in as "admin"
    And the field "Enable automatic enrolment in groups for this course" matches value "1"
    And the field "Groups for LTI tool: Consumer A tool" matches value "University A"
    And the field "Groups for LTI tool: Consumer B tool" matches value "University B"

  # A6: backup and restore through the user interface (needs JavaScript: runs in the GitHub CI, not in the local runner).
  @javascript
  Scenario: A6 A restored course maps the restored tools to the restored groups
    Given the following config values are set as admin:
      | enableasyncbackup | 0 |
    And I enable "lti" "enrol" plugin
    And I am on the "C1" "tool_ltigroupautoenrol > settings" page logged in as "teacher1"
    And I set the field "Enable automatic enrolment in groups for this course" to "1"
    And I set the field "Groups for LTI tool: Consumer A tool" to "University A"
    And I press "Save changes"
    And the following "tool_ltigroupautoenrol > lti enrolments" exist:
      | user  | tool            |
      | alice | Consumer A tool |
    And I log out
    And I log in as "admin"
    When I backup "Course 1" course using this options:
      | Confirmation | Filename | ltigae_backup.mbz |
    And I restore "ltigae_backup.mbz" backup into a new course using this options:
      | Schema | Course name       | Course 1 restored |
      | Schema | Course short name | C1RESTORED        |
    # enrol_lti restores its instances but not its user enrolments (no restore_user_enrolment()).
    Then "alice" should not be a member of group "University A" in course "C1RESTORED"
    And the following "tool_ltigroupautoenrol > lti enrolments" exist:
      | user | tool            | course     |
      | bob  | Consumer A tool | C1RESTORED |
    And "bob" should be a member of group "University A" in course "C1RESTORED"
    And "bob" should not be a member of group "University A" in course "C1"
