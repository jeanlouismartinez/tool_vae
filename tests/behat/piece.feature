@tool @tool_vae
Feature: A candidate attaches supporting pieces to a declaration
  In order to back a declaration with a document or a link
  As a candidate
  I need to attach a file or a link, read what the plugin kept, and remove it

  # T-13, lot 1 (D-3). The plugin does not read a piece, judge it or visit a link: it keeps what it was given, with a
  # digest for a file, and says what it cannot verify. Removing always works.

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email               |
      | candidat | Camille   | Durand   | camille@example.com |
    And the following "permission overrides" exist:
      | capability       | permission | role | contextlevel | reference |
      | tool/vae:declare | Allow      | user | System       |           |
      | tool/vae:viewown | Allow      | user | System       |           |
    And the following "tool_vae > sources" exist:
      | fichecode | ficherang | shortname                       |
      | RNCP36832 | C03       | Spot the risk factors for falls |
    And the following "tool_vae > declarations" exist:
      | candidate | situation                            |
      | candidat  | Night rounds with the care assistant |
    And the following config values are set as admin:
      | piecesopen       | 1 | tool_vae |
      | piecenoantivirus | 1 | tool_vae |

  Scenario: The candidate attaches a link, reads the warning about what is at an address, and removes it
    Given I log in as "candidat"
    When I am on the "Night rounds with the care assistant" "tool_vae > Piece" page
    Then I should see "No piece is attached to this declaration."
    And I should see "Do not attach a document that names or shows a person you accompany"
    When I set the field "id_linknature" to "Payslip"
    And I set the field "Address" to "https://exemple.test/doc?token=abc"
    And I press "Attach the link"
    Then I should see "The link was attached."
    And I should see "carries a query or a fragment"
    And I should see "https://exemple.test/doc?token=abc"
    When I press "Remove"
    Then I should see "The piece was removed."
    And I should see "removed on"
    And I should not see "https://exemple.test/doc?token=abc"

  Scenario: A piece needs a nature
    Given I log in as "candidat"
    When I am on the "Night rounds with the care assistant" "tool_vae > Piece" page
    And I set the field "Address" to "https://exemple.test/doc"
    And I press "Attach the link"
    Then I should see "Required"
    And I should see "No piece is attached to this declaration."

  Scenario: An address that is not plain http is refused
    Given I log in as "candidat"
    When I am on the "Night rounds with the care assistant" "tool_vae > Piece" page
    And I set the field "id_linknature" to "Payslip"
    And I set the field "Address" to "javascript:alert(1)"
    And I press "Attach the link"
    Then I should see "This address is not accepted."
    And I should see "No piece is attached to this declaration."

  Scenario: The candidate attaches a file, which keeps no name and has a digest
    Given I log in as "candidat"
    When I am on the "Night rounds with the care assistant" "tool_vae > Piece" page
    And I set the field "id_filenature" to "Employment contract"
    And I attach the fixture "piece-essai.pdf" to the field "File"
    And I press "Attach the file"
    Then I should see "The file was attached."
    And I should see "SHA-256"
    And I should see "PDF"
    And I should not see "piece-essai"
    And I should see "Open the file"

  Scenario: Closed, the page says so and offers nothing new
    Given the following config values are set as admin:
      | piecesopen | 0 | tool_vae |
    And I log in as "candidat"
    When I am on the "Night rounds with the care assistant" "tool_vae > Piece" page
    Then I should see "Attaching new pieces is closed."
    And I should not see "Attach the link"
    And I should not see "Attach the file"

  Scenario: Another person cannot open the page of a declaration that is not theirs
    Given the following "users" exist:
      | username | firstname | lastname | email            |
      | autre    | Alex      | Martin   | alex@example.com |
    And I log in as "autre"
    Then the "Night rounds with the care assistant" "Piece" page should be refused with "You may not do this on this piece or this declaration."
