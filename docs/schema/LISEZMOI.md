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
| `referentiel.json` (pas de schéma) | Facultatifs depuis T-33 : `bloc` (rang du bloc) sur une compétence, `blocs` (rang et intitulé) sur une fiche à blocs. Absents d'une fiche à plat. `regle_validation` (T-41, facultatif) : liste de `intitule`, `adresse`, `verifie_le` (ou null), seulement si le site a ouvert `refopen`. Non relu à l'import : les adresses sont celles du site, pas du candidat |
| `validation.schema.json` | La **préparation du dossier de validation** (T-11, lot 2), version `0.1.0`, fichier facultatif de l'archive (`validation.json`) : une liste de rubriques de texte (fiche, code, bloc, partie, version, date, texte), `officiel` **toujours `false`**, la phrase d'étiquetage. Écrit avec le plugin, pas de reconstruction |
| `exemples/validation/*.json`, `tests/contre-exemples-validation.json` | Exemples valides et 19 contre-exemples (une seule modification de l'exemple complet chacun) |
| `reflexion.schema.json` | Le **journal de bord réflexif** (T-14, lot 2), version `0.1.0`, fichier facultatif de l'archive (`reflexion.json`) : une liste d'entrées numérotées de 1 à 200 (numéro, date d'ouverture, version, date d'enregistrement, texte), `officiel` **toujours `false`**, une phrase qui dit ce qu'est le fichier. **N'entre que dans l'archive que son auteur exporte lui-même** : jamais dans une sauvegarde faite par un tiers. Écrit avec le plugin, pas de reconstruction |
| `exemples/reflexion/*.json`, `tests/contre-exemples-reflexion.json` | Exemples valides et 20 contre-exemples (une seule modification de l'exemple complet chacun) |
| `pieces.schema.json` | Les **pièces justificatives** (T-13, lot 3), version `0.1.0`, fichier facultatif de l'archive (`pieces.json`) : une entrée par pièce, retirées comprises (identifiant, déclaration, genre fichier ou lien, nature d'une liste fermée, intitulé facultatif, dates, et pour un fichier le type, la taille et l'empreinte SHA-256 ; pour un lien l'adresse ; l'attestation en vigueur qui la couvrait). `officiel` **toujours `false`**. **Il dit qu'une pièce existe, jamais ce qu'elle contient** : les octets (`pieces/<identifiant>.<extension>`) et l'adresse d'un lien n'entrent que dans l'archive que le candidat exporte lui-même et dans la sauvegarde conservée sur la plateforme, jamais dans l'export d'un tiers. **Le nom d'origine d'un fichier n'est jamais gardé** : aucune propriété ne lui est destinée. Un plugin antérieur à T-13 réimporte les déclarations et perd les pièces sans le dire |
| `exemples/pieces/*.json`, `tests/contre-exemples-pieces.json` | Exemples valides et 35 contre-exemples (une seule modification de l'exemple complet chacun) |
| `hors_activite.schema.json` | Les **compétences que le candidat dit hors de son activité** (T-42, lot 3), version `0.1.0`, fichier facultatif de l'archive (`hors_activite.json`) : une phrase qui dit que c'est la parole du candidat et qu'elle n'exempte de rien, puis une entrée par compétence ou bloc (fiche, rang, intitulé publié facultatif, note facultative de 255 caractères, date). Aucune propriété qui porterait un effet. Les bornes de la liste (2 000 entrées) sont tenues par l'importeur, le validateur du plugin ne connaissant pas `maxItems` |
| `exemples/hors_activite/*.json`, `tests/contre-exemples-hors_activite.json` | Exemples valides et 18 contre-exemples (une seule modification de l'exemple complet chacun) |
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
en compte huit, plus vingt-sept sur le reste.

## Ce que ce schéma ne couvre pas encore

Il décrit **une preuve**. Le lot 8 doit encore définir :

- l'**enveloppe d'export** : manifeste, empreintes SHA-256, horodatage, version du schéma (MC-1) ;
- le **référentiel** joint au dossier : intitulés des compétences, licence et attribution
  (Licence Ouverte 2.0 exige les deux, avec la date de mise à jour de la source) ;
- l'**état d'ancrage** d'une preuve : compétence absente de la fiche, renommée, déliée ;
- le **critère 6** du PRP, perdu avec lui : le lot 8 le redéfinit et l'écrit dans le dépôt.
