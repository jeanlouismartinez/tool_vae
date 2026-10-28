@tool @tool_vae
Feature: The coverage of a dossier is shown to its candidate alone
  In order to see which competencies of a record my declarations reach, without any verdict on them
  As a candidate
  I need the counts on my own dossier, and nobody else to find them there

  # Decided with the project owner on 12 October 2026: read by a manager or the direction, the
  # same counts become a gauge on a person. The follower below reads the whole dossier, which is
  # what makes the absence of the table a rule and not a missing right.

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email               |
      | candidat | Camille   | Durand   | camille@example.com |
      | suivi    | Sam       | Leroy    | sam@example.com     |
    And the following "roles" exist:
      | shortname | name                |
      | follower  | Follows a candidate |
    And the following "permission overrides" exist:
      | capability                    | permission | role     | contextlevel | reference |
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

  Scenario: The candidate sees the coverage of their dossier, a manager who follows them does not
    Given I log in as "candidat"
    When I am on the "candidat" "tool_vae > Dossier" page
    Then I should see "Coverage of your declarations"
    And I should see "Spot the risk factors for falls"
    And I log out
    And I log in as "suivi"
    And I am on the "candidat" "tool_vae > Dossier" page
    And I should see "Night rounds with the care assistant"
    And I should not see "Coverage of your declarations"
    And I should not see "Attestations in force"
    # The declaration itself names its competency since 0.12.1: that is not the coverage.
    And I should see "Spot the risk factors for falls"
