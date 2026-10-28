<?php
/**
 * Convertit une fiche de l'export de France competences en fichier d'import du plugin (T-31).
 *
 * Lit les fichiers « Blocs_De_Competences » et « Standard » de l'export CSV quotidien de France competences
 * (https://www.data.gouv.fr/datasets/repertoire-national-des-certifications-professionnelles-et-repertoire-specifique),
 * choisit une fiche par son numero, et ecrit le CSV que l'ecran d'import lit (rang, intitule, description).
 *
 * AUCUNE DONNEE DANS LE DEPOT. Ce script ne contient ni ne produit de donnee dans l'arbre du plugin : le fichier de
 * sortie va ou l'on dit, hors depot. Les donnees de France competences sont sous Licence Ouverte 2.0.
 *
 * AUCUN LIBELLE N'EST REFORMULE NI COUPE. Un libelle de plus de 100 caracteres (la largeur de la colonne du coeur)
 * devient le titre neutre « Bloc BC03 » et part en entier dans la description. Le script le dit.
 *
 * Usage : php scripts/convertir-fiche-rncp.php <blocs.csv> <standard.csv> <numero> <sortie.csv>
 * Exemple : php scripts/convertir-fiche-rncp.php blocs.csv standard.csv RNCP40692 deas.csv
 *
 * Il affiche ensuite les valeurs a saisir sur l'ecran d'import : code, type, intitule, source, licence, attribution.
 * La date de mise a jour de la source est celle de l'export (le nom du fichier telecharge la porte).
 *
 * VOIE DE SECOURS. Depuis la 0.47.0, l'ecran « Importer des fiches depuis France competences » lit lui-meme l'archive de
 * l'export (deposee ou recuperee sur data.gouv.fr) et n'a plus besoin de ce script ni de la retape des champs. Il reste pour
 * un poste sans site, ou pour preparer un fichier que l'on veut relire avant de l'importer par l'ecran du fichier CSV.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

// Hors du chargeur de classes (aucun site ici) : le refus d'abord, le convertisseur ensuite, qui le leve.
require_once(__DIR__ . '/../classes/local/fiche_refusal.php');
require_once(__DIR__ . '/../classes/local/fiche_converter.php');

use tool_vae\local\fiche_converter;

if ($argc !== 5) {
    fwrite(STDERR, "Usage : php scripts/convertir-fiche-rncp.php <blocs.csv> <standard.csv> <numero> <sortie.csv>\n");
    exit(2);
}

[, $blocs, $standard, $numero, $sortie] = $argv;
foreach ([$blocs, $standard] as $fichier) {
    if (!is_readable($fichier)) {
        fwrite(STDERR, "Fichier illisible : $fichier\n");
        exit(2);
    }
}

try {
    $resultat = fiche_converter::convert(file_get_contents($blocs), file_get_contents($standard), $numero);
} catch (RuntimeException $e) {
    fwrite(STDERR, 'Refuse : ' . $e->getMessage() . "\n");
    exit(1);
}

// Never say a file was written when it was not: the person who reads the end of the output would type nine fields for nothing.
if (!is_dir(dirname($sortie)) || file_put_contents($sortie, fiche_converter::to_csv($resultat->rows)) === false) {
    fwrite(STDERR, "Impossible d'ecrire le fichier $sortie : rien n'a ete ecrit.\n");
    exit(2);
}

echo count($resultat->rows) . " bloc(s) ecrit(s) dans $sortie\n";
if ($resultat->long) {
    echo 'Libelle de plus de ' . fiche_converter::LABEL_MAXLENGTH . ' caracteres, publie en entier dans la description, '
        . 'titre « Bloc <rang> » : ' . implode(', ', $resultat->long) . "\n";
}
if ($resultat->markup) {
    echo 'Libelle contenant « < » ou une balise (le coeur le refuserait comme titre), publie en entier dans la description, '
        . 'titre « Bloc <rang> » : ' . implode(', ', $resultat->markup) . "\n";
}
echo "\nA saisir sur l'ecran d'import :\n";
echo '  Code de la fiche : ' . $resultat->meta->fichecode . "\n";
echo '  Type : ' . strtoupper($resultat->meta->fichetype) . "\n";
echo '  Intitule : ' . $resultat->meta->name . "\n";
if ($resultat->meta->name !== $resultat->meta->title) {
    echo '    (l\'intitule publie, « ' . $resultat->meta->title . ' », depasse ' . fiche_converter::LABEL_MAXLENGTH
        . " caracteres : le code tient lieu de nom)\n";
}
echo "  Source : https://www.data.gouv.fr/datasets/repertoire-national-des-certifications-professionnelles-et-repertoire-specifique\n";
echo "  Licence : Licence Ouverte 2.0\n";
echo "  Attribution : France compétences\n";
echo "  Date de mise a jour de la source : la date de l'export telecharge\n";
