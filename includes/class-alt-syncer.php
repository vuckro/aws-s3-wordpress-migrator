<?php
/**
 * Alt_Syncer — apply a single Alt_Diff by rewriting the <img alt> and
 * <img title> attributes inside post_content.
 *
 * Design notes:
 *  - Matches <img> by its EXACT src attribute (not class="wp-image-N",
 *    which isn't reliable after a URL-replace pass).
 *  - Library values are re-read live at apply time (alt = postmeta
 *    _wp_attachment_image_alt, title = attachment post_title).
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
	 * @return array{tags_updated:int,library_alt:string,library_title:string,errors:string[]}
	 */
	public function apply( Alt_Diff $diff ): array {
		$post_id = $diff->post_id();
		$src     = $diff->src();
		$att_id  = $diff->attachment_id();

		$post = get_post( $post_id );
		if ( ! $post ) {
			$this->store->mark_failed( $diff->id(), 'post_not_found' );
			return [ 'tags_updated' => 0, 'library_alt' => '', 'library_title' => '', 'errors' => [ 'post_not_found' ] ];
		}

		$library_alt   = trim( (string) get_post_meta( $att_id, '_wp_attachment_image_alt', true ) );
		$library_title = trim( (string) get_the_title( $att_id ) );

		// Skip rows where the library has nothing useful to push.
		if ( '' === $library_alt && '' === $library_title ) {
			$this->store->mark_failed( $diff->id(), 'library_empty' );
			return [ 'tags_updated' => 0, 'library_alt' => '', 'library_title' => '', 'errors' => [ 'library_empty' ] ];
		}

		$original = (string) $post->post_content;
		[ $new, $count ] = $this->rewrite_tag( $original, $src, $library_alt, $library_title );

		if ( 0 === $count || $new === $original ) {
			// Tag disappeared since the scan, or already in sync. Drop the row.
			$this->store->delete( $diff->id() );
			return [ 'tags_updated' => 0, 'library_alt' => $library_alt, 'library_title' => $library_title, 'errors' => [] ];
		}

		if ( false === $this->write_post_content( $post_id, $new ) ) {
			global $wpdb;
			$err = $wpdb->last_error ?: 'db_update_failed';
			$this->store->mark_failed( $diff->id(), $err );
			return [ 'tags_updated' => 0, 'library_alt' => $library_alt, 'library_title' => $library_title, 'errors' => [ $err ] ];
		}

		$this->store->delete( $diff->id() );
		return [ 'tags_updated' => $count, 'library_alt' => $library_alt, 'library_title' => $library_title, 'errors' => [] ];
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
	 * Rewrite alt AND title on every <img> whose src EXACTLY matches.
	 * - Replaces the existing attribute, or injects it after `<img` if absent.
	 * - Library empty value → attribute is left alone (we never overwrite with
	 *   an empty string).
	 *
	 * @return array{0:string,1:int} [new content, tags modified]
	 */
	private function rewrite_tag( string $content, string $src, string $new_alt, string $new_title ): array {
		if ( '' === $content || '' === $src ) {
			return [ $content, 0 ];
		}

		$pattern = '#<img\b[^>]*?\bsrc=(["\'])' . preg_quote( $src, '#' ) . '\1[^>]*>#i';
		$count   = 0;

		$new = preg_replace_callback( $pattern, function ( array $m ) use ( $new_alt, $new_title, &$count ): string {
			$tag     = $m[0];
			$changed = false;

			$tag = $this->upsert_attr( $tag, 'alt',   $new_alt,   $changed );
			$tag = $this->upsert_attr( $tag, 'title', $new_title, $changed );

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
}
