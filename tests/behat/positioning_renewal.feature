@tool @tool_vae
Feature: A certification can carry a last day, and the direction sees the ones whose day has passed
  In order to keep the positionings of the structure up to date without a message, a task or a verdict on a dossier
  As the direction who certifies, and as whoever reads a dossier
  I need an optional last day on a certification, told on reading, and a list of the days that have passed

  # T-90, plan 7. A certification may say "stands until"; after that day it is "to renew", computed on the day of the reading and
  # written nowhere. Renewing is a new line. The list is for who reads every dossier AND certifies; it ranks nobody.

  Background:
    Given the following "users" exist:
      | username     | firstname | lastname  | email                |
      | candidat     | Camille   | Durand    | camille@example.com  |
      | direction    | Dana      | Direction | dana@example.com     |
      | lecteur      | Omar      | Lecture   | omar@example.com     |
      | certificateur | Rita     | Seule     | rita@example.com     |
    And the following "roles" exist:
      | shortname     | name                  |
      | vaeiste       | Vaeiste role          |
      | director      | Direction VAE         |
      | reader        | Reads every dossier   |
      | certifieronly | Certifies, reads none |
    And the following "permission overrides" exist:
      | capability       | permission | role          | contextlevel | reference |
      | tool/vae:declare | Allow      | vaeiste       | System       |           |
      | tool/vae:viewown | Allow      | vaeiste       | System       |           |
      | tool/vae:viewany | Allow      | director      | System       |           |
      | tool/vae:certify | Allow      | director      | System       |           |
      | tool/vae:viewany | Allow      | reader        | System       |           |
      | tool/vae:certify | Allow      | certifieronly | System       |           |
    And the following "role assigns" exist:
      | user          | role          | contextlevel | reference |
      | candidat      | vaeiste       | System       |           |
      | direction     | director      | System       |           |
      | lecteur       | reader        | System       |           |
      | certificateur | certifieronly | System       |           |
    And the following "tool_vae > sources" exist:
      | fichecode | ficherang | name   | shortname            |
      | RNCP100   | BC01      | Record | Accompany the person |
    And the following "tool_vae > competencies" exist:
      | fichecode | ficherang | shortname    | bloc |
      | RNCP100   | C01       | Help to wash | BC01 |
    And the following "tool_vae > declarations" exist:
      | candidate | fichecode | ficherang | situation    |
      | candidat  | RNCP100   | C01       | Night rounds |

  Scenario: The direction certifies a bloc with a last day and the line says it
    Given I log in as "direction"
    When I am on the "candidat RNCP100-BC01" "tool_vae > Certify" page
    And I set the following fields to these values:
      | Written reason                         | The structure stands behind the bloc |
      | Why the bloc is certified all the same | The competency is seen next week     |
      | Stands until (optional)                | 2099-12-31                           |
    And I press "Record"
    Then I should see "The positioning is recorded."
    And I should see "stands until 2099-12-31" in the ".tool-vae-history" "css_element"
    When I am on the "candidat" "tool_vae > Dossier" page
    Then I should see "stands until 2099-12-31" in the ".tool-vae-positioning" "css_element"
    And I should not see "To renew"

  Scenario: A last day before the day of the decision is refused and nothing is written
    Given I log in as "direction"
    When I am on the "candidat RNCP100-BC01" "tool_vae > Certify" page
    And I set the following fields to these values:
      | Written reason                         | The structure stands behind the bloc |
      | Why the bloc is certified all the same | The competency is seen next week     |
      | Stands until (optional)                | 2020-01-01                           |
    And I press "Record"
    Then I should see "The last day must be a real day written as YYYY-MM-DD"
    And I should not see "The positioning is recorded."
    And I should see "No line yet."

  Scenario: A certification whose last day has passed is told to whoever reads, and only the direction renews it
    Given the following "tool_vae > certifications" exist:
      | candidate | fichecode | ficherang | author    | decisiondate | validuntil | alertreason       |
      | candidat  | RNCP100   | BC01      | direction | 2020-01-15   | 2020-06-30 | Seen next week.   |
    When I log in as "candidat"
    And I am on the "candidat" "tool_vae > Dossier" page
    Then I should see "To renew" in the ".tool-vae-positioning" "css_element"
    And I should see "Stands until 2020-06-30" in the ".tool-vae-positioning" "css_element"
    And I should not see "Renew the certification"
    When I log in as "lecteur"
    And I am on the "candidat" "tool_vae > Dossier" page
    Then I should see "To renew" in the ".tool-vae-positioning" "css_element"
    And I should not see "Renew the certification"
    When I log in as "direction"
    And I am on the "candidat" "tool_vae > Dossier" page
    Then I should see "Renew the certification" in the ".tool-vae-positioning" "css_element"
    When I click on "Renew the certification" "link" in the ".tool-vae-positioning" "css_element"
    And I set the following fields to these values:
      | Written reason                         | The structure renews its position |
      | Why the bloc is certified all the same | Seen next week.                   |
      | Stands until (optional)                | 2099-12-31                        |
    And I press "Record"
    Then I should see "The positioning is recorded."
    When I am on the "candidat" "tool_vae > Dossier" page
    Then I should not see "To renew"
    And I should see "stands until 2099-12-31" in the ".tool-vae-positioning" "css_element"

  Scenario: The list shows the days that have passed to who reads every dossier and certifies
    Given the following "tool_vae > certifications" exist:
      | candidate | fichecode | ficherang | author    | decisiondate | validuntil | alertreason     |
      | candidat  | RNCP100   | BC01      | direction | 2020-01-15   | 2020-06-30 | Seen next week. |
    When I log in as "direction"
    And I am on the "tool_vae > Renewals" page
    Then I should see "Certifications to renew" in the "region-main" "region"
    And I should see "Camille Durand" in the ".tool-vae-renewals" "css_element"
    And I should see "RNCP100" in the ".tool-vae-renewals" "css_element"
    And I should see "2020-06-30" in the ".tool-vae-renewals" "css_element"
    And I should see "Renew the certification" in the ".tool-vae-renewals" "css_element"
    And I should see "says nothing of the quality of a dossier"
    And I should see "It is not a validation: only the jury validates"

  Scenario: The list is empty when no day has passed
    Given I log in as "direction"
    When I am on the "tool_vae > Renewals" page
    Then I should see "No certification has passed its date."

  Scenario: Who reads every dossier without certifying, or certifies without reading, is refused the list
    Given I log in as "lecteur"
    Then the "any" "Renewals" page should be refused with "Access denied"
    When I log in as "certificateur"
    Then the "any" "Renewals" page should be refused with "Access denied"
