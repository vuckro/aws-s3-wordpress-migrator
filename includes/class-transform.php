<?php
/**
 * Transform — rule-based bulk editor for the alt_text / derived_title columns
 * of the migration log (and, optionally, their mirrored values on imported
 * attachments).
 *
 * A rule has three parts:
 *   - field     : which column to target (alt or title)
 *   - condition : which rows match  (contains / equals / empty)
 *   - action    : what to do        (set literal / copy from other field /
 *                                    remove substring / clear)
 *
 * Replaces the previous Search_Replace class which only supported substring
 * replacement, and couldn't express the common "set ALT = Title when ALT
 * looks like a placeholder" use case.
 *
 * @package WaasKitS3Migrator
 */

namespace WKS3M;

defined( 'ABSPATH' ) || exit;

class Transform {

	public const FIELD_ALT   = 'alt';
	public const FIELD_TITLE = 'title';

	public const COND_CONTAINS = 'contains';
	public const COND_EQUALS   = 'equals';
	public const COND_EMPTY    = 'empty';

	public const ACTION_SET_LITERAL      = 'set_literal';
	public const ACTION_FROM_TITLE       = 'from_title';
	public const ACTION_FROM_ALT         = 'from_alt';
	public const ACTION_REMOVE_SUBSTRING = 'remove_substring';
	public const ACTION_CLEAR            = 'clear';

	/**
	 * Report how many rows and attachments a rule would affect + a 5-row sample
	 * with real before/after values (no placeholder markers).
	 *
	 * @return array{
	 *     rows:int,
	 *     attachments:int,
	 *     sample: array<int,array{id:int,before:string,after:string}>
	 * }
	 */
	public function preview( array $rule ): array {
		$rule = $this->validate( $rule );
		if ( null === $rule ) {
			return [ 'rows' => 0, 'attachments' => 0, 'sample' => [] ];
		}

		global $wpdb;
		$table  = Activator::table_name();
		$column = $this->column_for( $rule['field'] );

		[ $where_sql, $where_params ] = $this->where_clause( $column, $rule['condition'] );

		$rows = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}", ...$where_params )
		);

		$attachments = 0;
		if ( ! empty( $rule['update_attachments'] ) && $rows > 0 ) {
			$attachments = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$table} WHERE {$where_sql} AND attachment_id IS NOT NULL",
					...$where_params
				)
			);
		}

		// Sample: select the row, compute the "after" value in PHP so the
		// preview matches exactly what apply() will write.
		$samples = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, alt_text, derived_title FROM {$table} WHERE {$where_sql} ORDER BY id ASC LIMIT 5",
				...$where_params
			),
			ARRAY_A
		);
		$sample = [];
		foreach ( (array) $samples as $r ) {
			$before = (string) ( $rule['field'] === self::FIELD_ALT ? $r['alt_text'] : $r['derived_title'] );
			$after  = $this->compute_after(
				$before,
				(string) $r['alt_text'],
				(string) $r['derived_title'],
				$rule['action']
			);
			$sample[] = [ 'id' => (int) $r['id'], 'before' => $before, 'after' => $after ];
		}

		return [ 'rows' => $rows, 'attachments' => $attachments, 'sample' => $sample ];
	}

	/**
	 * Execute the rule. Returns counts updated.
	 *
	 * @return array{rows_updated:int,attachments_updated:int}
	 */
	public function apply( array $rule ): array {
		$rule = $this->validate( $rule );
		if ( null === $rule ) {
			return [ 'rows_updated' => 0, 'attachments_updated' => 0 ];
		}

		global $wpdb;
		$table  = Activator::table_name();
		$column = $this->column_for( $rule['field'] );

		[ $where_sql, $where_params ] = $this->where_clause( $column, $rule['condition'] );
		[ $set_sql, $set_params ]     = $this->set_clause( $column, $rule['action'] );

		$sql    = "UPDATE {$table} SET {$set_sql} WHERE {$where_sql}";
		$params = array_merge( $set_params, $where_params );

		$rows_updated        = (int) $wpdb->query( $params ? $wpdb->prepare( $sql, ...$params ) : $sql );
		$attachments_updated = 0;

		if ( ! empty( $rule['update_attachments'] ) ) {
			$attachments_updated = $this->propagate_to_attachments( $rule, $where_sql, $where_params );
		}

		Logger::info( 'Transform applied', [
			'rule'    => $rule,
			'rows'    => $rows_updated,
			'attach'  => $attachments_updated,
		] );

		return [
			'rows_updated'        => $rows_updated,
			'attachments_updated' => $attachments_updated,
		];
	}

	/* ------------ internals ------------ */

	private function column_for( string $field ): string {
		return self::FIELD_ALT === $field ? 'alt_text' : 'derived_title';
	}

	/**
	 * @return array{0:string,1:array<int,string>}
	 */
	private function where_clause( string $column, array $condition ): array {
		global $wpdb;
		$value = (string) ( $condition['value'] ?? '' );
		return match ( $condition['type'] ) {
			self::COND_CONTAINS => [ "{$column} LIKE %s", [ '%' . $wpdb->esc_like( $value ) . '%' ] ],
			self::COND_EQUALS   => [ "{$column} = %s", [ $value ] ],
			self::COND_EMPTY    => [ "({$column} IS NULL OR {$column} = '')", [] ],
			default             => [ '1=0', [] ],
		};
	}

	/**
	 * @return array{0:string,1:array<int,string>}
	 */
	private function set_clause( string $column, array $action ): array {
		$value = (string) ( $action['value'] ?? '' );
		return match ( $action['type'] ) {
			self::ACTION_SET_LITERAL      => [ "{$column} = %s", [ $value ] ],
			self::ACTION_CLEAR            => [ "{$column} = ''", [] ],
			self::ACTION_FROM_TITLE       => [ "{$column} = derived_title", [] ],
			self::ACTION_FROM_ALT         => [ "{$column} = alt_text", [] ],
			self::ACTION_REMOVE_SUBSTRING => [ "{$column} = REPLACE({$column}, %s, '')", [ $value ] ],
			default                       => [ "{$column} = {$column}", [] ],
		};
	}

	/** Simulate the action in PHP (kept in sync with set_clause). */
	private function compute_after( string $before, string $alt_text, string $derived_title, array $action ): string {
		$value = (string) ( $action['value'] ?? '' );
		return match ( $action['type'] ) {
			self::ACTION_SET_LITERAL      => $value,
			self::ACTION_CLEAR            => '',
			self::ACTION_FROM_TITLE       => $derived_title,
			self::ACTION_FROM_ALT         => $alt_text,
			self::ACTION_REMOVE_SUBSTRING => str_replace( $value, '', $before ),
			default                       => $before,
		};
	}

	/**
	 * Mirror the rule onto the corresponding WordPress data:
	 *  - alt_text → postmeta _wp_attachment_image_alt
	 *  - derived_title → wp_posts.post_title (attachment)
	 *
	 * We re-select the affected ids and re-apply the action in SQL against the
	 * target table. This keeps the log table and the WP data strictly in sync.
	 */
	private function propagate_to_attachments( array $rule, string $where_sql, array $where_params ): int {
		global $wpdb;
		$table = Activator::table_name();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT attachment_id, alt_text, derived_title FROM {$table}
				 WHERE {$where_sql} AND attachment_id IS NOT NULL",
				...$where_params
			),
			ARRAY_A
		);

		$count = 0;
		foreach ( (array) $rows as $r ) {
			$attach_id = (int) $r['attachment_id'];
			if ( ! $attach_id ) {
				continue;
			}
			// Determine the new value from the freshly-updated log row.
			$current_source_before = self::FIELD_ALT === $rule['field'] ? (string) $r['alt_text'] : (string) $r['derived_title'];
			$new_value             = $this->compute_after(
				$current_source_before,
				(string) $r['alt_text'],
				(string) $r['derived_title'],
				$rule['action']
			);

			if ( self::FIELD_ALT === $rule['field'] ) {
				update_post_meta( $attach_id, '_wp_attachment_image_alt', $new_value );
			} else {
				wp_update_post( [ 'ID' => $attach_id, 'post_title' => $new_value ] );
			}
			$count++;
		}
		return $count;
	}

	/**
	 * Normalize + validate an incoming rule.
	 *
	 * @return array{field:string,condition:array,action:array,update_attachments:bool}|null
	 */
	private function validate( array $rule ): ?array {
		$field = (string) ( $rule['field'] ?? '' );
		if ( ! in_array( $field, [ self::FIELD_ALT, self::FIELD_TITLE ], true ) ) {
			return null;
		}

		$cond_type = (string) ( $rule['condition']['type'] ?? '' );
		if ( ! in_array( $cond_type, [ self::COND_CONTAINS, self::COND_EQUALS, self::COND_EMPTY ], true ) ) {
			return null;
		}
		$cond_value = (string) ( $rule['condition']['value'] ?? '' );
		if ( in_array( $cond_type, [ self::COND_CONTAINS, self::COND_EQUALS ], true ) && '' === $cond_value ) {
			return null;
		}

		$action_type = (string) ( $rule['action']['type'] ?? '' );
		$valid_act   = [
			self::ACTION_SET_LITERAL,
			self::ACTION_FROM_TITLE,
			self::ACTION_FROM_ALT,
			self::ACTION_REMOVE_SUBSTRING,
			self::ACTION_CLEAR,
		];
		if ( ! in_array( $action_type, $valid_act, true ) ) {
			return null;
		}
		$action_value = (string) ( $rule['action']['value'] ?? '' );
		if ( in_array( $action_type, [ self::ACTION_SET_LITERAL, self::ACTION_REMOVE_SUBSTRING ], true ) && '' === $action_value && self::ACTION_SET_LITERAL !== $action_type ) {
			// set_literal with empty value == clear, allowed; remove_substring with empty value is a no-op.
			return null;
		}

		return [
			'field'              => $field,
			'condition'          => [ 'type' => $cond_type, 'value' => $cond_value ],
			'action'             => [ 'type' => $action_type, 'value' => $action_value ],
			'update_attachments' => ! empty( $rule['update_attachments'] ),
		];
	}
}
