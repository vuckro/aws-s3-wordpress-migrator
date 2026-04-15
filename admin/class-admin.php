<?php
/**
 * Admin UI — menu registration and asset enqueue.
 *
 * @package ClaireexploreS3Migrator
 */

namespace CXS3M\Admin;

defined( 'ABSPATH' ) || exit;

class Admin {

	public const MENU_SLUG = 'cxs3m';

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
	}

	public function add_menu(): void {
		add_management_page(
			__( 'Claireexplore S3 Migrator', 'claireexplore-s3-migrator' ),
			__( 'S3 Migrator', 'claireexplore-s3-migrator' ),
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
			'cxs3m-admin',
			CXS3M_PLUGIN_URL . 'assets/css/admin.css',
			[],
			CXS3M_VERSION
		);
		wp_enqueue_script(
			'cxs3m-admin',
			CXS3M_PLUGIN_URL . 'assets/js/admin.js',
			[ 'jquery' ],
			CXS3M_VERSION,
			true
		);
		wp_localize_script(
			'cxs3m-admin',
			'CXS3M',
			[
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'cxs3m_action' ),
				'i18n'     => [
					'scanning'      => __( 'Scan en cours…', 'claireexplore-s3-migrator' ),
					'done'          => __( 'Terminé.', 'claireexplore-s3-migrator' ),
					'error'         => __( 'Erreur pendant le scan.', 'claireexplore-s3-migrator' ),
					'processed'     => __( 'Posts analysés', 'claireexplore-s3-migrator' ),
					'urls_found'    => __( 'URLs S3 uniques', 'claireexplore-s3-migrator' ),
					'base_keys'     => __( 'Images distinctes (variantes regroupées)', 'claireexplore-s3-migrator' ),
					'postmeta_hits' => __( 'Lignes postmeta contenant des URLs S3', 'claireexplore-s3-migrator' ),
					'options_hits'  => __( 'Lignes options contenant des URLs S3', 'claireexplore-s3-migrator' ),
					'already_known' => __( 'Déjà connues (table de mapping)', 'claireexplore-s3-migrator' ),
					'variants_lbl'  => __( 'Variantes', 'claireexplore-s3-migrator' ),
					'posts_lbl'     => __( 'Posts', 'claireexplore-s3-migrator' ),
				],
			]
		);
	}

	public function render_page(): void {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( (string) $_GET['tab'] ) : 'scan';
		?>
		<div class="wrap cxs3m-wrap">
			<h1><?php esc_html_e( 'Claireexplore S3 Migrator', 'claireexplore-s3-migrator' ); ?></h1>
			<nav class="nav-tab-wrapper">
				<a href="<?php echo esc_url( admin_url( 'tools.php?page=' . self::MENU_SLUG . '&tab=scan' ) ); ?>"
					class="nav-tab <?php echo 'scan' === $tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Scan', 'claireexplore-s3-migrator' ); ?>
				</a>
				<a href="#" class="nav-tab disabled" aria-disabled="true">
					<?php esc_html_e( 'File d\'attente', 'claireexplore-s3-migrator' ); ?>
					<span class="cxs3m-pill"><?php esc_html_e( 'Phase 2', 'claireexplore-s3-migrator' ); ?></span>
				</a>
				<a href="#" class="nav-tab disabled" aria-disabled="true">
					<?php esc_html_e( 'Historique', 'claireexplore-s3-migrator' ); ?>
					<span class="cxs3m-pill"><?php esc_html_e( 'Phase 4', 'claireexplore-s3-migrator' ); ?></span>
				</a>
			</nav>
			<?php
			switch ( $tab ) {
				case 'scan':
				default:
					require CXS3M_PLUGIN_DIR . 'admin/views/page-scan.php';
					break;
			}
			?>
		</div>
		<?php
	}
}
