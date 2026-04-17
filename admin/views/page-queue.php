<?php
/**
 * Queue tab — the central workspace.
 *
 * Panels (top → bottom):
 *   1. Migration queue (per-row + bulk import)
 *   2. Transform ALT / titles (pre-import bulk cleanup)
 *   3. Synchro ALT (scan content → apply library alt into <img alt>)
 *   4. Cleanup (purge finished rows, pending alt diffs, old revisions)
 *
 * @package WaasKitS3Migrator
 */

defined( 'ABSPATH' ) || exit;

use WKS3M\Admin\View_Helper;
use WKS3M\Alt_Diff;
use WKS3M\Migration_Row;
use WKS3M\Plugin;

$store      = Plugin::instance()->mapping_store();
$alt_store  = Plugin::instance()->alt_diff_store();
$status     = isset( $_GET['status'] ) ? sanitize_key( (string) $_GET['status'] ) : '';
$host       = isset( $_GET['host'] ) ? sanitize_text_field( (string) $_GET['host'] ) : '';
$search     = isset( $_GET['s'] ) ? sanitize_text_field( (string) $_GET['s'] ) : '';
$paged      = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
$alt_search = isset( $_GET['alt_s'] ) ? sanitize_text_field( (string) $_GET['alt_s'] ) : '';
$alt_errors = ! empty( $_GET['alt_errors'] );
$alt_paged  = isset( $_GET['alt_paged'] ) ? max( 1, (int) $_GET['alt_paged'] ) : 1;

$data   = $store->list( [
	'status'   => $status,
	'host'     => $host,
	'search'   => $search,
	'page'     => $paged,
	'per_page' => 25,
] );
$counts = $store->counts_by_status();
$hosts  = $store->distinct_hosts();

$alt_data   = $alt_store->list( [
	'search'      => $alt_search,
	'errors_only' => $alt_errors,
	'page'        => $alt_paged,
	'per_page'    => 25,
] );
$alt_counts = $alt_store->counts();

$status_tabs = [
	''         => __( 'Toutes', 'waaskit-s3-migrator' ),
	'pending'  => __( 'En attente', 'waaskit-s3-migrator' ),
	'imported' => __( 'Importées', 'waaskit-s3-migrator' ),
	'replaced' => __( 'Remplacées', 'waaskit-s3-migrator' ),
	'failed'   => __( 'Échecs', 'waaskit-s3-migrator' ),
];

$rows = View_Helper::wrap_rows( $data['items'] );

$purged_rows = isset( $_GET['purged_rows'] ) ? (int) $_GET['purged_rows'] : -1;
$purged_revs = isset( $_GET['purged_revs'] ) ? (int) $_GET['purged_revs'] : -1;
$freed_mb    = isset( $_GET['freed_mb'] ) ? (int) $_GET['freed_mb'] : 0;
$purged      = isset( $_GET['purged'] ) ? sanitize_key( (string) $_GET['purged'] ) : '';

$finished_count  = (int) ( $counts['replaced'] ?? 0 ) + (int) ( $counts['failed'] ?? 0 );
global $wpdb;
$revisions_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision'" );
?>

<?php if ( $purged_rows >= 0 ) : ?>
	<div class="notice notice-success is-dismissible"><p>
		<?php printf( esc_html__( 'Lignes terminées supprimées : %1$d. Espace libéré : %2$d MB.', 'waaskit-s3-migrator' ), $purged_rows, $freed_mb ); ?>
	</p></div>
<?php elseif ( $purged_revs >= 0 ) : ?>
	<div class="notice notice-success is-dismissible"><p>
		<?php printf( esc_html__( 'Révisions supprimées : %1$d. Espace libéré : %2$d MB.', 'waaskit-s3-migrator' ), $purged_revs, $freed_mb ); ?>
	</p></div>
<?php elseif ( 'alt_diff' === $purged ) : ?>
	<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Divergences ALT en attente vidées.', 'waaskit-s3-migrator' ); ?></p></div>
<?php endif; ?>

<!-- ============================================================ -->
<!--  PANEL 1 : migration queue                                    -->
<!-- ============================================================ -->
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
			<?php printf( esc_html__( 'Tout migrer (%d en attente)', 'waaskit-s3-migrator' ), (int) ( $counts['pending'] ?? 0 ) ); ?>
		</button>
		<button type="button" class="button" id="wks3m-bulk-stop" hidden><?php esc_html_e( 'Stop', 'waaskit-s3-migrator' ); ?></button>
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
						<td><?php echo View_Helper::thumb_html( $row->attachment_id() ?: null, $row->variants()[0] ?? '' ); ?></td>
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

<!-- ============================================================ -->
<!--  PANEL 2 : Transform ALT / Titres                             -->
<!-- ============================================================ -->
<div class="wks3m-panel">
	<h2><?php esc_html_e( 'Transformer les ALT / Titres avant import', 'waaskit-s3-migrator' ); ?></h2>
	<p class="description">
		<?php esc_html_e( 'Règle « Si… alors… » pour nettoyer en masse les ALT et titres détectés au scan. Typique : « Si l\'ALT contient "xxx", copier le Titre dérivé ». Cliquer Aperçu avant Appliquer.', 'waaskit-s3-migrator' ); ?>
	</p>

	<table class="form-table" role="presentation" id="wks3m-tr-form">
		<tbody>
			<tr>
				<th scope="row"><label for="wks3m-tr-field"><?php esc_html_e( 'Champ', 'waaskit-s3-migrator' ); ?></label></th>
				<td>
					<select id="wks3m-tr-field">
						<option value="alt"><?php esc_html_e( 'ALT', 'waaskit-s3-migrator' ); ?></option>
						<option value="title"><?php esc_html_e( 'Titre dérivé', 'waaskit-s3-migrator' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><label><?php esc_html_e( 'Condition', 'waaskit-s3-migrator' ); ?></label></th>
				<td>
					<select id="wks3m-tr-cond">
						<option value="contains"><?php esc_html_e( 'Contient', 'waaskit-s3-migrator' ); ?></option>
						<option value="equals"><?php esc_html_e( 'Égale', 'waaskit-s3-migrator' ); ?></option>
						<option value="empty"><?php esc_html_e( 'Est vide', 'waaskit-s3-migrator' ); ?></option>
					</select>
					<input type="text" id="wks3m-tr-cond-value" class="regular-text" placeholder="xxx" />
				</td>
			</tr>
			<tr>
				<th scope="row"><label><?php esc_html_e( 'Action', 'waaskit-s3-migrator' ); ?></label></th>
				<td>
					<select id="wks3m-tr-action">
						<option value="from_title"><?php esc_html_e( 'Copier depuis le Titre', 'waaskit-s3-migrator' ); ?></option>
						<option value="from_alt"><?php esc_html_e( 'Copier depuis l\'ALT', 'waaskit-s3-migrator' ); ?></option>
						<option value="set_literal"><?php esc_html_e( 'Définir une valeur', 'waaskit-s3-migrator' ); ?></option>
						<option value="remove_substring"><?php esc_html_e( 'Supprimer la chaîne', 'waaskit-s3-migrator' ); ?></option>
						<option value="clear"><?php esc_html_e( 'Vider', 'waaskit-s3-migrator' ); ?></option>
					</select>
					<input type="text" id="wks3m-tr-action-value" class="regular-text" placeholder="" hidden />
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Propagation', 'waaskit-s3-migrator' ); ?></th>
				<td>
					<label><input type="checkbox" id="wks3m-tr-update-attachments" checked />
						<?php esc_html_e( 'Mettre à jour aussi les attachments déjà importés (postmeta ALT / post_title).', 'waaskit-s3-migrator' ); ?>
					</label>
				</td>
			</tr>
		</tbody>
	</table>

	<p>
		<button type="button" class="button" id="wks3m-tr-preview-btn"><?php esc_html_e( 'Aperçu', 'waaskit-s3-migrator' ); ?></button>
		<button type="button" class="button button-primary" id="wks3m-tr-apply-btn" disabled><?php esc_html_e( 'Appliquer', 'waaskit-s3-migrator' ); ?></button>
		<span class="spinner" id="wks3m-tr-spinner" style="float:none;"></span>
	</p>

	<div id="wks3m-tr-results" class="wks3m-tr-results" hidden>
		<div class="wks3m-tr-counts"></div>
		<table class="widefat striped" id="wks3m-tr-sample" hidden>
			<thead>
				<tr>
					<th style="width:80px"><?php esc_html_e( 'ID', 'waaskit-s3-migrator' ); ?></th>
					<th><?php esc_html_e( 'Avant', 'waaskit-s3-migrator' ); ?></th>
					<th><?php esc_html_e( 'Après', 'waaskit-s3-migrator' ); ?></th>
				</tr>
			</thead>
			<tbody></tbody>
		</table>
	</div>
</div>

<!-- ============================================================ -->
<!--  PANEL 3 : Synchro ALT                                        -->
<!-- ============================================================ -->
<div class="wks3m-panel">
	<h2><?php esc_html_e( 'Synchro ALT (contenu ↔ Bibliothèque)', 'waaskit-s3-migrator' ); ?></h2>
	<p class="description">
		<?php esc_html_e( 'Détecte chaque <img> dans le contenu des articles dont l\'attribut alt diverge de la Bibliothèque WordPress. Résolution par src URL (core attachment_url_to_postid + fallback via le journal pour les URLs externes S3/CDN encore présentes). Rien n\'est modifié pendant le scan.', 'waaskit-s3-migrator' ); ?>
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

	<?php if ( (int) $alt_counts['errors'] > 0 ) : ?>
		<ul class="subsubsub wks3m-subfilter">
			<li>
				<a href="<?php echo View_Helper::tab_url( 'queue' ); ?>" class="<?php echo ! $alt_errors ? 'current' : ''; ?>">
					<?php esc_html_e( 'Toutes', 'waaskit-s3-migrator' ); ?> <span class="count">(<?php echo (int) $alt_counts['total']; ?>)</span>
				</a> |
			</li>
			<li>
				<a href="<?php echo View_Helper::tab_url( 'queue', [ 'alt_errors' => 1 ] ); ?>" class="<?php echo $alt_errors ? 'current' : ''; ?>">
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
					<th><?php esc_html_e( 'ALT contenu', 'waaskit-s3-migrator' ); ?></th>
					<th><?php esc_html_e( 'ALT Bibliothèque', 'waaskit-s3-migrator' ); ?></th>
					<th style="width:160px"><?php esc_html_e( 'Action', 'waaskit-s3-migrator' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $alt_data['items'] as $raw ) :
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

		<?php
		// Pagination uses its own paged key so it doesn't collide with the queue's.
		$alt_pages = (int) $alt_data['pages'];
		if ( $alt_pages > 1 ) {
			echo '<div class="tablenav bottom"><div class="tablenav-pages">';
			echo paginate_links( [
				'base'      => add_query_arg( 'alt_paged', '%#%' ),
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

<!-- ============================================================ -->
<!--  PANEL 4 : Cleanup / purge                                    -->
<!-- ============================================================ -->
<div class="wks3m-panel">
	<h2><?php esc_html_e( 'Nettoyer', 'waaskit-s3-migrator' ); ?></h2>
	<p class="description">
		<?php esc_html_e( 'Quand le travail est terminé, vide les tables pour réduire l\'empreinte en base. Les trois boutons sont indépendants.', 'waaskit-s3-migrator' ); ?>
	</p>

	<table class="form-table" role="presentation">
		<tbody>
			<tr>
				<th scope="row"><?php esc_html_e( 'Lignes de migration terminées', 'waaskit-s3-migrator' ); ?></th>
				<td>
					<p class="description">
						<?php printf( esc_html__( '%d ligne(s) remplacées / en échec.', 'waaskit-s3-migrator' ), (int) $finished_count ); ?>
					</p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
						onsubmit="return confirm('<?php echo esc_js( __( 'Supprimer toutes les lignes terminées ? Le scan reste possible (relancer depuis l\'onglet Scan).', 'waaskit-s3-migrator' ) ); ?>');">
						<input type="hidden" name="action" value="wks3m_purge_queue" />
						<?php wp_nonce_field( 'wks3m_purge_queue' ); ?>
						<button type="submit" class="button" <?php disabled( 0, $finished_count ); ?>>
							<?php esc_html_e( 'Purger les lignes terminées', 'waaskit-s3-migrator' ); ?>
						</button>
					</form>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Divergences ALT en attente', 'waaskit-s3-migrator' ); ?></th>
				<td>
					<p class="description">
						<?php printf( esc_html__( '%d ligne(s) non synchronisées. Un nouveau scan les recrée depuis l\'état courant.', 'waaskit-s3-migrator' ), (int) $alt_counts['total'] ); ?>
					</p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
						onsubmit="return confirm('<?php echo esc_js( __( 'Vider toutes les divergences ALT en attente ?', 'waaskit-s3-migrator' ) ); ?>');">
						<input type="hidden" name="action" value="wks3m_purge_alt_diff" />
						<?php wp_nonce_field( 'wks3m_purge_alt_diff' ); ?>
						<button type="submit" class="button" <?php disabled( 0, (int) $alt_counts['total'] ); ?>>
							<?php esc_html_e( 'Vider les divergences ALT', 'waaskit-s3-migrator' ); ?>
						</button>
					</form>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Anciennes révisions de posts', 'waaskit-s3-migrator' ); ?></th>
				<td>
					<p class="description">
						<?php printf( esc_html__( '%d révision(s) totales. Chaque édition en empile une. Garde les N plus récentes par post.', 'waaskit-s3-migrator' ), (int) $revisions_count ); ?>
					</p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
						onsubmit="return confirm('<?php echo esc_js( __( 'Supprimer les révisions au-delà du nombre indiqué ?', 'waaskit-s3-migrator' ) ); ?>');">
						<input type="hidden" name="action" value="wks3m_purge_revisions" />
						<?php wp_nonce_field( 'wks3m_purge_revisions' ); ?>
						<label><?php esc_html_e( 'Garder par post :', 'waaskit-s3-migrator' ); ?>
							<input type="number" name="keep" value="5" min="0" max="100" class="small-text" />
						</label>
						<button type="submit" class="button" style="margin-left:.5em;" <?php disabled( 0, $revisions_count ); ?>>
							<?php esc_html_e( 'Purger les révisions', 'waaskit-s3-migrator' ); ?>
						</button>
					</form>
				</td>
			</tr>
		</tbody>
	</table>

	<p class="description" style="margin-top:1em;">
		<?php esc_html_e( 'Conseil : ajoute `define(\'WP_POST_REVISIONS\', 5);` dans wp-config.php pour plafonner les révisions à l\'avenir.', 'waaskit-s3-migrator' ); ?>
	</p>
</div>
