@tool @tool_vae
Feature: A manager reads the supporting pieces of a declaration before attesting it
  In order to sign what I was shown
  As a manager who follows a candidate and may attest
  I need the pieces listed on the attestation screen, a box to say I took note of them, and a mark on a piece added later

  # T-13, lot 2 (D-3, MC-25, MC-26). The box records a DECLARATION, not a fact: the plugin cannot know that a file was read.
  # The list signed is the list shown; a piece added after the signature is marked, and the signature does not cover it.

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email               |
      | candidat | Camille   | Durand   | camille@example.com |
      | suivi    | Sam       | Leroy    | sam@example.com     |
      | lecteur  | Lou       | Marti    | lou@example.com     |
    And the following "roles" exist:
      | shortname | name                |
      | follower  | Follows a candidate |
      | viewer    | Reads a dossier     |
    And the following "permission overrides" exist:
      | capability            | permission | role     | contextlevel | reference |
      | tool/vae:declare      | Allow      | user     | System       |           |
      | tool/vae:viewown      | Allow      | user     | System       |           |
      | tool/vae:viewassigned | Allow      | follower | System       |           |
      | tool/vae:attest       | Allow      | follower | System       |           |
      | tool/vae:viewassigned | Allow      | viewer   | System       |           |
    And the following "role assigns" exist:
      | user    | role     | contextlevel | reference |
      | suivi   | follower | User         | candidat  |
      | lecteur | viewer   | User         | candidat  |
    And the following "tool_vae > sources" exist:
      | fichecode | ficherang | shortname                       |
      | RNCP36832 | C03       | Spot the risk factors for falls |
    And the following "tool_vae > declarations" exist:
      | candidate | situation                            |
      | candidat  | Night rounds with the care assistant |
    And the following config values are set as admin:
      | piecesopen       | 1 | tool_vae |
      | piecenoantivirus | 1 | tool_vae |

  Scenario: The pieces are listed above the form, and the box of the pieces is required
    Given the following "tool_vae > pieces" exist:
      | declaration                          | nature  | title        | url                        |
      | Night rounds with the care assistant | diploma | Mon diplome  | https://exemple.test/doc/1 |
    And I log in as "suivi"
    When I am on the "Night rounds with the care assistant" "tool_vae > Attest" page
    Then I should see "Supporting pieces of this declaration"
    And I should see "Diploma or training certificate"
    And I should see "https://exemple.test/doc/1"
    When I set the following fields to these values:
      | Your position and establishment | Care manager, Les Tilleuls residential home |
      | Wording of your attestation     | I supervised these night rounds myself.     |
      | confirmread                     | 1                                           |
    And I press "Attest this declaration"
    Then I should see "Tick the box to say that you took note of the supporting pieces."
    When I set the field "id_piecesread" to "1"
    And I press "Attest this declaration"
    Then I should see "Attestation recorded."

  Scenario: A piece added after the signature is marked, and the signature does not cover it
    Given the following "tool_vae > attestations" exist:
      | declaration                          | attestant |
      | Night rounds with the care assistant | suivi     |
    And the following "tool_vae > pieces" exist:
      | declaration                          | nature   | title        | url                        |
      | Night rounds with the care assistant | contract | Un contrat   | https://exemple.test/doc/2 |
    And I log in as "suivi"
    When I am on the "Night rounds with the care assistant" "tool_vae > Piece" page
    Then I should see "Added after the signature of"
    And I should see "Employment contract"
    And "Remove" "button" should not exist in the ".tool-vae-pieces" "css_element"

  Scenario: A reader who may only view the dossier sees the list and cannot open a piece
    Given the following "tool_vae > pieces" exist:
      | declaration                          | nature  | title       | url                        |
      | Night rounds with the care assistant | diploma | Mon diplome | https://exemple.test/doc/1 |
    And I log in as "lecteur"
    When I am on the "Night rounds with the care assistant" "tool_vae > Piece" page
    Then I should see "Diploma or training certificate"
    And I should see "Listed here. You may not open it."
    And I should not see "https://exemple.test/doc/1"
    And I should not see "Mon diplome"
    And I should not see "Attach the link"
    And "Remove" "button" should not exist in the ".tool-vae-pieces" "css_element"
