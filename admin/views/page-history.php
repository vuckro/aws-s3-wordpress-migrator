<?php
/**
 * History & Rollback tab view.
 *
 * @package WaasKitS3Migrator
 */

defined( 'ABSPATH' ) || exit;

$store  = \WKS3M\Plugin::instance()->mapping_store();
$counts = $store->counts_by_status();
$status = isset( $_GET['status'] ) ? sanitize_key( (string) $_GET['status'] ) : 'replaced';
$page   = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;

$data = $store->list( [
	'status'   => in_array( $status, [ 'imported', 'replaced', 'rolled_back', 'failed' ], true ) ? $status : '',
	'page'     => $page,
	'per_page' => 25,
] );

$base_url = admin_url( 'tools.php?page=wks3m&tab=history' );
?>
<div class="wks3m-panel">
	<h2><?php esc_html_e( 'Historique & Rollback', 'waaskit-s3-migrator' ); ?></h2>

	<ul class="subsubsub">
		<?php
		$tabs = [
			'imported'    => __( 'Importées', 'waaskit-s3-migrator' ),
			'replaced'    => __( 'Remplacées', 'waaskit-s3-migrator' ),
			'rolled_back' => __( 'Rollback', 'waaskit-s3-migrator' ),
			'failed'      => __( 'Échecs', 'waaskit-s3-migrator' ),
		];
		$last = array_key_last( $tabs );
		foreach ( $tabs as $key => $label ) :
			$url = esc_url( add_query_arg( [ 'status' => $key, 'paged' => null ], $base_url ) );
		?>
			<li>
				<a href="<?php echo $url; ?>" class="<?php echo $status === $key ? 'current' : ''; ?>">
					<?php echo esc_html( $label ); ?>
					<span class="count">(<?php echo (int) $counts[ $key ]; ?>)</span>
				</a>
				<?php if ( $key !== $last ) echo ' |'; ?>
			</li>
		<?php endforeach; ?>
	</ul>

	<p class="description">
		<?php esc_html_e( 'Rollback restaure le contenu des articles à son état d\'avant migration (sauvegardé en postmeta). La case à cocher permet aussi de supprimer l\'image de la Media Library.', 'waaskit-s3-migrator' ); ?>
	</p>

	<?php if ( empty( $data['items'] ) ) : ?>
		<p><?php esc_html_e( 'Rien à afficher.', 'waaskit-s3-migrator' ); ?></p>
	<?php else : ?>
		<table class="widefat striped wks3m-history-table">
			<thead>
				<tr>
					<th style="width:72px"><?php esc_html_e( 'Média', 'waaskit-s3-migrator' ); ?></th>
					<th><?php esc_html_e( 'Titre', 'waaskit-s3-migrator' ); ?></th>
					<th><?php esc_html_e( 'Hôte source', 'waaskit-s3-migrator' ); ?></th>
					<th><?php esc_html_e( 'Posts affectés', 'waaskit-s3-migrator' ); ?></th>
					<th><?php esc_html_e( 'Statut', 'waaskit-s3-migrator' ); ?></th>
					<th><?php esc_html_e( 'Dates', 'waaskit-s3-migrator' ); ?></th>
					<th style="width:220px"><?php esc_html_e( 'Action', 'waaskit-s3-migrator' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $data['items'] as $row ) :
					$posts    = json_decode( (string) $row['post_ids'], true ) ?: [];
					$can_rollback = in_array( $row['status'], [ 'imported', 'replaced' ], true );
				?>
					<tr data-id="<?php echo (int) $row['id']; ?>">
						<td>
							<?php if ( ! empty( $row['attachment_id'] ) ) : ?>
								<?php echo wp_get_attachment_image( (int) $row['attachment_id'], [ 56, 56 ] ); ?>
							<?php endif; ?>
						</td>
						<td>
							<strong><?php echo esc_html( (string) $row['derived_title'] ?: (string) $row['base_key'] ); ?></strong><br>
							<code class="wks3m-url-tiny"><?php echo esc_html( (string) $row['base_key'] ); ?></code>
						</td>
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
							<span class="wks3m-status wks3m-status-<?php echo esc_attr( (string) $row['status'] ); ?>"><?php echo esc_html( ucfirst( (string) $row['status'] ) ); ?></span>
							<?php if ( ! empty( $row['error_message'] ) ) : ?>
								<br><small class="wks3m-error"><?php echo esc_html( (string) $row['error_message'] ); ?></small>
							<?php endif; ?>
						</td>
						<td>
							<small>
								<?php if ( ! empty( $row['replaced_at'] ) ) : ?>
									R : <?php echo esc_html( (string) $row['replaced_at'] ); ?><br>
								<?php endif; ?>
								<?php if ( ! empty( $row['rolled_back_at'] ) ) : ?>
									RB : <?php echo esc_html( (string) $row['rolled_back_at'] ); ?><br>
								<?php endif; ?>
								<?php if ( ! empty( $row['created_at'] ) ) : ?>
									C : <?php echo esc_html( (string) $row['created_at'] ); ?>
								<?php endif; ?>
							</small>
						</td>
						<td>
							<?php if ( $can_rollback ) : ?>
								<label style="display:block;margin-bottom:4px;">
									<input type="checkbox" class="wks3m-rollback-delete" />
									<?php esc_html_e( 'Supprimer le média', 'waaskit-s3-migrator' ); ?>
								</label>
								<button type="button" class="button wks3m-rollback-btn" data-id="<?php echo (int) $row['id']; ?>">
									<?php esc_html_e( 'Rollback', 'waaskit-s3-migrator' ); ?>
								</button>
							<?php elseif ( 'rolled_back' === $row['status'] ) : ?>
								<em><?php esc_html_e( 'Déjà rollback', 'waaskit-s3-migrator' ); ?></em>
							<?php else : ?>
								<em>—</em>
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
