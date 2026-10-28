@tool @tool_vae
Feature: A manager asks a candidate for a precision on a declaration, and the candidate answers
  In order to understand a declaration before attesting it
  As a manager who follows a candidate
  I need to ask a question in words, and read the candidate's answer beside the declaration

  # A QUESTION, NOT A GATE, NOT A VERDICT (D-1, D-13). The plugin records the two texts with their
  # dates and holds nothing back: the attestation does not wait for the answer.

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email               |
      | candidat | Camille   | Durand   | camille@example.com |
      | suivi    | Sam       | Leroy    | sam@example.com     |
    And the following "roles" exist:
      | shortname | name                |
      | follower  | Follows a candidate |
    And the following "permission overrides" exist:
      | capability            | permission | role     | contextlevel | reference |
      | tool/vae:declare      | Allow      | user     | System       |           |
      | tool/vae:viewown      | Allow      | user     | System       |           |
      | tool/vae:viewassigned | Allow      | follower | System       |           |
      | tool/vae:attest       | Allow      | follower | System       |           |
    And the following "role assigns" exist:
      | user  | role     | contextlevel | reference |
      | suivi | follower | User         | candidat  |
    And the following "tool_vae > sources" exist:
      | fichecode | ficherang | shortname                       |
      | RNCP36832 | C03       | Spot the risk factors for falls |
    And the following "tool_vae > declarations" exist:
      | candidate | situation                            |
      | candidat  | Night rounds with the care assistant |

  Scenario: The manager asks, the candidate reads the question and answers it
    Given I log in as "suivi"
    When I am on the "Night rounds with the care assistant" "tool_vae > Precision" page
    Then I should see "No question has been asked on this declaration."
    When I set the field "Your question" to "Which role did you hold during these rounds?"
    And I press "Ask this question"
    Then I should see "Question recorded."
    And I should see "Which role did you hold during these rounds?"
    And I should see "Awaiting the candidate's answer."
    And I should not see "Ask this question"
    When I log out
    And I log in as "candidat"
    And I am on the "Night rounds with the care assistant" "tool_vae > Precision" page
    Then I should see "Which role did you hold during these rounds?"
    When I set the field "Your answer" to "I was the referent of the night team."
    And I press "Record this answer"
    Then I should see "Answer recorded."
    And I should see "I was the referent of the night team."
    And I should not see "Record this answer"

  Scenario: The candidate cannot ask a question on their own declaration
    Given I log in as "candidat"
    When I am on the "Night rounds with the care assistant" "tool_vae > Precision" page
    Then I should see "No question has been asked on this declaration."
    And I should not see "Ask this question"
