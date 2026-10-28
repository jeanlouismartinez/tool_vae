# VAE - pathway (`tool_vae`)

A Moodle **admin tool** for people going through a **VAE** (*validation des acquis de l'expérience*, the French process by which a professional obtains a qualification from the experience already gained at work). It lets a learner build, over time, a dated, attributed and verifiable trace of the skills acquired in the workplace.

The plugin helps to collect and to format. It **does not judge**: it says nothing about whether a dossier is admissible, never pre-assesses, and gives no opinion on the likely outcome. The referent attests, the certifying body and its panel decide.

> The French full documentation of the project is in [`README.fr.md`](README.fr.md). This file is a summary for Moodle 5.3, the only branch this plugin supports.

## Status

- **Version 01.5.3 (Moodle 5.3)**, build number `2026102802`, maturity **alpha**.
- The release name reads "generation . Moodle branch": `01` is the first version intended for the Moodle plugins directory, `5.3` is the one Moodle branch it supports. What Moodle compares to decide on an update is the build number in `version.php`, not this label.
- **Tested** in continuous integration on PostgreSQL and MariaDB with Moodle 5.3: 1,376 PHPUnit tests, plus coding standard, PHPDoc, plugin validation, upgrade savepoints and Behat scenarios.
- **Never used by real learners so far.** The screen that fetches the export of France compétences from data.gouv.fr has never been run against the real site, and the import screen has never been used by a person (the automated scenarios run on invented files).

## Requirements

- **Moodle 5.3 LTS** and **PHP 8.3**.
- The competencies subsystem enabled (*Site administration → Advanced features → Enable competencies*). Without it the plugin explains why and stops: it feeds `core_competency`, it does not replace it.
- This plugin supports **LTS releases only**. It declares `supported = [503, 503]` and `incompatible = 504`: Moodle 5.4 and later refuse it, so that an upgrade of the core never silently runs the plugin where nobody tested it.

## Installation

Copy the plugin folder to `public/admin/tool/vae` in your Moodle 5.3 tree (the folder must be named `vae`), then open *Site administration → Notifications*. Or upload the zip in *Site administration → Plugins → Install plugins*.

Then, **before anyone can use it, create and assign roles**: no capability that touches personal data is granted by default (see "Capabilities" below).

## What it does

Moodle already has a complete competency subsystem. This plugin **feeds** it and **extends** it; it never re-implements it.

- **Declaration.** A learner declares that they exercised an activity, against a competency of an imported certification record, with the period and the setting of the work. The narrative is plain text.
- **Attestation.** A manager who follows the learner reads the narrative and attests the declaration. An attestation is a row of this plugin, not a core API object: it can be **withdrawn** without destroying the learner's evidence, and the dossier then says that an attestation existed and was withdrawn, when, and why.
- **Coverage table.** The learner sees, for each competency of the records declared against, how many declarations and valid attestations exist. It is visible to the learner only and never concludes anything.
- **Certification records from France compétences.** An administrator imports records (RNCP and RS) from the daily export of France compétences, on one screen with two ways to get the file: upload the zip downloaded from data.gouv.fr, or click "fetch the latest export" and the server downloads it. A preview shows, for each number, what the import will do; nothing is written before confirmation. A four-column CSV import and a command-line script remain as fallbacks.
- **Feasibility dossier** (*dossier de faisabilité*), written section by section: automatic drafts, saved versions compared word by word and restorable, identity and status fields (read from the Moodle account where possible), a **deposit** (a dated milestone with a SHA-256 hash of what was saved), a manager's **opinion** on a deposit, and a manager's **question** on a declaration before attestation.
- **Reminders.** If the administrator sets a number of days, the managers who can attest are reminded once that a declaration is still waiting.
- **Exports.** A frozen **archive** of the dossier (JSON files validated against shipped schemas, a manifest of SHA-256 hashes, and a printable `dossier.pdf`), editable **.docx** and **.odt** copies, and an optional weekly copy into the learner's private files (off by default, the last two kept). An archive can be re-imported on another site, under conditions (see below). A dossier can also be **backed up on Moodle**, and every dossier is backed up automatically when the plugin is uninstalled through the administration screen or `admin/cli/uninstall_plugins.php`.
- **Notifications** by Moodle messaging (twelve message types). No message ever reproduces the narrative; the learner's name never appears in a subject line. They are sent by cron.

### Features that are closed by default

Each of these is switched on by an administrator in the plugin settings, after the site has reviewed it:

| Feature | Setting | What it is |
|---|---|---|
| Supporting documents | `piecesopen` | A file (PDF, ODT, DOCX, JPEG, PNG) or a link attached to a declaration, with a nature from a closed list. The type is read from the content, the original file name is never kept, an antivirus is required (files are refused without one unless the administrator accepts it in writing). The plugin never reads nor judges a document. |
| Validation-rule links | `refopen` | Up to five links per imported record, to the page where the certifier publishes its validation rule. The plugin never copies nor applies a rule. |
| "Outside my activity" marker | `scopeopen` | The learner states that a competency does not belong to their activity. A statement, not an exemption: the panel decides. |
| AI rewording help | `aiopen` | The learner may ask for another wording of a text they wrote. Needs the `tool/vae:useai` capability, exactly one active AI text provider, and a confirmation at each sending. Nothing is saved by the plugin, and **no AI model is shipped**. |

Block referents (*Site administration → Competencies → Referents of the blocs*) only decide **who is notified first** of a declaration on a block; they grant and remove no right.

## Capabilities

**No capability that touches data has a default archetype. This is deliberate**: the archetype `user` is granted at system level, and the user context is a child of the system context, so a capability given to it would be held by every user over every other user. The plugin proves membership in PHP (`\tool_vae\local\access`), never through an inherited capability. You must create roles and assign them.

The learner's role is created with the **"Student" role archetype template**, chosen in the role form (not the "MODEL: Student" shortcut on the first screen, which would copy all 81 student rights onto the whole site).

| Capability | Context | Opens |
|---|---|---|
| `declare` | user | declare an activity, about oneself |
| `viewown` | user | read one's own dossier |
| `attest` | user | attest the declaration of a learner one follows |
| `viewassigned` | user | read the dossiers of the learners one follows (linked by cohort) |
| `viewany` | system | read all dossiers (management) |
| `export` | user | export a dossier as an archive (with `viewown` for one's own, `viewany` for others) |
| `withdrawany` | user | withdraw any attestation of a dossier one can read |
| `import` | system | import certification records; administrator by default |
| `importdossier` | system | re-import an archive and back up any learner's dossier; administrator by default |
| `draftown` | user | write the sections of one's own feasibility dossier |
| `draftview` | user | read sections and versions of another dossier one can view |
| `identityview` | user | read identity and status in a feasibility dossier |
| `readdeposit` | user | read the deposits of a learner one can view |
| `writeopinion` | user | write an opinion on a deposit (also needs `readdeposit`) |
| `linkcourse` | course | link a whole imported record to the competencies of a course; manager by default |
| `useai` | user | use the AI rewording help (when enabled) |

Two rules that the table does not show: `attest` is not enough on its own (attesting requires being able to **read** the dossier, by following the learner or holding `viewany`), and `export` is never enough on its own.

**Following a learner** means holding `viewassigned` in the learner's user context, directly or through a cohort role (`tool_cohortroles`). It is the only way to read another person's dossier without `viewany`.

The core also asks for its own rights: the learner needs `moodle/competency:competencyview` when a declaration is saved, and three actions need a core manager right (`competencymanage` for the import, `coursecompetencymanage` for linking a course, `userevidencemanage` for backing up or re-importing a dossier).

## Personal data

- The Moodle **Privacy API** is implemented for the eighteen tables of the plugin that hold personal data; two others (obsolete competency identifiers, public validation-rule links) are declared exempt.
- **Erasing a learner** removes their dossier, with its backups and editable copies. **Erasing a manager** does *not* remove the dossier: their identity is taken out of the attestation, which is marked withdrawn and dated, so that the learner keeps a proof saying "attested, then withdrawn". The learner and the managers who follow them are notified.
- **GDPR compliance is the responsibility of the site that installs the plugin.** The site is the data controller; the author receives no data. For a learner to take the dossier away before their account is deleted, the site must give them the `tool/vae:export` capability.
- The archive does **not** carry birth date, nationality, address or phone number: it keeps opaque identifiers. It does not carry the deposits, the managers' opinions, nor the questions and answers; they are in Moodle's own personal data export, which can be read but not re-imported.
- Texts sent to an AI provider and the answers stay in the core's tables. The plugin does not delete them itself; Moodle empties them only when a data deletion request is approved, under conditions the administrator must know.

## Known limits

- **Date selector**: three drop-down lists (day, month, year); a month-year selector is not built.
- **Archive from another site**: each site numbers its own competencies the first time a dossier leaves it. An archive from another site is refused as a whole, with a message naming the competencies concerned, if the receiving site already numbered them differently, or if the learner's account already has another identifier there. A screen for a person to match competencies between two sites is decided, not built.
- **A line with a competency rank out of format** (for example `c7` for `C7`), written by an earlier version of the plugin, does not block the export: the archive carries a warning naming the declaration, but cannot be re-imported as it is. The editable copy remains possible.
- **A record hidden in Moodle accepts no new declaration.** Declarations already made do not change.
- Nobody has used the plugin end to end yet.

## Licence and data

**GNU GPL v3 or later**, see `LICENSE` (the licence of Moodle itself). Copyright holder: **Jean-Louis Martinez**, jeanlouis@martinez.pro.

The imported certification records are **data** produced by **France compétences** and published under the **Licence Ouverte 2.0**. They are **never shipped with the plugin**: the administrator uploads the export, or has the server fetch it on a click, and it lives in the site's database. The attribution and the update date are shown wherever a record is presented, and no competency title is reworded.

**Open point.** The Licence Ouverte 2.0 declares compatibility only with the previous Licence Ouverte, the UK Open Government Licence, CC-BY and ODC-BY, never with a copyleft licence. Whether using that data alongside GPL code raises an issue has not been settled by a legal opinion. The choice of the GPL for the plugin does not settle it, because the question is about the data, not about the code.

## Credits and names

The component was named `tool_referentiel` up to version 0.10.1. The word "Référentiel" in the former name refers to the plugin of Jean Fruitet ([`moodle_referentiel`](https://github.com/jfruitet/moodle_referentiel)). The displayed name is "VAE - pathway" in English and "VAE - cheminement" in French. Both languages are provided in the interface.

---

*Fil d'Argent, Jean-Louis Martinez, jeanlouis@martinez.pro*
