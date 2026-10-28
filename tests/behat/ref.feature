@tool @tool_vae
Feature: An administrator gives the addresses where the certifier publishes its rule of validation
  In order to let candidates find the rule of the certifier without the plugin quoting it, computing it or applying it
  As an administrator who imports certification records
  I need to give an address and a short title for a record, and the candidates to see them only once I opened them

  # AN ADDRESS, NEVER A RULE (T-41, D-1, D-4). The box on the dossier sits above the figures, quotes nothing and says that
  # the plugin applies nothing. Closed by default: the addresses can be entered before any candidate sees them.

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email               |
      | candidat | Camille   | Durand   | camille@example.com |
    And the following "permission overrides" exist:
      | capability       | permission | role | contextlevel | reference |
      | tool/vae:declare | Allow      | user | System       |           |
      | tool/vae:viewown | Allow      | user | System       |           |
    And the following "tool_vae > sources" exist:
      | fichecode | ficherang | name       | shortname            |
      | RNCP100   | C01       | Old record | Accompany the person |
    And the following "tool_vae > declarations" exist:
      | candidate | situation                            | fichecode | ficherang |
      | candidat  | Night rounds with the care assistant | RNCP100   | C01       |

  Scenario: The administrator gives an address and takes it back
    Given I log in as "admin"
    When I am on the "tool_vae > Refs" page
    Then I should see "No address given" in the "RNCP100" "table_row"
    When I set the field "Record" to "RNCP100 — Old record"
    And I set the field "Short title" to "Fiche du repertoire"
    And I set the field "Address" to "https://example.org/rncp/100/"
    And I press "Record the address"
    Then I should see "The address was recorded."
    And I should see "Fiche du repertoire" in the "RNCP100" "table_row"
    And I should see "https://example.org/rncp/100/" in the "RNCP100" "table_row"
    When I press "Remove"
    Then I should see "The address was removed."
    And I should see "No address given" in the "RNCP100" "table_row"

  Scenario: An address that is not http or https is refused and nothing is recorded
    Given I log in as "admin"
    When I am on the "tool_vae > Refs" page
    And I set the field "Record" to "RNCP100 — Old record"
    And I set the field "Short title" to "Piege"
    And I set the field "Address" to "javascript:alert(1)"
    And I press "Record the address"
    Then I should see "This address is not accepted."
    And I should see "No address given" in the "RNCP100" "table_row"

  Scenario: Closed by default, the candidate sees nothing, open the candidate sees the address and the sentence
    Given I log in as "admin"
    And I am on the "tool_vae > Refs" page
    And I set the field "Record" to "RNCP100 — Old record"
    And I set the field "Short title" to "Fiche du repertoire"
    And I set the field "Address" to "https://example.org/rncp/100/"
    And I press "Record the address"
    And I log out
    When I log in as "candidat"
    And I am on the "candidat" "tool_vae > Dossier" page
    Then I should see "Coverage of your declarations"
    And I should not see "Rule of validation: see"
    And I should not see "Fiche du repertoire"
    And I log out
    Given the following config values are set as admin:
      | refopen | 1 | tool_vae |
    When I log in as "candidat"
    And I am on the "candidat" "tool_vae > Dossier" page
    Then I should see "Rule of validation: see"
    And I should see "Fiche du repertoire"
    And I should see "The plugin does not apply this rule and does not say whether your dossier satisfies it."
