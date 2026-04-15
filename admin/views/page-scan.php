<?php
/**
 * Scan tab view (Phase 1 — read-only).
 *
 * @package ClaireexploreS3Migrator
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="cxs3m-panel">
	<h2><?php esc_html_e( 'Scan du site', 'claireexplore-s3-migrator' ); ?></h2>
	<p class="description">
		<?php
		printf(
			/* translators: %s: S3 host */
			esc_html__( 'Cherche toutes les URLs pointant vers %s dans le contenu des articles, les postmeta et les options. Aucune donnée n\'est modifiée à ce stade.', 'claireexplore-s3-migrator' ),
			'<code>' . esc_html( CXS3M_S3_HOST ) . '</code>'
		);
		?>
	</p>

	<p>
		<button type="button" class="button button-primary" id="cxs3m-scan-start">
			<?php esc_html_e( 'Lancer le scan', 'claireexplore-s3-migrator' ); ?>
		</button>
		<label style="margin-left:1em;">
			<?php esc_html_e( 'Taille du lot :', 'claireexplore-s3-migrator' ); ?>
			<input type="number" id="cxs3m-scan-batch" value="100" min="10" max="500" step="10" />
		</label>
		<span class="spinner" id="cxs3m-scan-spinner" style="float:none;margin-left:.5em;"></span>
	</p>

	<div id="cxs3m-scan-progress" class="cxs3m-progress" hidden>
		<div class="cxs3m-progress-bar"><span></span></div>
		<p class="cxs3m-progress-label"></p>
	</div>

	<div id="cxs3m-scan-summary" class="cxs3m-summary" hidden>
		<table class="widefat striped">
			<tbody>
				<tr><th scope="row"><?php esc_html_e( 'Posts analysés', 'claireexplore-s3-migrator' ); ?></th><td class="processed">0</td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Images distinctes (variantes regroupées)', 'claireexplore-s3-migrator' ); ?></th><td class="base-keys">0</td></tr>
				<tr><th scope="row"><?php esc_html_e( 'URLs S3 uniques (toutes variantes)', 'claireexplore-s3-migrator' ); ?></th><td class="urls-found">0</td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Déjà connues (migrées ou en cours)', 'claireexplore-s3-migrator' ); ?></th><td class="already-known">0</td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Lignes postmeta contenant des URLs S3', 'claireexplore-s3-migrator' ); ?></th><td class="postmeta-hits">–</td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Lignes options contenant des URLs S3', 'claireexplore-s3-migrator' ); ?></th><td class="options-hits">–</td></tr>
			</tbody>
		</table>
	</div>

	<div id="cxs3m-scan-results" class="cxs3m-results" hidden>
		<h3><?php esc_html_e( 'Aperçu des images détectées', 'claireexplore-s3-migrator' ); ?></h3>
		<p class="description">
			<?php esc_html_e( 'Liste en lecture seule. Les actions de migration seront disponibles dans l\'onglet File d\'attente en Phase 2.', 'claireexplore-s3-migrator' ); ?>
		</p>
		<table class="widefat striped cxs3m-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Clé de base (nom de fichier)', 'claireexplore-s3-migrator' ); ?></th>
					<th><?php esc_html_e( 'Variantes', 'claireexplore-s3-migrator' ); ?></th>
					<th><?php esc_html_e( 'Posts utilisant cette image', 'claireexplore-s3-migrator' ); ?></th>
					<th><?php esc_html_e( 'Statut', 'claireexplore-s3-migrator' ); ?></th>
				</tr>
			</thead>
			<tbody></tbody>
		</table>
	</div>
</div>
