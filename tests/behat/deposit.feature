@tool @tool_vae
Feature: A candidate deposits the dossier de faisabilité as a dated milestone
  In order to give the people who follow me something fixed to read, without showing my drafts
  As a candidate
  I need to deposit what I have saved, and read my deposits again

  # A DEPOSIT IS A MILESTONE, NOT A LOCK (D-11). The candidate goes on writing; a new deposit is a
  # new row; nothing is deposited that was not saved.

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email               |
      | candidat | Camille   | Durand   | camille@example.com |
    And the following "permission overrides" exist:
      | capability        | permission | role | contextlevel | reference |
      | tool/vae:draftown | Allow      | user | System       |           |
      | tool/vae:viewown  | Allow      | user | System       |           |

  Scenario: Nothing saved, nothing to deposit
    Given I log in as "candidat"
    When I visit "/admin/tool/vae/deposit.php"
    Then I should see "No deposit has been made yet."
    When I press "Deposit the dossier"
    Then I should see "Nothing to deposit"

  Scenario: A saved section is deposited, read again, and a second identical deposit is refused
    Given I log in as "candidat"
    When I visit "/admin/tool/vae/section.php?section=objectifs"
    And I set the field "Text of the section" to "I want to obtain the diploma to move up."
    And I press "Save a version"
    And I visit "/admin/tool/vae/deposit.php"
    And I set the field "Note (optional)" to "For my first reading"
    And I press "Deposit the dossier"
    Then I should see "Deposit number 1 made."
    And I should see "For my first reading"
    When I follow "Read deposit 1"
    Then I should see "Objectives of the approach"
    And I should see "I want to obtain the diploma to move up."
    And I should see "SHA-256 digest of this deposit"
    When I visit "/admin/tool/vae/deposit.php"
    And I press "Deposit the dossier"
    Then I should see "Nothing has changed since deposit number 1"
