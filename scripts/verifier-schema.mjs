/**
 * Verifie le schema pivot reconstruit (docs/schema) contre ses exemples et ses contre-exemples.
 *
 * Chaque exemple de docs/schema/exemples doit etre accepte. Chaque contre-exemple de
 * docs/schema/tests/contre-exemples.json est UNE modification de l'exemple complet : il doit
 * etre refuse, et il ne peut l'etre que pour la raison qu'il nomme. Un contre-exemple accepte
 * signifie qu'une regle du schema a disparu.
 *
 * Les regles qu'un schema JSON ne sait pas exprimer (fin de periode >= debut) sont verifiees ici,
 * a la suite du schema.
 *
 * Exige ajv 8 et ajv-formats 3. Le dossier node_modules qui les contient est donne par la
 * variable AJV_MODULES ; a defaut, ils sont cherches a cote de ce script.
 *
 * Avec --preuves <dossier>, chaque fichier .json du dossier doit aussi etre accepte, et le dossier
 * ne doit pas etre vide : c'est ainsi que la CI valide les preuves que l'export a reellement
 * produites pendant PHPUnit (voir tests/exporter_test.php). Un dossier vide ferait passer le
 * controle sans rien controler.
 *
 * Usage : AJV_MODULES=/chemin/node_modules node scripts/verifier-schema.mjs [racine] [--preuves <dossier>]
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import { createRequire } from 'node:module';
import { readFileSync, readdirSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const ici = dirname(fileURLToPath(import.meta.url));
const args = process.argv.slice(2);
const option = args.indexOf('--preuves');
const preuvesProduites = option >= 0 ? args.splice(option, 2)[1] : null;
const racine = resolve(args[0] ?? join(ici, '..'));
const dossier = join(racine, 'docs', 'schema');
const lire = (chemin) => JSON.parse(readFileSync(chemin, 'utf8'));

const modules = process.env.AJV_MODULES;
const require = createRequire(modules ? join(resolve(modules), 'resolveur.js') : import.meta.url);
const Ajv2020 = require('ajv/dist/2020').default;
const ajouterFormats = require('ajv-formats').default;

const ajv = new Ajv2020({ allErrors: true, strict: true });
ajouterFormats(ajv);
ajv.addSchema(lire(join(dossier, 'commun', 'identifiant.schema.json')));
const valider = ajv.compile(lire(join(dossier, 'preuve.schema.json')));
const validerFaisabilite = ajv.compile(lire(join(dossier, 'faisabilite.schema.json')));
const validerValidation = ajv.compile(lire(join(dossier, 'validation.schema.json')));
const validerReflexion = ajv.compile(lire(join(dossier, 'reflexion.schema.json')));
const validerPieces = ajv.compile(lire(join(dossier, 'pieces.schema.json')));
const validerHorsActivite = ajv.compile(lire(join(dossier, 'hors_activite.schema.json')));

/** Regles hors schema. Ne supposent rien de la forme : le schema a pu la refuser. */
function reglesHorsSchema(preuve) {
    const erreurs = [];
    const debut = Date.parse(preuve?.periode?.debut);
    const fin = Date.parse(preuve?.periode?.fin);
    if (!Number.isNaN(debut) && !Number.isNaN(fin) && fin < debut) {
        erreurs.push('/periode la fin precede le debut');
    }
    if ((preuve?.reportee_depuis === undefined) !== (preuve?.reportee_le === undefined)) {
        erreurs.push('/ reportee_depuis et reportee_le vont ensemble');
    }
    return erreurs;
}

function erreursDe(preuve) {
    const erreurs = valider(preuve) ? [] : valider.errors.map((e) => `${e.instancePath || '/'} ${e.message}`);
    return erreurs.concat(reglesHorsSchema(preuve));
}

function appliquer(base, cas) {
    const copie = structuredClone(base);
    const chemin = [...cas.chemin];
    const cle = chemin.pop();
    let parent = copie;
    for (const pas of chemin) {
        if (parent[pas] === undefined) {
            throw new Error(`contre-exemple ${cas.nom} : chemin inexistant dans la base (${cas.chemin.join('.')})`);
        }
        parent = parent[pas];
    }
    if (cas.supprimer) {
        if (!(cle in parent)) {
            throw new Error(`contre-exemple ${cas.nom} : rien a supprimer en ${cas.chemin.join('.')}`);
        }
        delete parent[cle];
    } else {
        parent[cle] = cas.repeter !== undefined ? cas.repeter.repeat(cas.fois) : cas.valeur;
    }
    return copie;
}

let echecs = 0;
const exemples = readdirSync(join(dossier, 'exemples')).filter((f) => f.endsWith('.json')).sort();
for (const fichier of exemples) {
    const erreurs = erreursDe(lire(join(dossier, 'exemples', fichier)));
    if (erreurs.length) {
        echecs++;
        console.log(`  ✗ exemple refuse : ${fichier}\n      ${erreurs.join('\n      ')}`);
    }
}

const jeu = lire(join(dossier, 'tests', 'contre-exemples.json'));
const base = lire(join(dossier, jeu.base));
const noms = new Set();
for (const cas of jeu.cas) {
    if (noms.has(cas.nom)) {
        echecs++;
        console.log(`  ✗ contre-exemple en double : ${cas.nom}`);
    }
    noms.add(cas.nom);
    if (erreursDe(appliquer(base, cas)).length === 0) {
        echecs++;
        console.log(`  ✗ contre-exemple accepte : ${cas.nom} — ${cas.regle}`);
    }
}

// Les rubriques de texte du dossier de faisabilite (T-10, lot 4b) : meme methode, autre schema.
const erreursFaisabilite = (rubriques) => (validerFaisabilite(rubriques)
    ? []
    : validerFaisabilite.errors.map((e) => `${e.instancePath || '/'} ${e.message}`));
const exemplesFaisabilite = readdirSync(join(dossier, 'exemples', 'faisabilite')).filter((f) => f.endsWith('.json')).sort();
for (const fichier of exemplesFaisabilite) {
    const erreurs = erreursFaisabilite(lire(join(dossier, 'exemples', 'faisabilite', fichier)));
    if (erreurs.length) {
        echecs++;
        console.log(`  ✗ exemple de faisabilite refuse : ${fichier}\n      ${erreurs.join('\n      ')}`);
    }
}
const jeuFaisabilite = lire(join(dossier, 'tests', 'contre-exemples-faisabilite.json'));
const baseFaisabilite = lire(join(dossier, jeuFaisabilite.base));
const nomsFaisabilite = new Set();
for (const cas of jeuFaisabilite.cas) {
    if (nomsFaisabilite.has(cas.nom)) {
        echecs++;
        console.log(`  ✗ contre-exemple de faisabilite en double : ${cas.nom}`);
    }
    nomsFaisabilite.add(cas.nom);
    if (erreursFaisabilite(appliquer(baseFaisabilite, cas)).length === 0) {
        echecs++;
        console.log(`  ✗ contre-exemple de faisabilite accepte : ${cas.nom} - ${cas.regle}`);
    }
}

// La preparation du dossier de validation (T-11, lot 2) : meme methode, troisieme schema.
const erreursValidation = (document) => (validerValidation(document)
    ? []
    : validerValidation.errors.map((e) => `${e.instancePath || '/'} ${e.message}`));
const exemplesValidation = readdirSync(join(dossier, 'exemples', 'validation')).filter((f) => f.endsWith('.json')).sort();
for (const fichier of exemplesValidation) {
    const erreurs = erreursValidation(lire(join(dossier, 'exemples', 'validation', fichier)));
    if (erreurs.length) {
        echecs++;
        console.log(`  ✗ exemple de validation refuse : ${fichier}\n      ${erreurs.join('\n      ')}`);
    }
}
const jeuValidation = lire(join(dossier, 'tests', 'contre-exemples-validation.json'));
const baseValidation = lire(join(dossier, jeuValidation.base));
const nomsValidation = new Set();
for (const cas of jeuValidation.cas) {
    if (nomsValidation.has(cas.nom)) {
        echecs++;
        console.log(`  ✗ contre-exemple de validation en double : ${cas.nom}`);
    }
    nomsValidation.add(cas.nom);
    if (erreursValidation(appliquer(baseValidation, cas)).length === 0) {
        echecs++;
        console.log(`  ✗ contre-exemple de validation accepte : ${cas.nom} - ${cas.regle}`);
    }
}

// Le journal de bord reflexif (T-14, lot 2) : meme methode, quatrieme schema.
const erreursReflexion = (document) => (validerReflexion(document)
    ? []
    : validerReflexion.errors.map((e) => `${e.instancePath || '/'} ${e.message}`));
const exemplesReflexion = readdirSync(join(dossier, 'exemples', 'reflexion')).filter((f) => f.endsWith('.json')).sort();
for (const fichier of exemplesReflexion) {
    const erreurs = erreursReflexion(lire(join(dossier, 'exemples', 'reflexion', fichier)));
    if (erreurs.length) {
        echecs++;
        console.log(`  ✗ exemple de reflexion refuse : ${fichier}\n      ${erreurs.join('\n      ')}`);
    }
}
const jeuReflexion = lire(join(dossier, 'tests', 'contre-exemples-reflexion.json'));
const baseReflexion = lire(join(dossier, jeuReflexion.base));
const nomsReflexion = new Set();
for (const cas of jeuReflexion.cas) {
    if (nomsReflexion.has(cas.nom)) {
        echecs++;
        console.log(`  ✗ contre-exemple de reflexion en double : ${cas.nom}`);
    }
    nomsReflexion.add(cas.nom);
    if (erreursReflexion(appliquer(baseReflexion, cas)).length === 0) {
        echecs++;
        console.log(`  ✗ contre-exemple de reflexion accepte : ${cas.nom} - ${cas.regle}`);
    }
}

// Les pieces justificatives (T-13, lot 3) : meme methode, cinquieme schema.
const erreursPieces = (document) => (validerPieces(document)
    ? []
    : validerPieces.errors.map((e) => `${e.instancePath || '/'} ${e.message}`));
const exemplesPieces = readdirSync(join(dossier, 'exemples', 'pieces')).filter((f) => f.endsWith('.json')).sort();
for (const fichier of exemplesPieces) {
    const erreurs = erreursPieces(lire(join(dossier, 'exemples', 'pieces', fichier)));
    if (erreurs.length) {
        echecs++;
        console.log(`  ✗ exemple de pieces refuse : ${fichier}\n      ${erreurs.join('\n      ')}`);
    }
}
const jeuPieces = lire(join(dossier, 'tests', 'contre-exemples-pieces.json'));
const basePieces = lire(join(dossier, jeuPieces.base));
const nomsPieces = new Set();
for (const cas of jeuPieces.cas) {
    if (nomsPieces.has(cas.nom)) {
        echecs++;
        console.log(`  ✗ contre-exemple de pieces en double : ${cas.nom}`);
    }
    nomsPieces.add(cas.nom);
    if (erreursPieces(appliquer(basePieces, cas)).length === 0) {
        echecs++;
        console.log(`  ✗ contre-exemple de pieces accepte : ${cas.nom} - ${cas.regle}`);
    }
}

// Les competences dites hors de l'activite (T-42, lot 3) : meme methode, sixieme schema.
const erreursHorsActivite = (document) => (validerHorsActivite(document)
    ? []
    : validerHorsActivite.errors.map((e) => `${e.instancePath || '/'} ${e.message}`));
const exemplesHorsActivite = readdirSync(join(dossier, 'exemples', 'hors_activite')).filter((f) => f.endsWith('.json')).sort();
for (const fichier of exemplesHorsActivite) {
    const erreurs = erreursHorsActivite(lire(join(dossier, 'exemples', 'hors_activite', fichier)));
    if (erreurs.length) {
        echecs++;
        console.log(`  ✗ exemple de hors_activite refuse : ${fichier}\n      ${erreurs.join('\n      ')}`);
    }
}
const jeuHorsActivite = lire(join(dossier, 'tests', 'contre-exemples-hors_activite.json'));
const baseHorsActivite = lire(join(dossier, jeuHorsActivite.base));
const nomsHorsActivite = new Set();
for (const cas of jeuHorsActivite.cas) {
    if (nomsHorsActivite.has(cas.nom)) {
        echecs++;
        console.log(`  ✗ contre-exemple de hors_activite en double : ${cas.nom}`);
    }
    nomsHorsActivite.add(cas.nom);
    if (erreursHorsActivite(appliquer(baseHorsActivite, cas)).length === 0) {
        echecs++;
        console.log(`  ✗ contre-exemple de hors_activite accepte : ${cas.nom} - ${cas.regle}`);
    }
}

let produites = [];
if (preuvesProduites !== null) {
    try {
        produites = readdirSync(preuvesProduites).filter((f) => f.endsWith('.json')).sort();
    } catch (e) {
        produites = [];
    }
    if (produites.length === 0) {
        echecs++;
        console.log(`  ✗ aucune preuve produite dans ${preuvesProduites} : rien n'a ete controle`);
    }
    for (const fichier of produites) {
        const erreurs = erreursDe(lire(join(preuvesProduites, fichier)));
        if (erreurs.length) {
            echecs++;
            console.log(`  ✗ preuve exportee refusee : ${fichier}\n      ${erreurs.join('\n      ')}`);
        }
    }
}

if (echecs) {
    console.log(`  ${echecs} echec(s). Voir docs/schema/LISEZMOI.md.`);
    process.exit(1);
}
if (preuvesProduites !== null) {
    console.log(`  ✓ preuves exportees : ${produites.length} acceptees`);
}
console.log(`  ✓ schema pivot : ${exemples.length} exemples acceptes, ${jeu.cas.length} contre-exemples refuses`);
console.log(`  ✓ schema de faisabilite : ${exemplesFaisabilite.length} exemples acceptes, `
    + `${jeuFaisabilite.cas.length} contre-exemples refuses`);
console.log(`  ✓ schema de validation : ${exemplesValidation.length} exemples acceptes, `
    + `${jeuValidation.cas.length} contre-exemples refuses`);
console.log(`  ✓ schema de reflexion : ${exemplesReflexion.length} exemples acceptes, `
    + `${jeuReflexion.cas.length} contre-exemples refuses`);
console.log(`  ✓ schema de pieces : ${exemplesPieces.length} exemples acceptes, `
    + `${jeuPieces.cas.length} contre-exemples refuses`);
console.log(`  ✓ schema de hors_activite : ${exemplesHorsActivite.length} exemples acceptes, `
    + `${jeuHorsActivite.cas.length} contre-exemples refuses`);
