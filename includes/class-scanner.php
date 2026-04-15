<?php
/**
 * Scanner — finds external image URLs across wp_posts, wp_postmeta, wp_options.
 *
 * Detection strategy:
 *   - Match any http(s) URL ending in a known image extension
 *     (jpg, jpeg, png, gif, webp, svg, avif), optionally with a query string.
 *   - If user provided explicit source hosts (Settings::source_hosts), keep only
 *     URLs whose host is in that list.
 *   - Otherwise, if auto-detect is enabled, keep any URL whose host ≠ site host.
 *
 * Phase 1: read-only. No writes, no side effects.
 *
 * @package WaasKitS3Migrator
 */

namespace WKS3M;

defined( 'ABSPATH' ) || exit;

class Scanner {

	/**
	 * Matches any http(s) URL that ends with a known image extension,
	 * optionally followed by a query string.
	 */
	public static function url_regex(): string {
		$ext = implode( '|', array_map( 'preg_quote', Settings::IMAGE_EXTENSIONS ) );
		return '#https?://[^\s"\'<>)\]]+?\.(?:' . $ext . ')(?:\?[^\s"\'<>)\]]*)?#i';
	}

	/**
	 * Scan a single content string and return distinct external image URLs.
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
			$url  = rtrim( $url, '\\",;:)' );
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
				// No hosts configured and auto-detect disabled → nothing to scan.
				return [];
			}
			$out[ $url ] = true;
		}
		return array_keys( $out );
	}

	/**
	 * Given a full image URL, return its base filename (optionally stripping
	 * Strapi size prefixes large_/medium_/small_/thumbnail_).
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

	/**
	 * Host + base filename — ensures two different CDNs hosting the same
	 * filename don't collide.
	 */
	public static function composite_key( string $url ): string {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		return $host . '|' . self::base_key( $url );
	}

	/**
	 * Build the SQL LIKE patterns used to pre-filter candidate posts.
	 *
	 * @return string[]
	 */
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
	 * Scan a batch of posts by offset/limit.
	 *
	 * @return array{
	 *     processed:int,
	 *     total:int,
	 *     next_offset:int,
	 *     urls_found:int,
	 *     base_keys_found:int,
	 *     matches: array<string, array{variants: string[], post_ids: int[], host: string, base_key: string}>
	 * }
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
			$urls = $this->extract_urls( (string) $row['post_content'] );
			foreach ( $urls as $u ) {
				$key = self::composite_key( $u );
				if ( '|' === $key || '' === $key ) {
					continue;
				}
				$matches[ $key ] ??= [
					'variants' => [],
					'post_ids' => [],
					'host'     => strtolower( (string) wp_parse_url( $u, PHP_URL_HOST ) ),
					'base_key' => self::base_key( $u ),
				];
				if ( ! in_array( $u, $matches[ $key ]['variants'], true ) ) {
					$matches[ $key ]['variants'][] = $u;
				}
				$pid = (int) $row['ID'];
				if ( ! in_array( $pid, $matches[ $key ]['post_ids'], true ) ) {
					$matches[ $key ]['post_ids'][] = $pid;
				}
			}
		}

		$urls_found = array_sum( array_map( static fn( $v ) => count( $v['variants'] ), $matches ) );

		return [
			'processed'       => count( (array) $rows ),
			'total'           => $total,
			'next_offset'     => $offset + count( (array) $rows ),
			'urls_found'      => (int) $urls_found,
			'base_keys_found' => count( $matches ),
			'matches'         => $matches,
		];
	}

	/**
	 * Count external image hits in wp_postmeta and wp_options (summary only).
	 *
	 * @return array{postmeta:int,options:int}
	 */
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

		return [
			'postmeta' => $postmeta,
			'options'  => $options,
		];
	}
}
