<?php
/**
 * AJAX handlers (read-only for Phase 1).
 *
 * @package ClaireexploreS3Migrator
 */

namespace CXS3M\Admin;

use CXS3M\Plugin;

defined( 'ABSPATH' ) || exit;

class Ajax_Controller {

	public function register(): void {
		add_action( 'wp_ajax_cxs3m_scan_batch', [ $this, 'scan_batch' ] );
		add_action( 'wp_ajax_cxs3m_scan_secondary', [ $this, 'scan_secondary' ] );
	}

	private function guard(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'forbidden' ], 403 );
		}
		check_ajax_referer( 'cxs3m_action', 'nonce' );
	}

	public function scan_batch(): void {
		$this->guard();

		$offset = isset( $_POST['offset'] ) ? max( 0, (int) $_POST['offset'] ) : 0;
		$limit  = isset( $_POST['limit'] ) ? max( 1, min( 500, (int) $_POST['limit'] ) ) : 100;

		$scanner = Plugin::instance()->scanner();
		$result  = $scanner->scan_posts_batch( $offset, $limit );

		// Flag "already known" against the mapping store.
		$store = Plugin::instance()->mapping_store();
		foreach ( $result['matches'] as $key => &$m ) {
			$m['already_known'] = $store->is_known( $key );
		}
		unset( $m );

		wp_send_json_success( $result );
	}

	public function scan_secondary(): void {
		$this->guard();
		$scanner = Plugin::instance()->scanner();
		wp_send_json_success( $scanner->count_secondary_sources() );
	}
}
