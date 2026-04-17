<?php
/**
 * Synchro ALT tab — detect and apply ALT divergences between the Media Library
 * (_wp_attachment_image_alt) and the hardcoded <img alt> values in post_content.
 *
 * @package WaasKitS3Migrator
 */

defined( 'ABSPATH' ) || exit;

use WKS3M\Admin\View_Helper;
use WKS3M\Alt_Diff;
use WKS3M\Plugin;

$store  = Plugin::instance()->alt_diff_store();
$status = isset( $_GET['status'] ) ? sanitize_key( (string) $_GET['status'] ) : 'diff';
$search = isset( $_GET['s'] ) ? sanitize_text_field( (string) $_GET['s'] ) : '';
$paged  = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;

$data   = $store->list( [
	'status'   => $status,
	'search'   => $search,
	'page'     => $paged,
	'per_page' => 25,
] );
$counts = $store->counts_by_status();

$status_tabs = [
	'diff'        => __( 'À synchroniser', 'waaskit-s3-migrator' ),
	'applied'     => __( 'Synchronisés', 'waaskit-s3-migrator' ),
	'rolled_back' => __( 'Rollback', 'waaskit-s3-migrator' ),
	'failed'      => __( 'Échecs', 'waaskit-s3-migrator' ),
];
?>
<div class="wks3m-panel">
	<h2><?php esc_html_e( 'Scan des ALT divergents', 'waaskit-s3-migrator' ); ?></h2>
	<p class="description">
		<?php esc_html_e( 'Détecte chaque <img> dans le contenu des articles dont l\'attribut alt diverge de la Bibliothèque WordPress. Résolution par src URL via attachment_url_to_postid() — fiable même si la classe wp-image-N est incorrecte.', 'waaskit-s3-migrator' ); ?>
	</p>
	<p class="description">
		<?php esc_html_e( 'Périmètre : posts déjà traités par la migration (statut « Remplacée » dans la file d\'attente). Rien n\'est modifié pendant le scan.', 'waaskit-s3-migrator' ); ?>
	</p>

	<p>
		<button type="button" class="button button-primary" id="wks3m-alt-scan-start">
			<?php esc_html_e( 'Lancer le scan', 'waaskit-s3-migrator' ); ?>
		</button>
		<label style="margin-left:1em;">
			<?php esc_html_e( 'Posts par requête :', 'waaskit-s3-migrator' ); ?>
			<input type="number" id="wks3m-alt-scan-batch" value="50" min="10" max="200" step="10" />
		</label>
		<span class="spinner" id="wks3m-alt-scan-spinner" style="float:none;margin-left:.5em;"></span>
	</p>

	<div id="wks3m-alt-scan-progress" class="wks3m-progress" hidden>
		<div class="wks3m-progress-bar"><span></span></div>
		<p class="wks3m-progress-label"></p>
	</div>

	<div id="wks3m-alt-scan-summary" class="wks3m-summary" hidden>
		<table class="widefat striped">
			<tbody>
				<tr><th scope="row"><?php esc_html_e( 'Posts analysés', 'waaskit-s3-migrator' ); ?></th><td class="processed">0</td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Balises <img> examinées', 'waaskit-s3-migrator' ); ?></th><td class="imgs">0</td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Divergences détectées', 'waaskit-s3-migrator' ); ?></th><td class="diffs">0</td></tr>
			</tbody>
		</table>
	</div>

	<div id="wks3m-alt-scan-done" class="wks3m-scan-done" hidden>
		<p><?php esc_html_e( 'Scan terminé. Le tableau ci-dessous liste les divergences à synchroniser.', 'waaskit-s3-migrator' ); ?></p>
	</div>
</div>

<div class="wks3m-panel">
	<h2><?php esc_html_e( 'Divergences détectées', 'waaskit-s3-migrator' ); ?></h2>

	<ul class="subsubsub">
		<?php foreach ( $status_tabs as $key => $label ) :
			$count = (int) ( $counts[ $key ] ?? 0 );
			$url   = View_Helper::tab_url( 'alt-sync', [ 'status' => $key ] );
		?>
			<li>
				<a href="<?php echo $url; ?>" class="<?php echo $status === $key ? 'current' : ''; ?>">
					<?php echo esc_html( $label ); ?> <span class="count">(<?php echo (int) $count; ?>)</span>
				</a><?php if ( $key !== array_key_last( $status_tabs ) ) echo ' |'; ?>
			</li>
		<?php endforeach; ?>
	</ul>

	<form method="get" class="wks3m-queue-filters">
		<input type="hidden" name="page" value="wks3m" />
		<input type="hidden" name="tab" value="alt-sync" />
		<input type="hidden" name="status" value="<?php echo esc_attr( $status ); ?>" />

		<label><?php esc_html_e( 'Recherche :', 'waaskit-s3-migrator' ); ?>
			<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Fichier, alt…', 'waaskit-s3-migrator' ); ?>" />
		</label>

		<?php submit_button( __( 'Filtrer', 'waaskit-s3-migrator' ), 'secondary', '', false, [ 'style' => 'margin-left:.4em;' ] ); ?>
	</form>

	<?php if ( 'diff' === $status && (int) $counts['diff'] > 0 ) : ?>
		<div class="wks3m-bulk-bar">
			<button type="button" class="button button-primary" id="wks3m-alt-bulk-apply">
				<?php
				printf(
					/* translators: %d: number of divergences */
					esc_html__( 'Tout synchroniser (%d)', 'waaskit-s3-migrator' ),
					(int) $counts['diff']
				);
				?>
			</button>
			<button type="button" class="button" id="wks3m-alt-bulk-stop" hidden><?php esc_html_e( 'Stop', 'waaskit-s3-migrator' ); ?></button>
			<span class="spinner" id="wks3m-alt-bulk-spinner" style="float:none;"></span>
		</div>
		<div id="wks3m-alt-bulk-progress" class="wks3m-progress" hidden>
			<div class="wks3m-progress-bar"><span></span></div>
			<p class="wks3m-progress-label"></p>
		</div>
	<?php endif; ?>

	<?php if ( empty( $data['items'] ) ) : ?>
		<p><?php esc_html_e( 'Aucune ligne. Lance un scan depuis ce même onglet.', 'waaskit-s3-migrator' ); ?></p>
	<?php else : ?>
		<table class="widefat striped wks3m-alt-table">
			<thead>
				<tr>
					<th style="width:72px"><?php esc_html_e( 'Aperçu', 'waaskit-s3-migrator' ); ?></th>
					<th><?php esc_html_e( 'Post', 'waaskit-s3-migrator' ); ?></th>
					<th><?php esc_html_e( 'ALT contenu', 'waaskit-s3-migrator' ); ?></th>
					<th><?php esc_html_e( 'ALT Bibliothèque', 'waaskit-s3-migrator' ); ?></th>
					<th><?php esc_html_e( 'Statut', 'waaskit-s3-migrator' ); ?></th>
					<th style="width:180px"><?php esc_html_e( 'Action', 'waaskit-s3-migrator' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $data['items'] as $raw ) :
					$diff = new Alt_Diff( (array) $raw );
					$post = get_post( $diff->post_id() );
				?>
					<tr data-diff-id="<?php echo (int) $diff->id(); ?>">
						<td><?php echo View_Helper::thumb_html( $diff->attachment_id() ?: null, $diff->src() ); ?></td>
						<td>
							<?php if ( $post ) : ?>
								<a href="<?php echo esc_url( admin_url( 'post.php?post=' . (int) $diff->post_id() . '&action=edit' ) ); ?>" target="_blank">
									<strong>#<?php echo (int) $diff->post_id(); ?></strong> — <?php echo esc_html( get_the_title( $post ) ?: '(sans titre)' ); ?>
								</a>
							<?php else : ?>
								<em>#<?php echo (int) $diff->post_id(); ?> (supprimé)</em>
							<?php endif; ?>
							<br>
							<code class="wks3m-url-tiny"><?php echo esc_html( basename( (string) wp_parse_url( $diff->src(), PHP_URL_PATH ) ) ); ?></code>
						</td>
						<td><?php echo '' === $diff->content_alt() ? '<em>(vide)</em>' : esc_html( $diff->content_alt() ); ?></td>
						<td><strong><?php echo esc_html( $diff->library_alt() ); ?></strong></td>
						<td>
							<?php echo View_Helper::status_pill( $diff->status() ); ?>
							<?php if ( '' !== $diff->error_message() ) : ?>
								<br><small class="wks3m-error"><?php echo esc_html( $diff->error_message() ); ?></small>
							<?php endif; ?>
						</td>
						<td>
							<?php if ( 'diff' === $diff->status() ) : ?>
								<button type="button" class="button button-primary wks3m-alt-apply-btn" data-id="<?php echo (int) $diff->id(); ?>">
									<?php esc_html_e( 'Remplacer', 'waaskit-s3-migrator' ); ?>
								</button>
							<?php elseif ( 'applied' === $diff->status() ) : ?>
								<button type="button" class="button wks3m-alt-rollback-btn" data-id="<?php echo (int) $diff->id(); ?>">
									<?php esc_html_e( '↺ Rollback', 'waaskit-s3-migrator' ); ?>
								</button>
							<?php else : ?>
								<em>—</em>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<?php View_Helper::pagination( (int) $data['pages'], (int) $data['page'] ); ?>
	<?php endif; ?>
</div>
