<?php
/**
 * Synchro ALT tab — scan + apply ALT divergences (Library → content).
 *
 * @package WaasKitS3Migrator
 */

defined( 'ABSPATH' ) || exit;

use WKS3M\Admin\View_Helper;
use WKS3M\Alt_Diff;
use WKS3M\Plugin;

$alt_store  = Plugin::instance()->alt_diff_store();
$alt_search = isset( $_GET['s'] ) ? sanitize_text_field( (string) $_GET['s'] ) : '';
$alt_errors = ! empty( $_GET['errors'] );
$alt_paged  = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;

$alt_data   = $alt_store->list( [
	'search'      => $alt_search,
	'errors_only' => $alt_errors,
	'page'        => $alt_paged,
	'per_page'    => 25,
] );
$alt_counts = $alt_store->counts();
?>

<div class="wks3m-panel">
	<h2><?php esc_html_e( 'Synchro ALT (Bibliothèque → contenu)', 'waaskit-s3-migrator' ); ?></h2>
	<p class="description">
		<?php esc_html_e( 'Après avoir édité les ALT dans Média → Bibliothèque, ce panneau pousse les nouvelles valeurs dans le contenu des articles. La Bibliothèque est la source de vérité — le contenu des posts suit.', 'waaskit-s3-migrator' ); ?>
	</p>
	<p class="description">
		<?php esc_html_e( 'Le scan détecte chaque <img> dont l\'attribut alt diverge de la Bibliothèque. Résolution par src URL (core attachment_url_to_postid + fallback via le journal pour les URLs S3/CDN encore présentes). Rien n\'est modifié pendant le scan.', 'waaskit-s3-migrator' ); ?>
	</p>

	<p>
		<button type="button" class="button button-primary" id="wks3m-alt-scan-start">
			<?php esc_html_e( 'Lancer le scan', 'waaskit-s3-migrator' ); ?>
		</button>
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
				<tr><th scope="row"><?php esc_html_e( 'Images non résolues', 'waaskit-s3-migrator' ); ?><br><small class="description"><?php esc_html_e( 'src qui ne matche aucun attachment — soit image orpheline, soit hors périmètre.', 'waaskit-s3-migrator' ); ?></small></th><td class="unresolved">0</td></tr>
			</tbody>
		</table>
	</div>
</div>

<div class="wks3m-panel">
	<h2><?php esc_html_e( 'Divergences', 'waaskit-s3-migrator' ); ?></h2>

	<?php if ( (int) $alt_counts['errors'] > 0 ) : ?>
		<ul class="subsubsub wks3m-subfilter">
			<li>
				<a href="<?php echo esc_url( View_Helper::tab_url( 'alt-sync' ) ); ?>" class="<?php echo ! $alt_errors ? 'current' : ''; ?>">
					<?php esc_html_e( 'Toutes', 'waaskit-s3-migrator' ); ?> <span class="count">(<?php echo (int) $alt_counts['total']; ?>)</span>
				</a> |
			</li>
			<li>
				<a href="<?php echo esc_url( View_Helper::tab_url( 'alt-sync', [ 'errors' => 1 ] ) ); ?>" class="<?php echo $alt_errors ? 'current' : ''; ?>">
					<?php esc_html_e( 'Erreurs uniquement', 'waaskit-s3-migrator' ); ?> <span class="count">(<?php echo (int) $alt_counts['errors']; ?>)</span>
				</a>
			</li>
		</ul>
	<?php endif; ?>

	<?php if ( (int) $alt_counts['total'] > 0 && ! $alt_errors ) : ?>
		<div class="wks3m-bulk-bar">
			<button type="button" class="button button-primary" id="wks3m-alt-bulk-apply">
				<?php printf( esc_html__( 'Tout synchroniser (%d)', 'waaskit-s3-migrator' ), (int) $alt_counts['total'] ); ?>
			</button>
			<button type="button" class="button" id="wks3m-alt-bulk-stop" hidden><?php esc_html_e( 'Stop', 'waaskit-s3-migrator' ); ?></button>
			<span class="spinner" id="wks3m-alt-bulk-spinner" style="float:none;"></span>
		</div>
		<div id="wks3m-alt-bulk-progress" class="wks3m-progress" hidden>
			<div class="wks3m-progress-bar"><span></span></div>
			<p class="wks3m-progress-label"></p>
		</div>
	<?php endif; ?>

	<?php if ( empty( $alt_data['items'] ) ) : ?>
		<p><?php esc_html_e( 'Aucune divergence. Lance un scan au-dessus.', 'waaskit-s3-migrator' ); ?></p>
	<?php else : ?>
		<table class="widefat striped wks3m-alt-table">
			<thead>
				<tr>
					<th style="width:72px"><?php esc_html_e( 'Aperçu', 'waaskit-s3-migrator' ); ?></th>
					<th><?php esc_html_e( 'Post', 'waaskit-s3-migrator' ); ?></th>
					<th><?php esc_html_e( 'ALT (contenu → biblio)', 'waaskit-s3-migrator' ); ?></th>
					<th><?php esc_html_e( 'Title (contenu → biblio)', 'waaskit-s3-migrator' ); ?></th>
					<th style="width:160px"><?php esc_html_e( 'Action', 'waaskit-s3-migrator' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $alt_data['items'] as $raw ) :
					$diff = new Alt_Diff( (array) $raw );
					$post = get_post( $diff->post_id() );
					$alt_diverges   = ( '' !== $diff->library_alt() )   && ( $diff->library_alt()   !== $diff->content_alt() );
					$title_diverges = ( '' !== $diff->library_title() ) && ( $diff->library_title() !== $diff->content_title() );
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
						<td<?php echo $alt_diverges ? ' class="wks3m-diverges"' : ''; ?>>
							<?php if ( $alt_diverges ) : ?>
								<small><?php echo '' === $diff->content_alt() ? '<em>(vide)</em>' : esc_html( $diff->content_alt() ); ?></small>
								<br>→ <strong><?php echo esc_html( $diff->library_alt() ); ?></strong>
							<?php else : ?>
								<small class="wks3m-muted">✓ OK</small>
							<?php endif; ?>
						</td>
						<td<?php echo $title_diverges ? ' class="wks3m-diverges"' : ''; ?>>
							<?php if ( $title_diverges ) : ?>
								<small><?php echo '' === $diff->content_title() ? '<em>(vide)</em>' : esc_html( $diff->content_title() ); ?></small>
								<br>→ <strong><?php echo esc_html( $diff->library_title() ); ?></strong>
							<?php elseif ( '' === $diff->library_title() ) : ?>
								<small class="wks3m-muted">—</small>
							<?php else : ?>
								<small class="wks3m-muted">✓ OK</small>
							<?php endif; ?>
						</td>
						<td>
							<button type="button" class="button button-primary wks3m-alt-apply-btn" data-id="<?php echo (int) $diff->id(); ?>">
								<?php esc_html_e( 'Remplacer', 'waaskit-s3-migrator' ); ?>
							</button>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<?php
		$alt_pages = (int) $alt_data['pages'];
		if ( $alt_pages > 1 ) {
			echo '<div class="tablenav bottom"><div class="tablenav-pages">';
			echo paginate_links( [
				'base'      => add_query_arg( 'paged', '%#%' ),
				'format'    => '',
				'total'     => $alt_pages,
				'current'   => (int) $alt_data['page'],
				'prev_text' => '‹',
				'next_text' => '›',
			] );
			echo '</div></div>';
		}
		?>
	<?php endif; ?>
</div>
