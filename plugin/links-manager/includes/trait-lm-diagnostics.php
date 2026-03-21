<?php
/**
 * Fatal diagnostics and runtime profiling helpers.
 */

if (!defined('ABSPATH')) {
  exit;
}

trait LM_Diagnostics_Trait {
  private function is_lm_request_context() {
    $action = $this->request_key('action', '');
    if ($action !== '' && strpos($action, 'lm_') === 0) {
      return true;
    }

    $page = $this->request_key('page', '');
    if ($page !== '' && strpos($page, 'links-manager') === 0) {
      return true;
    }

    $uri = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '';
    if ($uri !== '' && strpos($uri, 'links-manager') !== false) {
      return true;
    }

    return false;
  }

  public function capture_fatal_diagnostic() {
    if (!$this->is_lm_request_context()) {
      return;
    }

    $error = error_get_last();
    if (!is_array($error) || empty($error)) {
      return;
    }

    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR];
    $errType = isset($error['type']) ? (int)$error['type'] : 0;
    if (!in_array($errType, $fatalTypes, true)) {
      return;
    }

    $requestPage = $this->request_text('page', '');
    $requestAction = $this->request_text('action', '');
    $requestLang = $this->request_text('lang', '');
    $memoryLimit = (string)ini_get('memory_limit');
    $executionLimit = (string)ini_get('max_execution_time');

    $diag = [
      'captured_at' => current_time('mysql'),
      'error_type' => $errType,
      'message' => isset($error['message']) ? sanitize_text_field((string)$error['message']) : '',
      'file' => isset($error['file']) ? sanitize_text_field((string)$error['file']) : '',
      'line' => isset($error['line']) ? (int)$error['line'] : 0,
      'request_page' => $requestPage,
      'request_action' => $requestAction,
      'request_lang' => $requestLang,
      'request_uri' => isset($_SERVER['REQUEST_URI']) ? sanitize_text_field((string)$_SERVER['REQUEST_URI']) : '',
      'php_version' => PHP_VERSION,
      'memory_limit' => $memoryLimit,
      'max_execution_time' => $executionLimit,
      'memory_peak_bytes' => (int)memory_get_peak_usage(true),
      'memory_usage_bytes' => (int)memory_get_usage(true),
    ];

    update_option(self::DIAGNOSTIC_OPTION_KEY, $diag, false);
  }

  private function can_access_debug_diagnostics() {
    if (!$this->is_debug_mode_enabled()) {
      return false;
    }

    return current_user_can('manage_options');
  }

  private function is_debug_mode_enabled() {
    $settings = $this->get_settings();
    return isset($settings['debug_mode']) && (string)$settings['debug_mode'] === '1';
  }

  private function get_last_fatal_diagnostic() {
    if (!$this->can_access_debug_diagnostics()) {
      return [];
    }

    $diag = get_option(self::DIAGNOSTIC_OPTION_KEY, []);
    return is_array($diag) ? $diag : [];
  }

  private function format_bytes_human($bytes) {
    $bytes = (float)$bytes;
    if ($bytes <= 0) return '0 B';
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $pow = (int)floor(log($bytes, 1024));
    $pow = max(0, min($pow, count($units) - 1));
    $value = $bytes / pow(1024, $pow);
    return number_format($value, 2) . ' ' . $units[$pow];
  }

  private function is_runtime_profile_enabled() {
    if ($this->runtime_profile_enabled !== null) {
      return (bool)$this->runtime_profile_enabled;
    }

    $forceProfile = $this->request_bool_flag('lm_profile');
    $this->runtime_profile_enabled = $forceProfile || (defined('WP_DEBUG') && WP_DEBUG);
    return (bool)$this->runtime_profile_enabled;
  }

  private function profile_start() {
    if (!$this->is_runtime_profile_enabled()) {
      return 0.0;
    }
    return microtime(true);
  }

  private function profile_end($name, $startedAt, $meta = []) {
    if (!$this->is_runtime_profile_enabled()) {
      return;
    }
    $startedAt = (float)$startedAt;
    if ($startedAt <= 0) {
      return;
    }

    $entry = [
      'name' => sanitize_key((string)$name),
      'elapsed_ms' => round((microtime(true) - $startedAt) * 1000, 2),
      'meta' => is_array($meta) ? $meta : [],
      'memory_peak_bytes' => (int)memory_get_peak_usage(true),
    ];

    $this->runtime_profile_entries[] = $entry;
    if (count($this->runtime_profile_entries) > 80) {
      $this->runtime_profile_entries = array_slice($this->runtime_profile_entries, -80);
    }
  }

  private function reset_crawl_runtime_stats() {
    if (!$this->is_runtime_profile_enabled()) {
      $this->crawl_runtime_stats = null;
      return;
    }

    $this->crawl_runtime_stats = [
      'posts_seen' => 0,
      'posts_skipped_no_source_markers' => 0,
      'posts_skipped_excluded_url' => 0,
      'content_marker_posts' => 0,
      'content_block_posts' => 0,
      'content_blocks_total' => 0,
      'content_blocks_skipped_no_link_marker' => 0,
      'excerpt_marker_posts' => 0,
      'meta_keys_total_checked' => 0,
      'meta_values_with_link_markers' => 0,
      'parse_calls_total' => 0,
      'parse_links_total' => 0,
      'parse_ms_total' => 0.0,
      'parse_skip_empty' => 0,
      'parse_skip_no_marker' => 0,
      'parse_load_failed' => 0,
      'parse_source_content_calls' => 0,
      'parse_source_excerpt_calls' => 0,
      'parse_source_meta_calls' => 0,
      'parse_source_menu_calls' => 0,
    ];
  }

  private function add_crawl_runtime_stat($key, $delta = 1) {
    if (!is_array($this->crawl_runtime_stats)) {
      return;
    }
    if (!isset($this->crawl_runtime_stats[$key])) {
      $this->crawl_runtime_stats[$key] = 0;
    }
    $this->crawl_runtime_stats[$key] += $delta;
  }

  private function record_parse_runtime_stats($startedAt, $context, $resultsCount, $statusKey = '') {
    if (!is_array($this->crawl_runtime_stats)) {
      return;
    }

    $elapsedMs = round((microtime(true) - (float)$startedAt) * 1000, 2);
    $this->add_crawl_runtime_stat('parse_calls_total', 1);
    $this->add_crawl_runtime_stat('parse_links_total', (int)$resultsCount);
    $this->add_crawl_runtime_stat('parse_ms_total', $elapsedMs);

    $source = isset($context['source']) ? sanitize_key((string)$context['source']) : '';
    if ($source === 'content') {
      $this->add_crawl_runtime_stat('parse_source_content_calls', 1);
    } elseif ($source === 'excerpt') {
      $this->add_crawl_runtime_stat('parse_source_excerpt_calls', 1);
    } elseif ($source === 'meta') {
      $this->add_crawl_runtime_stat('parse_source_meta_calls', 1);
    } elseif ($source === 'menu') {
      $this->add_crawl_runtime_stat('parse_source_menu_calls', 1);
    }

    if ($statusKey !== '') {
      $this->add_crawl_runtime_stat($statusKey, 1);
    }
  }

  private function get_crawl_runtime_stats_snapshot() {
    return is_array($this->crawl_runtime_stats) ? $this->crawl_runtime_stats : [];
  }

  private function profile_meta_with_crawl_stats($meta) {
    $meta = is_array($meta) ? $meta : [];
    $stats = $this->get_crawl_runtime_stats_snapshot();
    if (!empty($stats)) {
      $meta['crawl_stats'] = $stats;
    }
    return $meta;
  }

  public function persist_runtime_profile() {
    if (!$this->is_runtime_profile_enabled() || empty($this->runtime_profile_entries)) {
      return;
    }

    $requestPage = $this->request_text('page', '');
    $requestAction = $this->request_text('action', '');
    $requestUri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field((string)$_SERVER['REQUEST_URI']) : '';
    $payload = [
      'captured_at' => current_time('mysql'),
      'request_page' => $requestPage,
      'request_action' => $requestAction,
      'request_uri' => $requestUri,
      'entries' => $this->runtime_profile_entries,
    ];

    update_option(self::RUNTIME_PROFILE_OPTION_KEY, $payload, false);
  }

  private function get_last_runtime_profile() {
    if (!$this->can_access_debug_diagnostics()) {
      return [];
    }

    $profile = get_option(self::RUNTIME_PROFILE_OPTION_KEY, []);
    return is_array($profile) ? $profile : [];
  }
}
