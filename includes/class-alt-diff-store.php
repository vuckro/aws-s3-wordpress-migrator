<?php
/**
 * Alt_Diff_Store — read/write access to the wks3m_alt_diff table.
 *
 * @package WaasKitS3Migrator
 */

namespace WKS3M;

defined( 'ABSPATH' ) || exit;

class Alt_Diff_Store {

	public function table(): string {
		return Activator::alt_diff_table_name();
	}

	public function get( int $id ): ?Alt_Diff {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$this->table()} WHERE id = %d", $id ),
			ARRAY_A
		);
		return Alt_Diff::from_array( $row ?: null );
	}

	/**
	 * Upsert a divergence. If a row exists for (post_id, src):
	 *   - with status='diff', refresh content_alt/library_alt/scanned_at
	 *   - with status='applied'/'rolled_back', leave untouched (historical)
	 *   - if content matches library now (re-synced elsewhere), caller should
	 *     call resolve() instead — not this method.
	 */
	public function upsert_diff(
		int $post_id,
		int $attachment_id,
		string $src,
		string $content_alt,
		string $library_alt
	): int {
		global $wpdb;
		$table = $this->table();
		$now   = current_time( 'mysql' );

		$existing = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, status FROM {$table} WHERE post_id = %d AND src = %s",
				$post_id,
				$src
			),
			ARRAY_A
		);

		if ( $existing ) {
			if ( 'diff' === $existing['status'] ) {
				$wpdb->update(
					$table,
					[
						'attachment_id' => $attachment_id,
						'content_alt'   => $content_alt,
						'library_alt'   => $library_alt,
						'scanned_at'    => $now,
					],
					[ 'id' => (int) $existing['id'] ]
				);
			}
			return (int) $existing['id'];
		}

		$wpdb->insert(
			$table,
			[
				'post_id'       => $post_id,
				'attachment_id' => $attachment_id,
				'src'           => $src,
				'content_alt'   => $content_alt,
				'library_alt'   => $library_alt,
				'status'        => 'diff',
				'scanned_at'    => $now,
			]
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Remove stale 'diff' rows for this post whose (src) is not in the list of
	 * currently-divergent srcs we just observed. Keeps the table clean when a
	 * diff resolves itself (e.g. the user re-inserted the block in Gutenberg).
	 *
	 * @param string[] $current_srcs Srcs still divergent after this scan.
	 */
	public function purge_resolved_for_post( int $post_id, array $current_srcs ): int {
		global $wpdb;
		$table = $this->table();
		if ( empty( $current_srcs ) ) {
			return (int) $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$table} WHERE post_id = %d AND status = 'diff'",
					$post_id
				)
			);
		}
		$placeholders = implode( ',', array_fill( 0, count( $current_srcs ), '%s' ) );
		return (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table}
				 WHERE post_id = %d AND status = 'diff' AND src NOT IN ({$placeholders})",
				array_merge( [ $post_id ], $current_srcs )
			)
		);
	}

	public function mark_applied( int $id ): void {
		global $wpdb;
		$wpdb->update(
			$this->table(),
			[
				'status'        => 'applied',
				'applied_at'    => current_time( 'mysql' ),
				'error_message' => null,
			],
			[ 'id' => $id ]
		);
	}

	public function mark_rolled_back( int $id ): void {
		global $wpdb;
		$wpdb->update(
			$this->table(),
			[
				'status'         => 'rolled_back',
				'rolled_back_at' => current_time( 'mysql' ),
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

	/**
	 * @return int[]
	 */
	public function ids_by_status( string $status, int $limit = 20000 ): array {
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

	/** @return array<string,int> */
	public function counts_by_status(): array {
		global $wpdb;
		$out = [ 'diff' => 0, 'applied' => 0, 'rolled_back' => 0, 'failed' => 0 ];
		foreach ( (array) $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM {$this->table()} GROUP BY status", ARRAY_A ) as $r ) {
			$key = (string) $r['status'];
			if ( isset( $out[ $key ] ) ) {
				$out[ $key ] = (int) $r['n'];
			}
		}
		return $out;
	}

	/**
	 * Paginated fetch for the Synchro ALT tab.
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
		if ( ! empty( $args['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
			$where[]  = '(src LIKE %s OR content_alt LIKE %s OR library_alt LIKE %s)';
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
}
