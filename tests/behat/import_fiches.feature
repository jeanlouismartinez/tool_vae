@tool @tool_vae
Feature: An administrator imports certification records from the export of France competences
  In order to put the records of the certifications on the site without typing nine fields for each, nor a line of command
  As an administrator who may import certification records
  I need one screen where I give the numbers, hand over the export, read what it holds for each number, and confirm

  # THE ARCHIVES ARE INVENTED and built when the step runs (tests/fixtures/export_zip_builder.php), in the shape measured on the
  # real exports of 19 and 27 October 2026. No datum of France competences is in the tree (open point PO-2).
  #
  # THE FETCH FROM data.gouv.fr IS NOT PLAYED HERE. There is no network in this run (the continuous integration already failed
  # once on "Could not resolve host"), and a door that let a test choose where to go would be a request forgery; the simulator
  # of the core serves PHPUnit (fiche_fetcher_test, fiche_import_test). This file plays the whole path of the upload, which is
  # the same path after the download, and checks that the button of the fetch is there and says what it does.
  #
  # NO JAVASCRIPT: the upload is a plain field of the page, so a scenario can attach a file to it.

  Background:
    Given I log in as "admin"

  Scenario: The home page offers the import from the export and keeps the CSV file as a special case
    When I am on the "tool_vae > Index" page
    Then I should see "Import records from France compétences"
    And I should see "Import a CSV file (special case)"
    And I should see "No certification record has been imported yet."

  Scenario: The screen says what each way does before anything is asked
    When I am on the "tool_vae > Import fiches" page
    Then I should see "Import records from France compétences"
    And "Fetch the latest export" "button" should exist
    And I should see "when you click and only then: nothing is scheduled"
    And I should see "Or upload the zip file"
    And "Read the uploaded file" "button" should exist

  Scenario: A preview writes nothing and a cancel leaves the site as it was
    Given I am on the "tool_vae > Import fiches" page
    When I set the field "Record numbers" to multiline:
      """
      RNCP99001
      RNCP99002
      """
    And I attach a synthetic France Competences export named "export-fiches-csv-2026-10-19.zip" to the field "Export zip file"
    And I press "Read the uploaded file"
    Then I should see "What the export holds"
    And I should see "Export uploaded: export-fiches-csv-2026-10-19.zip"
    And I should see "19 October 2026"
    And I should see "Read each title before you confirm."
    And I should see "RNCP99001"
    And I should see "Diplôme inventé d'accompagnement (DIA)"
    And I should see "4 bloc(s)"
    And I should see "New: it will be added."
    And I should see "1 bloc label(s) of more than 100 characters (BC03)"
    And I should see "This title cannot be the name of the framework"
    And "Import 2 record(s)" "button" should exist
    When I press "Cancel"
    Then I should see "No certification record has been imported yet."

  Scenario: The confirmation writes the records and a second pass finds nothing to change
    Given I am on the "tool_vae > Import fiches" page
    And I set the field "Record numbers" to "RNCP99001, RNCP99002"
    And I attach a synthetic France Competences export named "export-fiches-csv-2026-10-19.zip" to the field "Export zip file"
    And I press "Read the uploaded file"
    When I press "Import 2 record(s)"
    Then I should see "Result of the import"
    And I should see "RNCP99001: Imported: 4 competency(ies) added"
    And I should see "RNCP99002: Imported: 1 competency(ies) added"
    And I should see "Does one of these records replace another?"
    And I should not see "Take these numbers back, to do the operation again"
    # A reload of the address of the result shows the same result and imports nothing again.
    When I reload the page
    Then I should see "RNCP99001: Imported: 4 competency(ies) added"
    When I press "Continue"
    Then I should see "RNCP99001" in the "RNCP99001" "table_row"
    And I should see "RNCP99002" in the "RNCP99002" "table_row"
    And I should see "Diplôme inventé d'accompagnement (DIA)" in the "RNCP99001" "table_row"
    When I am on the "tool_vae > Import fiches" page
    And I set the field "Record numbers" to "RNCP99001"
    And I attach a synthetic France Competences export named "export-fiches-csv-2026-10-19.zip" to the field "Export zip file"
    And I press "Read the uploaded file"
    Then I should see "Already imported (export of"
    And I should see "Same content as in Moodle: nothing will change."

  Scenario: A record that cannot be converted is refused by its name and the others go through
    Given I am on the "tool_vae > Import fiches" page
    And I set the field "Record numbers" to multiline:
      """
      RNCP99001
      RNCP99003
      RNCP99004
      RS99005
      RNCP00000
      RNCP99006
      RNCP99007
      """
    And I attach a synthetic France Competences export named "export-fiches-csv-2026-10-19.zip" to the field "Export zip file"
    When I press "Read the uploaded file"
    Then I should see "This record is in force, but the export details no competency bloc for it"
    And I should see "This record has no competency bloc in the export: there is nothing to import. This is the case of most RNCP records that are no longer in force"
    And I should see "The code of a bloc of this record does not have the expected form: RNCP99999BC02"
    And I should see "This number is not in the export of"
    And I should see "Nothing will be written for this record."
    And I should see "Shown as inactive by France compétences in this export."
    And "Import 2 record(s)" "button" should exist
    When I press "Import 2 record(s)"
    And I press "Continue"
    Then I should see "RNCP99001" in the "RNCP99001" "table_row"
    And I should see "RNCP99006" in the "RNCP99006" "table_row"
    And "RNCP99003" "table_row" should not exist
    And "RS99005" "table_row" should not exist

  Scenario: A label with a less-than sign is imported whole, with no error from the core
    Given I am on the "tool_vae > Import fiches" page
    And I set the field "Record numbers" to "RNCP99006"
    And I attach a synthetic France Competences export named "export-fiches-csv-2026-10-19.zip" to the field "Export zip file"
    When I press "Read the uploaded file"
    Then I should see "1 bloc label(s) containing"
    And I should see "Installer un mât (<10m) dans une gaine"
    When I press "Import 1 record(s)"
    Then I should see "Result of the import"
    And I should not see "Coding error"

  Scenario: An export older than the version Moodle holds is not imported without a tick
    Given the following "tool_vae > sources" exist:
      | fichecode | ficherang | shortname    | sourceupdated |
      | RNCP99001 | BC01      | Premier bloc | 2026-11-15    |
    And I am on the "tool_vae > Import fiches" page
    And I set the field "Record numbers" to multiline:
      """
      RNCP99001
      RNCP99006
      """
    And I attach a synthetic France Competences export named "export-fiches-csv-2026-10-19.zip" to the field "Export zip file"
    When I press "Read the uploaded file"
    Then I should see "Moodle already holds the version of"
    And I should see "more recent than this export"
    And I should see "Also import the 1 record(s) that Moodle holds in a more recent version"
    And "Import 1 record(s)" "button" should exist
    When I press "Import 1 record(s)"
    Then I should see "not imported, Moodle holds a more recent version of this record than this export"
    When I press "Continue"
    Then I should see "15 November 2026" in the "RNCP99001" "table_row"

  Scenario: Ticking the box takes the record back to the version of the export
    Given the following "tool_vae > sources" exist:
      | fichecode | ficherang | shortname    | sourceupdated |
      | RNCP99001 | BC01      | Premier bloc | 2026-11-15    |
    And I am on the "tool_vae > Import fiches" page
    And I set the field "Record numbers" to multiline:
      """
      RNCP99001
      RNCP99006
      """
    And I attach a synthetic France Competences export named "export-fiches-csv-2026-10-19.zip" to the field "Export zip file"
    And I press "Read the uploaded file"
    When I set the field "Also import the 1 record(s) that Moodle holds in a more recent version (they will go back to the version of this export)" to "1"
    And I press "Import 1 record(s)"
    And I press "Continue"
    Then I should see "19 October 2026" in the "RNCP99001" "table_row"

  Scenario: What cannot be read is said in plain words and the numbers typed are kept
    Given I am on the "tool_vae > Import fiches" page
    And I set the field "Record numbers" to "RNCP99001"
    When I attach the fixture "fiche-exemple.csv" to the field "Export zip file"
    And I press "Read the uploaded file"
    Then I should see "not a readable zip file"
    And the field "Record numbers" matches value "RNCP99001"
    When I press "Read the uploaded file"
    Then I should see "Choose the zip file to read"
    When I attach a synthetic France Competences export named "export.zip" to the field "Export zip file"
    And I press "Read the uploaded file"
    Then I should see "does not say which day the export is from"
    And the field "Record numbers" matches value "RNCP99001"

  Scenario: The numbers are checked before anything is read
    Given I am on the "tool_vae > Import fiches" page
    When I press "Read the uploaded file"
    Then I should see "Enter at least one record number."
    When I set the field "Record numbers" to "RNCP1 OR 1=1"
    And I press "Read the uploaded file"
    Then I should see "is not a record number"

  Scenario: Nothing goes out without a number, whichever button is pressed
    Given I am on the "tool_vae > Import fiches" page
    When I press "Fetch the latest export"
    Then I should see "Enter at least one record number."
    And I should not see "What the export holds"

  Scenario: When no record can be imported there is no button to import, only the way out
    Given I am on the "tool_vae > Import fiches" page
    And I set the field "Record numbers" to multiline:
      """
      RNCP99003
      RNCP00000
      """
    And I attach a synthetic France Competences export named "export-fiches-csv-2026-10-19.zip" to the field "Export zip file"
    When I press "Read the uploaded file"
    Then I should see "No record can be imported. Correct the numbers, or take another export."
    And I should see "Nothing will be written for this record."
    And "Import 1 record(s)" "button" should not exist
    And "Import 2 record(s)" "button" should not exist
    And "Cancel" "button" should exist
    And I should see "Change the list of numbers"

  Scenario: Only older records are ready: the button itself says so, and the record goes back to the export
    Given the following "tool_vae > sources" exist:
      | fichecode | ficherang | shortname    | sourceupdated |
      | RNCP99001 | BC01      | Premier bloc | 2026-11-15    |
    And I am on the "tool_vae > Import fiches" page
    And I set the field "Record numbers" to "RNCP99001"
    And I attach a synthetic France Competences export named "export-fiches-csv-2026-10-19.zip" to the field "Export zip file"
    When I press "Read the uploaded file"
    Then I should see "Imported only if you ask for it, at the bottom of the page."
    And I should not see "Will be imported."
    And "Import the 1 record(s) older than those Moodle holds" "button" should exist
    When I press "Import the 1 record(s) older than those Moodle holds"
    And I press "Continue"
    Then I should see "19 October 2026" in the "RNCP99001" "table_row"

  Scenario: The list of numbers can be changed after the preview, and a result that left a record out offers to take them back
    Given the following "tool_vae > sources" exist:
      | fichecode | ficherang | shortname    | sourceupdated |
      | RNCP99001 | BC01      | Premier bloc | 2026-11-15    |
    And I am on the "tool_vae > Import fiches" page
    And I set the field "Record numbers" to multiline:
      """
      RNCP99001
      RNCP99006
      """
    And I attach a synthetic France Competences export named "export-fiches-csv-2026-10-19.zip" to the field "Export zip file"
    And I press "Read the uploaded file"
    When I follow "Change the list of numbers"
    Then the field "Record numbers" matches multiline:
      """
      RNCP99001
      RNCP99006
      """
    When I attach a synthetic France Competences export named "export-fiches-csv-2026-10-19.zip" to the field "Export zip file"
    And I press "Read the uploaded file"
    And I press "Import 1 record(s)"
    Then I should see "Result of the import"
    And I should see "RNCP99006: Imported:"
    And I should see "RNCP99001: not imported, Moodle holds a more recent version"
    When I follow "Take these numbers back, to do the operation again"
    Then the field "Record numbers" matches multiline:
      """
      RNCP99001
      RNCP99006
      """

  Scenario: The state and the end of registration are said in words
    Given I am on the "tool_vae > Import fiches" page
    And I set the field "Record numbers" to "RNCP99001"
    And I attach a synthetic France Competences export named "export-fiches-csv-2026-10-19.zip" to the field "Export zip file"
    When I press "Read the uploaded file"
    Then I should see "Status: active"
    And I should see "End of registration: 31/08/2030"

  Scenario: An account without the right to import is refused
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | nobody   | No        | Body     | nobody@example.com   |
    And I log out
    And I log in as "nobody"
    Then the "any" "Import fiches" page should be refused with "Access denied"
