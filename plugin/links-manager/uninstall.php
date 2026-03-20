<?php
/**
 * Links Manager - Uninstall Script
 * 
 * Handles cleanup of plugin data when plugin is uninstalled
 * (not just deactivated, but actually deleted from WordPress)
 */

// Security check - only run if called from WordPress uninstall
if (!defined('WP_UNINSTALL_PLUGIN')) {
  exit;
}

global $wpdb;

// Unschedule plugin cron hooks.
wp_clear_scheduled_hook('lm_scheduled_cache_rebuild');
wp_clear_scheduled_hook('lm_background_rebuild_cache');

// Delete all plugin options from wp_options table
$options_to_delete = [
  'lm_db_version',
  'lm_settings',
  'lm_anchor_groups',
  'lm_anchor_targets',
  'lm_stats_last_date',
  'lm_maintenance_last_date',
  'lm_stats_snapshot_version',
  'lm_last_wpml_lang_context',
];

foreach ($options_to_delete as $option) {
  delete_option($option);
}

// Delete dynamic cache scan options (lm_cache_scan_*).
$wpdb->query(
  "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'lm_cache_scan_%'"
);

// Delete all plugin transients (temporary cached data)
// This clears entries like _transient_lm_cache_*, _transient_lm_url_*, etc.
$transients = $wpdb->get_results(
  "SELECT REPLACE(option_name, '_transient_', '') as transient_name
   FROM $wpdb->options
   WHERE option_name LIKE '_transient_lm_%'"
);

if ($transients) {
  foreach ($transients as $transient) {
    delete_transient($transient->transient_name);
  }
}

// Delete custom database tables
$audit_table = $wpdb->prefix . 'lm_audit_log';
$stats_table = $wpdb->prefix . 'lm_stats_log';
$audit_table_safe = preg_replace('/[^A-Za-z0-9_]/', '', $audit_table);
$stats_table_safe = preg_replace('/[^A-Za-z0-9_]/', '', $stats_table);

// Check if tables exist before dropping (avoid errors)
if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $audit_table)) === $audit_table) {
  $wpdb->query("DROP TABLE IF EXISTS `{$audit_table_safe}`");
}

if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $stats_table)) === $stats_table) {
  $wpdb->query("DROP TABLE IF EXISTS `{$stats_table_safe}`");
}

// Additional cleanup for multisite: remove plugin options from all sites.
if (is_multisite()) {
  $sites = get_sites(['fields' => 'ids']);
  if (is_array($sites) && !empty($sites)) {
    foreach ($sites as $site_id) {
      switch_to_blog((int)$site_id);

      foreach ($options_to_delete as $option) {
        delete_option($option);
      }

      // Delete dynamic cache scan options per site table.
      $wpdb->query(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'lm_cache_scan_%'"
      );

      restore_current_blog();
    }
  }
}
