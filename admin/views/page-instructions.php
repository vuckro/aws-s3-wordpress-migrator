<?php
/**
 * Instructions tab — end-to-end user guide.
 *
 * @package WaasKitS3Migrator
 */

defined( 'ABSPATH' ) || exit;

use WKS3M\Admin\View_Helper;
?>

<div class="wks3m-panel">
	<h2><?php esc_html_e( 'À quoi sert ce plugin', 'waaskit-s3-migrator' ); ?></h2>
	<p class="description" style="font-size:14px;">
		<?php esc_html_e( 'Il détecte les images hébergées sur un domaine externe (bucket AWS S3, CDN, CMS headless type Strapi…), les importe dans la Bibliothèque WordPress locale avec leurs métadonnées SEO, remplace les URLs dans le contenu des articles, et synchronise les balises ALT entre la Bibliothèque et le HTML des posts.', 'waaskit-s3-migrator' ); ?>
	</p>

	<ol class="wks3m-flow">
		<li>
			<span class="n">1</span>
			<strong><?php esc_html_e( 'Scan', 'waaskit-s3-migrator' ); ?></strong>
			<small><?php esc_html_e( 'Détecter les images externes', 'waaskit-s3-migrator' ); ?></small>
		</li>
		<li>
			<span class="n">2</span>
			<strong><?php esc_html_e( 'Transformer', 'waaskit-s3-migrator' ); ?></strong>
			<small><?php esc_html_e( 'Nettoyer ALT / Titres (optionnel)', 'waaskit-s3-migrator' ); ?></small>
		</li>
		<li>
			<span class="n">3</span>
			<strong><?php esc_html_e( 'Migrer', 'waaskit-s3-migrator' ); ?></strong>
			<small><?php esc_html_e( 'Télécharger + remplacer URLs', 'waaskit-s3-migrator' ); ?></small>
		</li>
		<li>
			<span class="n">4</span>
			<strong><?php esc_html_e( 'Synchro ALT', 'waaskit-s3-migrator' ); ?></strong>
			<small><?php esc_html_e( 'Biblio → contenu', 'waaskit-s3-migrator' ); ?></small>
		</li>
		<li>
			<span class="n">5</span>
			<strong><?php esc_html_e( 'Nettoyer', 'waaskit-s3-migrator' ); ?></strong>
			<small><?php esc_html_e( 'Libérer l\'espace BDD', 'waaskit-s3-migrator' ); ?></small>
		</li>
	</ol>
</div>

<div class="wks3m-panel">
	<h2><?php esc_html_e( 'Parcours complet (A → Z)', 'waaskit-s3-migrator' ); ?></h2>

	<h3><?php esc_html_e( '1. Scan — détecter les images externes', 'waaskit-s3-migrator' ); ?></h3>
	<p>
		<?php
		printf(
			/* translators: %s: link to Scan tab */
			esc_html__( 'Onglet %s. Configure les domaines à chercher (ou laisse vide pour la détection auto de toute image externe). Regroupe les variantes Strapi (large_, medium_, small_, thumbnail_) si besoin. Clique « Lancer le scan ».', 'waaskit-s3-migrator' ),
			'<a href="' . View_Helper::tab_url( 'scan' ) . '"><strong>Scan</strong></a>'
		);
		?>
	</p>
	<p class="description">
		<?php esc_html_e( 'Le plugin parcourt wp_posts par lots, détecte les <img> externes, extrait leur ALT actuel et dérive un titre lisible depuis le nom de fichier. Résultats stockés dans wks3m_migration_log. Rien n\'est modifié à ce stade.', 'waaskit-s3-migrator' ); ?>
	</p>

	<h3><?php esc_html_e( '2. Transformer les ALT / Titres (optionnel, avant import)', 'waaskit-s3-migrator' ); ?></h3>
	<p>
		<?php
		printf(
			/* translators: %s: link to Queue tab */
			esc_html__( 'Onglet %s → section « Transformer les ALT / Titres ». Règle « Si… alors… » pour nettoyer en masse les placeholders.', 'waaskit-s3-migrator' ),
			'<a href="' . View_Helper::tab_url( 'queue' ) . '"><strong>File d\'attente</strong></a>'
		);
		?>
	</p>
	<p class="description">
		<?php esc_html_e( 'Exemple typique : « Si ALT égale "xxx" → Copier depuis le Titre dérivé ». Preview puis Appliquer. Utile juste après un scan avant de lancer la migration, pour que les attachments importés partent directement avec le bon ALT.', 'waaskit-s3-migrator' ); ?>
	</p>

	<h3><?php esc_html_e( '3. Migrer', 'waaskit-s3-migrator' ); ?></h3>
	<p>
		<?php
		printf(
			/* translators: %s: link to Queue tab */
			esc_html__( 'Onglet %s → section « File d\'attente ». Deux options par session :', 'waaskit-s3-migrator' ),
			'<a href="' . View_Helper::tab_url( 'queue' ) . '"><strong>File d\'attente</strong></a>'
		);
		?>
	</p>
	<ul class="wks3m-options-summary">
		<li><strong><?php esc_html_e( 'Dry-run', 'waaskit-s3-migrator' ); ?></strong> — <?php esc_html_e( 'simule sans télécharger ni écrire. À utiliser pour une première vérification.', 'waaskit-s3-migrator' ); ?></li>
		<li><strong><?php esc_html_e( 'Remplacer URLs après import', 'waaskit-s3-migrator' ); ?></strong> — <?php esc_html_e( 'enchaîne automatiquement le remplacement des URLs dans le post_content après chaque import réussi.', 'waaskit-s3-migrator' ); ?></li>
	</ul>
	<p>
		<?php esc_html_e( 'Clic « Tout migrer » : chaque image est téléchargée, insérée comme attachment (avec ALT + titre), puis l\'URL est remplacée dans le post_content si l\'option est cochée. Bouton Stop disponible à tout moment.', 'waaskit-s3-migrator' ); ?>
	</p>

	<h3><?php esc_html_e( '4. Synchro ALT (Bibliothèque → contenu)', 'waaskit-s3-migrator' ); ?></h3>
	<p>
		<?php esc_html_e( 'Cas d\'usage : après la migration, tu édites les ALT dans Média → Bibliothèque. Ces modifications ne se propagent PAS automatiquement vers le HTML des articles (WordPress garde les <img alt="…"> en dur dans post_content). Ce panneau fait la synchro dans le bon sens : la Bibliothèque est la source de vérité.', 'waaskit-s3-migrator' ); ?>
	</p>
	<p>
		<?php
		printf(
			/* translators: %s: link to Queue tab */
			esc_html__( 'Onglet %s → section « Synchro ALT ». Clique « Lancer le scan » (différent du scan d\'URLs — celui-ci détecte les divergences d\'ALT). Le tableau liste chaque <img> divergent. Clique « Remplacer » par ligne ou « Tout synchroniser » en masse.', 'waaskit-s3-migrator' ),
			'<a href="' . View_Helper::tab_url( 'queue' ) . '"><strong>File d\'attente</strong></a>'
		);
		?>
	</p>
	<p class="description">
		<?php esc_html_e( 'L\'écriture se fait en SQL direct, sans créer de révision et sans déclencher la cascade save_post (Yoast, slim-seo, etc.). Un apply prend environ 15 ms.', 'waaskit-s3-migrator' ); ?>
	</p>

	<h3><?php esc_html_e( '5. Nettoyer (quand le travail est terminé)', 'waaskit-s3-migrator' ); ?></h3>
	<p>
		<?php
		printf(
			/* translators: %s: link to Queue tab */
			esc_html__( 'Onglet %s → section « Nettoyer ». Trois purges indépendantes, chacune avec un OPTIMIZE TABLE qui libère l\'espace disque immédiatement.', 'waaskit-s3-migrator' ),
			'<a href="' . View_Helper::tab_url( 'queue' ) . '"><strong>File d\'attente</strong></a>'
		);
		?>
	</p>
	<ul class="wks3m-options-summary">
		<li><strong><?php esc_html_e( 'Lignes de migration terminées', 'waaskit-s3-migrator' ); ?></strong> — <?php esc_html_e( 'supprime les rows replaced + failed de wks3m_migration_log.', 'waaskit-s3-migrator' ); ?></li>
		<li><strong><?php esc_html_e( 'Divergences ALT en attente', 'waaskit-s3-migrator' ); ?></strong> — <?php esc_html_e( 'vide la table wks3m_alt_diff. Un nouveau scan reconstruira.', 'waaskit-s3-migrator' ); ?></li>
		<li><strong><?php esc_html_e( 'Anciennes révisions de posts', 'waaskit-s3-migrator' ); ?></strong> — <?php esc_html_e( 'garde les N plus récentes par post (défaut 5). Souvent le plus gros gain BDD après une migration.', 'waaskit-s3-migrator' ); ?></li>
	</ul>
	<p class="description">
		<?php
		printf(
			/* translators: %s: the define statement */
			esc_html__( 'Prévention : ajoute %s dans wp-config.php pour plafonner les révisions à l\'avenir.', 'waaskit-s3-migrator' ),
			'<code>define(\'WP_POST_REVISIONS\', 5);</code>'
		);
		?>
	</p>
</div>

<div class="wks3m-panel">
	<h2><?php esc_html_e( 'Ce qui est couvert et ce qui ne l\'est pas', 'waaskit-s3-migrator' ); ?></h2>

	<h3 style="color:#166534;"><?php esc_html_e( 'Couvert', 'waaskit-s3-migrator' ); ?></h3>
	<ul class="wks3m-options-summary">
		<li><code>&lt;img&gt;</code> <?php esc_html_e( 'dans wp_posts.post_content, tous types (post, page, CPT).', 'waaskit-s3-migrator' ); ?></li>
		<li><?php esc_html_e( 'Blocs Gutenberg wp:image et wp:gallery (y compris le champ id dans le JSON du bloc).', 'waaskit-s3-migrator' ); ?></li>
		<li><?php esc_html_e( 'Variantes de taille Strapi (large_, medium_, small_, thumbnail_) regroupées en une seule image si l\'option est active.', 'waaskit-s3-migrator' ); ?></li>
		<li><?php esc_html_e( 'URLs S3/CDN restées dans le contenu après la migration — la résolution alt-sync les rattrape via le journal des variantes.', 'waaskit-s3-migrator' ); ?></li>
	</ul>

	<h3 style="color:#991b1b;"><?php esc_html_e( 'Non couvert automatiquement', 'waaskit-s3-migrator' ); ?></h3>
	<ul class="wks3m-options-summary">
		<li><?php esc_html_e( 'Champs ACF / postmeta custom contenant des URLs ou des ALT.', 'waaskit-s3-migrator' ); ?></li>
		<li><?php esc_html_e( 'Options de thème et réglages du customizer.', 'waaskit-s3-migrator' ); ?></li>
		<li><?php esc_html_e( 'Widgets classiques (hors block-based theme).', 'waaskit-s3-migrator' ); ?></li>
	</ul>
	<p class="description">
		<?php esc_html_e( 'L\'onglet Scan affiche un compteur « Lignes postmeta / options contenant des URLs externes » à titre indicatif. Pour traiter ces cas, utilise `wp search-replace` via WP-CLI, ou WP Migrate DB Pro.', 'waaskit-s3-migrator' ); ?>
	</p>
</div>

<div class="wks3m-panel">
	<h2><?php esc_html_e( 'Raccourcis WP-CLI', 'waaskit-s3-migrator' ); ?></h2>
	<pre style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:4px;padding:12px;overflow-x:auto;">
# Migrer tout ce qui est en attente + remplacer les URLs
wp wks3m migrate --replace

# Mode simulation (aucune écriture)
wp wks3m migrate --dry-run

# Relancer uniquement les échecs
wp wks3m migrate --status=failed --limit=500

# Import rapide sans thumbnails (à finaliser ensuite)
wp wks3m migrate --replace --defer-thumbnails

# Générer les thumbnails différés
wp wks3m finalize-thumbnails</pre>
	<p class="description">
		<?php esc_html_e( 'WP-CLI est recommandé pour les très gros volumes (>2000 images) : pas de timeout navigateur, le site reste réactif.', 'waaskit-s3-migrator' ); ?>
	</p>
</div>

<div class="wks3m-panel">
	<h2><?php esc_html_e( 'Bonnes pratiques', 'waaskit-s3-migrator' ); ?></h2>
	<ul class="wks3m-options-summary">
		<li><?php esc_html_e( 'Sauvegarde la BDD avant la première migration réelle. Le plugin ne crée pas de backup automatique.', 'waaskit-s3-migrator' ); ?></li>
		<li><?php esc_html_e( 'Teste toujours en dry-run avant de passer en mode réel, surtout après changement de configuration.', 'waaskit-s3-migrator' ); ?></li>
		<li><?php esc_html_e( 'Après la migration, lance les trois purges pour réduire l\'empreinte BDD de plusieurs centaines de MB.', 'waaskit-s3-migrator' ); ?></li>
		<li>
			<?php
			printf(
				/* translators: %s: the define statement */
				esc_html__( 'Ajoute %s dans wp-config.php pour éviter l\'accumulation de révisions à l\'avenir.', 'waaskit-s3-migrator' ),
				'<code>define(\'WP_POST_REVISIONS\', 5);</code>'
			);
			?>
		</li>
	</ul>
</div>

<div class="wks3m-panel">
	<h2><?php esc_html_e( 'Où regarder en cas de problème', 'waaskit-s3-migrator' ); ?></h2>
	<ul class="wks3m-options-summary">
		<li><?php esc_html_e( 'Scan qui ne trouve rien : vérifie que le domaine source est bien saisi (sans https://, juste le host) ou que la détection auto est activée.', 'waaskit-s3-migrator' ); ?></li>
		<li><?php esc_html_e( 'Import qui échoue : onglet File d\'attente, filtre « Échecs » — la colonne Statut affiche le message d\'erreur (403 / 404 / timeout / MIME invalide…).', 'waaskit-s3-migrator' ); ?></li>
		<li><?php esc_html_e( 'Logs détaillés : wp-content/debug.log quand WP_DEBUG_LOG est activé.', 'waaskit-s3-migrator' ); ?></li>
	</ul>
</div>
