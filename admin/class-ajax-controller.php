<?php
/**
 * AJAX handlers.
 *
 * @package WaasKitS3Migrator
 */

namespace WKS3M\Admin;

use WKS3M\Alt_Syncer;
use WKS3M\Importer;
use WKS3M\Plugin;
use WKS3M\Replacer;
use WKS3M\Settings;
use WKS3M\Transform;
use WKS3M\Util;

defined( 'ABSPATH' ) || exit;

class Ajax_Controller {

	public function register(): void {
		$handlers = [
			'wks3m_scan_batch'        => 'scan_batch',
			'wks3m_scan_secondary'    => 'scan_secondary',
			'wks3m_pending_ids'       => 'pending_ids',
			'wks3m_import_row'        => 'import_row',
			'wks3m_replace_row'       => 'replace_row',
			'wks3m_finalize_thumb'    => 'finalize_thumb',
			'wks3m_pending_thumb_ids' => 'pending_thumb_ids',
			// Bulk ALT/title transform on the queue rows (pre-import cleanup).
			'wks3m_transform_preview' => 'transform_preview',
			'wks3m_transform_apply'   => 'transform_apply',
			// Alt sync.
			'wks3m_alt_scan_batch'          => 'alt_scan_batch',
			'wks3m_alt_diff_ids'            => 'alt_diff_ids',
			'wks3m_alt_apply_diff'          => 'alt_apply_diff',
			'wks3m_alt_fill_from_title'     => 'alt_fill_from_title',
			'wks3m_alt_missing_ids'         => 'alt_missing_ids',
		];
		foreach ( $handlers as $action => $method ) {
			add_action( "wp_ajax_{$action}", [ $this, $method ] );
		}
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
		wp_send_json_success( Plugin::instance()->scanner()->scan_posts_batch( $offset, $limit ) );
	}

	public function scan_secondary(): void {
		$this->guard();
		wp_send_json_success( Plugin::instance()->scanner()->count_secondary_sources() );
	}

	public function pending_ids(): void {
		$this->guard();
		$limit = isset( $_POST['limit'] ) ? max( 1, min( 5000, (int) $_POST['limit'] ) ) : 5000;
		wp_send_json_success( [
			'ids' => Plugin::instance()->mapping_store()->ids_by_status( 'pending', $limit ),
		] );
	}

public function import_row(): void {
		$this->guard();

		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;

		$store = Plugin::instance()->mapping_store();
		$row   = $store->get( $id );
		if ( ! $row ) {
			wp_send_json_error( [ 'message' => 'row_not_found' ], 404 );
		}

		$importer      = new Importer();
		$attachment_id = $row->attachment_id();
		if ( ! $row->is_imported() ) {
			// Read per-request override, fallback to global setting.
			$defer  = isset( $_POST['defer_thumbnails'] )
				? Util::bool_param( $_POST['defer_thumbnails'], false )
				: Settings::defer_thumbnails();
			$result = $importer->import( $row, $defer );
			if ( is_wp_error( $result ) ) {
				$store->mark_failed( $id, $result->get_error_message() );
				wp_send_json_error( [ 'message' => $result->get_error_message() ], 500 );
			}
			$attachment_id = (int) $result['attachment_id'];
			$store->mark_imported( $id, $attachment_id );
			$row = $store->get( $id );
		}

		// Always rewrite URLs in post_content after a successful import.
		$replaced = false;
		if ( $row ) {
			$rep = ( new Replacer() )->replace_for_row( $row, $attachment_id );
			if ( empty( $rep['errors'] ) && $rep['posts_updated'] > 0 ) {
				$store->mark_replaced( $id );
				$replaced = true;
			}
		}

		wp_send_json_success( [
			'attachment_id'  => $attachment_id,
			'attachment_url' => (string) wp_get_attachment_url( $attachment_id ),
			'status'         => $replaced ? 'replaced' : 'imported',
			'replaced'       => $replaced,
		] );
	}

	public function replace_row(): void {
		$this->guard();
		$id    = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		$store = Plugin::instance()->mapping_store();
		$row   = $store->get( $id );
		if ( ! $row || ! $row->is_imported() ) {
			wp_send_json_error( [ 'message' => 'not_imported_yet' ], 400 );
		}

		$res = ( new Replacer() )->replace_for_row( $row, $row->attachment_id() );
		if ( ! empty( $res['errors'] ) && 0 === (int) $res['posts_updated'] ) {
			wp_send_json_error( [ 'message' => implode( ' | ', $res['errors'] ) ], 500 );
		}
		$store->mark_replaced( $id );
		wp_send_json_success( $res );
	}

	public function finalize_thumb(): void {
		$this->guard();
		$attach_id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		if ( $attach_id <= 0 ) {
			wp_send_json_error( [ 'message' => 'invalid_id' ], 400 );
		}
		$res = ( new Importer() )->finalize_thumbnails( $attach_id );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( [ 'message' => $res->get_error_message() ], 500 );
		}
		wp_send_json_success( [ 'attachment_id' => $attach_id ] );
	}

	public function pending_thumb_ids(): void {
		$this->guard();
		$limit = isset( $_POST['limit'] ) ? max( 1, min( 20000, (int) $_POST['limit'] ) ) : 5000;
		wp_send_json_success( [ 'ids' => Importer::pending_thumbnails_ids( $limit ) ] );
	}

	/* ------------ Bulk ALT / title Transform (on queue rows) ------------ */

	public function transform_preview(): void {
		$this->guard();
		wp_send_json_success( ( new Transform() )->preview( $this->read_transform_rule() ) );
	}

	public function transform_apply(): void {
		$this->guard();
		wp_send_json_success( ( new Transform() )->apply( $this->read_transform_rule() ) );
	}

	private function read_transform_rule(): array {
		return [
			'field'     => sanitize_key( (string) ( $_POST['field'] ?? '' ) ),
			'condition' => [
				'type'  => sanitize_key( (string) ( $_POST['condition_type'] ?? '' ) ),
				'value' => isset( $_POST['condition_value'] ) ? (string) wp_unslash( $_POST['condition_value'] ) : '',
			],
			'action'    => [
				'type'  => sanitize_key( (string) ( $_POST['action_type'] ?? '' ) ),
				'value' => isset( $_POST['action_value'] ) ? (string) wp_unslash( $_POST['action_value'] ) : '',
			],
		];
	}

	/* ------------ Alt sync ------------ */

	public function alt_scan_batch(): void {
		$this->guard();
		$offset = isset( $_POST['offset'] ) ? max( 0, (int) $_POST['offset'] ) : 0;
		$limit  = isset( $_POST['limit'] ) ? max( 1, min( 500, (int) $_POST['limit'] ) ) : 100;
		wp_send_json_success( Plugin::instance()->alt_scanner()->scan_batch( $offset, $limit ) );
	}

	public function alt_diff_ids(): void {
		$this->guard();
		$limit = isset( $_POST['limit'] ) ? max( 1, min( 20000, (int) $_POST['limit'] ) ) : 20000;
		wp_send_json_success( [
			'ids' => Plugin::instance()->alt_diff_store()->pending_ids( $limit ),
		] );
	}

	public function alt_apply_diff(): void {
		$this->guard();
		$id   = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		$diff = Plugin::instance()->alt_diff_store()->get( $id );
		if ( ! $diff ) {
			wp_send_json_error( [ 'message' => 'diff_not_found' ], 404 );
		}
		$res = ( new Alt_Syncer() )->apply( $diff );
		if ( ! empty( $res['errors'] ) && 0 === (int) $res['tags_updated'] ) {
			wp_send_json_error( [ 'message' => implode( ' | ', $res['errors'] ) ], 500 );
		}
		wp_send_json_success( $res );
	}

	public function alt_fill_from_title(): void {
		$this->guard();
		$id  = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		$alt = \WKS3M\Alt_Scanner::fill_alt_from_title( $id );
		if ( '' === $alt ) {
			wp_send_json_error( [ 'message' => 'empty_title_or_not_attachment' ], 400 );
		}
		wp_send_json_success( [ 'id' => $id, 'alt' => $alt ] );
	}

	public function alt_missing_ids(): void {
		$this->guard();
		wp_send_json_success( [
			'ids' => array_map( 'intval', (array) get_option( \WKS3M\Alt_Scanner::OPT_MISSING_ALT, [] ) ),
		] );
	}
}
