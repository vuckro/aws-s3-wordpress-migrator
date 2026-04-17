<?php
/**
 * Alt_Diff_Store — read/write access to the wks3m_alt_diff table.
 *
 * The table holds only rows that represent *pending* divergences. On apply
 * success a row is deleted. On apply failure error_message is filled and
 * the row stays visible in the UI so the user can triage.
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
	 * Insert or refresh a divergence for a (post_id, src) pair. Clears any
	 * prior error_message when refreshing.
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

		$existing_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE post_id = %d AND src = %s",
				$post_id,
				$src
			)
		);

		if ( $existing_id > 0 ) {
			$wpdb->update(
				$table,
				[
					'attachment_id' => $attachment_id,
					'content_alt'   => $content_alt,
					'library_alt'   => $library_alt,
					'scanned_at'    => $now,
					'error_message' => null,
				],
				[ 'id' => $existing_id ]
			);
			return $existing_id;
		}

		$wpdb->insert(
			$table,
			[
				'post_id'       => $post_id,
				'attachment_id' => $attachment_id,
				'src'           => $src,
				'content_alt'   => $content_alt,
				'library_alt'   => $library_alt,
				'scanned_at'    => $now,
			]
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Remove rows for (post_id, src) pairs that are no longer divergent.
	 *
	 * @param string[] $current_srcs Srcs still divergent after this scan pass.
	 */
	public function purge_resolved_for_post( int $post_id, array $current_srcs ): int {
		global $wpdb;
		$table = $this->table();
		if ( empty( $current_srcs ) ) {
			return (int) $wpdb->query(
				$wpdb->prepare( "DELETE FROM {$table} WHERE post_id = %d", $post_id )
			);
		}
		$placeholders = implode( ',', array_fill( 0, count( $current_srcs ), '%s' ) );
		return (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE post_id = %d AND src NOT IN ({$placeholders})",
				array_merge( [ $post_id ], $current_srcs )
			)
		);
	}

	public function delete( int $id ): void {
		global $wpdb;
		$wpdb->delete( $this->table(), [ 'id' => $id ], [ '%d' ] );
	}

	public function mark_failed( int $id, string $error ): void {
		global $wpdb;
		$wpdb->update(
			$this->table(),
			[ 'error_message' => $error ],
			[ 'id' => $id ]
		);
	}

	/** @return int[] */
	public function pending_ids( int $limit = 20000 ): array {
		global $wpdb;
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$this->table()}
				 WHERE error_message IS NULL OR error_message = ''
				 ORDER BY id ASC
				 LIMIT %d",
				max( 1, $limit )
			)
		);
		return array_map( 'intval', (array) $rows );
	}

	/** @return array{total:int,errors:int} */
	public function counts(): array {
		global $wpdb;
		$table = $this->table();
		$total  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		$errors = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$table} WHERE error_message IS NOT NULL AND error_message <> ''"
		);
		return [ 'total' => $total, 'errors' => $errors ];
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

		if ( ! empty( $args['errors_only'] ) ) {
			$where[] = "error_message IS NOT NULL AND error_message <> ''";
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
