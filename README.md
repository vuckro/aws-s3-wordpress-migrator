# AWS S3 WordPress Migrator

Plugin WordPress réutilisable qui **scanne** les URLs d'images externes présentes dans un site WordPress (bucket S3, CDN distant, CMS headless comme Strapi…), les **télécharge** dans la Media Library locale avec leurs métadonnées SEO (`alt`, titre), **remplace** les URLs dans le contenu, et garde un **log réversible** de chaque migration.

Aucune donnée client n'est codée en dur : l'utilisateur saisit la liste des domaines sources à scanner, ou laisse le plugin détecter automatiquement toute URL d'image externe au site.

## Statut

**Phase 1 — Skeleton + Scanner read-only.** Aucune écriture sur le site.

## Prérequis

- WordPress 6.0+
- PHP 8.1+

## Installation

1. Cloner ce repo dans `wp-content/plugins/waaskit-s3-migrator/`
2. Activer depuis `wp-admin → Extensions`
3. Aller dans `Outils → AWS S3 Migrator`

## Usage (Phase 1)

**Onglet Scan — section "Sources à scanner"** :

- Saisir un ou plusieurs domaines à chercher (ex. `my-bucket.s3.amazonaws.com` ou `cdn.example.com`), un par ligne. Les URLs complètes sont acceptées (seul le host est conservé).
- **OU** laisser le champ vide et activer **Détection automatique** : le plugin cherche alors toute image (`jpg`, `jpeg`, `png`, `gif`, `webp`, `svg`, `avif`) dont le host est différent de celui du site.
- Case **Variantes Strapi** : regroupe les préfixes `large_` / `medium_` / `small_` / `thumbnail_` comme une seule image source.

**Onglet Scan — section "Scan du site"** :

- Clic sur *Lancer le scan* → le plugin parcourt `wp_posts` par lots (taille configurable), détecte les images externes, les regroupe par `host + nom de fichier`, et compte les occurrences dans `wp_postmeta` et `wp_options`.
- **Aucune image n'est téléchargée ni modifiée à ce stade.**

## Phases suivantes

- **Phase 2** — File d'attente : miniatures, `alt` détecté depuis `<img>`, titre dérivé du filename.
- **Phase 3** — Download + Import (dry-run puis réel) dans la Media Library.
- **Phase 4** — Remplacement `post_content` + Rollback + batching + Historique.
- **Phase 5** — Metabox par article + WP-CLI + i18n.

## Architecture

- [`waaskit-s3-migrator.php`](./waaskit-s3-migrator.php) — bootstrap + constantes.
- `includes/class-settings.php` — options utilisateur (domaines, auto-détection, préfixes Strapi).
- `includes/class-scanner.php` — regex de détection + filtrage par host.
- `includes/class-mapping-store.php` — accès à la table `{prefix}wks3m_migration_log`.
- `admin/class-admin.php` — menu, onglets, enregistrement des sources.
- `admin/class-ajax-controller.php` — endpoints AJAX.

## Conventions

- Namespace PHP : `WKS3M\`
- Préfixe options / table / AJAX : `wks3m_`
- Text-domain : `waaskit-s3-migrator`

## Auteur

WaasKit — https://github.com/vuckro/aws-s3-wordpress-migrator

## Licence

GPL-2.0-or-later
