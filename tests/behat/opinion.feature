@tool @tool_vae
Feature: A manager who follows a candidate reads a deposit and writes an opinion on it
  In order to say, in my own words, what I think of a deposited dossier de faisabilité
  As a manager who follows a candidate
  I need to read the deposit, never the drafts, and to write an opinion the candidate then reads

  # THE OPINION IS A STATEMENT, NOT A VERDICT (D-1, D-12). The plugin records what was written,
  # with its date and its author; it says nothing of admissibility. An opinion is written once and
  # never rewritten. Only a manager who follows the candidate AND holds both rights writes one.

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email               |
      | candidat | Camille   | Durand   | camille@example.com |
      | suivi    | Sam       | Leroy    | sam@example.com     |
      | lecteur  | Lou       | Garnier  | lou@example.com     |
    And the following "roles" exist:
      | shortname | name                          |
      | follower  | Follows and advises           |
      | reader    | Follows and only reads        |
    And the following "permission overrides" exist:
      | capability            | permission | role     | contextlevel | reference |
      | tool/vae:draftown     | Allow      | user     | System       |           |
      | tool/vae:viewown      | Allow      | user     | System       |           |
      | tool/vae:viewassigned | Allow      | follower | System       |           |
      | tool/vae:readdeposit  | Allow      | follower | System       |           |
      | tool/vae:writeopinion | Allow      | follower | System       |           |
      | tool/vae:viewassigned | Allow      | reader   | System       |           |
      | tool/vae:readdeposit  | Allow      | reader   | System       |           |
    And the following "role assigns" exist:
      | user    | role     | contextlevel | reference |
      | suivi   | follower | User         | candidat  |
      | lecteur | reader   | User         | candidat  |
    And I log in as "candidat"
    And I visit "/admin/tool/vae/section.php?section=objectifs"
    And I set the field "Text of the section" to "I want to obtain the diploma to move up."
    And I press "Save a version"
    And I visit "/admin/tool/vae/deposit.php"
    And I press "Deposit the dossier"
    And I log out

  Scenario: A manager who follows the candidate writes an opinion, which then cannot be written again
    Given I log in as "suivi"
    When I am on the "candidat" "tool_vae > Deposit" page
    Then I should see "I want to obtain the diploma to move up."
    And I should see "No opinion has been written on this deposit."
    When I set the field "Your opinion" to "The path is clear and the objective is stated in the candidate's own words."
    And I press "Record this opinion"
    Then I should see "Opinion recorded."
    And I should see "The path is clear and the objective is stated in the candidate's own words."
    And I should not see "No opinion has been written on this deposit."
    When I set the field "Your opinion" to "The path is clear and the objective is stated in the candidate's own words."
    And I press "Record this opinion"
    Then I should see "You have already written this same opinion on this deposit."

  Scenario: The candidate reads the opinion and cannot write one
    Given I log in as "suivi"
    And I am on the "candidat" "tool_vae > Deposit" page
    And I set the field "Your opinion" to "A clear dossier."
    And I press "Record this opinion"
    And I log out
    When I log in as "candidat"
    And I am on the "candidat" "tool_vae > Deposit" page
    Then I should see "A clear dossier."
    And I should not see "Record this opinion"

  Scenario: A manager who may read deposits but not write opinions reads without a form
    Given I log in as "lecteur"
    When I am on the "candidat" "tool_vae > Deposit" page
    Then I should see "I want to obtain the diploma to move up."
    And I should not see "Record this opinion"
