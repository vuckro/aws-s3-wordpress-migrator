# AWS S3 WordPress Migrator

Plugin WordPress qui **détecte**, **télécharge** et **importe** dans la Media Library locale les images hébergées sur un domaine externe (bucket AWS S3, CDN, CMS headless type Strapi…), **remplace** les URLs dans le contenu des articles, et garde un **historique réversible** de chaque migration.

Aucune donnée n'est codée en dur : l'utilisateur configure les domaines sources, ou laisse le plugin détecter automatiquement toute image externe au site.

## Prérequis

- WordPress 6.0+
- PHP 8.1+

## Installation

```bash
cd wp-content/plugins
git clone https://github.com/vuckro/aws-s3-wordpress-migrator.git
```

Puis activer depuis `wp-admin → Extensions` et ouvrir `Outils → AWS S3 Migrator`.

## Parcours utilisateur

### 1. Scan

Onglet **Scan**. Configure les sources — soit une liste de domaines à chercher, soit laisse vide avec la détection automatique (toute image externe au site actuel). Option pour regrouper les variantes Strapi (`large_` / `medium_` / `small_` / `thumbnail_`) en une seule image.

Clique **Lancer le scan**. Le plugin parcourt `wp_posts` par lots, détecte toutes les images externes, extrait leur `alt` depuis le HTML, dérive un titre lisible depuis le nom de fichier, et persiste tout en base dans `{prefix}wks3m_migration_log`.

### 2. Migration

Onglet **File d'attente**. Filtres par statut / hôte, pagination 25/page. Deux toggles :
- **Dry-run** — simule sans télécharger
- **Remplacer URLs après import** — enchaîne import + remplacement dans le contenu

Actions :
- **Migrer** par ligne
- **Tout migrer** (tout ce qui est en attente)
- **Migrer la sélection** (checkboxes)
- **Stop** interrompt proprement

Chaque import crée un attachment WordPress avec le `alt`, le titre, et deux postmeta (`_wks3m_source_url`, `_wks3m_source_host`) pour la traçabilité. Le replacer met à jour le `post_content` des articles affectés (blocs Gutenberg compris : `id` dans le commentaire JSON, classe `wp-image-{id}` sur l'`<img>`) et sauvegarde l'ancien contenu dans `_wks3m_backup_{row_id}` pour rollback.

### 3. Synchro ALT *(optionnel)*

Onglet **Synchro ALT**. Après avoir édité les ALT dans la Bibliothèque WordPress, ce tab propage les nouvelles valeurs dans le `post_content` des articles où ces images apparaissent en dur (`<img alt="…">`).

Résolution src → attachment en deux passes :
1. `attachment_url_to_postid()` (core WP) pour les URLs locales déjà réécrites vers `/wp-content/uploads/`
2. Lookup dans `wks3m_migration_log.source_url_variants` pour les URLs externes (S3/CDN) encore présentes dans le contenu

Le pivot n'est **jamais** la classe `wp-image-N` — elle n'est pas fiable après le replace d'URLs en masse.

Flow : **Lancer le scan** → remplit `wp_wks3m_alt_diff` avec une ligne par `<img>` divergent → tableau filtrable/paginé → **Remplacer** par ligne ou **Tout synchroniser** en masse. Sur apply OK la ligne disparaît de la liste des divergences et s'ajoute dans l'historique (`wp_wks3m_alt_history`) ; sur erreur elle reste visible dans la liste avec le message affiché.

L'écriture passe en direct SQL (pas de révision créée, pas de save_post cascade). Pas de rollback natif — pour annuler un changement ALT, ré-éditer la valeur dans la Bibliothèque puis relancer un scan.

**Sous-onglets** :
- **Divergences** (défaut) — travail en attente avec bulk apply
- **Historique** — journal en lecture seule de tous les syncs réussis

**Nettoyage** : `Réglages → Nettoyer les données` vide la table des divergences en attente ou l'historique, quand le travail est fini et qu'on veut réduire l'empreinte BDD.

### 4. Historique & Rollback

Onglet **Historique & Rollback**. Sous-onglets Importées / Remplacées / Rollback / Échecs. **Rollback** par ligne ou **Tout rollback** global restaurent le `post_content` d'origine depuis les backups. Les attachments restent dans la Media Library (à supprimer manuellement si besoin).

## Architecture

```
waaskit-s3-migrator/
├── waaskit-s3-migrator.php        # bootstrap
├── uninstall.php
├── includes/
│   ├── class-activator.php        # table + options
│   ├── class-settings.php         # options (source hosts, auto-detect, prefixes)
│   ├── class-url-helper.php       # filename / prefix / WP-size / best variant
│   ├── class-migration-row.php    # value object autour d'une ligne DB
│   ├── class-mapping-store.php    # CRUD sur {prefix}wks3m_migration_log
│   ├── class-metadata-extractor.php # <img alt> depuis post_content + titre dérivé
│   ├── class-scanner.php          # regex + upsert
│   ├── class-downloader.php       # download_url + MIME check
│   ├── class-importer.php         # wp_insert_attachment + postmeta
│   ├── class-replacer.php         # str_replace + Gutenberg-aware rewrite
│   ├── class-rollback-manager.php # restore post_content from backup
│   ├── class-alt-diff.php          # value object pour wks3m_alt_diff
│   ├── class-alt-diff-store.php    # CRUD sur wks3m_alt_diff
│   ├── class-alt-scanner.php       # détecte les ALT divergents contenu vs biblio
│   ├── class-alt-syncer.php        # apply + rollback par src exact
│   ├── class-cli.php              # commandes WP-CLI (migrate, finalize-thumbnails)
│   ├── class-logger.php
│   └── class-util.php             # helpers partagés
├── admin/
│   ├── class-admin.php            # menu, enqueue, tabs
│   ├── class-ajax-controller.php  # endpoints AJAX
│   ├── class-view-helper.php      # status pill, posts_links, tab_url, etc.
│   └── views/
│       ├── page-scan.php
│       ├── page-queue.php
│       ├── page-alt-sync.php
│       ├── page-history.php
│       └── page-settings.php
├── assets/
│   ├── css/admin.css
│   └── js/admin.js
└── languages/
```

## Schéma BDD

### `{prefix}wks3m_migration_log` (migration d'images)

| Colonne | Description |
|---|---|
| `id` | PK |
| `source_url_base` | `host|filename` (UNIQUE) — clé de dédup |
| `source_url_variants` | JSON array — toutes les URLs équivalentes (variantes de taille) |
| `source_host`, `base_key` | extraits pour filtrage rapide |
| `alt_text`, `derived_title` | métadonnées extraites au scan |
| `attachment_id` | id Media Library après import |
| `post_ids` | JSON array — posts référençant cette image |
| `status` | `pending` / `imported` / `replaced` / `rolled_back` / `failed` |
| `error_message` | dernier message d'erreur si `failed` |
| `created_at`, `last_seen_at`, `replaced_at`, `rolled_back_at` | timestamps |

### `{prefix}wks3m_alt_diff` (synchro ALT — travail en attente)

| Colonne | Description |
|---|---|
| `id` | PK |
| `post_id`, `src` | (UNIQUE) — une ligne par `<img>` divergent |
| `attachment_id` | résolu par `attachment_url_to_postid()` ou variant lookup |
| `content_alt` | alt lu dans `post_content` |
| `library_alt` | alt live depuis `_wp_attachment_image_alt` |
| `error_message` | NULL en attente, rempli si apply a échoué |
| `scanned_at` | timestamp |

Lignes transitoires : apply OK → DELETE (+ log dans `wks3m_alt_history`), apply KO → `error_message` rempli, la ligne reste visible.

### `{prefix}wks3m_alt_history` (synchro ALT — journal)

| Colonne | Description |
|---|---|
| `id` | PK |
| `post_id`, `attachment_id`, `src` | identifie l'image |
| `old_alt` | valeur dans le contenu au moment de l'apply |
| `new_alt` | valeur de la biblio écrite |
| `applied_at` | timestamp |

Append-only. Visible dans l'onglet **Synchro ALT → Historique**. Vidable depuis **Réglages → Nettoyer les données** quand le travail est fini.

## Postmeta créés

| Clé | Portée | But |
|---|---|---|
| `_wp_attachment_image_alt` | attachment | standard WordPress |
| `_wks3m_source_url` | attachment | URL d'origine |
| `_wks3m_source_host` | attachment | host d'origine |
| `_wks3m_backup_{row_id}` | post | snapshot `post_content` pour rollback d'import |
| `_wks3m_replacements` | post | log des remplacements par row |

## Options

| Clé | Défaut | But |
|---|---|---|
| `wks3m_source_hosts` | `[]` | Array de hosts à scanner |
| `wks3m_auto_detect_external` | `1` | Si hosts vide, scanner toute image externe |
| `wks3m_strip_strapi_prefixes` | `1` | Grouper les variantes Strapi |
| `wks3m_dry_run` | `1` | Placeholder — le toggle est par-session côté JS |
| `wks3m_batch_size` | `10` | Placeholder |
| `wks3m_defer_thumbnails` | `0` | Skip `wp_generate_attachment_metadata()` à l'import |
| `wks3m_db_version` | `1.6.0` | Pour migrations de schéma |

Notes : la concurrence est fixée à 1 (séquentiel) et les retries à 3 par défaut — non configurables pour éviter les mauvaises surprises.

## Performance — gros volumes (>1000 images)

1. **Thumbnails différés** — coche "Thumbnails différés" pour sauter la génération des tailles WP pendant l'import. Un attachment est créé immédiatement après le download, sans regen ImageMagick (qui prend 1-5 s par image haute résolution). Une fois la migration finie, le bouton **« Générer les thumbnails manquants »** ou `wp media regenerate --only-missing` finalise le tout. Aucune perte de qualité — juste un déport dans le temps.
2. **Retry exponentiel** — 3 tentatives (1 s / 2 s / 4 s) pour les timeouts et 5xx. Les 4xx (hors 408/429) ne sont pas réessayés (inutile).
3. **Traitement séquentiel** — une migration à la fois côté client. Simple, prévisible, pas de saturation du host.

### WP-CLI — migration headless

Pour les très gros volumes (>2000 images), préférer WP-CLI : pas de timeout navigateur, pas de session admin verrouillée, le site reste réactif pendant que la commande tourne.

```bash
# Migrer tout ce qui est en attente + remplacer les URLs + thumbnails différés
wp wks3m migrate --replace --defer-thumbnails

# Relancer uniquement les échecs
wp wks3m migrate --status=failed --limit=500

# Simuler sans rien écrire
wp wks3m migrate --dry-run

# Générer les thumbnails différés a posteriori
wp wks3m finalize-thumbnails
```

En combinaison avec `tmux` ou `nohup`, la migration peut tourner pendant des heures sans garder de terminal ouvert :

```bash
nohup wp wks3m migrate --replace --defer-thumbnails > /tmp/wks3m.log 2>&1 &
```

## Désinstallation

`uninstall.php` supprime la table `{prefix}wks3m_migration_log` + toutes les options `wks3m_*` (et nettoie aussi les anciennes options `cxs3m_*` si présentes). Les backups dans `postmeta` peuvent être nettoyés manuellement si plus nécessaires (`_wks3m_backup_%`, `_wks3m_replacements`).

## Licence

GPL-2.0-or-later
