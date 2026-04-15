<?php
/**
 * Migration_Row — typed value object wrapping a migration log DB row.
 *
 * Decodes JSON columns lazily and exposes every field as a method, so callers
 * never have to json_decode / (int) / (string) the raw array themselves.
 *
 * @package WaasKitS3Migrator
 */

namespace WKS3M;

defined( 'ABSPATH' ) || exit;

class Migration_Row {

	/** @var array<string,mixed> */
	private array $data;

	/** @var string[]|null */
	private ?array $variants_cache = null;

	/** @var int[]|null */
	private ?array $post_ids_cache = null;

	public function __construct( array $data ) {
		$this->data = $data;
	}

	public static function from_array( ?array $row ): ?self {
		return null === $row ? null : new self( $row );
	}

	public function id(): int                 { return (int) ( $this->data['id'] ?? 0 ); }
	public function status(): string          { return (string) ( $this->data['status'] ?? 'pending' ); }
	public function attachment_id(): int      { return (int) ( $this->data['attachment_id'] ?? 0 ); }
	public function source_host(): string     { return (string) ( $this->data['source_host'] ?? '' ); }
	public function source_url_base(): string { return (string) ( $this->data['source_url_base'] ?? '' ); }
	public function base_key(): string        { return (string) ( $this->data['base_key'] ?? '' ); }
	public function alt_text(): string        { return trim( (string) ( $this->data['alt_text'] ?? '' ) ); }
	public function derived_title(): string   { return trim( (string) ( $this->data['derived_title'] ?? '' ) ); }
	public function error_message(): string   { return (string) ( $this->data['error_message'] ?? '' ); }

	/** @return string[] */
	public function variants(): array {
		if ( null === $this->variants_cache ) {
			$this->variants_cache = Util::decode_json_list( (string) ( $this->data['source_url_variants'] ?? '' ) );
		}
		return $this->variants_cache;
	}

	/** @return int[] */
	public function post_ids(): array {
		if ( null === $this->post_ids_cache ) {
			$raw  = (string) ( $this->data['post_ids'] ?? '' );
			$list = '' === $raw ? [] : json_decode( $raw, true );
			$this->post_ids_cache = is_array( $list ) ? array_map( 'intval', $list ) : [];
		}
		return $this->post_ids_cache;
	}

	public function best_variant(): string {
		return Url_Helper::pick_best_variant( $this->variants() );
	}

	public function is_imported(): bool {
		return in_array( $this->status(), [ 'imported', 'replaced' ], true ) && $this->attachment_id() > 0;
	}

	public function to_array(): array {
		return $this->data;
	}
}
