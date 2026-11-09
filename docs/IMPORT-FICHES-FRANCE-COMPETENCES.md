# Importer des fiches de France compétences (procédure)

> Rédigée le 20 octobre 2026 (T-31), **réécrite le 27 octobre 2026 pour la version 0.47.0** : une fiche s'importe désormais par un
> écran, qui lit l'export de France compétences déposé par l'administrateur ou récupéré par le serveur sur data.gouv.fr. La ligne de
> commande de la version 0.31.0 reste comme voie de secours (§ 3). Les mesures citées viennent des exports quotidiens des 19 et
> 27 octobre 2026, lus en lecture seule, hors du dépôt. **Aucune donnée de France compétences n'est dans ce dépôt** (Licence Ouverte 2.0,
> point ouvert PO-2) : ce document dit où la prendre, ce que fait l'écran, et ce qu'on y a constaté. **L'écran n'a jamais été cliqué
> par une personne** (marque « non cliqué ») ; tout ce qui n'est pas vérifié est dit au § 7.

## 1. Ce que fait l'écran

L'écran s'ouvre depuis la page d'accueil du plugin (*Administration du site, Compétences, VAE - cheminement*), par le bouton
**« Importer des fiches depuis France compétences »**. Le second bouton, **« Importer un fichier CSV (cas particulier) »**, ouvre
l'ancien écran à quatre colonnes, inchangé : il reste pour une fiche préparée à la main. Les deux boutons ne sont offerts qu'à qui
détient le droit `tool/vae:import`, et un clic sur l'un d'eux n'ouvre qu'une page : aucune requête ne part, rien n'est écrit.

La page exige aussi que les compétences soient activées et que l'administrateur puisse gérer les référentiels de compétences
(`moodle/competency:competencymanage`). Sinon elle le dit, avant de lire un fichier ou de faire une requête.

### 1.1 Les numéros, puis une voie sur deux

1. **Les numéros des fiches** : un ou plusieurs, par exemple `RNCP12345` ou `RS1234` (des exemples de forme, pas des fiches à importer), un par ligne ou séparés par des virgules ou des
   points-virgules. « RNCP 40692 » (avec une espace) et les minuscules sont tolérés ; un numéro tapé deux fois n'est lu qu'une fois, et
   l'aperçu le dit. **Vingt numéros au plus** par passage. Aucune liste n'est figée dans le code : les numéros changent quand une fiche est
   remplacée, et l'écran ne les devine jamais. Un lien, « Reprendre les numéros des fiches déjà importées », remplit le champ.
2. **Une voie, par son bouton**, dans le même formulaire (aucun JavaScript) :
   - **Voie B, « Aller chercher le dernier export »** : le serveur du site va chercher lui-même le dernier export sur data.gouv.fr, **au
     clic et seulement alors**. Rien n'est planifié, aucun réglage n'est à cocher. Elle suppose que le serveur puisse joindre
     `www.data.gouv.fr` et `static.data.gouv.fr` en https.
   - **Voie A, « Lire le fichier déposé »** : l'administrateur a téléchargé lui-même le fichier zip `export-fiches-csv-AAAA-MM-JJ.zip`
     (environ 9,7 Mo) sur la page du jeu de données, **sans le décompresser ni le renommer**, et le dépose dans le champ « Fichier zip de
     l'export ». Utile quand l'hébergement ne laisse pas le serveur aller sur Internet. La limite d'envoi de fichiers du site est annoncée
     au-dessus du champ quand elle est inférieure à environ 12 Mo : l'export approche 10 Mo, et il grossit ; une limite voisine fait préférer la
     voie B.
3. L'écran dit que la lecture peut prendre une minute. **Entrée dans un champ ne lance aucune requête** : le premier bouton du
   formulaire est un bouton par défaut qui ne fait que demander de choisir l'une des deux voies.

Le bouton de la voie B est celui d'un formulaire envoyé avec la clé de session de Moodle : un lien ou une adresse tapée n'en déclenche
jamais l'effet, et un compte sans le droit d'importer ne fait jamais télécharger quoi que ce soit au serveur.

### 1.2 La lecture, puis l'aperçu : rien n'est écrit

Dans les deux cas, le chemin est le même à partir du fichier zip. Le plugin :

- **lit le fichier zip sans rien extraire sur le disque**, et n'y ouvre que deux fichiers : « Blocs de compétences » et « Standard ».
  **Le gros fichier « Partenaires » (32 Mo : noms et SIRET d'organismes) n'est jamais ouvert**, ni lu, ni journalisé ;
- convertit les fiches demandées en une seule lecture, et contrôle chacune contre ce que le site détient, **sans rien écrire** ni dans les
  référentiels de Moodle ni dans le tableau des fiches importées ;
- garde un plan : un petit fichier (quelques Ko) dans le dossier temporaire du site, valable deux heures, et de nouveau deux heures après la confirmation, le temps de relire le résultat ; lié à
  l'administrateur qui l'a demandé. **Le fichier zip n'est jamais gardé** ;
- affiche un **aperçu**, que l'on peut recharger (un rechargement ne relance rien). Le plan étant un fichier, **ce qu'il dit (la voie, le
  jour de l'export, l'empreinte, les numéros, les types, la forme des blocs) est contrôlé de nouveau avant d'écrire** : un plan altéré est
  refusé en entier.

L'aperçu montre d'abord la source (« Export déposé » ou « Export récupéré sur data.gouv.fr », avec le nom et la taille du fichier), la date
de l'export et une note (les fichiers contenus dans l'export portent le jour d'avant : c'est normal), puis les avertissements qui
n'empêchent rien : export d'au moins sept jours, dates de l'export et de ses fichiers qui ne vont pas ensemble, numéro saisi plusieurs
fois. Il dit ensuite : *« Lisez chaque intitulé avant de confirmer. Un numéro faux, mais qui existe, importe une autre certification sans
aucun message d'erreur. »* C'est la seule protection contre un numéro faux : **l'intitulé publié est montré en entier**.

Le tableau a une ligne par numéro et quatre colonnes :

| Colonne | Ce qu'elle dit |
|---|---|
| **Fiche** | le numéro et l'intitulé publié, en entier |
| **Dans l'export** | le nombre de blocs (le détail se déplie : rang et libellé publié en entier) ; les libellés devenus « Bloc » suivi du rang, parce qu'ils dépassent 100 caractères ou que le cœur de Moodle les refuserait comme titre ; un intitulé de fiche qui ne peut pas servir de nom (seulement pour une fiche nouvelle ou à reconstruire : un référentiel que Moodle détient garde son nom) ; l'état tel que publié (active, ou indiquée inactive) ; la fin d'enregistrement telle que publiée |
| **Dans Moodle** | nouvelle ; déjà importée (avec la date de sa source et le nombre de déclarations déjà faites dessus) ; référentiel à reconstruire ; référentiel existant à reprendre tel quel |
| **Ce qui sera fait** | les compétences ajoutées, mises à jour, sorties de la fiche ou modifiées hors du plugin ; ou le refus nommé suivi de « Rien ne sera écrit pour cette fiche. » ; ou la mention d'un export plus ancien que la version tenue (§ 1.5) |

Sous le tableau : ce qui sera enregistré sans rien à saisir (voir § 1.4), l'avertissement qu'**une fiche importée est ouverte aux
déclarations des vaeistes dès l'import** (pour en préparer une à l'abri, l'importer puis la cacher aussitôt dans Moodle : § 5), et, si une
fiche déjà importée est concernée, la note sur les compétences qui sortent : elles ne sont jamais supprimées, elles sont marquées, et les
déclarations qui les portent restent telles quelles.

### 1.3 La confirmation, puis le résultat

Le bouton **« Importer N fiche(s) »** dit combien de fiches partent (les fiches prêtes, moins celles d'un export plus ancien que la version
tenue). Un lien, « Modifier la liste des numéros », ramène aux numéros ; « Annuler » supprime le plan et revient à l'accueil. Si rien ne peut
être importé, l'écran le dit (« Aucune fiche ne peut être importée ») et n'offre pas de bouton d'import.

À la confirmation, le plugin redemande les droits, puis écrit **les fiches une par une, avec la même porte d'écriture que l'écran CSV**
(`importer::import`). Une fiche refusée ne bloque pas les autres. Une panne de la base ou du code arrête la boucle (chaque fiche suivante échouerait de la même façon) : les fiches non tentées sont dites
« non traitées », une fiche déjà refusée reste dite refusée, et le détail de la panne va aux messages de débogage du site, **jamais à l'écran**. Le résultat s'affiche à la même adresse, rechargeable : **un rechargement ou un second clic ne réimporte rien**. Il dit,
pour chaque fiche, ce qui s'est passé (« Importée : » et les mêmes compteurs que l'écran CSV : compétences ajoutées, mises à jour, absentes de la fiche, modifiées
hors du plugin), ou la raison d'un échec, ou « non importée » avec son motif. Une phrase renvoie vers « Fiches de certification remplacées »,
à utiliser quand un nouveau numéro en remplace un ancien (§ 5). Quand une fiche n'a pas été importée, un lien « Reprendre ces numéros pour
refaire l'opération » ramène aux numéros. Un seul import à la fois : un second administrateur lit « Un autre import
est en cours ».

Une republication se réimporte de la même manière : l'import est fait pour ça (rien n'est supprimé, un bloc sorti de la fiche est
marqué, les déclarations ne bougent pas).

### 1.4 Rien à retaper, et la date de la source

Le plugin écrit lui-même, pour chaque fiche : le type (RNCP ou RS), l'intitulé, la **source** (la page du jeu de données, la même pour les
deux voies), la **licence** (« Licence Ouverte 2.0 »), la **paternité** (« France compétences », avec l'accent) et la **date**.

**La date de la source est celle de l'export, lue et jamais devinée** : dans le nom du fichier zip pour la voie A (le navigateur peut y ajouter
« (1) »), dans le titre de la ressource pour la voie B. Si le nom ne la donne plus, un champ facultatif (« Date de l'export », à activer) la
reçoit, et elle l'emporte. Une date postérieure à demain est refusée. Elle est écrite comme l'écran CSV l'écrit (minuit, dans le fuseau de
l'administrateur), pour qu'une même fiche porte le même instant par l'un ou l'autre écran. Les fichiers à l'intérieur de l'export portent
la date de la veille (mesuré : fichiers du 18 octobre dans le zip du 19, du 26 dans celui du 27) : c'est dit à l'écran.

### 1.5 Un export plus ancien que ce que Moodle détient

Si l'export est plus ancien, **par jour**, que la version que Moodle détient pour une fiche, l'aperçu le dit fiche par fiche : l'importer ferait
revenir la fiche en arrière. Elle n'est importée que si une case est cochée en bas de page (« Importer aussi les N fiche(s) que Moodle tient
dans une version plus récente »), ou si c'est la seule chose à importer : le bouton le dit alors lui-même (« Importer les N fiche(s) plus
anciennes que celles de Moodle »). Le plugin informe, il n'empêche rien ; la comparaison est refaite au moment d'écrire. Pour une fiche plus ancienne, l'aperçu ne dit pas ce
que l'import changerait : rien ne change sans le geste.

### 1.6 Ce que l'écran refuse, par son nom

**Refus par fiche** : rien n'est écrit pour elle, les autres s'importent.
- numéro absent de l'export (« Ce numéro n'est pas dans l'export du … ») ;
- fiche **sans bloc, qui n'est plus en vigueur** (la plupart des fiches RNCP inactives : 14 603 sur l'export du 19 octobre 2026) ou du
  Répertoire spécifique (aucune fiche RS n'a de bloc dans les exports des 19 et 27 octobre 2026) : la phrase conseille de chercher le
  numéro de la fiche en vigueur ;
- fiche **sans bloc, en vigueur** (`Actif` vaut `ACTIVE` : 236 fiches RNCP et 1 490 fiches RS sur l'export du 19 octobre 2026, mesurées
  en lecture seule ; une de ces fiches RNCP est `RNCP39923`) : phrase à part, qui dit qu'il n'y a rien à importer de cet export, que ce n'est
  pas un numéro à remplacer, et renvoie à l'écran CSV (cas particulier) ;
- code de bloc d'une autre forme que le numéro de la fiche suivi de `BC` et de chiffres ;
- libellé de bloc vide ; caractère de commande invisible ou contrôle de sens d'écriture ; texte de plus de 20 000 caractères ; état ou fin
  d'enregistrement qui contient un tel caractère ou dépasse 64 caractères ;
- fiche **en double** dans l'export (le même numéro deux fois dans le fichier Standard, ou le même rang de bloc deux fois) : on ne sait
  pas laquelle prendre, rien n'est choisi au hasard ; fiche de **plus de 500 blocs** (ce n'est pas une certification) ;
- refus de l'importeur, avant toute écriture : un référentiel de Moodle qui porte déjà ce code et contient des compétences que le plugin n'a pas
  écrites, ou dont l'identifiant a été changé depuis le dernier import.

**Refus de tout l'export** (« Rien n'a été écrit ») : fichier qui n'est pas un fichier zip lisible ; fichier de plus de 32 Mio ou de plus de
cinquante entrées ; fichier de l'export manquant, ou deux fichiers qui pourraient l'être ; fichier abîmé, chiffré, trop gros ou dont la taille déclarée
ment ; contenu qui n'est pas en UTF-8 (les accents seraient abîmés) ; fichier vide ; colonne attendue absente ; nom sans date et pas de date
saisie ; date dans le futur ; et, avant cela, numéros absents, mal formés ou trop nombreux. Un aperçu qui a plus de deux heures, ou qui est
celui d'une autre personne, ne se confirme pas.

**Refus propres à la voie B** : le serveur ne joint pas data.gouv.fr (réseau, délai dépassé, certificat non vérifiable, adresse bloquée par la
liste d'hôtes bloqués de Moodle, erreur HTTP), réponse inattendue, aucun export reconnu, licence du jeu de données qui n'est plus la Licence
Ouverte 2.0, redirection refusée, fichier trop gros, fichier incomplet ou abîmé (taille ou empreinte différente de ce que data.gouv.fr
annonce). Chaque phrase dit « Rien n'a été écrit » et, sauf pour la licence (qui dit de le signaler au responsable du plugin), renvoie vers le
dépôt du fichier zip. Pour le réseau, le délai et le certificat, une ligne ajoute **l'hôte et le code cURL, à donner à l'hébergeur** ;
l'erreur de data.gouv.fr donne son code dans la phrase ; l'adresse bloquée renvoie à un réglage de Moodle, pas à l'hébergeur. Jamais le texte d'une exception, d'un en-tête ou d'un chemin.

### 1.7 Ce que la voie B envoie, et à qui

C'est **la seule requête que le plugin fait de lui-même**, et elle ne part que d'un clic d'administrateur.

- **Deux hôtes écrits dans le code, jamais d'autres** : `www.data.gouv.fr` (qui dit quel fichier est le dernier, et vérifie que le jeu de
  données porte toujours la Licence Ouverte 2.0) et `static.data.gouv.fr` (qui sert le fichier). En https, certificat vérifié (jamais
  désactivé). Aucune adresse ne vient d'une saisie ou d'un réglage : la première est reconstruite à partir d'une constante et de l'identifiant
  contrôlé de la ressource, et la seule autre que le plugin suive est **exactement** celle que l'API a annoncée pour ce fichier (validée
  d'avance : https, `static.data.gouv.fr`, chemin sous `/resources/`, nom du fichier).
- **Les redirections sont jugées à la main** avant d'être suivies : trois au plus, et seulement vers l'adresse annoncée pour ce fichier,
  écrite à l'identique (une adresse de la même forme, mais pas celle-là, est refusée).
- **Plafonds**, comptés **sur les octets écrits** : réponse de l'API 512 Kio, fichier zip 32 Mio. Le fichier s'arrête de grandir au plafond,
  quelle que soit la longueur annoncée ou son absence, et rien n'est décompressé en route. Délais de 10 secondes pour se connecter, 20
  pour l'API, 120 pour le fichier ; taille et empreinte SHA-1 annoncées par l'API contrôlées à l'arrivée.
- **Aucune donnée sur personne ne part.** La requête montre l'adresse IP du serveur (ou de son proxy), l'heure, et un User-Agent fixe, écrit
  dans le code, qui ne donne pas l'adresse du site (celui que Moodle met par défaut la donnerait). La liste d'hôtes bloqués et le proxy de Moodle
  s'appliquent en plus.
- **Le fichier zip entier, fichiers de partenaires compris, transite par le serveur le temps de la requête**, sans être lu ni gardé : il est
  écrit dans le dossier de requête de Moodle et effacé. Il n'est jamais copié dans le dépôt ni dans la distribution du plugin.
- **Jamais planifié** : rien dans `db/tasks.php` ni dans `classes/task/` ne l'appelle. C'est la mesure compensatoire MC-30 (« une seule porte
  sortante »), contrôlée par `scripts/sortie-reseau.sh` (`make sortie`) et par un test miroir (`tests/fiche_no_background_test.php`).
- **Une trace à chaque requête**, aboutie ou refusée (événement `export_fetched` : ressource, titre, taille, empreinte SHA-256, résultat), et une
  par fiche écrite (événement `record_imported` : code, voie, date de l'export, empreinte SHA-256 du fichier zip, compteurs). Ni libellé, ni
  texte d'exception, ni donnée de vaeiste, et pas d'autre nom de fichier que le titre de l'export.

## 2. Les données de France compétences

Le jeu « Répertoire national des certifications professionnelles et répertoire spécifique », publié par France compétences sur data.gouv.fr :
<https://www.data.gouv.fr/datasets/repertoire-national-des-certifications-professionnelles-et-repertoire-specifique>

Le fichier zip à prendre est **`export-fiches-csv-AAAA-MM-JJ.zip`** (9 678 253 octets le 19 octobre 2026, 9 704 180 le 27 ; mis à jour
chaque jour). Les autres fichiers de la page (`export-fiches-rncp-…`) ne conviennent pas. Il contient dix CSV, un par thème ; deux servent ici :

| Fichier de l'export | Colonnes lues |
|---|---|
| `export_fiches_CSV_Blocs_De_Compétences_AAAA_MM_JJ.csv` | `Numero_Fiche`, `Bloc_Competences_Code`, `Bloc_Competences_Libelle` |
| `export_fiches_CSV_Standard_AAAA_MM_JJ.csv` | `Numero_Fiche`, `Intitule`, et, lues telles que publiées mais jamais exigées, `Actif` et `Date_Fin_Enregistrement` |

Format constaté (exports des 19 et 27 octobre 2026) : séparateur point-virgule, valeurs entre guillemets, UTF-8, fins de ligne CRLF (des
retours à la ligne simples apparaissent à l'intérieur de certains textes entre guillemets du fichier Standard). **Ni l'un ni l'autre de ces deux
exports ne porte de marque d'ordre des octets** : le premier octet de chaque fichier est le guillemet ouvrant du premier en-tête. (La version
précédente de ce document disait l'inverse pour l'export du 19 octobre : c'était inexact.) La page et le script lisent les deux cas, avec ou
sans marque. Dans le fichier zip du 19 octobre, les fichiers intérieurs portent le 18 ; dans celui du 27, le 26.

**L'export ne donne, pour un bloc, que son code et son libellé** : pas la liste de ses compétences. Une fiche est donc importée en **blocs**,
une ligne par bloc.

Deux constats sur l'état d'une fiche, mesurés sur ces deux exports :
- `Actif` vaut `ACTIVE` ou `INACTIVE` (6 559 et 24 115 le 19 octobre) ; `Date_Fin_Enregistrement` est `jj/mm/aaaa` ou vide. L'écran les affiche
  tels quels, sans les interpréter et sans rien empêcher.
- **Aucune fiche RS n'a de bloc** dans ces exports ; **6 094 fiches inactives en ont** (6 120 le 27 octobre) et **s'importent** sans un mot : seul
  l'état affiché à l'aperçu (« Indiquée inactive par France compétences dans cet export ») les signale.

### 2.1 Les liens (relevés le 27 octobre 2026)

- Page du jeu de données (à ouvrir à la main, pour déposer le zip) : <https://www.data.gouv.fr/datasets/repertoire-national-des-certifications-professionnelles-et-repertoire-specifique>
- Ce que le bouton « Aller chercher » interroge (API de data.gouv.fr, JSON, jeu `5eebbc067a14b6fecc9c9976`) : <https://www.data.gouv.fr/api/2/datasets/5eebbc067a14b6fecc9c9976/> et, pour les ressources d'un mois, `.../resources/?q=export-fiches-csv-2026-10-&page_size=100&page=1`.
- Lien stable d'un fichier par son identifiant : `https://www.data.gouv.fr/api/1/datasets/r/<identifiant>` (celui du 27 octobre : `c302e089-f5c6-4e52-a685-c877e3a6cdf5`), qui renvoie vers le fichier sur `static.data.gouv.fr`.
- Fichier du 27 octobre 2026 : <https://static.data.gouv.fr/resources/repertoire-national-des-certifications-professionnelles-et-repertoire-specifique/20261027-020002/export-fiches-csv-2026-10-27.zip> (9 704 180 octets). L'adresse change chaque jour : ne la copiez pas, prenez-la sur la page du jeu de données.

**Les accents « abîmés » dans Excel ne sont pas un défaut des fichiers.** Les CSV de l'export sont en UTF-8 sans marque d'ordre des octets : Excel les lit en Windows-1252 et affiche « Ã© » pour « é ». Le plugin lit bien l'UTF-8. Pour regarder un fichier dans Excel : *Données, À partir d'un fichier texte/CSV*, origine « Unicode (UTF-8) », séparateur point-virgule. **Ne l'enregistrez jamais depuis Excel avant de le donner au plugin** (les accents seraient réellement abîmés, et le plugin refuserait le fichier, qui n'est plus en UTF-8).

### 2.2 Trouver le bon fichier, puis la bonne fiche

1. **Le bon fichier** : sur la page du jeu de données (§ 2.1), prenez **`export-fiches-csv-AAAA-MM-JJ.zip`**, le plus récent (la date est dans le nom). Il contient toutes les fiches, une fois : ce n'est pas un fichier par qualification. Ne prenez ni les fichiers `export-fiches-rncp-…`, ni un fichier d'un autre jeu (par exemple le jeu ROME des métiers, qui n'est pas celui des certifications). Ne le décompressez pas : le plugin lit le zip tel quel.
2. **La bonne fiche** : le plugin demande un **numéro** (`RNCP` ou `RS` suivi de chiffres), jamais un nom. Pour le trouver, cherchez la qualification par son intitulé sur le site de France compétences (recherche RNCP : <https://www.francecompetences.fr/recherche/rncp/>, adresse non vérifiée) et relevez le numéro de **la fiche en vigueur**. Une même qualification a eu plusieurs fiches successives : seule la fiche en vigueur (état « active », fin d'enregistrement dans le futur) vaut ; un ancien numéro est refusé ou se présente comme « inactive » à l'aperçu.
3. **Contrôler avant de confirmer** : l'aperçu montre l'intitulé publié en entier. **Un numéro faux, mais qui existe, importe une autre certification sans aucun message d'erreur** : lisez l'intitulé. Une fiche du Répertoire spécifique (RS) ou une fiche sans bloc ne s'importe pas depuis cet export (le message le dit).

## 3. La voie de secours : la ligne de commande

**Quand l'employer** : sur un poste qui a PHP mais pas de site (la page n'y est pas), pour **préparer un fichier que l'on veut relire avant
de l'importer** par l'écran CSV ; ou quand la page ne peut pas lire l'export (hébergement trop juste en mémoire ou en délai, format qui a
changé : l'écran le dit et demande de le signaler au responsable du plugin). Ce n'est plus le chemin ordinaire : il demande d'extraire des
fichiers et de retaper neuf champs. Le convertisseur est **le même** que celui de la page (`fiche_converter`) : mêmes règles, mêmes refus.

1. Télécharger le fichier zip (§ 2) et en **extraire les deux fichiers** utiles dans un dossier **hors du dépôt**.
2. Convertir une fiche :

   ```bash
   php scripts/convertir-fiche-rncp.php blocs.csv standard.csv RNCP40692 deas.csv
   ```

   Le script écrit `deas.csv` (colonnes `rang`, `intitule`, `description`, lisibles par l'écran CSV) et **affiche les valeurs à saisir** :
   code de la fiche, type, intitulé, source, licence, paternité (« France compétences », avec accent depuis la 0.47.0) et la date de
   l'export.
3. Ouvrir l'écran **« Importer un fichier CSV (cas particulier) »** (droit `tool/vae:import`) et y saisir les **neuf champs** : le
   fichier, son encodage (UTF-8), le code, le type, l'intitulé, la date de mise à jour de la source (**celle de l'export téléchargé**, la
   date du nom du fichier), la source, la licence et la paternité.

Ce que fait le convertisseur, et ne fait pas :
- le rang d'un bloc est la fin de son code (`RNCP40692BC03` donne `BC03`) ;
- **aucun libellé n'est reformulé ni coupé** (la licence interdit de tromper sur le contenu) ;
- un libellé de **100 caractères ou moins** passe tel que publié (les blancs autour sont retirés) ;
- un libellé de **plus de 100 caractères** ne peut pas être celui d'une compétence du cœur (colonne de 100 caractères) : la ligne reçoit le
  titre neutre `Bloc BC03` et **le libellé publié, en entier, va dans la description**. Le plugin **montre alors le libellé publié**, pas le
  titre neutre (liste de déclaration, couverture, dossier, archive) ;
- **un libellé de 100 caractères ou moins qui contient « < »** est traité de même. Le cœur lit le titre d'une compétence comme du texte brut
  et le coupe à un « < » qu'il prend pour une balise : il refuserait ce qu'il aurait à changer. C'est le cas réel de `RNCP35292BC04`
  (« (<10m) ») : titre `Bloc BC04`, libellé publié entier en description ;
- un intitulé de fiche de plus de 100 caractères (ou qui contient « < ») ne peut pas être le nom du référentiel : le code (`RNCP36836`) en
  tient lieu, et le script donne l'intitulé complet à garder de côté ;
- une fiche **sans bloc** dans l'export ou un code de bloc d'une autre forme est **refusé par son nom**, et rien n'est écrit.

## 4. Les six fiches médico-sociales, d'après les exports des 19 et 27 octobre 2026

| Diplôme | Fiche en vigueur | Blocs | Libellés de plus de 100 caractères | Fin d'enregistrement |
|---|---|---|---|---|
| DEAS (aide-soignant) | `RNCP40692` | 5 | BC03, BC04, BC05 | 01/09/2029 |
| DEAES (accompagnant éducatif et social) | `RNCP42423` | 5 | BC02, BC05 | 31/08/2027 |
| DEES (éducateur spécialisé) | `RNCP41747` | 4 | BC03 | 31/08/2031 |
| DEME (moniteur-éducateur) | `RNCP39643` | 3 | BC03 | 31/12/2028 |
| CAFERUIS | `RNCP36836` | 4 | aucun ; **intitulé de la fiche de 110 caractères** | 31/08/2027 |
| CAFDES | `RNCP36838` | 4 | BC01 ; **intitulé de la fiche de 111 caractères** | 31/08/2027 |

Relevé en lecture seule sur les deux exports : **les six fiches donnent les mêmes blocs, mot pour mot, le 19 et le 27 octobre**, avec les
mêmes intitulés et les mêmes fins d'enregistrement.

**Deux fiches ont un intitulé de plus de 100 caractères : le CAFERUIS (110) et le CAFDES (111).** Moodle ne peut pas porter un nom de
référentiel aussi long : le référentiel de chacune porte **son numéro pour nom** (`RNCP36836`, `RNCP36838`). L'aperçu montre l'intitulé
complet ; il n'est pas enregistré dans Moodle (décision à prendre plus tard : 0.47.1, peut-être).

**La paternité enregistrée sur les six lignes déjà importées est « France competences », sans accent**, alors que l'écran écrit « France
compétences ». L'import réécrit la licence, la paternité et l'adresse de la source à chaque passage : la paternité sera donc réécrite, avec
l'accent, au prochain import de chacune. (Relevé fourni pour cette mise à jour ; non refait ici sur l'instance.)

À vérifier à chaque republication : les numéros changent quand une fiche est remplacée (le DEAS et le DEAES ont eu
plusieurs fiches successives). Le fichier « `Ancienne_Nouvelle_Certification` » de l'export donne les remplacements ;
dans le plugin, c'est T-30 (« Fiches remplacées ») qui porte le passage des déclarations d'une fiche à l'autre.

**Les fiches de 2015.** Le DEES de 2015 (`RNCP2348`) et le CAFDES de 2015 (`RNCP367`), qui reposaient sur le référentiel
de 2007, sont **inactifs et sans bloc** dans l'export : ils ne peuvent pas être importés par ce moyen (l'écran et le script
les refusent par leur nom). Les fiches en vigueur sont celles du tableau.

**Un cas réel de « < ».** `RNCP35292` (inactive, fin d'enregistrement 20/12/2023, 6 blocs) a un bloc, `BC04`, dont le libellé contient « (<10m) » :
il est importé avec le titre `Bloc BC04` et son libellé publié entier en description (§ 3).

## 5. Ce qui reste à la main

- **Avec l'écran à deux voies, il n'y a que les numéros à saisir.** Seule la voie de secours demande de saisir les valeurs de l'écran CSV
  (le script les affiche).
- **Lire chaque intitulé à l'aperçu, avant de confirmer.** Un numéro faux, mais qui existe, importe une autre certification sans aucun
  message d'erreur : l'intitulé publié est la seule protection. Avec la voie de secours, le fichier converti ne contient pas le code de la
  fiche : le script l'affiche à part et vous le retapez à l'écran CSV, sans aucun contrôle (l'import ne peut pas comparer le fichier au
  code). Dans les deux cas, après l'import, ouvrez le référentiel dans Moodle (Administration du site, Compétences, Référentiels de
  compétences) et vérifiez que son code et ses blocs sont bien ceux du diplôme voulu.
- **Une fiche qui n'est pas prête : la cacher aussitôt après l'import** (« Visible : Non », dans le même écran de Moodle). Tant que le
  référentiel est caché, le plugin refuse d'y déclarer une activité et d'y reporter une déclaration ; le vaeiste lit un message écrit pour
  lui (l'intitulé et le code de la fiche, qu'elle est cachée et qu'on ne peut pas y déclarer pour le moment, de s'adresser à son responsable
  ou à l'organisme qui l'accompagne). La page de déclaration nomme la fiche cachée au lieu de la faire disparaître de la liste. Un formulaire
  ouvert avant que la fiche soit cachée, puis envoyé, est refusé avec ce message et redonné avec ce que le vaeiste avait écrit, sans rien
  enregistrer ; sa liste de compétences commence alors par « Choisir… », aucune n'étant présélectionnée. Si toutes les fiches sont cachées, il
  n'y a plus de formulaire : le texte écrit est redonné dans des champs en lecture seule, à copier. L'adresse d'un report ouverte sur une fiche
  remplaçante cachée renvoie au dossier avec le même message ; un formulaire de report déjà ouvert est redonné avec son texte et le message.
  Ce qui était déjà déclaré reste. Un administrateur ne peut pas non plus l'essayer avec un compte
  d'essai de vaeiste : pour l'essayer, la rendre visible.
- **Une fiche importée par erreur** (par exemple le fichier d'un diplôme importé sous le code d'un autre), avant que quiconque déclare.
  Trois temps. 1. La cacher. 2. Supprimer le référentiel dans Moodle, si Moodle l'accepte : il refuse tant qu'un cours, un plan, un modèle
  de plan, un badge ou une déclaration s'appuie sur l'une de ses compétences, et il emporte avec lui toutes ses compétences, y compris celles
  qui y auraient été ajoutées à la main (lu dans le code de Moodle, non rejoué). 3. Retirer la ligne de la fiche avec le bouton « Retirer
  cette ligne » de la page « VAE - cheminement » (offert à qui détient le droit `tool/vae:import`, libellé « Importer des fiches de
  certification (fichier CSV, ou export de France compétences déposé ou récupéré sur data.gouv.fr), et retirer la ligne d'une fiche importée
  par erreur »). Le bouton n'apparaît que lorsque Moodle ne détient plus le référentiel de la fiche, et trois causes l'écartent. Les deux premières
  ont chacune leur phrase sur la page des fiches, sous la liste des fiches sans référentiel : (a) un vaeiste a laissé une trace sur la fiche (une
  déclaration, une marque « Hors de mon activité » ou une rubrique de la préparation du dossier de validation) ; (b) un référentiel de Moodle
  porte déjà ce code (fait à la main, par exemple, et même caché) : le supprimer d'abord. (c) Le droit d'importer manque, ou un autre cas : la
  page ne donne alors que la phrase commune à toutes les lignes sans référentiel (le référentiel n'existe plus dans Moodle ; importer de
  nouveau la fiche), sans parler de bouton. Un écran de confirmation liste ce qui part avec la ligne : licence et paternité de
  la source, dates, marques de compétences absentes de la fiche ou modifiées hors de l'outil, adresses des règles de validation, référents de bloc, correspondances entre
  compétences, fiche remplaçante. La ligne ne se récupère pas ; importer de nouveau la fiche en crée une neuve. Dès qu'un vaeiste a déclaré
  sur la fiche, Moodle refuse la suppression du référentiel et le plugin refuse de retirer la ligne : la fiche reste, cachée.
- **Si seul l'export ou le fichier était faux** (le code était bon) : réimporter le bon export ou le bon fichier ne supprime rien, les compétences en trop restent dans
  Moodle, marquées comme sorties de la fiche. La voie propre est de supprimer le référentiel (mêmes conditions) puis de réimporter : l'import
  reprend la ligne laissée par la suppression.
- **Un diplôme dont la fiche a changé de numéro** : importer le **nouveau** numéro (l'écran de résultat y renvoie par un lien), puis
  décider de déclarer le remplacement sur la page « Fiches de certification remplacées » (T-30).
- **Un hébergement qui ne laisse pas sortir le serveur** : la voie B échoue avec un refus nommé ; déposer le fichier zip (voie A), ou demander
  à l'hébergeur d'ouvrir le https sortant vers `www.data.gouv.fr` et `static.data.gouv.fr`. L'hôte et le code à lui donner sont dans le message.
  Ne pas assouplir la « Sécurité HTTP » de Moodle pour ce seul besoin : déposer le fichier suffit.
- Les compétences à l'intérieur d'un bloc ne sont pas structurées : l'export ne les donne pas. Une fiche plus fine
  demanderait un autre fichier, au format de l'import à quatre colonnes (T-33, colonne `bloc`), préparé à la main.

## 6. Adresses de référence d'une fiche (T-41)

Depuis la 0.40.0, l'administrateur peut saisir, par fiche, des adresses de référence (fiche RNCP, arrêté du diplôme sur Légifrance) avec,
s'il le dit, le jour où il a vérifié la page. **Le plugin ne les calcule pas, et l'import ne les remplit pas.** Modèle de l'adresse de la fiche RNCP, **non vérifié** (à ouvrir et contrôler
à la main avant de le saisir) : `https://www.francecompetences.fr/recherche/rncp/<numéro sans « RNCP »>/`, par exemple `.../rncp/40692/` pour
RNCP40692. L'export ne donne pas d'adresse ; il donne, pour deux fiches, l'intitulé d'un arrêté (RNCP40692, RNCP41747), à chercher sur
Légifrance. Le champ `PREREQUIS_VALIDATION_CERTIFICATION` (AFGSU 2) n'est pas repris par le plugin.

## 7. Ce qui n'est pas vérifié (27 octobre 2026)

- **La voie B n'a jamais été jouée contre le vrai data.gouv.fr avec ce code.** Les observations de l'API (filtre par préfixe de mois, champs
  de taille et d'empreinte, licence `lov2`, une redirection de `www` vers `static`) datent du 27 octobre 2026 et ne sont pas des garanties :
  si l'API change, la voie B échoue avec un refus nommé et la voie A reste.
- **L'export du 27 octobre n'est pas passé par la page.** Les scénarios Behat de l'écran (voie A, refus, export plus ancien, droit) sont
  verts en local, mais sur des fichiers zip inventés de la même forme que les exports réels ; ils ne cliquent jamais la voie B (pas de réseau).
  Les mesures du § 2 et du § 4 ont été prises en lisant les fichiers zip hors de la page. Le protocole de preuve à la main est dans
  `docs/BANC-ESSAI.md`.
- **L'hébergement réel du porteur** : sortie réseau, limite d'envoi de fichiers, mémoire, délai maximal d'exécution, proxy. La croissance
  de la mémoire pour lire l'export réel du 19 octobre (les deux fichiers de 6 Mo chacun sont tenus en mémoire) a été mesurée à environ
  19 Mo, en ligne de commande sans site, pas dans une requête web.
- **La lecture de l'écran par une personne**, au clavier et au lecteur d'écran. PHPDoc et JavaScript ne se jouent que par l'intégration continue.
- La paternité des six lignes déjà importées (§ 4) est un relevé fourni, non refait sur l'instance. Pendant la voie B (au plus 120 secondes
  pour le fichier ; durée réelle non mesurée), la session de l'administrateur reste verrouillée : ses autres onglets attendent (lu dans le code,
  non mesuré).
