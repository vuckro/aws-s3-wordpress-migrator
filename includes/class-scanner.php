<?php
/**
 * Scanner — finds external image URLs across wp_posts and persists results
 * into the migration log.
 *
 * @package WaasKitS3Migrator
 */

namespace WKS3M;

defined( 'ABSPATH' ) || exit;

class Scanner {

	/** Regex matching http(s) URLs ending in an image extension, plain or JSON-escaped. */
	public static function url_regex(): string {
		$ext = implode( '|', array_map( 'preg_quote', Settings::IMAGE_EXTENSIONS ) );
		return '#https?:(?:\\\\?/){2}[^\s"\'<>)\]\\\\]+?\.(?:' . $ext . ')(?:\?[^\s"\'<>)\]\\\\]*)?#i';
	}

	/** Unescape JSON slashes + trim trailing punctuation. */
	public static function normalize_url( string $url ): string {
		return rtrim( str_replace( '\\/', '/', $url ), '\\",;:)' );
	}

	/** Delegates to Url_Helper, respecting the user's "strip Strapi prefixes" option. */
	public static function base_key( string $url ): string {
		return Url_Helper::base_key( $url, Settings::strip_strapi_prefixes() );
	}

	public static function composite_key( string $url ): string {
		return Url_Helper::composite_key( $url, Settings::strip_strapi_prefixes() );
	}

	/**
	 * Extract all distinct external image URLs from a chunk of content.
	 *
	 * @return string[]
	 */
	public function extract_urls( string $content ): array {
		if ( '' === $content ) {
			return [];
		}
		if ( ! preg_match_all( self::url_regex(), $content, $m ) || empty( $m[0] ) ) {
			return [];
		}

		$hosts_filter = Settings::source_hosts();
		$auto_detect  = Settings::auto_detect_external();
		$site_host    = Settings::site_host();

		$out = [];
		foreach ( $m[0] as $raw_url ) {
			$url  = self::normalize_url( $raw_url );
			$host = Url_Helper::host( $url );
			if ( '' === $host || ! $this->host_is_eligible( $host, $hosts_filter, $auto_detect, $site_host ) ) {
				continue;
			}
			$out[ $url ] = true;
		}
		return array_keys( $out );
	}

	private function host_is_eligible( string $host, array $hosts_filter, bool $auto_detect, string $site_host ): bool {
		if ( ! empty( $hosts_filter ) ) {
			return in_array( $host, $hosts_filter, true );
		}
		return $auto_detect && $host !== $site_host;
	}

	/**
	 * Scan one batch of posts. Upserts results into the log so the Queue tab
	 * can display them without replaying the scan.
	 */
	public function scan_posts_batch( int $offset = 0, int $limit = 100 ): array {
		global $wpdb;

		$patterns  = $this->like_patterns();
		$where     = implode( ' OR ', array_fill( 0, count( $patterns ), 'post_content LIKE %s' ) );
		$base_args = $patterns;

		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts}
				 WHERE post_status IN ('publish','draft','pending','private','future')
				 AND post_type NOT IN ('revision','attachment')
				 AND ({$where})",
				...$base_args
			)
		);

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_content FROM {$wpdb->posts}
				 WHERE post_status IN ('publish','draft','pending','private','future')
				 AND post_type NOT IN ('revision','attachment')
				 AND ({$where})
				 ORDER BY ID ASC
				 LIMIT %d OFFSET %d",
				...array_merge( $base_args, [ $limit, $offset ] )
			),
			ARRAY_A
		);

		$matches = [];
		foreach ( (array) $rows as $row ) {
			$content = (string) $row['post_content'];
			foreach ( $this->extract_urls( $content ) as $url ) {
				$key = self::composite_key( $url );
				if ( '' === $key || '|' === $key ) {
					continue;
				}
				$matches[ $key ] ??= [
					'variants'      => [],
					'post_ids'      => [],
					'host'          => Url_Helper::host( $url ),
					'base_key'      => self::base_key( $url ),
					'first_content' => $content,
				];
				if ( ! in_array( $url, $matches[ $key ]['variants'], true ) ) {
					$matches[ $key ]['variants'][] = $url;
				}
				$pid = (int) $row['ID'];
				if ( ! in_array( $pid, $matches[ $key ]['post_ids'], true ) ) {
					$matches[ $key ]['post_ids'][] = $pid;
				}
			}
		}

		$urls_found = 0;
		foreach ( $matches as $key => $m ) {
			$urls_found += count( $m['variants'] );
			$this->upsert( $key, $m );
		}

		return [
			'processed'       => count( (array) $rows ),
			'total'           => $total,
			'next_offset'     => $offset + count( (array) $rows ),
			'urls_found'      => $urls_found,
			'base_keys_found' => count( $matches ),
		];
	}

	/** Insert or update the migration log entry for a single grouped match. */
	private function upsert( string $key, array $data ): void {
		global $wpdb;
		$table = Activator::table_name();

		$alt   = Metadata_Extractor::extract_alt( $data['first_content'], $data['variants'] );
		$title = Metadata_Extractor::derive_title( $data['base_key'] );

		$existing = $wpdb->get_row(
			$wpdb->prepare( "SELECT id, status, alt_text FROM {$table} WHERE source_url_base = %s", $key ),
			ARRAY_A
		);

		$now = current_time( 'mysql' );
		$payload = [
			'source_url_variants' => wp_json_encode( array_values( $data['variants'] ) ),
			'post_ids'            => wp_json_encode( array_values( $data['post_ids'] ) ),
			'source_host'         => $data['host'],
			'base_key'            => $data['base_key'],
			'last_seen_at'        => $now,
		];

		if ( $existing ) {
			if ( empty( $existing['alt_text'] ) && '' !== $alt ) {
				$payload['alt_text'] = $alt;
			}
			if ( 'pending' === $existing['status'] ) {
				$payload['derived_title'] = $title;
			}
			$wpdb->update( $table, $payload, [ 'id' => (int) $existing['id'] ] );
			return;
		}

		$wpdb->insert(
			$table,
			$payload + [
				'source_url_base' => $key,
				'alt_text'        => $alt,
				'derived_title'   => $title,
				'status'          => 'pending',
				'created_at'      => $now,
			]
		);
	}

	/** Build the SQL LIKE patterns used to narrow candidate posts. */
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

	public function count_secondary_sources(): array {
		global $wpdb;
		$patterns = $this->like_patterns();
		$where_pm = implode( ' OR ', array_fill( 0, count( $patterns ), 'meta_value LIKE %s' ) );
		$where_op = implode( ' OR ', array_fill( 0, count( $patterns ), 'option_value LIKE %s' ) );

		return [
			'postmeta' => (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE {$where_pm}", ...$patterns )
			),
			'options'  => (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE {$where_op}", ...$patterns )
			),
		];
	}
}
