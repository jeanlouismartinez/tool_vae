#!/usr/bin/env bash
#
# MC-5 — aucun appel au coeur hors de la passerelle.
#
# Mesure compensatoire de DER-referentiel-001. Le modele natif prive le projet de
# l'amortisseur qu'est normalement le pont ; la passerelle le reconstitue a l'interieur du
# plugin. Un echec ici est un echec de derogation, pas un avertissement de style.
#
# CE CONTROLE A DEJA ETE ECRIT FAUX UNE FOIS. La premiere version etait :
#     grep -rn 'core_competency' … | grep -v passerelle && echo "✗" && exit 1
# Sur un arbre propre, le `grep -v` ne trouve rien, sort en 1, le `&&` court-circuite, et
# le statut du pipeline reste 1 : le controle echouait sur du code correct comme sur du
# code fautif. Il aurait ete desactive au premier jour, et MC-5 n'aurait jamais ete
# verifiee. D'ou `grep -q` et une sortie explicite dans les deux branches.
#
# Deux exemptions, nommees ET SITUEES :
#   - classes/local/competency_gateway.php : c'est la passerelle.
#   - classes/privacy/provider.php         : le provider RGPD doit declarer
#                              add_subsystem_link() vers le sous-systeme, sinon la Privacy API
#                              est fausse.
# Elles portent sur un CHEMIN, pas sur un nom de fichier. `grep --exclude` ne compare que le
# nom de base : ecrite ainsi, l'exemption couvrait n'importe quel provider.php de l'arbre, y
# compris un futur classes/local/provider.php qui, lui, n'a aucune raison d'appeler le coeur.
# Signale en revue adverse le 28 aout 2026.
# Le controle est litteral : il inspecte les commentaires comme le code, et n'offre aucun
# autre mecanisme d'exclusion. Un garde-fou assorti d'une liste d'exceptions finit par
# n'etre qu'une liste d'exceptions.
#
# Usage : scripts/mc5-passerelle.sh [racine]

set -uo pipefail

RACINE="${1:-.}"

# DEUX MOTIFS, PARCE QUE LE COEUR S'APPELLE DE DEUX FACONS. Le premier attrape la classe, le
# second la TABLE : `$DB->count_records('competency', ...)` dans un presenter est exactement la
# meme breche qu'un appel a l'api, et la version precedente de ce script, qui ne cherchait que
# le nom de classe, repondait « vert » dessus. Signale en revue adverse le 29 aout 2026.
# Le second motif vise la position de l'argument, pas le mot : `'competencyframeworkid' =>`,
# qui est une cle de tableau, ne doit pas etre compte comme un acces a une table.
MOTIF='core_competency'
MOTIF_TABLE="\\\$DB->[a-z_]+\\(\\s*'(competency|user_evidence)"

COMMUN=(
  --include='*.php' --include='*.js'
  --exclude-dir=.git --exclude-dir=node_modules --exclude-dir=vendor
)

# Le filtre est applique APRES le grep, sur le chemin complet, et son resultat est mis dans une
# variable : un `grep | grep -v` en pipeline rend le statut du DERNIER maillon, ce qui est
# exactement le defaut B9 qui rendait ce controle inoperant dans sa premiere version.
EXEMPTES='classes/local/competency_gateway\.php:|classes/privacy/provider\.php:'

# Le nom de CLASSE est interdit partout, tests compris : un test qui appelle l'api du coeur
# echapperait a la migration LTS comme n'importe quel autre fichier.
TROUVE=$(grep -rn "$MOTIF" "$RACINE" "${COMMUN[@]}" 2>/dev/null | grep -Ev "$EXEMPTES" || true)

# Le nom de TABLE est interdit hors de la passerelle, SAUF sous tests/ : un test dont le role
# est de prouver que la passerelle a bien ecrit dans le coeur doit regarder le coeur, sinon il
# ne prouve rien. L'exemption porte sur un chemin, jamais sur un nom de fichier.
TROUVE_TABLE=$(grep -rnE "$MOTIF_TABLE" "$RACINE" "${COMMUN[@]}" 2>/dev/null \
  | grep -Ev "$EXEMPTES|(^|/)tests/" || true)

TROUVE=$(printf '%s\n%s' "$TROUVE" "$TROUVE_TABLE" | grep -v '^$' || true)

if [ -n "$TROUVE" ]; then
  echo "✗ MC-5 violee : le coeur est appele hors de la passerelle"
  echo
  echo "$TROUVE"
  echo
  echo "  Corriger en passant par \\tool_vae\\local\\competency_gateway."
  echo "  Voir DER-referentiel-001, mesure compensatoire MC-5."
  exit 1
fi

echo "✓ MC-5 — aucun appel au coeur hors de la passerelle"
exit 0
