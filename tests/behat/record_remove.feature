@tool @tool_vae
Feature: A manager removes the row of a record imported by mistake
  In order to get rid of a record imported under the wrong code, once Moodle no longer holds its framework
  As a manager who may import certification records
  I need a button on the list of the imported records, a screen that says what leaves, and no button where a candidate has written

  # THE GESTURE IS IN TWO SCREENS, AND ONLY THE SECOND IS THIS PLUGIN'S. The manager deletes the competency framework in
  # Moodle, which decides whether it can go. Then, on the list of the imported records, the row that is left offers
  # "Remove this row". Nothing is deleted in Moodle by this plugin: it removes what it kept itself (provenance, addresses,
  # referents, correspondences, replacing record).
  #
  # THE STEPS THAT DELETE A FRAMEWORK ARE IN behat_tool_vae.php: the first goes through the core's own door, as the screen of
  # Moodle does; the second empties the tables with no event and no check, to reach a state the core's lock would forbid (a
  # framework gone while a declaration stands).

  Background:
    Given the following "tool_vae > sources" exist:
      | fichecode | ficherang | name                                | shortname                       |
      | RNCP36832 | C03       | Aide-soignant (imported by mistake) | Spot the risk factors for falls |
      | RNCP40692 | BC03      | Aide-soignant                       | Assess the clinical state       |
    And the following "tool_vae > replacements" exist:
      | fichecode | successorcode |
      | RNCP36832 | RNCP40692     |

  Scenario: A record Moodle still holds has no button, and its screen says why
    Given I log in as "admin"
    When I am on the "tool_vae > Index" page
    Then I should not see "Remove this row"
    When I am on the "RNCP36832" "tool_vae > Remove record" page
    Then I should see "Moodle still holds a competency framework carrying this code"
    And I should not see "Remove this row"

  Scenario: The manager removes the row of a record deleted in Moodle
    Given the competency framework of the record "RNCP36832" is deleted in Moodle
    And I log in as "admin"
    When I am on the "tool_vae > Index" page
    Then I should see "no longer exists in Moodle"
    And I should see "remove its row from this list with the button below"
    When I press "Remove this row"
    Then I should see "Remove the row of the record RNCP36832"
    And I should see "Leaves with the row"
    And I should see "Licence of the source: Licence Ouverte 2.0. Attribution: France competences."
    And I should see "Addresses of the rule of validation given for this record: 0"
    And I should see "Replacing record declared for it: RNCP40692"
    When I press "Remove this row"
    Then I should see "The row of the record RNCP36832 was removed from the list."
    And "RNCP36832" "table_row" should not exist
    And I should not see "no longer exists in Moodle"
    And I should not see "Remove this row"

  Scenario: Cancelling leaves the row where it is
    Given the competency framework of the record "RNCP36832" is deleted in Moodle
    And I log in as "admin"
    And I am on the "RNCP36832" "tool_vae > Remove record" page
    When I press "Cancel"
    Then I should see "no longer exists in Moodle"
    And I should see "Remove this row"

  Scenario: A record a candidate declared on is not offered for removal
    Given the following "users" exist:
      | username | firstname | lastname | email               |
      | candidat | Camille   | Durand   | camille@example.com |
    And the following "permission overrides" exist:
      | capability       | permission | role | contextlevel | reference |
      | tool/vae:declare | Allow      | user | System       |           |
    And the following "tool_vae > declarations" exist:
      | candidate | fichecode | ficherang | situation                            |
      | candidat  | RNCP36832 | C03       | Night rounds with the care assistant |
    And the competency framework of the record "RNCP36832" is erased from Moodle without the checks of the core
    And I log in as "admin"
    When I am on the "tool_vae > Index" page
    Then I should see "no longer exists in Moodle"
    And I should see "The row of the record(s) RNCP36832 cannot be removed from this list: a candidate has left a trace on the record"
    And I should not see "Moodle holds a competency framework carrying this code"
    And I should not see "Remove this row"
    When I am on the "RNCP36832" "tool_vae > Remove record" page
    Then I should see "at least one candidate has left a trace on it"
    And I should see "1 declaration(s)"
    And I should not see "Remove this row"

  # THE LIST NAMES THE CAUSE THAT KEEPS A BUTTON AWAY, NOT ALWAYS THE SAME ONE. A row whose framework was deleted has no button in
  # two cases: a framework of Moodle carries its code again (made by hand, say), or a candidate has written on it. The page used to
  # say "a candidate has left a trace" for both, and the manager looked for a trace that was not there.
  Scenario: A framework made by hand under the code is named, and no trace of a candidate is claimed
    Given the competency framework of the record "RNCP36832" is deleted in Moodle
    And the following "core_competency > frameworks" exist:
      | shortname    | idnumber  |
      | Made by hand | RNCP36832 |
    And I log in as "admin"
    When I am on the "tool_vae > Index" page
    Then I should see "no longer exists in Moodle"
    And I should see "The row of the record(s) RNCP36832 cannot be removed from this list: Moodle holds a competency framework carrying this code"
    And I should not see "left a trace"
    And I should not see "Remove this row"
    When I am on the "RNCP36832" "tool_vae > Remove record" page
    Then I should see "Moodle still holds a competency framework carrying this code"
    And I should not see "Remove this row"

  Scenario: Two records without a button for two causes are each named with their own
    Given the following "users" exist:
      | username | firstname | lastname | email               |
      | candidat | Camille   | Durand   | camille@example.com |
    And the following "permission overrides" exist:
      | capability       | permission | role | contextlevel | reference |
      | tool/vae:declare | Allow      | user | System       |           |
    And the following "tool_vae > declarations" exist:
      | candidate | fichecode | ficherang | situation                            |
      | candidat  | RNCP40692 | BC03      | Night rounds with the care assistant |
    And the competency framework of the record "RNCP36832" is deleted in Moodle
    And the following "core_competency > frameworks" exist:
      | shortname    | idnumber  |
      | Made by hand | RNCP36832 |
    And the competency framework of the record "RNCP40692" is erased from Moodle without the checks of the core
    And I log in as "admin"
    When I am on the "tool_vae > Index" page
    Then I should see "The row of the record(s) RNCP36832 cannot be removed from this list: Moodle holds a competency framework carrying this code"
    And I should see "The row of the record(s) RNCP40692 cannot be removed from this list: a candidate has left a trace on the record"
    And I should not see "Remove this row"
