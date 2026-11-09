@tool @tool_vae
Feature: Whoever may view any dossier reads the configuration of the scheme, and no candidate
  In order to know which records the site holds, around what, and who may do what
  As the direction of the scheme
  I need the "Organisation" tab of the organisation page, which counts the plans of the core and never names them

  # T-90, lot 4. The tab is drawn for whoever holds the right to view any dossier, and refused to everybody else, by the same
  # rule. Nothing in it is a person: the number of learning plans per record has no name, drafts included.

  Background:
    Given the following "users" exist:
      | username  | firstname | lastname  | email                |
      | direction | Dana      | Direction | dana@example.com     |
      | candidat  | Camille   | Durand    | camille@example.com  |
      | suivi     | Sam       | Leroy     | sam@example.com      |
    And the following "roles" exist:
      | shortname | name                |
      | direction | Direction VAE       |
      | follower  | Follows a candidate |
    And the following "permission overrides" exist:
      | capability            | permission | role      | contextlevel | reference |
      | tool/vae:declare      | Allow      | user      | System       |           |
      | tool/vae:viewown      | Allow      | user      | System       |           |
      | tool/vae:viewany      | Allow      | direction | System       |           |
      | tool/vae:viewassigned | Allow      | follower  | System       |           |
      | tool/vae:export       | Prohibit   | follower  | System       |           |
    And the following "role assigns" exist:
      | user      | role      | contextlevel | reference |
      | direction | direction | System       |           |
      | suivi     | follower  | User         | candidat  |
    And the following "tool_vae > sources" exist:
      | fichecode | ficherang | name   | shortname            |
      | RNCP100   | BC01      | Record | Accompany the person |
      | RS200     | C01       | Flat   | Use a lift           |
    And the following "tool_vae > competencies" exist:
      | fichecode | ficherang | shortname    | bloc |
      | RNCP100   | BC02      | Work in team |      |
      | RNCP100   | C01       | Help to wash | BC01 |
      | RNCP100   | C02       | Hand over    | BC02 |
    And the following "tool_vae > declarations" exist:
      | candidate | fichecode | ficherang | situation    |
      | candidat  | RNCP100   | C01       | Night rounds |
    And the following "courses" exist:
      | fullname              | shortname | visible |
      | Care course           | CARE      | 1       |
      | Secret working title  | SECRET    | 0       |
    And the following "core_competency > course_competencies" exist:
      | course | competency   |
      | CARE   | RNCP100-C01  |
      | SECRET | RNCP100-C01  |
    And the following "core_competency > templates" exist:
      | shortname       | visible |
      | Care pathway    | 1       |
      | Secret template | 0       |
    And the following "core_competency > template_competencies" exist:
      | template        | competency  |
      | Care pathway    | RNCP100-C01 |
      | Secret template | RNCP100-C01 |
    And the following "core_competency > plans" exist:
      | name       | user     | status |
      | Plan AES   | candidat | active |
      | Draft plan | candidat | draft  |
    And the following "core_competency > plan_competencies" exist:
      | plan       | competency  |
      | Plan AES   | RNCP100-C01 |
      | Draft plan | RNCP100-C01 |

  Scenario: The direction reads the records with what the core holds around them
    Given I log in as "direction"
    When I am on the "tool_vae > Organisation site" page
    Then "Organisation" "link" should exist in the ".nav-tabs.mb-3" "css_element"
    And "My pathway" "link" should exist in the ".nav-tabs.mb-3" "css_element"
    And "Followed candidates" "link" should not exist in the ".nav-tabs.mb-3" "css_element"
    And the following should exist in the "tool-vae-site-fiches" table:
      | Records | Blocs | Competencies | Courses     | Templates of the core | Learning plans (number) |
      | RNCP100 | 2     | 4            | Care course | Care pathway          | 2                       |
      | RS200   | —     | 1            | none        | none                  | 0                       |
    And I should see "drafts included"

  Scenario: A hidden course and a hidden template are drawn as a word, without their name
    Given I log in as "direction"
    When I am on the "tool_vae > Organisation site" page
    Then I should see "A hidden course"
    And I should see "A hidden template"
    And I should not see "Secret working title"
    And I should not see "Secret template"

  Scenario: The number of plans is a number: no name of a plan, no name of a candidate
    Given I log in as "direction"
    When I am on the "tool_vae > Organisation site" page
    Then I should not see "Plan AES"
    And I should not see "Draft plan"
    And I should not see "Camille"
    And I should not see "Durand"

  Scenario: The rights of the plugin are listed with the roles of the site that give or forbid them
    Given I log in as "direction"
    When I am on the "tool_vae > Organisation site" page
    Then the following should exist in the "tool-vae-site-powers" table:
      | Right                                                    | Roles that give it | Roles that forbid it |
      | View any candidate's dossier and the configuration       | Direction VAE      | none                 |
      | Export a dossier for the panel                           | no role            | Follows a candidate  |
    And I should see "Site administrators hold every right"

  Scenario: A setting that opens a part of the scheme is shown open or closed
    Given the following config values are set as admin:
      | validationopen | 1 | tool_vae |
    And I log in as "direction"
    When I am on the "tool_vae > Organisation site" page
    Then the following should exist in the "tool-vae-site-settings" table:
      | Setting                                                   | State  |
      | Open the preparation of the validation dossier to candidates | open   |
      | Open the reformulation aid to candidates                  | closed |

  Scenario: Somebody who holds the right and has declared nothing arrives on the tab without choosing it
    Given I log in as "direction"
    When I am on the "tool_vae > Organisation" page
    Then ".tool-vae-site-fiches" "css_element" should exist

  Scenario: The administrator reads the tab next to their own pathway
    Given I log in as "admin"
    When I am on the "tool_vae > Organisation" page
    Then "My pathway" "link" should exist in the ".nav-tabs.mb-3" "css_element"
    And "Organisation" "link" should exist in the ".nav-tabs.mb-3" "css_element"
    And ".tool-vae-site-fiches" "css_element" should exist

  Scenario: A manager who follows a candidate is not offered the tab, and its address is refused
    Given I log in as "suivi"
    When I am on the "tool_vae > Organisation" page
    Then "Followed candidates" "link" should exist in the ".nav-tabs.mb-3" "css_element"
    And "Organisation" "link" should not exist in the ".nav-tabs.mb-3" "css_element"
    And the "suivi" "Organisation site" page should be refused with "Sorry, but you do not currently have permissions to do that (View any candidate's dossier and the configuration of the scheme"

  Scenario: A candidate is not offered the tab either, and its address is refused
    Given I log in as "candidat"
    When I am on the "tool_vae > Organisation" page
    Then "My pathway" "link" should exist in the ".nav-tabs.mb-3" "css_element"
    And "Organisation" "link" should not exist in the ".nav-tabs.mb-3" "css_element"
    And the "candidat" "Organisation site" page should be refused with "Sorry, but you do not currently have permissions to do that (View any candidate's dossier and the configuration of the scheme"
