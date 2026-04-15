<?php
/**
 * AJAX handlers.
 *
 * @package WaasKitS3Migrator
 */

namespace WKS3M\Admin;

use WKS3M\Importer;
use WKS3M\Plugin;
use WKS3M\Replacer;
use WKS3M\Rollback_Manager;

defined( 'ABSPATH' ) || exit;

class Ajax_Controller {

	public function register(): void {
		add_action( 'wp_ajax_wks3m_scan_batch', [ $this, 'scan_batch' ] );
		add_action( 'wp_ajax_wks3m_scan_secondary', [ $this, 'scan_secondary' ] );
		add_action( 'wp_ajax_wks3m_import_row', [ $this, 'import_row' ] );
		add_action( 'wp_ajax_wks3m_replace_row', [ $this, 'replace_row' ] );
		add_action( 'wp_ajax_wks3m_rollback_row', [ $this, 'rollback_row' ] );
		add_action( 'wp_ajax_wks3m_pending_ids', [ $this, 'pending_ids' ] );
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
		unset( $result['matches'] );
		wp_send_json_success( $result );
	}

	public function scan_secondary(): void {
		$this->guard();
		$scanner = Plugin::instance()->scanner();
		wp_send_json_success( $scanner->count_secondary_sources() );
	}

	/**
	 * Return up to N pending row IDs for a bulk import driver in the browser.
	 */
	public function pending_ids(): void {
		$this->guard();
		$limit = isset( $_POST['limit'] ) ? max( 1, min( 5000, (int) $_POST['limit'] ) ) : 2000;
		$ids   = Plugin::instance()->mapping_store()->ids_by_status( 'pending', $limit );
		wp_send_json_success( [ 'ids' => $ids ] );
	}

	/**
	 * Import (or dry-run) a single migration row.
	 * Optionally runs the URL replacer immediately after a successful import.
	 */
	public function import_row(): void {
		$this->guard();

		$id           = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		$dry_run      = isset( $_POST['dry_run'] ) ? (bool) (int) $_POST['dry_run'] : true;
		$auto_replace = isset( $_POST['auto_replace'] ) ? (bool) (int) $_POST['auto_replace'] : false;

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

		// If already imported, reuse the attachment.
		if ( ! empty( $row['attachment_id'] ) && in_array( $row['status'], [ 'imported', 'replaced' ], true ) ) {
			$attachment_id = (int) $row['attachment_id'];
		} else {
			$result = $importer->import( $row );
			if ( is_wp_error( $result ) ) {
				$store->mark_failed( $id, $result->get_error_message() );
				wp_send_json_error( [ 'message' => $result->get_error_message() ], 500 );
			}
			$attachment_id = (int) $result['attachment_id'];
			$store->mark_imported( $id, $attachment_id );
			$row['attachment_id'] = $attachment_id;
			$row['status']        = 'imported';
		}

		$payload = [
			'dry_run'        => false,
			'attachment_id'  => $attachment_id,
			'attachment_url' => (string) wp_get_attachment_url( $attachment_id ),
			'replaced'       => false,
		];

		if ( $auto_replace ) {
			$rep = ( new Replacer() )->replace_for_row( $row, $attachment_id );
			if ( empty( $rep['errors'] ) && $rep['posts_updated'] > 0 ) {
				$store->mark_replaced( $id );
				$payload['replaced']      = true;
				$payload['posts_updated'] = $rep['posts_updated'];
			} else {
				$payload['replace_errors'] = $rep['errors'];
				$payload['posts_updated']  = $rep['posts_updated'];
			}
		}

		wp_send_json_success( $payload );
	}

	/**
	 * Replace URLs in post_content for an already-imported row.
	 */
	public function replace_row(): void {
		$this->guard();
		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;

		$store = Plugin::instance()->mapping_store();
		$row   = $store->find_by_id( $id );
		if ( ! $row || empty( $row['attachment_id'] ) ) {
			wp_send_json_error( [ 'message' => 'not_imported_yet' ], 400 );
		}

		$res = ( new Replacer() )->replace_for_row( $row, (int) $row['attachment_id'] );
		if ( ! empty( $res['errors'] ) && 0 === (int) $res['posts_updated'] ) {
			wp_send_json_error( [ 'message' => implode( ' | ', $res['errors'] ) ], 500 );
		}

		$store->mark_replaced( $id );
		wp_send_json_success( $res );
	}

	/**
	 * Roll back a migration (restore post_content from backup).
	 */
	public function rollback_row(): void {
		$this->guard();
		$id                = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		$delete_attachment = isset( $_POST['delete_attachment'] ) ? (bool) (int) $_POST['delete_attachment'] : false;

		$res = ( new Rollback_Manager() )->rollback( $id, $delete_attachment );
		if ( ! empty( $res['errors'] ) && 0 === (int) $res['posts_restored'] ) {
			wp_send_json_error( [ 'message' => implode( ' | ', $res['errors'] ) ], 500 );
		}
		wp_send_json_success( $res );
	}
}
