@tool @tool_vae
Feature: The pathway of a candidate is shown to the candidate alone
  In order to see, for each competency of a record I declared against, what I did and where I can learn it
  As a candidate
  I need the "My pathway" page to bring my declarations, the courses and the learning plans together, for me alone

  # T-90, lot 2. The page reads the plans and the course competencies of the core; it never reads a mark.
  # Scenarios 5 to 7 of the plan (rights taken away, "log in as") are added once they have been played.

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email               |
      | candidat | Camille   | Durand   | camille@example.com |
      | vide     | Victor    | Moreau   | victor@example.com  |
      | suivi    | Sam       | Leroy    | sam@example.com     |
    And the following "roles" exist:
      | shortname | name                |
      | follower  | Follows a candidate |
    And the following "permission overrides" exist:
      | capability            | permission | role     | contextlevel | reference |
      | tool/vae:declare      | Allow      | user     | System       |           |
      | tool/vae:viewown      | Allow      | user     | System       |           |
      | tool/vae:viewassigned | Allow      | follower | System       |           |
    And the following "role assigns" exist:
      | user  | role     | contextlevel | reference |
      | suivi | follower | User         | candidat  |
    And the following "tool_vae > sources" exist:
      | fichecode | ficherang | shortname                       |
      | RNCP36832 | C03       | Spot the risk factors for falls |
    And the following "tool_vae > declarations" exist:
      | candidate | situation                            |
      | candidat  | Night rounds with the care assistant |
    And the following "courses" exist:
      | fullname   | shortname |
      | Night care | NC        |
    And the following "core_competency > course_competencies" exist:
      | course | competency    |
      | NC     | RNCP36832-C03 |
    And the following "core_competency > plans" exist:
      | name     | user     | status |
      | Plan AES | candidat | active |
    And the following "core_competency > plan_competencies" exist:
      | plan     | competency    |
      | Plan AES | RNCP36832-C03 |

  Scenario: The candidate sees, line by line, what they declared, the course and the plan that carry it
    Given I log in as "candidat"
    When I am on the "tool_vae > Organisation" page
    Then I should see "My pathway"
    And the following should exist in the "Record RNCP36832" table:
      | Competency                      | Declarations | Courses    | Learning plans    |
      | Spot the risk factors for falls | 1            | Night care | Plan AES (Active) |

  Scenario: A manager who follows the candidate reads their own pathway, which is empty, and never the candidate's
    Given I log in as "suivi"
    When I am on the "tool_vae > Organisation" page
    Then I should see "You have not declared any activity yet"
    And I should not see "Spot the risk factors for falls"
    And I should not see "Night care"

  Scenario: A candidate with nothing declared and no plan is told so and offered to declare
    Given I log in as "vide"
    When I am on the "tool_vae > Organisation" page
    Then I should see "You have not declared any activity yet"
    And I should see "Declare an activity"

  Scenario: A site that takes away the right to read one's own plans is told so, never "none"
    Given the following "permission overrides" exist:
      | capability                   | permission | role | contextlevel | reference |
      | moodle/competency:planviewown | Prohibit   | user | System       |           |
    And I log in as "candidat"
    When I am on the "tool_vae > Organisation" page
    Then the following should exist in the "Record RNCP36832" table:
      | Competency                      | Declarations | Courses    | Learning plans                |
      | Spot the risk factors for falls | 1            | Night care | Not readable with your rights |
    And I should see "Your learning plans are not readable with your rights"
    And I should not see "Plan AES"

  Scenario: A site that takes away the right to read the competencies of a course is told so, never "none"
    Given the following "permission overrides" exist:
      | capability                              | permission | role | contextlevel | reference |
      | moodle/competency:coursecompetencyview | Prohibit   | user | System       |           |
    And I log in as "candidat"
    When I am on the "tool_vae > Organisation" page
    Then the following should exist in the "Record RNCP36832" table:
      | Competency                      | Declarations | Courses                       | Learning plans    |
      | Spot the risk factors for falls | 1            | Not readable with your rights | Plan AES (Active) |
    And I should not see "Night care"

  Scenario: An administrator logged in as the candidate does not read the pathway nor the coverage
    Given I am on the "candidat" "user > profile" page logged in as "admin"
    And I follow "Log in as"
    And I press "Continue"
    When I am on the "candidat" "tool_vae > Dossier" page
    Then I should not see "Coverage of your declarations"
    And I should not see "My pathway"
    And the "candidat" "Organisation" page should be refused with "Sorry, but you do not currently have permissions to do that (View one's own dossier)."

  Scenario: The dossier of the candidate leads to the pathway
    Given I log in as "candidat"
    When I am on the "candidat" "tool_vae > Dossier" page
    And I press "My pathway"
    Then I should see "Record RNCP36832"
