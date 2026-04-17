<?php
/**
 * Alt_Scanner — find <img> tags whose alt attribute in post_content diverges
 * from _wp_attachment_image_alt on the resolved Media Library attachment.
 *
 * Pivot is the <img src> URL resolved via WordPress core
 * attachment_url_to_postid(), NOT class="wp-image-N" — that class is not a
 * reliable identifier on migrated content (the existing URL replacer stamps
 * the same class onto every <img> in a post during a replace pass).
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
	 * Scan a batch of posts. Only posts referenced by a 'replaced' row in the
	 * migration log are in scope — this is the universe of content the plugin
	 * has touched and therefore the universe we can safely sync.
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

		$replaced_atts = $this->replaced_attachment_ids();

		foreach ( $slice as $post_id ) {
			$processed++;
			[ $imgs, $diffs ] = $this->scan_post( (int) $post_id, $replaced_atts );
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
	private function scan_post( int $post_id, array $replaced_atts ): array {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return [ 0, 0 ];
		}
		$content = (string) $post->post_content;
		if ( '' === $content || false === stripos( $content, '<img' ) ) {
			// Post no longer has any img — purge any leftover diffs for it.
			$this->store->purge_resolved_for_post( $post_id, [] );
			return [ 0, 0 ];
		}

		$imgs         = 0;
		$diffs        = 0;
		$current_srcs = [];

		if ( preg_match_all( '#<img\b[^>]*>#i', $content, $m ) ) {
			foreach ( $m[0] as $tag ) {
				$imgs++;
				if ( ! preg_match( '#\bsrc=(["\'])(.+?)\1#i', $tag, $sm ) ) {
					continue;
				}
				$src = html_entity_decode( $sm[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' );

				$att_id = (int) attachment_url_to_postid( $src );
				if ( $att_id <= 0 || ! isset( $replaced_atts[ $att_id ] ) ) {
					continue;
				}

				$library_alt = trim( (string) get_post_meta( $att_id, '_wp_attachment_image_alt', true ) );
				if ( '' === $library_alt ) {
					// Don't touch content when the library says "no alt" (user's
					// explicit choice: avoid overwriting a valid content alt with
					// an empty library value).
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
		}

		// Remove leftover 'diff' rows for this post whose src is no longer
		// divergent (user manually fixed or removed the image).
		$this->store->purge_resolved_for_post( $post_id, $current_srcs );

		return [ $imgs, $diffs ];
	}

	/**
	 * Union of post_ids across all 'replaced' migration log rows. Cached in
	 * a static to avoid rebuilding it batch after batch within one request.
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
	 * Set of attachment IDs we own (status='imported' or 'replaced' in the
	 * migration log). Used as a safety filter so we never rewrite alts on
	 * attachments that were pre-existing in the library.
	 *
	 * @return array<int,true>
	 */
	private function replaced_attachment_ids(): array {
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
}
