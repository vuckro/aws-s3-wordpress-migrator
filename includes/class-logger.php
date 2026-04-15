<?php
/**
 * Simple logger wrapper.
 *
 * @package ClaireexploreS3Migrator
 */

namespace CXS3M;

defined( 'ABSPATH' ) || exit;

class Logger {

	public static function debug( string $message, array $context = [] ): void {
		if ( ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
			return;
		}
		self::write( 'DEBUG', $message, $context );
	}

	public static function info( string $message, array $context = [] ): void {
		self::write( 'INFO', $message, $context );
	}

	public static function error( string $message, array $context = [] ): void {
		self::write( 'ERROR', $message, $context );
	}

	private static function write( string $level, string $message, array $context ): void {
		$line = sprintf( '[CXS3M][%s] %s', $level, $message );
		if ( ! empty( $context ) ) {
			$line .= ' ' . wp_json_encode( $context );
		}
		error_log( $line );
	}
}
