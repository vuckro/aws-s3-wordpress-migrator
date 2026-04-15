<?php
/**
 * Url_Helper — single source of truth for URL / filename parsing logic.
 *
 * Centralizes the knowledge that used to be duplicated across Scanner,
 * Importer, Replacer and Downloader: how to extract a basename, detect a
 * Strapi size prefix, map a prefix to a WordPress image size, and pick the
 * best variant among a group.
 *
 * @package WaasKitS3Migrator
 */

namespace WKS3M;

defined( 'ABSPATH' ) || exit;

class Url_Helper {

	/** Strapi size prefixes, biggest first — drives best-variant selection. */
	public const SIZE_PREFIXES = [ 'large_', 'medium_', 'small_', 'thumbnail_' ];

	/** Maps a Strapi prefix to the closest WordPress-generated image size. */
	public const WP_SIZE_FOR_PREFIX = [
		'large_'     => 'full',
		'medium_'    => 'medium_large',
		'small_'     => 'medium',
		'thumbnail_' => 'thumbnail',
	];

	/** Download priority — large > unprefixed > medium > small > thumbnail. */
	private const VARIANT_RANK = [
		'large_'     => 4,
		''           => 3,
		'medium_'    => 2,
		'small_'     => 1,
		'thumbnail_' => 0,
	];

	public static function host( string $url ): string {
		return strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
	}

	public static function filename( string $url ): string {
		return basename( (string) wp_parse_url( $url, PHP_URL_PATH ) );
	}

	/**
	 * Detect a known size prefix. Returns [prefix, stripped_filename].
	 *
	 * @return array{0:?string,1:string}
	 */
	public static function split_prefix( string $filename ): array {
		foreach ( self::SIZE_PREFIXES as $prefix ) {
			if ( 0 === strpos( $filename, $prefix ) ) {
				return [ $prefix, substr( $filename, strlen( $prefix ) ) ];
			}
		}
		return [ null, $filename ];
	}

	/**
	 * Canonical base key for dedup (filename without size prefix when enabled).
	 */
	public static function base_key( string $url, bool $strip_prefixes = true ): string {
		$filename = self::filename( $url );
		if ( $strip_prefixes ) {
			[ , $filename ] = self::split_prefix( $filename );
		}
		return $filename;
	}

	/** host|filename composite key for grouping across hosts. */
	public static function composite_key( string $url, bool $strip_prefixes = true ): string {
		return self::host( $url ) . '|' . self::base_key( $url, $strip_prefixes );
	}

	/** Map a filename's prefix to the WP size to use on replacement. */
	public static function wp_size_for_filename( string $filename ): string {
		[ $prefix ] = self::split_prefix( $filename );
		return $prefix && isset( self::WP_SIZE_FOR_PREFIX[ $prefix ] )
			? self::WP_SIZE_FOR_PREFIX[ $prefix ]
			: 'full';
	}

	/**
	 * Pick the variant to download: large > unprefixed > medium > small > thumb.
	 */
	public static function pick_best_variant( array $variants ): string {
		if ( empty( $variants ) ) {
			return '';
		}
		$best      = (string) $variants[0];
		$best_rank = -1;
		foreach ( $variants as $v ) {
			[ $prefix ] = self::split_prefix( self::filename( (string) $v ) );
			$rank       = self::VARIANT_RANK[ $prefix ?? '' ] ?? -1;
			if ( $rank > $best_rank ) {
				$best      = (string) $v;
				$best_rank = $rank;
			}
		}
		return $best;
	}
}
