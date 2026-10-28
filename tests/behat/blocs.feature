@tool @tool_vae
Feature: A record with blocs is presented by bloc
  In order to read my dossier the way a jury reads a partial validation
  As a candidate who declares against a record made of blocs of competencies
  I need the list to declare in, my coverage and my declarations grouped by bloc, with counts only

  # PRESENTATION ONLY (T-33, lot 2). A bloc line adds up counts; nothing says that a bloc is complete or validated.

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email               |
      | candidat | Camille   | Durand   | camille@example.com |
    And the following "permission overrides" exist:
      | capability       | permission | role | contextlevel | reference |
      | tool/vae:declare | Allow      | user | System       |           |
      | tool/vae:viewown | Allow      | user | System       |           |
    And the following "tool_vae > sources" exist:
      | fichecode | ficherang | name   | shortname            |
      | RNCP100   | BC01      | Record | Accompany the person |
    And the following "tool_vae > competencies" exist:
      | fichecode | ficherang | shortname      | bloc |
      | RNCP100   | C01       | Help to wash   | BC01 |
      | RNCP100   | C02       | Help to eat    | BC01 |

  Scenario: The list to declare in is grouped by bloc, the bloc itself being choosable
    Given I log in as "candidat"
    When I am on the "tool_vae > Declare" page
    Then the "Competency claimed" select box should contain "RNCP100-BC01 — Accompany the person (the bloc itself)"
    And the "Competency claimed" select box should contain "RNCP100-C01 — Help to wash"
    And the "Competency claimed" select box should contain "RNCP100-C02 — Help to eat"

  Scenario: The coverage and the declaration say the bloc, in counts only
    Given the following "tool_vae > declarations" exist:
      | candidate | fichecode | ficherang | situation                            |
      | candidat  | RNCP100   | C01       | Night rounds with the care assistant |
    And I log in as "candidat"
    When I am on the "candidat" "tool_vae > Dossier" page
    Then I should see "A bloc line adds up its own declarations"
    And I should see "(bloc)"
    And I should see "Night rounds with the care assistant"
    And I should see "Bloc" in the ".tool-vae-claim" "css_element"
    And I should see "RNCP100-BC01 — Accompany the person" in the ".tool-vae-claim" "css_element"
