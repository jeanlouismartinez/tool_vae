@tool @tool_vae
Feature: The administrator creates the VAE roles from the presets, one by one, and confirms
  In order to avoid importing XML files by hand
  As the administrator
  I need a page that creates the roles of the plugin only when I ask, and never changes one that exists

  # Nothing is created by opening the page; a role that exists is shown as such and kept as it is.

  Scenario: The page lists the three base roles and the two optional ones, none created yet
    Given I log in as "admin"
    When I am on the "tool_vae > Roles installer" page
    Then I should see "The three base roles"
    And I should see "Two optional roles"
    And I should see "Create \"Vaeiste\""
    And I should see "Create \"Direction VAE certificatrice\""
    And I should not see "Already exists"

  Scenario: A role is asked for, confirmed and created with its rights only
    Given I log in as "admin"
    And I am on the "tool_vae > Roles installer" page
    When I press "Create \"Vaeiste\""
    Then I should see "will be created with these rights"
    And I should see "tool/vae:declare"
    And I should not see "tool/vae:certify"
    When I press "Create the role"
    Then I should see "The role \"Vaeiste\" was created"
    And I should see "Already exists (not changed)"
    And I should not see "Create \"Vaeiste\""
    And I should see "Create \"Responsable VAE\""

  Scenario: Cancelling the confirmation creates nothing
    Given I log in as "admin"
    And I am on the "tool_vae > Roles installer" page
    When I press "Create \"Direction VAE\""
    And I press "Cancel"
    Then I should see "Create \"Direction VAE\""
    And I should not see "Already exists"

  Scenario: Somebody who may not manage roles is refused
    Given the following "users" exist:
      | username | firstname | lastname | email             |
      | simple   | Sam       | Leroy    | sam@example.com   |
    And I log out
    And I log in as "simple"
    Then the "any" "Roles installer" page should be refused with "Access denied"
