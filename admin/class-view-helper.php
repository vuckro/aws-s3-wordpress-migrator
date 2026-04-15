<?php
/**
 * View_Helper — shared template helpers used by admin views.
 *
 * Removes the duplication that was creeping into the Queue and History
 * page templates for status pills, post-id link lists and thumbnails.
 *
 * @package WaasKitS3Migrator
 */

namespace WKS3M\Admin;

use WKS3M\Migration_Row;

defined( 'ABSPATH' ) || exit;

class View_Helper {

	/** URL to a specific sub-tab (e.g. queue or history) with a status filter. */
	public static function tab_url( string $tab, array $extra = [] ): string {
		$args = array_merge(
			[ 'page' => 'wks3m', 'tab' => $tab ],
			array_filter( $extra, static fn( $v ) => null !== $v && '' !== $v )
		);
		return esc_url( admin_url( 'tools.php?' . http_build_query( $args ) ) );
	}

	public static function status_pill( string $status ): string {
		$labels = [
			'pending'     => __( 'En attente', 'waaskit-s3-migrator' ),
			'imported'    => __( 'Importée', 'waaskit-s3-migrator' ),
			'replaced'    => __( 'Remplacée', 'waaskit-s3-migrator' ),
			'rolled_back' => __( 'Rollback', 'waaskit-s3-migrator' ),
			'failed'      => __( 'Échec', 'waaskit-s3-migrator' ),
		];
		$label = $labels[ $status ] ?? ucfirst( $status );
		return sprintf(
			'<span class="wks3m-status wks3m-status-%s">%s</span>',
			esc_attr( $status ),
			esc_html( $label )
		);
	}

	public static function posts_links( array $post_ids, int $max = 5 ): string {
		if ( empty( $post_ids ) ) {
			return '';
		}
		$html  = '';
		$shown = array_slice( $post_ids, 0, $max );
		foreach ( $shown as $pid ) {
			$html .= sprintf(
				'<a href="%s" target="_blank">#%d</a> ',
				esc_url( admin_url( 'post.php?post=' . (int) $pid . '&action=edit' ) ),
				(int) $pid
			);
		}
		if ( count( $post_ids ) > $max ) {
			$html .= '<span class="wks3m-more">+' . ( count( $post_ids ) - $max ) . '</span>';
		}
		return $html;
	}

	public static function thumb_html( ?int $attachment_id, string $fallback_url = '' ): string {
		if ( $attachment_id ) {
			$img = wp_get_attachment_image( $attachment_id, [ 56, 56 ] );
			if ( $img ) {
				return $img;
			}
		}
		if ( '' !== $fallback_url ) {
			return '<img loading="lazy" src="' . esc_url( $fallback_url ) . '" alt="" class="wks3m-thumb" />';
		}
		return '';
	}

	/**
	 * Render the pagination block commonly shared by Queue + History.
	 */
	public static function pagination( int $total_pages, int $current_page ): void {
		if ( $total_pages < 2 ) {
			return;
		}
		?>
		<div class="tablenav bottom">
			<div class="tablenav-pages">
				<?php
				echo paginate_links( [
					'base'      => add_query_arg( 'paged', '%#%' ),
					'format'    => '',
					'total'     => $total_pages,
					'current'   => $current_page,
					'prev_text' => '‹',
					'next_text' => '›',
				] );
				?>
			</div>
		</div>
		<?php
	}

	/** Hydrate a list of raw DB rows into Migration_Row value objects. */
	public static function wrap_rows( array $items ): array {
		return array_map( static fn( $r ) => new Migration_Row( (array) $r ), $items );
	}
}
