@tool @tool_vae
Feature: The export screen says what the archive carries and what it does not
  In order not to promise a vaeiste more than the archive holds
  As the person who exports a dossier
  I need the screen to say what the archive does not contain and where a reimport can fail

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email             |
      | karim    | Karim     | Benali   | karim@example.com |
    And the following "permission overrides" exist:
      | capability       | permission | role | contextlevel | reference |
      | tool/vae:declare | Allow      | user | System       |           |
      | tool/vae:viewown | Allow      | user | System       |           |
    And the following "tool_vae > sources" exist:
      | fichecode | ficherang | name   | shortname            |
      | RNCP100   | C01       | Record | Accompany the person |
    And the following "tool_vae > declarations" exist:
      | candidate | fichecode | ficherang | situation                            |
      | karim     | RNCP100   | C01       | Night rounds with the care assistant |

  # The administrator who exports the dossier of somebody else reads the text in the third person: no "your dossier", and the
  # account that must not hold an identifier on the other platform is the one of the candidate.
  Scenario: The export screen says what the archive does not carry, to the administrator who exports another dossier
    Given I log in as "admin"
    When I am on the "karim" "tool_vae > Export" page
    Then I should see "This archive does not contain the deposits of the feasibility dossier"
    And I should see "which can be read but not reimported"
    And I should see "provided that other platform has not already numbered differently the competencies it names"
    And I should see "and that the account of the candidate has no identifier there yet"
    And I should not see "your feasibility dossier"

  Scenario: The export screen speaks directly to the vaeiste who exports their own dossier
    Given the following "permission overrides" exist:
      | capability      | permission | role | contextlevel | reference |
      | tool/vae:export | Allow      | user | System       |           |
    And I log in as "karim"
    When I am on the "karim" "tool_vae > Export" page
    Then I should see "This archive does not contain the deposits of your feasibility dossier"
    And I should see "which can be read but not reimported"
    And I should see "provided that other platform has not already numbered differently the competencies it names"
    And I should see "and that your account has no identifier there yet"
