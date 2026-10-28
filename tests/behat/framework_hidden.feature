@tool @tool_vae
Feature: A hidden certification record refuses a declaration, and says so to the candidate in plain words
  In order to understand why I cannot declare on a record, and not lose what I wrote
  As a candidate
  I need the page to name the record, say that it is hidden and whom to ask, and to give back my text

  # THE RULE IS KEPT, THE REFUSAL IS MADE CLEAR (decision of the project owner, 27 October 2026). A record whose framework is
  # hidden in Moodle ("Visible: No") is not declared on, as it is not attached to a course. What changes is how it is said:
  # for the candidate, with the title of the record and its code, and nothing of the administration screens. The screens of
  # the site administrator keep their own wording (course link, administration page).
  #
  # THE STEP THAT HIDES A RECORD IS "the framework of the record ... is hidden" (tests/behat/behat_tool_vae.php): it does what
  # an administrator does with "Visible: No", by the table.

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email               |
      | candidat | Camille   | Durand   | camille@example.com |
    And the following "permission overrides" exist:
      | capability       | permission | role | contextlevel | reference |
      | tool/vae:declare | Allow      | user | System       |           |
      | tool/vae:viewown | Allow      | user | System       |           |
    And the following "tool_vae > sources" exist:
      | fichecode | ficherang | name                        | shortname                        |
      | RNCP36832 | C03       | Accompanying elderly people | Spot the risk factors of a fall  |
      | RNCP40692 | BC03      | Aide-Soignant (RNCP40692)   | Work in a pluriprofessional team |
    And the following "tool_vae > replacements" exist:
      | fichecode | successorcode |
      | RNCP36832 | RNCP40692     |
    And the following "tool_vae > declarations" exist:
      | candidate | fichecode | ficherang | situation                            |
      | candidat  | RNCP36832 | C03       | Night rounds with the care assistant |

  # THE WAY IT WAS, AND NO SCENARIO PLAYED IT THROUGH THE FORM BEFORE: a record that is visible is declared on, as ever.
  Scenario: A declaration on a visible record is recorded as before
    Given I log in as "candidat"
    And I am on the "tool_vae > Declare" page
    And I set the field "Competency claimed" to "RNCP40692-BC03 — Work in a pluriprofessional team"
    And I set the field "Work situation" to "Early rounds in the protected unit, twice a week"
    When I press "Record this declaration"
    Then I should see "Declaration recorded."
    And I should see "Early rounds in the protected unit, twice a week"
    And I should not see "is hidden"

  Scenario: A hidden record is explained to the candidate, and the other records stay offered
    Given the framework of the record "RNCP40692" is hidden
    And I log in as "candidat"
    When I am on the "tool_vae > Declare" page
    Then I should see "The record Aide-Soignant (RNCP40692) is hidden"
    And I should see "Ask your manager or the organisation that accompanies you"
    And I should not see "Site administration"
    And I should not see "Competency frameworks"
    And the "Competency claimed" select box should contain "RNCP36832-C03 — Spot the risk factors of a fall"
    And the "Competency claimed" select box should not contain "RNCP40692-BC03 — Work in a pluriprofessional team"

  Scenario: A form opened before the record was hidden gives back the message and what was written
    Given I log in as "candidat"
    And I am on the "tool_vae > Declare" page
    And I set the field "Competency claimed" to "RNCP40692-BC03 — Work in a pluriprofessional team"
    And I set the field "Work situation" to "Early rounds in the protected unit, twice a week"
    And the framework of the record "RNCP40692" is hidden
    When I press "Record this declaration"
    Then I should see "The record Aide-Soignant (RNCP40692) is hidden"
    And I should see "What you wrote is kept in the form below"
    And I should not see "This competency does not exist"
    And the field "Work situation" matches value "Early rounds in the protected unit, twice a week"
    And the "Competency claimed" select box should contain "Choose..."
    # It is the entry the list stands on, not only one of its lines: the browser would otherwise fall on the first competency,
    # which is of another record, and a second send would declare there.
    And the field "Competency claimed" matches value "Choose..."
    And the "Competency claimed" select box should not contain "RNCP40692-BC03 — Work in a pluriprofessional team"

  Scenario: When every record is hidden after the form was opened, what was written is kept
    Given I log in as "candidat"
    And I am on the "tool_vae > Declare" page
    And I set the field "Work situation" to "Early rounds in the protected unit, twice a week"
    And the framework of the record "RNCP36832" is hidden
    And the framework of the record "RNCP40692" is hidden
    When I press "Record this declaration"
    Then I should see "The record Accompanying elderly people (RNCP36832) is hidden"
    And I should see "The record Aide-Soignant (RNCP40692) is hidden"
    And I should see "Nothing has been recorded"
    And I should see "Early rounds in the protected unit, twice a week"
    And I should not see "No certification record has been imported yet"

  Scenario: The carry is offered while the new record is visible, and not once it is hidden
    Given the following "tool_vae > correspondences" exist:
      | from          | to             | note                    |
      | RNCP36832-C03 | RNCP40692-BC03 | Same field, new wording |
    And I log in as "candidat"
    When I am on the "candidat" "tool_vae > Dossier" page
    Then I should see "Carry over to the new record"
    And the framework of the record "RNCP40692" is hidden
    And I am on the "candidat" "tool_vae > Dossier" page
    And I should see "Night rounds with the care assistant"
    And I should not see "Carry over to the new record"

  Scenario: A carry sent after the new record was hidden comes back to the form with what was written
    Given the following "tool_vae > correspondences" exist:
      | from          | to             | note                    |
      | RNCP36832-C03 | RNCP40692-BC03 | Same field, new wording |
    And I log in as "candidat"
    And I am on the "candidat" "tool_vae > Dossier" page
    And I press "Carry over to the new record"
    And I set the field "Work situation" to "Night rounds, reread for the new record"
    And the framework of the record "RNCP40692" is hidden
    When I press "Carry this declaration over"
    Then I should see "The record Aide-Soignant (RNCP40692) is hidden"
    And I should see "Carry a declaration over to the new record"
    And I should see "What you wrote is kept in the form below"
    And the field "Work situation" matches value "Night rounds, reread for the new record"

  # THE ADDRESS OF THE CARRY, OPENED AFTER THE RECORD WAS HIDDEN: nothing was typed yet, so there is no text to give back and no
  # form to open for a carry that cannot be made. The candidate is told on the dossier, where the button was.
  Scenario: The address of the carry, opened once the new record is hidden, sends back to the dossier with the message
    Given the following "tool_vae > correspondences" exist:
      | from          | to             | note                    |
      | RNCP36832-C03 | RNCP40692-BC03 | Same field, new wording |
    And I log in as "candidat"
    And I am on the "candidat" "tool_vae > Dossier" page
    And I press "Carry over to the new record"
    And the framework of the record "RNCP40692" is hidden
    When I reload the page
    Then I should see "The record Aide-Soignant (RNCP40692) is hidden"
    And I should see "Night rounds with the care assistant"
    And I should not see "Carry a declaration over to the new record"
    And I should not see "What you wrote is kept in the form below"
