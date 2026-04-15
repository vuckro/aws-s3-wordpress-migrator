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
	public const CURRENT_DB_VERSION   = '1.1.0';

	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'wks3m_migration_log';
	}

	public static function activate(): void {
		self::install_table();
		// Default options.
		add_option( 'wks3m_dry_run', 1 );
		add_option( 'wks3m_batch_size', 10 );
		add_option( 'wks3m_source_hosts', [] );
		add_option( 'wks3m_auto_detect_external', 1 );
		add_option( 'wks3m_strip_strapi_prefixes', 1 );
	}

	public static function deactivate(): void {
		// Keep data in place. Uninstall.php drops the table.
	}

	public static function install_table(): void {
		global $wpdb;

		$table           = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			source_url_base VARCHAR(500) NOT NULL,
			source_url_variants LONGTEXT NULL,
			source_host VARCHAR(255) NULL,
			attachment_id BIGINT UNSIGNED NULL,
			post_ids LONGTEXT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			error_message TEXT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			replaced_at DATETIME NULL,
			rolled_back_at DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY source_url_base (source_url_base(191)),
			KEY status (status),
			KEY attachment_id (attachment_id),
			KEY source_host (source_host)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( self::TABLE_VERSION_OPTION, self::CURRENT_DB_VERSION );
	}
}
