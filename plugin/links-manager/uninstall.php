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

$options_to_delete = [
  'lm_db_version',
  'lm_settings',
  'lm_anchor_groups',
  'lm_anchor_targets',
  'lm_anchor_config_version',
  'lm_stats_last_date',
  'lm_last_stats_snapshot_at',
  'lm_maintenance_last_date',
  'lm_stats_snapshot_version',
  'lm_dataset_cache_version',
  'lm_last_wpml_lang_context',
  'lm_last_fatal_diagnostic',
  'lm_last_runtime_profile',
];

/**
 * Remove plugin data for the current blog context.
 */
$cleanup_blog = static function() use ($wpdb, $options_to_delete) {
  wp_clear_scheduled_hook('lm_scheduled_cache_rebuild');
  wp_clear_scheduled_hook('lm_background_rebuild_cache');
  wp_clear_scheduled_hook('lm_prewarm_rest_list_cache');

  foreach ($options_to_delete as $option) {
    delete_option($option);
  }

  $wpdb->query(
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'lm_cache_scan_%'"
  );
  $wpdb->query(
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'lm_rebuild_job_state_%'"
  );
  $wpdb->query(
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'lm_rebuild_last_finalize_metrics_%'"
  );
  $wpdb->query(
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'lm_rebuild_job_lock_%'"
  );

  $transients = $wpdb->get_results(
    "SELECT REPLACE(REPLACE(option_name, '_transient_timeout_', ''), '_transient_', '') AS transient_name
     FROM {$wpdb->options}
     WHERE option_name LIKE '_transient_lm_%'
        OR option_name LIKE '_transient_timeout_lm_%'"
  );

  if ($transients) {
    foreach ($transients as $transient) {
      if (!empty($transient->transient_name)) {
        delete_transient($transient->transient_name);
      }
    }
  }

  $tables = [
    $wpdb->prefix . 'lm_audit_log',
    $wpdb->prefix . 'lm_stats_log',
    $wpdb->prefix . 'lm_link_fact',
    $wpdb->prefix . 'lm_link_post_summary',
    $wpdb->prefix . 'lm_link_domain_summary',
    $wpdb->prefix . 'lm_anchor_text_summary',
  ];

  foreach ($tables as $table) {
    $table_safe = preg_replace('/[^A-Za-z0-9_]/', '', $table);
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table) {
      $wpdb->query("DROP TABLE IF EXISTS `{$table_safe}`");
    }
  }
};

if (is_multisite()) {
  $sites = get_sites(['fields' => 'ids']);
  if (is_array($sites) && !empty($sites)) {
    foreach ($sites as $site_id) {
      switch_to_blog((int)$site_id);
      $cleanup_blog();
      restore_current_blog();
    }
  }
} else {
  $cleanup_blog();
}
