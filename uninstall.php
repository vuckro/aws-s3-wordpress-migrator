<?php
/**
 * Uninstall — drop table and delete options.
 *
 * @package ClaireexploreS3Migrator
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

$table = $wpdb->prefix . 's3_migration_log';
$wpdb->query( "DROP TABLE IF EXISTS {$table}" );

delete_option( 'cxs3m_db_version' );
delete_option( 'cxs3m_dry_run' );
delete_option( 'cxs3m_batch_size' );
