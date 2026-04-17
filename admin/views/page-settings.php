<?php
/**
 * Settings tab — thumbnails options + cleanup / purge actions.
 *
 * @package WaasKitS3Migrator
 */

defined( 'ABSPATH' ) || exit;

use WKS3M\Admin\View_Helper;
use WKS3M\Plugin;

$perf_saved   = ! empty( $_GET['perf_saved'] );
$purged_rows  = isset( $_GET['purged_rows'] ) ? (int) $_GET['purged_rows'] : -1;
$purged_revs  = isset( $_GET['purged_revs'] ) ? (int) $_GET['purged_revs'] : -1;
$freed_mb     = isset( $_GET['freed_mb'] ) ? (int) $_GET['freed_mb'] : 0;
$purged       = isset( $_GET['purged'] ) ? sanitize_key( (string) $_GET['purged'] ) : '';

$alt_store       = Plugin::instance()->alt_diff_store();
$finished_count  = (int) $alt_store->counts()['total']; // placeholder, overwritten below
$queue_counts    = Plugin::instance()->mapping_store()->counts_by_status();
$finished_queue  = (int) ( $queue_counts['replaced'] ?? 0 ) + (int) ( $queue_counts['failed'] ?? 0 );
$alt_diff_count  = (int) $alt_store->counts()['total'];
global $wpdb;
$revisions_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision'" );
?>

<?php if ( $perf_saved ) : ?>
	<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Réglages enregistrés.', 'waaskit-s3-migrator' ); ?></p></div>
<?php endif; ?>
<?php if ( $purged_rows >= 0 ) : ?>
	<div class="notice notice-<?php echo $purged_rows > 0 ? 'success' : 'info'; ?> is-dismissible"><p>
		<?php
		if ( $purged_rows > 0 ) {
			printf( esc_html__( 'Lignes terminées supprimées : %1$d. Espace libéré : %2$d MB.', 'waaskit-s3-migrator' ), $purged_rows, $freed_mb );
		} else {
			esc_html_e( 'Rien à purger — aucune ligne terminée (remplacée / en échec).', 'waaskit-s3-migrator' );
		}
		?>
	</p></div>
<?php elseif ( $purged_revs >= 0 ) : ?>
	<div class="notice notice-<?php echo $purged_revs > 0 ? 'success' : 'info'; ?> is-dismissible"><p>
		<?php
		if ( $purged_revs > 0 ) {
			printf( esc_html__( 'Révisions supprimées : %1$d. Espace libéré : %2$d MB.', 'waaskit-s3-migrator' ), $purged_revs, $freed_mb );
		} else {
			esc_html_e( 'Rien à purger — tous les posts ont déjà 5 révisions ou moins.', 'waaskit-s3-migrator' );
		}
		?>
	</p></div>
<?php elseif ( 'alt_diff' === $purged ) : ?>
	<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Divergences ALT en attente vidées.', 'waaskit-s3-migrator' ); ?></p></div>
<?php endif; ?>

<div class="wks3m-panel">
	<h2><?php esc_html_e( 'Performance', 'waaskit-s3-migrator' ); ?></h2>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="wks3m_save_performance" />
		<?php wp_nonce_field( 'wks3m_save_performance' ); ?>

		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row"><label for="wks3m-defer-thumbs"><?php esc_html_e( 'Thumbnails différés', 'waaskit-s3-migrator' ); ?></label></th>
					<td>
						<label>
							<input type="checkbox" id="wks3m-defer-thumbs" name="defer_thumbnails" value="1" <?php checked( \WKS3M\Settings::defer_thumbnails() ); ?> />
							<?php esc_html_e( 'Ne pas générer les tailles intermédiaires pendant l\'import.', 'waaskit-s3-migrator' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( 'Accélère fortement l\'import sur les grosses images (jusqu\'à 50 %). Les thumbnails sont générés plus tard via le bouton ci-dessous. Aucune perte de qualité.', 'waaskit-s3-migrator' ); ?>
						</p>
					</td>
				</tr>
			</tbody>
		</table>

		<?php submit_button( __( 'Enregistrer', 'waaskit-s3-migrator' ) ); ?>
	</form>

	<hr />

	<h3><?php esc_html_e( 'Finaliser les thumbnails différés', 'waaskit-s3-migrator' ); ?></h3>
	<?php
	$pending       = \WKS3M\Importer::pending_thumbnails_ids( 20000 );
	$pending_count = count( $pending );
	?>
	<p>
		<?php
		printf(
			/* translators: %d: number of attachments awaiting thumbnail generation */
			esc_html__( '%d attachment(s) importé(s) sans thumbnails.', 'waaskit-s3-migrator' ),
			(int) $pending_count
		);
		?>
	</p>
	<p>
		<button type="button" class="button button-secondary" id="wks3m-finalize-thumbs" <?php disabled( 0, $pending_count ); ?>>
			<?php esc_html_e( 'Générer les thumbnails manquants', 'waaskit-s3-migrator' ); ?>
		</button>
		<button type="button" class="button" id="wks3m-finalize-stop" hidden><?php esc_html_e( 'Stop', 'waaskit-s3-migrator' ); ?></button>
		<span class="spinner" id="wks3m-finalize-spinner" style="float:none;"></span>
	</p>
	<div id="wks3m-finalize-progress" class="wks3m-progress" hidden>
		<div class="wks3m-progress-bar"><span></span></div>
		<p class="wks3m-progress-label"></p>
	</div>
	<p class="description">
		<?php esc_html_e( 'Alternative via WP-CLI : `wp media regenerate --only-missing`.', 'waaskit-s3-migrator' ); ?>
	</p>
</div>

<div class="wks3m-panel">
	<h2><?php esc_html_e( 'Nettoyer la base', 'waaskit-s3-migrator' ); ?></h2>
	<p class="description">
		<?php esc_html_e( 'Quand tes imports sont terminés, ces trois boutons libèrent de l\'espace en base. Chacun exécute un OPTIMIZE TABLE derrière, et t\'affiche les MB récupérés.', 'waaskit-s3-migrator' ); ?>
	</p>

	<table class="form-table" role="presentation">
		<tbody>
			<tr>
				<th scope="row"><?php esc_html_e( 'Lignes de migration terminées', 'waaskit-s3-migrator' ); ?></th>
				<td>
					<p class="description">
						<?php printf( esc_html__( '%d ligne(s) remplacée(s) / en échec dans le journal.', 'waaskit-s3-migrator' ), (int) $finished_queue ); ?>
					</p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
						onsubmit="return confirm('<?php echo esc_js( __( 'Supprimer toutes les lignes terminées du journal de migration ?', 'waaskit-s3-migrator' ) ); ?>');">
						<input type="hidden" name="action" value="wks3m_purge_queue" />
						<?php wp_nonce_field( 'wks3m_purge_queue' ); ?>
						<button type="submit" class="button" <?php disabled( 0, $finished_queue ); ?>>
							<?php esc_html_e( 'Purger les lignes terminées', 'waaskit-s3-migrator' ); ?>
						</button>
					</form>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Divergences ALT en attente', 'waaskit-s3-migrator' ); ?></th>
				<td>
					<p class="description">
						<?php printf( esc_html__( '%d ligne(s) en attente dans la table de synchro ALT. Un nouveau scan les reconstruira.', 'waaskit-s3-migrator' ), (int) $alt_diff_count ); ?>
					</p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
						onsubmit="return confirm('<?php echo esc_js( __( 'Vider toutes les divergences ALT en attente ?', 'waaskit-s3-migrator' ) ); ?>');">
						<input type="hidden" name="action" value="wks3m_purge_alt_diff" />
						<?php wp_nonce_field( 'wks3m_purge_alt_diff' ); ?>
						<button type="submit" class="button" <?php disabled( 0, $alt_diff_count ); ?>>
							<?php esc_html_e( 'Vider les divergences ALT', 'waaskit-s3-migrator' ); ?>
						</button>
					</form>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Anciennes révisions de posts', 'waaskit-s3-migrator' ); ?></th>
				<td>
					<p class="description">
						<?php printf( esc_html__( '%d révision(s) totales. Garde les 5 plus récentes par post — souvent le plus gros gain de place.', 'waaskit-s3-migrator' ), (int) $revisions_count ); ?>
					</p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
						onsubmit="return confirm('<?php echo esc_js( __( 'Supprimer toutes les révisions sauf les 5 dernières par post ?', 'waaskit-s3-migrator' ) ); ?>');">
						<input type="hidden" name="action" value="wks3m_purge_revisions" />
						<?php wp_nonce_field( 'wks3m_purge_revisions' ); ?>
						<button type="submit" class="button" <?php disabled( 0, $revisions_count ); ?>>
							<?php esc_html_e( 'Purger les révisions', 'waaskit-s3-migrator' ); ?>
						</button>
					</form>
				</td>
			</tr>
		</tbody>
	</table>

	<p class="description" style="margin-top:1em;">
		<?php
		printf(
			/* translators: %s: the define statement */
			esc_html__( 'Pour empêcher l\'accumulation à l\'avenir, ajoute %s dans ton wp-config.php.', 'waaskit-s3-migrator' ),
			'<code>define(\'WP_POST_REVISIONS\', 5);</code>'
		);
		?>
	</p>
</div>
