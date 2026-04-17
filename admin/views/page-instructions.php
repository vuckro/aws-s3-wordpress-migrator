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
		<?php esc_html_e( 'Tes images sont hébergées sur un serveur externe (S3, CDN, Strapi…) ? Ce plugin les rapatrie dans la Bibliothèque WordPress, met à jour les URLs dans tes articles, et synchronise les balises ALT que tu modifies dans la Bibliothèque vers le HTML des posts.', 'waaskit-s3-migrator' ); ?>
	</p>

	<ol class="wks3m-flow">
		<li>
			<span class="n">1</span>
			<strong><?php esc_html_e( 'Scan', 'waaskit-s3-migrator' ); ?></strong>
			<small><?php esc_html_e( 'Trouver les images externes', 'waaskit-s3-migrator' ); ?></small>
		</li>
		<li>
			<span class="n">2</span>
			<strong><?php esc_html_e( 'Importer', 'waaskit-s3-migrator' ); ?></strong>
			<small><?php esc_html_e( 'Rapatrier + réécrire les URLs', 'waaskit-s3-migrator' ); ?></small>
		</li>
		<li>
			<span class="n">3</span>
			<strong><?php esc_html_e( 'Synchro ALT', 'waaskit-s3-migrator' ); ?></strong>
			<small><?php esc_html_e( 'Propager les alt biblio dans les posts', 'waaskit-s3-migrator' ); ?></small>
		</li>
		<li>
			<span class="n">4</span>
			<strong><?php esc_html_e( 'Nettoyer', 'waaskit-s3-migrator' ); ?></strong>
			<small><?php esc_html_e( 'Libérer l\'espace en base', 'waaskit-s3-migrator' ); ?></small>
		</li>
	</ol>
</div>

<div class="wks3m-panel">
	<h2><?php esc_html_e( 'Étape par étape', 'waaskit-s3-migrator' ); ?></h2>

	<h3><?php esc_html_e( '1. Scanner le site', 'waaskit-s3-migrator' ); ?></h3>
	<p>
		<?php
		printf(
			esc_html__( 'Va dans l\'onglet %s et clique « Lancer le scan ».', 'waaskit-s3-migrator' ),
			'<a href="' . esc_url( View_Helper::tab_url( 'scan' ) ) . '"><strong>Scan</strong></a>'
		);
		?>
	</p>
	<p class="description">
		<?php esc_html_e( 'Par défaut, toute image hébergée sur un domaine différent du tien est détectée. Tu peux restreindre à des domaines précis (1 par ligne) si besoin. Le scan ne modifie rien — il liste juste les images trouvées.', 'waaskit-s3-migrator' ); ?>
	</p>

	<h3><?php esc_html_e( '2. Importer les images dans la Bibliothèque', 'waaskit-s3-migrator' ); ?></h3>
	<p>
		<?php
		printf(
			esc_html__( 'Onglet %s. Clique « Tout importer » pour lancer en masse, ou « Importer » ligne par ligne pour tester d\'abord.', 'waaskit-s3-migrator' ),
			'<a href="' . esc_url( View_Helper::tab_url( 'queue' ) ) . '"><strong>Importer / Remplacer</strong></a>'
		);
		?>
	</p>
	<p class="description">
		<?php esc_html_e( 'Chaque image est téléchargée, ajoutée dans la Bibliothèque avec son ALT et son titre, et son URL est automatiquement remplacée dans le contenu des articles qui la référencent.', 'waaskit-s3-migrator' ); ?>
	</p>
	<p class="description">
		<em><?php esc_html_e( 'Astuce : si tes ALT arrivent avec des placeholders (« xxx », « image »…), déplie le panneau « Nettoyer les ALT / Titres avant import » dans ce même onglet. Règle typique : « Si l\'ALT contient "xxx" → Copier depuis le Titre dérivé ».', 'waaskit-s3-migrator' ); ?></em>
	</p>

	<h3><?php esc_html_e( '3. Synchroniser les ALT (optionnel)', 'waaskit-s3-migrator' ); ?></h3>
	<p>
		<?php
		printf(
			esc_html__( 'Si tu édites un ALT depuis Média → Bibliothèque, WordPress ne le propage PAS vers le HTML des articles. L\'onglet %s résout ça : la Bibliothèque est la source de vérité, et le plugin pousse la nouvelle valeur dans les posts.', 'waaskit-s3-migrator' ),
			'<a href="' . esc_url( View_Helper::tab_url( 'alt-sync' ) ) . '"><strong>Synchro ALT</strong></a>'
		);
		?>
	</p>
	<p class="description">
		<?php esc_html_e( 'Clique « Lancer le scan » (il détecte les ALT divergents), puis « Tout synchroniser ». Direction unique : Bibliothèque → contenu.', 'waaskit-s3-migrator' ); ?>
	</p>

	<h3><?php esc_html_e( '4. Nettoyer la base (quand tout est terminé)', 'waaskit-s3-migrator' ); ?></h3>
	<p>
		<?php
		printf(
			esc_html__( 'Onglet %s, section « Nettoyer la base ». Trois boutons indépendants :', 'waaskit-s3-migrator' ),
			'<a href="' . esc_url( View_Helper::tab_url( 'settings' ) ) . '"><strong>Réglages</strong></a>'
		);
		?>
	</p>
	<ul class="wks3m-options-summary">
		<li><strong><?php esc_html_e( 'Lignes terminées', 'waaskit-s3-migrator' ); ?></strong> — <?php esc_html_e( 'supprime le journal des imports déjà traités.', 'waaskit-s3-migrator' ); ?></li>
		<li><strong><?php esc_html_e( 'Divergences ALT', 'waaskit-s3-migrator' ); ?></strong> — <?php esc_html_e( 'vide le tableau des synchros en attente.', 'waaskit-s3-migrator' ); ?></li>
		<li><strong><?php esc_html_e( 'Révisions de posts', 'waaskit-s3-migrator' ); ?></strong> — <?php esc_html_e( 'garde les 5 dernières par post. Souvent le plus gros gain d\'espace en base.', 'waaskit-s3-migrator' ); ?></li>
	</ul>
	<p class="description">
		<?php esc_html_e( 'Chaque bouton affiche le nombre de MB libérés immédiatement.', 'waaskit-s3-migrator' ); ?>
	</p>
</div>

<div class="wks3m-panel">
	<h2><?php esc_html_e( 'Bon à savoir', 'waaskit-s3-migrator' ); ?></h2>
	<ul class="wks3m-options-summary">
		<li><?php esc_html_e( 'Le plugin couvre les <img> dans le contenu des articles (post, page, CPT, blocs Gutenberg).', 'waaskit-s3-migrator' ); ?></li>
		<li><?php esc_html_e( 'Il ne scanne PAS les champs ACF, les options de thème ou les widgets. Pour ces cas, utilise wp search-replace ou WP Migrate DB Pro.', 'waaskit-s3-migrator' ); ?></li>
		<li><?php esc_html_e( 'Fais une sauvegarde de la base avant la première migration réelle.', 'waaskit-s3-migrator' ); ?></li>
		<li>
			<?php
			printf(
				esc_html__( 'Ajoute %s dans wp-config.php pour éviter l\'accumulation de révisions à l\'avenir.', 'waaskit-s3-migrator' ),
				'<code>define(\'WP_POST_REVISIONS\', 5);</code>'
			);
			?>
		</li>
	</ul>
</div>

<div class="wks3m-panel">
	<h2><?php esc_html_e( 'WP-CLI (pour les gros volumes)', 'waaskit-s3-migrator' ); ?></h2>
	<pre style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:4px;padding:12px;overflow-x:auto;">
# Importer tout ce qui est en attente (remplace aussi les URLs)
wp wks3m migrate

# Relancer uniquement les échecs
wp wks3m migrate --status=failed --limit=500

# Import rapide sans thumbnails (à finaliser ensuite)
wp wks3m migrate --defer-thumbnails

# Générer les thumbnails différés
wp wks3m finalize-thumbnails</pre>
</div>
