@tool @tool_vae
Feature: The direction reads, per actor of the scheme, the rights to hold and the roles of the site that give them
  In order to see at a glance whether the roles of the site cover the scheme
  As the direction of the scheme
  I need the grid of the actors and their rights in the "Organisation" tab, with a word where no role gives a right

  # T-90, lot 2. A reading of the configuration after the guide (section 2.4): never a person. A right that should be
  # given and that no role gives is flagged; one that is kept for the site administrators is not.

  Background:
    Given the following "users" exist:
      | username  | firstname | lastname  | email                |
      | direction | Dana      | Direction | dana@example.com     |
    And the following "roles" exist:
      | shortname | name          |
      | direction | Direction VAE |
      | vaeiste   | Vaeiste role  |
    And the following "permission overrides" exist:
      | capability       | permission | role      | contextlevel | reference |
      | tool/vae:viewany | Allow      | direction | System       |           |
      | tool/vae:declare | Allow      | vaeiste   | System       |           |
      | tool/vae:viewown | Allow      | vaeiste   | System       |           |
      | tool/vae:viewown | Prohibit   | direction | System       |           |
    And the following "role assigns" exist:
      | user      | role      | contextlevel | reference |
      | direction | direction | System       |           |

  Scenario: A right a role gives names the role, and a right nobody gives is flagged
    Given I log in as "direction"
    When I am on the "tool_vae > Organisation site" page
    Then the following should exist in the "tool-vae-site-actors" table:
      | Actor           | Right                       | Roles that give it                                   |
      | Vaeiste         | tool/vae:declare            | Vaeiste role                                         |
      | Vaeiste         | tool/vae:draftown           | Missing No role gives this right: only the site administrators can use it |
      | VAE direction   | tool/vae:viewany            | Direction VAE                                        |
    And I should see "forbidden by Direction VAE"

  Scenario: A right the manager cannot do without is flagged as one nobody can use, even to the site administrators
    Given I log in as "direction"
    When I am on the "tool_vae > Organisation site" page
    Then the following should exist in the "tool-vae-site-actors" table:
      | Actor           | Right                   | Roles that give it                                         |
      | VAE manager     | tool/vae:viewassigned   | Missing No role gives this right: nobody can use it         |
      | VAE manager     | tool/vae:writeopinion   | Missing No role gives this right: nobody can use it         |

  Scenario: A right kept for the administrators and an optional right are not flagged
    Given I log in as "direction"
    When I am on the "tool_vae > Organisation site" page
    Then the following should exist in the "tool-vae-site-actors" table:
      | Actor                        | Right                   | Roles that give it                           |
      | Scheme administrator         | tool/vae:import         | No role: kept for the site administrators     |
      | VAE direction                | tool/vae:draftview      | No role (optional)                           |
    And I should see "No account: the jury receives the archive and decides alone."
