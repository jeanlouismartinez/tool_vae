# Paquets Moodle 4.5 et Moodle 5.3 : seul le 5.3 est maintenu

Décidé avec le porteur du projet le 12 octobre 2026 : un paquet par version LTS de Moodle, et un
passage de l'un à l'autre sans erreur. La seconde moitié de cette décision tient toujours ; la première
a été remplacée le 27 octobre 2026 : les deux branches existent encore, mais un seul paquet est maintenu,
le 5.3.

**Le paquet 4.5 n'est plus maintenu (décision du porteur du projet, 27 octobre 2026).** La branche
`MOODLE_405` reste consultable, mais aucun correctif n'y est promis, y compris jusqu'au 4 octobre 2027,
date où Moodle 4.5 lui-même cesse de recevoir ses correctifs de sécurité. L'audit du 26 octobre 2026
ne l'a pas touchée : d'après une relecture de son code (estimation, les défauts n'ont pas été rejoués un
à un), environ 28 des 55 défauts que cet audit a corrigés dans le paquet 5.3 y existent encore, dont 5
de sécurité. **Ne l'installez sur aucun site réel.** Un site en Moodle 4.5 doit passer à Moodle 5.3 : la
marche à suivre est dans la section suivante.

| Branche | Moodle | `supported` | `incompatible` | Numéro de version (au 14 octobre 2026) | Maintenue jusqu'au |
|---|---|---|---|---|---|
| `MOODLE_405` | 4.5 LTS | `[405, 405]` | `500` | 2026101310 (0.9.5) | **Plus maintenue** : aucun correctif promis (Moodle 4.5 lui-même reçoit ses correctifs de sécurité jusqu'au 4 octobre 2027) |
| `MOODLE_503` (et `main`) | 5.3 LTS | `[503, 503]` | `504` | 2026101409 (0.17.0) | fin des correctifs de Moodle 5.3 |

`supported` est une déclaration (avertissement dans la page des plugins) ; **`incompatible` est le
verrou** : Moodle refuse un paquet dont `incompatible` est inférieur ou égal à sa propre branche.
À chaque nouvelle LTS testée, le paquet concerné relève ce nombre.

## Passer d'un paquet à l'autre

C'est la voie d'un site resté en Moodle 4.5 : aucun correctif n'est promis pour le paquet 4.5.

1. Exporter ou sauvegarder les dossiers (bouton « Sauvegarder sur Moodle ») : ce n'est pas
   nécessaire, mais c'est la ceinture avec les bretelles.
2. Mettre le serveur au niveau de Moodle 5.3 : **PHP 8.3, PostgreSQL 17 ou MariaDB 11.4 au
   minimum**. Moodle 4.5 fonctionne déjà avec ces versions.
3. Mettre Moodle à jour en 5.3 et, dans le même mouvement, remplacer le dossier du plugin par le
   paquet `MOODLE_503` — sous `public/admin/tool/vae` : Moodle range le code web dans
   `public/` depuis la 5.1.
4. Lancer la mise à jour (page d'administration ou `php admin/cli/upgrade.php`).

Les dossiers, les attestations, les identifiants et les sauvegardes traversent la mise à jour tels
quels. La tâche `migration` de l'intégration continue le prouve à chaque poussée sur `MOODLE_503`,
sur PostgreSQL seulement (sur MariaDB, ce passage n'est pas prouvé) :
elle installe un vrai Moodle 4.5 avec le paquet 4.5, y crée des dossiers, passe en 5.3 avec le
paquet 5.3, lance la mise à jour, puis vérifie que les fichiers `preuves/*.json` sont identiques à
l'octet à ceux de l'export pris sous 4.5, que l'archive exportée sous 4.5 se réimporte sur le compte
d'origine en étant reconnue par le registre comme délivrée par la plateforme, et que la sauvegarde
prise sous 4.5 se réimporte sur un compte neuf après effacement du premier — étrangère alors, ses
lignes du registre étant parties avec le compte effacé (`scripts/migration/`). Cette tâche installe
encore la pointe de la branche `MOODLE_405` (`.forgejo/workflows/moodle-ci.yml`, étape « Paquet 4.5 »,
`ref: MOODLE_405`) : elle prouve que ce paquet s'installe et se met à jour, pas qu'il est exempt de
défauts.

## Règles pour garder ce passage sans erreur

Le paquet 4.5 n'étant plus maintenu, ces règles ne servent plus qu'à garder la tâche `migration` verte
et à encadrer toute reprise éventuelle de la branche `MOODLE_405`.

- **Le numéro de la 4.5 reste au-dessous de la plus basse étape de mise à jour propre à la 5.3.**
  Moodle ne lance une étape que si le numéro installé lui est inférieur. Un site passé de la 4.5 à
  la 5.3 saute donc toute étape de la 5.3 numérotée au plus à son numéro 4.5 : s'il n'avait pas la
  colonne ou le droit qu'elle crée, il ne l'aura jamais. Depuis la 0.13.2, l'étape des deux champs
  de la demande de retrait (2026101311) est **recopiée, idempotente, sous 2026101403** : la plus
  basse étape dont un site 4.5 a encore besoin est donc **2026101403**, et **tout numéro 4.5 doit
  rester inférieur à 2026101403**. La 4.5 a de la place jusqu'à 2026101402. Un numéro 4.5 au-dessus
  de 2026101311 saute l'étape d'origine, et c'est la copie qui crée les champs : la CI de migration
  le contrôle (`scripts/migration/verify.php`, étape 1 bis).
- **Le numéro courant de la 5.3 est toujours supérieur à celui de la 4.5**, pour que la mise à jour
  ait lieu du tout. Une version 4.5 ne sort donc pas sans une version 5.3 plus haute.
- **Quand les numéros 4.5 manquent**, on recopie l'étape propre à la 5.3 sous un numéro plus haut,
  dans la 5.3 : elle vérifie avant d'agir (règle ci-dessous), un site qui l'a déjà passée la repasse
  sans effet, et la 4.5 peut alors monter au-dessus de l'ancien numéro.
- **Aucune plage n'est réservée à l'avance.** Une plage fixée le 12 octobre 2026 (4.5 de
  2026101201 à …99, 5.3 à partir de 2026101300) a été dépassée dès le renommage : la 4.5 est montée
  à 2026101305, puis 2026101308. Ce que la CI vérifie est le passage lui-même (tâche `migration`),
  pas ces règles : la 4.5 qu'elle prend est la **pointe de `MOODLE_405`**, non celle d'une branche de
  travail. Pousser `MOODLE_405` avant `MOODLE_503` fait passer la migration sur le paquet à venir.
- **Une correction faite sur 4.5 est reportée sur 5.3.** Une étape de mise à jour ajoutée à
  `MOODLE_405` (par exemple 2026101203) est recopiée dans `MOODLE_503` sous un autre numéro (ici
  2026101302). Le numéro de la copie est libre : un site qui a passé l'original sous 4.5 la repasse
  sans effet. Ce sont les étapes **sans jumelle sur la 4.5** qui doivent rester au-dessus de tout
  numéro 4.5.
- **Toute étape de mise à jour vérifie avant d'agir** (`field_exists`, `index_exists`…). Un site
  qui l'a déjà passée sous 4.5 la repasse sous 5.3 sans effet, et sans erreur.
- **Aucune fonctionnalité sur 4.5 seulement.** La branche 4.5 n'est plus maintenue : aucun correctif
  n'y est promis. Si elle était retouchée un jour, ce ne serait que pour corriger, jamais pour ajouter
  une fonctionnalité.
