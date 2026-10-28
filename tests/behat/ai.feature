@tool @tool_vae
Feature: A candidate may ask for another wording of their text, with a confirmation each time
  In order to put my own experience into clearer words without anything leaving behind my back
  As a candidate whom the site allows to use the rewording aid
  I need to be shown the policy, then the exact text and where it goes, before anything is sent

  # CLOSED BY DEFAULT (T-70). Nothing is drawn on the forms unless the site opened the aid, the candidate holds the right and one
  # provider can answer. The scenarios stop at the confirmation: no provider is ever reached from here.

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email               |
      | candidat | Camille   | Durand   | camille@example.com |
    And the following "permission overrides" exist:
      | capability       | permission | role | contextlevel | reference |
      | tool/vae:declare | Allow      | user | System       |           |
      | tool/vae:viewown | Allow      | user | System       |           |
    And the following "tool_vae > sources" exist:
      | fichecode | ficherang | name       | shortname            |
      | RNCP100   | C01       | Old record | Accompany the person |

  Scenario: Closed by default, nothing is offered even to a candidate who holds the right and has a provider
    Given the following "permission overrides" exist:
      | capability     | permission | role | contextlevel | reference |
      | tool/vae:useai | Allow      | user | System       |           |
    And the following "tool_vae > aiproviders" exist:
      | name            |
      | Ollama d'essai  |
    And I log in as "candidat"
    When I am on the "tool_vae > Declare" page
    Then "Propose another wording" "button" should not exist

  Scenario: Open, with the right and one provider, the policy comes first and then the confirmation, and nothing is sent
    Given the following "permission overrides" exist:
      | capability     | permission | role | contextlevel | reference |
      | tool/vae:useai | Allow      | user | System       |           |
    And the following "tool_vae > aiproviders" exist:
      | name            |
      | Ollama d'essai  |
    And the following config values are set as admin:
      | aiopen                    | 1 | tool_vae         |
      | automaticdeletionrequests | 1 | tool_dataprivacy |
    And I log in as "candidat"
    When I am on the "tool_vae > Declare" page
    And I set the field "Work situation" to "Le matin, j'aide les residents."
    And I press "Propose another wording"
    Then I should see "AI usage policy"
    And I should see "Welcome to the new AI feature!"
    And I should not see "Policy of use of the rewording aid"
    And the field "Your text" matches value "Le matin, j'aide les residents."
    When I press "I have read the policy and I accept it"
    Then I should see "Check what will be sent"
    And I should see "This exact text will be sent to Ollama d'essai"
    And I should see "until your data is erased"
    And I should see "once the deletion request that Moodle then files has been approved"
    And I should see "Moodle keeps a line in its register"
    And I should see "your account number"
    And I should see "The use is logged"
    And I should not see "does not erase them by itself"
    And I should not see "last two days"
    And the field "Your text" matches value "Le matin, j'aide les residents."
    And "Send this text to Ollama d'essai" "button" should exist

  Scenario: Without the right nothing is offered
    Given the following "tool_vae > aiproviders" exist:
      | name            |
      | Ollama d'essai  |
    And the following config values are set as admin:
      | aiopen | 1 | tool_vae |
    And I log in as "candidat"
    When I am on the "tool_vae > Declare" page
    Then "Propose another wording" "button" should not exist

  Scenario: With two providers nothing is offered, since the text could reach the second without the candidate knowing
    Given the following "permission overrides" exist:
      | capability     | permission | role | contextlevel | reference |
      | tool/vae:useai | Allow      | user | System       |           |
    And the following "tool_vae > aiproviders" exist:
      | name            |
      | Ollama d'essai  |
      | Ollama second   |
    And the following config values are set as admin:
      | aiopen | 1 | tool_vae |
    And I log in as "candidat"
    When I am on the "tool_vae > Declare" page
    Then "Propose another wording" "button" should not exist

  Scenario: On a section of the dossier de faisabilité the aid is offered the same way, down to the confirmation
    Given the following "permission overrides" exist:
      | capability        | permission | role | contextlevel | reference |
      | tool/vae:useai    | Allow      | user | System       |           |
      | tool/vae:draftown | Allow      | user | System       |           |
    And the following "tool_vae > aiproviders" exist:
      | name            |
      | Ollama d'essai  |
    And the following config values are set as admin:
      | aiopen                    | 1 | tool_vae         |
      | automaticdeletionrequests | 1 | tool_dataprivacy |
    And I log in as "candidat"
    When I visit "/admin/tool/vae/section.php?section=objectifs"
    And I set the field "Text of the section" to "Je veux obtenir le diplome pour evoluer."
    And I press "Propose another wording"
    Then I should see "AI usage policy"
    And I should see "Welcome to the new AI feature!"
    When I press "I have read the policy and I accept it"
    Then I should see "Check what will be sent"
    And I should see "This exact text will be sent to Ollama d'essai"
    And I should see "until your data is erased"
    And I should see "after your account is deleted"
    And I should not see "on your request"
    And "Send this text to Ollama d'essai" "button" should exist

  # WHAT THE SCREEN PROMISES IS WHAT MOODLE DOES ON THIS SITE (audit of 27 October 2026). The deletion request that empties the
  # texts exists only where the site files it on its own, and the core never empties what is tied to the site once the data
  # registry defines the purpose and the category of the site: where either holds, the confirmation promises no erasure and says
  # whom to ask.
  Scenario: On a site that does not file deletion requests on its own, the confirmation does not promise an erasure
    Given the following "permission overrides" exist:
      | capability     | permission | role | contextlevel | reference |
      | tool/vae:useai | Allow      | user | System       |           |
    And the following "tool_vae > aiproviders" exist:
      | name            |
      | Ollama d'essai  |
    And the following config values are set as admin:
      | aiopen                    | 1 | tool_vae         |
      | automaticdeletionrequests | 0 | tool_dataprivacy |
    And I log in as "candidat"
    When I am on the "tool_vae > Declare" page
    And I set the field "Work situation" to "Le matin, j'aide les residents."
    And I press "Propose another wording"
    And I press "I have read the policy and I accept it"
    Then I should see "Check what will be sent"
    And I should see "are kept by Moodle in its own tables"
    And I should see "Moodle does not erase them by itself"
    And I should see "ask the site administrator"
    And I should see "The use is logged"
    And I should not see "until your data is erased"
    And I should not see "once the deletion request that Moodle then files has been approved"
    And "Send this text to Ollama d'essai" "button" should exist

  Scenario: On a site whose data registry is defined, the confirmation does not promise an erasure
    Given the following "permission overrides" exist:
      | capability     | permission | role | contextlevel | reference |
      | tool/vae:useai | Allow      | user | System       |           |
    And the following "tool_vae > aiproviders" exist:
      | name            |
      | Ollama d'essai  |
    # The screen reads only whether the registry defines both, as the core does (data_registry::defaults_set()).
    And the following config values are set as admin:
      | aiopen                    | 1 | tool_vae         |
      | automaticdeletionrequests | 1 | tool_dataprivacy |
      | context_system_purpose    | 1 | tool_dataprivacy |
      | context_system_category   | 1 | tool_dataprivacy |
    And I log in as "candidat"
    When I am on the "tool_vae > Declare" page
    And I set the field "Work situation" to "Le matin, j'aide les residents."
    And I press "Propose another wording"
    And I press "I have read the policy and I accept it"
    Then I should see "Check what will be sent"
    And I should see "Moodle does not erase them by itself"
    And I should see "ask the site administrator"
    And I should not see "until your data is erased"
    And I should not see "once the deletion request that Moodle then files has been approved"
    And "Send this text to Ollama d'essai" "button" should exist

  Scenario: The section engagement of the candidate is offered the aid too, as the site decided
    Given the following "permission overrides" exist:
      | capability        | permission | role | contextlevel | reference |
      | tool/vae:useai    | Allow      | user | System       |           |
      | tool/vae:draftown | Allow      | user | System       |           |
    And the following "tool_vae > aiproviders" exist:
      | name            |
      | Ollama d'essai  |
    And the following config values are set as admin:
      | aiopen | 1 | tool_vae |
    And I log in as "candidat"
    When I visit "/admin/tool/vae/section.php?section=engagement"
    Then "Propose another wording" "button" should exist
