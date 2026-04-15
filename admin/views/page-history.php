<?php
/**
 * History & Rollback tab.
 *
 * @package WaasKitS3Migrator
 */

defined( 'ABSPATH' ) || exit;

use WKS3M\Admin\View_Helper;
use WKS3M\Migration_Row;
use WKS3M\Plugin;

$store  = Plugin::instance()->mapping_store();
$status = isset( $_GET['status'] ) ? sanitize_key( (string) $_GET['status'] ) : 'replaced';
$paged  = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;

$allowed = [ 'imported', 'replaced', 'rolled_back', 'failed' ];
if ( ! in_array( $status, $allowed, true ) ) {
	$status = 'replaced';
}

$data   = $store->list( [ 'status' => $status, 'page' => $paged, 'per_page' => 25 ] );
$counts = $store->counts_by_status();
$rows   = View_Helper::wrap_rows( $data['items'] );

$tabs = [
	'imported'    => __( 'Importées', 'waaskit-s3-migrator' ),
	'replaced'    => __( 'Remplacées', 'waaskit-s3-migrator' ),
	'rolled_back' => __( 'Rollback', 'waaskit-s3-migrator' ),
	'failed'      => __( 'Échecs', 'waaskit-s3-migrator' ),
];
?>
<div class="wks3m-panel">
	<h2><?php esc_html_e( 'Historique & Rollback', 'waaskit-s3-migrator' ); ?></h2>

	<ul class="subsubsub">
		<?php foreach ( $tabs as $key => $label ) :
			$url = View_Helper::tab_url( 'history', [ 'status' => $key ] );
		?>
			<li>
				<a href="<?php echo $url; ?>" class="<?php echo $status === $key ? 'current' : ''; ?>">
					<?php echo esc_html( $label ); ?> <span class="count">(<?php echo (int) $counts[ $key ]; ?>)</span>
				</a><?php if ( $key !== array_key_last( $tabs ) ) echo ' |'; ?>
			</li>
		<?php endforeach; ?>
	</ul>

	<p class="description"><?php esc_html_e( 'Rollback restaure le contenu des articles à son état d\'avant migration. La case à cocher permet aussi de supprimer l\'image de la Media Library.', 'waaskit-s3-migrator' ); ?></p>

	<?php if ( empty( $rows ) ) : ?>
		<p><?php esc_html_e( 'Rien à afficher.', 'waaskit-s3-migrator' ); ?></p>
	<?php else : ?>
		<table class="widefat striped wks3m-history-table">
			<thead>
				<tr>
					<th style="width:72px"><?php esc_html_e( 'Média', 'waaskit-s3-migrator' ); ?></th>
					<th><?php esc_html_e( 'Titre', 'waaskit-s3-migrator' ); ?></th>
					<th><?php esc_html_e( 'Hôte source', 'waaskit-s3-migrator' ); ?></th>
					<th><?php esc_html_e( 'Posts', 'waaskit-s3-migrator' ); ?></th>
					<th><?php esc_html_e( 'Statut', 'waaskit-s3-migrator' ); ?></th>
					<th style="width:220px"><?php esc_html_e( 'Action', 'waaskit-s3-migrator' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $row ) : /** @var Migration_Row $row */
					$can_rollback = in_array( $row->status(), [ 'imported', 'replaced' ], true );
				?>
					<tr data-id="<?php echo (int) $row->id(); ?>">
						<td><?php echo View_Helper::thumb_html( $row->attachment_id() ?: null ); ?></td>
						<td>
							<strong><?php echo esc_html( $row->derived_title() ?: $row->base_key() ); ?></strong><br>
							<code class="wks3m-url-tiny"><?php echo esc_html( $row->base_key() ); ?></code>
						</td>
						<td><code><?php echo esc_html( $row->source_host() ); ?></code></td>
						<td><?php echo View_Helper::posts_links( $row->post_ids() ); ?></td>
						<td>
							<?php echo View_Helper::status_pill( $row->status() ); ?>
							<?php if ( '' !== $row->error_message() ) : ?>
								<br><small class="wks3m-error"><?php echo esc_html( $row->error_message() ); ?></small>
							<?php endif; ?>
						</td>
						<td>
							<?php if ( $can_rollback ) : ?>
								<label style="display:block;margin-bottom:4px;">
									<input type="checkbox" class="wks3m-rollback-delete" />
									<?php esc_html_e( 'Supprimer le média', 'waaskit-s3-migrator' ); ?>
								</label>
								<button type="button" class="button wks3m-rollback-btn" data-id="<?php echo (int) $row->id(); ?>">
									<?php esc_html_e( 'Rollback', 'waaskit-s3-migrator' ); ?>
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
