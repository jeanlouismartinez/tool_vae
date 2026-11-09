@tool @tool_vae
Feature: The activities that carry a competency are named to who can see them, and only said to exist to who cannot
  In order to know where, in which activity of which course, a competency is worked
  As a candidate, and as the direction of the scheme
  I need the activities beside the courses, with the rule of the core on what each reader may be told

  # T-90, lot 1 bis. The activity is the offer of training one level below the course. A reader who cannot see an activity
  # (hidden, under a restriction, or in a course they are not in) is told there is one, never its name.

  Background:
    Given the following "users" exist:
      | username  | firstname | lastname  | email                |
      | direction | Dana      | Direction | dana@example.com     |
      | candidat  | Camille   | Durand    | camille@example.com  |
    And the following "roles" exist:
      | shortname | name          |
      | direction | Direction VAE |
    And the following "permission overrides" exist:
      | capability       | permission | role      | contextlevel | reference |
      | tool/vae:declare | Allow      | user      | System       |           |
      | tool/vae:viewown | Allow      | user      | System       |           |
      | tool/vae:viewany | Allow      | direction | System       |           |
    And the following "role assigns" exist:
      | user      | role      | contextlevel | reference |
      | direction | direction | System       |           |
    And the following "tool_vae > sources" exist:
      | fichecode | ficherang | name   | shortname            |
      | RNCP100   | BC01      | Record | Accompany the person |
    And the following "tool_vae > competencies" exist:
      | fichecode | ficherang | shortname    | bloc |
      | RNCP100   | C01       | Help to wash | BC01 |
    And the following "tool_vae > declarations" exist:
      | candidate | fichecode | ficherang | situation    |
      | candidat  | RNCP100   | C01       | Night rounds |
    And the following "courses" exist:
      | fullname    | shortname |
      | Care course | CARE      |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | candidat | CARE   | student |
    And the following "activities" exist:
      | activity | course | name        | visible |
      | quiz     | CARE   | Night quiz  | 1       |
      | assign   | CARE   | Secret task | 0       |
    And the "quiz" activity "Night quiz" carries the competency "RNCP100-C01"
    And the "assign" activity "Secret task" carries the competency "RNCP100-C01"

  Scenario: A candidate in the course reads the activity they can see, and is told of the one they cannot
    Given I log in as "candidat"
    When I am on the "tool_vae > Organisation" page
    Then I should see "Care course › Night quiz (Quiz)"
    And I should see "Care course › An activity that is not visible"
    And I should not see "Secret task"

  Scenario: The direction, who is not in the course, reads the number and no name
    Given I log in as "direction"
    When I am on the "tool_vae > Organisation site" page
    Then the following should exist in the "tool-vae-site-fiches" table:
      | Records | Activities    |
      | RNCP100 | 2 activities  |
    And I should see "Care course › An activity that is not visible"
    And I should not see "Night quiz"
    And I should not see "Secret task"

  Scenario: The administrator reads the names, the hidden activity included
    Given I log in as "admin"
    When I am on the "tool_vae > Organisation site" page
    Then I should see "Care course › Night quiz (Quiz)"
    And I should see "Care course › Secret task (Assignment)"
