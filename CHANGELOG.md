# Journal des versions

Format : [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/). Versionnage sémantique jusqu'à la 0.47.1 ; **à partir de la 01.5.3, numérotation « génération . branche de Moodle »** (voir l'entrée ci-dessous).

---

## [2.5.03 — build 2026110409] — 2026-11-09

**Publication.** Build préparé pour la publication du plugin sous l'étiquette 2.5.03 : dépôt public GitHub `jeanlouismartinez/tool_vae` et répertoire des plugins de Moodle ; le dépôt privé `moodle-tool_vae` (plateforme lavae.org) porte le même commit. **Aucun changement de code** par rapport au build 2026110408 : deux textes d'écran, deux commentaires, un essai et la documentation. Décisions du porteur du 9 novembre 2026 au soir : numéro de build neuf pour la publication ; maturité alpha gardée ; l'administrateur du site garde la possibilité de certifier ; la sauvegarde reste entière (positionnements de la structure, auto-positionnement et situations, annotations et accords compris) et l'administrateur qui la détient peut la lire.

### Corrigé
- Texte d'écran `validationdepositintro` : il dit maintenant que les responsables qui suivent le vaeiste et la direction lisent aussi la dernière version, le brouillon en cours et l'historique, que le journal reste à son auteur seul et que l'administrateur du site lit les dépôts et rien d'autre de la préparation (lecture élargie, D-1b-G ; `deposit_manager::can_read`, règle antérieure au lot). Essai `tests/validation_deposit_text_test.php` (rouge avant, vert après ; mutation « ne lit rien » prouvée rouge).
- Message `errorpiecelimit` : il parlait d'« une déclaration » alors qu'il est aussi levé sur une situation de travail ; il parle maintenant d'« une déclaration ou d'une situation de travail ».
- Deux commentaires de code en retard sur le code : `classes/local/situation_archive.php` (l'export d'un tiers ne porte `situations.json` que s'il peut lire la préparation du dossier de validation) et `db/uninstall.php` (`positionnement.json` n'est que dans la sauvegarde et l'archive de la structure ; l'auto-positionnement et les situations sont dans la sauvegarde).
- `docs/AIPD-VAE.md` : encadré de mise à jour (sauvegarde entière, l'administrateur peut certifier et lit les dépôts, risques des situations non encore analysés) et correction de la liste des sorties de `positionnement.json` (décision du 7 novembre).

### Modifié
- `version.php` : 2026110409 ; `db/upgrade.php` : une étape vide, pour qu'un site déjà en 2026110408 enregistre la version et vide ses caches (les textes d'écran corrigés sont alors lus).
- `README.md` : état au 9 novembre, sections « Me positionner » et « Situations de travail », archives du positionnement de la structure depuis le 7 novembre ; `CHANGELOG.md` : entrées des builds 2026110406 à 2026110408 écrites ou renommées.
- `tests/role_installer_test.php` : l'essai de la mise à jour lit la version attendue dans `version.php` au lieu du nombre figé 2026110408.

### Vérifié
- Le 9 novembre 2026 sur l'instance locale (PostgreSQL 17, Moodle 5.3.0) : suite PHPUnit complète **OK (2 028 essais, 15 589 assertions)** sur l'arbre de ce build avant la bosse de version (code identique), puis, après elle, mise à niveau réelle du site d'essai (étape 2026110409 jouée) et essais de la mise à jour, des rôles et des textes d'écran verts (31 essais, 411 assertions) ; suite Behat hors JavaScript **169 scénarios, 2 395 pas** ; standard de codage Moodle 0 ; `@param` de toutes les fonctions documentées ; parité des chaînes ; déclaration de confidentialité (31 tables) ; MC-2, MC-5, MC-30 ; secrets ; sept schémas ; fins de ligne. **Non vérifié en local** : MariaDB, vérificateur PHPDoc officiel, migration 4.5 → 5.3, scénario `@javascript` : ils se lisent dans la CI du commit publié (GitHub, puis Forgejo).

---

## [2.5.03 — build 2026110408] — 2026-11-09

**Situations de travail dans le livret (priorité 1, lot 1b).** Le vaeiste décrit lui-même ses situations de travail : un titre, une période, un cadre, un texte en versions (avec son brouillon en cours), des étiquettes sur les éléments de son référentiel à partir du deuxième niveau (« ce dont cette situation témoigne »), des pièces (fichier ou lien, avec la « partie concernée »). Il donne, nominativement, un accord daté et retirable à un responsable qui le suit, pour que celui-ci annote ses situations et son auto-positionnement (la lecture reste celle du dossier : les responsables qui le suivent et la direction). **Rien n'est validé, rien n'est calculé** : une annotation est la parole d'un responsable, sans valeur de validation, et chaque écran le dit ; seul le jury valide, hors de la plateforme. Conception : `docs/superpowers/specs/2026-11-08-situation-de-travail-1b-design.md` ; plan suivi : `docs/superpowers/plans/2026-11-08-1b-situation-de-travail.md` (seize tâches, chacune relue par deux relecteurs indépendants puis corrigée rouge-vert ; registre hors dépôt).

### Ajouté
- Pages `situations.php` (les situations d'une fiche, et la **vue inverse** : les situations et déclarations qui portent sur un élément), `situation.php` (une situation : détails enregistrés par leur propre bouton, texte avec ses versions et son brouillon en cours, étiquettes dans le panneau de la fiche, pièces, annotations reçues), `consent.php` (l'accord donné à un responsable, son historique). Une situation s'écrit quand la préparation du dossier de validation est ouverte (`validationopen`) et sur une fiche proposée, **sans déclaration préalable** ; fermée ou cachée, elle reste lisible. Le vaeiste seul écrit, crée, verse, supprime (la suppression retire étiquettes, liens, versions et brouillon ; la pièce reste ; les annotations deviennent « situation supprimée », toujours lisibles).
- Panneau **« Où j'en suis »** (`whereami_panel`) sur la déclaration, la préparation et la partie « lier » d'une section : pour chaque élément du référentiel, le nombre de situations et de déclarations qui le portent, avec le lien vers la vue inverse ; comptes seulement, aucun total ni pourcentage ; le compte des situations est masqué pour qui ne peut pas lire la préparation. Dans la mise en regard de l'auto-positionnement : « n déclaration(s), dont n attestée(s) » (décision 4 du lot 1a).
- Sept tables : `tool_vae_situation`, `tool_vae_sitversion` (instantané des détails à chaque version), `tool_vae_sitetag`, `tool_vae_spiece` (zone de fichiers `spiece`), `tool_vae_slink`, `tool_vae_consent` (lignes ajoutées, jamais modifiées : donné ou retiré), `tool_vae_note` (annotation ou retrait, texte simple ou Markdown de 4 000 caractères). Bornes : 30 situations par fiche, 200 versions par situation, 5 pièces actives par situation, 200 annotations par cible, 400 accords donnés par vaeiste ; **un retrait n'est jamais refusé par une borne**.
- Classes `situation_manager`, `spiece_manager` (règles de `piece_manager`, quota de 30 Mo partagé, nature « écrit professionnel »), `consent_manager`, `note_manager`, `situation_screen`, `note_screen`, `whereami_panel`, `situation_archive` ; formulaires `situation_form`, `situation_details_form`, `note_form` ; événements `situation_created`, `situation_deleted`, `spiece_added`, `spiece_read`, `spiece_removed`, `consent_changed`, `note_recorded` (identifiants seuls, jamais de texte ni de nom).
- Droit `tool/vae:annotate` (contexte utilisateur, écriture, aucun archétype) dans les quatre préréglages d'encadrement (responsable, responsable évaluateur, direction, direction certificatrice) ; annoter exige le droit, le suivi du vaeiste (`access::follows`) **et** son accord en cours, revérifiés à chaque écriture ; l'auteur seul retire son annotation.
- Messages `noteadded` (au vaeiste) et `consentgiven` (au responsable), sans texte libre, droits revérifiés au moment de l'envoi ; un retrait n'envoie rien.
- Archive : fichier `situations.json` et dossier `pieces-situations/` dans l'export du vaeiste et dans la sauvegarde, et dans l'export d'un tiers **seulement quand ce tiers peut lire la préparation du dossier de validation** (la direction et les responsables qui suivent le vaeiste, jamais l'administrateur du site ; décision du 9 novembre 2026) ; octets des pièces dans l'archive du vaeiste et la sauvegarde de l'administrateur seulement ; schéma `docs/schema/situations.schema.json` (deux exemples, soixante-dix-neuf contre-exemples) ; restauration à l'import du dossier : empreintes vérifiées, lignes déjà présentes laissées, **une annotation ou un accord restauré ne nomme jamais un compte du site et un accord restauré n'ouvre jamais l'annotation** ; un retrait restauré ne retire qu'une annotation restaurée du même auteur, active, sur la même cible ; une situation revient avec son texte même si un lien ou une étiquette ne peut pas être restauré (laissé et compté). Jamais dans `validation.json`, le PDF, les copies Word/ODT ni les dépôts.
- Confidentialité : les sept tables déclarées colonne par colonne (`privacy:metadata:situation*`, `sitversion*`, `sitetag*`, `spiece*`, `slink*`, `consent*`, `note*`), export au vaeiste (tout ce qui est à lui) et au responsable (ses annotations, les accords reçus), effacement par les trois chemins (dossier, auteur effacé, compte supprimé) des sept tables et de la zone `spiece` ; un responsable effacé laisse ses annotations sans signature et ses lignes d'accord sont supprimées ; la tâche `reconcile_orphans` rattrape un compte supprimé sans passage de l'observateur. `record_remover` compte situations, pièces et annotations comme des traces qui bloquent le retrait d'une fiche. Durée de conservation : l'existence du compte.
- Mise à jour `2026110408` (`db/upgrade.php`) : `update_capabilities()` d'abord (le cœur n'enregistre `db/access.php` qu'après la fonction de mise à jour), puis `role_installer::grant_missing()` donne `tool/vae:positionself` au rôle Vaeiste installé et `tool/vae:annotate` aux quatre rôles de préréglage installés (seulement s'ils existent, seulement au niveau système, seulement si le droit manque ; un droit posé par l'administrateur, même refusé, n'est jamais réécrit ; rien d'autre du rôle ne bouge) ; les sept tables créées si absentes.
- Lecture élargie (D-1b-G) : `validation.php`, `history.php` et `compare.php` s'ouvrent en lecture à qui peut voir le dossier (responsables qui suivent, direction) : dernière version et brouillon en cours marqué ; **l'administrateur du site reste refusé** (invariant MC-16), à l'écran comme par l'API.
- Essais : `tests/situation_*_test.php`, `consent_manager_test.php`, `note_manager_test.php`, `whereami_panel_test.php`, `spiece_manager_test.php`, `situation_archive_test.php`, `situation_privacy_test.php` ; générateurs `situations`, `consents`, `notes` ; scénarios `tests/behat/situation.feature` (six scénarios hors JavaScript).

### Modifié
- `draft_manager` : règle de lecture du type `validation` (auteur avec `draftown`, ou `access::can_view` hors administrateur ; `tool/vae:draftview` ne joue plus pour ce type), portes nommées pour la section de situation (`section()`, `save_version()`, `restore()` la refusent : elle ne s'écrit que par `situation_manager`), fiche proposée exigée pour écrire. `piece_manager` : `check_file` et `store_file` extraits, partagés avec `spiece_manager`. `exporter`, `dossier_importer`, `import_dossier.php`, `backup` (commentaire), `purge` (une seule écriture de la règle « personne effacée », `forget_person`), `classes/privacy/provider.php`, `record_remover`, `notifier`, `actors` (catalogue : `annotate`), `role_installer` (`grant_missing`), `selfpos_regard` (« dont n attestée(s) »), `selfposition.php` (annotations de nœud), `declare.php`, `validation.php`, `section.php`, `dossier.php`, `history.php`, `lib.php` (zone `spiece`), `scripts/verifier-schema.mjs`, préréglages des quatre rôles d'encadrement, chaînes fr et en (parité et ordre contrôlés).
- `version.php` : 2026110408 ; `db/install.xml`, `db/upgrade.php`, `db/access.php`, `db/events.php`, `db/messages.php`.

### Décisions prises sans le porteur, à relire
- D-1b-A à L du plan (archive propre `situations.json` ; accord à qui suit et tient `annotate` ; même mécanisme de mise à jour pour `annotate` que pour `positionself` ; les situations ne sont pas une section de la préparation ; ouverture par `validationopen` ; aucune déclaration préalable ; lecture élargie sans l'administrateur ; le lecteur voit la dernière version et le brouillon marqué ; titre, période, cadre, étiquettes enregistrés par leur bouton, la ligne porte l'état courant ; bornes ; suppression par le vaeiste seul ; identité des auteurs dans l'archive).
- Rulings du contrôleur pendant le codage : la fermeture refuse aussi les détails et la suppression ; un droit en PREVENT ou PROHIBIT n'est jamais réécrit par la mise à jour ; `may_open` d'une pièce de situation refuse l'administrateur ; l'ajout d'une pièce dépend de `piecesopen` seul ; `for_target(situation)` exige la lecture de la préparation (l'administrateur est refusé par l'API, pas seulement par les pages).
- **Relecture d'ensemble du 9 novembre (quatre lentilles indépendantes : sécurité et accès, RGPD et effacement, archive et restauration, Moodle et logique), corrigée le jour même, chaque correctif avec son essai rouge puis vert** : un retrait d'accord ou d'annotation était refusé quand la borne de stockage était atteinte (un accord pouvait devenir irrévocable après une restauration) : **la borne compte ce qui ouvre, jamais ce qui ferme** ; la borne de 30 situations était comptée sur tout le fichier d'archive et non par fiche (un dossier normal sur deux fiches n'était plus réimportable) ; une situation entière était laissée à la restauration pour un lien vers une pièce non restaurable (sauvegarde d'un non-administrateur, export d'un tiers) : elle revient, le lien est laissé et compté ; l'identifiant du responsable restait dans le journal des événements après son effacement (`consent_changed` : il est désormais `relateduserid`) ; un compte de responsable supprimé sans passage de l'observateur n'était rattrapé par rien ; les archives d'un vaeiste qui n'avait qu'un accord donné à un responsable effacé n'étaient pas refaites ; l'API écrivait encore sur une fiche cachée depuis ; quatre chaînes de confidentialité décrivaient un traitement inexact ; lien « Voir » offert à qui ne peut pas lire ; situation comptée deux fois dans les traces ; « auteur effacé » affiché pour un auteur d'archive non nommé.
- **Relevé, non corrigé, à trancher par le porteur** : (1) `situations.json` (versions, brouillon en cours, annotations, accords) va dans l'export par un tiers et dans la sauvegarde de l'administrateur, comme `autopositionnement.json` (D-1b-A), alors que l'administrateur ne peut pas lire les situations à l'écran (MC-16) : deux relecteurs indépendants y voient une contradiction ; un correctif minimal existe (conditionner `situations.json` à la règle de lecture dans l'export par un tiers) ; (2) l'administrateur lit les annotations de nœud sur la page d'auto-positionnement, et les accords ; (3) la restauration écrit une situation sur une fiche cachée ou quand la préparation est fermée ; (4) une situation supprimée peut revenir d'une archive plus ancienne si son emplacement est libre ; (5) un export peut être refusé à la réimport dans un cas rare d'entrelacement de lignes locales et restaurées d'un même identifiant ; (6) le panneau « Où j'en suis » ne porte pas la phrase « aucune valeur de validation » (il ne montre aucune annotation) ; (7) l'événement `booklet_authorization_changed` (lot antérieur) porte encore l'identifiant du responsable dans `other` ; (8) la borne anti-crue de 20 000 lignes d'annotation par fichier d'archive ; (9) le titre « Où j'en suis » à la première personne pour le lecteur (lot documentation) ; (10) en anglais, « booklet » pour « livret » et « panel » pour « jury » (usage établi du plugin).
- **Tranché par le porteur le 9 novembre 2026 (session 32, matin)** : (1) l'export fait par un tiers n'emporte `situations.json` que si ce tiers peut lire la préparation (la direction et les responsables qui suivent : oui ; l'administrateur : non) ; la sauvegarde de l'administrateur reste entière (filet de la désinstallation) : **codé le jour même** ; (2) l'administrateur garde la lecture des annotations de nœud et des accords (exception assumée) ; (3) la restauration écrit sur une fiche cachée ou une préparation fermée : **gardé** (une archive est un état passé) ; (4) une situation supprimée peut revenir d'une archive ancienne : **gardé et documenté** ; (5) le cas rare d'entrelacement : **signalé seulement** ; (6) le panneau reste sans la phrase ; (7) `booklet_authorization_changed` : **aligné le jour même** (responsable en `relateduserid`) ; (8) borne de 20 000 : **gardée** ; (9) libellés : **corrigés le jour même** (« Où il en est » pour le lecteur ; `history.php` montre le titre courant de la situation et dit « Historique de la situation ») ; (10) « booklet » et « panel » : **gardés**. Les deux règles du contrôleur (la borne compte ce qui ouvre, jamais un retrait ; une situation restaurée revient sans le lien qu'on ne peut pas restaurer) sont **confirmées**.

### À savoir
- Rien ici n'est encore commité ni publié : ce build fait partie de l'ensemble 1a, 1b, priorité 2, priorité 3, commité d'un trait à la fin sur l'accord du porteur.
- **Après la mise à jour d'un site déjà installé**, le rôle Vaeiste reçoit `tool/vae:positionself` et les quatre rôles d'encadrement `tool/vae:annotate` si leurs `shortname` sont ceux des préréglages du plugin ; un rôle créé autrement reste tel quel (la page « Rôles VAE » montre le manque).
- Vérifié le 9 novembre 2026 sur l'instance locale (`moodle53-web`, PostgreSQL 17, Moodle 5.3) : suite PHPUnit complète du plugin (2 026 essais, 15 573 assertions, 39 minutes, rejouée sur l'arbre final après les décisions du porteur), suite Behat hors JavaScript (169 scénarios, dont les six de `situation.feature`), standard de codage Moodle sans erreur ni avertissement, parité et ordre des chaînes fr/en, déclaration de confidentialité des 31 tables, schémas JSON (exemples acceptés, contre-exemples refusés), contrôles MC-5, MC-2 et secrets ; **mise à jour réelle** par `admin/cli/upgrade.php` depuis une copie de base ramenée en 2026110406 (rôles de préréglage présents, `positionself` et `annotate` absents, tables de 1a et 1b absentes) : réussie, les deux droits enregistrés, les huit tables créées, et la différence des rôles avant/après tient en exactement cinq lignes (`annotate` sur les quatre rôles d'encadrement, `positionself` sur le rôle Vaeiste), rien d'autre ; puis mise à jour du site local 2026110407 → 2026110408 ; **parcours dans le navigateur** avec deux comptes d'essai : situation créée, étiquetée sur trois niveaux, une version, un lien et un fichier PDF (envoi et téléchargement par HTTP, empreinte identique), accord donné, annotation du responsable signée et datée, lecture par lui de la préparation et des situations, panneau sur la déclaration et la préparation, vue inverse, « dont n attestée(s) ». **Non vérifié** : le scénario `@javascript` (CI seulement), la CI Forgejo, lavae.org, MariaDB, l'effet sur de vrais vaeistes ; l'ordre `update_capabilities` puis `grant_missing` n'est prouvé que par la mise à jour réelle, jamais par PHPUnit.

---

## [2.5.03 — build 2026110406] — 2026-11-07

**Livret de la structure séparé de ceux du vaeiste ; archives gardées.** Décision du porteur, 7 novembre 2026 : la parole de la structure (évaluations des compétences, certifications des blocs) ne figure jamais dans les documents du vaeiste ; elle a son propre livret.

### Modifié
- Les positionnements de la structure ne figurent plus dans l'archive, le PDF ni les copies Word et OpenDocument du vaeiste ; seule la sauvegarde, filet de sécurité d'une désinstallation, les garde. Le vaeiste les lit toujours à l'écran.

### Ajouté
- Archive de la structure, accompagnatrice, une par vaeiste (page `structure.php`), conservée puis remplacée par la suivante ; autorisation donnée une fois par responsable (page `booklet_authorize.php`, table `tool_vae_booklet_auth`) ; droit `tool/vae:issuestructure` ; événements `booklet_authorization_changed` et `structure_archive_issued` ; déclarations de confidentialité.
- Archive que le vaeiste exporte lui-même : gardée, une seule, remplacée par la suivante.
- Essais : `tests/booklet_authorization_test.php`, `structure_archive_test.php`, `booklet_privacy_test.php`, scénarios `tests/behat/structure_archive.feature`.

### À savoir
- Les builds 2026102804 à 2026110405 (pages « Mon parcours », « Vaeistes suivis » et « Organisation », grille « Acteurs et pouvoirs », positionnement de la structure, page « Rôles VAE », renouvellement des certifications) n'ont pas d'entrée détaillée dans ce journal : leur description est dans le README et dans `docs/TRAVAIL.md`.

---

## [2.5.03 — build 2026110407] — 2026-11-08

**Auto-positionnement du vaeiste par niveau (priorité 1, lot 1a).** Le vaeiste dit, lui-même, où il en est sur chaque élément de son référentiel, à tous les niveaux que le référentiel a (référentiel, domaine ou bloc, compétence, habileté…), par un palier d'une échelle en mots, avec une justification facultative. C'est sa propre parole, une trace de son développement : **ni une validation, ni une note ; rien n'en est calculé** (aucun total, taux, seuil, tri ni écart). Conception : `docs/superpowers/specs/2026-11-08-auto-positionnement-1a-design.md` ; plan suivi : `docs/superpowers/plans/2026-11-08-1a-auto-positionnement.md`. Demande n° 1 du forum francophone moodle.org.

### Ajouté
- Page `selfposition.php` « Me positionner », atteinte depuis « Mon parcours » : les fiches proposées (les mêmes qu'à la déclaration, les fiches cachées dites avec leur message), l'arbre d'une fiche à tous ses niveaux (le nom de chaque niveau vient de la taxonomie du référentiel dans le noyau, « Niveau n » à défaut ; le référentiel lui-même est le premier nœud, rang vide), puis un nœud avec son formulaire, la parole de la structure en regard (dernière évaluation ou certification, sans écart calculé), le nombre de déclarations et de pièces sur l'élément, l'historique (la ligne qui fait foi, puis les lignes antérieures, un retrait étant une ligne). Un nœud dont le code ne suit pas `<fiche>-<rang>` est affiché mais non positionnable, avec l'explication.
- Table `tool_vae_selfpos` (lignes ajoutées, jamais modifiées : identifiant d'archive, vaeiste, fiche, rang, indice du palier 1 à 4 ou 0 pour un retrait, libellé du palier figé à l'écriture, justification texte simple ou Markdown de 10 000 caractères au plus, empreinte SHA-256, date, date de restauration) ; 200 lignes au plus par élément. Classes `selfpos_tree`, `selfpos_scale`, `selfpos_manager`, `selfpos_regard`, `selfpos_screen`, `selfpos_archive`, `selfpos_successor` ; formulaire `selfpos_form` ; événement `selfpositioning_recorded`.
- Droit `tool/vae:positionself` (contexte utilisateur, écriture, aucun archétype), ajouté au préréglage du rôle Vaeiste. **Seul le vaeiste écrit, sur son propre dossier** ; les responsables qui le suivent et la direction lisent, par la règle de lecture du dossier. Un envoi identique à la dernière ligne est refusé ; un palier hors échelle, un rang inconnu, du HTML dans la justification aussi.
- Quatre réglages d'administration `selfposlabel1` à `selfposlabel4` : les libellés des paliers, modifiables par le site. Libellés par défaut : « Je découvre », « Je pratique avec aide », « J'agis seul », « Je maîtrise et je transmets ».
- Archive : fichier `autopositionnement.json` dans l'export du vaeiste (propre et par un tiers) et dans la sauvegarde, **jamais dans l'archive de la structure** ; schéma `docs/schema/autopositionnement.schema.json` (deux exemples, trente-cinq contre-exemples) ; restauration à l'import du dossier, clé par clé, empreinte vérifiée, lignes déjà présentes ou éléments inconnus laissés tels quels et comptés.
- Confidentialité : la table est déclarée (`privacy:metadata:selfpos*`), exportée et effacée avec le dossier ; la purge des rubriques orphelines et l'effacement du dossier la vident ; une ligne d'auto-positionnement est une trace qui bloque le retrait d'une fiche du référentiel (`errorremoverecordtraces`). Succession de référentiel : la dernière ligne de la fiche remplacée est lue sur le nœud équivalent, jamais copiée.
- Essais : neuf fichiers `tests/selfpos_*_test.php` (82 essais), générateur `selfpositions`, scénarios `tests/behat/selfposition.feature`.

### Modifié
- `positioning_screen` : l'écran d'évaluation montre, sous la ligne de la structure, l'auto-positionnement du vaeiste sur la même compétence, sans écart. `organisation.php` : lien « Me positionner » dans « Mon parcours ». `record_remover`, `purge`, `exporter`, `dossier_importer`, `import_dossier.php`, `classes/privacy/provider.php`, `competency_gateway` (arbre complet et taxonomies d'un référentiel), `positioning_rules`, `scripts/verifier-schema.mjs` (le nouveau schéma), préréglage `role_vaeiste.xml`, chaînes fr et en.
- `version.php` : 2026110407 ; `db/install.xml`, `db/upgrade.php`, `db/access.php`.
- `classes/local/actors.php` : le catalogue des acteurs (grille « qui peut quoi » de l'onglet Organisation, guide § 2.4) donne `positionself` au vaeiste, droit attendu ; l'essai qui exige que tout droit du plugin soit donné à un acteur l'avait relevé (seul échec de la suite complète, corrigé le 8 novembre au soir).

### Décisions prises sans le porteur, à relire
- D1 aucune déclaration préalable exigée pour se positionner ; D2 nœud à code non conforme affiché mais non positionnable ; D3 nom de niveau lu dans la taxonomie du noyau ; D4 le dossier PDF n'est pas étendu, seule l'archive JSON porte l'auto-positionnement ; D5 le fichier d'archive n'existe que s'il y a au moins une ligne ; D6 aucun message du notifier ; D7 pas de colonne d'auteur. **D8 (8 novembre, preuve en navigateur)** : les écrans lus par un responsable ou la direction parlent du vaeiste (« Ce que le vaeiste écrit ici, il l'écrit seul… ») et non au lecteur (« Ce que vous écrivez ici… »), n'invitent pas le lecteur à se positionner, et renvoient au dossier plutôt qu'à « mon parcours » ; le premier nœud de l'arbre porte le nom du référentiel et non son code deux fois.
- **Relecture indépendante du 8 novembre (six lentilles, trois contestataires par constat), corrigé le jour même, chaque correctif avec son essai rouge puis vert** : le dossier (`dossier.php`, section « Positionnement de la structure ») porte un lien vers l'auto-positionnement complet, « Lire l'auto-positionnement du vaeiste » pour un lecteur, « Me positionner » pour le vaeiste (la spec § 6.5 le demandait, aucun chemin n'existait) ; sur une fiche cachée, le texte « repris » n'est lu que d'un envoi porteur du jeton de session, comme à la déclaration (un lien forgé ne peut plus faire afficher un texte tiers comme celui du vaeiste) ; le message de fiche cachée n'est plus échappé deux fois ; un nœud désigné par son idnumber (code non conforme) ne masque plus le nœud positionnable de même rang ; la restauration d'une clé (fiche, rang) est entière ou nulle quand l'élément ne peut pas recevoir toutes ses lignes (sinon la ligne qui fait foi n'aurait pas été celle de l'archive) ; un identifiant d'archive qui n'est pas un ULID est refusé par son nom, non par une erreur de base.
- **Relevé, non corrigé, à trancher par le porteur** : (1) un vaeiste qui s'est positionné sans déclarer n'a ni export ni sauvegarde à la désinstallation (`errorexportempty` : la règle « pas de déclaration, rien à exporter » précède le lot et vaut aussi pour le journal seul) ; un essai fige ce comportement pour que tout changement se voie ; (2) sur un site déjà installé, le rôle Vaeiste existant ne reçoit pas `tool/vae:positionself` : le plugin ne modifie jamais un rôle existant (principe du projet), la page « Rôles VAE » montre le manque et l'administrateur l'ajoute à la main ; (3) la spec § 6.6 (arbre en lecture sur les écrans de déclaration et de livret, avec lien vers « Me positionner ») n'est pas codée en 1a ; (4) la mise en regard donne les comptes de déclarations et de pièces, pas l'état « attestée » de la déclaration que la spec nomme. Un constat (fins de ligne CRLF) est faux : le dépôt est en CRLF depuis l'origine, la copie jouée dans le conteneur est en LF.
- **Tranché par le porteur le 8 novembre 2026 (session 30)** : (1) la règle « pas de déclaration, rien à exporter » est **gardée** (aucun code) ; (2) la mise à jour du plugin **donne** `tool/vae:positionself` au rôle Vaeiste d'un site déjà installé (dérogation décidée au principe « ne jamais toucher un rôle existant », à coder dans le lot 1b) ; (3) la spec § 6.6 (arbre en lecture sur déclaration et livret) se code **dans le lot 1b** ; (4) la mise en regard montrera **l'état « attestée »** de la déclaration, codé dans le lot 1b.
- Le verrou de base est réentrant dans une session PHPUnit : « deux envois simultanés » n'est pas prouvable en essai unitaire (essai reformulé).

### À savoir
- Ce build est commité depuis le 9 novembre 2026 (`570f1fe`) ; la CI Forgejo du commit `2d98922`, qui contient aussi le lot 1b, est entièrement verte (MariaDB, PostgreSQL, migration, PHPDoc).
- **Après la mise à jour d'un site déjà installé** : donner le droit `tool/vae:positionself` au rôle Vaeiste (la page « Rôles VAE » montre le manque ; le plugin ne touche jamais un rôle existant). Sans ce droit, le vaeiste lit la page « Me positionner » sans formulaire. *Dépassé par le build 2026110408 : la mise à jour le donne elle-même au rôle Vaeiste installé (décision du porteur, session 30).*
- Vérifié le 8 novembre 2026 sur l'instance locale (`moodle53-web`, PostgreSQL) : suite PHPUnit complète du plugin, suite Behat hors JavaScript (163 scénarios), standard de codage Moodle, parité des chaînes, déclaration de confidentialité, schémas ; mise à jour du site par la ligne de commande et parcours complet dans le navigateur (référentiel à quatre niveaux, un nœud positionné par niveau, un retrait, l'historique, la lecture par un responsable). **Non vérifié** : le scénario `@javascript` (accessibilité, CI seulement), la CI Forgejo, lavae.org, l'effet sur de vrais vaeistes (autocensure), MariaDB.

---

## [2.5.03] — 2026-11-06

**Changement d'étiquette de ce plugin, `moodle-tool_vae`, sans aucun changement de code.** Décision du porteur, le 6 novembre 2026 : `01.5.3` est l'étiquette du plugin public `tool_vae` (première version prévue pour le répertoire des plugins de Moodle, plateforme fildargent.net). `moodle-tool_vae` (dépôt privé, plateforme lavae.org) a continué de vivre depuis le 28 octobre sans changer d'étiquette ; son contenu étant bien plus avancé, il devient la **deuxième version** du plugin VAE (« 2 »), selon la règle proposée dans l'entrée 01.5.3 ci-dessous, et la page des plugins distingue ainsi les deux.

### Modifié
- `version.php` : étiquette `release` « 2.5.03 (Moodle 5.3) » ; numéro `version` 2026110405, d'un rang au-dessus de 2026110404, pour que les sites installés enregistrent la nouvelle étiquette. Aucune table, aucun droit, aucune chaîne de langue, aucun fournisseur de messages, aucune tâche planifiée.
- `README.md` : l'en-tête dit de quel plugin il s'agit et ne le confond plus avec `tool_vae`.

### À faire encore
- Les documents du dossier `docs/` citent encore « 01.5.3 » et le build 2026110404 pour ce plugin ; ils sont à mettre à jour au prochain lot de documentation.

---

## [01.5.3] — 2026-10-28

**Renumérotation de la 0.47.1, sans aucun changement de code.** Décision du porteur : « 01 » dit que c'est la première version prévue pour le répertoire des plugins de Moodle, « 5.3 » dit que le plugin ne vaut que pour Moodle 5.3. **Règle pour les versions suivantes : proposée, pas encore décidée par le porteur** (la prochaine version pour la même branche monterait la génération, 2.5.03 ; un paquet pour une autre branche porterait la sienne, par exemple 01.5.4).

### Modifié
- `version.php` : étiquette `release` « 01.5.3 (Moodle 5.3) » ; numéro `version` 2026102802, d'un rang au-dessus de 2026102801, pour que les sites installés enregistrent la nouvelle étiquette. Aucune table, aucun droit, aucune chaîne de langue, aucun fournisseur de messages, aucune tâche planifiée.
- **Titulaire et licence, décisions du porteur du 28 octobre 2026.** Titulaire des droits : Jean-Louis Martinez, jeanlouis@martinez.pro ; je l'ai vérifié sur les en-têtes : les 261 fichiers PHP portent exactement ce nom et cette adresse, sous « GNU GPL v3 or later », comme le fichier `LICENSE`. Deux scripts de la CI (`scripts/migration/seed.php` et `verify.php`) n'avaient pas d'en-tête : ils portent maintenant ces deux lignes. Le plugin reste en GPL v3 ou ultérieure, licence de Moodle et donc compatible GPL ; le commentaire de `version.php` le dit. L'AGPL v3 a été examinée le même jour et **n'est pas retenue** (décision du porteur) : aucun en-tête ni le fichier `LICENSE` ne change.
- **PO-2 n'est pas levé par ce choix.** Le point ouvert ne porte pas sur la licence du plugin mais sur la compatibilité de la Licence Ouverte 2.0 des données de France compétences avec du code GPL. Le plugin ne livre aucune de ces données (l'administrateur les charge à l'exécution) ; la question reste à faire trancher par un conseil juridique avant toute diffusion publique, comme le README le dit déjà.
- `README.md` : relu et modifié par le porteur dans la page de lecture (en-tête sans « médico-social », mention du plugin de Jean Fruitet à l'origine du nom « Référentiel », projet limité aux versions LTS de Moodle, état raccourci, renvois vers `docs/` remplacés par deux adresses de martinez.pro) ; les paragraphes sont recollés, **plus aucune coupure de ligne au milieu d'un paragraphe** (rendu identique, vérifié). L'état et la vérification disent la 01.5.3 et la CI de la 0.47.1 (commit `db52ee5`, verte sur PostgreSQL, MariaDB et la migration) ; une note explique la numérotation.

### À savoir
- Les entrées 0.x ci-dessous et les repères `@since 0.xx` du code gardent leur numéro : ils disent quand une chose est apparue, avant la renumérotation.
- La maturité reste `MATURITY_ALPHA` : le plugin n'a pas encore été utilisé de bout en bout par un humain. C'est une décision à prendre avant le dépôt dans le répertoire des plugins.
- Vérifié le 28 octobre 2026 sur l'instance locale : la mise à jour par la ligne de commande passe, la page des plugins affiche « 01.5.3 (Moodle 5.3) » et le numéro 2026102802, et le contrôle de standard de codage de `version.php` est propre. **Pas vérifié à l'écriture** : la CI de la 01.5.3 elle-même, et la suite de tests rejouée sur ce renommage (le code n'a pas changé).

---

## [0.47.1] — 2026-10-28

**Orthographe : « vaeiste », sans accent.** Le mot se dit « vé-a-iste » (le V et le A se prononcent seuls, le E ne se prononce pas) : l'accent sur le E le faisait lire autrement. Aucune table, aucun droit, aucun événement nouveau ; le numéro de version monte (2026102801) pour que Moodle recharge les textes.

### Modifié
- Tous les textes français du plugin (`lang/fr/tool_vae.php`), le README, la FAQ, les guides d'usage (V1 et V2), l'installation pas à pas, le banc d'essai, l'AIPD, la conformité (T-84), le modèle de contrat, la lettre à l'employeur, la consigne du forum, les deux fiches de relecture et le guide d'import : « vaéiste » devient « vaeiste » (Vaeiste, vaeistes).
- Deux tests qui citent la langue (`backup_test`, `exporter_test`) suivent le nouveau mot.
- **Non touchés, par choix** : les entrées anciennes de ce journal, `docs/TRAVAIL.md`, les notes de conception T-xx, `docs/derogations/` et le commentaire d'histoire de `version.php` (ils gardent le mot de leur époque).

### À savoir
- Le plugin ne renomme pas un rôle déjà créé : sur un site où le rôle s'appelle « Vaéiste », l'administrateur le renomme à la main (*Utilisateurs → Permissions → Définition des rôles*). Le nom abrégé `vaeiste` n'a jamais eu d'accent.
- Non vérifié à ce jour : la suite complète de tests (voir le journal de la session).

---

## [0.47.0] — 2026-10-27

**Importer des fiches depuis France compétences, par un écran.** Pas de table nouvelle, pas de droit nouveau. Le numéro de version monte (2026102702) : une page nouvelle, deux événements nouveaux, des textes nouveaux. **C'est la première requête que le plugin fait de lui-même**, et elle est étroite : un clic d'administrateur, deux hôtes écrits dans le code, jamais planifiée (mesure compensatoire MC-30).

### Ajouté
- **Écran « Importer des fiches depuis France compétences »** (`import_fiches.php`) : l'administrateur tape des numéros (vingt au plus), puis dépose le zip de l'export téléchargé sur data.gouv.fr, **ou** clique « Aller chercher le dernier export » (le serveur le récupère). Aperçu sans rien importer (intitulé publié en entier, blocs, ce que Moodle détient, ce que l'import fera, export plus ancien signalé par jour), confirmation, puis import fiche par fiche par la même porte que l'écran CSV (`importer::import`). Source, licence, paternité et date sont écrites par le plugin. Un rechargement ou un second clic n'importe rien de plus ; le résultat se relit pendant deux heures.
- **Une seule porte sortante** (`fiche_fetcher`) : https, certificat vérifié, client du cœur, redirection jugée à la main et seulement vers l'adresse annoncée pour le fichier, plafonds comptés sur les octets écrits (512 Kio, 32 Mio), SHA-1 et taille contrôlés, licence du jeu de données revérifiée. `scripts/sortie-reseau.sh` (`make sortie`) et `tests/fiche_no_background_test.php` la gardent.
- Événements `export_fetched` et `record_imported` (aucune donnée de vaéiste). Lecture du zip sans extraction, sans ouvrir le gros fichier « Partenaires » ; conversion en flux (`fiche_converter`, partagé avec le script en ligne de commande).
- Refus nommés, en français et en anglais : fiche en double, plus de 500 blocs, fiche **en vigueur** sans bloc (236 fiches RNCP et 1 490 fiches RS sur l'export du 19 octobre 2026 : ce n'est pas un numéro à remplacer), et les refus du réseau avec, quand l'hébergeur peut agir, l'hôte et le code à lui donner.

### Modifié
- Bouton de la page d'accueil : le CSV à quatre colonnes devient « Importer un fichier CSV (cas particulier) » ; `import.php` est inchangé. Paternité enregistrée : « France compétences », avec l'accent.
- Documentation : README, guides, FAQ, installation, banc d'essai, AIPD, conformité (T-84), dérogation (amendement 5, MC-30).

### Corrigé (trouvé par trois relectures contradictoires du chantier)
- Un jour à un chiffre (« 3 novembre ») se comparait comme plus ancien que le « 27 octobre » (`userdate()` retire le zéro) : un export ancien paraissait récent.
- Une panne de la base arrête la boucle d'import ; un plan altéré est refusé ; le résultat est gardé au fur et à mesure ; un premier import échoué n'est plus présenté comme « déjà importé ».
- Archives hostiles : nombre d'entrées lu avant l'ouverture (ZIP64 et fausses fins comprises), plafonds sur les octets écrits.

### Tests
- PHPUnit : **1 376 tests, 10 406 assertions**, verts en local sur PostgreSQL. Behat : **105 scénarios hors JavaScript, tous verts** en local (16 pour cet écran). phpcs : 0 violation. 43 défauts volontairement introduits dans le code : tous détectés par au moins un test. Preuve sur les vrais octets de l'export du 19 octobre 2026 (lecture, conversion, aperçu sans écriture, écriture d'une fiche, nettoyage).

### Non vérifié
- **Le bouton « Aller chercher le dernier export » n'a jamais été joué jusqu'au bout contre le vrai data.gouv.fr avec ce code.** Seule la recherche du dernier export (`latest()`, deux ou trois questions JSON, lecture seule) l'a été : elle trouve l'export du 27 octobre 2026 (9 704 180 octets). Le téléchargement du fichier réel attend l'accord du porteur.
- **Aucune personne n'a cliqué l'écran.** La voie A a été jouée en HTTP réel sur l'instance d'essai par script (export réel du 19 octobre 2026, 9,2 Mo : aperçu, confirmation, résultat, rechargement ; lecture en 0,5 s), puis l'instance a été remise dans son état d'avant. L'export du 27 octobre n'est pas passé par la page.
- **La CI de Forgejo n'a pas joué cette version** (PHPDoc et le scénario JavaScript d'accessibilité ne se jouent que par elle). Rien n'est commité ni poussé.
- L'hébergement réel du porteur (sortie réseau, limite d'envoi, mémoire, délai maximal) n'est pas essayé ; la croissance de mémoire de la lecture (environ 19 Mo) est mesurée en ligne de commande, pas dans une requête web.
- Un échec d'écriture du plan sur le disque n'est pas simulé par un test (le cas « dossier des plans disparu » l'est).

## [0.46.1] — 2026-10-27

Dix décisions du porteur et les corrections de leur relecture contradictoire. Pas de table nouvelle, pas de droit nouveau, pas de fournisseur de message nouveau. Le numéro de version monte (2026102701) : une page nouvelle, un événement nouveau, des textes nouveaux ou changés ; les sites installés purgent leurs caches.

### Ajouté
- **Retirer la ligne d'une fiche importée par erreur** (`remove_record.php`, `record_remover`, événement `record_removed`). Sur la page des fiches importées, un bouton « Retirer cette ligne » est offert à qui peut importer, **seulement** si Moodle n'a plus le référentiel de la fiche (aucun référentiel ne porte plus son code) et si aucun vaéiste n'a laissé de trace sur ce code (déclaration, marque « Hors de mon activité », préparation du dossier de validation). Un écran de confirmation liste ce qui part ; la suppression se fait dans une transaction (ligne de provenance, adresses de règles, référents, correspondances, marques d'absence, lien « fiche remplaçante » des autres fiches), sans aucune écriture dans les tables des compétences. Quand le bouton manque, la page dit pourquoi (trace de vaéiste ; un référentiel de Moodle porte déjà le code ; autre cause).
- **Une phrase fixe dans chaque message** : « Ce message est automatique. Pour …, ouvrez le dossier avec le lien ci-dessous. », avec les verbes de ce que le destinataire peut vraiment faire depuis le dossier. L'expéditeur reste l'adresse « ne pas répondre » du site ; le nom du vaéiste n'est pas dans l'objet. La question de précision dit que la réponse se donne dans le dossier.
- **Messages quand l'effacement d'un responsable retire des attestations** (effacement des données, ou suppression du compte pour les attestations reprises d'une archive) : le vaéiste reçoit **un** message (type existant « Attestations retirées de votre dossier »), les autres responsables qui le suivent un message de relance (type existant « Déclarations en attente »). Mis en file juste après l'enregistrement de l'effacement et avant la régénération des sauvegardes, sans pouvoir faire échouer l'effacement, regroupés par vaéiste (envoi environ 15 minutes après le dernier effacement qui le concerne), composés à l'envoi de ce qui est encore vrai.
- **Avertissement à l'export** : une ligne hors format (rang de compétence hors format, par exemple « c7 ») ne bloque plus l'export de l'archive ni la sauvegarde d'écran ; l'archive est produite, l'écran nomme les déclarations en cause, la notice `LISEZMOI.txt` porte la mention, l'événement `dossier_exported` compte les lignes. La pièce perdue ou altérée et le dossier trop volumineux restent refusés (l'interrupteur qui commandait les trois protections est séparé).
- Étapes Behat de test (`behat_tool_vae.php`) et sept fichiers de scénarios nouveaux ou étendus : fiche cachée, retrait de ligne, export avec avertissement, écran d'export.

### Modifié
- **Fiche cachée** (« Visible : Non » dans Moodle) : le refus de déclarer et de reporter est dit **au vaéiste dans ses mots** (intitulé et code de la fiche, « cachée », s'adresser à son responsable), sans chemin d'administration ; un formulaire ouvert avant le masquage rend le texte déjà écrit et la liste recommence sur « Choisir… » ; l'adresse du report ouverte sur une fiche cachée ramène au dossier avec le message. `declare_form` écrit lui-même l'entrée « Choisir… » d'une liste groupée, que le cœur de Moodle 5.3 perd (variable mal orthographiée dans `selectgroups::export_for_template`).
- **Textes d'IA à la suppression d'un compte** : le plugin n'efface **plus rien lui-même** (la règle des deux jours disparaît) ; tout passe par la demande de suppression de données de Moodle, une fois approuvée. Le registre des appels du plugin (30 secondes, 25 par jour) reste. L'écran de confirmation dit la vérité selon l'état du site : phrase normale, ou « sur ce site Moodle ne les efface pas de lui-même » quand les demandes automatiques sont désactivées ou quand l'administrateur a défini la finalité et la catégorie du site (le cœur n'efface alors **jamais** ces textes et déclare pourtant la demande terminée) ; elle dit aussi ce que la ligne de registre de Moodle garde après l'effacement.
- **Archive d'un autre site** : le refus (reste en entier) donne le nombre de compétences numérotées autrement et nomme les dix premières (code-rang et intitulé du site, triées), puis compte les autres, et dit quoi faire ; la garde est rejouée dans la transaction d'écriture. Les promesses de reprise portent deux conditions (compétences non numérotées autrement ; compte du vaéiste sans identifiant sur le site d'accueil). L'écran d'export dit ce que l'archive ne contient pas (dépôts, avis, questions de précision et réponses : dans l'export de données personnelles de Moodle) et se lit à la troisième personne pour la direction ou l'administrateur qui exporte le dossier d'un autre. L'écran de sauvegarde porte la réserve du réimport.
- **Attestation retirée** : la phrase du dossier, du PDF et des copies Word/OpenDocument dit « Cette attestation n'est plus valable » et, seulement si aucune autre attestation n'est en vigueur, que le vaéiste peut demander à un responsable de l'attester de nouveau.
- Libellé du droit `tool/vae:import` : il dit qu'il donne aussi le retrait de la ligne d'une fiche importée par erreur.
- Documentation : README, guides, FAQ, installation, branches, conformité (T-84, AIPD, contrat modèle, dérogation), fiche de relecture de l'aide d'IA.

### Retiré
- **Paquet pour Moodle 4.5 : plus maintenu** (décision du 27 octobre 2026). Plus de promesse de correctifs jusqu'au 4 octobre 2027 ; la branche `MOODLE_405` reste consultable ; environ 28 des 55 défauts de l'audit y existent dont 5 de sécurité ; à ne pas installer sur un site réel ; passer à Moodle 5.3.

### Corrigé
- Deux scénarios Behat rouges en CI sur `b832bc3` (commit `aea6a9e`, tests seulement) : la phrase attendue de `ai.feature` et la prémisse fausse de `faisabilite.feature` (le cœur retire les balises d'un champ du profil dès la création du compte ; nouvelle étape qui écrit le texte tel quel).
- `hidden_frameworks::sent()` lit la requête sans lever d'exception sur un paramètre tableau ; `describe_warnings()` distingue valeur absente et valeur vide.

### Tests
- PHPUnit : **1 161 tests, 8 757 assertions** (986 et 7 110 à la 0.46.0), jouées en local sur PostgreSQL. Preuve rouge puis verte : sur 119 méthodes ajoutées, 108 échouent sur le code et les textes de HEAD et toutes passent sur le code final ; 6 passent aussi sur HEAD, ce sont des gardes de non-régression voulues ; 5 sont des tests à jeux de données que la mesure ne relève pas par méthode (ceux des phrases de message ont été mesurés à part : tous rouges sur HEAD).
- Behat : **89 scénarios hors JavaScript, tous verts** en local (69 à la 0.46.0). Le scénario JavaScript (accessibilité) n'est joué que par la CI.
- Non joué par la CI : cette version. La CI de `aea6a9e` (0.46.0 et son correctif de tests) est verte sur MariaDB, PostgreSQL et la migration.

### Limites connues, non corrigées
- Sur un site dont l'administrateur a défini la finalité et la catégorie du site dans le registre des données, ou qui a désactivé les demandes automatiques, Moodle n'efface pas les textes d'IA : l'écran et les documents le disent ; la variante « le plugin rappelle l'effacement du cœur dans ce seul cas » est à décider par le porteur.
- Le regroupement des messages d'effacement n'est protégé par aucun verrou : deux effacements joués en parallèle peuvent donner deux messages. Après un échec de la régénération des sauvegardes, le rejeu de l'effacement ne les refait pas toutes (défaut antérieur). Un échec de mise en file du message ne laisse qu'une trace de développeur.
- Pas de test d'atomicité ni de verrou pour le retrait d'une ligne de fiche ; les correspondances d'une compétence supprimée ne se rattachent à aucune fiche (elles sont prises par l'autre bout).
- Un identifiant « déjà attribué à autre chose » dans une archive refuse seul, avant la liste des compétences numérotées autrement.
- La chronologie du dossier donne à une attestation effacée la date de création de sa ligne, qui est la date de signature.
- Prochains chantiers décidés, non construits : un écran de correspondance validé par une personne pour importer quand le site connaît déjà la compétence sous un autre identifiant, puis une archive complète « pour soi ».

## [0.46.0] — 2026-10-26

Vocabulaire et corrections. Pas de table nouvelle, pas de droit nouveau. Le numéro de version monte pour que les sites installés purgent leur cache de chaînes.

### Modifié
- **« Candidat » devient « vaéiste »** dans tous les écrans en français (82 chaînes de `lang/fr` ; aucune clé, aucun identifiant de code, aucun nom de fichier ni
  de colonne changés ; `{$a->candidate}` et le manifeste `candidat` des archives sont inchangés ; l'anglais est inchangé), dans le README, la FAQ, les trois guides et
  les documents destinés aux utilisateurs (RGPD, AIPD, modèle de contrat, fiches de relecture, modèle de lettre, consigne de forum). Le rôle Moodle des guides
  s'appelle « Vaéiste » (nom abrégé `vaeiste`) ; les notes de conception `docs/T-*.md` et le journal `docs/TRAVAIL.md` gardent le mot de leur époque.
- **Masculin générique** dans toute la prose française (plus de « candidate », « soussigné(e) », « il/elle », « administratrice ») : c'est l'usage de la traduction
  française de Moodle, où l'écriture inclusive n'existe que comme paquetage de langue facultatif.
- **Guides** : le rôle Vaéiste est calé sur le **modèle de rôle Moodle « Étudiant »**, choisi dans le champ « Modèle de rôle » du formulaire du rôle, **sans**
  recopier les droits du modèle. Choisir « MODÈLE : Étudiant » sur le premier écran (« Utiliser rôle ou modèle de rôle ») recopie les 81 droits d'un étudiant de
  cours, dont « Voir les profils des utilisateurs » : attribués au niveau du site, ils ouvrent le profil de n'importe quel utilisateur (vérifié sur l'instance
  d'essai 5.3). Les rôles Responsable VAE et Direction VAE restent sans modèle ; les tableaux d'acteurs donnent entre parenthèses le nom du rôle Moodle.
- **Numéro de version 2026102601** (la 0.46.0 portait 2026102600) : à la mise à jour, Moodle enregistre le douzième fournisseur de message (`declarationpurged`) et joue une
  étape de migration (`db/upgrade.php`, `backup::mark_unmarked()`) qui pose sa marque sur les anciennes sauvegardes, que l'on ne cherche plus par leur dossier ni par leur nom.
  Pas de schéma.

### Corrigé
- Chaînes `messageprovider:withdrawalrequested` et `messageprovider:withdrawalrefused` (fr, en) : la page des préférences de notification affichait
  `[[messageprovider:…]]`. Un test PHPUnit et `scripts/verifier-langue.php` contrôlent désormais que chaque fournisseur de message a sa chaîne dans chaque langue.
- `section.php` : les valeurs de « Coordonnées » lues du compte (ville, pays, téléphone…) sont échappées comme tout le reste de l'écran (scénario Behat).

Audit du 26 octobre 2026 (relecture indépendante, constats vérifiés dans le code, puis corrigés) :

- **Sécurité.** Trois XSS stockés fermés : le numéro d'identification et la description d'une compétence, que le gestionnaire des référentiels de Moodle saisit librement dans `tool_lp`,
  étaient écrits sans échappement dans la liste à plat de la page de déclaration, dans la liste de « Hors de mon activité » et dans celle des référents, et dans les étiquettes de la
  page des correspondances ; ils sont échappés. **Une sauvegarde n'est plus cherchée que par sa marque** (le champ `source`), non par son dossier ni par son nom : un compte qui nommait un
  fichier de ses fichiers personnels du nom d'une sauvegarde en recevait une au rafraîchissement suivant (retrait d'une pièce, effacement d'un signataire). Elle n'est réécrite que dans les fichiers
  d'un compte qui a encore les deux droits que la page de sauvegarde demande, comme le réimport : `importdossier` et `moodle/competency:userevidencemanage` sur le vaéiste. **Le contenu d'une archive et son
  producteur ne dépendent plus de l'utilisateur de la session** : ni le journal de bord ni la préparation du dossier de validation ne sont jamais dans une sauvegarde, quelle que soit la
  personne qui la déclenche (la sauvegarde refaite quand un vaéiste retirait une pièce emportait son journal dans les fichiers de l'administrateur), et le registre, le fichier et l'événement
  sont au nom de celui qui détient la sauvegarde.
- **Compétences.** Un référentiel supprimé dans « Référentiels de compétences », ou jamais créé parce qu'un premier import avait échoué, ne fait plus planter les écrans (déclaration, rattachement à
  un cours, « Hors de mon activité », couverture, correspondances) : la fiche reste listée sur la page d'administration, avec un avertissement et sans compétence, et la réimporter reconstruit
  le référentiel ; le plugin observe cette suppression : la ligne de provenance de la fiche est gardée (licence, attribution, date de publication, remplaçante déclarée), mais elle perd son
  référentiel et ses marques d'absence. Les compétences d'une fiche sont lues sans `moodle/competency:competencyview` (le cœur le redemande à l'enregistrement d'une déclaration : si le vaéiste ne le détient pas,
  la page de déclaration le dit au lieu d'ouvrir le formulaire). **Le réimport suit le référentiel que la fiche a enregistré**, non plus le seul identifiant : un identifiant modifié dans Moodle est
  refusé en le nommant, sans rien écrire (avant, un second référentiel était créé et les déclarations déjà faites restaient sur le premier) ; un premier import ne reprend un référentiel qui
  porte déjà le code de la fiche que s'il est vide ou ne contient que des compétences `<code>-<rang>`, sinon il refuse en disant combien de compétences sont étrangères à cet outil ; les marques
  d'absence qui ne nomment plus aucune compétence sont nettoyées. Un référentiel masqué (« Visible : Non ») : la page de rattachement à un cours le dit à la place du bouton, et la page de
  déclaration ne propose plus la fiche. Le tableau des fiches importées montre l'intitulé de la certification et, à part, le répertoire (la colonne « Intitulé de la certification » affichait le répertoire).
- **RGPD calé sur Moodle.** L'aide à la reformulation affiche la **politique d'IA du cœur** (« Politique d'usage IA », rédigée une fois par le site, acceptée une fois pour tout le site, dans Moodle
  ou ici) : les cinq points propres au plugin (`aipolicyitem1` à `5`, `aipolicyheading`) sont supprimés. Le motif de retrait « Effacement demandé par le signataire » s'appelle
  **« Demande de suppression de données »** (la clé `withdrawreason:rgpd` ne change pas) : c'est aussi le motif de l'anonymisation faite à l'approbation de la demande que Moodle crée lui-même
  à la suppression d'un compte (`automaticdeletionrequests` = 1, approbation manuelle), désormais écrite dans le README, T-84, les guides et la FAQ. L'effacement immédiat des textes de l'aide
  d'IA à la suppression d'un compte ne vaut plus que pour un compte qui a appelé l'aide dans les deux derniers jours (`tool_vae_aicall`) : pour les autres, c'est la demande de suppression de
  données qui les efface, et la phrase de confirmation de l'aide le dit. L'export d'un signataire, d'un demandeur de retrait ou d'un responsable qui a posé une question ne contient plus le
  récit du vaéiste ni le texte de sa réponse ; l'export du vaéiste est complété des dates et de l'intitulé de la compétence ; le lien de sous-système `core_files` n'est déclaré qu'une fois.
  **Tous les messages partent de l'utilisateur « sans réponse » du site** : l'adresse de courriel du vaéiste ne figure plus dans les en-têtes de ceux qui vont aux responsables. **Nouveau message**
  (douzième fournisseur, `declarationpurged`) : « Une déclaration que vous aviez attestée a été supprimée », envoyé aux signataires quand le vaéiste supprime, dans l'écran « Preuves d'acquis »
  du cœur, la preuve qui portait la déclaration (le nom du vaéiste et la référence, rien du récit). Le refus d'une demande de retrait est toujours notifié au demandeur, qu'il suive ou non le
  vaéiste ; une demande de retrait adressée à un signataire dont le compte est supprimé tombe, et « Demander le retrait au signataire » n'est plus proposé quand le signataire est supprimé ou
  suspendu (le dossier renvoie à l'administrateur) ; le rappel des déclarations en attente a sa propre borne (`remindlastrun`) : une déclaration créée
  pendant l'exécution du passage précédent n'échappe plus au rappel.
- **Brouillons et versions.** Le contrôle du conflit entre onglets ne porte plus seulement sur l'enregistrement automatique : « Enregistrer une version » et « Restaurer » envoient aussi la date de
  la page chargée (`workingat`, 0 pour une page ouverte sans brouillon) et refusent d'écraser un brouillon plus récent écrit dans un autre onglet, sauf quand le texte enregistré est ce brouillon
  même ; la page se réaffiche avec le message, le texte enregistré restant dans le formulaire, et deux liens (recharger, historique). Un enregistrement automatique, ou une version, dont le texte est
  celui de la dernière version ne garde plus aucun brouillon. La ligne d'état de la préparation du dossier de validation dit « Version N, enregistrée le {date}, brouillon plus récent en cours » (la
  date est celle de la version) au lieu de coller « Brouillon en cours, aucune version enregistrée » derrière une version. Une version reprise d'une archive est au nom du vaéiste, non de
  l'administrateur qui importe.
- **Lire un dossier suppose de pouvoir le consulter.** `tool/vae:readdeposit`, `tool/vae:draftview` et `tool/vae:identityview`, détenues par un rôle du niveau du site sans `viewany` ni suivi,
  ouvraient les dépôts, les rubriques et les versions de tous les vaéistes : elles n'ouvrent plus rien sans le droit de consulter le dossier. Un identifiant qui n'est pas un compte est refusé
  comme un dossier non lisible. Écrans d'attestation et de retrait : l'avertissement d'ancrage est affiché ; la page d'une déclaration déjà attestée ne dit plus que seul l'administrateur peut
  retirer (`errorattestexists`) ; une attestation déjà retirée est dite retirée avant tout refus de droit ; la page de demande de retrait n'a plus qu'un seul refus, pour un identifiant
  inexistant comme pour un acte que la page ne connaît pas.
- **Pièces, archives, import.** Le réglage du site « Taille maximale des fichiers déposés » s'applique aussi aux pièces jointes quand il est plus bas que le plafond du plugin (la taille annoncée sur
  la page est la plus petite des deux) ; le type d'un PDF se lit par fenêtres, la mémoire utilisée ne dépend plus de la taille du fichier (avant, le fichier entier était lu en mémoire) ;
  l'import d'une archive demande plus de mémoire avant de la lire (`MEMORY_EXTRA`) et retire sa copie temporaire quelle que soit l'issue. Une déclaration refuse par son nom un rang qui n'a pas la forme
  de l'archive (`^[A-Z0-9]{1,8}$`) et un code différent de celui de la fiche, au lieu de produire une archive que l'import refuse ; l'import nomme une compétence déjà identifiée autrement sur le
  site avant d'écrire quoi que ce soit ; le message `errorarchivescope` est échappé.
- **Archives et copies.** Les rubriques du dossier de faisabilité et les intitulés des pièces, dans les exports et les copies Word/ODT, suivent les droits de lecture de l'écran (la direction qui
  n'a que `viewany` et `export` reçoit une copie sans rubriques) ; les dates des archives et des copies sont celles du fuseau du vaéiste, quel que soit le lecteur ; les titres de niveau 4 ne sont
  plus ramenés au niveau 3 et le nom de la plateforme s'écrit en texte (une esperluette ne sortait plus en `&amp;`) dans les copies Word/ODT ; le `LISEZMOI.txt` de chaque archive dit ce qu'elle ne porte
  pas (dépôts, avis, demandes de précision, versions antérieures des rubriques, décision de report ; journal et préparation hors de l'archive de leur auteur). La sauvegarde de désinstallation ne dépend
  plus des droits de lecture de la personne connectée ni du réglage des compétences : depuis la ligne de commande, où personne n'est connecté, elle porte les intitulés des compétences, y compris
  pour un vaéiste qui a posé une marque « Hors de mon activité » (avant, la désinstallation s'arrêtait sur un refus de droit).

## [0.45.0] — 2026-10-24

T-70, **lot 2** : les écrans de l'aide à la reformulation. Pas de table nouvelle. Paquet 5.3 seulement. **Toujours fermée** (`aiopen`) : à ouvrir seulement après T-84, le contrat du
fournisseur et la relecture de l'instruction et de la politique.

### Ajouté
- **Le bouton** « Proposer une autre formulation » sous le champ du texte, sur la page d'une rubrique de texte du dossier de faisabilité, de la préparation du dossier de validation et
  sur la déclaration (le champ de la situation). **Pas dans le journal réflexif, pas sur les champs de l'identité.** Il n'est dessiné que si l'aide est offerte à ce candidat
  (`ai_assistant::offered()` : ouverte, droit, un seul fournisseur) ; pour tout autre les formulaires sont comme avant. Il envoie le formulaire à `ai.php` (`formaction`), sans rien
  enregistrer et sans valider le reste du formulaire.
- **`ai.php`**, trois écrans, toujours en POST avec le jeton de session : la politique d'usage (cinq points, à accepter une fois), **la confirmation à chaque envoi** (le texte exact dans un
  champ non modifiable qui part avec le formulaire, le nom du fournisseur, le nombre de caractères, ce qui est conservé, le rappel de ne nommer personne ; aucun envoi si le texte est vide
  ou trop long), le résultat (la proposition à côté de l'original, « à relire », rien d'enregistré ; en cas d'échec, seulement les mots du plugin et le texte rendu). Le lieu d'où vient le
  texte est vérifié comme le fait la page d'écriture ; une demande forgée (journal, identité, autre source, sans jeton) est refusée.
- `ai_screens` (le HTML des trois écrans, tout échappé, la proposition d'abord), `ai_button`, `ai_assistant::offered()` et `clean()` (le texte est nettoyé de la même façon pour l'écran et pour
  l'envoi). Le fournisseur est montré sous le nom que l'administrateur lui a donné.
- Behat `ai.feature` (fermée, ouverte jusqu'à la confirmation, sans le droit, avec deux fournisseurs) avec une entité `aiproviders` du générateur ; **les scénarios s'arrêtent à la
  confirmation, aucun fournisseur n'est joint**. README, FAQ.

### Corrigé après la relecture indépendante (Opus, 4 angles, 17 constats confirmés)
- **Le fournisseur nommé est celui qui reçoit** : la confirmation porte la marque de l'instance nommée et un **jeton à usage unique** gardé dans la session (sans texte). L'envoi les rend :
  si l'administrateur a changé de fournisseur pendant que l'écran était ouvert, si la page est rechargée, si on y revient par « suivant » ou si le bouton est cliqué deux fois, **rien n'est
  envoyé** et la confirmation est montrée de nouveau. Le bouton se désactive à l'envoi.
- **Entrée enregistre toujours** : le bouton de l'aide vient APRÈS les boutons du formulaire (le navigateur prend le premier bouton pour celui par défaut) ; avant, Entrée dans le champ « note »
  ou « détail du cadre » postait vers l'aide.
- **Le texte non enregistré n'est plus perdu** : le bouton ouvre l'aide dans un **nouvel onglet** (`formtarget`) et la page de rédaction garde son texte ; la mention le dit sur chaque écran
  (avant, « Retour » rechargeait la page depuis la base).
- L'écran dit aussi ce que le fournisseur reçoit en plus du texte : un code qui désigne le candidat sans le nommer, et la consigne réglée dans l'administration de l'IA.
- Le réglage `aiopen` ne dit plus que le plugin ne peut pas effacer le texte ; README (nombre de tables exemptées) et FAQ (faute) corrigés.

### Pas fait, consigné
- Le texte de la politique et de l'instruction n'est pas relu par un accompagnateur VAE ni par la personne qui tient le registre. La section « engagement » du dossier de faisabilité (le
  texte que le candidat signe) est offerte comme les autres : **à décider avec le porteur**. Les écrans de l'aide s'ouvrent dans un nouvel onglet : le texte non enregistré reste sur la page de rédaction.

---

## [0.44.0] — 2026-10-24

T-70, **lot 1** : l'aide à la reformulation, côté serveur seulement. Conception : `docs/T-70-aide-a-la-formulation-ia.md` (validée le 23/10, points précisés le 24/10).
**Une table nouvelle** (`tool_vae_aicall`, mise à niveau 2026101524). Paquet 5.3 seulement. **Fermée par défaut (`aiopen`) et sans écran** : rien n'est proposé à un candidat
tant que le lot 2 (écrans) n'est pas fait et que T-84 ne l'a pas permis.

### Ajouté
- `ai_assistant` : à la demande d'un candidat, **une reformulation d'un texte qu'il a écrit**, jamais écrite à partir de rien, jamais évaluée, **jamais enregistrée** par le plugin.
  Toutes les gardes sont à notre charge, car le cœur (`core_ai\manager::process_action()`) n'en vérifie aucune : réglage `aiopen` fermé par défaut ; droit `tool/vae:useai`
  (aucun archétype ; **un administrateur du site passe tous les contrôles de droit, le vrai verrou est le réglage**) contrôlé dans le contexte utilisateur du candidat, **pour le
  candidat lui-même** ; un fournisseur capable de répondre ; l'acceptation de la politique d'usage par le candidat (consignée dans le registre du cœur) ; un texte non vide
  d'au plus 3 000 caractères ; **30 secondes entre deux appels** ; **25 appels par jour** (les deux derniers réglables entre des bornes du code).
- **Les plafonds sont comptés par le plugin** : le limiteur du cœur est une fenêtre fixe d'une heure dans un cache qui peut être vidé, il ne tient ni 30 secondes ni un jour. Table
  `tool_vae_aicall` (qui et quand, jamais le texte), purgée par la tâche nocturne existante (ce qui a plus de deux jours : trois jours au plus tard), effacée avec le candidat.
- L'instruction envoyée est fixe, **en français quelle que soit la langue de l'écran, et dans le code** (`ai_assistant::PROMPT`, pas dans un paquet de langue : celui qui manque ou qui remplace
  ne change pas ce qui a été relu) ; **le texte du candidat est placé entre deux marqueurs tirés au hasard à chaque appel**, traité comme une donnée.
- Événement `ai_used` sans contenu (qui, quel fournisseur, combien de caractères, réussi ou non). Un échec ne dit rien de ce que le fournisseur a répondu, et il compte.
- Vie privée : la table est déclarée, exportée (le moment seulement), effacée avec le candidat ; **lien vers le sous-système `core_ai`** : le texte envoyé et la réponse restent
  dans les tables du cœur, **rattachés au contexte du site au nom du candidat et non à son contexte utilisateur** (relecture indépendante : le cœur supprime le contexte utilisateur
  dans l'heure qui suit le compte, et son fournisseur de vie privée ne retrouve ses lignes que par le contexte : le texte serait resté sans qu'aucune demande l'atteigne). À la suppression
  du compte, l'observateur appelle l'effacement du cœur (il vide le texte et la réponse et garde la ligne du registre, comme pour toute demande).
- **Un seul fournisseur, ou rien** : le cœur essaie les fournisseurs actifs l'un après l'autre jusqu'à ce qu'un réponde ; avec deux, le texte pourrait partir chez le second sans que le
  candidat en ait été informé et le journal nommerait le premier. Avec plus d'un fournisseur actif pour le texte, rien n'est envoyé (`errorainoseveral`).
- Une exception du fournisseur (hôte injoignable, adresse bloquée, réponse illisible) n'est jamais montrée : le message peut contenir l'adresse du fournisseur. L'appel compte et
  `ai_used` est journalisé en échec.

### Corrigé
- L'import des marqueurs « Hors de mon activité » (T-42) lisait l'arbre de la fiche **une fois par marqueur** (jusqu'à 2 000) : il le lit maintenant une fois par fiche
  (`scope_manager::ranks_of()`, passé à `restore()`), même résultat.

### Pas fait, consigné
- Les adresses des règles de validation (T-41) ne sont pas montrées sur `declare.php`, et l'encadré du dossier ne l'est que si le candidat a déjà une déclaration : **décision laissée au
  porteur** (l'encadré est voulu unique, loin des chiffres, pour qu'une règle ne devienne pas une cible ; `refopen` est fermé jusqu'à la relecture de sa phrase).
- Lot 2 (écrans de confirmation et d'affichage sur les trois lieux de rédaction), lot 3 (textes de politique relus), lot 4 (Behat). **Pas vérifié** : un vrai fournisseur (aucun appel réel
  dans les tests), le texte de l'instruction et de la politique relus par un accompagnateur VAE et par la personne qui tient le registre, MariaDB (la CI seule ; PHPUnit local tourne sur PostgreSQL).

---

## [0.43.0] — 2026-10-24

T-23, **lot 3** : sur la page « Mes candidats », un référent compte les déclarations de ses blocs. Pas de table nouvelle. Paquet 5.3 seulement.

### Ajouté
- `candidates.php` : si le responsable est référent d'un bloc (ou d'une fiche entière), un choix « Compter les déclarations sur » limite les nombres (déclarations, en attente) à ce
  bloc. **La liste des candidats ne change pas**, ni ce que le responsable peut lire. Le choix est vérifié contre les lignes de référent de la personne : une valeur de l'adresse
  qui n'en fait pas partie est ignorée. Un responsable qui n'est référent de rien ne voit pas le choix.
- `candidate_overview::counts()` : le comptage sorti de la page, testable (déclarations et déclarations en attente, par candidat, limité à une fiche et aux rangs d'un bloc).
- `referent_manager::bloc_map()` (le bloc de chaque compétence d'une fiche, **une seule lecture de l'arbre** ; `bloc_of()` s'en sert), `for_user()`, `ranks_under()`.
- Behat `candidates.feature` (non joué en local : la CI seule).

### Consigné
- Le coût de `bloc_of()` à chaque déclaration (une lecture de l'arbre de la fiche par déclaration quand il y a au moins deux responsables) est **un geste humain isolé**, pas une boucle :
  non corrigé volontairement.

---

## [0.42.0] — 2026-10-23

T-23, **lots 1 et 2** : les référents des blocs. Conception : `docs/T-23-referents-par-domaine.md` (validée le 23/10). **Une table nouvelle**
(`tool_vae_referent`, mise à niveau 2026101522). Paquet 5.3 seulement.

### Ajouté
- `referent_manager` : l'administrateur (droit d'importer) nomme un référent pour un bloc d'une fiche importée ou pour toute la fiche, par identifiant ; un rang qui n'est pas un bloc,
  un compte inactif et un doublon sont refusés. Page `referents.php`, événements `referent_added` et `referent_removed`.
- **Aiguillage** : à une déclaration (et à une pièce ajoutée après une signature), `notifier` prévient les référents du bloc et de la fiche entière parmi les responsables qui
  suivent le candidat, **tous les responsables sinon**. Aucun droit ne change. Un échec de lecture des référents prévient tous les responsables, comme avant.
- Vie privée : la ligne d'un référent est déclarée, exportée pour lui, supprimée avec lui ; qui l'a nommé s'efface. Behat (`referent.feature`), README, FAQ.

### Pas fait, consigné
- Le rappel d'attente (T-22), le dépôt, l'avis et la précision ne sont pas aiguillés par bloc (ils portent sur le candidat) ; la page « Mes candidats » pour un suiveur n'est pas faite.
- **Relecture indépendante faite** (Opus, lecture seule, rien joué). **Corrigé après elle** : l'aiguillage se faisait avant le contrôle du droit d'attester, donc un référent qui suit
  le candidat sans pouvoir attester (ou dont le compte est suspendu) faisait que **personne n'était prévenu** ; il se fait maintenant parmi les responsables qui peuvent l'être (comptes actifs) ;
  la ligne d'un référent est supprimée avec son compte (`user_deleted`) ; l'export d'un administrateur dit ce qu'il a nommé. 15 tests.
- Le coût de `bloc_of()` (l'arbre de la fiche est lu à chaque déclaration quand il y a au moins deux responsables) est consigné, non corrigé.

---

## [0.41.0] — 2026-10-23

T-42, **lots 1 à 4** : « Hors de mon activité », le marqueur que le candidat pose lui-même sur une compétence ou un bloc. Conception :
`docs/T-42-non-pertinent.md` (validée le 23/10). **Une table nouvelle** (`tool_vae_scope`, mise à niveau 2026101521). Paquet 5.3 seulement. **Fermé par défaut**
(`scopeopen`).

### Ajouté
- `scope_manager` : le candidat seul dit et retire (`access::require_can_declare`) ; la compétence ou le bloc doit être dans une fiche importée ; une fois
  par compétence ; note facultative de 255 caractères, une ligne, sans caractère de format. Fermé : plus d'ajout, mais ce qui est dit reste lisible et se retire.
- Page `scope.php` (le candidat), événements `scope_marked` (création) et `scope_unmarked` (suppression), sans la note.
- Sur le dossier, **un encadré à part** « Hors de mon activité » (jamais dans la couverture) lu par le candidat, ses suiveurs et `viewany`, avec la phrase :
  le marqueur est la parole du candidat, **il n'exempte de rien**, la décision revient au jury. Rien n'est compté, rappelé ni attesté à cause d'un marqueur.
- Vie privée : déclaré, exporté, effacé avec le dossier (`purge::dossier`, balayage des orphelins).
- **Lot 3** : `hors_activite.json` (schéma `docs/schema/hors_activite.schema.json`, exemples et 18 contre-exemples, `schema_hors_activite` au manifeste),
  dans toute archive d'un candidat qui a posé un marqueur, **fonction ouverte ou non** ; le PDF et la copie Word/ODT l'écrivent avec la phrase ; un marqueur dit ou retiré
  fait une copie nouvelle ; **l'import** le rapporte pour le candidat concerné, **sans écraser** un marqueur déjà posé et en laissant de côté une compétence que ce site ne tient
  pas (aussi quand la fonction est fermée ici : c'est une parole déjà dite, la perdre serait pire).
- **Lot 4** : Behat (`scope.feature`), README, FAQ, et une relecture indépendante (Opus, lecture seule, rien joué). **Corrigé après elle** : la page ne casse plus quand le
  sous-système des compétences est coupé (les marqueurs restent lisibles et retirables) ; un séparateur de ligne ou un caractère de contrôle C1 n'entre plus dans une note ni un
  intitulé ; le code de fiche du schéma n'impose plus de forme (sinon l'archive d'une fiche au code inhabituel était refusée au réimport) ; le choix d'une compétence ne se
  découpe plus sur un tiret (un code de fiche peut en porter) ; l'import rattrape une insertion simultanée. 17 + 11 tests.

### Pas fait, consigné
- Le coût de l'import (une lecture de la fiche par entrée, bornée à 2 000 entrées) ; les dates d'une archive forgée ne sont pas bornées ; les libellés du choix de compétence ne sont
  pas échappés par le formulaire, comme dans `declare_form` (source : fiches importées par l'administrateur) ; un marqueur importé ne se distingue pas de celui que le candidat a posé ici.
- **À faire par le porteur avant d'ouvrir `scopeopen`** : faire relire par un accompagnateur VAE le libellé et la phrase.

---

## [0.40.0] — 2026-10-22

T-41, **lots 1 à 3 sur 4** : les adresses où le certificateur publie sa règle de validation. Conception :
`docs/T-41-regle-du-certificateur.md` § 8 (le lien, pas le texte). **Une table nouvelle** (`tool_vae_ref`, mise à niveau 2026101520), sans
donnée personnelle (exemptée de la vie privée, avec son marqueur). Paquet 5.3 seulement. **Fermé par défaut** (`refopen`).

### Ajouté
- `ref_manager` : donner et retirer une adresse par fiche (droit d'importer), intitulé court obligatoire (60 caractères, une ligne), jour de
  vérification facultatif et jamais à venir, 5 adresses au plus par fiche, aucune deux fois ; mêmes règles d'adresse que les liens de pièces
  (http et https seulement, pas d'identifiants, avertissement pour une requête ou un nom privé).
- Page d'administration `refs.php` (Compétences, « Règles de validation : adresses »), événement `ref_changed` (code de la fiche et action,
  ni l'adresse ni l'intitulé).
- Sur le dossier du candidat, sous la provenance de chaque fiche : un encadré « Règle de validation : voir… » avec les liens (nouvel onglet,
  `noopener noreferrer`, adresse entière au survol) et la phrase qui dit que le plugin n'applique pas la règle et ne vérifie pas les adresses. Rien
  n'est cité, rien n'est mis à côté des nombres, aucune couleur. Montré **seulement si `refopen` est coché** ; les adresses peuvent être saisies avant.
- **Lot 3** : `referentiel.json` porte, par fiche, `regle_validation` (intitulé, adresse, jour de vérification ou null) **seulement si `refopen` est coché** ; le PDF et la copie Word/ODT écrivent les adresses en texte (jamais un lien actif) avec la même phrase, une fois par fiche ; une adresse nouvelle fait une copie nouvelle (le condensé change). **Pas d'import** : `referentiel.json` n'est pas relu, les adresses sont celles du site. Aucun changement de schéma de base de données ni de `version_format`.
- **Lot 4** : scénarios Behat (`tests/behat/ref.feature`), README, FAQ. **Relecture indépendante faite** (Opus, lecture seule, rien joué) ; corrigé :
  le jour de vérification est celui que l'administrateur a choisi, gardé à midi UTC et montré en UTC (il pouvait se lire un jour trop tôt ou trop
  tard hors de son fuseau) ; le compte, le doublon et l'ajout sous un verrou, et l'index unique rattrapé (deux envois à la fois) ; message propre pour
  une fiche inconnue ; un caractère de format (inversion de sens d'écriture) refusé dans l'intitulé ; l'hôte en punycode dans l'infobulle.
- **Encadré unique** (décision du 22/10, après la relecture) : un seul encadré par dossier, **au-dessus** de la section de la couverture, et plus sous le titre de chaque fiche ; l'hôte de l'adresse est mis en minuscules (le chemin non) ; l'ajout et le retrait sont deux événements (`ref_added`, création ; `ref_removed`, suppression).
- 33 tests.

### Pas fait, consigné
- L'encadré n'apparaît que si le candidat a au moins une déclaration (la couverture n'est montrée qu'alors). Le chemin d'une adresse reste sensible à la casse sur PostgreSQL et insensible sur MySQL (comparaison de la base).
- La page de déclaration (`declare.php`) ne montre pas encore les adresses : elle n'a pas d'en-tête par fiche.
- **À faire par le porteur avant d'ouvrir `refopen`** : faire relire par un accompagnateur VAE la phrase d'encadrement et sa place.
- Les adresses réelles (fiche du répertoire, arrêtés) sont à saisir et à ouvrir à la main ; le modèle de l'adresse RNCP n'est pas vérifié.

---

## [0.39.0] — 2026-10-22

T-13, **lot 3 sur 4** : les pièces justificatives dans l'archive, la sauvegarde, l'import, le PDF et la copie modifiable. Conception :
`docs/T-13-pieces-jointes.md` § 4.8 et 4.9 ; amendement 4 du DER (MC-28). **Aucun changement de schéma de base de données.**
`version_format` reste « 1 » (fichier facultatif). Paquet 5.3 seulement. Toujours **fermé par défaut** (`piecesopen`).

### Ajouté

- **`pieces.json`**, fichier facultatif de l'archive, avec son schéma (`docs/schema/pieces.schema.json`, `0.1.0`, 2 exemples, 35
  contre-exemples, contrôle `ajv`) et `schema_pieces` au manifeste : une entrée par pièce, retirées comprises (identifiant, déclaration,
  fichier ou lien, nature, intitulé s'il y en a un, dates, type, taille et empreinte SHA-256 d'un fichier, la signature en vigueur qui
  la couvrait). `officiel` toujours `false`, une phrase dit que la plateforme ne peut pas vérifier qu'une pièce ne nomme personne. **Le nom
  d'origine d'un fichier n'y est nulle part** : aucune propriété ne lui est destinée.
- **Qui emporte quoi (MC-28)** : **les octets** (`pieces/<identifiant>.<extension>`) **et les adresses** des liens entrent dans **l'archive
  que le candidat exporte lui-même** et dans **la sauvegarde conservée sur la plateforme** (le filet de la désinstallation) ; **l'export fait
  par un tiers** liste les pièces (nature, type, taille, empreinte, dates) **sans octets ni adresses**. Chaque fichier est relu et comparé à
  son empreinte au moment de l'export : un fichier absent ou qui n'est plus celui qui a été reçu **refuse l'archive par le nom de la
  pièce** (`errorpiecealtered`).
- **L'import** relit `pieces.json` : le schéma, puis un identifiant que le plugin peut stocker (un ULID) et que personne n'a déjà, une
  pièce nomme une déclaration de la même archive, un fichier est un fichier et un lien un lien, la signature qui « couvrait » une pièce est une que la déclaration porte
  en vigueur ; chaque fichier repasse par **la porte d'une personne** (empreinte, taille, **type lu sur le contenu et le même que
  l'archive déclare**, macros, antivirus) ; un fichier de `pieces/` que nulle pièce ne nomme, ou un dossier sans `pieces.json`, refuse
  l'archive. **Refus de toute l'archive, par le nom de la pièce** (`errorarchivepieces`). **Quand la jonction de pièces est fermée
  ici et que l'archive a des pièces à restaurer, elle est refusée** (`errorarchivepiecesclosed`, avec le chemin : ouvrir, importer de
  nouveau, refermer) : une déclaration ne s'importe pas deux fois, des pièces laissées en silence seraient perdues pour toujours. Ouverte,
  tout ce qui a de quoi être restauré l'est (un fichier avec ses octets, un lien avec son adresse, la trace d'une pièce retirée) ; ce qui
  vient sans octets ni adresse (l'export d'un tiers) est compté et laissé. La liste de ce que la signature couvrait est restaurée avec elle.
- **Le PDF et la copie Word/ODT listent les pièces** sous chaque déclaration (nature, intitulé, type, taille, empreinte ou « lien, sans
  empreinte », date, « retirée le ») et **n'incorporent jamais un fichier ni n'écrivent une adresse**. La copie hebdomadaire change quand
  une pièce est ajoutée ou retirée (`LAYOUT` 5).
- **Le LISEZMOI de l'archive** dit ce que contient `pieces.json`, que l'archive du candidat et la sauvegarde portent aussi les fichiers,
  les adresses et les intitulés et qu'un export fait par une autre personne n'en porte aucun, et qu'**un plugin antérieur à la 0.39.0
  réimporte les déclarations et perd les pièces sans le dire** (les versions 0.37 et 0.38 ignorent `pieces.json` comme un fichier listé
  qu'elles ne connaissent pas).

### À savoir

- L'archive est construite en mémoire, et **les pièces sont comptées dans les 50 Mio** qu'elle tolère (au plus 30 Mo de pièces par candidat,
  borné par le code) ; une archive trop grande est refusée à l'export, comme avant (`errorexporttoolarge`). À l'import, un PDF de 30 Mo
  est copié deux fois de plus pour être examiné. **Pas éprouvé** : la mémoire pour une archive de 30 Mo de pièces sur un serveur à 128 Mo.
- **La case « pièces lues » ne voyage pas** (`piecesread` n'est pas dans `pieces.json`) : une attestation importée a la liste de ce
  qu'elle couvrait, pas la déclaration de prise de connaissance.
- **Reste** (lot 4) : relecture indépendante finale ; amendement 4 à valider. Pas de Behat de l'export et de l'import : un zip ne
  s'inspecte pas par un navigateur, PHPUnit en joue les cas.

### Corrigé après relecture indépendante (même version, avant la CI)

- **Faille corrigée à l'import** : le message d'une archive refusée reprenait tel quel le nom d'une propriété de l'archive, et la page
  d'import l'affiche comme du HTML (un nom de propriété `<img onerror=…>` s'exécutait chez l'administrateur). Les messages du schéma sont
  échappés (pièces, et aussi, depuis un lot plus ancien, la préparation du dossier de validation et le journal).
- **Une adresse acceptée au dépôt doit pouvoir être réimportée** : le schéma de l'archive est sensible à la casse (`https://`), le dépôt
  ne l'était pas (« Https:// » tapé sur un téléphone, ou une espace insécable collée, refusaient toute l'archive du candidat au réimport,
  sauvegarde comprise). Le dépôt refuse maintenant toute espace (insécable comprise), met le schéma en minuscules, et le schéma
  accepte les deux casses pour les lignes déjà écrites.
- **L'import passe la porte d'une personne pour tout** : l'adresse (celle que `validate_url()` rend), l'intitulé (celui que
  `clean_title()` rend), les bornes que le code tient (5 pièces actives, 20 lignes par déclaration, 30 Mo par candidat, cumul avec
  l'existant compris), des dates qui s'accordent avec la signature dite couvrir la pièce (une pièce ajoutée après la signature ne peut
  pas être dite couverte), un identifiant ULID.
- **Fermé, un import n'est plus bloqué par l'antivirus** (la porte n'est pas demandée quand rien ne sera écrit) **et ne perd plus rien en
  silence** (voir plus haut).
- **Un échec au milieu de l'import ou du dépôt ne laisse plus de contenu sur le disque** (le contenu écrit est retiré après le retour
  arrière, si plus rien n'y renvoie).
- **Un fichier perdu n'arrête plus** le retrait d'une pièce, l'effacement d'un signataire ni la désinstallation : la sauvegarde et le
  rafraîchissement sont faits sans lui, la pièce listée sans son chemin ; seul l'export promis au candidat refuse, par le nom de la pièce.
- **L'intitulé** (champ libre) **ne sort plus dans l'export d'un tiers** ; il reste dans l'archive du candidat, la sauvegarde d'un
  administrateur, et, dans la copie Word/ODT, pour qui lit aussi l'identité. **La sauvegarde ne porte les octets que si elle est faite
  pour un administrateur du site.** L'événement d'export dit si les octets sont sortis. L'importateur n'est plus inscrit comme auteur de
  l'ajout ou du retrait d'une pièce.

## [0.38.0] — 2026-10-22

T-13, **lot 2 sur 4** : le responsable lit les pièces avant d'attester, et l'attestation enregistre ce qu'elle couvre. Conception :
`docs/T-13-pieces-jointes.md` § 4.5 et 4.6 ; amendement 4 du DER (MC-25, MC-26). **Deux tables et une colonne nouvelles** (étape de
mise à niveau), 5.3 seulement. **Toujours fermé par défaut** (`piecesopen`), et **à ne pas ouvrir avant ce lot** (c'est dit dans la
description du réglage).

### Ajouté

- **L'écran d'attestation liste les pièces** de la déclaration (nature, intitulé, type, taille, empreinte, lien ou fichier à ouvrir,
  si le lecteur peut l'ouvrir) au-dessus du formulaire. Le formulaire **porte la liste affichée** (identifiant et empreinte de chaque
  pièce) et une **case** « J'ai pris connaissance des pièces justificatives… », obligatoire quand il y a des pièces, côté serveur aussi.
- **`attest()` compare la liste vue à la liste qu'il y a, sous verrou, et refuse si elle a changé** (pièce ajoutée ou retirée entre
  l'ouverture de l'écran et la signature, ou une signature qui croyait n'en voir aucune) : le responsable ne signe jamais ce qu'il n'a
  pas vu ; l'écran est redessiné avec la liste d'aujourd'hui. **Le verrou des pièces du candidat est partagé** par l'ajout, le retrait
  et l'attestation.
- **La liste signée est gardée avec la signature** (`tool_vae_attest_piece` : la pièce et l'empreinte du fichier au moment de la
  signature) et **la case aussi** (`tool_vae_attest.piecesread`, une DÉCLARATION, pas un fait). « Ajoutée après l'attestation » n'est
  plus une comparaison de dates : c'est « pas dans la liste de la signature en vigueur », et on dit laquelle, avec sa date. Une pièce
  retirée depuis est dite couverte puis retirée.
- **L'ouverture d'un fichier est enregistrée** (`tool_vae_piece_open`) pour qui n'est pas le candidat, et l'écran dit « n fichiers sur m
  que vous pouvez ouvrir ont été ouverts par vous. Ouvrir n'est pas lire. ». Un lien n'est pas compté : le serveur ne sait pas qu'on le
  clique.
- **Un message aux responsables qui suivent** (fournisseur `pieceafterattest`, les mêmes personnes que celles qui peuvent attester) quand
  une pièce est ajoutée à une déclaration déjà signée : le nom du candidat et la référence de la déclaration, **rien de la pièce**. Relu à
  l'envoi (la pièce est toujours là, la signature en vigueur ne la couvre pas).
- **La page des pièces est aussi la lecture** : qui peut lire le dossier y voit la liste (un fichier ou un lien s'ouvre seulement si le
  droit d'ouvrir est là : celui d'attester, ou l'administrateur) ; seul le candidat y ajoute et y retire. Le dossier montre le lien aux
  lecteurs dès qu'une pièce est là. **Un seul rendu** de la liste pour le candidat, le responsable et le lecteur (`presenter::pieces()`).
- **Vie privée** : la liste signée et la case sont **vidées avec la signature** (retrait, effacement, anonymisation) ; l'ouverture d'un
  fichier est **effacée avec la personne** ; la purge d'un dossier prend tout. Déclarations de la Privacy API à jour.

### Corrigé après relecture indépendante (même version, avant la CI)

- **L'effacement d'un signataire passait à côté du lot 2** : `strip_attestant` (la porte du candidat) laissait la case et la liste ; il les
  vide maintenant, avec **ce que la personne a ouvert** dans ce dossier. Un responsable qui a seulement ouvert une pièce, sans rien
  signer, est maintenant **trouvé** par la Privacy API (contextes et utilisateurs) et effacé ; un compte supprimé perd aussi ce qu'il a
  ouvert. L'**export** donne au signataire ses ouvertures, au candidat la case et la liste de chaque signature.
- **L'intitulé d'une pièce** (champ libre) n'est plus montré à un lecteur qui n'a pas le droit de l'ouvrir.
- **La liste affichée et le jeton signé viennent d'une seule lecture** ; un verrou occupé est dit au signataire dans ses mots.
- **Le retrait ne tient plus le verrou pendant la reconstruction** de l'archive, de la sauvegarde et des copies ; l'ouverture de deux
  téléchargements simultanés ne lève plus d'exception ; `withdraw()` retire la signature et sa liste dans une transaction ; le balayage
  des orphelins prend aussi la liste et les ouvertures.
- **Le dossier dit combien de pièces la signature ne couvre pas** (« n ajoutée(s) après la signature »), à côté du lien : le message peut
  être désactivé, l'écran de travail le dit quand même.

### À savoir

- **Une signature donnée avant 0.38.0 n'a pas de liste** : si `piecesopen` avait été ouvert en 0.37, une pièce jointe avant cette signature
  serait montrée comme ajoutée après (dit dans la description du réglage ; fermé par défaut, personne ne l'a fait).
- Un message par pièce et par responsable qui suit, sans regroupement ; le signataire qui ne suit pas le candidat (direction avec
  `viewany` et `attest`) n'est pas prévenu : conforme à la conception, signalé par le relecteur.
- Qui peut attester peut ouvrir (la règle d'ouverture contient celle de l'attestation) : la règle « qui ne peut pas ouvrir ne peut pas
  attester une déclaration qui a des pièces » n'a pas de cas à refuser.
- **Pas vérifié** : l'exclusion de deux signatures simultanées sur deux processus (un verrou de base est ré-entrant dans un même
  processus : le test unitaire ne peut pas l'éprouver) ; Behat (la CI seule) ; l'e-mail réellement reçu.
- **Reste** (lot 3) : `pieces.json`, les octets dans l'archive du candidat et la sauvegarde, l'import, le PDF et la copie Word/ODT.

## [0.37.0] — 2026-10-21

T-13, **lot 1 sur 4** : les pièces justificatives d'une déclaration, côté candidat. Conception : `docs/T-13-pieces-jointes.md`
(version 2, validée le 21/10 avec les neuf recommandations) ; **amendement 4 du DER-referentiel-001** (R2 révisé, R8, MC-24 à MC-29).
**Une table nouvelle** (`tool_vae_piece`, étape de mise à niveau), 5.3 seulement. **Fermé par défaut** (réglage `piecesopen`).

### Ajouté

- **Un fichier ou un lien** joint à une déclaration, avec une **nature en liste fermée** (bulletin de paie, contrat, attestation de
  l'employeur, diplôme, fiche de poste, autre) et un intitulé facultatif (60 caractères). Page `piece.php`, lien sous chaque
  déclaration du dossier du candidat.
- **Fichiers** : PDF, ODT, DOCX, JPEG, PNG, **type lu sur le contenu**. **Le nom d'origine n'est jamais gardé** : le fichier est
  enregistré, servi et (plus tard) archivé sous son identifiant. Macros, scripts et relations vers l'extérieur refusés dans DOCX et
  ODT, scripts visibles refusés dans un PDF (**contrôle partiel** : ce qui est dans un objet compressé n'est pas vu). **Empreinte
  SHA-256** calculée à la réception et recomparée au fichier enregistré. **Antivirus du cœur** appelé par le plugin ; **sans antivirus
  configuré, les fichiers sont refusés** sauf acceptation écrite de l'administrateur (`piecenoantivirus`) ; les liens n'en ont pas
  besoin.
- **Plafonds** : 5 Mo par fichier, 5 pièces actives (20 lignes en tout, retraits compris) par déclaration, **30 Mo par candidat**
  (borné par le code : l'archive tient 50 Mio en tout). Réglables sous ces bornes.
- **Liens** : `http` et `https`, jamais visités par le serveur ; avertissement quand l'adresse porte une requête ou un fragment, ou
  désigne une adresse IP ou un nom qui semble privé. Aucune empreinte (ce qui est à une adresse peut changer).
- **Retrait** toujours possible, ouvert ou fermé, par le candidat : le fichier, l'intitulé et l'adresse sont **effacés** ; la nature,
  le type, la taille, l'empreinte et les dates restent. Archive en cours, sauvegarde et copies Word/ODT rafraîchies.
- **Droit d'ouvrir** : le candidat, qui peut attester ce candidat (`access::can_attest()`), l'administrateur du site ; téléchargement
  forcé, accès décidé depuis la ligne de la pièce, consigné (`piece_read`). Aucun intitulé, nom ou adresse dans un événement.
- **Vie privée** : table et fichiers déclarés, export du cœur (fichiers compris, pour le candidat seul), effacement et purge d'un
  dossier (`purge::remove()`). Événements `piece_added`, `piece_removed`, `piece_read`.
- Réglages `piecesopen` (fermé), `piecemaxbytes`, `piecemaxpercandidate`, `piecenoantivirus`.

### Corrigé après relecture indépendante (même version, avant la CI)

- **Contrôle des documents** : les relations d'un DOCX sont lues **comme du XML** (guillemets, espaces, préfixes, casse du nom de
  la partie : plus de contournement), jamais tronquées (une partie trop grande refuse le document) ; objets incorporés, ActiveX,
  champs `INCLUDEPICTURE`, `INCLUDETEXT`, `DDE` refusés ; ODT : image ou objet à une adresse extérieure, événement lié à un script
  de l'application, objet incorporé refusés ; PDF : noms d'action après défaire les échappements `#xx`, `/GoToR`, `/SubmitForm`,
  `/ImportData`, `/RichMedia` en plus. **Reste une liste d'interdits, pas une garantie** (MC-24 le dit).
- **Un verrou par candidat** autour du comptage et de l'écriture : deux envois simultanés ne dépassent plus les plafonds.
- **L'antivirus ne reçoit plus le nom d'origine** (il l'écrit dans le journal et dans les messages aux administrateurs).
- Aperçu du cœur (`?preview=`) retiré du service d'une pièce (il coupait le téléchargement forcé) ; retrait **en POST** seulement,
  déclaration vérifiée **avant** le retrait ; nettoyage d'un échec limité à la ligne en cause ; nature **sans valeur par défaut** ;
  plafonds de réglage **validés** à l'écran ; limite d'envoi de PHP lue et dite ; POST vide (fichier plus grand que `post_max_size`)
  signalé ; adresses `127.1`, `0x7f.0.0.1`, entier seul, point final avertis ; nom d'hôte affiché en punycode ; HEIC nommé dans le
  refus ; balayage nocturne des pièces et fichiers orphelins ; métadonnées de vie privée complétées ; `piecesopen` ne doit pas être
  ouvert avant le lot 2 (dit dans la description du réglage).

### À savoir

- **Le champ de fichier est un champ HTML ordinaire**, lu dans `$_FILES` avant la construction des formulaires : l'élément `file` de
  la bibliothèque de formulaires est **une erreur fatale** sur ce cœur (`HTML_QuickForm_file::_findValue()` incompatible sous PHP
  8.3) et elle vide `$_FILES` quand elle traite un envoi. Trouvé en jouant la page ; les tests unitaires ne le voyaient pas.
- **Pas d'en-tête `X-Content-Type-Options`** : un essai n'a eu aucun effet constaté sur l'instance ; le type servi est celui du
  contenu et le téléchargement est forcé.
- **Pas encore** : la lecture par le responsable sur l'écran d'attestation, la liste enregistrée à l'attestation, le message aux
  responsables (lot 2) ; `pieces.json`, les octets dans l'archive, l'import, le PDF et la copie Word/ODT (lot 3).
- **Pas vérifié** : l'antivirus du cœur (aucun configuré sur l'instance d'essai), Behat (la CI seule), le champ de fichier sous
  Behat, la suppression des fichiers à la désinstallation.

---

## [0.36.1] — 2026-10-21

La **copie Word/ODT** présente, comme le PDF, les déclarations d'une fiche à blocs **par bloc** (T-33) : un intertitre par bloc,
le bloc lui-même en tête de son groupe, les compétences hors bloc à la fin sous « Compétences hors bloc » ; les déclarations
passent alors un niveau plus bas. Une fiche sans bloc est écrite comme avant. **Aucun changement de schéma ni d'archive.** La
mise en page change (`dossier_document::LAYOUT` = 4) : la copie hebdomadaire écrit donc une copie neuve, même sans dossier modifié.
Deux tests (`blocs_archive_test`). **Pas vérifié** : le document ouvert dans Word ou LibreOffice.

## [0.36.0] — 2026-10-21

T-14, **lot 2 sur 2** : le journal de bord réflexif sort dans l'archive **de son auteur**. **Aucun changement de schéma de base
de données.** `version_format` et `schema_preuve` ne changent pas. Paquet 5.3 seulement. Toujours **fermé par défaut**
(réglage `reflectionopen`).

### Ajouté

- **`reflexion.json`**, fichier facultatif de l'archive : une entrée par numéro (1 à 200), **la dernière version enregistrée,
  jamais le brouillon**, avec la date d'ouverture de l'entrée. Il dit ce qu'il est dans le fichier même (`officiel: false`,
  une phrase : *ce n'est pas un dossier, personne ne l'atteste, personne d'autre que son auteur ne l'a lu*).
- **Il n'entre que dans l'archive que le candidat exporte lui-même** (MC-22). Une sauvegarde faite par l'administrateur, un
  export fait par la direction, n'en contiennent rien : un test le vérifie. **Conséquence à connaître** : une sauvegarde de
  dossier faite par un tiers ne contient pas le journal. Pas dans le PDF, pas dans la copie Word/ODT, pas dans la copie
  hebdomadaire.
- **Schéma** `docs/schema/reflexion.schema.json` (version `0.1.0`), exemples, 20 contre-exemples, contrôle `ajv` ;
  `schema_reflexion` au manifeste ; le schéma est joint à l'archive.
- **L'import** relit `reflexion.json` : le schéma, puis un numéro n'est pas là deux fois ; refus de toute l'archive, par son nom
  (`errorarchivereflexion`), sinon **restauration à son numéro en version 1, sans jamais écraser** une entrée déjà enregistrée
  ici, avec sa date d'ouverture d'origine. Le compte-rendu de l'import dit combien d'entrées sont revenues.
- La phrase de l'écran « ce journal ne quitte la plateforme que dans l'archive que vous exportez vous-même » est de nouveau vraie.
- Tests : `tests/reflexion_archive_test.php` (8) et 2 sur le schéma.

### Ce que cette version ne fait pas

- La limite de 200 entrées d'un fichier importé tient par le numéro (de 1 à 200, jamais deux fois), pas par un `maxItems` : le
  validateur du plugin ne connaît pas ce mot-clé.
- **La fonction reste fermée** : il faut la relecture de la préparation par un accompagnateur VAE, et T-84 (durée de conservation).

---

## [0.35.0] — 2026-10-21

T-14, **lot 1 sur 2** : un **journal de bord réflexif**, strictement privé. **Aucun changement de schéma de base de données**
(le moteur de brouillons et de versions du dossier de faisabilité sert tel quel). Paquet 5.3 seulement. **Fermé par défaut**
(réglage `reflectionopen`) : ne l'ouvrir qu'après la relecture de la préparation du dossier de validation par un
accompagnateur VAE et une fois la durée de conservation écrite (T-84).

### Ajouté

- **Un journal** : des entrées numérotées (200 au plus), datées, libres. Chaque entrée garde ses versions, son historique et sa
  comparaison comme les autres rubriques. Trois questions d'aide facultatives (*Que s'est-il passé ? Qu'en puis-je retirer ?
  Que ferais-je autrement ?*), jamais comptées ni évaluées. Page `journal.php` : liste, nouvelle entrée (elle prend le premier
  numéro libre), suppression après confirmation.
- **Strictement privé** : l'auteur seul lit. L'administrateur et un lecteur de brouillons (`draftview`) n'y ont **aucun accès** ;
  le journal **ne se dépose pas** (le gestionnaire de dépôts le refuse) ; le responsable ne le lit pas. Le rappel
  d'anonymisation et la mention « ce journal est à vous seul » sont sur chaque écran et ne se ferment pas.
- **Le candidat peut supprimer une entrée** avec toutes ses versions.
- **Amendement 3 du DER-referentiel-001** (MC-19 à MC-23) : le troisième contrôle du régime 2 n'existe pas pour ce document ;
  il tient parce que le texte ne sort pas par une voie qui se partage.
- Tests : `tests/reflexion_journal_test.php` (9) et `tests/behat/reflexion.feature` (2 scénarios).

### Ce que cette version ne fait pas

- **Pas encore dans l'archive** : l'export du candidat n'emporte pas le journal avant le lot 2 (`reflexion.json`, schéma, import).
  Jusque-là il n'existe que sur la plateforme, et l'effacement et l'export de la vie privée de Moodle le couvrent déjà.
- **Pas vérifié en CI** au moment de l'écriture : le Behat.

---

## [0.34.0] — 2026-10-20

T-11, **lot 3 sur 3** : la préparation du dossier de validation se **dépose**, et le responsable qui suit le candidat la
**lit**. C'est le troisième contrôle du régime 2, celui qui manquait : avant ce lot, personne ne lisait ce texte avant sa
sortie. **Aucun changement de schéma de base de données** (la table des dépôts portait déjà le type de document). Paquet
5.3 seulement. Toujours **fermée par défaut** (réglage `validationopen`).

### Ajouté

- **Le dépôt de la préparation** : un instantané, daté et empreinté, des **versions enregistrées** de toutes les fiches
  ensemble (jamais un brouillon). Numérotation **à part** de celle des dépôts du dossier de faisabilité ; un dépôt identique
  au dernier est refusé ; refusé aussi tant que la fonction est fermée, ou quand rien n'est enregistré. Pas de case
  « engagement » : l'engagement est celui du dossier de faisabilité seul.
- **La lecture** : le responsable qui suit le candidat (droit `readdeposit`) et l'administrateur lisent **la version
  déposée**, rubrique par rubrique, avec la phrase d'étiquetage toujours affichée (elle n'est pas figée dans l'empreinte :
  elle se lit dans la langue du lecteur). Ni brouillon, ni historique. Un lecteur de brouillons (`draftview`) n'y a pas accès.
- **Pas d'avis écrit** sur ce document (D-15) : l'avis du responsable est refusé sur un dépôt de ce type.
- Le responsable est **prévenu** à chaque dépôt, avec des mots à lui (*une préparation du dossier de validation*, pas
  *dossier de faisabilité*) et sans une ligne du texte.
- Boutons : « Dépôts de la préparation » sur la page de préparation (candidat) et sur le dossier (lecteurs, quand un dépôt
  existe).
- **Un export fait par un tiers n'emporte pas la préparation non déposée** (archive, PDF, copie Word/ODT) : D-15 (3) prime. Seul
  le candidat l'emporte avec son propre export ; une copie faite par la tâche planifiée (sans utilisateur) la contient.
  **Conséquence à connaître** : une sauvegarde de dossier faite par l'administrateur ne contient pas ces textes ; ce qui a été
  déposé reste lisible dans les dépôts.
- Le message de fin d'import dit combien de rubriques de la préparation sont revenues et combien ont été laissées telles
  quelles ; la page de report dit que les rubriques de la préparation **ne suivent pas** le report.
- La page « Fiches remplacées » compte aussi les candidats qui n'ont que des rubriques de la préparation sur l'ancienne fiche
  (une personne une fois par fiche, déclarations ou préparation).
- **Import quand la fonction est fermée** : les rubriques reviennent quand même (brouillons invisibles tant que la fonction est
  fermée, lisibles à l'ouverture) : choix de ne rien perdre plutôt que de refuser une archive valide.
- Tests : `tests/validation_deposit_test.php` (10), un test sur l'export par un tiers, un scénario Behat du dépôt.

### Corrigé en passant

- Le message de dépôt et celui de l'avis retrouvaient le dépôt par `(candidat, numéro)` sans le type de document : avec deux
  documents déposables, un dépôt 1 en aurait masqué un autre. Ils demandent maintenant le type.

### Ce que cette version ne fait pas

- **La fonction reste fermée** : il faut encore la relecture des intitulés et des aides par un accompagnateur VAE
  (`docs/RELECTURE-PREPARATION-VALIDATION.md`) avant d'ouvrir `validationopen`.
- Behat du dépôt de la préparation, la page « Fiches remplacées » et la page de report (elles ne disent pas encore que les
  rubriques ne suivent pas le report) : consignés.

---

## [0.33.0] — 2026-10-20

T-11, **lot 2 sur 3** : la préparation du dossier de validation sort avec le dossier. **Aucun changement de schéma de base
de données.** `version_format` et `schema_preuve` du manifeste ne changent pas. Paquet 5.3 seulement. Toujours **fermée
par défaut** (réglage `validationopen`) : ne l'ouvrir qu'après le lot 3 et la relecture des intitulés.

### Ajouté

- **`validation.json`**, fichier facultatif de l'archive : une entrée par rubrique écrite (fiche, code, bloc, partie, version,
  date, texte), **la dernière version enregistrée, jamais le brouillon**, par fiche puis par bloc en ordre naturel (BC2 avant
  BC10), la synthèse en dernier. Il dit **ce qu'il est** dans le fichier lui-même : `officiel: false`, `gabarit: par-bloc`,
  et la phrase d'étiquetage dans la langue du candidat (*trame d'aide, pas le dossier officiel, personne n'atteste ce
  texte*). Un bloc **retiré de la fiche** est exporté quand même.
- **Schéma** `docs/schema/validation.schema.json` (version `0.1.0`), exemples, 19 contre-exemples, contrôle `ajv` ;
  `schema_validation` au manifeste ; le schéma est joint à l'archive.
- **La copie imprimable (PDF) et la copie Word/ODT** portent la préparation, en dernier, avec la phrase d'étiquetage ; **la
  copie hebdomadaire** en tient compte (une rubrique enregistrée de nouveau fait une nouvelle copie ; mise en page 3).
- **L'import** relit `validation.json` : le schéma, puis ce qu'il ne dit pas (la fiche existe sur le site, le code se lit
  comme le bloc et la partie annoncés, une rubrique n'est pas là deux fois) ; refus de toute l'archive, par son nom
  (`errorarchivevalidation`), sinon **restauration en version 1 sans jamais écraser** une rubrique déjà écrite ici. Une
  rubrique d'un bloc que la fiche du site ne tient pas revient, montrée comme celle d'un bloc retiré.
- La structure d'une fiche se lit **sans le droit de lecture du cœur** (`framework_tree`) : un candidat n'en a pas besoin et
  les copies sont faites là où personne n'est connecté.
- Tests : 12 PHPUnit (`tests/validation_archive_test.php`) et 2 sur le schéma.

### Ce que cette version ne fait pas

- **Pas de dépôt, pas de lecture par le responsable** (lot 3) : le troisième contrôle du régime 2 n'existe pas encore.
- Le message de l'import ne dit pas encore combien de rubriques de validation sont revenues (le résultat les compte).
- **Pas vérifié** : le PDF rendu et regardé (seul le HTML est joué), la copie Word/ODT ouverte dans un traitement de texte.

---

## [0.32.0] — 2026-10-20

T-11, **lot 1 sur 3** : le candidat prépare son dossier de validation, bloc par bloc. **Une étape de mise à niveau** sur
`tool_vae_draft` (la fiche entre dans l'adresse d'une rubrique). Paquet 5.3 seulement. Conception :
`docs/T-11-dossier-de-validation.md` (v2, deux relectures indépendantes) ; décisions : D-15 ; amendement 2 du
DER-referentiel-001 (MC-14 à MC-18).

### Ajouté

- **Un second document, « Préparation du dossier de validation »**, dans le même moteur de brouillons que le dossier de
  faisabilité : brouillon automatique, versions jamais réécrites, historique, comparaison, retour à une version, effacement.
  **Ce n'est pas le dossier officiel du certificateur**, et chaque écran le dit en tête, en un encadré qu'on ne peut pas
  fermer : *trame d'aide à l'écriture, à reporter dans la trame du certificateur ; personne n'atteste ce texte*.
- **La trame générique d'attente** (`validation_catalog`) : pour chaque **bloc** de la fiche (T-33), trois rubriques de texte
  (Décrire mes activités, Analyser ma pratique, Faire le lien avec le bloc), puis une **synthèse** pour la fiche. Une fiche
  **sans bloc**, ou avec plus de 12 blocs, a les trois rubriques une fois pour la fiche entière. Rien n'est calculé,
  suggéré ni évalué : aucun décompte, aucune barre, aucun « complet ». **Les intitulés et les aides sont provisoires, à
  relire par un accompagnateur VAE en exercice avant toute mise à disposition** (D-15, MC-14).
- **La consigne d'anonymisation** contre « Décrire » et « Analyser » (une analyse de pratique raconte des situations qui
  identifient une personne sans la nommer), en plus du rappel non masquable.
- **Une page de préparation** (`validation.php`, bouton sur le dossier) : par fiche, par bloc, les déclarations du candidat
  **en lecture seule** à côté (renvoi, jamais copie), où en est chaque rubrique en mots (« pas encore écrite » ou la date de
  la dernière version). Les rubriques d'un bloc **retiré** de la fiche restent montrées, lisibles et conservées.
- **Accès restreint (MC-16)** : le candidat seul lit ce document ; ni l'administrateur du site, ni un lecteur de `draftview`
  ne lisent les brouillons ni l'historique (le dossier de faisabilité reste lisible comme avant). Ils liront les versions
  *déposées* (lot 3).
- **Adresse** : l'index unique de `tool_vae_draft` devient `(candidat, doctype, fiche, rubrique, emplacement)`, la colonne
  `fichecode` est non nulle (vide pour le dossier de faisabilité) ; la rubrique s'appelle `<rang>_<partie>` (`bc01_decrire`),
  `_synthese` pour la fiche. L'étape de mise à niveau remplit les anciennes lignes.
- **Vie privée** : la déclaration de la table dit que ce document décrit des situations de travail et peut, malgré le rappel,
  contenir des éléments sur des personnes accompagnées ou des collègues.
- Tests : 12 PHPUnit (`tests/validation_preparation_test.php`) et 2 scénarios Behat (`tests/behat/validation.feature`).

- **Relecture indépendante faite, corrigée** : l'étape de mise à niveau est une classe testée (`draft_schema`) qui joue les
  **deux états** de la table, dont celui d'un site venu du paquet 4.5 (table créée par une étape précédente depuis le
  `install.xml` actuel : la colonne y est déjà non nulle sous l'index, et le cœur refuse de changer une colonne sous un
  index) ; le défaut vide de la colonne est retiré (le cœur l'ignore et signale un message de débogage, que le contrôle de
  l'installation de PHPUnit refusait : c'est ce qu'avait vu la CI de `c271282`) ; le titre de la rubrique est échappé (le nom
  d'un bloc vient de la fiche) et les textes de la liste groupée aussi ; la fermeture vaut pour toutes les portes
  (autosave, version, restauration) ; la fiche doit être déclarée par le candidat et écrite exactement comme le site la tient
  (la base peut replier la casse) ; l'étape Behat « history » ne prend plus que le dossier de faisabilité.
- **Consigné, pas fait** : au-delà de 12 blocs après une republication, les rubriques déjà écrites par bloc sont montrées
  comme celles d'un bloc retiré ; un rang hors du motif des rangs est ignoré sans message.

### Ce que cette version ne fait pas

- **Fermée par défaut** : un réglage de l'administration (« Ouvrir la préparation du dossier de validation aux candidats », `validationopen`, 0) la garde invisible tant que les intitulés n'ont pas été relus par un accompagnateur et que le dépôt (lot 3) n'existe pas ; fermée, la page, le bouton du dossier et l'écriture refusent (`errorvalidationclosed`).
- **Rien ne sort encore** : ni archive, ni PDF, ni copie Word/ODT, ni dépôt (lots 2 et 3). **Pas de lecture par le responsable**
  avant le lot 3 : le troisième contrôle du régime 2 (D-15) n'existe qu'avec lui. **Ne pas ouvrir le réglage avant le lot 3 et
  la relecture des intitulés.**
- Pas de rapport des rubriques d'un candidat dans « Fiches remplacées » ; la page de report (T-30) ne dit pas encore que les
  rubriques ne suivent pas.
- **Pas vérifié** : Behat (la CI seule le joue) ; la page à l'écran avec un bloc long ; la mise à niveau sur une base qui a
  déjà des rubriques (jouée sur l'instance d'essai, une base vide de rubriques de validation) ; les textes par un humain.

---

## [0.31.0] — 2026-10-20

T-31 : les fiches médico-sociales de France compétences, de l'export à l'écran d'import. **Aucun changement de schéma
de base de données.** Paquet 5.3 seulement. Conception : `docs/T-31-fiches-medico-sociales.md` (validée le 20/10).

### Ajouté

- **Un convertisseur** (`scripts/convertir-fiche-rncp.php`, classe `fiche_converter` sans dépendance à Moodle) : il lit
  l'export CSV quotidien de France compétences (fichiers des blocs et « Standard », lus sur l'export du 19/10/2026 :
  point-virgule, UTF-8 avec marque d'ordre des octets, CRLF) et écrit le CSV de l'écran d'import, puis affiche les
  valeurs à y saisir. **Aucun libellé n'est reformulé ni coupé** : à 100 caractères ou moins il passe tel que publié ;
  au-delà (colonne de 100 caractères du cœur) la ligne prend le titre neutre `Bloc BC03` et **le libellé publié va en
  entier dans la description**. Un intitulé de fiche de plus de 100 caractères laisse le code tenir lieu de nom. Une fiche
  sans bloc, inconnue, ou un code de bloc d'une autre forme sont refusés par leur nom.
- **Le plugin montre le libellé publié** : `competency_gateway::label_of()` rend la description à la place du titre neutre
  (et seulement pour lui) ; liste de déclaration, couverture, dossier, archive et page des correspondances le lisent.
- **Une procédure** : `docs/IMPORT-FICHES-FRANCE-COMPETENCES.md` (où prendre l'export, convertir, importer, republier), avec
  **les six fiches en vigueur** constatées dans l'export du 19/10/2026 : DEAS `RNCP40692`, DEAES `RNCP42423`, DEES
  `RNCP41747`, DEME `RNCP39643`, CAFERUIS `RNCP36836`, CAFDES `RNCP36838`.
- Une fixture **inventée** aux colonnes de l'export réel (`tests/fixtures/export-*-exemple.csv`) et 11 tests
  (`tests/fiche_converter_test.php`) : conversion, libellé long jamais coupé, refus nommés, CSV relu par l'import, réimport
  sans changement.

### Constaté sur l'export réel

- Sur 56 314 blocs, 9 861 ont un libellé de plus de 100 caractères ; parmi les six fiches : DEAS 3 sur 5, DEAES 2 sur 5, DEES
  1 sur 4, DEME 1 sur 3, CAFDES 1 sur 4, CAFERUIS aucun. L'intitulé de la fiche CAFERUIS fait 110 caractères.
- **L'export ne donne pas les compétences d'un bloc**, seulement son code et son libellé.
- Le DEES de 2015 (`RNCP2348`) et le CAFDES de 2015 (`RNCP367`) sont inactifs et sans bloc : non importables par ce moyen.
- Les six fiches ont été importées sur l'instance d'essai ; une seconde passe ne change rien.

### Ce que cette version ne fait pas

- Aucune donnée de France compétences dans le dépôt (PO-2). Les compétences à l'intérieur d'un bloc ne sont pas
  structurées. **Pas vérifié** : l'écran d'import avec un de ces fichiers dans un navigateur ; Behat (non écrit pour ce lot) ;
  les fiches de l'instance de production.

---

## [0.30.0] — 2026-10-19

T-33, **lot 3 sur 3** : l'archive d'export et la copie imprimable disent le bloc. **Aucun changement de schéma de
base de données.** `schema_preuve` et `version_format` du manifeste ne changent pas : le schéma d'une preuve n'est
pas touché, seul `referentiel.json` (qui n'est pas relu à l'import) gagne des champs facultatifs. Paquet 5.3.

### Ajouté

- **`referentiel.json`** : sur une compétence déclarée qui se range sous un bloc, `bloc` (le rang du bloc) ; sur la
  fiche, `blocs` (les blocs qui portent une compétence déclarée, et ceux qui sont eux-mêmes déclarés), chacun une
  fois, avec son rang et son intitulé. **Une fiche à plat n'a ni l'un ni l'autre** : son fichier est celui d'avant.
- **La copie imprimable** (PDF) groupe les déclarations d'une fiche à blocs sous un titre « Bloc … » par bloc, **le
  bloc lui-même en premier**, les compétences hors bloc à la fin, dans l'ordre des rangs. Une fiche à plat s'écrit
  comme avant.
- Passerelle : `framework_tree()` lit la place des compétences d'une fiche **sans le droit de lecture du cœur** : l'archive est
  aussi produite là où personne n'est connecté (sauvegarde à la désinstallation, ligne de commande), où l'appel
  habituel est refusé. Trouvé par le test de sauvegarde existant, avant tout envoi.
- **La copie imprimable dit le report** (T-30) : une déclaration reportée porte une ligne « Report : Reportée depuis
  RNCP… le … » quand l'archive tient l'ancienne ; l'ancienne ne dit rien. Un test dans `tests/filiation_test.php`.
- Tests : `tests/blocs_archive_test.php` (5 tests : le bloc et les blocs listés une fois, un bloc déclaré seul, une
  fiche à plat sans champ, le PDF groupé et son ordre, le PDF d'une fiche à plat inchangé).

- **Relecture indépendante des lots 2 et 3 faite** : une fiche à plat posée à côté d'une fiche à blocs garde son
  propre groupe dans la liste (elle n'est plus dite « hors bloc ») ; le PDF met un titre aux compétences hors bloc et
  trie les blocs en ordre naturel (BC2 avant BC10) ; une compétence rangée plus bas que deux niveaux (arbre fait à la
  main dans `tool_lp`) reste dans la liste ; tri naturel partout ; la phrase de la couverture ne contient plus le mot
  « complet » ; générateur Behat au nom de l'administrateur ; libellé de champ Behat corrigé ; tests ajoutés
  (attestations et dernière date d'un bloc, fiche à plat à côté d'une fiche à blocs, hors bloc au PDF, ordre naturel).
- **Consigné, pas fait** : la copie Word/ODT (`dossier_document`) ne groupe pas encore par bloc (sa copie hebdomadaire
  sera réécrite une fois pour une fiche à blocs) ; un bloc sorti de la source et jamais déclaré laisse ses
  compétences déclarées en retrait sans ligne de bloc ; `declare.php` trie maintenant les fiches par code ; deux
  requêtes de plus par déclaration affichée (`bloc_of`).

### Ce que cette version ne fait pas

- **La page des correspondances (T-30)** reste à plat. **L'import d'une archive** ne relit pas `referentiel.json` :
  la hiérarchie vient de l'import de la fiche, jamais de l'archive.
- Pas vérifié : le PDF rendu et regardé (seul le HTML est joué par les tests) ; l'archive lue par un jury.

---

## [0.29.0] — 2026-10-19

T-33, **lot 2 sur 3** : une fiche à blocs est présentée par bloc. **Aucun changement de schéma de base de données.**
Une fiche à plat s'affiche exactement comme avant. Paquet 5.3 seulement.

### Ajouté

- **La liste pour déclarer est groupée par bloc** quand une fiche en a : un groupe par bloc, le bloc lui-même en
  premier (« … (le bloc lui-même) », on peut déclarer sur un bloc comme sur une compétence), puis ses compétences ;
  les compétences sans bloc dans un dernier groupe « Compétences hors bloc ». Sans bloc nulle part : la liste simple
  d'avant.
- **Le tableau de couverture** range un bloc puis ses compétences (mises en retrait) ; la ligne d'un bloc dit
  « (bloc) » et **additionne** ses déclarations, ses attestations en vigueur et sa dernière attestation avec celles de
  ses compétences. **Des nombres seulement** : jamais un pourcentage, un état ou un mot qui dise qu'un bloc est
  complet ou validé (D-1, D-4). Toujours le seul dossier du candidat. Une phrase le dit sous le titre de la fiche.
- **Le dossier** nomme le bloc d'une déclaration (ligne « Bloc »).
- Une classe `outline` range les compétences d'une fiche en blocs puis compétences, **par rang** (ordre naturel de
  l'`idnumber`), jamais par le `sortorder` du cœur : un déplacement fait à l'import place la compétence en dernier
  dans son bloc. La couverture, la liste et (lot 3) l'archive la lisent au lieu de refaire le regroupement.
- Passerelle : `bloc_of()` (le bloc d'une compétence, pour l'affichage).
- Tests : 9 PHPUnit (`tests/blocs_presentation_test.php`), 2 scénarios Behat (`tests/behat/blocs.feature`), une entité
  de générateur « competencies » (une compétence de plus, sous un bloc si on le dit).

### Ce que cette version ne fait pas

- **L'archive et le PDF** ne disent pas encore le bloc (lot 3) ; la page des correspondances (T-30) reste à plat.
- Pas vérifié : la liste groupée au lecteur d'écran ; Behat (la CI seule le joue). **Jouées sur l'instance d'essai** :
  la liste de déclaration (trois groupes, le bloc lui-même choisissable) et le dossier avec couverture par bloc.

---

## [0.28.0] — 2026-10-19

T-33, **lot 1 sur 3** (conception : `docs/T-33-presentation-par-bloc.md`) : l'import garde les blocs de compétences
d'une fiche en arbre. **Aucun changement de schéma de base de données.** Rien ne change encore à l'écran du
candidat (lot 2). Paquet 5.3 seulement.

### Ajouté

- **Colonne facultative `bloc`** du CSV d'import : le rang du bloc sous lequel la ligne se range, vide pour un bloc
  lui-même. **Sans la colonne, rien ne change** (fiche à plat, mêmes messages). Avec elle, la hiérarchie est celle
  du cœur (parent d'une compétence de `core_competency`, visible dans `tool_lp`) : le plugin n'a pas de table de
  blocs. Deux niveaux au plus (bloc, compétence).
- **Contrôles avant toute écriture**, les lignes nommées : un bloc qui n'est pas une ligne du fichier, un bloc rangé
  lui-même sous un autre bloc, une ligne qui est son propre bloc, un bloc qui n'est pas un rang. Le fichier est
  refusé en entier.
- **Écriture** : les blocs d'abord, quel que soit l'ordre des lignes ; une compétence est créée sous son bloc. À la
  réimportation, une compétence dont le bloc a changé est **rangée sous le nouveau** (seule sa place change :
  `idnumber`, intitulé, déclarations, correspondances ne bougent pas) ; une cellule vide la remet au sommet ; **une
  fiche déjà importée à plat reçoit sa hiérarchie** sans que rien de ce qui a été déclaré ne bouge. Un fichier sans la
  colonne laisse une hiérarchie existante telle quelle. Le message d'import dit combien de compétences ont changé
  de bloc.
- **Passerelle** : `list_framework_competencies()` rend aussi `parentid` ; `create_competency()` prend un parent ;
  `set_parent_competency()` change la place d'une compétence. Aucun appel au cœur hors de la passerelle.
- Une fiche d'essai à deux niveaux : `tests/fixtures/fiche-exemple-blocs.csv`.
- **Relecture indépendante faite** : un fichier qui rangerait sous un autre bloc un bloc qui porte encore des
  compétences sur le site est refusé avant toute écriture (sinon un troisième niveau resterait dans le cœur) ; les
  tests de refus vérifient maintenant quelle erreur est levée, un bloc qui quitte la fiche reste avec ses
  compétences et est marqué sorti ; le message dit « changé de place » (un retour au sommet compte aussi).
- **À savoir (le cœur)** : un déplacement ou une création sous un bloc efface la règle de compétence de `tool_lp`
  des blocs concernés ; un déplacement place la compétence en dernier dans son nouveau bloc : le lot 2 trie par
  rang, pas par `sortorder`.

### Ce que cette version ne fait pas

- **Rien n'est présenté par bloc** : liste de déclaration, couverture et dossier restent à plat (lot 2) ; archive et
  PDF (lot 3). **Jamais de seuil ni d'état de bloc.**
- **Pas vérifié** : la page d'import jouée dans un navigateur avec un fichier à blocs ; `tool_lp` regardé sur une
  fiche à arbre ; Behat (non écrit pour ce lot).

---

## [0.27.0] — 2026-10-19

T-30, **suite du lot 3** : la filiation d'une déclaration reportée voyage dans l'archive d'export. **Aucun
changement de schéma de base de données** ; le schéma pivot d'une preuve gagne deux champs facultatifs. Paquet 5.3
seulement.

### Ajouté

- **`reportee_depuis` et `reportee_le`** dans une preuve (`preuve.schema.json`, facultatifs, toujours ensemble) :
  l'identifiant de la preuve dont celle-ci est la copie, et la date du report. L'export les écrit sur la
  déclaration reportée ; l'ancienne n'en porte pas. **Ni qui a décidé la correspondance, ni sa note** : ils restent
  sur le site d'origine.
- **À l'import**, la filiation est relue et la trace `tool_vae_carry` revient, **sans** celui qui a décidé, la date
  de décision et la note (comme après un effacement). Une archive sans filiation se lit comme avant.
- **Refus de l'archive entière** (`errorarchivefiliation`) : la preuve nommée n'est pas dans l'archive, est sur la
  même fiche, a déjà été reportée par une autre preuve, ou la chaîne revient sur elle-même ; un seul des deux
  champs, ou une preuve reportée d'elle-même (refus de schéma).
- **La chronologie d'une déclaration** (T-40) dit le report : « Reportée sur la nouvelle fiche » ou « Reportée depuis
  la fiche que celle-ci remplace », à sa date, sans nom ni texte.
- Un exemple (`preuve-reportee.json`) et trois contre-exemples du schéma ; la règle « les deux champs vont
  ensemble » est aussi jouée par `scripts/verifier-schema.mjs`.

### À savoir

- **`schema_preuve` et `version_format` du manifeste ne changent pas** : changer le premier ferait refuser par
  l'importeur toutes les archives déjà produites. Conséquence : un plugin d'une version **antérieure** refuse
  (proprement, par son schéma) une archive qui porte une filiation ; il lit toutes les autres.
- Le PDF imprimable ne dit pas encore le report (le CHANGELOG 0.26.0 disait la même chose de la chronologie : c'est fait). **Pas vérifié** : `scripts/verifier-schema.mjs` (ajv) joué en
  local, la CI seule le joue ; l'archive vue dans un navigateur.

---

## [0.26.0] — 2026-10-19

T-30, **lot 3 sur 3** (D-14) : reporter une déclaration sur la fiche qui remplace la sienne. **Une table nouvelle**
(`tool_vae_carry`, étape de mise à niveau), un événement, une page. Paquet 5.3 seulement.

### Ajouté

- **Le candidat reporte lui-même, une déclaration à la fois**, sur une correspondance que l'administrateur a
  décidée (lot 2) : bouton « Reporter sur la nouvelle fiche » sous la déclaration, dans son dossier, puis page
  `carry.php`. La page montre la déclaration d'origine, la correspondance (décidée par l'administrateur du site, à
  telle date, avec sa note) et un formulaire **prérempli** que le candidat relit, corrige s'il le veut, puis envoie.
  Rien n'est jamais reporté sans lui, ni en lot, ni automatiquement.
- **Une copie, pas un déplacement.** La nouvelle déclaration est faite par `evidence_manager::declare_activity()`
  (mêmes contrôles, nouvelle ancre dans le cœur) ; elle est « à attester ». L'ancienne reste sur l'ancienne fiche,
  avec son attestation, intacte. Chacune porte une mention : « Reportée sur la fiche … le … » et « Reportée depuis
  la fiche … le … ; elle doit être attestée de nouveau ».
- **La trace** (`tool_vae_carry`) garde une **copie de la décision** (qui, quand, note) : la ligne de
  `tool_vae_passage` peut être remplacée ou reprise après coup, la trace reste. Le candidat lit « l'administrateur
  du site » et la date ; le nom n'est montré qu'à qui peut importer.
- **Gardes** : seul le candidat, avec le droit de déclarer ; une fiche remplaçante déclarée ; une correspondance
  décidée ; **la correspondance est relue au moment du report** (si la fiche remplaçante a changé depuis, refus
  nommé) ; une déclaration ne se reporte qu'une fois (verrou et clé unique) ; la compétence est celle de la
  décision, jamais celle du formulaire ; un échec après l'écriture dans le cœur ne laisse rien (transaction).
- **Page « Fiches remplacées »** : une colonne de nombres, « Reportées ». Aucun nom, aucun contenu.
- **Événement** `evidence_carried` : identifiants seulement. **Vie privée** : table déclarée, exportée
  (références de la déclaration d'origine et de la nouvelle), effacée avec le dossier ; l'effacement de
  l'administrateur retire son nom, la date et la note de la trace et garde la trace. Purge d'une déclaration :
  sa trace part avec elle.

### Ce que cette version ne fait pas

- **Les attestations ne se reportent pas** (D-14). **L'archive d'export** ne porte pas encore la filiation
  (deux déclarations indépendantes) : un champ facultatif viendra dans un lot suivant.
- **La chronologie d'une déclaration** n'affiche pas encore le report. **Pas de message** nouveau.
- **Relecture indépendante faite** (deux relecteurs, lecture du code seule) : un défaut bloquant trouvé aussi en
  jouant la page sur l'instance (valeurs initiales du formulaire passées comme un tableau), corrigé ; la sonde
  d'identifiant de `carry.php` fermée ; la date du report ajoutée à l'export du candidat ; la page des
  correspondances prévient que la note est lue par le candidat ; deux tests ajoutés (clé unique, purge de
  l'ancienne déclaration) et l'assertion d'atomicité précisée.
- **Consigné, pas fait** : une compétence cible marquée « sortie de la source » reste proposée ; le message
  « correspondance périmée » sert aussi quand le cœur refuse la lecture de la cible ; un refus de droit n'est pas
  journalisé sur cette page ; `passage_manager` ne refuse pas de changer une décision déjà reportée (la trace en
  garde la copie) ; pas de balayage des orphelins pour `tool_vae_carry` (aucun chemin n'en crée aujourd'hui) ; le
  cas « échec de l'écriture de la trace après la déclaration » n'a pas de test propre.
- **Pas vérifié** : la concurrence de deux envois (verrou et clé unique lus, pas éprouvés à deux processus) ; Behat
  (la CI seule le joue) ; les textes relus par un humain.

---

## [0.25.0] — 2026-10-19

T-30, **lot 2 sur 3** (D-14) : proposer les correspondances entre les compétences d'une fiche remplacée et
celles de la fiche qui la remplace, et laisser une personne décider. **Aucun changement de schéma** (la table
`tool_vae_passage` existait, vide). Un événement, une page. Paquet 5.3 seulement.

### Ajouté

- **Page « Correspondances pour la fiche X »** (depuis la page des fiches remplacées, lien « Correspondances »,
  droit `tool/vae:import`) : pour chaque compétence de la fiche remplacée, le nombre de déclarations qui la
  portent, **la suggestion** du plugin et **la décision** prise, avec qui, quand et la note.
- **La suggestion** est la compétence de même rang dans la nouvelle fiche, à défaut celle dont le libellé est
  presque identique (85 % de ressemblance au moins) ; la page dit pourquoi. Sans rang ni libellé assez proche :
  aucune suggestion, jamais « la moins mauvaise ».
- **La décision** se prend une compétence à la fois : « Confirmer cet équivalent », avec une note facultative
  (500 caractères). Une nouvelle décision remplace la précédente ; « Retirer » la reprend ; « Aucun équivalent
  décidé » dans la liste revient au même. Une compétence sans décision n'a pas d'équivalent et reste où elle est.
- Garde-fous : l'équivalent doit appartenir à la fiche qui remplace ; la fiche doit avoir un remplaçant déclaré ;
  le droit d'importer est redemandé là où l'on écrit.
- **Événement** `passage_changed` : les identifiants des deux compétences, jamais la note.

### Ce que cette version ne fait pas

- **Une suggestion n'est jamais enregistrée** : seule une décision l'est.
- **Elle ne déplace rien** : aucune déclaration ne change de fiche, aucune attestation n'est refaite. Le
  report des déclarations est le lot 3, qui lira ces décisions. L'attestation ne se reportera pas (D-14).
- Pas de Behat pour les deux pages (fiches remplacées, correspondances).

---

## [0.24.0] — 2026-10-19

T-30, **lot 1 sur 3** (D-14) : savoir quelle fiche de certification en remplace une autre, et ce qui tient
encore l'ancienne. **Une colonne nouvelle** (`tool_vae_source.successorcode`, étape de mise à niveau), un
événement, une page. Paquet 5.3 seulement.

### Ajouté

- **Page « Fiches de certification remplacées »** (*Compétences*, droit `tool/vae:import`) : par fiche
  importée, la date de la source, la fiche qui la remplace si l'administrateur l'a déclarée, le **nombre de
  déclarations** et le **nombre de candidats** qui la tiennent, le nombre de compétences sorties de la source.
  Un formulaire déclare « la fiche B remplace la fiche A » ; un bouton retire la déclaration.
- Garde-fous : une fiche ne se remplace pas elle-même ; ni boucle, si longue soit la chaîne ; les deux fiches
  doivent être importées ; le droit d'importer est redemandé là où l'on écrit, pas seulement sur la page.
- **Événement** `supersession_changed` : les codes des deux fiches, rien d'autre.

### Ce que cette version ne fait pas

- **Elle ne déplace rien.** Aucune déclaration ne change de fiche, aucune attestation n'est refaite, la table
  `tool_vae_passage` reste vide. Reporter une déclaration sur la nouvelle fiche est le lot 3, précédé du lot 2
  (proposer des correspondances, qu'une personne valide une à une). L'administrateur décide seul ; l'attestation
  ne se reportera pas (D-14).
- La page compte et ne nomme personne : elle est lue par qui peut importer, pas par qui peut lire les dossiers.
- Pas de Behat pour la page.

---

## [0.23.1] — 2026-10-19

Corrections tirées d'une **relecture indépendante** de T-20 et de T-22 (deux relecteurs, lecture du code ; ni
PHP ni PHPUnit ne tournaient chez eux). **Aucun changement de schéma.** Paquet 5.3 seulement.

### Corrigé

- **T-22 — le rappel pouvait partir deux fois, ou pas du tout.** La fenêtre d'un jour était fixe alors que la
  tâche tourne à une minute tirée au hasard : deux exécutions rapprochées trouvaient la même déclaration, deux
  exécutions éloignées en laissaient une dans le trou. La fenêtre va maintenant **de la dernière exécution à
  maintenant**. Sans dernière exécution connue (première fois), un jour seulement. Un arrêt de plus d'une
  semaine n'est pas rattrapé en bloc : les rappels de ces jours-là ne sont pas envoyés. Le texte « exactement
  une fois » reste vrai tant que la tâche tourne ; il n'était pas vrai avant.
- **T-20 — deux courses.** Deux responsables qui envoyaient au même instant pouvaient ouvrir deux questions sur
  la même déclaration (D-13 n'en permet qu'une), et deux envois de la réponse pouvaient écraser la première.
  La vérification et l'écriture se font maintenant sous un verrou (`errorrequestbusy` si le verrou n'est pas
  libre au bout de dix secondes). **Pas de test de concurrence** : le verrou est lu, pas éprouvé.
- **T-20 — le message de précision visait le droit de brouillon**, alors que répondre demande `declare` et
  `viewown`. Il vise maintenant `declare` (fournisseur) et vérifie les deux à l'envoi : un candidat qui ne
  peut pas répondre ne reçoit pas un message qui n'y mène pas. Le fournisseur de message n'est relu qu'à une
  mise à niveau : d'où ce numéro de version.
- Le message de rappel dit « depuis N jours ou plus » (le critère est « au moins N »).

### Ajouté

- Tests : fenêtre du rappel (deux exécutions rapprochées, exécution tardive, première fois, arrêt long) et
  **message de précision** (ni auteur ni texte, pas d'envoi à un autre que le candidat, une fois répondu ou
  effacé, ni sans le droit de répondre).
- Le rappel dit dans la sortie de la tâche combien de messages il a mis en file, et les échecs de mise en file.
- Réglage `overduedays` : son intervalle (1 à 365 ; hors de là, désactivé) est dit dans sa description.
- README : « Dix messages », avec ceux qui manquaient (rappel, dépôt, avis, précision).

### Pas fait

- Une attestation retirée après le délai ne redéclenche pas le rappel (la déclaration garde son âge, elle ne
  « franchit » plus rien). Voulu pour l'instant.
- Les identifiants d'un responsable dont le compte est supprimé restent dans les demandes de précision et les
  avis des dossiers des autres ; le nom, lui, n'est plus affiché. Comme pour les avis, à trancher avec T-84.
- `PARAM_TEXT` retire les `<…>` d'un texte libre (comme dans les autres formulaires du projet).
- Un message de précision part encore si la déclaration a été attestée entre-temps (« ni verrou ni verdict »).

---

## [0.23.0] — 2026-10-15

T-22 : le rappel des déclarations qui attendent une attestation, et leur rapport. **Aucun changement de
schéma** ; une tâche planifiée, un fournisseur de message, une page, un réglage. Paquet 5.3 seulement.

### Ajouté

- **Rappel, une fois par déclaration** : réglage `overduedays` (nombre de jours, **zéro = désactivé, par
  défaut**). Une tâche quotidienne (4 h) trouve les déclarations qui atteignent ce délai pendant le dernier
  jour et prévient **chaque responsable qui suit le candidat et peut attester**, un message par candidat et
  par responsable. Aucune colonne ne retient ce qui a été annoncé : la fenêtre d'un jour suffit.
- **Le message** (`declarationoverdue`) nomme le candidat et dit un nombre ; jamais le récit. Il est
  recalculé à l'envoi : il n'est pas envoyé si plus rien n'attend, ou si le responsable ne suit plus ou ne
  peut plus attester.
- **Rapport** (*Compétences → Déclarations en attente d'attestation*, droit `tool/vae:viewany`) : par
  candidat, le nombre de déclarations en attente, la plus ancienne et son âge, filtrable par délai. Il
  met côte à côte ce que le lecteur peut déjà lire une à une ; il ne crée aucune donnée.
- **Ce n'est pas une jauge sur une personne** : le rapport dit que des déclarations attendent et depuis
  quand, rien de la qualité d'un dossier ni du travail d'un responsable. Une attestation retirée refait
  attendre la déclaration, dont l'âge reste celui de la déclaration.

### Pas fait

- Pas de rappel répété : un seul, au franchissement du délai. Une tâche qui manque un jour manque aussi
  les déclarations qui franchissaient le délai ce jour-là.
- Pas de rapport par responsable (il aurait été une jauge sur une personne).
- Behat non écrit pour la page du rapport.

---

## [0.22.0] — 2026-10-15

T-20 : la demande de précision sur une déclaration, avant son attestation (D-13). **Une table nouvelle**
(`tool_vae_request`), deux événements, un message. Paquet 5.3 seulement.

### Ajouté

- **Le responsable qui suit le candidat et peut attester pose une question sur une déclaration** : texte libre,
  daté, empreinté, **jamais réécrit**, 5 000 caractères au plus. Une seule question ouverte à la fois par
  déclaration ; rien n'est demandé d'une déclaration attestée (le retrait de l'attestation rouvre).
- **Le candidat répond une fois**, avec le rappel d'anonymisation toujours visible ; la réponse est un texte à
  côté de la déclaration, qui n'est pas modifiée.
- **Ce n'est ni un verrou ni un verdict** (D-1) : l'attestation n'attend pas la réponse, et le plugin ne dit pas
  si une réponse suffit.
- **Le candidat est prévenu** (`precisionasked`) : ni le texte ni l'auteur.
- **Page `precision.php`** et lien sous chaque déclaration du dossier, avec le nombre de questions en attente.
- **Vie privée** : table déclarée ; le candidat exporte tout, un responsable les questions qu'il a posées ;
  l'effacement du dossier les emporte ; l'effacement de l'auteur vide `authorid` et garde le texte.
- **Scénarios Behat** de l'avis sur un dépôt (`opinion.feature`) et de la demande de précision
  (`precision.feature`) : écrits, **non joués en local** (la CI seule les joue).

### Pas fait

- Le responsable n'est pas prévenu de la réponse : il la lit sur la page de la déclaration.
- La question n'est pas dans l'archive d'export.

---

## [0.21.0] — 2026-10-15

T-10, lot 5b : l'avis du responsable sur un dépôt (D-12). **Une table nouvelle** (`tool_vae_opinion`), une
capability, un événement, un message. Paquet 5.3 seulement.

### Ajouté

- **Le responsable qui suit le candidat écrit son avis sur un dépôt précis** : texte libre, daté,
  empreinté, **jamais réécrit**, 20 000 caractères au plus. Il faut suivre le candidat et détenir
  `tool/vae:writeopinion` **et** `tool/vae:readdeposit` ; le candidat n'écrit pas le sien, et
  l'administrateur, qui ne suit personne, n'écrit pas à la place du responsable. Le même avis deux fois
  par la même personne sur le même dépôt est refusé.
- **L'avis reste sur son dépôt** : un nouveau dépôt n'en porte pas tant qu'un responsable n'en a pas
  écrit un dessus.
- **Le plugin ne dit pas si l'avis est favorable** : pas de case, pas de calcul (D-1, D-4). Il enregistre
  ce que le responsable a écrit.
- **Lecture** : le candidat et ceux qui lisent le dépôt lisent les avis, avec le nom de l'auteur ; le
  nom disparaît si le compte de l'auteur est effacé, le texte reste.
- **Le candidat est prévenu** (`opinionmade`) : le numéro du dépôt, ni le texte ni l'auteur.
- **Vie privée** : table déclarée ; le candidat exporte tous les avis de ses dépôts, un responsable ceux
  qu'il a écrits ; l'effacement du dossier les emporte ; l'effacement de l'auteur vide `authorid`.

### Pas fait

- **L'avis n'est pas dans l'archive d'export** : il est de la main d'un tiers, sur un jalon que l'archive
  n'emporte pas non plus.
- Le scénario Behat de l'avis n'est pas écrit (les droits à poser sont nombreux) ; PHPUnit couvre les
  règles.

---

## [0.20.0] — 2026-10-15

T-10, lot 5a : l'engagement du candidat. Chaînes nouvelles, **aucun changement de schéma**, paquet 5.3
seulement.

### Ajouté

- **Une dixième rubrique, « Engagement du candidat »**, rubrique de texte comme les autres (versions,
  historique, comparaison, copie Word/ODT, archive). Elle suit le parcours prévisionnel, dans l'ordre de
  l'annexe II. L'aide dit que le plugin n'est pas la signature du formulaire officiel.
- **La confirmation se donne avec le dépôt** : une case, décochée par défaut, au formulaire de dépôt. Elle
  est **dans le jalon**, donc dans son empreinte : le même texte déposé avec la case est un nouveau jalon,
  pas « rien n'a changé ». Elle exige que la rubrique ait une version enregistrée. Le jalon dit, à la
  lecture, que l'engagement a été confirmé ; sa date est celle du dépôt.

### Pas fait

- L'**avis de l'accompagnant** (lot 5b, décidé : D-12) n'est pas encore là.

---

## [0.19.0] — 2026-10-15

T-21 : le dépôt du dossier de faisabilité. **Une table nouvelle** (`tool_vae_deposit`), une capability,
un événement, une page, un message. Paquet 5.3 seulement.

### Ajouté

- **Le dépôt est un jalon, pas un verrou** (décidé le 15/10) : un instantané daté et empreinté (SHA-256)
  de la dernière version **enregistrée** de chaque rubrique, jamais un brouillon. Le candidat continue
  d'écrire après ; un nouveau dépôt est un nouveau jalon, numéroté. Les jalons passés ne changent jamais.
  Un dépôt identique au dernier est refusé ; un dossier sans rubrique enregistrée aussi.
- **`tool/vae:readdeposit`** : lire les dépôts d'un candidat, **jamais ses brouillons ni ses versions**.
  Donnée à aucun archétype. L'identité et le statut d'un dépôt restent derrière `tool/vae:identityview`
  (MC-11). Le candidat dépose seul : l'administrateur lit, il ne dépose pas à sa place.
- **Événement `deposit_created`** : numéro seulement, jamais le contenu.
- **Vie privée** : la table est déclarée, exportée avec les données du candidat, effacée avec le dossier
  et balayée par la tâche de réconciliation si le compte a disparu.

- **La page `deposit.php`** (lot 2) : le candidat dépose avec une note facultative, voit la liste de ses
  dépôts (numéro, date, note, début de l'empreinte) et relit chacun, tel qu'il a été figé. Un lecteur y
  voit la liste et chaque dépôt, jamais un brouillon ni une version. L'identité et le statut d'un dépôt ne
  s'affichent qu'à qui détient `identityview`. Bouton sur le dossier et sur la liste des rubriques.
- **Le responsable est prévenu** (message `depositmade`) : le nom du candidat et le numéro du dépôt, **rien
  des rubriques**. Il l'est s'il suit le candidat **et** détient `tool/vae:readdeposit` ; les deux sont
  redemandés à l'envoi.

### Pas fait

- **Aucun avis du responsable** sur le dépôt : c'est le lot 5 de T-10. Il lit, il ne répond pas.
- La lecture d'un dépôt ne laisse pas de trace dans le journal (seul le dépôt en laisse une).

---

## [0.18.0] — 2026-10-15

T-10, lot 4b : l'archive d'export porte les rubriques de texte du dossier de faisabilité. Chaînes
nouvelles, **aucun changement de schéma**, paquet 5.3 seulement. La version de format reste `1`.

### Ajouté

- **`faisabilite.json`** dans l'archive, avec son schéma (`schema/faisabilite.schema.json`) et la
  mention `schema_faisabilite` au manifeste : les rubriques de **texte** seulement, chacune dans sa
  dernière version **enregistrée**, jamais le brouillon en cours. **Jamais l'identité, le statut ni les
  coordonnées** (MC-13) : la boucle de l'export ne lit que les rubriques que le catalogue dit de texte, et
  l'importeur refuse tout autre code. Le fichier est facultatif : une archive qui n'en a pas se réimporte
  comme avant, d'où la version de format inchangée.
- **Le PDF** : une section « Dossier de faisabilité » avant les déclarations, tirée du même tableau que
  le fichier JSON.
- **Le réimport** valide le fichier (schéma, puis catalogue : code de texte, emplacement existant, pas de
  doublon) et **restaure chaque rubrique absente en version 1**, avec la date de l'archive et la note
  « Restauré depuis l'archive <identifiant> ». **Une rubrique déjà écrite sur ce site n'est jamais
  écrasée** : le dossier vivant prime. Le message d'import dit combien de rubriques sont restaurées et
  combien sont laissées.
- Le schéma gagne les mots-clés `minimum` et `maximum` dans le validateur PHP (le schéma de la preuve
  n'en use pas).

### Pas fait

- **L'identité n'est pas dans l'archive** (décision, MC-13) : après un réimport sur un site vierge, le
  candidat la ressaisit.
- Le brouillon en cours n'est pas emporté.

---

## [0.17.0] — 2026-10-15

T-10, lot 4a : la copie Word/ODT porte les rubriques du dossier de faisabilité. Chaînes nouvelles,
**aucun changement de schéma**, paquet 5.3 seulement. L'archive d'export ne change pas (lot 4b).

### Modifié

- **La copie commence par le dossier de faisabilité**, dans l'ordre de l'annexe II : rubriques de texte
  (dernière version enregistrée, **jamais le brouillon en cours**, ce que la phrase d'en-tête dit), puis
  identité, coordonnées et statut, puis les déclarations et attestations comme avant.
- **Identité, coordonnées, statut** : écrits pour le candidat (page et copie hebdomadaire) et pour qui
  détient `tool/vae:identityview` (MC-11) ; **pour les autres, la copie les omet et le dit**. Les
  coordonnées et les prénoms sont lus du compte au moment de la copie.
- **Copie hebdomadaire** : l'empreinte couvre les rubriques et les valeurs lues du compte ; un profil
  modifié, comme une rubrique enregistrée, déclenche une nouvelle copie.

---

## [0.16.0] — 2026-10-15

T-10, lot 3 : les rubriques à champs du dossier de faisabilité. Une capability nouvelle ; **aucun
changement de schéma**. Paquet 5.3 seulement.

### Ajouté

- **Identité** : nom de naissance, date de naissance, nationalité (liste de pays), niveau de formation le
  plus élevé (2 à 8), niveau de la certification obtenue la plus élevée (3 à 8), intitulés des
  certifications. **Statut** : neuf choix de l'annexe II, plusieurs possibles, et l'identifiant de la
  convention collective. Les astérisques de l'annexe s'affichent ; **rien n'est refusé pour cause
  d'incomplétude**.
- **Stockage** : le même mécanisme que les récits (brouillon, version, historique, restauration), le
  contenu étant un objet JSON à clés fermées, **validé et mis en forme canonique** à l'enregistrement :
  mêmes valeurs, même texte, donc pas de version en double. Pas de brouillon automatique pour ces
  rubriques : le formulaire est court et s'enregistre d'un bloc.
- **Coordonnées** : lues du compte (courriel, adresse, ville, pays, téléphone), **aucune ligne, aucune
  version**, avec le lien vers le profil. Prénoms et nom d'usage de même, dans l'identité.
- **Capability `tool/vae:identityview`** (MC-11) : lire identité et statut d'un autre candidat, en plus de
  `draftview`, jamais à sa place. Aucun archétype.
- **Historique et comparaison** des rubriques à champs : présentés en lignes « libellé : valeur », que la
  comparaison mot à mot sait lire.

### Confidentialité

- Les descriptions de la Privacy API disent que les versions peuvent porter une date de naissance et une
  nationalité ; l'export, l'effacement, les sauvegardes et les copies les couvrent déjà.
- **L'archive d'export n'emporte pas ces données** (MC-13). Le genre n'est pas repris (MC-10).

### Limites

- Les listes ont été lues par un lecteur de page sur Légifrance, pas copiées du texte brut : **à comparer
  avec le Journal officiel** avant la production.
- Le compte Moodle n'a qu'un champ adresse : le code postal et le complément y sont écrits, l'aide le dit.
- Les valeurs lues du compte ne sont pas versionnées : un profil modifié montre la valeur du jour.
- Ces rubriques n'entrent pas encore dans la copie Word/ODT ni dans l'archive : lot 4.

---

## [0.15.2] — 2026-10-15

T-10, lot 2 : les rubriques de texte du dossier de faisabilité se présentent. Chaînes nouvelles, **aucun
changement de schéma**, paquet 5.3 seulement.

### Modifié

- **Rédiger** : le titre est le nom de la rubrique (« Expériences n° 2 »), et une phrase d'aide propre à la
  rubrique le suit (ce qu'on y met, jamais qui on accompagne). Libellé du champ et boutons inchangés.
- **Historique et comparaison** : une ligne dit de quelle rubrique il s'agit.
- **Liste** : une colonne « Dernière modification » (vide pour une rubrique jamais écrite).
- Le rappel d'anonymisation reste, non masquable, sur **toutes** les rubriques de texte (décidé le 15/10).

### Inchangé, volontairement

Pas de suppression d'une rubrique (D-10) ; pas de lien entre l'emplacement d'un bloc et le bloc de la
certification (les fiches importées sont plates, T-33) ; pas de champ obligatoire ni de contrôle de
complétude (le plugin ne juge pas, D-1). Les phrases d'aide sont celles du plugin, pas celles de l'arrêté.

---

## [0.15.1] — 2026-10-15

T-10, lot 1 : la liste des rubriques du dossier de faisabilité. Chaînes nouvelles, **aucun changement de
schéma**, paquet 5.3 seulement.

### Ajouté

- **Catalogue des rubriques** (`faisabilite_catalog`) : l'ordre et les noms de l'annexe II de l'arrêté du
  3 juillet 2025 (certification visée, prérequis, identité, coordonnées, situation et statut, objectifs,
  expériences, expériences rapportées à chaque bloc, parcours prévisionnel). Dix expériences et douze blocs
  au plus. Le gestionnaire de brouillons **refuse** une rubrique que le catalogue ne connaît pas.
- **Page « Dossier de faisabilité »** (`faisabilite.php`) : chaque rubrique, son état (non commencée,
  brouillon, version enregistrée, version et brouillon plus récent), ses liens (rédiger, historique) et,
  pour une rubrique répétée, l'ajout de la suivante. **Elle ne crée rien** et ne dit pas si le dossier
  suffit. Un bouton sur le dossier du candidat y mène : les écrans de T-81 ont enfin un menu.
- Les rubriques à champs (identité, coordonnées, statut) sont listées, « pas encore disponibles » : elles
  s'ouvrent au lot 3, et `section.php` les refuse d'ici là.

### Corrigé

- **`dossier.php` sans paramètre `userid`** : le défaut était la chaîne de l'identifiant de session, et la
  comparaison stricte prenait le candidat pour un tiers. Le bouton de déclaration, et le nouveau bouton,
  ne s'affichaient pas sur l'adresse sans paramètre. Trouvé en jouant la page sur l'instance d'essai ;
  même cast sur `export.php` et `document.php`.

---

## [0.15.0] — 2026-10-15

Copie modifiable du dossier, en Word et en OpenDocument (T-83, décision D-8). Une tâche planifiée et un
événement nouveaux ; **aucun changement de schéma**. **Paquet 5.3 seulement.**

### Ajouté

- **Téléchargement à la demande** : deux boutons sur la page du dossier, « Copie modifiable (Word,
  .docx) » et « (OpenDocument, .odt) ». Le contenu est celui du PDF de l'archive (déclarations,
  attestations, fiche de certification), tiré des mêmes données ; une phrase en tête dit que ce n'est pas
  l'archive. Même droit que l'archive. La langue est celle du compte du candidat.
- **Copie hebdomadaire**, désactivée par défaut (réglages « Écrire une copie hebdomadaire de chaque
  dossier » et « Format »). La nuit du dimanche au lundi, une copie datée dans les fichiers personnels du
  candidat, dossier `vae/copies`, seulement si le dossier a changé ; **les deux dernières sont gardées**.
  Chaque copie porte une marque (`tool_vae:copy:<ULID>:<empreinte>`) que ni un renommage ni un
  déplacement ne modifient : c'est par elle qu'une demande d'effacement les retrouve. La marque n'est pas
  celle des sauvegardes, pour que le rafraîchissement des sauvegardes ne les transforme pas en archives.
- **Événement** `dossier_document_issued` : nombre de déclarations, format, empreinte des données, copie
  à la demande ou planifiée ; jamais le texte.

### Confidentialité

- L'effacement du candidat supprime ses copies ; l'effacement d'un signataire les **rédige de nouveau**
  (même règle que les sauvegardes) ; la réconciliation quotidienne retire celles dont le candidat n'existe
  plus.
- Ce que le code ne règle pas, et que le site fixe avant d'activer la copie hebdomadaire : base légale,
  durée de conservation, registre des traitements (T-84).

### Limites

- Le dossier de faisabilité saisi (T-81, T-10) n'y figure pas encore : aucun menu n'y mène. La copie
  l'accueillera avec T-10.
- Non ouvert dans Word ni dans LibreOffice (absents du poste d'essai) ; relu par pandoc 3.9.

---

## [0.14.0] — 2026-10-15

Première pièce du dossier de faisabilité saisi dans le plugin (T-81, décisions D-7 à D-10) : des
**rubriques** que le candidat rédige, avec un brouillon, des versions et la comparaison de deux
versions. Deux tables nouvelles (étape 2026101404), **paquet 5.3 seulement**. **Aucun menu ne mène
encore à ces pages** : T-10 les reliera aux rubriques du dossier ; elles s'ouvrent par leur adresse.

### Ajouté — brouillon et versions d'une rubrique

- **Deux couches.** Le brouillon en cours est enregistré tout seul toutes les vingt secondes, quand le
  texte a changé ; une **version** n'est écrite que lorsque le candidat le demande. Une version n'est
  jamais modifiée : restaurer en crée une nouvelle, l'historique ne se rembobine pas.
- **Rien d'inutile.** Enregistrer deux fois le même texte ne crée pas de version (empreinte SHA-256, fins
  de ligne normalisées). Une page restée ouverte ne peut pas écraser le brouillon plus récent d'un autre
  onglet (refus 409 dit en une phrase).
- **Le candidat seul écrit** sa rubrique, y compris face à l'administrateur, qui détient toutes les
  capacités et n'écrit pas à sa place. Lecture : le candidat, l'administrateur, et qui détient
  `tool/vae:draftview`. Les responsables qui accompagnent ne voient rien avant le dépôt (T-21).
- **Droits nouveaux** : `tool/vae:draftown`, `tool/vae:draftview` (aucun par archétype).

### Ajouté — comparaison de deux versions

Mot à mot, sans dépendance (`text_diff`) : ajouts soulignés, suppressions barrées, **jamais la couleur
seule** (chaque marque a son texte pour un lecteur d'écran). Bornée : au-delà de quatre millions de
cases, par lignes, puis en bloc. Une phrase de synthèse en tête : « 7 mots ajoutés, 2 supprimés ».
Dans l'historique, le nombre de mots entrés et sortis est donné pour chaque version.

### Confidentialité

- **Privacy API** : les deux tables sont déclarées ; l'export d'une personne contient chaque version et le
  brouillon en cours ; l'effacement du candidat détruit rubriques et versions ; l'effacement d'une
  autre personne vide l'auteur des versions qu'elle a enregistrées et laisse le texte.
- **Journal** : deux événements, `draft_version_saved` et `draft_version_restored`, **identifiants
  seuls, jamais le texte**.
- **Réconciliation** : la tâche quotidienne retire aussi les rubriques dont le candidat n'existe plus,
  et les versions sans rubrique.
- **Archive d'export** : n'emporte pas l'identité (MC-13, décidé le 15/10) ; l'historique n'y entre pas.

### Limites connues

- **Aucun essai au lecteur d'écran** : les repères « ajouté » et « supprimé » sont dans le code, leur
  annonce n'a pas été entendue.
- **Sans JavaScript**, le brouillon n'est pas enregistré automatiquement ; le bouton reste.
- **Texte brut seulement**, 100 000 caractères au plus par rubrique.

## [0.13.2] — 2026-10-14

Cinq corrections issues de l'audit externe du 14 octobre (relues dans le code avant d'être retenues),
et un changement de règle de numérotation. Aucun changement de schéma pour un site déjà à la 0.13.1.

### Corrigé — deux attestations concurrentes (T-76)

`attest()` lisait l'attestation en vigueur puis insérait, sans verrou : deux responsables au même
instant pouvaient en créer deux sur la même déclaration, et l'archive exportée était alors refusée
à l'import (une seule attestation en vigueur par preuve). Un verrou par déclaration sérialise
l'opération et l'état est relu sous verrou, comme pour le retrait. Rejoué avec deux processus
simultanés : l'ancienne version produisait deux attestations 12 fois sur 12, la nouvelle 0 sur 6.

### Corrigé — l'export refuse ce que l'import refuserait (T-77)

L'import lit au plus 2 000 fichiers et 50 Mo ; l'export construisait n'importe quoi, et une archive
trop grosse sortait puis était refusée au retour. L'export lit maintenant les mêmes constantes et
refuse avant d'enregistrer ou d'écrire, en disant les chiffres et les limites. Un dossier plus gros
n'est pas découpé : il n'a pas d'export. La désinstallation et le rafraîchissement après un effacement
ne subissent pas la limite (ils ne doivent pas bloquer un retrait) : une copie trop grosse est gardée,
mais l'import ne la relira pas.

### Corrigé — les copies de sauvegarde ne se perdent plus au renommage (T-75)

Les copies étaient retrouvées par dossier et par nom ; leur détenteur peut renommer ou déplacer le
fichier dans ses fichiers personnels, et un effacement ne l'atteignait plus. Chaque copie porte
maintenant dans son `source` la marque `tool_vae:backup:<ULID du candidat>`, que ni un renommage ni
un déplacement ne modifient (essayé par le chemin du gestionnaire de fichiers). Les copies d'avant
la marque restent retrouvées par dossier et par nom. Reste hors de portée : une copie téléchargée ou
recopiée hors de Moodle.

### Ajouté — réconciliation des suppressions inachevées (T-78)

Tâche quotidienne `reconcile_orphans` : retire les déclarations dont la fiche du cœur n'existe plus
(un observateur ne peut pas rendre bruyant son échec), et les sauvegardes marquées dont le candidat
n'a plus d'identité (effacement interrompu avant la suppression des fichiers). Un événement par
déclaration retirée, une ligne dans le journal de cron. Pas d'alerte à l'administrateur : la trace est
l'événement.

### Modifié — numérotation du paquet 4.5 (T-80)

Une seconde étape de mise à niveau, idempotente, crée les deux champs de la demande de retrait pour
un site venu de la 4.5. Un numéro 4.5 n'a plus à rester sous 2026101311 : il doit rester **sous
2026101403**.

## [0.13.1] — 2026-10-14

Correction d'accessibilité, sans changement de schéma ni de chaîne de langue.

### Corrigé — le rappel d'anonymisation reste lu avec le champ en erreur (T-72)

Quand un champ libre revenait avec une erreur, le gabarit du cœur de Moodle écrivait
`aria-describedby="id_error_<champ>"` avant l'attribut du formulaire, et le navigateur ne retient que
le premier : le champ n'était plus décrit que par son message d'erreur, et le rappel n'était plus
annoncé avec lui par un lecteur d'écran, au moment précis où il compte le plus. Il restait affiché.
Les identifiants des rappels sont désormais fusionnés dans l'attribut après affichage, derrière le
message d'erreur, sur le formulaire de déclaration (détail du cadre, situation) et sur celui
d'attestation (énoncé). Constaté et corrigé sur Moodle 5.3 ; l'annonce elle-même n'a pas été
entendue à un lecteur d'écran. Sans JavaScript, le champ garde l'attribut du cœur : le rappel reste
visible, comme avant.

## [0.13.0] — 2026-10-14

Version de texte et de présentation, sans changement de schéma. Décidée avec le porteur le 14 octobre.

### Modifié — un seul mot pour l'acte du responsable : « attestation » (T-64)

L'écran disait « attestation » et « attester » d'un côté, « contreseing » et « contresigner » de
l'autre ; en anglais, « countersignature » partout. Le responsable **atteste** de ce qu'il a vu : il
ne double pas une signature. Le mot est maintenant « attestation » / « attester » (« attestation » /
« attest » en anglais) dans les écrans, les notifications, la déclaration de confidentialité, le
README et les commentaires du code. Les noms techniques (table `tool_vae_attest`, droit
`tool/vae:attest`, classes) ne changent pas : ils disaient déjà « attest ». Les entrées de ce
journal gardent le mot qu'elles employaient.

### Modifié — prénoms fictifs dans les récits (T-69)

Le rappel d'anonymisation dit désormais : ni nom, ni vrai prénom, ni numéro de chambre, ni détail
qui permettrait de reconnaître la personne ; si le récit doit la désigner, **un prénom fictif, sans
rapport avec le sien**. Le plugin ne peut pas savoir si un prénom est fictif : le contrôle reste la
relecture du responsable. Texte du rappel (entier et court), README (régime 2) et
DER-referentiel-001 mis à jour.

### Modifié — présentation

- **Sélecteurs de dates** (T-55) : de cinquante ans en arrière jusqu'à l'année en cours, et non plus
  de 1900 à 2050 (fiche source : depuis 1980).
- **« 1er septembre »** (T-57) : en français, le premier du mois prend « er » dans toutes les dates
  affichées et dans l'archive. Les autres langues sont laissées à leur paquet de langue.
- **Bouton après un retrait** (T-60) : une déclaration dont l'attestation a été retirée propose
  « Attester de nouveau », et non le même « Attester » qu'une déclaration jamais attestée.

### Outillage

- `docs/BANC-ESSAI.md` : le banc Moodle 5.3 sous Docker est documenté, et PHPUnit tourne en local
  (T-58).

---

## [0.12.5] — 2026-10-13

### Modifié — le rappel d'anonymisation est dit une fois, en entier, puis court près de chaque champ (T-54)

Constat de l'essai du 13 octobre : le rappel, long, était répété contre chaque champ libre du
formulaire de déclaration ; la candidate le lisait deux fois avant d'écrire un mot, et apprenait à
le sauter.

- **Une fois, en tête du formulaire** : le rappel en entier, dans un encadré qu'on ne peut ni replier
  ni fermer.
- **Une ligne contre chacun des deux champs libres** de la déclaration (le détail du cadre, le
  récit) : « ne nommez aucune personne accompagnée et ne donnez aucun détail qui la rende
  reconnaissable ». Chaque champ pointe, par `aria-describedby`, vers sa ligne puis vers le rappel
  entier.
- Le contreseing garde son rappel entier, une seule fois, contre son seul champ libre.
- **Ce qui ne change pas** : le texte du rappel complet, son caractère non masquable, sa présence
  contre chacun des trois champs libres — c'est ce sur quoi repose le régime 2.
- Aucun changement de schéma ni de comportement hors de ce formulaire.

---

## [0.12.4] — 2026-10-13

### Corrigé — l'archive est écrite dans la langue de la candidate (T-52)

Constat de l'essai du 13 octobre : le `dossier.pdf` prenait la langue de la personne qui exporte.
Un administrateur anglophone produisait un PDF en anglais pour une candidate française.

Décidé avec le porteur : le dossier est lu par un jury du pays où se trouve la candidate ; l'archive
parle donc **la langue du compte de la candidate**, et non celle de qui appuie sur le bouton.

- Tout ce que la plateforme écrit dans l'archive suit cette langue : l'avertissement du manifeste,
  `LISEZMOI.txt`, `dossier.pdf` et ses dates.
- Une langue que le site n'a pas donne la langue du site.
- La langue de la personne qui exporte lui est rendue avant la fin de l'export.
- Rien d'autre ne change : ni le schéma, ni le format de l'archive.
- **Limites connues** (liste de travail, T-71) : le plugin ne livre que l'anglais et le français, donc
  un compte dans une autre langue installée donne une archive en anglais, avec des dates dans cette
  langue ; les dates suivent le fuseau horaire de la personne qui exporte.

---

## [0.12.3] — 2026-10-13

### Modifié — chaque paquet refuse les versions de Moodle qu'il n'a pas testées

Constat : `$plugin->supported` n'est dans Moodle qu'une déclaration, un avertissement dans la page
des plugins ; il n'a jamais empêché d'installer le paquet sur une autre version. Seuls `requires`
(version minimale) et `incompatible` (première branche refusée) refusent.

- **`$plugin->incompatible = 504`** sur le paquet 5.3 : Moodle 5.4 et au-delà — versions
  intermédiaires — le refusent. Le paquet 4.5 déclare de même `incompatible = 500` (0.9.2).
- Les commentaires de `version.php` qui disaient « Moodle refuse lui-même l'installation » sont
  corrigés, ainsi que la durée du support de sécurité d'une version intermédiaire : dix-huit mois,
  et non dix à douze.
- Rien d'autre ne change : ni le schéma, ni le comportement du plugin.

---

## [0.12.2] — 2026-10-13

### Corrigé — la demande de retrait au regard du RGPD

Les deux champs ajoutés en 0.12.0 (`withdrawreqby`, `withdrawreqat`) nomment le responsable qui a
demandé le retrait. Ils sont maintenant couverts par le fournisseur de confidentialité :

- **trouvés** : le contexte du dossier est annoncé au responsable qui a demandé, et il figure dans la
  liste des personnes du contexte ;
- **exportés** : sa demande (quelle déclaration, quelle fiche, quand) et rien du récit de la
  candidate ni du signataire ; la candidate et le signataire apprennent qu'un retrait a été demandé,
  et quand, jamais par qui ;
- **effacés** : sa demande part avec lui, le contreseing reste ; l'effacement du signataire emporte
  aussi la demande portée par sa signature.

Trois points relevés par une relecture contradictoire, corrigés :

- **le journal** : les deux événements désignaient la candidate comme « utilisateur concerné », ce
  qui faisait remonter le nom du demandeur dans l'export du journal de la candidate. Le signataire
  (ou le demandeur, pour un refus) est désormais l'utilisateur concerné ; la candidate est dans `other` ;
- **la file d'attente** : un message en attente après un refus pouvait être délivré sous la demande
  suivante. Il est lié à la demande qui l'a produit (qui, et quand) ;
- **la déclaration des notifications** : elle omettait les deux nouveaux messages et le fait que
  deux d'entre eux nomment un responsable.

---

## [0.12.1] — 2026-10-13

### Corrigé — une déclaration n'est plus coupée entre deux pages du PDF (T-50)

Trouvé en regardant un vrai PDF : un titre et trois lignes en bas d'une page, la suite en haut de la
suivante. Chaque déclaration est désormais écrite d'un seul tenant : si elle ne tient pas dans ce
qui reste de la page, elle passe en haut de la suivante, avec le titre de la fiche si elle est la
première de cette fiche. Une déclaration plus longue qu'une page s'écoule comme avant.

### Modifié — la page du dossier affiche l'intitulé de la compétence (T-56, T-59)

Le code seul s'affichait chez la candidate et chez le responsable, alors que le PDF donnait
l'intitulé. « Compétence visée » est ajouté sous le code de la fiche.

---

## [0.12.0] — 2026-10-13

### Ajouté — le retrait d'un contreseing n'est plus l'affaire du seul administrateur

La règle du 12 octobre (« l'administrateur du site, et personne d'autre ») obligeait à le déranger
pour chaque contreseing donné par erreur. Trois ouvertures, aucune par défaut :

- **Réglage** « Les responsables retirent leurs propres contreseings » : le signataire retire ce
  qu'il a signé lui-même. Désactivé par défaut.
- **Droit `tool/vae:withdrawany`** : retirer n'importe quel contreseing d'un dossier que l'on peut
  lire. Attribué par l'administrateur à un rôle ou à une personne ; personne ne l'a par défaut.
- **Demande au signataire** : un responsable ne retire jamais le contreseing d'un collègue, il le
  lui **demande** ; le signataire l'accepte (c'est un retrait, avec son motif) ou la refuse par un
  bouton, et le demandeur en est prévenu. Deux champs sur la contresignature, effacés dès la réponse.

**La candidate est prévenue** du retrait, et du nom des responsables qui peuvent contresigner de
nouveau ; ces responsables sont prévenus à leur tour que la déclaration les attend.

---

## [0.11.3] — 2026-10-13

### Modifié — le formulaire de déclaration ne demande plus le format du récit (T-53)

Le choix « Texte brut / Markdown » était du jargon pour une candidate. Le récit est désormais
toujours en **texte brut**, sans choix à faire. Le Markdown reste accepté pour ce qu'apporte un
import ; le HTML reste exclu à la colonne. Trois chaînes de langue inutilisées sont retirées
(`situationformat`, `formatplain`, `formatmarkdown`).

---

## [0.11.2] — 2026-10-13

### Corrigé — le pied de page du PDF, trouvé en regardant un vrai PDF (T-02)

Premier essai du plugin sur un vrai Moodle 5.3 (installé le 13 octobre 2026) : le `dossier.pdf`
était lisible — accents, listes, polices intégrées —, mais :

- **le pied de page s'écrivait en haut d'une page nouvelle** : écrit à 25 mm du bas alors que
  `setPage()` rétablit pour chaque page un seuil de saut à 30 mm, il ouvrait une page, s'y plaçait
  et laissait une **dernière page blanche** ;
- **la numérotation était fausse** : « page 2 sur 2 » sur la troisième page d'un document ;
- **sur une page se terminant par un libellé, le pied de page sortait en gras, trop grand**, et
  débordait à droite : `setPage()` rétablit aussi la police de fin de page.

Correction dans `pdf_renderer::render()` : sauts de page désactivés et police fixée **après**
chaque `setPage()`. Le rendu lève désormais une `coding_exception` si le pied de page ajoute une
page, au lieu de laisser un PDF faux dans l'archive.

### Pourquoi les tests ne l'avaient pas vu

Le test existant ne lisait que les cinq premiers octets (`%PDF-`). Un test compte maintenant les
pages : une déclaration, une page. Le rendu visuel n'est pas testable ainsi ; il se regarde.

### Constaté, non corrigé

- **Une déclaration peut être coupée entre deux pages** : son intitulé et trois lignes en bas de
  page, la suite en haut de la suivante. À traiter (garder chaque déclaration d'un seul tenant).
- « du 1 septembre 2025 » : le français écrit « 1er septembre ». Formatage de date du cœur.
- Le PDF suit la langue de **celui qui exporte** : anglais pour un administrateur anglophone,
  français pour un candidat francophone. À décider si un jury doit toujours lire du français.

---

## [0.11.1] — 2026-10-13

### Modifié — le nom affiché du plugin

- **`pluginname`** : « VAE - cheminement » en français, « VAE - pathway » en anglais. Il remplace
  « Référentiel de compétences pour la VAE » et « Skills record for accreditation of prior
  learning », dont le premier mot prêtait à confusion avec les cadres de compétences du cœur de
  Moodle. Le mot « cheminement » dit ce que le plugin accompagne : un parcours, dont le dossier
  n'est que le produit final.
- Le nom technique reste `tool_vae`. Le mot « Moodle » n'est pas dans le nom (marque déposée).
- Le test Behat de navigation et le titre du README suivent.

---

## [0.11.0] — 2026-10-13

### Modifié — le composant devient `tool_vae` (liste de travail T-01)

- **Nom technique `tool_referentiel` → `tool_vae`**, décidé par le porteur du projet le 13 octobre
  2026 : le mot « référentiel » désigne déjà les cadres de compétences du cœur de Moodle, et le
  plugin sert la VAE. Aucune installation n'existait : il n'y a donc **aucune migration de
  données**. Un site qui aurait installé l'ancien composant verrait un autre plugin.
- **Ce qui change de nom** : le composant et ses espaces de noms (`tool_vae\…`), les 7 tables
  (`tool_vae_*`), les capabilities (`tool/vae:*`) et leurs chaînes de langue (`vae:*`), le dossier `admin/tool/vae`, les fichiers de
  langue (`tool_vae.php`), les pages d'administration (`toolvae*`), les classes CSS (`tool-vae-*`),
  le dossier des sauvegardes dans les fichiers privés de l'administrateur (`/vae/`).
- **Ce qui ne change pas** : le format de l'archive (`manifeste.json`, `preuves/`,
  `referentiel.json`, `dossier.pdf`), car `referentiel.json` décrit bien le référentiel de
  certification ; le nom affiché du plugin (`pluginname`), à décider à part ; le nom du dépôt
  GitHub, encore `moodle-tool_referentiel` ; les noms historiques (`mod_referentiel`,
  `DER-referentiel-001`), qui désignent des objets passés.
- Les entrées plus anciennes de ce journal gardent l'ancien nom : elles disent ce qui s'est
  passé sous ce nom.
- **Vérifié le 13 octobre 2026** avec PHP 8.3.35 : syntaxe des 87 fichiers PHP, contrôle de langue
  (9 capabilities, 2 langues), couverture RGPD (7 tables), MC-2, MC-5, recherche de secrets,
  `doctor.sh`. **Non exécuté** : PHPUnit, Behat, `codestyle` et le schéma pivot, qui exigent un
  Moodle installé ou des modules absents de ce poste. La CI doit les confirmer avant toute
  fusion.

---

## [0.10.1] — 2026-10-12

### Corrections — lot 8 quinquies : les petits points laissés ouverts par la revue

- **Un seul refus d'attester, quelle qu'en soit la cause** (`errorattestrefused`) : pas de droit
  d'attester, dossier que l'on ne peut pas lire, ou déclaration qui n'existe pas. L'ancien refus
  citait la capability « Countersign a declaration made by a candidate » et trompait un responsable
  qui la détenait sans pouvoir lire le dossier. Le refus ne porte aucun lien vers le profil du
  candidat, et l'API applique la même règle que les pages (pas d'énumération des déclarations).
  `errorattestexists` ne demande plus au responsable de retirer lui-même un doublon : c'est
  l'administrateur du site qui retire.
- **Registre des archives** : supprimer le compte d'un candidat supprime aussi ses lignes du
  registre, comme l'effacement RGPD le faisait déjà. Une copie détenue ailleurs se réimporte alors
  comme « étrangère ».
- Commentaires de `attest.php` et d'`attestation_manager` alignés sur la règle du 12 octobre :
  seul l'administrateur du site retire un contreseing.
- **Tâche `migration` de la CI, à l'octet près** (`scripts/migration/verify.php`) : les
  fichiers `preuves/*.json` produits sous 5.3 sont comparés octet par octet à ceux de l'export
  pris sous 4.5 (et non plus des tableaux décodés comparés avec `!=`) ; la **sauvegarde** prise
  sous 4.5 est relue puis réimportée sur un compte neuf, en plus de l'archive exportée. L'export
  se réimporte sur le compte d'origine (déclarations retirées, personne gardée) et doit être
  reconnu par le registre (`foreign` = 0) : le registre traverse la mise à jour. La sauvegarde
  se réimporte après effacement du premier compte et doit se lire comme étrangère, puisque ses
  lignes du registre sont parties avec lui. Hors comparaison, et dit dans le script : le manifeste, le
  LISEZMOI, `dossier.pdf`, et `correspondances[]` après un réimport sur un autre compte.

---

## [0.10.0] — 2026-10-12

### Ajouté — version imprimable du dossier (R4), décisions du porteur du projet du 12 octobre 2026

- **`dossier.pdf` dans chaque archive**, à l'export comme à la sauvegarde : le dossier mis en page
  pour le jury. Il est **listé dans `manifeste.json`** avec sa taille et son empreinte SHA-256,
  comme tout autre fichier ; il est donc couvert par les empreintes et par le registre des
  archives délivrées.
- Ce que le PDF montre, tel qu'un jury le lit : en tête, le nom du candidat, l'identifiant de
  l'archive, la date et l'heure de l'export, la plateforme émettrice, la version du plugin et
  l'avertissement sur les retraits ultérieurs ; puis, par fiche, son code, son type et la ligne
  de provenance exigée par la Licence Ouverte 2.0 ; pour chaque déclaration, la compétence (rang
  et intitulé), la version de la fiche, la date de déclaration, la période, le cadre et sa
  précision, le récit, et les attestations. En pied de chaque page : « page x sur y » et la phrase
  qui renvoie à la plateforme émettrice, avec l'identifiant de l'archive, pour confirmer
  l'authenticité de la copie.
- **Ce qu'il ne montre pas** : ni tableau de couverture (R3 reste au seul candidat), ni décompte,
  ni pourcentage, ni jugement. D'une attestation retirée, **une date et un motif**, rien de
  nominatif : exactement ce que porte le fichier de preuve (MC-9), et rien de plus, puisque rien
  de plus ne parvient au rendu.
- **Mêmes données que l'archive** : `classes/local/pdf_renderer.php` est appelé par l'exporteur
  avec les tableaux mêmes qu'il encode en JSON, avant le calcul des empreintes ; aucune requête
  propre. `pdf_renderer::html()` ne prend que le manifeste, les preuves et `referentiel.json` : le
  PDF se redessine depuis une archive décodée, et les tests le font.
- Récit rendu par `format_text()` dans son format (texte ou Markdown), puis réduit au balisage de
  texte, sans attribut : ni image ni lien, que TCPDF irait chercher en ligne. Police `freesans`
  du cœur, accents compris. Neuf chaînes `exportpdf*` (en, fr) ; la notice `LISEZMOI.txt`
  mentionne le PDF.
- **Réimport** : `dossier.pdf` est vérifié contre son empreinte comme tout fichier listé — altéré,
  il fait refuser l'archive — et n'est jamais lu comme donnée. Une archive sans PDF (0.8.x, 0.9.0,
  paquet 4.5) se réimporte toujours : `version_format` reste à 1, aucun fichier lu par le réimport
  n'a changé.
- Tests : 4 dans `tests/exporter_test.php` (PDF listé avec son empreinte et commençant par `%PDF`,
  contenu attendu sans couverture, attestation retirée sans signataire, balisage du récit), 3 dans
  `tests/dossier_importer_test.php` (PDF accepté et non importé, PDF altéré refusé, archive sans
  PDF réimportée).
- Version 2026101304, sans changement de schéma.

### Limite connue

- **Le PDF n'est pas reproductible à l'octet.** Ses dates de création et de modification sont
  celles de l'export, mais TCPDF tire au hasard l'identifiant de chaque document : deux rendus du
  même dossier n'ont pas la même empreinte. L'empreinte de `dossier.pdf` prouve qu'une copie est
  celle que porte l'archive, pas qu'on peut la reproduire.

---

## [0.9.0] — 2026-10-12

### Ajouté — lot 9, couverture sans jugement (R3), décisions du porteur du projet du 12 octobre 2026

- **Tableau de couverture** sur le dossier, **pour le candidat seul et sur son propre dossier** :
  ni le responsable qui le suit, ni la direction, ni l'administrateur qui lit le dossier d'un
  autre ne le voient. Lus par un tiers, les mêmes chiffres deviendraient une jauge sur une
  personne.
- Un tableau par fiche sur laquelle le candidat a déclaré au moins une fois (code, type,
  provenance au titre de la Licence Ouverte 2.0), une ligne par compétence de la fiche, **y
  compris celles sans aucune déclaration** — c'est l'information utile. Colonnes : compétence,
  nombre de déclarations, nombre d'attestations **en vigueur** (une attestation retirée ne compte
  pas), date de la dernière attestation en vigueur.
- **Rien qui juge** : ni pourcentage, ni total, ni seuil, ni « validable », ni couleur, ni poids.
  Une compétence sortie de la fiche qui porte des déclarations reste listée, avec la mention
  qu'elle n'y figure plus ; sortie sans déclaration, elle disparaît du tableau.
- Calcul dans `classes/local/coverage.php` : une requête pour tous les décomptes, une lecture des
  compétences par fiche via la passerelle (MC-5), jamais une par compétence. Rendu dans
  `presenter::coverage()` : légende de tableau, compétence en en-tête de ligne. La ligne de
  provenance est désormais partagée avec l'écran des fiches.
- Tests : `tests/coverage_test.php` (6 tests), `tests/behat/coverage.feature` (1 scénario).
- Version 2026101303, sans changement de schéma.

---

## [0.8.4] — 2026-10-12

### Sécurité et corrections — lot 8 quater, décisions du porteur du projet du 12 octobre 2026

- **Registre des archives délivrées** (constat C1 de la revue). Nouvelle table
  `tool_referentiel_archive` (étape 2026101203) : chaque export et chaque sauvegarde y inscrit
  l'ULID de l'archive et l'empreinte SHA-256 de son `manifeste.json`. Au réimport :
  - archive de cette plateforme, manifeste identique : acceptée, vérifiée ;
  - archive de cette plateforme dont le manifeste a changé : **refusée en entier**
    (`errorarchivenotasissued`) — c'est l'attaque « preuve modifiée, empreinte recalculée » qui
    passait jusqu'ici ;
  - archive inconnue du registre (autre plateforme, ou ULID changé) : acceptée avec un
    **avertissement** écrit à l'administrateur et `foreign` = 1 dans l'événement.
  La page d'export affiche l'ULID et l'empreinte ; le LISEZMOI explique comment faire confirmer une
  copie par la plateforme. Les textes ne promettent plus que les empreintes prouvent la provenance.
  **Limites** : un faussaire qui change l'ULID rend l'archive « étrangère » (il n'échappe pas à
  l'avertissement) ; les archives produites avant cette version, et les sauvegardes réimportées
  après une réinstallation, passent pour étrangères.
- **Retrait d'un contreseing réservé à l'administrateur du site**, pour toute attestation
  (`errorwithdrawadmin`, remplace `errorwithdrawimported`). Le retrait est atomique (verrou et
  mise à jour conditionnelle) : un seul événement, une seule notification. **Il refait les
  sauvegardes** du dossier ; les exports déjà remis ne changent pas (MC-9).
- **RGPD** : avant de supprimer la ligne d'identité d'un compte (suppression ou effacement), la
  purge anonymise les attestations reprises d'une archive qui le nomment par son ULID, puis refait
  les sauvegardes concernées. Asymétrie voulue : un responsable supprimé garde ses signatures
  données ici (décision du 28 août 2026), pas celles qu'on ne pourrait plus lui rattacher.
- **Import de fiche** : une **description** republiée par la source est reprise (elle était
  ignorée tant que l'intitulé ne changeait pas) ; modifiée localement, elle compte comme
  divergence et le texte publié est rétabli.
- **Échelle** : un référentiel créé par l'import prend l'**échelle de compétence par défaut** du
  cœur, et non l'échelle n° 1 du site (« Separate and Connected ways of knowing »). Les référentiels
  déjà créés ne sont pas migrés.
- **Notifications** : une compétence partie, revenue puis repartie pendant le délai de grâce
  n'est annoncée qu'une fois.
- **MC-2** : exception nommée (`relabel_evidence()`, désinstallation seule) inscrite dans
  DER-referentiel-001, et contrôle automatique `scripts/mc2-ecriture-coeur.sh` en CI.
- **Limites écrites** : les pièces jointes ajoutées dans les plans d'apprentissage ne sont pas
  emportées (MC-1) ; après désinstallation, plus rien n'efface une sauvegarde (texte de la page).

---

## [0.8.3] — 2026-10-12

### Sécurité — lot 8 ter : corrections de la revue complète du 12 octobre 2026

Revue de tout le code par trois relecteurs indépendants, puis corrections relues une à une en
revue adverse. Corrections seules : aucun changement de schéma ni de capability.

- **Attester exige de lire le dossier** (`access::can_attest`). Un rôle portant `attest` au niveau
  système, sans `viewany` ni suivi du candidat, ouvrait `attest.php` et `withdraw.php` par
  l'adresse : il lisait le récit de n'importe quel candidat, contresignait et retirait toute
  signature. `can_view()` prend un lecteur facultatif ; le notifier évalue le destinataire.
- **Plus d'oracle d'existence** : un identifiant de compte, de déclaration ou d'attestation qui
  n'existe pas reçoit le même refus qu'un dossier étranger (`access`, pages, réimport). Le refus
  d'attester porte le contexte système, sans lien vers le profil du candidat.
- **RGPD** : effacer un attestant le retire aussi des **sauvegardes** (`backup::refresh()`), et
  le retrouve sous son ULID quand il a signé une attestation reprise d'une archive. L'administrateur
  qui a réimporté un dossier (`usermodified` des preuves) est retrouvé et effacé. Les sauvegardes
  ne sont refaites que si la personne effacée a signé dans le dossier.
- **Réimport d'une archive** : une preuve ne porte qu'une attestation en vigueur, et jamais celle
  du candidat lui-même (`errorarchiveattestmany`, `errorarchiveattestown`). Le ZIP n'est plus
  extrait sur disque : chaque entrée est lue par `ZipArchive` avec sa taille déclarée pour plafond,
  et une taille négative (Zip64) est refusée.
- **Motifs** : `^…$` acceptait un retour à la ligne final en PHP (modificateur `D` ajouté au
  validateur et à l'ULID) ; 36 contre-exemples au lieu de 35.
- **XSS** : une cellule du CSV d'import de fiche n'entre plus brute dans un message d'erreur.
- **`backup.php`** : dossier vide ou compétences désactivées donnent un message, pas une exception.
- **Tests** : PHPUnit sur chacun de ces points (archives piégées, panne au milieu du réimport,
  attest au niveau système) ; **`access_control.feature`**, cinq scénarios Behat qui ouvrent les
  pages par l'adresse et lisent le refus.

### Reste ouvert (lot 8 quater)

- Une sauvegarde prise avant un **retrait** garde la signature retirée ; à trancher avec MC-9.
- Si la ligne d'identité d'un signataire est supprimée avant son effacement (suppression du
  compte), ses attestations reprises sous ULID ne lui sont plus rattachables.
- L'archive reste vérifiable contre la corruption, pas contre une falsification (registre prévu).
## [0.8.2 pour Moodle 5.3] — 2026-10-12

### Ajouté — deux paquets, et le passage de l'un à l'autre prouvé

Décidé avec le porteur du projet : un paquet par LTS, à condition de passer de l'un à l'autre
sans erreur. Règles et marche à suivre : `docs/BRANCHES.md`.

- **Branche `MOODLE_405`** : le paquet pour Moodle 4.5, figé en 0.8.2 (version 2026101201),
  maintenu jusqu'au 4 octobre 2027, corrections seulement, numéros 2026101201 à 2026101299.
- **Branche `MOODLE_503`** (suivie par `main`) : le paquet pour Moodle 5.3, mêmes fonctions,
  version 2026101300, `requires` 5.3.0, `supported = [503, 503]`.
- **Tâche `migration` de la CI**, à chaque poussée : un vrai Moodle 4.5 avec le paquet 4.5, des
  dossiers créés (déclarations, attestation, attestation retirée), un export et une sauvegarde ;
  puis Moodle 5.3 avec le paquet 5.3 et la mise à jour. Vérifié : le numéro du plugin monte, les
  preuves sont identiques à l'octet près, la sauvegarde prise sous 4.5 est là, et l'archive
  exportée sous 4.5 se réimporte sous 5.3. Premier passage vert le 12 octobre 2026.
- CI 5.3 : 193 tests PHPUnit (PHPUnit 11) sur PostgreSQL 17 et MariaDB 11.4, Behat 5/5.

### Modifié (branche 5.3)

- Les tests déclarent ce qu'ils couvrent par des attributs PHPUnit 11 (`CoversClass`) au lieu des
  annotations `@covers`, que PHPUnit 11 signale comme obsolètes (16 avertissements levés).
- CI : PostgreSQL 17 et MariaDB 11.4, minimums de Moodle 5.3 ; contrôle de santé de MariaDB par
  `healthcheck.sh` (l'image 11.4 n'a plus `mysqladmin`).

### À savoir avant de passer en 5.3

- Moodle 5.3 exige **PHP 8.3, PostgreSQL 17 ou MariaDB 11.4** au minimum.
- Le plugin se range désormais sous `public/admin/tool/referentiel` (organisation de Moodle
  depuis la 5.1).

---

## [0.8.2] — 2026-10-12

### Ajouté — lot 8 bis : sauvegardes sur Moodle et fiches rattachées aux cours

Demandé par le porteur du projet le 12 octobre 2026, avec ses choix.

- **Sauvegarde permanente d'un dossier** (`classes/local/backup.php`, page `backup.php`) : un
  bouton « Sauvegarder sur Moodle » sur le dossier range l'archive normative dans les **fichiers
  personnels de l'administrateur**, dossier `referentiel`, sans limite de durée. Ce fichier
  survit à la désinstallation du plugin et se réimporte tel quel avec « Réimporter une archive »
  (le sélecteur de fichiers le propose). Fichier nommé `dossier-<ULID du candidat>-<ULID de
  l'archive>.zip`.
- **Sauvegarde automatique avant désinstallation** : `db/uninstall.php` sauvegarde chaque dossier
  avant toute autre opération, dans les fichiers personnels de l'administrateur qui désinstalle
  (ou de l'administrateur principal en ligne de commande). Une sauvegarde qui échoue arrête la
  désinstallation tant que les tables existent encore : rien n'est perdu. **La limite de la 0.8.1
  « désinstaller perd les dossiers » est levée.**
- **RGPD** : une demande d'effacement supprime aussi les sauvegardes du candidat, où qu'elles
  soient rangées (`purge::dossier()`), et la déclaration des fichiers le dit.
- **Rattacher une fiche RNCP à un cours** (`classes/local/course_linker.php`, page
  `course_link.php`, lien dans la navigation du cours) : une fois la fiche importée par
  l'administrateur, la direction et les gestionnaires ajoutent **toute la fiche** aux
  compétences du cours, en un clic. Rien de nouveau n'est stocké : ce sont les « compétences du
  cours » de Moodle. Une compétence déjà présente est laissée telle quelle ; une compétence que la
  source ne porte plus n'est pas ajoutée ; tout ou rien, en une transaction.
- **Nouvelle capability `tool/referentiel:linkcourse`** (niveau cours) : accordée au gestionnaire
  par défaut, à attribuer au rôle de la direction. Le droit du cœur
  `moodle/competency:coursecompetencymanage` est demandé aussi, avant toute écriture. Un
  enseignant ne rattache pas de fiche.

---

## [0.8.1] — 2026-10-12

### Ajouté — lot 8, seconde moitié : le réimport

**Critère 6, redéfini avec le porteur du projet le 12 octobre 2026** (le libellé d'origine est perdu
avec le PRP) : une archive exportée puis réimportée redonne les mêmes preuves, avec les mêmes ULID,
après vérification de ses empreintes SHA-256 ; une archive altérée est refusée en entier.

- **`classes/local/dossier_importer.php`** — tout est vérifié avant toute écriture, puis tout est
  écrit en une transaction :
  - le manifeste : format, version de format et version du schéma que cette version sait lire ;
  - chaque fichier contre son empreinte et sa taille ; un fichier absent, ou présent sans figurer
    au manifeste, fait refuser l'archive comme une empreinte fausse ;
  - chaque preuve contre le schéma, puis ce que le schéma ne dit pas (fin de période après le
    début, preuve rangée sous son propre ULID, candidat unique) ;
  - la liste du ZIP est jugée avant l'extraction : 2 000 fichiers et 50 Mo au plus, chemins sans
    `..`, aucun chemin que la bibliothèque ZIP aurait dû nettoyer.
- **`classes/local/schema_validator.php`** — Moodle n'a pas de validateur JSON Schema. Celui-ci
  **lit** `docs/schema` au lieu d'en recopier les règles, n'implémente que les mots-clés que le
  schéma emploie, et lève une exception sur tout autre. Le test fait passer les 35 contre-exemples
  du schéma : il refuse ce qu'ajv refuse en CI.
- **Décidé avec le porteur du projet** :
  - celui qui importe choisit le compte de destination (le bouton est sur le dossier de ce
    compte) ; l'ULID du candidat dans l'archive est donné à ce compte, et un compte déjà connu sous
    un autre ULID fait refuser l'archive ;
  - la fiche RNCP doit avoir été importée avant ; une seule compétence absente fait refuser
    l'archive. La compétence est retrouvée par son code et son rang, publics ;
  - un identifiant déjà présent sur le site fait refuser l'archive entière : rien n'est fusionné ;
  - **les attestations reprises ne sont rattachées à aucun compte** (le signataire est désigné par
    l'ULID de l'archive, nouvelle colonne `attestantuuid`), elles disent sur le dossier qu'elles ont
    été reprises (`importedat`), et **seul l'administrateur du site peut les retirer**. Un
    responsable qui suit le candidat ne voit pas le bouton, et `withdraw()` le refuse.
- **Nouvelle capability `tool/referentiel:importdossier`**, attribuée à la main seulement : par
  défaut, l'administrateur du site seul. La page demande aussi le droit du cœur d'écrire des
  preuves pour autrui (`moodle/competency:userevidencemanage`), **avant** de lire l'archive.
- **Identifiants ULID seulement.** Le schéma admet aussi l'UUID v4, que ce plugin ne produit
  jamais et que ses colonnes ne peuvent pas contenir : une archive qui en porte est refusée en le
  nommant.
- Nouvel événement `dossier_imported` (des comptes et l'ULID de l'archive, jamais de contenu).

### Modifié

- **Importer une fiche RNCP est réservé à l'administrateur du site** (décision du porteur du
  projet, 12 octobre 2026) : le rôle gestionnaire perd `tool/referentiel:import`, y compris sur un
  site déjà installé (étape d'upgrade).
- La réexportation d'un dossier réimporté écrit le signataire repris sous son ULID d'origine, sans
  correspondance Moodle.

### Limites, dites

- **Désinstaller le plugin perd les dossiers.** Une réinstallation ne les retrouve pas : il ne reste
  dans le cœur qu'une preuve renommée à la description vide. Exporter chaque dossier avant de
  désinstaller, puis réimporter, est la seule voie.
- Une compétence déjà connue sur le site sous un autre ULID (site qui a créé ses propres dossiers
  avant de recevoir des archives d'ailleurs) fait refuser l'archive. Sur une instance vierge, cas
  visé par ce lot, la question ne se pose pas.
- **À venir, demandé le 12 octobre 2026** : une fois importées, les fiches RNCP pourront être
  choisies et rattachées à un cours par la direction et le gestionnaire.

---

## [0.8.0] — 2026-10-11

### Ajouté — lot 8 : l'export normatif, version figée du dossier

La contrepartie de la dérogation `DER-referentiel-001` (MC-1), encadrée comme MC-9 l'exige. Reste
le réimport, qui fera la seconde moitié du lot.

- **`classes/local/exporter.php`** — produit une archive ZIP autoportante :
  - `manifeste.json` : l'inventaire de l'archive, avec l'empreinte SHA-256 de chaque fichier ;
  - `preuves/<ULID>.json` : une preuve par fichier, conforme au schéma reconstruit ;
  - `referentiel.json` : les fiches, avec l'intitulé des compétences visées, la licence et
    l'attribution ;
  - `schema/` : une copie du schéma ;
  - `LISEZMOI.txt` : la notice pour une personne.

  Aucun identifiant Moodle hors de `correspondances[]`. Une attestation retirée n'y porte qu'une
  date et un motif.
- **Qui exporte** (décidé avec le porteur du projet) : le candidat exporte son propre dossier, et
  la direction (`viewany`) celui de n'importe qui. Un responsable qui suit un candidat n'exporte
  pas, même s'il détient la capability. Nouvelle capability `tool/referentiel:export`, déclarée
  avec le code qui la lit.
- **Encadrement (MC-9)**, sur le modèle de l'export RGPD du cœur (`tool_dataprivacy`) :
  - l'archive est rangée dans l'aire `export` du contexte utilisateur, jamais dans
    `$CFG->tempdir` ;
  - elle est servie par `tool_referentiel_pluginfile()` à son seul auteur, pendant 24 heures, et
    tant qu'il a encore le droit d'exporter ;
  - la tâche `purge_exports`, horaire, supprime les archives expirées ;
  - chaque export est journalisé par l'événement `dossier_exported` ;
  - la page de confirmation, le manifeste et la notice disent que retirer une attestation après
    coup est sans effet sur l'archive et sur ses copies.
- **RGPD** :
  - l'archive en attente est déclarée (`core_files`), remise au candidat dans son export de
    données, et supprimée avec son dossier comme au retrait d'un signataire ;
  - l'identifiant opaque d'un signataire, créé à l'export, est retrouvable, exporté et effacé.
- **`tests/exporter_test.php`** — 21 tests. En CI, les preuves qu'ils produisent sont en plus
  validées par ajv contre le schéma lui-même : `scripts/verifier-schema.mjs --preuves`, qui
  échoue si le dossier est vide.

### Ajouté — lot 8, première étape : le schéma pivot, reconstruit

`preuve.schema.json`, que l'export normatif doit respecter, est introuvable : ni sur claude.ai, ni
sur GitHub, ni sur le disque, ni dans l'historique git ou les sessions de travail. Seule piste
restante, le NAS du bureau. Le lot 8 ne pouvait pas l'attendre : le schéma est **reconstruit à
partir de ce que le code en savait**, et le dit.

- `docs/schema/preuve.schema.json` et `docs/schema/commun/identifiant.schema.json` — JSON Schema
  2020-12, version `0.1.0-reconstruit`. Chaque champ porte sa source : CODE, DÉDUIT ou INVENTÉ.
- `docs/schema/exemples/` — deux preuves valides, données fictives, récit sans personne nommée.
- `docs/schema/tests/contre-exemples.json` — 35 contre-exemples, chacun une seule modification de
  l'exemple complet, donc refusé pour la seule raison qu'il nomme ; huit portent sur
  l'attestation retirée.
- `scripts/verifier-schema.mjs`, `make schema` et une étape de la CI — ajv 8 en mode strict, plus
  la règle que le schéma ne sait pas dire : la fin d'une période ne précède pas son début.
- `docs/schema/LISEZMOI.md` — la table de traçabilité, et la règle : si l'original revient, il
  prime, et chaque écart est tranché explicitement.

Contre-vérifié : dans une copie où la règle de l'attestation retirée est relâchée, le contrôle
échoue sur six contre-exemples.

Le schéma seul ne change aucune ligne de code exécuté : il est arrivé dans un commit à part
(`2cab1b4`), avant l'export qui s'y conforme.

### Décidé en reconstruisant — à confronter à l'original s'il revient

- **Horodatages complets en UTC partout, période comprise** : un réimport sans perte, et aucune
  dérive d'un jour selon le fuseau, défaut déjà mesuré sur `corelabel`.
- **Une attestation retirée ne porte pas d'identifiant** : un ULID contient l'heure de sa
  création, c'est-à-dire de la signature, que le retrait efface.
- **Valeurs de `correspondances[]` en chaîne** : un entier sur 64 bits ne survit pas à tous les
  lecteurs JSON.
- **UUID v1 refusé** : il contient une adresse matérielle et une heure.

---

## [0.7.1] — 2026-10-11

### Ajouté — lot 7 ter : prévenir les bonnes personnes, sans rien dire du récit

Reprise R2 de l'inventaire, la seconde condition posée avant toute utilisation réelle. Jusqu'ici,
personne n'était prévenu de rien : un candidat déclarait, son responsable n'en savait rien ; une
compétence quittait la fiche, le candidat qui avait déclaré dessus non plus.

Quatre messages, par la Message API de Moodle — notification et courriel, activés par défaut,
chacun réglable dans ses préférences :

| Message | Destinataire | Expéditeur |
|---|---|---|
| Une déclaration attend votre attestation | les responsables qui **suivent** le candidat **et** peuvent attester | le candidat |
| Une de vos déclarations a été attestée | le candidat | personne (adresse no-reply) |
| Une attestation a été retirée de votre dossier | le candidat | personne |
| Une compétence de votre dossier ne figure plus dans sa fiche | chaque candidat ayant déclaré sur cette compétence, une fois | personne |

- **Aucun extrait de récit.** Un message est fait d'une phrase fixe, du nom du candidat (aux
  responsables seulement), de la référence de la compétence (`RNCP36832-C03`, donnée publique) et
  d'un lien vers le dossier, où les droits sont vérifiés de nouveau. Ni situation, ni cadre, ni
  période, ni énoncé, ni fonction du signataire, ni motif de retrait. Un test lit chaque champ de
  chaque message d'un cycle complet — déclaré, attesté, retiré — à la recherche de tout cela.
- **Qui envoie.** Le message de déclaration part au nom du candidat : la suppression RGPD de
  Moodle efface les notifications qu'une personne a envoyées comme celles qu'elle a reçues, et un
  message qui nomme un candidat ne survit donc pas à l'effacement de ses données. Les autres
  partent de l'adresse no-reply, **jamais** du responsable qui a signé ou retiré : le retrait
  efface qui a signé, et une notification « de » lui remettrait son nom dans la boîte du candidat.
- **Qui est prévenu d'une déclaration.** Ceux qui suivent le candidat (lot 7 bis) et peuvent
  attester pour lui. Ni le lecteur qui ne peut pas signer, ni le détenteur d'`attest` au niveau du
  site qui ne suit personne, ni la direction qui détient `viewany` : chaque déclaration
  deviendrait une circulaire.
- **Un candidat qui ne peut pas ouvrir son dossier n'en reçoit pas le lien.** Le filtre de Moodle
  sur le type de message ne suffit pas : il cherche `viewown` partout où elle est accordée, et une
  interdiction posée sur le contexte du candidat lui échappe. Le plugin pose la question
  lui-même, sur ce contexte — tenu par un test.
- **Envoyés par le cron, pas par la requête.** Les observateurs ne font que choisir les
  destinataires et mettre en file **une tâche par destinataire**, qui ne porte que des
  identifiants. Le message est composé et envoyé par le cron, dans la langue du destinataire.
  Un échec coûte un message, repris par le gestionnaire de tâches et visible dans ses journaux.
- **Vérifiés de nouveau à l'envoi.** Entre l'acte et le cron, un droit peut être retiré, un compte
  suspendu, une déclaration purgée ou déjà attestée, un départ défait : rien de ce qui n'est plus
  vrai ne part.
- **Un départ attend un jour.** Importer un fichier tronqué fait « partir » des compétences qui
  ne sont pas parties ; le bon fichier, réimporté, les fait revenir. Le message de départ attend
  24 heures et ne part que si la compétence est toujours absente.
- **Nouvel événement `competency_left_record`**, levé par l'import quand une compétence devient
  absente de sa fiche — à ce passage seulement, pas à chaque réimport qui la trouve toujours
  absente.
- **L'import lève la marque « absente » d'une compétence revenue dans sa fiche.** Défaut
  antérieur, trouvé pendant ce lot : la marque restait pour toujours. Sans conséquence tant que
  personne n'était prévenu, il aurait rendu une annonce fausse définitive, et un second départ
  muet.
- `access::follows()` et `access::can_attest()` acceptent un lecteur nommé ;
  `access::followers()` va du candidat vers ses responsables, par la même garde que la page du
  dossier.
- Déclaration RGPD : lien vers le sous-système de messagerie (`core_message`).

### Conception — des observateurs externes, à l'inverse de ceux de la purge

Une purge doit appartenir à l'acte auquel elle réagit ; un message, non. Les quatre observateurs
sont déclarés **externes** : ils ne s'exécutent qu'après la validation de la transaction. L'import
lève ses départs à l'intérieur de sa transaction ; s'il est annulé, rien n'est même mis en file.
Un test enveloppe l'import dans une transaction, vérifie que rien n'est en file avant la
validation, l'annule, et vérifie que rien ne l'est jamais. Et les observateurs rattrapent
eux-mêmes tout ce qu'ils lèvent, `\Error` comprise, que le gestionnaire d'événements laisserait
passer dans une requête dont l'acte est déjà enregistré.

### Corrigé — après revue adverse, avant fusion

La première version (commit `cd0b226`, CI verte) a été soumise à un second agent, qui a tout
vérifié dans les sources de Moodle 4.5. Ce qu'il a trouvé, et ce qui a été fait :

1. *Les courriels partaient de la requête.* Une fiche qui perd un bloc peut concerner cent
   candidats : cent envois synchrones dans la requête d'import, et une requête coupée en route
   laissait la moitié d'entre eux sans nouvelles — pour toujours, le départ déjà enregistré n'étant
   plus jamais annoncé. → Tâches ad hoc, une par destinataire.
2. *Un fichier tronqué envoyait des annonces fausses et définitives.* → Délai d'un jour, vérifié à
   l'envoi, et levée de la marque « absente » au retour de la compétence.
3. *La robustesse face à une `\Error` n'était vraie qu'à moitié.* → Chaque observateur rattrape
   tout ce qu'il lève.
4. *La langue forcée sur la session pouvait y rester* si la requête mourait pendant un envoi. →
   La langue est passée au gestionnaire de chaînes, la session n'est plus touchée.
5. *Deux tests de fuite seraient restés verts sur une fuite* : le motif de retrait cherché par
   son code et non par son libellé, la période cherchée seulement dans le libellé complet. → Les
   deux formes sont cherchées.
6. *Tests manquants* : compte suspendu, rattachement retiré avant l'envoi, garde au niveau du site
   vue depuis le notifier, passage de « renommée » à « absente ». → Ajoutés.
7. *Recherche des déclarations par une colonne sans index.* → Laissé en l'état : un départ est
   rare, la table tient les déclarations d'un seul site.
8. *Aucune trace d'un échec d'envoi en production.* → Les tâches en laissent une dans leur journal.

### Tests

Treize tests de plus que la 0.7.0, soit 144 : onze dans `notifier_test.php`, un dans `access_test.php`
(`followers()` et la garde au niveau du site), un dans `importer_test.php` (un seul événement
par départ, levée de la marque au retour, nouvel événement au départ suivant), et une assertion
de plus dans le test « renommée puis absente ». Les messages sont interceptés **après** les
contrôles de Moodle qui décident s'ils peuvent partir, et les tâches sont exécutées comme le
ferait le cron.

### Vérifié

- Hors ligne : syntaxe, MC-5, couverture RGPD, parité des chaînes (207 par langue), secrets.
- Intégration continue, première version (run n° 7, commit `cd0b226`) : PHPUnit 140 tests,
  2 546 assertions, verts sur PostgreSQL 16 et MariaDB 10.11 ; Behat 5 sur 5.
- Intégration continue, version corrigée (runs n° 9 et 10, commit `c500a39`) : **PHPUnit 144
  tests, 2 632 assertions, verts sur PostgreSQL 16 et sur MariaDB 10.11**, avec
  `--fail-on-warning` ; Behat 5 scénarios sur 5 ; standard de codage et PHPDoc sans un
  avertissement.

### Reste ouvert

- **Pas de regroupement.** Dix déclarations d'affilée valent dix messages au responsable. Moodle
  n'offre pas de résumé pour les notifications ; un résumé quotidien serait une tâche planifiée,
  à décider à l'usage.
- **Un fichier tronqué corrigé plus d'un jour après** a eu le temps de prévenir les candidats ;
  le message ne se rattrape pas.
- **Aucun test dans une autre langue que l'anglais** : la CI n'installe pas de paquet de langue.
  La langue du destinataire est lue, pas vérifiée.
- Une déclaration antérieure à la colonne `competencyid` n'est pas retrouvée au départ de sa
  compétence. Aucune n'existe hors d'un banc d'essai.

---

## [0.7.0] — 2026-10-11

### Ajouté — lot 7 bis : un responsable lit les dossiers des candidats qu'il suit, et eux seuls

Jusqu'ici, lire le dossier d'un autre exigeait `viewany` **au niveau du site** : pour lire un
dossier, un responsable devait pouvoir les lire tous (constat de la 0.6.4). C'est la reprise R1
de l'inventaire, et la condition posée avant toute utilisation réelle.

- **`tool/referentiel:viewassigned`** (CONTEXT_USER, sans archétype) : lire le dossier d'un
  candidat que l'on suit, c'est-à-dire dans le contexte utilisateur duquel on détient un rôle.
- **Le rattachement n'est pas construit, il est emprunté** : `tool_cohortroles`, présent dans le
  cœur, attribue un rôle dans le contexte de chaque membre d'une cohorte et se resynchronise
  d'heure en heure. Une cohorte par établissement, un rôle « Responsable VAE », et c'est tout —
  le choix de Jean-Louis : le gestionnaire affecte, par établissement. Mode d'emploi au README.
- **`candidates.php`, « Mes candidats »** : les candidats suivis, avec leur nombre de
  déclarations et le nombre de celles qui attendent une attestation. Un compte du travail à faire
  pour le responsable, jamais une mesure du candidat. Lien sur le profil du responsable.
- `access::can_view()` : la décision de lecture en un seul endroit, partagée par les pages et par
  le lien de profil — qui la recalculait jusqu'ici de son côté.

### Sécurité — `viewassigned` détenue au niveau du site n'ouvre rien

Le piège décrit en tête de `db/access.php` vaut pour cette capability comme pour les autres :
attribuée au niveau du site, elle serait héritée par **tous** les contextes utilisateur, soit la
portée de `viewany` sous un nom qui en promet une plus étroite. Plutôt que de compter sur
l'administrateur pour ne jamais faire cette attribution, `access::follows()` **refuse** la
capability quand elle est détenue au niveau du site. Échec fermé, tenu par un test qui rougit si
on retire la garde.

### Tests

Six tests ajoutés à `access_test.php`, dont un de bout en bout avec `tool_cohortroles` : cohorte,
rattachement, synchronisation, puis lecture permise au membre et refusée à l'extérieur.
`viewassigned` rejoint la liste des capabilities qui ne doivent être détenues par personne par
défaut.

### Vérifié — par l'intégration continue, pour la première fois

Première exécution de `.github/workflows/moodle-ci.yml`, le 11 octobre 2026, Moodle 4.5.15,
PHP 8.3 :

- **PHPUnit : 131 tests, 2 399 assertions, verts — sur PostgreSQL 16 *et* sur MariaDB 10.11.**
  MariaDB n'avait jamais été essayée ; l'affirmation du lot 4 sur `withdrawnat !== null` est
  désormais exécutée, plus seulement lue.
- **Behat : 5 scénarios sur 5**, dont le scénario `@javascript` et son contrôle axe-core, jamais
  exécuté jusqu'ici.
- `validate`, `savepoints`, `phpdoc`, `mustache`, `grunt` : verts.
- **Le standard de codage a échoué**, pour deux raisons sans rapport avec le lot, corrigées :
  - `scripts/` était exclu par `phpcs.xml.dist`, que moodle-plugin-ci ne lit pas. L'exclusion
    est reportée dans `.moodle-plugin-ci.yml`.
  - Les chaînes de langue n'étaient pas triées par clé — une règle de moodle-cs plus récente que
    le dernier passage du `Makefile`. Elles le sont désormais, sans titres de section ; un script
    a vérifié que les 191 chaînes de chaque langue sont identiques avant et après.
- `actions/checkout` passe en v5 : la v4 tourne sur Node 20, déprécié par GitHub.

---

## [Remise en ordre, sans version] — 2026-10-11

**Remise en ordre pour la pérennité. Aucune ligne de code exécuté n'a changé** — `version.php`
ne change que dans un commentaire, d'où l'absence de nouveau numéro de version.

### Ajouté — `docs/INVENTAIRE-mod_referentiel.md`

Comparaison fonction par fonction de l'ancien plugin `mod_referentiel` (Fruitet, 2016), du
sous-système de compétences de Moodle 4.5 et de ce plugin. Conclusion : la fonction centrale de
l'ancien plugin, le protocole de certification, calcule un verdict et reste donc hors périmètre
par principe. Quatre idées sont à reprendre, aucune sous forme de code : R1, rattacher un
responsable à ses candidats ; R2, les notifications ; R3, la couverture par compétence sans
seuil ; R4, la version figée du dossier, qui est l'export du lot 8.

### Ajouté — branche `archive/portage-mod_referentiel`

Le portage de l'ancien plugin vers 4.5, commencé le 15 août 2026, n'existait que sur un poste.
Il est figé et versé dans ce dépôt avec ses 14 commits ; `ARCHIVE.md`, à la racine de la
branche, dit pourquoi il s'arrête et quels défauts connus il laisse.

### Ajouté — `.github/workflows/moodle-ci.yml`, pour de bon

Le fichier annoncé en 0.6.2 **n'avait jamais été commité** : l'entrée de cette version décrivait
un contrôle qui n'existait pas. Il est réécrit sur le modèle officiel de `moodle-plugin-ci` 4,
matrice `MOODLE_405_STABLE` × PHP 8.3 × {PostgreSQL 16, MariaDB 10.11}. Il lance en plus les
quatre contrôles propres au projet (MC-5, RGPD, langue, secrets). **⚠ Comme en 0.6.2 : il n'est
pas un contrôle acquis tant qu'une exécution verte n'a pas été constatée.**

### Corrigé — la documentation disait autre chose que le code

- `README.md` annonçait « lot 3 » et 40 tests : le code est au lot 6. Le tableau des lots est
  à jour et gagne un lot 7 bis (R1, R2) à faire avant toute utilisation réelle.
- Quatre capabilities de données, et non cinq : `export` a été retirée en 0.6.2.
- Compteurs de tests réalignés dans le `README.md` et le `Makefile` : 101 tests verts constatés
  (0.6.2) ; la suite en compte 125 (comptage statique), dont l'exécution reste à constater.

### Modifié — calendrier de Moodle 5.3 LTS

La branche `MOODLE_503` devait s'ouvrir le 5 octobre 2026 ; elle ne l'a pas été. Elle s'ouvre
désormais **après le lot 8** : l'export normatif est ce qui vérifie une migration. Objectif :
premier semestre 2027, avant la fin du support de 4.5 le 4 octobre 2027.

### Signalé — fiche DER

Un constat daté complète la réserve, sans toucher à la décision : MC-5 est contrôlée ; MC-4 ne
l'est toujours pas ; **MC-2 est enfreinte une fois** (`relabel_evidence()`), un écart justifié
dans le code qui doit être accepté comme exception nommée ou supprimé.

### Reste ouvert

- **Le PRP, `DECISIONS.md`, `SOCLE-JLM.md` et `preuve.schema.json` ne sont ni dans le dépôt ni
  sur le disque de travail.** Les critères 1 à 10 ne sont définis nulle part ici.
- Le journal d'exécution de `docs/BANC-ESSAI.md` s'arrête à 98 tests (0.6.0) : aucune
  exécution n'a été faite depuis, faute de banc sur le poste.

---

## [0.6.5] — 2026-08-30

### Ajouté — lot 6 : importer une fiche de certification, et la réimporter sans dégât

- `import.php` et `classes/form/import_form.php` — l'écran d'administration qui manquait : on
  y dépose un CSV, on y déclare **d'où il vient**. La provenance (attribution, licence, date de
  publication) est exigée, pas facultative : la Licence Ouverte 2.0 l'impose partout où la
  donnée est présentée, et une déclaration doit pouvoir dire, cinq ans plus tard, **à quelle
  version de la fiche** elle se rapporte.
- `classes/local/importer.php` — l'appariement se fait sur `<code>-<rang>`. Un réimport met à
  jour les intitulés et **ne touche à aucune déclaration**. Une compétence qui a quitté la fiche
  n'est jamais supprimée : elle est consignée (décision 3 — une preuve orpheline reste une
  preuve).
- Le séparateur n'est pas supposé : virgule, point-virgule et tabulation sont essayés dans cet
  ordre. Un tableur français écrit des points-virgules.
- `index.php` liste les fiches importées avec leur ligne de provenance, leurs compteurs et
  l'état du dernier import.

### Corrigé — les onze défauts de la revue adverse, et un douzième trouvé au clavier

Le lot a été soumis à un second agent avant d'être versé. Ce qu'il a trouvé, et ce qui a été
fait. **Chaque correction est tenue par un test qui rougit quand on la retire** — vérifié par
mutation, pas supposé.

**Bloquants.**

1. *Tout fichier non-UTF-8 perdait ses accents, en silence.* L'encodage était écrit en dur ;
   `fix_utf8` du cœur **supprime** les octets qu'il ne sait pas lire. Un CSV latin-1 — ce
   qu'écrit un Excel français par défaut — s'importait au vert et « Accompagner la personne
   âgée » arrivait en base « Accompagner la personne ge ». L'encodage est désormais **demandé
   sur le formulaire**, comme le fait `tool_lpimportcsv` du cœur.
2. *Un intitulé de plus de 100 caractères faisait exploser l'import au milieu.*
   `competency.shortname` fait 100 caractères et le persistant du cœur ne le valide pas ; les
   blocs de compétences du RNCP dépassent couramment cette longueur. L'erreur arrivait à la
   douzième ligne sur quarante, nommait une colonne inconnue de l'administrateur, et laissait
   onze compétences derrière elle. Contrôlé avant toute écriture, et le message **nomme la
   ligne et la longueur**. C'était la leçon du critère 3, déjà payée sur le rang, non appliquée
   à l'intitulé.
3. *L'index unique entrait en collision avec le marquage des divergences.* Le test d'existence
   portait sur trois colonnes, la contrainte sur deux : la séquence « renommée localement, puis
   absente de la fiche » mourait sur une clé dupliquée, en plein import. Un état par compétence
   et par fiche, ce que dit l'index.
4. *Aucune transaction.* Un import mort à la quarantième ligne sur cinquante laissait la base à
   moitié écrite. Et l'annulation devait être **explicite** : laisser l'exception s'échapper
   d'une transaction déléguée ne défait rien — mesuré, deux compétences sur quatre survivaient.

**Majeurs.**

5. *`version.php` n'était pas incrémenté.* Le lot ajoutait deux classes autochargées, une page
   et 39 chaînes par langue sans changer le numéro : sur un site déjà installé, aucune mise à
   jour proposée, aucun cache reconstruit, et l'administrateur devant `Class not found`. C'est
   la famille de défaut de la 0.6.4, invisible aux tests et visible au premier déploiement.
6. *MC-5 violée, et le garde-fou aveugle.* `presenter.php` comptait les compétences par un
   `$DB` direct sur une table du cœur. Le script ne cherchait qu'un **nom de classe** ; il
   cherche maintenant aussi le **nom de table**, à la position de l'argument. Vérifié dans les
   deux sens : il attrape la violation corrigée, et laisse passer les tests, dont le rôle est
   justement de regarder ce que la passerelle a écrit dans le cœur.
7. *Un import raté transformait les republications en divergences locales.* La date de
   publication était écrite **avant** l'import ; après un échec et une reprise, tout intitulé
   que France compétences avait réellement changé était imputé au site. Et, entre les deux, la
   ligne de provenance annonçait une version que le site ne détenait pas — ce que la licence
   interdit. La date ne bouge plus qu'au succès.
8. *Une ligne bancale était annoncée comme un en-tête illisible.* Le lecteur refuse tout le
   fichier dès qu'**une** ligne a le mauvais nombre de colonnes ; le message envoyait
   l'administrateur inspecter une première ligne parfaitement correcte. Son diagnostic est
   maintenant transmis. Un fichier vide, lui, est dit vide.
9. *Le code de fiche n'était unique qu'en intention.* Index unique en base, plus une étape de
   mise à jour qui fusionne les doublons éventuels au lieu de les jeter. Et le code est **plié
   en majuscules** : MySQL compare sans la casse, PostgreSQL non — le même geste donnait un
   réimport propre sur un site et un référentiel dupliqué sur le suivant.
10. *Le générateur de tests n'écrivait pas ce que l'import écrit.* Il posait le code de fiche
    dans `shortname` quand l'import l'apparie sur `idnumber` : un scénario qui semait une fiche
    puis passait par `import.php` créait un **second** référentiel complet.
11. *Rien ne chargeait la page d'import.* Deux scénarios Behat la demandent maintenant. Ce
    qu'ils attrapent est mesuré et écrit dans le fichier ; **ce qu'ils n'attrapent pas aussi**.

**Un douzième, trouvé après la revue, en pilotant le vrai formulaire par HTTP.**

12. *Le menu d'encodage était inutilisable, et la valeur postée était rejetée en silence.*
    `core_text::get_encodings()`, que le cœur lui-même donne tel quel à un `select` dans
    `tool_lpimportcsv`, est la liste brute des alias de mbstring : **78 entrées**, dont BASE64,
    UUENCODE et QUOTED-PRINTABLE, proposées à quelqu'un qui importe un tableur. Pire, la clé
    s'écrit `WINDOWS-1252` et non `Windows-1252` : la première version envoyait la mauvaise
    graphie, le formulaire la refusait sans rien dire, retombait sur UTF-8, et l'import
    retirait les accents de tous les intitulés — un import au vert et une fiche mutilée,
    c'est-à-dire exactement le défaut n° 1 réintroduit par sa propre correction.
    Trois choix explicites désormais, et **un test qui vérifie que chaque clé proposée est
    connue du cœur**. Aucun test unitaire ne pouvait le voir : ils appellent tous le lecteur
    directement. Il a fallu déposer un fichier latin-1 dans un vrai brouillon et soumettre le
    formulaire par HTTP — ce qui a été fait, et « Accompagner la personne âgée » arrive
    maintenant intact en base.

**Mineurs traités.** Capacité `moodle/competency:competencymanage` vérifiée **avant** le
formulaire plutôt que découverte pendant l'import ; espaces insécables nettoyées, qu'un `trim()`
ne voit pas et qui se lisaient comme une divergence ; numérotation des lignes rendue honnête —
le lecteur du cœur supprime les lignes vides, le message dit désormais de quel comptage il
parle ; contradiction entre le docbloc et un test sur `lastimport` tranchée, en distinguant le
fichier refusé avant toute écriture de l'import mort en chemin ; les divergences consignées
sont enfin **lues et affichées** sur l'écran d'administration.

### Reste ouvert

- Prévenir **le candidat** — et pas seulement l'administrateur — quand la compétence qu'il a
  déclarée a quitté la fiche. C'est une décision de régime 2 sur ce qu'un dossier montre devant
  un jury, pas un choix technique : à trancher.
- L'injection de formule Excel (`=cmd|...`) traverse l'import intacte. L'affichage est sûr
  (tout passe par `s()`), le risque est en aval, dans un tableur qui rouvrirait un export.
  Neutraliser la valeur reviendrait à reformuler un libellé publié, ce que la licence interdit.
- `index.php` fait deux requêtes par fiche, sans pagination. Sans effet à dix fiches.

---

## [0.6.4] — 2026-08-29

### Corrigé — les trois écrans à formulaire mouraient sur une erreur fatale

`Class "moodleform" not found`, sur `declare.php`, `attest.php` et `withdraw.php`. La classe de
formulaire hérite de `moodleform`, qui vit dans `lib/formslib.php` — **une bibliothèque que
l'autoloader de Moodle ne connaît pas**. Une page qui instancie le formulaire sans l'avoir
chargée meurt.

**Aucun des 101 tests unitaires ne pouvait le voir**, et c'est le point important : chacun charge
`formslib` lui-même dans son `setUp()`. Un harnais qui charge ce que la page ne charge pas masque
l'erreur fatale exactement là où elle se produit. Le défaut a été trouvé en **se servant du
plugin dans un navigateur**, pas en le testant.

Le `require_once` est posé dans les trois classes de formulaire, à l'endroit où le cœur le pose
pour sa propre classe de base autochargée, `\core\form\persistent` : celui qui charge la classe
obtient son parent, et une page ajoutée plus tard ne peut pas oublier.

### Ajouté — de quoi qu'un test puisse enfin demander une page

- `tests/generator/lib.php` et `tests/generator/behat_tool_referentiel_generator.php` — un
  scénario peut désormais créer une fiche de certification importée en une ligne. Sans cela,
  **Behat ne pouvait atteindre aucun formulaire du plugin** : tous les écrans s'arrêtent avant,
  sur « aucune fiche n'a été importée ». C'est pourquoi le seul défaut qu'un navigateur peut
  voir n'avait jamais été vu.
- Un scénario Behat qui **demande la page de déclaration** et vérifie qu'elle s'ouvre. C'est le
  contrôle falsifiable de la correction ci-dessus, et il a été éprouvé dans les deux sens :
  `require_once` retiré, le scénario passe au rouge sur le `classloader` ; remis, les deux
  scénarios passent. **Behat : 2 scénarios, 12 pas, verts.**

### Vérifié — le parcours complet, dans un navigateur, sur un vrai site

Un site Moodle 4.5.13 installé, deux comptes, une fiche RNCP semée à la main faute d'import :

| Étape | Résultat |
|---|---|
| Page d'administration du plugin | s'ouvre, état vide correct — **et n'offre aucune action** |
| Dossier de la candidate, vide | « aucune activité déclarée », bouton *Déclarer* |
| Formulaire de déclaration | s'ouvre **après correction**, rappel d'anonymisation présent deux fois, rattaché par `aria-describedby` |
| Déclaration enregistrée | reprise intégralement dans le dossier |
| Côté cœur | libellé généré écrit, **description vide**, compétence liée, aucune maîtrise attribuée |
| Le cadre lit le dossier | possible, **au prix d'un droit de lecture au niveau du site** |
| Contreseing | écran, soumission, enregistrement |
| La candidate voit la signature | oui — et **aucun bouton de retrait ne lui est offert** |
| Écran de retrait, pour le cadre | s'ouvre |

---

## [0.6.3] — 2026-08-28

### Écrit noir sur blanc — le plugin ne tourne que sur les LTS de Moodle

Décision du commanditaire. Ce n'est pas une prudence de développeur : un dossier de VAE se
construit sur des mois et se lit devant un jury des années plus tard, et la plateforme qui le
détient doit encore recevoir des correctifs de sécurité ce jour-là. **Une LTS en apporte trois
ans ; une version intermédiaire dix à douze mois — moins que la durée d'un seul parcours.**

La règle était déjà mécanique sans être dite : `$plugin->supported = [405, 405]` fait refuser
l'installation par Moodle lui-même sur 5.0, 5.1 et 5.2. Elle est maintenant énoncée dans
`version.php`, dans le `README`, dans l'intégration continue et au registre des décisions.

**Le calendrier est fixé d'avance**, plutôt que découvert le jour venu :

| Branche | Moodle | Ouverte le | Maintenue jusqu'au |
|---|---|---|---|
| `main` | 4.5 LTS | — | 4 octobre 2027 |
| `MOODLE_503` | 5.3 LTS | 5 octobre 2026 | 1er octobre 2029 |

Les deux vivront côte à côte un an. **Aucune n'élargira sa plage pour absorber l'autre**, et
l'intégration continue ne construit jamais que la branche sur laquelle elle tourne — d'où un
fichier de workflow par branche, et non une matrice à deux branches.

### Changé — le dépôt suit la convention Moodle

`referentiel` devient **`moodle-tool_referentiel`**, selon `moodle-{type}_{nom}`. C'est ce que
supposent `moodle-plugin-ci`, les scripts de publication, et tout lecteur qui cherche un plugin
Moodle sur un compte GitHub. La marche à suivre est dans `claude/PUSH-GITHUB.md` ; GitHub garde
une redirection, mais les deux clones doivent mettre `origin` à jour.

---

## [0.6.2] — 2026-08-28

### Retiré — deux capabilities déclarées sans code

`tool/referentiel:export` et `tool/referentiel:managepassage` : décrites dans l'écran des rôles,
attribuables, et **lues par zéro ligne de code**. Un droit qui se lit comme accordé et ne l'est
pas. Elles reviendront avec les lots qui les lisent — 8 et 7. Leurs chaînes de langue partent
avec elles : `make langue` échoue sur une capability sans chaîne, donc la paire ne peut pas
revenir à moitié.

**Vérifié sur un site réel** : après la montée de version, Moodle a retiré les deux lignes de
`mdl_capabilities` de lui-même. Il en reste cinq.

### Ajouté — `.github/workflows/moodle-ci.yml`

L'intégration continue officielle de Moodle, `moodle-plugin-ci`, ne s'installe pas dans
l'environnement où ce plugin est construit — elle vient de packagist, qui y est bloqué. Elle
tourne sur GitHub. Elle apporte quatre contrôles que ni le `Makefile` ni le banc ne peuvent
faire : `validate`, `savepoints`, `phpmd`, `phpdoc`.

Et deux choses qu'elle seule peut prouver :

- **le scénario Behat `@javascript`**, donc le contrôle d'accessibilité axe-core de Moodle 4.5,
  jamais exécuté faute d'un pilote de navigateur assorti dans le conteneur ;
- **MariaDB.** Tout a été vérifié sur PostgreSQL jusqu'ici. L'affirmation du lot 4 selon laquelle
  `withdrawnat !== null` se comporte pareil sur les deux vient d'une lecture, jamais d'une
  exécution — et les deux pièges de typage qui ont coûté le plus cher à ce projet sont de cette
  famille exactement.

Une seule branche dans la matrice, `MOODLE_405_STABLE`, parce que `version.php` déclare
`supported = [405, 405]` : **annoncer une compatibilité qu'on ne teste pas serait pire que ne
rien annoncer.**

**⚠ Ce fichier n'a jamais tourné** et ne peut l'être qu'au premier push. Il n'est pas un contrôle
acquis tant qu'une exécution verte n'a pas été constatée.

### Vérifié

- 101 tests, 2 273 assertions, verts. `make valider` vert, lu à son code de sortie.
- `php admin/cli/check_database_schema.php` : *Database structure is ok.*

---

## [0.6.1] — 2026-08-28

**Quatre dysfonctionnements relevés à l'inventaire, réparés.** Aucun n'avait été trouvé par les
tests : trois étaient des promesses non tenues, le quatrième attendait un jeu de données réel.

### Corrigé — un rang de fiche trop long renvoyait une erreur SQL brute au candidat

`ficherang` est en `char(8)`, `fichecode` en `char(32)`, et rien ne contrôlait leur longueur.
`RNCP36832-BC01C0299` — une forme ordinaire de bloc de compétences — produisait :

```
dml_write_exception : Error writing to database (ERROR: value too long for type character varying(8)
```

**Aucune validation de formulaire ne pouvait l'attraper** : ces deux valeurs ne sont saisies par
personne, elles sont découpées dans l'`idnumber` d'un référentiel importé. Le contrôle appartient
donc au gestionnaire, et il y est. Le message nomme désormais la valeur fautive et dit quoi
faire, comme l'exige le critère 3 — un pilote de base de données, lui, nomme un type de colonne.

Trois tests, dont un sur la borne exacte : un rang de huit caractères passe. Sans lui, un
contrôle écrit avec `>=` refuserait un rang valide et passerait les deux autres. Vérifié par
mutation, dans les deux sens.

### Corrigé — `uuid` n'était pas déclaré au provider RGPD

Ni sur les déclarations, ni sur les attestations. C'est l'identifiant qui désigne le dossier
d'une personne dans l'archive exportée. Les autres champs non déclarés ont été revus un par un :
ce sont des métadonnées de référentiel, pas des données personnelles.

### Corrigé — cinq classes CSS émises, aucune feuille de style

Dont `tool-referentiel-anonymise`, qui porte le rappel d'anonymisation — la mesure sur laquelle
repose tout l'argument du régime 2. Le texte était lisible ; rien ne le distinguait du reste du
formulaire, et à 400 % de zoom un encart sans délimitation se lit comme la suite de l'étiquette
au-dessus.

`styles.css` n'impose **aucune couleur, aucune police, aucune taille en pixels** : tout est
exprimé dans les variables du thème avec un repli Bootstrap, pour qu'un site en thème sombre ou
en police agrandie obtienne un résultat cohérent plutôt que l'idée que ce plugin s'en fait. La
couleur n'est jamais le seul porteur de sens.

### Retiré — `competency_gateway::list_evidence()`

Appelée nulle part. Du code mort dans la classe dont le rôle est précisément de cartographier la
surface du cœur : elle en donnait une image plus large que la réalité.

### Vérifié

- **101 tests, 2 275 assertions**, verts. Les 1 628 tests RGPD du cœur, verts. `make valider`
  vert, lu à son code de sortie.
- **Mutations** : contrôle de longueur retiré → deux tests rouges ; `>` changé en `>=` → le test
  de borne rouge.

---

## [0.6.0] — 2026-08-28

**L'ancre, et la sortie.** Deux décisions du commanditaire, prises après mesure, sur des choses
que le plugin ne pouvait pas voir.

### Ajouté — le dossier ne ment plus sur ce que la plateforme atteste

Le candidat possède la preuve d'acquis à laquelle ce plugin rattache une déclaration, et peut la
modifier depuis `tool_lp` : la renommer, délier la compétence, en lier une autre. Mesuré avant
d'écrire une ligne : dans tous les cas le dossier continuait d'affirmer « RNCP36832-C03 » quand
la plateforme n'attestait plus rien de tel. **Ce n'est pas une fuite, c'est un mensonge** — et il
est servi à un jury.

- `classes/local/anchor.php` — l'état de l'ancre : disparue, déliée, renommée, ou rien.
- Deux colonnes sur `tool_referentiel_evidence` : **`corelabel`**, le libellé exact écrit sur la
  ligne du cœur, et **`competencyid`**, la compétence au titre de laquelle la déclaration a été
  faite. Ce sont des références, pas des verdicts : des faits sur le passé, qui ne périment pas.
- `classes/observer.php::user_evidence_updated()` et `classes/event/evidence_diverged.php` — la
  trace au journal.
- `dossier.php` et `presenter::anchor_warning()` — la mention visible, **recalculée à chaque
  affichage**. Un drapeau posé ne connaîtrait que ce qui s'est produit après lui, et par la
  seule porte qui déclenche un événement : la déliaison n'en déclenche aucun.

**Le plugin n'empêche rien** — ce sont les données du candidat dans son propre outil, et un
observateur ne peut pas refuser proprement de toute façon. Il constate et il le dit.

### Ajouté — `db/uninstall.php` : le libellé devient lisible, la preuve reste

Après désinstallation, chaque candidat gardait à vie dans `tool_lp` une entrée
`RNCP36832-C03 · 2025-09 → 2026-06` ne renvoyant plus à rien. Moodle ne peut pas le deviner :
rien ne lui dit qu'un composant a écrit chez un autre. **Décision : neutraliser le libellé, ne
rien détruire** — l'enregistrement appartient au candidat et porte ses pièces jointes.

**À écrire dans les consignes d'exploitation** : ce crochet n'est PAS appelé si l'on supprime le
dossier du plugin avant de purger. Le libellé codé survit alors, et devient irrécupérable
puisque les tables qui portaient la correspondance tombent avec. Prouvé sur un site réel, les
deux chemins exécutés. **Désinstaller par l'écran d'administration ou par
`admin/cli/uninstall_plugins.php`, jamais en supprimant le dossier.**

### Ce que la revue adverse a corrigé — à ne pas réintroduire

Verdict rendu : **SÉRIEUX**, sur dix-huit constats dont l'essentiel prouvé par exécution.

1. **Le détecteur ne voyait pas une compétence échangée.** Il demandait « reste-t-il un lien ? »,
   pas « le lien que j'ai nommé est-il toujours là ». Délier C03 et lier C07 : deux clics, verdict
   « rien n'a changé », dossier affirmant toujours C03. D'où la colonne `competencyid`.
2. **Renommée ET déliée était rapportée comme un renommage** — le message rassurant à la place
   de celui qui compte. Les verdicts sont désormais rendus du plus grave au moins grave.
3. **Toute panne de lecture était annoncée comme une disparition.** Un `catch (\Throwable)`
   renvoyait `null`, et `null` signifiait « l'enregistrement n'existe plus » : un délai
   d'attente dépassé faisait dire au dossier, devant un jury, que la preuve avait disparu.
   **« Je ne sais pas » ne doit jamais s'afficher comme « c'est parti ».** Seule une ligne
   réellement absente vaut disparition ; tout le reste remonte, et le détecteur se tait.
4. **La désinstallation fabriquait la divergence qu'elle prétend éviter** : elle réécrit le
   libellé du cœur, la référence stockée ne suivait pas, et une interruption laissait chaque
   dossier accusant son titulaire. La référence suit maintenant.
5. **La désinstallation réécrivait la provenance de la ligne du candidat** — `usermodified`
   passait à l'identifiant de l'administrateur. Le libellé s'écrit désormais sans le persistant,
   donc sans toucher à `usermodified` ni à `timemodified`.
6. **Le libellé d'adieu recalculait les mois** — exactement ce que ce lot condamne pour
   `corelabel`. Il part maintenant de la référence stockée.
7. **`make valider` était rouge sur l'arbre commité**, sur une accolade. Je lisais mon propre
   rapport filtré au lieu de son code de sortie. **Le contrôle se lit à son code de sortie.**
8. **Le coût annoncé était faux d'un facteur cinq** — cinq requêtes par déclaration, pas une,
   dont la moitié en pure duplication : la ligne du cœur était lue deux fois. Une seule lecture
   désormais, trois requêtes, et le chiffre écrit est celui qui a été mesuré.
9. Un garde mort dans `relabel_evidence`, une justification inexacte des deux méthodes de
   passerelle réservées aux tests, la version de `db/install.xml` non incrémentée, et
   l'événement réémis à chaque modification ultérieure — le dernier est assumé et expliqué.

### Vérifié

- **98 tests, 2 269 assertions**, verts. Les 1 628 tests RGPD du cœur, verts. `make valider`
  vert — **vérifié à son code de sortie**.
- **Mutations** : la vérification « un lien quelconque » remise en place → un test rouge ;
  l'ordre des verdicts inversé → un test rouge ; le détecteur muet → cinq tests rouges ; le
  crochet de désinstallation vidé → un test rouge.
- **Non couvert, et dit plutôt que laissé à découvrir** : élargir le `catch` de la passerelle à
  `\Throwable` laisse la suite verte. L'éprouver demanderait de casser la base sous une requête
  vivante, ce qu'aucun test portable ne fait.

---

## [0.5.1] — 2026-08-28

**Audit de raccordement à la plateforme.** Le principe posé par le commanditaire : le plugin ne
doit pas refaire ce que Moodle fait déjà, il doit être **branché** dessus. Un raccordement absent
ou silencieusement cassé est un défaut au même titre qu'un bug — et il ne se voit pas, puisque
rien n'échoue.

### Corrigé — le plugin déclarait des données au niveau du site sans jamais les servir

`get_contexts_for_userid()` annonçait le contexte système pour qui avait importé une fiche
(`_source`) ou décidé un passage (`_passage`) ; `export_user_data()` ne traitait que les
contextes utilisateur, et les trois portes d'effacement en sortaient sans rien faire. **Le site
répondait donc « ce plugin détient des données sur vous », n'en rendait aucune, puis déclarait
l'effacement fait sans rien effacer.** C'est la même famille que le défaut du 21 août — un
provider qui répond faux — et c'est un défaut de raccordement, pas un défaut de RGPD à part :
la plateforme ne connaît que ce que le plugin lui déclare.

- `export_site_actor()` — rend les imports et les décisions de passage de la personne.
- `strip_site_actor()` — **la ligne reste, la personne s'en va**, exactement comme pour une
  attestation retirée. Supprimer les lignes effacerait la licence et la paternité que la Licence
  Ouverte 2.0 oblige à afficher, et orphelinerait toute déclaration faite contre cette fiche.
- **Conséquence assumée** : `passage.note` part avec l'identifiant. C'est du texte libre, déclaré
  comme donnée personnelle, et il nomme en pratique les membres de la commission. Le garder
  serait la contradiction même que ce correctif ferme.
- Six tests écrits **avant** le correctif, dont quatre étaient rouges. Le cinquième — « le plugin
  annonce bien détenir quelque chose » — était vert : sans lui, les quatre autres ne
  prouveraient rien, puisqu'un provider qui n'annonce rien et ne sert rien serait cohérent.

### Ajouté — `tests/integration_test.php`, six tests de raccordement

Ils ne demandent pas si le code du plugin est juste, mais si **le cœur le voit**. Un rappel dont
le nom est faux d'une lettre, une page rangée sous une catégorie qui n'existe pas : rien
n'échoue, ça n'a simplement pas lieu.

- Le dossier est atteignable depuis la page de profil, et **ne l'est pas** sans la capability,
  ni quand le sous-système est éteint.
- Le plugin a une page dans l'arbre d'administration, **et elle est sous `competencies`** — la
  présence seule ne suffit pas : `locate()` fouille tout l'arbre, donc une page rangée sous
  `tools` passait le test. Vérifié par mutation, c'est ce qui a fait ajouter l'assertion de
  rangement. `tools` n'existe que pour un administrateur de site, et le test tourne comme tel :
  l'entrée aurait paru présente ici et disparu pour tous les autres.
- **Aucune aire de recherche globale n'est déclarée, et c'est délibéré.** Une aire mettrait le
  récit dans un index **hors** des tables du plugin : un second système de droits à écrire
  (`\core_search\base::check_access()` est abstraite, le moteur ne consulte jamais les
  capabilities d'un plugin) et un second chemin d'effacement, une purge de la base laissant la
  copie indexée vivante. Le test transforme ce raisonnement en quelque chose qu'un commit
  ultérieur ne peut pas défaire en silence.
- Le plugin ne détient aucun fichier, donc aucun fichier n'échappe à sa purge.

### Behat a tourné pour la première fois — et le fichier de scénarios ne pouvait pas passer

Trois défauts, tous invisibles à la lecture, tous trouvés par la première exécution :

1. **Il manquait `tests/behat/behat_tool_referentiel.php`.** Sans résolveur de composant,
   `I am on the "..." page` est confié au résolveur du cœur, qui lève
   `Unrecognised core page type`.
2. **Un scénario avait ses deux arguments inversés** — l'identifiant et le type de page. Le cœur
   lisait le type de page « autre ».
3. **Tous les scénarios attendaient l'interface en FRANÇAIS**, alors que le site d'acceptation
   tourne en anglais, comme toujours. Aucun n'avait la moindre chance de passer.

Le troisième scénario — la non-régression de l'escalade de privilège — **a été retiré**, et son
absence est argumentée dans le fichier : Behat fait échouer tout pas qui affiche une exception
Moodle, et un refus de permission en est une. Le cœur de Moodle 4.5 ne contient d'ailleurs aucun
scénario de ce genre. La garantie n'est pas perdue, elle est là où elle peut tourner :
`tests/access_test.php`. **Un scénario qui ne peut pas passer est pire que pas de scénario.**

Reste non exécuté : le scénario `@javascript`. Le conteneur a un chromedriver 147 et un chromium
141, et le téléchargement d'un pilote assorti est bloqué par le réseau. Ce n'est pas un défaut du
plugin ; la marche à suivre est consignée dans `docs/BANC-ESSAI.md`.

### Vérifié

- 82 tests, 2 241 assertions, verts. Les 1 628 tests RGPD du cœur, verts. `make valider` vert.
- **Falsifiabilité** : rappel de profil renommé → un test rouge ; page rangée sous `tools` → un
  test rouge ; les quatre tests du contexte système, rouges avant le correctif.

---

## [0.5.0] — 2026-08-28

Lot 5. **L'observateur : une suppression décidée ailleurs ne laisse aucun récit orphelin.**
C'est le critère 8. Le candidat peut détruire sa preuve depuis `tool_lp`, par un service web
que ce plugin ne voit pas passer ; le cœur efface ses lignes et s'arrête là. Le récit — du
texte libre sur du travail de soin, dans une table de ce plugin — survivait, accroché à un
enregistrement qui n'existe plus : invisible dans toutes les interfaces, et hors d'atteinte de
toute purge ultérieure.

### Ajouté

- `db/events.php` et `classes/observer.php` — **deux** observateurs, pas un.
- `classes/local/purge.php` — la destruction d'un dossier, écrite une fois et appelée par
  l'observateur comme par le provider RGPD. Trois chemins détruisaient les mêmes lignes ;
  écrits trois fois, ils dérivent, et une table ajoutée au schéma se met à être purgée sur un
  chemin et à survivre sur les deux autres sans que rien n'échoue.
- `classes/event/evidence_purged.php` et `dossier_purged.php` — la trace au journal, avec des
  entiers seulement. Le second ne porte aucun identifiant de déclaration : après la purge ils
  ne désignent plus rien, et une ligne de journal qui les nomme n'invite qu'à une recherche
  vouée à l'échec.
- `tests/observer_test.php` — 12 tests, deux candidats, une attestation.

### Le second chemin, que le PRP ne décrivait pas

**Supprimer un COMPTE ne passe pas par la même porte.** `delete_user()` appelle le crochet du
sous-système de compétences, qui efface les preuves **en masse et sans déclencher aucun
événement** — vérifié dans les sources, puis mesuré sur un site en fonctionnement le
28 août 2026 : ligne du cœur partie, ligne du plugin et attestation toujours là. Un observateur
sur la suppression unitaire ne couvre donc pas la fermeture d'un compte, et s'en contenter
aurait laissé le trou le plus large derrière un contrôle qui avait l'air complet.

**Décisions du 28 août 2026.** Le compte du candidat supprimé : le dossier est purgé, carte
d'identité comprise. Le compte de l'attestant supprimé : **on ne touche à rien** — fermer un
compte n'est pas une demande d'effacement, et vider ces champs dégraderait le dossier de tous
les candidats qu'il a contresignés, dont aucun n'a rien demandé. L'effacement a sa propre
porte, le provider RGPD.

### Ce que la revue adverse a corrigé — à ne pas réintroduire

- **`internal` vaut `true` par défaut dans un observateur Moodle**, et le commentaire du code
  affirmait le contraire. Un observateur qu'on croit externe tourne en réalité dans la
  transaction de l'appelant, ce qui rend la transaction de la purge toujours imbriquée : un
  échec y condamne alors la transaction de l'appelant, qui reçoit une erreur SQL sans rapport
  apparent pendant que la cause réelle est réduite à un `debugging()`. La clé est désormais
  écrite en toutes lettres, et le choix — interne — est argumenté : une purge qui ne peut pas
  aboutir ne doit pas laisser tenir la suppression qui l'a provoquée.
- **Le gestionnaire d'événements n'attrape que `\Exception`, pas `\Throwable`.** Une `\Error`
  traverse et avorte l'appel du cœur.
- **La transaction était posée au mauvais niveau** : elle laissait la carte d'identité dehors.
  Une interruption entre les deux laissait le dossier détruit et le ULID toujours en place.
- **Le repli de `context_of()` était écrit avec `??`** alors que `get_context()` retourne
  `false`. Il avait l'air d'un garde-fou et n'en était pas un.
- **`tables_exist()` ne vérifiait qu'une table sur les trois** où la purge écrit — précisément
  dans le cas du schéma à moitié construit pour lequel ce garde-fou existe.
- **Trois mutations survivaient à la suite de tests** : le contexte de la trace, la purge de la
  carte d'identité par `itemid` seul, et l'absence de transaction. Les deux premières sont
  désormais tuées par un test ; la troisième est assumée et documentée.
- **L'exemption MC-5 portait sur un nom de fichier, pas sur un chemin.** `grep --exclude` ne
  compare que le nom de base : n'importe quel `provider.php` de l'arbre était exempté. Corrigé
  et éprouvé dans les deux sens, y compris sur un `classes/local/provider.php` égaré.

### Le risque résiduel, nommé

Une purge qui échoue est **silencieuse** : le gestionnaire d'événements réduit l'exception à un
`debugging()`, la suppression du cœur tient, et le récit reste orphelin. La transaction empêche
que cet échec soit à moitié fait ; elle ne peut pas le rendre bruyant. Détecter un orphelin
après coup relève d'une tâche planifiée — point ouvert du lot 7.

### Vérifié

- 69 tests, 2 213 assertions, verts sur Moodle 4.5.13+, PHP 8.3.6, PostgreSQL 16.
- Les 1 628 tests de conformité RGPD du cœur, verts.
- `make valider` vert de bout en bout.
- **Falsifiabilité** : `db/events.php` vidé de ses observateurs, les deux tests de câblage
  passent au rouge. Un contrôle qu'on n'a pas vu échouer n'est pas un contrôle.

---

## [0.4.0] — 2026-08-28

Lot 4. **L'attestation et son retrait.** Un responsable contresigne la déclaration d'un
candidat ; le retrait de cette signature laisse la déclaration intacte. C'est le critère 1 bis,
et c'est la raison pour laquelle l'attestation vit dans une table du plugin plutôt que sur
l'enregistrement du cœur : le cœur n'offre aucun moyen de décontresigner sans détruire la preuve.

### Ajouté

- `classes/local/attestation_manager.php` — attestation et retrait. **Aucun appel au cœur**,
  vérifié par `make mc5` et par un test qui photographie les quatre tables du sous-système
  avant et après.
- `classes/local/presenter.php` — rendu d'une déclaration et de son historique d'attestation.
  Tout passe par `s()` ou par `format_text()` avec le format choisi par le candidat, jamais
  par concaténation brute : ce texte s'affiche dans la session d'un cadre, c'est-à-dire le
  compte du site qui vaut le plus la peine d'être attaqué.
- `classes/form/attest_form.php`, `withdraw_form.php`, `attest.php`, `withdraw.php`.
- Trois événements : `evidence_attested`, `attestation_withdrawn`, `attestation_denied`.
- `dossier.php` liste enfin les déclarations et porte les liens d'attestation et de retrait.
  **Ce n'est pas l'écran de restitution** — celui-là est le lot 9.
- `tests/attestation_manager_test.php` — 17 tests.

### Décidé — ce que le retrait efface

**Cinq champs, pas quatre.** `attestantid`, `attestantrole`, `statement`, `attestedat` — et
`usermodified`, remis à 0. Les quatre premiers étaient au plan depuis le lot 2. Le cinquième
a été trouvé en revue adverse : `usermodified` porte l'identifiant du signataire depuis
l'instant où il signe, et le réécrire avec l'identifiant de celui qui retire remet un nom sur
la ligne **dans le cas précis où le motif est « rgpd » et où le signataire est justement la
personne qui demande l'effacement**.

Le provider RGPD a été complété dans le même passage : `strip_attestant()` atteint désormais
aussi les lignes que la personne a seulement modifiées en dernier, et `get_contexts_for_userid()`
comme `get_users_in_context()` savent la retrouver par cette colonne. Sans cela, le site
répondait « ce plugin ne détient rien sur vous » à quelqu'un qu'une colonne déclarée comme
donnée personnelle rattachait toujours au dossier d'un candidat.

**Conséquence assumée** : une fois retirée, l'attestation ne dit plus qui avait signé. Le
retrait a bien un auteur, et c'est le journal d'événements qui le porte, sous la règle de
conservation du site plutôt que dans le dossier.

### Corrigé en revue adverse — avant toute exécution

- **Le test du refus de capability était rouge sur du code correct.** Il cherchait
  `tool/referentiel:attest` dans le message de `required_capability_exception`, qui contient le
  **libellé traduit** de la capability, pas son identifiant. Le lot 3 avait déjà appris la leçon
  et testait `errorcode === 'nopermissions'` ; le lot 4 l'avait oubliée.
- **Le retrait n'avait pas la règle « on ne signe pas pour soi ».** L'attestation l'avait. Un
  cadre lui-même candidat — banal dans le secteur — pouvait donc détruire par l'URL la
  signature qui se portait garante de lui : le rôle porteur de `tool/referentiel:attest`
  s'assigne au niveau système, donc il vaut aussi sur son propre contexte.
- **Deux trous de couverture qui auraient laissé disparaître ces contrôles sans bruit** : un
  seul candidat dans le jeu d'essai rendait indétectable une vérification de capability écrite
  sur le contexte du signataire au lieu de celui du candidat, et le contrôle de capability du
  retrait n'était testé nulle part. Un second candidat et deux tests de plus.
- **Le motif de retrait le plus lourd était présélectionné.** Le menu s'ouvrait sur
  « Effacement demandé par le signataire ». Une option vide obligatoire désormais.
- L'énoncé de l'attestation était aplati sur une ligne à l'affichage (`nl2br`), et l'écran
  d'attestation masquait l'historique des signatures retirées.

### Contrôles

| Contrôle | État |
|---|---|
| Syntaxe PHP, 19 fichiers | ✓ |
| MC-5 — aucun appel au cœur hors passerelle | ✓ |
| Parité des chaînes de langue, 7 capabilities × 2 langues | ✓ |
| Couverture RGPD — 6 tables, 5 déclarées, 1 exemptée | ✓ |
| Longueur de ligne hors `lang/` | ✓ |
| **PHPUnit — les 17 tests du lot 4** | **○ NON EXÉCUTÉ** |
| **PHPUnit — les 40 tests des lots 0 à 3** | **○ NON EXÉCUTÉ depuis le 21 août** |
| phpcs / moodle-plugin-ci | ○ NON EXÉCUTÉ |
| Behat | ○ jamais exécuté |

**Les tests de ce lot n'ont jamais tourné.** Aucun banc d'essai Moodle n'était monté pendant
cette séance, et PHP n'est pas installé sur le poste. C'est exactement le risque que
`docs/BANC-ESSAI.md` existe pour empêcher : du code jamais exécuté qui s'empile. **Rien de ce
lot ne doit être présenté comme éprouvé avant que le banc ait tourné.**

## [0.3.1] — 2026-08-21

**Un défaut RGPD trouvé sur question de Jean-Louis**, en cherchant si le piège des constantes
chaînes du lot 3 existait ailleurs. Il existait, vivant, dans le provider.

### Corrigé — sérieux

- **Une demande d'effacement du candidat ne supprimait pas son dossier.**
  `approved_contextlist::get_user()->id` et `context::$instanceid` viennent de la base et sont
  des **chaînes**. La comparaison `(int) $context->instanceid === $userid` opposait un entier
  à une chaîne : **toujours fausse**. L'effacement partait donc dans la branche « dossier de
  quelqu'un d'autre », ne touchait à rien, et le site déclarait l'effacement fait.
- **Le même défaut vidait l'export du candidat** : il se voyait traité en tiers sur son propre
  dossier et n'obtenait rien.

Corrigé en castant **à la source**, une fois par méthode. Même famille que `FORMAT_PLAIN` valant
la chaîne `'2'` — une comparaison stricte contre une valeur dont le type n'est pas celui qu'on
croit. Rien ne se lit comme faux.

### Ajouté

- `tests/privacy_provider_test.php` — cinq tests écrits **avant** le correctif, comme l'exige
  le socle. Deux échouaient, prouvant le défaut ; les trois autres passaient déjà, parce que
  leurs comparaisons castaient les deux côtés.
  Ils vérifient le **comportement** — le dossier a-t-il disparu, l'export contient-il quelque
  chose — et non la branche prise, pour attraper la prochaine variante du piège.

### Audit

Toutes les comparaisons strictes du plugin ont été passées en revue, et le type réel de chaque
constante Moodle utilisée a été relevé sur le site : **seule la famille `FORMAT_*` est déclarée
en chaînes**. Les 23 autres constantes sont correctement typées. `docs/BANC-ESSAI.md` compte
désormais six pièges.

---

## [0.3.0] — 2026-08-21

Lot 3. **Le premier lot qui écrit vraiment.** Un candidat peut déclarer l'exercice d'une
activité ; la déclaration crée une preuve d'acquis dans le sous-système de compétences et la
rattache à une compétence importée.

### Ajouté

- `classes/local/evidence_manager.php` — la déclaration, sous transaction.
- `classes/form/declare_form.php` et `declare.php` — le formulaire, avec le **rappel
  d'anonymisation non masquable** sur les deux champs libres, rattaché par `aria-describedby`
  et jamais rendu en `placeholder` : un `placeholder` disparaît à la première frappe,
  c'est-à-dire exactement quand la personne commence la phrase qu'il devait infléchir.
- `classes/event/evidence_declared.php` et `declaration_denied.php`. Les deux **valident
  qu'aucune valeur non entière n'entre dans `other`** : l'anti-patron 16 devient une règle
  que le code applique, pas une consigne qu'on espère suivie.
- La passerelle implémente réellement `create_evidence()`, `link_to_competency()`,
  `list_evidence()`, `delete_evidence()`, `list_framework_competencies()`, `get_competency()`.
- Douze tests couvrant les critères 1, 2 et 3, l'atomicité, et les deux pièges ci-dessous.

### Conception

- **Une déclaration est toujours à la première personne.** Vérifié sur le site réel :
  `moodle/competency:userevidencemanageown` **et** `moodle/competency:competencyview` portent
  l'archétype « utilisateur authentifié ». Un candidat ordinaire peut donc écrire sans qu'on
  attribue à personne un droit sur le contexte utilisateur d'autrui. La correction C2 du
  blueprint tient entièrement.
- **Une déclaration doit viser une fiche importée.** Sans fiche source il n'y a pas de date de
  mise à jour, et sans elle la preuve ne peut pas dire à quelle version du référentiel elle se
  rapporte — ce qui est tout son objet.
- **Le libellé écrit dans le cœur est généré**, jamais saisi : `RNCP36832-C03 · 2025-09 →
  2026-06`. Le cœur recopie ce champ dans `competency_evidence.desca` au rattachement **et au
  délien** ; rien de ce qu'un utilisateur tape ne doit y parvenir.
- `competency_gateway` n'est plus `final` : la transaction ne peut être éprouvée que par un
  échec survenant **après** l'écriture du cœur, et aucune entrée atteignable n'en produit.
  Un test substitue donc une sous-classe dont le second appel échoue.

### Deux défauts trouvés en exécutant, invisibles à la lecture

- **`FORMAT_PLAIN` est la chaîne `'2'`, pas l'entier 2.** Mon contrôle de format faisait
  `in_array((int) $valeur, [FORMAT_PLAIN, FORMAT_MARKDOWN], true)` — un entier comparé à des
  chaînes, donc **toujours faux**. Le contrôle rejetait *toutes* les déclarations, y compris
  les valides. Il se lit comme évidemment correct. Normalisé en un seul endroit, et couvert
  par un test qui empêche le retour du piège.
- **Un test d'atomicité ne voit rien sans `preventResetByRollback()`.**
  `advanced_testcase::setUp()` ouvre une transaction autour de chaque test, et
  `moodle_database` ne défait que le niveau le plus externe. L'annulation du plugin était
  correcte, le test la déclarait fausse — et une version sans aucune transaction aurait donné
  le même verdict, donc le test ne distinguait rien.

Les deux sont consignés dans `docs/BANC-ESSAI.md`, qui compte désormais cinq pièges.

### Connu, non traité

- L'énumération des cadres d'intervention reste **provisoire** — PO-3 demande de l'établir
  avec un professionnel du secteur, pas depuis un bureau.
- Behat toujours non exécuté.

---

## [0.2.1] — 2026-08-21

**Première exécution réelle.** Jusqu'ici rien n'avait jamais tourné : ni PHPUnit, ni
l'installation du plugin, ni le standard de codage. Un Moodle 4.5.13 complet a été monté
(PHP 8.3, PostgreSQL 16) et tout a été exécuté. Marche à suivre dans `docs/BANC-ESSAI.md`.

### Ce que l'exécution a confirmé

- **Le plugin s'installe.** Les six tables sont créées, la version est enregistrée, les sept
  capabilities apparaissent aux bons niveaux de contexte et avec les bons masques de risque.
- **Aucune élévation de privilège.** Seules `import` et `managepassage` sont attribuées par
  défaut, au gestionnaire. **Aucune capability n'échoit à l'utilisateur authentifié** —
  vérifié en base, puis figé par un test.
- **Le provider RGPD passe le test de conformité du cœur** : 1628 tests, 7753 assertions,
  sur tous les composants du site.
- **La page d'administration se construit** sous la catégorie « Competencies », et le lien
  du profil n'apparaît qu'avec `viewown` attribué — et disparaît si le sous-système de
  compétences est désactivé.

### Corrigé

- **42 violations du standard de codage Moodle**, dont 39 auto-corrigeables. Le plugin passe
  désormais `phpcs --standard=moodle` **sans une seule violation**.
- **`MOODLE_INTERNAL` retiré de `lib.php` et `db/upgrade.php`.** Ces fichiers ne définissent
  que des fonctions, sans effet de bord ; le cœur ne l'y met plus non plus depuis
  `tool_dataprivacy` et `tool_policy`. Le sniff avait raison.
- **Le marqueur d'exemption RGPD devient `// Privacy-exempt:`** — le standard exige qu'un
  commentaire commence par une majuscule. La détection est passée en insensible à la casse.

### Ajouté

- `tests/access_test.php` — **sept tests de non-régression sur l'élévation de privilège**
  trouvée en revue adverse. Ils vérifient les deux moitiés du correctif : qu'aucune
  capability n'est accordée par archétype, ET que `viewown` accordée sur son propre contexte
  ne vaut pas sur celui d'un autre. Un test qui ne couvrirait que le cas passant passerait
  dans les deux cas.
- `phpcs.xml.dist` — le standard Moodle, avec `scripts/` exclu : ce sont des outils de
  construction qui s'exécutent hors de Moodle, et le contrôle `MOODLE_INTERNAL` qu'exige le
  standard les ferait mourir. Aucune autre exclusion.
- `make codestyle`, versé dans `make valider`.
- `docs/BANC-ESSAI.md`.

### Trois pièges de banc d'essai, consignés

Chacun m'a fait conclure à tort à un défaut du plugin :

1. **Un administrateur de site contourne tout contrôle de capability** — le tester avec
   `admin` ne prouve rien.
2. **`manager::build_tree()` prend trois paramètres et retourne l'arbre** — lui en passer un
   quatrième donne un arbre vide.
3. **En ligne de commande sans session, `has_capability()` est faux pour tout le monde** —
   l'arbre d'administration paraît alors vide et `settings.php` sans effet.

**Avant de corriger le plugin sur la foi d'un banc d'essai, vérifier que le banc dit vrai
sur un cas dont on connaît déjà la réponse.**

### Non exécuté

- **Behat.** Le scénario du lot 0 n'a pas tourné : il faut un serveur web et un navigateur
  pilotés. `make doctor` le compte en « ○ NON EXÉCUTÉ », pas en succès.

---

## [0.2.0] — 2026-08-21

Lot 2. **Le schéma de données, et la déclaration de ce qu'il contient — ensemble.**

### Ajouté

- `db/install.xml` — six tables. Toutes étendent une entité du cœur par clé étrangère ;
  aucune n'en duplique une (MC-3).
- `classes/local/identity_map.php` — **MC-8**. Correspondance entre les identifiants
  entiers de Moodle et des ULID opaques. Sans elle, l'export ne peut pas produire d'objet
  conforme à `preuve.schema.json`, qui rejette explicitement une clé entière ; sans export
  conforme, MC-1 n'est pas satisfaite ; sans MC-1, la dérogation au modèle natif n'a plus
  de contrepartie.
- `classes/privacy/provider.php` — déclaration complète : cinq tables avec toutes leurs
  colonnes, une table exemptée explicitement, et `add_subsystem_link('core_competency')`
  puisque le plugin fait écrire ce sous-système.
- `db/upgrade.php`, `tests/identity_map_test.php`, `scripts/verifier-privacy.php`.
- 38 chaînes de langue de confidentialité, en anglais et en français.

### Modifié

- **Le provider RGPD est écrit au lot 2, pas au lot 10.** Le PRP le plaçait à la fin. Créer
  les tables sans déclarer ce qu'elles contiennent laisse le site répondre « ce plugin ne
  détient rien sur vous » alors qu'il détient un dossier complet. Séparer le schéma de sa
  déclaration est précisément ce qui laisse un `null_provider` survivre à sa vérité.
- `tool_referentiel_evidence` porte `candidateid`, relation d'appartenance propre au
  plugin. Ce n'est pas une duplication au sens de l'anti-patron : c'est ce que tout
  contrôle d'accès et toute requête RGPD réclament, et cela évite qu'une requête du plugin
  nomme une table du cœur. Le champ ne peut pas diverger — le cœur n'offre aucun moyen de
  déplacer une preuve d'un utilisateur à un autre.
- `tool_referentiel_source` porte `lastimportstatus` : sans lui, un import en échec qui
  laisse une date ancienne se lit comme un import réussi.

### Sécurité

- **L'effacement d'un attestant vide quatre champs, pas un.** `attestantid`,
  `attestantrole` (« Cadre de santé, EHPAD Les Tilleuls »), `statement` (« Je soussignée
  Claire Dupont… ») et `attestedat`. Ne vider que l'identifiant produit un enregistrement
  qui se croit anonymisé et nomme la personne en toutes lettres. **La preuve du candidat
  survit**, marquée retirée et datée.

### Corrigé avant d'exister

- **Le contrôle `doctor` sur le provider RGPD était un faux positif.** Il cherchait la
  chaîne `null_provider` et la trouvait dans un **commentaire** expliquant pourquoi le
  provider n'en était plus un. Même faute que le contrôle MC-5 cassé du lot 0 : un test
  littéral là où il fallait un test de sens. Remplacé par `verifier-privacy.php`, qui
  vérifie que **chaque table d'`install.xml` est déclarée au provider ou exemptée
  explicitement** par un marqueur `// privacy-exempt: <table>`. Une table ajoutée plus tard
  et oubliée fait échouer la construction — c'est ainsi qu'un provider pourrit.
- **Le contrôle des secrets criait au loup** sur le mot « secret » dans le texte de la GPL
  et dans son propre Makefile. Refait sur des motifs de **valeur** — affectation d'un nom
  sensible à une chaîne assez longue, ou empreinte de jeton reconnaissable.

### Vérifié

- Générateur ULID : 200 000 tirages, aucune collision, motif du pivot respecté aux cinq
  bornes temporelles jusqu'à l'an 10889. Tri lexicographique = tri chronologique.
- **Contrôle croisé** : 500 ULID produits par PHP soumis au schéma JSON du socle par Ajv,
  tous acceptés — le générateur et le schéma ont été écrits séparément, c'est là qu'on
  vérifie qu'ils parlent du même format.
- Les cinq contrôles de couverture RGPD testés **dans les deux sens** : table non déclarée,
  exemption retirée, retour à un `null_provider`, exemption périmée.

### Connu, non traité

- Rien n'écrit encore dans ces tables. Lot 3.
- Les méthodes de la passerelle autres que la garde lèvent `coding_exception`. Lot 3.

---

## [0.1.0] — 2026-08-21

Premier lot. **Squelette installable qui ne fait encore rien** — c'est délibéré : le lot 0
existe pour poser les garde-fous avant qu'il y ait quoi que ce soit à garder.

### Ajouté

- `version.php` — cible Moodle 4.5 LTS. `supported = [405, 405]`, plage d'une seule
  branche : le projet ne supporte que les LTS et n'annonce jamais une plage de versions.
  `maturity = MATURITY_ALPHA`.
- `db/access.php` — sept capabilities. **Aucun archétype par défaut sur les cinq qui
  touchent à la donnée**, voir « Sécurité » ci-dessous.
- `classes/local/competency_gateway.php` — la passerelle, seul fichier autorisé à nommer le
  sous-système de compétences (MC-5 de `DER-referentiel-001`). Écrite au lot 0 plutôt qu'au
  lot 1 : elle porte la garde sur l'activation du sous-système, et son existence dès le
  premier commit interdit structurellement aux lots suivants de court-circuiter le cœur.
- `classes/local/access.php` — les contrôles d'accès, en un seul endroit.
- `lib.php` — lien vers le dossier depuis la page de profil.
- `settings.php` — page d'administration, sous la catégorie `competencies`.
- `index.php`, `dossier.php` — états vides traités.
- `classes/privacy/provider.php` — `null_provider`, **exact au lot 0 seulement**. Remplacé
  au lot 10. `make doctor` échoue dès que `db/install.xml` existera alors que cette
  déclaration serait devenue fausse.
- Fichiers de langue anglais et français.
- Tests : critère d'acceptation 10 (sous-système désactivé) en PHPUnit, plus un scénario
  Behat de non-régression sur l'élévation de privilège.
- `Makefile` et `scripts/` — `doctor`, `lint`, `mc5`, `langue`, `secrets`.
- `docs/derogations/DER-referentiel-001-modele-natif.md`.

### Sécurité

- **Aucun archétype par défaut sur `declare`, `attest`, `viewown`, `viewany` et `export`.**
  L'archétype `user` est attribué au contexte système, dont le contexte utilisateur est un
  enfant direct : une capability `CONTEXT_USER` accordée à cet archétype serait détenue par
  chaque utilisateur dans le contexte de tous les autres. Combiné à un contrôle écrit
  `require_capability(..., context_user::instance($idvenudelarequete))`, cela transformait
  `dossier.php?userid=<un autre>` en fuite de données. Défaut trouvé en revue adverse avant
  toute ligne de code, et couvert par un scénario Behat.
- L'appartenance se prouve par comparaison d'identité en PHP, jamais par une capability
  héritée — c'est ainsi que procède le cœur.

### Corrigé avant d'exister

Deux erreurs du blueprint, trouvées en construisant le squelette :

- **`$CFG->enablecompetencies` n'existe pas.** Le réglage réel est `core_competency/enabled`
  dans `config_plugins`. Le nom `enablecompetencies` n'est qu'une clé de langue — le libellé
  du réglage. Une garde écrite contre ce nom aurait lu `null` sur tous les sites et le
  plugin aurait refusé de fonctionner partout. Trois tests couvrent le cas, dont un qui
  échoue si la garde consulte l'ancien nom.
- **La catégorie d'administration `tools` n'existe que pour un administrateur de site.**
  Le plugin se range sous `competencies`, créée sans condition — sinon l'entrée disparaît
  silencieusement pour tout autre administrateur.

- **Le contrôle MC-5 du blueprint était cassé** : écrit `grep … | grep -v … && echo && exit 1`,
  il retournait 1 sur un arbre propre comme sur un arbre en violation, donc il aurait été
  désactivé au premier jour. Réécrit avec `grep -q` et une sortie explicite dans les deux
  branches, élargi à tout le plugin et au JavaScript.

### Connu, non traité

- Le plugin ne stocke rien et ne fait rien. Lot 2 et suivants.
- `VERSIONS.md`, `ARCHITECTURE.md` et `docs/MEMO-JURIDIQUE.md` n'existent pas encore :
  `make doctor` les signale en « NON EXÉCUTÉ », pas en succès.
- Compatibilité Licence Ouverte 2.0 / GPLv3 non levée — bloquant avant diffusion.
