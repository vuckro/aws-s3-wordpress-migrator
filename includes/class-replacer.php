<?php
/**
 * Replacer — swaps external image URLs for local Media Library URLs inside
 * post_content, with Gutenberg block awareness and a reversible backup.
 *
 * Strategy:
 *   1. Fetch all post IDs listed on the migration row.
 *   2. For each post, snapshot current post_content to postmeta
 *      `_wks3m_backup_{row_id}` (keyed so rollback picks the right one).
 *   3. For each stored source URL variant, build a list of replacement strings
 *      (plain + JSON-escaped \/ form) and swap them for the matching local URL
 *      (size prefix heuristic maps large_/medium_/small_/thumbnail_ to the
 *      closest WP-generated size).
 *   4. Fix Gutenberg block metadata near swapped URLs: update `"id":N` attr in
 *      the block JSON comment and the `wp-image-N` class on <img>.
 *   5. Persist the updated content via wp_update_post().
 *
 * @package WaasKitS3Migrator
 */

namespace WKS3M;

defined( 'ABSPATH' ) || exit;

class Replacer {

	private const BACKUP_META_PREFIX = '_wks3m_backup_';
	private const REPLACEMENTS_META  = '_wks3m_replacements';

	/**
	 * Run the replacement for one migration-log row + its associated attachment.
	 *
	 * @return array{posts_updated:int,posts_skipped:int,errors:string[]}
	 */
	public function replace_for_row( array $row, int $attachment_id ): array {
		$row_id    = (int) $row['id'];
		$post_ids  = json_decode( (string) $row['post_ids'], true ) ?: [];
		$variants  = json_decode( (string) $row['source_url_variants'], true ) ?: [];
		if ( empty( $post_ids ) || empty( $variants ) ) {
			return [ 'posts_updated' => 0, 'posts_skipped' => 0, 'errors' => [ 'nothing_to_replace' ] ];
		}

		$replacements = $this->build_replacement_map( $variants, $attachment_id );

		$updated = 0;
		$skipped = 0;
		$errors  = [];

		foreach ( $post_ids as $pid ) {
			$pid  = (int) $pid;
			$post = get_post( $pid );
			if ( ! $post ) {
				$skipped++;
				continue;
			}
			$original = (string) $post->post_content;
			$new      = $this->rewrite_content( $original, $replacements, $attachment_id );
			if ( $new === $original ) {
				$skipped++;
				continue;
			}

			// Backup before writing.
			update_post_meta( $pid, self::BACKUP_META_PREFIX . $row_id, $original );
			$log = (array) get_post_meta( $pid, self::REPLACEMENTS_META, true );
			if ( ! is_array( $log ) ) {
				$log = [];
			}
			$log[ $row_id ] = [
				'attachment_id' => $attachment_id,
				'replaced_at'   => current_time( 'mysql' ),
				'variants'      => array_keys( $replacements ),
			];
			update_post_meta( $pid, self::REPLACEMENTS_META, $log );

			$res = wp_update_post( [ 'ID' => $pid, 'post_content' => $new ], true );
			if ( is_wp_error( $res ) ) {
				$errors[] = sprintf( '#%d: %s', $pid, $res->get_error_message() );
				continue;
			}
			$updated++;
		}

		return [ 'posts_updated' => $updated, 'posts_skipped' => $skipped, 'errors' => $errors ];
	}

	/**
	 * Rollback a replacement: restore every affected post's content from backup
	 * and clear the log entry.
	 *
	 * @return array{posts_restored:int,errors:string[]}
	 */
	public function rollback_row( array $row ): array {
		$row_id   = (int) $row['id'];
		$post_ids = json_decode( (string) $row['post_ids'], true ) ?: [];
		$restored = 0;
		$errors   = [];

		foreach ( $post_ids as $pid ) {
			$pid    = (int) $pid;
			$backup = get_post_meta( $pid, self::BACKUP_META_PREFIX . $row_id, true );
			if ( '' === $backup || null === $backup ) {
				continue;
			}
			$res = wp_update_post( [ 'ID' => $pid, 'post_content' => $backup ], true );
			if ( is_wp_error( $res ) ) {
				$errors[] = sprintf( '#%d: %s', $pid, $res->get_error_message() );
				continue;
			}
			delete_post_meta( $pid, self::BACKUP_META_PREFIX . $row_id );
			$log = (array) get_post_meta( $pid, self::REPLACEMENTS_META, true );
			if ( is_array( $log ) && isset( $log[ $row_id ] ) ) {
				unset( $log[ $row_id ] );
				if ( empty( $log ) ) {
					delete_post_meta( $pid, self::REPLACEMENTS_META );
				} else {
					update_post_meta( $pid, self::REPLACEMENTS_META, $log );
				}
			}
			$restored++;
		}
		return [ 'posts_restored' => $restored, 'errors' => $errors ];
	}

	/**
	 * Build source-URL → local-URL mapping, covering plain and JSON-escaped forms.
	 *
	 * @return array<string,string>
	 */
	private function build_replacement_map( array $variants, int $attachment_id ): array {
		$map = [];
		foreach ( $variants as $v ) {
			$filename = basename( (string) wp_parse_url( $v, PHP_URL_PATH ) );
			$size     = $this->size_for_prefix( $filename );
			$local    = wp_get_attachment_image_url( $attachment_id, $size );
			if ( ! $local ) {
				$local = (string) wp_get_attachment_url( $attachment_id );
			}
			$map[ $v ]                         = $local;
			$map[ str_replace( '/', '\\/', $v ) ] = str_replace( '/', '\\/', $local );
		}
		return $map;
	}

	/**
	 * Map a Strapi size prefix to the closest WP-generated image size.
	 */
	private function size_for_prefix( string $filename ): string {
		if ( 0 === strpos( $filename, 'thumbnail_' ) ) {
			return 'thumbnail';
		}
		if ( 0 === strpos( $filename, 'small_' ) ) {
			return 'medium';
		}
		if ( 0 === strpos( $filename, 'medium_' ) ) {
			return 'medium_large';
		}
		// large_ and anything unprefixed → full.
		return 'full';
	}

	/**
	 * Apply URL swaps + fix Gutenberg block metadata around swapped tags.
	 */
	private function rewrite_content( string $content, array $replacements, int $attachment_id ): string {
		if ( '' === $content ) {
			return $content;
		}
		// 1. Raw URL swaps.
		$content = str_replace( array_keys( $replacements ), array_values( $replacements ), $content );

		// 2. Fix wp-image-{id} classes pointing to stale attachment IDs on
		//    <img> tags whose src has just been rewritten to /wp-content/uploads/.
		$content = preg_replace_callback(
			'#<img\b[^>]*?\bsrc="([^"]+)"[^>]*>#i',
			function ( array $m ) use ( $attachment_id ) {
				$tag = $m[0];
				$src = $m[1];
				if ( false === strpos( $src, '/wp-content/uploads/' ) ) {
					return $tag;
				}
				if ( preg_match( '#\bwp-image-\d+#', $tag ) ) {
					$tag = preg_replace( '#\bwp-image-\d+#', 'wp-image-' . $attachment_id, $tag );
				} elseif ( preg_match( '#\bclass="([^"]*)"#', $tag ) ) {
					$tag = preg_replace(
						'#\bclass="([^"]*)"#',
						'class="$1 wp-image-' . $attachment_id . '"',
						$tag,
						1
					);
				}
				return $tag;
			},
			$content
		);

		// 3. Fix the block JSON header: <!-- wp:image {"id":N,...} -->
		//    If a block wraps an <img> whose src now points to the local upload,
		//    make sure the id matches the new attachment.
		$content = preg_replace_callback(
			'#<!--\s*wp:image\s*(\{[^}]*\})\s*-->(.*?)<!--\s*/wp:image\s*-->#is',
			function ( array $m ) use ( $attachment_id ) {
				$attrs   = $m[1];
				$inner   = $m[2];
				$has_loc = false !== strpos( $inner, '/wp-content/uploads/' );
				if ( ! $has_loc ) {
					return $m[0];
				}
				// Replace "id":N or inject if absent.
				if ( preg_match( '#"id"\s*:\s*\d+#', $attrs ) ) {
					$attrs = preg_replace( '#"id"\s*:\s*\d+#', '"id":' . $attachment_id, $attrs );
				} else {
					$attrs = preg_replace( '#^\{#', '{"id":' . $attachment_id . ',', $attrs, 1 );
				}
				return '<!-- wp:image ' . $attrs . ' -->' . $inner . '<!-- /wp:image -->';
			},
			$content
		);

		return $content;
	}
}
