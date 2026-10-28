#!/usr/bin/env bash
#
# MC-30 — une seule porte sortante, et jamais planifiee.
#
# Jusqu'a la 0.47.0 le plugin ne faisait AUCUNE requete de lui-meme. Le bouton « Aller chercher le dernier export » de
# l'ecran d'import en ouvre une, et cette promesse ne tient que si elle reste etroite : une seule classe, et rien de ce qui
# tourne sans qu'une personne clique. Ce controle lit les SOURCES, commentaires compris (comme MC-5 : un garde-fou assorti
# d'une liste d'exceptions n'est plus qu'une liste d'exceptions), sans tenir compte de la casse, et echoue si :
#
#   1. un fichier PHP, JS ou inc du plugin - tous, pas seulement classes/ et la racine - AUTRE que
#      classes/local/fiche_fetcher.php, nomme un client HTTP, curl, une ouverture de socket, une resolution de nom, un
#      client SOAP, ou ouvre une adresse http(s) avec file_get_contents, fopen, readfile ou copy ;
#   2. fiche_fetcher.php lui-meme coupe une verification de securite : ignoresecurity, CURLOPT_SSL, skipcertverify, `verify`
#      autre que vrai, `allow_redirects` autre que faux (les redirections sont jugees a la main, avant d'etre suivies) ;
#   3. db/tasks.php ou un fichier de classes/task/ nomme la porte (fiche_fetcher), ce qui l'appelle (fiche_import) ou le
#      client HTTP : le bouton est le seul declencheur ;
#   4. la porte ou ce qui l'appelle (fiche_fetcher, from_data_gouv) est nomme ailleurs que dans les quatre fichiers qui
#      en ont le droit : fiche_fetcher.php, fiche_import.php, import_fiches.php et classes/event/export_fetched.php ; ou la
#      classe qui l'appelle (fiche_import) est nommee ailleurs que dans les sept fichiers qui la nomment (la casse ne compte
#      pas, et un nom de methode peut se construire : c'est le nom de la classe qui est gardé). Un observateur, un service
#      web, un script en ligne de commande ou un nouveau fichier n'ouvrent pas la porte.
#
# L'EXEMPTION PORTE SUR UN CHEMIN COMPLET, comparé en entier (la lecon de MC-5 : `grep --exclude` ne compare que le nom de
# base). lang/, tests/, scripts/ et docs/ ne sont pas parcourus : les chaines et les essais peuvent parler du reseau.
# fiche_no_background_test.php fait le meme controle pour une integration continue sans shell.
#
# CE CONTROLE EST ECRIT AVEC UNE SORTIE EXPLICITE DANS TOUTES LES BRANCHES (le defaut qui avait rendu MC-5 inoperant a sa
# premiere version : un `grep -v` en fin de pipeline rend le statut du dernier maillon) ; lance depuis une racine qui n'est
# pas celle du plugin, il echoue (code 2) au lieu de ne rien trouver.
#
# Usage : scripts/sortie-reseau.sh [racine]

set -uo pipefail

RACINE="${1:-.}"
PORTE='classes/local/fiche_fetcher.php'

if [ ! -d "$RACINE/classes" ] || [ ! -f "$RACINE/version.php" ]; then
  echo "✗ MC-30 : « $RACINE » n'est pas la racine du plugin (classes/ ou version.php manque) : rien n'a ete controle"
  exit 2
fi

# Ce qu'aucun autre fichier ne doit contenir.
INTERDIT='http_client|GuzzleHttp|curl_init|curl_exec|curl_multi|curl_setopt|CURLOPT|FOLLOWLOCATION|new[[:space:]]+\\?curl([^_[:alnum:]]|$)|\\curl::|download_file_content|create_file_from_url|fsockopen|stream_socket_client|SoapClient|dns_get_record|gethostbyname|(^|[^_[:alnum:]>:$])get_headers[[:space:]]*\(|(file_get_contents|fopen|readfile|copy|file|simplexml_load_file)[[:space:]]*\([[:space:]]*['"'"'"]https?:'

# Ce que la porte elle-meme ne doit jamais contenir.
RELACHE="ignoresecurity|CURLOPT_SSL|skipcertverify|verify['\"]?[[:space:]]*=>[[:space:]]*[^t[:space:]]|allow_redirects['\"]?[[:space:]]*=>[[:space:]]*[^f[:space:]]"

# Ce qui tourne sans clic.
PLANIFIE='fiche_fetcher|fiche_import([^_[:alnum:]]|$)|from_data_gouv|http_client'

# Ce qui nomme la porte ou ce qui l'appelle.
NOMME='fiche_fetcher|from_data_gouv'

# Les quatre fichiers qui ont le droit de nommer la porte (chemins complets, comparés en entier).
NOMMEURS=("$PORTE" "classes/local/fiche_import.php" "import_fiches.php" "classes/event/export_fetched.php")

# La classe qui appelle la porte (fiche_import) : une methode de PHP n'a pas de casse et son nom peut se construire, donc on ne
# garde pas seulement le nom de la methode, mais aussi qui nomme la classe. Les sept fichiers qui la nomment aujourd'hui.
IMPORTEUR='fiche_import([^_[:alnum:]]|$)'
IMPORTEURS=(
  "classes/local/fiche_import.php" "classes/local/fiche_screen.php" "classes/local/fiche_plan_store.php"
  "classes/form/fiche_import_form.php" "classes/form/import_form.php" "classes/event/record_imported.php" "import_fiches.php"
)

# Les sources du plugin qui tournent sur le serveur ou dans le navigateur : tout, hors ce qui n'est pas du code du plugin.
fichiers() {
  (
    cd "$RACINE" && find . \
      \( -path ./.git -o -path ./node_modules -o -path ./vendor -o -path ./tests -o -path ./docs -o -path ./lang -o -path ./scripts \) -prune \
      -o -type f \( -name '*.php' -o -name '*.js' -o -name '*.inc' \) -print \
      | sed 's#^\./##' | LC_ALL=C sort
  )
}

est_dans() { # est_dans <fichier> <chemin>... : le fichier est l'un de ces chemins complets
  local fichier="$1" autorise
  shift
  for autorise in "$@"; do
    [ "$fichier" = "$autorise" ] && return 0
  done
  return 1
}

TOUS=$(fichiers)
if [ -z "$TOUS" ]; then
  echo "✗ MC-30 : aucun fichier a controler sous « $RACINE » : le controle n'a rien lu"
  exit 2
fi

TROUVE_AILLEURS=""
TROUVE_RELACHE=""
TROUVE_PLANIFIE=""
TROUVE_NOMME=""

while IFS= read -r fichier; do
  chemin="$RACINE/$fichier"

  if [ "$fichier" != "$PORTE" ]; then
    trouve=$(LC_ALL=C grep -naiE "$INTERDIT" "$chemin" | sed "s#^#$fichier:#" || true)
    [ -n "$trouve" ] && TROUVE_AILLEURS+="$trouve"$'\n'
  else
    trouve=$(LC_ALL=C grep -naiE "$RELACHE" "$chemin" | sed "s#^#$fichier:#" || true)
    [ -n "$trouve" ] && TROUVE_RELACHE+="$trouve"$'\n'
  fi

  case "$fichier" in
    db/tasks.php | classes/task/*.php)
      trouve=$(LC_ALL=C grep -naiE "$PLANIFIE" "$chemin" | sed "s#^#$fichier:#" || true)
      [ -n "$trouve" ] && TROUVE_PLANIFIE+="$trouve"$'\n'
      ;;
  esac

  if ! est_dans "$fichier" "${NOMMEURS[@]}"; then
    trouve=$(LC_ALL=C grep -naiE "$NOMME" "$chemin" | sed "s#^#$fichier:#" || true)
    [ -n "$trouve" ] && TROUVE_NOMME+="$trouve"$'\n'
  fi
  if ! est_dans "$fichier" "${IMPORTEURS[@]}"; then
    trouve=$(LC_ALL=C grep -naiE "$IMPORTEUR" "$chemin" | sed "s#^#$fichier:#" || true)
    [ -n "$trouve" ] && TROUVE_NOMME+="$trouve"$'\n'
  fi
done <<<"$TOUS"

if [ ! -f "$RACINE/$PORTE" ]; then
  TROUVE_RELACHE+="$PORTE : la porte n'existe pas"$'\n'
fi

TROUVE=$(printf '%s%s%s%s' "$TROUVE_AILLEURS" "$TROUVE_RELACHE" "$TROUVE_PLANIFIE" "$TROUVE_NOMME" | grep -v '^$' || true)

if [ -n "$TROUVE" ]; then
  echo "✗ MC-30 violee : la porte sortante n'est pas la seule, ou elle est relachee, ou quelque chose la planifie ou la nomme"
  echo
  echo "$TROUVE"
  echo
  echo "  Seule classes/local/fiche_fetcher.php peut nommer un client HTTP (verify, redirections jugees a la main) ;"
  echo "  rien dans db/tasks.php ni classes/task/ ne peut l'appeler ; seuls fiche_import.php, import_fiches.php et"
  echo "  classes/event/export_fetched.php peuvent nommer la porte, et seuls sept fichiers nomment la classe fiche_import."
  exit 1
fi

echo "✓ MC-30 — une seule porte sortante, jamais planifiee ($(printf '%s\n' "$TOUS" | wc -l | tr -d ' ') fichiers lus)"
exit 0
