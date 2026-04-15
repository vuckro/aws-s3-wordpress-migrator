<?php
/**
 * Mapping store — read/write access to the migration log table.
 *
 * Phase 1: read-only helpers only. Write methods arrive in Phase 3/4.
 *
 * @package WaasKitS3Migrator
 */

namespace WKS3M;

defined( 'ABSPATH' ) || exit;

class Mapping_Store {

	public function table(): string {
		return Activator::table_name();
	}

	/**
	 * Return a row by its base source URL, or null.
	 */
	public function find_by_base_url( string $base_url ): ?array {
		global $wpdb;
		$table = $this->table();
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE source_url_base = %s LIMIT 1", $base_url ),
			ARRAY_A
		);
		return $row ?: null;
	}

	/**
	 * Return counts by status.
	 *
	 * @return array{pending:int,downloaded:int,replaced:int,rolled_back:int,failed:int}
	 */
	public function counts_by_status(): array {
		global $wpdb;
		$table = $this->table();
		$out   = [
			'pending'     => 0,
			'downloaded'  => 0,
			'replaced'    => 0,
			'rolled_back' => 0,
			'failed'      => 0,
		];
		$rows  = $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM {$table} GROUP BY status", ARRAY_A );
		foreach ( (array) $rows as $r ) {
			$key = (string) $r['status'];
			if ( isset( $out[ $key ] ) ) {
				$out[ $key ] = (int) $r['n'];
			}
		}
		return $out;
	}

	/**
	 * Check if the mapping table already knows about a base URL.
	 */
	public function is_known( string $base_url ): bool {
		return null !== $this->find_by_base_url( $base_url );
	}
}
