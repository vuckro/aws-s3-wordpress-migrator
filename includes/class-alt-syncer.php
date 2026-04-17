<?php
/**
 * Alt_Syncer — apply a single Alt_Diff by rewriting the <img alt> inside
 * post_content.
 *
 * Design notes:
 *  - The <img> tag is matched by its EXACT src attribute (not by class).
 *    class="wp-image-N" is not reliable here — the URL replacer stamps the
 *    same class onto every <img> in a post during a replace pass.
 *  - Library alt is re-read LIVE at apply time so library edits made after
 *    the scan are honoured.
 *  - Writes post_content via $wpdb->update (not wp_update_post). Bypasses the
 *    save_post cascade — Yoast reindex, revision insert, SEO/form plugin
 *    listeners — which on a bulk of thousands of diffs would saturate
 *    MySQL/PHP-FPM.
 *  - On success the diff row is deleted. No rollback, no backup postmeta:
 *    a re-scan rebuilds the diff table from live state, and WP revisions
 *    already exist as a safety net for the post-level changes.
 *
 * @package WaasKitS3Migrator
 */

namespace WKS3M;

defined( 'ABSPATH' ) || exit;

class Alt_Syncer {

	private Alt_Diff_Store $store;
	private Alt_History_Store $history;

	public function __construct(
		?Alt_Diff_Store $store = null,
		?Alt_History_Store $history = null
	) {
		$this->store   = $store   ?? new Alt_Diff_Store();
		$this->history = $history ?? new Alt_History_Store();
	}

	/**
	 * @return array{tags_updated:int,library_alt:string,errors:string[]}
	 */
	public function apply( Alt_Diff $diff ): array {
		$post_id = $diff->post_id();
		$src     = $diff->src();
		$att_id  = $diff->attachment_id();

		$post = get_post( $post_id );
		if ( ! $post ) {
			$this->store->mark_failed( $diff->id(), 'post_not_found' );
			return [ 'tags_updated' => 0, 'library_alt' => '', 'errors' => [ 'post_not_found' ] ];
		}

		$library_alt = trim( (string) get_post_meta( $att_id, '_wp_attachment_image_alt', true ) );
		if ( '' === $library_alt ) {
			$this->store->mark_failed( $diff->id(), 'library_alt_empty' );
			return [ 'tags_updated' => 0, 'library_alt' => '', 'errors' => [ 'library_alt_empty' ] ];
		}

		$original = (string) $post->post_content;
		[ $new, $count ] = $this->rewrite_alt( $original, $src, $library_alt );

		if ( 0 === $count || $new === $original ) {
			// Tag disappeared since the scan, or already in sync. Drop the row
			// — nothing to fix. A fresh scan would not recreate it.
			$this->store->delete( $diff->id() );
			return [ 'tags_updated' => 0, 'library_alt' => $library_alt, 'errors' => [] ];
		}

		if ( false === $this->write_post_content( $post_id, $new ) ) {
			$err = $this->last_db_error();
			$this->store->mark_failed( $diff->id(), $err );
			return [ 'tags_updated' => 0, 'library_alt' => $library_alt, 'errors' => [ $err ] ];
		}

		$this->history->log( $post_id, $att_id, $src, $diff->content_alt(), $library_alt );
		$this->store->delete( $diff->id() );
		return [ 'tags_updated' => $count, 'library_alt' => $library_alt, 'errors' => [] ];
	}

	/**
	 * Direct SQL UPDATE of post_content + post_modified, plus cache flush.
	 * Skips the save_post hook cascade (by design).
	 *
	 * @return int|false Rows updated, or false on DB error.
	 */
	private function write_post_content( int $post_id, string $content ) {
		global $wpdb;
		$now     = current_time( 'mysql' );
		$now_gmt = current_time( 'mysql', true );
		$result  = $wpdb->update(
			$wpdb->posts,
			[
				'post_content'      => $content,
				'post_modified'     => $now,
				'post_modified_gmt' => $now_gmt,
			],
			[ 'ID' => $post_id ],
			[ '%s', '%s', '%s' ],
			[ '%d' ]
		);
		if ( false !== $result ) {
			clean_post_cache( $post_id );
		}
		return $result;
	}

	private function last_db_error(): string {
		global $wpdb;
		return $wpdb->last_error ?: 'db_update_failed';
	}

	/**
	 * Rewrite the alt attribute of every <img> whose src EXACTLY matches.
	 * Injects alt="…" right after `<img` when the tag has none.
	 *
	 * @return array{0:string,1:int} [new content, tags updated]
	 */
	private function rewrite_alt( string $content, string $src, string $new_alt ): array {
		if ( '' === $content || '' === $src ) {
			return [ $content, 0 ];
		}

		$pattern = '#<img\b[^>]*?\bsrc=(["\'])' . preg_quote( $src, '#' ) . '\1[^>]*>#i';
		$esc     = htmlspecialchars( $new_alt, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$count   = 0;

		$new = preg_replace_callback( $pattern, function ( array $m ) use ( $esc, &$count ): string {
			$tag = $m[0];
			if ( preg_match( '#\balt=(["\'])(.*?)\1#is', $tag ) ) {
				$count++;
				return preg_replace( '#\balt=(["\'])(.*?)\1#is', 'alt="' . $esc . '"', $tag, 1 );
			}
			$count++;
			return preg_replace( '#^<img\b#i', '<img alt="' . $esc . '"', $tag, 1 );
		}, $content );

		return [ $new ?? $content, $count ];
	}
}
