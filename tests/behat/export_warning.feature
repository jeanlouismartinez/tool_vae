@tool @tool_vae
Feature: A rank written outside the format does not stop the export of a dossier, it is reported
  In order to hand the dossier to the jury on the day it is due
  As a vaeiste whose declaration holds a rank outside the format, recorded by an earlier version of the plugin
  I need the archive to be produced, and to be told what is wrong with it

  # THE RANK IS READ FROM THE IDENTIFICATION NUMBER OF THE COMPETENCY, which an administrator types freely in the screen of
  # the core, and copied once to the declaration. The pages of the plugin refuse a rank outside the format (since 0.46.0), so
  # the scenario writes the rank straight into the declaration, as an earlier version recorded it. The archive is produced all
  # the same; the page says what will not be readable again and that Moodle cannot correct the line, and the import (not
  # played here) still refuses such an archive.

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email               |
      | candidat | Camille   | Durand   | camille@example.com |
    And the following "permission overrides" exist:
      | capability       | permission | role | contextlevel | reference |
      | tool/vae:declare | Allow      | user | System       |           |
      | tool/vae:viewown | Allow      | user | System       |           |
      | tool/vae:export  | Allow      | user | System       |           |
    And the following "tool_vae > sources" exist:
      | fichecode | ficherang | name   | shortname            |
      | RNCP100   | C01       | Record | Accompany the person |
    And the following "tool_vae > declarations" exist:
      | candidate | fichecode | ficherang | situation                            |
      | candidat  | RNCP100   | C01       | Night rounds with the care assistant |

  Scenario: A rank written c7 does not stop the export, the archive is produced and the page warns
    Given the declaration "Night rounds with the care assistant" holds the rank "c7" written directly
    And I log in as "candidat"
    When I am on the "candidat" "tool_vae > Export" page
    And I press "Continue"
    Then I should see "has been produced"
    And I should see "whose format is not the one expected"
    And I should see "cannot be corrected from Moodle"
    And I should see "RNCP100-c7"
    And I should see "Download the archive"

  # A value is whatever an administrator typed in the competency screen of the core: the page shows it as text. Read as markup,
  # the browser would show "RNCP100-x" with a bold x, and the step below would not find the text as typed. The value is of eight
  # characters, the width of the column.
  Scenario: A rank that holds markup is shown as text on the page, not read as markup
    Given the declaration "Night rounds with the care assistant" holds the rank "<b>x</b>" written directly
    And I log in as "candidat"
    When I am on the "candidat" "tool_vae > Export" page
    And I press "Continue"
    Then I should see "has been produced"
    And I should see "RNCP100-<b>x</b>"
    And I should see "Download the archive"

  Scenario: A sound dossier is exported without any warning
    Given I log in as "candidat"
    When I am on the "candidat" "tool_vae > Export" page
    And I press "Continue"
    Then I should see "has been produced"
    And I should not see "whose format is not the one expected"
