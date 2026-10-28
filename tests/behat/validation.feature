@tool @tool_vae
Feature: A candidate prepares their validation dossier, bloc by bloc
  In order to get ready to fill the certifier's own validation dossier
  As a candidate who declared against a record made of blocs
  I need a writing aid that is clear it is not the official dossier, shows my declarations beside it, and keeps my versions

  # NOT THE OFFICIAL DOSSIER (T-11, D-15). The screen says so first; nothing in it assesses what is written.

  Background:
    Given the following config values are set as admin:
      | validationopen | 1 | tool_vae |
    And the following "users" exist:
      | username | firstname | lastname | email               |
      | candidat | Camille   | Durand   | camille@example.com |
    And the following "permission overrides" exist:
      | capability       | permission | role | contextlevel | reference |
      | tool/vae:declare | Allow      | user | System       |           |
      | tool/vae:viewown | Allow      | user | System       |           |
      | tool/vae:draftown | Allow     | user | System       |           |
    And the following "tool_vae > sources" exist:
      | fichecode | ficherang | name   | shortname            |
      | RNCP100   | BC01      | Record | Accompany the person |
    And the following "tool_vae > competencies" exist:
      | fichecode | ficherang | shortname    | bloc |
      | RNCP100   | C01       | Help to wash | BC01 |
    And the following "tool_vae > declarations" exist:
      | candidate | fichecode | ficherang | situation                            |
      | candidat  | RNCP100   | C01       | Night rounds with the care assistant |

  Scenario: The preparation says what it is, lists the blocs and shows my declarations beside
    Given I log in as "candidat"
    When I am on the "tool_vae > Validation" page
    Then I should see "Preparing the validation dossier"
    And I should see "Writing aid proposed by this plugin"
    And I should see "RNCP100"
    When I follow "RNCP100"
    Then I should see "Describe my activities"
    And I should see "Analyse my practice"
    And I should see "Make the link with the bloc"
    And I should see "Not written yet"
    And I should see "Night rounds with the care assistant"

  Scenario: The candidate writes a section and a version is kept
    Given I log in as "candidat"
    When I visit "/admin/tool/vae/section.php?doctype=validation&fiche=RNCP100&section=bc01_decrire"
    Then I should see "Writing aid proposed by this plugin"
    And I should see "Describe the situation by what serves the analysis"
    When I set the field "Text of the section" to "I lead the morning care with a care assistant, as the referent of the pair."
    And I press "Save a version"
    Then I should see "Version 1 saved."
    When I am on the "tool_vae > Validation" page
    And I follow "RNCP100"
    Then I should see "Version 1, saved on"

  Scenario: The preparation is deposited and read as it was deposited, with its labelling sentence
    Given I log in as "candidat"
    When I visit "/admin/tool/vae/deposit.php?doctype=validation"
    And I press "Deposit the dossier"
    Then I should see "Nothing to deposit"
    When I visit "/admin/tool/vae/section.php?doctype=validation&fiche=RNCP100&section=bc01_decrire"
    And I set the field "Text of the section" to "I lead the morning care with a care assistant, as the referent of the pair."
    And I press "Save a version"
    And I visit "/admin/tool/vae/deposit.php?doctype=validation"
    Then I should see "Deposits of the preparation of the validation dossier"
    And I should see "Writing aid proposed by this plugin"
    When I press "Deposit the dossier"
    Then I should see "Deposit number 1 made."
    When I follow "Read deposit 1"
    Then I should see "Writing aid proposed by this plugin"
    And I should see "I lead the morning care with a care assistant, as the referent of the pair."
    And I should see "SHA-256 digest of this deposit"
    When I visit "/admin/tool/vae/deposit.php?doctype=validation"
    And I press "Deposit the dossier"
    Then I should see "Nothing has changed since deposit number 1"
