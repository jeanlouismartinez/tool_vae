# Questions fréquentes, par rôle

> Ce guide dit ce que le plugin « VAE - cheminement » (`tool_vae`, dépôt `moodle-tool_vae`) fait **à ce jour** (version 2.5.03 (Moodle 5.3),
> build 2026110404). Il ne remplace pas le certificateur : le plugin aide à **constituer et mettre en forme** un dossier,
> il ne juge pas, ne calcule aucun verdict et ne dit jamais si un dossier est recevable (D-1).
> Les mots difficiles sont dans le [glossaire du README](../README.md#glossaire).

## Vous êtes vaeiste

**Qu'est-ce que je peux faire ici ?**
Déclarer ce que vous faites dans votre activité (une « déclaration » rattachée à une compétence d'une fiche
de certification), rédiger les rubriques de votre dossier de faisabilité, déposer ce dossier, lire ce que
votre accompagnant en pense, répondre à ses questions, et exporter votre dossier (l'archive n'en reprend pas tout : voir « Comment
récupérer mon dossier ? »).

**Qui lit ce que j'écris ?**
Vos **brouillons** et vos **versions** ne sont lus que par vous et par l'administrateur du site ; la direction ou un
responsable ne les lit que si le site lui a donné le droit `tool/vae:draftview`, que personne n'a par défaut. La préparation
du dossier de validation et le journal réflexif ne sont lus que par vous. Un responsable qui vous suit lit vos
déclarations (pour les attester) et le **jalon** que vous déposez, et vos brouillons seulement avec ce droit.

**Puis-je écrire le nom d'une personne que j'accompagne, ou d'un résident ?**
Non. Décrivez ce que **vous** avez fait, pas qui en a bénéficié. Un prénom fictif est toléré. Le rappel est
affiché près de chaque champ libre et ne se masque pas : c'est un des trois contrôles du plugin.

**Mon brouillon est-il sauvegardé tout seul ?**
Oui, toutes les 20 secondes environ. Un brouillon n'est **pas** une version : pour garder un état, utilisez
« Enregistrer une version ». Chaque version se compare à la précédente, mot à mot, et se restaure comme
une nouvelle version.

**Que se passe-t-il quand je dépose ?**
Le plugin fige ce que vous avez **enregistré** (pas les brouillons) dans un jalon daté et empreinté. Vous
pouvez continuer à écrire ; un nouveau dépôt est un nouveau jalon. Vos responsables sont prévenus, sans le
contenu. Rien n'est déposé si rien n'a changé depuis le dernier dépôt.

**Un responsable m'a posé une question sur une déclaration. Dois-je répondre ?**
Rien ne vous y oblige, et l'attestation n'attend pas. Mais la réponse est un texte à côté de votre
déclaration, daté, qui éclaire le responsable. Vous répondez **une fois** ; la réponse ne se modifie pas.

**Je me suis trompé dans une déclaration.**
Une déclaration attestée ne se modifie pas. Demandez le retrait de l'attestation à votre responsable ou à
la direction, puis déclarez de nouveau. Cela ne corrige pas une ligne dont le format est inattendu, signalée par un avertissement à
l'export : l'ancienne déclaration reste dans le dossier et l'avertissement revient (voir plus bas).

**Une attestation de mon dossier est marquée « n'est plus valable » : que faire ?**
Une attestation a été retirée de l'une de vos déclarations, par exemple parce que les données de la personne qui l'avait signée ont été
effacées. Votre dossier, son PDF et ses copies Word ou OpenDocument disent, pour chaque attestation retirée, qu'elle a existé et qu'elle a été retirée
à telle date, avec le motif, et que cette attestation n'est plus valable. Si aucune autre attestation n'est en vigueur sur la déclaration, ils ajoutent
que la déclaration n'a pas été modifiée (elle reste dans votre dossier) et que vous pouvez demander à un responsable de l'attester de nouveau ; si un
responsable l'a déjà attestée de nouveau, ils montrent cette nouvelle attestation et gardent seulement la mention que la précédente n'est plus valable,
sans cette invitation. Quand un effacement retire des attestations, vous recevez un seul message (« Des attestations ont été retirées de votre
dossier », ou « Une attestation a été retirée de votre dossier » s'il n'y en a qu'une), environ quinze minutes après le dernier effacement
qui vous concerne ; il nomme les responsables qui vous suivent et peuvent de nouveau attester (s'il n'y en a aucun, il vous renvoie à l'administrateur du
site). Les responsables qui vous suivent reçoivent un message qui dit qu'une ou plusieurs de vos déclarations attendent une attestation.

**Puis-je joindre un document à une déclaration ?**
Si l'administrateur a ouvert les pièces justificatives : oui, **un fichier** (PDF, ODT, DOCX, JPEG, PNG ; la taille maximale est annoncée sur la page de dépôt, 5 Mo par défaut)
**ou un lien**, avec ce qu'il est (bulletin de paie, contrat, diplôme…). **Masquez le nom et le visage de toute personne
accompagnée avant de le joindre** : le plugin ne peut pas lire un document ni vérifier qu'il ne nomme personne, et un
document garde aussi son auteur, ses dates et, pour une photographie, parfois sa position. Le nom de votre fichier n'est
pas conservé. Le responsable qui atteste peut ouvrir la pièce. Vous pouvez la **retirer** à tout moment : le fichier et
l'intitulé sont effacés, mais pas une copie déjà téléchargée ni une archive déjà remise à un jury. Une pièce jointe
**après** l'attestation est marquée comme telle : la signature ne la couvre pas.

**Comment récupérer mon dossier ?**
Les boutons de copie modifiable (Word, OpenDocument) ; le bouton d'export pour l'archive figée, avec le
PDF imprimable, à remettre au jury. L'archive ne contient **jamais** votre identité (date de naissance,
nationalité, statut) : elle sert à la preuve, pas à l'état civil. Elle ne contient pas non plus les dépôts de votre dossier de faisabilité,
les avis de vos responsables, ni les questions de précision et vos réponses : ils restent sur la plateforme. Vous les retrouvez dans l'export
de vos données personnelles de Moodle (« Demandes de données » dans votre profil si l'administrateur a ouvert cette possibilité, ou à défaut
en la demandant à l'administrateur du site), qui se lit mais ne se réimporte pas.

**L'export affiche un avertissement sur une ligne dont le format n'est pas celui attendu : que faire ?**
L'archive a bien été produite et son PDF est utilisable : vous pouvez les remettre au jury. L'avertissement dit seulement que cette archive ne
pourra pas être réimportée telle quelle. Vous ne tapez jamais ce numéro vous-même : la ligne vient d'une déclaration déjà enregistrée avec un rang de
compétence hors format (par exemple « c7 » au lieu de « C7 »), par une version antérieure du plugin ou écrite directement dans la base de données ; une
déclaration nouvelle sur un tel rang est refusée. Cela ne se corrige pas depuis Moodle : corriger le numéro d'identification de la compétence ne réécrit
pas la déclaration déjà enregistrée. Prévenez l'administrateur du site. L'avertissement revient à chaque export tant que la déclaration existe, et
déclarer de nouveau ne l'efface pas. La copie modifiable du dossier (Word ou OpenDocument) reste possible. Deux cas restent refusés, avec un message qui le
dit : un dossier trop volumineux pour être relu par la plateforme, et une pièce dont le fichier est perdu ou altéré ; la copie modifiable reste
possible dans ces deux cas aussi.

**Que devient mon dossier si je pars ?**
L'effacement du compte emporte le dossier. Une copie de sauvegarde que vous avez demandée est retirée aussi. Si vous changez de plateforme,
l'administrateur de la nouvelle plateforme peut reprendre votre archive, à deux conditions : que la nouvelle plateforme n'ait pas déjà numéroté autrement
les compétences que l'archive nomme (ce qui arrive par exemple dès qu'un dossier est sorti d'elle sur ces compétences : archive, copie Word ou
OpenDocument, sauvegarde), et que votre compte n'y ait pas déjà d'identifiant (il le reçoit dès sa première déclaration, son premier export, sa première
copie ou sa première sauvegarde) ; sinon l'import est refusé en entier, avec un message qui dit pourquoi, et il faut refaire vos déclarations et les
signatures de vos responsables sur la nouvelle plateforme (l'archive et son PDF restent une pièce de votre dossier). Les dépôts, les avis et les
questions de précision ne suivent pas : demandez l'export de vos données personnelles de Moodle avant de partir.

**Où trouver la règle de validation de mon diplôme ?**
Si l'administrateur a ouvert la fonction, votre dossier montre, dans un seul encadré au-dessus du tableau de couverture, les pages où le certificateur publie sa règle (fiche du répertoire, arrêté). Le plugin ne cite rien, n'applique pas la règle et ne dit pas si votre dossier la satisfait : lisez la page vous-même, la décision revient au jury.

**Une compétence de ma fiche ne relève pas de mon activité : puis-je le dire ?**
Oui, si le site a ouvert la fonction : *Hors de mon activité* sur votre dossier. C'est votre parole, avec une note facultative d'une ligne (sans nom de personne accompagnée). Elle n'exempte de rien : le jury décide si une compétence non exercée compte, et pour certains diplômes elle compte. Vous pouvez la retirer à tout moment.

**Mon employeur peut-il écrire une lettre pour mon dossier ?**
Oui, et le plugin la prend comme une **pièce justificative** : joignez-la à la déclaration qu'elle éclaire, nature *attestation de l'employeur*. Elle doit **décrire** votre activité, sans évaluer et sans proposer quoi que ce soit au jury, et ne nommer aucune personne accompagnée. Un modèle est dans [docs/MODELE-LETTRE-EMPLOYEUR.md](MODELE-LETTRE-EMPLOYEUR.md) (à faire relire par un accompagnateur VAE avant de le donner).

**Puis-je échanger avec d'autres vaeistes dans le plugin ?**
Non, volontairement : un vaeiste ne lit jamais le dossier d'un autre, et le plugin ne propose pas d'échange entre vaeistes. Un site peut créer un forum Moodle d'entraide, **modéré**, hors du plugin ; la consigne à y afficher est dans [docs/CONSIGNE-FORUM-ENTRAIDE.md](CONSIGNE-FORUM-ENTRAIDE.md).

**Puis-je demander à une intelligence artificielle de reformuler mon texte ?**
Seulement si le site a ouvert cette aide et vous en a donné le droit : le bouton *Proposer une autre formulation* paraît alors sous le champ de votre texte (rubriques de texte du dossier de
faisabilité, préparation du dossier de validation, situation d'une déclaration ; **jamais** le journal réflexif). Vous lisez d'abord la politique d'IA du site, celle de Moodle (une fois pour tout le site : si vous l'avez déjà acceptée dans un éditeur, elle n'est pas redemandée), puis, **à chaque envoi**,
le texte exact qui va partir, chez qui, et ce qui est conservé (l'écran dit si Moodle efface ce texte après la suppression de votre compte ou, sur un site où
il ne le fait pas de lui-même, de le demander à l'administrateur). La proposition s'affiche à côté de votre texte, **à relire** : elle peut changer un fait. **Rien n'est enregistré** dans votre
dossier : vous copiez ce qui vous convient et vous enregistrez vous-même. Ne citez aucune personne accompagnée ou soignée : ce qui est envoyé quitte la plateforme. Ne pas utiliser l'aide ne
change rien à votre dossier.

**Où lire où en est la structure sur mes compétences et sur mes blocs ?**
Sur votre dossier, dans la section « Positionnement de la structure », sous chaque fiche où vous avez déclaré au moins une fois. Elle suit l'arbre de la fiche, pas vos
déclarations : chaque bloc montre sa certification et chaque compétence son évaluation, ou « Pas encore de positionnement ». Vous y lisez le statut, qui l'a écrit (son nom,
la date et l'heure ; « une personne dont le compte n'existe plus » si ce compte a été effacé) et le motif écrit. Pour un bloc s'y ajoutent la date de la décision, le dernier jour
de validité s'il y en a un et le motif de l'alerte s'il y en a un (voir la partie direction). Une évaluation porte l'un de ces statuts : « à construire », « en cours de preuve »,
« suffisante pour certification » ou « retirée » ; une certification est « certifié par la structure » ou « retiré ». Un statut retiré s'affiche comme « Pas encore de positionnement »,
et la ligne reste dans l'historique. Le lien « Historique » ouvre toutes les lignes : celle qui fait foi d'abord, puis les lignes antérieures. Ce ne sont ni des notes ni des
pourcentages, et le plugin n'en calcule aucun. Vous n'y écrivez rien. Quand la structure y écrit une ligne (évaluation ou certification), vous recevez un message automatique (« Une ligne a été ajoutée à votre positionnement »), par la messagerie de Moodle et par courriel : il dit seulement qu'une ligne a été ajoutée, rappelle que ce n'est pas une validation, renvoie à votre dossier par un lien et indique le délégué à la protection des données du site pour demander une rectification, une limitation ou une opposition. Il **ne dit ni le statut, ni le motif, ni qui a écrit** : ouvrez votre dossier pour le lire. Il part par la tâche asynchrone de Moodle (donc avec un léger délai), seulement si vous pouvez encore ouvrir votre dossier et si une ligne subsiste ; vous pouvez le désactiver dans vos préférences de notification (« Lignes de positionnement de la structure écrites sur votre parcours »), comme l'administrateur pour tout le site. Pour contester une ligne, adressez-vous au délégué à la protection des données du site, par la clause du contrat d'usage ; il n'existe pas d'écran pour cela. Une rectification passe par une nouvelle ligne écrite par la structure (la ligne ancienne n'est jamais modifiée) ; la limitation et l'opposition passent par le délégué.
*(Non vérifié : ce message est testé par PHPUnit et a été envoyé le 5 novembre 2026 par un dialogue SMTP réel vers un récepteur local de test, mais jamais par le serveur de courriel d'un site réel ; les mots des statuts n'ont pas encore été relus par un accompagnateur VAE.)*

**Mon bloc est « certifié par la structure » : est-ce une validation ?**
Non. C'est un **positionnement de la structure** : la direction écrit, à une date et avec un motif, que la structure se tient derrière ce bloc. Seul le jury valide, **hors de cette
plateforme**, et seule cette validation ouvre l'accès à la qualification du diplôme officiel. Le jury reçoit votre archive et décide seul ; sa décision ne s'enregistre pas dans ce
cycle. Un positionnement ne dit pas non plus si votre dossier est recevable : le plugin ne le dit jamais.

**Mon dossier dit « Valable jusqu'au… » ou « À renouveler » : que faut-il comprendre ?**
En certifiant un bloc, la direction peut donner un dernier jour de validité. Sans date, la certification n'expire pas. Le dernier jour compte encore en entier ; à partir du
lendemain, votre dossier affiche une boîte « À renouveler : Valable jusqu'au… - échéance passée depuis N jour(s), comptés au jour où vous lisez ». Cette mention est **calculée à chaque
lecture**, au jour de la personne qui lit (selon son fuseau horaire) : rien n'est écrit en base, rien n'est envoyé, et la certification reste la ligne qui fait foi. Elle dit seulement qu'une
date est passée, rien de la qualité de votre dossier. Vous n'avez pas de bouton « Renouveler » : c'est la direction qui ajoute une nouvelle ligne de certification, et la boîte disparaît. Vos exports
(archive, PDF, copies) disent « valable jusqu'au… » et jamais « à renouveler » : rien n'y est calculé.

**Mon dossier dit « Positionné sur l'ancienne fiche… » : qu'est-ce que c'est ?**
La fiche sur laquelle vous avez déclaré en remplace une autre, et l'administrateur a décidé qu'une compétence (ou un bloc) de l'ancienne fiche correspond à une compétence (ou un bloc)
de la nouvelle. Sous celle-ci, une ligne en lecture seule rappelle ce que la structure avait écrit sur l'ancienne, par exemple « Positionné sur l'ancienne fiche… (C3) : suffisante pour
certification, <date> », avec la date de décision et le dernier jour pour une certification. Rien n'est copié, reporté ni modifié : la ligne reste sur l'ancienne fiche, et il n'y a pas de
positionnement sur la nouvelle tant que la structure n'en a pas écrit un. On ne remonte que d'**une fiche** (jamais de A à C en passant par B), et seule la ligne qui fait foi est montrée,
si ce n'est pas un retrait. Si cette ligne n'apparaît pas, c'est qu'une de ces conditions manque (voir la partie administrateur).

**Mes positionnements partent-ils dans l'archive, le PDF et les copies ?**
Oui, dès qu'il existe au moins une ligne. L'archive contient `positionnement.json`, avec **toutes** les lignes (celle qui fait foi est marquée) et leur motif, sous la phrase
« Positionnement de la structure, non validation… ». Chaque ligne y porte un identifiant opaque de son auteur, pas son nom, et rien si cet auteur a été effacé. Le PDF et les copies
Word et OpenDocument ont une section « Positionnement de la structure (ce n'est pas une validation) ». Le fichier part dans toute archive du dossier, y compris celle de la direction et la
sauvegarde conservée sur la plateforme. Une correction faite après un export ne change pas l'archive déjà produite. Réimportée, l'archive restaure les lignes de chaque couple fiche-rang que le
site ne porte pas encore (un couple qui a déjà une ligne ici est laissé tel quel), dans l'ordre, avec la mention « restaurée depuis une archive » ; un plugin plus ancien que celui qui a écrit
le fichier réimporte les déclarations et perd ce fichier sans le dire. Si vous partez, l'effacement de votre compte emporte toutes les lignes qui vous concernent.

**Que montre la colonne « Activités » de « Mon parcours » ?**
Pour chaque compétence des fiches où vous avez déclaré (et, à part, des fiches que vos plans de formation nomment sans déclaration), le plugin liste, à côté des cours, les activités de cours que
le cœur de Moodle a reliées à cette compétence, sous la forme « Cours › Activité (type) », avec un lien quand vous pouvez ouvrir l'activité. C'est l'offre de formation du site, un fait sur le site, jamais une
mesure de vous : le plugin ne dit pas si vous avez fait l'activité ni si elle compte comme preuve, il ne lit pas la règle que l'activité applique à la compétence. Trois mentions à connaître.
« Une activité non visible » : le cœur ne vous la montre pas (cachée, ou sous restriction d'accès ; le cas d'un compte de direction qui lit des activités sans nom est traité dans la partie direction) ; elle garde
sa place sans son nom. « Un cours caché » : le cours n'est pas nommé, et l'activité non plus. « Non lisible avec vos droits » : des activités portent la compétence mais vos droits du cœur ne vous les montrent pas, ce qui n'est pas
« aucun ». Cette page n'est visible que de vous.

## Vous êtes responsable (accompagnant, référent)

**Quels dossiers puis-je lire ?**
Ceux des vaeistes que vous **suivez** : votre rôle doit être attribué dans le contexte de chaque vaeiste
(à la main ou par cohorte). Une capability détenue au niveau du site n'ouvre aucun dossier : le plugin
vérifie l'appartenance lui-même.

**Que puis-je faire sur une déclaration ?**
La **lire**, poser **une question** (une à la fois, tant qu'elle n'est pas attestée), l'**attester**. En
attestant, vous confirmez avoir relu le récit : c'est le troisième contrôle d'anonymisation, le seul fait
par quelqu'un qui n'a pas écrit le texte. Si vous y lisez un nom de personne accompagnée, ne signez pas.

**Puis-je retirer une attestation ?**
Pas directement par défaut. Vous pouvez la demander au signataire (« Demander le retrait »), qui accepte ou
refuse. L'administrateur peut aussi vous laisser retirer **les vôtres** (réglage), ou vous donner
`tool/vae:withdrawany`.

**Que lis-je d'un dossier de faisabilité ?**
Le dernier **dépôt**, ni les brouillons ni l'historique sauf si le site vous a donné `tool/vae:draftview` (personne ne l'a par défaut). L'identité et le statut ne se lisent qu'avec
`tool/vae:identityview`. Vous pouvez y écrire **votre avis** (texte libre, daté, jamais réécrit) si
`tool/vae:writeopinion` vous a été donné. Le plugin ne dit pas si votre avis est favorable.

**Que dois-je faire des pièces jointes à une déclaration ?**
Les **ouvrir avant de signer** : l'écran liste les pièces, et votre signature couvre **exactement** la liste que vous
voyez (si une pièce est ajoutée ou retirée pendant que vous lisez, la signature est refusée et l'écran se redessine).
Il vous demande de cocher que vous en avez pris connaissance : c'est votre **déclaration**, le plugin ne sait pas si vous
avez ouvert les fichiers (il dit seulement combien vous en avez ouverts, et que ouvrir n'est pas lire). Si une pièce
nomme ou montre une personne accompagnée, ne signez pas. Si une pièce est ajoutée après votre signature, vous êtes
prévenu, sans rien de la pièce, et le dossier le marque.

**Suis-je prévenu ?**
Quand un vaeiste déclare (attestation attendue) et quand il dépose, **sans le texte**. Vous ne l'êtes pas
quand un vaeiste répond à votre question : la réponse se lit sur la page de la déclaration. Vous l'êtes aussi quand l'effacement des
données d'un collègue signataire (ou la suppression de son compte, pour une attestation reprise d'une archive) retire des attestations du
dossier d'un vaeiste que vous suivez : un seul message de relance par vaeiste, environ quinze minutes après le dernier effacement qui le
concerne, sans le nom de la personne effacée. Tous ces messages viennent de l'adresse « ne pas répondre » du site (inutile d'y répondre),
ne portent pas le nom du vaeiste dans l'objet et se terminent par une phrase qui dit où agir : ouvrir le dossier avec le lien joint.

**Comment évaluer une compétence d'un vaeiste que je suis ?**
Ouvrez son dossier, section « Positionnement de la structure », et cliquez « Évaluer » à côté de la compétence. Il faut : que vous le **suiviez** (votre rôle attribué dans son contexte, comme pour
lire son dossier), que ce rôle donne `tool/vae:evaluate` (aucun archétype ne le donne), que le vaeiste ait déclaré **au moins une fois sur cette fiche**, et que la ligne soit une **compétence** (une feuille de la fiche) :
un bloc ne s'évalue pas, il se certifie, et c'est la direction qui le fait. Vous n'évaluez jamais votre propre dossier, et l'administrateur du site, qui ne suit personne, n'évalue pas à votre place. Vous choisissez un
statut (« à construire », « en cours de preuve », « suffisante pour certification », « retirée ») et vous écrivez un **motif, obligatoire** (4 000 caractères au plus). C'est le positionnement de la structure sur cette
compétence : une personne le décide, le plugin ne calcule jamais un statut à votre place, et ce n'est pas une validation (le jury est hors de la plateforme). Le plugin refuse un motif vide, de plus de 4 000 caractères ou qui contient des caractères de contrôle, mais il ne peut pas lire ce que vous écrivez : le formulaire affiche, au-dessus du motif,
le rappel d'anonymisation des autres champs libres (« ne nommez aucune personne accompagnée »), et il ne se masque pas.
*(Non vérifié : le sens des quatre statuts n'a pas été relu par un accompagnateur VAE.)*

**Je me suis trompé dans une évaluation, ou un autre responsable a évalué la même compétence : laquelle compte ?**
Une ligne n'est jamais modifiée ni effacée : pour corriger, vous **ajoutez** une ligne, avec son motif. **La dernière ligne fait foi**, quel qu'en soit l'auteur (à égalité de seconde, la dernière enregistrée) ; les
autres restent dans l'« Historique ». Une ligne qui répète la dernière (même statut, même motif) est refusée : « C'est ce que dit déjà la dernière ligne ». Pour retirer une évaluation, choisissez le statut « retirée » : c'est
possible seulement s'il y a une ligne qui fait foi et qui n'est pas déjà un retrait (sinon : « Il n'y a rien à retirer »). Le dossier dit alors « Pas encore de positionnement », et, pour la certification du bloc, une compétence
retirée compte comme **pas encore suffisante**.

**Le vaeiste lit-il mon évaluation, et que faut-il y écrire ?**
Il la lit en entier : statut, votre nom, la date et l'heure, le motif et tout l'historique. Les autres responsables qui le suivent et la direction la lisent aussi. Le plugin ne vous envoie aucun message quand une
évaluation s'ajoute ; il en envoie un au vaeiste, qui dit seulement qu'une ligne a été ajoutée (ni le statut, ni le motif, ni votre nom) ; un événement est tracé dans le journal de Moodle, avec la fiche, le rang et le statut, jamais le motif. Écrivez donc le motif pour qu'il soit lu par la personne qu'il concerne : ce que vous avez constaté et ce qui
manque encore, sans nom de personne accompagnée. Il figure aussi dans l'export de ses données personnelles.

## Vous êtes direction VAE

**De quels droits ai-je besoin ?**
Pour lire tous les dossiers et ouvrir l'onglet « Organisation » : `tool/vae:viewany`, au niveau du site. Pour certifier un bloc et pour voir la liste des certifications à renouveler : `viewany` **et** `tool/vae:certify`, tous
deux au niveau du site. `certify` sans `viewany` n'ouvre rien, et aucun archétype ne donne `certify`. L'administrateur du site passe ces contrôles, mais personne ne certifie son propre dossier, l'administrateur compris. Aucun réglage du
plugin n'ouvre ni ne ferme ce cycle : c'est l'attribution des droits qui le fait. Une seule condition du site s'y ajoute : le sous-système de compétences de Moodle doit être activé, sinon évaluer et certifier sont refusés (message « Le sous-système de compétences est désactivé ») et la liste des renouvellements le dit.

**Comment certifier un bloc ?**
Ouvrez le dossier du vaeiste, section « Positionnement de la structure », et cliquez « Certifier ce bloc » sous le bloc. Il faut que le vaeiste ait déclaré au moins une fois sur la fiche. Une fiche qui n'a aucun bloc est son
propre bloc (« La fiche entière »). Le formulaire demande le statut (« certifié par la structure » ou « retiré »), la **date de la décision** (AAAA-MM-JJ, du 2000-01-01 à aujourd'hui, proposée au jour même), un « Valable jusqu'au »
facultatif, un **motif écrit obligatoire** (4 000 caractères au plus) et, quand il y a lieu, le motif de l'alerte ; le rappel « ne nommez aucune personne accompagnée » s'affiche au-dessus du motif et au-dessus du motif de l'alerte. Le vaeiste reçoit alors le même message automatique que pour une évaluation (une ligne a été ajoutée, sans le statut, le motif ni l'auteur). **Certifier n'est pas valider** : c'est le positionnement de la structure, à une date et par écrit. Seul le jury valide, hors
de la plateforme, et le plugin ne garde pas sa décision.

**Une compétence du bloc n'est pas encore suffisante : puis-je certifier quand même ?**
Oui, c'est une **alerte, pas un verrou**. La page affiche « Pas encore suffisantes : … » avec la liste des compétences du bloc dont la dernière ligne d'évaluation n'est pas « suffisante pour certification » (une compétence jamais
évaluée, ou « retirée », compte comme non suffisante ; une compétence qui ne figure plus dans la fiche de certification n'en est pas une). Tant qu'il y en a au moins une, le **motif de l'alerte est obligatoire** (« Pourquoi le bloc
est tout de même certifié ») ; quand aucune ne manque, il doit rester vide. Le serveur recalcule au moment d'enregistrer : si les évaluations ont changé entre-temps, la page revient avec le message et l'alerte à jour, sans rien écrire.
Un retrait de certification ne lève aucune alerte et n'en demande pas.

**À quoi sert la date « Valable jusqu'au », et que se passe-t-il quand elle passe ?**
C'est le dernier jour où la certification tient, facultatif : **sans date, elle n'expire jamais**. Elle ne peut pas précéder la date de la décision ni dépasser le 2100-01-01, et elle est refusée sur un retrait. Un jour déjà passé est permis
(une certification antidatée peut naître « à renouveler »). Le dernier jour compte encore en entier. Passé ce jour, rien n'est écrit et **rien n'est envoyé** : le dossier dit « Valable jusqu'au… - échéance passée depuis N jour(s) » et la liste montre le dernier jour et le nombre de jours depuis dans ses colonnes « Valable jusqu'au » et « Jours depuis »,
calculés à la lecture, au jour de la personne qui lit. La certification reste la ligne qui fait foi, et le plugin ne dit rien d'autre que « une date est passée ».

**Où est la liste des certifications à renouveler, et comment renouveler ?**
*Administration du site → Compétences → Certifications à renouveler*, visible seulement pour qui détient `viewany` et `certify` (la page redemande les deux). Une ligne par certification qui fait foi et dont le dernier jour est passé : le vaeiste (un lien vers son dossier),
la fiche, le bloc, le dernier jour, le nombre de jours depuis, et le lien « Renouveler la certification ». Les plus anciennes échéances d'abord ; pas de total, pas de pourcentage, pas de couleur ; les comptes suspendus y figurent, les comptes supprimés non. Elle est calculée
au jour où vous l'ouvrez, et c'est la **seule aide** : aucun message ni aucune tâche planifiée ne vous prévient. Renouveler, c'est **ajouter une ligne** « certifié par la structure », avec un nouveau motif (le formulaire ne le préremplit pas) et, si vous le voulez, une nouvelle date
de fin : l'ancienne ligne reste dans l'historique et le bloc quitte la liste. Si une compétence du bloc n'est plus suffisante, l'alerte s'applique de nouveau. Pour votre propre dossier, la liste dit « Vous pouvez la lire, pas la renouveler », sans lien ; elle dit aussi quand la fiche n'est plus
sur le site, quand les compétences sont désactivées ou quand le vaeiste n'a plus de déclaration sur la fiche (la page de renouvellement refuserait).
*(Non vérifié : les mots « à renouveler » et « valable jusqu'au » n'ont pas été relus par un accompagnateur VAE.)*

**Comment corriger ou retirer une certification ?**
Comme une évaluation : on **ajoute** une ligne, la dernière fait foi, l'historique garde tout. « Retiré » n'est possible que s'il y a une certification qui fait foi ; le motif reste obligatoire, et un retrait refuse un motif d'alerte et un « Valable jusqu'au ». Le dossier ajoute « Des compétences de ce bloc ont été
positionnées après cette certification » quand la ligne d'évaluation la plus récente d'une compétence du bloc est postérieure à la certification : un repère calculé à la lecture, pas un verdict, qui ne retire rien à la certification.

**La fiche a été remplacée : ce que j'ai certifié sur l'ancienne compte-t-il sur la nouvelle ?**
Non. Rien n'est copié, reporté ni déduit, comme une attestation ne se reporte pas. Quand l'administrateur a déclaré le remplacement et décidé l'équivalent, la nouvelle fiche **montre en lecture** « Positionné sur l'ancienne fiche… : statut, date », d'après la ligne qui fait foi de
l'ancienne (si ce n'est pas un retrait). Les évaluations se lisent de compétence à compétence ; une certification, de bloc à bloc, seulement si le bloc de l'ancienne fiche a sa propre décision vers un bloc de la nouvelle (elle n'est jamais déduite de ses compétences). Une seule
fiche en arrière ; la lecture donne une ligne par décision de correspondance confirmée, avec le code de l'ancienne fiche : plusieurs anciennes fiches vers une nouvelle donnent donc des lignes distinctes, et deux compétences d'une même ancienne fiche confirmées vers la même compétence de la nouvelle en donnent deux sous cette compétence. Cette lecture **ne compte pas pour l'alerte** : elle regarde les seules évaluations écrites sur la nouvelle fiche. Si vous voulez un positionnement sur la nouvelle fiche, écrivez-le.

**Qu'est-ce que l'onglet « Organisation », et qui peut l'ouvrir ?**
La configuration du dispositif sur ce site, pour qui détient `viewany`. On l'ouvre par *Administration du site → Compétences → Organisation*, ou par le lien « Organisation » de son propre profil ; un détenteur de `viewany` qui n'a rien déclaré y arrive directement. Elle **montre, elle ne règle
rien**. Pour chaque fiche : le nombre de blocs et de compétences, les cours, les activités (un nombre, « 3 activités » par exemple, qui se déplie en liste d'un clic sur ce nombre, sans JavaScript), les modèles de plans du cœur, le **nombre** de plans de formation, les référents du bloc et les adresses des règles de validation (ou « Réglage fermé »
quand ce réglage l'est). Puis « Droits, rôles et réglages » : la grille « Acteurs et pouvoirs », les droits du plugin avec les rôles qui les accordent, les retirent ou les interdisent, les cohortes dont les membres sont suivis par un responsable, et l'état de dix réglages. **Aucun vaeiste n'y est nommé** ;
les seules personnes citées sont les référents d'un bloc. Le nombre de plans compte les plans de tous les comptes qui ont laissé une trace dans le plugin (une déclaration, une marque « Hors de mon activité » ou un brouillon de la préparation du dossier de validation), **brouillons compris** : jamais un nom de plan.
« Non lisible avec vos droits » veut dire que le cœur vous cache la chose, pas qu'elle n'existe pas ; un cours ou un modèle que le cœur cache à votre rôle reste dans la liste sans son nom (« Un cours caché », « Un modèle caché »).

**Pourquoi l'onglet « Organisation » montre-t-il « 3 activités » sans aucun nom ?**
Le nom d'une activité n'est donné qu'à qui le cœur la montre. Les autres lisent « Une activité non visible », et sa place ne dit rien de son nom (les activités nommées passent d'abord, les autres suivent). Une activité n'est comptée que si vous avez, dans son contexte, le droit du cœur de voir les
compétences du module ; une activité en cours de suppression ne l'est pas. Sur l'instance d'essai, un compte de direction qui ne détient que `viewany` a lu « 3 activités » sans aucun nom, et l'administrateur du site a lu les trois noms ; la cause probable est que le compte de direction n'est pas inscrit aux cours (non vérifié). Ce ne sont jamais des personnes ni des dates : l'offre de formation, rien de plus. Le plugin ne lit pas la règle que
l'activité applique à la compétence.

## Vous êtes administrateur

**Par où commencer ?**
1. Activer les compétences (*Fonctions avancées*), installer le plugin, *Notifications*.
2. Importer les fiches de certification depuis France compétences : *Compétences → VAE - cheminement → « Importer des fiches depuis France compétences »* (vous
   donnez les numéros des fiches, puis le serveur va chercher l'export sur data.gouv.fr, ou vous déposez le fichier zip ; un aperçu précède toute écriture). L'ancien écran du fichier CSV
   reste, pour une fiche préparée à la main : « Importer un fichier CSV (cas particulier) ».
3. Créer **un rôle par usage** : vaeiste (`declare`, `viewown`, `draftown`, `export` ; modèle de rôle
   « Étudiant » choisi dans le formulaire, sans recopier les droits du modèle ; attribué au niveau du site),
   responsable (`viewassigned`, `attest`, `readdeposit`, `writeopinion` ; et `evaluate` si le site le décide ; attribué **dans le
   contexte utilisateur du vaeiste**, par cohorte). Aucun archétype ne les donne : ce n'est pas un oubli.
   Pour qui peut gérer les rôles (`moodle/role:manage`), la page *Administration du site → Compétences → Rôles VAE* les crée à partir des préréglages livrés dans le plugin
   (`presets/roles/`) : les trois rôles de base (vaeiste, responsable, direction) et deux rôles facultatifs, « responsable évaluateur » (le responsable avec `evaluate`)
   et « direction certificatrice » (la direction avec `certify`). Elle dit lesquels existent déjà et ne crée un rôle que sur votre demande, après un écran qui liste ses droits ; rien n'est créé en ouvrant la page ni à l'installation.
   Un rôle de même nom court n'est jamais modifié. `evaluate` et `certify` ne sont dans aucun des trois rôles de base, et l'import, la reprise d'un dossier, l'identité, l'aide d'IA, le retrait
   de n'importe quelle attestation et le rattachement à un cours ne sont dans aucun préréglage (la page les refuse même si un fichier était modifié). Le plugin n'attribue rien : qui détient un rôle, et où, se décide ensuite dans Moodle.
   Importer à la main les fichiers de `presets/roles/` par *Définir les rôles* reste possible (essayé le 5/11/2026 sur l'instance locale : formulaire lu, rien créé). La page Rôles VAE a été essayée par PHPUnit et Behat ; son essai à la main dans l'interface sur un site réel n'a pas été vérifié.
4. Rattacher les responsables à leurs vaeistes par cohorte (`tool_cohortroles`) si besoin.

**Pourquoi le plugin ne marche-t-il pas avec un rôle « utilisateur authentifié » ?**
Parce que ce rôle, attribué au système, est détenu par chacun dans le contexte de **tous** les autres.
Le plugin refuse ce raccourci : l'appartenance est prouvée en PHP.

**Que faut-il décider avant d'ouvrir à de vrais vaeistes ?**
La base légale, la durée de conservation et le registre des traitements (T-84). Le plugin déclare ses
données (Privacy API) mais ne tient pas votre registre. Décidé le 5 novembre 2026 par le porteur, délégué à la protection des données du site : la base légale des positionnements et du nom des référents de bloc est le contrat d'usage ; les comptes inactifs relèvent d'une décision de l'administrateur du site, à réexaminer tous les 36 mois (le plugin ne supprime aucun compte de lui-même ; le réglage est celui de Moodle ; *formule à confirmer dans sa rédaction exacte*) ; le bornage des copies hebdomadaires et des sauvegardes de l'administrateur reste une politique du site, à écrire par le délégué (section 6 de T-84), sans durée fixée ici.

**Comment sauvegarder avant une désinstallation ?**
Par l'écran d'administration ou `admin/cli/uninstall_plugins.php` : chaque dossier est sauvegardé dans les
fichiers personnels de l'administrateur. Supprimer d'abord le dossier du plugin ne sauvegarde **rien**. Depuis la ligne de
commande, où personne n'est connecté, la sauvegarde se fait sans aucun droit de lecture des compétences, y compris pour un vaeiste qui a
posé une marque « Hors de mon activité », et porte les intitulés des compétences. Elle ne contient ni les dépôts du dossier de faisabilité
(numéros, dates, empreintes, notes), ni les avis des responsables, ni les questions de précision et leurs réponses, ni les versions antérieures des
rubriques, ni qui a décidé un report et sa note, ni — quel que soit celui qui désinstalle — le journal de bord et la préparation du dossier de
validation : un réimport ne les rend pas. Une ligne dont le format n'est pas celui attendu n'empêche pas la sauvegarde, mais la copie ne se
réimportera pas telle quelle (la notice LISEZMOI qu'elle contient le dit). Elle ne se réimportera pas non plus si, depuis la réinstallation du plugin, un dossier
est déjà sorti du site sur les mêmes compétences, ou si le compte du vaeiste a déjà reçu un nouvel identifiant (dès sa première déclaration, son premier export, sa
première copie ou sa première sauvegarde) : l'import est alors refusé en entier, avec un message qui dit pourquoi. Faites exporter par les vaeistes le journal et la préparation avant de désinstaller.

**Quand ouvrir les pièces justificatives ?**
Pas avant d'avoir **validé l'amendement 4** de `DER-referentiel-001` (une pièce échappe au rappel
d'anonymisation : le risque est plus élevé que pour un texte), **écrit la durée de conservation** de tels fichiers (T-84),
et décidé si des documents de soins joints appellent une analyse d'impact. Réglage `piecesopen` (fermé par défaut) ; des
plafonds (`piecemaxbytes`, `piecemaxpercandidate`) ; **sans antivirus, les fichiers sont refusés** sauf si vous cochez
`piecenoantivirus`. Le réglage du site « Taille maximale des fichiers déposés » (*Administration du site → Sécurité → Réglages de sécurité du site*)
s'applique aussi quand il est plus bas que `piecemaxbytes` : la taille annoncée sur la page est la plus petite des deux. Fermer le réglage n'enlève rien : les pièces déjà là restent lisibles et le vaeiste peut les retirer.

**Les pièces sont-elles dans les archives ?**
La **liste** (`pieces.json`) l'est toujours. Les **fichiers et les adresses** n'entrent que dans l'archive que le
**vaeiste exporte lui-même** et dans la **sauvegarde** conservée sur la plateforme ; l'export fait par la direction ou par
un tiers n'en contient ni les octets ni les adresses. Un plugin plus ancien que la 0.39.0 réimporte les déclarations et
perd les pièces sans le dire.

**Une archive qui porte des pièces est refusée à l'import : pourquoi ?**
La jonction de pièces est fermée sur ce site, et une déclaration ne s'importe pas deux fois : importer sans les pièces les perdrait
pour toujours. Ouvrez `piecesopen` (en ayant lu la question ci-dessus), importez de nouveau, et refermez si vous le souhaitez.
Une archive qui est seulement une liste (l'export d'un tiers) s'importe fermée ; ses pièces sont comptées et laissées.

**Comment réimporter une archive ?**
Page d'administration du plugin, formulaire de réimport. L'archive est vérifiée par empreintes ; un seul
octet modifié la refuse, et rien n'est écrit. Les rubriques déjà écrites sur ce site ne sont jamais écrasées. L'import reste strict : une archive qui
porte une ligne dont le format n'est pas celui attendu (voir l'avertissement d'export, plus haut) est refusée en entier, les déclarations valides avec elle.
Une archive ne porte jamais les dépôts du dossier de faisabilité, les avis des responsables ni les questions de précision : ils ne reviennent pas (ils
figurent dans l'export des données personnelles de Moodle, qui se lit mais ne se réimporte pas). Une archive venue d'un autre site est refusée en entier si ce
site numérote déjà autrement une compétence qu'elle nomme, ou si le compte du vaeiste y est déjà connu sous un autre identifiant (question suivante).

**Une archive venue d'un autre site est refusée, parce que ce site numérote autrement des compétences qu'elle nomme ou connaît déjà le compte du vaeiste : que faire ?**
Chaque site donne lui-même un numéro à une compétence la première fois qu'un dossier en sort (archive, copie Word ou OpenDocument, sauvegarde) ou
qu'une archive y est reprise, puis ne le change plus. Si le site d'accueil en a déjà donné un autre, l'archive ne peut pas être rangée sous cette
compétence : elle est refusée en entier, rien n'est importé, et l'écran ne permet pas de passer outre. Le message donne le nombre de compétences en cause
et nomme les dix premières (triées par code, chacune par son code-rang avec l'intitulé que ce site lui donne) ; les autres sont comptées. Aujourd'hui, deux issues :
repartir de zéro sur ce site (nouvelles déclarations, nouvelles signatures des responsables) en gardant l'archive et son PDF comme pièce du dossier, ou faire
intervenir un développeur. Un écran où une personne validerait que deux numéros désignent la même compétence n'existe pas à ce jour. Le même refus en entier
tombe si le compte du vaeiste a déjà un identifiant sur le site d'accueil (il le reçoit dès sa première déclaration, son premier export, sa première copie ou sa
première sauvegarde) : le message dit alors que ce compte est déjà connu sur ce site sous un autre identifiant, et invite à choisir le compte à qui appartient
le dossier, ou un compte qui n'a pas encore de dossier. N'annoncez donc pas à un vaeiste que son archive sera reprise ailleurs sans ces deux réserves.

**L'export ou la sauvegarde affiche un avertissement sur une ligne dont le format n'est pas celui attendu : que faire ?**
L'archive (ou la sauvegarde faite depuis l'écran « Sauvegarder sur Moodle », qui ramène alors au dossier avec deux messages : la réussite et l'avertissement) a été
produite quand même, pour qu'un dossier puisse être remis au jury à temps :
l'écran nomme la déclaration, le fichier et la valeur en cause (dix lignes au plus, les autres sont comptées) et la notice LISEZMOI de l'archive porte la même
mention. Elle ne pourra pas être réimportée telle quelle, et un outil qui valide le schéma la refusera. La ligne vient d'une déclaration déjà enregistrée avec un
rang de compétence hors format (un rang « c7 » au lieu de « C7 »), par une version antérieure du plugin ou écrite directement dans la base de données ; une
déclaration nouvelle sur un tel rang est refusée. Cela ne se corrige pas depuis Moodle : l'archive reprend le rang tel que la déclaration l'a enregistré, et corriger
le numéro d'identification de la compétence dans *Compétences → Référentiels de compétences* ne réécrit pas la déclaration déjà enregistrée ; le plugin n'a aucun
écran pour la réécrire. L'avertissement revient à chaque export et à chaque sauvegarde tant que la déclaration existe. Le texte de l'écran invite le vaeiste à
prévenir l'administrateur du site, c'est-à-dire vous : signalez les lignes affichées au responsable du plugin. Le vaeiste garde la copie modifiable (Word ou OpenDocument).
Deux cas restent refusés : un dossier trop volumineux pour être relu par la plateforme, et une pièce dont le fichier est perdu ou altéré.

**Le plugin se connecte-t-il à Internet ?**
Dans deux cas seulement, et **chacun est déclenché par une personne** : rien ne part tout seul, et aucune tâche planifiée du plugin n'en fait.
(1) **L'aide à la reformulation**, fermée par défaut : quand un vaeiste à qui vous avez donné le droit clique « Proposer une autre formulation », puis **confirme** à chaque envoi,
le texte qu'il a écrit part chez le fournisseur d'IA que vous avez activé dans Moodle. C'est le **cœur de Moodle** (son sous-système d'IA) qui envoie, pas le plugin ; l'écran de confirmation
dit chaque fois le texte exact, chez qui, et ce qui est conservé (voir « Comment ouvrir l'aide à la reformulation par IA ? »). (2) **L'import des fiches depuis data.gouv.fr** : quand
un administrateur clique « Aller chercher le dernier export », le serveur du site va chercher le dernier export public de France compétences, sur deux adresses écrites dans le plugin
(`www.data.gouv.fr` et `static.data.gouv.fr`, en https, certificat vérifié). **Aucune donnée sur personne n'est envoyée** par cette requête : elle montre l'adresse IP du serveur (ou celle de son
proxy), l'heure et un nom de programme fixe qui ne donne pas l'adresse du site. C'est la seule requête sortante propre au plugin. Il n'y a pas de réglage à ouvrir : le bouton est réservé à qui a
le droit d'importer, et Moodle garde la trace de chaque clic (événement « Export de France compétences récupéré sur data.gouv.fr »). **Jamais une adresse saisie par un utilisateur** : le serveur
ne visite ni le lien d'une pièce justificative ni l'adresse d'une règle de validation. **Le dépôt du fichier zip n'en demande aucune** : c'est le navigateur qui envoie le fichier au serveur, qui
n'a rien à joindre. Les notifications que le plugin fait envoyer passent par la messagerie de Moodle et les réglages de courriel de votre site : ce n'est pas une connexion du plugin.
*(Non vérifié : la requête vers data.gouv.fr n'a pas encore été jouée avec ce code contre le vrai site, ni sur votre hébergement.)*

**L'import depuis data.gouv.fr échoue : que faire ?**
Le message rouge de l'écran dit ce qui s'est passé et, presque toujours, « Rien n'a été écrit » ; la page garde vos numéros. Selon le message : « Le serveur de ce site n'a pas pu joindre data.gouv.fr »
(l'hébergement ne laisse pas le serveur aller sur Internet, ou un pare-feu ou un proxy l'en empêche : demandez à l'hébergeur d'ouvrir le https sortant vers `www.data.gouv.fr` et `static.data.gouv.fr`) ;
« n'a pas répondu à temps » (réessayez dans quelques minutes) ; « Le certificat de data.gouv.fr n'a pas pu être vérifié » (souvent un pare-feu qui inspecte le trafic chiffré, ou un serveur dont les
certificats ne sont pas à jour : **le plugin ne désactive jamais cette vérification**) ; « Moodle bloque l'adresse de data.gouv.fr pour ce serveur » (réglages *Sécurité → Sécurité HTTP*, hôtes bloqués et
ports autorisés : un administrateur peut l'autoriser, mais **n'assouplissez pas ces réglages pour ce seul besoin**) ; « data.gouv.fr a répondu par une erreur (code …) », « a répondu autrement que prévu »,
« ne propose aucun export de fiches reconnu » (réessayez plus tard) ; « a renvoyé vers une adresse que le plugin n'accepte pas », « Le fichier annoncé … est plus gros que ce que le plugin accepte »,
« Le fichier reçu est incomplet ou abîmé » ; et « data.gouv.fr n'indique plus la Licence Ouverte 2.0 pour ce jeu de données » (le plugin enregistre « Licence Ouverte 2.0 » avec chaque fiche et ne peut pas vérifier celle d'un fichier déposé :
vérifiez-la sur la page du jeu de données, et signalez-le au responsable du plugin). Pour les messages du réseau, du délai dépassé et du certificat, une ligne « À donner à votre hébergeur » nomme l'adresse et le code cURL ; l'erreur de data.gouv.fr donne son code dans la phrase. **Dans tous les cas, sauf celui de la licence,
la sortie est la même : déposez vous-même le fichier zip** (le téléchargement se fait sur votre poste, le serveur n'a rien à joindre), puis cliquez « Lire le fichier déposé ». Tous les messages de refus,
avec leur cause et leur remède, sont dans le guide d'usage V2, partie 4.11 (« En cas de refus »).

**Mon numéro n'est pas trouvé, ou la fiche n'a aucun bloc : que faire ?**
À l'aperçu, la ligne du numéro porte un refus nommé, suivi de « Rien ne sera écrit pour cette fiche. » ; les autres numéros s'importent. « Ce numéro n'est pas dans l'export du … » : une faute de frappe,
ou une fiche publiée après cet export ; vérifiez le numéro sur la fiche de France compétences, et prenez l'export le plus récent. « Cette fiche n'a aucun bloc de compétences dans l'export » :
c'est le cas de la plupart des fiches RNCP qui ne sont plus en vigueur et des fiches du répertoire spécifique (RS) non actives, dont cet export ne détaille pas les blocs (sur l'export du 19 octobre 2026, aucune fiche RS n'a de bloc,
et **236 fiches RNCP en vigueur** n'en ont pas non plus). Pour toute fiche en vigueur sans bloc, RNCP comme RS (les 236 fiches RNCP et les 1 490 fiches RS en vigueur de cet export), la phrase est « Cette fiche est en vigueur, mais l'export ne détaille aucun bloc… », et il n'y a pas de numéro plus récent à chercher. À l'inverse, **6 094 fiches inactives de l'export du 19 octobre 2026 ont des blocs et s'importent** : l'aperçu ne fait que signaler leur état (« Indiquée inactive par France
compétences dans cet export. L'importer reste possible. »). Pour une fiche qui n'est plus en vigueur, cherchez le numéro de la fiche **en vigueur** : il change quand une fiche est remplacée. Pour une fiche RS, ou une fiche en vigueur sans bloc, cet export ne suffit pas : le fichier
CSV préparé à la main (« Importer un fichier CSV (cas particulier) ») reste possible. **Un numéro faux, mais qui existe, n'est signalé par rien** : il importe une autre certification. C'est pourquoi l'aperçu
affiche l'intitulé publié en entier et demande de le lire avant de confirmer.

**Pourquoi mon export est-il signalé « plus ancien » ?**
L'aperçu dit alors, pour la fiche : « Moodle tient déjà la version du … de cette fiche, plus récente que cet export (…) : l'importer la ferait revenir en arrière. » Moodle détient pour cette fiche une
version datée d'un jour **postérieur** à celui de l'export (par exemple une fiche importée par le fichier CSV avec la date du jour, ou depuis un export plus récent). La comparaison se fait par jour : le même jour
est accepté. Le plugin informe, il n'empêche rien : la fiche n'est importée que si vous cochez « Importer aussi les … fiche(s) que Moodle tient dans une version plus récente », et, si c'est la seule chose à
importer, c'est le bouton lui-même qui le dit (« Importer les … fiche(s) plus anciennes que celles de Moodle »). L'import la fait revenir à la version de l'export. À ne confondre avec l'avertissement jaune
« Cet export a … jours », qui dit seulement que des fiches ont pu être remplacées depuis : « Aller chercher le dernier export » donne le plus récent.

**Le numéro d'une fiche a changé : que faire ?**
Quand France compétences remplace une fiche, c'est le **nouveau** numéro qu'il faut importer, copié depuis la fiche en vigueur. Importez-le, puis, sur la page de résultat, suivez le lien « Une de ces fiches en remplace
une autre ? » vers *Fiches de certification remplacées* pour déclarer le remplacement. Cette déclaration ne fait rien bouger : personne n'est prévenu, aucune attestation n'est redemandée, et les vaeistes peuvent toujours
déclarer sur l'ancienne fiche tant que vous ne la cachez pas. Aucune liste de numéros n'est figée dans le plugin : le lien « Reprendre les numéros des fiches déjà importées » remplit le champ avec ceux que votre site détient.

**Puis-je retirer une fiche importée par erreur, ou supprimer son référentiel ?**
Oui, en trois temps. **Cachez** d'abord le référentiel de la fiche (*Administration du site → Compétences → Référentiels de compétences*, « Visible : Non ») :
plus personne ne peut déclarer dessus pendant que vous la retirez. **Supprimez** ensuite le référentiel si Moodle l'accepte : il refuse tant qu'un cours, un plan,
un modèle de plan, un badge ou une compétence d'utilisateur s'appuie sur l'une de ses compétences, ce qui arrive dès qu'un vaeiste a déclaré dessus ; supprimer un
référentiel supprime aussi ses compétences, sans corbeille. La fiche reste alors listée sur la page d'administration du plugin, avec un avertissement et sans compétence, et la
réimporter reconstruit le référentiel. Pour la faire disparaître de la liste, revenez sur la page *VAE - cheminement* et cliquez **Retirer cette ligne** (droit `tool/vae:import`) :
un écran de confirmation liste ce qui part (licence, paternité, dates, adresses des règles de validation, référents de bloc, marques de compétences sorties de la fiche,
correspondances, fiche remplaçante et lien des fiches qui la désignent comme remplaçante), et la ligne ne se récupère pas (réimporter la fiche en crée une neuve). Ce geste
ne supprime rien dans Moodle. Le bouton n'est pas offert dans trois cas. (1) Un vaeiste a laissé une trace sur la fiche (une déclaration, une marque « Hors de mon
activité », une rubrique de la préparation du dossier de validation) : la page le dit, avec sa propre phrase, et il ne reste alors que de cacher le référentiel, car ce
qu'un vaeiste a écrit n'est jamais supprimé depuis cet écran. (2) Moodle détient encore un référentiel portant ce code (fait à la main, par exemple, et même caché) :
la page le dit avec une autre phrase, et la ligne ne peut être retirée qu'une fois ce référentiel supprimé dans Moodle ; si Moodle refuse de le supprimer, le cacher
l'écarte de l'usage mais ne permet pas de retirer la ligne. (3) Vous n'avez pas le droit `tool/vae:import` : la page ne dit alors que sa phrase générale (le référentiel
de la fiche n'existe plus dans Moodle, importez-la de nouveau), sans bouton ni explication. Ne modifiez pas l'identifiant d'un référentiel importé : l'import le refuse
en le nommant, tant qu'il n'est pas remis tel quel.

**Que se passe-t-il si je cache le référentiel d'une fiche importée ?**
Cacher un référentiel (« Visible : Non » dans *Référentiels de compétences* ; Moodle dit « caché », et les écrans d'administration du plugin « masqué ») ferme la fiche
à deux usages : le rattachement à un cours (le cœur le refuse : la page de rattachement l'indique à la place du bouton) et la déclaration d'une activité, y compris le
report d'une déclaration vers cette fiche quand elle en remplace une autre (le bouton n'est plus proposé, et une adresse de report ouverte sur une fiche cachée
ramène au dossier avec le même message). La fiche n'est plus proposée sur « Déclarer une activité », où le
vaeiste lit une phrase écrite pour lui, sans chemin d'administration : la fiche (son intitulé et son code) est cachée, on ne peut pas y déclarer pour le moment, il doit
s'adresser à son responsable ou à l'organisme qui l'accompagne. Un formulaire ouvert avant que la fiche soit cachée est refusé de la même façon, avec le même message,
et ce que le vaeiste avait écrit lui est rendu dans le formulaire, dont la liste de compétences recommence à « Choisir... » : aucune compétence n'est présélectionnée, il
en choisit une d'une autre fiche pour enregistrer son texte (quand plus aucune fiche n'est ouverte, il n'y a plus de formulaire : le texte est rendu dans des champs en
lecture seule, à copier). Un formulaire de report refusé de la même façon est rendu avec son texte, à renvoyer quand la fiche ne sera
plus cachée. Ce qui a déjà été déclaré reste. La restauration d'une archive de dossier et l'écran « Hors de mon activité » ne sont
pas concernés. Vous ne pouvez pas essayer une fiche cachée avec un compte d'essai de vaeiste. Pour préparer une fiche à l'abri : importez-la (le référentiel est créé
visible, donc ouvert aux déclarations tant que vous ne l'avez pas caché), puis cachez-la aussitôt ; rendre la fiche visible rouvre tout.

**Comment être rappelé des déclarations qui attendent ?**
Réglage « Rappeler aux responsables après ce nombre de jours » (zéro : désactivé). La page « Déclarations en attente d'attestation » (droit `viewany`) donne le même état, par vaeiste, sans rien notifier. Sa colonne « Responsables à prévenir » donne le nombre de responsables qui seraient prévenus (jamais leurs noms) ; zéro veut dire que personne ne le serait, et la case « Seulement les vaeistes que personne ne peut prévenir » ne garde que ceux-là : rattachez-les à un responsable par une cohorte.

**Comment donner les adresses des règles de validation ?**
*Compétences → Règles de validation : adresses* : une fiche, un intitulé court, l'adresse, et le jour où vous avez ouvert la page pour la vérifier (cinq adresses au plus par fiche). Le plugin ne les ouvre jamais. Elles ne sont montrées aux vaeistes que lorsque vous cochez « Montrer les adresses des règles de validation » : faites d'abord relire la phrase qui les encadre par un accompagnateur VAE.

**Comment ouvrir l'aide à la reformulation par IA ?**
Elle est **fermée par défaut**. Ne l'ouvrez (*Administration du site → Compétences → VAE : réglages*, réglage « Ouvrir l'aide à la reformulation aux vaeistes ») qu'une fois écrits la base légale, le registre,
la durée de conservation et le contrat avec le fournisseur, et relus par un accompagnateur VAE et par la personne qui tient le registre l'instruction envoyée et la politique d'IA du site (le texte du cœur, que vous rédigez une fois par la personnalisation de langue, pour tous les endroits qui offrent de l'IA). Il faut
ensuite donner le droit `tool/vae:useai` aux personnes de votre choix (aucun rôle ne l'a par défaut ; un administrateur du site passe tous les contrôles de droit, c'est le réglage qui
verrouille) et **n'activer qu'un seul fournisseur d'IA pour le texte** (*Administration du site → IA → Fournisseurs d'IA*) : avec deux, le texte pourrait partir chez le second sans que le vaeiste le sache, et l'aide
ne se propose pas. Le texte envoyé et la réponse sont conservés par Moodle dans ses propres tables ; le plugin n'en efface aucun lui-même, ni à la suppression d'un compte ni à un autre moment. Ils ne sont vidés que lorsqu'une personne approuve la demande de suppression de données (celle que Moodle crée à la suppression d'un compte, ou celle du vaeiste), et seulement à deux conditions. Première condition : traitez cette file (*Administration du site → Utilisateurs → Protection des données et politiques → Demandes de données*) et ne désactivez pas la création automatique de ces demandes (« Créer automatiquement des demandes de suppression de données », active par défaut, dans *Réglages de protection des données*), sinon rien ne crée la demande qui les vide. Seconde condition : que le registre des données du site (*Administration du site → Utilisateurs → Protection des données et politiques → Registre des données*) n'ait pas défini la finalité et la catégorie du site. Si elles le sont, Moodle ne vide jamais ces textes, rattachés au contexte du site, ni pour la demande créée à la suppression d'un compte ni pour celle du vaeiste, et déclare quand même la demande terminée : il faut alors les faire effacer à la main. L'écran de confirmation de l'aide dit au vaeiste, à chaque envoi, l'une de deux phrases selon l'état du site à ce moment-là : celle qui annonce l'effacement après la suppression du compte, une fois la demande approuvée, ou, quand l'une des deux conditions manque, celle qui dit que sur ce site Moodle ne les efface pas de lui-même et qu'il faut le demander à l'administrateur. Après l'effacement, Moodle garde une ligne de registre : le numéro du compte (qui reste rattaché au nom dans Moodle), la date, le fournisseur, le modèle, la taille de l'échange en jetons, la réussite ou l'échec de l'appel et, après un échec, le message d'erreur du fournisseur. Le journal du plugin n'en garde jamais
le texte ; le registre des appels du plugin (lui aussi sans aucun texte) ne sert qu'à tenir les deux limites : 30 secondes entre deux demandes, 25 par vaeiste et par jour par défaut.

**Comment répartir les responsables par bloc ?**
*Compétences → Référents des blocs* : pour une fiche (ou un de ses blocs), nommez par identifiant les membres du personnel qui doivent être prévenus en premier d'une déclaration. C'est un aiguillage des messages : qui suit un vaeiste peut toujours lire son dossier et attester, et un bloc sans référent parmi ses suiveurs est annoncé à tous comme avant. Sur *Mes vaeistes*, un référent peut aussi compter les déclarations de ses blocs ; la liste de ses vaeistes ne change pas.

**Comment ouvrir le cycle « déclarer, évaluer, certifier » ?**
Les trois actes sont internes à la structure ; seul le jury valide, hors de la plateforme. Déclarer et attester existent déjà ; évaluer et certifier sont deux droits de plus, qu'**aucun archétype** ne donne et qu'**aucun réglage du plugin**
ne commande. **Évaluer** : ajoutez `tool/vae:evaluate` au rôle du responsable, attribué dans le contexte utilisateur de chaque vaeiste suivi (à la main ou par cohorte), comme `viewassigned`. Ce n'est pas le même cas que `viewassigned` : le code refuse `viewassigned` tenu au niveau du site (on ne suit alors personne), mais ne refuse pas `evaluate` tenu au niveau du site, qui s'hérite dans tous les contextes utilisateur ; il ne sert pourtant qu'à qui suit le vaeiste (`viewassigned` dans son contexte) et jamais sur son propre dossier. Attribuez-le donc, comme `viewassigned`, dans le contexte de chaque vaeiste suivi, pour ne pas l'ouvrir plus large que le suivi. **Certifier** :
donnez `tool/vae:certify`, en plus de `viewany`, au niveau du site, au rôle de la direction. L'administrateur du site ne suit personne : il n'évalue jamais, mais il peut certifier (jamais son propre dossier). La grille « Acteurs et pouvoirs »
de l'onglet « Organisation » range ces deux droits en « facultatif » : elle ne signale aucun « Manque » tant que vous ne les donnez pas. Seul le vaeiste est prévenu, d'une ligne ajoutée, sans son contenu ; la direction, elle, ouvre la liste des
certifications à renouveler. Décision du porteur, qui est aussi délégué à la protection des données du site, le 5 novembre 2026 : la base légale de ces textes que la structure écrit sur une personne (et du nom des référents de bloc) est le contrat d'usage, comme pour les autres traitements (article 6, paragraphe 1, point b ; T-84). **Prévoyez dans le contrat d'usage la clause** qui informe le vaeiste de ces lignes, de leur durée (celle du dossier) et de la voie pour les contester (le délégué à la protection des données du site).
*(Non vérifié : la rédaction de la clause n'est pas écrite dans le plugin, et la base légale n'a pas été relue par un juriste.)*

**Que deviennent les positionnements quand un compte est effacé, quand je désinstalle ou quand je retire une fiche ?**
Compte d'un **responsable ou de la direction** effacé : les lignes qu'il a écrites restent, et le motif avec elles. Dès la suppression du compte, l'écran et l'archive ne le nomment plus (« une personne dont le compte n'existe plus »), mais la ligne garde son numéro de compte dans la base : c'est à l'approbation de la demande de suppression de données de ce compte que le plugin retire l'identité de la ligne et refait les archives, les sauvegardes et les copies qu'il conservait. L'observateur de suppression de compte ne le fait pas à lui seul (écart connu, consigné dans l'AIPD et dans T-84 ; *non vérifié par un essai sur un site réel*). Compte du **vaeiste**
effacé : toutes ses lignes partent avec lui. **Durée de conservation** (décision du 5 novembre 2026) : celle du dossier, jusqu'à la suppression du compte, plus un effacement à la demande du délégué à la protection des données du site ; le plugin n'a pas de purge automatique propre, car la ligne est en ajout seul. Le texte d'une ligne dont l'auteur est effacé reste, l'identité part (confirmé le 5 novembre 2026). **Désinstallation** : la sauvegarde de chaque dossier contient `positionnement.json` (état courant et historique) et un réimport les restaure, sauf pour un vaeiste resté sans aucune déclaration : son dossier n'est pas
sauvegardé, et ses positionnements se perdent. **Retrait de la ligne d'une fiche** : une évaluation ou une certification écrite sur la fiche compte, comme une déclaration, parmi les traces qui font refuser le retrait, et le message de refus les compte. Les deux
tables (`tool_vae_eval` et `tool_vae_certif`) sont déclarées dans l'API de confidentialité de Moodle.

**Pourquoi « Positionné sur l'ancienne fiche » n'apparaît-il pas sur un dossier ?**
Il faut que toutes ces conditions tiennent : (1) la nouvelle fiche est déclarée comme remplaçant l'ancienne (*Fiches de certification remplacées*) ; (2) une correspondance est **confirmée** dans « Correspondances », d'une compétence de l'ancienne fiche vers une compétence de la nouvelle, ou
d'un bloc vers un bloc (une suggestion non confirmée ne compte pas), et la compétence visée existe encore ; (3) le vaeiste a déclaré sur la nouvelle fiche, car la section est bâtie sur les fiches où il a déclaré ; (4) l'ancienne fiche porte, pour cette compétence ou ce bloc, une ligne qui fait foi et qui n'est
pas un retrait ; (5) on ne remonte que d'une fiche. Sur une fiche sans bloc (la fiche entière tient lieu de bloc), la certification ne se relit pas sur la nouvelle ; les évaluations de ses compétences, elles, se relisent si la correspondance de compétence à compétence est confirmée. Cette lecture n'écrit rien et ne compte pas pour l'alerte de la direction.

**Comment une activité apparaît-elle dans les colonnes « Activités » ?**
Le plugin ne relie rien : il lit ce que le **cœur de Moodle** a rattaché, dans un cours, entre une activité et une compétence (table `competency_modulecomp`), en une lecture pour toutes les compétences, jamais une par compétence. Aucun réglage du plugin n'y touche. La règle que l'activité applique à la
compétence (preuve, recommandation, achèvement) et la note ne sont **jamais lues**. Une activité en cours de suppression n'est pas comptée ; une activité dont le plugin n'a plus l'information (un type d'activité désinstallé, un cache en retard) est écartée sans erreur. Chaque lecteur ne voit que ce que ses droits du cœur lui montrent (voir
« Que montre la colonne « Activités » de « Mon parcours » ? »).

**La grille « Acteurs et pouvoirs » affiche « Manque » : que faire ?**
« Manque » veut dire qu'un droit que l'acteur doit détenir n'est donné par **aucun rôle** du site. Selon le droit, la phrase est « seuls les administrateurs du site peuvent l'exercer » ou « personne ne peut l'exercer » (c'est le cas de `viewassigned` et de `writeopinion` : l'administrateur ne suit personne). Corrigez le rôle
(*Administration du site → Utilisateurs → Permissions → Définir les rôles*) puis relisez la grille. « Aucun rôle (facultatif) » est normal pour un droit que le site peut ne pas vouloir (lire les rubriques ou l'identité d'une autre personne, évaluer, certifier) ; « Aucun rôle : réservé aux administrateurs du site » est normal pour l'import, la reprise d'un
dossier et le retrait de n'importe quelle attestation. Le rattachement d'une fiche à un cours (`tool/vae:linkcourse`) fait exception : aucun droit n'est donné par défaut sauf celui-là, donné au Gestionnaire. Un rôle qui **interdit** un droit est nommé (« interdit par… »), même si un autre le donne. Les limites sont écrites sur la page : la grille lit les définitions de rôles au niveau du site, pas qui détient un rôle, pas les surcharges faites plus bas, pas les droits
du cœur exigés en plus, et elle ne dit pas à quel acteur un rôle est destiné (un droit de la direction « donné » par un rôle de vaeiste se lit dans les noms de rôles). Elle dit ce que le dispositif prévoit, pas qui l'exerce. Le rôle du responsable ne s'attribue jamais au niveau du site.

**Quelle version de Moodle ?**
Moodle 5.3 LTS (branche `main`). Le paquet pour Moodle 4.5 LTS (branche `MOODLE_405`, qui n'a ni PDF, ni couverture, ni dépôt) n'est plus
maintenu : aucun correctif n'y est promis, pas même jusqu'au 4 octobre 2027 (date où Moodle 4.5 cesse lui-même de recevoir ses correctifs de sécurité) ;
environ 28 des 55 défauts corrigés par l'audit du 26 octobre 2026 y existent encore (dont 5 de sécurité ; estimation faite en relisant son code, les défauts
n'ont pas été rejoués un à un), et il ne faut pas l'installer sur un site réel. Un site en Moodle 4.5 doit passer à Moodle 5.3 : ce passage existe et il est vérifié par l'intégration
continue du plugin, sur PostgreSQL ; sur MariaDB, il n'est pas prouvé. La branche reste consultable. Voir [docs/BRANCHES.md](BRANCHES.md).
