<?php
/**
 * Queue tab — paginated list of detected images with per-row import and
 * bulk migration.
 *
 * @package WaasKitS3Migrator
 */

defined( 'ABSPATH' ) || exit;

use WKS3M\Admin\View_Helper;
use WKS3M\Migration_Row;
use WKS3M\Plugin;

$store  = Plugin::instance()->mapping_store();
$status = isset( $_GET['status'] ) ? sanitize_key( (string) $_GET['status'] ) : '';
$host   = isset( $_GET['host'] ) ? sanitize_text_field( (string) $_GET['host'] ) : '';
$search = isset( $_GET['s'] ) ? sanitize_text_field( (string) $_GET['s'] ) : '';
$paged  = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;

$data   = $store->list( [
	'status'   => $status,
	'host'     => $host,
	'search'   => $search,
	'page'     => $paged,
	'per_page' => 25,
] );
$counts = $store->counts_by_status();
$hosts  = $store->distinct_hosts();

$status_tabs = [
	''         => __( 'Toutes', 'waaskit-s3-migrator' ),
	'pending'  => __( 'En attente', 'waaskit-s3-migrator' ),
	'imported' => __( 'Importées', 'waaskit-s3-migrator' ),
	'replaced' => __( 'Remplacées', 'waaskit-s3-migrator' ),
	'failed'   => __( 'Échecs', 'waaskit-s3-migrator' ),
];

$rows = View_Helper::wrap_rows( $data['items'] );
?>
<div class="wks3m-panel">
	<h2><?php esc_html_e( 'File d\'attente', 'waaskit-s3-migrator' ); ?></h2>

	<ul class="subsubsub">
		<?php foreach ( $status_tabs as $key => $label ) :
			$count = '' === $key ? array_sum( $counts ) : (int) ( $counts[ $key ] ?? 0 );
			$url   = View_Helper::tab_url( 'queue', [ 'status' => $key ?: null ] );
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
		<input type="hidden" name="tab" value="queue" />
		<?php if ( $status ) : ?><input type="hidden" name="status" value="<?php echo esc_attr( $status ); ?>" /><?php endif; ?>

		<label><?php esc_html_e( 'Hôte :', 'waaskit-s3-migrator' ); ?>
			<select name="host">
				<option value=""><?php esc_html_e( 'Tous', 'waaskit-s3-migrator' ); ?></option>
				<?php foreach ( $hosts as $h ) : ?>
					<option value="<?php echo esc_attr( $h ); ?>" <?php selected( $host, $h ); ?>><?php echo esc_html( $h ); ?></option>
				<?php endforeach; ?>
			</select>
		</label>

		<label style="margin-left:.8em;"><?php esc_html_e( 'Recherche :', 'waaskit-s3-migrator' ); ?>
			<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Fichier, titre, alt…', 'waaskit-s3-migrator' ); ?>" />
		</label>

		<?php submit_button( __( 'Filtrer', 'waaskit-s3-migrator' ), 'secondary', '', false, [ 'style' => 'margin-left:.4em;' ] ); ?>
	</form>

	<div class="wks3m-bulk-bar">
		<label><input type="checkbox" id="wks3m-dry-run" checked /> <?php esc_html_e( 'Dry-run', 'waaskit-s3-migrator' ); ?></label>
		<label><input type="checkbox" id="wks3m-auto-replace" checked /> <?php esc_html_e( 'Remplacer URLs après import', 'waaskit-s3-migrator' ); ?></label>
		<span class="wks3m-bulk-bar-sep"></span>
		<button type="button" class="button button-primary" id="wks3m-bulk-all">
			<?php
			printf(
				/* translators: %d: number of pending rows */
				esc_html__( 'Tout migrer (%d en attente)', 'waaskit-s3-migrator' ),
				(int) $counts['pending']
			);
			?>
		</button>
		<button type="button" class="button" id="wks3m-bulk-selected"><?php esc_html_e( 'Migrer la sélection', 'waaskit-s3-migrator' ); ?></button>
		<span class="spinner" id="wks3m-bulk-spinner" style="float:none;"></span>
	</div>

	<div id="wks3m-bulk-progress" class="wks3m-progress" hidden>
		<div class="wks3m-progress-bar"><span></span></div>
		<p class="wks3m-progress-label"></p>
	</div>

	<?php if ( empty( $rows ) ) : ?>
		<p><?php esc_html_e( 'Aucun résultat. Lance un scan depuis l\'onglet Scan.', 'waaskit-s3-migrator' ); ?></p>
	<?php else : ?>
		<table class="widefat striped wks3m-queue-table">
			<thead>
				<tr>
					<td class="check-column"><input type="checkbox" id="wks3m-select-all" /></td>
					<th style="width:72px"><?php esc_html_e( 'Aperçu', 'waaskit-s3-migrator' ); ?></th>
					<th><?php esc_html_e( 'Fichier / Titre dérivé', 'waaskit-s3-migrator' ); ?></th>
					<th><?php esc_html_e( 'ALT détecté', 'waaskit-s3-migrator' ); ?></th>
					<th><?php esc_html_e( 'Hôte', 'waaskit-s3-migrator' ); ?></th>
					<th><?php esc_html_e( 'Posts', 'waaskit-s3-migrator' ); ?></th>
					<th><?php esc_html_e( 'Statut', 'waaskit-s3-migrator' ); ?></th>
					<th style="width:180px"><?php esc_html_e( 'Action', 'waaskit-s3-migrator' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $row ) : /** @var Migration_Row $row */ ?>
					<tr data-id="<?php echo (int) $row->id(); ?>">
						<th scope="row" class="check-column">
							<?php if ( in_array( $row->status(), [ 'pending', 'failed' ], true ) ) : ?>
								<input type="checkbox" class="wks3m-row-check" value="<?php echo (int) $row->id(); ?>" />
							<?php endif; ?>
						</th>
						<td>
							<?php echo View_Helper::thumb_html( $row->attachment_id() ?: null, $row->variants()[0] ?? '' ); ?>
						</td>
						<td>
							<strong><?php echo esc_html( $row->derived_title() ?: $row->base_key() ); ?></strong><br>
							<code class="wks3m-url-tiny"><?php echo esc_html( $row->base_key() ); ?></code>
							<div class="wks3m-variants">
								<?php foreach ( $row->variants() as $v ) : ?>
									<a href="<?php echo esc_url( $v ); ?>" target="_blank" rel="noopener"><?php echo esc_html( basename( (string) parse_url( $v, PHP_URL_PATH ) ) ); ?></a>
								<?php endforeach; ?>
							</div>
						</td>
						<td><?php echo esc_html( $row->alt_text() ); ?></td>
						<td><code><?php echo esc_html( $row->source_host() ); ?></code></td>
						<td><?php echo View_Helper::posts_links( $row->post_ids() ); ?></td>
						<td>
							<?php echo View_Helper::status_pill( $row->status() ); ?>
							<?php if ( '' !== $row->error_message() ) : ?>
								<br><small class="wks3m-error"><?php echo esc_html( $row->error_message() ); ?></small>
							<?php endif; ?>
						</td>
						<td>
							<?php if ( 'replaced' === $row->status() ) : ?>
								<a class="button button-link" href="<?php echo esc_url( admin_url( 'post.php?post=' . $row->attachment_id() . '&action=edit' ) ); ?>" target="_blank"><?php esc_html_e( 'Voir média', 'waaskit-s3-migrator' ); ?></a>
							<?php elseif ( 'imported' === $row->status() ) : ?>
								<button type="button" class="button wks3m-replace-btn" data-id="<?php echo (int) $row->id(); ?>"><?php esc_html_e( 'Remplacer URLs', 'waaskit-s3-migrator' ); ?></button>
							<?php else : ?>
								<button type="button" class="button button-primary wks3m-import-btn" data-id="<?php echo (int) $row->id(); ?>"><?php esc_html_e( 'Migrer', 'waaskit-s3-migrator' ); ?></button>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<?php View_Helper::pagination( (int) $data['pages'], (int) $data['page'] ); ?>
	<?php endif; ?>
</div>
