#!/usr/bin/env bash
#
# MC-2 — aucune ecriture directe dans les tables du coeur.
#
# Mesure compensatoire de DER-referentiel-001 : tout passe par \core_competency\api, pour
# toutes les tables prefixees competency_ (et user_evidence, son ancien nom). Ce controle
# n'existait pas jusqu'au 12 octobre 2026 ; la revue complete de ce jour a constate que
# MC-2 etait enfreinte une fois sans que rien ne le dise.
#
# UNE SEULE EXCEPTION, NOMMEE DANS LA FICHE ET SITUEE ICI A LA FONCTION PRES :
#   classes/local/competency_gateway.php, fonction relabel_evidence() — le renommage des
#   preuves du coeur a la desinstallation (voir la fiche, « Exception nommee a MC-2 »).
# L'exemption porte sur le couple (chemin, fonction), pas sur le fichier : une seconde
# ecriture directe ajoutee dans la passerelle fait echouer ce controle comme ailleurs.
#
# Les tests sont hors champ, comme pour MC-5 : un test qui prepare ou verifie le coeur doit
# pouvoir le toucher.
#
# Usage : scripts/mc2-ecriture-coeur.sh [racine]

set -uo pipefail

RACINE="${1:-.}"

# Perl lit chaque fichier en entier : l'appel et le nom de la table sont souvent sur deux
# lignes, ce qu'un grep ligne a ligne ne voit pas.
TROUVE=$(find "$RACINE" -name '*.php' -not -path '*/.git/*' -not -path '*/tests/*' \
  -not -path '*/vendor/*' -not -path '*/node_modules/*' -print0 \
  | xargs -0 perl -0777 -ne '
      my $f = $ARGV;
      my $ecriture = qr/\$DB->(?:insert_records?|update_record|set_fields?|set_field_select|delete_records(?:_select|_list)?)\(\s*[\x27"](?:competency|user_evidence)[a-z_]*[\x27"]/;
      my $sql = qr/(?:UPDATE|INSERT\s+INTO|DELETE\s+FROM)\s+\{(?:competency|user_evidence)/i;
      while (/$ecriture|$sql/g) {
          my $pos = $-[0];
          my $avant = substr($_, 0, $pos);
          my $ligne = ($avant =~ tr/\n//) + 1;
          my ($fonction) = $avant =~ /.*function\s+(\w+)/s;
          $fonction //= "(hors fonction)";
          next if $f =~ m{(^|/)classes/local/competency_gateway\.php$} && $fonction eq "relabel_evidence";
          print "$f:$ligne: $fonction()\n";
      }' || true)

if [ -n "$TROUVE" ]; then
  echo "✗ MC-2 violee : ecriture directe dans une table du coeur"
  echo
  echo "$TROUVE"
  echo
  echo "  Passer par \\core_competency\\api, via \\tool_vae\\local\\competency_gateway."
  echo "  Voir DER-referentiel-001, mesure compensatoire MC-2."
  exit 1
fi

echo "✓ MC-2 — aucune ecriture directe dans le coeur hors de l'exception nommee"
exit 0
