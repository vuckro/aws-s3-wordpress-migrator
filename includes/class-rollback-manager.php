<?php
/**
 * Rollback_Manager — restore post_content from the per-row backup and flip
 * the migration log status.
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
	 * @return array{posts_restored:int,errors:string[]}
	 */
	public function rollback( int $row_id ): array {
		$row = $this->store->get( $row_id );
		if ( ! $row ) {
			return [ 'posts_restored' => 0, 'errors' => [ 'row_not_found' ] ];
		}
		$res = $this->replacer->rollback_row( $row );
		$this->store->mark_rolled_back( $row_id );
		return $res;
	}
}
