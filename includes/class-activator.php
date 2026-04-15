<?php
/**
 * Activation / deactivation hooks.
 *
 * @package ClaireexploreS3Migrator
 */

namespace CXS3M;

defined( 'ABSPATH' ) || exit;

class Activator {

	public const TABLE_VERSION_OPTION = 'cxs3m_db_version';
	public const CURRENT_DB_VERSION   = '1.0.0';

	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 's3_migration_log';
	}

	public static function activate(): void {
		self::install_table();
		// Default options.
		add_option( 'cxs3m_dry_run', 1 );
		add_option( 'cxs3m_batch_size', 10 );
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
			s3_url_base VARCHAR(500) NOT NULL,
			s3_url_variants LONGTEXT NULL,
			attachment_id BIGINT UNSIGNED NULL,
			post_ids LONGTEXT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			error_message TEXT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			replaced_at DATETIME NULL,
			rolled_back_at DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY s3_url_base (s3_url_base(191)),
			KEY status (status),
			KEY attachment_id (attachment_id)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( self::TABLE_VERSION_OPTION, self::CURRENT_DB_VERSION );
	}
}
