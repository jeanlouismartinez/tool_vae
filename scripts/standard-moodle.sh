#!/usr/bin/env bash
#
# Standard de codage Moodle (moodle-cs), joue en local contre l'etat COMMITE du plugin, comme
# le fait la CI (`moodle-plugin-ci phpcs --max-warnings 0`).
#
# POURQUOI. Le 15 octobre 2026, la CI du lot T-81 a echoue sur ce controle alors que PHPUnit et
# Behat passaient : une variable `$byNumber`, un `foreach` coupe, des chaines de langue mal triees.
# Aucun des controles hors ligne du depot ne le joue. C'est une consigne permanente : on le joue
# avant de pousser.
#
# CE QUE LE SCRIPT FAIT
#   1. installe moodlehq/moodle-cs dans /tmp/mcs du conteneur moodle53-web, hors du depot, la
#      premiere fois seulement (composer, depuis packagist.org) ;
#   2. copie l'etat commite (`git archive HEAD`) dans /tmp/vae-src du conteneur ;
#   3. normalise les fins de ligne : sous Windows, `git archive` ecrit du CRLF, alors que le depot
#      est en LF et que le standard l'exige - sans cela, 552 fausses erreurs ;
#   4. lance phpcs avec le ruleset du depot (phpcs.xml.dist) et affiche le rapport.
#
# Code de sortie : 0 si le standard est respecte, non nul sinon. Les avertissements comptent
# comme des erreurs, comme en CI (--max-warnings 0).
#
# Usage : scripts/standard-moodle.sh [conteneur]

set -uo pipefail

CONTENEUR="${1:-moodle53-web}"
RACINE="$(cd "$(dirname "$0")/.." && pwd)"
export MSYS_NO_PATHCONV=1

if ! docker exec "$CONTENEUR" true 2>/dev/null; then
    echo "✗ le conteneur $CONTENEUR ne repond pas (Docker Desktop, puis : docker start moodle53-db moodle53-web)"
    exit 2
fi

if ! docker exec -u www-data "$CONTENEUR" test -x /tmp/mcs/vendor/bin/phpcs; then
    echo "· installation de moodle-cs dans /tmp/mcs (une seule fois)"
    docker exec -u www-data "$CONTENEUR" sh -c '
        mkdir -p /tmp/mcs && cd /tmp/mcs &&
        php -d memory_limit=-1 /var/www/html/composer.phar require --dev moodlehq/moodle-cs \
            --no-interaction --no-progress --no-plugins >/dev/null 2>&1 ||
        php -d memory_limit=-1 /var/www/html/composer.phar install --no-plugins --no-interaction --no-progress >/dev/null 2>&1;
        vendor/bin/phpcs --config-set installed_paths \
/tmp/mcs/vendor/moodlehq/moodle-cs,/tmp/mcs/vendor/phpcsstandards/phpcsextra,/tmp/mcs/vendor/phpcsstandards/phpcsutils >/dev/null'
    if ! docker exec -u www-data "$CONTENEUR" test -x /tmp/mcs/vendor/bin/phpcs; then
        echo "✗ installation de moodle-cs impossible (reseau ? composer ?)"
        exit 2
    fi
fi

cd "$RACINE" || exit 2
git archive HEAD | docker exec -i -u www-data "$CONTENEUR" sh -c '
    rm -rf /tmp/vae-src && mkdir -p /tmp/vae-src && tar -x -C /tmp/vae-src &&
    find /tmp/vae-src -name "*.php" -exec sed -i "s/\r$//" {} +'

docker exec -u www-data "$CONTENEUR" sh -c '
    cd /tmp/mcs &&
    vendor/bin/phpcs --standard=/tmp/vae-src/phpcs.xml.dist --extensions=php -s --report=full /tmp/vae-src'
STATUT=$?

if [ "$STATUT" -eq 0 ]; then
    echo "✓ standard de codage Moodle — aucune erreur, aucun avertissement (etat commite HEAD)"
else
    echo "✗ standard de codage Moodle — voir le rapport ci-dessus (phpcbf corrige seul les lignes marquees [x])"
fi
exit "$STATUT"
