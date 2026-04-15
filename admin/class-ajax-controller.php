<?php
/**
 * AJAX handlers.
 *
 * @package WaasKitS3Migrator
 */

namespace WKS3M\Admin;

use WKS3M\Importer;
use WKS3M\Plugin;

defined( 'ABSPATH' ) || exit;

class Ajax_Controller {

	public function register(): void {
		add_action( 'wp_ajax_wks3m_scan_batch', [ $this, 'scan_batch' ] );
		add_action( 'wp_ajax_wks3m_scan_secondary', [ $this, 'scan_secondary' ] );
		add_action( 'wp_ajax_wks3m_import_row', [ $this, 'import_row' ] );
	}

	private function guard(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'forbidden' ], 403 );
		}
		check_ajax_referer( 'wks3m_action', 'nonce' );
	}

	public function scan_batch(): void {
		$this->guard();

		$offset = isset( $_POST['offset'] ) ? max( 0, (int) $_POST['offset'] ) : 0;
		$limit  = isset( $_POST['limit'] ) ? max( 1, min( 500, (int) $_POST['limit'] ) ) : 100;

		$scanner = Plugin::instance()->scanner();
		$result  = $scanner->scan_posts_batch( $offset, $limit );

		// Drop the heavy "matches" array before sending back — Queue tab reads from DB.
		unset( $result['matches'] );
		wp_send_json_success( $result );
	}

	public function scan_secondary(): void {
		$this->guard();
		$scanner = Plugin::instance()->scanner();
		wp_send_json_success( $scanner->count_secondary_sources() );
	}

	/**
	 * Import (or dry-run) a single migration row.
	 */
	public function import_row(): void {
		$this->guard();

		$id      = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		$dry_run = isset( $_POST['dry_run'] ) ? (bool) $_POST['dry_run'] : true;

		$store = Plugin::instance()->mapping_store();
		$row   = $store->find_by_id( $id );
		if ( ! $row ) {
			wp_send_json_error( [ 'message' => 'row_not_found' ], 404 );
		}

		$importer = new Importer();

		if ( $dry_run ) {
			wp_send_json_success( [
				'dry_run' => true,
				'preview' => $importer->dry_run( $row ),
			] );
		}

		$result = $importer->import( $row );
		if ( is_wp_error( $result ) ) {
			$store->mark_failed( $id, $result->get_error_message() );
			wp_send_json_error( [ 'message' => $result->get_error_message() ], 500 );
		}

		$store->mark_imported( $id, $result['attachment_id'] );
		wp_send_json_success( [
			'dry_run'        => false,
			'attachment_id'  => $result['attachment_id'],
			'attachment_url' => $result['attachment_url'],
		] );
	}
}
