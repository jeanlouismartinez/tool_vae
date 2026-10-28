@tool @tool_vae
Feature: A candidate keeps a private reflective journal
  In order to think about my practice as I go along, before I know what will go into my dossier
  As a candidate
  I need a journal that nobody else reads, that I can write in, look back on and delete from

  # THE JOURNAL IS THE AUTHOR'S ALONE (T-14). It says so on every screen, keeps the anonymisation reminder, assesses nothing.

  Background:
    Given the following config values are set as admin:
      | reflectionopen | 1 | tool_vae |
    And the following "users" exist:
      | username | firstname | lastname | email               |
      | candidat | Camille   | Durand   | camille@example.com |
    And the following "permission overrides" exist:
      | capability        | permission | role | contextlevel | reference |
      | tool/vae:draftown | Allow      | user | System       |           |
      | tool/vae:viewown  | Allow      | user | System       |           |

  Scenario: The journal says it is private, keeps the reminder, and starts empty
    Given I log in as "candidat"
    When I visit "/admin/tool/vae/journal.php"
    Then I should see "My reflective journal"
    And I should see "This journal is yours alone"
    And I should see "Even without a name, a situation told with its place and its date can identify a person."
    And I should see "No entry yet."

  Scenario: An entry is written, saved as a version, listed, and deleted after confirmation
    Given I log in as "candidat"
    When I visit "/admin/tool/vae/journal.php"
    And I press "New entry"
    Then I should see "Entry 1"
    And I should see "None is required."
    When I set the field "Text of the section" to "This morning I led the handover for the first time."
    And I press "Save a version"
    Then I should see "Version 1 saved."
    When I visit "/admin/tool/vae/journal.php"
    Then I should see "Entry 1"
    When I follow "Delete"
    Then I should see "Delete entry 1 with all its versions?"
    When I press "Continue"
    Then I should see "The entry was deleted with all its versions."
    And I should see "No entry yet."
