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
use WKS3M\Search_Replace;
use WKS3M\Util;

defined( 'ABSPATH' ) || exit;

class Ajax_Controller {

	public function register(): void {
		add_action( 'wp_ajax_wks3m_scan_batch', [ $this, 'scan_batch' ] );
		add_action( 'wp_ajax_wks3m_scan_secondary', [ $this, 'scan_secondary' ] );
		add_action( 'wp_ajax_wks3m_import_row', [ $this, 'import_row' ] );
		add_action( 'wp_ajax_wks3m_replace_row', [ $this, 'replace_row' ] );
		add_action( 'wp_ajax_wks3m_rollback_row', [ $this, 'rollback_row' ] );
		add_action( 'wp_ajax_wks3m_pending_ids', [ $this, 'pending_ids' ] );
		add_action( 'wp_ajax_wks3m_sr_preview', [ $this, 'sr_preview' ] );
		add_action( 'wp_ajax_wks3m_sr_apply', [ $this, 'sr_apply' ] );
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

	public function pending_ids(): void {
		$this->guard();
		$limit = isset( $_POST['limit'] ) ? max( 1, min( 5000, (int) $_POST['limit'] ) ) : 2000;
		$ids   = Plugin::instance()->mapping_store()->ids_by_status( 'pending', $limit );
		wp_send_json_success( [ 'ids' => $ids ] );
	}

	public function import_row(): void {
		$this->guard();

		$id      = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		$dry_run = Util::bool_param( $_POST['dry_run'] ?? null, true );
		$opts    = [
			'auto_replace'     => Util::bool_param( $_POST['auto_replace'] ?? null, false ),
			'use_alt_as_title' => Util::bool_param( $_POST['use_alt_as_title'] ?? null, false ),
			'fill_empty_alts'  => Util::bool_param( $_POST['fill_empty_alts'] ?? null, false ),
		];

		$store = Plugin::instance()->mapping_store();
		$row   = $store->find_by_id( $id );
		if ( ! $row ) {
			wp_send_json_error( [ 'message' => 'row_not_found' ], 404 );
		}

		$importer = new Importer();

		if ( $dry_run ) {
			wp_send_json_success( [
				'dry_run' => true,
				'preview' => $importer->dry_run( $row, $opts ),
			] );
		}

		if ( ! empty( $row['attachment_id'] ) && in_array( $row['status'], [ 'imported', 'replaced' ], true ) ) {
			$attachment_id = (int) $row['attachment_id'];
		} else {
			$result = $importer->import( $row, $opts );
			if ( is_wp_error( $result ) ) {
				$store->mark_failed( $id, $result->get_error_message() );
				wp_send_json_error( [ 'message' => $result->get_error_message() ], 500 );
			}
			$attachment_id        = (int) $result['attachment_id'];
			$row['attachment_id'] = $attachment_id;
			$row['status']        = 'imported';
			$store->mark_imported( $id, $attachment_id );
		}

		$payload = [
			'dry_run'        => false,
			'attachment_id'  => $attachment_id,
			'attachment_url' => (string) wp_get_attachment_url( $attachment_id ),
			'replaced'       => false,
		];

		if ( $opts['auto_replace'] ) {
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

	public function rollback_row(): void {
		$this->guard();
		$id                = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		$delete_attachment = Util::bool_param( $_POST['delete_attachment'] ?? null, false );

		$res = ( new Rollback_Manager() )->rollback( $id, $delete_attachment );
		if ( ! empty( $res['errors'] ) && 0 === (int) $res['posts_restored'] ) {
			wp_send_json_error( [ 'message' => implode( ' | ', $res['errors'] ) ], 500 );
		}
		wp_send_json_success( $res );
	}

	public function sr_preview(): void {
		$this->guard();
		[ $find, $replace, $fields, $update_attachments ] = $this->read_sr_params();
		if ( '' === $find ) {
			wp_send_json_error( [ 'message' => 'empty_find' ], 400 );
		}
		wp_send_json_success( ( new Search_Replace() )->preview( $find, $fields, $update_attachments ) + [
			'replace' => $replace,
		] );
	}

	public function sr_apply(): void {
		$this->guard();
		[ $find, $replace, $fields, $update_attachments ] = $this->read_sr_params();
		if ( '' === $find ) {
			wp_send_json_error( [ 'message' => 'empty_find' ], 400 );
		}
		wp_send_json_success(
			( new Search_Replace() )->apply( $find, $replace, $fields, $update_attachments )
		);
	}

	/**
	 * @return array{0:string,1:string,2:string[],3:bool}
	 */
	private function read_sr_params(): array {
		$find    = isset( $_POST['find'] ) ? (string) wp_unslash( $_POST['find'] ) : '';
		$replace = isset( $_POST['replace'] ) ? (string) wp_unslash( $_POST['replace'] ) : '';
		$fields  = isset( $_POST['fields'] ) && is_array( $_POST['fields'] )
			? array_map( 'sanitize_key', (array) wp_unslash( $_POST['fields'] ) )
			: [];
		$update_attachments = Util::bool_param( $_POST['update_attachments'] ?? null, true );
		return [ $find, $replace, $fields, $update_attachments ];
	}
}
