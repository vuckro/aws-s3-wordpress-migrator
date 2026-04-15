<?php
/**
 * Settings — centralized access to plugin options.
 *
 * @package WaasKitS3Migrator
 */

namespace WKS3M;

defined( 'ABSPATH' ) || exit;

class Settings {

	/** Image file extensions considered migratable. */
	public const IMAGE_EXTENSIONS = [ 'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'avif' ];

	/** Size prefixes Strapi emits for CDN image variants. */
	public const STRAPI_SIZE_PREFIXES = [ 'large_', 'medium_', 'small_', 'thumbnail_' ];

	public static function dry_run(): bool {
		return (bool) get_option( 'wks3m_dry_run', 1 );
	}

	public static function batch_size(): int {
		return max( 1, (int) get_option( 'wks3m_batch_size', 10 ) );
	}

	/**
	 * Number of parallel HTTP workers for bulk migration (client-side).
	 * Clamped to [1,6] to avoid hammering source CDNs and saturating the host.
	 */
	public static function concurrency(): int {
		return max( 1, min( 6, (int) get_option( 'wks3m_concurrency', 3 ) ) );
	}

	/**
	 * When true, wp_generate_attachment_metadata() is skipped during import.
	 * Thumbnails are regenerated later via the "Finaliser les thumbnails" action
	 * (or by running `wp media regenerate --only-missing`).
	 */
	public static function defer_thumbnails(): bool {
		return (bool) get_option( 'wks3m_defer_thumbnails', 0 );
	}

	/**
	 * Max download attempts per image (1 = no retry). Backoff grows exponentially.
	 */
	public static function download_retries(): int {
		return max( 1, min( 5, (int) get_option( 'wks3m_download_retries', 3 ) ) );
	}

	/**
	 * List of hostnames the user wants to scan for.
	 * When empty, the scanner uses auto-detection (any external host).
	 *
	 * @return string[]
	 */
	public static function source_hosts(): array {
		$raw = get_option( 'wks3m_source_hosts', [] );
		if ( is_string( $raw ) ) {
			$raw = preg_split( '/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY );
		}
		if ( ! is_array( $raw ) ) {
			return [];
		}
		$out = [];
		foreach ( $raw as $h ) {
			$h = trim( (string) $h );
			if ( '' === $h ) {
				continue;
			}
			// Accept full URLs as well; extract host.
			if ( false !== strpos( $h, '://' ) ) {
				$p = wp_parse_url( $h, PHP_URL_HOST );
				if ( $p ) {
					$h = $p;
				}
			}
			$out[] = strtolower( $h );
		}
		return array_values( array_unique( $out ) );
	}

	public static function set_source_hosts( array $hosts ): void {
		update_option( 'wks3m_source_hosts', array_values( array_unique( array_map( 'strtolower', array_map( 'trim', $hosts ) ) ) ) );
	}

	public static function auto_detect_external(): bool {
		return (bool) get_option( 'wks3m_auto_detect_external', 1 );
	}

	public static function strip_strapi_prefixes(): bool {
		return (bool) get_option( 'wks3m_strip_strapi_prefixes', 1 );
	}

	/**
	 * Host of the current site — used to exclude internal image URLs.
	 */
	public static function site_host(): string {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		return strtolower( (string) $host );
	}
}
