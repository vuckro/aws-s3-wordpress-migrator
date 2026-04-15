<?php
/**
 * Admin UI — menu registration and asset enqueue.
 *
 * @package WaasKitS3Migrator
 */

namespace WKS3M\Admin;

defined( 'ABSPATH' ) || exit;

class Admin {

	public const MENU_SLUG = 'wks3m';

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
		add_action( 'admin_post_wks3m_save_sources', [ $this, 'save_sources' ] );
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
		wp_enqueue_style(
			'wks3m-admin',
			WKS3M_PLUGIN_URL . 'assets/css/admin.css',
			[],
			WKS3M_VERSION
		);
		wp_enqueue_script(
			'wks3m-admin',
			WKS3M_PLUGIN_URL . 'assets/js/admin.js',
			[ 'jquery' ],
			WKS3M_VERSION,
			true
		);
		wp_localize_script(
			'wks3m-admin',
			'WKS3M',
			[
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'wks3m_action' ),
				'i18n'     => [
					'scanning'       => __( 'Scan en cours…', 'waaskit-s3-migrator' ),
					'error'          => __( 'Erreur.', 'waaskit-s3-migrator' ),
					'importing'      => __( 'Import en cours…', 'waaskit-s3-migrator' ),
					'imported'       => __( 'Importée', 'waaskit-s3-migrator' ),
					'replaced'       => __( 'Remplacée', 'waaskit-s3-migrator' ),
					'pending'        => __( 'En attente', 'waaskit-s3-migrator' ),
					'failed'         => __( 'Échec', 'waaskit-s3-migrator' ),
					'rolled_back'    => __( 'Rollback', 'waaskit-s3-migrator' ),
					'dry_run_ok'     => __( 'Dry-run OK', 'waaskit-s3-migrator' ),
					'confirm_real'   => __( 'Mode réel : l\'image sera téléchargée dans la Media Library. Continuer ?', 'waaskit-s3-migrator' ),
					'confirm_bulk'   => __( 'Migrer toutes les images en attente en mode réel ? Cette opération peut prendre du temps.', 'waaskit-s3-migrator' ),
					'confirm_rollback' => __( 'Le contenu des articles va être restauré à son état d\'avant la migration. Continuer ?', 'waaskit-s3-migrator' ),
					'bulk_progress'  => __( 'Migration en cours…', 'waaskit-s3-migrator' ),
					'view_media'     => __( 'Voir média', 'waaskit-s3-migrator' ),
					'tr_invalid'     => __( 'Règle incomplète. Vérifie le champ, la condition et l\'action.', 'waaskit-s3-migrator' ),
					'tr_confirm'     => __( 'Appliquer la règle ? La modification est définitive (pas de rollback pour les transformations).', 'waaskit-s3-migrator' ),
				],
			]
		);
	}

	/**
	 * Persist the "source hosts" textarea submitted from the Scan tab.
	 */
	public function save_sources(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'forbidden', 403 );
		}
		check_admin_referer( 'wks3m_save_sources' );

		$raw           = isset( $_POST['source_hosts'] ) ? (string) wp_unslash( $_POST['source_hosts'] ) : '';
		$auto_detect   = ! empty( $_POST['auto_detect'] );
		$strip_prefixes = ! empty( $_POST['strip_prefixes'] );

		$hosts = preg_split( '/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY ) ?: [];
		\WKS3M\Settings::set_source_hosts( $hosts );

		update_option( 'wks3m_auto_detect_external', $auto_detect ? 1 : 0 );
		update_option( 'wks3m_strip_strapi_prefixes', $strip_prefixes ? 1 : 0 );

		wp_safe_redirect( admin_url( 'tools.php?page=' . self::MENU_SLUG . '&tab=scan&sources_saved=1' ) );
		exit;
	}

	public function render_page(): void {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( (string) $_GET['tab'] ) : 'scan';
		?>
		<div class="wrap wks3m-wrap">
			<h1><?php esc_html_e( 'AWS S3 WordPress Migrator', 'waaskit-s3-migrator' ); ?></h1>
			<nav class="nav-tab-wrapper">
				<a href="<?php echo esc_url( admin_url( 'tools.php?page=' . self::MENU_SLUG . '&tab=scan' ) ); ?>"
					class="nav-tab <?php echo 'scan' === $tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Scan', 'waaskit-s3-migrator' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'tools.php?page=' . self::MENU_SLUG . '&tab=queue' ) ); ?>"
					class="nav-tab <?php echo 'queue' === $tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'File d\'attente', 'waaskit-s3-migrator' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'tools.php?page=' . self::MENU_SLUG . '&tab=history' ) ); ?>"
					class="nav-tab <?php echo 'history' === $tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Historique & Rollback', 'waaskit-s3-migrator' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'tools.php?page=' . self::MENU_SLUG . '&tab=settings' ) ); ?>"
					class="nav-tab <?php echo 'settings' === $tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Réglages', 'waaskit-s3-migrator' ); ?>
				</a>
			</nav>
			<?php
			switch ( $tab ) {
				case 'queue':
					require WKS3M_PLUGIN_DIR . 'admin/views/page-queue.php';
					break;
				case 'history':
					require WKS3M_PLUGIN_DIR . 'admin/views/page-history.php';
					break;
				case 'settings':
					require WKS3M_PLUGIN_DIR . 'admin/views/page-settings.php';
					break;
				case 'scan':
				default:
					require WKS3M_PLUGIN_DIR . 'admin/views/page-scan.php';
					break;
			}
			?>
		</div>
		<?php
	}
}
