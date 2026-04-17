<?php
/**
 * Partial — list of pending divergences with per-row + bulk apply.
 *
 * Required in scope from page-alt-sync.php:
 *   $diff_store, $diff_counts, $search, $errors, $paged
 *
 * @package WaasKitS3Migrator
 */

defined( 'ABSPATH' ) || exit;

use WKS3M\Admin\View_Helper;
use WKS3M\Alt_Diff;

$data = $diff_store->list( [
	'search'      => $search,
	'errors_only' => $errors,
	'page'        => $paged,
	'per_page'    => 25,
] );
?>

<?php if ( (int) $diff_counts['errors'] > 0 ) : ?>
	<ul class="subsubsub wks3m-subfilter">
		<li>
			<a href="<?php echo View_Helper::tab_url( 'alt-sync' ); ?>" class="<?php echo ! $errors ? 'current' : ''; ?>">
				<?php esc_html_e( 'Toutes', 'waaskit-s3-migrator' ); ?> <span class="count">(<?php echo (int) $diff_counts['total']; ?>)</span>
			</a> |
		</li>
		<li>
			<a href="<?php echo View_Helper::tab_url( 'alt-sync', [ 'errors' => 1 ] ); ?>" class="<?php echo $errors ? 'current' : ''; ?>">
				<?php esc_html_e( 'Erreurs uniquement', 'waaskit-s3-migrator' ); ?> <span class="count">(<?php echo (int) $diff_counts['errors']; ?>)</span>
			</a>
		</li>
	</ul>
<?php endif; ?>

<form method="get" class="wks3m-queue-filters">
	<input type="hidden" name="page" value="wks3m" />
	<input type="hidden" name="tab" value="alt-sync" />
	<?php if ( $errors ) : ?><input type="hidden" name="errors" value="1" /><?php endif; ?>

	<label><?php esc_html_e( 'Recherche :', 'waaskit-s3-migrator' ); ?>
		<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Fichier, alt…', 'waaskit-s3-migrator' ); ?>" />
	</label>
	<?php submit_button( __( 'Filtrer', 'waaskit-s3-migrator' ), 'secondary', '', false, [ 'style' => 'margin-left:.4em;' ] ); ?>
</form>

<?php if ( (int) $diff_counts['total'] > 0 && ! $errors ) : ?>
	<div class="wks3m-bulk-bar">
		<button type="button" class="button button-primary" id="wks3m-alt-bulk-apply">
			<?php
			printf(
				/* translators: %d: number of divergences */
				esc_html__( 'Tout synchroniser (%d)', 'waaskit-s3-migrator' ),
				(int) $diff_counts['total']
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
	<p><?php esc_html_e( 'Aucune divergence. Lance un scan pour en détecter.', 'waaskit-s3-migrator' ); ?></p>
<?php else : ?>
	<table class="widefat striped wks3m-alt-table">
		<thead>
			<tr>
				<th style="width:72px"><?php esc_html_e( 'Aperçu', 'waaskit-s3-migrator' ); ?></th>
				<th><?php esc_html_e( 'Post', 'waaskit-s3-migrator' ); ?></th>
				<th><?php esc_html_e( 'ALT contenu', 'waaskit-s3-migrator' ); ?></th>
				<th><?php esc_html_e( 'ALT Bibliothèque', 'waaskit-s3-migrator' ); ?></th>
				<th style="width:160px"><?php esc_html_e( 'Action', 'waaskit-s3-migrator' ); ?></th>
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
						<?php if ( $diff->has_error() ) : ?>
							<br><small class="wks3m-error"><?php echo esc_html( $diff->error_message() ); ?></small>
						<?php endif; ?>
					</td>
					<td><?php echo '' === $diff->content_alt() ? '<em>(vide)</em>' : esc_html( $diff->content_alt() ); ?></td>
					<td><strong><?php echo esc_html( $diff->library_alt() ); ?></strong></td>
					<td>
						<button type="button" class="button button-primary wks3m-alt-apply-btn" data-id="<?php echo (int) $diff->id(); ?>">
							<?php esc_html_e( 'Remplacer', 'waaskit-s3-migrator' ); ?>
						</button>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<?php View_Helper::pagination( (int) $data['pages'], (int) $data['page'] ); ?>
<?php endif; ?>
