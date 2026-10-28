#!/usr/bin/env bash
#
# doctor — ce qui manque, et ce que ca empeche.
#
# Le socle distingue trois issues : ✓ reussi, ✗ echoue, ○ NON EXECUTE (prerequis absent).
# Un rapport qui affiche « tout est vert » alors qu'il a saute des controles est pire que
# pas de rapport.
#
# Usage : scripts/doctor.sh [racine]

set -uo pipefail

RACINE="${1:-.}"
MANQUES=0
ERREURS=0

titre() { echo; echo "── $1"; }
ok()    { echo "  ✓ $1"; }
absent(){ echo "  ○ NON EXECUTE — $1"; MANQUES=$((MANQUES+1)); }
faux()  { echo "  ✗ $1"; ERREURS=$((ERREURS+1)); }

titre "Outils"
command -v php  >/dev/null && ok "php $(php -r 'echo PHP_VERSION;')" || absent "php absent — lint et verifier-langue impossibles"
command -v git  >/dev/null && ok "git" || absent "git absent — le controle des secrets ne peut pas tourner"
command -v node >/dev/null && ok "node $(node --version)" || absent "node absent — make schemas ne peut pas tourner"

titre "Fichiers exiges par moodle-plugin-ci validate"
[ -f "$RACINE/version.php" ] && ok "version.php" || faux "version.php manquant — validate echoue"
[ -f "$RACINE/lang/en/tool_vae.php" ] && ok "lang/en/tool_vae.php" || faux "lang/en manquant — validate echoue"

titre "Fichiers exiges par le socle"
[ -f "$RACINE/LICENSE" ]   && ok "LICENSE" || faux "LICENSE manquant — regle 7 du socle irreductible"
[ -f "$RACINE/README.md" ] && ok "README.md" || faux "README manquant — regle 8 du socle irreductible"
[ -f "$RACINE/CHANGELOG.md" ] && ok "CHANGELOG.md" || faux "CHANGELOG manquant"
[ -f "$RACINE/docs/derogations/DER-referentiel-001-modele-natif.md" ] \
  && ok "DER-referentiel-001" || faux "fiche de derogation absente — le modele natif n'est pas couvert"

titre "Fichiers du socle non encore produits"
[ -f "$RACINE/VERSIONS.md" ]  && ok "VERSIONS.md" || absent "VERSIONS.md — MC-4 (registre des depreciations) non verifiable"
[ -f "$RACINE/ARCHITECTURE.md" ] && ok "ARCHITECTURE.md" || absent "ARCHITECTURE.md — encapsulations et ecarts non traces"
[ -f "$RACINE/docs/MEMO-JURIDIQUE.md" ] && ok "MEMO-JURIDIQUE.md" || absent "MEMO-JURIDIQUE.md — PO-2 (LO 2.0 / GPLv3) non instruit"

titre "Couverture RGPD"
php scripts/verifier-privacy.php "$RACINE" || ERREURS=$((ERREURS+1))

titre "Coherence du lot en cours"

if [ -f "$RACINE/db/upgrade.php" ]; then
  grep -q 'function xmldb_tool_vae_upgrade' "$RACINE/db/upgrade.php" \
    && ok "db/upgrade.php — nom de fonction correct" \
    || faux "db/upgrade.php present avec une mauvaise signature — validate echoue"
else
  ok "db/upgrade.php absent — correct tant qu'il n'y a rien a migrer"
fi

titre "Tests Behat"
for f in "$RACINE"/tests/behat/*.feature; do
  [ -e "$f" ] || { absent "aucun fichier .feature"; break; }
  head -1 "$f" | grep -q '@tool ' && head -1 "$f" | grep -q '@tool_vae' \
    && ok "$(basename "$f") — tags @tool et @tool_vae" \
    || faux "$(basename "$f") — les deux tags @tool et @tool_vae sont exiges par validate"
done

echo
echo "──────────────────────────────────────────────"
echo "  ✗ echecs : $ERREURS      ○ non executes : $MANQUES"
echo
echo "  Ce qu'aucun outil ne verifiera :"
echo "   - qu'aucun champ libre ne designe nominativement une personne accompagnee"
echo "   - qu'aucun affichage ne pre-juge de la decision du jury"
echo "   - que la paternite et la date du referentiel sont affichees"
echo "   - que l'archive exportee est relisible par un humain dans cinq ans, sans Moodle"
echo "──────────────────────────────────────────────"

[ "$ERREURS" -eq 0 ] || exit 1
exit 0
