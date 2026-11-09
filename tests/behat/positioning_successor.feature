@tool @tool_vae
Feature: The record that replaces another shows what the old one positioned, in reading only
  In order to find where the structure stood when a certification record is replaced, without anything being copied or carried
  As the candidate and the direction who read the dossier
  I need the positionings written on the old record shown, in reading, on the equivalent decided on the new record

  # T-90, plan 7. The lines stay on the old record. The new one shows, where an administrator decided that a competency (or a
  # bloc) has an equivalent, "positioned on the earlier record": the line that stands, never a withdrawal, one hop.

  Background:
    Given the following "users" exist:
      | username    | firstname | lastname  | email                |
      | candidat    | Camille   | Durand    | camille@example.com  |
      | responsable | Nadia     | Benali    | nadia@example.com    |
      | direction   | Dana      | Direction | dana@example.com     |
    And the following "roles" exist:
      | shortname | name                |
      | vaeiste   | Vaeiste role        |
      | follower  | Follows a candidate |
      | director  | Direction VAE       |
    And the following "permission overrides" exist:
      | capability            | permission | role     | contextlevel | reference |
      | tool/vae:declare      | Allow      | vaeiste  | System       |           |
      | tool/vae:viewown      | Allow      | vaeiste  | System       |           |
      | tool/vae:viewassigned | Allow      | follower | System       |           |
      | tool/vae:evaluate     | Allow      | follower | System       |           |
      | tool/vae:viewany      | Allow      | director | System       |           |
      | tool/vae:certify      | Allow      | director | System       |           |
    And the following "role assigns" exist:
      | user        | role     | contextlevel | reference |
      | candidat    | vaeiste  | System       |           |
      | responsable | follower | User         | candidat  |
      | direction   | director | System       |           |
    And the following "tool_vae > sources" exist:
      | fichecode | ficherang | name       | shortname |
      | RNCP100   | BC01      | Old record | Old bloc  |
      | RNCP200   | BC01      | New record | New bloc  |
    And the following "tool_vae > competencies" exist:
      | fichecode | ficherang | shortname    | bloc |
      | RNCP100   | C01       | Help to wash | BC01 |
      | RNCP200   | C01       | Wash a person | BC01 |
    And the following "tool_vae > replacements" exist:
      | fichecode | successorcode |
      | RNCP100   | RNCP200       |
    And the following "tool_vae > correspondences" exist:
      | from         | to           |
      | RNCP100-C01  | RNCP200-C01  |
      | RNCP100-BC01 | RNCP200-BC01 |
    And the following "tool_vae > declarations" exist:
      | candidate | fichecode | ficherang | situation          |
      | candidat  | RNCP100   | C01       | Night rounds       |
      | candidat  | RNCP200   | C01       | Morning rounds     |

  Scenario: The candidate and the direction read what the old record positioned, on the equivalent
    Given the following "tool_vae > evaluations" exist:
      | candidate | fichecode | ficherang | author      | status     | reason            |
      | candidat  | RNCP100   | C01       | responsable | sufficient | Mastered on site. |
    And the following "tool_vae > certifications" exist:
      | candidate | fichecode | ficherang | author    | reason                 |
      | candidat  | RNCP100   | BC01      | direction | The structure stands.  |
    When I log in as "candidat"
    And I am on the "candidat" "tool_vae > Dossier" page
    Then I should see "Positioned on the earlier record RNCP100 (C01): sufficient for certification" in the ".tool-vae-positioning" "css_element"
    And I should see "Positioned on the earlier record RNCP100 (BC01): certified by the structure" in the ".tool-vae-positioning" "css_element"
    When I log in as "direction"
    And I am on the "candidat" "tool_vae > Dossier" page
    Then I should see "Positioned on the earlier record RNCP100 (C01): sufficient for certification" in the ".tool-vae-positioning" "css_element"

  Scenario: A line withdrawn on the old record shows nothing on the new one
    Given the following "tool_vae > evaluations" exist:
      | candidate | fichecode | ficherang | author      | status     | reason            |
      | candidat  | RNCP100   | C01       | responsable | sufficient | Mastered on site. |
      | candidat  | RNCP100   | C01       | responsable | withdrawn  | Taken back.       |
    When I log in as "candidat"
    And I am on the "candidat" "tool_vae > Dossier" page
    Then I should see "Positioning of the structure" in the ".tool-vae-positioning" "css_element"
    And I should see "Wash a person" in the ".tool-vae-positioning" "css_element"
    And I should not see "Positioned on the earlier record"
