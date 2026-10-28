@tool @tool_vae
Feature: A candidate carries a declaration over to the record that replaces its own
  In order to keep a declaration meaningful when a certification record is republished
  As a candidate who declared against the old record
  I need to carry my declaration over myself, on the equivalent the administrator decided, and read what was done

  # THE CANDIDATE CARRIES, ON A DECISION OF THE ADMINISTRATOR (T-30, lot 3, D-14). A copy, never a move: the new
  # declaration is to be attested again, the old one stays on the dossier. Nothing is offered without a decision.

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email               |
      | candidat | Camille   | Durand   | camille@example.com |
    And the following "permission overrides" exist:
      | capability       | permission | role | contextlevel | reference |
      | tool/vae:declare | Allow      | user | System       |           |
      | tool/vae:viewown | Allow      | user | System       |           |
    And the following "tool_vae > sources" exist:
      | fichecode | ficherang | name       | shortname                  |
      | RNCP100   | C01       | Old record | Accompany the person       |
      | RNCP200   | C01       | New record | Accompany the person daily |
    And the following "tool_vae > replacements" exist:
      | fichecode | successorcode |
      | RNCP100   | RNCP200       |
    And the following "tool_vae > declarations" exist:
      | candidate | fichecode | ficherang | situation                            |
      | candidat  | RNCP100   | C01       | Night rounds with the care assistant |

  Scenario: Nothing is offered while the administrator has decided nothing
    Given I log in as "candidat"
    When I am on the "candidat" "tool_vae > Dossier" page
    Then I should see "Night rounds with the care assistant"
    And I should not see "Carry over to the new record"

  Scenario: The candidate carries the declaration over and reads what was done
    Given the following "tool_vae > correspondences" exist:
      | from        | to          | note                    |
      | RNCP100-C01 | RNCP200-C01 | Same field, new wording |
    And I log in as "candidat"
    And I am on the "candidat" "tool_vae > Dossier" page
    When I press "Carry over to the new record"
    Then I should see "Carry a declaration over to the new record"
    And I should see "decided by the site administrator"
    And I should see "Same field, new wording"
    And the field "Work situation" matches value "Night rounds with the care assistant"
    When I set the field "Work situation" to "Night rounds with the care assistant, reread for the new record"
    And I press "Carry this declaration over"
    Then I should see "The declaration was carried over."
    And I should see "Night rounds with the care assistant, reread for the new record"
    And I should see "Carried over to record RNCP200-C01"
    And I should see "Carried over from record RNCP100-C01"
    And I should see "has to be attested again"
    And I should not see "Carry over to the new record"
