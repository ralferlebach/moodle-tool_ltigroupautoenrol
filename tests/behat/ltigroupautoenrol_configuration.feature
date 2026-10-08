@tool @tool_ltigroupautoenrol
Feature: Configure LTI group auto enrolment
  In order to automatically assign LTI users to groups
  As a teacher
  I need to configure LTI group auto enrolment settings

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Teacher   | One      | teacher1@example.com |
      | teacher2 | Teacher   | Two      | teacher2@example.com |
      | student1 | Student   | One      | student1@example.com |
      | student2 | Student   | Two      | student2@example.com |
    And the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1        | 0        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | teacher2 | C1     | teacher        |

  @javascript
  Scenario: Check whether LTI-enrol is accessible
    Given I am on the "Course 1" course page logged in as "teacher1"
    When I navigate to "Participants" in current page administration
    And I click on ".dropdown-toggle:contains('Enrolled users')" "css_element"
    Then I should see "LTI-enrol in groups"

  @javascript
  Scenario: Try to configure LTI group auto enrolment without groups
    Given I am on the "Course 1" course page logged in as "teacher1"
    When I navigate to "Participants" in current page administration
    And I click on ".dropdown-toggle:contains('Enrolled users')" "css_element"
    And I click on ".dropdown-item:contains('LTI-enrol in groups')" "css_element"
    Then I should see "Create groups first!"
    And I should not see "Enable automatic enrolment in groups for this course"

  @javascript
  Scenario: Non-editing teachers do not see the link
    Given I am on the "Course 1" course page logged in as "teacher2"
    When I navigate to "Participants" in current page administration
    And I click on ".dropdown-toggle:contains('Enrolled users')" "css_element"
    Then I should not see "LTI-enrol in groups"

  Scenario: Configure LTI group auto enrolment without groups
    Given I am on the "C1" "tool_ltigroupautoenrol > settings" page logged in as "teacher1"
    When I click on "Create groups first!" "link"
    Then I should see "Create group"

  Scenario: The navigation link follows the capability of the settings page
    When I am on the "C1" "enrolled users" page logged in as "teacher1"
    Then I should see "LTI-enrol in groups"
    And I log out
    And I am on the "C1" "enrolled users" page logged in as "teacher2"
    And I should not see "LTI-enrol in groups"

  Scenario: Course without LTI 1.3 tools explains the empty state
    Given the following "groups" exist:
      | name    | course | idnumber |
      | Group A | C1     | GA       |
    When I am on the "C1" "tool_ltigroupautoenrol > settings" page logged in as "teacher1"
    Then I should see "This course does not publish any LTI 1.3 tool yet"
    And I set the field "Enable automatic enrolment in groups for this course" to "1"
    And I press "Save changes"
    And the field "Enable automatic enrolment in groups for this course" matches value "1"

  Scenario: A new LTI enrolment is assigned to the configured groups
    Given the following "groups" exist:
      | name    | course | idnumber |
      | Group A | C1     | GA       |
      | Group B | C1     | GB       |
    And the following "tool_ltigroupautoenrol > lti tools" exist:
      | course | name   |
      | C1     | Tool 1 |
      | C1     | Tool 2 |
    And I am on the "C1" "tool_ltigroupautoenrol > settings" page logged in as "teacher1"
    And I set the field "Enable automatic enrolment in groups for this course" to "1"
    And I set the field "Groups for LTI tool: Tool 1" to "Group A"
    And I set the field "Groups for LTI tool: Tool 2" to "Group B"
    And I press "Save changes"
    And I should see "Changes saved"
    When the following "tool_ltigroupautoenrol > lti enrolments" exist:
      | user     | tool   |
      | student1 | Tool 1 |
      | student2 | Tool 2 |
    Then "student1" should be a member of group "Group A"
    And "student1" should not be a member of group "Group B"
    And "student2" should be a member of group "Group B"
    And "student2" should not be a member of group "Group A"

  # A real browser submits core's placeholder for an empty multi-select; BrowserKit does not.
  @javascript
  Scenario: Only some LTI tools are mapped
    Given the following "groups" exist:
      | name    | course | idnumber |
      | Group A | C1     | GA       |
    And the following "tool_ltigroupautoenrol > lti tools" exist:
      | course | name   |
      | C1     | Tool 1 |
      | C1     | Tool 2 |
    And I am on the "C1" "tool_ltigroupautoenrol > settings" page logged in as "teacher1"
    And I set the field "Enable automatic enrolment in groups for this course" to "1"
    And I set the field "Groups for LTI tool: Tool 1" to "Group A"
    When I press "Save changes"
    Then I should see "Changes saved"
    And I should not see "Only groups of this course can be selected."
    And the following "tool_ltigroupautoenrol > lti enrolments" exist:
      | user     | tool   |
      | student1 | Tool 1 |
      | student2 | Tool 2 |
    And "student1" should be a member of group "Group A"
    And "student2" should not be a member of group "Group A"

  Scenario: Existing LTI participants are assigned by the confirmed backfill
    Given the following "groups" exist:
      | name    | course | idnumber |
      | Group A | C1     | GA       |
    And the following "tool_ltigroupautoenrol > lti tools" exist:
      | course | name   |
      | C1     | Tool 1 |
    And the following "tool_ltigroupautoenrol > lti enrolments" exist:
      | user     | tool   |
      | student1 | Tool 1 |
    And I am on the "C1" "tool_ltigroupautoenrol > settings" page logged in as "teacher1"
    And I set the field "Enable automatic enrolment in groups for this course" to "1"
    And I set the field "Groups for LTI tool: Tool 1" to "Group A"
    And I press "Save changes"
    And "student1" should not be a member of group "Group A"
    When I press "Assign existing LTI participants …"
    Then I should see "Group memberships to be added: 1."
    And I press "Assign participants"
    And I should see "has been scheduled"
    And I run all adhoc tasks
    And "student1" should be a member of group "Group A"
    And I am on the "C1" "tool_ltigroupautoenrol > backfill" page
    And I should see "All active LTI participants are already members of their configured groups."

  @javascript @accessibility
  Scenario: Settings and backfill pages meet accessibility standards
    Given the following "groups" exist:
      | name    | course | idnumber |
      | Group A | C1     | GA       |
    And the following "tool_ltigroupautoenrol > lti tools" exist:
      | course | name   |
      | C1     | Tool 1 |
    And the following "tool_ltigroupautoenrol > lti enrolments" exist:
      | user     | tool   |
      | student1 | Tool 1 |
    When I am on the "C1" "tool_ltigroupautoenrol > settings" page logged in as "teacher1"
    Then the page should meet accessibility standards
    And I set the field "Enable automatic enrolment in groups for this course" to "1"
    And I set the field "Groups for LTI tool: Tool 1" to "Group A"
    And I press "Save changes"
    And I press "Assign existing LTI participants …"
    And I should see "Group memberships to be added: 1."
    And the page should meet accessibility standards
