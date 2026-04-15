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
		}
	}

	public function scanner(): Scanner {
		return new Scanner();
	}

	public function mapping_store(): Mapping_Store {
		return new Mapping_Store();
	}
}
