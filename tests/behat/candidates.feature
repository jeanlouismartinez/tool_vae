@tool @tool_vae
Feature: A referent narrows the counts of "My candidates" to the declarations on their bloc
  In order to see the work waiting on the blocs I answer for
  As a manager who follows candidates and is referent of a bloc
  I need to count the declarations on one of my blocs, without losing the list of the candidates I follow

  # A POINTER, NOT A RIGHT (T-23, lot 3). The choice narrows what is counted; the list of the candidates, and what the manager
  # may read, are those of every follower.

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email               |
      | candidat | Camille   | Durand   | camille@example.com |
      | suivi    | Sam       | Leroy    | sam@example.com     |
      | autre    | Alex      | Martin   | alex@example.com    |
      | novice   | Noa       | Perrin   | noa@example.com     |
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
      | autre | follower | User         | candidat  |
      | suivi | follower | User         | novice    |
    And the following "tool_vae > sources" exist:
      | fichecode | ficherang | name   | shortname            |
      | RNCP100   | BC01      | Record | Accompany the person |
    And the following "tool_vae > competencies" exist:
      | fichecode | ficherang | shortname    | bloc |
      | RNCP100   | BC02      | Work in team |      |
      | RNCP100   | C01       | Help to wash | BC01 |
      | RNCP100   | C02       | Hand over    | BC02 |
    And the following "tool_vae > declarations" exist:
      | candidate | fichecode | ficherang | situation               |
      | candidat  | RNCP100   | C01       | Night rounds            |
      | candidat  | RNCP100   | C02       | Evening handover        |
      | candidat  | RNCP100   | C02       | Handover to the weekend |
    And I log in as "admin"
    And I am on the "tool_vae > Referents" page
    And I set the field "Bloc" to "RNCP100 — Record — bloc BC01"
    And I set the field "Username" to "suivi"
    And I press "Name the referent"
    And I log out

  Scenario: A referent counts the declarations on their bloc and keeps the list of their candidates
    Given I log in as "suivi"
    When I am on the "tool_vae > Candidates" page
    Then the following should exist in the "tool-vae-candidates" table:
      | Candidate      | Declarations | Awaiting attestation |
      | Camille Durand | 3            | 3                    |
      | Noa Perrin     | 0            | 0                    |
    When I set the field "Count the declarations on" to "RNCP100 — bloc BC01"
    And I press "Show the counts"
    Then I should see "The counts are limited to the declarations on RNCP100 — bloc BC01."
    And the following should exist in the "tool-vae-candidates" table:
      | Candidate      | Declarations | Awaiting attestation |
      | Camille Durand | 1            | 1                    |
      | Noa Perrin     | 0            | 0                    |

  Scenario: A manager who is referent of nothing is offered no choice
    Given I log in as "autre"
    When I am on the "tool_vae > Candidates" page
    Then the following should exist in the "tool-vae-candidates" table:
      | Candidate      | Declarations | Awaiting attestation |
      | Camille Durand | 3            | 3                    |
    And I should not see "Count the declarations on"
