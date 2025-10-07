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
    And I click on "Enrolled users" "link"
    Then I should see "LTI enrol in groups" in the "Enrolment methods" "select"

    When I select "LTI enrol in groups" from the "Enrolment methods" singleselect
    Then I should see "Create groups first!"
    And I should not see "Enable automatic enrolment in groups for this course"

    When I click on "Create groups first!" "link"
    Then I should see "Create group"

    When I click on "Create group" "button"
    And I set the following fields to these values:
      | Group name | Group A |
    And I press "Save changes"
    Then I should see "Group A" in the "Groups" "table"

    When I click on "Create group" "button"
    And I set the following fields to these values:
      | Group name | Group B |
    And I press "Save changes"
    Then I should see "Group B" in the "Groups" "table"

    When I navigate to "Participants" in current page administration
    And I click on "Enrolled users" "link"
    And I select "LTI enrol in groups" from the "Enrolment methods" singleselect
    Then I should see "Enable automatic enrolment in groups for this course"

    When I set the field "Enable automatic enrolment in groups for this course" to "1"
    And I press "Save changes"
    Then I should see "Changes saved"

    When I navigate to "Participants" in current page administration
    And I click on "Enrolled users" "link"
    And I select "LTI enrol in groups" from the "Enrolment methods" singleselect
    Then the field "Enable automatic enrolment in groups for this course" matches value "1"

  Scenario: Configure deployment to group mappings
    Given the following "groups" exist:
      | name    | course | idnumber |
      | Group A | C1     | GA       |
      | Group B | C1     | GB       |
    And I am on the "Course 1" course page logged in as "teacher1"
    When I navigate to "Participants" in current page administration
    And I click on "Enrolled users" "link"
    And I select "LTI enrol in groups" from the "Enrolment methods" singleselect
    Then I should see "Enable automatic enrolment in groups for this course"

    When I set the field "Enable automatic enrolment in groups for this course" to "1"
    And I press "Save changes"
    And I navigate to "Participants" in current page administration
    And I click on "Enrolled users" "link"
    And I select "LTI enrol in groups" from the "Enrolment methods" singleselect
    Then the field "Enable automatic enrolment in groups for this course" matches value "1"
