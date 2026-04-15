<?php
/**
 * Mapping store — read/write access to the migration log table.
 *
 * @package WaasKitS3Migrator
 */

namespace WKS3M;

defined( 'ABSPATH' ) || exit;

class Mapping_Store {

	public function table(): string {
		return Activator::table_name();
	}

	public function find_by_base_url( string $base_url ): ?array {
		global $wpdb;
		$table = $this->table();
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE source_url_base = %s LIMIT 1", $base_url ),
			ARRAY_A
		);
		return $row ?: null;
	}

	public function find_by_id( int $id ): ?array {
		global $wpdb;
		$table = $this->table();
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ),
			ARRAY_A
		);
		return $row ?: null;
	}

	public function is_known( string $base_url ): bool {
		return null !== $this->find_by_base_url( $base_url );
	}

	/**
	 * Paginated fetch for the Queue tab.
	 *
	 * @param array{status?:string,host?:string,search?:string,per_page?:int,page?:int} $args
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
			$params[] = $like; $params[] = $like; $params[] = $like;
		}
		$where_sql = $where ? ( 'WHERE ' . implode( ' AND ', $where ) ) : '';

		$total_sql = "SELECT COUNT(*) FROM {$table} {$where_sql}";
		$total     = $params
			? (int) $wpdb->get_var( $wpdb->prepare( $total_sql, ...$params ) )
			: (int) $wpdb->get_var( $total_sql );

		$list_sql = "SELECT * FROM {$table} {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d";
		$items    = $wpdb->get_results(
			$wpdb->prepare( $list_sql, ...array_merge( $params, [ $per_page, $offset ] ) ),
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
		$table = $this->table();
		$rows  = $wpdb->get_col( "SELECT DISTINCT source_host FROM {$table} WHERE source_host IS NOT NULL ORDER BY source_host ASC" );
		return array_values( array_filter( (array) $rows ) );
	}

	public function counts_by_status(): array {
		global $wpdb;
		$table = $this->table();
		$out   = [
			'pending'     => 0,
			'imported'    => 0,
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

	public function mark_imported( int $id, int $attachment_id ): void {
		global $wpdb;
		$wpdb->update(
			$this->table(),
			[
				'status'        => 'imported',
				'attachment_id' => $attachment_id,
				'error_message' => null,
				'replaced_at'   => null,
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

	/**
	 * Return IDs of rows in a given status, oldest first.
	 *
	 * @return int[]
	 */
	public function ids_by_status( string $status, int $limit = 100 ): array {
		global $wpdb;
		$table = $this->table();
		$rows  = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE status = %s ORDER BY id ASC LIMIT %d",
				$status,
				max( 1, $limit )
			)
		);
		return array_map( 'intval', (array) $rows );
	}
}
