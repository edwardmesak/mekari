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
    $plugin->ensure_scheduled_cache_rebuild();
  }

  public static function deactivate() {
    self::clear_scheduled_hooks();
  }

  private static function clear_scheduled_hooks() {
    wp_clear_scheduled_hook('lm_scheduled_cache_rebuild');
    wp_clear_scheduled_hook('lm_background_rebuild_cache');
    wp_clear_scheduled_hook('lm_prewarm_rest_list_cache');
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
