<?php
/**
 * Importer — takes a downloaded file + metadata and creates a Media Library
 * attachment with alt, title and derived caption.
 *
 * @package WaasKitS3Migrator
 */

namespace WKS3M;

defined( 'ABSPATH' ) || exit;

class Importer {

	private Downloader $downloader;

	public function __construct( ?Downloader $downloader = null ) {
		$this->downloader = $downloader ?? new Downloader();
	}

	/**
	 * Dry-run a single migration: report what would happen without touching
	 * the filesystem or DB. Returns the payload we *would* insert.
	 */
	public function dry_run( array $row ): array {
		$variants = $this->decode_list( $row['source_url_variants'] ?? '' );
		return [
			'source_url'    => $variants[0] ?? '',
			'would_save_as' => sanitize_file_name( basename( parse_url( $variants[0] ?? '', PHP_URL_PATH ) ?: '' ) ),
			'alt_text'      => (string) ( $row['alt_text'] ?? '' ),
			'post_title'    => (string) ( $row['derived_title'] ?? '' ),
			'post_excerpt'  => '', // caption — empty per project decision (HTML alt only).
			'post_content'  => '', // description.
		];
	}

	/**
	 * Perform a real import for a single migration log row.
	 *
	 * @return array{attachment_id:int,attachment_url:string}|\WP_Error
	 */
	public function import( array $row ) {
		$variants = $this->decode_list( $row['source_url_variants'] ?? '' );
		if ( empty( $variants ) ) {
			return new \WP_Error( 'wks3m_no_url', 'No source URL stored for this row.' );
		}

		// Prefer the largest variant (large_ if present, else first).
		$source = $this->pick_best_variant( $variants );
		$file   = $this->downloader->download( $source );
		if ( is_wp_error( $file ) ) {
			return $file;
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$attachment = [
			'post_mime_type' => $file['mime'],
			'post_title'     => (string) ( $row['derived_title'] ?? pathinfo( $file['filename'], PATHINFO_FILENAME ) ),
			'post_content'   => '',
			'post_excerpt'   => '',
			'post_status'    => 'inherit',
		];

		$attach_id = wp_insert_attachment( $attachment, $file['path'], 0, true );
		if ( is_wp_error( $attach_id ) ) {
			@unlink( $file['path'] );
			return $attach_id;
		}

		$meta = wp_generate_attachment_metadata( $attach_id, $file['path'] );
		wp_update_attachment_metadata( $attach_id, $meta );

		$alt = (string) ( $row['alt_text'] ?? '' );
		if ( '' !== $alt ) {
			update_post_meta( $attach_id, '_wp_attachment_image_alt', $alt );
		}
		update_post_meta( $attach_id, '_wks3m_source_url', $source );
		if ( ! empty( $row['source_host'] ) ) {
			update_post_meta( $attach_id, '_wks3m_source_host', (string) $row['source_host'] );
		}

		return [
			'attachment_id'  => (int) $attach_id,
			'attachment_url' => (string) wp_get_attachment_url( $attach_id ),
		];
	}

	private function decode_list( string $json ): array {
		$list = json_decode( $json, true );
		return is_array( $list ) ? array_values( array_unique( array_map( 'strval', $list ) ) ) : [];
	}

	private function pick_best_variant( array $variants ): string {
		foreach ( $variants as $v ) {
			if ( 0 === strpos( basename( (string) parse_url( $v, PHP_URL_PATH ) ), 'large_' ) ) {
				return $v;
			}
		}
		return (string) $variants[0];
	}
}
