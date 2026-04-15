<?php
/**
 * Mapping_Store — read/write access to the migration log table.
 *
 * Returns Migration_Row value objects for single-row lookups so callers don't
 * have to juggle raw arrays.
 *
 * @package WaasKitS3Migrator
 */

namespace WKS3M;

defined( 'ABSPATH' ) || exit;

class Mapping_Store {

	public function table(): string {
		return Activator::table_name();
	}

	/** @return Migration_Row|null */
	public function get( int $id ): ?Migration_Row {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$this->table()} WHERE id = %d", $id ),
			ARRAY_A
		);
		return Migration_Row::from_array( $row ?: null );
	}

	public function find_by_base_url( string $base_url ): ?Migration_Row {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$this->table()} WHERE source_url_base = %s LIMIT 1", $base_url ),
			ARRAY_A
		);
		return Migration_Row::from_array( $row ?: null );
	}

	public function is_known( string $base_url ): bool {
		return null !== $this->find_by_base_url( $base_url );
	}

	/**
	 * Paginated fetch for the Queue / History tables.
	 *
	 * @return array{items:array[],total:int,pages:int,page:int,per_page:int}
	 */
	public function list( array $args = [] ): array {
		global $wpdb;
		$table = $this->table();

		$per_page = max( 1, min( 200, (int) ( $args['per_page'] ?? 25 ) ) );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$offset   = ( $page - 1 ) * $per_page;

		$where  = [];
		$params = [];
		if ( ! empty( $args['status'] ) ) {
			$where[]  = 'status = %s';
			$params[] = (string) $args['status'];
		}
		if ( ! empty( $args['host'] ) ) {
			$where[]  = 'source_host = %s';
			$params[] = (string) $args['host'];
		}
		if ( ! empty( $args['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
			$where[]  = '(base_key LIKE %s OR derived_title LIKE %s OR alt_text LIKE %s)';
			array_push( $params, $like, $like, $like );
		}
		$where_sql = $where ? ( 'WHERE ' . implode( ' AND ', $where ) ) : '';

		$total = $params
			? (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} {$where_sql}", ...$params ) )
			: (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} {$where_sql}" );

		$items = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d",
				...array_merge( $params, [ $per_page, $offset ] )
			),
			ARRAY_A
		);

		return [
			'items'    => $items ?: [],
			'total'    => $total,
			'pages'    => (int) ceil( $total / $per_page ),
			'page'     => $page,
			'per_page' => $per_page,
		];
	}

	public function distinct_hosts(): array {
		global $wpdb;
		$rows = $wpdb->get_col(
			"SELECT DISTINCT source_host FROM {$this->table()}
			 WHERE source_host IS NOT NULL ORDER BY source_host ASC"
		);
		return array_values( array_filter( (array) $rows ) );
	}

	/** @return array<string,int> */
	public function counts_by_status(): array {
		global $wpdb;
		$out = [ 'pending' => 0, 'imported' => 0, 'replaced' => 0, 'rolled_back' => 0, 'failed' => 0 ];
		foreach ( (array) $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM {$this->table()} GROUP BY status", ARRAY_A ) as $r ) {
			$key = (string) $r['status'];
			if ( isset( $out[ $key ] ) ) {
				$out[ $key ] = (int) $r['n'];
			}
		}
		return $out;
	}

	/** @return int[] */
	public function ids_by_status( string $status, int $limit = 100 ): array {
		global $wpdb;
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$this->table()} WHERE status = %s ORDER BY id ASC LIMIT %d",
				$status,
				max( 1, $limit )
			)
		);
		return array_map( 'intval', (array) $rows );
	}

	public function mark_imported( int $id, int $attachment_id ): void {
		global $wpdb;
		$wpdb->update(
			$this->table(),
			[
				'status'        => 'imported',
				'attachment_id' => $attachment_id,
				'error_message' => null,
			],
			[ 'id' => $id ]
		);
	}

	public function mark_replaced( int $id ): void {
		global $wpdb;
		$wpdb->update(
			$this->table(),
			[
				'status'      => 'replaced',
				'replaced_at' => current_time( 'mysql' ),
			],
			[ 'id' => $id ]
		);
	}

	public function mark_failed( int $id, string $error ): void {
		global $wpdb;
		$wpdb->update(
			$this->table(),
			[
				'status'        => 'failed',
				'error_message' => $error,
			],
			[ 'id' => $id ]
		);
	}

	public function mark_rolled_back( int $id, bool $attachment_deleted ): void {
		global $wpdb;
		$payload = [
			'status'         => 'rolled_back',
			'rolled_back_at' => current_time( 'mysql' ),
		];
		if ( $attachment_deleted ) {
			$payload['attachment_id'] = null;
		}
		$wpdb->update( $this->table(), $payload, [ 'id' => $id ] );
	}
}
