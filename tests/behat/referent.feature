@tool @tool_vae
Feature: An administrator names the referents of the blocs, and it gives and takes away no right
  In order to let the right manager be told first of a declaration on a bloc
  As an administrator who imports certification records
  I need to name a referent for a record, read who is named, and take them back

  # A POINTER, NOT A RIGHT (T-23). The rule is the site's, written once. It decides who is told first among the managers who
  # follow a candidate; whoever follows a candidate may still read their dossier and attest.

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                 |
      | alice    | Alice     | Martin   | alice@example.com     |
      | simple   | Sam       | Leroy    | sam@example.com       |
    And the following "tool_vae > sources" exist:
      | fichecode | ficherang | name       | shortname            |
      | RNCP100   | C01       | Old record | Accompany the person |

  Scenario: The administrator names a referent for a record and takes them back
    Given I log in as "admin"
    When I am on the "tool_vae > Referents" page
    Then I should see "No referent named" in the "RNCP100" "table_row"
    When I set the field "Bloc" to "RNCP100 — Old record — whole record"
    And I set the field "Username" to "alice"
    And I press "Name the referent"
    Then I should see "The referent was named."
    And I should see "Alice Martin (alice)" in the "RNCP100" "table_row"
    When I press "Take back"
    Then I should see "The referent was taken back."
    And I should see "No referent named" in the "RNCP100" "table_row"

  Scenario: A username nobody holds is refused and nothing is recorded
    Given I log in as "admin"
    When I am on the "tool_vae > Referents" page
    And I set the field "Bloc" to "RNCP100 — Old record — whole record"
    And I set the field "Username" to "personne"
    And I press "Name the referent"
    Then I should see "No active account has this username."
    And I should see "No referent named" in the "RNCP100" "table_row"
