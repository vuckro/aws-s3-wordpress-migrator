<?php
/**
 * Alt_Syncer — apply a single Alt_Diff by rewriting the <img alt> attribute
 * inside post_content, and stripping any <img title> the plugin used to
 * inject. Title is no longer a syncable field: the syncer only writes alt,
 * and deletes title="…" on every matched tag.
 *
 * Design notes:
 *  - Matches <img> by its EXACT src attribute (not class="wp-image-N",
 *    which isn't reliable after a URL-replace pass).
 *  - Library alt is re-read live at apply time (postmeta
 *    _wp_attachment_image_alt).
 *  - Writes post_content via $wpdb->update directly — bypasses save_post
 *    hooks, revisions, and caching-plugin cascades. No clean_post_cache()
 *    either: it triggers listeners in slim-seo / Yoast / wpcode that can
 *    stretch a sub-second write into seconds, and the AJAX response is
 *    short-lived (no next read in this request).
 *  - On success the diff row is deleted. No backup, no rollback.
 *
 * @package WaasKitS3Migrator
 */

namespace WKS3M;

defined( 'ABSPATH' ) || exit;

class Alt_Syncer {

	private Alt_Diff_Store $store;

	public function __construct( ?Alt_Diff_Store $store = null ) {
		$this->store = $store ?? new Alt_Diff_Store();
	}

	/**
	 * @return array{tags_updated:int,library_alt:string,errors:string[]}
	 */
	public function apply( Alt_Diff $diff ): array {
		$post_id = $diff->post_id();
		$src     = $diff->src();

		$post = get_post( $post_id );
		if ( ! $post ) {
			$this->store->mark_failed( $diff->id(), 'post_not_found' );
			return [ 'tags_updated' => 0, 'library_alt' => '', 'errors' => [ 'post_not_found' ] ];
		}

		// Use the alt stored at scan time — the scanner already applied the
		// filename-filter, alt-from-title fallback, and content_alt fallback
		// when the library is empty. Re-deriving here would lose the fallback
		// (content state not in scope). If the user edits the library, a
		// rescan refreshes this.
		$library_alt = $diff->library_alt();

		$original = (string) $post->post_content;
		[ $new, $count ] = $this->rewrite_tag( $original, $src, $library_alt );

		if ( 0 === $count || $new === $original ) {
			// Tag disappeared since the scan, or already in sync with no
			// stray title to strip. Drop the row.
			$this->store->delete( $diff->id() );
			return [ 'tags_updated' => 0, 'library_alt' => $library_alt, 'errors' => [] ];
		}

		if ( false === $this->write_post_content( $post_id, $new ) ) {
			global $wpdb;
			$err = $wpdb->last_error ?: 'db_update_failed';
			$this->store->mark_failed( $diff->id(), $err );
			return [ 'tags_updated' => 0, 'library_alt' => $library_alt, 'errors' => [ $err ] ];
		}

		$this->store->delete( $diff->id() );
		return [ 'tags_updated' => $count, 'library_alt' => $library_alt, 'errors' => [] ];
	}

	/**
	 * Direct SQL UPDATE of post_content + post_modified. No save_post hooks,
	 * no revisions, no cache cascade.
	 *
	 * @return int|false Rows updated, or false on DB error.
	 */
	private function write_post_content( int $post_id, string $content ) {
		global $wpdb;
		$now     = current_time( 'mysql' );
		$now_gmt = current_time( 'mysql', true );
		return $wpdb->update(
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
	}

	/**
	 * Rewrite every <img> whose src EXACTLY matches:
	 *   - upsert alt (skipped silently when the library side is empty),
	 *   - strip any title="…" attribute (legacy values injected by the
	 *     previous sync-title feature).
	 *
	 * A tag counts as modified when either the alt changed OR a title was
	 * stripped.
	 *
	 * @return array{0:string,1:int} [new content, tags modified]
	 */
	private function rewrite_tag( string $content, string $src, string $new_alt ): array {
		if ( '' === $content || '' === $src ) {
			return [ $content, 0 ];
		}

		$pattern = '#<img\b[^>]*?\bsrc=(["\'])' . preg_quote( $src, '#' ) . '\1[^>]*>#i';
		$count   = 0;

		$new = preg_replace_callback( $pattern, function ( array $m ) use ( $new_alt, &$count ): string {
			$tag     = $m[0];
			$changed = false;

			$tag = $this->upsert_attr( $tag, 'alt', $new_alt, $changed );
			$tag = $this->strip_attr( $tag, 'title', $changed );

			if ( $changed ) {
				$count++;
			}
			return $tag;
		}, $content );

		return [ $new ?? $content, $count ];
	}

	/**
	 * Add or replace a single HTML attribute on an <img> tag.
	 * No-op when $new_value is empty (we never overwrite with empty).
	 */
	private function upsert_attr( string $tag, string $attr, string $new_value, bool &$changed ): string {
		if ( '' === $new_value ) {
			return $tag;
		}
		$esc  = htmlspecialchars( $new_value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$repl = $attr . '="' . $esc . '"';

		if ( preg_match( '#\b' . preg_quote( $attr, '#' ) . '=(["\'])(.*?)\1#is', $tag, $m ) ) {
			$current = trim( html_entity_decode( $m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
			if ( $current === $new_value ) {
				return $tag; // already right
			}
			$changed = true;
			return preg_replace( '#\b' . preg_quote( $attr, '#' ) . '=(["\'])(.*?)\1#is', $repl, $tag, 1 );
		}

		$changed = true;
		return preg_replace( '#^<img\b#i', '<img ' . $repl, $tag, 1 );
	}

	/**
	 * Remove a single HTML attribute (with its leading whitespace) from an
	 * <img> tag. No-op when the attribute is absent.
	 */
	private function strip_attr( string $tag, string $attr, bool &$changed ): string {
		$pattern = '#\s+' . preg_quote( $attr, '#' ) . '=(["\'])(.*?)\1#is';
		if ( ! preg_match( $pattern, $tag ) ) {
			return $tag;
		}
		$changed = true;
		return preg_replace( $pattern, '', $tag, 1 );
	}
}
