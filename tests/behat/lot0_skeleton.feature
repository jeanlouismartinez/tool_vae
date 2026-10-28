@tool @tool_vae
Feature: The plugin installs and refuses to work without the competency subsystem
  In order to build a dossier of prior learning
  As a candidate or an administrator
  I need the plugin to be reachable, and to say plainly when it cannot work

  # EVERY STRING ASSERTED HERE IS THE ENGLISH ONE, AND THAT IS NOT A PREFERENCE.
  # The acceptance test site runs in English; it always has. This file used to assert the
  # French wording of an establishment's site - "Aucune fiche de certification n'a encore été
  # importée." - which no page of the test site ever renders. All three scenarios were
  # therefore incapable of passing, and the file read as coverage while covering nothing.
  # Found on 28 August 2026, the first time Behat was actually run on this plugin.

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | candidat | Camille   | Durand   | camille@example.com  |
      | autre    | Alex      | Martin   | alex@example.com     |

  @javascript
  Scenario: The administration page is reachable and reports the empty state
    Given I log in as "admin"
    When I navigate to "Competencies > VAE - pathway" in site administration
    Then I should see "No certification record has been imported yet."

  Scenario: The plugin explains itself when competencies are switched off
    Given the following config values are set as admin:
      | enabled | 0 | core_competency |
    And I log in as "admin"
    When I am on the "tool_vae > Index" page
    Then I should see "The competency subsystem is switched off on this site."

  # LE SCÉNARIO QUI SUIT EST NÉ D'UN DÉFAUT QUE 101 TESTS UNITAIRES N'ONT PAS VU.
  #
  # declare.php mourait sur `Class "moodleform" not found` : la classe de formulaire hérite de
  # moodleform, qui vit dans lib/formslib.php et n'est PAS chargée par l'autoloader. Chaque test
  # unitaire chargeait cette bibliothèque lui-même dans son setUp() - donc aucun ne pouvait voir
  # que la page, elle, ne la chargeait pas. Trouvé le 29 août 2026 en se servant du plugin dans
  # un navigateur, pas en le testant.
  #
  # Un harnais qui charge ce que la page ne charge pas masque une erreur fatale. Seul un test qui
  # DEMANDE LA PAGE peut le voir : celui-ci.
  Scenario: The declaration form opens for a candidate
    Given the following "tool_vae > sources" exist:
      | fichecode | ficherang | shortname                                |
      | RNCP36832 | C03       | Spot the risk factors for falls          |
    And the following "permission overrides" exist:
      | capability               | permission | role | contextlevel | reference |
      | tool/vae:declare | Allow      | user | User         | candidat  |
    And I log in as "candidat"
    When I am on the "tool_vae > Declare" page
    Then I should see "Competency claimed"
    And I should see "Describe what you did, not who you did it for"

  # LE MÊME RAISONNEMENT POUR L'IMPORT, ET POUR LA MÊME RAISON.
  #
  # Le lot 6 ajoute une page HTTP et un second formulaire, donc il rouvre exactement la
  # configuration du 29 août 2026 : une classe qui hérite de moodleform, des tests unitaires qui
  # chargent formslib eux-mêmes, et personne pour demander la page. La revue adverse du
  # 29 août 2026 l'a relevé - `grep import_form tests/` ne renvoyait rien.
  #
  # CE QU'IL ATTRAPE, MESURÉ PAR MUTATION LE 30 AOÛT 2026, PAS SUPPOSÉ :
  #   - admin_externalpage_setup sur une page non déclarée  → rouge (« Section error »)
  #   - un élément de formulaire ou une chaîne de langue absents → rouge
  #
  # CE QU'IL N'ATTRAPE PAS, ET C'EST À DIRE : retirer le require_once de formslib.php dans
  # import_form.php laisse ce scénario VERT. Sur cette page-là, admin_externalpage_setup
  # construit l'arbre d'administration, qui charge les settings.php de tous les greffons, dont
  # plusieurs chargent formslib ; la classe parente arrive donc par accident. Le require_once
  # reste juste - une classe ne doit pas dépendre d'un accident - mais il est gardé par
  # declare.php, qui ne passe pas par adminlib, et par lui seul.
  # Un scénario dont on annonce plus qu'il ne prouve est exactement ce que ce projet reproche
  # aux tests qu'il a dû réécrire.
  Scenario: The import form opens and refuses a file it cannot read
    Given I log in as "admin"
    When I am on the "tool_vae > Import" page
    Then I should see "CSV file"
    And I should see "File encoding"
    And I should see "Provenance"

  # ET LA LISTE DOIT DIRE CE QUE L'IMPORT A CONSIGNÉ.
  #
  # Une compétence qui a quitté la fiche était fidèlement notée dans tool_vae_obsolete
  # et affichée nulle part : la décision 3 conserve la preuve, elle n'autorise pas à se taire.
  # Relevé en revue adverse le 29 août 2026.
  Scenario: The administration page states what an import recorded
    Given the following "tool_vae > sources" exist:
      | fichecode | ficherang | shortname                       |
      | RNCP36832 | C03       | Spot the risk factors for falls |
    And I log in as "admin"
    When I am on the "tool_vae > Index" page
    Then I should see "RNCP36832"
    And I should see "Import a CSV file (special case)"
    And I should see "Import records from France compétences"

  # THE THIRD SCENARIO WAS REMOVED, AND ITS ABSENCE IS DELIBERATE.
  #
  # It read: a candidate without an explicit grant cannot read anyone's dossier, and it was the
  # regression test for the privilege escalation found in adversarial review. Two things were
  # wrong with it, and only running Behat could show either.
  #
  # First, its step had its two arguments inverted, so the core resolved the page type as
  # "autre" and threw `Unrecognised core page type` before reaching this plugin at all.
  #
  # Second, and this one is not fixable: Behat FAILS ANY STEP THAT RENDERS A MOODLE EXCEPTION.
  # behat_hooks::look_for_exceptions() runs after every step, and a permission refusal is an
  # exception page. The plugin does refuse - verified, the run showed
  # `required_capability_exception` raised at access.php line 54 - but the refusal cannot be
  # expressed as a passing scenario. There is no such scenario anywhere in the Moodle core
  # either: grep for the refusal wording across every .feature file of 4.5 returns nothing.
  #
  # The guarantee is not lost, it lives where it can actually run: tests/access_test.php,
  # `test_a_plain_authenticated_user_holds_nothing_by_default` and
  # `test_viewown_is_not_enough_to_read_someone_elses_dossier`. A scenario that cannot pass is
  # worse than no scenario: it makes a file look like it covers something.
  #
  # REVISITED ON 12 OCTOBER 2026 (lot 8 ter). "Not fixable" was too strong: the hook only looks
  # at the page left open when a step ends. access_control.feature reads the refusal inside one
  # custom step and leaves the page before the step returns, so refusals by direct URL are now
  # scenarios - see that file. This scenario stays removed; that one replaces it.
