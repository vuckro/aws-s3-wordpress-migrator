<?php
/**
 * Scanner — finds S3 URLs across wp_posts, wp_postmeta, wp_options.
 *
 * Phase 1: read-only. No writes, no side effects.
 *
 * @package ClaireexploreS3Migrator
 */

namespace CXS3M;

defined( 'ABSPATH' ) || exit;

class Scanner {

	/** Matches any URL pointing at the Claireexplore S3 bucket. */
	public const URL_REGEX = '#https?://clairexplore\.s3\.eu-west-3\.amazonaws\.com/[^\s"\'<>)]+#i';

	/** Strapi size prefixes to strip when computing a base key. */
	public const SIZE_PREFIXES = [ 'large_', 'medium_', 'small_', 'thumbnail_' ];

	/**
	 * Scan a single post_content string and return distinct S3 URLs.
	 *
	 * @return string[]
	 */
	public function extract_urls( string $content ): array {
		if ( '' === $content || false === stripos( $content, CXS3M_S3_HOST ) ) {
			return [];
		}
		preg_match_all( self::URL_REGEX, $content, $m );
		if ( empty( $m[0] ) ) {
			return [];
		}
		// Drop trailing punctuation sometimes captured inside Gutenberg JSON strings.
		$urls = array_map( static fn( string $u ): string => rtrim( $u, '\\",;:)' ), $m[0] );
		return array_values( array_unique( $urls ) );
	}

	/**
	 * Given a full S3 URL, return its base key (filename without size prefix).
	 * Example: https://.../large_DSC_05525_391abe2185.jpg  →  DSC_05525_391abe2185.jpg
	 */
	public static function base_key( string $url ): string {
		$path     = wp_parse_url( $url, PHP_URL_PATH ) ?: '';
		$filename = ltrim( $path, '/' );
		foreach ( self::SIZE_PREFIXES as $prefix ) {
			if ( 0 === strpos( $filename, $prefix ) ) {
				$filename = substr( $filename, strlen( $prefix ) );
				break;
			}
		}
		return $filename;
	}

	/**
	 * Group URLs by base key; returns [ base_key => [variants…] ].
	 */
	public function group_by_base( array $urls ): array {
		$out = [];
		foreach ( $urls as $u ) {
			$key = self::base_key( $u );
			if ( '' === $key ) {
				continue;
			}
			$out[ $key ] ??= [];
			if ( ! in_array( $u, $out[ $key ], true ) ) {
				$out[ $key ][] = $u;
			}
		}
		return $out;
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
	 *     matches: array<string, array{variants: string[], post_ids: int[]}>
	 * }
	 */
	public function scan_posts_batch( int $offset = 0, int $limit = 100 ): array {
		global $wpdb;

		$total = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts}
			 WHERE post_status IN ('publish','draft','pending','private','future')
			 AND post_type NOT IN ('revision','attachment')
			 AND post_content LIKE '%" . esc_sql( CXS3M_S3_HOST ) . "%'"
		);

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_content FROM {$wpdb->posts}
				 WHERE post_status IN ('publish','draft','pending','private','future')
				 AND post_type NOT IN ('revision','attachment')
				 AND post_content LIKE %s
				 ORDER BY ID ASC
				 LIMIT %d OFFSET %d",
				'%' . $wpdb->esc_like( CXS3M_S3_HOST ) . '%',
				$limit,
				$offset
			),
			ARRAY_A
		);

		$matches = [];
		foreach ( (array) $rows as $row ) {
			$urls = $this->extract_urls( (string) $row['post_content'] );
			foreach ( $urls as $u ) {
				$key = self::base_key( $u );
				if ( '' === $key ) {
					continue;
				}
				$matches[ $key ]               ??= [ 'variants' => [], 'post_ids' => [] ];
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
	 * Count S3 occurrences in wp_postmeta and wp_options (for the Scan summary).
	 *
	 * @return array{postmeta:int,options:int}
	 */
	public function count_secondary_sources(): array {
		global $wpdb;
		$like = '%' . $wpdb->esc_like( CXS3M_S3_HOST ) . '%';

		$postmeta = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_value LIKE %s",
				$like
			)
		);
		$options  = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_value LIKE %s",
				$like
			)
		);

		return [
			'postmeta' => $postmeta,
			'options'  => $options,
		];
	}
}
