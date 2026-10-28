@tool @tool_vae
Feature: A candidate follows the sections of the dossier de faisabilité from one list
  In order to know what is written, what is a draft and what remains to write
  As a candidate
  I need one page that lists the sections, says where each stands and leads to it

  # THE PARCOURS, BY BUTTONS AND LINKS. The list says what is there and never whether it is
  # enough: the certifying body decides (D-1). It creates nothing by being looked at.

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email               |
      | candidat | Camille   | Durand   | camille@example.com |
    And the following "permission overrides" exist:
      | capability        | permission | role | contextlevel | reference |
      | tool/vae:draftown | Allow      | user | System       |           |
      | tool/vae:viewown  | Allow      | user | System       |           |

  Scenario: The list is reached from the dossier and shows every section
    Given I log in as "candidat"
    When I visit "/admin/tool/vae/dossier.php"
    And I press "Dossier de faisabilité"
    Then I should see "Dossier de faisabilité" in the "region-main" "region"
    And I should see "It does not say whether your dossier is admissible"
    And I should see "Certification aimed at"
    And I should see "Objectives of the approach"
    And I should see "Planned path"
    And I should see "Not started"
    And I should see "Read from your account"
    And I should see "Last modified"

  Scenario: A section written from the list shows its version there
    Given I log in as "candidat"
    When I visit "/admin/tool/vae/faisabilite.php"
    And I follow "Write: Objectives of the approach"
    Then I should see "Objectives of the approach" in the "region-main" "region"
    And I should see "Say what you expect from this approach"
    When I set the field "Text of the section" to "I want to obtain the diploma to move up."
    And I press "Save a version"
    Then I should see "Version 1 saved."
    When I follow "History and comparison of versions"
    Then I should see "Section: Objectives of the approach"
    When I visit "/admin/tool/vae/faisabilite.php"
    Then I should see "Version 1 saved"
    And I should see "History: Objectives of the approach"

  Scenario: An experience is added, then the next slot is offered
    Given I log in as "candidat"
    When I visit "/admin/tool/vae/faisabilite.php"
    And I follow "Add: Experiences"
    And I set the field "Text of the section" to "My first experience, in a care home."
    And I press "Save a version"
    And I follow "Back to the list of sections"
    Then I should see "Experiences no. 1"
    And I should see "Version 1 saved"
    And I should see "Add: Experiences"

  Scenario: Identity and status are saved as separate fields, and the contact details are read
    Given I log in as "candidat"
    When I visit "/admin/tool/vae/section.php?section=identite"
    Then I should see "Read from your account"
    And I should see "Camille"
    When I set the field "id_nom_naissance" to "Martin"
    And I set the field "id_nationalite" to "France"
    And I set the field "id_niveau_formation" to "Level 4"
    And I press "Save a version"
    Then I should see "Version 1 saved."
    When I visit "/admin/tool/vae/section.php?section=statut"
    And I set the field "id_statut_benevole" to "1"
    And I set the field "id_convention" to "1486"
    And I press "Save a version"
    Then I should see "Version 1 saved."
    When I visit "/admin/tool/vae/section.php?section=contact"
    Then I should see "camille@example.com"
    And I should see "Change these in your profile"
    When I visit "/admin/tool/vae/faisabilite.php"
    Then I should see "Version 1 saved"

  Scenario: What the account holds is shown as text, never as markup
    Given the following "users" exist:
      | username | firstname | lastname | email               |
      | balises  | Camille   | Durand   | balises@example.com |
    # The core strips the tags from a profile field as it makes the account: the markup is written afterwards.
    And the "city" of the account "balises" holds the raw text "<i>Ville</i> & cie"
    And I log in as "balises"
    When I visit "/admin/tool/vae/section.php?section=contact"
    Then I should see "<i>Ville</i> & cie"
