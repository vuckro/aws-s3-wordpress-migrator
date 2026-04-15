<?php
/**
 * Search & Replace — batch clean-up of alt_text / derived_title in the
 * migration log, with optional propagation to already-imported attachments.
 *
 * @package WaasKitS3Migrator
 */

namespace WKS3M;

defined( 'ABSPATH' ) || exit;

class Search_Replace {

	public const FIELD_ALT   = 'alt';
	public const FIELD_TITLE = 'title';

	/** Fields allowed as scope values. */
	private const ALLOWED_FIELDS = [ self::FIELD_ALT, self::FIELD_TITLE ];

	/**
	 * Count rows that would be affected — no writes.
	 *
	 * @param string[] $fields One or more of FIELD_ALT / FIELD_TITLE.
	 *
	 * @return array{
	 *     rows:int,
	 *     alt_rows:int,
	 *     title_rows:int,
	 *     attachments:int,
	 *     sample: array<int,array{id:int,before:string,after:string,field:string}>
	 * }
	 */
	public function preview( string $find, array $fields, bool $update_attachments = true ): array {
		$fields = $this->sanitize_fields( $fields );
		if ( '' === $find || empty( $fields ) ) {
			return [ 'rows' => 0, 'alt_rows' => 0, 'title_rows' => 0, 'attachments' => 0, 'sample' => [] ];
		}

		global $wpdb;
		$table = Activator::table_name();
		$like  = '%' . $wpdb->esc_like( $find ) . '%';

		$alt_rows   = in_array( self::FIELD_ALT, $fields, true )
			? (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE alt_text LIKE %s", $like ) )
			: 0;
		$title_rows = in_array( self::FIELD_TITLE, $fields, true )
			? (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE derived_title LIKE %s", $like ) )
			: 0;

		$where_or = [];
		if ( in_array( self::FIELD_ALT, $fields, true ) ) {
			$where_or[] = $wpdb->prepare( 'alt_text LIKE %s', $like );
		}
		if ( in_array( self::FIELD_TITLE, $fields, true ) ) {
			$where_or[] = $wpdb->prepare( 'derived_title LIKE %s', $like );
		}
		$rows = (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT id) FROM {$table} WHERE " . implode( ' OR ', $where_or )
		);

		$attachments = 0;
		if ( $update_attachments && in_array( self::FIELD_ALT, $fields, true ) ) {
			$attachments = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(DISTINCT pm.post_id)
					 FROM {$wpdb->postmeta} pm
					 INNER JOIN {$table} l ON l.attachment_id = pm.post_id
					 WHERE pm.meta_key = '_wp_attachment_image_alt'
					 AND pm.meta_value LIKE %s",
					$like
				)
			);
		}

		// Pull a short sample for UI reassurance.
		$sample_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, alt_text, derived_title FROM {$table}
				 WHERE " . implode( ' OR ', $where_or ) . "
				 ORDER BY id ASC LIMIT 5"
			),
			ARRAY_A
		);
		$sample = [];
		foreach ( (array) $sample_rows as $r ) {
			if ( in_array( self::FIELD_ALT, $fields, true ) && false !== strpos( (string) $r['alt_text'], $find ) ) {
				$sample[] = [
					'id'     => (int) $r['id'],
					'field'  => self::FIELD_ALT,
					'before' => (string) $r['alt_text'],
					'after'  => str_replace( $find, '⟶REPLACE⟵', (string) $r['alt_text'] ),
				];
			}
			if ( in_array( self::FIELD_TITLE, $fields, true ) && false !== strpos( (string) $r['derived_title'], $find ) ) {
				$sample[] = [
					'id'     => (int) $r['id'],
					'field'  => self::FIELD_TITLE,
					'before' => (string) $r['derived_title'],
					'after'  => str_replace( $find, '⟶REPLACE⟵', (string) $r['derived_title'] ),
				];
			}
		}

		return [
			'rows'        => $rows,
			'alt_rows'    => $alt_rows,
			'title_rows'  => $title_rows,
			'attachments' => $attachments,
			'sample'      => $sample,
		];
	}

	/**
	 * Apply the replacement. Returns the number of rows/attachments updated.
	 *
	 * @return array{rows_updated:int,attachments_updated:int}
	 */
	public function apply( string $find, string $replace, array $fields, bool $update_attachments = true ): array {
		$fields = $this->sanitize_fields( $fields );
		if ( '' === $find || empty( $fields ) ) {
			return [ 'rows_updated' => 0, 'attachments_updated' => 0 ];
		}

		global $wpdb;
		$table = Activator::table_name();
		$like  = '%' . $wpdb->esc_like( $find ) . '%';

		$rows_updated = 0;
		if ( in_array( self::FIELD_ALT, $fields, true ) ) {
			$rows_updated += (int) $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET alt_text = REPLACE(alt_text, %s, %s) WHERE alt_text LIKE %s",
					$find,
					$replace,
					$like
				)
			);
		}
		if ( in_array( self::FIELD_TITLE, $fields, true ) ) {
			$rows_updated += (int) $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET derived_title = REPLACE(derived_title, %s, %s) WHERE derived_title LIKE %s",
					$find,
					$replace,
					$like
				)
			);
		}

		$attachments_updated = 0;
		if ( $update_attachments && in_array( self::FIELD_ALT, $fields, true ) ) {
			$attachments_updated = (int) $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->postmeta} pm
					 INNER JOIN {$table} l ON l.attachment_id = pm.post_id
					 SET pm.meta_value = REPLACE(pm.meta_value, %s, %s)
					 WHERE pm.meta_key = '_wp_attachment_image_alt'
					 AND pm.meta_value LIKE %s",
					$find,
					$replace,
					$like
				)
			);
		}

		Logger::info( 'Search & Replace applied', [
			'find'    => $find,
			'replace' => $replace,
			'fields'  => $fields,
			'rows'    => $rows_updated,
			'attach'  => $attachments_updated,
		] );

		return [
			'rows_updated'        => $rows_updated,
			'attachments_updated' => $attachments_updated,
		];
	}

	private function sanitize_fields( array $fields ): array {
		return array_values( array_intersect( $fields, self::ALLOWED_FIELDS ) );
	}
}
