# T-84 — Conformité RGPD du plugin « VAE - cheminement » : base légale, durées, droits, registre

> **Projet, à valider par le délégué à la protection des données (DPO) du site qui installe le plugin.** Rédigé le 25 octobre 2026, mis à jour le 27 octobre 2026
> d'après le code (version 2.5.03 (Moodle 5.3), build 2026110405, de code identique à celui du build 2026110404 ; l'ancienne 0.47.1, de code identique, a été renumérotée). Ce n'est pas un avis juridique. **Le responsable de traitement est le site** qui installe et exploite le
> plugin ; l'auteur du plugin ne reçoit aucune donnée et ne choisit ni l'hébergement, ni le fournisseur d'IA. Ce document donne au site
> les propositions, les durées que le code applique déjà et ce qu'il reste à décider. L'analyse d'impact est dans `docs/AIPD-VAE.md`.

> **Complété le 4 novembre 2026** pour quatre traitements nouveaux (T9 à T12 : le positionnement de la structure, le tableau « Organisation », la
> liste des certifications à renouveler), d'après le code de l'arbre de travail (`version.php` 2026110404). **Tout est commité depuis le 5 novembre 2026** : le code de T9 à T12 (`8a055b1`, `1203ecd`), le message au vaeiste et le rappel des formulaires (`e9d8733`, build 2026110404), les rôles (`927a33b`), ces ajouts (`6652a97`), ainsi que les ajouts de la ligne T1 sur les pages « Mon parcours » et « Vaeistes suivis » (lots 2 et 3 de T-90). Chaque fait ajouté est lu dans le code ; ce qui n'a pas été vérifié est dit « non vérifié ». **Ce qui reste
> à décider par le porteur est marqué « À décider par le porteur »** et récapitulé au § 8. **Les points de T9 à T12 ont été décidés le 5 novembre 2026 par le porteur, qui est aussi le DPO du site (D1 à D8, inscrites aux § 2 à § 8)** ; il ne reste « à décider » que ce que le § 8 dit encore tel. **L'analyse d'impact (`docs/AIPD-VAE.md`, § 2 et § 3) décrit
> désormais T9 à T12** (positionnements : T9 et T10 ; tableau « Organisation » : T11 ; liste à renouveler : T12), commitée comme le code. Elle
> garde ouverts les points qu'elle dit « non vérifié » (base légale d'une échéance, durée de conservation, essais en HTTP réel, MariaDB et PostgreSQL,
> mesure sur un site réel) : ils restent à décider ou à prouver par le porteur.

## 1. Les traitements

| # | Traitement | Ce que fait le plugin |
|---|---|---|
| T1 | Constitution du dossier de VAE | Déclarations d'activité, rubriques du dossier de faisabilité et de la préparation du dossier de validation, pièces, versions, dépôts ; lecture, par le vaeiste sur lui-même, des plans de formation et des compétences de cours du cœur (page « Mon parcours »), et lecture du nom et du statut de ses plans de formation par le responsable qui le suit, seulement s'il détient le droit du cœur de les lire (onglet « Vaeistes suivis ») ; **lecture, par la direction (détenteur de `tool/vae:viewany`), de la configuration du dispositif (onglet « Organisation », décisions du porteur du 4/11/2026 ; fiche de registre : T11)** : par fiche, le **nombre exact** de plans de formation qui la citent — plans de comptes ayant une trace dans le plugin, **brouillons compris, sans seuil, sans aucun nom** —, les cours, **les activités de cours** et les modèles du cœur qui la portent (le nom d'un cours, d'une activité ou d'un modèle que le lecteur ne peut pas voir est tu ; le nombre d'activités et la liste dépliable ne portent aucune donnée sur une personne), le nom complet des référents de bloc ; puis les rôles du site qui accordent, retirent ou interdisent chaque droit du plugin, tels que le site les définit au niveau du site (ni les surcharges faites plus bas, ni les personnes qui détiennent un rôle), les cohortes dont les membres sont suivis par un responsable à travers un rôle qui accorde au moins un droit du plugin (**nommées même masquées ; une cohorte supprimée dont la ligne subsiste n'a pas de nom**) et les réglages d'ouverture |
| T2 | Attestation par un responsable | Le responsable atteste une déclaration, pose des questions, donne un avis |
| T3 | Journal de bord privé | Écriture réflexive du vaeiste, lue par son seul auteur |
| T4 | Aide à la reformulation par IA | Envoi d'un texte, confirmé à chaque fois, à un fournisseur d'IA choisi par le site (fermée par défaut) |
| T5 | Export pour le jury | Archive figée, téléchargée par le vaeiste, ou par qui détient `tool/vae:viewany` et `tool/vae:export` au niveau du site (la direction, pour tout dossier), remise à un jury. **Elle porte aussi `positionnement.json` (T9, T10)** quand le dossier en contient |
| T6 | Copie hebdomadaire (facultative) et sauvegarde de l'administrateur | Deux copies du dossier, de nature différente : la **copie planifiée chaque semaine**, dans les fichiers personnels du vaeiste ; la **sauvegarde de l'administrateur**, dans les fichiers personnels du compte qui la prend (l'administrateur, ou un compte à qui ce droit a été donné ; un compte délégué n'y met que la liste des pièces, pas les fichiers), à la demande et avant la désinstallation, et refaite automatiquement dans les cas dits ci-dessous. **Elles portent les mêmes positionnements** : la sauvegarde de l'administrateur (`backup::save`, à la demande sur la page du dossier et pour tous les dossiers avant la désinstallation, `backup::save_all`) contient le même `positionnement.json` que l'archive, et la copie planifiée chaque semaine (`document_copies::run`, tâche `scheduled_copies`) reprend les positionnements dans le document du dossier ; l'une comme l'autre ne retient que les dossiers qui ont au moins une déclaration. **La copie planifiée est écrite dans les fichiers personnels du vaeiste, non dans ceux de l'administrateur** (`document_copies`), rien n'est écrit tant que l'administrateur ne l'a pas activée, et elle ne garde que les deux dernières ; **la sauvegarde de l'administrateur n'est pas planifiée** (elle est prise sur demande et avant la désinstallation ; elle est aussi refaite automatiquement, sans demande, à chaque retrait d'attestation, retrait de pièce ou effacement d'un signataire : `backup::refresh` remplace toutes les sauvegardes du vaeiste par une seule par propriétaire qui en détenait une et garde le droit d'en tenir) ; le code n'en limite pas la durée, ni le nombre en dehors de ces remplacements |
| T7 | Traçabilité et notifications | Journaux sans récit, messages sans récit, ouvertures de pièces ; **deux événements nouveaux, `evaluation_recorded` et `certification_recorded` (T9, T10) : des identifiants et le statut, jamais le motif** ; **un message au vaeiste à chaque ligne de positionnement écrite** (fournisseur `positioningrecorded`, build 2026110404) : il dit seulement qu'une ligne a été ajoutée, rappelle que ce n'est pas une validation, renvoie au dossier par un lien et indique le DPO du site pour une rectification, une limitation ou une opposition ; **ni le statut, ni le motif, ni l'auteur**. Envoyé au seul vaeiste concerné, et seulement s'il peut encore ouvrir son dossier (`tool/vae:viewown`) et qu'une ligne subsiste ; mis en file d'attente, porté par la tâche asynchrone comme les autres messages du plugin, et désactivable par la personne ou par l'administrateur dans les préférences de notification (`db/messages.php` : courriel et fenêtre actifs par défaut, permis) |
| T8 | Récupération du référentiel public (facultative) | **Aucune donnée personnelle sortante.** Sur clic d'un administrateur, jamais planifiée, le serveur va chercher l'export public de France compétences sur data.gouv.fr (deux hôtes fixes, https) : la requête ne porte aucune donnée sur personne, elle montre l'adresse IP du serveur (ou de son proxy), l'heure et un User-Agent fixe. Le dépôt du zip, lui, n'ouvre aucune requête |
| T9 | Évaluation d'une compétence (positionnement de la structure) | Le responsable qui suit le vaeiste et détient `tool/vae:evaluate` dans le contexte de ce vaeiste écrit, pour **une compétence d'une fiche sur laquelle le vaeiste a déclaré**, un statut (à construire, en cours de preuve, suffisante pour certification, retirée) et un **motif écrit libre** (4 000 caractères au plus). **Une ligne s'ajoute, aucune ne se modifie** : la dernière fait foi, l'historique reste. **Ce n'est pas une validation** : seul le jury valide, hors de la plateforme. Personne n'évalue son propre dossier ; l'administrateur du site, qui ne suit personne, n'évalue pas. Lue par le vaeiste, les responsables qui le suivent et la direction ; portée dans l'archive (T5), la sauvegarde (T6), le PDF et les copies modifiables. **Le vaeiste est prévenu par un message à chaque ligne écrite** (décision du 5/11/2026, build 2026110404 ; voir T7) : il lit ses lignes dans son dossier |
| T10 | Certification d'un bloc (positionnement de la structure) | Qui détient, au niveau du site, `tool/vae:viewany` **et** `tool/vae:certify` consigne, pour **un bloc d'une fiche** (la fiche entière quand elle n'a pas de bloc), « certifié » ou « retiré », avec la **date de la décision** et un **motif écrit libre**. **Pour un bloc certifié dont une compétence n'est pas encore suffisante** (calcul du serveur à l'écriture : jamais évaluée, ou dernière ligne autre que « suffisante » ; une compétence que la source a retirée ne compte pas comme un manque), un **motif d'alerte** est obligatoire : la certification n'est pas bloquée, elle doit être expliquée. Si aucune compétence ne manque, le motif d'alerte est refusé (il doit rester vide) ; pour un **retrait**, il est refusé aussi et rien n'est exigé. Une date « valable jusqu'au » est facultative (T12). Ajout seul, dernière ligne qui fait foi. Personne ne certifie son propre dossier, pas même l'administrateur du site ; mais l'administrateur, qui passe les deux droits, **peut certifier le dossier d'un autre** (à la différence de T9, où il n'évalue pas parce qu'il ne suit personne). Même lecture, mêmes archives, même message au vaeiste que T9. **La certification est un positionnement de la structure, jamais une validation.** Sur une fiche qui en remplace une autre, la ligne qui fait foi sur l'ancienne fiche (évaluation ou certification, T9 comme T10), **si ce n'est pas un retrait**, est **montrée en lecture seule** sur la nouvelle, là où un administrateur a décidé une équivalence dont les deux extrémités existent : d'une compétence à une compétence pour une évaluation, **d'un bloc à un bloc pour une certification** (jamais déduite des compétences, jamais lue pour une fiche sans bloc). **Un seul saut** est lu : la fiche remplacée par l'ancienne n'est pas relue plus loin. Rien n'est copié ni écrit, les mêmes lecteurs y accèdent |
| T11 | Tableau « Organisation » (lecture de la configuration du dispositif) | Page de la direction (détenteur de `tool/vae:viewany`). Par fiche : ce que le cœur porte (blocs, compétences, cours, activités de cours, modèles), le **nombre** de plans de formation qui la citent, les référents de bloc (nom complet). Puis, pour chaque droit du plugin, les rôles du site qui l'accordent, le retirent ou l'interdisent (grille « Acteurs et pouvoirs » : **des rôles, jamais des personnes**), les cohortes dont les membres sont suivis par un responsable et le rôle donné, les réglages d'ouverture. **Aucun nom de vaeiste, aucun nom de plan, aucun nom du responsable qui suit.** Rien n'est écrit ni conservé : tout est lu à chaque affichage |
| T12 | Liste des certifications à renouveler | Page `renewals.php`, pour qui détient `tool/vae:viewany` **et** `tool/vae:certify`. Les certifications qui tiennent encore (la dernière ligne du bloc) et dont la date « valable jusqu'au » est passée, **comptée au jour du lecteur** : nom complet du vaeiste (lien vers son dossier), fiche, bloc, date, nombre de jours depuis. **Lue, jamais écrite, envoyée ni stockée** : aucune tâche planifiée, aucun message. Ni total, ni couleur, ni classement de personnes (l'échéance la plus ancienne d'abord). Un compte supprimé n'y figure pas, un compte suspendu y figure. Le même constat s'affiche dans le dossier de chaque vaeiste |

**T8 et l'API de vie privée du plugin.** La récupération de l'export public n'ajoute aucune table (pour cette fonction, `db/install.xml` ne change pas : 20 tables,
comme avant ; il en compte 22 depuis les deux de T9 et T10, voir le § 5) et aucune donnée personnelle : la requête ne porte aucune donnée sur personne. Ce que l'import écrit sur une personne dans les
tables du plugin était déjà déclaré (`usermodified` et `timemodified` de `tool_vae_source` : le compte de l'administrateur qui a importé la
fiche, comme avec l'écran du fichier CSV) ; les référentiels eux-mêmes sont écrits par le sous-système des compétences du cœur, par la même
porte qu'avec le fichier CSV. **La déclaration du fournisseur de vie privée (`get_metadata()` dans `classes/privacy/provider.php`) ne change
donc pas pour cette fonction.** Le contrôle `scripts/verifier-privacy.php` compare chaque table d'`install.xml` à ce que le fournisseur
déclare ou exempte explicitement : sans table nouvelle, il n'y a rien de nouveau à déclarer. Déclarer un emplacement externe serait faux :
rien de personnel n'y part. Les deux événements de l'import (`export_fetched`, `record_imported`) vont dans le journal de Moodle, avec le
compte de l'administrateur qui a cliqué, comme tout événement ; ils ne portent aucune donnée sur un vaeiste (§ 3, journaux d'événements).

## 2. Base légale (RGPD, article 6, paragraphe 1)

**Décision du porteur, le 25 octobre 2026 : le contrat d'usage de la plateforme (conditions d'utilisation que le vaeiste accepte, distinct de la convention de VAE) est la base légale de tous les
traitements du plugin, y compris l'aide d'IA.** Le vaeiste qui n'accepte pas le contrat ne l'utilise pas ; le responsable de
traitement fonde ainsi ses traitements sur un accord écrit (le contrat d'usage), et non sur une série de consentements séparés. La responsabilité reste celle du site seul. Base retenue :
**article 6, paragraphe 1, point b** (exécution d'un contrat), pour T1 à T7. **Décision du porteur, qui est aussi le DPO du site, le 5 novembre 2026 (D1) : la même base, le contrat d'usage (article 6, paragraphe 1, point b), couvre T9, T10, T12 et le nom des référents de bloc (T11)**, donc aussi ce que les lignes T1, T5, T6 et T7 du § 1 en portent depuis le 4 novembre 2026 (voir le paragraphe sur T9 à T12 ci-dessous). **T8 est à part** : il ne traite aucune donnée de vaeiste ni de
responsable, et la décision ci-dessus porte sur les traitements de leurs données ; le contrat d'usage n'a rien à porter pour T8. Ne restent
que les traces du compte de l'administrateur qui a cliqué (le journal de Moodle, avec les autres journaux, T7 ; et `usermodified` de la fiche importée) : le DPO du site en apprécie le fondement.

Les six bases existent. Rappel de ce que chacune donne, avant la décision :

| Base | Condition | Pour ce plugin |
|---|---|---|
| **a. Consentement** | Libre, spécifique, éclairé, univoque, retirable, demandé séparément | Non retenu : le contrat tient lieu de cadre unique |
| **b. Exécution d'un contrat** | Le traitement est nécessaire à l'exécution du contrat conclu avec la personne | **Retenu pour T1 à T7, et pour T9 à T12 depuis le 5/11/2026** (voir plus bas) |
| **c. Obligation légale** | Un texte impose le traitement | Ne couvre pas le dossier. Reste invocable en complément pour les journaux de sécurité (article 32) |
| **d. Intérêts vitaux** | Danger pour la vie | Sans objet |
| **e. Mission d'intérêt public** | Mission fondée sur un texte | Possible pour un établissement public, en complément |
| **f. Intérêts légitimes** | Mise en balance | Possible en complément pour T6 et T7 |

**Positionnement de la structure, tableau « Organisation », liste à renouveler (T9 à T12) : base légale décidée le 5 novembre 2026 (D1).**
**Décision du porteur, qui est aussi le DPO du site : le contrat d'usage, comme T1 à T7 (article 6, paragraphe 1, point b), pour T9, T10, T12 et pour le nom des référents de bloc (T11).**
Les notes de travail du 4 novembre 2026 avaient laissé ce point ouvert (plan 6, plan 7) ; il ne l'est plus. Les faits lus dans le code, qui conditionnent le contenu
du contrat :

- T9 et T10 ne sont pas ce que le vaeiste écrit : ce sont des actes de la structure (un responsable, la direction) **sur** lui. Il les lit, il ne
  peut ni les modifier ni les retirer ; une correction est une ligne de plus, signée par l'auteur.
- Le motif est un texte libre d'un tiers, jusqu'à 4 000 caractères, qui part dans l'archive remise au jury (T5). La raison de cette différence avec
  les avis sur dépôt, que l'archive ne porte pas : l'avis est un texte d'accompagnement ; le positionnement est un acte de la structure que le jury
  doit pouvoir lire (plan 6, point 18). Le contrat doit donc décrire le positionnement comme une composante de la prestation (la réserve de la nécessité
  objective, plus bas, vaut ici comme pour l'aide d'IA).
- T12 ne crée aucune donnée : elle remet sous les yeux de qui lit déjà T10 une date qu'il a posée ; même base que T10. T11 ne
  nomme aucun vaeiste (des nombres, des rôles, des noms de cohortes) ; **ces agrégats peuvent pourtant désigner une personne ou un groupe**, risques
  acceptés du § 5 (un petit nombre de plans, le nom d'une cohorte masquée). **Seul le nom des référents de bloc est une donnée nominative** :
  il suit le contrat d'usage (D1), comme le reste.
- **Aucune décision automatisée** : le plugin n'écrit aucun positionnement de lui-même. L'alerte d'une compétence non suffisante est un constat qu'il
  calcule à l'écriture pour exiger un motif ; « à renouveler » est un constat calculé à la lecture. La décision et le motif sont d'une personne.
  Le DPO du site, qui est le porteur, a confirmé le 6 novembre 2026 que l'article 22 n'est pas en jeu, pour la raison ci-dessus : aucun positionnement n'est écrit sans qu'une personne agisse. Le DPO d'un autre site installateur fait la même vérification pour son site.
- Si le site est l'employeur du vaeiste, la réserve du « rapport d'emploi » ci-dessous vaut aussi pour des positionnements écrits par sa hiérarchie.
- **Information du vaeiste (D2, 5/11/2026)** : deux voies, qui se complètent. Une clause du contrat d'usage (point 6 de la liste ci-dessous) ; et un message au vaeiste à chaque ligne de positionnement écrite, évaluation ou certification (T7, § 1). Le message ne remplace pas la clause : il est désactivable par la personne ou par l'administrateur dans les préférences de notification.

**Ce que le contrat doit dire pour porter ce choix** (modèle de clauses : `docs/MODELE-CONTRAT-USAGE-PLATEFORME.md`, à faire valider par le DPO du site) :

1. **L'objet** : le service est la plateforme de cheminement VAE, y compris son **aide d'IA à la reformulation**, qui fait
   partie de la prestation.
2. **L'information complète** (articles 13 et 14) sur chaque traitement : finalités, destinataires dont le **fournisseur d'IA nommé**,
   lieu de traitement, durées (§ 3), droits (§ 4).
3. **Ce que le vaeiste accepte en signant** : qu'un texte qu'il écrit puisse être envoyé au fournisseur d'IA **à chaque fois qu'il
   le demande et le confirme**, et les limites de cet envoi (aucune personne accompagnée nommée).
4. **La conséquence d'un refus** : sans signature, pas d'accès à la plateforme.
5. **Un changement de fournisseur d'IA ou d'hébergement** donne lieu à une information écrite au vaeiste avant qu'il s'applique.
6. **Les positionnements de la structure (T9, T10 ; décisions du 5/11/2026, D1, D2, D4, D5)** : la structure consigne par écrit, à chaque ligne, où elle en est sur une
   compétence ou un bloc ; ce n'est pas une validation, seul le jury valide ; le vaeiste lit ses lignes dans son dossier et elles figurent dans l'archive remise au jury ;
   il reçoit un message à chaque ligne écrite (sans le statut, le motif ni l'auteur) ; les lignes sont conservées avec le dossier ; pour une rectification, une limitation
   ou une opposition, il s'adresse au DPO du site (voie sans écran, § 4).

**Réserves à connaître, sans remettre la décision en cause** (le DPO du site les évalue) :

- **La nécessité se juge objectivement.** Les lignes directrices 2/2019 du CEPD et l'arrêt Meta contre Bundeskartellamt de la Cour de
  justice (C-252/21, 2023) retiennent qu'un traitement n'est pas nécessaire à un contrat du seul fait qu'une clause le prévoit. Le
  contrat doit donc décrire une prestation dont l'aide d'IA est **réellement une composante**, et le plugin doit être cohérent avec
  cette description. **Le texte de la politique (désormais celui du cœur) ne dit pas que l'aide est facultative** (phrase retirée le 25/10/2026 du texte que le plugin affichait alors).
- **Le plugin laisse la confirmation à chaque envoi.** Le vaeiste n'est jamais obligé d'envoyer un texte ; c'est une garantie
  technique, qui reste, et qui n'a pas à être commentée à l'écran.
- **Le rapport d'emploi.** Si le site est l'employeur, une autorité peut examiner si l'accord du salarié à un contrat de ce type est
  réellement libre ; elle examinera surtout la nécessité du traitement pour la prestation. La case « J'ai lu la politique et je
  l'accepte » de l'écran de l'aide reste : c'est la preuve que le vaeiste a lu l'information, pas un consentement séparé. **La politique
  affichée est celle de l'IA du site, le texte du cœur (« Politique d'usage IA »), que le site rédige une fois, par la personnalisation de
  langue, pour tous les endroits qui offrent de l'IA** ; le cœur n'en tient qu'une acceptation par personne, qu'elle ait été donnée dans un
  éditeur ou ici. Ce qui est propre à l'aide (ne nommer personne, ce qui est conservé, relire la proposition) est rappelé à chaque envoi.
- **Le consentement reste exigible dans un cas** : si le site envoie des données à un tiers pour une **autre finalité** que
  l'accompagnement (statistique, entraînement d'un modèle, cession). Le plugin ne le fait jamais ; le contrat avec le fournisseur d'IA
  doit l'interdire (§ 7).

**Données de l'article 9 (dont la santé).** Le plugin n'a aucune finalité qui en demande. Des données de santé **de tiers** (une
personne accompagnée nommée dans un récit, une transmission de soins en pièce) y entreraient par erreur : aucune exception de
l'article 9 ne les couvre pour cette finalité, et le contrat n'en tient pas lieu. La réponse est **de les empêcher** (liste fermée des
pièces, politique d'usage, formation des vaeistes, § 6) et non de leur chercher une base légale.

## 3. Durées de conservation

**Principe retenu par le porteur le 25 octobre 2026 : le dossier du vaeiste est conservé jusqu'à la suppression de son compte.**
Cette durée ne protège que si le site supprime les comptes. **Le site doit donc fixer l'événement qui déclenche la suppression**
(fin de la VAE, validation, abandon, départ de l'établissement) et son délai, et l'appliquer (suppression par l'administrateur, ou règles
de conservation de l'outil « Protection des données » de Moodle). **Décision du porteur, le 5 novembre 2026 (D7) : la suppression des comptes inactifs est une décision de l'administrateur du site, à réexaminer tous les 36 mois.** Le plugin ne supprime aucun compte de lui-même : le réglage est celui de Moodle. **Formule à confirmer dans sa rédaction exacte** (le porteur a dit « décision de l'administrateur, relancer tous les 36 mois »). L'ancienne proposition de vingt-quatre mois n'est plus retenue ; avant toute suppression, la personne est prévenue et peut exporter son dossier (§ 4).

**Ce que Moodle fait lui-même à la suppression d'un compte.** Par défaut, l'outil « Protection des données » crée à chaque suppression
manuelle une demande de suppression de données au nom du compte supprimé (`automaticdeletionrequests` = 1), laissée « en attente d'approbation »
(`automaticdatadeletionapproval` = 0) : tant que le délégué à la protection des données ne l'a pas approuvée, ce circuit n'efface rien ; une fois
approuvée, elle passe par le fournisseur de vie privée de chaque plugin. Le plugin détruit de lui-même, à la suppression, le dossier du vaeiste ;
il **laisse les signatures d'un responsable supprimé**, que seule l'approbation de cette demande anonymise (quatre champs effacés, attestation
marquée retirée, motif « Demande de suppression de données »), sauf celles reprises d'une archive (voir plus bas). **Le site choisit** :
suspendre (et non supprimer) les comptes des responsables, refuser ou laisser sans suite la demande automatique d'un signataire, désactiver
`automaticdeletionrequests`, ou accepter l'anonymisation ; et le délégué doit savoir qu'approuver cette demande de routine anonymise toutes
les attestations du compte, **envoie des messages** aux vaeistes et aux responsables concernés (voir ci-dessous) et **vide les textes que le
compte a fait générer par l'IA de Moodle**, dont ceux de l'aide à la reformulation, **sauf si le registre des données du site définit la finalité et la catégorie du site** : Moodle ne les vide alors jamais et déclare pourtant la demande terminée (voir le tableau).

**Ce que l'approbation envoie.** Quand l'effacement des données d'un responsable retire des attestations, le vaeiste concerné reçoit **un
message** (type « Attestations retirées de votre dossier ») : l'attestation est retirée (il y trouve la référence de la compétence s'il n'y
a qu'une déclaration, le nombre de déclarations sinon), la déclaration n'a pas été modifiée, et les responsables qui peuvent l'attester de
nouveau sont nommés (s'il n'y en a aucun, le message renvoie à l'administrateur du site). Chaque responsable qui suit ce vaeiste et peut attester reçoit **un message de relance** (type « Déclarations en attente
de votre attestation »), qui nomme le vaeiste. Aucun des deux ne dit le nom de la personne dont les données sont effacées, ni la cause. Ils
partent **environ quinze minutes après la dernière demande approuvée qui concerne ce vaeiste** : une série d'approbations donne un message par
destinataire et par vaeiste, non un par responsable effacé. Ils sont recomposés à l'envoi (rien n'est envoyé pour une déclaration attestée de
nouveau entre-temps), chacun peut les couper dans ses préférences de notification, et la tâche planifiée qui les porte ne contient que des
numéros (comptes, déclarations), aucun texte. **Une exception** : l'attestation reprise d'une archive désigne le responsable par un
identifiant d'archive, non par un compte ; le plugin la retire dès la suppression du compte de ce responsable, sans attendre aucune
approbation (elle ne pourrait plus être effacée ensuite), et les mêmes messages partent alors dans les mêmes délais.

**Ce que le dossier en garde.** Dans le dossier, dans le PDF et dans les copies Word ou OpenDocument, une attestation retirée n'est plus qu'une
phrase : « Une attestation a existé et a été retirée le (date) (motif). Cette attestation n'est plus valable. » Le motif d'un effacement de
signataire est « Demande de suppression de données ». Seulement si aucune autre attestation n'est en vigueur sur la déclaration (et une seule
fois, après la dernière attestation retirée), la phrase est suivie de : « La déclaration n'a pas été modifiée : le vaeiste peut demander à un
responsable de l'attester de nouveau. »

| Donnée | Durée appliquée par le code | Remarque |
|---|---|---|
| Déclarations, rubriques, versions, dépôts, pièces, avis, questions, attestations | Jusqu'à la suppression du compte du vaeiste | Le retrait d'une attestation ne détruit pas la déclaration. L'attestation d'un responsable supprimé reste jusqu'à l'approbation de la demande de suppression de données de son compte (sauf celle reprise d'une archive, retirée à la suppression du compte) |
| Journal de bord privé | Jusqu'à la suppression du compte | Entre dans l'archive que le vaeiste exporte lui-même |
| Évaluations et certifications, avec leur historique (T9, T10) | Jusqu'à la suppression du compte du vaeiste (`purge::dossier`, comme son dossier), plus un effacement à la demande du DPO du site ; aucune durée propre, aucune purge automatique propre (**décision du 5/11/2026, D4**) | Ajout seul : aucune ligne ne se modifie ni ne s'efface pendant la vie du compte. Elles sont dans l'archive et dans la sauvegarde (voir les lignes plus bas). **Un auteur dont le compte est supprimé** n'est plus nommé à l'écran ni dans l'archive (« une personne dont le compte n'existe plus »), mais la ligne garde son numéro de compte dans la base jusqu'à l'approbation de la demande de suppression de données de ce compte, qui le vide ; **le texte, lui, reste** (il parle du vaeiste ; **confirmé par le porteur et DPO le 5/11/2026, D6**). Le plan 6 prévoyait aussi que l'observateur de suppression de compte le vide ; le code lu (`classes/observer.php`) ne le fait pas : **écart à confirmer par un essai (non vérifié)**. Une ligne restaurée depuis une archive porte l'identifiant d'archive de son auteur (`authoruuid`) : lui est vidé **dès la suppression du compte de cet auteur**, sans attendre l'approbation d'une demande (`observer::user_deleted` appelle `purge::dossier`, qui retire cet identifiant dans `strip_signatory`) ; la ligne et son texte restent |
| Page des certifications à renouveler (T12) | Aucune conservation | Calculée à chaque affichage, au jour du lecteur |
| Événements `evaluation_recorded` et `certification_recorded` (T9, T10) | Durée réglée par le site (« Nettoyage des journaux ») | Le compte de l'auteur, le compte du vaeiste, la fiche, le rang et le statut ; jamais le motif, ni la date de décision, ni le motif d'alerte |
| Texte envoyé à l'aide d'IA et réponse (tables du cœur) | Jusqu'à l'effacement des données du compte | **Le plugin ne les efface pas** (décision du porteur, 27/10/2026), ni à la suppression du compte ni à un autre moment : tout est laissé à la demande de suppression de données que Moodle crée à chaque suppression de compte. **Moodle les vide quand une personne approuve cette demande** (elle attend par défaut d'être approuvée) : le texte, la réponse et l'acceptation de la politique d'IA ; d'ici là, ils restent lisibles en clair pour qui a accès à la base. **Cela suppose deux conditions que le plugin ne peut pas changer.** Première condition : le site crée ces demandes de lui-même (`automaticdeletionrequests`) ; s'il désactive cette création automatique, plus rien ne les efface, sauf une demande créée à la main. Seconde condition : le registre des données du site (Administration du site > Utilisateurs > Protection des données et politiques > Registre des données) ne définit PAS la finalité et la catégorie du site ; si l'administrateur les a définies, Moodle ne vide jamais ces textes, rattachés au contexte du site (`api::get_approved_contextlist_collection_for_collection()` écarte ce contexte), même quand la demande est approuvée, et il déclare quand même la demande terminée : les textes restent, à faire effacer à la main. L'écran de confirmation de l'aide dit au vaeiste, selon l'état du site au moment de l'envoi, que Moodle les efface (après approbation) ou qu'il ne les efface pas de lui-même (les deux phrases exactes sont dans `docs/RELECTURE-AIDE-REFORMULATION.md`, texte 3). L'effacement du cœur vide aussi les autres textes générés par l'IA de Moodle au nom du même compte (résumés, explications, consignes d'image), quel que soit l'outil qui les a produits : le cœur ne sait pas lequel a fait l'appel. **Le registre de l'usage du cœur reste**, dans tous les cas, après l'effacement : le numéro du compte (qui reste rattaché au nom du vaeiste dans Moodle), la date, le fournisseur, le modèle, la taille de l'échange (en jetons), la réussite ou l'échec de l'appel et, après un échec, le message d'erreur du fournisseur ; le journal du plugin ne contient jamais le texte |
| Appels à l'aide d'IA (plugin : qui et quand, pas le texte) | **3 jours au plus** (tâche de nuit) | Sert à tenir la pause et le plafond du jour |
| Archive d'export | **24 heures** | Supprimée de la plateforme ; ses copies échappent au plugin |
| Copies hebdomadaires (facultatives) | Les deux dernières, dans les fichiers personnels du vaeiste | Supprimées avec le dossier sur demande d'effacement. **Bornage : politique du site, à écrire par le DPO (§ 6, décision du 5/11/2026, D8), aucun code** ; la durée reste « à fixer par le site » : le code ne la limite pas au-delà des deux dernières |
| Sauvegardes de l'administrateur | Aucune limite de durée dans le code ; le nombre n'est borné qu'au remplacement : une par demande, plus une par dossier avant la désinstallation, et, à chaque retrait d'attestation, retrait de pièce ou effacement d'un signataire, toutes celles du vaeiste sont remplacées par une seule par propriétaire qui en détenait une et garde le droit (`backup::refresh`), écrite sans demande de l'administrateur, dans les fichiers personnels du compte qui la prend (l'administrateur, ou un compte à qui ce droit a été donné) | Supprimées avec le dossier sur demande d'effacement (`backup::discard`). **Bornage : politique du site, à écrire par le DPO (§ 6, décision du 5/11/2026, D8), aucun code** ; la durée reste « à fixer par le site » : le code ne les borne pas |
| Ouvertures de pièces | Avec la personne | Effacées avec elle |
| Journaux d'événements de Moodle | Durée réglée par le site (« Nettoyage des journaux ») | Ne contiennent pas le récit. Les événements de l'import des fiches (`export_fetched`, `record_imported`) y portent le compte de l'administrateur qui a cliqué, aucune donnée sur un vaeiste |
| Plan d'un import de fiches (fichier temporaire du serveur) | Valable 2 heures après sa création, puis 2 heures après la confirmation (le résultat se relit) ; effacé ensuite à son ouverture ou à la préparation suivante du même compte, sinon par le nettoyage du dossier temporaire de Moodle (une semaine, selon un commentaire du code : non vérifié) | Les fiches demandées, qui sont publiques, et le numéro du compte de l'administrateur ; jamais l'archive de l'export, qui est supprimée dès l'aperçu préparé |
| Archive remise à un jury | Hors plateforme | Le site et le vaeiste décident avec le jury de ce qu'il en fait |

## 4. Droits des personnes, et la portabilité avant suppression

**Principe retenu : conservation jusqu'à la suppression du compte, mais la personne doit pouvoir emporter ses données avant.**

| Droit (RGPD) | Ce que le plugin offre déjà | Ce qui manque |
|---|---|---|
| **Accès** (art. 15) | Export des données personnelles de Moodle (outil « Confidentialité »), qui inclut les données du plugin par son API de vie privée. Le vaeiste y trouve ses déclarations avec leurs dates et la compétence visée. **L'export d'un signataire contient ses attestations et la référence des déclarations signées, jamais le récit ni le cadre du vaeiste ; celui d'un responsable qui a posé une question contient sa question, la date et l'empreinte de la réponse, jamais son texte** | Vérifier avec un dossier réel que l'export du cœur porte bien l'identité et les rubriques |
| **Portabilité** (art. 20) | **Archive d'export** : zip lisible sans la plateforme, `manifeste.json`, preuves en JSON conformes à un schéma publié, empreintes SHA-256, `dossier.pdf`. **Les positionnements de la structure (T9, T10) y sont aussi** : `positionnement.json` (schéma 0.1.0, facultatif) porte toutes les lignes, la ligne qui fait foi marquée, sous la phrase « positionnement de la structure, non validation » ; l'import les restaure clé par clé (fiche, rang), **seulement si la clé est vide sur le site d'accueil**, tout ou rien par clé, l'empreinte du motif étant vérifiée contre le texte (décision du porteur du 4/11/2026). **Une clé est aussi laissée de côté**, sans refuser l'archive, quand la fiche ou le rang n'existe pas sur le site d'accueil sous la forme attendue (une compétence pour une évaluation, un bloc pour une certification) ou quand l'identifiant d'une de ses lignes y est déjà présent ; le nombre de lignes restaurées et celui des lignes laissées sont comptés (`positionnement`, `positionnementlaissees`). Une ligne mal formée refuse en revanche l'archive entière. Une correction ou un retrait fait après l'export est sans effet sur l'archive déjà remise. **Une ligne hors format** (le rang d'une compétence écrit `c7` au lieu de `C7`) **ne bloque ni l'export ni la sauvegarde d'écran** : l'archive est produite, avec un avertissement à l'écran et dans sa notice (la sauvegarde d'écran, avec son avertissement, renvoie au dossier), et ne pourra pas être réimportée telle quelle (l'import reste strict). Cette ligne vient d'une déclaration **déjà enregistrée** avec un rang hors format, par une version antérieure du plugin ou écrite directement dans la base de données ; dans les versions 01.5.3 et 2.5.03 (Moodle 5.3), une déclaration nouvelle sur un tel rang est refusée. Elle **ne se corrige pas depuis Moodle** : changer le numéro d'identification de la compétence ne réécrit pas la déclaration déjà enregistrée, dont le rang est écrit à plusieurs endroits. L'avertissement revient à chaque export tant que la déclaration existe, et il faut prévenir l'administrateur ; la copie modifiable du dossier (Word ou OpenDocument) reste possible. Une pièce perdue ou altérée, ou un dossier trop volumineux pour être relu, restent refusés. **Reprise possible par une autre instance, à deux conditions** : qu'elle n'ait pas déjà numéroté autrement les compétences que l'archive nomme, et que le compte du vaeiste n'y ait pas déjà d'identifiant (il le reçoit dès sa première déclaration, son premier export, sa première copie ou sa première sauvegarde) ; sinon l'import est refusé en entier et rien n'est écrit. Pour les compétences, le message en donne le nombre et nomme les dix premières (code et rang, avec l'intitulé que le site d'accueil leur donne, dans l'ordre des codes), puis compte les autres ; il faut alors soit refaire les déclarations et les signatures sur la nouvelle instance (l'archive et son PDF restent une pièce du dossier), soit demander l'aide d'un développeur. Le vaeiste l'exporte **lui-même** quand il veut, avec son journal de bord, **à condition que le site lui ait donné le droit `tool/vae:export`** (accordé à aucun rôle par défaut : sans cela, il ne peut pas exporter son propre dossier). **La direction** (qui détient `tool/vae:viewany` et `tool/vae:export` au niveau du site) **peut aussi exporter le dossier de n'importe quel vaeiste**, `positionnement.json` et ses motifs libres compris (`access::can_export`) | L'archive **n'emporte ni date de naissance, ni nationalité, ni adresse, ni téléphone** (MC-13, choix de minimisation pour le jury). Pour la portabilité au sens strict, ces champs que le vaeiste a lui-même fournis relèvent de l'export du cœur. **L'archive ne porte pas non plus** les dépôts du dossier de faisabilité (numéro, date, empreinte, notes), les avis des responsables, les questions de précision et leurs réponses, ni les versions antérieures des rubriques : ils figurent dans l'export de données personnelles de Moodle, lisible mais **non réimportable**, que le vaeiste ne peut demander lui-même que si le site a activé « Contacter le délégué à la protection des données » (réglage désactivé par défaut) et prévu l'approbation des demandes ; l'écran d'export le dit. Les dépôts sont des écrits du vaeiste ; les avis et les questions sont des écrits de responsables : le délégué dit si cela relève de la portabilité ou du droit d'accès. **Chantiers décidés le 27/10/2026, non construits, chacun à valider avant livraison** : un écran de correspondance, validé par une personne, pour importer quand le site connaît déjà la compétence sous un autre identifiant ; puis une « archive complète pour moi-même », qui inclurait ces champs |
| **Rectification** (art. 16) | Le vaeiste corrige ses rubriques (nouvelle version) | Les dépôts et avis sont figés par conception : on ajoute, on ne réécrit pas. **Les positionnements (T9, T10) aussi** : le vaeiste ne peut pas écrire sur son propre dossier ; une erreur se corrige par une ligne de plus de l'auteur ou d'un autre détenteur du droit, l'ancienne restant dans l'historique et dans l'archive. **Voie du vaeiste qui conteste une ligne (décision du 5/11/2026, D5) : le DPO du site, par la clause du contrat d'usage, sans écran.** Une rectification passe par une nouvelle ligne écrite par la structure (ajout seul) ; le message envoyé au vaeiste à chaque ligne (T7) renvoie au DPO du site |
| **Effacement** (art. 17) | Efface le dossier du vaeiste à la suppression de son compte, ou sur demande ; l'aide d'IA : **vidée à l'approbation de la demande de suppression de données que Moodle crée à la suppression du compte, sous deux conditions que le site doit connaître** (création automatique des demandes active, registre des données du site sans finalité ni catégorie du site définies : § 3) ; **les signatures d'un responsable : à l'approbation de la demande de suppression de données de son compte, ou de la sienne** (§ 3) ; **les positionnements (T9, T10) : supprimés avec le dossier du vaeiste ; ceux qu'une personne a écrits sur un autre : à l'approbation de sa demande, son identité est retirée de la ligne (compte et identifiant d'archive), le motif reste, et les archives, sauvegardes et copies sont refaites** (lu dans `strip_attestant` ; l'effacement n'a pas été rejoué sur un site réel) ; **le retrait d'une attestation et le retrait d'une pièce refont de même les sauvegardes et les copies** (`backup::refresh`, `document_copies`) | Une archive déjà remise, une copie téléchargée, la corbeille de la plateforme : dit à l'écran, hors de portée du plugin |
| **Limitation, opposition** (art. 18, 21) | Pas d'écran dédié | Traitées par le DPO du site (D5, 5/11/2026) ; pour une ligne de positionnement, la voie est dite dans la clause du contrat d'usage et dans le message au vaeiste. L'effacement d'une ligne à la demande du DPO du site est prévu (D4) : **lu dans le code, aucun écran n'efface une ligne seule** : les tables ne sont vidées que par la purge du dossier d'un vaeiste (`purge::dossier`) ; le retrait d'une fiche (`record_remover`) est **refusé** tant qu'une évaluation ou une certification de cette fiche existe et n'efface rien dans ces tables (le DPO purge d'abord les dossiers concernés) ; **la manière dont le DPO du site efface en pratique une ligne ou un dossier, non vérifiée en conditions réelles** |

**Ce qui serait logique, selon le porteur, et comment l'obtenir :**

0. **Donner aux vaeistes le droit `tool/vae:export`** (avec `tool/vae:viewown`) : c'est la condition de la portabilité par l'archive. Pour les
   dépôts, les avis et les questions de précision, que l'archive ne porte pas, il faut en plus que le site ouvre la demande de données de Moodle
   au vaeiste (« Contacter le délégué à la protection des données ») ou qu'une personne la crée pour lui.
1. **Avant toute suppression de compte, la personne peut exporter son dossier.** Aujourd'hui rien ne force cet ordre : Moodle supprime
   un compte sans demander si ses données ont été récupérées. **Mesure organisationnelle immédiate, à inscrire dans la procédure du
   site :** prévenir le vaeiste par message avant la suppression (au moins trente jours) et lui indiquer l'export (« Exporter mon
   dossier »), puis supprimer.
2. **Mesure technique possible, à décider : un lot « avant suppression ».** Une tâche planifiée qui, quand le site marque un compte à
   supprimer, envoie au vaeiste un message avec le lien d'export et la date de suppression, et n'efface rien avant l'expiration du
   délai. Cela demande du code (une table de demandes de suppression, une tâche, des tests) : à ouvrir comme action **T-85** si le
   porteur le souhaite.
3. **Archive complète pour soi-même** (voir ci-dessus ; chantier décidé le 27 octobre 2026, non construit, à valider avant livraison) pour que la
   portabilité couvre aussi l'identité.

## 5. Registre des traitements (article 30) : fiche modèle

À recopier dans le registre du site, une colonne par traitement. Deux colonnes modèles sont données ici, T1 (dossier) et T4 (aide d'IA). T2, T3, T5, T6 et T7 sont des traitements du même dossier (attestation, journal de bord, export, copies, traçabilité) : le DPO les inscrit en reprenant la colonne T1, ou ajoute une colonne chacun s'il estime que la finalité diffère (choix à confirmer par le porteur ou le DPO). T9 à T12 : voir plus bas (base légale : le contrat, décision du 5/11/2026) :

| Rubrique | T1 dossier | T4 aide d'IA |
|---|---|---|
| Responsable | Le site (nom, adresse) | Idem |
| DPO | À compléter | À compléter |
| Finalité | Constituer le dossier de VAE d'un professionnel | Proposer une autre formulation d'un texte écrit par le vaeiste |
| Personnes concernées | Vaeistes ; responsables qui attestent | Vaeistes |
| Catégories de données | Identité, pratique professionnelle, avis, pièces ; nom et statut des plans de formation du vaeiste, cours qui portent ses compétences (lus dans le cœur, jamais copiés) | Texte du vaeiste, réponse |
| Base légale | Contrat (article 6, 1, b) | Contrat (article 6, 1, b) |
| Destinataires | Vaeiste, responsables habilités, administrateur, jury (par l'archive) ; responsables qui suivent le vaeiste et détiennent le droit du cœur de lire ses plans (nom et statut des plans seulement) | Fournisseur d'IA choisi par le site |
| Transferts hors UE | Selon l'hébergement (AIPD § 5.1) | Selon le fournisseur (AIPD § 5.2) |
| Durée | Voir § 3 | Voir § 3 |
| Sécurité | Voir `docs/AIPD-VAE.md` § 4 et § 5 | Idem |

T8 (récupération de l'export public) n'a pas de colonne dans cette fiche modèle : aucune donnée personnelle n'y est traitée. **Le DPO du site
décide s'il l'inscrit au registre** ; il y trouve les faits : un flux sortant facultatif, deux hôtes fixes, aucune donnée sur personne
(`docs/AIPD-VAE.md` § 2, « Flux sortants »).

### Traitements ajoutés le 4 novembre 2026 (T9 à T12)

Les deux tables nouvelles (`tool_vae_eval`, `tool_vae_certif`) font passer `db/install.xml` de 20 à **22 tables** ; leur déclaration
est dans `get_metadata()` (`classes/privacy/provider.php`) et le contrôle `scripts/verifier-privacy.php` les compare à `install.xml` (vert selon le
plan 7 : **non rejoué pour ce complément**). T11 et T12 n'ajoutent aucune table. **Les finalités ci-dessous sont proposées d'après le code ; le
porteur les confirme.** Les décisions du 5 novembre 2026 (D1 à D6) sont portées dans les rubriques qu'elles touchent.

| Rubrique | T9 évaluation d'une compétence | T10 certification d'un bloc |
|---|---|---|
| Responsable | Le site (nom, adresse) | Idem |
| DPO | À compléter | À compléter |
| Finalité (proposée) | Consigner par écrit, dans le dossier, où en est la structure sur une compétence du vaeiste, pour que lui-même, les responsables qui le suivent et la direction le lisent, et que le jury puisse le lire dans l'archive. **Ce n'est pas une validation** | Consigner qu'à une date, avec un motif écrit, la structure se tient derrière un bloc (avec une explication quand une compétence du bloc n'est pas suffisante), et jusqu'à quand si elle le dit. **Ce n'est pas une validation** |
| Personnes concernées | Vaeistes ; responsables qui évaluent (auteurs) ; **tiers que le motif pourrait citer** (une personne accompagnée) | Vaeistes ; membres de la direction qui certifient (auteurs) ; mêmes tiers |
| Catégories de données | Numéro de compte du vaeiste, code de fiche, rang de la compétence, statut, **motif libre**, empreinte SHA-256, identifiant de ligne, compte de l'auteur, date d'écriture ; pour une ligne restaurée d'une archive : identifiant d'archive de l'auteur et date de restauration. Ni note, ni taux | Les mêmes, pour un bloc, plus la date de la décision, le **motif d'alerte libre** et la date « valable jusqu'au » (facultative) |
| Données de l'article 9 | Aucune n'est attendue, mais le motif est un texte libre d'un tiers, qui peut décrire la santé du vaeiste ou d'une personne accompagnée. **Les deux formulaires rappellent, au-dessus du motif, de ne nommer aucune personne accompagnée** (chaîne `anonymiseshort`, décision du 5/11/2026, D3 ; lu dans `classes/form/evaluation_form.php`, build 2026110404, commité) ; la formation (§ 6) reste | Idem pour le motif **et** le motif d'alerte (`classes/form/certification_form.php` : le rappel est posé au-dessus de chacun des deux) |
| Base légale | Contrat (article 6, 1, b) : **décision du 5/11/2026** (§ 2) | Contrat (article 6, 1, b) : idem |
| Destinataires | Le vaeiste ; les responsables qui le suivent ; la direction (`viewany`) ; le jury, par l'archive, le PDF ou la copie modifiable que le vaeiste remet (l'archive peut aussi être produite par qui détient `viewany` et `export` au niveau du site, pour tout dossier) ; l'administrateur qui tient les sauvegardes. **Message sortant : un message au vaeiste concerné à chaque ligne écrite**, sans statut, motif ni auteur (T7 ; fournisseur de messages de Moodle, aucun tiers) | Idem |
| Transferts hors UE | Aucun flux sortant propre. L'aide d'IA ne les lit pas (aucune référence à ces classes ni à ces tables dans `ai_assistant.php` ni `ai_screens.php`). L'archive remise au jury sort de la plateforme : hors de portée du plugin | Idem |
| Durée | Celle du dossier : jusqu'à la suppression du compte du vaeiste, plus un effacement à la demande du DPO du site (§ 3, **décision du 5/11/2026, D4**) ; pas de purge propre | Idem |
| Droits | Accès : lecture à l'écran et export de vie privée de Moodle (le vaeiste reçoit toutes les lignes, un auteur les siennes). Rectification : une ligne de plus, écrite par la structure. Effacement, portabilité : § 4. Contestation, limitation, opposition : le DPO du site, par la clause du contrat d'usage, sans écran (**D5, 5/11/2026**) | Idem |
| Sécurité | Écrire : `tool/vae:evaluate` dans le contexte du vaeiste ET suivre ce vaeiste ; jamais soi-même. Lire : le droit de lire le dossier. Verrou à l'écriture, empreinte, journaux sans motif, message au vaeiste sans contenu. Décrits dans `docs/AIPD-VAE.md` (§ 2, « Positionnements de la structure ») | Écrire : `viewany` ET `certify` au niveau du site ; jamais soi-même. Pour le reste, comme T9 |

| Rubrique | T11 tableau « Organisation » | T12 liste des certifications à renouveler |
|---|---|---|
| Responsable | Le site (nom, adresse) | Idem |
| DPO | À compléter | À compléter |
| Finalité (proposée) | Permettre à la direction de voir la configuration du dispositif (fiches, cours, activités, modèles du cœur, plans, référents, rôles, cohortes, réglages) pour le piloter | Permettre à la direction de voir quelles certifications ont dépassé la date que la structure a elle-même posée, pour décider de les renouveler |
| Personnes concernées | Les référents de bloc (nom complet). Les vaeistes **seulement en nombre** (plans par fiche). Les membres des cohortes ne sont ni listés ni comptés : la cohorte est nommée ; le responsable qui suit n'est pas lu | Les vaeistes dont une certification a dépassé sa date : ils sont nommés |
| Catégories de données | Nom complet des référents encore actifs ; **nombre exact** de plans de formation par fiche (plans de comptes ayant une trace dans le plugin, brouillons compris, sans seuil ; aucun nom de plan, aucun nom de personne) ; noms de cours, d'activités et de modèles (selon le droit du lecteur sur le cœur) ; noms de rôles et de cohortes ; valeurs de réglages | Nom complet du vaeiste, code de fiche, bloc, date « valable jusqu'au », nombre de jours. Aucun motif |
| Base légale | Contrat (article 6, 1, b) : **décision du 5/11/2026** (§ 2) ; seul le nom des référents est une donnée de personne | Contrat (article 6, 1, b) : la même base que T10 |
| Destinataires | Qui détient `tool/vae:viewany` ; la page se refuse sans ce droit | Qui détient `tool/vae:viewany` ET `tool/vae:certify` ; la page se refuse sans l'un des deux |
| Transferts hors UE | Aucun flux sortant | Aucun flux sortant |
| Durée | Aucune conservation : lu à chaque affichage | Aucune conservation : calculée à chaque affichage |
| Droits | Pour un référent : le nom est celui de son compte ; la suppression de son compte retire la règle qui le désigne (`observer::user_deleted`). Le reste : voir § 4 | Passent par T10 : la liste ne stocke rien |
| Sécurité | Droit exigé à chaque lecture. **Risques acceptés par le porteur le 4/11/2026, non mesurés sur un site réel** : (1) le nombre de plans montre à un lecteur que le cœur refuse l'existence de plans et de brouillons ; (2) un petit nombre (1 ou 2) sur une fiche suivie par peu de vaeistes peut désigner une personne pour qui connaît le site ; (3) le nom d'une cohorte masquée peut nommer un groupe de personnes (`docs/AIPD-VAE.md` § 2) | Deux droits exigés ; aucune écriture ; aucun message |

**Décidé le 5 novembre 2026 par le porteur, qui est aussi le DPO du site, pour T9 à T12** (le détail est au § 8) : (1) la base légale, le contrat d'usage (§ 2, D1) ;
(2) l'information du vaeiste, par une clause du contrat d'usage et par un message à chaque ligne écrite (D2) ; (3) le rappel de ne nommer aucune personne accompagnée sur les deux
formulaires (D3) ; (4) la durée de conservation, celle du dossier, plus un effacement à la demande du DPO du site (D4) ; (5) la voie du vaeiste qui conteste une ligne, le DPO du site
sans écran (D5) ; (6) ce qu'il reste d'une ligne dont l'auteur est effacé, le texte reste et l'identité part (D6) ; (7) pour T11 et T12, le nom des référents et la liste suivent le contrat (D1).

**Non vérifié.** L'article 22 : confirmé par le DPO du site le 6 novembre 2026 (§ 2), à refaire par le DPO de chaque autre site installateur. L'effacement d'un auteur et celui d'un vaeiste : couverts par des tests automatiques selon les plans 6 et
7 (relus, non rejoués ici), jamais joués sur un site réel. L'écart entre le plan 6 et l'observateur de suppression de compte (§ 3). **Le message au vaeiste est couvert par des tests PHPUnit (`tests/notifier_test.php`, rejoués le 5/11/2026 avec toute la série, CI verte) ; il a été envoyé le 5/11/2026 par un dialogue SMTP réel vers un récepteur local de test, jamais par le serveur de courriel d'un site réel.** Le rappel des deux formulaires : lu dans le code et compté par un test PHPUnit (`test_the_two_forms_of_the_cycle_carry_the_reminder_not_to_name_anyone_next_to_each_free_field`, rejoué le 5 novembre 2026), non vu à l'écran. La lisibilité des
mots des statuts (« à construire », « en cours de preuve », « suffisante pour certification », « certifié par la structure ») par un accompagnateur
VAE.

## 6. Mesures organisationnelles à la charge du site

- **Former les vaeistes** : ne jamais nommer ni rendre reconnaissable une personne accompagnée ou soignée, ni joindre une
  transmission de soins. C'est la mesure qui réduit le plus le risque R2 de l'AIPD.
- **Informer** (articles 13 et 14) : une mention d'information propre au site, qui reprend ce document ; le plugin fournit l'écran de
  confirmation de l'aide d'IA (la politique d'IA est celle du cœur, à rédiger par le site), pas la mention générale.
- **Supprimer un compte de responsable** : décider avant, comme au § 3 (suspendre, laisser sans suite, désactiver la création automatique
  ou accepter l'anonymisation de ses signatures), et le dire à la personne qui approuve les demandes de suppression de données, avec ce que
  son approbation envoie (messages aux vaeistes et aux responsables concernés, § 3).
- **Traiter la file des demandes de suppression de données de Moodle** : c'est elle, et elle seule, qui vide les textes de l'aide d'IA (§ 3).
  Le site désigne qui approuve et sous quel délai ; tant qu'une demande attend, ces textes restent dans Moodle. S'il désactive la création
  automatique de ces demandes (`automaticdeletionrequests`), plus rien ne les efface : le dire à l'administrateur. S'il a défini la finalité et
  la catégorie du site dans le registre des données, Moodle ne vide jamais ces textes, même demande approuvée (il la déclare quand même
  terminée) : les faire effacer à la main, et le dire à l'administrateur.
- **Former qui évalue et qui certifie (T9, T10)** : le motif est un texte libre que le vaeiste lit et qui part dans l'archive du jury. Y décrire
  où en est la pratique du vaeiste, jamais une personne accompagnée, jamais une donnée de santé. **Les deux formulaires le rappellent désormais** (décision du
  5/11/2026, D3 : phrase `anonymiseshort` au-dessus du motif, et du motif d'alerte pour la certification ; code commité), en plus de la formation.
- **Décider qui détient `tool/vae:evaluate` et `tool/vae:certify`** : ils ne sont donnés à aucun rôle par défaut. Et savoir que `tool/vae:viewany`
  ouvre désormais, en plus de la lecture de tous les dossiers, le tableau « Organisation » (T11) ; avec `tool/vae:certify`, il ouvre la liste des
  certifications à renouveler (T12). Donner `viewany` à un rôle, c'est lui donner ces lectures aussi.
- **Informer le vaeiste de ces positionnements (T9, T10)** (décision du 5/11/2026, D2) : le contrat d'usage dit que la structure consigne sa position, que le vaeiste la lit,
  qu'elle figure dans l'archive remise au jury, qu'il en est prévenu par un message à chaque ligne et qu'il s'adresse au DPO du site pour la contester (clause 6 du § 2). Le message
  est désactivable par la personne ou par l'administrateur : la clause reste donc la preuve de l'information.
- **Écrire la politique de bornage des copies hebdomadaires et des sauvegardes de l'administrateur** (décision du 5/11/2026, D8) : durée, et qui purge ; par le DPO du site. Aucun code.
- **Réexaminer tous les 36 mois la suppression des comptes inactifs** (décision du 5/11/2026, D7) : décision de l'administrateur, réglage de Moodle.
- **Procédure de violation** (articles 33 et 34) : désigner qui prévient la CNIL dans les 72 heures et qui prévient les personnes.
- **Sous-traitants** (article 28) : contrat avec l'hébergeur et avec le fournisseur d'IA ; liste des sous-traitants ultérieurs. **data.gouv.fr
  n'est pas un sous-traitant** : il ne reçoit aucune donnée personnelle (la requête du plugin ne porte aucune donnée sur un vaeiste, un
  responsable ou un autre utilisateur du site ; il voit l'adresse IP du serveur, ou celle de son proxy, l'heure et un User-Agent fixe, comme
  tout site que l'on visite). Ni contrat, ni ligne dans la liste des sous-traitants pour lui ; à confirmer par le DPO du site.
- **Récupération de l'export public (T8)** : **ouvrir le https sortant du serveur vers deux adresses, `www.data.gouv.fr` et
  `static.data.gouv.fr`** (pare-feu sortant, proxy), **ou n'utiliser que le dépôt du zip** : l'administrateur télécharge lui-même l'archive sur
  la page du jeu de données et la dépose dans l'écran d'import ; le serveur n'a alors aucune requête à faire. Si Moodle bloque ces adresses
  (Administration du site, Sécurité, Sécurité HTTP), ne pas assouplir ce réglage pour ce seul besoin : le dépôt du zip suffit. Le droit
  `tool/vae:import` n'est donné par défaut à aucun rôle : le site décide qui peut cliquer.
- **Revue annuelle** de l'AIPD et de ce document.

## 7. Contrat avec le fournisseur d'IA : points à exiger (article 28)

Objet et durée ; **traitement uniquement sur instruction documentée du site** ; **pas de ré-utilisation des textes pour entraîner un
modèle** ; confidentialité du personnel ; mesures de sécurité ; liste des **sous-traitants ultérieurs** et leur accord préalable ;
**lieu du traitement** (Union européenne, sinon mécanisme de transfert et analyse d'impact du transfert) ; **durée de conservation des
requêtes chez le fournisseur** (la plus courte possible, idéalement nulle) ; assistance pour les demandes de droits ; **suppression ou
restitution** à la fin ; audits ; notification des violations. Pour un modèle **local** (Ollama sur un serveur du site), il n'y a pas
de contrat de sous-traitance : la sécurité de ce serveur est celle de l'hébergement du site.

## 8. Décisions

| Décision | Qui | État |
|---|---|---|
| Texte et réponse de l'aide d'IA conservés jusqu'à l'effacement des données du compte | Porteur | **Prise le 25/10/2026**, inscrite dans l'écran de confirmation (reformulée le 26/10/2026 : la politique est désormais celle du cœur) ; **précisée le 27/10/2026** : le plugin n'efface plus rien lui-même, tout est laissé à la demande de suppression de données que Moodle crée à chaque suppression de compte (écran de confirmation corrigé en conséquence : il dit l'une de deux phrases selon l'état du site au moment de l'envoi, voir § 3 ; la ligne de registre de Moodle reste après l'effacement, avec le numéro du compte, la date, le fournisseur, le modèle, les jetons, la réussite ou l'échec et, après un échec, le message d'erreur du fournisseur) |
| La rubrique « Engagement du vaeiste » reste offerte à l'aide | Porteur | **Prise le 25/10/2026** |
| Une seule relecture VAE (aussi garante du RGPD) des textes de l'aide | Porteur | **Prise le 25/10/2026** |
| Conservation du dossier jusqu'à la suppression du compte, avec export avant suppression | Porteur | **Principe retenu le 25/10/2026** ; procédure à écrire (§ 4, point 1) |
| Délai de suppression des comptes inactifs | Site (administrateur) | **Décidé le 5/11/2026 (D7)** : décision de l'administrateur, à réexaminer tous les 36 mois ; le plugin ne supprime aucun compte (réglage de Moodle). **Formule à confirmer dans sa rédaction exacte** ; la proposition de 24 mois est abandonnée |
| Bornage des copies hebdomadaires et des sauvegardes de l'administrateur | Site (DPO) | **Décidé le 5/11/2026 (D8)** : politique du site à écrire par le DPO (§ 6), aucun code. **La durée reste à fixer par le site** |
| Lot « avant suppression » (T-85) | Porteur | À décider |
| Archive complète pour soi-même ; écran de correspondance pour importer une archive quand le site connaît déjà la compétence sous un autre identifiant | Porteur | **Décidés le 27/10/2026, non construits**, chacun à valider avant livraison |
| Mode d'hébergement (H1 à H5) et d'IA (A0 à A3) | Site | À choisir dans l'AIPD |
| Base légale : le contrat pour tous les traitements, aide d'IA comprise | Porteur | **Décision du 25/10/2026** ; clauses du contrat à écrire par le site (§ 2) ; réserves notées au § 2 |
| Récupération facultative de l'export public de France compétences, sur clic d'un administrateur, jamais planifiée, vers deux hôtes fixes (T8) | Porteur | **Décision du 27/10/2026** ; code de la 01.5.3 (Moodle 5.3), alors numérotée 0.47.x. **La récupération n'a pas encore été jouée contre le vrai data.gouv.fr avec ce code : non vérifié.** Le site qui ne veut pas de cette requête utilise le dépôt du zip |
| Cycle interne déclarer, évaluer, certifier : évaluation d'une compétence par le responsable qui suit, certification d'un bloc par la direction (alerte et motif si une compétence n'est pas suffisante), ajout seul, le vaeiste lit ses lignes, archive du jury avec l'état courant et l'historique, restauration à l'import ; le jury reste hors du plugin (T9, T10) | Porteur | **Décisions du 4/11/2026** (plan 6, Q1 à Q11). Code de la version 2026110402, **commité** (`1203ecd`) |
| Date « valable jusqu'au » facultative sur une certification, « à renouveler » calculé à la lecture, liste pour qui détient `viewany` et `certify` (T12) ; lignes de l'ancienne fiche montrées en lecture seule sur la fiche qui la remplace | Porteur | **Décisions du 4/11/2026** (plan 7, R1 à R3). Code de la version 2026110403, reprise dans la 2026110404, **commité** (`1203ecd`) |
| Tableau « Organisation » (T11) : nombre exact de plans, brouillons compris, sans seuil ; cohortes liées à un rôle qui accorde un droit du plugin, nommées même masquées (celle qui est supprimée n'a plus de nom) ; activités de cours en nombre et liste dépliable, activité non visible tue | Porteur | **Décisions du 4/11/2026** (plan 3, D2, D9, D10 ; lot 1 bis, Q1 et Q2). Risques acceptés, non mesurés : § 5 |
| **Base légale de T9, T10 et T12** (et du nom des référents de bloc, T11) | Porteur, avec le DPO du site | **Décidé le 5/11/2026 (D1)** : le contrat d'usage, article 6, paragraphe 1, point b, comme T1 à T7 (§ 2). Confirmation de l'article 22 donnée par le DPO du site le 6/11/2026 (§ 2) |
| **Information du vaeiste** sur les positionnements | Porteur | **Décidé le 5/11/2026 (D2)** : une clause du contrat d'usage (§ 2, point 6) et un message au vaeiste à chaque ligne écrite (évaluation ou certification). Code build 2026110404, **commité** (`e9d8733`) ; testé par PHPUnit (rejoué le 5/11/2026, CI verte), **envoyé le 5/11/2026 par un dialogue SMTP réel vers un récepteur local de test (texte relu : ni statut, ni motif, ni auteur) ; jamais envoyé par le serveur de courriel d'un site réel : non vérifié** |
| **Rappel de ne nommer aucune personne accompagnée** sur les formulaires d'évaluation et de certification | Porteur | **Décidé le 5/11/2026 (D3)** : ajouté au-dessus du motif (et du motif d'alerte), code commité (`e9d8733`), non vu à l'écran |
| **Durée de conservation** des évaluations et certifications | Porteur / site | **Décidé le 5/11/2026 (D4)** : celle du dossier (jusqu'à la suppression du compte), plus un effacement à la demande du DPO du site ; pas de purge automatique propre |
| **Voie offerte au vaeiste qui conteste une ligne** (rectification, limitation, opposition) | Porteur | **Décidé le 5/11/2026 (D5)** : le DPO du site, par la clause du contrat d'usage, sans écran ; une rectification est une nouvelle ligne de la structure, la limitation et l'opposition passent par le DPO |
| Ligne dont l'auteur est effacé : le texte reste, l'identité part | Porteur / DPO | **Confirmé le 5/11/2026 (D6).** Écart avec l'observateur de suppression de compte toujours à vérifier par un essai (§ 3) |
| Complément de `docs/AIPD-VAE.md` pour T9 à T12 | Porteur | **Écrit et commité** (`6652a97`) (§ 2 : positionnements (T9, T10), tableau « Organisation » (T11), liste à renouveler (T12) ; § 3, R11). Restent ouverts les points qu'il note « non vérifié » (base légale d'une échéance, durée de conservation, essais en HTTP réel, MariaDB et PostgreSQL, mesure sur un site réel) |
