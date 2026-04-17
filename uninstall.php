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

// Current tables + legacy ones from prior versions.
$tables = [
	$wpdb->prefix . 'wks3m_migration_log',
	$wpdb->prefix . 'wks3m_alt_diff',
	$wpdb->prefix . 'wks3m_alt_history',  // removed in 1.6.0
	$wpdb->prefix . 's3_migration_log',   // pre-white-label
];
foreach ( $tables as $t ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$t}" );
}

// Postmeta written by the removed URL-rollback feature.
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '\\_wks3m\\_backup\\_%'" );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s", '_wks3m_replacements' ) );

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
