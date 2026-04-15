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
use WKS3M\Transform;
use WKS3M\Util;

defined( 'ABSPATH' ) || exit;

class Ajax_Controller {

	public function register(): void {
		$handlers = [
			'wks3m_scan_batch'     => 'scan_batch',
			'wks3m_scan_secondary' => 'scan_secondary',
			'wks3m_pending_ids'    => 'pending_ids',
			'wks3m_import_row'     => 'import_row',
			'wks3m_replace_row'    => 'replace_row',
			'wks3m_rollback_row'   => 'rollback_row',
			'wks3m_transform_preview' => 'transform_preview',
			'wks3m_transform_apply'   => 'transform_apply',
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
		$limit = isset( $_POST['limit'] ) ? max( 1, min( 5000, (int) $_POST['limit'] ) ) : 2000;
		wp_send_json_success( [
			'ids' => Plugin::instance()->mapping_store()->ids_by_status( 'pending', $limit ),
		] );
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
		$row   = $store->get( $id );
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

		$attachment_id = $row->attachment_id();
		if ( ! $row->is_imported() ) {
			$result = $importer->import( $row, $opts );
			if ( is_wp_error( $result ) ) {
				$store->mark_failed( $id, $result->get_error_message() );
				wp_send_json_error( [ 'message' => $result->get_error_message() ], 500 );
			}
			$attachment_id = (int) $result['attachment_id'];
			$store->mark_imported( $id, $attachment_id );
			$row = $store->get( $id );
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
			'field'              => sanitize_key( (string) ( $_POST['field'] ?? '' ) ),
			'condition'          => [
				'type'  => sanitize_key( (string) ( $_POST['condition_type'] ?? '' ) ),
				'value' => isset( $_POST['condition_value'] ) ? (string) wp_unslash( $_POST['condition_value'] ) : '',
			],
			'action'             => [
				'type'  => sanitize_key( (string) ( $_POST['action_type'] ?? '' ) ),
				'value' => isset( $_POST['action_value'] ) ? (string) wp_unslash( $_POST['action_value'] ) : '',
			],
			'update_attachments' => Util::bool_param( $_POST['update_attachments'] ?? null, true ),
		];
	}
}
