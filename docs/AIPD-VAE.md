# Analyse d'impact relative à la protection des données (AIPD) — plugin « VAE - cheminement »

> **Projet, à valider par le délégué à la protection des données (DPO) du site qui installe le plugin.** Rédigée le 25 octobre 2026, mise à jour
> le 27 octobre 2026 (flux sortants, § 2), le 4 novembre 2026 (positionnements de la structure, § 1 à 8) et le 5 novembre 2026 (décisions du porteur, § 2, « Décisions du 5 novembre 2026 » ; message au vaeiste, rappel sur les formulaires) par l'équipe du plugin, d'après le code
> (version 2.5.03 (Moodle 5.3), build 2026110405, de code identique à celui du build 2026110404 ; le code des positionnements, le message au vaeiste et le rappel sur les formulaires sont **commités** (5 novembre 2026 : `1203ecd`, `e9d8733`) ; « 0.47.0 » n'est que l'étape où l'export de France compétences a été ajouté) et la méthode de la CNIL (RGPD, article 35, paragraphe 7). Ce n'est pas un avis
> juridique. **Chaque site est responsable de traitement** : il complète les parties marquées « À compléter par le site », la signe et
> la tient à jour. Le plugin fournit ce qu'il sait de lui-même ; il ne sait rien de l'hébergement ni du fournisseur d'IA du site.
> Fiche associée : `docs/T-84-conformite-rgpd.md` (base légale, durées, droits, registre).

> **Mise à jour du 9 novembre 2026 (build 2026110409).** Depuis la rédaction des sections ci-dessous, trois traitements se sont ajoutés, que les sections 1 à 8
> **n'ont pas encore analysés** : l'auto-positionnement du vaeiste par niveau (lot 1a, table `tool_vae_selfpos`) ; ses situations de travail (lot 1b : sept tables
> `tool_vae_situation`, `tool_vae_sitversion`, `tool_vae_sitetag`, `tool_vae_spiece`, `tool_vae_slink`, `tool_vae_consent`, `tool_vae_note`), avec les annotations des
> responsables auxquels il a donné son accord, nominativement, retirable ; et le livret de la structure, séparé de ceux du vaeiste (décision du porteur du 7 novembre 2026).
> Ce que le code fait de ces données est déclaré colonne par colonne dans `classes/privacy/provider.php` (export au vaeiste et au responsable, effacement par trois
> chemins) ; leur durée est celle du compte du vaeiste. Trois points sont à faire examiner par le DPO du site.
> **(1) Sauvegarde entière (décision du 9 novembre 2026).** La sauvegarde faite avant une désinstallation, ou par l'administrateur, reste entière : pour chaque dossier qui
> porte au moins une déclaration, elle emporte les positionnements de la structure, l'auto-positionnement et les situations de travail, annotations et accords compris ;
> l'administrateur qui la détient peut la lire. Un vaeiste qui n'a que des situations ou un auto-positionnement, sans déclaration, n'est pas sauvegardé.
> **(2) L'administrateur du site peut certifier** (décision du 9 novembre 2026) , lit la page d'auto-positionnement d'un vaeiste et les dépôts de la préparation du dossier de validation (`deposit_manager::can_read`, règle antérieure) ; il reste refusé sur les situations
> de travail et sur les brouillons, versions et historique de la préparation (invariant MC-16).
> **(3) Les risques propres à ces traitements** (texte libre sur le travail, pièces jointes, annotations d'un tiers) restent à ajouter au § 3 : ils ne le sont pas.

## 1. L'AIPD est-elle nécessaire ?

La CNIL demande une AIPD quand le traitement figure sur sa liste, ou quand il remplit **au moins deux des neuf critères** du groupe de
l'article 29. Lecture pour ce plugin (analyse de l'équipe, à confirmer par le DPO) :

| Critère | Ce plugin | Remarque |
|---|---|---|
| Évaluation / notation | **Oui, dès que la structure s'en sert** | Depuis les positionnements de la structure (§ 2), des personnes écrivent sur un vaeiste où en est chaque compétence et si un bloc est certifié par la structure, avec un motif écrit. **Le plugin, lui, ne note, ne classe ni ne conclut jamais** (décision D-1 : le plugin aide à rédiger et à mettre en forme, il ne dit jamais « recevable » ni « suffisant » pour un dossier) : pour les positionnements, il ne calcule que trois lectures, les compétences d'un bloc qui ne sont pas encore suffisantes (alerte), le fait qu'un dernier jour est passé et le fait que des compétences du bloc ont été positionnées après la certification (§ 2 ; § 3, R10 et R11 pour les deux premières). Il lit aussi, sans la copier, la ligne « positionné sur l'ancienne fiche … » (statut et date) quand un administrateur a décidé une correspondance entre deux fiches, et il compte, pour la couverture du dossier, les déclarations et les attestations de chaque compétence : des comptes, jamais un pourcentage, un seuil ni une note. Analyse de l'équipe : une évaluation de compétences professionnelles écrite par l'employeur est proche du critère « rendement au travail » du G29 ; à confirmer par le DPO. |
| Décision automatique à effet juridique | **Non** | Toute attestation, tout positionnement et toute décision sont humains ; l'alerte et l'échéance sont des lectures, pas des décisions. |
| Surveillance systématique | **Non** | Le journal d'ouverture des pièces enregistre qui a ouvert quoi, pas un suivi de comportement. |
| Données sensibles ou hautement personnelles | **Possible** | Identité, date de naissance, nationalité, récits d'activité professionnelle. Pas de donnée de l'article 9 par conception, **sauf** données de santé de tiers qu'un vaeiste écrirait ou joindrait par erreur (voir § 3, risque R2). |
| Grande échelle | **Selon le site** | Un établissement : non ; un organisme de formation ou de branche : peut-être. |
| Croisement de données | **Non pour toute autre base ; une lecture interne au site existe** | Aucun rapprochement avec une base extérieure à Moodle sur des personnes. En revanche, l'onglet « Organisation » rapproche, dans la base de Moodle, les comptes qui ont une trace dans le plugin (une déclaration, une marque « hors de mon activité » ou un brouillon de validation) des plans de formation du cœur, pour afficher **un nombre de plans par fiche**, sans nom de plan ni de personne (§ 2, « Configuration du dispositif »). La récupération facultative de l'export public de France compétences ne porte aucune donnée sur personne (§ 2, « Flux sortants »). |
| Personnes vulnérables | **Oui, si le site est l'employeur** | Les salariés sont cités par le G29 à cause du rapport de dépendance avec l'employeur. |
| Usage innovant | **Oui, si l'aide d'IA est ouverte** | Réglage `aiopen`, fermé par défaut. |
| Exclusion d'un droit ou d'un contrat | **Non** | Le plugin ne décide rien. |

**Conclusion proposée.** Avant les positionnements de la structure, sans l'aide d'IA : un seul critère certain (salariés) et un possible
(grande échelle) ; l'AIPD était **recommandée**. **Avec les positionnements (4 novembre 2026), un site qui est l'employeur de ses vaeistes et
s'en sert réunit deux critères (salariés, évaluation) : l'AIPD est requise, même sans aide d'IA**, et le présent document tient lieu de base.
**Avec l'aide d'IA ouverte, deux critères au moins : l'AIPD est requise.** La liste de
la CNIL cite les dossiers des résidents d'EHPAD et de CCAS : ce plugin **n'est pas** un dossier de résident, et il est conçu pour ne
jamais en contenir (§ 3).

## 2. Description du traitement

**Finalité.** Aider un professionnel du secteur médico-social à constituer son dossier de validation des acquis de l'expérience
(VAE) : déclarer une activité, la faire attester par un responsable, préparer les textes du dossier de faisabilité et du dossier de
validation, exporter une archive pour le jury ; et, depuis les positionnements, **dire par écrit où en est la structure sur chaque
compétence et sur chaque bloc du vaeiste** (l'évaluer, le certifier). C'est un positionnement interne, qui n'est jamais la validation :
seul le jury valide, hors de la plateforme.

**Personnes concernées.** Les vaeistes (professionnels) ; les responsables qui attestent (nom, avis, questions) ; les responsables qui
suivent un vaeiste et la direction, quand ils évaluent une compétence ou certifient un bloc (leur compte est l'auteur de la ligne) ; en principe pas les
personnes accompagnées ou soignées, **qui ne doivent jamais être nommées ni reconnaissables**.

**Données traitées** (détail dans l'API de vie privée du plugin, `classes/privacy/provider.php`, 22 tables dont 2 sans donnée personnelle) :

| Catégorie | Contenu | Où |
|---|---|---|
| Déclarations et attestations | l'activité déclarée, la situation (récit de pratique), l'attestation d'un responsable, ses pièces | tables `evidence`, `attest`, `attest_piece` |
| Pièces justificatives | nature (liste fermée), intitulé, type, taille, empreinte SHA-256, fichier ou lien | `piece`, fichiers du cœur |
| Dossier de faisabilité et de validation | rubriques de texte, **identité** (nom, date de naissance, nationalité), versions, dépôts | `draft`, `version`, `deposit` |
| Avis et questions des responsables | texte libre | `opinion`, `request` |
| Positionnements de la structure (T-90, plan 6, évaluer et certifier, dit « lot 3 » dans le code ; échéance, plan 7) | **Évaluation d'une compétence** : fiche, rang, statut (à construire, en cours de preuve, suffisante pour certification, retirée) et **motif écrit** (texte libre, obligatoire, 4 000 caractères au plus). **Certification d'un bloc** : statut (certifié par la structure, retiré), motif écrit, **date de la décision**, **motif de l'alerte** (texte libre, exigé seulement pour le statut « certifié par la structure », et seulement quand une compétence du bloc n'est pas encore suffisante ; refusé dans tous les autres cas, y compris sur un retrait) et **dernier jour où la certification tient** (« valable jusqu'au »), facultatif. Pour chaque ligne : identifiant, empreinte SHA-256, date d'écriture, auteur (son compte ; pour une ligne restaurée d'une archive, l'identifiant opaque donné par l'archive). **Ajout seul** : une correction ou un retrait est une nouvelle ligne, la dernière fait foi | `eval`, `certif` |
| Journal de bord (T-14) | texte privé, lu par son seul auteur | `draft` (type réflexif) |
| Ouvertures de pièces | qui a ouvert quoi, quand | `piece_open` |
| Aide à la reformulation | appels (qui, quand, jamais le texte), texte et réponse (conservés par le cœur) | `aicall`, tables du cœur `core_ai` |
| Archives | registre des archives délivrées, fichier zip du vaeiste | `archive`, fichiers du cœur |
| Plans et cours du cœur (page « Mon parcours ») | nom et statut des plans de formation du vaeiste, cours et activités rattachés aux compétences de sa fiche ; **lus** dans le cœur à chaque affichage, jamais copiés ni écrits ; le vaeiste les voit sur lui-même ; **le responsable qui le suit ne voit que le nom et le statut de ses plans (onglet « Vaeistes suivis »), et seulement s'il détient le droit du cœur de les lire** (`moodle/competency:planview` ou `planmanage` dans le contexte du vaeiste, et `planviewdraft` ou `planmanagedraft` pour les brouillons ; droits du cœur de Moodle, jamais accordés par ce plugin (`db/access.php` n'en définit aucun) ; le seul défaut que le code mentionne est `planview` pour l'archétype gestionnaire (commentaire de `competency_gateway::can_view_plans_of()`), les défauts des trois autres sont ceux du cœur et des rôles du site, **non vérifiés ici**) ; jamais la note, jamais les compétences du plan. Sous « Se connecter en tant que » un responsable, l'administrateur lit les mêmes choses que ce responsable (comme sur « Mes vaeistes ») | aucune table du plugin ; tables du cœur `competency_plan`, `competency_usercompplan`, `competency_templatecomp`, `competency_plancomp`, `competency`, `competency_coursecomp`, `course` (nom complet et visibilité des cours), `competency_modulecomp`, `course_modules`, `modules` (lecture directe en SQL, sans l'API du cœur pour les cours rattachés) ; le nom des activités rattachées est lu par les informations de module du cœur |
| Configuration du dispositif (onglet « Organisation », lue par qui détient `tool/vae:viewany`) | par fiche : **nombre exact de plans de formation** qui la citent (comptes ayant une trace dans le plugin ; **brouillons compris ; aucun seuil ; aucun nom de plan ni de personne**), cours, activités et modèles du cœur rattachés (le nom d'un cours ou d'un modèle caché, ou d'une activité que le lecteur ne peut pas voir, n'est donné qu'à qui le cœur le montre), nom complet des référents de bloc ; pour chaque droit du plugin, les rôles du site qui l'accordent, le retirent ou l'interdisent ; les cohortes dont les membres sont suivis (**toutes nommées, masquées comprises**) et leur rôle, jamais le responsable ; les réglages d'ouverture. **Risques acceptés par le porteur le 4/11/2026** : (1) le nombre de plans montre à un lecteur que le cœur refuse (aucun droit sur les plans) l'existence de plans et de brouillons ; (2) un petit nombre (1 ou 2) sur une fiche suivie par peu de vaeistes peut désigner une personne pour qui connaît le site ; (3) le nom d'une cohorte masquée peut nommer un groupe de personnes. Non mesuré sur un site réel | aucune table du plugin ; tables du cœur `competency_plan`, `competency_usercompplan`, `competency_templatecomp`, `competency_plancomp` (comptes seulement), `competency_template` (nom court et visibilité de chaque modèle ; le nom est affiché), `competency_framework`, `competency`, `competency_coursecomp`, `course` (nom complet et visibilité des cours), `competency_modulecomp`, `course_modules`, `modules` (activités rattachées ; leur nom est lu par les informations de module du cœur), `user` (nom des référents de bloc), `role_capabilities`, `cohort`, `tool_cohortroles` (cohorte et rôle seulement, jamais le responsable) |
| Autres | correspondance d'identifiants, référents, reports de fiche, hors-activité | `identity`, `referent`, `carry`, `passage`, `scope`, `source` |

**Destinataires.** Le vaeiste ; les responsables habilités à l'attester (jamais le récit dans les messages) ; l'administrateur du
site ; **le vaeiste reçoit un message à chaque ligne de positionnement écrite sur lui, sans contenu** (ci-dessous, « Information du vaeiste ») ; **le jury, par l'archive que le vaeiste choisit d'exporter, qui porte les positionnements de la structure avec leurs motifs** (la sortie est
alors hors de la plateforme) ; le fournisseur d'IA, **si et seulement si** le vaeiste confirme un envoi. Pour les positionnements, qui les lit
est dit plus bas (« Positionnements de la structure »).

**Flux sortants.** Hors l'aide d'IA (l'envoi est alors fait par le cœur de Moodle, à chaque confirmation du vaeiste), le plugin ne fait
**qu'une seule requête sortante qui lui soit propre**, facultative : aller chercher l'export public de France compétences sur data.gouv.fr
(0.47.0). Elle ne concerne aucune personne du site.

- **Sur un clic, jamais planifiée.** Elle part du bouton « Aller chercher le dernier export » de l'écran d'import des fiches, quand une
  personne qui détient le droit `tool/vae:import` (par défaut, l'administrateur du site seul) le presse. Aucune tâche planifiée ne
  l'appelle, aucun réglage ne l'active.
- **Aucune donnée sur personne** : ni vaeiste, ni responsable, ni administrateur. Les numéros de fiche que l'administrateur tape ne partent
  pas : ils servent à choisir des lignes dans l'export reçu, et ne composent aucune adresse. La requête **révèle** seulement à data.gouv.fr
  l'adresse IP du serveur (ou celle de son proxy), l'heure et un User-Agent fixe, qui ne donne pas l'adresse du site.
- **Deux hôtes écrits dans le code** : `www.data.gouv.fr` (qui dit quel fichier est le dernier) et `static.data.gouv.fr` (qui le sert), en
  https, certificat vérifié. Aucune adresse ne vient d'une saisie ou d'un réglage : la première est reconstruite d'une constante et de l'identifiant (contrôlé) de la
  ressource, et la seule autre que le plugin suive est celle que l'API annonce pour ce fichier, validée d'avance (https, `static.data.gouv.fr`,
  chemin sous `/resources/`, nom du fichier) et que la redirection doit répéter à l'identique.
- **Le zip entier transite par le serveur le temps de la requête** (environ 10 Mo), y compris ses fichiers « Partenaires » et
  « Certificateurs » (noms et SIRET d'organismes), **qui ne sont ni lus ni gardés** : le plugin n'ouvre que deux fichiers de l'archive
  (« Blocs de compétences » et « Standard »), n'extrait rien sur le disque, et supprime l'archive dès l'aperçu préparé. Il ne garde,
  deux heures (et de nouveau deux heures après la confirmation, le temps de relire le résultat ; le fichier n'est effacé qu'à sa prochaine ouverture ou par le nettoyage du dossier temporaire de Moodle), qu'un plan de quelques kilo-octets (les fiches demandées, qui sont publiques, et le numéro du compte de l'administrateur).
  Que certains de ces organismes soient des personnes physiques : non vérifié, puisque ces fichiers ne sont jamais ouverts.
- **Une trace à chaque clic qui a fait partir une requête**, aboutie ou refusée : l'événement `export_fetched` (ressource, taille, empreinte, résultat ; aucune donnée sur
  personne), et `record_imported` pour chaque fiche écrite. Comme tout événement de Moodle, ils sont rattachés au compte de l'administrateur
  qui a cliqué et gardés selon le « Nettoyage des journaux » du site.
- **Pas de sous-traitant, et rien de plus à déclarer** : data.gouv.fr ne reçoit aucune donnée personnelle (fiche T-84, § 6), et la
  déclaration du fournisseur de vie privée ne change pas pour cette fonction (fiche T-84, § 1). Le site qui ne veut pas de cette requête,
  ou dont le serveur ne sort pas sur Internet, utilise le **dépôt du zip** : l'administrateur télécharge lui-même l'archive et la dépose ;
  le serveur ne fait alors aucune requête.
- **Contrôles et limites.** Une seule classe du plugin (`fiche_fetcher`) peut nommer un client HTTP, curl ou une ouverture de connexion,
  et rien de ce qui est planifié ne l'appelle (mesure compensatoire MC-30 : `scripts/sortie-reseau.sh`, et un test PHPUnit qui lit les
  mêmes sources). Ces contrôles lisent le code du plugin ; ils ne voient pas ce que fait le cœur de Moodle, par exemple pour l'aide d'IA.
  **Non vérifié à la date de ce document** : la récupération contre le vrai data.gouv.fr avec ce code (ses tests jouent un simulateur de
  réponses, sans requête réelle), et l'hébergement réel du site, sortie réseau comprise.

**Positionnements de la structure (T-90, plan 6, évaluer et certifier, dit « lot 3 » dans le code ; échéance, plan 7).** Un responsable évalue une compétence, la direction certifie un bloc :
c'est le **positionnement de la structure**, interne à la plateforme. **Ce n'est jamais la validation**, que seul le jury donne, hors de la
plateforme : chaque écran, la copie, le PDF et le fichier d'archive le disent (dans le fichier d'archive, la clé `officiel` est toujours fausse). Ce qui suit est lu dans le
code (`evaluation_manager`, `certification_manager`, `positioning_rules`, `positioning_archive`, `positioning_screen`, `renewal_report`,
`purge`, `record_remover`, `privacy\provider`), non dans les plans. Ne pas confondre ce « lot 3 » avec les commits « T-90 lot 3 (a) à (d) », qui sont l'onglet « Vaeistes suivis » (plan 2) : autre numérotation, celle de la première conception.

- **Qui écrit.** *Évaluer une compétence* : qui **suit** le vaeiste (droit `tool/vae:viewassigned` dans le contexte du vaeiste, refusé s'il
  est détenu au niveau du site), détient `tool/vae:evaluate` dans ce contexte et peut lire le dossier ; jamais sur soi-même ; l'administrateur
  du site, qui ne suit personne, n'évalue pas à la place du responsable. *Certifier un bloc* : qui détient à la fois `tool/vae:viewany` et
  `tool/vae:certify` au niveau du site (aucun archétype ne les accorde) ; jamais sur soi-même. Dans les deux cas, le vaeiste doit avoir déclaré au
  moins une fois sur la fiche. `certification_manager::can_write()` ne teste que ces deux droits et l'identité : l'administrateur du site, à qui
  Moodle accorde tous les droits, peut donc certifier le bloc d'un autre (déduit du code, non rejoué).
- **Qui lit.** Qui peut lire le dossier : le vaeiste, qui détient `viewany`, qui le suit. **Lire ne demande ni `evaluate` ni `certify`** : un
  responsable qui suit le vaeiste lit donc aussi les certifications de la direction et le motif de leur alerte, et les évaluations écrites par ses
  collègues. Le texte libre est affiché en texte brut. S'y ajoute la liste « Certifications à renouveler » (`renewals.php`, sous Compétences),
  **nominative** (nom du vaeiste, fiche, bloc, dernier jour, jours depuis), pour qui détient `viewany` **et** `certify`.
- **Ajout seul.** Les deux gestionnaires n'ont ni fonction de modification ni fonction de suppression d'une ligne ; seuls l'effacement
  (ci-dessous) et la restauration d'une archive, qui ajoute, écrivent autrement. Une ligne qui répète la dernière est refusée ; les écritures d'un
  même vaeiste sur une même fiche passent par un verrou. L'empreinte SHA-256 permet de comparer une ligne à celle de l'archive ; elle
  **détecte l'accident, non la malveillance** (limite notée dans les preuves du plan 7).
- **Ce que le serveur calcule, et quand.** (a) *L'alerte*, **à l'écriture** : les compétences du bloc dont la dernière ligne n'est pas
  « suffisante » (jamais évaluée, ou retirée, comprises ; celles que la source a retirées de la fiche, exclues). Son motif est exigé pour
  une certification s'il en manque, refusé sinon (et toujours refusé sur un retrait) : la certification n'est jamais bloquée, elle doit être
  expliquée. La même liste est recalculée **à la lecture** sur la page de certification (`certify.php`) et affichée à **quiconque peut lire cette page** (le vaeiste,
  qui détient `viewany`, qui le suit), pas seulement à la personne qui s'apprête à certifier (seule `access::require_can_view()` précède l'appel) ; elle n'y
  est pas écrite. (b) *« À renouveler »*, **à la lecture**, au jour du
  lecteur dans son fuseau : le dernier jour posé par la direction est passé. **Rien n'est écrit quand une date passe**, aucun message, aucune
  tâche : `classes/notifier.php` et `classes/task` ne lisent ni la date « valable jusqu'au » ni la liste à renouveler (le seul message du plugin sur ces lignes est celui de l'écriture d'une ligne, ci-dessous, « Information du vaeiste »). (c) *« Des compétences de ce bloc ont été positionnées après cette
  certification »*, à la lecture. Le vaeiste lit le même texte neutre et n'a aucun bouton « Renouveler ». Les copies et archives portent
  « valable jusqu'au », jamais « à renouveler ».
- **Ce qui est lu sans être copié.** Sur une fiche qui en remplace une autre, quand un administrateur a décidé une correspondance entre deux
  compétences ou deux blocs, l'écran montre en lecture « positionné sur l'ancienne fiche … : statut, date » (et le dernier jour) : jamais le motif,
  jamais de copie ni de report.
- **Journal.** Les événements `evaluation_recorded` et `certification_recorded` portent les identifiants (auteur, vaeiste concerné), la fiche, le
  rang et le statut ; **jamais le motif**. Gardés selon le « Nettoyage des journaux » du site.
- **Information du vaeiste (décision D2 du 5 novembre 2026, code commité (`e9d8733`), build 2026110404).** À chaque ligne écrite, évaluation ou certification, le
  vaeiste reçoit un message (fournisseur `positioningrecorded`, `db/messages.php`, droit `tool/vae:viewown`). Lu dans le code : les observateurs
  de `db/events.php` appellent `notifier::evaluation_recorded` et `notifier::certification_recorded`, qui mettent en file d'attente une tâche
  asynchrone ; elle part au prochain passage du cron, comme les autres messages du plugin, **un message par ligne écrite** (aucune fusion). Il dit qu'une
  ligne a été ajoutée à son positionnement, rappelle que ce n'est pas une validation (phrase `positioningdisclaimer`), renvoie au dossier par un
  lien et indique le délégué à la protection des données du site pour une rectification, une limitation ou une opposition. **Il ne dit ni le
  statut, ni le motif, ni l'auteur** (chaînes `notifypositioningrecorded_subject` et `_body`, fr et en ; un test PHPUnit le contrôle avec un
  motif, un nom et un statut connus). Il n'est envoyé qu'au vaeiste concerné, et seulement s'il peut encore ouvrir son dossier (droit `viewown`) et
  qu'une ligne d'évaluation ou de certification existe encore pour lui au moment de l'envoi (`notifier::deliver_positioning`). Le fournisseur est
  « permis, activé par défaut » (popup et courriel) : la personne peut le désactiver dans ses préférences de notification, et l'administrateur
  aussi par les réglages de notification du site, comme pour les autres messages du plugin ; **un vaeiste qui l'a désactivé n'est donc pas prévenu**, et la
  clause du contrat d'usage (ci-dessous) reste la première information. Seuls les deux gestionnaires d'écriture déclenchent les événements : une ligne
  qui entre par la restauration d'une archive n'envoie aucun message (lu, non rejoué). **Vérifié le 5 novembre 2026 par un dialogue SMTP réel vers un récepteur local de test** (message relu : ni statut, ni motif, ni auteur) ; **non vérifié : l'envoi par le serveur de courriel d'un site réel** (les tests
  jouent la file de messages de Moodle, sans serveur).
- **Sorties.** *Archive de la structure et sauvegarde* : le fichier `positionnement.json` (schéma 0.1.0, contrôlé à l'import, 5 000 lignes au plus) est dans
  l'archive de la structure et dans la sauvegarde d'un dossier qui porte au moins une ligne ; **depuis la décision du porteur du 7 novembre 2026, il n'est plus
  dans l'archive que le vaeiste emporte ni dans ses copies Word, ODT et PDF** (le vaeiste lit toujours ses lignes à l'écran) ; il contient **toutes les lignes, historique compris, les motifs en clair**, l'intitulé de la compétence ou du bloc (lu dans la fiche au moment de l'archive, non conservé dans `eval` ni `certif`), l'indication de
  la ligne qui fait foi, et l'auteur par un **identifiant opaque** (aucun nom ; absent si le compte de l'auteur est supprimé). *Sauvegardes* durables et
  sauvegarde faite avant une désinstallation : elles les portent. *Export de la vie privée de Moodle* : le vaeiste reçoit toutes les lignes écrites sur lui ; un auteur reçoit celles qu'il a
  écrites, **motif compris** (texte sur un tiers : point à examiner par le DPO). *Restauration* d'une archive : elle **ajoute** les lignes d'une
  clé (fiche, rang) qui n'en porte pas encore, laisse telles quelles les autres, écrit l'auteur par son identifiant opaque (sans compte) et marque la
  ligne « restaurée depuis une archive ».
- **Conservation (décision D4 du 5 novembre 2026).** Celle du dossier, plus un effacement à la demande du DPO du site ; aucune purge automatique propre :
  aucune tâche n'efface une ligne à cause de son âge, la ligne est en ajout seul. Une ligne, avec son historique, **reste tant que
  dure le compte du vaeiste** et part avec lui (fiche T-84 § 3). **Le code n'offre aucun écran ni aucune fonction qui efface une seule ligne** (lu : les deux
  gestionnaires n'ont ni modification ni suppression) : la demande du DPO se joue par l'effacement du dossier du vaeiste, ou par une intervention du
  site sur la base ; ce choix technique est celui du site (non vérifié). Une ligne empêche aussi
  de retirer la ligne d'une fiche importée (`record_remover` refuse tant qu'une évaluation ou une certification existe sur ce code).
  Désinstallation : la sauvegarde préalable emporte les positionnements des dossiers qui portent au moins une déclaration ; **ceux d'un vaeiste
  sans déclaration ne sont pas sauvegardés** et disparaissent avec les tables.
- **Effacement.** *Du vaeiste* (suppression du compte, ou demande d'effacement) : `purge::dossier` supprime ses lignes d'évaluation et de
  certification dans la transaction qui supprime le reste du dossier, puis les archives en attente, les sauvegardes et les copies datées ; la
  tâche `reconcile_orphans` rattrape une suppression interrompue. Les copies déjà remises (jury, vaeiste) échappent au plugin. *D'un auteur*
  (demande d'effacement du RGPD) : son compte et son identifiant d'archive sont vidés sur les lignes qu'il a écrites dans chaque dossier ;
  **le texte reste**, parce qu'il est écrit sur le vaeiste, qui a le droit de le lire (même règle que pour les avis et les questions) ; les
  archives en attente sont supprimées et les sauvegardes refaites. L'écran écrit alors « une personne dont le compte n'existe plus ».
  **Limite lue dans le code** : la suppression du compte d'un auteur n'est pas traitée par l'observateur du plugin, qui ne s'occupe que du
  vaeiste ; le numéro de compte reste dans la ligne tant que la demande d'effacement n'a pas été jouée pour cette personne (comme pour les
  textes de l'aide d'IA, plus bas : elle dépend de l'approbation de la demande que Moodle crée à la suppression du compte). L'écran et l'archive
  le traitent déjà comme un auteur disparu ; la base le garde.
- **Non vérifié à la date de ce document.** L'essai en HTTP réel de la liste et des formulaires sur l'instance locale, MariaDB et
  PostgreSQL (preuves du plan 7) ; les textes affichés (« certifié par la structure », « à renouveler », « valable jusqu'au »), à relire par un
  accompagnateur VAE ; l'envoi du message au vaeiste par le serveur de courriel d'un site réel (un dialogue SMTP vers un récepteur local de test a été relu le 5 novembre 2026) ; l'affichage réel du rappel sur les deux formulaires (contrôlé par PHPUnit, `tests/positioning_test.php`, non rejoué pour cette mise à jour) ; la rédaction de la clause du contrat d'usage, que le site fait valider par son DPO ; ce que l'export de
  Moodle écrit du nom du contexte du vaeiste dans l'export d'un auteur ; toute mesure sur un site réel.

#### Décisions du 5 novembre 2026 (porteur du plugin, qui est aussi le DPO du site)

| | Décision | Où dans ce document |
|---|---|---|
| D1 | Base légale de T9, T10, T12 et du nom des référents de bloc (T11) : **le contrat d'usage**, comme T1 à T7 (article 6, paragraphe 1, point b). Plus de point ouvert | Ici ; R12 |
| D2 | Information du vaeiste : **une clause du contrat d'usage ET un message à chaque ligne écrite** | « Information du vaeiste » ci-dessus ; R12, § 6 |
| D3 | Rappel « ne nommez aucune personne accompagnée » **ajouté** sur le formulaire d'évaluation (au-dessus du motif) et sur celui de certification (au-dessus du motif et au-dessus du motif d'alerte) : `classes/form/evaluation_form.php`, `classes/form/certification_form.php`, chaîne `anonymiseshort` | R2, R9 |
| D4 | Durée : celle du dossier, plus effacement à la demande du DPO ; pas de purge propre | « Conservation » ci-dessus |
| D5 | Vaeiste qui conteste une ligne : **le DPO du site, par la clause du contrat d'usage, sans écran**. Rectification = nouvelle ligne écrite par la structure (ajout seul) ; limitation et opposition par le DPO | R12, § 6 |
| D6 | Ligne dont l'auteur est effacé : le texte reste, l'identité part. **Confirmé** | « Effacement » ci-dessus |
| D7 | Comptes inactifs : décision de l'administrateur du site, à réexaminer tous les 36 mois ; le plugin ne supprime aucun compte de lui-même (le réglage est celui de Moodle). **Formule à confirmer dans sa rédaction exacte** : le porteur a dit « décision de l'administrateur, relancer tous les 36 mois » | « Durées », ci-dessous |
| D8 | Copies hebdomadaires et sauvegardes de l'administrateur : politique du site à écrire par le DPO (fiche T-84, § 6), aucun code ; la durée reste « à fixer par le site » | « Durées », ci-dessous |

Le code de D2 et de D3 est **commité** (`e9d8733`). Le porteur étant aussi le DPO du site, ces décisions sont les siennes ; le DPO d'un autre site installateur les revoit au § 7. Le 6 novembre 2026, il a aussi confirmé que l'article 22 n'est pas en jeu : aucun positionnement n'est écrit sans qu'une personne agisse (T-84, § 2).

**Base légale (D1).** Le contrat d'usage porte T9 (évaluation), T10 (certification), T12 (liste à renouveler) et le nom des référents de bloc (T11), comme T1 à T7. Les
réserves de la fiche T-84 § 2 (nécessité objective, rapport d'emploi si le site est l'employeur) valent pour eux comme pour le reste ; elles ne sont pas
levées par cette décision. Le modèle `docs/MODELE-CONTRAT-USAGE-PLATEFORME.md` ne parle pas de positionnement (lu : aucune occurrence) : le site y ajoute la clause ci-dessous.

**Clause à prévoir dans le contrat d'usage (D2, D5).** Que la structure consigne par écrit des positionnements sur le vaeiste (évaluation d'une compétence, certification d'un bloc), avec un motif ; que ce n'est pas une validation, que seul le jury valide ; que le vaeiste les lit
dans son dossier ; qu'elles sont conservées avec le dossier et partent dans l'archive qu'il remet au jury ; qu'il en est averti par un message qui ne dit pas ce que la ligne contient ; et que, pour la contester, demander sa rectification, sa limitation, son opposition ou
l'effacement, il s'adresse au DPO du site (une rectification se fait par une nouvelle ligne de la structure).

**Durées** : voir `docs/T-84-conformite-rgpd.md` § 3 (et « Positionnements de la structure », « Conservation » et « Effacement », ci-dessus). En résumé : jusqu'à la suppression du compte, sauf ce qui est purgé plus tôt
(appels d'IA : 3 jours ; archives : 24 heures) ; **évaluations et certifications : celle du dossier, plus l'effacement à la demande du DPO du site, sans purge propre (D4)** ; les textes de l'aide d'IA que garde le cœur ne sont vidés qu'à l'approbation de la demande de
suppression de données que Moodle crée à la suppression du compte, et seulement si le site crée ces demandes de lui-même et n'a pas défini la
finalité et la catégorie du site dans son registre des données (sinon ils restent). Après l'effacement, Moodle garde une ligne de registre d'usage
(voir R6 et `docs/T-84-conformite-rgpd.md` § 3).
**Comptes inactifs (D7)** : la suppression d'un compte inactif est une décision de l'administrateur du site, à réexaminer tous les 36 mois ; le plugin ne supprime aucun compte de lui-même, le réglage est celui de Moodle (formule à confirmer dans sa rédaction exacte). **Copies hebdomadaires et sauvegardes de l'administrateur (D8)** : le code n'en borne pas la durée au-delà des remplacements décrits en fiche T-84 § 3 ; la politique du site est à écrire par le DPO (fiche T-84, § 6), sans code, et la durée reste à fixer par le site.

## 3. Risques pour les personnes

Gravité et vraisemblance sont celles **avant** les mesures, à l'échelle d'un site de taille moyenne ; elles se réévaluent selon
l'hébergement (§ 5).

| # | Risque | Gravité | Vraisemblance | Conséquence pour la personne |
|---|---|---|---|---|
| R1 | **Accès illégitime** au dossier d'un vaeiste (compte volé, droit trop large, administrateur curieux, vol de serveur) | Importante | Moyenne | Divulgation d'identité, de pratique et d'avis, atteinte à la carrière |
| R2 | **Donnée de santé d'un tiers** écrite ou jointe par erreur (nom d'un résident, transmission de soins en pièce) | Maximale | Faible à moyenne | Atteinte au secret médical d'une personne qui ne sait pas qu'elle figure là |
| R3 | **Modification ou perte** d'un dossier (panne, mauvaise sauvegarde, écrasement) | Moyenne | Faible | Perte de preuves, de temps, d'un délai de VAE |
| R4 | **Envoi à un fournisseur d'IA** de ce qui ne devait pas partir (nom, tiers), conservation chez lui, ré-utilisation pour son entraînement | Importante | Moyenne si l'aide est ouverte | Données sorties du site, parfois hors de l'Union européenne |
| R5 | **Reformulation qui change un fait** et que le vaeiste reprend sans relire | Moyenne | Moyenne | Un dossier inexact, une attestation sur un texte altéré |
| R6 | **Conservation excessive** ou impossibilité d'obtenir ses données avant effacement | Moyenne | Moyenne | Atteinte aux droits d'accès, de portabilité, d'effacement |
| R7 | **Archive remise à un jury** copiée, transmise ou conservée sans maîtrise | Importante | Moyenne | Perte de maîtrise sur les copies (le plugin ne peut pas les rappeler) ; l'archive porte aussi les positionnements de la structure, motifs en clair (R8 à R12) |
| R8 | **Un positionnement lu comme une validation** : « certifié par la structure », « suffisante pour certification » ou « certification » pris pour la décision d'un jury ou pour un titre, par le vaeiste, un employeur, un jury ou un tiers à qui l'archive est remise | Importante | Moyenne | Fausse assurance, ou fausse accusation, sur une qualification que seul le jury donne ; atteinte à la carrière |
| R9 | **Texte libre** (motif, motif de l'alerte) : jugement d'un tiers sur la personne, propos excessifs ou inexacts, donnée de santé du vaeiste ou d'une personne accompagnée écrite par erreur (le rappel de ne nommer personne est sur les deux formulaires depuis le 5 novembre 2026, § 4, mais il ne dispense pas de former ceux qui écrivent) | Importante | Moyenne | Atteinte à la réputation et à la carrière ; secret médical d'un tiers ; lu par tous ceux qui suivent le vaeiste et par le jury |
| R10 | **L'alerte** : certifier un bloc dont des compétences ne sont pas suffisantes fait écrire, et afficher dans le dossier puis partir dans l'archive, le motif de l'alerte ; la liste calculée de ce qui manque est montrée sur la page de certification | Moyenne | Moyenne | Un manque lu comme une faute de la personne, ou comme un défaut de la structure ; un texte qui part chez le jury |
| R11 | **L'échéance calculée à la lecture** : « à renouveler » n'est écrit nulle part, il dépend du jour et du fuseau de celui qui lit ; la liste des certifications à renouveler nomme les vaeistes | Moyenne | Moyenne | Un jour d'écart selon le lecteur ; une échéance passée lue comme un jugement sur la personne ou comme un classement ; aucune trace de ce que le lecteur a vu |
| R12 | **Une ligne ne se corrige pas** (ajout seul) et reste tant que dure le compte, y compris dans les archives et sauvegardes déjà faites | Moyenne | Moyenne | Rectification d'une ligne par une ligne de plus seulement, effacement d'une seule ligne sans écran (par le DPO du site, D4, D5) ; historique complet remis au jury ; numéro de compte de l'auteur gardé après la suppression de son compte, tant que l'effacement n'est pas joué |

R8 à R12 (4 novembre 2026) : estimation de l'équipe, non mesurée sur un site réel.

## 4. Mesures déjà prévues par le plugin

| Risque | Mesures dans le code (version 2.5.03 (Moodle 5.3), build 2026110405, de code identique à celui du build 2026110404) |
|---|---|
| R1 | Droits par rôle ; le récit n'est visible que du vaeiste, de qui peut l'attester et de l'administrateur ; **lire un récit n'ouvre pas la date de naissance** (droit `tool/vae:identityview` à part, donné à aucun archétype) ; une liste de pièces sans ouverture pour qui ne peut que lire ; téléchargement forcé ; **le journal de bord n'est lu que par son auteur** ; les plans de formation d'un vaeiste ne sont lus par le responsable qui le suit que sous le droit du cœur de les lire, vaeiste par vaeiste ; jamais un total ni une jauge par responsable ; messages sans récit ; journaux sans texte ; export d'un tiers sans la préparation non déposée ; **positionnements** : écrits seulement par qui suit le vaeiste et détient `tool/vae:evaluate`, ou par qui détient `viewany` et `certify` (aucun archétype), jamais sur soi-même, lus seulement par qui peut lire le dossier, journaux sans motif |
| R2 | **Nature des pièces en liste fermée** ; rappel de ne citer aucune personne accompagnée sur les formulaires de texte libre suivants : déclaration, rubrique, hors-activité (rappel court), attestation, **réponse** à une demande de précision, pièces (rappel propre aux pièces), **évaluation d'une compétence et certification d'un bloc (D3, 5 novembre 2026, code commité : voir R9)** et chaque envoi à l'aide d'IA ; **pas** sur la **question** de précision posée par le responsable, ni sur la note d'un dépôt, ni sur l'avis écrit sur un dépôt (lus dans `classes/form` et `deposit.php`, non rejoués) ; antivirus exigé pour les fichiers (sinon refusés, sauf acceptation écrite de l'administrateur) ; fonction de pièces **fermée par défaut** ; le plugin n'est pas un dossier de résident |
| R3 | Versions de chaque rubrique jamais modifiées ; dépôts figés ; empreintes SHA-256 recomparées à chaque export ; sauvegarde hebdomadaire optionnelle ; archive qui se lit sans la plateforme |
| R4 | **Fermée par défaut** ; droit `tool/vae:useai` donné à la main ; un seul fournisseur actif (sinon rien n'est envoyé) ; politique d'IA du site (le texte du cœur) acceptée par le vaeiste ; **confirmation à chaque envoi** montrant le texte exact et le nom du fournisseur ; jeton de confirmation à usage unique ; plafonds par jour et pause ; rien d'autre du dossier n'est envoyé ; un code opaque désigne le vaeiste |
| R5 | Réponse affichée à côté de l'original, jamais enregistrée par le plugin ; mention « à relire » ; le vaeiste copie à la main |
| R6 | Interface de vie privée de Moodle (export et effacement) ; **archive d'export structurée, lisible sans la plateforme** (schéma publié) ; effacement des données du plugin à la suppression du compte ; textes de l'aide d'IA (tables du cœur) **non effacés par le plugin** : vidés à l'approbation de la demande de suppression de données que Moodle crée à la suppression du compte (tant qu'elle n'est pas approuvée, ou si le site a désactivé sa création automatique, ou si le site a défini la finalité et la catégorie du site dans le registre des données, ils restent lisibles en clair pour qui a accès à la base : Moodle ne vide alors jamais ces textes, même demande approuvée et déclarée terminée, et il faut les faire effacer à la main) ; **le cœur garde dans tous les cas une ligne de registre après l'effacement** : numéro du compte (rattaché au nom du vaeiste dans Moodle), date, fournisseur, modèle, taille de l'échange en jetons, réussite ou échec et, après un échec, message d'erreur du fournisseur ; purge des appels d'IA et des archives ; **positionnements** : toutes les lignes exportées au vaeiste, les siennes à l'auteur, supprimées avec le dossier ; l'auteur effacé quitte la ligne et le texte reste (§ 2, « Effacement ») |
| R7 | L'archive dit dans son manifeste et sa notice qu'elle est figée et que ses copies échappent au plugin ; elle est supprimée de la plateforme au bout de 24 heures ; `positionnement.json` porte sa propre phrase de non-validation (R8) |
| R8 | La phrase « Ce n'est pas une validation : seul le jury valide, hors de cette plateforme » est en tête de la section du dossier, des pages d'évaluation et de certification et de la liste à renouveler ; la copie, le PDF et le fichier d'archive portent une phrase du même sens (« Positionnement de la structure, non validation : seul le jury valide, hors de la plateforme… ») ; dans le fichier d'archive seul, la clé `officiel` est toujours fausse ; statuts écrits « certifié par la structure » et « suffisante pour certification », jamais « validé » ; un test PHPUnit (`test_no_word_of_validation_in_the_new_strings`, `tests/positioning_test.php`, non rejoué pour cette mise à jour) refuse les mots de validation (et « acquis », « candidat », « taux », « note », « score » en français) dans les seules chaînes dont la clé commence par un préfixe de liste fixe : `vae:evaluate`, `vae:certify`, `eval`, `certif`, `eventevaluation`, `eventcertification`, `errorpositioning`, `errorcertification`, `renewals`, `privacy:metadata:certif:validuntil` (une seule clé), et parmi les `positioning*` seulement `positioninguntil`, `positioningvaliduntil`, `positioningexpired`, `positioningrenew`, `positioningtorenew`, `positioningonold`. **Il ne lit pas les autres chaînes affichées** (`positioningalert*`, `positioningcertifyintro`, `positioningevaluateintro`, `positioningstale`, les titres, libellés, historique et phrases du PDF) ni les trois phrases d'avertissement (`positioningdisclaimer`, `positioningarchivesentence`, `positioningdossierheading`), qui disent « validation » pour la nier ; une recherche du mot dans les chaînes `positioning*` françaises et anglaises ne trouve que ces trois phrases (relevé à la main, non rejoué par un test), et aucun contrôle de langue du dépôt ne porte sur ces mots. **Reste** : « certifié » et « certification » peuvent encore se lire comme un titre ; les textes n'ont pas été relus par un accompagnateur VAE (non vérifié) |
| R9 | Motif obligatoire, 4 000 caractères au plus, caractères de contrôle refusés ; affiché en texte brut, sans filtre ; écrit seulement par qui suit le vaeiste ou par la direction ; le vaeiste lit tout ce qui est écrit sur lui ; journaux sans motif. **Rappel ajouté (D3, 5 novembre 2026, code commité)** : la chaîne `anonymiseshort` (« Rappel : ne nommez aucune personne accompagnée (au besoin, un prénom fictif) et ne donnez aucun détail qui la rende reconnaissable. ») est affichée sur le formulaire d'évaluation, au-dessus du motif, et sur celui de certification, au-dessus du motif **et** au-dessus du motif d'alerte (lu dans `classes/form/evaluation_form.php` et `certification_form.php` ; un test PHPUnit compte 1 et 2 occurrences, non rejoué pour cette mise à jour). **Reste** : un rappel ne remplace pas la consigne écrite du site à ceux qui écrivent (§ 7) |
| R10 | Les manques sont calculés par le serveur au moment d'écrire, sous verrou, et le motif est exigé quand il en manque, refusé sinon : la structure dit pourquoi, et la certification n'est jamais bloquée ; la liste (non écrite) ne donne que des intitulés de compétences, lus dans le cœur au moment de l'affichage (`competency_gateway::label_of()` : nom court ou description) : ce sont ceux de la fiche importée, **sauf renommage local** par un administrateur, que le plugin ne contrôle pas |
| R11 | Compté sur deux dates AAAA-MM-JJ, jamais en secondes, au jour du lecteur, que l'écran dit (« au jour de la lecture ») ; le dernier jour compte encore ; rien d'écrit, aucun message à l'échéance ; liste sans total, sans couleur, sans classement de personnes (triée par date de fin), qui dit qu'elle « ne dit rien de la qualité d'un dossier » ; page réservée à qui détient `viewany` et `certify` (absente de l'arbre sinon) ; aucun lien sur sa propre ligne ; comptes supprimés exclus, suspendus gardés |
| R12 | Une ligne qui répète la dernière est refusée ; empreinte SHA-256 de chaque ligne ; effacement du vaeiste complet (archives en attente, sauvegardes, copies datées) ; effacement d'un auteur sur ses lignes ; **le vaeiste est prévenu de chaque ligne par un message sans contenu (D2) et sait à qui s'adresser pour la contester : le DPO du site, par la clause du contrat d'usage (D5)**. **Reste** : aucune modification d'une ligne (on en ajoute une), aucun écran pour la contester, l'historique complet part dans l'archive, le numéro de compte d'un auteur dont le compte est supprimé reste dans la base jusqu'à l'effacement joué (§ 2) |

## 5. Mesures qui dépendent du site : l'hébergement

Le plugin ne choisit ni le serveur, ni la sauvegarde, ni le chiffrement du disque, ni le pare-feu sortant, ni le fournisseur d'IA. **L'AIPD d'un site se fait sur
la combinaison choisie.** Cinq modes d'hébergement, puis quatre modes d'IA.

### 5.1 L'hébergement de la plateforme

Le régime « HDS » (hébergeur de données de santé, article L. 1111-8 du code de la santé publique, texte en vigueur depuis le
1er juillet 2025) vise la personne qui **héberge, pour le compte d'un producteur**, des données de santé recueillies à l'occasion de
prévention, de diagnostic, de soins **ou de suivi social et médico-social**. Les données de ce plugin ne sont pas, par conception,
des données de santé recueillies dans le soin : ce sont des données de formation et de certification d'un professionnel. **Le risque R2
est ce qui peut faire basculer** (une transmission de soins jointe, un nom de résident dans un récit). Le tableau ci-dessous dit, pour
chaque mode, ce que cela change. À confirmer par le DPO.

| Mode | Description | HDS exigé ? | Ce qui change dans le risque | Mesures à ajouter par le site |
|---|---|---|---|---|
| **H1** | Serveur **interne** de l'établissement, sans certification HDS | Non (l'établissement héberge pour lui-même ; pas de tiers hébergeur) | R1 et R3 dépendent de la sécurité du site (accès, sauvegarde, patchs). R2 : l'établissement est déjà tenu par le secret sur les données de ses résidents | Contrôle d'accès physique et logique ; chiffrement du disque ; sauvegardes testées ; correctifs ; journal d'accès ; **un serveur sans sortie vers Internet utilise le dépôt du zip** pour importer les fiches de France compétences (§ 2, « Flux sortants »), et non « Aller chercher le dernier export » |
| **H2** | Serveur **interne** hébergé sur une infrastructure **certifiée HDS** | Oui, si le plugin est présenté comme pouvant recevoir des données de santé | R1, R3 réduits par la certification ; R2 mieux couvert | Contrat d'hébergement conforme à l'article L. 1111-8 ; mêmes mesures que H1 |
| **H3** | Serveur **externe** chez un hébergeur **certifié HDS**, dans l'Union européenne | Oui | R1 et R3 transférés en partie à l'hébergeur ; R2 couvert contractuellement | Contrat ; accès de l'hébergeur tracés ; sous-traitants ultérieurs listés ; restitution et effacement en fin de contrat |
| **H4** | Serveur **externe non certifié HDS**, dans l'Union européenne (mutualisé, VPS) | **Non**, mais **le plugin ne doit alors recevoir aucune donnée de santé** : R2 devient bloquant | R1 : exposition à un tiers sans certification ; R3 selon le contrat | Contrat de sous-traitance (article 28 du RGPD) ; **interdire par écrit** les pièces de soins ; contrôle plus strict des pièces ; chiffrement avec clés détenues par le site |
| **H5** | Serveur **externe hors Union européenne** ou chez un fournisseur soumis à une loi extraterritoriale | Non recommandé | R1 aggravé (accès de puissances publiques étrangères) ; transfert hors UE | Mécanisme de transfert (article 46 du RGPD, analyse d'impact du transfert) ; sinon **ne pas utiliser** |

**Dans tous les modes**, « Aller chercher le dernier export » suppose que le serveur puisse joindre en https `www.data.gouv.fr` et
`static.data.gouv.fr` : un pare-feu sortant, un proxy ou un hébergement mutualisé qui ferme les connexions sortantes l'en empêche. Le plugin
le dit à l'écran et renvoie au dépôt du zip. Aucune donnée personnelle ne part dans cette requête (§ 2, « Flux sortants »).

**Décision à prendre par le site :** un mode, avec ses justifications. En l'absence de pièces de soins (liste fermée, rappel de ne citer personne,
formation des vaeistes), H1 à H4 sont défendables ; **H5 est à éviter**.

### 5.2 Le fournisseur d'IA (si l'aide est ouverte)

Le fournisseur d'IA est choisi par l'administrateur du site, pas par le plugin. Quatre cas :

| Mode | Description | Ce qui change | Mesures du site |
|---|---|---|---|
| **A0** | **Aide fermée** (`aiopen` à 0, par défaut) | Aucun texte envoyé à un fournisseur d'IA. Le critère « usage innovant » tombe | Aucune |
| **A1** | Modèle **local** sur un serveur du site (par exemple Ollama) | Le texte ne quitte pas le site. R4 devient celui de l'hébergement (§ 5.1) | Sécuriser le serveur d'IA comme H1 à H3 ; ne pas journaliser les requêtes |
| **A2** | Fournisseur **dans l'Union européenne**, contrat de sous-traitance | R4 existe mais reste dans le cadre du RGPD | Contrat article 28 ; **pas de ré-utilisation pour l'entraînement** ; durée de conservation chez lui ; liste des sous-traitants ultérieurs |
| **A3** | Fournisseur **hors Union européenne** | R4 aggravé : transfert hors UE, ré-utilisation possible | Mécanisme de transfert et analyse d'impact du transfert ; sinon **ne pas ouvrir l'aide** |

Dans tous les cas : un **seul fournisseur actif**, la **consigne du fournisseur** relue (voir la fiche
`docs/RELECTURE-AIDE-REFORMULATION.md`), le registre des traitements à jour, le vaeiste informé par la politique d'IA du site et par l'écran de
confirmation.

## 6. Nécessité et proportionnalité

- **Finalité déterminée** : la constitution du dossier de VAE, rien d'autre. Aucun usage statistique ou commercial.
- **Minimisation** : l'archive d'export n'emporte ni date de naissance, ni nationalité, ni adresse, ni téléphone (mesure compensatoire MC-13 : un gabarit ne peut pas faire entrer l'identité dans l'archive) ; les
  messages ne portent jamais le récit ; pièces en liste fermée ; l'aide d'IA n'envoie que le texte confirmé ; les positionnements ne portent que fiche,
  rang, intitulé de la compétence ou du bloc (dans l'archive, 1 000 caractères au plus), statut, motifs, dates, identifiant de ligne et empreinte, avec l'auteur par un identifiant opaque dans l'archive, et ni le journal ni un message ne porte un motif (le message au vaeiste ne dit ni le statut, ni le motif, ni l'auteur) ; la récupération facultative
  de l'export public de France compétences ne porte aucune donnée sur personne (§ 2, « Flux sortants »).
- **Exactitude** : le vaeiste écrit et corrige ses textes ; les versions restent.
- **Durées** : § 3 de la fiche T-84.
- **Information** : politique d'IA du site (le texte du cœur), écran de confirmation, mention dans le README et la FAQ ; à compléter par le site avec sa propre
  mention d'information. Pour les positionnements : la clause du contrat d'usage et le message envoyé au vaeiste à chaque ligne écrite (§ 2, « Décisions du 5 novembre 2026 »).
- **Droits** : accès et portabilité par l'archive du vaeiste et par l'export de Moodle ; effacement à la suppression du compte, ou sur
  demande ; limites dites (§ 4 de la fiche T-84). Un vaeiste qui conteste une ligne de positionnement s'adresse au DPO du site, sans écran (D5) ; la rectification est une nouvelle ligne écrite par la structure, la limitation et l'opposition passent par le DPO. L'archive ne porte ni les dépôts du dossier de faisabilité, ni les avis des responsables, ni
  les questions de précision et leurs réponses ; l'export de Moodle les porte, mais ne se réimporte pas, et n'est ouvert au vaeiste que si le
  site a activé « Contacter le délégué à la protection des données » (désactivé par défaut) et prévu l'approbation des demandes. L'archive, elle, porte les positionnements de la structure, historique compris (motifs en clair), et se
  réimporte en les ajoutant (§ 2, « Sorties ») ; l'export de Moodle les porte aussi. Une archive
  qui nomme des compétences que le site d'accueil a déjà numérotées autrement, ou dont le vaeiste a déjà un identifiant sur le site d'accueil (reçu
  dès sa première déclaration, son premier export, sa première copie ou sa première sauvegarde), est refusée en entier à l'import.

## 7. Risques résiduels et décision

| Risque | Résiduel avec H1 à H4 et A0/A1 | Résiduel avec A2 | Commentaire |
|---|---|---|---|
| R1 | Moyen | Moyen | Dépend du site |
| R2 | **Moyen** | **Moyen à élevé** | Reste le point le plus sensible : il dépend des vaeistes, que seule la formation corrige |
| R3 | Faible | Faible | |
| R4 | Faible (A0/A1) | Moyen | Le vaeiste confirme chaque envoi |
| R5 | Moyen | Moyen | Un texte relu par l'intéressé |
| R6 | Faible | Faible | |
| R7 | Moyen | Moyen | Hors du pouvoir du plugin |
| R8 | Moyen | Moyen | Tient aux libellés, que ni un accompagnateur VAE ni le DPO n'ont encore relus (non vérifié) ; indépendant de l'aide d'IA |
| R9 | Moyen à élevé | Moyen à élevé | Texte d'un tiers sur la personne, ajout seul ; le rappel est sur les formulaires (D3), reste que le site forme ceux qui écrivent ; indépendant de l'aide d'IA |
| R10 | Faible à moyen | Faible à moyen | Le motif est écrit et lu par des personnes ; le plugin n'en tire aucun verdict |
| R11 | Faible | Faible | Calculé et jamais écrit ; reste le nom des vaeistes dans la liste de qui détient deux droits |
| R12 | Moyen | Moyen | Choix assumé de l'ajout seul ; le levier du site est l'effacement du vaeiste, et l'effacement de l'auteur qui a quitté la ligne |

**À compléter par le site :** mode d'hébergement retenu (H1 à H5) ; mode d'IA (A0 à A3) ; qui, parmi ses responsables et sa direction, reçoit les
droits d'évaluer et de certifier, et la consigne écrite qui leur dit ce qu'un motif peut contenir et ne contient jamais (R9) ; la clause du contrat d'usage sur les positionnements (§ 2, D1, D2, D5) ; la politique de durée des copies hebdomadaires et des sauvegardes (D8) et la décision sur les comptes inactifs (D7) ; avis du DPO ; avis des personnes concernées
ou de leurs représentants (article 35, paragraphe 9) ; décision du responsable de traitement ; date de la prochaine revue (au plus un an,
ou à chaque changement d'hébergement, de fournisseur d'IA ou de version majeure du plugin).

## 8. Sources

- Règlement (UE) 2016/679, articles 5, 6, 9, 15, 17, 20, 28, 32, 35, 44 à 46.
- Code de la santé publique, article L. 1111-8 (hébergement de données de santé), version en vigueur au 1er juillet 2025.
- CNIL : « Ce qu'il faut savoir sur l'AIPD » ; liste des traitements pour lesquels une AIPD est requise ; lignes directrices du G29 sur
  l'AIPD (WP 248 rév. 01) ; logiciel PIA de la CNIL.
- Positionnements de la structure (code au build 2026110404, commité) : `classes/local/evaluation_manager.php`,
  `certification_manager.php`, `positioning_rules.php`, `positioning_archive.php`, `positioning_screen.php`, `positioning_successor.php`,
  `renewal_report.php`, `purge.php`, `record_remover.php`, `classes/privacy/provider.php`, `renewals.php`, `db/access.php`, `db/install.xml`
  (tables `tool_vae_eval` et `tool_vae_certif`) ; message au vaeiste : `classes/notifier.php` (`evaluation_recorded`, `certification_recorded`, `deliver_positioning`), `db/messages.php`, `db/events.php`, `lang/fr/tool_vae.php` (`notifypositioningrecorded_subject` et `_body`) ; rappel : `classes/form/evaluation_form.php`, `classes/form/certification_form.php` ; `docs/T-90-plan-realisation-7.md` (preuves et non vérifié de l'échéance).
- Plugin (build 2026110404) : `README.md`, `classes/privacy/provider.php`, `classes/local/fiche_fetcher.php` (la seule requête sortante),
  `scripts/sortie-reseau.sh` (mesure MC-30), `docs/IMPORT-FICHES-FRANCE-COMPETENCES.md`, `docs/T-13-pieces-jointes.md`,
  `docs/T-70-aide-a-la-formulation-ia.md`, `docs/T-12-livrable-tout-en-un.md` (décision D-1 et mesure MC-13), `docs/derogations/DER-referentiel-001-modele-natif.md`.
