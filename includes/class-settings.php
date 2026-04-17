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

	/**
	 * Number of parallel HTTP workers. Fixed at 1 — sequential processing is
	 * the safest default and removes a source of surprise for end users.
	 */
	public static function concurrency(): int {
		return 1;
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
	 * Fixed at 3 — a sensible default that handles transient 5xx without
	 * drowning slow sources.
	 */
	public static function download_retries(): int {
		return 3;
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

	/**
	 * Auto-detect any external image host when `source_hosts` is empty.
	 * Always on — the old opt-out checkbox was noise; scanning nothing when
	 * no hosts are configured never helped anyone.
	 */
	public static function auto_detect_external(): bool {
		return true;
	}

	/**
	 * Group Strapi size variants (large_, medium_, small_, thumbnail_) as a
	 * single image. Always on — no reason to treat these as separate images.
	 */
	public static function strip_strapi_prefixes(): bool {
		return true;
	}

	/**
	 * Host of the current site — used to exclude internal image URLs.
	 */
	public static function site_host(): string {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		return strtolower( (string) $host );
	}
}
