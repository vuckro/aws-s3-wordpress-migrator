<?php
/**
 * Scanner — finds external image URLs across wp_posts and persists results.
 *
 * Phase 2: still read-only on the host site, but now writes to the migration
 * log table (upsert) so the Queue tab can paginate results without replaying
 * the full scan.
 *
 * @package WaasKitS3Migrator
 */

namespace WKS3M;

defined( 'ABSPATH' ) || exit;

class Scanner {

	/**
	 * Matches any http(s) URL that ends with a known image extension,
	 * optionally followed by a query string. Handles JSON-escaped slashes.
	 */
	public static function url_regex(): string {
		$ext = implode( '|', array_map( 'preg_quote', Settings::IMAGE_EXTENSIONS ) );
		return '#https?:(?:\\\\?/){2}[^\s"\'<>)\]\\\\]+?\.(?:' . $ext . ')(?:\?[^\s"\'<>)\]\\\\]*)?#i';
	}

	/**
	 * Turn any captured URL string into a canonical form:
	 *   - unescape JSON `\/` → `/`
	 *   - drop trailing punctuation sometimes captured inside serialized JSON
	 */
	public static function normalize_url( string $url ): string {
		$url = str_replace( '\\/', '/', $url );
		$url = rtrim( $url, '\\",;:)' );
		return $url;
	}

	/**
	 * Scan a single content string and return distinct, canonicalized URLs.
	 *
	 * @return string[]
	 */
	public function extract_urls( string $content ): array {
		if ( '' === $content ) {
			return [];
		}
		if ( ! preg_match_all( self::url_regex(), $content, $m ) ) {
			return [];
		}
		if ( empty( $m[0] ) ) {
			return [];
		}
		$hosts_filter = Settings::source_hosts();
		$auto_detect  = Settings::auto_detect_external();
		$site_host    = Settings::site_host();

		$out = [];
		foreach ( $m[0] as $url ) {
			$url  = self::normalize_url( $url );
			$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
			if ( '' === $host ) {
				continue;
			}
			if ( ! empty( $hosts_filter ) ) {
				if ( ! in_array( $host, $hosts_filter, true ) ) {
					continue;
				}
			} elseif ( $auto_detect ) {
				if ( $host === $site_host ) {
					continue;
				}
			} else {
				return [];
			}
			$out[ $url ] = true;
		}
		return array_keys( $out );
	}

	/**
	 * Given a canonical URL, return its base filename (optionally stripping
	 * Strapi size prefixes).
	 */
	public static function base_key( string $url ): string {
		$path     = (string) wp_parse_url( $url, PHP_URL_PATH );
		$filename = basename( $path );
		if ( Settings::strip_strapi_prefixes() ) {
			foreach ( Settings::STRAPI_SIZE_PREFIXES as $prefix ) {
				if ( 0 === strpos( $filename, $prefix ) ) {
					$filename = substr( $filename, strlen( $prefix ) );
					break;
				}
			}
		}
		return $filename;
	}

	public static function composite_key( string $url ): string {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		return $host . '|' . self::base_key( $url );
	}

	private function like_patterns(): array {
		global $wpdb;
		$hosts = Settings::source_hosts();
		if ( ! empty( $hosts ) ) {
			return array_map(
				static fn( string $h ): string => '%' . $wpdb->esc_like( $h ) . '%',
				$hosts
			);
		}
		$patterns = [];
		foreach ( Settings::IMAGE_EXTENSIONS as $ext ) {
			$patterns[] = '%.' . $wpdb->esc_like( $ext ) . '%';
		}
		return $patterns;
	}

	/**
	 * Scan a batch of posts. Persists results to the migration log (upsert).
	 */
	public function scan_posts_batch( int $offset = 0, int $limit = 100 ): array {
		global $wpdb;

		$patterns = $this->like_patterns();
		$where    = implode( ' OR ', array_fill( 0, count( $patterns ), 'post_content LIKE %s' ) );

		$total_sql = "SELECT COUNT(*) FROM {$wpdb->posts}
			WHERE post_status IN ('publish','draft','pending','private','future')
			AND post_type NOT IN ('revision','attachment')
			AND ({$where})";
		$total     = (int) $wpdb->get_var( $wpdb->prepare( $total_sql, ...$patterns ) );

		$select_sql = "SELECT ID, post_content FROM {$wpdb->posts}
			WHERE post_status IN ('publish','draft','pending','private','future')
			AND post_type NOT IN ('revision','attachment')
			AND ({$where})
			ORDER BY ID ASC
			LIMIT %d OFFSET %d";
		$rows       = $wpdb->get_results(
			$wpdb->prepare( $select_sql, ...array_merge( $patterns, [ $limit, $offset ] ) ),
			ARRAY_A
		);

		$matches = [];
		foreach ( (array) $rows as $row ) {
			$urls    = $this->extract_urls( (string) $row['post_content'] );
			$content = (string) $row['post_content'];
			foreach ( $urls as $u ) {
				$key = self::composite_key( $u );
				if ( '|' === $key || '' === $key ) {
					continue;
				}
				$matches[ $key ] ??= [
					'variants'       => [],
					'post_ids'       => [],
					'host'           => strtolower( (string) wp_parse_url( $u, PHP_URL_HOST ) ),
					'base_key'       => self::base_key( $u ),
					'contents_seen'  => [],
				];
				if ( ! in_array( $u, $matches[ $key ]['variants'], true ) ) {
					$matches[ $key ]['variants'][] = $u;
				}
				$pid = (int) $row['ID'];
				if ( ! in_array( $pid, $matches[ $key ]['post_ids'], true ) ) {
					$matches[ $key ]['post_ids'][]      = $pid;
					$matches[ $key ]['contents_seen'][] = $content;
				}
			}
		}

		$urls_found = 0;
		foreach ( $matches as $key => &$m ) {
			$urls_found                += count( $m['variants'] );
			// Extract alt from the first content that referenced this image.
			$m['alt_text']      = Metadata_Extractor::extract_alt( $m['contents_seen'][0] ?? '', $m['variants'] );
			$m['derived_title'] = Metadata_Extractor::derive_title( $m['base_key'] );
			unset( $m['contents_seen'] );
			$this->upsert_result( $key, $m );
		}
		unset( $m );

		return [
			'processed'       => count( (array) $rows ),
			'total'           => $total,
			'next_offset'     => $offset + count( (array) $rows ),
			'urls_found'      => (int) $urls_found,
			'base_keys_found' => count( $matches ),
			'matches'         => $matches, // kept for backwards-compat with the scan tab counters
		];
	}

	/**
	 * Upsert a single scan result into the migration log.
	 */
	private function upsert_result( string $key, array $data ): void {
		global $wpdb;
		$table = Activator::table_name();

		$existing = $wpdb->get_row(
			$wpdb->prepare( "SELECT id, status, alt_text FROM {$table} WHERE source_url_base = %s", $key ),
			ARRAY_A
		);

		$now      = current_time( 'mysql' );
		$variants = wp_json_encode( array_values( $data['variants'] ) );
		$posts    = wp_json_encode( array_values( $data['post_ids'] ) );

		if ( $existing ) {
			// Don't overwrite an imported row's metadata blindly.
			$payload = [
				'source_url_variants' => $variants,
				'post_ids'            => $posts,
				'last_seen_at'        => $now,
				'source_host'         => $data['host'],
				'base_key'            => $data['base_key'],
			];
			if ( empty( $existing['alt_text'] ) && ! empty( $data['alt_text'] ) ) {
				$payload['alt_text'] = $data['alt_text'];
			}
			if ( empty( $existing['alt_text'] ) || 'pending' === $existing['status'] ) {
				$payload['derived_title'] = $data['derived_title'];
			}
			$wpdb->update( $table, $payload, [ 'id' => (int) $existing['id'] ] );
			return;
		}

		$wpdb->insert(
			$table,
			[
				'source_url_base'     => $key,
				'source_host'         => $data['host'],
				'base_key'            => $data['base_key'],
				'source_url_variants' => $variants,
				'post_ids'            => $posts,
				'alt_text'            => $data['alt_text'],
				'derived_title'       => $data['derived_title'],
				'status'              => 'pending',
				'last_seen_at'        => $now,
				'created_at'          => $now,
			]
		);
	}

	public function count_secondary_sources(): array {
		global $wpdb;
		$patterns = $this->like_patterns();
		$where_pm = implode( ' OR ', array_fill( 0, count( $patterns ), 'meta_value LIKE %s' ) );
		$where_op = implode( ' OR ', array_fill( 0, count( $patterns ), 'option_value LIKE %s' ) );

		$postmeta = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE {$where_pm}", ...$patterns )
		);
		$options  = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE {$where_op}", ...$patterns )
		);

		return [ 'postmeta' => $postmeta, 'options' => $options ];
	}
}
