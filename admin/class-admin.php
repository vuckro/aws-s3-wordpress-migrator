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
			'diff'                => __( 'À synchroniser', 'waaskit-s3-migrator' ),
			'applied'             => __( 'Synchronisé', 'waaskit-s3-migrator' ),
			'view_media'          => __( 'Voir média', 'waaskit-s3-migrator' ),
			// Confirmations.
			'confirm_real'        => __( 'Mode réel : l\'image sera téléchargée dans la Media Library. Continuer ?', 'waaskit-s3-migrator' ),
			'confirm_bulk'        => __( 'Migrer toutes les images en attente en mode réel ? Cette opération peut prendre du temps.', 'waaskit-s3-migrator' ),
			'confirm_rollback'    => __( 'Le contenu de l\'article va être restauré à son état d\'avant la migration. Continuer ?', 'waaskit-s3-migrator' ),
			// Dry-run alert template (%s placeholders replaced JS-side).
			'dry_run_tpl'         => __( "Dry-run\n\nSource: %source%\nFichier: %file%\nTitre: %title%\nAlt: %alt%", 'waaskit-s3-migrator' ),
			// Thumbnail finalization.
			'finalize_progress'   => __( 'Génération des thumbnails…', 'waaskit-s3-migrator' ),
			'finalize_none'       => __( 'Aucun thumbnail en attente.', 'waaskit-s3-migrator' ),
			'confirm_finalize'    => __( 'Générer les thumbnails manquants pour tous les attachments importés en mode différé ?', 'waaskit-s3-migrator' ),
			// Alt sync.
			'alt_scan_progress'   => __( 'Scan des ALT en cours…', 'waaskit-s3-migrator' ),
			'alt_apply_progress'  => __( 'Synchro en cours…', 'waaskit-s3-migrator' ),
			'confirm_alt_apply'   => __( 'Remplacer les ALT divergents dans le contenu des articles ? Une sauvegarde par ligne est créée (rollback possible).', 'waaskit-s3-migrator' ),
			'confirm_alt_rollback' => __( 'Restaurer le contenu de l\'article à son état d\'avant la synchro ALT ?', 'waaskit-s3-migrator' ),
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
