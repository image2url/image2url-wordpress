<?php

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('image2url_settings');
delete_option('image2url_mapping_table_version');

global $wpdb;

$transient_like = $wpdb->esc_like('_transient_image2url_rate_limit_') . '%';
$timeout_like = $wpdb->esc_like('_transient_timeout_image2url_rate_limit_') . '%';
$job_lock_like = $wpdb->esc_like('_transient_image2url_migration_lock_') . '%';
$job_lock_timeout_like = $wpdb->esc_like('_transient_timeout_image2url_migration_lock_') . '%';
$table_name = $wpdb->prefix . 'image2url_mappings';
$jobs_table_name = $wpdb->prefix . 'image2url_migration_jobs';

if (function_exists('wp_clear_scheduled_hook')) {
    wp_clear_scheduled_hook('image2url_migration_process_job');
}

$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
        $transient_like,
        $timeout_like,
        $job_lock_like,
        $job_lock_timeout_like
    )
);

$wpdb->query("DROP TABLE IF EXISTS {$table_name}");
$wpdb->query("DROP TABLE IF EXISTS {$jobs_table_name}");
