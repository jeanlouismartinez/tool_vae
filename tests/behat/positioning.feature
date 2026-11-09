@tool @tool_vae
Feature: The manager who follows evaluates a competency, the direction certifies a bloc, the candidate reads both
  In order to say where the structure stands on a dossier without ever saying that it is validated
  As the manager who follows, the direction and the candidate
  I need positionings of the structure that are added to and never changed, and a reminder that only the jury validates

  # T-90, lot 3. An evaluation is a status and a written reason on one competency; a certification is the positioning of the
  # structure on one bloc, with a date and a reason, and an alert when a competency of the bloc is not yet sufficient.

  Background:
    Given the following "users" exist:
      | username    | firstname | lastname  | email                |
      | candidat    | Camille   | Durand    | camille@example.com  |
      | responsable | Nadia     | Benali    | nadia@example.com    |
      | observateur | Omar      | Lecture   | omar@example.com     |
      | direction   | Dana      | Direction | dana@example.com     |
    And the following "roles" exist:
      | shortname | name                |
      | vaeiste   | Vaeiste role        |
      | follower  | Follows a candidate |
      | reader    | Follows, no evaluate |
      | director  | Direction VAE       |
    And the following "permission overrides" exist:
      | capability            | permission | role     | contextlevel | reference |
      | tool/vae:declare      | Allow      | vaeiste  | System       |           |
      | tool/vae:viewown      | Allow      | vaeiste  | System       |           |
      | tool/vae:viewassigned | Allow      | follower | System       |           |
      | tool/vae:evaluate     | Allow      | follower | System       |           |
      | tool/vae:viewassigned | Allow      | reader   | System       |           |
      | tool/vae:viewany      | Allow      | director | System       |           |
      | tool/vae:certify      | Allow      | director | System       |           |
    And the following "role assigns" exist:
      | user        | role     | contextlevel | reference |
      | candidat    | vaeiste  | System       |           |
      | responsable | follower | User         | candidat  |
      | observateur | reader   | User         | candidat  |
      | direction   | director | System       |           |
    And the following "tool_vae > sources" exist:
      | fichecode | ficherang | name   | shortname            |
      | RNCP100   | BC01      | Record | Accompany the person |
    And the following "tool_vae > competencies" exist:
      | fichecode | ficherang | shortname    | bloc |
      | RNCP100   | C01       | Help to wash | BC01 |
      | RNCP100   | C02       | Help to eat  | BC01 |
    And the following "tool_vae > declarations" exist:
      | candidate | fichecode | ficherang | situation    |
      | candidat  | RNCP100   | C01       | Night rounds |

  Scenario: The manager who follows evaluates a competency and the line is kept
    Given I log in as "responsable"
    When I am on the "candidat RNCP100-C01" "tool_vae > Evaluate" page
    And I set the following fields to these values:
      | Status         | in the course of proof          |
      | Written reason | Two situations seen on the ward |
    And I press "Record"
    Then I should see "The positioning is recorded."
    And I should see "Two situations seen on the ward" in the ".tool-vae-history" "css_element"
    And I should see "in the course of proof" in the ".tool-vae-history" "css_element"
    And I should not see "validated"

  Scenario: A correction is a new line and the first stays in the history
    Given the following "tool_vae > evaluations" exist:
      | candidate | fichecode | ficherang | author      | status      | reason           |
      | candidat  | RNCP100   | C01       | responsable | toconstruct | First statement. |
    And I log in as "responsable"
    When I am on the "candidat RNCP100-C01" "tool_vae > Evaluate" page
    And I set the following fields to these values:
      | Status         | sufficient for certification |
      | Written reason | Second statement.            |
    And I press "Record"
    Then I should see "Second statement." in the ".tool-vae-history" "css_element"
    And I should see "First statement." in the ".tool-vae-history" "css_element"
    And I should see "Earlier lines"

  Scenario: A manager who follows without the right reads and cannot write
    Given the following "tool_vae > evaluations" exist:
      | candidate | fichecode | ficherang | author      | status | reason          |
      | candidat  | RNCP100   | C01       | responsable | inproof | Seen on the ward. |
    And I log in as "observateur"
    When I am on the "candidat RNCP100-C01" "tool_vae > Evaluate" page
    Then I should see "Seen on the ward."
    And I should not see "Written reason"
    And I should not see "Record" in the "#region-main" "css_element"

  Scenario: The candidate reads the evaluation and the reminder, and cannot evaluate
    Given the following "tool_vae > evaluations" exist:
      | candidate | fichecode | ficherang | author      | status      | reason            |
      | candidat  | RNCP100   | C01       | responsable | sufficient  | Mastered on site. |
    And I log in as "candidat"
    When I am on the "candidat" "tool_vae > Dossier" page
    Then I should see "Positioning of the structure"
    And I should see "It is not a validation: only the jury validates"
    And I should see "sufficient for certification"
    And I should see "Mastered on site."
    And I should not see "Evaluate" in the ".tool-vae-positioning" "css_element"

  Scenario: The direction certifies a bloc with an alert and the line is kept
    Given the following "tool_vae > evaluations" exist:
      | candidate | fichecode | ficherang | author      | status     | reason  |
      | candidat  | RNCP100   | C01       | responsable | sufficient | Mastered. |
    And I log in as "direction"
    When I am on the "candidat RNCP100-BC01" "tool_vae > Certify" page
    Then I should see "Not yet sufficient: Help to eat"
    When I set the following fields to these values:
      | Written reason | The structure stands behind the bloc |
    And I press "Record"
    Then I should see "A competency of this bloc is not yet sufficient"
    When I set the following fields to these values:
      | Written reason                         | The structure stands behind the bloc |
      | Why the bloc is certified all the same | Second competency seen next week     |
    And I press "Record"
    Then I should see "The positioning is recorded."
    And I should see "certified by the structure"
    And I should see "Second competency seen next week"

  Scenario: Nobody but the direction certifies
    Given I log in as "responsable"
    When I am on the "candidat RNCP100-BC01" "tool_vae > Certify" page
    Then I should not see "Written reason"
    And I should see "Certification of a bloc"
    And I should not see "Certify a bloc"
