<?php
/**
 * Queue tab view — paginated list of detected images with per-row import button.
 *
 * @package WaasKitS3Migrator
 */

defined( 'ABSPATH' ) || exit;

$store  = \WKS3M\Plugin::instance()->mapping_store();
$status = isset( $_GET['status'] ) ? sanitize_key( (string) $_GET['status'] ) : '';
$host   = isset( $_GET['host'] ) ? sanitize_text_field( (string) $_GET['host'] ) : '';
$search = isset( $_GET['s'] ) ? sanitize_text_field( (string) $_GET['s'] ) : '';
$page   = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;

$data   = $store->list( [
	'status'   => $status,
	'host'     => $host,
	'search'   => $search,
	'page'     => $page,
	'per_page' => 25,
] );
$counts = $store->counts_by_status();
$hosts  = $store->distinct_hosts();

$base_url = admin_url( 'tools.php?page=wks3m&tab=queue' );

function wks3m_queue_status_url( string $base_url, string $status ): string {
	return esc_url( add_query_arg( [ 'status' => $status ?: null, 'paged' => null ], $base_url ) );
}
?>

<div class="wks3m-panel">
	<h2><?php esc_html_e( 'File d\'attente des images détectées', 'waaskit-s3-migrator' ); ?></h2>

	<ul class="subsubsub">
		<li><a href="<?php echo wks3m_queue_status_url( $base_url, '' ); ?>" class="<?php echo '' === $status ? 'current' : ''; ?>"><?php esc_html_e( 'Toutes', 'waaskit-s3-migrator' ); ?> <span class="count">(<?php echo (int) array_sum( $counts ); ?>)</span></a> |</li>
		<li><a href="<?php echo wks3m_queue_status_url( $base_url, 'pending' ); ?>" class="<?php echo 'pending' === $status ? 'current' : ''; ?>"><?php esc_html_e( 'En attente', 'waaskit-s3-migrator' ); ?> <span class="count">(<?php echo (int) $counts['pending']; ?>)</span></a> |</li>
		<li><a href="<?php echo wks3m_queue_status_url( $base_url, 'imported' ); ?>" class="<?php echo 'imported' === $status ? 'current' : ''; ?>"><?php esc_html_e( 'Importées', 'waaskit-s3-migrator' ); ?> <span class="count">(<?php echo (int) $counts['imported']; ?>)</span></a> |</li>
		<li><a href="<?php echo wks3m_queue_status_url( $base_url, 'failed' ); ?>" class="<?php echo 'failed' === $status ? 'current' : ''; ?>"><?php esc_html_e( 'Échecs', 'waaskit-s3-migrator' ); ?> <span class="count">(<?php echo (int) $counts['failed']; ?>)</span></a></li>
	</ul>

	<form method="get" class="wks3m-queue-filters">
		<input type="hidden" name="page" value="wks3m" />
		<input type="hidden" name="tab" value="queue" />
		<?php if ( $status ) : ?>
			<input type="hidden" name="status" value="<?php echo esc_attr( $status ); ?>" />
		<?php endif; ?>

		<label>
			<?php esc_html_e( 'Hôte :', 'waaskit-s3-migrator' ); ?>
			<select name="host">
				<option value=""><?php esc_html_e( 'Tous', 'waaskit-s3-migrator' ); ?></option>
				<?php foreach ( $hosts as $h ) : ?>
					<option value="<?php echo esc_attr( $h ); ?>" <?php selected( $host, $h ); ?>><?php echo esc_html( $h ); ?></option>
				<?php endforeach; ?>
			</select>
		</label>

		<label style="margin-left:.8em;">
			<?php esc_html_e( 'Recherche :', 'waaskit-s3-migrator' ); ?>
			<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Fichier, titre, alt…', 'waaskit-s3-migrator' ); ?>" />
		</label>

		<?php submit_button( __( 'Filtrer', 'waaskit-s3-migrator' ), 'secondary', '', false, [ 'style' => 'margin-left:.4em;' ] ); ?>
	</form>

	<p class="wks3m-import-mode">
		<label><input type="checkbox" id="wks3m-dry-run" checked /> <?php esc_html_e( 'Mode dry-run (simuler sans télécharger)', 'waaskit-s3-migrator' ); ?></label>
	</p>

	<?php if ( empty( $data['items'] ) ) : ?>
		<p><?php esc_html_e( 'Aucun résultat. Lance un scan depuis l\'onglet Scan.', 'waaskit-s3-migrator' ); ?></p>
	<?php else : ?>
		<table class="widefat striped wks3m-queue-table">
			<thead>
				<tr>
					<th style="width:72px"><?php esc_html_e( 'Aperçu', 'waaskit-s3-migrator' ); ?></th>
					<th><?php esc_html_e( 'Fichier / Titre dérivé', 'waaskit-s3-migrator' ); ?></th>
					<th><?php esc_html_e( 'Alt détecté', 'waaskit-s3-migrator' ); ?></th>
					<th><?php esc_html_e( 'Hôte', 'waaskit-s3-migrator' ); ?></th>
					<th><?php esc_html_e( 'Posts', 'waaskit-s3-migrator' ); ?></th>
					<th><?php esc_html_e( 'Statut', 'waaskit-s3-migrator' ); ?></th>
					<th style="width:120px"><?php esc_html_e( 'Action', 'waaskit-s3-migrator' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $data['items'] as $row ) :
					$variants = json_decode( (string) $row['source_url_variants'], true ) ?: [];
					$posts    = json_decode( (string) $row['post_ids'], true ) ?: [];
					$thumb    = $variants[0] ?? '';
				?>
					<tr data-id="<?php echo (int) $row['id']; ?>">
						<td>
							<?php if ( ! empty( $row['attachment_id'] ) ) : ?>
								<?php echo wp_get_attachment_image( (int) $row['attachment_id'], [ 56, 56 ] ); ?>
							<?php elseif ( $thumb ) : ?>
								<img loading="lazy" src="<?php echo esc_url( $thumb ); ?>" alt="" class="wks3m-thumb" />
							<?php endif; ?>
						</td>
						<td>
							<strong><?php echo esc_html( (string) $row['derived_title'] ?: (string) $row['base_key'] ); ?></strong><br>
							<code class="wks3m-url-tiny" title="<?php echo esc_attr( (string) $row['base_key'] ); ?>"><?php echo esc_html( (string) $row['base_key'] ); ?></code>
							<div class="wks3m-variants">
								<?php foreach ( $variants as $v ) : ?>
									<a href="<?php echo esc_url( $v ); ?>" target="_blank" rel="noopener"><?php echo esc_html( basename( (string) parse_url( $v, PHP_URL_PATH ) ) ); ?></a>
								<?php endforeach; ?>
							</div>
						</td>
						<td><?php echo esc_html( (string) $row['alt_text'] ); ?></td>
						<td><code><?php echo esc_html( (string) $row['source_host'] ); ?></code></td>
						<td>
							<?php
							$shown = array_slice( $posts, 0, 5 );
							foreach ( $shown as $pid ) {
								printf(
									'<a href="%s" target="_blank">#%d</a> ',
									esc_url( admin_url( 'post.php?post=' . (int) $pid . '&action=edit' ) ),
									(int) $pid
								);
							}
							if ( count( $posts ) > 5 ) {
								echo '<span class="wks3m-more">+' . ( count( $posts ) - 5 ) . '</span>';
							}
							?>
						</td>
						<td>
							<span class="wks3m-status wks3m-status-<?php echo esc_attr( (string) $row['status'] ); ?>">
								<?php echo esc_html( ucfirst( (string) $row['status'] ) ); ?>
							</span>
							<?php if ( ! empty( $row['error_message'] ) ) : ?>
								<br><small class="wks3m-error"><?php echo esc_html( (string) $row['error_message'] ); ?></small>
							<?php endif; ?>
						</td>
						<td>
							<?php if ( 'imported' !== $row['status'] ) : ?>
								<button type="button" class="button wks3m-import-btn" data-id="<?php echo (int) $row['id']; ?>">
									<?php esc_html_e( 'Importer', 'waaskit-s3-migrator' ); ?>
								</button>
							<?php else : ?>
								<a class="button button-link" href="<?php echo esc_url( admin_url( 'post.php?post=' . (int) $row['attachment_id'] . '&action=edit' ) ); ?>" target="_blank"><?php esc_html_e( 'Voir média', 'waaskit-s3-migrator' ); ?></a>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<div class="tablenav bottom">
			<div class="tablenav-pages">
				<?php
				echo paginate_links( [
					'base'      => add_query_arg( 'paged', '%#%' ),
					'format'    => '',
					'total'     => (int) $data['pages'],
					'current'   => (int) $data['page'],
					'prev_text' => '‹',
					'next_text' => '›',
				] );
				?>
			</div>
		</div>
	<?php endif; ?>
</div>
