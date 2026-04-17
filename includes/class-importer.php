<?php
/**
 * Importer — downloads a remote image, inserts it in the Media Library, and
 * writes alt/title/source metadata.
 *
 * The values written come straight from the Migration_Row (alt_text,
 * derived_title). Once an image is in the library, edit its ALT directly
 * from Média → Bibliothèque, then use the Synchro ALT tab to propagate the
 * change into post_content across all affected posts.
 *
 * @package WaasKitS3Migrator
 */

namespace WKS3M;

defined( 'ABSPATH' ) || exit;

class Importer {

	public const META_SOURCE_URL     = '_wks3m_source_url';
	public const META_SOURCE_HOST    = '_wks3m_source_host';
	/** Flag stored on attachments whose thumbnails were deferred at import. */
	public const META_THUMBS_PENDING = '_wks3m_thumbs_pending';

	private Downloader $downloader;

	public function __construct( ?Downloader $downloader = null ) {
		$this->downloader = $downloader ?? new Downloader();
	}

	/**
	 * Perform the import.
	 *
	 * @param Migration_Row $row
	 * @param bool|null     $defer_thumbnails When true, skips wp_generate_attachment_metadata()
	 *                                        and marks the attachment with META_THUMBS_PENDING.
	 *                                        Null = read from Settings.
	 *
	 * @return array{attachment_id:int,attachment_url:string}|\WP_Error
	 */
	public function import( Migration_Row $row, ?bool $defer_thumbnails = null ) {
		$source = $row->best_variant();
		if ( '' === $source ) {
			return new \WP_Error( 'wks3m_no_url', 'No source URL stored for this row.' );
		}

		$file = $this->downloader->download( $source );
		if ( is_wp_error( $file ) ) {
			Logger::error( 'Download failed', [ 'url' => $source, 'err' => $file->get_error_message() ] );
			return $file;
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$title = $row->derived_title();
		if ( '' === $title ) {
			$title = pathinfo( $file['filename'], PATHINFO_FILENAME );
		}

		$attach_id = wp_insert_attachment(
			[
				'post_mime_type' => $file['mime'],
				'post_title'     => $title,
				'post_content'   => '',
				'post_excerpt'   => '',
				'post_status'    => 'inherit',
			],
			$file['path'],
			0,
			true
		);
		if ( is_wp_error( $attach_id ) ) {
			@unlink( $file['path'] );
			Logger::error( 'wp_insert_attachment failed', [ 'err' => $attach_id->get_error_message() ] );
			return $attach_id;
		}

		$defer = $defer_thumbnails ?? Settings::defer_thumbnails();
		if ( $defer ) {
			// Store a minimal metadata record (file + width/height only, no sizes).
			// WordPress tolerates the empty "sizes" array and will display the
			// full-size image wherever thumbnails would normally be used.
			$uploads  = wp_upload_dir();
			$relpath  = ltrim( str_replace( trailingslashit( $uploads['basedir'] ), '', $file['path'] ), '/' );
			$dims     = @getimagesize( $file['path'] );
			wp_update_attachment_metadata( $attach_id, [
				'file'   => $relpath,
				'width'  => (int) ( $dims[0] ?? 0 ),
				'height' => (int) ( $dims[1] ?? 0 ),
				'sizes'  => [],
			] );
			update_post_meta( $attach_id, self::META_THUMBS_PENDING, 1 );
		} else {
			wp_update_attachment_metadata( $attach_id, wp_generate_attachment_metadata( $attach_id, $file['path'] ) );
		}

		$alt = $row->alt_text();
		if ( '' !== $alt ) {
			update_post_meta( $attach_id, '_wp_attachment_image_alt', $alt );
		}
		update_post_meta( $attach_id, self::META_SOURCE_URL, $source );
		if ( '' !== $row->source_host() ) {
			update_post_meta( $attach_id, self::META_SOURCE_HOST, $row->source_host() );
		}

		Logger::info( 'Imported attachment', [ 'id' => $attach_id, 'source' => $source ] );

		return [
			'attachment_id'  => (int) $attach_id,
			'attachment_url' => (string) wp_get_attachment_url( $attach_id ),
		];
	}

	/**
	 * Generate the missing thumbnails for one attachment previously imported
	 * with "defer thumbnails" enabled. Idempotent — clears the pending flag on
	 * success.
	 *
	 * @return true|\WP_Error
	 */
	public function finalize_thumbnails( int $attach_id ) {
		$path = get_attached_file( $attach_id );
		if ( ! $path || ! file_exists( $path ) ) {
			return new \WP_Error( 'wks3m_missing_file', sprintf( 'Attachment %d: file not found on disk.', $attach_id ) );
		}
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$meta = wp_generate_attachment_metadata( $attach_id, $path );
		if ( empty( $meta ) ) {
			return new \WP_Error( 'wks3m_meta_failed', sprintf( 'Could not generate metadata for attachment %d.', $attach_id ) );
		}
		wp_update_attachment_metadata( $attach_id, $meta );
		delete_post_meta( $attach_id, self::META_THUMBS_PENDING );
		return true;
	}

	/**
	 * Return the attachment IDs that need thumbnails regenerated.
	 *
	 * Two sources:
	 *   1. Attachments explicitly deferred at import time (META_THUMBS_PENDING
	 *      flag set by this plugin).
	 *   2. Attachments whose `_wp_attachment_metadata` is missing, empty, or
	 *      has no `sizes` array — happens when a past import failed midway
	 *      (memory limit, image library crash, etc.) leaving the original
	 *      file on disk but no derived sizes. The first source only captures
	 *      cases we know about; the second catches the real leaks.
	 *
	 * @return int[] Attachment IDs, most recent first.
	 */
	public static function pending_thumbnails_ids( int $limit = 5000 ): array {
		global $wpdb;
		$limit = max( 1, min( 20000, $limit ) );

		// Source 1: plugin's deferred flag.
		$flagged = $wpdb->get_col( $wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s",
			self::META_THUMBS_PENDING
		) );

		// Source 2: image attachments with missing/empty/unsized metadata.
		$rows = $wpdb->get_results(
			"SELECT p.ID, pm.meta_value
			 FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->postmeta} pm
			   ON pm.post_id = p.ID AND pm.meta_key = '_wp_attachment_metadata'
			 WHERE p.post_type = 'attachment'
			   AND p.post_mime_type LIKE 'image/%'",
			ARRAY_A
		);
		$orphans = [];
		foreach ( (array) $rows as $r ) {
			$raw = (string) $r['meta_value'];
			if ( '' === $raw ) {
				$orphans[] = (int) $r['ID'];
				continue;
			}
			$meta = @maybe_unserialize( $raw );
			if ( ! is_array( $meta ) || empty( $meta['sizes'] ) || ! is_array( $meta['sizes'] ) ) {
				$orphans[] = (int) $r['ID'];
			}
		}

		$ids = array_values( array_unique( array_map( 'intval', array_merge( (array) $flagged, $orphans ) ) ) );
		rsort( $ids, SORT_NUMERIC );
		return array_slice( $ids, 0, $limit );
	}
}
