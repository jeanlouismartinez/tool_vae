@tool @tool_vae
Feature: The direction sees which candidates nobody can be told of
  In order to know that a declaration will not be announced to anyone
  As the direction, who reads every dossier
  I need the number of managers to tell next to each candidate, and a filter on those with none

  # T-90, piste A. A number, never a name: the audience of the notifier (followers who may attest, account active).
  # A holder of viewany who follows nobody is not counted, because the notifier does not tell them either.

  Background:
    Given the following "users" exist:
      | username  | firstname | lastname | email                |
      | suivie    | Camille   | Durand   | camille@example.com  |
      | seule     | Alex      | Martin   | alex@example.com     |
      | manager   | Sam       | Leroy    | sam@example.com      |
      | direction | Dana      | Roux     | dana@example.com     |
    And the following "roles" exist:
      | shortname | name                |
      | follower  | Follows a candidate |
      | direction | Management          |
    And the following "permission overrides" exist:
      | capability            | permission | role      | contextlevel | reference |
      | tool/vae:declare      | Allow      | user      | System       |           |
      | tool/vae:viewown      | Allow      | user      | System       |           |
      | tool/vae:viewassigned | Allow      | follower  | System       |           |
      | tool/vae:attest       | Allow      | follower  | System       |           |
      | tool/vae:viewany      | Allow      | direction | System       |           |
      | tool/vae:attest       | Allow      | direction | System       |           |
    And the following "role assigns" exist:
      | user      | role      | contextlevel | reference |
      | manager   | follower  | User         | suivie    |
      | direction | direction | System       |           |
    And the following "tool_vae > sources" exist:
      | fichecode | ficherang | name   | shortname            |
      | RNCP100   | BC01      | Record | Accompany the person |
    And the following "tool_vae > competencies" exist:
      | fichecode | ficherang | shortname    | bloc |
      | RNCP100   | C01       | Help to wash | BC01 |
    And the following "tool_vae > declarations" exist:
      | candidate | fichecode | ficherang | situation    |
      | suivie    | RNCP100   | C01       | Night rounds |
      | seule     | RNCP100   | C01       | Morning care |

  Scenario: The list gives the number of managers to tell for each candidate
    Given I log in as "direction"
    When I am on the "tool_vae > Pending" page
    Then the following should exist in the "tool-vae-pending" table:
      | Candidate      | Managers to tell |
      | Camille Durand | 1                |
      | Alex Martin    | 0                |

  Scenario: The filter keeps only the candidates nobody can be told of
    Given I log in as "direction"
    And I am on the "tool_vae > Pending" page
    When I set the field "Only the candidates nobody can be told of" to "1"
    And I click on "Show" "button" in the "#region-main" "css_element"
    Then I should see "Alex Martin" in the "tool-vae-pending" "table"
    And I should not see "Camille Durand" in the "tool-vae-pending" "table"
