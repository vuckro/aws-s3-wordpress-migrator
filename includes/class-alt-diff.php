<?php
/**
 * Alt_Diff — typed value object wrapping a wks3m_alt_diff row.
 *
 * One row per (post_id, src) pair where <img alt> in post_content diverges
 * from the library's _wp_attachment_image_alt on the resolved attachment.
 *
 * @package WaasKitS3Migrator
 */

namespace WKS3M;

defined( 'ABSPATH' ) || exit;

class Alt_Diff {

	/** @var array<string,mixed> */
	private array $data;

	public function __construct( array $data ) {
		$this->data = $data;
	}

	public static function from_array( ?array $row ): ?self {
		return null === $row ? null : new self( $row );
	}

	public function id(): int            { return (int) ( $this->data['id'] ?? 0 ); }
	public function post_id(): int       { return (int) ( $this->data['post_id'] ?? 0 ); }
	public function attachment_id(): int { return (int) ( $this->data['attachment_id'] ?? 0 ); }
	public function src(): string        { return (string) ( $this->data['src'] ?? '' ); }
	public function content_alt(): string { return (string) ( $this->data['content_alt'] ?? '' ); }
	public function library_alt(): string { return (string) ( $this->data['library_alt'] ?? '' ); }
	public function status(): string     { return (string) ( $this->data['status'] ?? 'diff' ); }
	public function error_message(): string { return (string) ( $this->data['error_message'] ?? '' ); }

	public function to_array(): array {
		return $this->data;
	}
}
