<?php
/**
 * Partial — read-only log of successful ALT syncs.
 *
 * Required in scope from page-alt-sync.php:
 *   $history_store, $search, $paged
 *
 * @package WaasKitS3Migrator
 */

defined( 'ABSPATH' ) || exit;

use WKS3M\Admin\View_Helper;

$data = $history_store->list( [
	'search'   => $search,
	'page'     => $paged,
	'per_page' => 25,
] );
?>

<form method="get" class="wks3m-queue-filters">
	<input type="hidden" name="page" value="wks3m" />
	<input type="hidden" name="tab" value="alt-sync" />
	<input type="hidden" name="view" value="history" />

	<label><?php esc_html_e( 'Recherche :', 'waaskit-s3-migrator' ); ?>
		<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Fichier, alt…', 'waaskit-s3-migrator' ); ?>" />
	</label>
	<?php submit_button( __( 'Filtrer', 'waaskit-s3-migrator' ), 'secondary', '', false, [ 'style' => 'margin-left:.4em;' ] ); ?>
</form>

<p class="description">
	<?php
	printf(
		/* translators: %s: link label */
		esc_html__( 'Journal en lecture seule. Pour le vider, utilise %s.', 'waaskit-s3-migrator' ),
		'<a href="' . View_Helper::tab_url( 'settings' ) . '#wks3m-purge"><strong>' . esc_html__( 'Réglages → Nettoyer les données', 'waaskit-s3-migrator' ) . '</strong></a>'
	);
	?>
</p>

<?php if ( empty( $data['items'] ) ) : ?>
	<p><?php esc_html_e( 'Aucun historique. Les syncs réussies apparaîtront ici.', 'waaskit-s3-migrator' ); ?></p>
<?php else : ?>
	<table class="widefat striped wks3m-alt-table">
		<thead>
			<tr>
				<th style="width:72px"><?php esc_html_e( 'Aperçu', 'waaskit-s3-migrator' ); ?></th>
				<th><?php esc_html_e( 'Post', 'waaskit-s3-migrator' ); ?></th>
				<th><?php esc_html_e( 'Ancien ALT', 'waaskit-s3-migrator' ); ?></th>
				<th><?php esc_html_e( 'Nouvel ALT', 'waaskit-s3-migrator' ); ?></th>
				<th style="width:160px"><?php esc_html_e( 'Date', 'waaskit-s3-migrator' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $data['items'] as $row ) :
				$post_id = (int) $row['post_id'];
				$post    = get_post( $post_id );
				$att_id  = (int) $row['attachment_id'];
			?>
				<tr>
					<td><?php echo View_Helper::thumb_html( $att_id ?: null, (string) $row['src'] ); ?></td>
					<td>
						<?php if ( $post ) : ?>
							<a href="<?php echo esc_url( admin_url( 'post.php?post=' . $post_id . '&action=edit' ) ); ?>" target="_blank">
								<strong>#<?php echo $post_id; ?></strong> — <?php echo esc_html( get_the_title( $post ) ?: '(sans titre)' ); ?>
							</a>
						<?php else : ?>
							<em>#<?php echo $post_id; ?> (supprimé)</em>
						<?php endif; ?>
						<br>
						<code class="wks3m-url-tiny"><?php echo esc_html( basename( (string) wp_parse_url( (string) $row['src'], PHP_URL_PATH ) ) ); ?></code>
					</td>
					<td><?php echo '' === (string) $row['old_alt'] ? '<em>(vide)</em>' : esc_html( (string) $row['old_alt'] ); ?></td>
					<td><strong><?php echo esc_html( (string) $row['new_alt'] ); ?></strong></td>
					<td><small><?php echo esc_html( mysql2date( 'Y-m-d H:i', (string) $row['applied_at'] ) ); ?></small></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<?php View_Helper::pagination( (int) $data['pages'], (int) $data['page'] ); ?>
<?php endif; ?>
