<?php
/**
 * Activation / deactivation hooks.
 *
 * @package WaasKitS3Migrator
 */

namespace WKS3M;

defined( 'ABSPATH' ) || exit;

class Activator {

	public const TABLE_VERSION_OPTION = 'wks3m_db_version';
	public const CURRENT_DB_VERSION   = '1.9.0';

	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'wks3m_migration_log';
	}

	public static function alt_diff_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'wks3m_alt_diff';
	}

	public static function activate(): void {
		self::install_tables();
		self::install_default_options();
	}

	/**
	 * Seed default option values. Safe to call repeatedly — add_option() is a no-op
	 * when the option already exists.
	 */
	public static function install_default_options(): void {
		add_option( 'wks3m_source_hosts', [] );
		add_option( 'wks3m_defer_thumbnails', 0 );
	}

	public static function deactivate(): void {
		// Keep data in place. Uninstall.php drops tables.
	}

	/**
	 * Install / upgrade schema tables in one pass. dbDelta() is idempotent.
	 *
	 * Upgrade paths:
	 *  - from < 1.5.0: drop legacy wks3m_alt_diff (had status/applied/rolled_back
	 *    columns that no longer exist); next scan rebuilds it.
	 *  - from < 1.7.0: drop wks3m_alt_history (history feature removed) and
	 *    delete leftover _wks3m_backup_* + _wks3m_replacements postmeta that the
	 *    replacer used to write for rollback (feature removed).
	 */
	public static function install_tables(): void {
		global $wpdb;
		$prev_version = (string) get_option( self::TABLE_VERSION_OPTION, '' );

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		self::install_migration_log_table();

		if ( '' !== $prev_version && version_compare( $prev_version, '1.5.0', '<' ) ) {
			$wpdb->query( 'DROP TABLE IF EXISTS ' . self::alt_diff_table_name() );
		}
		self::install_alt_diff_table();

		if ( '' !== $prev_version && version_compare( $prev_version, '1.7.0', '<' ) ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wks3m_alt_history" );
			// Free postmeta the removed rollback feature was writing to.
			$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '\\_wks3m\\_backup\\_%'" );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s", '_wks3m_replacements' ) );
		}

		if ( '' !== $prev_version && version_compare( $prev_version, '1.8.0', '<' ) ) {
			// Options no longer read by any code path.
			foreach ( [
				'wks3m_auto_detect_external',
				'wks3m_strip_strapi_prefixes',
				'wks3m_concurrency',
				'wks3m_download_retries',
				'wks3m_dry_run',
				'wks3m_batch_size',
			] as $deprecated ) {
				delete_option( $deprecated );
			}
		}

		if ( '' !== $prev_version && version_compare( $prev_version, '1.9.0', '<' ) ) {
			// Re-run the postmeta cleanup from 1.7.0. Users who pulled a DB
			// dump with db_version already ≥ 1.7.0 but with orphan
			// _wks3m_backup_* / _wks3m_replacements postmeta from an earlier
			// plugin version would otherwise keep carrying hundreds of MB of
			// dead data (the rollback feature was removed in 1.6.0).
			$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '\\_wks3m\\_backup\\_%'" );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s", '_wks3m_replacements' ) );
			// OPTIMIZE so the freed disk space shows up immediately.
			$wpdb->query( "OPTIMIZE TABLE {$wpdb->postmeta}" );
		}

		update_option( self::TABLE_VERSION_OPTION, self::CURRENT_DB_VERSION );
	}

	/** @deprecated retained for back-compat with older Plugin::boot() calls. */
	public static function install_table(): void {
		self::install_tables();
	}

	private static function install_migration_log_table(): void {
		global $wpdb;

		$table           = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			source_url_base VARCHAR(500) NOT NULL,
			source_url_variants LONGTEXT NULL,
			source_host VARCHAR(255) NULL,
			base_key VARCHAR(500) NULL,
			alt_text TEXT NULL,
			derived_title VARCHAR(255) NULL,
			attachment_id BIGINT UNSIGNED NULL,
			post_ids LONGTEXT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			error_message TEXT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			last_seen_at DATETIME NULL,
			replaced_at DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY source_url_base (source_url_base(191)),
			KEY status (status),
			KEY attachment_id (attachment_id),
			KEY source_host (source_host)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	/**
	 * Alt divergences detected by the Synchro ALT scan — pending work only.
	 * Rows are deleted on successful apply; failed applies keep error_message.
	 */
	private static function install_alt_diff_table(): void {
		global $wpdb;

		$table           = self::alt_diff_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			post_id BIGINT UNSIGNED NOT NULL,
			attachment_id BIGINT UNSIGNED NOT NULL,
			src VARCHAR(500) NOT NULL,
			content_alt TEXT NULL,
			library_alt TEXT NULL,
			error_message TEXT NULL,
			scanned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY post_src (post_id, src(191)),
			KEY attachment_id (attachment_id)
		) {$charset_collate};";

		dbDelta( $sql );
	}
}
