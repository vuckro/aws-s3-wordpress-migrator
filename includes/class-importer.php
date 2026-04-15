<?php
/**
 * Importer — downloads a remote image, inserts it in the Media Library, and
 * writes alt/title/source metadata.
 *
 * The values written come straight from the Migration_Row (alt_text,
 * derived_title). To influence them, use the Transform tool on the Settings
 * tab before importing — the import itself has no transformation knobs.
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
	 * Simulate an import — returns what would be written. No side effects.
	 */
	public function dry_run( Migration_Row $row ): array {
		$source = $row->best_variant();
		return [
			'source_url'    => $source,
			'would_save_as' => sanitize_file_name( Url_Helper::base_key( $source, Settings::strip_strapi_prefixes() ) ),
			'post_title'    => $row->derived_title(),
			'alt_text'      => $row->alt_text(),
		];
	}

	/**
	 * Perform a real import.
	 *
	 * @return array{attachment_id:int,attachment_url:string}|\WP_Error
	 */
	public function import( Migration_Row $row ) {
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

		wp_update_attachment_metadata( $attach_id, wp_generate_attachment_metadata( $attach_id, $file['path'] ) );

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
}
