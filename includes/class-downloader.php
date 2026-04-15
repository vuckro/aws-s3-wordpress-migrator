<?php
/**
 * Downloader — fetch a remote image to the WP uploads directory.
 *
 * @package WaasKitS3Migrator
 */

namespace WKS3M;

defined( 'ABSPATH' ) || exit;

class Downloader {

	/**
	 * Download a URL into the uploads directory.
	 *
	 * @return array{path:string,url:string,mime:string,filename:string}|\WP_Error
	 */
	public function download( string $url ) {
		if ( ! wp_http_validate_url( $url ) ) {
			return new \WP_Error( 'wks3m_invalid_url', sprintf( 'Invalid URL: %s', $url ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';

		$tmp = $this->download_with_retry( $url );
		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}

		$filename = $this->preferred_filename( $url );
		$filetype = wp_check_filetype_and_ext( $tmp, $filename );
		if ( empty( $filetype['type'] ) ) {
			@unlink( $tmp );
			return new \WP_Error( 'wks3m_bad_mime', sprintf( 'Unknown or disallowed MIME for %s', $url ) );
		}

		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			@unlink( $tmp );
			return new \WP_Error( 'wks3m_uploads_dir', $uploads['error'] );
		}

		$unique = wp_unique_filename( $uploads['path'], $filename );
		$dest   = trailingslashit( $uploads['path'] ) . $unique;

		if ( ! @rename( $tmp, $dest ) ) {
			if ( ! @copy( $tmp, $dest ) ) {
				@unlink( $tmp );
				return new \WP_Error( 'wks3m_move_failed', sprintf( 'Could not move file to %s', $dest ) );
			}
			@unlink( $tmp );
		}

		return [
			'path'     => $dest,
			'url'      => trailingslashit( $uploads['url'] ) . $unique,
			'mime'     => (string) $filetype['type'],
			'filename' => $unique,
		];
	}

	/**
	 * Attempt download_url() up to N times with exponential backoff.
	 * Retries only on transient errors (timeouts, DNS, 5xx). Client errors (4xx)
	 * bail out immediately since retrying won't help.
	 *
	 * @return string|\WP_Error Temp file path on success, WP_Error on final failure.
	 */
	private function download_with_retry( string $url ) {
		$attempts = Settings::download_retries();
		$last     = null;

		for ( $i = 1; $i <= $attempts; $i++ ) {
			$tmp = download_url( $url, 60 );
			if ( ! is_wp_error( $tmp ) ) {
				return $tmp;
			}
			$last = $tmp;

			// Do not retry on client errors — they won't recover.
			$code = (string) $last->get_error_code();
			$msg  = (string) $last->get_error_message();
			if ( preg_match( '/\b(4\d\d)\b/', $msg ) && ! preg_match( '/\b(408|429)\b/', $msg ) ) {
				break;
			}
			if ( in_array( $code, [ 'http_no_url', 'http_request_failed_permanently' ], true ) ) {
				break;
			}

			if ( $i < $attempts ) {
				// Exponential backoff: 1s, 2s, 4s… capped at 8s.
				$sleep = min( 8, (int) pow( 2, $i - 1 ) );
				Logger::info( 'Download retry', [ 'url' => $url, 'attempt' => $i, 'sleep' => $sleep, 'err' => $msg ] );
				sleep( $sleep );
			}
		}

		return $last ?: new \WP_Error( 'wks3m_download_failed', 'Download failed after retries' );
	}

	/** Strip Strapi size prefix when configured, then sanitize. */
	private function preferred_filename( string $url ): string {
		$name = Url_Helper::base_key( $url, Settings::strip_strapi_prefixes() );
		return sanitize_file_name( $name );
	}
}
