<?php
/**
 * Plugin Name:       AWS S3 WordPress Migrator
 * Plugin URI:        https://github.com/vuckro/aws-s3-wordpress-migrator
 * Description:       Détecte les images hébergées sur un domaine externe (S3, CDN, CMS headless…), les importe dans la Media Library WordPress avec leurs métadonnées SEO, remplace les URLs dans le contenu, et garde un historique réversible.
 * Version:           1.3.0
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * Author:            WaasKit
 * Author URI:        https://github.com/vuckro
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       waaskit-s3-migrator
 * Domain Path:       /languages
 *
 * @package WaasKitS3Migrator
 */

defined( 'ABSPATH' ) || exit;

define( 'WKS3M_VERSION', '1.3.0' );
define( 'WKS3M_PLUGIN_FILE', __FILE__ );
define( 'WKS3M_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WKS3M_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once WKS3M_PLUGIN_DIR . 'includes/class-activator.php';
require_once WKS3M_PLUGIN_DIR . 'includes/class-logger.php';
require_once WKS3M_PLUGIN_DIR . 'includes/class-util.php';
require_once WKS3M_PLUGIN_DIR . 'includes/class-settings.php';
require_once WKS3M_PLUGIN_DIR . 'includes/class-url-helper.php';
require_once WKS3M_PLUGIN_DIR . 'includes/class-migration-row.php';
require_once WKS3M_PLUGIN_DIR . 'includes/class-mapping-store.php';
require_once WKS3M_PLUGIN_DIR . 'includes/class-metadata-extractor.php';
require_once WKS3M_PLUGIN_DIR . 'includes/class-downloader.php';
require_once WKS3M_PLUGIN_DIR . 'includes/class-importer.php';
require_once WKS3M_PLUGIN_DIR . 'includes/class-replacer.php';
require_once WKS3M_PLUGIN_DIR . 'includes/class-rollback-manager.php';
require_once WKS3M_PLUGIN_DIR . 'includes/class-scanner.php';
require_once WKS3M_PLUGIN_DIR . 'includes/class-alt-diff.php';
require_once WKS3M_PLUGIN_DIR . 'includes/class-alt-diff-store.php';
require_once WKS3M_PLUGIN_DIR . 'includes/class-alt-scanner.php';
require_once WKS3M_PLUGIN_DIR . 'includes/class-alt-syncer.php';
require_once WKS3M_PLUGIN_DIR . 'includes/class-plugin.php';
require_once WKS3M_PLUGIN_DIR . 'admin/class-view-helper.php';
require_once WKS3M_PLUGIN_DIR . 'admin/class-admin.php';
require_once WKS3M_PLUGIN_DIR . 'admin/class-ajax-controller.php';

// WP-CLI commands — only loaded under CLI to keep web requests lean.
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once WKS3M_PLUGIN_DIR . 'includes/class-cli.php';
}

register_activation_hook( __FILE__, [ '\WKS3M\Activator', 'activate' ] );
register_deactivation_hook( __FILE__, [ '\WKS3M\Activator', 'deactivate' ] );

add_action( 'plugins_loaded', static function () {
	load_plugin_textdomain( 'waaskit-s3-migrator', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	\WKS3M\Plugin::instance()->boot();
} );
