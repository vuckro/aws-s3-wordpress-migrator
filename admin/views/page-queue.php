<?php
/**
 * Importer / Remplacer tab — the core migration workspace.
 *
 * Two panels:
 *   1. File d'attente — per-row import + bulk "Tout importer"
 *   2. Transformer les ALT / Titres — optional bulk cleanup on log rows
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
	<h2><?php esc_html_e( 'Importer & remplacer les URLs', 'waaskit-s3-migrator' ); ?></h2>
	<p class="description">
		<?php esc_html_e( 'Chaque ligne = une image externe détectée au scan. L\'import télécharge l\'image dans la Bibliothèque et remplace son URL dans le contenu des articles qui la référencent.', 'waaskit-s3-migrator' ); ?>
	</p>

	<ul class="subsubsub">
		<?php foreach ( $status_tabs as $key => $label ) :
			$count = '' === $key ? array_sum( $counts ) : (int) ( $counts[ $key ] ?? 0 );
			$url   = View_Helper::tab_url( 'queue', [ 'status' => $key ?: null ] );
		?>
			<li>
				<a href="<?php echo esc_url( $url ); ?>" class="<?php echo $status === $key ? 'current' : ''; ?>">
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
		<button type="button" class="button button-primary" id="wks3m-bulk-all">
			<?php printf( esc_html__( 'Tout importer (%d en attente)', 'waaskit-s3-migrator' ), (int) ( $counts['pending'] ?? 0 ) ); ?>
		</button>
		<button type="button" class="button" id="wks3m-bulk-stop" hidden><?php esc_html_e( 'Stop', 'waaskit-s3-migrator' ); ?></button>
		<span class="spinner" id="wks3m-bulk-spinner" style="float:none;"></span>
		<span class="description" style="margin-left:.4em;"><?php esc_html_e( 'L\'import télécharge, insère dans la Bibliothèque et remplace les URLs dans les articles.', 'waaskit-s3-migrator' ); ?></span>
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
								<button type="button" class="button button-primary wks3m-import-btn" data-id="<?php echo (int) $row->id(); ?>"><?php esc_html_e( 'Importer', 'waaskit-s3-migrator' ); ?></button>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<?php View_Helper::pagination( (int) $data['pages'], (int) $data['page'] ); ?>
	<?php endif; ?>
</div>

<div class="wks3m-panel">
	<details>
		<summary style="cursor:pointer;"><strong><?php esc_html_e( 'Nettoyer les ALT / Titres avant import (optionnel, avancé)', 'waaskit-s3-migrator' ); ?></strong></summary>
		<p class="description" style="margin-top:1em;">
			<?php esc_html_e( 'Règle « Si… alors… » pour corriger en masse les ALT placeholder détectés au scan. Typique : « Si l\'ALT contient "xxx", copier le Titre dérivé ». Clique Aperçu avant Appliquer. La règle agit sur les lignes en attente ET sur les attachments déjà importés.', 'waaskit-s3-migrator' ); ?>
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
						<option value="from_title"><?php esc_html_e( 'Copier depuis le Titre dérivé', 'waaskit-s3-migrator' ); ?></option>
						<option value="set_literal"><?php esc_html_e( 'Définir une valeur', 'waaskit-s3-migrator' ); ?></option>
						<option value="clear"><?php esc_html_e( 'Vider', 'waaskit-s3-migrator' ); ?></option>
					</select>
					<input type="text" id="wks3m-tr-action-value" class="regular-text" placeholder="" hidden />
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
	</details>
</div>
