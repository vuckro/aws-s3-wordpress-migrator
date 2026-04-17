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
	public const CURRENT_DB_VERSION   = '1.6.0';

	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'wks3m_migration_log';
	}

	public static function alt_diff_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'wks3m_alt_diff';
	}

	public static function alt_history_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'wks3m_alt_history';
	}

	public static function activate(): void {
		self::install_tables();
		self::install_default_options();
	}

	/**
	 * Seed default option values. Safe to call repeatedly — add_option() is a no-op
	 * when the option already exists. Called from activate() and from the
	 * upgrade safety-net in Plugin::boot().
	 */
	public static function install_default_options(): void {
		add_option( 'wks3m_source_hosts', [] );
		add_option( 'wks3m_auto_detect_external', 1 );
		add_option( 'wks3m_strip_strapi_prefixes', 1 );
		add_option( 'wks3m_defer_thumbnails', 0 );
	}

	public static function deactivate(): void {
		// Keep data in place. Uninstall.php drops tables.
	}

	/**
	 * Install / upgrade both schema tables in one pass. dbDelta() is idempotent.
	 *
	 * On upgrade from < 1.5.0 we drop the alt_diff table entirely: its legacy
	 * schema (status/applied_at/rolled_back_at columns + applied/rolled_back
	 * rows) no longer matches the current feature. The next scan rebuilds it.
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
		self::install_alt_history_table();

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
			rolled_back_at DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY source_url_base (source_url_base(191)),
			KEY status (status),
			KEY attachment_id (attachment_id),
			KEY source_host (source_host)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	/**
	 * Alt divergences detected by the Synchro ALT scan.
	 *
	 * One row per (post_id, src) pair waiting to be synced. On a successful
	 * apply the row is deleted; on failure error_message is filled and the
	 * row stays visible in the UI for triage. No history kept: a re-scan
	 * rebuilds the table from live state.
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

	/**
	 * Append-only log of applied ALT syncs. Read-only from the UI, purgable
	 * from the Settings tab when the user is done with the migration and wants
	 * to reclaim space.
	 */
	private static function install_alt_history_table(): void {
		global $wpdb;

		$table           = self::alt_history_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			post_id BIGINT UNSIGNED NOT NULL,
			attachment_id BIGINT UNSIGNED NOT NULL,
			src VARCHAR(500) NOT NULL,
			old_alt TEXT NULL,
			new_alt TEXT NULL,
			applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY post_id (post_id),
			KEY attachment_id (attachment_id),
			KEY applied_at (applied_at)
		) {$charset_collate};";

		dbDelta( $sql );
	}
}
