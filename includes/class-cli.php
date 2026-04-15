<?php
/**
 * WP-CLI commands.
 *
 * Exposes headless migration via `wp wks3m …` so large parks (>2000 images)
 * can run without a browser session and without tying up an admin AJAX
 * worker. Use tmux/nohup to detach from the terminal; the site stays fully
 * responsive because PHP-FPM admin workers are untouched.
 *
 * @package WaasKitS3Migrator
 */

namespace WKS3M;

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * Migrate images from external sources into the WP Media Library.
 */
class CLI {

	/**
	 * Migrate pending rows.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<n>]
	 * : Maximum number of rows to process. Default: all pending.
	 *
	 * [--status=<status>]
	 * : Only rows in this status. Default: pending. One of pending|failed|imported.
	 *
	 * [--replace]
	 * : Also replace URLs in post_content after each successful import.
	 *
	 * [--defer-thumbnails]
	 * : Skip wp_generate_attachment_metadata() during import (run finalize-thumbnails later).
	 *
	 * [--dry-run]
	 * : Simulate without downloading or writing anything.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wks3m migrate --replace
	 *     wp wks3m migrate --status=failed --limit=100
	 *     wp wks3m migrate --defer-thumbnails --replace
	 *
	 * @when after_wp_load
	 */
	public function migrate( array $args, array $assoc ): void {
		$status  = isset( $assoc['status'] ) ? sanitize_key( (string) $assoc['status'] ) : 'pending';
		$limit   = isset( $assoc['limit'] ) ? max( 1, (int) $assoc['limit'] ) : 100000;
		$replace = ! empty( $assoc['replace'] );
		$defer   = ! empty( $assoc['defer-thumbnails'] );
		$dry     = ! empty( $assoc['dry-run'] );

		$store = Plugin::instance()->mapping_store();
		$ids   = $store->ids_by_status( $status, $limit );
		$total = count( $ids );
		if ( 0 === $total ) {
			\WP_CLI::success( "No rows with status '{$status}'." );
			return;
		}

		\WP_CLI::log( sprintf( '%d row(s) to process (status=%s, replace=%s, defer=%s, dry=%s)',
			$total, $status, $replace ? 'yes' : 'no', $defer ? 'yes' : 'no', $dry ? 'yes' : 'no' ) );

		$progress = \WP_CLI\Utils\make_progress_bar( 'Migrating', $total );
		$importer = new Importer();
		$replacer = new Replacer();
		$ok       = 0;
		$ko       = 0;

		foreach ( $ids as $row_id ) {
			$row = $store->get( (int) $row_id );
			if ( ! $row ) { $ko++; $progress->tick(); continue; }

			if ( $dry ) {
				$ok++; $progress->tick(); continue;
			}

			$attachment_id = $row->attachment_id();
			if ( ! $row->is_imported() ) {
				$res = $importer->import( $row, $defer );
				if ( is_wp_error( $res ) ) {
					$store->mark_failed( (int) $row_id, $res->get_error_message() );
					$ko++;
					$progress->tick();
					continue;
				}
				$attachment_id = (int) $res['attachment_id'];
				$store->mark_imported( (int) $row_id, $attachment_id );
				$row = $store->get( (int) $row_id );
			}

			if ( $replace && $row ) {
				$rep = $replacer->replace_for_row( $row, $attachment_id );
				if ( empty( $rep['errors'] ) && (int) $rep['posts_updated'] > 0 ) {
					$store->mark_replaced( (int) $row_id );
				}
			}

			$ok++;
			$progress->tick();
		}
		$progress->finish();

		\WP_CLI::success( sprintf( 'Done. ✔ %d · ✖ %d', $ok, $ko ) );
	}

	/**
	 * Generate missing thumbnails for attachments imported with --defer-thumbnails.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<n>]
	 * : Maximum number of attachments to process.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wks3m finalize-thumbnails
	 *     wp wks3m finalize-thumbnails --limit=500
	 *
	 * @subcommand finalize-thumbnails
	 * @when after_wp_load
	 */
	public function finalize_thumbnails( array $args, array $assoc ): void {
		$limit = isset( $assoc['limit'] ) ? max( 1, (int) $assoc['limit'] ) : 20000;
		$ids   = Importer::pending_thumbnails_ids( $limit );
		$total = count( $ids );
		if ( 0 === $total ) {
			\WP_CLI::success( 'No attachments pending thumbnail generation.' );
			return;
		}

		\WP_CLI::log( sprintf( '%d attachment(s) to finalize.', $total ) );
		$progress = \WP_CLI\Utils\make_progress_bar( 'Generating thumbnails', $total );
		$importer = new Importer();
		$ok       = 0;
		$ko       = 0;

		foreach ( $ids as $attach_id ) {
			$res = $importer->finalize_thumbnails( (int) $attach_id );
			if ( is_wp_error( $res ) ) {
				$ko++;
				\WP_CLI::warning( sprintf( '#%d: %s', $attach_id, $res->get_error_message() ) );
			} else {
				$ok++;
			}
			$progress->tick();
		}
		$progress->finish();

		\WP_CLI::success( sprintf( 'Done. ✔ %d · ✖ %d', $ok, $ko ) );
	}
}

\WP_CLI::add_command( 'wks3m', __NAMESPACE__ . '\\CLI' );
