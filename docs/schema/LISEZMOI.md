# Schéma pivot — reconstruit

**Statut : reconstruction, version `0.1.0-reconstruit`, 11 octobre 2026.**

Le code de ce plugin renvoie depuis le lot 2 à `preuve.schema.json`, le schéma pivot que l'export
normatif (lot 8, MC-1) doit respecter. Le fichier d'origine est **introuvable**. Il a été cherché
sur claude.ai (deux organisations, dix projets, les conversations), sur GitHub (recherche de code
dans tous les dépôts du compte), sur le disque du portable, dans l'historique git et dans toutes
les sessions Claude Code. Seule piste restante : le NAS du bureau, `\\192.168.0.15\Direction`.

Le lot 8 ne pouvait pas attendre : l'export est la contrepartie de la dérogation
`DER-referentiel-001`. Le schéma est donc **reconstruit à partir de ce que le code en savait**,
et il le dit, champ par champ.

**Si l'original revient, il prime.** On compare les deux fichiers, et chaque écart est tranché
explicitement, puis consigné au `CHANGELOG.md`. Aucun écart n'est absorbé en silence.

## Fichiers

| Fichier | Rôle |
|---|---|
| `preuve.schema.json` | Une preuve : une activité déclarée contre une compétence, avec ses attestations |
| `exemples/preuve-reportee.json` | Une preuve reportée depuis une autre (T-30, lot 3) : `reportee_depuis` et `reportee_le`, facultatifs, toujours ensemble. Jamais qui a décidé la correspondance ni sa note |
| `referentiel.json` (pas de schéma) | Facultatifs depuis T-33 : `bloc` (rang du bloc) sur une compétence, `blocs` (rang et intitulé) sur une fiche à blocs. Absents d'une fiche à plat. `regle_validation` (T-41, facultatif) : liste de `intitule`, `adresse`, `verifie_le` (ou null), seulement si le site a ouvert `refopen`. Non relu à l'import : les adresses sont celles du site, pas du vaeiste |
| `validation.schema.json` | La **préparation du dossier de validation** (T-11, lot 2), version `0.1.0`, fichier facultatif de l'archive (`validation.json`) : une liste de rubriques de texte (fiche, code, bloc, partie, version, date, texte), `officiel` **toujours `false`**, la phrase d'étiquetage. Écrit avec le plugin, pas de reconstruction |
| `exemples/validation/*.json`, `tests/contre-exemples-validation.json` | Exemples valides et 19 contre-exemples (une seule modification de l'exemple complet chacun) |
| `reflexion.schema.json` | Le **journal de bord réflexif** (T-14, lot 2), version `0.1.0`, fichier facultatif de l'archive (`reflexion.json`) : une liste d'entrées numérotées de 1 à 200 (numéro, date d'ouverture, version, date d'enregistrement, texte), `officiel` **toujours `false`**, une phrase qui dit ce qu'est le fichier. **N'entre que dans l'archive que son auteur exporte lui-même** : jamais dans une sauvegarde faite par un tiers. Écrit avec le plugin, pas de reconstruction |
| `exemples/reflexion/*.json`, `tests/contre-exemples-reflexion.json` | Exemples valides et 20 contre-exemples (une seule modification de l'exemple complet chacun) |
| `pieces.schema.json` | Les **pièces justificatives** (T-13, lot 3), version `0.1.0`, fichier facultatif de l'archive (`pieces.json`) : une entrée par pièce, retirées comprises (identifiant, déclaration, genre fichier ou lien, nature d'une liste fermée, intitulé facultatif, dates, et pour un fichier le type, la taille et l'empreinte SHA-256 ; pour un lien l'adresse ; l'attestation en vigueur qui la couvrait). `officiel` **toujours `false`**. **Il dit qu'une pièce existe, jamais ce qu'elle contient** : les octets (`pieces/<identifiant>.<extension>`) et l'adresse d'un lien n'entrent que dans l'archive que le vaeiste exporte lui-même et dans la sauvegarde conservée sur la plateforme, jamais dans l'export d'un tiers. **Le nom d'origine d'un fichier n'est jamais gardé** : aucune propriété ne lui est destinée. Un plugin antérieur à T-13 réimporte les déclarations et perd les pièces sans le dire |
| `exemples/pieces/*.json`, `tests/contre-exemples-pieces.json` | Exemples valides et 35 contre-exemples (une seule modification de l'exemple complet chacun) |
| `hors_activite.schema.json` | Les **compétences que le vaeiste dit hors de son activité** (T-42, lot 3), version `0.1.0`, fichier facultatif de l'archive (`hors_activite.json`) : une phrase qui dit que c'est la parole du vaeiste et qu'elle n'exempte de rien, puis une entrée par compétence ou bloc (fiche, rang, intitulé publié facultatif, note facultative de 255 caractères, date). Aucune propriété qui porterait un effet. Les bornes de la liste (2 000 entrées) sont tenues par l'importeur, le validateur du plugin ne connaissant pas `maxItems` |
| `exemples/hors_activite/*.json`, `tests/contre-exemples-hors_activite.json` | Exemples valides et 18 contre-exemples (une seule modification de l'exemple complet chacun) |
| `positionnement.schema.json` | Les **positionnements de la structure** (T-90, lot 3), version `0.1.0`, fichier facultatif de l'archive (`positionnement.json`) : une phrase qui dit que ce n'est **pas une validation** (seul le jury valide, hors de la plateforme), `officiel` toujours faux, puis **toutes les lignes** d'évaluation (une compétence, par le responsable qui suit) et de certification (un bloc, par la direction), la ligne qui fait foi marquée (`fait_foi`). Chaque ligne : identifiant opaque, fiche, rang, statut, motif écrit, empreinte SHA-256 d'une forme canonique de la ligne (vérifiée à l'import), date d'écriture, auteur (identifiant opaque, absent s'il a été effacé) ; une certification ajoute le jour de la décision (`AAAA-MM-JJ`, jamais converti par un fuseau) et, quand une compétence du bloc n'était pas suffisante, le motif de l'alerte ; la date facultative `valable_jusqu_au` (`AAAA-MM-JJ`, au plus le 2100-01-01) fait calculer « à renouveler ». Les bornes (5 000 lignes pour le fichier) sont tenues par l'importeur, le validateur du plugin ne connaissant pas `maxItems`. Restauré à l'import clé par clé (fiche, rang), tout ou rien |
| `exemples/positionnement/*.json`, `tests/contre-exemples-positionnement.json` | Exemples valides et 38 contre-exemples (une seule modification de l'exemple complet chacun) |
| `autopositionnement.schema.json` | L'**auto-positionnement du vaeiste** (priorité 1, lot 1a), version `0.1.0`, fichier facultatif de l'archive (`autopositionnement.json`) : une phrase qui dit que c'est la parole du vaeiste sur lui-même et **pas une validation** (seul le jury valide, hors de la plateforme ; rien n'en est calculé), `officiel` toujours faux, puis **toutes les lignes** du vaeiste (une par écriture, retraits compris), la ligne qui fait foi marquée (`fait_foi`). Chaque ligne : identifiant opaque, fiche, rang (vide pour le référentiel entier), intitulé publié facultatif, indice du palier (`palier`, 0 pour un retrait), libellé du palier tel qu'il se lisait à l'écriture, justification (texte simple ou Markdown, jamais HTML, 10 000 caractères au plus) et son `format`, empreinte SHA-256 d'une forme canonique de la ligne (vérifiée à l'import), date d'écriture. Le vaeiste est le seul auteur : aucune propriété d'auteur. **N'entre que dans l'archive du vaeiste** (export propre, export par un tiers, sauvegarde), jamais dans l'archive de la structure. Les bornes (20 000 lignes pour le fichier, 200 par élément) sont tenues par l'importeur, le validateur du plugin ne connaissant pas `maxItems`. Restauré à l'import clé par clé, une ligne déjà présente ou un élément que le site ne tient pas est laissé tel quel et compté. Un plugin antérieur au lot 1a réimporte les déclarations et perd ce fichier sans le dire |
| `exemples/autopositionnement/*.json`, `tests/contre-exemples-autopositionnement.json` | Exemples valides (complet, minimal) et 35 contre-exemples (une seule modification de l'exemple complet chacun) |
| `situations.schema.json`, `exemples/situations/*.json`, `tests/contre-exemples-situations.json` | Les **situations de travail du vaeiste** (lot 1b), version `0.1.0`, fichier facultatif de l'archive (`situations.json`) : une phrase qui dit que rien n'y est attesté ni validé (parole du vaeiste et, à part, annotations de responsables avec son accord ; seul le jury valide), `officiel` toujours faux, puis les situations (identifiant, fiche, emplacement 1 à 30, titre de 60, période, cadre de 100, étiquettes `^[A-Z0-9]{1,8}$`, toutes les versions du texte avec empreinte SHA-256 et instantané du titre, de la période, du cadre et des étiquettes, brouillon en cours, pièces citées avec la partie concernée), la liste des pièces (comme `pieces.json`, nature `writing` en plus ; octets sous `pieces-situations/<identifiant>.<extension>` et adresses dans l'archive du vaeiste et la sauvegarde seulement), toutes les annotations (cible situation ou élément de l'auto-positionnement, auteur par identifiant opaque, texte de 4 000, retrait qui nomme l'annotation retirée, empreinte vérifiée à l'import) et toutes les lignes d'accord (responsable par identifiant opaque). Bornes tenues par l'importeur : 800 lignes d'accord, un retrait qui ne suit pas un accord donné au même responsable et non encore retiré, ou 20 000 annotations refusent le fichier ; 30 situations au plus par fiche (emplacement 1 à 30, jamais deux fois la même adresse), jamais de borne sur le fichier ; 200 versions par situation laissent la situation ; 200 annotations par cible (lignes `active`, retirées comprises ; les lignes de retrait ne comptent pas), 400 accords donnés ou 800 lignes d'accord pour le vaeiste (lignes du site comprises) laissent la cible ou tous les accords ; une situation revient sans l'étiquette ou le lien qui ne peut pas être restauré (étiquette inconnue, pièce d'une autre fiche, pièce que l'archive liste sans octets ni adresse, plus de 5 pièces actives), chacun laissé et compté. Une annotation ou un accord restauré ne désigne jamais un compte du site et n'ouvre aucune annotation. Jamais dans `validation.json` ni le PDF. Exemples valides (complet, minimal) et 77 contre-exemples (une seule modification de l'exemple complet chacun) |
| `commun/identifiant.schema.json` | L'identifiant opaque, ULID ou UUID v4 |
| `exemples/*.json` | Preuves valides, données fictives |
| `tests/contre-exemples.json` | Preuves invalides, chacune une seule modification de `exemples/preuve-complete.json` |
| `faisabilite.schema.json` | Les rubriques de **texte** du dossier de faisabilité (T-10, lot 4b), version `0.1.0`. **Écrit avec le plugin, jamais perdu** : pas de reconstruction. Fichier facultatif de l'archive (`faisabilite.json`) |
| `exemples/faisabilite/*.json` | Rubriques valides, données fictives |
| `tests/contre-exemples-faisabilite.json` | Rubriques invalides, chacune une seule modification de `exemples/faisabilite/faisabilite-complete.json` |

`faisabilite.schema.json` ne dit **pas** quels codes de rubrique sont admis : le catalogue du plugin le
dit, et l'importeur refuse tout code qui n'est pas une rubrique de texte (identité, statut et coordonnées
n'entrent jamais dans l'archive, MC-13). Ce que le schéma contraint : la forme, un emplacement de 0 à 99,
un numéro de version d'au moins 1, une date-heure complète, un texte de 100 000 caractères au plus.

Contrôle : `make schema`, ou bien `AJV_MODULES=<dossier node_modules> node scripts/verifier-schema.mjs .`
Il exige ajv 8 et ajv-formats 3. Il tourne aussi en CI.

## D'où vient chaque règle

**CODE** : le nom ou la règle est cité par le code. **DÉDUIT** : la règle vient du code, le nom
est choisi ici. **INVENTÉ** : choix de la reconstruction.

| Règle | Source | Degré |
|---|---|---|
| `id`, `personne`, `competence`, `structure` sont des identifiants opaques, ULID ou UUID v4 | `classes/local/identity_map.php`, en-tête | CODE |
| Un identifiant entier est refusé (« le projet avait écrit un contre-exemple pour ce cas ») | `identity_map.php`, en-tête | CODE |
| Motif ULID `^[0-7][0-9A-HJKMNP-TV-Z]{25}$` | `identity_map::ULID_PATTERN` | CODE |
| Le fichier `commun/identifiant.schema.json` existe | `tests/identity_map_test.php`, en-tête | CODE |
| L'identifiant de plateforme vit dans `correspondances[]`, jamais comme clé | `identity_map.php`, en-tête | CODE (nom) ; forme des éléments INVENTÉE |
| Une attestation `retiree` ne porte qu'une date et un motif | `classes/local/attestation_manager.php`, en-tête | CODE |
| Motifs de retrait `rgpd`, `erreur`, `contestation`, liste fermée | `attestation_manager::WITHDRAWAL_REASONS` | CODE |
| Rang `^[A-Z0-9]{1,8}$` | `importer::RANK_PATTERN` (« le rang tel que le schéma pivot le définit ») | CODE |
| Code de fiche, 32 caractères au plus | `evidence_manager::CODE_MAXLENGTH` | DÉDUIT |
| Version de la fiche au moment de la déclaration | `db/install.xml`, `ficheupdated` | DÉDUIT |
| Cadre pris dans une liste fermée | `evidence_manager::SETTINGS` (liste **provisoire**, point ouvert PO-3) | DÉDUIT |
| Précision sur le cadre, 255 caractères au plus | `db/install.xml`, `settingdetail` | DÉDUIT |
| Récit en texte ou en Markdown, jamais en HTML | `evidence_manager::FORMATS` | DÉDUIT |
| Fin de période ≥ début | `db/install.xml`, `periodend` | DÉDUIT — hors schéma, vérifié par le script |
| Attestation en vigueur : signataire, fonction, texte signé, date | `db/install.xml`, `tool_vae_attest` | DÉDUIT ; statut `en_vigueur` INVENTÉ |
| Horodatages complets (ISO 8601, UTC), période comprise | — | INVENTÉ : réimport sans perte ; une date seule dérive d'un jour selon le fuseau, défaut déjà mesuré sur `corelabel` |
| Une attestation retirée ne porte pas d'identifiant | — | DÉDUIT : un ULID contient l'heure de sa création, donc celle de la signature, que le retrait efface (`attestedat`) |
| Valeurs de correspondance en chaîne | — | INVENTÉ : un entier sur 64 bits ne survit pas à tous les lecteurs JSON |
| UUID v1 refusé | — | INVENTÉ : il contient une adresse matérielle et une heure |
| Aucune propriété hors schéma, à tous les niveaux | conséquence des deux règles CODE ci-dessus | DÉDUIT |

Le code annonçait **seize** contre-exemples sur l'attestation retirée dans le jeu d'origine. Celui-ci
en compte huit, plus trente et un sur le reste.

## Ce que ce schéma ne couvre pas encore

Il décrit **une preuve**. Le lot 8 doit encore définir :

- le **schéma JSON de l'enveloppe d'export** : le manifeste, les empreintes SHA-256, l'horodatage et la version des schémas sont déjà écrits par `exporter.php` (`manifeste.json`), mais aucun schéma JSON ne les décrit (MC-1) ;
- le **référentiel** joint au dossier : intitulés des compétences, licence et attribution
  (Licence Ouverte 2.0 exige les deux, avec la date de mise à jour de la source) ;
- l'**état d'ancrage** d'une preuve : compétence absente de la fiche, renommée, déliée ;
- le **critère 6** du PRP, perdu avec lui : le lot 8 le redéfinit et l'écrit dans le dépôt.
