@tool @tool_vae
Feature: A manager reads the candidates they follow, with their records and, where the core allows it, their plans
  In order to see who I follow, on which records, and how much work waits
  As a manager who follows candidates
  I need the "Followed candidates" tab of the organisation page, which counts work and never judges a person

  # T-90, lot 3. The tab is drawn for whoever follows at least one candidate, and refused to everybody else, by the same rule.
  # The plans of a candidate are read only under the core's own right to read them; without it the cell says so and never "none".

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email               |
      | candidat | Camille   | Durand   | camille@example.com |
      | unique   | Alex      | Martin   | alex@example.com    |
      | novice   | Noa       | Perrin   | noa@example.com     |
      | suivi    | Sam       | Leroy    | sam@example.com     |
      | etranger | Eli       | Roux     | eli@example.com     |
    And the following "roles" exist:
      | shortname | name                |
      | follower  | Follows a candidate |
    And the following "permission overrides" exist:
      | capability            | permission | role     | contextlevel | reference |
      | tool/vae:declare      | Allow      | user     | System       |           |
      | tool/vae:viewown      | Allow      | user     | System       |           |
      | tool/vae:viewassigned | Allow      | follower | System       |           |
      | tool/vae:attest       | Allow      | follower | System       |           |
    And the following "role assigns" exist:
      | user  | role     | contextlevel | reference |
      | suivi | follower | User         | candidat  |
      | suivi | follower | User         | unique    |
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
      | unique    | RNCP100   | C01       | Morning care            |
    And the following "tool_vae > attestations" exist:
      | declaration  | attestant |
      | Night rounds | suivi     |
    And the following "core_competency > plans" exist:
      | name     | user     | status |
      | Plan AES | candidat | active |

  Scenario: The manager reads the candidates they follow, with the counts of "My candidates", and nobody else
    Given I log in as "suivi"
    When I am on the "tool_vae > Organisation followed" page
    Then "Followed candidates" "link" should exist in the ".nav-tabs" "css_element"
    And "My pathway" "link" should exist in the ".nav-tabs" "css_element"
    And the following should exist in the "tool-vae-followed" table:
      | Candidate      | Records | Declarations | Awaiting attestation |
      | Camille Durand | RNCP100 | 3            | 2                    |
      | Alex Martin    | RNCP100 | 1            | 1                    |
      | Noa Perrin     |         | 0            | 0                    |
    And "RNCP100" "text" should not exist in the "Noa Perrin" "table_row"
    And I should not see "Eli Roux"
    And I should not see "Last act"
    And I should not see "BC01"

  Scenario: Without the core's right to read plans, the cell says so and never says "none"
    Given I log in as "suivi"
    When I am on the "tool_vae > Organisation followed" page
    Then the following should exist in the "tool-vae-followed" table:
      | Candidate      | Learning plans                |
      | Camille Durand | Not readable with your rights |
      | Noa Perrin     | Not readable with your rights |
    And I should not see "Plan AES"

  Scenario: With the core's right to read plans, the plan is read with the word of the core
    Given the following "permission overrides" exist:
      | capability                 | permission | role     | contextlevel | reference |
      | moodle/competency:planview | Allow      | follower | System       |           |
    And I log in as "suivi"
    When I am on the "tool_vae > Organisation followed" page
    Then the following should exist in the "tool-vae-followed" table:
      | Candidate      | Learning plans                                                                  |
      | Camille Durand | Plan AES (Active)Some plans may not be readable with your rights                |
    And I should not see "Last act"

  Scenario: A candidate who follows nobody is not offered the tab, and its address is refused
    Given I log in as "candidat"
    When I am on the "tool_vae > Organisation" page
    Then "My pathway" "link" should exist in the ".nav-tabs" "css_element"
    And "Followed candidates" "link" should not exist in the ".nav-tabs" "css_element"
    And the "candidat" "Organisation followed" page should be refused with "Sorry, but you do not currently have permissions to do that (View the dossier of a candidate one follows)."

  Scenario: A manager who follows nobody is refused the tab as well
    Given I log in as "etranger"
    Then the "etranger" "Organisation followed" page should be refused with "Sorry, but you do not currently have permissions to do that (View the dossier of a candidate one follows)."

  Scenario: A referent counts the declarations on their bloc and keeps the list of the candidates they follow
    Given I log in as "admin"
    And I am on the "tool_vae > Referents" page
    And I set the field "Bloc" to "RNCP100 — Record — bloc BC01"
    And I set the field "Username" to "suivi"
    And I press "Name the referent"
    And I log out
    And I log in as "suivi"
    When I am on the "tool_vae > Organisation followed" page
    And I set the field "Count the declarations on" to "RNCP100 — bloc BC01"
    And I press "Show the counts"
    Then I should see "The counts are limited to the declarations on RNCP100 — bloc BC01."
    And "Followed candidates" "link" should exist in the ".nav-tabs" "css_element"
    And the following should exist in the "tool-vae-followed" table:
      | Candidate      | Declarations | Awaiting attestation |
      | Camille Durand | 1            | 0                    |
      | Alex Martin    | 1            | 1                    |
      | Noa Perrin     | 0            | 0                    |

  Scenario: The name of a candidate leads to their dossier, and nothing else is offered on the tab
    Given I log in as "suivi"
    When I am on the "tool_vae > Organisation followed" page
    And I follow "Camille Durand"
    Then I should see "Camille Durand"
    And I should see "Night rounds"

  Scenario: "My candidates" leads to the tab
    Given I log in as "suivi"
    When I am on the "tool_vae > Candidates" page
    And I follow "Followed candidates, with their records and learning plans"
    Then I should see "Camille Durand"
    And "Followed candidates" "link" should exist in the ".nav-tabs" "css_element"
