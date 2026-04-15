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

	public const META_SOURCE_URL  = '_wks3m_source_url';
	public const META_SOURCE_HOST = '_wks3m_source_host';

	private Downloader $downloader;

	public function __construct( ?Downloader $downloader = null ) {
		$this->downloader = $downloader ?? new Downloader();
	}

	/**
	 * Return the (title, alt) pair to use for a given row, applying user options.
	 *
	 * @param array{use_alt_as_title?:bool,fill_empty_alts?:bool} $opts
	 * @return array{0:string,1:string} [title, alt]
	 */
	public static function resolve_title_and_alt( array $row, array $opts = [] ): array {
		$alt   = trim( (string) ( $row['alt_text'] ?? '' ) );
		$title = trim( (string) ( $row['derived_title'] ?? '' ) );

		if ( ! empty( $opts['use_alt_as_title'] ) && '' !== $alt ) {
			$title = $alt;
		}
		if ( ! empty( $opts['fill_empty_alts'] ) && '' === $alt && '' !== $title ) {
			$alt = $title;
		}
		return [ $title, $alt ];
	}

	/**
	 * Dry-run: report what would happen without touching the filesystem or DB.
	 */
	public function dry_run( array $row, array $opts = [] ): array {
		$variants          = Util::decode_json_list( $row['source_url_variants'] ?? '' );
		[ $title, $alt ]   = self::resolve_title_and_alt( $row, $opts );
		$source            = $this->pick_best_variant( $variants );
		return [
			'source_url'    => $source,
			'would_save_as' => sanitize_file_name( basename( parse_url( $source, PHP_URL_PATH ) ?: '' ) ),
			'post_title'    => $title,
			'alt_text'      => $alt,
		];
	}

	/**
	 * Perform a real import for a single migration log row.
	 *
	 * @param array{use_alt_as_title?:bool,fill_empty_alts?:bool} $opts
	 * @return array{attachment_id:int,attachment_url:string}|\WP_Error
	 */
	public function import( array $row, array $opts = [] ) {
		$variants = Util::decode_json_list( $row['source_url_variants'] ?? '' );
		if ( empty( $variants ) ) {
			return new \WP_Error( 'wks3m_no_url', 'No source URL stored for this row.' );
		}

		$source = $this->pick_best_variant( $variants );
		$file   = $this->downloader->download( $source );
		if ( is_wp_error( $file ) ) {
			Logger::error( 'Download failed', [ 'url' => $source, 'err' => $file->get_error_message() ] );
			return $file;
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		[ $title, $alt ] = self::resolve_title_and_alt( $row, $opts );
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

		wp_update_attachment_metadata( $attach_id, wp_generate_attachment_metadata( $attach_id, $file['path'] ) );

		if ( '' !== $alt ) {
			update_post_meta( $attach_id, '_wp_attachment_image_alt', $alt );
		}
		update_post_meta( $attach_id, self::META_SOURCE_URL, $source );
		if ( ! empty( $row['source_host'] ) ) {
			update_post_meta( $attach_id, self::META_SOURCE_HOST, (string) $row['source_host'] );
		}

		Logger::info( 'Imported attachment', [ 'id' => $attach_id, 'source' => $source ] );

		return [
			'attachment_id'  => (int) $attach_id,
			'attachment_url' => (string) wp_get_attachment_url( $attach_id ),
		];
	}

	/**
	 * Prefer the largest variant (large_ first, then any unprefixed, else first).
	 */
	private function pick_best_variant( array $variants ): string {
		foreach ( $variants as $v ) {
			if ( 0 === strpos( basename( (string) parse_url( $v, PHP_URL_PATH ) ), 'large_' ) ) {
				return $v;
			}
		}
		foreach ( $variants as $v ) {
			$name = basename( (string) parse_url( $v, PHP_URL_PATH ) );
			if ( 0 !== strpos( $name, 'medium_' )
				&& 0 !== strpos( $name, 'small_' )
				&& 0 !== strpos( $name, 'thumbnail_' )
			) {
				return $v;
			}
		}
		return (string) $variants[0];
	}
}
