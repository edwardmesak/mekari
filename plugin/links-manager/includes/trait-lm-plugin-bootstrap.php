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
    $plugin->maybe_schedule_initial_refresh_bootstrap(true);
  }

  public static function deactivate() {
    $plugin = new self(false);
    $plugin->clear_scheduled_hooks();
    $plugin->clear_lifecycle_runtime_state(true);
  }

  private function get_scheduled_hook_args_from_cron($hookName) {
    $cron = _get_cron_array();
    if (!is_array($cron) || empty($cron)) {
      return [];
    }

    $argSets = [];
    foreach ($cron as $hooks) {
      if (!is_array($hooks) || !isset($hooks[$hookName]) || !is_array($hooks[$hookName])) {
        continue;
      }
      foreach ($hooks[$hookName] as $event) {
        $args = isset($event['args']) && is_array($event['args']) ? array_values($event['args']) : [];
        $argSets[md5(wp_json_encode($args))] = $args;
      }
    }

    return array_values($argSets);
  }

  private function clear_scheduled_hooks() {
    wp_clear_scheduled_hook('lm_scheduled_cache_rebuild');

    $knownBackgroundArgs = [
      $this->background_rebuild_event_args('any', 'all'),
    ];
    $knownPrewarmArgs = [
      $this->rest_list_prewarm_event_args('any', 'all'),
    ];

    $state = $this->get_rebuild_job_state();
    if (is_array($state) && !empty($state)) {
      $scopePostType = sanitize_key((string)($state['scope_post_type'] ?? 'any'));
      if ($scopePostType === '') {
        $scopePostType = 'any';
      }
      $wpmlLang = $this->normalize_rebuild_wpml_lang((string)($state['wpml_lang'] ?? 'all'));
      $knownBackgroundArgs[] = $this->background_rebuild_event_args($scopePostType, $wpmlLang);
      $knownPrewarmArgs[] = $this->rest_list_prewarm_event_args($scopePostType, $wpmlLang);
    }

    $workerArgsList = array_merge(
      [$this->rebuild_step_worker_args()],
      $this->get_scheduled_hook_args_from_cron('lm_rebuild_step_worker')
    );
    foreach ($workerArgsList as $args) {
      wp_clear_scheduled_hook('lm_rebuild_step_worker', array_values((array)$args));
    }

    $backgroundArgsList = array_merge($knownBackgroundArgs, $this->get_scheduled_hook_args_from_cron('lm_background_rebuild_cache'));
    foreach ($backgroundArgsList as $args) {
      wp_clear_scheduled_hook('lm_background_rebuild_cache', array_values((array)$args));
    }

    $prewarmArgsList = array_merge($knownPrewarmArgs, $this->get_scheduled_hook_args_from_cron('lm_prewarm_rest_list_cache'));
    foreach ($prewarmArgsList as $args) {
      wp_clear_scheduled_hook('lm_prewarm_rest_list_cache', array_values((array)$args));
    }
  }

  private function clear_lifecycle_runtime_state($purgeTransientCaches = false) {
    $this->clear_rebuild_job_state();
    $this->release_rebuild_job_lock();
    delete_option($this->rebuild_last_finalize_metrics_option_key());

    if ($purgeTransientCaches) {
      $this->purge_lifecycle_transients([
        'lm_rebuild_partial_',
        'lm_rest_',
        'lm_bg_rebuild_lock_',
        'lm_initial_refresh_bootstrap_',
        'lm_scheduled_cache_rebuild_last_run_',
      ]);
    }
  }

  public function maybe_schedule_initial_refresh_bootstrap($force = false) {
    $force = (bool)$force;
    $jobState = $this->get_rebuild_job_state();
    $jobStatus = sanitize_key((string)($jobState['status'] ?? 'idle'));
    if (!$force && in_array($jobStatus, ['running', 'finalizing'], true)) {
      return;
    }

    $hasAnyDataset = $this->has_refresh_dataset_for_scope('any', 'all', false) || $this->has_nonempty_refresh_dataset_for_scope('any', 'all', false);
    if ($hasAnyDataset && !$force) {
      return;
    }

    $throttleKey = 'lm_initial_refresh_bootstrap_' . get_current_blog_id();
    if (!$force && get_transient($throttleKey)) {
      return;
    }

    set_transient($throttleKey, '1', 5 * MINUTE_IN_SECONDS);
    $this->schedule_background_rebuild('any', 'all', 2);
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
