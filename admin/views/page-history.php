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
$rollbackable = (int) $counts['imported'] + (int) $counts['replaced'];

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

	<p class="description"><?php esc_html_e( 'Le rollback restaure le contenu des articles à leur état d\'avant migration. Les médias importés restent dans la Media Library (supprime-les manuellement si besoin).', 'waaskit-s3-migrator' ); ?></p>

	<div class="wks3m-bulk-bar">
		<button type="button" class="button button-primary" id="wks3m-rollback-all" <?php disabled( 0 === $rollbackable ); ?>>
			<?php
			printf(
				/* translators: %d: number of rollbackable rows */
				esc_html__( 'Tout rollback (%d)', 'waaskit-s3-migrator' ),
				$rollbackable
			);
			?>
		</button>
		<button type="button" class="button" id="wks3m-bulk-stop" hidden><?php esc_html_e( 'Stop', 'waaskit-s3-migrator' ); ?></button>
		<span class="spinner" id="wks3m-bulk-spinner" style="float:none;"></span>
	</div>

	<div id="wks3m-bulk-progress" class="wks3m-progress" hidden>
		<div class="wks3m-progress-bar"><span></span></div>
		<p class="wks3m-progress-label"></p>
	</div>

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
					<th style="width:120px"><?php esc_html_e( 'Action', 'waaskit-s3-migrator' ); ?></th>
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
