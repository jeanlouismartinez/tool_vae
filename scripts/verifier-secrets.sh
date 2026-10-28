#!/usr/bin/env bash
#
# Secrets dans le diff indexe.
#
# CE CONTROLE A DEJA CRIE AU LOUP UNE FOIS. La premiere version cherchait les mots
# `secret`, `password`, `key`. Elle remontait le texte de la GPL (« must require no special
# password or key »), le nom de la cible `make secrets`, et sa propre expression reguliere.
# Un controle qui echoue sur du code correct est desactive dans la semaine, et il ne
# protege alors plus rien.
#
# On ne cherche donc pas des MOTS mais des VALEURS : une affectation d'un nom sensible a
# une chaine assez longue pour etre un vrai secret, ou une empreinte de jeton reconnaissable.
#
# LIMITE ASSUMEE : ce controle ne detecte pas un secret stocke dans une variable au nom
# anodin, ni un secret deja present dans l'historique. Il ferme la porte la plus frequente,
# pas toutes les portes. La regle du socle reste : aucun secret dans le code NI dans
# l'historique Git.
#
# Usage : scripts/verifier-secrets.sh [--tout]

set -uo pipefail

if ! command -v git > /dev/null 2>&1 || [ ! -d .git ]; then
  echo "  ○ NON EXECUTE — pas de depot git"
  exit 0
fi

# Affectation d'un nom sensible a une valeur d'au moins 8 caracteres.
AFFECTATION='(api[_-]?key|apikey|secret[_-]?key|client[_-]?secret|auth[_-]?token|access[_-]?token|passwd|password)["'"'"']?\s*[:=]>?\s*["'"'"'][^"'"'"']{8,}'
# Empreintes de jetons reconnaissables, independamment du nom de variable.
JETONS='(-----BEGIN [A-Z ]*PRIVATE KEY-----|xox[baprs]-[A-Za-z0-9-]{10,}|ghp_[A-Za-z0-9]{30,}|github_pat_[A-Za-z0-9_]{20,}|AKIA[0-9A-Z]{16}|sk-[A-Za-z0-9]{24,}|AIza[0-9A-Za-z_-]{30,})'

if [ "${1:-}" = "--tout" ]; then
  CIBLE=$(git ls-files -- ':!LICENSE' ':!scripts/verifier-secrets.sh')
  # shellcheck disable=SC2086
  TROUVE=$(grep -EIn "$AFFECTATION|$JETONS" $CIBLE 2>/dev/null)
else
  # Le diff indexe, lignes ajoutees seulement. On retire la ligne de ce script lui-meme et
  # le texte de la licence, qui ne sont pas du code et ne portent aucune valeur.
  TROUVE=$(git diff --cached -U0 -- ':!LICENSE' ':!scripts/verifier-secrets.sh' \
    | grep -E '^\+' \
    | grep -Ei "$AFFECTATION|$JETONS")
fi

if [ -n "$TROUVE" ]; then
  echo "  ✗ valeur ressemblant a un secret :"
  echo "$TROUVE" | sed 's/^/      /'
  echo
  echo "    Un secret dans l'historique Git y reste. Retirer AVANT de committer."
  exit 1
fi

echo "  ✓ aucune valeur ressemblant a un secret"
exit 0
