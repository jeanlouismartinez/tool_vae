@tool @tool_vae
Feature: The archive of the accompanying structure is a document of its own, produced by the direction or by a manager it authorized
  In order to hand over what the structure says about a vaeiste without ever mixing it with the vaeiste's dossiers
  As the direction, a manager who follows and the vaeiste
  I need an archive of the structure that holds the positionings and nothing else, that the direction authorizes a manager to produce

  # Decision of the project owner, 7 November 2026. The positionings of the structure are not in the archive or the copies of the
  # vaeiste: they are in the archive of the structure, one per vaeiste, the new one replacing the old one.

  Background:
    Given the following "users" exist:
      | username    | firstname | lastname  | email                |
      | candidat    | Camille   | Durand    | camille@example.com  |
      | responsable | Nadia     | Benali    | nadia@example.com    |
      | direction   | Dana      | Direction | dana@example.com     |
    And the following "roles" exist:
      | shortname | name                |
      | vaeiste   | Vaeiste role        |
      | follower  | Follows a candidate |
      | director  | Direction VAE       |
    And the following "permission overrides" exist:
      | capability              | permission | role     | contextlevel | reference |
      | tool/vae:declare        | Allow      | vaeiste  | System       |           |
      | tool/vae:viewown        | Allow      | vaeiste  | System       |           |
      | tool/vae:viewassigned   | Allow      | follower | System       |           |
      | tool/vae:evaluate       | Allow      | follower | System       |           |
      | tool/vae:viewany        | Allow      | director | System       |           |
      | tool/vae:issuestructure | Allow      | director | System       |           |
    And the following "role assigns" exist:
      | user        | role     | contextlevel | reference |
      | candidat    | vaeiste  | System       |           |
      | responsable | follower | User         | candidat  |
      | direction   | director | System       |           |
    And the following "tool_vae > sources" exist:
      | fichecode | ficherang | name   | shortname            |
      | RNCP100   | BC01      | Record | Accompany the person |
    And the following "tool_vae > competencies" exist:
      | fichecode | ficherang | shortname    | bloc |
      | RNCP100   | C01       | Help to wash | BC01 |
    And the following "tool_vae > declarations" exist:
      | candidate | fichecode | ficherang | situation    |
      | candidat  | RNCP100   | C01       | Night rounds |
    And the following "tool_vae > evaluations" exist:
      | candidate | fichecode | ficherang | author      | status     | reason         |
      | candidat  | RNCP100   | C01       | responsable | sufficient | Three seen.    |

  Scenario: The direction produces the archive of the structure and the vaeiste can download it
    Given I log in as "direction"
    When I am on the "candidat" "tool_vae > Structure" page
    Then I should see "Produce the archive of the structure of this vaeiste?"
    And I should see "neither the dossier of the vaeiste nor a validation"
    When I press "Continue"
    Then I should see "The archive of the structure is produced."
    And I should see "Download the archive of the structure"
    And I log out
    And I log in as "candidat"
    And I am on the "candidat" "tool_vae > Dossier" page
    Then I should see "Archive of the structure in place"
    And I should see "Download the archive of the structure"
    And I should not see "Produce the archive of the structure"

  Scenario: A manager nobody authorized is refused
    Given I log in as "responsable"
    Then the "candidat" "Structure" page should be refused with "Sorry, but you do not currently have permissions to do that (Produce the archive of the structure for a candidate, and authorize a manager to produce it)."

  Scenario: The direction authorizes the manager, who then produces it, and takes the authorization back
    Given I log in as "direction"
    When I am on the "tool_vae > Booklet authorization" page
    Then I should see "Not authorized" in the "Nadia Benali" "table_row"
    When I click on "Authorize" "button" in the "Nadia Benali" "table_row"
    Then I should see "The decision is recorded."
    And I should see "Authorized" in the "Nadia Benali" "table_row"
    When I log out
    And I log in as "responsable"
    And I am on the "candidat" "tool_vae > Structure" page
    Then I should see "Produce the archive of the structure of this vaeiste?"
    When I log out
    And I log in as "direction"
    And I am on the "tool_vae > Booklet authorization" page
    And I click on "Take back" "button" in the "Nadia Benali" "table_row"
    Then I should see "Not authorized" in the "Nadia Benali" "table_row"
    When I log out
    Then I log in as "responsable"
    And the "candidat" "Structure" page should be refused with "Sorry, but you do not currently have permissions to do that (Produce the archive of the structure for a candidate, and authorize a manager to produce it)."
