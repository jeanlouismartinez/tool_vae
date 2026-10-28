<?php
/**
 * Verifie que chaque capability declaree porte sa chaine de langue, dans toutes les langues.
 *
 * Ce controle existe parce que la cle d'une chaine de capability n'est PAS le nom de la
 * capability : pour 'tool/vae:declare', Moodle cherche 'vae:declare' dans
 * le composant tool_vae — get_capability_string() decoupe sur [/:] et reconstruit
 * la cle. Ecrire $string['tool/vae:declare'] produit un libelle « ??? » dans
 * l'ecran de definition des roles, sans qu'aucun outil ne s'en plaigne.
 *
 * Il verifie aussi la parite entre langues : une chaine presente en anglais et absente en
 * francais passe inapercue jusqu'au jour ou un utilisateur francophone voit l'anglais.
 *
 * Usage : php scripts/verifier-langue.php [racine]
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$racine = rtrim($argv[1] ?? __DIR__ . '/..', '/');
$composant = 'tool_vae';
$erreurs = [];

// Les fichiers du plugin refusent de s'executer hors de Moodle. On fournit le minimum.
define('MOODLE_INTERNAL', true);
foreach ([
    'RISK_MANAGETRUST' => 0x0001, 'RISK_CONFIG' => 0x0002, 'RISK_XSS' => 0x0004,
    'RISK_PERSONAL' => 0x0008, 'RISK_SPAM' => 0x0010, 'RISK_DATALOSS' => 0x0020,
    'CONTEXT_SYSTEM' => 10, 'CONTEXT_USER' => 30, 'CONTEXT_COURSECAT' => 40,
    'CONTEXT_COURSE' => 50, 'CONTEXT_MODULE' => 70, 'CONTEXT_BLOCK' => 80,
    'CAP_INHERIT' => 0, 'CAP_ALLOW' => 1, 'CAP_PREVENT' => -1, 'CAP_PROHIBIT' => -1000,
] as $nom => $valeur) {
    define($nom, $valeur);
}

/**
 * Charge un fichier de definitions et retourne la variable attendue.
 *
 * @param string $chemin
 * @param string $variable
 * @return array
 */
function charger(string $chemin, string $variable): array {
    if (!is_readable($chemin)) {
        return [];
    }
    $capabilities = [];
    $messageproviders = [];
    $string = [];
    require $chemin;
    return $$variable;
}

$capabilities = charger("$racine/db/access.php", 'capabilities');
if (!$capabilities) {
    fwrite(STDERR, "✗ db/access.php illisible ou sans \$capabilities\n");
    exit(1);
}

// Les fournisseurs de messages demandent une constante du coeur que db/messages.php ne definit pas.
foreach (['MESSAGE_PERMITTED' => 2, 'MESSAGE_DEFAULT_ENABLED' => 4, 'MESSAGE_DISALLOWED' => 1] as $nom => $valeur) {
    defined($nom) || define($nom, $valeur);
}
$fournisseurs = charger("$racine/db/messages.php", 'messageproviders');
if (!$fournisseurs) {
    fwrite(STDERR, "✗ db/messages.php illisible ou sans \$messageproviders\n");
    exit(1);
}

$langues = glob("$racine/lang/*/$composant.php");
if (!$langues) {
    fwrite(STDERR, "✗ aucun fichier de langue trouve\n");
    exit(1);
}

$chaines = [];
foreach ($langues as $fichier) {
    $code = basename(dirname($fichier));
    $chaines[$code] = charger($fichier, 'string');
}

// 1. Chaque capability a sa chaine, dans chaque langue, sous la bonne cle.
foreach (array_keys($capabilities) as $capability) {
    $morceaux = preg_split('|[/:]|', $capability);
    if (count($morceaux) !== 3) {
        $erreurs[] = "capability mal formee : $capability (attendu type/nom:action)";
        continue;
    }
    [, $nom, $action] = $morceaux;
    $cle = "$nom:$action";

    foreach ($chaines as $code => $definies) {
        if (!isset($definies[$cle])) {
            $erreurs[] = "lang/$code : chaine manquante \$string['$cle'] pour $capability";
        }
        if (isset($definies[$capability])) {
            $erreurs[] = "lang/$code : cle fautive \$string['$capability'] — attendu '$cle'";
        }
    }
}

// 1 bis. Chaque fournisseur de message a sa chaine 'messageprovider:<nom>' dans chaque langue : sans elle,
// l'ecran des preferences de notification montre le gabarit brut [[messageprovider:<nom>]] (vu le 26/10/2026
// pour withdrawalrequested et withdrawalrefused).
foreach (array_keys($fournisseurs) as $fournisseur) {
    $cle = "messageprovider:$fournisseur";
    foreach ($chaines as $code => $definies) {
        if (!isset($definies[$cle]) || trim((string) $definies[$cle]) === '') {
            $erreurs[] = "lang/$code : chaine manquante \$string['$cle'] pour le fournisseur de message $fournisseur";
        }
    }
}

// 2. Parite entre langues.
$reference = 'en';
if (!isset($chaines[$reference])) {
    $erreurs[] = "lang/$reference absent — c'est la langue de reference de Moodle";
} else {
    foreach ($chaines as $code => $definies) {
        if ($code === $reference) {
            continue;
        }
        foreach (array_diff(array_keys($chaines[$reference]), array_keys($definies)) as $manquante) {
            $erreurs[] = "lang/$code : chaine manquante '$manquante' (presente en $reference)";
        }
        foreach (array_diff(array_keys($definies), array_keys($chaines[$reference])) as $orpheline) {
            $erreurs[] = "lang/$code : chaine '$orpheline' absente de $reference";
        }
    }
}

// 3. pluginname est exige par moodle-plugin-ci validate.
if (!isset($chaines[$reference]['pluginname'])) {
    $erreurs[] = "lang/$reference : \$string['pluginname'] manquant — validate echouera";
}

// 4. Dans chaque fichier de langue, les cles se suivent dans l'ordre strict de strcmp. La verification de la CI
// (moodle-plugin-ci validate) l'exige, et aucun controle local ne le jouait : une chaine ajoutee a la fin du fichier
// n'etait vue qu'une heure et demie plus tard.
foreach ($chaines as $code => $definies) {
    $precedente = null;
    foreach (array_keys($definies) as $cle) {
        if ($precedente !== null && strcmp((string) $precedente, (string) $cle) >= 0) {
            $erreurs[] = "lang/$code : '$cle' ne devrait pas suivre '$precedente' (ordre strcmp)";
        }
        $precedente = $cle;
    }
}

if ($erreurs) {
    echo "✗ chaines de langue\n\n";
    foreach ($erreurs as $erreur) {
        echo "  - $erreur\n";
    }
    exit(1);
}

$nbcap = count($capabilities);
$nbfour = count($fournisseurs);
$nblang = count($chaines);
echo "✓ chaines de langue — $nbcap capabilities et $nbfour fournisseurs de message x $nblang langues, parite et ordre verifies\n";
exit(0);
