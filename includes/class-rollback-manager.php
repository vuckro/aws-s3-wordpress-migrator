<?php
/**
 * Rollback_Manager — restores post_content from backups, optionally deletes
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
	 * @return array{posts_restored:int,attachment_deleted:bool,errors:string[]}
	 */
	public function rollback( int $row_id, bool $delete_attachment = false ): array {
		$row = $this->store->get( $row_id );
		if ( ! $row ) {
			return [ 'posts_restored' => 0, 'attachment_deleted' => false, 'errors' => [ 'row_not_found' ] ];
		}

		$res = $this->replacer->rollback_row( $row );

		$attachment_deleted = false;
		if ( $delete_attachment && $row->attachment_id() > 0 ) {
			$attachment_deleted = (bool) wp_delete_attachment( $row->attachment_id(), true );
		}

		$this->store->mark_rolled_back( $row_id, $attachment_deleted );

		return [
			'posts_restored'     => (int) $res['posts_restored'],
			'attachment_deleted' => $attachment_deleted,
			'errors'             => $res['errors'],
		];
	}
}
