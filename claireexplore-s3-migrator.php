<?php
/**
 * Plugin Name:       Claireexplore S3 Migrator
 * Plugin URI:        https://github.com/
 * Description:       Scanne, télécharge et importe dans la Media Library les images hébergées sur clairexplore.s3.eu-west-3.amazonaws.com, avec validation manuelle et rollback réversible.
 * Version:           0.1.0-phase1
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * Author:            Claire Explore
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       claireexplore-s3-migrator
 * Domain Path:       /languages
 *
 * @package ClaireexploreS3Migrator
 */

defined( 'ABSPATH' ) || exit;

define( 'CXS3M_VERSION', '0.1.0-phase1' );
define( 'CXS3M_PLUGIN_FILE', __FILE__ );
define( 'CXS3M_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'CXS3M_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'CXS3M_S3_HOST', 'clairexplore.s3.eu-west-3.amazonaws.com' );

require_once CXS3M_PLUGIN_DIR . 'includes/class-activator.php';
require_once CXS3M_PLUGIN_DIR . 'includes/class-logger.php';
require_once CXS3M_PLUGIN_DIR . 'includes/class-mapping-store.php';
require_once CXS3M_PLUGIN_DIR . 'includes/class-scanner.php';
require_once CXS3M_PLUGIN_DIR . 'includes/class-plugin.php';
require_once CXS3M_PLUGIN_DIR . 'admin/class-admin.php';
require_once CXS3M_PLUGIN_DIR . 'admin/class-ajax-controller.php';

register_activation_hook( __FILE__, [ '\CXS3M\Activator', 'activate' ] );
register_deactivation_hook( __FILE__, [ '\CXS3M\Activator', 'deactivate' ] );

add_action( 'plugins_loaded', static function () {
	load_plugin_textdomain( 'claireexplore-s3-migrator', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	\CXS3M\Plugin::instance()->boot();
} );
