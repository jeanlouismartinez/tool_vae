@tool @tool_vae
Feature: An administrator decides, one competency at a time, which competency of a new record stands for an old one
  In order to prepare the carrying over of declarations from a record that was republished
  As an administrator who imports certification records
  I need the plugin's suggestion, my own decision with a note, and the means to take it back

  # THE PLUGIN SUGGESTS, A PERSON DECIDES (T-30, lot 2, D-14). A suggestion is recorded nowhere; only a
  # decision is, with who and when. Nothing is moved by a decision.

  Background:
    Given the following "tool_vae > sources" exist:
      | fichecode | ficherang | name       | shortname                  |
      | RNCP100   | C01       | Old record | Accompany the person       |
      | RNCP200   | C01       | New record | Accompany the person daily |
      | RNCP300   | C09       | Other      | Accompany the person       |

  Scenario: The same rank is suggested, and the administrator confirms it with a note
    Given the following "tool_vae > replacements" exist:
      | fichecode | successorcode |
      | RNCP100   | RNCP200       |
    And I log in as "admin"
    When I am on the "RNCP100" "tool_vae > Passage" page
    Then I should see "same rank"
    And I should see "No decision"
    When I set the field "Note (why) RNCP100-C01" to "Same field, new wording."
    And I press "Confirm this equivalent"
    Then I should see "The equivalent was recorded."
    And I should see "Decided by Admin User"
    And I should see "Same field, new wording."

  Scenario: A nearly identical label is suggested when the rank differs
    Given the following "tool_vae > replacements" exist:
      | fichecode | successorcode |
      | RNCP100   | RNCP300       |
    And I log in as "admin"
    When I am on the "RNCP100" "tool_vae > Passage" page
    Then I should see "nearly the same label"
    And I should see "No decision"

  Scenario: A decision is taken back
    Given the following "tool_vae > replacements" exist:
      | fichecode | successorcode |
      | RNCP100   | RNCP200       |
    And I log in as "admin"
    And I am on the "RNCP100" "tool_vae > Passage" page
    And I press "Confirm this equivalent"
    And I should see "Decided by Admin User"
    When I press "Take back"
    Then I should see "The decision was taken back."
    And I should see "No decision"
    And I should not see "Decided by Admin User"

  Scenario: No equivalent chosen in the list is the same as taking the decision back
    Given the following "tool_vae > replacements" exist:
      | fichecode | successorcode |
      | RNCP100   | RNCP200       |
    And I log in as "admin"
    And I am on the "RNCP100" "tool_vae > Passage" page
    And I press "Confirm this equivalent"
    When I set the field "Decide RNCP100-C01" to "No equivalent decided"
    And I press "Confirm this equivalent"
    Then I should see "The decision was taken back."
    And I should see "No decision"
