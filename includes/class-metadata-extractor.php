<?php
/**
 * Metadata Extractor — pulls <img> alt attributes from post_content and
 * derives a human-readable title from the filename.
 *
 * @package WaasKitS3Migrator
 */

namespace WKS3M;

defined( 'ABSPATH' ) || exit;

class Metadata_Extractor {

	/**
	 * Find the alt text of the first <img> that references any of the given URLs.
	 *
	 * Uses a regex rather than DOMDocument so serialized Gutenberg block JSON
	 * (with escaped slashes) is matched reliably.
	 *
	 * @param string[] $urls Full URLs (all variants) to search for.
	 */
	public static function extract_alt( string $content, array $urls ): string {
		if ( '' === $content || empty( $urls ) ) {
			return '';
		}
		// Build a set of URL fragments to search for, including the JSON-escaped form.
		$needles = [];
		foreach ( $urls as $u ) {
			$needles[] = $u;
			$needles[] = str_replace( '/', '\\/', $u );
		}

		// Match every <img ...> tag in the content.
		if ( ! preg_match_all( '#<img\b[^>]*>#i', $content, $tags ) ) {
			return '';
		}
		foreach ( $tags[0] as $tag ) {
			$has_needle = false;
			foreach ( $needles as $n ) {
				if ( false !== strpos( $tag, $n ) ) {
					$has_needle = true;
					break;
				}
			}
			if ( ! $has_needle ) {
				continue;
			}
			if ( preg_match( '#\balt\s*=\s*(["\'])(.*?)\1#is', $tag, $m ) ) {
				$alt = html_entity_decode( $m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				$alt = trim( preg_replace( '/\s+/u', ' ', $alt ) );
				if ( '' !== $alt ) {
					return $alt;
				}
			}
		}
		return '';
	}

	/**
	 * Derive a readable title from the file's base name.
	 *
	 * Strips:
	 *   - known size prefixes (large_/medium_/small_/thumbnail_) if enabled
	 *   - trailing Strapi-style hash: _[a-f0-9]{6,}
	 *   - file extension
	 * Then replaces [_\-.] with spaces and applies ucwords().
	 */
	public static function derive_title( string $filename ): string {
		$name = $filename;
		if ( Settings::strip_strapi_prefixes() ) {
			foreach ( Settings::STRAPI_SIZE_PREFIXES as $prefix ) {
				if ( 0 === strpos( $name, $prefix ) ) {
					$name = substr( $name, strlen( $prefix ) );
					break;
				}
			}
		}
		// Drop extension.
		$name = preg_replace( '/\.[a-z0-9]{2,5}$/i', '', $name );
		// Drop trailing Strapi-style hash (min 6 hex chars).
		$name = preg_replace( '/[_\-][a-f0-9]{6,}$/i', '', $name );
		// Normalise separators.
		$name = preg_replace( '/[_\-\.]+/', ' ', $name );
		$name = trim( preg_replace( '/\s+/u', ' ', $name ) );
		return $name ? ucwords( mb_strtolower( $name ) ) : '';
	}
}
