<?php
/**
 * Rollback Manager — restores post_content from backups and optionally deletes
 * the attachment created during migration.
 *
 * @package WaasKitS3Migrator
 */

namespace WKS3M;

defined( 'ABSPATH' ) || exit;

class Rollback_Manager {

	private Replacer $replacer;
	private Mapping_Store $store;

	public function __construct( ?Replacer $replacer = null, ?Mapping_Store $store = null ) {
		$this->replacer = $replacer ?? new Replacer();
		$this->store    = $store    ?? new Mapping_Store();
	}

	/**
	 * Roll back a migration-log row.
	 *
	 * @return array{posts_restored:int,attachment_deleted:bool,errors:string[]}
	 */
	public function rollback( int $row_id, bool $delete_attachment = false ): array {
		$row = $this->store->find_by_id( $row_id );
		if ( ! $row ) {
			return [ 'posts_restored' => 0, 'attachment_deleted' => false, 'errors' => [ 'row_not_found' ] ];
		}
		$res = $this->replacer->rollback_row( $row );

		$attachment_deleted = false;
		if ( $delete_attachment && ! empty( $row['attachment_id'] ) ) {
			$deleted = wp_delete_attachment( (int) $row['attachment_id'], true );
			$attachment_deleted = (bool) $deleted;
		}

		global $wpdb;
		$wpdb->update(
			$this->store->table(),
			[
				'status'         => 'rolled_back',
				'rolled_back_at' => current_time( 'mysql' ),
				'attachment_id'  => $attachment_deleted ? null : ( $row['attachment_id'] ?? null ),
			],
			[ 'id' => $row_id ]
		);

		return [
			'posts_restored'     => (int) $res['posts_restored'],
			'attachment_deleted' => $attachment_deleted,
			'errors'             => $res['errors'],
		];
	}
}
