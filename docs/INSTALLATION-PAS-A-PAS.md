# Installer et mettre en service le plugin « VAE - cheminement » — pas à pas, pour débutants

> Version du plugin décrite : **2.5.03 (Moodle 5.3), build 2026110405** (plugin **moodle-tool_vae**, plateforme lavae.org ; à ne pas confondre avec `tool_vae`, release 01.5.3, plateforme fildargent.net) (rédigé le 25 octobre 2026, mis à jour le 27 octobre 2026 puis le 5 novembre 2026). Ce guide suppose que **Moodle 5.3 est déjà
> installé et que vous avez un compte administrateur**. Il ne vous demande aucune connaissance de programmation. Comptez **deux à
> trois heures** pour tout faire une première fois, répétition générale comprise.
>
> **Comment lire ce guide.** Chaque étape dit *quoi faire*, *où cliquer*, et *ce que vous devez voir ensuite* (« Résultat
> attendu »). Si le résultat n'est pas celui-là, **arrêtez-vous** et allez à la partie 14 « Si quelque chose ne marche pas ».
> Les adresses de pages (par exemple `/admin/roles/manage.php`) sont données pour gagner du temps : ajoutez-les à l'adresse de
> votre site Moodle (par exemple `https://formation.mon-site.fr/admin/roles/manage.php`). Les intitulés de menus peuvent varier
> légèrement selon la langue et la version ; les adresses, elles, ne varient pas.

> **Ce qui a été vérifié, et ce qui ne l'a pas été.** Le 25 octobre 2026, nous avons rejoué les parties 6, 7 et 12 sur l'instance
> d'essai du plugin (Moodle 5.3, anglais) avec trois comptes jetables, supprimés ensuite : les trois rôles construits avec **les
> listes de droits de ce guide**, un responsable rattaché par une cohorte, puis une déclaration, une attestation et un export
> (archive de 7 éléments, PDF lisible). **Cela a fonctionné.** Les adresses de pages sont celles de Moodle 5.3. **Non vérifié :**
> l'installation par ZIP dans l'interface (la copie de dossier est vérifiée par l'intégration continue du plugin), les menus
> en français (les libellés viennent des fichiers de langue), la remise des notifications (elle dépend du cron, qui ne tourne pas
> sur l'instance d'essai), ClamAV, et un vrai fournisseur d'IA. **Le message qui informe un vaeiste d'une ligne de positionnement**
> (partie 10, ajouté le 5 novembre 2026) est contrôlé par les tests automatiques du plugin (PHPUnit), **non par un vrai serveur de courriel** ; ni
> lui, ni les rappels « ne nommez aucune personne accompagnée » des formulaires d'évaluation et de certification n'ont été rejoués à la main pour ce guide. Les passages ajoutés ensuite (fiche cachée en 8.4 et 12.2,
> bouton « Retirer cette ligne » en 8.4, avertissement d'export en 12.5, condition sur les demandes de suppression de données en
> partie 13) décrivent ce que disent le code et les textes du plugin : nous ne les avons pas rejoués à la main pour ce guide.
>
> **La partie 8 (import des fiches depuis France compétences, version 01.5.3) décrit un écran nouveau, « non cliqué » :** aucune personne ne l'a
> encore joué à l'écran, et elle garde cette marque tant que ce n'est pas fait. Son texte vient du code, des fichiers de langue et des tests du
> plugin (des scénarios de test joués en local sur des exports inventés ont déroulé le dépôt du fichier zip). **Non vérifié :** le clic
> « Aller chercher le dernier export » contre le vrai data.gouv.fr avec ce code ; l'export du 27 octobre 2026 passé par la page ;
> l'hébergement réel (sortie du serveur vers Internet, limite d'envoi de fichiers, mémoire) ; la lecture de l'écran au clavier et au lecteur d'écran.

---

## Partie 0 — Les mots à connaître (cinq minutes)

| Mot | Ce que cela veut dire ici |
|---|---|
| **Moodle** | Le logiciel de votre site. Vous l'administrez dans le menu **Administration du site**. |
| **Plugin** | Un module qu'on ajoute à Moodle. Celui-ci s'appelle « VAE - cheminement » (nom technique `tool_vae`). |
| **Administrateur** | La personne qui peut tout régler dans Moodle. C'est vous pour ce guide. |
| **Vaeiste** | Une personne qui prépare sa VAE (validation des acquis de l'expérience). Dans Moodle, son rôle est calé sur le modèle de rôle « Étudiant » (partie 6). |
| **Responsable** | Un accompagnant ou un référent qui suit des vaeistes et peut **attester** ce qu'ils déclarent. |
| **Direction** | Une personne qui lit tous les dossiers sans être l'administrateur du site. |
| **Rôle** | Un ensemble de droits qu'on donne à des personnes. Il faut créer trois rôles pour ce plugin. |
| **Capability** (« droit ») | Un droit précis, par exemple « déclarer une activité ». Les droits de ce plugin commencent tous par `tool/vae:`. |
| **Cohorte** | Une liste de personnes (ici : les vaeistes d'un établissement). Elle sert à dire quel responsable suit quels vaeistes. |
| **Fiche de certification** | La description officielle d'un diplôme (par exemple le DEAS) avec ses blocs de compétences, publiée par France compétences. |
| **Cron** | La tâche que Moodle lance chaque minute pour faire le travail en arrière-plan (messages, rappels, copies). |

**À retenir avant de commencer :**
- Le plugin **ne note personne et ne dit jamais qu'un dossier est recevable**. Il aide à préparer ; le jury décide.
- **Tout ce qui est délicat est fermé par défaut** (pièces jointes, aide d'IA, journal de bord…). Vous ouvrirez ces fonctions
  plus tard, une par une, quand vous aurez fait les vérifications indiquées en partie 13. **Vous pouvez utiliser le plugin sans
  les ouvrir.**
- Aucun droit n'est donné par défaut, sauf un : le rattachement d'une fiche à un cours (`tool/vae:linkcourse`) est donné au rôle
  Gestionnaire de Moodle. **Tant que vous n'avez pas créé et attribué les rôles de la partie 6, personne d'autre que l'administrateur
  du site et les gestionnaires ne verra quoi que ce soit.** C'est voulu : c'est un plugin qui manipule des données personnelles.

---

## Partie 1 — Ce qu'il faut avoir avant de commencer

Cochez chaque ligne.

- [ ] **Moodle 5.3** installé. Pour le vérifier : connectez-vous, ouvrez **Administration du site → Notifications**
  (`/admin/index.php`). La version s'affiche en bas de page. *Le plugin refuse de s'installer sur Moodle 5.4 ou plus récent, et sur
  une version antérieure à 5.3.0.* **Si vous avez Moodle 4.5**, n'installez pas le paquet de la branche `MOODLE_405` : il n'est
  plus maintenu, il porte des défauts connus (environ 28 des 55 corrigés par l'audit du 26 octobre 2026, dont 5 de sécurité) et il ne
  faut pas l'installer sur un site réel. Passez d'abord Moodle en 5.3 (marche à suivre dans `docs/BRANCHES.md` ; ce passage est
  vérifié par l'intégration continue du plugin sur PostgreSQL, pas sur MariaDB), puis suivez ce guide, qui ne décrit pas le paquet 4.5.
- [ ] **PHP 8.3** sur le serveur. Pour le vérifier : **Administration du site → Serveur → Environnement**
  (`/admin/environment.php`) ; chaque ligne doit être « OK ».
- [ ] Un **compte administrateur** de votre Moodle (le vôtre).
- [ ] **Une sauvegarde récente** de la base de données **et** du dossier `moodledata` (voir ci-dessous). Ne sautez pas cette étape.
- [ ] Soit un accès à l'interface d'administration avec le droit d'installer des plugins par ZIP (méthode A), soit un accès aux
  fichiers du serveur (méthode B). Si vous ne savez pas lequel vous avez, essayez la méthode A.
- [ ] Le **fichier du plugin** (un fichier ZIP, par exemple `vae.zip`). La partie 2 explique comment l'obtenir.
- [ ] Environ **10 Mo** pour l'export des fiches de France compétences (partie 8). Qui le télécharge dépend de la voie choisie : **votre poste** si vous déposez
  le fichier vous-même (voie A), **le serveur du site** si vous cliquez « Aller chercher le dernier export » (voie B ; le serveur doit alors pouvoir joindre
  `www.data.gouv.fr` et `static.data.gouv.fr` en https).

### Faire la sauvegarde (obligatoire)

Si votre site est hébergé chez un prestataire, **demandez-lui une sauvegarde complète** et attendez sa confirmation écrite.

Si vous gérez le serveur vous-même, par exemple avec PostgreSQL :

```bash
pg_dump -U moodle -h localhost moodle > sauvegarde-moodle-AAAA-MM-JJ.sql
tar czf sauvegarde-moodledata-AAAA-MM-JJ.tar.gz /chemin/vers/moodledata
```

ou avec MariaDB / MySQL :

```bash
mysqldump -u moodle -p moodle > sauvegarde-moodle-AAAA-MM-JJ.sql
tar czf sauvegarde-moodledata-AAAA-MM-JJ.tar.gz /chemin/vers/moodledata
```

*(Remplacez `moodle` par le nom réel de votre base et de l'utilisateur, et `/chemin/vers/moodledata` par le dossier de données de
Moodle ; ils figurent dans le fichier `config.php`.)* **Résultat attendu :** deux fichiers non vides, que vous gardez ailleurs que
sur le serveur.

---

## Partie 2 — Obtenir le fichier du plugin

Le plugin se livre sous forme d'**un dossier nommé exactement `vae`**, empaqueté dans un ZIP. **Le nom du dossier compte :** si
le ZIP contient `tool_vae` ou `vae-main` au lieu de `vae`, Moodle refuse de l'installer.

**Si on vous a remis le fichier ZIP du plugin :** gardez-le, passez à la partie 3. Vérifiez qu'en l'ouvrant vous voyez *un seul* dossier
`vae` à la racine, et dedans le fichier `version.php`.

**Si vous devez le fabriquer vous-même** (à faire sur un ordinateur où le dépôt du plugin est récupéré et où Git est installé) :

```bash
cd chemin/du/depot/moodle-tool_referentiel
git checkout <branche-ou-commit-à-installer>
git archive --format=zip --prefix=vae/ HEAD -o vae.zip
```

*(Remplacez `<branche-ou-commit-à-installer>` par la branche ou le commit que le porteur du projet vous indique ; le fichier `version.php` doit y annoncer la version `01.5.3 (Moodle 5.3)`.)*

**Résultat attendu :** un fichier `vae.zip` d'environ 4,5 Mo. Ouvrez-le : un seul dossier `vae` à la racine, avec
`version.php`, `settings.php`, `lib.php`… Le ZIP contient aussi `docs/` (les fiches de conformité) et `tests/` ; c'est normal.

---

## Partie 3 — Installer le plugin

Choisissez **une seule** méthode. La méthode A est la plus simple.

### Méthode A — par l'interface de Moodle (recommandée)

1. Connectez-vous en administrateur.
2. Ouvrez **Administration du site → Plugins → Installer des plugins** (`/admin/tool/installaddon/index.php`).
3. Dans « Installer un plugin depuis un fichier ZIP » :
   - **Type de plugin** : choisissez **Outil d'administration (tool)**. *(Si vous laissez « Détecter automatiquement », cela
     marche aussi quand le ZIP est correct.)*
   - **Fichier ZIP** : glissez le fichier ZIP du plugin dans le cadre, ou cliquez pour le choisir.
4. Cliquez **Installer le plugin depuis le fichier ZIP**.
5. Moodle affiche un récapitulatif : **VAE - cheminement**, composant `tool_vae`, version `2.5.03 (Moodle 5.3)`. Cliquez **Continuer**.
6. Moodle peut vous demander de **confirmer votre mot de passe** : faites-le.
7. Vous arrivez sur la page **Notifications** (« Vérification des extensions »). Elle liste le plugin à installer. Cliquez
   **Mettre à jour la base de données Moodle maintenant**.
8. Moodle installe les tables du plugin. Cela prend quelques secondes à une minute. À la fin, cliquez **Continuer**.

**Résultat attendu :** un message « Succès » pour `tool_vae`, puis retour à la page d'administration. Aucune ligne rouge.

*Si l'écran « Installer des plugins » n'existe pas, ou si Moodle répond que l'installation par ZIP est désactivée,* c'est que
l'administrateur technique l'a verrouillée : utilisez la méthode B.

### Méthode B — en copiant le dossier sur le serveur

À faire par la personne qui a accès aux fichiers du serveur.

1. Décompressez le fichier ZIP du plugin. Vous obtenez un dossier `vae`.
2. Copiez-le **dans le dossier `public/admin/tool/` de Moodle 5.3** pour obtenir `.../public/admin/tool/vae/version.php`.
   *(Dans Moodle 5.3, le dossier web est `public/`. Dans une version plus ancienne, ce serait `admin/tool/vae` sans `public`.)*
   Exemple sous Linux :

   ```bash
   cp -r vae /var/www/html/public/admin/tool/vae
   chown -R www-data:www-data /var/www/html/public/admin/tool/vae
   ```

   Exemple avec Docker (le conteneur s'appelant `moodle-web`) :

   ```bash
   docker cp vae moodle-web:/var/www/html/public/admin/tool/vae
   docker exec moodle-web chown -R www-data:www-data /var/www/html/public/admin/tool/vae
   ```

3. Dans le navigateur, ouvrez **Administration du site → Notifications** (`/admin/index.php`). Moodle détecte le nouveau plugin.
   Cliquez **Mettre à jour la base de données Moodle maintenant**, puis **Continuer**.

**Résultat attendu :** comme pour la méthode A. Si le serveur est derrière un cache, videz-le : **Administration du site →
Développement → Purger tous les caches** (`/admin/purgecaches.php`).

---

## Partie 4 — Activer ce dont le plugin a besoin

### 4.1 Activer les compétences (obligatoire)

Le plugin s'appuie sur le sous-système « Compétences » de Moodle. **Sans lui, il affiche une explication et s'arrête.**

1. Ouvrez **Administration du site → Fonctions avancées** (`/admin/settings.php?section=optionalsubsystems`).
2. Cochez **Activer les compétences**.
3. Cliquez **Enregistrer les modifications**.

**Résultat attendu :** après enregistrement, la case reste cochée.

### 4.2 Vérifier que le cron tourne (obligatoire)

Le cron fait fonctionner, en arrière-plan : l'envoi des messages en file d'attente (dont celui qui informe un vaeiste qu'une ligne
de positionnement a été écrite sur son parcours, partie 10), les rappels aux responsables, la purge des archives (24 heures), la copie
hebdomadaire, la mise à jour des rattachements par cohorte (au plus une heure). **Sans cron, ces fonctions ne se feront pas.**

1. Ouvrez **Administration du site → Serveur → Tâches → Tâches planifiées** (`/admin/tool/task/scheduledtasks.php`).
2. Repérez une tâche récente : la colonne « Dernière exécution » doit afficher **moins de quelques minutes**.

**Résultat attendu :** une date d'exécution récente. **Si la colonne est vide ou vieille de plusieurs heures,** le cron ne tourne
pas : il faut demander à l'hébergeur (ou ajouter une ligne dans la planification du serveur). Sur un serveur Linux géré par vous :

```bash
* * * * * www-data /usr/bin/php /var/www/html/admin/cli/cron.php > /dev/null
```

*(Dans Moodle 5.3, `admin/cli/cron.php` est à la racine de Moodle, pas dans `public/`.)*

---

## Partie 5 — Vérifier que le plugin est bien installé

1. Ouvrez **Administration du site → Compétences → VAE - cheminement** (`/admin/tool/vae/index.php`).
2. Vous devez voir le titre **VAE - cheminement**, un message bleu « **Aucune fiche de certification n'a encore été importée.** »,
   et deux boutons : **Importer des fiches depuis France compétences** (le premier, mis en avant) et **Importer un fichier CSV (cas particulier)**.

**Résultat attendu :** cette page, sans erreur. Le message bleu est normal : on importera les fiches en partie 8.

3. Ouvrez aussi **Administration du site → Compétences → VAE : réglages** (`/admin/settings.php?section=tool_vae_settings`).
   Elle liste les réglages du plugin (partie 9). **N'y changez rien pour l'instant.**

*Si vous voyez à la place « Le sous-système de compétences est désactivé… »,* retournez en 4.1.

---

## Partie 6 — Créer les rôles et donner les droits

**C'est l'étape la plus importante.** Prenez votre temps.

### 6.0 Ce qu'il faut comprendre

Le plugin n'accorde aucun droit par défaut, sauf `tool/vae:linkcourse`, donné au Gestionnaire de Moodle. On crée **trois rôles** (et, si le site le décide, deux rôles facultatifs, voir « Gagner du temps » ci-dessous) :

| Rôle | Pour qui | Où on l'attribue |
|---|---|---|
| **Vaeiste** (modèle de rôle Moodle « Étudiant ») | chaque vaeiste | **niveau du site** (à chaque vaeiste) |
| **Responsable VAE** (sans modèle) | chaque accompagnant ou référent | **à travers les cohortes** (partie 7), **jamais au niveau du site** |
| **Direction VAE** (sans modèle) | la direction | **niveau du site** |

**Le vaeiste est l'« étudiant » de cette plateforme.** Son rôle porte le **modèle de rôle « Étudiant »** de Moodle (étape 6.1, point 4) :
Moodle le range avec les étudiants — un plugin installé plus tard lui donne ce qu'il donne aux étudiants — **sans** lui donner un seul
droit d'étudiant sur le site. Les deux autres rôles n'ont pas de modèle.

**Règle d'or : ne donnez jamais le rôle « Responsable VAE » au niveau du site.** Un responsable doit ne voir que les vaeistes
qu'il suit ; le plugin refuse d'ailleurs d'ouvrir un dossier à un responsable dont le rôle est attribué au niveau du site.

**Gagner du temps : la page « Rôles VAE ».** Les rôles existent aussi en préréglages XML, dans `presets/roles/` du plugin : trois rôles de base (`role_vaeiste.xml`, `role_responsable.xml`, `role_direction.xml`) et deux rôles facultatifs (`role_responsable_evaluateur.xml` : le responsable, plus `tool/vae:evaluate`, au niveau utilisateur ; `role_direction_certificatrice.xml` : la direction, plus `tool/vae:certify`, au niveau du site, avec `tool/vae:viewany`). Pour qui peut gérer les rôles (`moodle/role:manage`), la page **Administration du site → Compétences → Rôles VAE** (`/admin/tool/vae/roles_install.php`) liste ces cinq rôles et dit lesquels existent déjà. Elle crée un rôle **seulement sur votre demande** : un écran de confirmation liste les droits qu'il recevra. Rien n'est créé en ouvrant la page, ni à l'installation du plugin ; un rôle qui existe déjà (même nom court) n'est jamais modifié ; l'événement de création est celui du cœur de Moodle. Le plugin refuse d'accorder, dans n'importe quel préréglage, les droits `import`, `importdossier`, `identityview`, `useai`, `withdrawany` et `linkcourse`, même si un fichier était modifié ; `evaluate` et `certify` ne sont dans aucun des trois rôles de base, seulement dans les deux facultatifs (à ne créer que si le site a décidé de laisser la structure écrire un positionnement, partie 10). **Le plugin n'attribue rien** : qui détient un rôle, et où (rôle de cohorte pour les responsables), se décide ensuite dans Moodle, comme décrit dans cette partie 6.

L'import à la main reste possible : à l'étape « Ajouter un nouveau rôle » de la définition des rôles, choisissez « Utiliser un préréglage », chargez le fichier de `presets/roles/`, puis vérifiez la liste des droits contre les tables ci-dessous avant de cliquer « Créer ce rôle ».

**Essayé, et non vérifié.** La page Rôles VAE a été essayée par les tests automatiques du plugin (PHPUnit, 8 tests ; Behat, scénarios de `roles_install.feature`). L'import à la main d'un préréglage a été essayé le 5 novembre 2026 sur l'instance d'essai locale (le formulaire a été lu, aucun rôle n'a été créé). **Non vérifié :** l'essai à la main de la page Rôles VAE dans l'interface d'un site réel.

Un quatrième rôle, **DPO / juriste**, est utile pour la partie 10 (politique de confidentialité). Il n'est pas propre au plugin.

### 6.1 Créer le rôle « Vaeiste »

1. Ouvrez **Administration du site → Utilisateurs → Permissions → Définition des rôles** (`/admin/roles/manage.php`).
2. Cliquez **Ajouter un nouveau rôle**.
3. À la question « **Utiliser rôle ou modèle de rôle** », choisissez **Aucun rôle** (premier choix de la liste), puis cliquez
   **Continuer**. **Ne choisissez pas « MODÈLE : Étudiant » sur cet écran** : ce choix recopie les 81 droits d'un étudiant de cours
   (dont « Voir les profils des utilisateurs » et « Voir les participants »), qui, portés par un rôle donné au niveau du site, valent sur
   tout le site — vérifié sur l'instance d'essai : un compte qui porte un tel rôle ouvre le profil de n'importe quel utilisateur. Le modèle
   se choisit à l'étape suivante, sans cette recopie.
4. Remplissez :
   - **Nom abrégé** : `vaeiste`
   - **Nom complet personnalisé** : `Vaeiste`
   - **Description personnalisée** : `Vaeiste : prépare et dépose son propre dossier.`
   - **Modèle de rôle** : choisissez **MODÈLE : Étudiant**. C'est ce qui cale le vaeiste sur le rôle de base « Étudiant » de Moodle :
     le rôle est rangé avec les étudiants (un plugin installé plus tard lui donne ce qu'il donne aux étudiants), **sans recevoir ici un
     seul droit de plus** que ceux que vous cochez au point 6. Ne cliquez jamais le bouton « Réinitialiser » de ce rôle (page du rôle, ouverte
     en cliquant son nom dans « Définition des rôles ») : cette action recopierait tous les droits du modèle.
5. Dans « **Types de contextes où ce rôle peut être attribué** », cochez **Système** (et rien d'autre).
6. Descendez à la liste des droits. Dans le champ **Filtre** au-dessus du tableau, tapez `tool/vae`. Seuls les droits du plugin
   restent affichés. Cochez la case **Autoriser** pour **ces quatre** droits (et seulement eux) :

   | Droit | Ce qu'il permet |
   |---|---|
   | `tool/vae:declare` | déclarer une activité |
   | `tool/vae:viewown` | lire son propre dossier |
   | `tool/vae:draftown` | rédiger les rubriques de son dossier de faisabilité |
   | `tool/vae:export` | exporter son propre dossier en archive (indispensable pour emporter ses déclarations et ses attestations ; les dépôts, les avis et les questions de précision se demandent par l'export des données personnelles de Moodle) |

   *(Un cinquième droit, `tool/vae:useai`, concerne l'aide d'IA : ne le cochez pas tant que vous n'avez pas ouvert cette
   fonction, partie 13.)*
7. Descendez et cliquez **Créer ce rôle**.

**Résultat attendu :** le rôle « Vaeiste » apparaît dans la liste des rôles.

### 6.2 Créer le rôle « Responsable VAE »

Mêmes étapes que 6.1, avec ces différences :

- **Nom abrégé** : `vae_responsable` ; **Nom complet personnalisé** : `Responsable VAE` ; **Description personnalisée** : `Suit des vaeistes et atteste
  leurs déclarations.`
- **Modèle de rôle** : laissez **Aucun**.
- **Types de contextes** : cochez **Utilisateur** (et **rien d'autre** : ni Système, ni Cours).
- **Droits à autoriser** (filtre `tool/vae`) :

   | Droit | Ce qu'il permet |
   |---|---|
   | `tool/vae:viewassigned` | lire le dossier des vaeistes que l'on suit |
   | `tool/vae:attest` | attester une déclaration (exige `viewassigned`) |
   | `tool/vae:readdeposit` | lire les dépôts du dossier de faisabilité d'un vaeiste suivi |
   | `tool/vae:writeopinion` | écrire un avis sur un dépôt (exige `readdeposit`) |

Cliquez **Créer ce rôle**.

**Résultat attendu :** le rôle « Responsable VAE » apparaît dans la liste.

### 6.3 Créer le rôle « Direction VAE »

Mêmes étapes que 6.1, avec ces différences :

- **Nom abrégé** : `vae_direction` ; **Nom complet personnalisé** : `Direction VAE` ; **Description personnalisée** : `Lit tous les dossiers.`
- **Modèle de rôle** : laissez **Aucun**.
- **Types de contextes** : cochez **Système**.
- **Droits à autoriser** (filtre `tool/vae`) :

   | Droit | Ce qu'il permet |
   |---|---|
   | `tool/vae:viewany` | lire tous les dossiers |
   | `tool/vae:export` | exporter le dossier de n'importe quel vaeiste |
   | `tool/vae:draftview` | lire les rubriques et les versions d'un dossier (avec `viewany`, sans quoi rien ne s'ouvre) |
   | `tool/vae:readdeposit` | lire les dépôts (avec `viewany`) |
   | `tool/vae:identityview` | lire l'identité (date de naissance, nationalité) — **à ne donner que si nécessaire** |

Cliquez **Créer ce rôle**.

Les droits **`tool/vae:import`** et **`tool/vae:importdossier`** (le premier se lit « Importer des fiches de certification (fichier CSV, ou export de France compétences déposé ou récupéré sur data.gouv.fr), et retirer la ligne d'une fiche importée par erreur » ; le second permet de réimporter des archives) restent réservés à
l'administrateur du site. **Ne les donnez pas** sans raison : `importdossier` permet de sauvegarder le dossier de n'importe quel
vaeiste.

### 6.4 Attribuer le rôle « Vaeiste » aux vaeistes

1. Ouvrez **Administration du site → Utilisateurs → Permissions → Attribuer des rôles système**
   (`/admin/roles/assign.php?contextid=1`).
2. Cliquez le rôle **Vaeiste**.
3. Dans la colonne de droite (« Utilisateurs existants »), choisissez les vaeistes (Ctrl+clic pour en choisir plusieurs),
   puis cliquez **Ajouter**.

**Résultat attendu :** les vaeistes apparaissent dans la colonne de gauche (« Utilisateurs avec ce rôle »).

### 6.5 Attribuer le rôle « Direction VAE »

Même page (`/admin/roles/assign.php?contextid=1`), cliquez **Direction VAE**, ajoutez la ou les personnes concernées.

*Le rôle « Responsable VAE » ne s'attribue pas ici : c'est la partie 7.*

---

## Partie 7 — Dire quel responsable suit quels vaeistes (cohortes)

Un responsable ne voit que les dossiers des vaeistes **qu'il suit**. Le rattachement se fait avec deux outils déjà présents dans
Moodle : les **cohortes** et la **gestion des rôles de cohorte**.

### 7.1 Créer une cohorte par établissement ou service

1. Ouvrez **Administration du site → Utilisateurs → Comptes → Cohortes** (`/cohort/index.php`).
2. Cliquez **Ajouter une cohorte** (au niveau « Système »).
3. **Nom** : par exemple `Vaeistes - EHPAD du Parc`. **Identifiant** : `ehpad-parc`. Cliquez **Enregistrer**.
4. Sur la ligne de la cohorte, cliquez l'icône **Assigner** (les deux silhouettes), puis ajoutez les vaeistes de cet
   établissement dans la cohorte.

**Résultat attendu :** la cohorte affiche le bon nombre de membres.

### 7.2 Donner à un responsable le rôle sur les membres d'une cohorte

1. Ouvrez **Administration du site → Utilisateurs → Permissions → Gestion des rôles de cohorte**
   (`/admin/tool/cohortroles/index.php`).
2. Remplissez les trois listes :
   - **Sélectionner un rôle** : `Responsable VAE` ;
   - **Sélectionner une cohorte** : la cohorte créée en 7.1 ;
   - **Sélectionner un utilisateur** : le responsable (tapez son nom).
3. Cliquez **Assigner**.

**Résultat attendu :** une ligne apparaît dans le tableau en dessous : *(responsable) — Responsable VAE — (cohorte)*.

*La synchronisation est faite par le cron, au plus tard une heure après un changement.* Un vaeiste ajouté plus tard à la cohorte
est rattaché automatiquement.

**Où le responsable retrouve ses vaeistes :** sur **son profil** (clic sur son nom en haut à droite, puis **Profil**), lien
**Mes vaeistes**.

---

## Partie 8 — Importer les fiches de certification (par exemple le DEAS)

Sans fiche, un vaeiste ne peut rien déclarer. On importe les fiches publiées par France compétences, **depuis un seul écran** de
Moodle, sans ligne de commande : **Importer des fiches depuis France compétences**. **Aucune donnée de France compétences n'est
livrée avec le plugin** : vous la faites venir à la demande (un clic, ou le dépôt d'un fichier), et elle est enregistrée dans la base de votre site.

Il y a **deux voies**, dans le même écran, chacune avec son bouton :

- **voie B — le serveur va chercher l'export** : un clic (8.2) ;
- **voie A — vous déposez vous-même le fichier zip** : vous le téléchargez sur votre poste, puis vous le déposez (8.3).

Dans les deux cas, le plugin lit l'export, **vous montre ce qu'il contient pour chaque numéro de fiche** (8.4), et **n'écrit rien avant votre
confirmation**. Il recopie ce que France compétences publie, sans le reformuler et sans le juger : cela reste l'affaire du certificateur et du jury.

*(Écran nouveau de la version 01.5.3, **non cliqué** : voir l'encadré « Ce qui a été vérifié » en tête de ce guide. Si vous ne pouvez utiliser
aucune des deux voies, une voie de secours à la main, en ligne de commande, existe : 8.5.)*

### 8.1 Choisir les fiches

Il vous faut le **numéro** de chaque fiche (`RNCP…` ou `RS…`). Les six fiches médico-sociales en vigueur (d'après l'export du 19 octobre 2026 ;
**à vérifier le jour de l'import**) :

| Diplôme | Numéro de fiche | Blocs |
|---|---|---|
| DEAS (aide-soignant) | `RNCP40692` | 5 |
| DEAES (accompagnant éducatif et social) | `RNCP42423` | 5 |
| DEES (éducateur spécialisé) | `RNCP41747` | 4 |
| DEME (moniteur-éducateur) | `RNCP39643` | 3 |
| CAFERUIS | `RNCP36836` | 4 |
| CAFDES | `RNCP36838` | 4 |

**Attention :** ces numéros changent quand une fiche est remplacée. Copiez le numéro depuis la fiche de France compétences, **pas depuis ce
tableau** : l'aperçu (8.4) montre l'intitulé publié de chaque numéro, et c'est lui qui vous dit si le numéro est le bon. *(Les six numéros du
tableau ont donné les mêmes blocs dans les exports du 19 et du 27 octobre 2026, relevés en lecture seule hors du plugin.)*

### 8.2 Importer en un clic : le serveur va chercher l'export (voie B)

1. Ouvrez **Administration du site → Compétences → VAE - cheminement** (`/admin/tool/vae/index.php`), puis le premier bouton, **Importer des fiches depuis
   France compétences** (`/admin/tool/vae/import_fiches.php`).
2. Dans le champ **Numéros des fiches**, tapez ou collez les numéros : un par ligne, ou séparés par des virgules (par exemple `RNCP40692`). Vingt au plus à la fois.
3. Dans la section **Aller chercher l'export sur data.gouv.fr**, cliquez **Aller chercher le dernier export**. La page prévient : « La lecture peut prendre une minute. Ne fermez pas la page. »

Ce qui se passe : le serveur de votre site va chercher **lui-même** le dernier export publié par France compétences, **au moment du clic et seulement
alors** : rien n'est planifié, et aucun réglage n'est à cocher. Il n'envoie aucune donnée sur personne, seulement une demande de téléchargement, à deux
adresses écrites dans le plugin (`www.data.gouv.fr` et `static.data.gouv.fr`, en https).

**Ce clic suppose que le serveur de votre site puisse joindre `www.data.gouv.fr` et `static.data.gouv.fr` en https.** Sur un hébergement qui ne laisse pas
le serveur aller sur Internet, ou derrière un pare-feu qui l'en empêche, il échoue : la page se réaffiche avec vos numéros, un message rouge (par exemple
« Le serveur de ce site n'a pas pu joindre data.gouv.fr. Rien n'a été écrit. ») et une ligne « À donner à votre hébergeur », que vous pouvez lui transmettre
pour qu'il ouvre le https sortant vers ces deux adresses. Vous pouvez aussi passer à 8.3, qui ne demande aucune sortie du serveur.

**Résultat attendu :** la page « **Ce que contient l'export** » (8.4).

### 8.3 Ou déposer le fichier zip vous-même (voie A)

À utiliser quand 8.2 échoue, ou quand votre hébergement ne laisse pas le serveur aller sur Internet.

1. Ouvrez
   <https://www.data.gouv.fr/datasets/repertoire-national-des-certifications-professionnelles-et-repertoire-specifique>.
2. Téléchargez le fichier zip **`export-fiches-csv-AAAA-MM-JJ.zip`** (environ 10 Mo), **sans le décompresser et sans le renommer** : la date de l'export est lue dans son
   nom (votre navigateur peut y ajouter « (1) », c'est toléré). Les autres fichiers de la page (`export-fiches-rncp-…`) ne conviennent pas.
3. Sur l'écran **Importer des fiches depuis France compétences**, saisissez les numéros (champ **Numéros des fiches**), puis, dans la section **Ou déposer
   le fichier zip**, choisissez le fichier dans le champ **Fichier zip de l'export** et cliquez **Lire le fichier déposé**.
4. Si le zip a été renommé et que son nom ne donne plus la date, cochez **Activer** à côté de **Date de l'export (seulement si le nom du fichier ne la
   donne plus)** et choisissez le jour de l'export.

**La limite d'envoi de fichiers.** Votre site refuse un fichier plus gros que la limite d'envoi que règle votre hébergeur. Si cette limite est inférieure à
environ 12 Mo, donc juste pour l'export (environ 10 Mo, qui grossit), l'écran l'annonce au-dessus du champ (« Ce site accepte au plus … par fichier. L'export pèse
environ 10 Mo et grossit… »). Si le dépôt échoue, utilisez plutôt 8.2, ou demandez à l'hébergeur de relever la limite d'envoi de fichiers. Un fichier trop gros n'arrive pas en entier : l'écran le dit.

**Résultat attendu :** la page « **Ce que contient l'export** » (8.4).

### 8.4 Lire l'aperçu, puis confirmer

La page « **Ce que contient l'export** » s'ouvre. **Aucune fiche n'est encore importée** (pour « Aller chercher », seules la trace de la requête dans le journal des événements et le plan temporaire existent).

1. Contrôlez la source et la date : « Export récupéré sur data.gouv.fr : … » ou « Export déposé : … », puis « Date de l'export : … ». Des encadrés jaunes signalent ce
   qui mérite un coup d'œil (export de 7 jours ou plus, dates qui ne vont pas ensemble, numéro saisi deux fois) ; ils n'empêchent rien.
2. **Lisez l'intitulé de chaque ligne du tableau.** Un numéro faux, mais qui existe, importe une autre certification sans aucun message d'erreur : l'intitulé publié,
   affiché en entier, est la seule protection. Le tableau dit aussi, pour chaque numéro : le nombre de blocs (à déplier), l'état donné par France compétences
   (active, ou indiquée inactive : une fiche inactive peut avoir des blocs et s'importe quand même), ce que Moodle détient déjà (nouvelle, déjà importée avec sa date,
   référentiel à reconstruire…) et **ce qui sera fait**.
3. Une fiche **refusée** porte son motif en toutes lettres et « Rien ne sera écrit pour cette fiche. » ; **les autres s'importent**. Le tableau de tous les messages de refus,
   avec la cause et le remède de chacun, est dans le guide d'usage V2, partie 4.11 (« En cas de refus »).
4. Si une fiche est **plus ancienne** que la version que Moodle détient déjà, l'aperçu le dit et ne l'importe que si vous cochez la case prévue en bas de page.
5. Cliquez **Importer {N} fiche(s)** (N est le nombre de fiches qui partent), ou **Annuler** pour renoncer.

**Résultat attendu :** la page « **Résultat de l'import** » : une ligne par fiche, qui dit ce qui s'est passé pour elle (compétences ajoutées, mises à jour…), ou pourquoi elle n'a pas été importée.
Cliquez **Continuer** : sur la page **VAE - cheminement**, une ligne par fiche importée. Recharger la page de résultat ne réimporte rien. Recommencez 8.2 ou 8.3 pour d'autres fiches.

*Une fiche republiée se réimporte de la même façon (le lien **Reprendre les numéros des fiches déjà importées** remplit le champ) : la date de la source vient de l'export, l'aperçu dit déjà ce que le
réimport changera, rien n'est supprimé, un bloc sorti de la fiche est seulement marqué, et les déclarations des vaeistes ne bougent pas. Si une fiche est remplacée par un
**nouveau numéro**, importez le **nouveau** numéro, puis déclarez le remplacement avec le lien proposé sur la page de résultat (**Fiches de certification remplacées**).*

*Une fiche qui n'est pas prête (en relecture, à l'essai) : à l'import, le plugin crée toujours son référentiel **visible**, donc ouvert
aux déclarations. Importez-la, puis **cachez-la aussitôt** : **Administration du site → Compétences → Référentiels de compétences**,
modifiez le référentiel, « Visible : Non ». Tant qu'il est caché, personne ne peut déclarer une activité sur cette fiche ni la
rattacher à un cours, et le vaeiste qui la cherche lit un message qui lui dit qu'elle est cachée. Pour l'ouvrir, remettez
« Visible : Oui ».*

*Une fiche importée par erreur (mauvais code, mauvais fichier) : cachez-la d'abord, puis supprimez son référentiel au même endroit si
Moodle l'accepte (possible tant qu'aucun vaeiste n'a déclaré dessus et qu'aucun cours ni plan ne s'y appuie). Revenez ensuite sur la page
**VAE - cheminement** : un bouton **Retirer cette ligne** est offert (à qui a le droit d'importer), avec un écran de confirmation qui liste
ce qui part. Il n'apparaît pas dans trois cas : un vaeiste a laissé une trace sur la fiche (déclaration, marque « Hors de mon activité », préparation du
dossier de validation) ; Moodle détient encore un référentiel portant ce code, même caché (il faut d'abord le supprimer) ; ou vous n'avez pas le droit
d'importer. Les deux premiers cas ont chacun leur phrase sur la page ; sans le droit, la page ne dit que sa phrase générale (le référentiel de la fiche
n'existe plus, importez-la de nouveau), sans bouton. Détails dans `docs/FAQ.md`.*

*Une fiche refusée à l'aperçu parce qu'elle « n'a aucun bloc de compétences dans l'export » : ce sont la plupart des fiches qui ne sont plus en vigueur, les fiches
du répertoire spécifique (RS), et quelques fiches en vigueur (236 fiches RNCP sur l'export du 19 octobre 2026), dont cet export ne détaille pas les blocs. Pour une fiche qui n'est plus en vigueur, cherchez le numéro de la
fiche en vigueur : il change quand une fiche est remplacée ; pour une fiche en vigueur, il n'y a pas de numéro plus récent. Les autres fiches de la liste s'importent. Détails dans `docs/FAQ.md`.*

### 8.5 Voie de secours : préparer un fichier à la main (ligne de commande)

*Cette voie demande une petite manipulation en ligne de commande sur un ordinateur où PHP est installé (le serveur Moodle convient). Elle sert pour une fiche préparée à la main,
ou quand ni 8.2 ni 8.3 ne marchent sur votre site : le script se joue sur un poste, sans site. Si vous n'êtes pas à l'aise, demandez à votre informaticien : c'est l'affaire d'un quart d'heure.*

1. Téléchargez le fichier zip **`export-fiches-csv-AAAA-MM-JJ.zip`** (comme en 8.3). La date du nom est celle de l'export : **notez-la**.
2. Décompressez-le dans un dossier de votre choix, **en dehors du dossier du plugin**, et repérez deux fichiers :
   - `export_fiches_CSV_Blocs_De_Compétences_AAAA_MM_JJ.csv` (les blocs)
   - `export_fiches_CSV_Standard_AAAA_MM_JJ.csv` (les intitulés)
3. Dans un terminal, placez-vous dans le dossier du plugin sur votre ordinateur, puis, par exemple pour le DEAS :

   ```bash
   php scripts/convertir-fiche-rncp.php blocs.csv standard.csv RNCP40692 deas.csv
   ```

   *(Remplacez `blocs.csv` et `standard.csv` par les noms exacts des deux fichiers de l'étape 2, avec leur chemin.)*

   **Résultat attendu :** un fichier `deas.csv` est créé, et le terminal **affiche les valeurs à recopier** à l'étape suivante :
   code de la fiche, type, intitulé, source, licence, paternité (« France compétences »). **Gardez cet affichage sous les yeux.**
   Si le script répond qu'une fiche est « refusée », c'est qu'elle n'a pas de bloc dans l'export : prenez le numéro de la fiche en vigueur.
4. Ouvrez **Administration du site → Compétences → VAE - cheminement** (`/admin/tool/vae/index.php`), puis le second bouton, **Importer un fichier CSV (cas particulier)**
   (`/admin/tool/vae/import.php`, dont le titre reste « Importer une fiche de certification »), et remplissez le formulaire avec les valeurs affichées par le script :

   | Champ | Quoi mettre |
   |---|---|
   | **Fichier CSV** | le fichier créé à l'étape 3 (`deas.csv`) |
   | **Encodage du fichier** | `UTF-8` (déjà choisi) |
   | **Code de la fiche** | `RNCP40692` |
   | **Répertoire** | « RNCP - répertoire national des certifications professionnelles » |
   | **Intitulé de la certification** | l'intitulé affiché par le script (100 caractères au plus) |
   | **Date de publication de la fiche** | **la date de l'export téléchargé** (celle du nom du fichier ZIP) |
   | **Adresse d'où vient le fichier** | l'adresse data.gouv.fr de l'étape 1 de 8.3 |
   | **Licence de la source** | `Licence Ouverte 2.0` (déjà remplie) |
   | **Paternité** | `France compétences` (déjà remplie) |

5. Cliquez **Importer**.

**Résultat attendu :** un message de confirmation, puis, sur la page **VAE - cheminement**, une ligne pour la fiche importée.
Recommencez les étapes 3 à 5 pour chaque diplôme dont vous avez besoin.

---

## Partie 9 — Les réglages du plugin

Page : **Administration du site → Compétences → VAE : réglages** (`/admin/settings.php?section=tool_vae_settings`).
Voici ce que fait chaque réglage et la valeur que nous conseillons pour **démarrer**. N'oubliez pas **Enregistrer les
modifications** en bas.

| Réglage | Conseillé pour démarrer | Ce qu'il fait |
|---|---|---|
| Les responsables retirent leurs propres attestations | décoché | Si coché, un responsable peut retirer une attestation qu'il a lui-même signée. Sinon l'administrateur la retire, ou une personne à qui il a attribué le droit « Retirer n'importe quelle attestation » (`tool/vae:withdrawany`) ; le signataire peut aussi la retirer quand on le lui a demandé. |
| Écrire une copie hebdomadaire de chaque dossier | décoché | Si coché, une copie modifiable de chaque dossier modifié depuis sa dernière copie est écrite la nuit de dimanche à lundi (un dossier inchangé n'en reçoit pas). **À n'activer qu'après avoir fixé sa durée de conservation** (fiche de conformité). |
| Format de la copie hebdomadaire | Word | Word (.docx) ou OpenDocument (.odt). |
| Rappeler aux responsables après ce nombre de jours | `0` (désactivé), ou `14` | Un rappel, **une seule fois**, quand une déclaration attend une attestation. De 1 à 365. |
| Ouvrir la préparation du dossier de validation aux vaeistes | **décoché** | Fermé : voir partie 13. |
| Ouvrir le journal de bord aux vaeistes | **décoché** | Fermé : voir partie 13. |
| Laisser les vaeistes dire qu'une compétence est hors de leur activité | **décoché** | Fermé : voir partie 13. |
| Ouvrir l'aide à la reformulation aux vaeistes | **décoché** | Fermé : voir partie 13. |
| Texte envoyé le plus long (caractères) | 3000 (défaut) | Limite de l'aide d'IA (500 à 6000). |
| Propositions par vaeiste et par jour | 25 (défaut) | Plafond de l'aide d'IA (1 à 100). |
| Montrer les adresses des règles de validation | **décoché** | Fermé : voir partie 13. |
| Ouvrir les pièces justificatives aux vaeistes | **décoché** | Fermé : voir partie 13. |
| Plus gros fichier (octets) | 5 Mo (défaut) | Taille maximale d'un fichier joint (30 Mo au plus). Le réglage du site « Taille maximale des fichiers déposés » (**Administration du site → Sécurité → Réglages de sécurité du site**) s'applique aussi quand il est plus bas : la taille annoncée sur la page est la plus petite des deux. |
| Total de fichiers d'un vaeiste (octets) | défaut | Plafond par vaeiste. |
| Accepter les fichiers sans antivirus | **décoché** | Sans antivirus configuré, les fichiers sont refusés tant que cette case est décochée. |
| Barème des référentiels importés | Automatique (défaut) | Le barème de compétence donné au référentiel de chaque fiche importée depuis France compétences. En automatique, le plugin retrouve par son nom le barème par défaut du cœur ; si votre site l'a renommé ou ne l'a jamais créé, choisissez ici un barème de tout le site. |

**Résultat attendu :** après enregistrement, la page se recharge avec vos valeurs.

---

## Partie 10 — Publier la politique de confidentialité et le contrat d'usage

Le plugin traite des données personnelles. **Avant d'ouvrir le site aux vaeistes**, faites accepter un texte qui explique ce qui
est fait de leurs données. Le modèle est dans `docs/MODELE-CONTRAT-USAGE-PLATEFORME.md` ; **il doit être relu par le délégué à la
protection des données (DPO) ou un juriste de votre structure** avant publication. Moodle fournit l'outil pour le publier et
garder la preuve de l'acceptation (`tool_policy`).

1. **Activer l'outil.** Ouvrez **Administration du site → Utilisateurs → Confidentialité et politiques → Réglages des politiques**
   (`/admin/settings.php?section=policysettings`). Réglez **Gestionnaire de politique du site** (`sitepolicyhandler`) sur
   **tool_policy**. Enregistrez.
2. **Créer la politique.** Ouvrez **Gérer les politiques** (`/admin/tool/policy/managedocs.php`), puis **Nouvelle politique**.
   Choisissez le type **Politique du site**, collez le texte du contrat (relu), choisissez **Obligatoire** et **Tous les
   utilisateurs authentifiés** (ou la seule population des vaeistes), puis enregistrez.
3. **L'activer.** Passez la politique à l'état **Actif** (« Définir l'état sur Actif »). Chaque personne la verra à sa prochaine
   connexion et devra l'accepter.
4. **La modifier.** Un changement important crée une **nouvelle version** qui redemande l'acceptation. Les anciennes versions et
   leurs acceptations restent consultables (`/admin/tool/policy/acceptances.php`).
5. **Déléguer la rédaction.** Pour qu'un DPO ou un juriste corrige le texte sans être administrateur du site, créez un rôle
   (comme en 6.1, contexte **Système**) avec les droits `tool/policy:managedocs` et `tool/policy:viewacceptances`, et attribuez-le
   en 6.4.

**Résultat attendu :** en vous connectant avec un compte de test (partie 12), vous voyez la politique et devez l'accepter pour
continuer.

### 10.1 Ce que le contrat d'usage doit dire du positionnement de la structure

*Décisions du porteur du projet, également délégué à la protection des données (DPO) du site, le 5 novembre 2026. Le modèle
`docs/MODELE-CONTRAT-USAGE-PLATEFORME.md` ne dit encore rien du positionnement (vérifié le 5 novembre 2026) : **la clause est à y ajouter, puis
à faire relire par le DPO du site**, avant d'attribuer à quiconque les droits `tool/vae:evaluate` ou `tool/vae:certify`, à la main ou par un des deux rôles facultatifs (partie 6).*

Un **positionnement** est une ligne écrite par la structure (une évaluation d'une compétence, ou la certification d'un bloc) : elle dit où en
est la structure, **jamais une validation**, que seul le jury externe donne. Le contrat doit prévoir :

1. **La base légale** : le contrat d'usage lui-même (article 6, paragraphe 1, point b, du RGPD), comme pour la déclaration, l'attestation et
   l'export. Il en va de même pour le nom des référents de bloc.
2. **L'information du vaeiste** : le contrat dit qu'à chaque ligne de positionnement écrite, le vaeiste en est averti par un message. Le plugin
   envoie ce message (voir 10.2) ; la clause et le message vont ensemble.
3. **La durée de conservation** : celle du dossier. Une ligne reste jusqu'à la suppression du compte du vaeiste et s'efface avec lui ; le plugin
   ne la purge pas de lui-même et elle ne se modifie pas (ajout seul). Le DPO du site peut en demander l'effacement.
4. **La voie de contestation** : le vaeiste qui conteste une ligne s'adresse au **DPO du site**. Il n'existe **aucun écran** pour cela dans le
   plugin. Une rectification passe par une **nouvelle ligne écrite par la structure** (la précédente reste) ; la limitation et l'opposition
   passent par le DPO.
5. **L'auteur d'une ligne** : si le compte de son auteur est effacé, le texte de la ligne reste et l'identité de l'auteur part. Le contrat peut le dire.
6. **Les comptes inactifs** : leur suppression est une décision de l'administrateur du site, à réexaminer tous les 36 mois ; le plugin ne
   supprime aucun compte de lui-même (le réglage est celui de Moodle). *Formule à confirmer dans sa rédaction exacte : le porteur a dit
   « décision de l'administrateur, relancer tous les 36 mois ».*
7. **Les copies hebdomadaires et les sauvegardes de l'administrateur** : leur durée n'est pas fixée par le plugin. C'est une politique du site,
   à écrire par le DPO (section 6 de `docs/T-84-conformite-rgpd.md`) ; elle reste **à fixer par le site**.

### 10.2 Le message au vaeiste (build 2026110404)

Chaque fois qu'une évaluation ou une certification est écrite, le plugin met en file d'attente **un message au vaeiste concerné**. Ce qu'il fait,
d'après le code :

- Il dit seulement **qu'une ligne a été ajoutée** à son positionnement, rappelle que **ce n'est pas une validation**, renvoie au dossier par un
  lien (« Ouvrir le dossier ») et indique de s'adresser au DPO du site pour une rectification, une limitation ou une opposition. Il ne dit **ni le
  statut, ni le motif, ni l'auteur** : le vaeiste les lit dans son dossier.
- Il n'est envoyé qu'au vaeiste concerné, et seulement s'il peut encore ouvrir son dossier (droit `tool/vae:viewown`) et si une ligne subsiste au
  moment de l'envoi.
- Il part par une tâche de fond, **comme les autres messages du plugin : il n'arrive que si le cron tourne** (partie 4.2). Ce n'est donc pas
  un message immédiat.
- Comme les autres messages du plugin, il est **activé par défaut** (fenêtre de notification et courriel) et **permis**, non imposé : la
  personne peut le désactiver dans ses préférences de notification, et l'administrateur dans les réglages par défaut des notifications de Moodle.
  *Le code déclare le message ainsi ; les pages de Moodle n'ont pas été rejouées pour ce guide.* Un vaeiste qui le désactive n'est pas averti :
  la clause du contrat (10.1, point 2) reste donc nécessaire.

À la répétition générale (partie 12), ce message ne se contrôle que si un compte de test reçoit le droit `tool/vae:evaluate` ou
`tool/vae:certify` (droits à donner à la main, ou par un des deux rôles facultatifs de la partie 6) ; sinon, ce point reste **non vérifié** sur votre site.

### 10.3 Le rappel sur les formulaires

Les formulaires d'évaluation et de certification affichent, **au-dessus du motif**, le rappel : « Rappel : ne nommez aucune personne accompagnée
(au besoin, un prénom fictif) et ne donnez aucun détail qui la rende reconnaissable. » Le formulaire de certification l'affiche aussi **au-dessus du
motif d'alerte**. C'est un rappel à l'écran, sans contrôle du texte saisi : il faut le redire aux personnes qui reçoivent ces droits.

---

## Partie 11 — Antivirus (seulement si vous ouvrez les pièces jointes)

À ignorer tant que « Ouvrir les pièces justificatives aux vaeistes » reste décoché.

1. Faites installer **ClamAV** sur le serveur (c'est une affaire de serveur : prestataire ou informaticien).
2. Ouvrez **Administration du site → Plugins → Antivirus → Gérer les antivirus**
   (`/admin/settings.php?section=manageantiviruses`), activez **ClamAV** et réglez-le (méthode d'exécution et chemin ou socket).
3. Testez avec un fichier d'essai inoffensif, connu des antivirus (« EICAR »), pour vérifier qu'il est bien refusé.

*Tant qu'aucun antivirus n'est configuré, le plugin refuse tout fichier, sauf si vous cochez « Accepter les fichiers sans
antivirus » (déconseillé).*

---

## Partie 12 — Répétition générale avec trois comptes de test

**Ne passez pas à l'ouverture réelle sans avoir fait cette répétition.** Elle prend une demi-heure et trouve la plupart des
oublis.

### 12.1 Créer trois comptes de test

Ouvrez **Administration du site → Utilisateurs → Comptes → Ajouter un utilisateur** (`/user/editadvanced.php?id=-1`). Créez :

| Nom d'utilisateur | Prénom / Nom | À faire ensuite |
|---|---|---|
| `essai.vaeiste` | Camille Essai | Attribuer **Vaeiste** (6.4) ; l'ajouter à une cohorte d'essai (7.1) |
| `essai.responsable` | Robin Essai | Le rattacher à la cohorte d'essai avec **Responsable VAE** (7.2) |
| `essai.direction` | Dominique Essai | Attribuer **Direction VAE** (6.5) |

Notez les mots de passe. **Supprimez ces trois comptes à la fin de la répétition.**

*Attendez au plus une heure (cron) que le rattachement du responsable soit synchronisé, ou lancez le cron à la main.*

### 12.2 Le parcours du vaeiste

Ouvrez une **fenêtre de navigation privée**, connectez-vous en `essai.vaeiste`.

1. Acceptez la politique si elle s'affiche (partie 10).
2. Cliquez votre nom en haut à droite, puis **Profil**. Dans la zone « Divers », cliquez **Dossier d'acquis de l'expérience**.

   **Résultat attendu :** la page du dossier, avec le message « Aucune activité n'a encore été déclarée. » et des boutons
   (**Déclarer une activité**, **Dossier de faisabilité**…). Le bouton **Exporter le dossier** n'apparaît pas encore : il
   n'apparaît qu'**après une première déclaration**.
3. Cliquez **Déclarer une activité**. Le formulaire demande : la **compétence visée** (une liste tirée de la fiche importée), les
   dates **Activité exercée du** et **jusqu'au**, le **cadre d'intervention** (EHPAD, SSIAD, SAAD…), une **précision sur le cadre**
   et la **situation de travail**. Remplissez-les avec des données **fictives**, puis cliquez **Enregistrer cette déclaration**.

   *Si la fiche choisie est cachée dans Moodle (« Visible : Non »), elle n'est pas proposée : la page dit qu'elle est cachée et qu'on
   ne peut pas y déclarer pour le moment. Un compte d'essai de vaeiste ne peut pas essayer une fiche cachée : rendez-la visible le
   temps de la répétition (elle est alors ouverte à tous), puis cachez-la de nouveau.*

   **Résultat attendu :** vous revenez au dossier, la déclaration apparaît, **non attestée**, et le bouton **Exporter le dossier**
   est maintenant présent.
4. Cliquez **Dossier de faisabilité**, ouvrez la rubrique « Objectifs de la démarche », écrivez deux phrases, cliquez
   **Enregistrer une version**.

   **Résultat attendu :** « Version 1 enregistrée. »

### 12.3 Le parcours du responsable

Dans une autre fenêtre privée, connectez-vous en `essai.responsable`.

1. Vous devez avoir reçu une **notification** (cloche en haut à droite) : une déclaration attend votre attestation.
   *Cette notification part par une tâche de fond : **elle n'arrive que si le cron tourne** (partie 4.2). Attendez une minute ; si
   rien n'arrive, le cron est en cause, pas les droits.*
   Le message vient de l'expéditeur « ne pas répondre » du site, avec pour objet « Une déclaration attend votre attestation » (le nom du
   vaeiste n'est pas dans l'objet). Il se termine par la phrase « Ce message est automatique. Pour attester la déclaration ou poser une
   question, ouvrez le dossier avec le lien ci-dessous. », puis par le lien « Ouvrir le dossier » : c'est ce lien qu'on suit, pas une réponse.
2. Profil → **Mes vaeistes** → Camille Essai.
3. Dans le dossier de Camille, sous la déclaration, cliquez le bouton d'attestation. Remplissez **Votre fonction et votre
   établissement** et **Énoncé de votre attestation**, cochez **Lecture** (vous confirmez avoir lu le récit), puis cliquez
   **Attester cette déclaration**.

   **Résultat attendu :** la déclaration passe à **attestée** ; Camille reçoit une notification.

### 12.4 Le parcours de la direction

Connectez-vous en `essai.direction`. Ouvrez `/admin/tool/vae/pending.php` (**Déclarations en attente d'attestation**).

**Résultat attendu :** la liste affiche les déclarations non attestées de tout le site (vide si tout est attesté).

### 12.5 L'export (la portabilité, et ses limites)

Retour dans la fenêtre du vaeiste : dans son dossier, cliquez **Exporter le dossier**.

**Résultat attendu :** un fichier `.zip` se télécharge. Ouvrez-le : il contient `manifeste.json`, un dossier `preuves/`,
`referentiel.json`, un dossier `schema/`, `LISEZMOI.txt` et `dossier.pdf`. La page d'export vous demande d'abord de confirmer (« Produire
maintenant l'archive de ce dossier ? ») et rappelle que l'archive est une version figée destinée au jury, puis dit ce qu'elle ne contient pas
(les dépôts du dossier de faisabilité, les avis des responsables, les questions de précision et leurs réponses) et où les retrouver (l'export des
données personnelles de Moodle, qui se lit mais ne se réimporte pas), et qu'une autre plateforme ne peut la reprendre qu'à deux conditions : qu'elle n'ait pas
déjà numéroté autrement les compétences que l'archive nomme, et que le compte du vaeiste n'y ait pas déjà d'identifiant (il le reçoit dès sa première
déclaration, son premier export, sa première copie ou sa première sauvegarde) ; sinon l'import est refusé en entier. Ce texte s'adresse au vaeiste (« votre
dossier », « vos déclarations ») quand il exporte son propre dossier ; quand la direction ou l'administrateur exporte le dossier d'un autre, il parle du vaeiste
à la troisième personne. **Le PDF doit s'ouvrir et se lire.** (L'archive est supprimée du serveur
au bout de 24 heures.)

Si, sur un vrai site, un avertissement s'affiche après l'export (« une ligne dont le format n'est pas celui attendu »), l'archive a bien été produite
et son PDF se remet au jury : l'avertissement dit seulement qu'elle ne pourra pas être réimportée telle quelle. La ligne vient d'une déclaration déjà enregistrée
avec un rang de compétence hors format (par exemple « c7 » au lieu de « C7 »), par une version antérieure du plugin ou directement dans la base de données ;
une déclaration nouvelle sur un tel rang est refusée. Cela ne se corrige pas depuis Moodle (corriger le numéro d'identification de la compétence ne réécrit pas
la déclaration enregistrée) : l'écran invite le vaeiste à prévenir l'administrateur du site, c'est-à-dire vous, et l'avertissement revient à chaque export tant que
la déclaration existe. Voir la FAQ (`docs/FAQ.md`). Un dossier trop volumineux, ou une pièce dont le fichier est perdu ou altéré, restent refusés, avec un message
qui rappelle que la copie modifiable (Word ou OpenDocument) reste possible.

### 12.6 Nettoyer

Supprimez les trois comptes de test (**Administration du site → Utilisateurs → Comptes → Parcourir la liste des utilisateurs**).

---

## Partie 13 — Les fonctions fermées : quand et comment les ouvrir

Ces fonctions sont **fermées par défaut** (partie 9). Chacune a une condition. **Ne les ouvrez pas avant.** Pour ouvrir : cochez
le réglage dans `/admin/settings.php?section=tool_vae_settings` et enregistrez.

| Fonction (réglage) | Ne l'ouvrir que si… |
|---|---|
| **Préparation du dossier de validation** (`validationopen`) | un accompagnateur VAE en exercice a relu ses intitulés et ses aides (fiche `docs/RELECTURE-PREPARATION-VALIDATION.md`) |
| **Journal de bord** (`reflectionopen`) | la préparation du dossier de validation est relue **et** la durée de conservation d'un tel texte est écrite |
| **Pièces justificatives** (`piecesopen`) | l'amendement 4 du document de dérogation `DER-referentiel-001` est validé, la durée de conservation est écrite, **un antivirus est configuré** (partie 11), et la question d'une analyse d'impact pour d'éventuelles pièces de soins est tranchée |
| **Règles de validation : adresses** (`refopen`) | un accompagnateur VAE a relu la phrase qui encadre les adresses, et vous avez saisi les vraies adresses (**Compétences → Règles de validation : adresses**, `/admin/tool/vae/refs.php`) |
| **« Hors de mon activité »** (`scopeopen`) | un accompagnateur VAE a relu le libellé et la phrase qui l'encadre |
| **Aide à la reformulation par IA** (`aiopen`) | voir ci-dessous |

### Ouvrir l'aide d'IA, point par point

Cette fonction envoie un texte du vaeiste à un fournisseur d'intelligence artificielle. Elle exige **toutes** ces conditions :

1. Le **contrat d'usage** est publié et accepté (partie 10), et il nomme le fournisseur d'IA.
2. La **base légale, la durée de conservation, le registre des traitements** et, si besoin, l'**analyse d'impact** sont faits
   (`docs/T-84-conformite-rgpd.md`, `docs/AIPD-VAE.md`).
3. Un **contrat avec le fournisseur d'IA** existe (ou le modèle est installé sur votre propre serveur).
4. **Un seul fournisseur d'IA est actif** (*Administration du site → Plugins → IA → Fournisseurs d'IA*). Avec deux, le plugin
   n'envoie rien, par précaution.
5. Les textes (instruction, politique d'IA du site, consigne du fournisseur) ont été relus (`docs/RELECTURE-AIDE-REFORMULATION.md`).
6. Vous avez donné le droit **`tool/vae:useai`** au rôle « Vaeiste » (6.1).
7. Vous savez **qui approuve les demandes de suppression de données** (**Administration du site → Utilisateurs → Protection des données et
   politiques → Demandes de données**) ; le réglage de Moodle qui crée une telle demande à chaque suppression de compte (« Créer
   automatiquement des demandes de suppression de données », actif par défaut, dans **Réglages de protection des données**) reste actif ; et la
   finalité et la catégorie du site ne sont pas définies dans le registre des données (**Administration du site → Utilisateurs → Protection des données et
   politiques → Registre des données**), ou vous acceptez de faire effacer à la main les textes envoyés à l'IA.
   Le plugin n'efface pas lui-même ces textes : Moodle les garde dans ses propres tables, et seule l'approbation d'une
   de ces demandes les vide, à ces deux conditions. Si le réglage est désactivé, plus rien ne crée cette demande à la suppression d'un compte : les textes
   restent dans Moodle tant qu'une demande n'a pas été créée, puis approuvée. Si la finalité et la catégorie du site sont définies, Moodle ne vide jamais ces
   textes (ils sont rattachés au contexte du site) et déclare quand même la demande terminée : il faut alors les faire effacer à la main. Après l'effacement,
   Moodle garde une ligne de registre : numéro du compte (qui reste rattaché au nom dans Moodle), date, fournisseur, modèle, taille de l'échange en jetons,
   réussite ou échec et, après un échec, le message d'erreur du fournisseur. À chaque envoi, l'écran de confirmation dit au vaeiste l'une de deux phrases selon
   l'état du site à ce moment-là : l'effacement après la suppression du compte, une fois la demande approuvée, ou, quand l'une des deux conditions manque, que
   sur ce site Moodle ne les efface pas de lui-même et qu'il faut le demander à l'administrateur.
8. Vous cochez enfin **Ouvrir l'aide à la reformulation aux vaeistes**.

**Résultat attendu :** un vaeiste de test voit, sous un champ de texte, le bouton **Proposer une autre formulation**. En cliquant,
il lit la politique d'IA du site, l'accepte (une fois pour tout le site), puis voit **le texte exact et le nom du fournisseur** avant tout envoi, et doit confirmer. **À
chaque envoi.**

---

## Partie 14 — Si quelque chose ne marche pas

| Ce que vous voyez | Cause probable | Que faire |
|---|---|---|
| « Le sous-système de compétences est désactivé… » | Les compétences ne sont pas activées | Partie 4.1 |
| Le ZIP est refusé à l'installation | Le dossier racine n'est pas `vae`, ou l'installation par ZIP est verrouillée | Vérifier la partie 2 ; sinon méthode B |
| Moodle refuse le plugin pour sa version | Moodle n'est pas en 5.3 (ou est en 5.4 ou plus) | Passer Moodle en 5.3 (le paquet 4.5 n'est plus maintenu : `docs/BRANCHES.md`) ; en 5.4 ou plus, le plugin n'est pas déclaré compatible |
| Un vaeiste ne voit pas « Dossier d'acquis de l'expérience » sur son profil | Il n'a pas le rôle « Vaeiste » **au niveau du site**, ou ce rôle n'a pas `viewown` | Parties 6.1 et 6.4 |
| Un vaeiste voit le dossier mais pas « Déclarer une activité » | Droit `tool/vae:declare` absent | Partie 6.1 |
| Un vaeiste ne peut pas exporter | Droit `tool/vae:export` absent (avec `viewown`) | Partie 6.1 |
| Aucune fiche à choisir pour déclarer | Aucune fiche importée | Partie 8 |
| « Aller chercher le dernier export » échoue : « Le serveur de ce site n'a pas pu joindre data.gouv.fr… » (ou « n'a pas répondu à temps », ou un message sur le certificat) | L'hébergement ne laisse pas le serveur aller sur Internet, un pare-feu ou un proxy l'en empêche, ou data.gouv.fr ne répond pas | Donner à l'hébergeur la ligne « À donner à votre hébergeur » du message, pour qu'il ouvre le https sortant vers `www.data.gouv.fr` et `static.data.gouv.fr` ; ou déposer le fichier zip vous-même (partie 8.3), qui ne demande aucune sortie du serveur. Tous les messages : guide d'usage V2, 4.11 |
| Le dépôt du fichier zip échoue : « Le fichier n'est pas arrivé en entier… » | La limite d'envoi de fichiers du site est inférieure à l'export (environ 10 Mo) | Utiliser « Aller chercher le dernier export » (partie 8.2), ou demander à l'hébergeur de relever la limite d'envoi |
| L'aperçu refuse une fiche : « Ce numéro n'est pas dans l'export… » ou « Cette fiche n'a aucun bloc de compétences… » | Faute de frappe, fiche publiée après l'export, fiche qui n'est plus en vigueur, fiche du répertoire spécifique (RS), ou fiche en vigueur sans bloc dans l'export | Vérifier le numéro sur la fiche de France compétences ; chercher le numéro de la fiche en vigueur (partie 8.4) ; les autres fiches s'importent |
| Le vaeiste lit « La fiche … est cachée : on ne peut pas y déclarer d'activité pour le moment » | Le référentiel de la fiche est réglé sur « Visible : Non » dans Moodle | Le remettre sur « Visible : Oui » quand la fiche est prête (partie 8.4) |
| Une fiche est listée sur « VAE - cheminement » sans aucune compétence, avec un avertissement | Son référentiel a été supprimé dans Moodle, ou le premier import a échoué | La réimporter ; ou, si elle a été importée par erreur, « Retirer cette ligne » (partie 8.4 : le bouton manque si un vaeiste a laissé une trace sur la fiche, si Moodle détient un référentiel portant ce code, ou sans le droit d'importer) |
| L'export ou la sauvegarde affiche un avertissement sur une ligne dont le format n'est pas celui attendu | Une déclaration a été enregistrée avec un rang de compétence hors format (par une version antérieure du plugin, ou directement dans la base de données) | L'archive ou la sauvegarde est produite quand même mais ne se réimportera pas telle quelle ; cela ne se corrige pas depuis Moodle, et l'avertissement revient tant que la déclaration existe : partie 12.5 et `docs/FAQ.md` |
| Un responsable ne voit pas « Mes vaeistes » | Il n'est rattaché à aucune cohorte, ou la synchronisation n'a pas eu lieu | Partie 7.2 ; attendre le cron ; vérifier 4.2 |
| Un responsable ne peut pas attester | Il n'a pas `viewassigned` **et** `attest`, ou son rôle est attribué au niveau du site | Partie 6.2 ; refaire par cohorte (7.2) |
| Les pièces jointes sont refusées | Aucun antivirus configuré | Partie 11 |
| Pas de rappel, pas de copie hebdomadaire | Le cron ne tourne pas | Partie 4.2 |
| Page blanche ou erreur | Erreur de serveur | Activer temporairement le débogage (**Développement → Débogage**), reproduire, **noter le message**, puis remettre le débogage à « Aucun » |
| Autre | — | Notez l'adresse de la page, ce que vous avez cliqué, le message d'erreur exact, et contactez le responsable du plugin |

---

## Partie 15 — Après la mise en service

### Mettre à jour le plugin

1. Faites une **sauvegarde** (partie 1).
2. Installez le nouveau ZIP comme en partie 3 (même méthode). Moodle détecte une version plus récente.
3. Sur **Notifications**, cliquez **Mettre à jour la base de données Moodle maintenant**.
4. Refaites la **répétition générale** de la partie 12, en vous limitant au parcours du vaeiste et de l'export.

Les données des vaeistes sont conservées.

### Désinstaller le plugin

Passez **uniquement** par **Administration du site → Plugins → Présentation des plugins** (`/admin/plugins.php`), chercher
« VAE - cheminement », **Désinstaller**. Chaque dossier est alors **sauvegardé automatiquement** dans les fichiers personnels de
l'administrateur qui désinstalle. **Ne supprimez jamais le dossier `vae` sur le serveur sans cette étape préalable :** les
données seraient perdues sans sauvegarde.

Cette sauvegarde ne contient ni les dépôts du dossier de faisabilité (numéros, dates, empreintes, notes), ni les avis des responsables, ni les
questions de précision et leurs réponses, ni les versions antérieures des rubriques, ni qui a décidé un report et sa note, ni — quel que soit celui
qui désinstalle — le journal de bord et la préparation du dossier de validation. Faites exporter par les vaeistes le journal et la préparation
avant de désinstaller, et notez que dépôts, avis et demandes de précision ne reviennent pas au réimport : ils se retrouvent dans l'export des
données personnelles de Moodle de chaque vaeiste, à obtenir **avant** de désinstaller (voir plus bas « Ce que vous devez faire
régulièrement »). Une ligne dont le format n'est pas
celui attendu (un rang de compétence écrit en minuscules, par exemple) n'empêche pas cette sauvegarde, mais la copie ne se réimportera
pas telle quelle : la notice `LISEZMOI.txt` qu'elle contient le dit. Elle ne se réimportera pas non plus si, depuis la réinstallation du plugin, un dossier est déjà
sorti du site sur les mêmes compétences, ou si le compte du vaeiste a déjà reçu un nouvel identifiant (dès sa première déclaration, son premier export, sa première
copie ou sa première sauvegarde) : l'import est alors refusé en entier, avec un message qui dit pourquoi.

### Ce que vous devez faire régulièrement

- Vérifier que le **cron** tourne.
- Après chaque republication d'une fiche par France compétences, **réimporter** la fiche (partie 8) : si elle a changé de numéro, importer le nouveau numéro.
- Réexaminer tous les **36 mois** la décision sur les **comptes inactifs** (10.1, point 6) : le plugin ne supprime aucun compte de lui-même.
- Relire la **politique** et le **contrat** chaque année, et à chaque changement d'hébergement ou de fournisseur d'IA.
- **Supprimer** les comptes des vaeistes selon le délai que vous avez fixé (fiche `docs/T-84-conformite-rgpd.md`, partie 3), après
  leur avoir laissé le temps d'**exporter leur dossier** (l'archive ne porte pas les dépôts, les avis ni les questions de précision) et
  d'obtenir l'**export de leurs données personnelles de Moodle**, qui les contient. Pour que le vaeiste puisse le demander lui-même :
  **Administration du site → Utilisateurs → Protection des données et politiques → Réglages de protection des données**, cochez
  « Contacter le délégué à la protection des données » (désactivé par défaut), et prévoyez qui approuve (le rôle de délégué à la
  protection des données ; à défaut, les administrateurs du site ; ou la case « Approbation automatique des demandes d'exportation de
  données », désactivée par défaut). Sans cela, l'administrateur crée la demande à sa place (**Demandes de données → Nouvelle demande**).

---

## Récapitulatif : la liste des droits du plugin

| Droit | Niveau | Rôle qui le porte |
|---|---|---|
| `tool/vae:declare` | utilisateur | Vaeiste |
| `tool/vae:viewown` | utilisateur | Vaeiste |
| `tool/vae:draftown` | utilisateur | Vaeiste |
| `tool/vae:export` | utilisateur | Vaeiste (le sien) ; Direction VAE (tous) |
| `tool/vae:useai` | utilisateur | Vaeiste, **seulement quand l'aide d'IA est ouverte** |
| `tool/vae:viewassigned` | utilisateur | Responsable VAE |
| `tool/vae:attest` | utilisateur | Responsable VAE |
| `tool/vae:readdeposit` | utilisateur | Responsable VAE ; Direction VAE |
| `tool/vae:writeopinion` | utilisateur | Responsable VAE |
| `tool/vae:viewany` | système | Direction VAE |
| `tool/vae:draftview` | utilisateur | Direction VAE |
| `tool/vae:identityview` | utilisateur | Direction VAE (si nécessaire) |
| `tool/vae:withdrawany` | utilisateur | à décider (retirer n'importe quelle attestation) |
| `tool/vae:import` | système | administrateur du site |
| `tool/vae:importdossier` | système | administrateur du site |
| `tool/vae:evaluate` | utilisateur | rôle facultatif « Responsable VAE évaluateur », ou à donner à la main au rôle qui suit les vaeistes (évaluer une compétence d'un vaeiste suivi, avec un statut et un motif écrit ; jamais pour soi-même). Chaque ligne écrite envoie un message au vaeiste (10.2) |
| `tool/vae:certify` | système | rôle facultatif « Direction VAE certificatrice », ou à donner à la main, toujours avec `tool/vae:viewany` (certifier un bloc : c'est le positionnement de la structure, jamais une validation, que seul le jury externe donne ; pas pour son propre dossier). Chaque ligne écrite envoie un message au vaeiste (10.2) |
| `tool/vae:linkcourse` | cours | Gestionnaire de Moodle (archétype manager), par défaut (rattacher une fiche à un cours) |
