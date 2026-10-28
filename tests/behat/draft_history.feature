@tool @tool_vae
Feature: A candidate writes a section, keeps its versions, compares them and restores one
  In order to rewrite a text without losing what came before
  As a candidate
  I need every saved version kept, compared word by word, and restorable without rewinding anything

  # THE PARCOURS A CANDIDATE FOLLOWS, BY BUTTONS AND LINKS, AS THEY WOULD.
  # The refusal of another person is the other way round: the page is opened by its address,
  # which is what an intruder does (see access_control.feature for the reasoning).

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email               |
      | candidat | Camille   | Durand   | camille@example.com |
      | autre    | Alex      | Martin   | alex@example.com    |
    And the following "permission overrides" exist:
      | capability         | permission | role | contextlevel | reference |
      | tool/vae:draftown  | Allow      | user | System       |           |

  Scenario: Two versions are kept, compared and one is restored
    Given I log in as "candidat"
    When I visit "/admin/tool/vae/section.php?section=experiences&slot=1"
    And I set the field "Text of the section" to "I prepare the equipment before the morning care."
    And I press "Save a version"
    Then I should see "Version 1 saved."
    When I visit "/admin/tool/vae/section.php?section=experiences&slot=1"
    And I set the field "Text of the section" to "I put away the equipment before the morning care and I write the targeted notes."
    And I set the field "Note on this version (optional)" to "After review"
    And I press "Save a version"
    Then I should see "Version 2 saved."
    When I follow "History and comparison of versions"
    Then I should see "Version 1"
    And I should see "Version 2"
    And I should see "After review"
    When I press "Compare the two selected versions"
    Then I should see "Comparison of two versions"
    And I should see "words added"
    When I follow "History and comparison of versions"
    And I press "Restore"
    Then I should see "Version 3 was created from version 1."
    And I should see "Restoration of version 1."

  Scenario: Saving the same text twice creates no second version
    Given I log in as "candidat"
    When I visit "/admin/tool/vae/section.php?section=experiences&slot=1"
    And I set the field "Text of the section" to "The same text."
    And I press "Save a version"
    Then I should see "Version 1 saved."
    When I press "Save a version"
    Then I should see "Nothing has changed since the latest version: no version was created."

  Scenario: Another person cannot read the history of a section
    Given I log in as "candidat"
    And I visit "/admin/tool/vae/section.php?section=experiences&slot=1"
    And I set the field "Text of the section" to "A private account of my practice."
    And I press "Save a version"
    And I log out
    And I log in as "autre"
    Then the "candidat" "History" page should be refused with "You may not read or write this section."
