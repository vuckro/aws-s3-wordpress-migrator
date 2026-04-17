<?php
/**
 * Alt_History_Store — append-only log of successful ALT syncs.
 *
 * One row per apply. Purgable from Settings → Nettoyer les données.
 *
 * @package WaasKitS3Migrator
 */

namespace WKS3M;

defined( 'ABSPATH' ) || exit;

class Alt_History_Store {

	public function table(): string {
		return Activator::alt_history_table_name();
	}

	public function log(
		int $post_id,
		int $attachment_id,
		string $src,
		string $old_alt,
		string $new_alt
	): void {
		global $wpdb;
		$wpdb->insert(
			$this->table(),
			[
				'post_id'       => $post_id,
				'attachment_id' => $attachment_id,
				'src'           => $src,
				'old_alt'       => $old_alt,
				'new_alt'       => $new_alt,
				'applied_at'    => current_time( 'mysql' ),
			]
		);
	}

	public function count(): int {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table()}" );
	}

	/**
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
		if ( ! empty( $args['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
			$where[]  = '(src LIKE %s OR old_alt LIKE %s OR new_alt LIKE %s)';
			array_push( $params, $like, $like, $like );
		}
		$where_sql = $where ? ( 'WHERE ' . implode( ' AND ', $where ) ) : '';

		$total = $params
			? (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} {$where_sql}", ...$params ) )
			: (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} {$where_sql}" );

		$items = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} {$where_sql} ORDER BY applied_at DESC, id DESC LIMIT %d OFFSET %d",
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

	public function purge(): int {
		global $wpdb;
		return (int) $wpdb->query( 'TRUNCATE TABLE ' . $this->table() );
	}
}
