@tool @tool_vae
Feature: A bloc whose published label is too long is shown by its published label
  In order to read the bloc the way France competences publishes it
  As a candidate who declares against a record imported from its export
  I need to see the published label, not the neutral title the import had to give it

  # T-31. A label of more than 100 characters cannot be the name of a competency of the core: the converter gives the
  # neutral title "Bloc BC01" and writes the published label whole in the description. The plugin shows the label.

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email               |
      | candidat | Camille   | Durand   | camille@example.com |
    And the following "permission overrides" exist:
      | capability       | permission | role | contextlevel | reference |
      | tool/vae:declare | Allow      | user | System       |           |
      | tool/vae:viewown | Allow      | user | System       |           |
    And the following "tool_vae > sources" exist:
      | fichecode | ficherang | name   | shortname | description                                                                                                      |
      | RNCP100   | BC01      | Record | Bloc BC01 | Accompany and support the person in the acts of daily life, in respect of their rights, choices and own rhythm |
    And the following "tool_vae > competencies" exist:
      | fichecode | ficherang | shortname    | bloc |
      | RNCP100   | C01       | Help to wash | BC01 |

  Scenario: The list to declare in shows the published label of the bloc
    Given I log in as "candidat"
    When I am on the "tool_vae > Declare" page
    Then the "Competency claimed" select box should contain "RNCP100-BC01 — Accompany and support the person in the acts of daily life, in respect of their rights, choices and own rhythm (the bloc itself)"
    And the "Competency claimed" select box should contain "RNCP100-C01 — Help to wash"
    And the "Competency claimed" select box should not contain "RNCP100-BC01 — Bloc BC01 (the bloc itself)"

  Scenario: The dossier shows the published label of the bloc
    Given the following "tool_vae > declarations" exist:
      | candidate | fichecode | ficherang | situation                            |
      | candidat  | RNCP100   | C01       | Night rounds with the care assistant |
    And I log in as "candidat"
    When I am on the "candidat" "tool_vae > Dossier" page
    Then I should see "Accompany and support the person in the acts of daily life" in the ".tool-vae-claim" "css_element"
    And I should not see "Bloc BC01 —"
