<?php
/**
 * Scan tab view (Phase 1 — read-only).
 *
 * @package WaasKitS3Migrator
 */

defined( 'ABSPATH' ) || exit;

$hosts          = \WKS3M\Settings::source_hosts();
$auto_detect    = \WKS3M\Settings::auto_detect_external();
$strip_prefixes = \WKS3M\Settings::strip_strapi_prefixes();
$site_host      = \WKS3M\Settings::site_host();
$saved          = isset( $_GET['sources_saved'] );
?>
<?php if ( $saved ) : ?>
	<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Sources enregistrées.', 'waaskit-s3-migrator' ); ?></p></div>
<?php endif; ?>

<div class="wks3m-panel">
	<h2><?php esc_html_e( 'Sources à scanner', 'waaskit-s3-migrator' ); ?></h2>
	<p class="description">
		<?php esc_html_e( 'Le plugin détecte les images distantes utilisées dans ton site (bucket S3, CDN, CMS externe…) par extension de fichier (jpg, jpeg, png, gif, webp, svg, avif).', 'waaskit-s3-migrator' ); ?>
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
							<?php esc_html_e( 'Un domaine par ligne (ou séparé par des virgules). Les URLs saisies en entier sont acceptées — seul le host sera conservé. Laisse vide pour activer la détection automatique de toute image externe au site.', 'waaskit-s3-migrator' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Détection automatique', 'waaskit-s3-migrator' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="auto_detect" value="1" <?php checked( $auto_detect ); ?> />
							<?php
							printf(
								/* translators: %s: site host */
								esc_html__( 'Si la liste des domaines est vide, scanner toute image externe au site actuel (%s).', 'waaskit-s3-migrator' ),
								'<code>' . esc_html( $site_host ) . '</code>'
							);
							?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Variantes Strapi', 'waaskit-s3-migrator' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="strip_prefixes" value="1" <?php checked( $strip_prefixes ); ?> />
							<?php esc_html_e( 'Regrouper les variantes Strapi (large_, medium_, small_, thumbnail_) comme une seule image.', 'waaskit-s3-migrator' ); ?>
						</label>
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
		} elseif ( $auto_detect ) {
			esc_html_e( 'Mode automatique : recherche de toute image externe au site.', 'waaskit-s3-migrator' );
		} else {
			esc_html_e( 'Aucune source configurée et détection automatique désactivée — le scan ne retournera rien. Ajoute au moins un domaine ou active la détection automatique.', 'waaskit-s3-migrator' );
		}
		?>
	</p>
	<p class="description"><?php esc_html_e( 'Aucune donnée n\'est modifiée à ce stade.', 'waaskit-s3-migrator' ); ?></p>

	<p>
		<button type="button" class="button button-primary" id="wks3m-scan-start">
			<?php esc_html_e( 'Lancer le scan', 'waaskit-s3-migrator' ); ?>
		</button>
		<label style="margin-left:1em;">
			<?php esc_html_e( 'Posts par requête :', 'waaskit-s3-migrator' ); ?>
			<input type="number" id="wks3m-scan-batch" value="100" min="10" max="500" step="10" />
		</label>
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
				<tr><th scope="row"><?php esc_html_e( 'Images distinctes (variantes regroupées)', 'waaskit-s3-migrator' ); ?></th><td class="base-keys">0</td></tr>
				<tr><th scope="row"><?php esc_html_e( 'URLs uniques trouvées (toutes variantes)', 'waaskit-s3-migrator' ); ?></th><td class="urls-found">0</td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Déjà connues (migrées ou en cours)', 'waaskit-s3-migrator' ); ?></th><td class="already-known">0</td></tr>
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
				esc_html__( 'Scan terminé. Passe à l\'onglet %s pour voir les images détectées, leurs métadonnées, et lancer les imports.', 'waaskit-s3-migrator' ),
				'<a href="' . esc_url( admin_url( 'tools.php?page=wks3m&tab=queue' ) ) . '"><strong>File d\'attente</strong></a>'
			);
			?>
		</p>
	</div>
</div>
