<?php
/**
 * Uninstall — drop table and delete options.
 *
 * Also cleans up legacy cxs3m_* options from the pre-0.2 branded release,
 * so reinstalls don't leave orphans.
 *
 * @package WaasKitS3Migrator
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// Current tables.
$tables = [
	$wpdb->prefix . 'wks3m_migration_log',
	$wpdb->prefix . 'wks3m_alt_diff',
];
foreach ( $tables as $t ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$t}" );
}

// Legacy table from the pre-white-label version.
$legacy = $wpdb->prefix . 's3_migration_log';
$wpdb->query( "DROP TABLE IF EXISTS {$legacy}" );

foreach (
	[
		'wks3m_db_version',
		'wks3m_dry_run',
		'wks3m_batch_size',
		'wks3m_source_hosts',
		'wks3m_auto_detect_external',
		'wks3m_strip_strapi_prefixes',
		'wks3m_concurrency',
		'wks3m_defer_thumbnails',
		'wks3m_download_retries',
		// Legacy.
		'cxs3m_db_version',
		'cxs3m_dry_run',
		'cxs3m_batch_size',
	] as $opt
) {
	delete_option( $opt );
}
