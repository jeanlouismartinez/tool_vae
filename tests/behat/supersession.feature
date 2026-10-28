@tool @tool_vae
Feature: An administrator says which certification record replaces which, and sees what still holds the old one
  In order to know which dossiers hold declarations against a record that was republished under a new number
  As an administrator who imports certification records
  I need to declare the replacement, read the counts, and take the declaration back

  # A DECLARATION, NOT AN ACTION (T-30, lot 1, D-14). The page says which record replaces which and counts
  # declarations and candidates; it names nobody and moves nothing.

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | candidat | Camille   | Durand   | camille@example.com  |
      | simple   | Sam       | Leroy    | sam@example.com      |
    And the following "permission overrides" exist:
      | capability       | permission | role | contextlevel | reference |
      | tool/vae:declare | Allow      | user | System       |           |
      | tool/vae:viewown | Allow      | user | System       |           |
    And the following "tool_vae > sources" exist:
      | fichecode | ficherang | name       | shortname                  |
      | RNCP100   | C01       | Old record | Accompany the person       |
      | RNCP200   | C01       | New record | Accompany the person daily |
    And the following "tool_vae > declarations" exist:
      | candidate | situation                            | fichecode | ficherang |
      | candidat  | Night rounds with the care assistant | RNCP100   | C01       |

  Scenario: The administrator declares a replacement and reads what still holds the old record
    Given I log in as "admin"
    When I am on the "tool_vae > Supersession" page
    Then I should see "None declared" in the "RNCP100" "table_row"
    And I should see "1" in the "RNCP100" "table_row"
    When I set the field "The record that is replaced" to "RNCP100 — Old record"
    And I set the field "The record that replaces it" to "RNCP200 — New record"
    And I press "Declare the replacement"
    Then I should see "The replacement was recorded."
    And I should see "RNCP200 — New record" in the "RNCP100" "table_row"
    And I should not see "Night rounds with the care assistant"
    And I should not see "Camille"

  Scenario: The administrator takes a replacement back
    Given the following "tool_vae > replacements" exist:
      | fichecode | successorcode |
      | RNCP100   | RNCP200       |
    And I log in as "admin"
    When I am on the "tool_vae > Supersession" page
    Then I should see "RNCP200 — New record" in the "RNCP100" "table_row"
    When I press "Remove the replacement"
    Then I should see "The replacement was removed."
    And I should see "None declared" in the "RNCP100" "table_row"

  Scenario: A record does not replace itself, nor close a loop
    Given the following "tool_vae > replacements" exist:
      | fichecode | successorcode |
      | RNCP100   | RNCP200       |
    And I log in as "admin"
    When I am on the "tool_vae > Supersession" page
    And I set the field "The record that is replaced" to "RNCP100 — Old record"
    And I set the field "The record that replaces it" to "RNCP100 — Old record"
    And I press "Declare the replacement"
    Then I should see "A record cannot replace itself."
    When I set the field "The record that is replaced" to "RNCP200 — New record"
    And I set the field "The record that replaces it" to "RNCP100 — Old record"
    And I press "Declare the replacement"
    Then I should see "This would make a record replace itself"

  Scenario: Whoever may not import is refused, even by the address
    Given I log in as "simple"
    Then the "RNCP100" "Passage" page should be refused with "Access denied"
