# AWS S3 WordPress Migrator

Plugin WordPress qui **détecte**, **télécharge** et **importe** dans la Media Library locale les images hébergées sur un domaine externe (bucket AWS S3, CDN, CMS headless type Strapi…), **remplace** les URLs dans le contenu des articles, et synchronise les ALT depuis la Bibliothèque vers le contenu hardcodé.

## Prérequis

- WordPress 6.0+
- PHP 8.1+
- MySQL 8.0+ (pour `ROW_NUMBER()` utilisé par la purge des révisions)

## Installation

```bash
cd wp-content/plugins
git clone https://github.com/vuckro/aws-s3-wordpress-migrator.git
```

Puis activer depuis `wp-admin → Extensions` et ouvrir `Outils → AWS S3 Migrator`.

## Interface

3 onglets seulement.

### Scan
Configure les sources (ou laisse vide pour détection auto de toute image externe), regroupe les variantes Strapi (`large_` / `medium_` / `small_` / `thumbnail_`) si souhaité, clique **Lancer le scan**. Le plugin parcourt `wp_posts` par lots, détecte les images externes, et persiste le tout dans `{prefix}wks3m_migration_log`.

### File d'attente
L'espace de travail central. Quatre sections empilées :

1. **Migration** — tableau paginé des images détectées, avec filtres par statut et hôte. Dry-run + option "Remplacer URLs après import" par session. Bouton "Tout migrer" séquentiel avec Stop.

2. **Transformer les ALT / Titres** — règle « Si… alors… » pour nettoyer en masse les ALT et titres avant import. Typique : `Si ALT = "xxx" → Copier depuis le Titre`. Preview puis Appliquer.

3. **Synchro ALT (contenu ↔ Bibliothèque)** — après avoir édité les ALT dans la Bibliothèque WP, ce panneau les propage dans le `post_content` des articles où les `<img alt>` sont en dur. Détecte les divergences en scannant chaque `<img>`, résout la src via `attachment_url_to_postid()` (local) **ou** via le journal de migration (URLs S3/CDN encore présentes). Écrit en SQL direct sans cascade `save_post`, sans créer de révision.

4. **Nettoyer** — trois purges indépendantes avec feedback "MB libérés" :
   - Lignes de migration terminées (`replaced` + `failed`)
   - Divergences ALT en attente (un nouveau scan les recrée depuis l'état courant)
   - Anciennes révisions de posts (garde N plus récentes par post, défaut 5)

### Réglages
Thumbnails différés + bouton « Générer les thumbnails manquants ».

## Architecture

```
waaskit-s3-migrator/
├── waaskit-s3-migrator.php        # bootstrap
├── uninstall.php
├── includes/
│   ├── class-activator.php        # schema + options
│   ├── class-settings.php         # options (hardcoded concurrency=1, retries=3)
│   ├── class-url-helper.php       # filename / prefix / WP-size / best variant
│   ├── class-migration-row.php    # value object pour wks3m_migration_log
│   ├── class-mapping-store.php    # CRUD sur wks3m_migration_log
│   ├── class-alt-diff.php         # value object pour wks3m_alt_diff
│   ├── class-alt-diff-store.php   # CRUD sur wks3m_alt_diff
│   ├── class-metadata-extractor.php # <img alt> depuis post_content + titre dérivé
│   ├── class-scanner.php          # scan URLs externes → upsert dans migration_log
│   ├── class-alt-scanner.php      # scan ALT divergents → upsert dans alt_diff
│   ├── class-downloader.php       # download_url + MIME check
│   ├── class-importer.php         # wp_insert_attachment + postmeta
│   ├── class-replacer.php         # str_replace + Gutenberg-aware rewrite
│   ├── class-alt-syncer.php       # direct SQL rewrite de <img alt>
│   ├── class-transform.php        # moteur de règles ALT/Titre sur migration_log
│   ├── class-cli.php              # commandes WP-CLI
│   ├── class-logger.php
│   └── class-util.php
├── admin/
│   ├── class-admin.php            # menu, enqueue, purge handlers
│   ├── class-ajax-controller.php  # endpoints AJAX
│   ├── class-view-helper.php
│   └── views/
│       ├── page-scan.php
│       ├── page-queue.php         # migration + transform + alt-sync + cleanup
│       └── page-settings.php
├── assets/
│   ├── css/admin.css
│   └── js/admin.js
└── languages/
```

## Schéma BDD

### `{prefix}wks3m_migration_log`
Une ligne par image externe détectée.

| Colonne | Description |
|---|---|
| `source_url_base` | `host|filename` (UNIQUE) |
| `source_url_variants` | JSON array des URLs (variantes de taille) |
| `alt_text`, `derived_title` | métadonnées extraites au scan |
| `attachment_id` | id Media Library après import |
| `post_ids` | JSON array des posts référençant cette image |
| `status` | `pending` / `imported` / `replaced` / `failed` |

### `{prefix}wks3m_alt_diff`
Une ligne par `<img>` divergent — travail en attente uniquement.

| Colonne | Description |
|---|---|
| `post_id`, `src` | (UNIQUE) |
| `attachment_id` | résolu au scan |
| `content_alt`, `library_alt` | les deux valeurs à réconcilier |
| `error_message` | rempli si apply a échoué |

Apply OK → DELETE. Re-scan reconstruit depuis l'état courant.

## Options

| Clé | Défaut | But |
|---|---|---|
| `wks3m_source_hosts` | `[]` | Array de hosts à scanner |
| `wks3m_auto_detect_external` | `1` | Si hosts vide, scanner toute image externe |
| `wks3m_strip_strapi_prefixes` | `1` | Grouper les variantes Strapi |
| `wks3m_defer_thumbnails` | `0` | Skip `wp_generate_attachment_metadata()` |
| `wks3m_db_version` | `1.7.0` | Migrations de schéma |

Concurrence fixée à 1 (séquentiel, safe), retries à 3 — non configurables par choix.

## WP-CLI

```bash
wp wks3m migrate --replace --defer-thumbnails
wp wks3m migrate --status=failed --limit=500
wp wks3m finalize-thumbnails
```
