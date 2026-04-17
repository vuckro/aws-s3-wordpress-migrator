<?php
/**
 * Settings tab — deferred thumbnails option, import options reference,
 * Synchro ALT data purge.
 *
 * @package WaasKitS3Migrator
 */

defined( 'ABSPATH' ) || exit;

$perf_saved = ! empty( $_GET['perf_saved'] );
$purged     = isset( $_GET['purged'] ) ? sanitize_key( (string) $_GET['purged'] ) : '';

$diff_count    = (int) \WKS3M\Plugin::instance()->alt_diff_store()->counts()['total'];
$history_count = \WKS3M\Plugin::instance()->alt_history_store()->count();
?>
<?php if ( $perf_saved ) : ?>
	<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Réglages enregistrés.', 'waaskit-s3-migrator' ); ?></p></div>
<?php endif; ?>
<?php if ( 'diff' === $purged ) : ?>
	<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Divergences ALT en attente vidées.', 'waaskit-s3-migrator' ); ?></p></div>
<?php elseif ( 'history' === $purged ) : ?>
	<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Historique ALT vidé.', 'waaskit-s3-migrator' ); ?></p></div>
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
							<?php esc_html_e( 'Ne pas générer les tailles intermédiaires pendant la migration.', 'waaskit-s3-migrator' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( 'Accélère fortement l\'import (30–50 % par grosse image). Les thumbnails sont générés plus tard via le bouton ci-dessous ou la commande `wp media regenerate --only-missing`. Aucune perte de qualité.', 'waaskit-s3-migrator' ); ?>
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
	$pending = \WKS3M\Importer::pending_thumbnails_ids( 20000 );
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
		<?php esc_html_e( 'Alternative : lance `wp media regenerate --only-missing` via WP-CLI si disponible.', 'waaskit-s3-migrator' ); ?>
	</p>
</div>

<div class="wks3m-panel">
	<h2><?php esc_html_e( 'Options d\'import', 'waaskit-s3-migrator' ); ?></h2>
	<p class="description"><?php esc_html_e( 'Ces options se règlent dans la barre de la File d\'attente et s\'appliquent à chaque migration (unitaire ou en masse).', 'waaskit-s3-migrator' ); ?></p>
	<ul class="wks3m-options-summary">
		<li><strong><?php esc_html_e( 'Dry-run', 'waaskit-s3-migrator' ); ?></strong> — <?php esc_html_e( 'simule l\'import sans rien télécharger ni écrire.', 'waaskit-s3-migrator' ); ?></li>
		<li><strong><?php esc_html_e( 'Remplacer URLs après import', 'waaskit-s3-migrator' ); ?></strong> — <?php esc_html_e( 'enchaîne automatiquement le remplacement dans le contenu des articles.', 'waaskit-s3-migrator' ); ?></li>
	</ul>
	<p class="description">
		<?php
		printf(
			/* translators: %s: link label */
			esc_html__( 'Pour synchroniser les ALT modifiés dans la Bibliothèque vers le contenu des articles, utilise l\'onglet %s.', 'waaskit-s3-migrator' ),
			'<a href="' . \WKS3M\Admin\View_Helper::tab_url( 'alt-sync' ) . '"><strong>' . esc_html__( 'Synchro ALT', 'waaskit-s3-migrator' ) . '</strong></a>'
		);
		?>
	</p>
</div>

<div class="wks3m-panel" id="wks3m-purge">
	<h2><?php esc_html_e( 'Nettoyer les données', 'waaskit-s3-migrator' ); ?></h2>
	<p class="description">
		<?php esc_html_e( 'Quand le travail de synchronisation est terminé, vide les tables pour réduire l\'empreinte en base. Les backups du remplacement d\'URLs (dans les postmeta _wks3m_backup_*) ne sont PAS touchés — ils restent disponibles pour le rollback depuis l\'onglet Historique & Rollback.', 'waaskit-s3-migrator' ); ?>
	</p>

	<table class="form-table" role="presentation">
		<tbody>
			<tr>
				<th scope="row"><?php esc_html_e( 'Divergences ALT en attente', 'waaskit-s3-migrator' ); ?></th>
				<td>
					<p>
						<?php
						printf(
							/* translators: %d: number of rows */
							esc_html__( '%d ligne(s) dans wks3m_alt_diff.', 'waaskit-s3-migrator' ),
							(int) $diff_count
						);
						?>
					</p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
						onsubmit="return confirm('<?php echo esc_js( __( 'Vider toutes les divergences ALT en attente ? Tu pourras relancer un scan pour les recréer.', 'waaskit-s3-migrator' ) ); ?>');">
						<input type="hidden" name="action" value="wks3m_purge_alt_diff" />
						<?php wp_nonce_field( 'wks3m_purge_alt_diff' ); ?>
						<button type="submit" class="button" <?php disabled( 0, $diff_count ); ?>>
							<?php esc_html_e( 'Vider les divergences en attente', 'waaskit-s3-migrator' ); ?>
						</button>
					</form>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Historique des syncs ALT', 'waaskit-s3-migrator' ); ?></th>
				<td>
					<p>
						<?php
						printf(
							/* translators: %d: number of rows */
							esc_html__( '%d ligne(s) dans wks3m_alt_history.', 'waaskit-s3-migrator' ),
							(int) $history_count
						);
						?>
					</p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
						onsubmit="return confirm('<?php echo esc_js( __( 'Vider l\'historique complet des syncs ALT ? Cette action est irréversible (les posts ne sont pas modifiés).', 'waaskit-s3-migrator' ) ); ?>');">
						<input type="hidden" name="action" value="wks3m_purge_alt_history" />
						<?php wp_nonce_field( 'wks3m_purge_alt_history' ); ?>
						<button type="submit" class="button" <?php disabled( 0, $history_count ); ?>>
							<?php esc_html_e( 'Vider l\'historique', 'waaskit-s3-migrator' ); ?>
						</button>
					</form>
				</td>
			</tr>
		</tbody>
	</table>
</div>
