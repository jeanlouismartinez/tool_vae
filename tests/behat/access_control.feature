@tool @tool_vae
Feature: The pages of a dossier refuse whoever may not act on it, even when reached by their address
  In order to keep a candidate's account of their practice between the people entitled to it
  As a candidate, a manager who follows a candidate, or a site-wide signatory
  I need every page to check who asks, whatever link or address brought them there

  # EVERY PAGE HERE IS OPENED BY ITS ADDRESS, NEVER BY A BUTTON.
  # A button is only drawn for those entitled to press it, so following buttons proves what the
  # dossier shows, not what the page behind it accepts. Typing the address is what an intruder
  # does, and what these scenarios do.
  #
  # The refusals are asserted with "the ... page should be refused with ...", a step of this
  # plugin: Moodle's Behat fails any step that leaves an exception page on screen, so the step
  # reads the refusal and leaves the page itself. See behat_tool_vae.php.

  Background:
    Given the following "users" exist:
      | username   | firstname | lastname | email                 |
      | candidat   | Camille   | Durand   | camille@example.com   |
      | autre      | Alex      | Martin   | alex@example.com      |
      | suivi      | Sam       | Leroy    | sam@example.com       |
      | signataire | Dominique | Petit    | dominique@example.com |
    And the following "roles" exist:
      | shortname | name                        |
      | follower  | Follows a candidate         |
      | signatory | Attests, site-wide    |
    # Declaring and reading one's own dossier are granted to every authenticated user at SITE
    # level: the very assignment db/access.php warns against, inherited by every user context.
    # The refusals below must hold even so, because ownership is proved in PHP.
    #
    # The follower also holds export in the candidate's context, so that the refusal of the
    # export proves the rule and not a missing capability.
    And the following "permission overrides" exist:
      | capability                    | permission | role      | contextlevel | reference |
      | tool/vae:declare      | Allow      | user      | System       |           |
      | tool/vae:viewown      | Allow      | user      | System       |           |
      | tool/vae:viewassigned | Allow      | follower  | System       |           |
      | tool/vae:attest       | Allow      | follower  | System       |           |
      | tool/vae:export       | Allow      | follower  | System       |           |
      | tool/vae:attest       | Allow      | signatory | System       |           |
    And the following "role assigns" exist:
      | user       | role      | contextlevel | reference |
      | suivi      | follower  | User         | candidat  |
      | signataire | signatory | System       |           |
    And the following "tool_vae > sources" exist:
      | fichecode | ficherang | shortname                       |
      | RNCP36832 | C03       | Spot the risk factors for falls |
    And the following "tool_vae > declarations" exist:
      | candidate | situation                            |
      | candidat  | Night rounds with the care assistant |

  Scenario: A candidate reads their own dossier and not another candidate's
    Given I log in as "candidat"
    When I am on the "candidat" "tool_vae > Dossier" page
    Then I should see "Night rounds with the care assistant"
    And the "autre" "Dossier" page should be refused with "Sorry, but you do not currently have permissions to do that (View any candidate's dossier)."

  # Following a candidate opens their dossier for reading. The archive leaves the platform for
  # good, so exporting it stays with the candidate and the direction - decided with the
  # project owner on 11 October 2026.
  Scenario: A manager who follows a candidate reads the dossier but cannot export it
    Given I log in as "suivi"
    When I am on the "candidat" "tool_vae > Dossier" page
    Then I should see "Night rounds with the care assistant"
    And the "candidat" "Export" page should be refused with "Sorry, but you do not currently have permissions to do that (Export a dossier for the panel)."

  # The A1 finding of 12 October 2026: the right to attest, held at site level, reaches every user
  # context, and attest.php shows the narrative of whatever declaration its address names.
  # Nobody signs what they may not read. The refusal names no cause: the holder does have the
  # capability, and told they lacked it they would look for the wrong fix.
  Scenario: The right to attest, held site-wide, does not open a declaration its holder may not read
    Given I log in as "signataire"
    Then the "Night rounds with the care assistant" "Attest" page should be refused with "You may attest only a declaration in a dossier you can read, with the right to attest it."

  # Withdrawing an attestation is the site administrator's act alone, decided with the
  # project owner on 12 October 2026 - the signatory included.
  Scenario: The right to attest, held site-wide, does not open the withdrawal of someone else's attestation
    Given the following "tool_vae > attestations" exist:
      | declaration                          | attestant |
      | Night rounds with the care assistant | suivi     |
    And I log in as "signataire"
    Then the "Night rounds with the care assistant" "Withdraw" page should be refused with "You may not withdraw this attestation."
    And I log out
    And I log in as "suivi"
    And the "Night rounds with the care assistant" "Withdraw" page should be refused with "You may not withdraw this attestation."

  # The other side of the same rule: the refusals above are not the page refusing everyone.
  Scenario: A manager who follows the candidate attests the declaration
    Given I log in as "suivi"
    When I am on the "Night rounds with the care assistant" "tool_vae > Attest" page
    And I set the following fields to these values:
      | Your position and establishment  | Care manager, Les Tilleuls residential home |
      | Wording of your attestation | I supervised these night rounds myself.     |
      | confirmread                      | 1                                           |
    And I press "Attest this declaration"
    Then I should see "Attestation recorded."
