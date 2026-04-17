<?php
/**
 * Plugin bootstrap singleton.
 *
 * @package WaasKitS3Migrator
 */

namespace WKS3M;

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

		// Safety-net: upgrade the schema + seed new option defaults on version bump.
		if ( is_admin() && get_option( Activator::TABLE_VERSION_OPTION ) !== Activator::CURRENT_DB_VERSION ) {
			Activator::install_tables();
			Activator::install_default_options();
		}

		if ( is_admin() ) {
			( new \WKS3M\Admin\Admin() )->register();
			( new \WKS3M\Admin\Ajax_Controller() )->register();
			add_filter( 'plugin_row_meta', [ $this, 'open_plugin_site_in_new_tab' ], 10, 2 );
		}

		// Featured images, gallery tiles, related-posts thumbnails — everything
		// WordPress renders via wp_get_attachment_image() — get alt from the
		// library but no title. Mirror alt → title here so both attributes are
		// present in the HTML, matching what the Alt_Syncer does for <img> tags
		// embedded in post_content. Priority 999 so we run after plugins like
		// SlimSEO that fill a missing alt at priority 10 — otherwise the alt
		// is still empty when we check.
		add_filter( 'wp_get_attachment_image_attributes', [ $this, 'mirror_alt_to_title' ], 999 );
	}

	public function mirror_alt_to_title( array $attr ): array {
		if ( empty( $attr['title'] ) && ! empty( $attr['alt'] ) ) {
			$attr['title'] = $attr['alt'];
		}
		return $attr;
	}

	/**
	 * Force the "Visit plugin site" link on the Plugins list to open in a new tab.
	 */
	public function open_plugin_site_in_new_tab( array $links, string $plugin_file ): array {
		if ( plugin_basename( WKS3M_PLUGIN_FILE ) !== $plugin_file ) {
			return $links;
		}
		foreach ( $links as $i => $link ) {
			if ( false !== strpos( $link, 'plugin-install.php' ) ) {
				continue;
			}
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

	public function alt_diff_store(): Alt_Diff_Store {
		return new Alt_Diff_Store();
	}

	public function alt_scanner(): Alt_Scanner {
		return new Alt_Scanner( $this->alt_diff_store() );
	}
}
