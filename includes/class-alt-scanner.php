<?php
/**
 * Alt_Scanner — find <img> tags whose alt attribute in post_content diverges
 * from _wp_attachment_image_alt on the resolved Media Library attachment.
 *
 * Src resolution has two layers, in order:
 *   1. attachment_url_to_postid() — fast path for URLs under wp-content/uploads/
 *   2. Migration-log variant lookup — for URLs still pointing to the original
 *      external source (S3/CDN) that we've already imported a local copy of
 *      but haven't (or couldn't) rewrite in post_content.
 *
 * Either way the pivot is the <img src>, NOT class="wp-image-N" — that class
 * is not a reliable identifier on this codebase (the URL replacer stamps the
 * same class onto every <img> in a post during a replace pass).
 *
 * @package WaasKitS3Migrator
 */

namespace WKS3M;

defined( 'ABSPATH' ) || exit;

class Alt_Scanner {

	private Alt_Diff_Store $store;

	public function __construct( ?Alt_Diff_Store $store = null ) {
		$this->store = $store ?? new Alt_Diff_Store();
	}

	/**
	 * Scan a batch of posts. In-scope = posts referenced by a 'replaced' row
	 * in the migration log.
	 *
	 * @return array{
	 *     processed:int, total:int, next_offset:int,
	 *     imgs_scanned:int, diffs_found:int
	 * }
	 */
	public function scan_batch( int $offset = 0, int $limit = 50 ): array {
		$post_ids = $this->post_ids_in_scope();
		$total    = count( $post_ids );

		$slice        = array_slice( $post_ids, $offset, max( 1, $limit ) );
		$processed    = 0;
		$imgs_scanned = 0;
		$diffs_found  = 0;

		$owned_atts = $this->owned_attachment_ids();
		$url_index  = $this->variant_index();

		foreach ( $slice as $post_id ) {
			$processed++;
			[ $imgs, $diffs ] = $this->scan_post( (int) $post_id, $owned_atts, $url_index );
			$imgs_scanned    += $imgs;
			$diffs_found     += $diffs;
		}

		return [
			'processed'    => $processed,
			'total'        => $total,
			'next_offset'  => $offset + $processed,
			'imgs_scanned' => $imgs_scanned,
			'diffs_found'  => $diffs_found,
		];
	}

	/**
	 * @return array{0:int,1:int} [imgs_scanned, diffs_recorded]
	 */
	private function scan_post( int $post_id, array $owned_atts, array $url_index ): array {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return [ 0, 0 ];
		}
		$content = (string) $post->post_content;
		if ( '' === $content || false === stripos( $content, '<img' ) ) {
			$this->store->purge_resolved_for_post( $post_id, [] );
			return [ 0, 0 ];
		}

		$imgs         = 0;
		$diffs        = 0;
		$current_srcs = [];

		if ( ! preg_match_all( '#<img\b[^>]*>#i', $content, $m ) ) {
			$this->store->purge_resolved_for_post( $post_id, [] );
			return [ 0, 0 ];
		}

		foreach ( $m[0] as $tag ) {
			$imgs++;
			if ( ! preg_match( '#\bsrc=(["\'])(.+?)\1#i', $tag, $sm ) ) {
				continue;
			}
			$src    = html_entity_decode( $sm[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			$att_id = $this->resolve_src( $src, $url_index );
			if ( $att_id <= 0 || ! isset( $owned_atts[ $att_id ] ) ) {
				continue;
			}

			$library_alt = trim( (string) get_post_meta( $att_id, '_wp_attachment_image_alt', true ) );
			if ( '' === $library_alt ) {
				continue;
			}

			$content_alt = '';
			if ( preg_match( '#\balt=(["\'])(.*?)\1#is', $tag, $am ) ) {
				$content_alt = trim( html_entity_decode( $am[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
			}
			if ( $library_alt === $content_alt ) {
				continue;
			}

			$this->store->upsert_diff( $post_id, $att_id, $src, $content_alt, $library_alt );
			$current_srcs[] = $src;
			$diffs++;
		}

		$this->store->purge_resolved_for_post( $post_id, $current_srcs );
		return [ $imgs, $diffs ];
	}

	/**
	 * Resolve a src URL to an attachment ID.
	 *
	 * Fast path: core's attachment_url_to_postid (local uploads URLs).
	 * Fallback: variant index built from migration log — picks up S3/CDN URLs
	 * that were never rewritten in post_content but whose underlying file has
	 * been imported.
	 */
	private function resolve_src( string $src, array $url_index ): int {
		$id = (int) attachment_url_to_postid( $src );
		if ( $id > 0 ) {
			return $id;
		}
		return (int) ( $url_index[ $src ] ?? 0 );
	}

	/**
	 * Union of post_ids across all 'replaced' migration log rows. Cached
	 * per-request.
	 *
	 * @return int[]
	 */
	private function post_ids_in_scope(): array {
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}
		global $wpdb;
		$table = Activator::table_name();
		$rows  = $wpdb->get_col( "SELECT post_ids FROM {$table} WHERE status = 'replaced' AND post_ids IS NOT NULL" );

		$set = [];
		foreach ( (array) $rows as $raw ) {
			$list = json_decode( (string) $raw, true );
			if ( ! is_array( $list ) ) {
				continue;
			}
			foreach ( $list as $pid ) {
				$pid = (int) $pid;
				if ( $pid > 0 ) {
					$set[ $pid ] = true;
				}
			}
		}
		$ids = array_keys( $set );
		sort( $ids, SORT_NUMERIC );
		$cache = $ids;
		return $cache;
	}

	/**
	 * Set of attachment IDs the plugin owns (imported or replaced). Safety
	 * filter so we never rewrite alts on attachments unrelated to the migration.
	 *
	 * @return array<int,true>
	 */
	private function owned_attachment_ids(): array {
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}
		global $wpdb;
		$table = Activator::table_name();
		$rows  = $wpdb->get_col(
			"SELECT attachment_id FROM {$table}
			 WHERE status IN ('imported','replaced') AND attachment_id IS NOT NULL"
		);
		$set = [];
		foreach ( (array) $rows as $id ) {
			$id = (int) $id;
			if ( $id > 0 ) {
				$set[ $id ] = true;
			}
		}
		$cache = $set;
		return $cache;
	}

	/**
	 * Map of external-variant URL → attachment_id, built from the migration
	 * log. Used to resolve src attributes that still point to the original
	 * S3/CDN host (i.e. post_content that was never URL-rewritten).
	 *
	 * @return array<string,int>
	 */
	private function variant_index(): array {
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}
		global $wpdb;
		$table = Activator::table_name();
		$rows  = $wpdb->get_results(
			"SELECT attachment_id, source_url_variants FROM {$table}
			 WHERE attachment_id IS NOT NULL AND source_url_variants IS NOT NULL",
			ARRAY_A
		);
		$index = [];
		foreach ( (array) $rows as $r ) {
			$att = (int) $r['attachment_id'];
			if ( $att <= 0 ) {
				continue;
			}
			$variants = json_decode( (string) $r['source_url_variants'], true );
			if ( ! is_array( $variants ) ) {
				continue;
			}
			foreach ( $variants as $v ) {
				$v = is_string( $v ) ? $v : '';
				if ( '' === $v ) {
					continue;
				}
				$index[ $v ] = $att;
			}
		}
		$cache = $index;
		return $cache;
	}
}
