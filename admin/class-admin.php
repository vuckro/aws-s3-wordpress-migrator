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
		'scan'     => [ 'label' => 'Scan',                  'view' => 'page-scan.php' ],
		'queue'    => [ 'label' => 'File d\'attente',       'view' => 'page-queue.php' ],
		'alt-sync' => [ 'label' => 'Synchro ALT',           'view' => 'page-alt-sync.php' ],
		'history'  => [ 'label' => 'Historique & Rollback', 'view' => 'page-history.php' ],
		'settings' => [ 'label' => 'Réglages',              'view' => 'page-settings.php' ],
	];

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
		add_action( 'admin_post_wks3m_save_sources', [ $this, 'save_sources' ] );
		add_action( 'admin_post_wks3m_save_performance', [ $this, 'save_performance' ] );
		add_action( 'admin_post_wks3m_purge_alt_diff', [ $this, 'purge_alt_diff' ] );
		add_action( 'admin_post_wks3m_purge_alt_history', [ $this, 'purge_alt_history' ] );
		add_action( 'admin_post_wks3m_purge_queue', [ $this, 'purge_queue' ] );
		add_action( 'admin_post_wks3m_purge_revisions', [ $this, 'purge_revisions' ] );
	}

	public function add_menu(): void {
		add_management_page(
			__( 'AWS S3 WordPress Migrator', 'waaskit-s3-migrator' ),
			__( 'AWS S3 Migrator', 'waaskit-s3-migrator' ),
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
			'ajax_url'             => admin_url( 'admin-ajax.php' ),
			'nonce'                => wp_create_nonce( 'wks3m_action' ),
			// URL template — JS replaces %d with the attachment ID.
			'edit_post_url_tmpl'   => admin_url( 'post.php?post=%d&action=edit' ),
			'concurrency'          => \WKS3M\Settings::concurrency(),
			'defer_thumbnails'     => \WKS3M\Settings::defer_thumbnails(),
			'i18n'                 => self::i18n_strings(),
		] );
	}

	/** All client-side strings in one place. */
	private static function i18n_strings(): array {
		return [
			'scanning'            => __( 'Scan en cours…', 'waaskit-s3-migrator' ),
			'bulk_progress'       => __( 'Migration en cours…', 'waaskit-s3-migrator' ),
			'error'               => __( 'Erreur.', 'waaskit-s3-migrator' ),
			'importing'           => __( 'Import en cours…', 'waaskit-s3-migrator' ),
			'btn_migrate'         => __( 'Migrer', 'waaskit-s3-migrator' ),
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
			'rolled_back'         => __( 'Rollback', 'waaskit-s3-migrator' ),
			'view_media'          => __( 'Voir média', 'waaskit-s3-migrator' ),
			// Confirmations.
			'confirm_real'        => __( 'Mode réel : l\'image sera téléchargée dans la Media Library. Continuer ?', 'waaskit-s3-migrator' ),
			'confirm_bulk'        => __( 'Migrer toutes les images en attente en mode réel ? Cette opération peut prendre du temps.', 'waaskit-s3-migrator' ),
			'confirm_rollback'    => __( 'Le contenu de l\'article va être restauré à son état d\'avant la migration. Continuer ?', 'waaskit-s3-migrator' ),
			// Dry-run alert template (%s placeholders replaced JS-side).
			'dry_run_tpl'         => __( "Dry-run\n\nSource: %source%\nFichier: %file%\nTitre: %title%\nAlt: %alt%", 'waaskit-s3-migrator' ),
			// Transform rule builder (bulk ALT/title edit on queue rows).
			'tr_invalid'          => __( 'Règle incomplète. Vérifie le champ, la condition et l\'action.', 'waaskit-s3-migrator' ),
			'tr_confirm'          => __( 'Appliquer la règle ? La modification est définitive (pas de rollback pour les transformations).', 'waaskit-s3-migrator' ),
			// Thumbnail finalization.
			'finalize_progress'   => __( 'Génération des thumbnails…', 'waaskit-s3-migrator' ),
			'finalize_none'       => __( 'Aucun thumbnail en attente.', 'waaskit-s3-migrator' ),
			'confirm_finalize'    => __( 'Générer les thumbnails manquants pour tous les attachments importés en mode différé ?', 'waaskit-s3-migrator' ),
			// Alt sync.
			'alt_scan_progress'   => __( 'Scan des ALT en cours…', 'waaskit-s3-migrator' ),
			'alt_apply_progress'  => __( 'Synchro en cours…', 'waaskit-s3-migrator' ),
			'confirm_alt_apply'   => __( 'Remplacer les ALT divergents dans le contenu des articles ? L\'opération écrit directement en base (aucune révision créée). Un nouveau scan reconstruit toujours le tableau depuis l\'état courant.', 'waaskit-s3-migrator' ),
			'alt_nothing'         => __( 'Aucune divergence à synchroniser. Lance un scan d\'abord.', 'waaskit-s3-migrator' ),
		];
	}

	/**
	 * Persist the "source hosts" textarea submitted from the Scan tab.
	 */
	public function save_sources(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'forbidden', 403 );
		}
		check_admin_referer( 'wks3m_save_sources' );

		$raw   = isset( $_POST['source_hosts'] ) ? (string) wp_unslash( $_POST['source_hosts'] ) : '';
		$hosts = preg_split( '/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY ) ?: [];
		\WKS3M\Settings::set_source_hosts( $hosts );

		update_option( 'wks3m_auto_detect_external', ! empty( $_POST['auto_detect'] ) ? 1 : 0 );
		update_option( 'wks3m_strip_strapi_prefixes', ! empty( $_POST['strip_prefixes'] ) ? 1 : 0 );

		wp_safe_redirect( View_Helper::tab_url( 'scan', [ 'sources_saved' => 1 ] ) );
		exit;
	}

	/**
	 * Persist the deferred-thumbnails checkbox from the Settings tab.
	 */
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

		wp_safe_redirect( View_Helper::tab_url( 'alt-sync', [ 'purged' => 'diff' ] ) );
		exit;
	}

	public function purge_alt_history(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'forbidden', 403 );
		}
		check_admin_referer( 'wks3m_purge_alt_history' );

		\WKS3M\Plugin::instance()->alt_history_store()->purge();

		wp_safe_redirect( View_Helper::tab_url( 'alt-sync', [ 'view' => 'history', 'purged' => 'history' ] ) );
		exit;
	}

	/**
	 * Delete finished migration-log rows (replaced / rolled_back / failed) and
	 * the postmeta they left behind (_wks3m_backup_{id}, _wks3m_replacements).
	 * Biggest space reclaim on sites with large post_content backups.
	 *
	 * Warning (in the UI): once this runs, the Synchro ALT scanner loses its
	 * in-scope universe (it reads from 'replaced' rows + source_url_variants).
	 * Only purge when alt sync is complete.
	 */
	public function purge_queue(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'forbidden', 403 );
		}
		check_admin_referer( 'wks3m_purge_queue' );

		global $wpdb;
		$table = \WKS3M\Activator::table_name();

		$before_mb = $this->table_size_mb( [ $wpdb->postmeta, $table ] );

		$rows_deleted = (int) $wpdb->query(
			"DELETE FROM {$table} WHERE status IN ('replaced','rolled_back','failed')"
		);

		// One DELETE per meta key pattern — avoids N queries for N rows.
		// Escape underscores (LIKE wildcards) with backslashes per MySQL rules.
		$metas_deleted  = (int) $wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '\\_wks3m\\_backup\\_%'" );
		$metas_deleted += (int) $wpdb->query(
			$wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s", '_wks3m_replacements' )
		);

		$this->optimize_and_analyze( [ $wpdb->postmeta, $table ] );
		$after_mb = $this->table_size_mb( [ $wpdb->postmeta, $table ] );

		wp_safe_redirect(
			View_Helper::tab_url(
				'queue',
				[
					'purged_rows'  => $rows_deleted,
					'purged_metas' => $metas_deleted,
					'freed_mb'     => max( 0, (int) round( $before_mb - $after_mb ) ),
				]
			)
		);
		exit;
	}

	/**
	 * Delete older post revisions, keeping the N most recent per parent post.
	 *
	 * On migration-heavy sites this is usually the biggest BDD space reclaim —
	 * wp_update_post() calls from earlier plugin versions (and any heavy
	 * editing) each created a full-content revision, and WordPress keeps them
	 * all unless WP_POST_REVISIONS is defined.
	 *
	 * Direct SQL — wp_delete_post_revision() would fire 10k+ delete_post hooks.
	 */
	public function purge_revisions(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'forbidden', 403 );
		}
		check_admin_referer( 'wks3m_purge_revisions' );

		$keep = isset( $_POST['keep'] ) ? max( 0, min( 100, (int) $_POST['keep'] ) ) : 5;

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

		$rows_deleted  = 0;
		$metas_deleted = 0;
		if ( ! empty( $ids ) ) {
			// Chunk to avoid MySQL max_allowed_packet blowouts on massive IN lists.
			foreach ( array_chunk( array_map( 'intval', $ids ), 1000 ) as $chunk ) {
				$in             = implode( ',', $chunk );
				$rows_deleted  += (int) $wpdb->query( "DELETE FROM {$wpdb->posts} WHERE ID IN ({$in}) AND post_type = 'revision'" );
				$metas_deleted += (int) $wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE post_id IN ({$in})" );
			}
		}

		$this->optimize_and_analyze( [ $wpdb->posts, $wpdb->postmeta ] );
		$after_mb = $this->table_size_mb( [ $wpdb->posts, $wpdb->postmeta ] );

		wp_safe_redirect(
			View_Helper::tab_url(
				'queue',
				[
					'purged_revs'  => $rows_deleted,
					'purged_metas' => $metas_deleted,
					'freed_mb'     => max( 0, (int) round( $before_mb - $after_mb ) ),
				]
			)
		);
		exit;
	}

	/**
	 * Total disk footprint (data + index, MB) of the given tables.
	 *
	 * @param string[] $tables Fully-qualified table names.
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
	 * Defragment + refresh stats. Without this MySQL 8 keeps reporting the
	 * pre-delete size in information_schema.TABLES until its auto-analyze runs,
	 * which confuses users who "don't see the space come back".
	 *
	 * OPTIMIZE TABLE on InnoDB is equivalent to ALTER TABLE … FORCE, so it
	 * rebuilds the .ibd file in place. Takes seconds per hundred MB — fine for
	 * a one-off user-triggered action.
	 *
	 * @param string[] $tables
	 */
	private function optimize_and_analyze( array $tables ): void {
		if ( empty( $tables ) ) {
			return;
		}
		global $wpdb;
		$wpdb->query( 'OPTIMIZE TABLE ' . implode( ', ', array_map( 'esc_sql', $tables ) ) );
	}

	public function render_page(): void {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( (string) $_GET['tab'] ) : 'scan';
		if ( ! isset( self::TABS[ $tab ] ) ) {
			$tab = 'scan';
		}
		?>
		<div class="wrap wks3m-wrap">
			<h1><?php esc_html_e( 'AWS S3 WordPress Migrator', 'waaskit-s3-migrator' ); ?></h1>
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
