<?php
/**
 * Chaque table declaree dans install.xml est-elle couverte par le provider RGPD ?
 *
 * C'EST AINSI QU'UN PROVIDER POURRIT : on ajoute une table au lot N, on oublie de la
 * declarer, et l'administrateur du site repond a une demande d'acces en toute bonne foi
 * avec une reponse incomplete. Rien ne le signale — ni Moodle, ni moodle-plugin-ci.
 *
 * Une table peut legitimement ne porter aucune donnee personnelle. Dans ce cas elle doit
 * etre exemptee EXPLICITEMENT, par un marqueur dans provider.php :
 *
 *     // privacy-exempt: tool_vae_obsolete
 *
 * L'omission devient alors une decision datee et relisible, au lieu d'un trou.
 *
 * PREMIERE VERSION DE CE CONTROLE, EN BASH : elle cherchait la chaine « null_provider »
 * dans provider.php et la trouvait dans un COMMENTAIRE expliquant pourquoi le provider
 * n'en etait plus un. Meme faute que le controle MC-5 casse : un test litteral la ou il
 * fallait un test de sens.
 *
 * Usage : php scripts/verifier-privacy.php [racine]
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$racine = rtrim($argv[1] ?? __DIR__ . '/..', '/');
$installxml = "$racine/db/install.xml";
$providerphp = "$racine/classes/privacy/provider.php";

if (!file_exists($installxml)) {
    echo "  ✓ aucune table — rien a declarer\n";
    exit(0);
}

if (!is_readable($providerphp)) {
    fwrite(STDERR, "  ✗ db/install.xml existe mais classes/privacy/provider.php est absent\n");
    exit(1);
}

$xml = simplexml_load_file($installxml);
if ($xml === false) {
    fwrite(STDERR, "  ✗ db/install.xml illisible\n");
    exit(1);
}

$tables = [];
foreach ($xml->TABLES->TABLE as $table) {
    $tables[] = (string) $table['NAME'];
}

$source = file_get_contents($providerphp);

// Le provider doit declarer des metadonnees des lors qu'il existe une table.
if (!str_contains($source, 'metadata\\provider')) {
    fwrite(STDERR, "  ✗ des tables existent mais le provider ne declare aucune metadonnee\n");
    fwrite(STDERR, "    Le site repondrait « ce plugin ne detient rien sur vous » en detenant un dossier.\n");
    exit(1);
}

preg_match_all("/add_database_table\(\s*'([^']+)'/", $source, $declarees);
preg_match_all('/privacy-exempt:\s*([A-Za-z0-9_]+)/i', $source, $exemptees);

$declarees = $declarees[1];
$exemptees = $exemptees[1];
$erreurs = [];

foreach ($tables as $table) {
    if (in_array($table, $declarees, true)) {
        continue;
    }
    if (in_array($table, $exemptees, true)) {
        continue;
    }
    $erreurs[] = "table « $table » ni declaree, ni exemptee explicitement";
}

foreach ($declarees as $table) {
    if (!in_array($table, $tables, true)) {
        $erreurs[] = "table « $table » declaree au provider mais absente d'install.xml";
    }
}

foreach ($exemptees as $table) {
    if (!in_array($table, $tables, true)) {
        $erreurs[] = "table « $table » exemptee mais absente d'install.xml — exemption perimee";
    }
}

if ($erreurs) {
    echo "  ✗ couverture RGPD incomplete\n";
    foreach ($erreurs as $erreur) {
        echo "      - $erreur\n";
    }
    echo "\n    Declarer la table, ou l'exempter explicitement dans provider.php :\n";
    echo "        // Privacy-exempt: <nom_de_la_table>.\n";
    exit(1);
}

$nbdec = count($declarees);
$nbex = count($exemptees);
$nbtab = count($tables);
echo "  ✓ couverture RGPD — $nbtab tables : $nbdec declarees, $nbex exemptee(s) explicitement\n";
exit(0);
