<?php
/**
 * Plugin bootstrap and lifecycle helpers for Links Manager.
 */

if (!defined('ABSPATH')) {
  exit;
}

trait LM_Plugin_Bootstrap_Trait {
  public static function activate() {
    $plugin = new self(false);
    $plugin->maybe_upgrade_schema();
    $plugin->clear_lifecycle_runtime_state();
    $plugin->ensure_scheduled_cache_rebuild();
  }

  public static function deactivate() {
    $plugin = new self(false);
    self::clear_scheduled_hooks();
    $plugin->clear_lifecycle_runtime_state(true);
  }

  private static function clear_scheduled_hooks() {
    wp_clear_scheduled_hook('lm_scheduled_cache_rebuild');
    wp_clear_scheduled_hook('lm_background_rebuild_cache');
    wp_clear_scheduled_hook('lm_prewarm_rest_list_cache');
  }

  private function clear_lifecycle_runtime_state($purgeTransientCaches = false) {
    $this->clear_rebuild_job_state();
    $this->release_rebuild_job_lock();
    delete_option($this->rebuild_last_finalize_metrics_option_key());

    if ($purgeTransientCaches) {
      $this->purge_lifecycle_transients(['lm_rebuild_partial_', 'lm_rest_']);
    }
  }

  private function purge_lifecycle_transients($prefixes) {
    global $wpdb;

    $prefixes = array_values(array_filter(array_map('strval', (array)$prefixes)));
    if (empty($prefixes)) {
      return;
    }

    $whereParts = [];
    $params = [];
    foreach ($prefixes as $prefix) {
      $whereParts[] = 'option_name LIKE %s';
      $params[] = '_transient_' . $prefix . '%';
      $whereParts[] = 'option_name LIKE %s';
      $params[] = '_transient_timeout_' . $prefix . '%';
    }

    $sql = "SELECT REPLACE(REPLACE(option_name, '_transient_timeout_', ''), '_transient_', '') AS transient_name
      FROM {$wpdb->options}
      WHERE " . implode(' OR ', $whereParts);
    $transients = $wpdb->get_col($wpdb->prepare($sql, $params));
    foreach ((array)$transients as $transientName) {
      $transientName = trim((string)$transientName);
      if ($transientName !== '') {
        delete_transient($transientName);
      }
    }
  }

  public function load_textdomain() {
    load_plugin_textdomain(
      'links-manager',
      false,
      dirname(plugin_basename(__FILE__), 2) . '/languages'
    );
  }

  private function unauthorized_message() {
    return __('Unauthorized', 'links-manager');
  }

  private function invalid_nonce_message() {
    return __('Invalid nonce', 'links-manager');
  }
}
