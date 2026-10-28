# VAE - cheminement (`tool_vae`)

> Plugin Moodle de type **admin tool**. Il permet à des professionnels engagés dans une démarche de VAE de constituer, au fil de leur activité, une trace datée, attribuée et vérifiable des compétences acquises en situation de travail.
>
> **Nom.** Affiché « VAE - cheminement » (« VAE - pathway » en anglais) ; composant `tool_vae`. Jusqu'à la version 0.10.1, le composant s'appelait `tool_referentiel` : les entrées anciennes du [journal des versions](CHANGELOG.md) gardent ce nom. Cette identification de "Référentiel" fait clairement référence au plugin de M. Jean Fruitet (https://github.com/jfruitet/moodle_referentiel). 
>
> **Un seul paquet maintenu : Moodle 5.3 (branche `main`, suivie par `MOODLE_503`). Le projet de ce plugin VAE sera construit uniquement pour les versions LTS de Moodle.**
>
> **État au 28 octobre 2026 : version 01.5.3 (Moodle 5.3).**
>
> **Numérotation.** Le numéro de publication se lit « génération . branche de Moodle » : **01.5.3** est la première version prévue pour le répertoire des plugins de Moodle (« 01 ») et ne vaut que pour **Moodle 5.3** (« 5.3 »). Les versions 0.x des entrées anciennes du [journal des versions](CHANGELOG.md) sont celles d'avant cette renumérotation. Ce que Moodle compare pour décider d'une mise à jour est le numéro `version` de `version.php` (une date et un rang), pas cette étiquette. Ce qui suit décrit le paquet 5.3 ; le paquet 4.5, qui n'est plus maintenu, est à part (voir plus bas). Un vaeiste déclare l'exercice d'une activité, un responsable l'atteste, chacun est prévenu de ce qui le concerne sans que le récit quitte le dossier, une fiche de certification s'importe et se réimporte sans dégât, et le dossier s'exporte en une archive figée pour le jury, PDF imprimable compris, que l'administrateur d'un autre site peut reprendre après vérification des empreintes, à condition que ce site n'ait pas déjà numéroté autrement les compétences qu'elle nomme (sinon l'import est refusé en entier, et le message nomme les compétences en cause). Une attestation donnée par erreur n'oblige plus à déranger l'administrateur chaque matin : un collègue qui suit le même vaeiste peut en demander le retrait au signataire, qui accepte ou refuse ; et le signataire peut la retirer lui-même si l'administrateur a activé le réglage. Le tableau de couverture montre au vaeiste ce qu'il a déclaré, sans jamais conclure.
>
> **Vérifié par l'intégration continue**, sur PostgreSQL, MariaDB et pour la migration 4.5 → 5.3, à la 0.47.1 (commit `db52ee5` : verte). La même version a été jouée en local sur PostgreSQL : 1 376 tests PHPUnit, 10 406 assertions, 105 scénarios Behat hors JavaScript. Le renommage en 01.5.3 ne change aucun code ; sa propre CI n'est pas encore lue. À la 0.22.0 : **440 tests PHPUnit** (3 994 assertions) et **25 scénarios Behat** (dont le contrôle d'accessibilité) sur le paquet 5.3 ; **272 tests** (3 131 assertions) et 10 scénarios Behat sur le paquet 4.5 à la 0.9.6 ; preuves exportées validées contre le schéma. La migration 4.5 → 5.3 n'est vérifiée que sur PostgreSQL : les fichiers de preuves d'un jeu de deux dossiers ressortent identiques à l'octet, et l'archive et la sauvegarde prises sous 4.5 se réimportent.
>
> **Vérifié à la main le 13 octobre 2026 sur un vrai Moodle 5.3.0** : installation, rendu du `dossier.pdf` en anglais et en français (l'essai a trouvé et fait corriger le pied de page, puis la coupure d'une déclaration entre deux pages), écrans du vaeiste, du responsable et de l'administration, et le retrait délégué de bout en bout (demande, refus, acceptation, réglage, droit).
>
> **Jamais vérifié à ce jour** : l'usage par de vrais vaeistes ; le comportement du plugin sous une charge réelle.
>
> **La suite** est dans la liste de travail du projet, avec la conception de la VAE qui le guide (https://martinez.pro/mod/page/view.php?id=84 & https://martinez.pro/mod/page/view.php?id=85)

---

## Pourquoi ce plugin, et pourquoi « cheminement »

La VAE n'est pas un examen. Elle transforme une compétence éprouvée en qualification, par un travail de réflexion sur la pratique : la formation va du savoir vers l'expérience, la validation va de l'expérience vers le savoir. Le plugin accompagne ce **cheminement** ; le dossier n'en est que le produit final. Il aide à constituer et à mettre en forme — le jury, lui, décide.

---

## Relancer le projet dans six mois

```bash
git clone <dépôt> tool_vae
cd tool_vae
make doctor      # ce qui manque, et ce que ça empêche
make valider     # tous les contrôles exécutables hors ligne
```

Pour l'installer dans un Moodle :

```bash
# Moodle 5.3 (branche main)
cp -r tool_vae <moodle>/public/admin/tool/vae
# Moodle 4.5 : paquet non maintenu, à ne pas installer sur un site réel ; passer à Moodle 5.3
```

puis **Administration du site → Notifications**.

**Première installation, pas à pas, pour débutants** : [`docs/INSTALLATION-PAS-A-PAS.md`](docs/INSTALLATION-PAS-A-PAS.md) (installation, rôles et droits, cohortes, import des fiches, réglages, politique de confidentialité, répétition générale, dépannage).

**Usage, de la première déclaration au livret final remis au jury** : [`docs/GUIDE-D-USAGE.md`](docs/GUIDE-D-USAGE.md) (vaeiste, responsable, direction, administrateur ; l'archive, son PDF, les copies Word, ce qu'il faut remettre ou ne pas remettre au jury).

**Version 3, de la configuration au livrable pour le jury, organisée autour des deux dossiers (faisabilité, validation) et des trois acteurs** : [`docs/GUIDE-D-USAGE-V3.md`](docs/GUIDE-D-USAGE-V3.md). **Version 2, clic par clic dans Moodle 5.3 en français, qui fait quoi et dans quel ordre** : [`docs/GUIDE-D-USAGE-V2.md`](docs/GUIDE-D-USAGE-V2.md) (menus, champs et boutons relevés sur une instance en français ; 905 affirmations vérifiées par six relecteurs indépendants).

**Prérequis :** Moodle 5.3 LTS (branche `main`), PHP 8.3, et le sous-système de compétences activé (*Fonctions avancées → Activer les compétences*). Sans lui, le plugin affiche une explication et s'arrête — il alimente `core_competency`, il ne le remplace pas. Le paquet pour Moodle 4.5 LTS (branche `MOODLE_405`) n'est plus maintenu : un site en Moodle 4.5 passe à Moodle 5.3.

### Dossier de faisabilité : identité, statut, coordonnées

Depuis la 0.16.0, la page « Dossier de faisabilité » du vaeiste comprend les rubriques à champs de l'annexe II de l'arrêté du 3 juillet 2025 (« candidat accompagné », intitulé de l'arrêté). **Ce que le plugin garde** : nom de naissance, date de naissance, nationalité, niveau de formation le plus élevé, niveau et intitulés des certifications obtenues, statut, identifiant de la convention collective. **Ce qu'il lit dans le compte Moodle sans le copier** : prénoms, nom d'usage, courriel, adresse, ville, pays, téléphone. Le genre, que l'annexe laisse facultatif, n'est pas repris. Chaque enregistrement est une version, comparable comme celles des récits ; les valeurs lues du compte ne sont pas versionnées.

- **Lecture par un tiers** : la capability `tool/vae:identityview`, à part de `tool/vae:draftview` et donnée à aucun archétype (MC-11). Lire les récits d'un vaeiste n'ouvre pas sa date de naissance. Le vaeiste lit les siens ; l'administrateur lit comme à l'ordinaire.
- **L'archive d'export n'emporte ni date de naissance, ni nationalité, ni adresse, ni téléphone** (MC-13, décidé le 15 octobre 2026) : elle garde des identifiants opaques.
- **Aucun contrôle de complétude** : l'astérisque dit ce que le formulaire officiel exige, et la rubrique s'enregistre même incomplète. Le plugin ne dit pas qu'un dossier est recevable.
- **Limite** : les listes de l'annexe ont été lues par un lecteur de page, non copiées du Journal officiel ; à comparer avec le texte avant tout usage en production.

### Copies modifiables du dossier (Word, OpenDocument)

Depuis la 0.15.0, le vaeiste (et la direction) peut télécharger son dossier en **.docx** ou en **.odt** pour le relire et le corriger : déclarations, attestations, fiche de certification, avec une phrase en tête qui dit que ce n'est **pas** l'archive délivrée par la plateforme. Même droit que l'archive (`tool/vae:export`) : la copie quitte la plateforme de la même façon. Chaque téléchargement laisse un événement (nombre de déclarations, format, empreinte des données, jamais le texte).

Depuis la 0.17.0, la copie **commence par les rubriques du dossier de faisabilité** (dernière version enregistrée de chacune, jamais un brouillon), puis reprend les déclarations et les attestations. **Identité, coordonnées et statut n'y figurent que pour le vaeiste et pour qui détient `tool/vae:identityview`** : les autres reçoivent la copie sans ces trois rubriques, avec une ligne qui le dit. **Les rubriques elles-mêmes n'y figurent que pour qui peut les lire à l'écran** (le vaeiste, l'administrateur, et qui détient `tool/vae:draftview` en pouvant consulter le dossier) : la direction qui n'a que `viewany` et `export` reçoit la copie sans rubriques. Les intitulés des pièces n'y figurent que pour qui peut ouvrir les pièces (le vaeiste, l'administrateur, et qui peut attester ce vaeiste), comme à l'écran, et non pour qui lit seulement l'identité. La copie hebdomadaire, écrite dans les fichiers du vaeiste, garde tout cela, avec la préparation du dossier de validation. Les dates des copies et des archives sont celles du fuseau horaire du vaeiste, quel que soit le lecteur.

Depuis la 0.19.0, le vaeiste peut **déposer** son dossier de faisabilité : un jalon daté et empreinté (SHA-256) de ce qu'il a **enregistré**, jamais d'un brouillon. Le dépôt n'est pas un verrou : il continue d'écrire, et un nouveau dépôt est un nouveau jalon, numéroté ; les précédents ne changent pas. Les responsables qui le suivent **et** détiennent `tool/vae:readdeposit` sont prévenus (le nom et le numéro, rien des rubriques) et lisent ce jalon, **jamais les brouillons ni les versions** ; l'identité et le statut restent derrière `tool/vae:identityview`. Le plugin enregistre le jalon ; il ne le juge pas et ne remplace pas le dépôt auprès du certificateur. Le dépôt n'est pas dans l'archive d'export ; il se trouve dans l'export des données personnelles de Moodle, qui se lit mais ne se réimporte pas.

Depuis la 0.21.0, un responsable qui suit le vaeiste, et détient `tool/vae:writeopinion` en plus de `tool/vae:readdeposit`, peut **écrire son avis sur un dépôt précis** : du texte libre, daté, empreinté, **jamais réécrit**, que le vaeiste lit (avec le nom de l'auteur) et dont il est prévenu sans le texte. L'avis reste attaché au dépôt qu'il commente : un nouveau dépôt n'en a pas tant qu'on n'en écrit pas un dessus. **Le plugin ne dit pas si l'avis est favorable** et n'est pas la signature du formulaire officiel. L'avis n'est pas dans l'archive d'export ; il se trouve dans l'export des données personnelles de Moodle, qui se lit mais ne se réimporte pas.

Depuis la 0.22.0, un responsable qui suit le vaeiste et peut attester une déclaration peut lui **poser une question sur cette déclaration, avant de l'attester** ; le vaeiste y répond une fois, avec des mots. Les deux textes sont datés, empreintés et jamais réécrits. **Ce n'est ni un verrou ni un verdict** : l'attestation n'attend pas la réponse et le plugin ne dit pas si elle suffit. Le vaeiste est prévenu qu'une question l'attend, sans le texte. La question et la réponse ne sont pas dans l'archive d'export ; elles se trouvent dans l'export des données personnelles de Moodle, qui se lit mais ne se réimporte pas.

Depuis la 0.23.0, l'administrateur peut choisir un **délai en jours** (réglage `overduedays`, zéro = désactivé) après lequel les responsables qui suivent le vaeiste et peuvent attester sont **rappelés, une fois**, qu'une déclaration attend encore leur attestation ; le message nomme le vaeiste et un nombre, jamais le récit. Une page « Déclarations en attente d'attestation » (droit `viewany`) les liste par vaeiste, la plus ancienne d'abord. Ce n'est ni un état de la déclaration ni un jugement : rien n'est retenu.

Depuis la 0.18.0, l'**archive d'export** emporte aussi les **rubriques de texte** du dossier de faisabilité : un fichier `faisabilite.json` (avec son schéma, sous empreinte comme le reste), une section « Dossier de faisabilité » dans le `dossier.pdf`, chaque rubrique dans sa dernière version enregistrée, jamais le brouillon. **Jamais l'identité, le statut ni les coordonnées** (MC-13) : le vaeiste les ressaisit sur le site qui reçoit l'archive. Au réimport, chaque rubrique absente revient en version 1 (note « Restauré depuis l'archive … ») ; **une rubrique déjà écrite sur le site n'est jamais écrasée**. Le fichier est facultatif : une archive d'avant la 0.18.0 se réimporte comme avant. L'archive ne les emporte que pour qui peut les lire à l'écran (le vaeiste, l'administrateur, et qui détient `tool/vae:draftview` en pouvant consulter le dossier) ; la sauvegarde sur Moodle les garde toutes.

**Archive venue d'un autre site.** Chaque site numérote lui-même ses compétences la première fois qu'un dossier en sort (archive, copie Word ou OpenDocument, sauvegarde) ou qu'une archive y est reprise, puis ne change plus ce numéro ; le compte du vaeiste reçoit de même son identifiant dès sa première déclaration, son premier export, sa première copie ou sa première sauvegarde. Une archive d'un autre site n'est donc reprise qu'à **deux conditions** : que le site qui la reçoit n'ait pas déjà numéroté autrement les compétences qu'elle nomme, et que le compte du vaeiste n'y ait pas déjà d'identifiant. Sinon elle est refusée en entier et rien n'est écrit. Pour les compétences, le message donne leur nombre et nomme les dix premières, triées par code (le code et le rang, avec l'intitulé que ce site leur donne), puis compte les autres ; pour le compte, il dit que le compte est déjà connu sur ce site sous un autre identifiant. L'écran d'export et l'écran de sauvegarde disent ces réserves ; l'écran d'export parle au vaeiste (à la deuxième personne) quand il exporte son propre dossier, et du vaeiste (à la troisième) quand la direction ou l'administrateur exporte le dossier d'un autre. Aujourd'hui, l'administrateur peut repartir de zéro sur le nouveau site (nouvelles déclarations, nouvelles signatures des responsables) en gardant l'archive et son PDF comme pièce du dossier, ou demander l'aide d'un développeur. Le plugin ne rapproche jamais de lui-même deux compétences de deux sites : c'est un jugement, qui reviendrait à une personne. Un écran de correspondance validée par une personne, puis une archive complète pour soi, sont des chantiers décidés, **pas construits** ; chacun sera validé avant livraison. L'archive ne contient ni les dépôts du dossier de faisabilité, ni les avis des responsables, ni les questions de précision et leurs réponses : ils se trouvent dans l'export des données personnelles de Moodle, et l'écran d'export le dit.

**Ligne hors format.** Une ligne dont le rang de compétence est hors format (« c7 » au lieu de « C7 ») ne bloque ni l'export de l'archive ni la sauvegarde faite depuis l'écran : l'archive est produite telle que le dossier la tient, avec un avertissement qui nomme la déclaration en cause et une mention dans sa notice `LISEZMOI.txt`. La sauvegarde faite depuis l'écran, avec avertissement, renvoie au dossier (ses messages s'y affichent comme des notifications). La ligne vient d'une déclaration **déjà enregistrée** avec un rang hors format, soit par une version antérieure du plugin (avant la 0.46.0), soit écrite directement dans la base de données ; depuis la 0.46.0, une déclaration nouvelle sur un tel rang est refusée. **Elle ne se corrige pas depuis Moodle** : l'archive reprend le rang écrit dans la déclaration enregistrée, non le numéro d'identification que la compétence porte aujourd'hui, si bien que corriger ce numéro (*Compétences → Référentiels de compétences*) ne réécrit pas la déclaration. L'avertissement revient à chaque export et à chaque sauvegarde tant que la déclaration existe, et il faut en prévenir l'administrateur du site. L'archive ne pourra pas être réimportée telle quelle, l'import restant strict ; la copie modifiable (Word, OpenDocument) reste possible. Le journal de Moodle garde, pour chaque export, le nombre de lignes en cause, et le rapport des journaux l'affiche dans la description de l'événement (seulement quand ce nombre est supérieur à zéro). Une pièce dont le fichier est perdu ou altéré, et un dossier trop volumineux pour être relu, restent refusés.

L'administrateur peut aussi activer, dans les réglages du plugin, une **copie hebdomadaire** : la nuit du dimanche au lundi, une tâche écrit une copie datée dans les **fichiers personnels du vaeiste** (dossier `vae/copies`), seulement si le dossier a changé depuis la dernière copie, et ne garde que les **deux dernières**. Désactivée par défaut. Une demande d'effacement les supprime avec le dossier. Ce traitement a sa base légale et sa durée de conservation à fixer par le site avant activation.

### Pièces justificatives

Depuis la 0.37.0, le vaeiste peut joindre à une déclaration des **pièces justificatives** : un **fichier** (PDF, ODT, DOCX, JPEG, PNG) ou un **lien**, avec une **nature** choisie dans une liste fermée (bulletin de paie, contrat de travail, attestation de l'employeur, diplôme ou attestation de formation, fiche de poste, autre) et un intitulé facultatif. **Le plugin ne lit pas une pièce, ne la juge pas et ne dit pas qu'elle suffit** ; il ne visite jamais une adresse saisie par un utilisateur (sa seule requête sortante propre (l'aide d'IA et les courriels sortent par le cœur de Moodle, pas par le plugin) est la récupération, sur clic d'un administrateur, de l'export de France compétences, vers deux hôtes fixes du code : voir « Importer des fiches depuis France compétences »). **Fermé par défaut** (réglage `piecesopen`) : à n'ouvrir que lorsque l'amendement 4 de `DER-referentiel-001` est validé et que la durée de conservation de tels fichiers est écrite (T-84). Conception : `docs/T-13-pieces-jointes.md`.

- **Ce qui est contrôlé.** Le type est lu **sur le contenu**, jamais sur le nom ; **le nom d'origine du fichier n'est jamais gardé** (le fichier est enregistré, servi et archivé sous un identifiant) ; 5 Mo par fichier, 5 pièces par déclaration, 30 Mo par vaeiste (réglables sous ces bornes : l'archive tient 50 Mio) ; macros, scripts, objets incorporés et relations vers l'extérieur sont refusés dans un DOCX ou un ODT, et les actions qui démarrent à l'ouverture dans un PDF quand elles sont lisibles. **C'est une liste d'interdits, pas une garantie.** L'antivirus du cœur est appelé ; **sans antivirus configuré, les fichiers sont refusés**, sauf si l'administrateur l'accepte par écrit (réglage `piecenoantivirus`). Une empreinte SHA-256 est calculée à la réception et recomparée à chaque export. Un lien n'a pas d'empreinte : ce qui est à une adresse peut changer ; il est signalé quand il porte une requête ou désigne une adresse qui semble privée.
- **Qui lit.** Le vaeiste ; qui peut attester ce vaeiste ; l'administrateur du site. Qui peut seulement lire le dossier voit la **liste** (nature, type, taille, empreinte, dates, pas l'intitulé) et n'ouvre rien. Le téléchargement est toujours forcé.
- **L'attestation.** L'écran liste les pièces ; le formulaire porte la liste affichée, et **la signature est refusée si les pièces ont changé** depuis l'affichage. La liste signée est gardée avec l'empreinte de chaque fichier, avec **une case** « J'ai pris connaissance des pièces » : une **déclaration**, pas un fait. L'écran dit combien de fichiers le signataire a ouverts, et que **ouvrir n'est pas lire**. Une pièce jointe **après** la signature est marquée comme telle, et les responsables qui suivent le vaeiste en sont prévenus (sans rien de la pièce).
- **Le retrait** est toujours possible pour le vaeiste, avant ou après l'attestation : le fichier, l'intitulé et l'adresse sont effacés, la nature, le type, la taille, l'empreinte et les dates restent. Ce que le retrait ne peut pas atteindre est écrit à l'écran : une copie déjà téléchargée, la corbeille de la plateforme jusqu'à son vidage, une archive déjà remise à un jury.
- **Dans les archives.** `pieces.json` (schéma `docs/schema/pieces.schema.json`) liste les pièces ; il ne dit jamais ce qu'elles contiennent.

| | Liste (`pieces.json`) | Octets des fichiers | Adresses des liens |
|---|---|---|---|
| Archive que **le vaeiste exporte lui-même** | oui | oui (`pieces/`) | oui |
| **Sauvegarde** conservée sur la plateforme (filet de la désinstallation) | oui | oui | oui |
| Export fait par **un tiers** (direction, `viewany` et `export`) | oui | **non** | **non** |
| PDF et copie Word/ODT | oui, en liste | **non** | **non** |

  L'intitulé d'une pièce (champ libre) suit les octets : absent de l'export d'un tiers.

  Au réimport, les pièces reviennent par la porte d'une personne (type lu sur le contenu, antivirus, empreinte, adresse et intitulé tels que le plugin les accepte, bornes tenues) ; une archive qui ne la passe pas est refusée **en entier, par le nom de la pièce**. **Si la jonction de pièces est fermée ici et que l'archive a des pièces à restaurer, elle est refusée** (une déclaration ne s'importe pas deux fois : des pièces laissées en silence seraient perdues) : ouvrir, importer de nouveau, refermer. Ce qui vient sans octets ni adresse (l'export d'un tiers) est compté et laissé. **Un plugin antérieur à la 0.39.0 réimporte les déclarations et perd les pièces sans le dire.**
- **Ce que le plugin ne peut pas faire.** Vérifier qu'un document ne nomme personne ; voir ce qu'un document garde en lui (auteur, dates, position d'une photographie : les métadonnées ne sont pas retirées) ; savoir si un responsable a lu une pièce. Pour un fichier, le régime 2 du projet ne tient plus sur les mêmes preuves : la probabilité du risque R2 est relevée de « faible » à « moyenne » dans l'amendement 4.

### Règle de validation du certificateur : un lien, jamais un texte

Depuis la 0.40.0, un administrateur peut donner, pour chaque fiche importée, **jusqu'à cinq adresses** où le certificateur publie sa règle de validation (la fiche du répertoire national, l'arrêté du diplôme sur Légifrance), chacune avec un **intitulé court** et, si l'administrateur le dit, le **jour où il a vérifié la page**. *Compétences → Règles de validation : adresses.*

- **Le plugin ne copie, ne lit et n'applique aucune règle.** L'export du répertoire national ne donne pas la règle de validation en VAE (constat du 22/10/2026) : copier « validation de tous les blocs » aurait laissé croire que c'est la règle de la VAE. Une adresse ne peut pas devenir fausse dans un dossier, seulement sur la page qu'elle nomme, et le jour de vérification le dit.
- **Sur le dossier du vaeiste**, **un seul encadré, au-dessus de toute la couverture** (jamais entre le titre d'une fiche et ses nombres) : « Règle de validation : voir » et, par fiche, les liens (nouvel onglet, adresse entière au survol), puis une phrase fixe : le plugin n'applique pas cette règle, ne dit pas si le dossier la satisfait, ne vérifie pas les adresses ; la décision revient au jury. L'encadré est à part, jamais sur la ligne d'un bloc, sans couleur, et rien n'est mis à côté des nombres de la couverture (D-1).
- **Le PDF et la copie Word/ODT** écrivent les adresses en texte (jamais un lien actif) avec la même phrase ; `referentiel.json` porte `regle_validation`. Les adresses ne sont pas relues à l'import : ce sont celles du site.
- **Fermé par défaut** (`refopen`) : les adresses peuvent être saisies, aucun vaeiste ne les voit, ni sur l'écran ni dans les documents, tant que le réglage n'est pas coché. **À ne cocher qu'après relecture de la phrase et de sa place par un accompagnateur VAE.** Le plugin n'ouvre jamais une adresse saisie par un utilisateur : le clic est celui du lecteur. (Sa seule requête sortante propre (l'aide d'IA et les courriels sortent par le cœur de Moodle, pas par le plugin) est la récupération, sur clic d'un administrateur, de l'export de France compétences, vers deux hôtes fixes du code ; aucune adresse n'y vient d'une saisie.)

### « Hors de mon activité » : la parole du vaeiste, sans effet

Depuis la 0.41.0, un vaeiste peut dire qu'une compétence ou un bloc d'une fiche importée **ne relève pas de son activité** (*Dossier → Hors de mon activité*), avec une note facultative d'une ligne sous le rappel d'anonymisation.

- **C'est une déclaration, pas une dispense.** Personne ne la signe (ce n'est pas une attestation), elle n'est comptée nulle part, ne déclenche aucun rappel ni aucun message, et ne change rien à la couverture. Le jury décide si une compétence non exercée compte : pour certains diplômes, elle compte. La phrase le dit partout où le marqueur apparaît : *« Ce marqueur est la parole du vaeiste sur son activité. Il n'exempte de rien : la décision revient au jury. »*
- **Sur le dossier**, dans un encadré **à part** de la couverture, lu par le vaeiste, par qui le suit et par qui lit tous les dossiers. Le vaeiste seul le pose et le retire.
- **Dans l'archive** : `hors_activite.json` (schéma `docs/schema/hors_activite.schema.json`), dans toute archive du dossier d'un vaeiste qui en a posé ; dans le PDF et la copie Word/ODT ; **réimporté sans écraser** un marqueur déjà posé, et en laissant de côté une compétence que ce site ne tient pas.
- **Fermé par défaut** (`scopeopen`) : aucun vaeiste ne peut en ajouter tant que le réglage n'est pas coché ; ce qui a été dit reste lisible et peut être retiré. **À ne cocher qu'après relecture du libellé et de la phrase par un accompagnateur VAE.**

### Référents des blocs : un aiguillage des messages, pas un droit

Depuis la 0.42.0, un administrateur peut nommer, pour un bloc d'une fiche importée (ou pour toute la fiche), des membres du personnel *référents* (*Compétences → Référents des blocs*). **C'est une règle du site, écrite une fois, pas une fois par vaeiste.**

- **Elle décide qui est prévenu en premier** d'une déclaration sur ce bloc, parmi les responsables qui suivent le vaeiste : les référents du bloc et de la fiche entière s'ils sont parmi eux, **tous les responsables sinon** (rien ne se perd en silence si un bloc n'a pas de référent ou si son référent ne suit pas ce vaeiste). Même aiguillage pour le message « pièce ajoutée après une signature ».
- **Elle ne donne et n'ôte aucun droit** : qui suit un vaeiste lit toujours son dossier et peut attester. Rien n'est compté ni classé par référent.
- Le rappel d'attente (T-22) et les autres messages (dépôt, avis, précision) ne sont pas aiguillés : ils portent sur le vaeiste, non sur un bloc.
- Depuis la 0.43.0, sur la page *Mes vaeistes*, un référent peut **compter les déclarations d'un de ses blocs** (ou de toute une fiche) : les nombres se limitent à ce bloc, **la liste des vaeistes ne change pas** et il peut toujours lire tout ce qu'il suit. Un responsable qui n'est référent de rien ne voit pas ce choix. Comptes seulement.

### Aide à la reformulation par IA : fermée, et jamais sans confirmation

Depuis la 0.44.0 (serveur) et la 0.45.0 (écrans), un vaeiste peut demander **une autre formulation d'un texte qu'il a écrit lui-même** : une rubrique de texte du dossier de faisabilité, la préparation du dossier de validation, la situation d'une déclaration. **Jamais** le journal réflexif, jamais l'identité. **Fermée par défaut** (réglage `aiopen`) : à n'ouvrir qu'une fois écrits la base légale, le registre, la durée de conservation, le contrat avec le fournisseur (T-84) et relus par un accompagnateur VAE et par la personne qui tient le registre l'instruction envoyée (`ai_assistant::PROMPT`) et le texte de la politique.

- **Ce qui doit être vrai pour que le bouton paraisse** : le réglage ouvert ; le droit `tool/vae:useai`, donné à la main (aucun rôle ne l'a par défaut ; **un administrateur du site passe tous les contrôles de droit, le vrai verrou est le réglage**) ; **un seul fournisseur d'IA actif pour le texte** (le cœur essaie les fournisseurs l'un après l'autre : avec deux, le texte pourrait partir chez le second sans que le vaeiste le sache). Sinon, les formulaires sont comme avant.
- **Trois écrans, toujours en POST** (`ai.php`) : la **politique d'IA du site**, le texte du cœur (« Politique d'usage IA », que le site rédige une fois pour tous les endroits qui offrent de l'IA), à accepter une fois pour tout le site, dans Moodle ou ici (consignée dans le registre du cœur) ; la **confirmation, à chaque envoi et qu'on ne peut pas désactiver**, qui montre le texte exact, le nom du fournisseur, le nombre de caractères et ce qui est conservé, et rappelle de ne nommer personne ; le **résultat**, la proposition à côté de l'original, avec la mention qu'elle est à relire. **Rien n'est enregistré** par le plugin : le vaeiste copie, ou non, et enregistre lui-même.
- **Plafonds** : 3 000 caractères au plus, 30 secondes entre deux appels, 25 appels par jour (les deux derniers réglables entre des bornes du code), comptés par le plugin dans `tool_vae_aicall` (qui et quand, jamais le texte, purgés au bout de trois jours au plus ; ce registre ne sert qu'à ces deux plafonds). Le limiteur du cœur ne le permet pas : c'est une fenêtre fixe d'une heure dans un cache.
- **Ce que le fournisseur reçoit en plus du texte** : un code qui désigne le vaeiste sans le nommer, et la **consigne réglée par l'administrateur dans l'administration de l'IA** (à faire relire avec l'instruction du plugin avant d'ouvrir). L'écran de confirmation le dit.
- **Pas de perte du texte non enregistré** : le bouton ouvre `ai.php` dans un nouvel onglet ; la page de rédaction garde son texte. Chaque confirmation porte un jeton à usage unique et la marque du fournisseur nommé : recharger la page, un double clic ou un fournisseur changé entre-temps n'envoient rien et ramènent à la confirmation.
- **Ce qui reste dans le cœur** : le texte envoyé et la réponse sont conservés par Moodle dans ses propres tables, rattachés au contexte du site au nom du vaeiste. **Le plugin n'en efface aucun lui-même**, ni à la suppression d'un compte ni à un autre moment. Moodle ne les vide que lorsqu'une personne approuve une demande de suppression de données (celle que Moodle crée à la suppression d'un compte, ou celle que le vaeiste dépose lui-même), **et à deux conditions** : que le site crée lui-même ces demandes à la suppression d'un compte (réglage `automaticdeletionrequests` de `tool_dataprivacy`, actif par défaut, avec approbation manuelle par défaut), sinon rien ne les crée ; **et que la finalité et la catégorie du site ne soient pas définies** dans le registre des données (*Administration du site → Utilisateurs → Protection des données et politiques → Registre des données*). Si elles le sont, Moodle ne vide **jamais** ces textes, rattachés au contexte du site, et déclare quand même la demande terminée : il faut alors les faire effacer à la main. Tant qu'une demande n'est pas approuvée, ils restent dans Moodle. **L'administrateur doit le savoir.** L'écran de confirmation de l'aide dit l'une de deux phrases selon l'état du site au moment de l'envoi : la phrase normale (les textes sont conservés jusqu'à l'effacement des données, après la suppression du compte et l'approbation de la demande), ou, quand l'une des deux conditions manque, que sur ce site Moodle ne les efface pas de lui-même et qu'il faut le demander à l'administrateur. Dans tous les cas, Moodle garde après l'effacement une ligne de registre : le numéro du compte (qui reste rattaché au nom dans Moodle), la date, le fournisseur, le modèle, la taille de l'échange en jetons, la réussite ou l'échec de l'appel et, après un échec, le message d'erreur du fournisseur, dont le plugin ne maîtrise pas le contenu. Le journal du plugin ne contient jamais le texte.

### Importer des fiches depuis France compétences : un écran, deux voies

Depuis la 0.47.0, un administrateur (droit `tool/vae:import`) importe des fiches **par un écran** (*Compétences → VAE - cheminement → « Importer des fiches depuis France compétences »*) : il tape des numéros de fiche (`RNCP12345`, `RS1234`, des exemples de forme ; vingt au plus), puis choisit **une voie**. Il **dépose** le fichier zip de l'export qu'il a téléchargé sur data.gouv.fr, ou il clique sur **« Aller chercher le dernier export »** : le serveur du site le récupère, au clic et seulement alors. Le plugin lit le fichier zip sans rien extraire, montre pour chaque numéro l'intitulé publié en entier, ce que Moodle détient déjà et ce que l'import fera, et **n'écrit rien avant la confirmation**. Une fiche refusée (numéro absent, fiche sans bloc, code de bloc inattendu…) l'est par son nom, sans bloquer les autres. Source, licence, paternité et date ne se retapent plus : la date est celle de l'export, lue dans le nom du fichier ; un export plus ancien que ce que Moodle détient est signalé et n'est importé que si une case est cochée. L'écran CSV à quatre colonnes reste (« cas particulier »), et la ligne de commande `scripts/convertir-fiche-rncp.php` reste comme voie de secours.

**La requête de la voie « Aller chercher » est la seule que le plugin fasse de lui-même** : deux hôtes écrits dans le code (`www.data.gouv.fr`, `static.data.gouv.fr`), https, jamais planifiée, aucune donnée sur personne (mesure compensatoire MC-30, `make sortie`). **Non vérifié** : cette voie n'a jamais été jouée contre le vrai data.gouv.fr ; l'écran n'a jamais été cliqué par une personne (les scénarios Behat du dépôt de fichier, des refus, de l'export plus ancien et du droit sont verts en local, sur des fichiers zip inventés) ; l'hébergement réel du porteur n'est pas connu. L'intitulé complet d'une fiche de plus de 100 caractères (CAFERUIS, CAFDES) n'est conservé nulle part : le référentiel porte le numéro pour nom. Détail et mesures : [`docs/IMPORT-FICHES-FRANCE-COMPETENCES.md`](docs/IMPORT-FICHES-FRANCE-COMPETENCES.md).

### Fiche importée par erreur : retirer sa ligne

Quand le fichier d'un diplôme a été importé sous le code d'un autre, la fiche fausse est visible de tous les vaeistes. Le geste complet tient en trois temps : **masquer** d'abord la fiche (*Visible : Non* dans Moodle ; voir « Une fiche cachée dans Moodle n'accepte plus de déclaration », plus bas), **supprimer le référentiel dans Moodle** si Moodle l'accepte (il refuse tant qu'un cours, un plan, un modèle de plan, un badge ou une compétence d'utilisateur s'appuie sur l'une de ses compétences, donc dès qu'un vaeiste a déclaré dessus), puis **retirer la ligne de la fiche** dans ce plugin.

- **Le bouton « Retirer cette ligne »** est offert, sur la page des fiches importées (*Compétences → VAE - cheminement*), à qui détient `tool/vae:import`, quand Moodle n'a plus le référentiel de la fiche, qu'aucun référentiel de Moodle ne porte ce code et qu'aucun vaeiste n'a laissé de trace sur ce code : ni déclaration, ni marque « Hors de mon activité », ni rubrique de la préparation du dossier de validation. Trois situations laissent une fiche sans bouton, et la page dit les deux premières par une phrase propre, pour les fiches qu'elles concernent : **une trace de vaeiste** (rien ne se retire : ce qu'un vaeiste a écrit n'est jamais supprimé depuis cet écran) ; **un référentiel de Moodle qui porte déjà le code** (fait à la main, par exemple, et même caché : la page dit de le supprimer d'abord dans Moodle) ; **tout autre cas**, où la page n'a que sa phrase générale (le référentiel n'existe plus ; importer la fiche de nouveau), sans parler de bouton : c'est ce qu'elle dirait sans le droit `tool/vae:import`, que son accès exige pourtant déjà.
- **Un écran de confirmation** liste ce qui part avec la ligne : la licence et la paternité de la source, les dates notées, les adresses de règles de validation, les référents de bloc, les marques de compétences absentes de la fiche, les correspondances entre compétences, la fiche remplaçante déclarée, et le lien des fiches qui la désignaient comme remplaçante. Le plugin n'écrit rien dans les référentiels de Moodle, et la ligne ne se récupère pas : importer de nouveau la fiche en crée une neuve.

---

## Ce que fait ce plugin, et ce qu'il ne fait pas

Moodle possède déjà un sous-système de compétences complet. Ce plugin l'**alimente** et l'**étend** ; il ne le réimplémente jamais.

**Il apporte** la collecte continue de preuves d'acquis produites en situation de travail — avec période d'exercice, cadre d'intervention et attestation d'un responsable — et leur restitution sous une forme exploitable pour un dossier de validation.

**Il n'apporte pas** :

- Il ne remplace pas `core_competency` et ne redéfinit aucune de ses entités.
- **Il ne se substitue en rien au certificateur.** Aucune recevabilité, aucune pré-évaluation, aucun avis sur l'issue probable. Il collecte et met en forme — le jury juge.
- Il n'édite pas le référentiel de certification : importé, jamais modifié depuis le plugin.
- Il n'embarque aucun modèle d'IA.

---

## Deux actes distincts

| Acte | Qui | Capability | API du cœur |
|---|---|---|---|
| **Déclaration** | Le **vaeiste**, sur lui-même | `tool/vae:declare` | `create_user_evidence()` + rattachement |
| **Attestation** | Le **responsable** | `tool/vae:attest` | **aucune** — ligne du plugin |

L'attestation étant une ligne du plugin, elle peut être **retirée sans détruire la preuve** du vaeiste : le dossier dit alors qu'une attestation a existé et qu'elle a été retirée à telle date, pour tel motif, et que cette attestation n'est plus valable. **Seulement si aucune autre attestation n'est en vigueur sur la déclaration**, il ajoute, une seule fois après la dernière attestation retirée, que la déclaration n'a pas été modifiée et que le vaeiste peut demander à un responsable de l'attester de nouveau. Même règle et mêmes phrases à l'écran, dans le PDF et dans les copies Word ou OpenDocument ; le fichier lisible par machine garde son motif codé.

Le récit saisi dans le formulaire est du **texte brut** : le vaeiste n'a aucun format à choisir, et les balises qu'il taperait sont retirées. Une archive réimportée peut porter un récit en Markdown ; le format HTML est refusé partout.

**Une fiche cachée dans Moodle n'accepte plus de déclaration.** Un référentiel de compétences dont l'administrateur a mis *Visible* sur *Non* (*Administration du site → Compétences → Référentiels de compétences*) refuse la déclaration d'une activité et le report d'une déclaration vers cette fiche. Le vaeiste lit un message écrit pour lui : l'intitulé et le code de la fiche, qu'elle est cachée, qu'on ne peut pas y déclarer pour le moment, et de s'adresser à son responsable ou à l'organisme qui l'accompagne ; aucun chemin d'administration. Un formulaire ouvert avant le masquage rend le texte déjà écrit au lieu de le perdre ; la liste des compétences que le formulaire de déclaration redonne après un refus commence par « Choisir… » et n'en présélectionne aucune, pour qu'un second envoi ne déclare pas sur une autre fiche à l'insu du vaeiste. L'adresse du report, ouverte sur une fiche déjà cachée, ramène au dossier avec ce message. Les déclarations déjà faites ne changent pas ; la restauration d'une archive et l'écran « Hors de mon activité » ne sont pas concernés. L'administrateur ne peut pas non plus essayer une fiche cachée avec un compte d'essai de vaeiste. Conseil : importer une fiche qui n'est pas prête, puis la cacher aussitôt.

---

## Habilitations — à attribuer explicitement

**Aucune des capabilities touchant à la donnée ne porte d'archétype par défaut.** Ce n'est pas un oubli.

L'archétype `user` désigne le rôle « Utilisateur authentifié », attribué au **contexte système**. Le contexte utilisateur étant un enfant direct du système, une capability accordée à cet archétype au niveau `CONTEXT_USER` serait détenue par chaque utilisateur dans le contexte de **tous les autres**. Le plugin prouve l'appartenance en PHP — `\tool_vae\local\access` — jamais par une capability héritée.

Il faut donc créer un rôle et l'attribuer. Le rôle du vaeiste se crée avec le **modèle de rôle « Étudiant »** de Moodle, choisi dans le formulaire du rôle — et non « MODÈLE : Étudiant » sur le premier écran, qui recopierait les 81 droits d'un étudiant de cours, dont « Voir les profils des utilisateurs », sur tout le site : le vaeiste est rangé avec les étudiants sans recevoir un seul de leurs droits. Les rôles du responsable et de la direction n'ont pas de modèle (guide d'installation, partie 6). Quinze capabilities, par ce qu'elles ouvrent :

| Capability | Niveau | Ce qu'elle ouvre |
|---|---|---|
| `declare` | utilisateur | déclarer une activité, sur soi |
| `viewown` | utilisateur | lire son propre dossier |
| `attest` | utilisateur | attester la déclaration d'un vaeiste que l'on suit |
| `viewassigned` | utilisateur | lire le dossier des vaeistes que l'on suit (rattachement par cohorte) |
| `viewany` | système | lire tous les dossiers — la direction |
| `export` | utilisateur | exporter un dossier en archive ; avec `viewown` sur le sien, avec `viewany` sur ceux des autres |
| `withdrawany` | utilisateur | retirer **n'importe quelle** attestation d'un dossier que l'on peut lire — voir « Retirer une attestation » |
| `import` | système | importer des fiches de certification (fichier CSV, ou export de France compétences déposé ou récupéré sur data.gouv.fr), et retirer la ligne d'une fiche importée par erreur — réservée par défaut à l'administrateur |
| `importdossier` | système | réimporter une archive, **et sauvegarder sur Moodle le dossier de n'importe quel vaeiste** — réservée par défaut à l'administrateur ; à n'attribuer qu'avec le soin de `viewany` ; la sauvegarde, comme le réimport, demande aussi le droit du cœur `moodle/competency:userevidencemanage` sur le vaeiste concerné |
| `draftown` | utilisateur | rédiger les rubriques de son dossier de faisabilité (brouillons, versions) ; donnée à aucun archétype |
| `draftview` | utilisateur | lire les rubriques et les versions d'un autre dossier **que l'on peut consulter** (`viewany`, ou en suivant le vaeiste) ; donnée à aucun archétype, **pas** aux responsables qui suivent (ils lisent le dépôt) |
| `identityview` | utilisateur | lire l'identité et le statut d'un dossier de faisabilité ; en plus de la lecture (qui suppose de pouvoir consulter le dossier), jamais à la place ; l'administrateur la détient d'office |
| `readdeposit` | utilisateur | lire les dépôts du dossier de faisabilité d'un vaeiste **que l'on peut consulter** (`viewany` pour la direction, `viewassigned` pour le responsable qui suit) ; jamais ses brouillons ni ses versions ; donnée à aucun archétype ; l'identité d'un dépôt demande en plus `identityview` |
| `writeopinion` | utilisateur | écrire un avis sur un dépôt d'un vaeiste que l'on suit ; exige aussi `readdeposit` ; donnée à aucun archétype |
| `linkcourse` | cours | rattacher toute une fiche importée aux compétences d'un cours ; gestionnaire par défaut ; exige aussi `moodle/competency:coursecompetencymanage` dans le cours, que les gestionnaires détiennent |

**Les droits du cœur de Moodle.** Le plugin lit les fiches importées (liste des compétences, dossier, couverture, rattachement à un cours, sauvegardes) sans le droit du cœur `moodle/competency:competencyview`. Mais le cœur le redemande à l'enregistrement d'une déclaration, quand il relie la preuve à la compétence : le vaeiste doit donc le détenir (par défaut il lui vient de l'archétype « Utilisateur authentifié »), et la page de déclaration le dit quand ce n'est pas le cas, au lieu d'ouvrir le formulaire. Trois actions demandent, elles, un droit du cœur de gestionnaire : l'import (`moodle/competency:competencymanage`), le rattachement à un cours (`moodle/competency:coursecompetencymanage`) et la sauvegarde ou le réimport d'un dossier (`moodle/competency:userevidencemanage`, sur le vaeiste).

Trois règles qui ne se lisent pas dans le tableau :

- **`attest` ne suffit pas seule.** Attester exige de **lire** le dossier : suivre le vaeiste (`viewassigned` dans son contexte) ou détenir `viewany`. Un rôle portant `attest` au niveau du site, sans l'une ou l'autre, n'ouvre aucun dossier (corrigé en 0.8.3).
- **`export` ne suffit jamais seule.** Un responsable qui suit un vaeiste lit son dossier, il ne l'exporte pas.
- **« Sauvegarder sur Moodle »** (garde : `importdossier`) place un dossier dans les fichiers personnels de l'administrateur. Chaque dossier y est sauvegardé automatiquement à la désinstallation du plugin **si elle passe par l'écran d'administration ou par `admin/cli/uninstall_plugins.php`** — jamais en supprimant d'abord le dossier du plugin, auquel cas rien n'est sauvegardé et les tables sont perdues. On réimporte après réinstallation, une fois les fiches de certification réimportées, à trois réserves : sauf si la sauvegarde porte la mention d'une ligne dont le format n'est pas celui attendu (notice `LISEZMOI.txt` de la sauvegarde, et avertissement affiché par l'écran de sauvegarde), le réimport refusant alors l'archive en entier ; sauf si, depuis la réinstallation, un dossier est déjà sorti du site sur les mêmes compétences (archive, copie Word ou OpenDocument, sauvegarde), le site leur ayant alors donné un nouveau numéro (voir « Archive venue d'un autre site ») ; et sauf si, depuis la réinstallation, le compte du vaeiste a déjà reçu un nouvel identifiant (dès sa première déclaration, son premier export, sa première copie ou sa première sauvegarde), le réimport refusant alors l'archive en entier. La sauvegarde faite depuis l'écran ne refuse plus un dossier pour une telle ligne ; elle refuse toujours une pièce dont le fichier est perdu ou altéré et un dossier trop volumineux pour être relu, ce que celle de la désinstallation ne fait pas. La sauvegarde est l'archive normative : elle ne contient ni les dépôts du dossier de faisabilité (numéros, dates, empreintes, notes), ni les avis des responsables, ni les demandes de précision et leurs réponses, ni les versions antérieures des rubriques, ni qui a décidé un report et sa note, ni — quel que soit celui qui la déclenche — le journal de bord et la préparation du dossier de validation ; un réimport ne les rend pas (le journal et la préparation se gardent par l'export du vaeiste lui-même). Depuis la ligne de commande, où personne n'est connecté, elle se fait sans aucun droit de lecture des compétences, y compris pour un vaeiste qui a posé une marque « Hors de mon activité », et porte les intitulés des compétences. Réécrite (retrait d'une pièce, effacement d'un signataire), elle ne l'est que dans les fichiers des comptes qui détiennent encore `importdossier` et, sur ce vaeiste, `moodle/competency:userevidencemanage`.

### Retirer une attestation

**Par défaut, seul l'administrateur du site retire directement une attestation.** Trois voies s'y ajoutent. Les deux premières sont dormantes tant que l'administrateur ne les a pas ouvertes ; la troisième n'a pas d'interrupteur — elle est disponible dès qu'un second responsable suit le même vaeiste — mais elle ne retire rien par elle-même : le signataire décide.

1. **Le signataire retire la sienne.** Case *Administration du site → Compétences → VAE : réglages* : « Les responsables retirent leurs propres attestations » (décochée par défaut). Il retire ce qu'il a signé lui-même, jamais celle d'un collègue.
2. **Un coordinateur retire n'importe laquelle.** La capability `tool/vae:withdrawany`, attribuée par l'administrateur à un rôle ou à une personne, dans le contexte des vaeistes qu'elle suit. Elle ne dispense pas de pouvoir **lire** le dossier.
3. **Un responsable demande à un collègue.** Sur l'attestation d'un collègue, le responsable qui suit le vaeiste et peut attester voit « Demander le retrait au signataire » (tant que le signataire a un compte actif : supprimé ou suspendu, le dossier renvoie à l'administrateur). Le signataire est prévenu (s'il suit le vaeiste, ce qui est le cas d'un responsable rattaché par cohorte ; un lecteur de `viewany` seul voit les boutons en ouvrant le dossier, sans message), et voit deux boutons : **Accepter le retrait** (c'est un retrait ordinaire, il en choisit le motif) ou **Refuser** (le demandeur en est prévenu, qu'il suive ou non le vaeiste : la réponse suit la question ; l'attestation reste). Une demande à la fois ; une fois la réponse donnée, la demande elle-même n'est pas conservée (le journal des événements de Moodle garde seulement l'acte).

Ce qui ne change jamais : le motif est obligatoire et pris dans une liste fermée (erreur de signature, contenu contesté, demande de suppression de données) ; le vaeiste ne retire jamais une attestation sur son propre dossier ; une attestation reprise d'une archive n'a pas de signataire sur ce site, donc seuls l'administrateur et un détenteur de `withdrawany` la retirent ; le retrait efface le signataire, sa fonction, son énoncé et la date de signature — la déclaration du vaeiste n'est pas touchée.

### Rattacher les responsables à leurs vaeistes, établissement par établissement

Un responsable ne lit que les dossiers des vaeistes qu'il suit — jamais ceux de tout le site. Le rattachement passe par un outil **déjà présent dans Moodle**, « Gestion des rôles de cohorte » (`tool_cohortroles`), qui donne un rôle à une personne dans le contexte de **chaque membre d'une cohorte**, et le tient à jour d'heure en heure.

1. **Une cohorte par établissement ou service** (*Administration du site → Utilisateurs → Cohortes*), contenant ses vaeistes.
2. **Un rôle « Responsable VAE »** (*Utilisateurs → Permissions → Définition des rôles*) :
   - contexte d'attribution : **Utilisateur**, et lui seul ;
   - capabilities : `tool/vae:viewassigned` et `tool/vae:attest`.
3. **Le rattachement** (*Utilisateurs → Permissions → Gestion des rôles de cohorte*) : tel responsable, rôle « Responsable VAE », telle cohorte. Un vaeiste ajouté plus tard à la cohorte est rattaché à la synchronisation suivante, au plus tard une heure après.

Le responsable trouve ses vaeistes sous **« Mes vaeistes »**, sur son profil.

**Ne jamais attribuer ce rôle au niveau du site.** Il atteindrait alors le contexte de tous les utilisateurs. Le plugin s'en défend : `viewassigned` détenue au niveau du site **n'ouvre aucun dossier** (`access::follows()`), et `readdeposit`, `draftview` et `identityview` détenues sans `viewany` ni suivi n'ouvrent rien non plus : on ne lit un dépôt ou une version que d'un dossier que l'on peut consulter. Lire tous les dossiers a sa propre capability, `viewany`, réservée à la direction.

### Qui est prévenu, et de quoi

Douze messages, par la messagerie de Moodle — notification et courriel par défaut ; chacun les règle dans ses *Préférences de notification*. Tous partent de l'utilisateur « sans réponse » du site : aucun ne part au nom du vaeiste, dont l'adresse de courriel ne voyage donc jamais dans les en-têtes, quelle que soit la configuration du site (domaines de courriel autorisés) :

- au **responsable** qui suit le vaeiste et peut attester : une déclaration attend son attestation — à la déclaration, et de nouveau quand un responsable ou l'administrateur retire une attestation et que la déclaration attend à nouveau (l'effacement des données d'un signataire, à sa demande ou par l'approbation de la demande que Moodle crée à la suppression de son compte, a ses propres messages, plus bas dans cette liste) ;
- au **vaeiste** : une de ses déclarations a été attestée ; une attestation a été retirée, avec le nom des responsables qui peuvent attester de nouveau ; une compétence de son dossier ne figure plus dans sa fiche ;
- au **vaeiste**, quand l'effacement des données d'un responsable (à sa demande, ou par l'approbation de la demande que Moodle crée à la suppression de son compte), ou la suppression du compte d'une personne que nomme la signature d'une attestation reprise d'une archive, retire des attestations de ses déclarations : un seul message, qui ne dit ni de qui sont les données ni pourquoi (le dossier dit la date et le motif) ;
- aux **responsables** qui suivent ce vaeiste et peuvent attester, dans le même cas : des déclarations attendent de nouveau une attestation, un seul message par vaeiste, sans le nom de la personne dont les données ont été effacées ; ces deux messages prennent les types de message qui existent déjà (« Attestations retirées de votre dossier », « Déclarations en attente de votre attestation »), donc les mêmes préférences de notification ;
- au **signataire** : un collègue lui demande de retirer son attestation, et lequel ; ou la déclaration qu'il avait attestée a été détruite, parce que le vaeiste a supprimé la preuve d'acquis qui la portait dans l'écran de Moodle (« Plans de formation », « Preuves d'acquis »), qui ne sait rien du plugin (le nom du vaeiste et la référence, rien du récit) ;
- au **responsable qui a demandé** : le signataire a refusé ;
- au **responsable** qui suit le vaeiste et peut attester, **si l'administrateur a fixé un délai** (réglage « Rappeler aux responsables après ce nombre de jours », désactivé par défaut) : des déclarations attendent une attestation depuis ce nombre de jours ou plus — un message par vaeiste, au franchissement du délai, sans rappel répété ;
- au **responsable** qui suit le vaeiste et peut lire les dépôts : un dépôt a été fait (sans son contenu) ;
- au **responsable** qui suit le vaeiste et peut attester : une pièce justificative a été jointe à une déclaration qui porte déjà une signature, et que cette signature ne couvre pas (le nom du vaeiste et la référence, rien de la pièce) ;
- au **vaeiste** : un responsable a écrit un avis sur un de ses dépôts ; un responsable lui demande une précision sur une déclaration (le message ne dit ni qui ni quoi : la question se lit et la réponse se donne dans le dossier).

**Aucun message ne reproduit le récit** — ni situation, ni cadre, ni période, ni énoncé. Le nom du vaeiste (pour son responsable), le nom d'un responsable (dans les deux messages qui le disent : celui qui demande, ceux qui reprennent), la référence de la compétence, une phrase fixe qui dit où agir et un lien vers le dossier : c'est tout. Le rôle « Responsable VAE » ci-dessus suffit, il n'y a rien d'autre à configurer. La direction, qui détient `viewany`, n'est pas prévenue : lire tous les dossiers n'est pas recevoir chaque déclaration.

**Chaque message se termine par une phrase fixe qui dit où agir**, juste au-dessus du lien « Ouvrir le dossier » : par exemple, pour une déclaration en attente, « Ce message est automatique. Pour attester la déclaration ou poser une question, ouvrez le dossier avec le lien ci-dessous. » Les verbes suivent ce que le destinataire peut faire depuis le dossier : attester ou poser une question (déclaration en attente, rappel, déclarations qui attendent de nouveau), accepter ou refuser (demande de retrait), lire (dépôt, avis, pièce, attestation, refus, retrait, déclaration détruite, compétence sortie de sa fiche), lire la question et y répondre (précision). **Le nom du vaeiste n'est jamais dans l'objet** du message. L'expéditeur reste l'adresse « ne pas répondre » du site, et aucun message ne demande de répondre au courriel : l'action se fait dans le dossier, avec le lien.

Les messages partent **par le cron** de Moodle, qui doit donc tourner : quelques minutes après l'acte en temps normal. Le départ d'une compétence attend **un jour** et n'est annoncé que s'il tient toujours — un fichier d'import tronqué, corrigé dans la journée, ne prévient personne. Les deux messages d'un effacement attendent environ **quinze minutes** après le dernier effacement qui concerne le vaeiste, pour qu'une série d'approbations n'en produise qu'un par destinataire.

Les notifications sont stockées, conservées et effacées par la messagerie de Moodle, selon sa propre échéance. Celle qui nomme un demandeur ou un vaeiste y reste donc après l'effacement de celui-ci : le plugin le déclare, il ne peut pas l'empêcher.

### Données personnelles

L'API de confidentialité de Moodle est implémentée pour les dix-huit tables du plugin qui portent des données personnelles (dont `tool_vae_aicall`, qui et quand, sans texte) ; deux autres, `tool_vae_obsolete` (identifiants de compétence et de source, date) et `tool_vae_ref` (adresses publiques des règles de validation), sont déclarées exemptées. Deux personnes figurent dans chaque attestation, et leurs droits vont en sens contraire :

- **effacer le vaeiste** supprime son dossier, qui est le sien, avec ses sauvegardes et ses copies modifiables (voir ci-dessous) ;
- **effacer un responsable** ne supprime **pas** le dossier : son identité est retirée de l'attestation (identifiant, fonction, énoncé, date), qui est marquée retirée et datée, pour que le vaeiste garde une preuve qui dit « attesté, puis retiré ». Le vaeiste en est prévenu, et les responsables qui le suivent aussi (voir « Qui est prévenu, et de quoi ») ;
- **effacer un responsable qui avait demandé un retrait** retire sa demande ; l'attestation, qui n'est pas la sienne, reste ;
- **supprimer le compte** d'un responsable n'anonymise pas ses attestations sur le moment, mais Moodle, laissé à ses réglages, crée alors lui-même une demande de suppression de données au nom du compte (`automaticdeletionrequests` = 1), laissée en attente d'approbation (`automaticdatadeletionapproval` = 0) : l'approbation par le délégué à la protection des données anonymise toutes les attestations du compte (motif affiché « Demande de suppression de données ») et envoie les deux messages d'effacement décrits plus haut, au vaeiste et aux autres responsables qui le suivent. Les attestations qu'une archive reprise attribue à ce compte (par son identifiant opaque) sont, elles, retirées dès la suppression du compte, sans demande à approuver, avec les mêmes messages. Le site choisit — suspendre plutôt que supprimer, laisser la demande sans suite, désactiver la création automatique, ou accepter — et le délégué doit savoir ce que son approbation emporte ([T-84](docs/T-84-conformite-rgpd.md), § 3) ; une demande de retrait adressée à un signataire dont le compte est supprimé tombe, la signature restant ;
- l'export d'un demandeur contient sa demande (quelle déclaration, quelle fiche, quand), et rien du récit du vaeiste ni du signataire ; celui d'un signataire contient ses attestations et la référence des déclarations signées, jamais le récit ni le cadre du vaeiste ; celui d'un responsable qui a posé une question contient sa question, la date et l'empreinte de la réponse, jamais son texte ;
- dans leur propre export, le vaeiste et le signataire trouvent qu'un retrait a été demandé et quand, jamais par qui (le signataire le sait par la notification, qui nomme le demandeur).

**Conformité (RGPD) : à la charge du site qui installe le plugin.** Le responsable de traitement est le site ; l'auteur du plugin ne reçoit aucune donnée. Trois documents, fournis en projet à valider par le délégué à la protection des données du site : [`docs/T-84-conformite-rgpd.md`](docs/T-84-conformite-rgpd.md) (bases légales possibles par traitement, durées de conservation, droits des personnes dont la portabilité avant suppression du compte, fiche pour le registre, contrat du fournisseur d'IA), [`docs/MODELE-CONTRAT-USAGE-PLATEFORME.md`](docs/MODELE-CONTRAT-USAGE-PLATEFORME.md) (modèle de contrat d'usage de la plateforme, base légale de tous les traitements) et [`docs/AIPD-VAE.md`](docs/AIPD-VAE.md) (analyse d'impact : hébergement interne ou externe, avec ou sans certification HDS, et quatre modes de fournisseur d'IA). **Pour que le vaeiste puisse emporter son dossier avant la suppression de son compte, le site doit lui donner le droit `tool/vae:export`** (aucun rôle ne l'a par défaut). **L'archive n'emporte ni les dépôts du dossier de faisabilité, ni les avis des responsables, ni les questions de précision et leurs réponses** : le vaeiste les obtient par l'export de ses données personnelles de Moodle, qui se lit mais ne se réimporte pas. Pour qu'il puisse le demander lui-même, l'administrateur coche « Contacter le délégué à la protection des données » (réglage `contactdataprotectionofficer` de `tool_dataprivacy`, désactivé par défaut) et prévoit qui approuve les demandes d'export (le délégué, ou la case « Approbation automatique des demandes d'exportation de données », désactivée par défaut) ; sans cela, l'administrateur crée la demande à sa place (*Demandes de données → Nouvelle demande*).

---

## Où en est le développement

| Lot | Contenu | État |
|---|---|---|
| **0** | Squelette, capabilities, garde sur le sous-système, passerelle | **fait** |
| ~~1~~ | *Passerelle* — fusionnée dans le lot 0 : la garde sur le sous-système lui appartient | — |
| **2** | `db/install.xml` (6 tables au lot 2, 7 aujourd'hui), `identity_map` (ULID), **Privacy API** | **fait** |
| **3** | Déclaration — critères 1, 2, 3 et atomicité | **fait** |
| **4** | Attestation et retrait — critère 1 bis | **fait** (0.4.0) |
| **5** | Observateur de suppression — critère 8 ; puis raccordement (0.5.1) et ancre (0.6.0) | **fait** (0.5.0) |
| **6** | Import CSV — critère 5 | **fait** (0.6.5) |
| 7 | Import par numéro, table `passage`, tâche de détection des orphelins — changement de version d'une fiche sans perdre les dossiers (T-30) | **en cours** — lots 1 et 2 faits (0.24.0, 0.25.0) : l'administrateur déclare quelle fiche en remplace une autre, voit ce qui tient encore l'ancienne, et décide les correspondances compétence par compétence ; rien n'est déplacé. Lot 3 fait (0.26.0) : le vaeiste reporte lui-même une déclaration sur la nouvelle fiche, par copie, sur la correspondance décidée ; elle redevient « à attester » et l'ancienne garde son attestation. La filiation voyage dans l'archive d'export (0.27.0) |
| **7 bis** | **Rattacher un responsable à ses vaeistes** (R1) — capability `viewassigned`, page « Mes vaeistes », rattachement par cohorte via `tool_cohortroles` | **fait** (0.7.0) |
| **7 ter** | **Prévenir les bonnes personnes** (R2) — quatre notifications, aucun extrait de récit ; voir « Qui est prévenu, et de quoi » | **fait** (0.7.1) |
| **8** | Export normatif et réimport — critère 6 ; porte aussi la version figée du dossier (R4) | **fait** (0.8.0, 0.8.1) — schéma pivot reconstruit, export, réimport avec vérification des empreintes ; critère 6 redéfini le 12/10/2026 |
| **8 bis** | Sauvegardes sur Moodle, fiches rattachées aux cours | **fait** (0.8.2) |
| **8 ter à 8 quinquies** | Revues complètes et corrections : sécurité, registre des archives, un seul refus d'attester | **fait** (0.8.3 à 0.8.5, 0.10.1) |
| **9** | Restitution, accessibilité — critères 4, 7 ; couverture par compétence, sans seuil (R3) | **en cours** — couverture (R3) faite (0.9.0) : tableau sur le dossier, visible du seul vaeiste ; version imprimable (R4) faite (0.10.0) : `dossier.pdf` dans chaque archive, sous empreinte, sans couverture ; restitution et passe d'accessibilité à faire |
| **10** | Vérification RGPD de bout en bout — critère 9 | **en cours** — déclaration faite au lot 2 ; demande de retrait couverte (0.12.2), relecture contradictoire faite ; reste le parcours complet d'une demande d'accès sur un vrai site |
| 11 | Documentation, `VERSIONS.md` | **en cours** — glossaire et [FAQ par rôle](docs/FAQ.md) faits (0.22.0) ; `VERSIONS.md` à faire |
| — | **Nom** : composant `tool_vae` (0.11.0), nom affiché « VAE - cheminement » (0.11.1) | **fait** |
| — | **Premier essai sur un vrai Moodle 5.3** : pied de page du PDF (0.11.2), récit en texte brut (0.11.3) | **fait** |
| — | **Retrait délégué** : réglage, `withdrawany`, demande au signataire, vaeiste et responsables prévenus (0.12.0) | **fait** |
| — | **Lisibilité** : une déclaration d'un seul tenant dans le PDF, intitulé de la compétence sur la page (0.12.1) | **fait** |
| — | **RGPD de la demande de retrait** (0.12.2) | **fait** |
| — | **Brouillons et versions** des rubriques (T-81, 0.14.0), **copie Word et ODT** (T-82, T-83, 0.15.0) | **fait** |
| — | **Dossier de faisabilité** : rubriques, identité et statut, export, engagement (T-10, 0.15.1 à 0.20.0) | **fait** |
| — | **Dépôt** (T-21, 0.19.0), **avis du responsable** (0.21.0), **précision avant attestation** (T-20, 0.22.0) | **fait** |
| — | **Pièces justificatives** (T-13) : table, fichiers et liens, antivirus, retrait (0.37.0) ; lecture par le responsable, liste signée, message (0.38.0) ; archive, sauvegarde, import, PDF et copies (0.39.0) | **fait**, fermé par défaut |
| — | **Import des fiches depuis France compétences** (T-31) : un écran, deux voies (dépôt du fichier zip, ou récupération par le serveur), aperçu, confirmation, refus par fiche, deux événements, MC-30 (0.47.0) | **écrit** ; Behat vert en local pour le dépôt, jamais cliqué par une personne, voie « Aller chercher » jamais jouée contre le vrai data.gouv.fr |

Le blueprint complet est le **PRP**, hors dépôt. **Il est introuvable au 11 octobre 2026** — ni sur claude.ai, ni sur GitHub, ni sur le disque de travail —, pas plus que `DECISIONS.md` et `SOCLE-JLM.md` : les critères 1 à 10 ne sont définis nulle part dans ce dépôt. Le schéma pivot `preuve.schema.json`, perdu lui aussi, a été **reconstruit à partir du code** dans `docs/schema/`, champ par champ, avec la source de chaque règle (voir son `LISEZMOI.md`). Si l'original revient, il prime.

L'ancien plugin `mod_referentiel` (Jean Fruitet, 2016), dont un portage vers 4.5 avait été commencé, est **archivé** sur la branche `archive/portage-mod_referentiel` de ce dépôt. Ce qui méritait d'en être repris est décrit, sous forme d'idées, dans `docs/INVENTAIRE-mod_referentiel.md`.

**Le lot 8 n'est pas reportable** : l'export normatif est la contrepartie de la dérogation `DER-referentiel-001`. Un plugin livré sans export est un plugin livré hors dérogation.

---

## Glossaire

| Mot | Ce qu'il veut dire ici |
|---|---|
| **Déclaration** | Ce que le vaeiste dit avoir fait dans son activité, rattaché à une compétence d'une fiche de certification. Elle se rédige, puis un responsable l'**atteste**. |
| **Attestation** | L'acte d'un responsable qui, ayant relu le récit, confirme la déclaration. Elle se **retire**, elle ne se modifie pas ; une nouvelle attestation est un nouvel acte. |
| **Fiche de certification** | Le référentiel de compétences d'un diplôme ou d'un titre (RNCP, RS), importé depuis l'export de France compétences (déposé ou récupéré), ou par CSV. |
| **Dossier de faisabilité** | Ex-« livret 1 » : la demande de recevabilité. Le plugin aide à le rédiger ; il ne dit jamais s'il est recevable. |
| **Dossier de validation** | Ex-« livret 2 » : le dossier de preuves. Pas encore dans le plugin (T-11). |
| **Rubrique** | Une partie du dossier de faisabilité (objectifs, parcours, expériences…). De texte, ou à champs (identité, statut). |
| **Brouillon / version** | Le brouillon est enregistré tout seul et n'est pas une version ; une version est enregistrée à la demande, se compare et se restaure. |
| **Dépôt** | Un jalon daté et empreinté du dossier de faisabilité tel qu'enregistré. Le vaeiste continue d'écrire ; un nouveau dépôt est un nouveau jalon. |
| **Avis** | Texte libre d'un responsable sur un dépôt précis ; jamais réécrit ; le plugin ne dit pas s'il est favorable. |
| **Précision** | Question d'un responsable sur une déclaration non attestée, et réponse du vaeiste ; ni verrou ni verdict. |
| **Pièce justificative** | Un fichier ou un lien joint à une déclaration, avec une nature d'une liste fermée. Le plugin ne la lit pas ni ne la juge ; il garde l'empreinte d'un fichier et la liste de celles qu'une signature couvrait. |
| **Empreinte** | Ici, toujours le **SHA-256** d'un contenu : elle prouve qu'un texte ou une archive n'a pas changé. (Chez Fruitet, dans `mod_referentiel`, le mot désignait un nombre de validations : autre chose.) |
| **Archive** | L'export figé du dossier, avec son manifeste d'empreintes et un PDF imprimable, pour le jury ; vérifiée par ses empreintes ; reprise possible par un autre site seulement s'il n'a pas déjà numéroté autrement les compétences qu'elle nomme et si le compte du vaeiste n'y a pas déjà d'identifiant, et refusée à l'import si elle porte la mention d'une ligne hors format ; sans les dépôts du dossier de faisabilité, les avis des responsables ni les questions de précision. |
| **Suivre** | Détenir `viewassigned` dans le contexte utilisateur du vaeiste, directement ou par cohorte. C'est la seule façon de lire le dossier d'un autre sans `viewany`. |

---

## Limites connues

Ce qui n'est **pas** réglé à ce jour — plusieurs points viennent de l'essai du 13 octobre ; la liste de travail ([docs/TRAVAIL.md](docs/TRAVAIL.md)) les suit un à un :

- **Sélecteur de dates** : trois listes déroulantes (jour, mois, année), de cinquante ans en arrière jusqu'à l'année en cours ; un sélecteur mois-année n'est pas fait (T-55).
- **Pièces justificatives** (T-13) : faites (0.37.0 à 0.39.0), fermées par défaut ; reste à valider l'amendement 4 du DER et à écrire la durée de conservation (T-84) avant de les ouvrir. Périmètre décidé le 14 octobre 2026 (D-1 à D-4 de la liste de travail) : aider à rédiger sans jamais juger, la décision du jury hors du plugin.
- **Archive d'un autre site** : refusée en entier si le site d'accueil a déjà numéroté autrement une compétence qu'elle nomme, ou si le compte du vaeiste y a déjà un identifiant (voir « Archive venue d'un autre site ») ; l'écran de correspondance validée par une personne et l'archive complète pour soi sont décidés, pas construits.
- **Paquet 4.5** : à la 0.9.6 ; il n'est plus maintenu (décision du 27 octobre 2026) et ne doit pas être installé sur un site réel ; il n'a de toute façon ni PDF, ni tableau de couverture, ni retrait délégué.

---

## Architecture — la règle qui prime

Ce plugin déroge au modèle noyau/pont du socle JLM : tout vit dans Moodle, sous GPLv3. Écart documenté par **`docs/derogations/DER-referentiel-001-modele-natif.md`**.

La contrepartie est **MC-5** : `classes/local/competency_gateway.php` est le **seul** fichier du plugin autorisé à nommer le sous-système de compétences. C'est le rôle d'amortisseur du pont, transposé à l'intérieur du modèle natif — quand Moodle change, un seul fichier est repris.

```bash
make mc5       # échoue si un autre fichier appelle le cœur
make privacy   # échoue si une table n'est ni déclarée au provider RGPD, ni exemptée
```

---

## Régime du projet

**Régime 2 — Production.** Des personnes réelles s'en serviront, et des données personnelles réelles y seront saisies : celles du vaeiste et celles du responsable qui atteste. Il n'a pas encore servi à de vrais vaeistes.

Le projet avait été inscrit en régime 3 au motif que les descriptions de situations de travail porteraient des données de santé de tiers. **Ce n'est pas le cas** : elles décrivent l'activité du professionnel, jamais une personne accompagnée. Trois contrôles y concourent — aucun champ nominatif proposé, rappel d'anonymisation non masquable sur les trois champs libres, relecture par le responsable à l'attestation. Le rappel invite à désigner, au besoin, une personne accompagnée par un prénom fictif ; le plugin ne peut pas savoir si un prénom est fictif, le contrôle reste donc la relecture du responsable.

**Bascule en régime 3 avant la première distribution du plugin.**

---

## Compatibilité — LTS uniquement

**Ce plugin ne fonctionne que sur les versions LTS de Moodle, et sur une seule à la fois par paquet.**

Ce n'est pas une prudence de développeur, c'est une conséquence de ce que le plugin porte. Un dossier de VAE se construit sur des mois et se lit devant un jury des années plus tard : la plateforme qui le détient doit encore recevoir des correctifs de sécurité ce jour-là. Une LTS en apporte trois ans, une version intermédiaire dix-huit mois — moins que ce qu'il faut pour conduire un parcours puis attendre son jury.

| Version | Statut | Support de sécurité jusqu'au | Branche de ce dépôt |
|---|---|---|---|
| Moodle 4.5 LTS | **paquet non maintenu**, à ne pas installer sur un site réel | 4 octobre 2027 (support de Moodle lui-même) | `MOODLE_405`, consultable |
| Moodle 5.0, 5.1, 5.2 | **jamais** — versions intermédiaires | — | aucune |
| Moodle 5.3 LTS | **prise en charge** | 1er octobre 2029 | `main`, suivie par `MOODLE_503` |

**Les deux paquets ont vécu côte à côte depuis le 12 octobre 2026 ; le paquet 4.5 n'est plus maintenu depuis la décision du 27 octobre 2026** : plus aucun correctif n'est promis jusqu'au 4 octobre 2027, fin du support de Moodle 4.5, et il ne faut pas l'installer sur un site réel. La branche `MOODLE_405` reste consultable, et la tâche `migration` s'en sert encore : le passage de Moodle 4.5 à Moodle 5.3 est prouvé à chaque poussée sur `main` et `MOODLE_503` (et sur toute branche qui en est issue), sur PostgreSQL seulement ; une poussée sur `MOODLE_405` ne lance que les tests 4.5 : voir [docs/BRANCHES.md](docs/BRANCHES.md).

**La règle est déclarée, puis verrouillée.** `version.php` déclare `$plugin->supported = [503, 503]` sur le paquet 5.3 et `[405, 405]` sur le paquet 4.5. Dans Moodle, `supported` n'est qu'une déclaration : hors de cette plage, la page des plugins affiche un avertissement, et l'installation reste possible. Ce qui refuse vraiment, c'est `requires` (version minimale : 5.3.0 sur le paquet 5.3, 4.5.0 sur le paquet 4.5) et `$plugin->incompatible` (première branche refusée). Chaque paquet déclare les deux : **le paquet 5.3 refuse Moodle 5.4 et au-delà (`incompatible = 504`), le paquet 4.5 refuse Moodle 5.0 et au-delà (`incompatible = 500`).** Un administrateur qui met à jour le cœur vers une version intermédiaire trouve le plugin bloqué, au lieu de le voir tourner là où personne ne l'a testé. À chaque nouvelle LTS testée, le paquet correspondant relève ce nombre (décidé le 13 octobre 2026).

**Aucun des deux paquets n'élargit sa plage pour absorber l'autre** : annoncer une compatibilité que personne ne teste est pire que ne rien annoncer, et l'intégration continue ne construit que la branche sur laquelle elle tourne — plus, ici, le passage de 4.5 à 5.3.

---

## Licence

**GPLv3 ou ultérieure** — voir `LICENSE`. C'est la licence de Moodle lui-même : le plugin est donc compatible GPL. **Titulaire des droits : Jean-Louis Martinez, jeanlouis@martinez.pro** (le nom et l'adresse portés par l'en-tête de chacun des fichiers PHP, confirmés par lui le 28 octobre 2026). Décision du porteur du 28 octobre 2026 : le plugin reste sous cette licence.

**Cette décision ne lève pas PO-2**, parce que PO-2 ne porte pas sur la licence du plugin mais sur celle des **données** importées (voir ci-dessous).

Le référentiel importé est une **donnée** : fiches du RNCP et du Répertoire spécifique, produites par **France compétences**, publiées sous **Licence Ouverte 2.0**. **Elle n'est jamais livrée avec le code** : l'administrateur la dépose (le fichier zip de l'export) ou la fait récupérer à la demande par le serveur (sur clic, depuis data.gouv.fr), et elle vit dans la base du site, pas dans l'arbre du plugin. Paternité et date de mise à jour affichées partout où le référentiel est présenté ; aucun intitulé de compétence n'est reformulé.

> **Point non levé.** La Licence Ouverte 2.0 ne déclare de compatibilité qu'avec la LO antérieure, l'Open Government Licence britannique, CC-BY et ODC-BY — jamais avec une licence copyleft. À faire trancher par un conseil **avant toute diffusion publique** (`docs/MEMO-JURIDIQUE.md`, à produire). La voie « Aller chercher le dernier export » ne met aucune donnée dans le dépôt ni dans la distribution, et **ne tranche pas ce point** (point ouvert PO-2).

---

## Conventions

- **Nom du dépôt : `moodle-tool_vae`**, selon la convention Moodle `moodle-{type}_{nom}`. Renommé le 13 octobre 2026 ; l'ancien nom, `moodle-tool_referentiel`, redirige vers le nouveau.
- Code, commentaires et noms **en anglais** dans le plugin ; documentation et échanges en français. Exceptions voulues : le format d'archive et son schéma pivot (clés et fichiers en français), et les scripts de contrôle de `scripts/`.
- Aucune chaîne visible en dur : fichiers de langue, sans exception.
- En-tête de licence GPLv3 dans chaque fichier PHP du plugin ; les quatre scripts de `scripts/` (contrôles de langue et de confidentialité, jeu d'essai de migration) n'en portent pas encore.
- Commenter le **pourquoi**, pas le **ce que**.

---

*Fil d'Argent — Jean-Louis Martinez, jeanlouis@martinez.pro*
