@tool @tool_ltigroupautoenrol @javascript
Feature: Configure LTI group auto enrolment
  In order to automatically assign LTI users to groups
  As a teacher
  I need to configure LTI group auto enrolment settings

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Teacher   | One      | teacher1@example.com |
    And the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1        | 0        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |

  Scenario: Check whether LTI-enrol is accessible
    Given I am on the "Course 1" course page logged in as "teacher1"
    When I navigate to "Participants" in current page administration
    And I click on ".dropdown-toggle:contains('Enrolled users')" "css_element"
    Then I should see "LTI-enrol in groups"

  Scenario: Try to configure LTI group auto enrolment without groups
    Given I am on the "Course 1" course page logged in as "teacher1"
    When I navigate to "Participants" in current page administration
    And I click on ".dropdown-toggle:contains('Enrolled users')" "css_element"
    And I click on ".dropdown-item" "css_element" containing "LTI-enrol in groups"
    Then I should see "Create groups first!"
    And I should not see "Enable automatic enrolment in groups for this course"

  Scenario: Configure LTI group auto enrolment without groups
    Given I am on the "Course 1" course page logged in as "teacher1"
    When I navigate to "Participants" in current page administration
    And I click on ".dropdown-toggle:contains('Enrolled users')" "css_element"
    And I click on ".dropdown-item" "css_element" containing "LTI-enrol in groups"
    And I click on "Create groups first!" "link"
    Then I should see "Create group"

  Scenario: Configure LTI group auto enrolment with existing groups
    Given the following "groups" exist:
      | name    | course | idnumber |
      | Group A | C1     | GA       |
      | Group B | C1     | GB       |
    And I am on the "Course 1" course page logged in as "teacher1"
    When I navigate to "Participants" in current page administration
    And I click on ".dropdown-toggle:contains('Enrolled users')" "css_element"
    And I click on ".dropdown-item" "css_element" containing "LTI-enrol in groups"
    And I set the field "Enable automatic enrolment in groups for this course" to "1"
    And I press "Save changes"
    And I navigate to "Participants" in current page administration
    And I click on ".dropdown-toggle:contains('Enrolled users')" "css_element"
    And I click on ".dropdown-item:contains('LTI-enrol in groups')" "css_element"
    Then the field "Enable automatic enrolment in groups for this course" matches value "1"
