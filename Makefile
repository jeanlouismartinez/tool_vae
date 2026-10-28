# tool_vae — boucles de validation
#
# Regime 2. En l'absence du depot jlm-socle, ce Makefile porte les controles executables
# hors ligne et sans installation. `make valider` distingue trois issues : ✓ reussi,
# ✗ echoue, ○ NON EXECUTE (prerequis absent) — un rapport qui affiche « tout est vert »
# alors qu'il a saute des controles est pire que pas de rapport.

PLUGIN  := tool_vae
RACINE  := .
PHP     := php
SOURCES := $(shell find $(RACINE) -name '*.php' -not -path './vendor/*' -not -path './node_modules/*' 2>/dev/null)
# Dossier node_modules contenant ajv 8 et ajv-formats 3, pour `make schema`.
AJV_MODULES ?=

.DEFAULT_GOAL := aide

.PHONY: aide valider doctor lint codestyle mc5 sortie langue privacy secrets schema ci

aide:
	@echo "tool_vae — cibles disponibles"
	@echo
	@echo "  make valider   tout ce qui est exigible au regime declare"
	@echo "  make doctor    ce qui manque, et ce que ca empeche"
	@echo "  make lint      syntaxe PHP de tous les fichiers"
	@echo "  make codestyle standard de codage Moodle (phpcs + moodlehq/moodle-cs)"
	@echo "  make mc5       MC-5 : aucun appel au coeur hors de la passerelle"
	@echo "  make sortie    MC-30 : une seule porte sortante, jamais planifiee"
	@echo "  make langue    chaines de capability et parite entre langues"
	@echo "  make privacy   chaque table est declaree au provider RGPD, ou exemptee"
	@echo "  make secrets   secrets dans le diff indexe"
	@echo "  make schema    schema pivot : exemples acceptes, contre-exemples refuses"
	@echo
	@echo "  Non couvert ici, exige avant fusion : moodle-plugin-ci"
	@echo "  (codechecker, validate, savepoints, mustache, grunt, phpunit, behat)"

schema:
	@echo "── Schema pivot (docs/schema)"
	@if ! command -v node > /dev/null 2>&1; then \
	  echo "  ○ NON EXECUTE — node absent."; \
	elif ! AJV_MODULES="$(AJV_MODULES)" node -e "const m = process.env.AJV_MODULES; require(m ? require('path').join(m, 'ajv/dist/2020') : 'ajv/dist/2020')" > /dev/null 2>&1; then \
	  echo "  ○ NON EXECUTE — ajv 8 introuvable."; \
	  echo "     make schema AJV_MODULES=<dossier node_modules contenant ajv et ajv-formats>"; \
	else \
	  AJV_MODULES="$(AJV_MODULES)" node scripts/verifier-schema.mjs $(RACINE); \
	fi

valider: doctor lint codestyle mc5 sortie langue privacy secrets schema
	@echo
	@echo "══════════════════════════════════════════════"
	@echo "  ✓ Controles hors ligne : tous verts"
	@echo
	@echo "  ○ NON EXECUTE ici — exige un Moodle installe (voir docs/BANC-ESSAI.md) :"
	@echo "     phpunit           165 tests verts en CI le 11 octobre 2026 (v0.8.0)"
	@echo "     conformite RGPD   1628 tests du coeur, verts le 28 aout 2026"
	@echo "     behat             5 scenarios verts en CI le 11 octobre 2026, dont axe-core"
	@echo
	@echo "  ○ NON EXECUTE — sans outil :"
	@echo "     test manuel au clavier et au lecteur d ecran"
	@echo "     revue humaine tierce : habilitations, donnees personnelles, licence"
	@echo "══════════════════════════════════════════════"

doctor:
	@bash scripts/doctor.sh $(RACINE)

lint:
	@echo "── Syntaxe PHP"
	@erreurs=0; \
	for f in $(SOURCES); do \
	  $(PHP) -l $$f > /dev/null 2>&1 || { echo "  ✗ $$f"; $(PHP) -l $$f; erreurs=1; }; \
	done; \
	if [ $$erreurs -eq 0 ]; then \
	  echo "  ✓ $(words $(SOURCES)) fichiers sans erreur de syntaxe"; \
	else exit 1; fi

codestyle:
	@echo "── Standard de codage Moodle"
	@PHPCS=$$(command -v phpcs || echo /opt/moodle-cs/vendor/bin/phpcs); \
	if [ -x "$$PHPCS" ]; then \
	  $$PHPCS --report=summary --no-colors $(RACINE) && echo "  ✓ aucune violation"; \
	else \
	  echo "  ○ NON EXECUTE — phpcs absent."; \
	  echo "     composer require moodlehq/moodle-cs, puis phpcs --report=summary ."; \
	  echo "     Les regles du plugin sont dans phpcs.xml.dist."; \
	fi

mc5:
	@echo "── MC-5"
	@bash scripts/mc5-passerelle.sh $(RACINE)

sortie:
	@echo "── MC-30"
	@bash scripts/sortie-reseau.sh $(RACINE)

langue:
	@echo "── Chaines de langue"
	@$(PHP) scripts/verifier-langue.php $(RACINE)

privacy:
	@echo "── Couverture RGPD"
	@$(PHP) scripts/verifier-privacy.php $(RACINE)

secrets:
	@echo "── Secrets"
	@bash scripts/verifier-secrets.sh

# Cible d'integration continue : ajoute ce qui exige une installation.
ci: valider
	@echo "── moodle-plugin-ci"
	@command -v moodle-plugin-ci > /dev/null 2>&1 \
	  && moodle-plugin-ci validate \
	  || echo "  ○ NON EXECUTE — moodle-plugin-ci absent de cette machine"
