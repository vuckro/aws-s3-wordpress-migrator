<?php
/**
 * Admin UI — menu registration, asset enqueue, tab dispatch.
 *
 * @package WaasKitS3Migrator
 */

namespace WKS3M\Admin;

defined( 'ABSPATH' ) || exit;

class Admin {

	public const MENU_SLUG = 'wks3m';

	/** Sub-tabs and the view file each one renders. */
	private const TABS = [
		'instructions' => [ 'label' => 'Instructions',          'view' => 'page-instructions.php' ],
		'scan'         => [ 'label' => 'Scan',                  'view' => 'page-scan.php' ],
		'queue'        => [ 'label' => 'Importer / Remplacer',  'view' => 'page-queue.php' ],
		'alt-sync'     => [ 'label' => 'Synchro ALT',           'view' => 'page-alt-sync.php' ],
		'settings'     => [ 'label' => 'Réglages',              'view' => 'page-settings.php' ],
	];

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
		add_action( 'admin_post_wks3m_save_sources', [ $this, 'save_sources' ] );
		add_action( 'admin_post_wks3m_save_performance', [ $this, 'save_performance' ] );
		add_action( 'admin_post_wks3m_purge_queue', [ $this, 'purge_queue' ] );
		add_action( 'admin_post_wks3m_purge_revisions', [ $this, 'purge_revisions' ] );
		add_action( 'admin_post_wks3m_purge_alt_diff', [ $this, 'purge_alt_diff' ] );
	}

	public function add_menu(): void {
		add_management_page(
			__( 'Offload Media Importer', 'waaskit-s3-migrator' ),
			__( 'Offload Media Importer', 'waaskit-s3-migrator' ),
			'manage_options',
			self::MENU_SLUG,
			[ $this, 'render_page' ]
		);
	}

	public function enqueue( string $hook ): void {
		if ( 'tools_page_' . self::MENU_SLUG !== $hook ) {
			return;
		}
		wp_enqueue_style(  'wks3m-admin', WKS3M_PLUGIN_URL . 'assets/css/admin.css', [],          WKS3M_VERSION );
		wp_enqueue_script( 'wks3m-admin', WKS3M_PLUGIN_URL . 'assets/js/admin.js',   [ 'jquery' ], WKS3M_VERSION, true );

		wp_localize_script( 'wks3m-admin', 'WKS3M', [
			'ajax_url'           => admin_url( 'admin-ajax.php' ),
			'nonce'              => wp_create_nonce( 'wks3m_action' ),
			'edit_post_url_tmpl' => admin_url( 'post.php?post=%d&action=edit' ),
			'concurrency'        => \WKS3M\Settings::concurrency(),
			'defer_thumbnails'   => \WKS3M\Settings::defer_thumbnails(),
			'i18n'               => self::i18n_strings(),
		] );
	}

	/** All client-side strings in one place. */
	private static function i18n_strings(): array {
		return [
			'scanning'            => __( 'Scan en cours…', 'waaskit-s3-migrator' ),
			'bulk_progress'       => __( 'Import en cours…', 'waaskit-s3-migrator' ),
			'error'               => __( 'Erreur.', 'waaskit-s3-migrator' ),
			'importing'           => __( 'Import en cours…', 'waaskit-s3-migrator' ),
			'btn_import'          => __( 'Importer', 'waaskit-s3-migrator' ),
			'stop'                => __( 'Stop', 'waaskit-s3-migrator' ),
			'stopping'            => __( 'Arrêt en cours…', 'waaskit-s3-migrator' ),
			'stopped'             => __( 'Arrêté', 'waaskit-s3-migrator' ),
			'done'                => __( 'Terminé', 'waaskit-s3-migrator' ),
			'nothing_to_do'       => __( 'Rien à traiter.', 'waaskit-s3-migrator' ),
			'reload_prompt'       => __( 'Recharger la page pour voir l\'état à jour ?', 'waaskit-s3-migrator' ),
			// Status labels.
			'pending'             => __( 'En attente', 'waaskit-s3-migrator' ),
			'imported'            => __( 'Importée', 'waaskit-s3-migrator' ),
			'replaced'            => __( 'Remplacée', 'waaskit-s3-migrator' ),
			'failed'              => __( 'Échec', 'waaskit-s3-migrator' ),
			'view_media'          => __( 'Voir média', 'waaskit-s3-migrator' ),
			// Confirmations.
			'confirm_bulk'        => __( 'Importer toutes les images en attente ? Cette opération peut prendre du temps.', 'waaskit-s3-migrator' ),
			// Transform rule builder (bulk ALT/title edit on queue rows).
			'tr_invalid'          => __( 'Règle incomplète. Vérifie le champ, la condition et l\'action.', 'waaskit-s3-migrator' ),
			'tr_confirm'          => __( 'Appliquer la règle ? La modification est définitive.', 'waaskit-s3-migrator' ),
			// Thumbnail finalization.
			'finalize_progress'   => __( 'Génération des thumbnails…', 'waaskit-s3-migrator' ),
			'finalize_none'       => __( 'Aucun thumbnail en attente.', 'waaskit-s3-migrator' ),
			'confirm_finalize'    => __( 'Générer les thumbnails manquants pour tous les attachments importés en mode différé ?', 'waaskit-s3-migrator' ),
			// Alt sync (same tab, Queue).
			'alt_scan_progress'   => __( 'Scan des ALT en cours…', 'waaskit-s3-migrator' ),
			'alt_apply_progress'  => __( 'Synchro en cours…', 'waaskit-s3-migrator' ),
			'confirm_alt_apply'   => __( 'Remplacer les ALT divergents dans le contenu des articles ?', 'waaskit-s3-migrator' ),
			'alt_nothing'         => __( 'Aucune divergence à synchroniser. Lance un scan d\'abord.', 'waaskit-s3-migrator' ),
		];
	}

	public function save_sources(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'forbidden', 403 );
		}
		check_admin_referer( 'wks3m_save_sources' );

		$raw   = isset( $_POST['source_hosts'] ) ? (string) wp_unslash( $_POST['source_hosts'] ) : '';
		$hosts = preg_split( '/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY ) ?: [];
		\WKS3M\Settings::set_source_hosts( $hosts );

		wp_safe_redirect( View_Helper::tab_url( 'scan', [ 'sources_saved' => 1 ] ) );
		exit;
	}

	public function save_performance(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'forbidden', 403 );
		}
		check_admin_referer( 'wks3m_save_performance' );

		update_option( 'wks3m_defer_thumbnails', ! empty( $_POST['defer_thumbnails'] ) ? 1 : 0 );

		wp_safe_redirect( View_Helper::tab_url( 'settings', [ 'perf_saved' => 1 ] ) );
		exit;
	}

	public function purge_alt_diff(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'forbidden', 403 );
		}
		check_admin_referer( 'wks3m_purge_alt_diff' );

		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . \WKS3M\Activator::alt_diff_table_name() );

		wp_safe_redirect( View_Helper::tab_url( 'settings', [ 'purged' => 'alt_diff' ] ) );
		exit;
	}

	/**
	 * Delete finished migration-log rows (replaced / failed). Direct DELETE +
	 * OPTIMIZE TABLE so the freed disk space is reported right away.
	 */
	public function purge_queue(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'forbidden', 403 );
		}
		check_admin_referer( 'wks3m_purge_queue' );

		global $wpdb;
		$table = \WKS3M\Activator::table_name();

		$before_mb    = $this->table_size_mb( [ $table ] );
		$rows_deleted = (int) $wpdb->query(
			"DELETE FROM {$table} WHERE status IN ('replaced','failed')"
		);
		$this->optimize( [ $table ] );
		$after_mb = $this->table_size_mb( [ $table ] );

		wp_safe_redirect(
			View_Helper::tab_url(
				'settings',
				[
					'purged_rows' => $rows_deleted,
					'freed_mb'    => max( 0, (int) round( $before_mb - $after_mb ) ),
				]
			)
		);
		exit;
	}

	/**
	 * Delete older post revisions, keeping the N most recent per parent post.
	 * Usually the biggest BDD reclaim after a migration — WP keeps all
	 * revisions unless WP_POST_REVISIONS is defined.
	 */
	public function purge_revisions(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'forbidden', 403 );
		}
		check_admin_referer( 'wks3m_purge_revisions' );

		// Fixed at 5 — matches the recommended WP_POST_REVISIONS and keeps a
		// sensible safety net without letting the user pick a dangerous 0.
		$keep = 5;

		global $wpdb;

		$before_mb = $this->table_size_mb( [ $wpdb->posts, $wpdb->postmeta ] );

		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT ID FROM (
				SELECT ID, ROW_NUMBER() OVER (PARTITION BY post_parent ORDER BY post_date DESC) AS rn
				FROM {$wpdb->posts}
				WHERE post_type = 'revision'
			) r
			WHERE rn > %d",
			$keep
		) );

		$rows_deleted = 0;
		if ( ! empty( $ids ) ) {
			foreach ( array_chunk( array_map( 'intval', $ids ), 1000 ) as $chunk ) {
				$in            = implode( ',', $chunk );
				$rows_deleted += (int) $wpdb->query( "DELETE FROM {$wpdb->posts} WHERE ID IN ({$in}) AND post_type = 'revision'" );
				$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE post_id IN ({$in})" );
			}
		}

		$this->optimize( [ $wpdb->posts, $wpdb->postmeta ] );
		$after_mb = $this->table_size_mb( [ $wpdb->posts, $wpdb->postmeta ] );

		wp_safe_redirect(
			View_Helper::tab_url(
				'settings',
				[
					'purged_revs' => $rows_deleted,
					'freed_mb'    => max( 0, (int) round( $before_mb - $after_mb ) ),
				]
			)
		);
		exit;
	}

	/**
	 * Total disk footprint (data + index, MB) of the given tables.
	 *
	 * @param string[] $tables
	 */
	private function table_size_mb( array $tables ): float {
		if ( empty( $tables ) ) {
			return 0.0;
		}
		global $wpdb;
		$placeholders = implode( ',', array_fill( 0, count( $tables ), '%s' ) );
		$sql          = $wpdb->prepare(
			"SELECT COALESCE(SUM(DATA_LENGTH + INDEX_LENGTH), 0) / 1024 / 1024
			 FROM information_schema.TABLES
			 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ({$placeholders})",
			...$tables
		);
		return (float) $wpdb->get_var( $sql );
	}

	/**
	 * Defragment + refresh stats so information_schema reflects reality
	 * immediately. Without this the size numbers stay stale until MySQL's
	 * next auto-analyze.
	 *
	 * @param string[] $tables
	 */
	private function optimize( array $tables ): void {
		if ( empty( $tables ) ) {
			return;
		}
		global $wpdb;
		$wpdb->query( 'OPTIMIZE TABLE ' . implode( ', ', array_map( 'esc_sql', $tables ) ) );
	}

	public function render_page(): void {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( (string) $_GET['tab'] ) : 'instructions';
		if ( ! isset( self::TABS[ $tab ] ) ) {
			$tab = 'instructions';
		}
		?>
		<div class="wrap wks3m-wrap">
			<h1><?php esc_html_e( 'Offload Media Importer', 'waaskit-s3-migrator' ); ?></h1>
			<nav class="nav-tab-wrapper">
				<?php foreach ( self::TABS as $key => $meta ) : ?>
					<?php echo View_Helper::nav_tab( $key, $tab, __( $meta['label'], 'waaskit-s3-migrator' ) ); // phpcs:ignore ?>
				<?php endforeach; ?>
			</nav>
			<?php require WKS3M_PLUGIN_DIR . 'admin/views/' . self::TABS[ $tab ]['view']; ?>
		</div>
		<?php
	}
}
