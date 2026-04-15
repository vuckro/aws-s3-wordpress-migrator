<?php
/**
 * Plugin bootstrap singleton.
 *
 * @package ClaireexploreS3Migrator
 */

namespace CXS3M;

defined( 'ABSPATH' ) || exit;

class Plugin {

	private static ?Plugin $instance = null;

	private bool $booted = false;

	public static function instance(): Plugin {
		return self::$instance ??= new self();
	}

	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		// Safety-net: if the plugin was installed before the table existed, try to create it.
		if ( is_admin() && get_option( Activator::TABLE_VERSION_OPTION ) !== Activator::CURRENT_DB_VERSION ) {
			Activator::install_table();
		}

		if ( is_admin() ) {
			( new \CXS3M\Admin\Admin() )->register();
			( new \CXS3M\Admin\Ajax_Controller() )->register();
			add_filter( 'plugin_row_meta', [ $this, 'open_plugin_site_in_new_tab' ], 10, 2 );
		}
	}

	/**
	 * Force the "Visit plugin site" link on the Plugins list to open in a new tab.
	 */
	public function open_plugin_site_in_new_tab( array $links, string $plugin_file ): array {
		if ( plugin_basename( CXS3M_PLUGIN_FILE ) !== $plugin_file ) {
			return $links;
		}
		foreach ( $links as $i => $link ) {
			if ( false !== strpos( $link, 'plugin-install.php' ) ) {
				continue;
			}
			// Match any <a> that doesn't already have a target attribute.
			if ( false !== strpos( $link, '<a ' ) && false === strpos( $link, 'target=' ) ) {
				$links[ $i ] = str_replace( '<a ', '<a target="_blank" rel="noopener noreferrer" ', $link );
			}
		}
		return $links;
	}

	public function scanner(): Scanner {
		return new Scanner();
	}

	public function mapping_store(): Mapping_Store {
		return new Mapping_Store();
	}
}
