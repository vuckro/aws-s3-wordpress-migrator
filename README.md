# AWS S3 WordPress Migrator by WaaKit

Plugin WordPress qui **scanne**, **télécharge** et **importe** dans la Media Library locale les images hébergées sur AWS S3 (ex. `clairexplore.s3.eu-west-3.amazonaws.com`), puis **remplace** les URLs dans les articles avec un **log réversible**.

## Statut

**Phase 1 — Skeleton + Scanner read-only.** Aucune écriture sur le site.

## Prérequis

- WordPress 6.0+
- PHP 8.1+

## Installation

1. Cloner ce repo dans `wp-content/plugins/claireexplore-s3-migrator/`
2. Activer le plugin depuis `wp-admin → Extensions`
3. Aller dans `Outils → AWS S3 Migrator`

## Usage (Phase 1)

Onglet **Scan** :
- Clic sur *Lancer le scan* → le plugin parcourt `wp_posts` par lots et liste toutes les URLs S3 trouvées, regroupées par image de base (les 4 variantes Strapi `large_`/`medium_`/`small_`/`thumbnail_` sont dédupliquées).
- Le résumé affiche également le nombre de lignes `wp_postmeta` et `wp_options` qui contiennent des références S3.
- **Aucune image n'est téléchargée ou modifiée à ce stade.** Le but est de valider la détection avant d'activer l'import.

## Phases suivantes (non livrées)

- Phase 2 — File d'attente avec miniatures, alt détecté, titre dérivé.
- Phase 3 — Download + Import dans la Media Library (unitaire, dry-run).
- Phase 4 — Remplacement `post_content` + Rollback + batching + Historique.
- Phase 5 — Metabox par article + WP-CLI + i18n.

## Architecture

Voir [`claireexplore-s3-migrator.php`](./claireexplore-s3-migrator.php) pour le bootstrap. Chaque composant a sa classe sous `includes/`.

Table custom : `{prefix}s3_migration_log` (créée à l'activation).

## Auteur

WaaKit

## Licence

GPL-2.0-or-later
