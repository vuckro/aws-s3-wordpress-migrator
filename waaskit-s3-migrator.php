<?php
/**
 * Plugin Name:       AWS S3 WordPress Migrator
 * Plugin URI:        https://github.com/vuckro/aws-s3-wordpress-migrator
 * Description:       Scanne les URLs d'images externes présentes dans WordPress (bucket S3, CDN distant, CMS externe…), les télécharge dans la Media Library avec leurs métadonnées SEO (alt, titre), remplace les URLs dans le contenu et garde un log réversible. Configurable pour tout site source.
 * Version:           0.6.0
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

define( 'WKS3M_VERSION', '0.6.0' );
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
require_once WKS3M_PLUGIN_DIR . 'includes/class-transform.php';
require_once WKS3M_PLUGIN_DIR . 'includes/class-scanner.php';
require_once WKS3M_PLUGIN_DIR . 'includes/class-plugin.php';
require_once WKS3M_PLUGIN_DIR . 'admin/class-view-helper.php';
require_once WKS3M_PLUGIN_DIR . 'admin/class-admin.php';
require_once WKS3M_PLUGIN_DIR . 'admin/class-ajax-controller.php';

register_activation_hook( __FILE__, [ '\WKS3M\Activator', 'activate' ] );
register_deactivation_hook( __FILE__, [ '\WKS3M\Activator', 'deactivate' ] );

add_action( 'plugins_loaded', static function () {
	load_plugin_textdomain( 'waaskit-s3-migrator', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	\WKS3M\Plugin::instance()->boot();
} );
