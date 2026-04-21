<?php
/**
 * Alt_Scanner — find <img> tags whose alt attribute in post_content diverges
 * from _wp_attachment_image_alt on the resolved Media Library attachment.
 *
 * Scope: every published post/page/CPT whose post_content contains `<img`.
 * We do NOT restrict to posts referenced by the migration log — that
 * coupling left the feature useless after the user purged finished rows,
 * and missed pre-existing library images the user might also have edited.
 * The library is always the source of truth.
 *
 * Src resolution, in order:
 *   1. attachment_url_to_postid() — fast path for local uploads URLs (core).
 *   2. Migration-log variant index — for URLs still pointing to the original
 *      external source (S3/CDN) when URL replacement didn't cover them.
 *
 * Either way the pivot is the <img src>, NOT class="wp-image-N" — that class
 * is not a reliable identifier on migrated content.
 *
 * @package WaasKitS3Migrator
 */

namespace WKS3M;

defined( 'ABSPATH' ) || exit;

class Alt_Scanner {

	/** Option storing attachment IDs used in content whose library ALT is empty. */
	public const OPT_MISSING_ALT = 'wks3m_missing_alt_attachments';

	/**
	 * Copy an attachment's post_title into its `_wp_attachment_image_alt`
	 * postmeta, and drop the attachment from OPT_MISSING_ALT so it disappears
	 * from the "Images sans ALT" panel. Returns the alt that was written, or
	 * empty string on no-op (empty title / not an attachment).
	 *
	 * Does NOT update post_content — a rescan + normal sync is still needed
	 * to push the new alt into <img> tags.
	 */
	/**
	 * Library ALT to push to content.
	 *
	 * 1. Read raw ALT (postmeta).
	 * 2. When ALT is empty, fall back to the attachment post_title — but only
	 *    if it isn't a filename-derived auto-title (noise like "DSC_1234").
	 * 3. Last resort: when the library has nothing usable, fall back to the
	 *    value already in $content_alt_fallback so pages with an inline
	 *    <img alt> set by legacy blocks / SEO plugins aren't flagged as
	 *    divergent without a usable replacement.
	 *
	 * Title sync was removed — the syncer strips <img title> instead of
	 * pushing it. See class-alt-syncer.php.
	 */
	public static function library_values( int $attachment_id, string $src, string $content_alt_fallback = '' ): string {
		$alt   = trim( (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) );
		$title = trim( (string) get_the_title( $attachment_id ) );

		if ( '' !== $title && self::title_looks_like_filename( $title, $src ) ) {
			$title = '';
		}

		if ( '' === $alt && '' !== $title ) {
			$alt = $title;
		}

		if ( '' === $alt && '' !== $content_alt_fallback ) {
			$alt = $content_alt_fallback;
		}

		return $alt;
	}

	public static function fill_alt_from_title( int $attachment_id ): string {
		$att = get_post( $attachment_id );
		if ( ! $att || 'attachment' !== $att->post_type ) {
			return '';
		}
		$title = trim( (string) $att->post_title );
		if ( '' === $title ) {
			return '';
		}
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', $title );

		$missing = array_map( 'intval', (array) get_option( self::OPT_MISSING_ALT, [] ) );
		$missing = array_values( array_diff( $missing, [ $attachment_id ] ) );
		update_option( self::OPT_MISSING_ALT, $missing, false );

		return $title;
	}

	private Alt_Diff_Store $store;

	public function __construct( ?Alt_Diff_Store $store = null ) {
		$this->store = $store ?? new Alt_Diff_Store();
	}

	/**
	 * Scan a batch of posts with `<img>` in their content.
	 *
	 * Side effect: updates OPT_MISSING_ALT with attachment IDs whose library
	 * ALT is empty AND whose content alt is also empty — images that need a
	 * human to fill the ALT field in Média → Bibliothèque. Reset on offset=0.
	 *
	 * @return array{
	 *     processed:int, total:int, next_offset:int,
	 *     imgs_scanned:int, diffs_found:int, unresolved:int, missing_alt:int
	 * }
	 */
	public function scan_batch( int $offset = 0, int $limit = 50 ): array {
		global $wpdb;

		if ( 0 === $offset ) {
			update_option( self::OPT_MISSING_ALT, [], false );
		}

		$total = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts}
			 WHERE post_status IN ('publish','private','draft','pending','future')
			 AND post_type NOT IN ('revision','attachment','nav_menu_item')
			 AND post_content LIKE '%<img%'"
		);

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_content FROM {$wpdb->posts}
				 WHERE post_status IN ('publish','private','draft','pending','future')
				 AND post_type NOT IN ('revision','attachment','nav_menu_item')
				 AND post_content LIKE '%<img%'
				 ORDER BY ID ASC
				 LIMIT %d OFFSET %d",
				max( 1, $limit ),
				max( 0, $offset )
			),
			ARRAY_A
		);

		$url_index      = $this->variant_index();
		$processed      = 0;
		$imgs_scanned   = 0;
		$diffs_found    = 0;
		$unresolved     = 0;
		$missing_batch  = [];

		foreach ( (array) $rows as $r ) {
			$processed++;
			[ $imgs, $diffs, $unres, $missing_in_post ] = $this->scan_post( (int) $r['ID'], (string) $r['post_content'], $url_index );
			$imgs_scanned += $imgs;
			$diffs_found  += $diffs;
			$unresolved   += $unres;
			foreach ( $missing_in_post as $att ) {
				$missing_batch[ $att ] = true;
			}
		}

		if ( ! empty( $missing_batch ) ) {
			$current = array_map( 'intval', (array) get_option( self::OPT_MISSING_ALT, [] ) );
			$merged  = array_values( array_unique( array_merge( $current, array_keys( $missing_batch ) ) ) );
			sort( $merged, SORT_NUMERIC );
			update_option( self::OPT_MISSING_ALT, $merged, false );
		}

		return [
			'processed'    => $processed,
			'total'        => $total,
			'next_offset'  => $offset + $processed,
			'imgs_scanned' => $imgs_scanned,
			'diffs_found'  => $diffs_found,
			'unresolved'   => $unresolved,
			'missing_alt'  => count( (array) get_option( self::OPT_MISSING_ALT, [] ) ),
		];
	}

	/**
	 * @return array{0:int,1:int,2:int,3:int[]} [imgs_scanned, diffs_recorded, unresolved, attachment_ids_missing_alt]
	 */
	private function scan_post( int $post_id, string $content, array $url_index ): array {
		$imgs         = 0;
		$diffs        = 0;
		$unresolved   = 0;
		$missing      = [];
		$current_srcs = [];

		if ( ! preg_match_all( '#<img\b[^>]*>#i', $content, $m ) ) {
			$this->store->purge_resolved_for_post( $post_id, [] );
			return [ 0, 0, 0, [] ];
		}

		foreach ( $m[0] as $tag ) {
			$imgs++;
			if ( ! preg_match( '#\bsrc=(["\'])(.+?)\1#i', $tag, $sm ) ) {
				continue;
			}
			$src    = html_entity_decode( $sm[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			$att_id = $this->resolve_src( $src, $url_index );
			if ( $att_id <= 0 ) {
				$unresolved++;
				continue;
			}

			// Read what's in the content first so we can use content_alt as a
			// last-resort fallback when the library is fully empty.
			$content_alt = '';
			if ( preg_match( '#\balt=(["\'])(.*?)\1#is', $tag, $am ) ) {
				$content_alt = trim( html_entity_decode( $am[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
			}
			$content_title = '';
			if ( preg_match( '#\btitle=(["\'])(.*?)\1#is', $tag, $tm ) ) {
				$content_title = trim( html_entity_decode( $tm[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
			}

			// Library alt — filtered, with title fallback and content_alt
			// promoted as last resort so pages with an inline <img alt> but
			// empty Bibliothèque aren't flagged as divergent unnecessarily.
			$library_alt = self::library_values( $att_id, $src, $content_alt );

			// A divergence is anything the syncer will act on:
			//   - alt differs from the library side (only when the library
			//     side has something to push), OR
			//   - content still carries a <img title="…"> the syncer needs
			//     to strip (title sync was removed — all title attributes on
			//     matched tags get cleaned up on the next apply).
			$alt_diverges      = ( '' !== $library_alt ) && ( $library_alt !== $content_alt );
			$title_to_strip    = '' !== $content_title;

			if ( ! $alt_diverges && ! $title_to_strip ) {
				// If nothing diverges AND the library has no alt to propose,
				// the attachment still needs a human-written alt.
				if ( '' === $library_alt && '' === $content_alt ) {
					$missing[] = $att_id;
				}
				continue;
			}

			$this->store->upsert_diff(
				$post_id,
				$att_id,
				$src,
				$content_alt,
				$library_alt,
				$content_title
			);
			$current_srcs[] = $src;
			$diffs++;
		}

		$this->store->purge_resolved_for_post( $post_id, $current_srcs );
		return [ $imgs, $diffs, $unresolved, array_values( array_unique( $missing ) ) ];
	}

	/**
	 * Is a library post_title just a filename-derived auto-title?
	 *
	 * Heuristic: normalize both the title and the file stem to lowercase
	 * alphanumerics. If they match, the title was generated by either
	 * WordPress core or our own Importer::derive_title() — not a human
	 * description, so we don't push it as <img title> in HTML.
	 */
	public static function title_looks_like_filename( string $title, string $src ): bool {
		$path = (string) wp_parse_url( $src, PHP_URL_PATH );
		$stem = pathinfo( basename( $path ), PATHINFO_FILENAME );
		// Strip sized suffix and known WP markers.
		$stem = preg_replace( '/-\d+x\d+$/', '', $stem );
		$stem = preg_replace( '/-scaled$/', '', $stem );

		$n_title = strtolower( preg_replace( '/[^a-z0-9]+/i', '', $title ) );
		$n_stem  = strtolower( preg_replace( '/[^a-z0-9]+/i', '', (string) $stem ) );
		return '' !== $n_stem && $n_title === $n_stem;
	}

	/**
	 * Resolve a src URL to an attachment ID, three passes:
	 *   1. Core attachment_url_to_postid() — local uploads, sized variants.
	 *   2. Migration-log variant index — external S3/CDN URLs we imported.
	 *   3. Filename match against _wp_attached_file postmeta — catches local
	 *      URLs that core fails on (e.g. when attachment metadata is stale
	 *      or the sized variant isn't registered in _wp_attachment_metadata).
	 *      This is what rescues the typical "-1024x527.jpg" miss.
	 */
	private function resolve_src( string $src, array $url_index ): int {
		$id = (int) attachment_url_to_postid( $src );
		if ( $id > 0 ) {
			return $id;
		}
		$id = (int) ( $url_index[ $src ] ?? 0 );
		if ( $id > 0 ) {
			return $id;
		}
		return $this->resolve_by_filename( $src );
	}

	/**
	 * Third-pass resolver — filename lookup in `_wp_attached_file` postmeta.
	 *
	 * Strips the `-WIDTHxHEIGHT` size suffix AND matches `-scaled` variants
	 * (WordPress auto-generates `foo-scaled.ext` for images over the
	 * big_image_size_threshold — 2560 px by default — while <img> tags in
	 * content may still reference `foo-1024x683.ext`).
	 *
	 * Cached per-request.
	 */
	private function resolve_by_filename( string $src ): int {
		static $cache = [];
		$path = (string) wp_parse_url( $src, PHP_URL_PATH );
		if ( '' === $path ) {
			return 0;
		}
		$filename = basename( $path );
		if ( isset( $cache[ $filename ] ) ) {
			return $cache[ $filename ];
		}
		// Strip sized variant and extract stem (no extension, no -scaled).
		$base = preg_replace( '/-\d+x\d+(?=\.[a-z0-9]{2,5}$)/i', '', $filename );
		$stem = preg_replace( '/\.[a-z0-9]{2,5}$/i', '', $base );
		if ( '' === $stem ) {
			return $cache[ $filename ] = 0;
		}

		global $wpdb;
		$like1 = '%/' . $wpdb->esc_like( $stem ) . '.%';            // foo.ext
		$like2 = '%/' . $wpdb->esc_like( $stem . '-scaled' ) . '.%'; // foo-scaled.ext
		$id    = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta}
				 WHERE meta_key = '_wp_attached_file'
				   AND ( meta_value LIKE %s OR meta_value LIKE %s )
				 LIMIT 1",
				$like1,
				$like2
			)
		);
		$cache[ $filename ] = $id;
		return $id;
	}

	/**
	 * Map of external-variant URL → attachment_id, built from the migration
	 * log. Empty if the log has been purged — that's fine, the primary
	 * attachment_url_to_postid() path handles local URLs.
	 *
	 * @return array<string,int>
	 */
	private function variant_index(): array {
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}
		global $wpdb;
		$table = Activator::table_name();
		$rows  = $wpdb->get_results(
			"SELECT attachment_id, source_url_variants FROM {$table}
			 WHERE attachment_id IS NOT NULL AND source_url_variants IS NOT NULL",
			ARRAY_A
		);
		$index = [];
		foreach ( (array) $rows as $r ) {
			$att = (int) $r['attachment_id'];
			if ( $att <= 0 ) {
				continue;
			}
			$variants = json_decode( (string) $r['source_url_variants'], true );
			if ( ! is_array( $variants ) ) {
				continue;
			}
			foreach ( $variants as $v ) {
				$v = is_string( $v ) ? $v : '';
				if ( '' === $v ) {
					continue;
				}
				$index[ $v ] = $att;
			}
		}
		$cache = $index;
		return $cache;
	}
}
