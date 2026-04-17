<?php
/**
 * Replacer — swap external URLs for local Media Library URLs inside
 * post_content, with Gutenberg block awareness.
 *
 * @package WaasKitS3Migrator
 */

namespace WKS3M;

defined( 'ABSPATH' ) || exit;

class Replacer {

	/**
	 * Run the replacement for one migration-log row.
	 *
	 * @return array{posts_updated:int,posts_skipped:int,errors:string[]}
	 */
	public function replace_for_row( Migration_Row $row, int $attachment_id ): array {
		$post_ids = $row->post_ids();
		$variants = $row->variants();
		if ( empty( $post_ids ) || empty( $variants ) ) {
			return [ 'posts_updated' => 0, 'posts_skipped' => 0, 'errors' => [ 'nothing_to_replace' ] ];
		}

		$map     = $this->build_replacement_map( $variants, $attachment_id );
		$updated = 0;
		$skipped = 0;
		$errors  = [];

		foreach ( $post_ids as $pid ) {
			$post = get_post( $pid );
			if ( ! $post ) {
				$skipped++;
				continue;
			}
			$original = (string) $post->post_content;
			$new      = $this->rewrite_content( $original, $map, $attachment_id );
			if ( $new === $original ) {
				$skipped++;
				continue;
			}

			if ( false === $this->write_post_content( (int) $pid, $new ) ) {
				global $wpdb;
				$errors[] = sprintf( '#%d: %s', $pid, $wpdb->last_error ?: 'db_update_failed' );
				continue;
			}
			$updated++;
		}

		return [ 'posts_updated' => $updated, 'posts_skipped' => $skipped, 'errors' => $errors ];
	}

	/**
	 * Direct SQL UPDATE of post_content + post_modified.
	 *
	 * Bypasses wp_update_post() and its save_post cascade — Yoast reindex,
	 * slim-seo analysis, revision insert, WPCode hooks. On a bulk migration
	 * with thousands of rows × N posts per row, that cascade is what used to
	 * drown the site. Same pattern as Alt_Syncer for the same reason.
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

	/** Build source-URL → local-URL map, covering plain + JSON-escaped forms. */
	private function build_replacement_map( array $variants, int $attachment_id ): array {
		$map = [];
		foreach ( $variants as $v ) {
			$filename = Url_Helper::filename( $v );
			$size     = Url_Helper::wp_size_for_filename( $filename );
			$local    = wp_get_attachment_image_url( $attachment_id, $size ) ?: (string) wp_get_attachment_url( $attachment_id );
			$map[ $v ]                             = $local;
			$map[ str_replace( '/', '\\/', $v ) ]  = str_replace( '/', '\\/', $local );
		}
		return $map;
	}

	/**
	 * Apply URL swaps and fix Gutenberg block metadata around swapped tags.
	 */
	private function rewrite_content( string $content, array $map, int $attachment_id ): string {
		if ( '' === $content ) {
			return $content;
		}
		$content = strtr( $content, $map );

		// Retag <img> classes to match the new attachment id.
		$content = preg_replace_callback(
			'#<img\b[^>]*?\bsrc="([^"]+)"[^>]*>#i',
			function ( array $m ) use ( $attachment_id ) {
				if ( false === strpos( $m[1], '/wp-content/uploads/' ) ) {
					return $m[0];
				}
				$tag = $m[0];
				if ( preg_match( '#\bwp-image-\d+#', $tag ) ) {
					return preg_replace( '#\bwp-image-\d+#', 'wp-image-' . $attachment_id, $tag );
				}
				if ( preg_match( '#\bclass="([^"]*)"#', $tag ) ) {
					return preg_replace( '#\bclass="([^"]*)"#', 'class="$1 wp-image-' . $attachment_id . '"', $tag, 1 );
				}
				return $tag;
			},
			$content
		);

		// Sync the block JSON header id with the new attachment.
		$content = preg_replace_callback(
			'#<!--\s*wp:image\s*(\{[^}]*\})\s*-->(.*?)<!--\s*/wp:image\s*-->#is',
			function ( array $m ) use ( $attachment_id ) {
				if ( false === strpos( $m[2], '/wp-content/uploads/' ) ) {
					return $m[0];
				}
				$attrs = $m[1];
				if ( preg_match( '#"id"\s*:\s*\d+#', $attrs ) ) {
					$attrs = preg_replace( '#"id"\s*:\s*\d+#', '"id":' . $attachment_id, $attrs );
				} else {
					$attrs = preg_replace( '#^\{#', '{"id":' . $attachment_id . ',', $attrs, 1 );
				}
				return '<!-- wp:image ' . $attrs . ' -->' . $m[2] . '<!-- /wp:image -->';
			},
			$content
		);

		return $content;
	}
}
