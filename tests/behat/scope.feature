@tool @tool_vae
Feature: A candidate says that a competency is outside their activity, and it exempts them from nothing
  In order to tell the panel that a competency of the record does not belong to my work, without it reading as a failure
  As a candidate
  I need to say it, see it on my dossier next to the sentence that says it exempts from nothing, and take it back

  # DECLARATIVE AND WITHOUT EFFECT (T-42, D-1, D-4). The mark sits in a box of its own, apart from the coverage. Closed by
  # default: what a candidate already said stays readable and can be taken back.

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

  Scenario: Closed by default, the candidate can read the page but add nothing
    Given I log in as "candidat"
    When I am on the "tool_vae > Scope" page
    Then I should see "This function is not open on this site"
    And I should not see "Competency or bloc"
    And I should not see "Say it is outside my activity"

  Scenario: The candidate says it, sees it with the sentence, and takes it back
    Given the following config values are set as admin:
      | scopeopen | 1 | tool_vae |
    And I log in as "candidat"
    When I am on the "tool_vae > Scope" page
    And I set the field "Competency or bloc" to "RNCP100-C01 — Accompany the person"
    And I set the field "Note (optional)" to "My post does not go through it."
    And I press "Say it is outside my activity"
    Then I should see "It is recorded as outside your activity."
    And I should see "My post does not go through it."
    When I am on the "candidat" "tool_vae > Dossier" page
    Then I should see "Outside my activity"
    And I should see "RNCP100-C01"
    And I should see "It exempts from nothing: the decision belongs to the panel."
    When I am on the "tool_vae > Scope" page
    And I press "Take back"
    Then I should see "The mark was taken back."
    And I should see "You have not said that any competency is outside your activity."

  Scenario: A mark already made stays readable and can be taken back when the site closes the function
    Given the following config values are set as admin:
      | scopeopen | 1 | tool_vae |
    And I log in as "candidat"
    And I am on the "tool_vae > Scope" page
    And I set the field "Competency or bloc" to "RNCP100-C01 — Accompany the person"
    And I press "Say it is outside my activity"
    And I log out
    And the following config values are set as admin:
      | scopeopen | 0 | tool_vae |
    When I log in as "candidat"
    And I am on the "tool_vae > Scope" page
    Then I should see "RNCP100-C01"
    And I should see "This function is not open on this site"
    When I press "Take back"
    Then I should see "The mark was taken back."
