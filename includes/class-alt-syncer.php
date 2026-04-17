<?php
/**
 * Alt_Syncer — apply a single Alt_Diff (rewrite <img alt> in post_content)
 * and rollback from a per-diff backup.
 *
 * Design notes:
 *  - The <img> tag is matched by its EXACT src attribute, not by class —
 *    class="wp-image-N" is not trustworthy on this codebase (the URL replacer
 *    stamps the same class onto every <img> in a post during a replace pass).
 *  - The library alt is re-read LIVE at apply time (not from the diff row)
 *    so that edits made to the library between scan and apply are honoured.
 *  - Backup key per diff (`_wks3m_alt_backup_{diff_id}`) keeps rollback
 *    granular — you can undo one image without touching the others.
 *
 * @package WaasKitS3Migrator
 */

namespace WKS3M;

defined( 'ABSPATH' ) || exit;

class Alt_Syncer {

	private const BACKUP_META_PREFIX = '_wks3m_alt_backup_';

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
		$att_id  = $diff->attachment_id();

		$post = get_post( $post_id );
		if ( ! $post ) {
			$this->store->mark_failed( $diff->id(), 'post_not_found' );
			return [ 'tags_updated' => 0, 'library_alt' => '', 'errors' => [ 'post_not_found' ] ];
		}

		// Re-read library alt live. If it became empty, refuse to write (same
		// rule as the scanner: never overwrite with an empty library value).
		$library_alt = trim( (string) get_post_meta( $att_id, '_wp_attachment_image_alt', true ) );
		if ( '' === $library_alt ) {
			$this->store->mark_failed( $diff->id(), 'library_alt_empty' );
			return [ 'tags_updated' => 0, 'library_alt' => '', 'errors' => [ 'library_alt_empty' ] ];
		}

		$original = (string) $post->post_content;
		[ $new, $count ] = $this->rewrite_alt( $original, $src, $library_alt );

		if ( 0 === $count || $new === $original ) {
			// Nothing to do — tag disappeared since scan. Mark applied anyway so
			// the diff leaves the queue; it's not an error state.
			$this->store->mark_applied( $diff->id() );
			return [ 'tags_updated' => 0, 'library_alt' => $library_alt, 'errors' => [] ];
		}

		// Store backup only if we haven't already for this diff (idempotent).
		if ( '' === (string) get_post_meta( $post_id, self::BACKUP_META_PREFIX . $diff->id(), true ) ) {
			update_post_meta( $post_id, self::BACKUP_META_PREFIX . $diff->id(), $original );
		}

		$res = wp_update_post( [ 'ID' => $post_id, 'post_content' => $new ], true );
		if ( is_wp_error( $res ) ) {
			$this->store->mark_failed( $diff->id(), $res->get_error_message() );
			return [ 'tags_updated' => 0, 'library_alt' => $library_alt, 'errors' => [ $res->get_error_message() ] ];
		}

		$this->store->mark_applied( $diff->id() );
		return [ 'tags_updated' => $count, 'library_alt' => $library_alt, 'errors' => [] ];
	}

	/**
	 * @return array{posts_restored:int,errors:string[]}
	 */
	public function rollback( Alt_Diff $diff ): array {
		$post_id = $diff->post_id();
		$key     = self::BACKUP_META_PREFIX . $diff->id();
		$backup  = get_post_meta( $post_id, $key, true );
		if ( '' === $backup || null === $backup ) {
			return [ 'posts_restored' => 0, 'errors' => [ 'no_backup' ] ];
		}
		$res = wp_update_post( [ 'ID' => $post_id, 'post_content' => $backup ], true );
		if ( is_wp_error( $res ) ) {
			return [ 'posts_restored' => 0, 'errors' => [ $res->get_error_message() ] ];
		}
		delete_post_meta( $post_id, $key );
		$this->store->mark_rolled_back( $diff->id() );
		return [ 'posts_restored' => 1, 'errors' => [] ];
	}

	/**
	 * Rewrite the alt attribute of every <img> whose src EXACTLY matches the
	 * given URL. If the tag has no alt, inject one right after `<img`.
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
