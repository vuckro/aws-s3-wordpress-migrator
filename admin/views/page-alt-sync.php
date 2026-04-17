<?php
/**
 * Synchro ALT tab — detect, apply, browse history.
 *
 * Sub-views:
 *   - diffs (default) : pending divergences, per-row + bulk apply
 *   - history          : append-only log of successful syncs (read-only)
 *
 * @package WaasKitS3Migrator
 */

defined( 'ABSPATH' ) || exit;

use WKS3M\Admin\View_Helper;
use WKS3M\Alt_Diff;
use WKS3M\Plugin;

$diff_store    = Plugin::instance()->alt_diff_store();
$history_store = Plugin::instance()->alt_history_store();

$view   = isset( $_GET['view'] ) && 'history' === $_GET['view'] ? 'history' : 'diffs';
$search = isset( $_GET['s'] ) ? sanitize_text_field( (string) $_GET['s'] ) : '';
$errors = ! empty( $_GET['errors'] );
$paged  = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;

$diff_counts   = $diff_store->counts();
$history_count = $history_store->count();
?>
<div class="wks3m-panel">
	<h2><?php esc_html_e( 'Scan des ALT divergents', 'waaskit-s3-migrator' ); ?></h2>
	<p class="description">
		<?php esc_html_e( 'Détecte chaque <img> dans le contenu des articles dont l\'attribut alt diverge de la Bibliothèque WordPress. Le scan résout d\'abord les URLs locales (wp-content/uploads/…) puis les URLs externes (S3/CDN) encore présentes dans le contenu via le journal de migration. Rien n\'est modifié pendant le scan.', 'waaskit-s3-migrator' ); ?>
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

	<div id="wks3m-alt-scan-done" class="wks3m-scan-done" hidden>
		<p><?php esc_html_e( 'Scan terminé. Le tableau ci-dessous liste les divergences à synchroniser.', 'waaskit-s3-migrator' ); ?></p>
	</div>
</div>

<div class="wks3m-panel">
	<ul class="subsubsub">
		<li>
			<a href="<?php echo View_Helper::tab_url( 'alt-sync' ); ?>" class="<?php echo 'diffs' === $view ? 'current' : ''; ?>">
				<?php esc_html_e( 'Divergences', 'waaskit-s3-migrator' ); ?>
				<span class="count">(<?php echo (int) $diff_counts['total']; ?>)</span>
			</a> |
		</li>
		<li>
			<a href="<?php echo View_Helper::tab_url( 'alt-sync', [ 'view' => 'history' ] ); ?>" class="<?php echo 'history' === $view ? 'current' : ''; ?>">
				<?php esc_html_e( 'Historique', 'waaskit-s3-migrator' ); ?>
				<span class="count">(<?php echo (int) $history_count; ?>)</span>
			</a>
		</li>
	</ul>

	<?php if ( 'diffs' === $view ) : ?>
		<?php require __DIR__ . '/partials/alt-sync-diffs.php'; ?>
	<?php else : ?>
		<?php require __DIR__ . '/partials/alt-sync-history.php'; ?>
	<?php endif; ?>
</div>
