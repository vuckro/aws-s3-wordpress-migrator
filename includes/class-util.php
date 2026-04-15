<?php
/**
 * Small static utilities shared across the plugin.
 *
 * @package WaasKitS3Migrator
 */

namespace WKS3M;

defined( 'ABSPATH' ) || exit;

class Util {

	/**
	 * Decode a JSON array column safely.
	 *
	 * @return string[]
	 */
	public static function decode_json_list( string $json ): array {
		if ( '' === $json ) {
			return [];
		}
		$list = json_decode( $json, true );
		if ( ! is_array( $list ) ) {
			return [];
		}
		return array_values( array_unique( array_map( 'strval', $list ) ) );
	}

	/**
	 * Boolean coercion for AJAX input (handles "0"/"1"/"true"/"false"/""/null).
	 */
	public static function bool_param( $value, bool $default = false ): bool {
		if ( null === $value || '' === $value ) {
			return $default;
		}
		if ( is_bool( $value ) ) {
			return $value;
		}
		$v = is_string( $value ) ? strtolower( trim( $value ) ) : $value;
		return in_array( $v, [ '1', 1, true, 'true', 'yes', 'on' ], true );
	}
}
