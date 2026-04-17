<?php
/**
 * Scan tab — configure source hosts, run the detection scan, see counters.
 *
 * @package WaasKitS3Migrator
 */

defined( 'ABSPATH' ) || exit;

$hosts     = \WKS3M\Settings::source_hosts();
$site_host = \WKS3M\Settings::site_host();
$saved     = isset( $_GET['sources_saved'] );
?>
<?php if ( $saved ) : ?>
	<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Sources enregistrées.', 'waaskit-s3-migrator' ); ?></p></div>
<?php endif; ?>

<div class="wks3m-panel">
	<h2><?php esc_html_e( 'Sources à scanner', 'waaskit-s3-migrator' ); ?></h2>
	<p class="description">
		<?php esc_html_e( 'Le plugin détecte les images distantes (S3, CDN, CMS externe…) par extension de fichier (jpg, jpeg, png, gif, webp, svg, avif).', 'waaskit-s3-migrator' ); ?>
	</p>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'wks3m_save_sources' ); ?>
		<input type="hidden" name="action" value="wks3m_save_sources" />

		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row">
						<label for="wks3m-source-hosts"><?php esc_html_e( 'Domaines sources (optionnel)', 'waaskit-s3-migrator' ); ?></label>
					</th>
					<td>
						<textarea id="wks3m-source-hosts" name="source_hosts" rows="4" cols="60" class="large-text code" placeholder="<?php echo esc_attr( "my-bucket.s3.amazonaws.com\ncdn.example.com" ); ?>"><?php echo esc_textarea( implode( "\n", $hosts ) ); ?></textarea>
						<p class="description">
							<?php
							printf(
								/* translators: %s: site host in code tags */
								esc_html__( 'Un domaine par ligne. Laisse vide : le plugin détecte automatiquement toute image externe au site actuel (%s) et regroupe les variantes Strapi (large_, medium_…) en une seule image.', 'waaskit-s3-migrator' ),
								'<code>' . esc_html( $site_host ) . '</code>'
							);
							?>
						</p>
					</td>
				</tr>
			</tbody>
		</table>

		<?php submit_button( __( 'Enregistrer les sources', 'waaskit-s3-migrator' ), 'secondary' ); ?>
	</form>
</div>

<div class="wks3m-panel">
	<h2><?php esc_html_e( 'Scan du site', 'waaskit-s3-migrator' ); ?></h2>
	<p class="description">
		<?php
		if ( ! empty( $hosts ) ) {
			printf(
				/* translators: %s: comma-separated host list */
				esc_html__( 'Recherche des images hébergées sur : %s', 'waaskit-s3-migrator' ),
				'<code>' . esc_html( implode( ', ', $hosts ) ) . '</code>'
			);
		} else {
			esc_html_e( 'Mode automatique : recherche de toute image externe au site.', 'waaskit-s3-migrator' );
		}
		?>
	</p>
	<p class="description"><?php esc_html_e( 'Aucune donnée n\'est modifiée à ce stade.', 'waaskit-s3-migrator' ); ?></p>

	<p>
		<button type="button" class="button button-primary" id="wks3m-scan-start">
			<?php esc_html_e( 'Lancer le scan', 'waaskit-s3-migrator' ); ?>
		</button>
		<span class="spinner" id="wks3m-scan-spinner" style="float:none;margin-left:.5em;"></span>
	</p>

	<div id="wks3m-scan-progress" class="wks3m-progress" hidden>
		<div class="wks3m-progress-bar"><span></span></div>
		<p class="wks3m-progress-label"></p>
	</div>

	<div id="wks3m-scan-summary" class="wks3m-summary" hidden>
		<table class="widefat striped">
			<tbody>
				<tr><th scope="row"><?php esc_html_e( 'Posts analysés', 'waaskit-s3-migrator' ); ?></th><td class="processed">0</td></tr>
				<tr><th scope="row"><?php esc_html_e( 'URLs uniques trouvées', 'waaskit-s3-migrator' ); ?></th><td class="urls-found">0</td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Lignes postmeta contenant des URLs externes', 'waaskit-s3-migrator' ); ?></th><td class="postmeta-hits">–</td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Lignes options contenant des URLs externes', 'waaskit-s3-migrator' ); ?></th><td class="options-hits">–</td></tr>
			</tbody>
		</table>
	</div>

	<div id="wks3m-scan-done" class="wks3m-scan-done" hidden>
		<p>
			<?php
			printf(
				/* translators: %s: Queue tab link */
				esc_html__( 'Scan terminé. Passe à l\'onglet %s pour voir les images détectées et lancer les imports.', 'waaskit-s3-migrator' ),
				'<a href="' . esc_url( \WKS3M\Admin\View_Helper::tab_url( 'queue' ) ) . '"><strong>' . esc_html__( 'Importer / Remplacer', 'waaskit-s3-migrator' ) . '</strong></a>'
			);
			?>
		</p>
	</div>
</div>
