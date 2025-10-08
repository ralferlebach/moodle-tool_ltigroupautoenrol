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

  Scenario: Configure LTI group auto enrolment with groups
    Given I am on the "Course 1" course page logged in as "teacher1"
    When I navigate to "Participants" in current page administration
    And I click on "Enrolled users" "combobox"
    Then I should see "LTI-enrol in groups"

    When I click on "LTI-enrol in groups" "option"
    Then I should see "Create groups first!"
    And I should not see "Enable automatic enrolment in groups for this course"