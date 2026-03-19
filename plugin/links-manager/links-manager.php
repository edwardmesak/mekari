<?php
/**
 * Plugin Name: Links Manager
 * Description: Manage and analyze all links across your WordPress site with precision. Edit link URLs, anchor texts, and relationship attributes in a user-friendly interface. Identify orphan pages and export link data for SEO audits.
 * Requires at least: 5.0
 * Requires PHP: 7.2
 * Version: 4.4.2
 * Author: Edward Mesak Dua Padang
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: links-manager
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) {
  exit;
}

class LM_Links_Manager {
  const PAGE_SLUG = 'links-manager';
  const NONCE_ACTION = 'lm_links_manager_nonce_action';
  const NONCE_NAME = 'lm_nonce';

  const CACHE_TTL = 6 * HOUR_IN_SECONDS;
  const CACHE_BASE_TTL = 30 * DAY_IN_SECONDS;
  const STATS_SNAPSHOT_TTL = 7 * DAY_IN_SECONDS;
  const AUDIT_RETENTION_DAYS = 90;
  const STATS_RETENTION_DAYS = 730;
  const CRAWL_POST_BATCH = 100;
  const MAX_CACHE_ROWS = 15000;
  const DIAGNOSTIC_OPTION_KEY = 'lm_last_fatal_diagnostic';
  const RUNTIME_PROFILE_OPTION_KEY = 'lm_last_runtime_profile';

  private $weak_anchor_patterns_cache = null;
  private $regex_pattern_cache = [];
  private $anchor_quality_label_cache = [];
  private $author_display_name_cache = [];
  private $scan_exclude_url_patterns_cache = null;
  private $scan_exclude_matchers_cache = null;
  private $scan_meta_keys_cache = null;
  private $enabled_scan_value_types_map_cache = null;
  private $crawl_runtime_stats = null;
  private $runtime_profile_entries = [];
  private $runtime_profile_enabled = null;

  public function __construct() {
    add_action('admin_menu', [$this, 'admin_menu']);
    add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);

    add_action('admin_post_lm_export_csv', [$this, 'handle_export_csv']);
    add_action('admin_post_lm_export_pages_link_csv', [$this, 'handle_export_pages_link_csv']);
    add_action('admin_post_lm_export_cited_domains_csv', [$this, 'handle_export_cited_domains_csv']);
    add_action('admin_post_lm_export_links_target_csv', [$this, 'handle_export_links_target_csv']);
    add_action('admin_post_lm_export_all_anchor_text_csv', [$this, 'handle_export_all_anchor_text_csv']);
    add_action('admin_post_lm_export_anchor_grouping_csv', [$this, 'handle_export_anchor_grouping_csv']);
    add_action('admin_post_lm_update_link', [$this, 'handle_update_link']);
    add_action('wp_ajax_lm_update_link_ajax', [$this, 'handle_update_link_ajax']);
    add_action('admin_post_lm_bulk_update', [$this, 'handle_bulk_update']);
    add_action('admin_post_lm_save_settings', [$this, 'handle_save_settings']);
    add_action('admin_post_lm_clear_diagnostics', [$this, 'handle_clear_diagnostics']);
    add_action('admin_post_lm_save_anchor_groups', [$this, 'handle_save_anchor_groups']);
    add_action('admin_post_lm_delete_anchor_group', [$this, 'handle_delete_anchor_group']);
    add_action('admin_post_lm_bulk_delete_anchor_groups', [$this, 'handle_bulk_delete_anchor_groups']);
    add_action('admin_post_lm_update_anchor_group', [$this, 'handle_update_anchor_group']);
    add_action('admin_post_lm_save_anchor_targets', [$this, 'handle_save_anchor_targets']);
    add_action('admin_post_lm_update_anchor_target', [$this, 'handle_update_anchor_target']);
    add_action('admin_post_lm_update_anchor_target_group', [$this, 'handle_update_anchor_target_group']);
    add_action('wp_ajax_lm_update_anchor_target_group_ajax', [$this, 'handle_update_anchor_target_group_ajax']);
    add_action('admin_post_lm_delete_anchor_target', [$this, 'handle_delete_anchor_target']);
    add_action('admin_post_lm_bulk_delete_anchor_targets', [$this, 'handle_bulk_delete_anchor_targets']);
    add_action('rest_api_init', [$this, 'register_rest_routes']);
    
    // Create audit table on init if needed
    add_action('init', [$this, 'maybe_create_audit_table']);
    add_action('init', [$this, 'ensure_scheduled_cache_rebuild']);
    add_action('admin_init', [$this, 'run_daily_maintenance']);
    add_action('shutdown', [$this, 'capture_fatal_diagnostic']);
    add_action('shutdown', [$this, 'persist_runtime_profile']);
    add_action('lm_background_rebuild_cache', [$this, 'run_background_rebuild_cache'], 10, 2);
    add_action('lm_scheduled_cache_rebuild', [$this, 'run_scheduled_cache_rebuild']);
  }

  private function is_lm_request_context() {
    $action = isset($_REQUEST['action']) ? sanitize_key((string)$_REQUEST['action']) : '';
    if ($action !== '' && strpos($action, 'lm_') === 0) {
      return true;
    }

    $page = isset($_REQUEST['page']) ? sanitize_key((string)$_REQUEST['page']) : '';
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

    $requestPage = isset($_REQUEST['page']) ? sanitize_text_field((string)$_REQUEST['page']) : '';
    $requestAction = isset($_REQUEST['action']) ? sanitize_text_field((string)$_REQUEST['action']) : '';
    $requestLang = isset($_REQUEST['lang']) ? sanitize_text_field((string)$_REQUEST['lang']) : '';
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

  private function get_last_fatal_diagnostic() {
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

  private function render_settings_diagnostic_box() {
    $diag = $this->get_last_fatal_diagnostic();
    if (empty($diag)) {
      return;
    }

    echo '<div class="lm-card lm-card-full" style="border-left:4px solid #d63638; margin-bottom:14px;">';
    echo '<h2 style="margin-top:0;">Last Fatal Diagnostic</h2>';
    echo '<div class="lm-small" style="margin-bottom:10px;">Captured automatically when a fatal error occurs on Links Manager request. Share this data to debug root cause.</div>';

    $capturedAt = isset($diag['captured_at']) ? (string)$diag['captured_at'] : '';
    $message = isset($diag['message']) ? (string)$diag['message'] : '';
    $file = isset($diag['file']) ? (string)$diag['file'] : '';
    $line = isset($diag['line']) ? (int)$diag['line'] : 0;
    $errorType = isset($diag['error_type']) ? (int)$diag['error_type'] : 0;
    $page = isset($diag['request_page']) ? (string)$diag['request_page'] : '';
    $action = isset($diag['request_action']) ? (string)$diag['request_action'] : '';
    $lang = isset($diag['request_lang']) ? (string)$diag['request_lang'] : '';
    $uri = isset($diag['request_uri']) ? (string)$diag['request_uri'] : '';
    $phpVersion = isset($diag['php_version']) ? (string)$diag['php_version'] : '';
    $memoryLimit = isset($diag['memory_limit']) ? (string)$diag['memory_limit'] : '';
    $maxExecution = isset($diag['max_execution_time']) ? (string)$diag['max_execution_time'] : '';
    $memoryPeak = isset($diag['memory_peak_bytes']) ? (int)$diag['memory_peak_bytes'] : 0;
    $memoryUsage = isset($diag['memory_usage_bytes']) ? (int)$diag['memory_usage_bytes'] : 0;

    echo '<table class="widefat striped" style="max-width:100%; margin-bottom:10px;">';
    echo '<tbody>';
    echo '<tr><th style="width:220px;">Captured At</th><td>' . esc_html($capturedAt) . '</td></tr>';
    echo '<tr><th>Error Type</th><td>' . esc_html((string)$errorType) . '</td></tr>';
    echo '<tr><th>Message</th><td><code>' . esc_html($message) . '</code></td></tr>';
    echo '<tr><th>File</th><td><code>' . esc_html($file) . '</code></td></tr>';
    echo '<tr><th>Line</th><td>' . esc_html((string)$line) . '</td></tr>';
    echo '<tr><th>Page</th><td>' . esc_html($page) . '</td></tr>';
    echo '<tr><th>Action</th><td>' . esc_html($action) . '</td></tr>';
    echo '<tr><th>Language</th><td>' . esc_html($lang) . '</td></tr>';
    echo '<tr><th>Request URI</th><td><code>' . esc_html($uri) . '</code></td></tr>';
    echo '<tr><th>PHP Version</th><td>' . esc_html($phpVersion) . '</td></tr>';
    echo '<tr><th>Memory Limit</th><td>' . esc_html($memoryLimit) . '</td></tr>';
    echo '<tr><th>Max Execution Time</th><td>' . esc_html($maxExecution) . ' s</td></tr>';
    echo '<tr><th>Peak Memory</th><td>' . esc_html($this->format_bytes_human($memoryPeak)) . '</td></tr>';
    echo '<tr><th>Memory at Shutdown</th><td>' . esc_html($this->format_bytes_human($memoryUsage)) . '</td></tr>';
    echo '</tbody>';
    echo '</table>';

    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    echo '<input type="hidden" name="action" value="lm_clear_diagnostics"/>';
    echo '<input type="hidden" name="' . esc_attr(self::NONCE_NAME) . '" value="' . esc_attr(wp_create_nonce(self::NONCE_ACTION)) . '"/>';
    submit_button('Clear Diagnostic', 'secondary', 'submit', false);
    echo '</form>';
    echo '</div>';
  }

  private function is_runtime_profile_enabled() {
    if ($this->runtime_profile_enabled !== null) {
      return (bool)$this->runtime_profile_enabled;
    }

    $forceProfile = isset($_REQUEST['lm_profile']) && (string)$_REQUEST['lm_profile'] === '1';
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

    $requestPage = isset($_REQUEST['page']) ? sanitize_text_field((string)$_REQUEST['page']) : '';
    $requestAction = isset($_REQUEST['action']) ? sanitize_text_field((string)$_REQUEST['action']) : '';
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
    $profile = get_option(self::RUNTIME_PROFILE_OPTION_KEY, []);
    return is_array($profile) ? $profile : [];
  }

  private function render_settings_runtime_profile_box() {
    $profile = $this->get_last_runtime_profile();
    $entries = isset($profile['entries']) && is_array($profile['entries']) ? $profile['entries'] : [];
    if (empty($entries)) {
      return;
    }

    $slowestEntries = $entries;
    usort($slowestEntries, function($a, $b) {
      $ea = isset($a['elapsed_ms']) ? (float)$a['elapsed_ms'] : 0.0;
      $eb = isset($b['elapsed_ms']) ? (float)$b['elapsed_ms'] : 0.0;
      if ($ea === $eb) {
        return 0;
      }
      return ($ea > $eb) ? -1 : 1;
    });
    $slowestEntries = array_slice($slowestEntries, 0, 3);

    echo '<div class="lm-card lm-card-full" style="border-left:4px solid #2271b1; margin-bottom:14px;">';
    echo '<h2 style="margin-top:0;">Last Runtime Profile</h2>';
    echo '<div class="lm-small" style="margin-bottom:10px;">Timing profile for the latest profiled request. Enable with <code>?lm_profile=1</code> or WP_DEBUG.</div>';
    echo '<table class="widefat striped" style="max-width:100%; margin-bottom:10px;">';
    echo '<tbody>';
    echo '<tr><th style="width:220px;">Captured At</th><td>' . esc_html((string)($profile['captured_at'] ?? '')) . '</td></tr>';
    echo '<tr><th>Page</th><td>' . esc_html((string)($profile['request_page'] ?? '')) . '</td></tr>';
    echo '<tr><th>Action</th><td>' . esc_html((string)($profile['request_action'] ?? '')) . '</td></tr>';
    echo '<tr><th>Request URI</th><td><code>' . esc_html((string)($profile['request_uri'] ?? '')) . '</code></td></tr>';
    echo '</tbody>';
    echo '</table>';

    echo '<div style="font-weight:600; margin:6px 0 8px;">Top 3 Slowest Phases</div>';
    echo '<table class="widefat striped" style="max-width:100%; margin-bottom:10px;">';
    echo '<thead><tr><th style="width:280px;">Phase</th><th style="width:140px;">Elapsed (ms)</th><th>Meta</th></tr></thead>';
    echo '<tbody>';
    foreach ($slowestEntries as $entry) {
      $phase = isset($entry['name']) ? (string)$entry['name'] : '';
      $elapsedMs = isset($entry['elapsed_ms']) ? (string)$entry['elapsed_ms'] : '0';
      $meta = isset($entry['meta']) && is_array($entry['meta']) ? wp_json_encode($entry['meta']) : '';
      if (!is_string($meta)) {
        $meta = '';
      }
      echo '<tr>';
      echo '<td><code>' . esc_html($phase) . '</code></td>';
      echo '<td>' . esc_html($elapsedMs) . '</td>';
      echo '<td><code>' . esc_html($meta) . '</code></td>';
      echo '</tr>';
    }
    echo '</tbody>';
    echo '</table>';

    echo '<table class="widefat striped" style="max-width:100%;">';
    echo '<thead><tr><th style="width:280px;">Phase</th><th style="width:140px;">Elapsed (ms)</th><th>Meta</th></tr></thead>';
    echo '<tbody>';
    foreach ($entries as $entry) {
      $phase = isset($entry['name']) ? (string)$entry['name'] : '';
      $elapsedMs = isset($entry['elapsed_ms']) ? (string)$entry['elapsed_ms'] : '0';
      $meta = isset($entry['meta']) && is_array($entry['meta']) ? wp_json_encode($entry['meta']) : '';
      if (!is_string($meta)) {
        $meta = '';
      }
      echo '<tr>';
      echo '<td><code>' . esc_html($phase) . '</code></td>';
      echo '<td>' . esc_html($elapsedMs) . '</td>';
      echo '<td><code>' . esc_html($meta) . '</code></td>';
      echo '</tr>';
    }
    echo '</tbody>';
    echo '</table>';
    echo '</div>';
  }
  
  public function maybe_create_audit_table() {
    $version = get_option('lm_db_version', '0');
    if (version_compare($version, '4.1', '<')) {
      $this->create_audit_table();
      $this->create_stats_table();
      update_option('lm_db_version', '4.1');
    }
  }
  
  private function create_audit_table() {
    global $wpdb;
    $table = $wpdb->prefix . 'lm_audit_log';
    $charset = $wpdb->get_charset_collate();
    
    $sql = "CREATE TABLE IF NOT EXISTS $table (
      id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
      user_id BIGINT(20) UNSIGNED,
      user_name VARCHAR(255),
      post_id BIGINT(20) UNSIGNED,
      action VARCHAR(50),
      old_url VARCHAR(2048),
      new_url VARCHAR(2048),
      old_rel VARCHAR(255),
      new_rel VARCHAR(255),
      changed_count INT(11),
      status VARCHAR(20),
      message TEXT,
      KEY timestamp (timestamp),
      KEY post_id (post_id),
      KEY action (action)
    ) $charset;";
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
  }

  private function create_stats_table() {
    global $wpdb;
    $table = $wpdb->prefix . 'lm_stats_log';
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS $table (
      id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      stat_date DATE NOT NULL,
      total_links INT(11) NOT NULL DEFAULT 0,
      internal_links INT(11) NOT NULL DEFAULT 0,
      external_links INT(11) NOT NULL DEFAULT 0,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY stat_date (stat_date)
    ) $charset;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
  }

  public function admin_menu() {
    if (!$this->current_user_can_access_plugin()) {
      return;
    }

    // Top-level menu with hover submenus
    add_menu_page(
      'Links Manager',
      'Links Manager',
      'read',
      self::PAGE_SLUG,
      [$this, 'render_admin_editor_page'],
      'dashicons-admin-links',
      80
    );

    add_submenu_page(
      self::PAGE_SLUG,
      'Statistics',
      'Statistics',
      'read',
      'links-manager-stats',
      [$this, 'render_admin_stats_page']
    );

    add_submenu_page(
      self::PAGE_SLUG,
      'Links Editor',
      'Links Editor',
      'read',
      self::PAGE_SLUG,
      [$this, 'render_admin_editor_page']
    );

    add_submenu_page(
      self::PAGE_SLUG,
      'Pages Link',
      'Pages Link',
      'read',
      'links-manager-pages-link',
      [$this, 'render_admin_pages_link_page']
    );

    add_submenu_page(
      self::PAGE_SLUG,
      'Links Target',
      'Links Target',
      'read',
      'links-manager-target',
      [$this, 'render_admin_links_target_page']
    );

    add_submenu_page(
      self::PAGE_SLUG,
      'Cited External Domains',
      'Cited Domains',
      'read',
      'links-manager-cited-domains',
      [$this, 'render_admin_cited_domains_page']
    );

    add_submenu_page(
      self::PAGE_SLUG,
      'All Anchor Text',
      'All Anchor Text',
      'read',
      'links-manager-all-anchor-text',
      [$this, 'render_admin_all_anchor_text_page']
    );

    add_submenu_page(
      self::PAGE_SLUG,
      'Settings',
      'Settings',
      'manage_options',
      'links-manager-settings',
      [$this, 'render_admin_settings_page']
    );

    // Hide the auto-added top-level submenu entry
    remove_submenu_page(self::PAGE_SLUG, self::PAGE_SLUG);
  }

  public function enqueue_admin_assets($hook) {
    $allowed = [
      'toplevel_page_' . self::PAGE_SLUG,
      'links-manager_page_links-manager-stats',
      'links-manager_page_links-manager-pages-link',
      'links-manager_page_links-manager-target',
      'links-manager_page_links-manager-cited-domains',
      'links-manager_page_links-manager-all-anchor-text',
      'links-manager_page_links-manager-settings',
    ];
    if (!in_array($hook, $allowed, true)) return;

    $css = "
    .lm-wrap{max-width:100%; padding-bottom:96px;}
    .lm-page-title{font-size:22px; font-weight:700; letter-spacing:.2px; margin:0 0 8px;}
    .lm-subtle{color:#6b7280; font-size:12px;}
    .lm-table-wrap{
      overflow-x:auto;
      overflow-y:visible;
      width:100%;
      border:1px solid #c3c4c7;
      background:#fff;
    }
    .lm-summary-table-wrap{
      width:100%;
      max-width:none;
      display:block;
      box-sizing:border-box;
    }
    .lm-summary-table-wrap .lm-table{
      width:100%;
      min-width:100%;
    }
    .lm-table{
      width:100%;
      min-width:max-content;
      table-layout:auto;
      border-collapse:collapse;
      font-size:12px;
    }
    .lm-table th{
      position:sticky;
      top:0;
      z-index:5;
      background:#f1f1f1;
      padding:8px 10px;
      text-align:left;
      border:1px solid #ddd;
      font-weight:600;
      white-space:normal;
      word-break:break-word;
    }
    .lm-table td{
      padding:8px 10px;
      border:1px solid #eee;
      vertical-align:top;
      word-break:break-word;
    }
    .lm-table tr:hover{background:#f9f9f9;}
    .lm-table td:first-child, .lm-table th:first-child{border-left:2px solid #0073aa;}
    
    /* Column specific widths */
    .lm-col-rowid{width:80px; font-family:monospace; font-size:11px;}
    .lm-col-occ{width:40px; text-align:center;}
    .lm-col-postid{width:60px; text-align:center;}
    .lm-col-title{width:250px;}
    .lm-col-type{width:80px;}
    .lm-col-author{width:100px;}
    .lm-col-date{width:120px;}
    .lm-col-source{width:100px;}
    .lm-col-location{width:100px;}
    .lm-col-block{width:50px; text-align:center;}
    .lm-col-pageurl{width:250px;}
    .lm-col-link{width:220px;}
    .lm-col-group{width:120px;}
    .lm-col-total{width:220px;}
    .lm-col-inlink{width:140px;}
    .lm-col-outbound{width:140px; text-align:center;}
    .lm-col-action{width:140px;}
    .lm-col-anchor{width:140px;}
    .lm-col-quality{width:120px; text-align:center;}
    .lm-col-alt{width:120px;}
    .lm-col-snippet{width:160px;}
    .lm-col-linktype{width:80px;}
    .lm-col-rel{width:120px;}
    .lm-col-valuetype{width:90px;}
    .lm-col-count{width:140px; text-align:center;}
    .lm-col-http{width:70px; text-align:center;}
    .lm-col-finalurl{width:180px;}
    .lm-col-checked{width:130px;}
    .lm-col-edit{width:300px; white-space:normal;}
    
    .lm-trunc{max-width:100%; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; display:block;}
    .lm-col-pageurl .lm-trunc,
    .lm-col-title .lm-trunc,
    .lm-col-link .lm-trunc,
    .lm-col-anchor .lm-trunc{
      white-space:normal;
      overflow:visible;
      text-overflow:clip;
      word-break:break-word;
      overflow-wrap:anywhere;
      line-height:1.35;
    }
    .lm-small{color:#646970; font-size:11px;}
    .lm-settings-actions{display:flex; flex-wrap:wrap; gap:12px; align-items:flex-start;}
    .lm-settings-actions-primary{min-width:220px;}
    .lm-settings-actions-card{min-width:300px;}
    .lm-settings-actions-title{font-weight:600; margin-bottom:6px;}
    .lm-settings-actions-note{margin-bottom:8px;}
    .lm-settings-actions-subtitle{margin:8px 0 6px;}
    .lm-danger-zone{margin:10px 0 8px; padding-top:8px; border-top:1px solid #dcdcde;}
    .lm-danger-text{color:#b32d2e; margin-bottom:6px;}
    .lm-help-tip{margin-top:4px;}
    .lm-chip{display:inline-block; padding:2px 6px; border:1px solid #dcdcde; border-radius:3px; font-size:11px;}
    .lm-chip.bad{border-color:#d63638; color:#d63638; background:#fff5f5;}
    .lm-chip.ok{border-color:#00a32a; color:#00a32a; background:#f0f7f0;}

    .lm-tabs{display:inline-flex; gap:6px; background:#f3f4f6; padding:4px; border-radius:8px; border:1px solid #e5e7eb;}
    .lm-tab{background:transparent; border:0; padding:6px 10px; font-size:12px; border-radius:6px; cursor:pointer;}
    .lm-tab.is-active{background:#b45309; color:#fff;}
    .lm-textarea-wrap{background:#111827; border-radius:10px; padding:10px; margin-top:8px;}
    .lm-textarea-wrap textarea{background:transparent; color:#e5e7eb; border:0; box-shadow:none; width:100%; min-height:140px; resize:vertical;}
    .lm-textarea-hint{color:#9ca3af; font-size:11px; margin-top:6px;}
    .lm-textarea-actions{display:flex; align-items:center; gap:10px; margin-top:8px; color:#9ca3af; font-size:11px;}
    .lm-textarea-actions input[type=file]{color:#9ca3af; font-size:11px;}

    /* editor column */
    .lm-edit-cell{white-space:normal;}
    .lm-edit-form{display:flex; flex-direction:column; gap:4px;}
    .lm-edit-form input[type=text]{width:100%; padding:4px; font-size:11px; box-sizing:border-box;}
    .lm-edit-form .button{padding:4px 8px; font-size:11px; height:auto;}
    .lm-form-msg{display:none; padding:4px; margin:4px 0; border-radius:3px; font-size:11px;}
    .lm-form-msg.success{background:#d4edda; color:#155724; border:1px solid #c3e6cb;}
    .lm-form-msg.error{background:#f8d7da; color:#721c24; border:1px solid #f5c6cb;}
    .lm-row-updated td{background:#f8fbff;}
    .lm-row-updated{animation:lmRowUpdatedFlash 1.05s ease-out;}
    @keyframes lmRowUpdatedFlash{
      0%{box-shadow:inset 0 0 0 9999px rgba(56,189,248,.12);}
      100%{box-shadow:inset 0 0 0 9999px rgba(56,189,248,0);}
    }
    .lm-updated-badge{
      display:inline-block;
      margin:4px 0 0;
      padding:2px 8px;
      border-radius:999px;
      font-size:10px;
      font-weight:700;
      letter-spacing:.3px;
      text-transform:uppercase;
      color:#0f5132;
      background:#e8f7ee;
      border:1px solid #b7e4c7;
      animation:lmUpdatedBadgeIn .2s ease-out;
    }
    .lm-snippet-anchor{
      background:#fff3bf;
      color:#5f3b00;
      border-radius:3px;
      padding:0 2px;
      font-weight:600;
    }
    @keyframes lmUpdatedBadgeIn{
      0%{transform:translateY(-3px); opacity:0;}
      100%{transform:translateY(0); opacity:1;}
    }
    
    .lm-grid{display:grid; grid-template-columns:1fr 1fr; gap:12px;}
    body.links-manager_page_links-manager-target .lm-grid{grid-auto-flow:row;}
    body.links-manager_page_links-manager-target .lm-card-grouping{order:1;}
    body.links-manager_page_links-manager-target .lm-card-target{order:2;}
    body.links-manager_page_links-manager-target .lm-card-summary{order:3;}
    .lm-card{background:#fff; border:1px solid #c3c4c7; padding:12px; border-radius:6px; margin:12px 0; width:100%; box-sizing:border-box;}
    .lm-card-full{grid-column:1 / -1; width:100%;}
    .lm-filter-select{min-width:140px; max-width:240px;}
    .lm-filter-grid{display:grid; grid-template-columns:repeat(5, minmax(170px, 1fr)); gap:10px 12px; align-items:start; margin-bottom:10px;}
    .lm-filter-field{min-width:0;}
    .lm-filter-field-full{grid-column:1 / -1;}
    .lm-filter-field-wide{grid-column:span 2;}
    .lm-filter-grid input[type=text],
    .lm-filter-grid input[type=number],
    .lm-filter-grid select{width:100%; max-width:none;}
    .lm-filter-grid .lm-checklist{max-height:170px; overflow:auto; border:1px solid #dcdcde; padding:8px; background:#fff;}
    .lm-filter-table{width:100%;}
    .lm-filter-table tbody{display:grid; grid-template-columns:repeat(5, minmax(170px, 1fr)); gap:10px 12px; align-items:start;}
    .lm-filter-table tr{display:block; margin:0;}
    .lm-filter-table th{display:block; width:auto; padding:0 0 6px 0; margin:0; font-size:11px; color:#646970; font-weight:600; text-align:left;}
    .lm-filter-table td{display:block; width:auto; padding:0; margin:0;}
    .lm-filter-table tr.lm-filter-full{grid-column:1 / -1;}
    .lm-filter-table input[type=text],
    .lm-filter-table input[type=number],
    .lm-filter-table select{width:100%; max-width:none;}
    .lm-filter-table .regular-text{width:100%; max-width:none;}
    .lm-stats-wrap{background:linear-gradient(135deg,#f5f7fb 0%,#eef2f7 100%); border:1px solid #e1e5ea; padding:16px; border-radius:12px; margin:12px 0 16px;}
    .lm-stats-grid{display:grid; grid-template-columns:repeat(3, minmax(0,1fr)); gap:12px;}
    .lm-stats-grid > *{min-width:0;}
    .lm-stat{background:#fff; border:1px solid #e6e8ee; border-radius:10px; padding:14px 14px 12px; box-shadow:0 1px 2px rgba(0,0,0,.04);}
    .lm-stat .lm-stat-label{font-size:11px; color:#6b7280; text-transform:uppercase; letter-spacing:.6px;}
    .lm-stat .lm-stat-value{font-size:24px; font-weight:700; margin-top:6px;}
    .lm-stat .lm-stat-sub{font-size:12px; color:#6b7280; margin-top:4px;}
    .lm-pill{display:inline-flex; align-items:center; gap:6px; padding:4px 8px; border-radius:999px; font-size:11px; font-weight:600; border:1px solid transparent;}
    .lm-pill.bad{background:#fff1f2; color:#b91c1c; border-color:#fecdd3;}
    .lm-pill.ok{background:#ecfdf3; color:#15803d; border-color:#bbf7d0;}
    .lm-pill.warn{background:#fff7ed; color:#b45309; border-color:#fed7aa;}
    .lm-audit-table th{background:#f8fafc; position:sticky; top:0;}
    .lm-top-grid{display:grid; grid-template-columns:repeat(2, minmax(0,1fr)); gap:12px; margin-bottom:24px;}
    .lm-top-card{background:#fff; border:1px solid #e6e8ee; border-radius:10px; padding:12px;}
    .lm-top-card h3{margin:0 0 8px; font-size:13px; font-weight:700;}
    .lm-top-list{list-style:none; margin:0; padding:0; font-size:12px;}
    .lm-top-list li{display:flex; justify-content:space-between; gap:10px; padding:6px 0; border-bottom:1px solid #f1f5f9;}
    .lm-top-list li:last-child{border-bottom:none;}
    .lm-top-name{flex:1; min-width:0; color:#111827;}
    .lm-top-count{color:#6b7280; font-variant-numeric:tabular-nums;}
    .lm-pie-card{background:#fff; border:1px solid #e6e8ee; border-radius:10px; padding:12px; display:flex; align-items:center; gap:16px;}
    .lm-pie-card-inline{margin:0; height:100%; width:100%; min-width:0; box-sizing:border-box; overflow:visible; align-items:flex-start;}
    .lm-pie-card-inline .lm-pie{width:110px; height:110px;}
    .lm-pie-card-inline h3{font-size:12px; margin:0 0 4px;}
    .lm-pie-card-inline > div:last-child{min-width:0; flex:1;}
    .lm-pie-card-inline .lm-pie-legend{gap:6px;}
    .lm-pie-card-inline .lm-pie-item{font-size:11px; white-space:normal;}
    .lm-pie-card-inline .lm-tooltip:hover::after{
      top:auto;
      bottom:calc(100% + 8px);
    }
    .lm-pie-card-inline .lm-tooltip:hover::before{
      top:auto;
      bottom:calc(100% + 2px);
      border-width:6px 6px 0 6px;
      border-color:#111827 transparent transparent transparent;
    }
    .lm-pie{width:140px; height:140px; border-radius:50%; background:#e5e7eb; position:relative; flex:0 0 auto;}
    .lm-pie-center{position:absolute; inset:10px; border-radius:50%; background:#fff; display:flex; align-items:center; justify-content:center; font-size:12px; color:#6b7280;}
    .lm-pie-legend{display:flex; gap:10px; flex-wrap:wrap; font-size:12px;}
    .lm-pie-item{display:flex; align-items:center; gap:6px; color:#111827;}
    .lm-pie-swatch{width:10px; height:10px; border-radius:2px;}
    .lm-chart-grid{display:grid; grid-template-columns:repeat(2, minmax(0,1fr)); gap:12px; margin:12px 0 16px;}
    .lm-bar-card{background:#fff; border:1px solid #e6e8ee; border-radius:10px; padding:12px;}
    .lm-bar-card h3{margin:0 0 8px; font-size:13px; font-weight:700;}
    .lm-bar-row{display:flex; align-items:center; gap:8px; margin:6px 0;}
    .lm-bar-label{width:140px; font-size:12px; color:#111827;}
    .lm-bar-track{flex:1; height:8px; background:#eef2f7; border-radius:999px; overflow:hidden;}
    .lm-bar-track-wrap{flex:1; position:relative; overflow:visible;}
    .lm-bar-fill{height:100%; border-radius:999px;}
    .lm-bar-value{width:60px; text-align:right; font-size:11px; color:#6b7280;}
    .lm-dual-bar{display:flex; gap:6px;}
    .lm-dual-bar .lm-bar-track{height:6px;}
    .lm-stacked-wrap{display:flex; align-items:center; gap:8px; flex:1;}
    .lm-stacked-track{flex:1; height:12px; background:#eef2f7; border-radius:999px; overflow:hidden; display:flex;}
    .lm-stacked-seg{height:100%;}
    .lm-stacked-seg-internal{background:#2563eb;}
    .lm-stacked-seg-external{background:#f59e0b;}
    .lm-stacked-meta{width:110px; text-align:right; font-size:11px; color:#374151; font-variant-numeric:tabular-nums;}
    .lm-chart-hint{font-size:11px; color:#6b7280; margin:4px 0 8px;}
    .lm-empty{font-size:12px; color:#6b7280;}
    .lm-legend{display:flex; gap:10px; flex-wrap:wrap; font-size:11px; color:#6b7280; margin:6px 0 8px;}
    .lm-legend-item{display:inline-flex; align-items:center; gap:6px;}
    .lm-legend-swatch{width:10px; height:10px; border-radius:2px; display:inline-block;}
    .lm-tooltip{position:relative; cursor:default;}
    .lm-tooltip:hover::after{
      content: attr(data-tooltip);
      position:absolute;
      left:50%;
      top:calc(100% + 8px);
      transform:translateX(-50%);
      background:#111827;
      color:#fff;
      padding:4px 8px;
      border-radius:4px;
      font-size:11px;
      white-space:normal;
      line-height:1.35;
      max-width:260px;
      min-width:140px;
      text-align:left;
      z-index:60;
      box-shadow:0 2px 6px rgba(0,0,0,.2);
      pointer-events:none;
    }
    .lm-tooltip:hover::before{
      content:'';
      position:absolute;
      left:50%;
      transform:translateX(-50%);
      top:calc(100% + 2px);
      border-width:0 6px 6px 6px;
      border-style:solid;
      border-color:transparent transparent #111827 transparent;
      z-index:60;
      pointer-events:none;
    }
    .lm-tooltip.is-left:hover::after{
      left:0;
      transform:none;
    }
    .lm-tooltip.is-left:hover::before{
      left:14px;
      transform:none;
    }
    .lm-tooltip.is-right:hover::after{
      left:auto;
      right:0;
      transform:none;
    }
    .lm-tooltip.is-right:hover::before{
      left:auto;
      right:14px;
      transform:none;
    }
    body.toplevel_page_links-manager #wpfooter,
    body.links-manager_page_links-manager-stats #wpfooter,
    body.links-manager_page_links-manager-pages-link #wpfooter,
    body.links-manager_page_links-manager-target #wpfooter,
    body.links-manager_page_links-manager-cited-domains #wpfooter,
    body.links-manager_page_links-manager-all-anchor-text #wpfooter{
      position:static;
    }
    body.toplevel_page_links-manager #wpbody-content,
    body.links-manager_page_links-manager-stats #wpbody-content,
    body.links-manager_page_links-manager-pages-link #wpbody-content,
    body.links-manager_page_links-manager-target #wpbody-content,
    body.links-manager_page_links-manager-cited-domains #wpbody-content,
    body.links-manager_page_links-manager-all-anchor-text #wpbody-content{
      padding-bottom:48px;
    }
    body.links-manager_page_links-manager-target #wpbody-content{
      max-width:none;
    }
    body.links-manager_page_links-manager-target #wpbody-content .wrap{
      max-width:none;
    }
    body.links-manager_page_links-manager-target .lm-card-grouping{order:1;}
    body.links-manager_page_links-manager-target .lm-card-target{order:2;}
    body.links-manager_page_links-manager-target .lm-card-summary{order:3;}
    @media (max-width: 1400px){
      .lm-filter-grid{grid-template-columns:repeat(4, minmax(160px, 1fr));}
      .lm-filter-table tbody{grid-template-columns:repeat(4, minmax(160px, 1fr));}
    }
    @media (max-width: 1200px){
      .lm-grid{grid-template-columns:1fr;}
      .lm-table{min-width:100vw;}
      .lm-filter-grid{grid-template-columns:repeat(3, minmax(160px, 1fr));}
      .lm-filter-table tbody{grid-template-columns:repeat(3, minmax(160px, 1fr));}
    }
    @media (max-width: 1024px){
      .lm-stats-grid{grid-template-columns:repeat(2, minmax(0,1fr));}
      .lm-top-grid{grid-template-columns:1fr;}
      .lm-filter-grid{grid-template-columns:repeat(2, minmax(160px, 1fr));}
      .lm-filter-table tbody{grid-template-columns:repeat(2, minmax(160px, 1fr));}
    }
    @media (max-width: 640px){
      .lm-stats-grid{grid-template-columns:1fr;}
      .lm-pie-card-inline{flex-direction:column; gap:10px;}
      .lm-filter-grid{grid-template-columns:1fr;}
      .lm-filter-field-wide{grid-column:1 / -1;}
      .lm-filter-table tbody{grid-template-columns:1fr;}
    }
    ";
    wp_register_style('lm-admin', false);
    wp_enqueue_style('lm-admin');
    wp_add_inline_style('lm-admin', $css);

    $js = "
    document.addEventListener('DOMContentLoaded', function() {
      const escapeHtml = (value) => String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/\"/g, '&quot;')
        .replace(/'/g, '&#039;');

      const renderHighlightedSnippet = (snippet, anchor) => {
        const snippetText = String(snippet || '');
        const anchorText = String(anchor || '').trim();
        if (!anchorText) return escapeHtml(snippetText);
        const lowerSnippet = snippetText.toLowerCase();
        const lowerAnchor = anchorText.toLowerCase();
        const matchIndex = lowerSnippet.indexOf(lowerAnchor);
        if (matchIndex === -1) return escapeHtml(snippetText);

        const before = snippetText.slice(0, matchIndex);
        const match = snippetText.slice(matchIndex, matchIndex + anchorText.length);
        const after = snippetText.slice(matchIndex + anchorText.length);

        return escapeHtml(before)
          + '<mark class=\"lm-snippet-anchor\">' + escapeHtml(match) + '</mark>'
          + escapeHtml(after);
      };

      const markRowUpdated = (tr) => {
        if (!tr) return;
        tr.classList.remove('lm-row-updated');
        // Force reflow so repeated updates retrigger animation.
        void tr.offsetWidth;
        tr.classList.add('lm-row-updated');

        const editCell = tr.querySelector('.lm-col-edit');
        if (!editCell) return;
        const oldBadge = editCell.querySelector('.lm-updated-badge');
        if (oldBadge) oldBadge.remove();

        const badge = document.createElement('span');
        badge.className = 'lm-updated-badge';
        badge.innerText = 'Updated';
        editCell.appendChild(badge);

        setTimeout(() => {
          tr.classList.remove('lm-row-updated');
          if (badge.parentNode) badge.remove();
        }, 1600);
      };

      const submitInlineEditForm = (form) => {
        if (!form) return;
        const btn = form.querySelector('.lm-edit-submit, button[type=submit]');
        const msg = form.querySelector('.lm-form-msg');
        if (btn) btn.disabled = true;
        if (msg) { msg.className = 'lm-form-msg'; msg.style.display = 'none'; }

        const fd = new FormData(form);
        // Override fallback admin-post action to ensure AJAX handler is always used.
        fd.set('action', 'lm_update_link_ajax');

        fetch(ajaxurl, { method: 'POST', body: fd })
          .then(r => r.json())
          .then(data => {
            const payload = (data && typeof data === 'object' && Object.prototype.hasOwnProperty.call(data, 'success') && data.data && typeof data.data === 'object')
              ? Object.assign({ ok: !!data.success }, data.data)
              : (data || {});

            if (!payload.ok && msg) {
              msg.classList.add('error');
              msg.innerText = payload.msg || 'Unknown error';
              msg.style.display = 'block';
            }

            if (payload.ok && payload.updated_anchor !== undefined) {
              const tr = form.closest('tr');
              if (tr) {
                const anchorSpan = tr.querySelector('.lm-col-anchor [data-anchor]');
                if (anchorSpan) {
                  anchorSpan.innerText = payload.updated_anchor;
                  anchorSpan.title = payload.updated_anchor;
                }

                const qualityCell = tr.querySelector('.lm-col-quality');
                if (qualityCell && payload.updated_quality !== undefined) {
                  qualityCell.innerText = payload.updated_quality;
                }

                if (payload.updated_link !== undefined) {
                  const linkCell = tr.querySelector('.lm-col-link');
                  if (linkCell) {
                    if (payload.updated_link) {
                      const safeLink = String(payload.updated_link);
                      linkCell.innerHTML = '';
                      const a = document.createElement('a');
                      a.href = safeLink;
                      a.target = '_blank';
                      a.rel = 'noopener noreferrer';
                      const span = document.createElement('span');
                      span.className = 'lm-trunc';
                      span.title = safeLink;
                      span.innerText = safeLink;
                      a.appendChild(span);
                      linkCell.appendChild(a);
                    } else {
                      linkCell.innerHTML = '';
                    }
                  }
                }

                if (payload.updated_rel_text !== undefined) {
                  const relSpan = tr.querySelector('.lm-col-rel .lm-trunc');
                  if (relSpan) {
                    relSpan.innerText = payload.updated_rel_text;
                    relSpan.title = payload.updated_rel_text;
                  }
                }

                if (payload.updated_snippet_display !== undefined) {
                  const snippetSpan = tr.querySelector('.lm-col-snippet .lm-trunc');
                  if (snippetSpan) {
                    snippetSpan.innerHTML = renderHighlightedSnippet(payload.updated_snippet_display, payload.updated_anchor);
                    snippetSpan.title = payload.updated_snippet_full !== undefined ? payload.updated_snippet_full : payload.updated_snippet_display;
                  }
                }
              }

              const oldLinkField = form.querySelector('input[name=old_link]');
              const oldAnchorField = form.querySelector('input[name=old_anchor]');
              const oldRelField = form.querySelector('input[name=old_rel]');
              const oldSnippetField = form.querySelector('input[name=old_snippet]');
              if (oldLinkField && payload.updated_link !== undefined) oldLinkField.value = payload.updated_link;
              if (oldAnchorField && payload.updated_anchor !== undefined) oldAnchorField.value = payload.updated_anchor;
              if (oldRelField && payload.updated_rel_raw !== undefined) oldRelField.value = payload.updated_rel_raw;
              if (oldSnippetField && payload.updated_snippet_full !== undefined) oldSnippetField.value = payload.updated_snippet_full;

              const newLinkField = form.querySelector('input[name=new_link]');
              const newAnchorField = form.querySelector('input[name=new_anchor]');
              const newRelField = form.querySelector('input[name=new_rel]');
              if (newLinkField) newLinkField.value = '';
              if (newAnchorField) newAnchorField.value = '';
              if (newRelField) newRelField.value = '';

              markRowUpdated(form.closest('tr'));
            }
            if (btn) btn.disabled = false;
            if (payload.ok) {
              if (msg) { msg.className = 'lm-form-msg'; msg.style.display = 'none'; }
              return;
            }
            setTimeout(() => { if (msg) { msg.className = 'lm-form-msg'; msg.style.display = 'none'; } }, 4000);
          })
          .catch(err => {
            if (msg) {
              msg.classList.add('error');
              msg.innerText = 'Network error: ' + err.message;
              msg.style.display = 'block';
            }
            if (btn) btn.disabled = false;
          });
      };

      // Use capture phase so native form submission is blocked reliably.
      document.addEventListener('submit', function(e) {
        const form = e.target;
        if (!(form instanceof HTMLFormElement)) return;
        if (!form.classList.contains('lm-edit-form')) return;
        e.preventDefault();
        e.stopPropagation();
        submitInlineEditForm(form);
      }, true);

      // Primary trigger for row editing: explicit button click (non-submit button).
      document.addEventListener('click', function(e) {
        const btn = e.target && e.target.closest ? e.target.closest('.lm-edit-submit') : null;
        if (!btn) return;
        const form = btn.closest('form.lm-edit-form');
        if (!form) return;
        e.preventDefault();
        submitInlineEditForm(form);
      });

      // Handle Links Target group change forms
      document.querySelectorAll('.lm-target-group-form').forEach(form => {
        form.addEventListener('submit', function(e) {
          e.preventDefault();
          const btn = form.querySelector('button[type=submit]');
          const msg = form.querySelector('.lm-form-msg');
          if (btn) btn.disabled = true;
          if (msg) { msg.className = 'lm-form-msg'; msg.style.display = 'none'; }

          const fd = new FormData(form);
          // Override fallback admin-post action to ensure AJAX handler is always used.
          fd.set('action', 'lm_update_anchor_target_group_ajax');

          fetch(ajaxurl, { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
              const payload = (data && typeof data === 'object' && Object.prototype.hasOwnProperty.call(data, 'success') && data.data && typeof data.data === 'object')
                ? Object.assign({ ok: !!data.success }, data.data)
                : (data || {});
              if (!msg) return;
              msg.classList.add(payload.ok ? 'success' : 'error');
              msg.innerText = payload.msg || 'Unknown error';
              msg.style.display = 'block';
              if (payload.ok && payload.updated_group !== undefined) {
                const tr = form.closest('tr');
                if (tr) {
                  const groupSpan = tr.querySelector('td.lm-col-group .lm-trunc');
                  if (groupSpan) { 
                    const displayText = payload.updated_group === 'no_group' ? '—' : payload.updated_group;
                    groupSpan.innerText = displayText; 
                    groupSpan.title = displayText;
                  }
                }
              }
              if (btn) btn.disabled = false;
              setTimeout(() => { if (msg) { msg.className = 'lm-form-msg'; msg.style.display = 'none'; } }, 3000);
            })
            .catch(err => {
              if (msg) {
                msg.classList.add('error');
                msg.innerText = 'Network error: ' + err.message;
                msg.style.display = 'block';
              }
              if (btn) btn.disabled = false;
            });
        });
      });

      // Normalize legacy table-based filter forms into consistent scanning order.
      document.querySelectorAll('table.lm-filter-table tbody').forEach(tbody => {
        const rows = Array.from(tbody.querySelectorAll('tr'));
        if (!rows.length) return;

        const getLabel = (row) => {
          const th = row.querySelector('th');
          return th ? (th.textContent || '').trim() : '';
        };

        rows.forEach(row => {
          const label = getLabel(row);
          if (/^Advanced$|^Export$/i.test(label)) {
            row.classList.add('lm-filter-full');
          }
        });

        const textModeRow = rows.find(row => /^Text Search Mode$/i.test(getLabel(row)));
        if (textModeRow) {
          const searchRows = rows.filter(row => /^Search /i.test(getLabel(row)));
          const lastSearchRow = searchRows.length ? searchRows[searchRows.length - 1] : null;
          if (lastSearchRow && lastSearchRow !== textModeRow) {
            lastSearchRow.after(textModeRow);
          }
        }
      });

      const rebuildRoot = document.getElementById('lm-rest-rebuild-controls');
      if (rebuildRoot) {
        const runBtn = rebuildRoot.querySelector('[data-lm-rest-rebuild-run]');
        const refreshBtn = rebuildRoot.querySelector('[data-lm-rest-rebuild-refresh]');
        const statusEl = rebuildRoot.querySelector('[data-lm-rest-rebuild-status]');
        const progressEl = rebuildRoot.querySelector('[data-lm-rest-rebuild-progress]');
        const metaEl = rebuildRoot.querySelector('[data-lm-rest-rebuild-meta]');
        const barEl = rebuildRoot.querySelector('[data-lm-rest-rebuild-bar]');
        const batchInput = rebuildRoot.querySelector('[data-lm-rest-rebuild-batch]');
        const config = (window.LM_REBUILD_REST && typeof window.LM_REBUILD_REST === 'object') ? window.LM_REBUILD_REST : null;

        let loopTimer = null;
        let isLooping = false;

        const setRunningUi = (running) => {
          if (runBtn) {
            runBtn.disabled = !!running;
            runBtn.textContent = running ? 'Rebuilding...' : 'Start / Continue REST Rebuild';
          }
          if (refreshBtn) refreshBtn.disabled = !!running;
          if (batchInput) batchInput.disabled = !!running;
        };

        const setStatusText = (text, isError) => {
          if (!statusEl) return;
          statusEl.textContent = text;
          statusEl.style.color = isError ? '#b32d2e' : '#1d2327';
        };

        const updateProgress = (state) => {
          const total = Math.max(0, parseInt((state && state.total_posts) ? state.total_posts : 0, 10) || 0);
          const processed = Math.max(0, parseInt((state && state.processed_posts) ? state.processed_posts : 0, 10) || 0);
          const rows = Math.max(0, parseInt((state && state.rows_count) ? state.rows_count : 0, 10) || 0);
          const status = String((state && state.status) ? state.status : 'idle');
          const pct = total > 0 ? Math.max(0, Math.min(100, Math.round((processed / total) * 100))) : (status === 'done' ? 100 : 0);

          if (progressEl) {
            progressEl.textContent = total > 0
              ? processed.toLocaleString() + ' / ' + total.toLocaleString() + ' posts (' + pct + '%)'
              : 'No active rebuild job.';
          }

          if (metaEl) {
            const updatedAt = state && state.updated_at ? String(state.updated_at) : '-';
            const batch = Math.max(0, parseInt((state && state.batch_size) ? state.batch_size : 0, 10) || 0);
            metaEl.textContent = 'Status: ' + status + ' | Rows: ' + rows.toLocaleString() + ' | Batch: ' + (batch > 0 ? batch.toLocaleString() : '-') + ' | Updated: ' + updatedAt;
          }

          if (barEl) {
            barEl.style.width = String(pct) + '%';
            barEl.setAttribute('aria-valuenow', String(pct));
          }
        };

        const apiCall = (path, method, body) => {
          if (!config || !config.base || !config.nonce) {
            return Promise.reject(new Error('REST config unavailable.'));
          }

          const headers = {
            'X-WP-Nonce': String(config.nonce),
          };
          const opts = {
            method: method,
            headers: headers,
            credentials: 'same-origin',
          };

          if (body && typeof body === 'object') {
            headers['Content-Type'] = 'application/json';
            opts.body = JSON.stringify(body);
          }

          return fetch(String(config.base).replace(/\/$/, '') + path, opts)
            .then(r => {
              if (!r.ok) {
                return r.text().then(t => {
                  throw new Error(t || ('HTTP ' + r.status));
                });
              }
              return r.json();
            });
        };

        const getRequestedBatch = () => {
          if (!batchInput) return 0;
          const val = parseInt(batchInput.value, 10);
          return Number.isFinite(val) && val > 0 ? val : 0;
        };

        const refreshStatus = () => {
          return apiCall('/rebuild/status', 'GET')
            .then(state => {
              updateProgress(state || {});
              const status = String((state && state.status) ? state.status : 'idle');
              if (status === 'running') {
                setStatusText('Rebuild job is running. You can continue it now.', false);
              } else if (status === 'done') {
                setStatusText('Rebuild finished. Cache is updated.', false);
              } else if (status === 'error') {
                setStatusText('Rebuild failed: ' + String((state && state.last_error) ? state.last_error : 'Unknown error'), true);
              } else {
                setStatusText('No active rebuild job.', false);
              }
              return state;
            });
        };

        const runStepLoop = () => {
          if (isLooping) return;
          isLooping = true;
          setRunningUi(true);

          const iterate = () => {
            const payload = {};
            const batch = getRequestedBatch();
            if (batch > 0) payload.batch = batch;

            apiCall('/rebuild/step', 'POST', payload)
              .then(state => {
                updateProgress(state || {});
                const status = String((state && state.status) ? state.status : 'idle');
                if (status === 'running') {
                  setStatusText('Rebuild in progress...', false);
                  loopTimer = window.setTimeout(iterate, 120);
                  return;
                }

                isLooping = false;
                setRunningUi(false);
                if (status === 'done') {
                  setStatusText('Rebuild finished. Cache is updated.', false);
                } else if (status === 'error') {
                  setStatusText('Rebuild failed: ' + String((state && state.last_error) ? state.last_error : 'Unknown error'), true);
                } else {
                  setStatusText('No active rebuild job. Press Start to create one.', false);
                }
              })
              .catch(err => {
                isLooping = false;
                setRunningUi(false);
                setStatusText('REST step error: ' + err.message, true);
              });
          };

          iterate();
        };

        if (runBtn) {
          runBtn.addEventListener('click', function(e) {
            e.preventDefault();
            if (loopTimer) {
              window.clearTimeout(loopTimer);
              loopTimer = null;
            }

            setStatusText('Starting rebuild job...', false);
            const startPayload = {
              post_type: 'any',
              wpml_lang: 'all',
            };

            apiCall('/rebuild/start', 'POST', startPayload)
              .then(state => {
                updateProgress(state || {});
                const status = String((state && state.status) ? state.status : 'idle');
                if (status === 'done') {
                  setStatusText('Rebuild finished immediately. Cache is updated.', false);
                  setRunningUi(false);
                  return;
                }
                if (status === 'error') {
                  setStatusText('Failed to start rebuild: ' + String((state && state.last_error) ? state.last_error : 'Unknown error'), true);
                  setRunningUi(false);
                  return;
                }
                setStatusText('Rebuild started. Processing chunks...', false);
                runStepLoop();
              })
              .catch(err => {
                setRunningUi(false);
                setStatusText('REST start error: ' + err.message, true);
              });
          });
        }

        if (refreshBtn) {
          refreshBtn.addEventListener('click', function(e) {
            e.preventDefault();
            refreshStatus().catch(err => {
              setStatusText('REST status error: ' + err.message, true);
            });
          });
        }

        refreshStatus().catch(err => {
          setStatusText('REST status error: ' + err.message, true);
        });
      }
    });
    ";
    wp_register_script('lm-admin-js', false);
    wp_enqueue_script('lm-admin-js');
    wp_add_inline_script('lm-admin-js', 'window.LM_REBUILD_REST = ' . wp_json_encode([
      'base' => esc_url_raw(rest_url('links-manager/v1')),
      'nonce' => wp_create_nonce('wp_rest'),
    ]) . ';', 'before');
    wp_add_inline_script('lm-admin-js', $js);
  }

  /* -----------------------------
   * Utils
   * ----------------------------- */

  private function get_available_post_types() {
    $pts = get_post_types(['public' => true], 'objects');
    $out = [];
    foreach ($pts as $pt) $out[$pt->name] = $pt->labels->singular_name;
    return $out;
  }

  private function get_default_scan_post_types($availablePostTypes = null) {
    if (!is_array($availablePostTypes)) {
      $availablePostTypes = $this->get_available_post_types();
    }

    $defaults = [];
    if (isset($availablePostTypes['post'])) $defaults[] = 'post';
    if (isset($availablePostTypes['page'])) $defaults[] = 'page';

    if (empty($defaults)) {
      $defaults = array_keys($availablePostTypes);
    }

    return $defaults;
  }

  private function sanitize_scan_post_types($scanPostTypes, $availablePostTypes = null) {
    if (!is_array($availablePostTypes)) {
      $availablePostTypes = $this->get_available_post_types();
    }

    $validPostTypes = array_keys($availablePostTypes);
    $sanitized = [];
    foreach ((array)$scanPostTypes as $pt) {
      $pt = sanitize_key((string)$pt);
      if ($pt !== '' && in_array($pt, $validPostTypes, true)) {
        $sanitized[$pt] = true;
      }
    }

    // Keep scanning functional even if all checkboxes are unchecked.
    if (empty($sanitized)) {
      foreach ($this->get_default_scan_post_types($availablePostTypes) as $pt) {
        $sanitized[$pt] = true;
      }
    }

    return array_keys($sanitized);
  }

  private function get_enabled_scan_post_types() {
    $availablePostTypes = $this->get_available_post_types();
    $settings = $this->get_settings();
    $selectedPostTypes = isset($settings['scan_post_types']) && is_array($settings['scan_post_types'])
      ? $settings['scan_post_types']
      : $this->get_default_scan_post_types($availablePostTypes);

    return $this->sanitize_scan_post_types($selectedPostTypes, $availablePostTypes);
  }

  private function get_filterable_post_types() {
    $availablePostTypes = $this->get_available_post_types();
    $enabledPostTypes = $this->get_enabled_scan_post_types();

    $filtered = [];
    foreach ($enabledPostTypes as $pt) {
      $pt = sanitize_key((string)$pt);
      if ($pt !== '' && isset($availablePostTypes[$pt])) {
        $filtered[$pt] = $availablePostTypes[$pt];
      }
    }

    if (empty($filtered)) {
      return $availablePostTypes;
    }

    return $filtered;
  }

  private function get_scan_source_type_options() {
    return [
      'content' => 'Content',
      'excerpt' => 'Excerpt',
      'meta' => 'Meta',
      'menu' => 'Menu',
    ];
  }

  private function get_default_scan_source_types() {
    return ['content'];
  }

  private function sanitize_scan_source_types($scanSourceTypes) {
    $options = $this->get_scan_source_type_options();
    $valid = array_keys($options);

    $sanitized = [];
    foreach ((array)$scanSourceTypes as $src) {
      $src = sanitize_key((string)$src);
      if ($src !== '' && in_array($src, $valid, true)) {
        $sanitized[$src] = true;
      }
    }

    if (empty($sanitized)) {
      foreach ($this->get_default_scan_source_types() as $src) {
        $sanitized[(string)$src] = true;
      }
    }

    return array_keys($sanitized);
  }

  private function get_enabled_scan_source_types() {
    $settings = $this->get_settings();
    $selected = isset($settings['scan_source_types']) && is_array($settings['scan_source_types'])
      ? $settings['scan_source_types']
      : $this->get_default_scan_source_types();

    return $this->sanitize_scan_source_types($selected);
  }

  private function get_scan_value_type_options() {
    return [
      'url' => 'Full URL',
      'relative' => 'Relative URL',
      'anchor' => 'Anchor (#)',
      'mailto' => 'Email (mailto)',
      'tel' => 'Phone (tel)',
      'javascript' => 'Javascript',
      'other' => 'Other',
      'empty' => 'Empty',
    ];
  }

  private function get_default_scan_value_types() {
    return ['url', 'relative'];
  }

  private function sanitize_scan_value_types($scanValueTypes) {
    $options = $this->get_scan_value_type_options();
    $valid = array_keys($options);

    $sanitized = [];
    foreach ((array)$scanValueTypes as $valueType) {
      $valueType = sanitize_key((string)$valueType);
      if ($valueType !== '' && in_array($valueType, $valid, true)) {
        $sanitized[$valueType] = true;
      }
    }

    if (empty($sanitized)) {
      foreach ($this->get_default_scan_value_types() as $valueType) {
        $sanitized[(string)$valueType] = true;
      }
    }

    return array_keys($sanitized);
  }

  private function get_enabled_scan_value_types() {
    $settings = $this->get_settings();
    $selected = isset($settings['scan_value_types']) && is_array($settings['scan_value_types'])
      ? $settings['scan_value_types']
      : $this->get_default_scan_value_types();

    return $this->sanitize_scan_value_types($selected);
  }

  private function is_scan_value_type_enabled($valueType, $enabledValueTypesMap = null) {
    $valueType = sanitize_key((string)$valueType);
    if ($valueType === '') {
      $valueType = 'empty';
    }

    if (!is_array($enabledValueTypesMap)) {
      $enabledValueTypesMap = [];
      foreach ($this->get_enabled_scan_value_types() as $enabledType) {
        $enabledValueTypesMap[sanitize_key((string)$enabledType)] = true;
      }
    }

    return isset($enabledValueTypesMap[$valueType]);
  }

  private function get_scan_author_options() {
    $users = get_users([
      'who' => 'authors',
      'fields' => ['ID', 'display_name'],
      'orderby' => 'display_name',
      'order' => 'ASC',
    ]);

    if (empty($users) || !is_array($users)) {
      return [];
    }

    $options = [];
    foreach ($users as $user) {
      $userId = isset($user->ID) ? (int)$user->ID : 0;
      if ($userId <= 0) continue;
      $displayName = isset($user->display_name) ? (string)$user->display_name : ('User #' . $userId);
      $options[$userId] = $displayName;
    }

    return $options;
  }

  private function sanitize_scan_author_ids($authorIds, $authorOptions = null) {
    if (!is_array($authorOptions)) {
      $authorOptions = $this->get_scan_author_options();
    }

    $sanitized = [];
    foreach ((array)$authorIds as $authorId) {
      $authorId = (int)$authorId;
      if ($authorId > 0 && isset($authorOptions[$authorId])) {
        $sanitized[$authorId] = true;
      }
    }

    return array_map('intval', array_keys($sanitized));
  }

  private function get_enabled_scan_author_ids() {
    $settings = $this->get_settings();
    $selected = isset($settings['scan_author_ids']) && is_array($settings['scan_author_ids'])
      ? $settings['scan_author_ids']
      : [];

    return $this->sanitize_scan_author_ids($selected);
  }

  private function get_scan_modified_within_days() {
    $settings = $this->get_settings();
    $days = isset($settings['scan_modified_within_days']) ? (int)$settings['scan_modified_within_days'] : 0;
    if ($days < 0) $days = 0;
    if ($days > 3650) $days = 3650;
    return $days;
  }

  private function get_scan_modified_after_gmt($modified_after_gmt = '') {
    $effectiveAfterGmt = trim((string)$modified_after_gmt);
    $windowDays = $this->get_scan_modified_within_days();

    if ($windowDays > 0) {
      $windowAfterTs = time() - ($windowDays * DAY_IN_SECONDS);
      $windowAfterGmt = gmdate('Y-m-d H:i:s', $windowAfterTs);

      if ($effectiveAfterGmt === '') {
        $effectiveAfterGmt = $windowAfterGmt;
      } else {
        $effectiveTs = strtotime($effectiveAfterGmt . ' UTC');
        if ($effectiveTs === false || $effectiveTs < $windowAfterTs) {
          $effectiveAfterGmt = $windowAfterGmt;
        }
      }
    }

    return $effectiveAfterGmt;
  }

  private function execute_scan_scope_post_count_query($postTypes, $wpmlLang = 'all') {
    $postTypes = array_values(array_unique(array_map('sanitize_key', (array)$postTypes)));
    if (empty($postTypes)) {
      return 0;
    }

    $queryArgs = [
      'post_type' => $postTypes,
      'post_status' => 'publish',
      'posts_per_page' => 1,
      'fields' => 'ids',
      'suppress_filters' => false,
      'no_found_rows' => false,
      'orderby' => 'ID',
      'order' => 'ASC',
      'update_post_meta_cache' => false,
      'update_post_term_cache' => false,
      'cache_results' => false,
    ];

    $scanAuthorIds = $this->get_enabled_scan_author_ids();
    if (!empty($scanAuthorIds)) {
      $queryArgs['author__in'] = array_values(array_map('intval', $scanAuthorIds));
    }

    $scanModifiedAfterGmt = $this->get_scan_modified_after_gmt('');
    if ($scanModifiedAfterGmt !== '') {
      $queryArgs['date_query'] = [[
        'column' => 'post_modified_gmt',
        'after' => $scanModifiedAfterGmt,
        'inclusive' => false,
      ]];
    }

    $globalTaxQuery = $this->get_global_scan_tax_query($postTypes);
    if (!empty($globalTaxQuery)) {
      $queryArgs['tax_query'] = $globalTaxQuery;
    }

    if ($this->is_wpml_active()) {
      $queryArgs['lang'] = ($wpmlLang === 'all') ? '' : sanitize_key((string)$wpmlLang);
    }

    $q = new WP_Query($queryArgs);
    return max(0, (int)$q->found_posts);
  }

  private function get_scan_scope_estimate_summary() {
    $postTypes = $this->get_enabled_scan_post_types();
    $wpmlLangs = $this->get_enabled_scan_wpml_langs();
    $hasAllLangs = in_array('all', $wpmlLangs, true);

    $estimatedPosts = 0;
    if ($this->is_wpml_active() && !$hasAllLangs && !empty($wpmlLangs)) {
      foreach ($wpmlLangs as $langCode) {
        $langCode = sanitize_key((string)$langCode);
        if ($langCode === '') continue;
        $estimatedPosts += $this->execute_scan_scope_post_count_query($postTypes, $langCode);
      }
    } else {
      $estimatedPosts = $this->execute_scan_scope_post_count_query($postTypes, 'all');
    }

    return [
      'estimated_posts' => max(0, (int)$estimatedPosts),
      'post_types_count' => count($postTypes),
      'authors_count' => count($this->get_enabled_scan_author_ids()),
      'modified_within_days' => $this->get_scan_modified_within_days(),
      'value_types_count' => count($this->get_enabled_scan_value_types()),
      'wpml_langs_count' => $hasAllLangs ? 0 : count(array_filter($wpmlLangs, function($lang) {
        return sanitize_key((string)$lang) !== '' && (string)$lang !== 'all';
      })),
      'wpml_all' => $hasAllLangs ? '1' : '0',
    ];
  }

  private function get_filterable_source_type_options($includeAny = true) {
    $allOptions = $this->get_scan_source_type_options();
    $enabled = $this->get_enabled_scan_source_types();

    $filtered = [];
    foreach ($enabled as $sourceKey) {
      $sourceKey = sanitize_key((string)$sourceKey);
      if ($sourceKey !== '' && isset($allOptions[$sourceKey])) {
        $filtered[$sourceKey] = (string)$allOptions[$sourceKey];
      }
    }

    if (empty($filtered)) {
      $filtered = $allOptions;
    }

    if (!$includeAny) {
      return $filtered;
    }

    return ['any' => 'All'] + $filtered;
  }

  private function sanitize_source_type_filter($rawValue, $allowAny = true) {
    $sourceType = sanitize_key((string)$rawValue);
    if ($allowAny && $sourceType === 'any') {
      return 'any';
    }

    $allowed = $this->get_filterable_source_type_options(false);
    if ($sourceType !== '' && isset($allowed[$sourceType])) {
      return $sourceType;
    }

    return $allowAny ? 'any' : '';
  }

  private function get_default_scan_wpml_langs() {
    return ['all'];
  }

  private function sanitize_scan_wpml_langs($langs) {
    if (!$this->is_wpml_active()) {
      return ['all'];
    }

    $valid = array_keys($this->get_wpml_languages_map());
    $valid[] = 'all';

    $sanitized = [];
    foreach ((array)$langs as $lang) {
      $lang = sanitize_key((string)$lang);
      if ($lang !== '' && in_array($lang, $valid, true)) {
        $sanitized[$lang] = true;
      }
    }

    if (empty($sanitized)) {
      $sanitized['all'] = true;
    }

    return array_keys($sanitized);
  }

  private function get_enabled_scan_wpml_langs() {
    $settings = $this->get_settings();
    $selected = isset($settings['scan_wpml_langs']) && is_array($settings['scan_wpml_langs'])
      ? $settings['scan_wpml_langs']
      : $this->get_default_scan_wpml_langs();

    return $this->sanitize_scan_wpml_langs($selected);
  }

  private function get_effective_scan_wpml_lang($requestedLang) {
    $requestedLang = $this->sanitize_wpml_lang_filter($requestedLang);
    $enabled = $this->get_enabled_scan_wpml_langs();

    if (in_array('all', $enabled, true)) {
      return $requestedLang;
    }

    if ($requestedLang !== 'all' && in_array($requestedLang, $enabled, true)) {
      return $requestedLang;
    }

    return isset($enabled[0]) ? (string)$enabled[0] : 'all';
  }

  private function sanitize_scan_term_ids($termIds, $taxonomy) {
    if (!taxonomy_exists((string)$taxonomy)) {
      return [];
    }

    $clean = [];
    foreach ((array)$termIds as $termId) {
      $termId = (int)$termId;
      if ($termId > 0) {
        $clean[$termId] = true;
      }
    }
    return array_map('intval', array_keys($clean));
  }

  private function get_global_scan_tax_query($postTypes) {
    $postTypes = array_values(array_unique(array_map('sanitize_key', (array)$postTypes)));
    if (!in_array('post', $postTypes, true)) {
      return [];
    }

    $settings = $this->get_settings();
    $categoryIds = $this->sanitize_scan_term_ids(isset($settings['scan_post_category_ids']) ? $settings['scan_post_category_ids'] : [], 'category');
    $tagIds = $this->sanitize_scan_term_ids(isset($settings['scan_post_tag_ids']) ? $settings['scan_post_tag_ids'] : [], 'post_tag');

    if (empty($categoryIds) && empty($tagIds)) {
      return [];
    }

    $taxQuery = ['relation' => 'AND'];
    if (!empty($categoryIds)) {
      $taxQuery[] = [
        'taxonomy' => 'category',
        'field' => 'term_id',
        'terms' => $categoryIds,
      ];
    }
    if (!empty($tagIds)) {
      $taxQuery[] = [
        'taxonomy' => 'post_tag',
        'field' => 'term_id',
        'terms' => $tagIds,
      ];
    }

    return $taxQuery;
  }

  private function normalize_scan_exclude_url_patterns($raw) {
    if (is_array($raw)) {
      $raw = implode("\n", array_map('strval', $raw));
    }

    $lines = preg_split('/\r\n|\r|\n/', (string)$raw);
    if (!is_array($lines)) return [];

    $patterns = [];
    foreach ($lines as $line) {
      $pattern = trim((string)$line);
      if ($pattern === '') continue;
      if (strlen($pattern) > 255) {
        $pattern = substr($pattern, 0, 255);
      }
      $patterns[$pattern] = true;
    }

    return array_keys($patterns);
  }

  private function get_scan_exclude_url_patterns() {
    if (is_array($this->scan_exclude_url_patterns_cache)) {
      return $this->scan_exclude_url_patterns_cache;
    }

    $settings = $this->get_settings();
    $raw = isset($settings['scan_exclude_url_patterns']) ? (string)$settings['scan_exclude_url_patterns'] : '';
    $this->scan_exclude_url_patterns_cache = $this->normalize_scan_exclude_url_patterns($raw);
    return $this->scan_exclude_url_patterns_cache;
  }

  private function get_scan_exclude_url_matchers() {
    if (is_array($this->scan_exclude_matchers_cache)) {
      return $this->scan_exclude_matchers_cache;
    }

    $matchers = [];
    foreach ($this->get_scan_exclude_url_patterns() as $patternRaw) {
      $pattern = strtolower(trim((string)$patternRaw));
      if ($pattern === '') {
        continue;
      }

      if (strpos($pattern, '*') !== false) {
        $matchers[] = [
          'type' => 'wildcard',
          'regex' => '/^' . str_replace('\\*', '.*', preg_quote($pattern, '/')) . '$/i',
        ];
      } else {
        $matchers[] = [
          'type' => 'contains',
          'value' => $pattern,
        ];
      }
    }

    $this->scan_exclude_matchers_cache = $matchers;
    return $matchers;
  }

  private function get_scan_meta_keys_cached() {
    if (is_array($this->scan_meta_keys_cache)) {
      return $this->scan_meta_keys_cache;
    }

    $metaKeys = apply_filters('lm_scan_meta_keys', []);
    $metaKeys = array_values(array_unique(array_filter(array_map('strval', (array)$metaKeys))));
    $this->scan_meta_keys_cache = $metaKeys;
    return $metaKeys;
  }

  private function get_enabled_scan_value_types_map_cached() {
    if (is_array($this->enabled_scan_value_types_map_cache)) {
      return $this->enabled_scan_value_types_map_cache;
    }

    $enabledValueTypesMap = [];
    foreach ($this->get_enabled_scan_value_types() as $enabledType) {
      $enabledValueTypesMap[sanitize_key((string)$enabledType)] = true;
    }

    $this->enabled_scan_value_types_map_cache = $enabledValueTypesMap;
    return $enabledValueTypesMap;
  }

  private function url_matches_scan_exclude_patterns($url, $patterns = null) {
    $url = strtolower(trim((string)$url));
    if ($url === '') return false;

    $matchers = is_array($patterns) ? [] : $this->get_scan_exclude_url_matchers();
    if (is_array($patterns)) {
      foreach ($patterns as $patternRaw) {
        $pattern = strtolower(trim((string)$patternRaw));
        if ($pattern === '') continue;
        if (strpos($pattern, '*') !== false) {
          $matchers[] = [
            'type' => 'wildcard',
            'regex' => '/^' . str_replace('\\*', '.*', preg_quote($pattern, '/')) . '$/i',
          ];
        } else {
          $matchers[] = [
            'type' => 'contains',
            'value' => $pattern,
          ];
        }
      }
    }

    $urlPath = '';
    foreach ($matchers as $matcher) {
      if (!is_array($matcher)) {
        continue;
      }

      $type = isset($matcher['type']) ? (string)$matcher['type'] : '';
      if ($type === 'contains') {
        $value = isset($matcher['value']) ? (string)$matcher['value'] : '';
        if ($value !== '' && strpos($url, $value) !== false) {
          return true;
        }
        continue;
      }

      if ($type === 'wildcard') {
        $regex = isset($matcher['regex']) ? (string)$matcher['regex'] : '';
        if ($regex === '') {
          continue;
        }
        if (preg_match($regex, $url) === 1) {
          return true;
        }

        // Also allow wildcard pattern to match path only.
        if ($urlPath === '') {
          $urlPath = strtolower((string)parse_url($url, PHP_URL_PATH));
        }
        if ($urlPath !== '' && preg_match($regex, $urlPath) === 1) {
          return true;
        }
      }
    }

    return false;
  }

  private function get_max_posts_per_rebuild() {
    $settings = $this->get_settings();
    $maxPosts = isset($settings['max_posts_per_rebuild']) ? (int)$settings['max_posts_per_rebuild'] : 0;
    if ($maxPosts < 0) $maxPosts = 0;
    if ($maxPosts > 50000) $maxPosts = 50000;
    return $maxPosts;
  }

  private function safe_wpml_apply_filters($tag, $default = null, $args = null) {
    try {
      if ($args === null) {
        return apply_filters($tag, $default);
      }
      return apply_filters($tag, $default, $args);
    } catch (Throwable $e) {
      if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('LM WPML filter error [' . (string)$tag . '].');
      }
      return $default;
    }
  }

  private function safe_wpml_switch_language($lang) {
    try {
      do_action('wpml_switch_language', $lang);
      return true;
    } catch (Throwable $e) {
      if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('LM WPML switch language error.');
      }
      return false;
    }
  }

  private function is_wpml_active() {
    if (defined('ICL_SITEPRESS_VERSION')) return true;
    $langs = $this->safe_wpml_apply_filters('wpml_active_languages', null, ['skip_missing' => 0]);
    return is_array($langs) && !empty($langs);
  }

  private function get_wpml_languages_map() {
    $out = [];
    if (!$this->is_wpml_active()) return $out;

    $langs = $this->safe_wpml_apply_filters('wpml_active_languages', null, 'skip_missing=0&orderby=code');
    if (!is_array($langs) || empty($langs)) {
      $langs = $this->safe_wpml_apply_filters('wpml_active_languages', null, ['skip_missing' => 0, 'orderby' => 'code']);
    }
    if ((!is_array($langs) || empty($langs)) && function_exists('icl_get_languages')) {
      try {
        $langs = icl_get_languages('skip_missing=0&orderby=code');
      } catch (Throwable $e) {
        $langs = [];
      }
    }
    if (!is_array($langs)) return $out;

    foreach ($langs as $code => $data) {
      $langCode = sanitize_key((string)$code);
      if ($langCode === '') continue;
      $label = '';
      if (is_array($data)) {
        $label = isset($data['native_name']) ? (string)$data['native_name'] : '';
        if ($label === '' && isset($data['translated_name'])) $label = (string)$data['translated_name'];
      }
      if ($label === '') $label = strtoupper($langCode);
      $out[$langCode] = $label;
    }
    ksort($out);
    return $out;
  }

  private function sanitize_wpml_lang_filter($lang) {
    $lang = sanitize_key((string)$lang);
    if ($lang === '' || $lang === 'all') return 'all';

    $available = $this->get_wpml_languages_map();
    if (empty($available)) return $lang;
    return isset($available[$lang]) ? $lang : 'all';
  }

  private function get_wpml_current_language() {
    if (!$this->is_wpml_active()) return 'all';

    $current = isset($_REQUEST['lang']) ? sanitize_text_field((string)$_REQUEST['lang']) : '';
    if (!is_string($current) || trim($current) === '') {
      $current = $this->safe_wpml_apply_filters('wpml_current_language', null);
    }

    $current = $this->sanitize_wpml_lang_filter($current);
    return $current === '' ? 'all' : $current;
  }

  private function sanitize_text_match_mode($mode) {
    $mode = sanitize_key((string)$mode);
    if (!in_array($mode, ['contains', 'exact', 'regex', 'starts_with', 'ends_with'], true)) {
      $mode = 'contains';
    }
    return $mode;
  }

  private function sanitize_date_ymd($value) {
    $value = trim((string)$value);
    if ($value === '') return '';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) return '';
    $dt = DateTime::createFromFormat('Y-m-d', $value);
    if (!$dt || $dt->format('Y-m-d') !== $value) return '';
    return $value;
  }

  private function get_text_match_modes() {
    return [
      'contains' => 'Contains',
      'exact' => 'Exact match',
      'regex' => 'Regex',
      'starts_with' => 'Starts with',
      'ends_with' => 'Ends with',
    ];
  }

  private function build_regex_pattern($input) {
    $input = trim((string)$input);
    if ($input === '') return '';

    if (array_key_exists($input, $this->regex_pattern_cache)) {
      return (string)$this->regex_pattern_cache[$input];
    }

    if (@preg_match($input, '') !== false) {
      $this->regex_pattern_cache[$input] = $input;
      return $input;
    }

    $escaped = preg_quote($input, '/');
    $pattern = '/' . $escaped . '/i';
    if (@preg_match($pattern, '') === false) {
      $this->regex_pattern_cache[$input] = '';
      return '';
    }
    $this->regex_pattern_cache[$input] = $pattern;
    return $pattern;
  }

  private function text_matches($haystack, $needle, $mode) {
    $needle = (string)$needle;
    if ($needle === '') return true;

    $haystack = (string)$haystack;
    $mode = $this->sanitize_text_match_mode($mode);

    if ($mode === 'exact') {
      return strcasecmp(trim($haystack), trim($needle)) === 0;
    }

    if ($mode === 'regex') {
      $pattern = $this->build_regex_pattern($needle);
      if ($pattern === '') return false;
      return preg_match($pattern, $haystack) === 1;
    }

    if ($mode === 'starts_with') {
      return stripos($haystack, $needle) === 0;
    }

    if ($mode === 'ends_with') {
      $haystackLower = strtolower($haystack);
      $needleLower = strtolower($needle);
      if ($needleLower === '') return true;
      $needleLen = strlen($needleLower);
      if ($needleLen > strlen($haystackLower)) return false;
      return substr($haystackLower, -$needleLen) === $needleLower;
    }

    return stripos($haystack, $needle) !== false;
  }

  private function normalize_new_anchor_input($rawValue, $oldAnchor = null) {
    if ($rawValue === null) {
      return null;
    }

    $clean = sanitize_text_field((string)$rawValue);
    if (trim($clean) === '') {
      return null;
    }

    if ($oldAnchor !== null) {
      $oldClean = sanitize_text_field((string)$oldAnchor);
      if ($clean === $oldClean) {
        return null;
      }
    }

    return $clean;
  }

  private function cache_key($scope_post_type, $wpml_lang = 'all') {
    return 'lm_cache_' . md5((string)$scope_post_type . '|' . (string)$wpml_lang . '|' . get_current_blog_id());
  }

  private function cache_backup_key($scope_post_type, $wpml_lang = 'all') {
    return 'lm_cache_base_' . md5((string)$scope_post_type . '|' . (string)$wpml_lang . '|' . get_current_blog_id());
  }

  private function cache_scan_option_key($scope_post_type, $wpml_lang = 'all') {
    return 'lm_cache_scan_' . md5((string)$scope_post_type . '|' . (string)$wpml_lang . '|' . get_current_blog_id());
  }

  private function purge_oversized_transient($transientKey, $maxBytes = 67108864) {
    global $wpdb;

    $transientKey = trim((string)$transientKey);
    if ($transientKey === '') return;

    $optionName = '_transient_' . $transientKey;
    $size = (int)$wpdb->get_var(
      $wpdb->prepare(
        "SELECT OCTET_LENGTH(option_value) FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
        $optionName
      )
    );

    if ($size > (int)$maxBytes) {
      delete_transient($transientKey);
      if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('LM oversized transient purged: ' . $transientKey);
      }
    }
  }

  private function persist_cache_payload($scope_post_type, $wpml_lang, $rows) {
    $maxRows = $this->get_runtime_max_cache_rows();
    if (is_array($rows) && count($rows) > $maxRows) {
      $rows = array_slice($rows, 0, $maxRows);
    }
    set_transient($this->cache_key($scope_post_type, $wpml_lang), $rows, self::CACHE_TTL);
    set_transient($this->cache_backup_key($scope_post_type, $wpml_lang), $rows, self::CACHE_BASE_TTL);
    update_option($this->cache_scan_option_key($scope_post_type, $wpml_lang), gmdate('Y-m-d H:i:s'), false);
  }

  private function append_rows(&$dest, $rows) {
    foreach ((array)$rows as $row) {
      $dest[] = $row;
    }
  }

  private function compact_rows_for_pages_link(&$rows) {
    if (!is_array($rows) || empty($rows)) {
      return;
    }

    foreach ($rows as &$row) {
      $row = [
        'post_id' => (string)($row['post_id'] ?? ''),
        'page_url' => (string)($row['page_url'] ?? ''),
        'source' => (string)($row['source'] ?? ''),
        'link_type' => (string)($row['link_type'] ?? ''),
        'link' => (string)($row['link'] ?? ''),
        'link_location' => (string)($row['link_location'] ?? ''),
        'rel_nofollow' => (string)($row['rel_nofollow'] ?? '0'),
        'rel_sponsored' => (string)($row['rel_sponsored'] ?? '0'),
        'rel_ugc' => (string)($row['rel_ugc'] ?? '0'),
      ];
    }
    unset($row);
  }

  private function build_pages_link_target_variants($url) {
    $url = trim((string)$url);
    if ($url === '') {
      return [];
    }

    $base = $this->normalize_for_compare($url);
    if ($base === '') {
      return [];
    }

    $variants = [$base => true];
    $noHash = preg_replace('/#.*$/', '', $base);
    if (is_string($noHash) && $noHash !== '' && $noHash !== $base) {
      $variants[$noHash] = true;
    }

    $noQuery = preg_replace('/\?.*$/', '', (string)$noHash);
    if (is_string($noQuery) && $noQuery !== '' && !isset($variants[$noQuery])) {
      $variants[$noQuery] = true;
    }

    $noTrail = untrailingslashit((string)$noQuery);
    if ($noTrail !== '' && !isset($variants[$noTrail])) {
      $variants[$noTrail] = true;
    }

    $parts = wp_parse_url($base);
    if (is_array($parts) && isset($parts['path'])) {
      $path = (string)$parts['path'];
      if ($path !== '') {
        $pathNorm = $this->normalize_for_compare($path);
        if ($pathNorm !== '' && !isset($variants[$pathNorm])) {
          $variants[$pathNorm] = true;
        }
        $pathNoTrail = untrailingslashit($pathNorm);
        if ($pathNoTrail !== '' && !isset($variants[$pathNoTrail])) {
          $variants[$pathNoTrail] = true;
        }
      }
    }

    return array_keys($variants);
  }

  private function resolve_target_post_id_for_pages_link($targetNorm, $allowedPostIdsMap, &$resolutionCache, $allowedTargetMap = [], &$fallbackState = null, $hydrateTargetMapCb = null) {
    $targetNorm = (string)$targetNorm;
    if ($targetNorm === '') {
      return '';
    }

    if (isset($resolutionCache[$targetNorm])) {
      return (string)$resolutionCache[$targetNorm];
    }

    if (is_array($allowedTargetMap)) {
      $variants = $this->build_pages_link_target_variants($targetNorm);
      foreach ($variants as $variant) {
        if (isset($allowedTargetMap[$variant])) {
          $targetPid = (string)$allowedTargetMap[$variant];
          $resolutionCache[$targetNorm] = $targetPid;
          return $targetPid;
        }
      }

      if (is_callable($hydrateTargetMapCb)) {
        call_user_func($hydrateTargetMapCb);
        foreach ($variants as $variant) {
          if (isset($allowedTargetMap[$variant])) {
            $targetPid = (string)$allowedTargetMap[$variant];
            $resolutionCache[$targetNorm] = $targetPid;
            return $targetPid;
          }
        }
      }
    }

    if (is_array($fallbackState)) {
      $maxFallback = isset($fallbackState['max']) ? (int)$fallbackState['max'] : 120;
      $usedFallback = isset($fallbackState['used']) ? (int)$fallbackState['used'] : 0;
      if ($maxFallback >= 0 && $usedFallback >= $maxFallback) {
        $resolutionCache[$targetNorm] = '';
        return '';
      }
      $fallbackState['used'] = $usedFallback + 1;
    }

    $targetPid = (int)url_to_postid($targetNorm);
    if ($targetPid < 1) {
      $alt = untrailingslashit($targetNorm);
      if ($alt !== $targetNorm && $alt !== '') {
        $targetPid = (int)url_to_postid($alt);
      }
    }

    if ($targetPid > 0 && isset($allowedPostIdsMap[(string)$targetPid])) {
      $resolutionCache[$targetNorm] = (string)$targetPid;
      return (string)$targetPid;
    }

    $resolutionCache[$targetNorm] = '';
    return '';
  }

  private function parse_php_bytes($value) {
    $value = trim((string)$value);
    if ($value === '') return 0;

    $last = strtolower(substr($value, -1));
    $num = (float)$value;
    if (!is_finite($num) || $num <= 0) return 0;

    if ($last === 'g') return (int)($num * 1024 * 1024 * 1024);
    if ($last === 'm') return (int)($num * 1024 * 1024);
    if ($last === 'k') return (int)($num * 1024);
    return (int)$num;
  }

  private function get_effective_memory_limit_bytes() {
    $bytes = $this->parse_php_bytes(ini_get('memory_limit'));
    if ($bytes <= 0) {
      return 268435456; // fallback 256 MB when unlimited/unknown
    }
    return $bytes;
  }

  private function get_runtime_max_cache_rows() {
    $limit = $this->get_effective_memory_limit_bytes();
    if ($limit <= 268435456) return 5000;   // <= 256 MB
    if ($limit <= 402653184) return 8000;   // <= 384 MB
    if ($limit <= 536870912) return 12000;  // <= 512 MB
    return self::MAX_CACHE_ROWS;
  }

  private function get_runtime_max_crawl_batch() {
    $limit = $this->get_effective_memory_limit_bytes();
    if ($limit <= 268435456) return 40;    // <= 256 MB
    if ($limit <= 402653184) return 60;    // <= 384 MB
    if ($limit <= 536870912) return 100;   // <= 512 MB
    return 200;
  }

  private function get_safe_transient_limit_bytes($isBackup = false) {
    $limit = $this->get_effective_memory_limit_bytes();
    $ratio = $isBackup ? 0.2 : 0.12;
    $cap = (int)floor($limit * $ratio);

    $min = $isBackup ? 33554432 : 16777216; // 32MB / 16MB
    $max = $isBackup ? 134217728 : 67108864; // 128MB / 64MB
    if ($cap < $min) $cap = $min;
    if ($cap > $max) $cap = $max;

    return $cap;
  }

  private function get_crawl_time_budget_seconds() {
    $maxExecution = (int)ini_get('max_execution_time');
    if ($maxExecution <= 0) {
      return 20;
    }

    $budget = $maxExecution - 5;
    if ($budget < 5) $budget = 5;
    if ($budget > 20) $budget = 20;
    return $budget;
  }

  private function should_abort_crawl($startedAt) {
    $elapsed = microtime(true) - (float)$startedAt;
    if ($elapsed >= $this->get_crawl_time_budget_seconds()) {
      return true;
    }

    $memoryLimit = $this->parse_php_bytes(ini_get('memory_limit'));
    if ($memoryLimit > 0) {
      $used = memory_get_usage(true);
      if ($used >= (int)($memoryLimit * 0.9)) {
        return true;
      }
    }

    return false;
  }

  private function rebuild_job_state_option_key() {
    return 'lm_rebuild_job_state_' . get_current_blog_id();
  }

  private function rebuild_job_partial_rows_key($scopePostType, $wpmlLang) {
    return 'lm_rebuild_partial_' . md5((string)$scopePostType . '|' . (string)$wpmlLang . '|' . get_current_blog_id());
  }

  private function get_rebuild_job_state() {
    $state = get_option($this->rebuild_job_state_option_key(), []);
    return is_array($state) ? $state : [];
  }

  private function save_rebuild_job_state($state) {
    if (!is_array($state)) {
      $state = [];
    }
    update_option($this->rebuild_job_state_option_key(), $state, false);
  }

  private function clear_rebuild_job_state() {
    $state = $this->get_rebuild_job_state();
    if (!empty($state['scope_post_type']) && !empty($state['wpml_lang'])) {
      delete_transient($this->rebuild_job_partial_rows_key((string)$state['scope_post_type'], (string)$state['wpml_lang']));
    }
    delete_option($this->rebuild_job_state_option_key());
  }

  public function rest_can_manage_links_manager($request = null) {
    return $this->current_user_can_access_plugin();
  }

  public function register_rest_routes() {
    register_rest_route('links-manager/v1', '/rebuild/start', [
      'methods' => 'POST',
      'callback' => [$this, 'rest_rebuild_start'],
      'permission_callback' => [$this, 'rest_can_manage_links_manager'],
    ]);

    register_rest_route('links-manager/v1', '/rebuild/status', [
      'methods' => 'GET',
      'callback' => [$this, 'rest_rebuild_status'],
      'permission_callback' => [$this, 'rest_can_manage_links_manager'],
    ]);

    register_rest_route('links-manager/v1', '/rebuild/step', [
      'methods' => 'POST',
      'callback' => [$this, 'rest_rebuild_step'],
      'permission_callback' => [$this, 'rest_can_manage_links_manager'],
    ]);
  }

  public function rest_rebuild_start($request) {
    $scopePostType = sanitize_key((string)$request->get_param('post_type'));
    if ($scopePostType === '') $scopePostType = 'any';

    $wpmlLang = sanitize_key((string)$request->get_param('wpml_lang'));
    if ($wpmlLang === '') $wpmlLang = 'all';
    $wpmlLang = $this->get_effective_scan_wpml_lang($wpmlLang);

    $enabledPostTypes = $this->get_enabled_scan_post_types();
    $postTypes = ($scopePostType === 'any')
      ? $enabledPostTypes
      : (in_array($scopePostType, $enabledPostTypes, true) ? [$scopePostType] : []);

    $job = [
      'status' => 'running',
      'scope_post_type' => $scopePostType,
      'wpml_lang' => $wpmlLang,
      'post_types' => $postTypes,
      'post_ids' => [],
      'offset' => 0,
      'total_posts' => 0,
      'processed_posts' => 0,
      'rows_count' => 0,
      'started_at' => current_time('mysql'),
      'updated_at' => current_time('mysql'),
      'last_error' => '',
    ];

    if (empty($postTypes)) {
      $rows = $this->crawl_menus($this->get_enabled_scan_source_types());
      $this->persist_cache_payload($scopePostType, $wpmlLang, $rows);
      $job['status'] = 'done';
      $job['rows_count'] = count((array)$rows);
      $this->save_rebuild_job_state($job);
      return rest_ensure_response($job);
    }

    $postIds = $this->query_cache_post_ids($postTypes, $wpmlLang, '');
    $job['post_ids'] = array_values(array_map('intval', (array)$postIds));
    $job['total_posts'] = count($job['post_ids']);

    set_transient($this->rebuild_job_partial_rows_key($scopePostType, $wpmlLang), [], self::CACHE_TTL);
    $this->save_rebuild_job_state($job);

    return rest_ensure_response($job);
  }

  public function rest_rebuild_status($request) {
    $state = $this->get_rebuild_job_state();
    if (empty($state)) {
      return rest_ensure_response([
        'status' => 'idle',
      ]);
    }
    return rest_ensure_response($state);
  }

  public function rest_rebuild_step($request) {
    $state = $this->get_rebuild_job_state();
    if (empty($state) || (string)($state['status'] ?? '') !== 'running') {
      return rest_ensure_response([
        'status' => 'idle',
        'message' => 'No running rebuild job.',
      ]);
    }

    $scopePostType = sanitize_key((string)($state['scope_post_type'] ?? 'any'));
    $wpmlLang = $this->get_effective_scan_wpml_lang((string)($state['wpml_lang'] ?? 'all'));
    $postIds = array_values(array_map('intval', (array)($state['post_ids'] ?? [])));
    $offset = max(0, (int)($state['offset'] ?? 0));
    $totalPosts = count($postIds);
    $batch = (int)$request->get_param('batch');
    if ($batch < 1) {
      $batch = $this->get_crawl_post_batch_size();
    }
    if ($batch > $this->get_runtime_max_crawl_batch()) {
      $batch = $this->get_runtime_max_crawl_batch();
    }

    $partialKey = $this->rebuild_job_partial_rows_key($scopePostType, $wpmlLang);
    $allRows = get_transient($partialKey);
    if (!is_array($allRows)) {
      $allRows = [];
    }

    $enabledSources = $this->get_enabled_scan_source_types();
    $maxRows = $this->get_runtime_max_cache_rows();
    $maxPosts = $this->get_max_posts_per_rebuild();
    $processedPosts = (int)($state['processed_posts'] ?? 0);
    $crawlStartedAt = microtime(true);

    $wpmlWasSwitched = false;
    $wpmlPreviousLang = '';
    try {
      if ($this->is_wpml_active()) {
        $prev = $this->safe_wpml_apply_filters('wpml_current_language', null);
        if (is_string($prev)) {
          $wpmlPreviousLang = sanitize_key($prev);
        }
        $switchLang = ($wpmlLang === 'all') ? 'all' : $wpmlLang;
        $wpmlWasSwitched = $this->safe_wpml_switch_language($switchLang);
      }

      $end = min($offset + $batch, $totalPosts);

      for ($i = $offset; $i < $end; $i++) {
        if ($maxPosts > 0 && $processedPosts >= $maxPosts) {
          break;
        }

        $postId = isset($postIds[$i]) ? (int)$postIds[$i] : 0;
        if ($postId < 1) continue;

        $this->append_rows($allRows, $this->crawl_post($postId, $enabledSources));
        $processedPosts++;

        if (count($allRows) >= $maxRows) {
          $allRows = array_slice($allRows, 0, $maxRows);
          break;
        }
        if ($this->should_abort_crawl($crawlStartedAt)) {
          break;
        }
      }
    } catch (Throwable $e) {
      $state['status'] = 'error';
      $state['last_error'] = sanitize_text_field($e->getMessage());
      $state['updated_at'] = current_time('mysql');
      $this->save_rebuild_job_state($state);
      return rest_ensure_response($state);
    } finally {
      if ($wpmlWasSwitched) {
        if ($wpmlPreviousLang !== '') {
          $this->safe_wpml_switch_language($wpmlPreviousLang);
        } else {
          $this->safe_wpml_switch_language(null);
        }
      }
    }

    $newOffset = min($offset + $batch, $totalPosts);
    $done = ($newOffset >= $totalPosts) || ($maxPosts > 0 && $processedPosts >= $maxPosts) || (count($allRows) >= $maxRows);

    if ($done) {
      $this->append_rows($allRows, $this->crawl_menus($enabledSources));
      $this->persist_cache_payload($scopePostType, $wpmlLang, $allRows);
      if ($this->is_wpml_active()) {
        update_option('lm_last_wpml_lang_context', (string)$wpmlLang, false);
      }
      delete_transient($partialKey);
      $state['status'] = 'done';
    } else {
      set_transient($partialKey, $allRows, self::CACHE_TTL);
      $state['status'] = 'running';
    }

    $state['offset'] = $newOffset;
    $state['processed_posts'] = $processedPosts;
    $state['rows_count'] = count((array)$allRows);
    $state['updated_at'] = current_time('mysql');
    $state['batch_size'] = $batch;
    $this->save_rebuild_job_state($state);

    return rest_ensure_response($state);
  }

  private function query_cache_post_ids($post_types, $wpml_lang = 'all', $modified_after_gmt = '') {
    $post_types = array_values(array_unique(array_map('sanitize_key', (array)$post_types)));
    $scanAuthorIds = $this->get_enabled_scan_author_ids();
    $effectiveAfterGmt = $this->get_scan_modified_after_gmt($modified_after_gmt);

    $queryArgs = [
      'post_type' => $post_types,
      'post_status' => 'publish',
      'posts_per_page' => -1,
      'fields' => 'ids',
      'suppress_filters' => false,
      'no_found_rows' => true,
      'orderby' => 'ID',
      'order' => 'ASC',
      'update_post_meta_cache' => false,
      'update_post_term_cache' => false,
      'cache_results' => false,
    ];

    if (!empty($scanAuthorIds)) {
      $queryArgs['author__in'] = array_values(array_map('intval', $scanAuthorIds));
    }

    if ($this->is_wpml_active()) {
      $queryArgs['lang'] = ($wpml_lang === 'all') ? '' : $wpml_lang;
    }

    if ($effectiveAfterGmt !== '') {
      $queryArgs['date_query'] = [[
        'column' => 'post_modified_gmt',
        'after' => $effectiveAfterGmt,
        'inclusive' => false,
      ]];
    }

    $globalTaxQuery = $this->get_global_scan_tax_query($post_types);
    if (!empty($globalTaxQuery)) {
      $queryArgs['tax_query'] = $globalTaxQuery;
    }

    $q = new WP_Query($queryArgs);
    if (empty($q->posts)) return [];

    return array_values(array_unique(array_map('intval', (array)$q->posts)));
  }

  private function get_pages_link_post_data_map($candidatePostIds, $postTypes, $needAllPageUrls, $candidatePageUrlMap = []) {
    $candidatePostIds = array_values(array_unique(array_filter(array_map('intval', (array)$candidatePostIds), function($id) {
      return $id > 0;
    })));
    if (empty($candidatePostIds)) {
      return [];
    }

    $postTypes = array_values(array_unique(array_filter(array_map('sanitize_key', (array)$postTypes), function($pt) {
      return $pt !== '';
    })));
    if (empty($postTypes)) {
      return [];
    }

    global $wpdb;
    $idPlaceholders = implode(',', array_fill(0, count($candidatePostIds), '%d'));
    $typePlaceholders = implode(',', array_fill(0, count($postTypes), '%s'));
    $sql = "SELECT ID, post_title, post_type, post_author, post_date, post_modified\n"
      . "FROM {$wpdb->posts}\n"
      . "WHERE ID IN ($idPlaceholders)\n"
      . "  AND post_status = 'publish'\n"
      . "  AND post_type IN ($typePlaceholders)";

    $queryParams = array_merge($candidatePostIds, $postTypes);
    $postRows = $wpdb->get_results($wpdb->prepare($sql, $queryParams));
    if (empty($postRows)) {
      return [];
    }

    $authorIds = [];
    foreach ($postRows as $postRow) {
      $authorId = isset($postRow->post_author) ? (int)$postRow->post_author : 0;
      if ($authorId > 0) {
        $authorIds[$authorId] = true;
      }
    }

    $authorMap = [];
    if (!empty($authorIds)) {
      $authorIdList = array_keys($authorIds);
      $authorPlaceholders = implode(',', array_fill(0, count($authorIdList), '%d'));
      $authorSql = "SELECT ID, display_name FROM {$wpdb->users} WHERE ID IN ($authorPlaceholders)";
      $authorRows = $wpdb->get_results($wpdb->prepare($authorSql, $authorIdList));
      foreach ((array)$authorRows as $authorRow) {
        $authorMap[(int)$authorRow->ID] = (string)$authorRow->display_name;
      }
    }

    $postDataMap = [];
    foreach ($postRows as $postRow) {
      $pid = isset($postRow->ID) ? (int)$postRow->ID : 0;
      if ($pid < 1) {
        continue;
      }
      $pidKey = (string)$pid;
      $authorId = isset($postRow->post_author) ? (int)$postRow->post_author : 0;

      $pageUrl = isset($candidatePageUrlMap[$pidKey]) ? (string)$candidatePageUrlMap[$pidKey] : '';
      if ($needAllPageUrls && $pageUrl === '') {
        $pageUrl = (string)get_permalink($pid);
      }

      $postDataMap[$pidKey] = [
        'post_title' => isset($postRow->post_title) ? (string)$postRow->post_title : '',
        'post_type' => isset($postRow->post_type) ? (string)$postRow->post_type : '',
        'author_name' => isset($authorMap[$authorId]) ? (string)$authorMap[$authorId] : '',
        'post_date' => isset($postRow->post_date) ? (string)$postRow->post_date : '',
        'post_modified' => isset($postRow->post_modified) ? (string)$postRow->post_modified : '',
        'page_url' => $pageUrl,
      ];
    }

    return $postDataMap;
  }

  private function get_post_modified_gmt_map($postIds) {
    $postIds = array_values(array_unique(array_filter(array_map('intval', (array)$postIds), function($id) {
      return $id > 0;
    })));
    if (empty($postIds)) {
      return [];
    }

    global $wpdb;
    $placeholders = implode(',', array_fill(0, count($postIds), '%d'));
    $sql = "SELECT ID, post_modified_gmt FROM {$wpdb->posts} WHERE ID IN ($placeholders)";
    $rows = $wpdb->get_results($wpdb->prepare($sql, $postIds));
    if (empty($rows)) {
      return [];
    }

    $out = [];
    foreach ((array)$rows as $row) {
      $id = isset($row->ID) ? (int)$row->ID : 0;
      if ($id < 1) {
        continue;
      }
      $out[$id] = isset($row->post_modified_gmt) ? (string)$row->post_modified_gmt : '';
    }

    return $out;
  }

  private function get_cache_rebuild_post_data_map($postIds) {
    $postIds = array_values(array_unique(array_filter(array_map('intval', (array)$postIds), function($id) {
      return $id > 0;
    })));
    if (empty($postIds)) {
      return [];
    }

    global $wpdb;
    $placeholders = implode(',', array_fill(0, count($postIds), '%d'));
    $sql = "SELECT ID, post_status, post_content, post_excerpt, post_title, post_type, post_date, post_modified, post_author\n"
      . "FROM {$wpdb->posts}\n"
      . "WHERE ID IN ($placeholders)";
    $rows = $wpdb->get_results($wpdb->prepare($sql, $postIds));
    if (empty($rows)) {
      return [];
    }

    $out = [];
    foreach ((array)$rows as $row) {
      $id = isset($row->ID) ? (int)$row->ID : 0;
      if ($id < 1) {
        continue;
      }
      $out[$id] = $row;
    }

    return $out;
  }

  private function build_incremental_cache_from_backup($backupRows, $post_types, $wpml_lang, $last_scan_gmt, $enabledSources = null) {
    if (!is_array($backupRows) || $last_scan_gmt === '') {
      return null;
    }

    if (!is_array($enabledSources)) {
      $enabledSources = $this->get_enabled_scan_source_types();
    }

    $enabledSourcesMap = [];
    foreach ((array)$enabledSources as $src) {
      $enabledSourcesMap[sanitize_key((string)$src)] = true;
    }

    $rowsByPostId = [];
    foreach ($backupRows as $row) {
      if ((string)($row['source'] ?? '') === 'menu') continue;
      $rowSource = sanitize_key((string)($row['source'] ?? ''));
      if ($rowSource !== '' && !isset($enabledSourcesMap[$rowSource])) continue;
      $postId = (int)($row['post_id'] ?? 0);
      if ($postId < 1) continue;
      if (!isset($rowsByPostId[$postId])) $rowsByPostId[$postId] = [];
      $rowsByPostId[$postId][] = $row;
    }

    $currentPostIds = $this->query_cache_post_ids($post_types, $wpml_lang, '');
    $changedMap = [];
    if (!empty($currentPostIds)) {
      $modifiedMap = $this->get_post_modified_gmt_map($currentPostIds);
      foreach ($currentPostIds as $pid) {
        $pid = (int)$pid;
        if ($pid < 1) {
          continue;
        }
        $modifiedGmt = isset($modifiedMap[$pid]) ? (string)$modifiedMap[$pid] : '';
        if ($modifiedGmt === '' || $modifiedGmt > $last_scan_gmt) {
          $changedMap[$pid] = true;
        }
      }
    }

    $all = [];
    $maxRows = $this->get_runtime_max_cache_rows();
    $maxPosts = $this->get_max_posts_per_rebuild();
    $processedPosts = 0;
    foreach ($currentPostIds as $postId) {
      if ($maxPosts > 0 && $processedPosts >= $maxPosts) {
        break;
      }

      $postId = (int)$postId;
      if ($postId < 1) continue;

      if (isset($changedMap[$postId]) || !isset($rowsByPostId[$postId])) {
        $this->append_rows($all, $this->crawl_post($postId, $enabledSources));
      } else {
        $this->append_rows($all, $rowsByPostId[$postId]);
      }

      if (count($all) >= $maxRows) {
        $all = array_slice($all, 0, $maxRows);
        break;
      }

      $processedPosts++;
    }

    $this->append_rows($all, $this->crawl_menus($enabledSources));
    return $all;
  }

  private function get_stats_snapshot_ttl() {
    $settings = $this->get_settings();
    $minutes = isset($settings['stats_snapshot_ttl_min']) ? (int)$settings['stats_snapshot_ttl_min'] : 15;
    if ($minutes < 1) $minutes = 1;
    if ($minutes > 525600) $minutes = 525600;
    return $minutes * MINUTE_IN_SECONDS;
  }

  private function get_stats_refresh_period_minutes_map() {
    return [
      'hour' => 60,
      'day' => 1440,
      'week' => 10080,
      'month' => 43200,
    ];
  }

  private function sanitize_stats_refresh_period($period) {
    $period = sanitize_key((string)$period);
    $map = $this->get_stats_refresh_period_minutes_map();
    return isset($map[$period]) ? $period : 'hour';
  }

  private function get_stats_refresh_period_from_minutes($minutes) {
    $minutes = (int)$minutes;
    if ($minutes <= 60) return 'hour';
    if ($minutes <= 1440) return 'day';
    if ($minutes <= 10080) return 'week';
    return 'month';
  }

  private function sanitize_stats_refresh_value($value) {
    $value = (int)$value;
    if ($value < 1) $value = 1;
    if ($value > 12) $value = 12;
    return $value;
  }

  private function get_stats_refresh_value_and_period_from_minutes($minutes) {
    $minutes = (int)$minutes;
    if ($minutes < 1) $minutes = 1;
    if ($minutes > 525600) $minutes = 525600;

    $map = $this->get_stats_refresh_period_minutes_map();

    // Prefer larger units when value is a clean integer and within UI range.
    foreach (['month', 'week', 'day', 'hour'] as $period) {
      $unit = (int)$map[$period];
      if ($unit < 1) continue;
      if ($minutes % $unit !== 0) continue;
      $value = (int)($minutes / $unit);
      if ($value >= 1 && $value <= 12) {
        return ['value' => $value, 'period' => $period];
      }
    }

    // Fallback for legacy minute values that do not align with unit boundaries.
    $hourValue = (int)round($minutes / 60);
    $hourValue = $this->sanitize_stats_refresh_value($hourValue);
    return ['value' => $hourValue, 'period' => 'hour'];
  }

  private function is_incremental_rebuild_enabled() {
    $settings = $this->get_settings();
    $mode = isset($settings['cache_rebuild_mode']) ? sanitize_key((string)$settings['cache_rebuild_mode']) : 'incremental';
    return $mode !== 'full';
  }

  private function get_crawl_post_batch_size() {
    $settings = $this->get_settings();
    $batch = isset($settings['crawl_post_batch']) ? (int)$settings['crawl_post_batch'] : self::CRAWL_POST_BATCH;
    if ($batch < 20) $batch = 20;
    $runtimeMax = $this->get_runtime_max_crawl_batch();
    if ($batch > $runtimeMax) $batch = $runtimeMax;
    return $batch;
  }

  private function get_recommended_performance_settings() {
    $recommendedBatch = min(self::CRAWL_POST_BATCH, $this->get_runtime_max_crawl_batch());
    return [
      'stats_snapshot_ttl_min' => '360',
      'cache_rebuild_mode' => 'incremental',
      'crawl_post_batch' => (string)$recommendedBatch,
      'scan_source_types' => ['content', 'excerpt', 'menu'],
      'scan_value_types' => $this->get_default_scan_value_types(),
      'scan_wpml_langs' => ['all'],
      'scan_post_category_ids' => [],
      'scan_post_tag_ids' => [],
      'scan_author_ids' => [],
      'scan_modified_within_days' => '0',
      'scan_exclude_url_patterns' => '',
      'max_posts_per_rebuild' => '0',
    ];
  }

  private function get_low_memory_performance_settings() {
    $safeBatch = min(20, $this->get_runtime_max_crawl_batch());
    if ($safeBatch < 20) $safeBatch = $this->get_runtime_max_crawl_batch();
    if ($safeBatch < 1) $safeBatch = 1;

    return [
      'stats_snapshot_ttl_min' => '1440',
      'cache_rebuild_mode' => 'incremental',
      'crawl_post_batch' => (string)$safeBatch,
      'scan_source_types' => ['content'],
      'scan_value_types' => $this->get_default_scan_value_types(),
      'scan_wpml_langs' => ['all'],
      'scan_post_category_ids' => [],
      'scan_post_tag_ids' => [],
      'scan_author_ids' => [],
      'scan_modified_within_days' => '0',
      'scan_exclude_url_patterns' => '',
      'max_posts_per_rebuild' => '500',
    ];
  }

  private function sanitize_inbound_status_thresholds($orphanMax, $lowMax, $standardMax) {
    $orphanMax = (int)$orphanMax;
    $lowMax = (int)$lowMax;
    $standardMax = (int)$standardMax;

    if ($orphanMax < 0) $orphanMax = 0;
    if ($orphanMax > 1000000) $orphanMax = 1000000;

    if ($lowMax < $orphanMax) $lowMax = $orphanMax;
    if ($lowMax > 1000000) $lowMax = 1000000;

    if ($standardMax < $lowMax) $standardMax = $lowMax;
    if ($standardMax > 1000000) $standardMax = 1000000;

    return [
      'orphan_max' => $orphanMax,
      'low_max' => $lowMax,
      'standard_max' => $standardMax,
    ];
  }

  private function get_inbound_status_thresholds() {
    $settings = $this->get_settings();
    $orphanMax = isset($settings['inbound_orphan_max']) ? (int)$settings['inbound_orphan_max'] : 0;
    $lowMax = isset($settings['inbound_low_max']) ? (int)$settings['inbound_low_max'] : 5;
    $standardMax = isset($settings['inbound_standard_max']) ? (int)$settings['inbound_standard_max'] : 10;
    return $this->sanitize_inbound_status_thresholds($orphanMax, $lowMax, $standardMax);
  }

  private function sanitize_four_level_status_thresholds($noneMax, $lowMax, $optimalMax) {
    $noneMax = (int)$noneMax;
    $lowMax = (int)$lowMax;
    $optimalMax = (int)$optimalMax;

    if ($noneMax < 0) $noneMax = 0;
    if ($noneMax > 1000000) $noneMax = 1000000;

    if ($lowMax < $noneMax) $lowMax = $noneMax;
    if ($lowMax > 1000000) $lowMax = 1000000;

    if ($optimalMax < $lowMax) $optimalMax = $lowMax;
    if ($optimalMax > 1000000) $optimalMax = 1000000;

    return [
      'none_max' => $noneMax,
      'low_max' => $lowMax,
      'optimal_max' => $optimalMax,
    ];
  }

  private function get_internal_outbound_status_thresholds() {
    $settings = $this->get_settings();
    $noneMax = isset($settings['internal_outbound_none_max']) ? (int)$settings['internal_outbound_none_max'] : 0;
    $lowMax = isset($settings['internal_outbound_low_max']) ? (int)$settings['internal_outbound_low_max'] : 5;
    $optimalMax = isset($settings['internal_outbound_optimal_max']) ? (int)$settings['internal_outbound_optimal_max'] : 10;
    return $this->sanitize_four_level_status_thresholds($noneMax, $lowMax, $optimalMax);
  }

  private function get_external_outbound_status_thresholds() {
    $settings = $this->get_settings();
    $noneMax = isset($settings['external_outbound_none_max']) ? (int)$settings['external_outbound_none_max'] : 0;
    $lowMax = isset($settings['external_outbound_low_max']) ? (int)$settings['external_outbound_low_max'] : 5;
    $optimalMax = isset($settings['external_outbound_optimal_max']) ? (int)$settings['external_outbound_optimal_max'] : 10;
    return $this->sanitize_four_level_status_thresholds($noneMax, $lowMax, $optimalMax);
  }

  private function four_level_status_key($count, $thresholds) {
    $count = (int)$count;
    if ($count <= (int)$thresholds['none_max']) return 'none';
    if ($count <= (int)$thresholds['low_max']) return 'low';
    if ($count <= (int)$thresholds['optimal_max']) return 'optimal';
    return 'excessive';
  }

  private function four_level_status_label($key) {
    switch ((string)$key) {
      case 'none':
        return 'None';
      case 'low':
        return 'Low';
      case 'optimal':
        return 'Optimal';
      case 'excessive':
        return 'Excessive';
      default:
        return '—';
    }
  }

  private function get_four_level_status_ranges_text($thresholds) {
    $noneMax = (int)$thresholds['none_max'];
    $lowMax = (int)$thresholds['low_max'];
    $optimalMax = (int)$thresholds['optimal_max'];

    $lowMin = $noneMax + 1;
    $optimalMin = $lowMax + 1;
    $excessiveMin = $optimalMax + 1;

    $noneLabel = ($noneMax === 0) ? '0' : ('0-' . $noneMax);
    $lowLabel = ($lowMin <= $lowMax) ? ($lowMin . '-' . $lowMax) : (string)$lowMax;
    $optimalLabel = ($optimalMin <= $optimalMax) ? ($optimalMin . '-' . $optimalMax) : (string)$optimalMax;
    $excessiveLabel = $excessiveMin . '+';

    return [
      'none' => $noneLabel,
      'low' => $lowLabel,
      'optimal' => $optimalLabel,
      'excessive' => $excessiveLabel,
    ];
  }

  private function get_settings() {
    $availablePostTypes = $this->get_available_post_types();

    $defaults = [
      'stats_snapshot_ttl_min' => (string)(int)(self::STATS_SNAPSHOT_TTL / MINUTE_IN_SECONDS),
      'cache_rebuild_mode' => 'incremental',
      'crawl_post_batch' => (string)self::CRAWL_POST_BATCH,
      'scan_post_types' => $this->get_default_scan_post_types($availablePostTypes),
      'scan_source_types' => $this->get_default_scan_source_types(),
      'scan_value_types' => $this->get_default_scan_value_types(),
      'scan_wpml_langs' => $this->get_default_scan_wpml_langs(),
      'scan_post_category_ids' => [],
      'scan_post_tag_ids' => [],
      'scan_author_ids' => [],
      'scan_modified_within_days' => '0',
      'scan_exclude_url_patterns' => '',
      'max_posts_per_rebuild' => '0',
      'inbound_orphan_max' => '0',
      'inbound_low_max' => '5',
      'inbound_standard_max' => '10',
      'internal_outbound_none_max' => '0',
      'internal_outbound_low_max' => '5',
      'internal_outbound_optimal_max' => '10',
      'external_outbound_none_max' => '0',
      'external_outbound_low_max' => '5',
      'external_outbound_optimal_max' => '10',
      'audit_retention_days' => (string)self::AUDIT_RETENTION_DAYS,
      'weak_anchor_patterns' => implode("\n", $this->get_default_weak_anchor_patterns()),
      'allowed_roles' => ['administrator'],
    ];
    $opt = get_option('lm_settings', []);
    if (!is_array($opt)) $opt = [];

    if (!isset($opt['allowed_roles']) || !is_array($opt['allowed_roles'])) {
      $opt['allowed_roles'] = ['administrator'];
    }

    if (!isset($opt['scan_post_types']) || !is_array($opt['scan_post_types'])) {
      $opt['scan_post_types'] = $this->get_default_scan_post_types($availablePostTypes);
    }
    $opt['scan_post_types'] = $this->sanitize_scan_post_types($opt['scan_post_types'], $availablePostTypes);

    if (!isset($opt['scan_source_types']) || !is_array($opt['scan_source_types'])) {
      $opt['scan_source_types'] = $this->get_default_scan_source_types();
    }
    $opt['scan_source_types'] = $this->sanitize_scan_source_types($opt['scan_source_types']);

    if (!isset($opt['scan_value_types']) || !is_array($opt['scan_value_types'])) {
      $opt['scan_value_types'] = $this->get_default_scan_value_types();
    }
    $opt['scan_value_types'] = $this->sanitize_scan_value_types($opt['scan_value_types']);

    if (!isset($opt['scan_wpml_langs']) || !is_array($opt['scan_wpml_langs'])) {
      $opt['scan_wpml_langs'] = $this->get_default_scan_wpml_langs();
    }
    $opt['scan_wpml_langs'] = $this->sanitize_scan_wpml_langs($opt['scan_wpml_langs']);

    $opt['scan_post_category_ids'] = $this->sanitize_scan_term_ids(isset($opt['scan_post_category_ids']) ? $opt['scan_post_category_ids'] : [], 'category');
    $opt['scan_post_tag_ids'] = $this->sanitize_scan_term_ids(isset($opt['scan_post_tag_ids']) ? $opt['scan_post_tag_ids'] : [], 'post_tag');
    $opt['scan_author_ids'] = $this->sanitize_scan_author_ids(isset($opt['scan_author_ids']) ? $opt['scan_author_ids'] : []);

    $scanModifiedWithinDays = isset($opt['scan_modified_within_days']) ? (int)$opt['scan_modified_within_days'] : 0;
    if ($scanModifiedWithinDays < 0) $scanModifiedWithinDays = 0;
    if ($scanModifiedWithinDays > 3650) $scanModifiedWithinDays = 3650;
    $opt['scan_modified_within_days'] = (string)$scanModifiedWithinDays;

    $opt['scan_exclude_url_patterns'] = implode("\n", $this->normalize_scan_exclude_url_patterns(isset($opt['scan_exclude_url_patterns']) ? $opt['scan_exclude_url_patterns'] : ''));

    $maxPostsPerRebuild = isset($opt['max_posts_per_rebuild']) ? (int)$opt['max_posts_per_rebuild'] : 0;
    if ($maxPostsPerRebuild < 0) $maxPostsPerRebuild = 0;
    if ($maxPostsPerRebuild > 50000) $maxPostsPerRebuild = 50000;
    $opt['max_posts_per_rebuild'] = (string)$maxPostsPerRebuild;

    return array_merge($defaults, $opt);
  }

  private function get_all_roles_map() {
    if (function_exists('get_editable_roles')) {
      $editable = get_editable_roles();
      if (is_array($editable) && !empty($editable)) {
        $map = [];
        foreach ($editable as $roleKey => $roleData) {
          $map[(string)$roleKey] = isset($roleData['name']) ? (string)$roleData['name'] : (string)$roleKey;
        }
        return $map;
      }
    }

    global $wp_roles;
    if (!($wp_roles instanceof WP_Roles)) {
      $wp_roles = wp_roles();
    }

    $map = [];
    if ($wp_roles instanceof WP_Roles && is_array($wp_roles->roles)) {
      foreach ($wp_roles->roles as $roleKey => $roleData) {
        $map[(string)$roleKey] = isset($roleData['name']) ? (string)$roleData['name'] : (string)$roleKey;
      }
    }
    return $map;
  }

  private function get_allowed_roles_from_settings() {
    $settings = $this->get_settings();
    $stored = isset($settings['allowed_roles']) && is_array($settings['allowed_roles']) ? $settings['allowed_roles'] : ['administrator'];
    $validRoles = array_keys($this->get_all_roles_map());
    $clean = [];
    foreach ($stored as $role) {
      $roleKey = sanitize_key((string)$role);
      if ($roleKey !== '' && in_array($roleKey, $validRoles, true)) {
        $clean[] = $roleKey;
      }
    }
    if (!in_array('administrator', $clean, true)) {
      $clean[] = 'administrator';
    }
    return array_values(array_unique($clean));
  }

  private function current_user_can_access_plugin() {
    if (!is_user_logged_in()) return false;
    if (current_user_can('manage_options')) return true;

    $allowedRoles = $this->get_allowed_roles_from_settings();
    $user = wp_get_current_user();
    $userRoles = is_array($user->roles ?? null) ? $user->roles : [];

    foreach ($userRoles as $role) {
      if (in_array((string)$role, $allowedRoles, true)) {
        return true;
      }
    }
    return false;
  }

  private function current_user_can_edit_link_target($post_id, $source = '') {
    $post_id = (int)$post_id;
    if ($post_id > 0) {
      return current_user_can('edit_post', $post_id);
    }

    if ((string)$source === 'menu') {
      return current_user_can('edit_theme_options');
    }

    return false;
  }

  private function normalize_weak_anchor_patterns($raw) {
    $parts = preg_split('/[\r\n,]+/', (string)$raw);
    $clean = [];
    foreach ($parts as $p) {
      $t = strtolower(trim((string)$p));
      if ($t === '') continue;
      $clean[] = $t;
    }
    return array_values(array_unique($clean));
  }

  private function get_default_weak_anchor_patterns() {
    return [
      'click here', 'read more', 'read full post', 'learn more', 'find out more',
      'more information', 'see more', 'view more', 'go to', 'link', 'click',
      'here', 'this', 'that', 'it', 'download', 'get', 'buy', 'order',
      '...', '→', '»', '>', '→→', '⇒', 'lihat lebih lanjut', 'klik di sini',
      'baca selengkapnya', 'lihat selengkapnya', 'pelajari', 'dapatkan',
      'learn', 'more', 'read', 'details', 'view', 'open', 'check', 'visit',
      'click this', 'click link', 'this link', 'continue reading', 'read article',
      'read post', 'see details', 'view details', 'discover more', 'selengkapnya',
      'baca lebih lanjut', 'baca lebih lengkap', 'klik', 'klik di sini sekarang',
      'lihat', 'lihat detail', 'lihat info', 'cek', 'kunjungi', 'lanjut',
      'lanjutkan membaca', 'pelajari lebih lanjut', 'info lengkap', 'itu', 'ini',
      'halaman', 'artikel', '>>>', '--', '[...]', 'selengkapnya...'
    ];
  }

  private function get_weak_anchor_patterns() {
    if (is_array($this->weak_anchor_patterns_cache)) {
      return $this->weak_anchor_patterns_cache;
    }

    $settings = $this->get_settings();
    $patterns = $this->normalize_weak_anchor_patterns((string)($settings['weak_anchor_patterns'] ?? ''));

    $this->weak_anchor_patterns_cache = $patterns;
    return $patterns;
  }

  private function save_settings($settings) {
    update_option('lm_settings', $settings);
  }

  private function get_anchor_groups() {
    $groups = get_option('lm_anchor_groups', []);
    return is_array($groups) ? $groups : [];
  }

  private function get_anchor_targets() {
    $targets = get_option('lm_anchor_targets', []);
    return is_array($targets) ? $targets : [];
  }

  private function save_anchor_groups($groups) {
    update_option('lm_anchor_groups', $groups, false);
  }

  private function save_anchor_targets($targets) {
    update_option('lm_anchor_targets', $targets, false);
  }

  private function sync_targets_with_groups($targets, $groups) {
    $targets = is_array($targets) ? $targets : [];
    $groups = is_array($groups) ? $groups : [];
    $all = $targets;
    foreach ($groups as $g) {
      $anchors = isset($g['anchors']) ? (array)$g['anchors'] : [];
      foreach ($anchors as $a) {
        $a = trim((string)$a);
        if ($a === '') continue;
        $all[] = $a;
      }
    }
    $merged = $this->normalize_anchor_list(implode("\n", $all));
    if ($merged !== $targets) {
      $this->save_anchor_targets($merged);
    }
    return $merged;
  }

  private function normalize_anchor_list($raw) {
    $parts = preg_split('/[\r\n,]+/', (string)$raw);
    $clean = [];
    foreach ($parts as $p) {
      $t = trim($p);
      if ($t === '') continue;
      $clean[] = $t;
    }
    return array_values(array_unique($clean));
  }

  public function run_daily_maintenance() {
    $today = gmdate('Y-m-d');
    $last = (string)get_option('lm_maintenance_last_date', '');
    if ($last === $today) return;

    $this->prune_old_audit_logs();
    $this->prune_old_stats_logs();

    update_option('lm_maintenance_last_date', $today);
  }

  private function prune_old_audit_logs() {
    global $wpdb;
    $table = $wpdb->prefix . 'lm_audit_log';
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) return;

    $settings = $this->get_settings();
    $retentionDays = (int)($settings['audit_retention_days'] ?? self::AUDIT_RETENTION_DAYS);
    if ($retentionDays < 30) $retentionDays = 30;
    if ($retentionDays > 3650) $retentionDays = 3650;

    $cutoff = gmdate('Y-m-d H:i:s', time() - ($retentionDays * DAY_IN_SECONDS));
    $wpdb->query(
      $wpdb->prepare(
        "DELETE FROM $table WHERE timestamp < %s",
        $cutoff
      )
    );
  }

  private function prune_old_stats_logs() {
    global $wpdb;
    $table = $wpdb->prefix . 'lm_stats_log';
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) return;

    $retentionDays = self::STATS_RETENTION_DAYS;
    if ($retentionDays < 30) $retentionDays = 30;

    $cutoff = gmdate('Y-m-d', time() - ($retentionDays * DAY_IN_SECONDS));
    $wpdb->query(
      $wpdb->prepare(
        "DELETE FROM $table WHERE stat_date < %s",
        $cutoff
      )
    );
  }

  private function clear_cache_all() {
    $langs = ['all'];
    if ($this->is_wpml_active()) {
      $langs = array_merge($langs, array_keys($this->get_wpml_languages_map()));
      $currentLang = $this->sanitize_wpml_lang_filter($this->get_wpml_current_language());
      if ($currentLang !== '') {
        $langs[] = $currentLang;
      }
    }
    $langs = array_values(array_unique(array_filter(array_map('strval', $langs))));

    $scopes = array_merge(['any'], array_keys($this->get_available_post_types()));
    foreach ($langs as $lang) {
      foreach ($scopes as $scope) {
        delete_transient($this->cache_key($scope, $lang));
        delete_transient($this->cache_backup_key($scope, $lang));
        delete_option($this->cache_scan_option_key($scope, $lang));
      }
    }

    delete_option('lm_last_wpml_lang_context');
    $v = (int)get_option('lm_stats_snapshot_version', 1);
    update_option('lm_stats_snapshot_version', $v + 1, false);
  }

  private function clear_main_cache_all() {
    $langs = ['all'];
    if ($this->is_wpml_active()) {
      $langs = array_merge($langs, array_keys($this->get_wpml_languages_map()));
      $currentLang = $this->sanitize_wpml_lang_filter($this->get_wpml_current_language());
      if ($currentLang !== '') {
        $langs[] = $currentLang;
      }
    }
    $langs = array_values(array_unique(array_filter(array_map('strval', $langs))));

    $scopes = array_merge(['any'], array_keys($this->get_available_post_types()));
    foreach ($langs as $lang) {
      foreach ($scopes as $scope) {
        delete_transient($this->cache_key($scope, $lang));
      }
    }
  }

  private function schedule_background_rebuild($scope_post_type = 'any', $wpml_lang = 'all', $delaySeconds = 5) {
    $scope_post_type = sanitize_key((string)$scope_post_type);
    if ($scope_post_type === '') {
      $scope_post_type = 'any';
    }
    $wpml_lang = $this->get_effective_scan_wpml_lang((string)$wpml_lang);
    $delaySeconds = max(1, (int)$delaySeconds);

    $args = [$scope_post_type, $wpml_lang];
    if (!wp_next_scheduled('lm_background_rebuild_cache', $args)) {
      wp_schedule_single_event(time() + $delaySeconds, 'lm_background_rebuild_cache', $args);
    }
  }

  private function background_rebuild_lock_key($scope_post_type, $wpml_lang) {
    return 'lm_bg_rebuild_lock_' . md5(get_current_blog_id() . '|' . (string)$scope_post_type . '|' . (string)$wpml_lang);
  }

  public function ensure_scheduled_cache_rebuild() {
    if (!wp_next_scheduled('lm_scheduled_cache_rebuild')) {
      wp_schedule_event(time() + (5 * MINUTE_IN_SECONDS), 'hourly', 'lm_scheduled_cache_rebuild');
    }
  }

  public function run_scheduled_cache_rebuild() {
    $this->run_background_rebuild_cache('any', 'all');
  }

  public function run_background_rebuild_cache($scope_post_type = 'any', $wpml_lang = 'all') {
    $scope_post_type = sanitize_key((string)$scope_post_type);
    if ($scope_post_type === '') {
      $scope_post_type = 'any';
    }
    $wpml_lang = $this->get_effective_scan_wpml_lang((string)$wpml_lang);

    $lockKey = $this->background_rebuild_lock_key($scope_post_type, $wpml_lang);
    if (get_transient($lockKey)) {
      return;
    }

    set_transient($lockKey, '1', 5 * MINUTE_IN_SECONDS);
    try {
      $this->build_or_get_cache($scope_post_type, false, $wpml_lang, false, true);
    } catch (Throwable $e) {
      if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('LM background rebuild error: ' . sanitize_text_field($e->getMessage()));
      }
    } finally {
      delete_transient($lockKey);
    }
  }

  private function site_hosts() {
    $hosts = [];
    $homeHost = parse_url(home_url(), PHP_URL_HOST);
    if ($homeHost) {
      $hosts[] = strtolower($homeHost);
      if (strpos($homeHost, 'www.') === 0) $hosts[] = strtolower(substr($homeHost, 4));
      else $hosts[] = 'www.' . strtolower($homeHost);
    }
    $hosts = apply_filters('lm_internal_hosts', $hosts);
    $hosts = array_values(array_unique(array_filter(array_map('strtolower', (array)$hosts))));
    return $hosts;
  }

  private function normalize_url($url) {
    $url = trim((string)$url);
    if ($url === '') return '';
    if (strpos($url, '//') === 0) $url = (is_ssl() ? 'https:' : 'http:') . $url;
    return $url;
  }

  private function normalize_for_compare($url) {
    $url = trim((string)$url);
    if ($url === '') return '';
    $url = $this->normalize_url($url);
    return rtrim($url, '/');
  }

  private function detect_link_value_type($href) {
    $href = trim((string)$href);
    if ($href === '') return 'empty';
    $lower = strtolower($href);

    if (strpos($lower, '#') === 0) return 'anchor';
    if (strpos($lower, 'mailto:') === 0) return 'mailto';
    if (strpos($lower, 'tel:') === 0) return 'tel';
    if (strpos($lower, 'javascript:') === 0) return 'javascript';

    if (preg_match('#^https?://#i', $href) || strpos($href, '//') === 0) return 'url';
    if (strpos($href, '/') === 0 || strpos($href, './') === 0 || strpos($href, '../') === 0) return 'relative';

    return 'other';
  }

  private function resolve_to_absolute($href, $pageUrl) {
    $href = trim((string)$href);
    if ($href === '') return '';

    $type = $this->detect_link_value_type($href);
    if (in_array($type, ['anchor','mailto','tel','javascript','empty'], true)) return $this->normalize_url($href);

    if (preg_match('#^https?://#i', $href) || strpos($href, '//') === 0) return $this->normalize_url($href);

    if (strpos($href, '/') === 0) return home_url($href);

    $base = $pageUrl ? $pageUrl : home_url('/');
    $baseParts = wp_parse_url($base);
    if (empty($baseParts['scheme']) || empty($baseParts['host'])) return $this->normalize_url($href);

    $scheme = $baseParts['scheme'];
    $host   = $baseParts['host'];
    $port   = isset($baseParts['port']) ? ':' . $baseParts['port'] : '';
    $path = isset($baseParts['path']) ? $baseParts['path'] : '/';
    $dir = preg_replace('#/[^/]*$#', '/', $path);

    $combined = $dir . $href;
    $segments = [];
    foreach (explode('/', $combined) as $seg) {
      if ($seg === '' || $seg === '.') continue;
      if ($seg === '..') { array_pop($segments); continue; }
      $segments[] = $seg;
    }
    $finalPath = '/' . implode('/', $segments);
    return $scheme . '://' . $host . $port . $finalPath;
  }

  private function is_external($url) {
    $url = $this->normalize_url($url);
    if ($url === '') return false;

    $lower = strtolower($url);
    if (strpos($lower, '#') === 0) return false;
    if (strpos($lower, 'mailto:') === 0) return false;
    if (strpos($lower, 'tel:') === 0) return false;
    if (strpos($lower, 'javascript:') === 0) return false;

    $host = parse_url($url, PHP_URL_HOST);
    if (!$host) return false;
    $host = strtolower($host);
    return !in_array($host, $this->site_hosts(), true);
  }

  private function parse_rel_flags($relRaw) {
    $relRaw = trim((string)$relRaw);
    $rel = strtolower(preg_replace('/\s+/', ' ', $relRaw));
    $parts = array_filter(explode(' ', $rel));
    $flags = array_fill_keys($parts, true);
    return [
      'raw' => $relRaw === '' ? '' : $rel,
      'nofollow' => isset($flags['nofollow']),
      'sponsored' => isset($flags['sponsored']),
      'ugc' => isset($flags['ugc']),
      'noreferrer' => isset($flags['noreferrer']),
      'noopener' => isset($flags['noopener']),
    ];
  }

  private function relationship_label($relRaw) {
    $relRaw = trim((string)$relRaw);
    if ($relRaw === '') return 'dofollow';
    return strtolower(preg_replace('/\s+/', ' ', $relRaw));
  }

  private function text_snippet($text, $max = 140) {
    $text = trim(preg_replace('/\s+/', ' ', (string)$text));
    if ($text === '') return '';
    if (function_exists('mb_strlen') && mb_strlen($text) > $max) return mb_substr($text, 0, $max) . '…';
    if (strlen($text) > $max) return substr($text, 0, $max) . '…';
    return $text;
  }

  private function normalize_snippet_match_word($word) {
    $word = trim((string)$word);
    if ($word === '') return '';

    $normalized = preg_replace('/^[^\p{L}\p{N}]+|[^\p{L}\p{N}]+$/u', '', $word);
    if ($normalized === null) {
      $normalized = preg_replace('/^[^A-Za-z0-9]+|[^A-Za-z0-9]+$/', '', $word);
    }

    return strtolower(trim((string)$normalized));
  }

  private function build_anchor_context_snippet($text, $anchor, $beforeWords = 3, $afterWords = 12, $fallbackMax = 220) {
    $text = trim(preg_replace('/\s+/', ' ', (string)$text));
    $anchor = trim(preg_replace('/\s+/', ' ', (string)$anchor));
    if ($text === '') return '';
    if ($anchor === '') return $this->text_snippet($text, $fallbackMax);

    $textWords = preg_split('/\s+/', $text);
    $anchorWords = preg_split('/\s+/', $anchor);
    if (empty($textWords) || empty($anchorWords)) return $this->text_snippet($text, $fallbackMax);

    $normalizedTextWords = array_values(array_map([$this, 'normalize_snippet_match_word'], $textWords));
    $normalizedAnchorWords = array_values(array_filter(array_map([$this, 'normalize_snippet_match_word'], $anchorWords), function($word) {
      return $word !== '';
    }));

    if (!empty($normalizedAnchorWords)) {
      $anchorWordCount = count($normalizedAnchorWords);
      $lastPossibleIndex = count($normalizedTextWords) - $anchorWordCount;

      for ($index = 0; $index <= $lastPossibleIndex; $index++) {
        $slice = array_slice($normalizedTextWords, $index, $anchorWordCount);
        if ($slice === $normalizedAnchorWords) {
          $startIndex = max(0, $index - max(0, (int)$beforeWords));
          $endIndex = min(count($textWords), $index + $anchorWordCount + max(0, (int)$afterWords));
          $windowWords = array_slice($textWords, $startIndex, $endIndex - $startIndex);
          $windowText = implode(' ', $windowWords);
          if ($startIndex > 0) $windowText = '… ' . $windowText;
          if ($endIndex < count($textWords)) $windowText .= ' …';
          return $this->text_snippet($windowText, $fallbackMax);
        }
      }
    }

    return $this->text_snippet($text, $fallbackMax);
  }

  private function text_snippet_with_anchor_offset($text, $anchor, $max = 60, $anchorWordPosition = 4) {
    $text = trim(preg_replace('/\s+/', ' ', (string)$text));
    $anchor = trim(preg_replace('/\s+/', ' ', (string)$anchor));
    if ($text === '') return '';
    if ($anchor === '' || $anchorWordPosition <= 1) return $this->text_snippet($text, $max);

    $textWords = preg_split('/\s+/', $text);
    $anchorWords = preg_split('/\s+/', $anchor);
    if (empty($textWords) || empty($anchorWords)) return $this->text_snippet($text, $max);

    $normalizedTextWords = array_values(array_map([$this, 'normalize_snippet_match_word'], $textWords));
    $normalizedAnchorWords = array_values(array_filter(array_map([$this, 'normalize_snippet_match_word'], $anchorWords), function($word) {
      return $word !== '';
    }));

    if (!empty($normalizedAnchorWords)) {
      $anchorWordCount = count($normalizedAnchorWords);
      $lastPossibleIndex = count($normalizedTextWords) - $anchorWordCount;

      for ($index = 0; $index <= $lastPossibleIndex; $index++) {
        $slice = array_slice($normalizedTextWords, $index, $anchorWordCount);
        if ($slice === $normalizedAnchorWords) {
          $startIndex = max(0, $index - ($anchorWordPosition - 1));
          $windowText = implode(' ', array_slice($textWords, $startIndex));
          return $this->text_snippet($windowText, $max);
        }
      }
    }

    if (function_exists('mb_stripos')) {
      $anchorPos = mb_stripos($text, $anchor, 0, 'UTF-8');
      if ($anchorPos === false) return $this->text_snippet($text, $max);
      $beforeText = trim(mb_substr($text, 0, $anchorPos, 'UTF-8'));
    } else {
      $anchorPos = stripos($text, $anchor);
      if ($anchorPos === false) return $this->text_snippet($text, $max);
      $beforeText = trim(substr($text, 0, $anchorPos));
    }

    $words = preg_split('/\s+/', $text);
    if (empty($words)) return '';

    $beforeWords = $beforeText === '' ? [] : preg_split('/\s+/', $beforeText);
    $anchorStartIndex = count($beforeWords);
    $startIndex = max(0, $anchorStartIndex - ($anchorWordPosition - 1));
    $windowText = implode(' ', array_slice($words, $startIndex));

    return $this->text_snippet($windowText, $max);
  }

  private function highlight_snippet_anchor_html($snippet, $anchor) {
    $snippet = (string)$snippet;
    $anchor = trim((string)$anchor);
    $escapedSnippet = esc_html($snippet);
    if ($snippet === '' || $anchor === '') return $escapedSnippet;

    $quotedAnchor = preg_quote($anchor, '/');
    $highlighted = preg_replace('/(' . $quotedAnchor . ')/iu', '<mark class="lm-snippet-anchor">$1</mark>', $escapedSnippet, 1);
    return $highlighted !== null ? $highlighted : $escapedSnippet;
  }

  private function row_id($post_id, $source, $location, $block_index, $occurrence, $link_resolved) {
    $raw = implode('|', [
      (string)$post_id,
      (string)$source,
      (string)$location,
      (string)$block_index,
      (string)$occurrence,
      (string)$this->normalize_for_compare($link_resolved),
    ]);
    return 'lm_' . substr(md5($raw), 0, 16);
  }

  private function safe_redirect_back($filters, $extra = []) {
    $url = $this->base_admin_url($filters, array_merge(['lm_paged' => 1], $extra));
    wp_safe_redirect($url);
    exit;
  }

  private function notice_class_for_message($msg, $default = 'info') {
    $text = strtolower(trim((string)$msg));
    if ($text === '') return (string)$default;

    if (
      strpos($text, 'failed') === 0 ||
      strpos($text, 'error') === 0 ||
      strpos($text, 'invalid') === 0 ||
      strpos($text, 'unauthorized') === 0 ||
      strpos($text, 'no ') === 0 ||
      strpos($text, 'cannot') !== false ||
      strpos($text, 'not found') !== false
    ) {
      return 'error';
    }

    if (
      strpos($text, 'success') === 0 ||
      strpos($text, 'settings saved') === 0 ||
      strpos($text, 'group saved') === 0 ||
      strpos($text, 'group updated') === 0 ||
      strpos($text, 'group deleted') === 0 ||
      strpos($text, 'targets saved') === 0 ||
      strpos($text, 'target updated') === 0 ||
      strpos($text, 'target deleted') === 0 ||
      strpos($text, 'deleted ') === 0
    ) {
      return 'success';
    }

    return (string)$default;
  }

  private function table_header_with_tooltip($class, $label, $tooltip = '', $align = '') {
    $tooltipClass = 'lm-tooltip';
    if ($align === 'left') {
      $tooltipClass .= ' is-left';
    } elseif ($align === 'right') {
      $tooltipClass .= ' is-right';
    }

    $inner = esc_html((string)$label);
    if ((string)$tooltip !== '') {
      $inner .= ' <span class="' . esc_attr($tooltipClass) . '" data-tooltip="' . esc_attr((string)$tooltip) . '">ⓘ</span>';
    }

    return '<th class="' . esc_attr((string)$class) . '">' . $inner . '</th>';
  }

  /* -----------------------------
   * Parsing links with occurrence
   * ----------------------------- */

  private function parse_links_from_html($html, $context) {
    $parseStartedAt = microtime(true);
    $results = [];
    if (trim((string)$html) === '') {
      $this->record_parse_runtime_stats($parseStartedAt, $context, 0, 'parse_skip_empty');
      return $results;
    }
    if (stripos((string)$html, '<a') === false && stripos((string)$html, 'href=') === false) {
      $this->record_parse_runtime_stats($parseStartedAt, $context, 0, 'parse_skip_no_marker');
      return $results;
    }

    $enabledValueTypesMap = $this->get_enabled_scan_value_types_map_cached();

    libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    $wrapped = '<?xml encoding="utf-8" ?>' . $html;
    $loaded = $doc->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    if (!$loaded) {
      libxml_clear_errors();
      $this->record_parse_runtime_stats($parseStartedAt, $context, 0, 'parse_load_failed');
      return $results;
    }

    $links = $doc->getElementsByTagName('a');

    $occ = 0;
    foreach ($links as $a) {
      /** @var DOMElement $a */
      $href = $a->getAttribute('href');

      $valueType = $this->detect_link_value_type($href);

      if (!$this->is_scan_value_type_enabled($valueType, $enabledValueTypesMap)) {
        $occ++;
        continue;
      }

      $resolved = $this->normalize_url($this->resolve_to_absolute($href, $context['page_url']));
      $linkType = $this->is_external($resolved) ? 'exlink' : 'inlink';

      $anchorText = trim($a->textContent);

      $altText = '';
      $imgs = $a->getElementsByTagName('img');
      if ($imgs && $imgs->length > 0) {
        $img = $imgs->item(0);
        $altText = trim($img->getAttribute('alt'));
      }

      $rel = $a->getAttribute('rel');
      $relFlags = $this->parse_rel_flags($rel);

      $snippetContextText = $a->parentNode ? $a->parentNode->textContent : $a->textContent;
      $snippetAnchorBasis = $anchorText !== '' ? $anchorText : $altText;
      $snippet = $this->build_anchor_context_snippet($snippetContextText, $snippetAnchorBasis, 3, 8, 120);

      $rowId = $this->row_id(
        $context['post_id'],
        $context['source'],
        $context['link_location'],
        $context['block_index'],
        $occ,
        $resolved
      );

      $results[] = array_merge($context, [
        'row_id' => $rowId,
        'occurrence' => (string)$occ,

        'link' => $resolved,
        'link_raw' => $href,
        'anchor_text' => $anchorText,
        'alt_text' => $altText,
        'snippet' => $snippet,

        'link_type' => $linkType,
        'relationship' => $this->relationship_label($rel),
        'rel_raw' => $relFlags['raw'],
        'rel_nofollow' => $relFlags['nofollow'] ? '1' : '0',
        'rel_sponsored' => $relFlags['sponsored'] ? '1' : '0',
        'rel_ugc' => $relFlags['ugc'] ? '1' : '0',

        'value_type' => $valueType,
      ]);

      $occ++;
    }

    libxml_clear_errors();
    $this->record_parse_runtime_stats($parseStartedAt, $context, count($results));
    return $results;
  }

  private function render_block_html_best_effort($block) {
    $inner = isset($block['innerHTML']) ? (string)$block['innerHTML'] : '';
    // Prefer stored block HTML so scan/update use the same source and row_id stays stable.
    if (trim($inner) !== '') return $inner;
    if (isset($block['innerContent']) && is_array($block['innerContent'])) {
      $fallback = implode('', array_map('strval', $block['innerContent']));
      if (trim($fallback) !== '') return $fallback;
    }

    $hasUrlishAttr = false;
    if (isset($block['attrs']) && is_array($block['attrs'])) {
      foreach ($block['attrs'] as $attrVal) {
        if (is_string($attrVal) && (
          stripos($attrVal, 'http://') !== false ||
          stripos($attrVal, 'https://') !== false ||
          stripos($attrVal, 'mailto:') !== false ||
          stripos($attrVal, 'tel:') !== false ||
          stripos($attrVal, 'href') !== false ||
          stripos($attrVal, '/') !== false
        )) {
          $hasUrlishAttr = true;
          break;
        }
      }
    }

    if (!$hasUrlishAttr) {
      return $inner;
    }

    if (function_exists('render_block')) {
      $rendered = render_block($block);
      if (is_string($rendered) && trim($rendered) !== '') return $rendered;
    }
    return $inner;
  }

  private function block_may_contain_link_marker($block) {
    if (!is_array($block)) {
      return false;
    }

    $inner = isset($block['innerHTML']) ? (string)$block['innerHTML'] : '';
    if ($inner !== '' && (stripos($inner, '<a') !== false || stripos($inner, 'href=') !== false)) {
      return true;
    }

    if (isset($block['innerContent']) && is_array($block['innerContent'])) {
      foreach ($block['innerContent'] as $piece) {
        if (!is_string($piece)) {
          continue;
        }
        if (stripos($piece, '<a') !== false || stripos($piece, 'href=') !== false) {
          return true;
        }
      }
    }

    if (isset($block['attrs']) && is_array($block['attrs'])) {
      foreach ($block['attrs'] as $attrVal) {
        if (is_string($attrVal) && (
          stripos($attrVal, 'http://') !== false ||
          stripos($attrVal, 'https://') !== false ||
          stripos($attrVal, 'mailto:') !== false ||
          stripos($attrVal, 'tel:') !== false ||
          stripos($attrVal, 'href') !== false ||
          stripos($attrVal, '/') !== false
        )) {
          return true;
        }
      }
    }

    return false;
  }

  private function get_author_display_name_cached($authorId) {
    $authorId = (int)$authorId;
    if ($authorId < 1) {
      return '';
    }

    if (isset($this->author_display_name_cache[$authorId])) {
      return (string)$this->author_display_name_cache[$authorId];
    }

    $name = (string)get_the_author_meta('display_name', $authorId);
    $this->author_display_name_cache[$authorId] = $name;
    return $name;
  }

  private function crawl_post($postRef, $enabledSources = null) {
    $post = null;
    $post_id = 0;

    if (is_object($postRef)) {
      $post = $postRef;
      $post_id = isset($post->ID) ? (int)$post->ID : 0;
    } else {
      $post_id = (int)$postRef;
      if ($post_id > 0) {
        $post = get_post($post_id);
      }
    }

    if (!$post || $post_id < 1 || !isset($post->post_status) || (string)$post->post_status !== 'publish') return [];
    $this->add_crawl_runtime_stat('posts_seen', 1);

    if (!is_array($enabledSources)) {
      $enabledSources = $this->get_enabled_scan_source_types();
    }

    $enabledSourcesMap = [];
    foreach ((array)$enabledSources as $src) {
      $enabledSourcesMap[sanitize_key((string)$src)] = true;
    }

    $content = isset($enabledSourcesMap['content']) ? (string)$post->post_content : '';
    $contentHasLinkMarkers = ($content !== '') && ((stripos($content, '<a') !== false) || (stripos($content, 'href=') !== false));
    $excerpt = isset($enabledSourcesMap['excerpt']) ? (string)$post->post_excerpt : '';
    $excerptHasLinkMarkers = ($excerpt !== '') && ((stripos($excerpt, '<a') !== false) || (stripos($excerpt, 'href=') !== false));
    $metaKeys = isset($enabledSourcesMap['meta']) ? $this->get_scan_meta_keys_cached() : [];
    $needsMetaScan = !empty($metaKeys);

    // Nothing to parse from enabled sources, skip expensive permalink/title/author/date work.
    if (!$contentHasLinkMarkers && !$excerptHasLinkMarkers && !$needsMetaScan) {
      $this->add_crawl_runtime_stat('posts_skipped_no_source_markers', 1);
      return [];
    }

    $pageUrl = get_permalink($post_id);
    if ($this->url_matches_scan_exclude_patterns((string)$pageUrl)) {
      $this->add_crawl_runtime_stat('posts_skipped_excluded_url', 1);
      return [];
    }
    $author = $post->post_author ? $this->get_author_display_name_cached($post->post_author) : '';

    $baseContext = [
      'post_id' => (string)$post_id,
      'post_title' => isset($post->post_title) ? (string)$post->post_title : '',
      'post_type' => (string)$post->post_type,
      'post_date' => isset($post->post_date) ? (string)$post->post_date : '',
      'post_modified' => isset($post->post_modified) ? (string)$post->post_modified : '',
      'post_author' => (string)$author,
      'page_url' => (string)$pageUrl,
    ];

    $rows = [];

    if (isset($enabledSourcesMap['content'])) {
      if ($contentHasLinkMarkers) {
        $this->add_crawl_runtime_stat('content_marker_posts', 1);
      }
      $hasBlockMarkup = (strpos($content, '<!-- wp:') !== false);
      $blocks = ($contentHasLinkMarkers && $hasBlockMarkup && function_exists('parse_blocks')) ? parse_blocks($content) : [];

      if (!empty($blocks)) {
        $this->add_crawl_runtime_stat('content_block_posts', 1);
        $this->add_crawl_runtime_stat('content_blocks_total', count($blocks));
        $i = 0;
        foreach ($blocks as $block) {
          if (!$this->block_may_contain_link_marker($block)) {
            $this->add_crawl_runtime_stat('content_blocks_skipped_no_link_marker', 1);
            $i++;
            continue;
          }

          $blockName = isset($block['blockName']) && $block['blockName'] ? $block['blockName'] : 'classic';
          $html = $this->render_block_html_best_effort($block);
          if (stripos($html, '<a') === false && stripos($html, 'href=') === false) {
            $this->add_crawl_runtime_stat('content_blocks_skipped_no_link_marker', 1);
            $i++;
            continue;
          }

          $context = array_merge($baseContext, [
            'source' => 'content',
            'link_location' => $blockName,
            'block_index' => (string)$i,
          ]);

          $this->append_rows($rows, $this->parse_links_from_html($html, $context));
          $i++;
        }
      } elseif ($contentHasLinkMarkers) {
        $context = array_merge($baseContext, [
          'source' => 'content',
          'link_location' => 'classic',
          'block_index' => '',
        ]);
        $this->append_rows($rows, $this->parse_links_from_html($content, $context));
      }
    }

    if (isset($enabledSourcesMap['excerpt'])) {
      if ($excerptHasLinkMarkers) {
        $this->add_crawl_runtime_stat('excerpt_marker_posts', 1);
        $context = array_merge($baseContext, [
          'source' => 'excerpt',
          'link_location' => 'excerpt',
          'block_index' => '',
        ]);
        $this->append_rows($rows, $this->parse_links_from_html($excerpt, $context));
      }
    }

    if (isset($enabledSourcesMap['meta'])) {
      foreach ($metaKeys as $key) {
        $this->add_crawl_runtime_stat('meta_keys_total_checked', 1);
        $val = get_post_meta($post_id, $key, true);
        if (is_string($val) && trim($val) !== '' && (stripos($val, '<a') !== false || stripos($val, 'href=') !== false)) {
          $this->add_crawl_runtime_stat('meta_values_with_link_markers', 1);
          $context = array_merge($baseContext, [
            'source' => 'meta',
            'link_location' => 'meta:' . $key,
            'block_index' => '',
          ]);
          $this->append_rows($rows, $this->parse_links_from_html($val, $context));
        }
      }
    }

    return $rows;
  }

  private function crawl_menus($enabledSources = null) {
    if (!is_array($enabledSources)) {
      $enabledSources = $this->get_enabled_scan_source_types();
    }
    if (!in_array('menu', array_map('sanitize_key', (array)$enabledSources), true)) {
      return [];
    }

    $rows = [];
    $menus = wp_get_nav_menus();
    if (empty($menus) || !is_array($menus)) return $rows;

    $baseContext = [
      'post_id' => '',
      'post_title' => '',
      'post_type' => 'menu',
      'post_date' => '',
      'post_modified' => '',
      'post_author' => '',
      'page_url' => '',
    ];

    $enabledValueTypesMap = $this->get_enabled_scan_value_types_map_cached();

    foreach ($menus as $menu) {
      $items = wp_get_nav_menu_items($menu->term_id);
      if (empty($items)) continue;

      $occ = 0;
      foreach ($items as $item) {
        $url = isset($item->url) ? (string)$item->url : '';
        $title = isset($item->title) ? (string)$item->title : '';

        $resolved = $this->normalize_url($this->resolve_to_absolute($url, home_url('/')));
        $valueType = $this->detect_link_value_type($url);
        if (!$this->is_scan_value_type_enabled($valueType, $enabledValueTypesMap)) {
          $occ++;
          continue;
        }

        $linkType = $this->is_external($resolved) ? 'exlink' : 'inlink';

        $menuBlockIndex = 'menu_item:' . (string)($item->ID ?? '');
        $rowId = $this->row_id('', 'menu', 'menu:' . (string)$menu->name, $menuBlockIndex, $occ, $resolved);

        $rows[] = array_merge($baseContext, [
          'row_id' => $rowId,
          'occurrence' => (string)$occ,

          'source' => 'menu',
          'link_location' => 'menu:' . (string)$menu->name,
          'block_index' => $menuBlockIndex,

          'link' => $resolved,
          'link_raw' => $url,
          'anchor_text' => $title,
          'alt_text' => '',
          'snippet' => '',

          'link_type' => $linkType,
          'relationship' => 'dofollow',
          'rel_raw' => '',
          'rel_nofollow' => '0',
          'rel_sponsored' => '0',
          'rel_ugc' => '0',

          'value_type' => $valueType,
        ]);

        $occ++;
      }
    }

    return $rows;
  }

  private function build_or_get_cache($scope_post_type, $force_rebuild = false, $wpml_lang = 'all', $allow_stale_serve = true, $force_incremental = false) {
    $profileTotalStarted = $this->profile_start();
    $this->reset_crawl_runtime_stats();
    $wpml_lang = $this->get_effective_scan_wpml_lang($wpml_lang);

    $key = $this->cache_key($scope_post_type, $wpml_lang);
    $backupKey = $this->cache_backup_key($scope_post_type, $wpml_lang);
    $this->purge_oversized_transient($key, $this->get_safe_transient_limit_bytes(false));
    $this->purge_oversized_transient($backupKey, $this->get_safe_transient_limit_bytes(true));

    if (!$force_rebuild) {
      $cached = get_transient($key);
      if (is_array($cached)) {
        $maxRows = $this->get_runtime_max_cache_rows();
        if (count($cached) > $maxRows) {
          $cached = array_slice($cached, 0, $maxRows);
          $this->persist_cache_payload($scope_post_type, $wpml_lang, $cached);
        }
        $this->profile_end('cache_read_hit', $profileTotalStarted, [
          'rows' => count($cached),
          'scope_post_type' => (string)$scope_post_type,
          'wpml_lang' => (string)$wpml_lang,
        ]);
        return $cached;
      }
    }

    $backup = get_transient($backupKey);
    $lastScanGmt = (string)get_option($this->cache_scan_option_key($scope_post_type, $wpml_lang), '');
    if (!$force_rebuild && $allow_stale_serve && is_array($backup) && !empty($backup)) {
      $maxRows = $this->get_runtime_max_cache_rows();
      if (count($backup) > $maxRows) {
        $backup = array_slice($backup, 0, $maxRows);
      }

      $this->profile_end('cache_read_backup_stale', $profileTotalStarted, [
        'rows' => count($backup),
        'scope_post_type' => (string)$scope_post_type,
        'wpml_lang' => (string)$wpml_lang,
        'last_scan_gmt' => (string)$lastScanGmt,
      ]);
      return $backup;
    }

    $enabledPostTypes = $this->get_enabled_scan_post_types();
    $scope_post_type = sanitize_key((string)$scope_post_type);
    $post_types = ($scope_post_type === 'any')
      ? $enabledPostTypes
      : (in_array($scope_post_type, $enabledPostTypes, true) ? [$scope_post_type] : []);

    if (empty($post_types)) {
      $all = $this->crawl_menus();
      $this->persist_cache_payload($scope_post_type, $wpml_lang, $all);
      if ($this->is_wpml_active()) {
        update_option('lm_last_wpml_lang_context', (string)$wpml_lang, false);
      }
      $this->profile_end('cache_rebuild_menus_only', $profileTotalStarted, [
        'rows' => count($all),
        'scope_post_type' => (string)$scope_post_type,
        'wpml_lang' => (string)$wpml_lang,
      ]);
      return $all;
    }

    $enabledSources = $this->get_enabled_scan_source_types();
    $scanModifiedAfterGmt = $this->get_scan_modified_after_gmt('');
    $maxPostsPerRebuild = $this->get_max_posts_per_rebuild();
    $crawlBatch = $this->get_crawl_post_batch_size();
    $incrementalEnabled = $force_incremental ? true : $this->is_incremental_rebuild_enabled();
    if (!$incrementalEnabled && $force_rebuild && is_array($backup) && $lastScanGmt !== '') {
      $incrementalEnabled = true;
    }

    $all = [];
    $maxRows = $this->get_runtime_max_cache_rows();
    $processedPosts = 0;
    $crawlStartedAt = microtime(true);
    $crawlAborted = false;
    $wpmlWasSwitched = false;
    $wpmlPreviousLang = '';

    try {
      if ($this->is_wpml_active()) {
        $prev = $this->safe_wpml_apply_filters('wpml_current_language', null);
        if (is_string($prev)) {
          $wpmlPreviousLang = sanitize_key($prev);
        }
        $switchLang = ($wpml_lang === 'all') ? 'all' : $wpml_lang;
        $wpmlWasSwitched = $this->safe_wpml_switch_language($switchLang);
      }

      if ($incrementalEnabled && is_array($backup) && $lastScanGmt !== '') {
        $incremental = $this->build_incremental_cache_from_backup($backup, $post_types, $wpml_lang, $lastScanGmt, $enabledSources);
        if (is_array($incremental)) {
          $all = $incremental;
          $this->persist_cache_payload($scope_post_type, $wpml_lang, $all);
          if ($this->is_wpml_active()) {
            update_option('lm_last_wpml_lang_context', (string)$wpml_lang, false);
          }
          $this->profile_end('cache_rebuild_incremental', $profileTotalStarted, $this->profile_meta_with_crawl_stats([
            'rows' => count($all),
            'force_rebuild' => $force_rebuild ? '1' : '0',
            'scope_post_type' => (string)$scope_post_type,
            'wpml_lang' => (string)$wpml_lang,
          ]));
          return $all;
        }
      }

      $postIds = $this->query_cache_post_ids($post_types, $wpml_lang, $scanModifiedAfterGmt);
      if (!empty($postIds)) {
        foreach ($postIds as $post_id) {
          if ($maxPostsPerRebuild > 0 && $processedPosts >= $maxPostsPerRebuild) {
            $crawlAborted = true;
            break;
          }

          $this->append_rows($all, $this->crawl_post((int)$post_id, $enabledSources));
          $processedPosts++;

          if (count($all) >= $maxRows) {
            $all = array_slice($all, 0, $maxRows);
            $crawlAborted = true;
            break;
          }
          if ($this->should_abort_crawl($crawlStartedAt)) {
            $crawlAborted = true;
            break;
          }

          // Keep lightweight periodic checkpoints for very large post sets.
          if ($crawlBatch > 0 && ($processedPosts % $crawlBatch) === 0 && $this->should_abort_crawl($crawlStartedAt)) {
            $crawlAborted = true;
            break;
          }
        }
      }
    } finally {
      if ($wpmlWasSwitched) {
        if ($wpmlPreviousLang !== '') {
          $this->safe_wpml_switch_language($wpmlPreviousLang);
        } else {
          $this->safe_wpml_switch_language(null);
        }
      }
    }

    $this->append_rows($all, $this->crawl_menus($enabledSources));

    if ($crawlAborted && defined('WP_DEBUG') && WP_DEBUG) {
      error_log('LM crawl stopped early to prevent timeout/memory exhaustion; returning partial cache.');
    }

    $this->persist_cache_payload($scope_post_type, $wpml_lang, $all);
    if ($this->is_wpml_active()) {
      update_option('lm_last_wpml_lang_context', (string)$wpml_lang, false);
    }
    $this->profile_end('cache_rebuild_full', $profileTotalStarted, $this->profile_meta_with_crawl_stats([
      'rows' => count($all),
      'processed_posts' => (int)$processedPosts,
      'crawl_aborted' => $crawlAborted ? '1' : '0',
      'scope_post_type' => (string)$scope_post_type,
      'wpml_lang' => (string)$wpml_lang,
    ]));
    return $all;
  }

  /* -----------------------------
   * Dashboard Statistics
   * ----------------------------- */

  private function get_dashboard_stats($all, $includeOrphanPages = false) {
    $stats = [
      'total_links' => count($all),
      'internal_links' => 0,
      'external_links' => 0,
      'dofollow_links' => 0,
      'nofollow_links' => 0,
      'orphan_pages' => 0,
      'internal' => [
        'total' => 0,
        'dofollow' => 0,
        'nofollow' => 0,
      ],
      'external' => [
        'total_domains' => 0,
        'dofollow' => 0,
        'nofollow' => 0,
      ],
      'by_type' => [],
      'non_good_anchor_text' => 0,
    ];

    $external_domains = [];

    foreach ($all as $row) {
      if ($row['link_type'] === 'inlink') $stats['internal_links']++;
      if ($row['link_type'] === 'exlink') $stats['external_links']++;
      
      if ($row['rel_nofollow'] === '1') $stats['nofollow_links']++;
      else $stats['dofollow_links']++;

      // Grouped stats by link type
      if ($row['link_type'] === 'inlink') {
        $stats['internal']['total']++;
        if ($row['rel_nofollow'] === '1') $stats['internal']['nofollow']++;
        else $stats['internal']['dofollow']++;
      } elseif ($row['link_type'] === 'exlink') {
        $host = parse_url($this->normalize_url((string)$row['link']), PHP_URL_HOST);
        if ($host) $external_domains[strtolower($host)] = true;
        if ($row['rel_nofollow'] === '1') $stats['external']['nofollow']++;
        else $stats['external']['dofollow']++;
      }
      
      $type = $row['link_type'];
      if (!isset($stats['by_type'][$type])) $stats['by_type'][$type] = 0;
      $stats['by_type'][$type]++;
      
      if ($this->get_anchor_quality_label((string)($row['anchor_text'] ?? '')) !== 'good') {
        $stats['non_good_anchor_text']++;
      }
    }

    $stats['external']['total_domains'] = count($external_domains);
    if ($includeOrphanPages) {
      $stats['orphan_pages'] = count($this->get_orphan_pages($all));
    }

    return $stats;
  }

  private function build_top_lists($all, $limit = 10) {
    $internalAnchorCounts = [];
    $externalAnchorCounts = [];
    $externalDomainCounts = [];
    $internalPageCounts = [];
    $externalPageCounts = [];

    foreach ($all as $row) {
      $anchor = trim((string)($row['anchor_text'] ?? ''));
      if ($anchor !== '') {
        if ($row['link_type'] === 'inlink') {
          if (!isset($internalAnchorCounts[$anchor])) $internalAnchorCounts[$anchor] = 0;
          $internalAnchorCounts[$anchor]++;
        } elseif ($row['link_type'] === 'exlink') {
          if (!isset($externalAnchorCounts[$anchor])) $externalAnchorCounts[$anchor] = 0;
          $externalAnchorCounts[$anchor]++;
        }
      }

      $link = (string)($row['link'] ?? '');
      if ($link === '') continue;

      if ($row['link_type'] === 'exlink') {
        $host = parse_url($this->normalize_url($link), PHP_URL_HOST);
        if ($host) {
          $host = strtolower($host);
          if (!isset($externalDomainCounts[$host])) $externalDomainCounts[$host] = 0;
          $externalDomainCounts[$host]++;
        }
        if (!isset($externalPageCounts[$link])) $externalPageCounts[$link] = 0;
        $externalPageCounts[$link]++;
      } elseif ($row['link_type'] === 'inlink') {
        if (!isset($internalPageCounts[$link])) $internalPageCounts[$link] = 0;
        $internalPageCounts[$link]++;
      }
    }

    $sortDesc = function(&$arr) use ($limit) {
      arsort($arr);
      return array_slice($arr, 0, $limit, true);
    };

    return [
      'internal_anchors' => $sortDesc($internalAnchorCounts),
      'external_anchors' => $sortDesc($externalAnchorCounts),
      'external_domains' => $sortDesc($externalDomainCounts),
      'internal_pages' => $sortDesc($internalPageCounts),
      'external_pages' => $sortDesc($externalPageCounts),
    ];
  }

  private function stats_snapshot_key($filters, $includeOrphanPages) {
    $version = (int)get_option('lm_stats_snapshot_version', 1);
    $schemaVersion = 2;
    $scope = (string)($filters['post_type'] ?? 'any');
    $lang = (string)($filters['wpml_lang'] ?? 'all');
    $orphanFlag = $includeOrphanPages ? '1' : '0';
    return 'lm_stats_snapshot_' . md5($scope . '|' . $lang . '|' . $orphanFlag . '|' . get_current_blog_id() . '|' . $version . '|schema_' . $schemaVersion);
  }

  private function build_stats_snapshot_payload($all, $includeOrphanPages) {
    $stats = $this->get_dashboard_stats($all, $includeOrphanPages);
    $tops = $this->build_top_lists($all, 10);

    $postTypeBuckets = [];
    $anchorQualityBuckets = ['good' => 0, 'poor' => 0, 'bad' => 0];
    foreach ($all as $row) {
      $pt = (string)($row['post_type'] ?? '');
      if ($pt !== '') {
        if (!isset($postTypeBuckets[$pt])) $postTypeBuckets[$pt] = ['internal' => 0, 'external' => 0];
        if (($row['link_type'] ?? '') === 'inlink') $postTypeBuckets[$pt]['internal']++;
        if (($row['link_type'] ?? '') === 'exlink') $postTypeBuckets[$pt]['external']++;
      }

      $q = $this->get_anchor_quality_suggestion((string)($row['anchor_text'] ?? ''));
      if (($q['quality'] ?? '') === 'good') $anchorQualityBuckets['good']++;
      elseif (($q['quality'] ?? '') === 'poor') $anchorQualityBuckets['poor']++;
      else $anchorQualityBuckets['bad']++;
    }

    ksort($postTypeBuckets);
    $maxPostType = 1;
    foreach ($postTypeBuckets as $bucket) {
      $maxPostType = max($maxPostType, (int)$bucket['internal'] + (int)$bucket['external']);
    }

    $internalCount = (int)($stats['internal_links'] ?? 0);
    $externalCount = (int)($stats['external_links'] ?? 0);
    $linkTotal = $internalCount + $externalCount;

    return [
      'stats' => $stats,
      'tops' => $tops,
      'post_type_buckets' => $postTypeBuckets,
      'anchor_quality_buckets' => $anchorQualityBuckets,
      'max_post_type' => $maxPostType,
      'max_anchor' => max($anchorQualityBuckets ?: [1]),
      'internal_count' => $internalCount,
      'external_count' => $externalCount,
      'internal_pct' => $linkTotal > 0 ? (int)round(($internalCount / $linkTotal) * 100) : 0,
      'external_pct' => $linkTotal > 0 ? (100 - ((int)round(($internalCount / $linkTotal) * 100))) : 0,
      'non_good_pct' => ($stats['total_links'] ?? 0) > 0 ? round((($stats['non_good_anchor_text'] ?? 0) / $stats['total_links']) * 100) : 0,
    ];
  }

  private function get_stats_snapshot_payload($all, $filters, $includeOrphanPages) {
    $profileStarted = $this->profile_start();
    $key = $this->stats_snapshot_key($filters, $includeOrphanPages);
    $cached = get_transient($key);
    if (is_array($cached)) {
      $this->profile_end('stats_snapshot_hit', $profileStarted, [
        'all_rows' => count((array)$all),
      ]);
      return $cached;
    }

    $payload = $this->build_stats_snapshot_payload($all, $includeOrphanPages);
    set_transient($key, $payload, $this->get_stats_snapshot_ttl());
    $this->profile_end('stats_snapshot_build', $profileStarted, [
      'all_rows' => count((array)$all),
    ]);
    return $payload;
  }

  private function get_orphan_pages($all) {
    return $this->get_orphan_pages_filtered($all, $this->get_pages_link_filters_from_request());
  }

  private function get_orphan_pages_filtered($all, $filters) {
    // Orphan page: published post/page with zero internal links in its content/excerpt/meta
    $ptList = $this->get_filterable_post_types();
    $post_types = ($filters['post_type'] === 'any') ? array_keys($ptList) : [$filters['post_type']];

    $orderby = $filters['orderby'];
    $queryOrderbyMap = [
      'date' => 'date',
      'title' => 'title',
      'modified' => 'modified',
      'post_id' => 'ID',
    ];
    $queryOrderby = isset($queryOrderbyMap[$orderby]) ? $queryOrderbyMap[$orderby] : 'date';
    $q = new WP_Query([
      'post_type' => $post_types,
      'post_status' => 'publish',
      'posts_per_page' => -1,
      'fields' => 'ids',
      'no_found_rows' => true,
      'orderby' => $queryOrderby,
      'order' => $filters['order'],
      'author' => $filters['author'],
      's' => $filters['search'],
    ]);

    $has_internal = [];
    foreach ($all as $row) {
      if ($row['source'] === 'menu') continue;
      if ($row['link_type'] !== 'inlink') continue;
      $pid = (string)$row['post_id'];
      if ($pid !== '') $has_internal[$pid] = true;
    }

    $orphans = [];
    if (!empty($q->posts)) {
      foreach ($q->posts as $post_id) {
        $pid = (string)$post_id;
        if (!isset($has_internal[$pid])) {
          $orphans[] = $post_id;
        }
      }
    }

    if (in_array($filters['orderby'], ['post_type','author'], true)) {
      $dir = $filters['order'] === 'ASC' ? 1 : -1;
      $sortMetaMap = $this->get_post_sort_meta_map($orphans);
      usort($orphans, function($a, $b) use ($filters, $dir, $sortMetaMap) {
        $aKey = (int)$a;
        $bKey = (int)$b;
        if ($filters['orderby'] === 'post_type') {
          $va = isset($sortMetaMap[$aKey]['post_type']) ? (string)$sortMetaMap[$aKey]['post_type'] : '';
          $vb = isset($sortMetaMap[$bKey]['post_type']) ? (string)$sortMetaMap[$bKey]['post_type'] : '';
        } else {
          // author
          $va = isset($sortMetaMap[$aKey]['post_author']) ? (string)$sortMetaMap[$aKey]['post_author'] : '';
          $vb = isset($sortMetaMap[$bKey]['post_author']) ? (string)$sortMetaMap[$bKey]['post_author'] : '';
        }

        $cmp = strcmp($va, $vb);
        if ($cmp === 0) {
          $cmp = ($aKey <=> $bKey);
        }
        return $cmp * $dir;
      });
    }

    return $orphans;
  }

  private function get_post_sort_meta_map($postIds) {
    $postIds = array_values(array_unique(array_filter(array_map('intval', (array)$postIds), function($id) {
      return $id > 0;
    })));
    if (empty($postIds)) {
      return [];
    }

    global $wpdb;
    $placeholders = implode(',', array_fill(0, count($postIds), '%d'));
    $sql = "SELECT ID, post_type, post_author FROM {$wpdb->posts} WHERE ID IN ($placeholders)";
    $rows = $wpdb->get_results($wpdb->prepare($sql, $postIds));
    if (empty($rows)) {
      return [];
    }

    $out = [];
    foreach ($rows as $row) {
      $id = isset($row->ID) ? (int)$row->ID : 0;
      if ($id < 1) {
        continue;
      }
      $out[$id] = [
        'post_type' => isset($row->post_type) ? (string)$row->post_type : '',
        'post_author' => isset($row->post_author) ? (string)$row->post_author : '',
      ];
    }

    return $out;
  }

  private function warm_pages_link_target_map_for_missing_posts($candidatePostIds, &$candidatePageUrlMap, &$allowedTargetMap, $limit = 200) {
    $candidatePostIds = array_values(array_unique(array_filter(array_map('intval', (array)$candidatePostIds), function($id) {
      return $id > 0;
    })));
    if (empty($candidatePostIds)) {
      return 0;
    }

    $limit = (int)$limit;
    if ($limit < 1) {
      return 0;
    }

    $missing = [];
    foreach ($candidatePostIds as $pid) {
      $pidKey = (string)$pid;
      if (!isset($candidatePageUrlMap[$pidKey]) || trim((string)$candidatePageUrlMap[$pidKey]) === '') {
        $missing[] = $pid;
      }
      if (count($missing) >= $limit) {
        break;
      }
    }

    if (empty($missing)) {
      return 0;
    }

    $warmed = 0;
    foreach ($missing as $pid) {
      $pidKey = (string)$pid;
      $url = (string)get_permalink($pid);
      if ($url === '') {
        continue;
      }

      $candidatePageUrlMap[$pidKey] = $url;
      $variants = $this->build_pages_link_target_variants($url);
      foreach ($variants as $variant) {
        if (!isset($allowedTargetMap[$variant])) {
          $allowedTargetMap[$variant] = $pidKey;
        }
      }
      $warmed++;
    }

    return $warmed;
  }

  private function get_pages_with_inbound_counts($all, $filters, $forceAllPageUrls = false) {
    $profileTotalStarted = $this->profile_start();
    $queryStarted = $this->profile_start();
    $ptList = $this->get_filterable_post_types();
    $post_types = ($filters['post_type'] === 'any') ? array_keys($ptList) : [$filters['post_type']];

    $orderby = $filters['orderby'];
    $queryOrderbyMap = [
      'date' => 'date',
      'title' => 'title',
      'modified' => 'modified',
      'post_id' => 'ID',
    ];
    $queryOrderby = isset($queryOrderbyMap[$orderby]) ? $queryOrderbyMap[$orderby] : 'date';
    $needAllPageUrls = (bool)$forceAllPageUrls || $orderby === 'page_url' || !empty($filters['search_url']);

    $taxQuery = [];
    $postCategoryFilter = isset($filters['post_category']) ? (int)$filters['post_category'] : 0;
    $postTagFilter = isset($filters['post_tag']) ? (int)$filters['post_tag'] : 0;
    if ($postCategoryFilter > 0 || $postTagFilter > 0) {
      if ($filters['post_type'] !== 'any' && $filters['post_type'] !== 'post') {
        return [];
      }
      $taxQuery = ['relation' => 'AND'];
      if ($postCategoryFilter > 0) {
        $taxQuery[] = [
          'taxonomy' => 'category',
          'field' => 'term_id',
          'terms' => [$postCategoryFilter],
        ];
      }
      if ($postTagFilter > 0) {
        $taxQuery[] = [
          'taxonomy' => 'post_tag',
          'field' => 'term_id',
          'terms' => [$postTagFilter],
        ];
      }
    }

    $queryArgs = [
      'post_type' => $post_types,
      'post_status' => 'publish',
      'posts_per_page' => -1,
      'fields' => 'ids',
      'no_found_rows' => true,
      'orderby' => $queryOrderby,
      'order' => $filters['order'],
      'author' => $filters['author'],
      's' => '',
      'update_post_meta_cache' => false,
      'update_post_term_cache' => false,
      'cache_results' => false,
    ];
    if (!empty($taxQuery)) {
      $queryArgs['tax_query'] = $taxQuery;
    }
    $dateQuery = [];
    if (!empty($filters['date_from'])) {
      $dateQuery['after'] = $filters['date_from'];
      $dateQuery['inclusive'] = true;
    }
    if (!empty($filters['date_to'])) {
      $dateQuery['before'] = $filters['date_to'];
      $dateQuery['inclusive'] = true;
    }
    $updatedDateQuery = [];
    if (!empty($filters['updated_date_from'])) {
      $updatedDateQuery['after'] = $filters['updated_date_from'];
      $updatedDateQuery['inclusive'] = true;
      $updatedDateQuery['column'] = 'post_modified';
    }
    if (!empty($filters['updated_date_to'])) {
      $updatedDateQuery['before'] = $filters['updated_date_to'];
      $updatedDateQuery['inclusive'] = true;
      $updatedDateQuery['column'] = 'post_modified';
    }

    $dateQueryClauses = [];
    if (!empty($dateQuery)) $dateQueryClauses[] = $dateQuery;
    if (!empty($updatedDateQuery)) $dateQueryClauses[] = $updatedDateQuery;
    if (!empty($dateQueryClauses)) {
      if (count($dateQueryClauses) > 1) {
        $dateQueryClauses['relation'] = 'AND';
      }
      $queryArgs['date_query'] = $dateQueryClauses;
    }
    $q = new WP_Query($queryArgs);
    $this->profile_end('pages_link_query_candidates', $queryStarted, [
      'candidate_posts' => !empty($q->posts) ? count($q->posts) : 0,
      'post_type_scope' => (string)$filters['post_type'],
    ]);

    $allowedPostIdsMap = [];
    if (!empty($q->posts)) {
      foreach ($q->posts as $post_id) {
        $allowedPostIdsMap[(string)$post_id] = true;
      }
    }

    $prefetchTargetsStarted = $this->profile_start();
    $allowedTargetMap = [];
    $candidatePageUrlMap = [];
    $postDataMap = [];
    $candidatePostIds = !empty($q->posts) ? array_values(array_unique(array_map('intval', (array)$q->posts))) : [];

    if (!empty($allowedPostIdsMap) && !empty($all)) {
      foreach ($all as $row) {
        $rowPostId = (string)($row['post_id'] ?? '');
        if ($rowPostId === '' || !isset($allowedPostIdsMap[$rowPostId])) {
          continue;
        }
        if (!isset($candidatePageUrlMap[$rowPostId])) {
          $candidatePageUrlMap[$rowPostId] = (string)($row['page_url'] ?? '');
        }
        $targetVariants = $this->build_pages_link_target_variants((string)($row['page_url'] ?? ''));
        foreach ($targetVariants as $variant) {
          if (!isset($allowedTargetMap[$variant])) {
            $allowedTargetMap[$variant] = $rowPostId;
          }
        }
      }
    }

    if (!empty($candidatePostIds)) {
      if ($forceAllPageUrls) {
        $warmupLimit = min(140, max(40, (int)ceil(count($candidatePostIds) * 0.06)));
      } else {
        $warmupLimit = min(80, max(20, (int)ceil(count($candidatePostIds) * 0.03)));
      }
    } else {
      $warmupLimit = 0;
    }
    $warmedTargetUrls = $this->warm_pages_link_target_map_for_missing_posts($candidatePostIds, $candidatePageUrlMap, $allowedTargetMap, $warmupLimit);

    $this->profile_end('pages_link_prefetch_target_map', $prefetchTargetsStarted, [
      'candidate_posts' => !empty($q->posts) ? count($q->posts) : 0,
      'target_map_size' => count($allowedTargetMap),
      'warmed_target_urls' => (int)$warmedTargetUrls,
    ]);

    $inbound_counts = [];
    $outbound_counts = [];
    $internal_outbound_counts = [];
    $targetResolutionCache = [];
    $adaptiveFallbackMax = 120;
    if (!empty($allowedPostIdsMap)) {
      $adaptiveFallbackMax = max(10, min(120, (int)ceil(count($allowedPostIdsMap) * 0.01)));
    }
    $targetFallbackState = ['used' => 0, 'max' => $adaptiveFallbackMax];
    $scanStarted = $this->profile_start();
    foreach ($all as $row) {
      if (($filters['location'] ?? 'any') !== 'any' && (string)($row['link_location'] ?? '') !== (string)$filters['location']) continue;
      if (($filters['source_type'] ?? 'any') !== 'any' && (string)($row['source'] ?? '') !== (string)$filters['source_type']) continue;
      if (($filters['link_type'] ?? 'any') !== 'any' && (string)($row['link_type'] ?? '') !== (string)$filters['link_type']) continue;
      if ((string)($filters['value_contains'] ?? '') !== '' && !$this->text_matches((string)($row['link'] ?? ''), (string)$filters['value_contains'], (string)($filters['search_mode'] ?? 'contains'))) continue;

      $seoFlag = (string)($filters['seo_flag'] ?? 'any');
      if ($seoFlag !== 'any') {
        $nofollow = (string)($row['rel_nofollow'] ?? '0') === '1';
        $sponsored = (string)($row['rel_sponsored'] ?? '0') === '1';
        $ugc = (string)($row['rel_ugc'] ?? '0') === '1';
        if ($seoFlag === 'dofollow' && ($nofollow || $sponsored || $ugc)) continue;
        if ($seoFlag === 'nofollow' && !$nofollow) continue;
        if ($seoFlag === 'sponsored' && !$sponsored) continue;
        if ($seoFlag === 'ugc' && !$ugc) continue;
      }

      if ($row['source'] === 'menu') continue;

      $sid = (string)$row['post_id'];
      $isCandidateSource = ($sid !== '' && isset($allowedPostIdsMap[$sid]));

      // Rows from posts outside the candidate set cannot affect outbound metrics.
      // Keep non-candidate rows only for inbound target resolution on internal links.
      if (!$isCandidateSource && (string)($row['link_type'] ?? '') !== 'inlink') {
        continue;
      }

      // Count inbound links: internal links pointing to a target post from other source posts
      if ($row['link_type'] === 'inlink') {
        $targetNorm = $this->normalize_for_compare((string)($row['link'] ?? ''));
        if ($targetNorm !== '') {
          $targetPid = $this->resolve_target_post_id_for_pages_link($targetNorm, $allowedPostIdsMap, $targetResolutionCache, $allowedTargetMap, $targetFallbackState);
          if ($targetPid !== '' && $targetPid !== $sid) {
            if (!isset($inbound_counts[$targetPid])) $inbound_counts[$targetPid] = 0;
            $inbound_counts[$targetPid]++;
          }
        }
      }

      // Count outbound links from a source post
      if ($isCandidateSource) {
        // Count internal outbound links
        if ($row['link_type'] === 'inlink') {
          if (!isset($internal_outbound_counts[$sid])) $internal_outbound_counts[$sid] = 0;
          $internal_outbound_counts[$sid]++;
        }
        // Count external outbound links
        if ($row['link_type'] === 'exlink') {
          if (!isset($outbound_counts[$sid])) $outbound_counts[$sid] = 0;
          $outbound_counts[$sid]++;
        }
      }
    }
    $this->profile_end('pages_link_scan_cache_rows', $scanStarted, [
      'all_rows' => count((array)$all),
      'resolved_targets' => count($targetResolutionCache),
      'url_to_postid_fallback_used' => isset($targetFallbackState['used']) ? (int)$targetFallbackState['used'] : 0,
    ]);

    $rows = [];
    $internalOutboundThresholds = $this->get_internal_outbound_status_thresholds();
    $externalOutboundThresholds = $this->get_external_outbound_status_thresholds();

    $prefetchPostDataStarted = $this->profile_start();
    if (empty($postDataMap) && !empty($candidatePostIds)) {
      $postDataMap = $this->get_pages_link_post_data_map($candidatePostIds, $post_types, $needAllPageUrls, $candidatePageUrlMap);
    }
    $this->profile_end('pages_link_prefetch_post_data', $prefetchPostDataStarted, [
      'candidate_posts' => !empty($q->posts) ? count($q->posts) : 0,
      'post_data_rows' => count($postDataMap),
    ]);

    $assembleStarted = $this->profile_start();
    if (!empty($q->posts)) {
      foreach ($q->posts as $post_id) {
        $pid = (string)$post_id;
        $postData = isset($postDataMap[$pid]) && is_array($postDataMap[$pid]) ? $postDataMap[$pid] : null;

        $postTitle = is_array($postData) ? (string)$postData['post_title'] : (string)get_the_title($post_id);
        if (!empty($filters['search'])) {
          if (!$this->text_matches($postTitle, (string)$filters['search'], (string)$filters['search_mode'])) {
            continue;
          }
        }

        $inbound = $inbound_counts[$pid] ?? 0;
        $outbound = $outbound_counts[$pid] ?? 0;
        $internal_outbound = $internal_outbound_counts[$pid] ?? 0;
        $statusKey = $this->inbound_status_key($inbound);
        $internalOutboundStatusKey = $this->four_level_status_key($internal_outbound, $internalOutboundThresholds);
        $externalOutboundStatusKey = $this->four_level_status_key($outbound, $externalOutboundThresholds);

        if ($filters['status'] !== 'any' && $filters['status'] !== $statusKey) {
          continue;
        }
        if (($filters['internal_outbound_status'] ?? 'any') !== 'any' && (string)$filters['internal_outbound_status'] !== $internalOutboundStatusKey) {
          continue;
        }
        if (($filters['external_outbound_status'] ?? 'any') !== 'any' && (string)$filters['external_outbound_status'] !== $externalOutboundStatusKey) {
          continue;
        }
        if ($filters['inbound_min'] >= 0 && $inbound < $filters['inbound_min']) {
          continue;
        }
        if ($filters['inbound_max'] >= 0 && $inbound > $filters['inbound_max']) {
          continue;
        }
        if ($filters['internal_outbound_min'] >= 0 && $internal_outbound < $filters['internal_outbound_min']) {
          continue;
        }
        if ($filters['internal_outbound_max'] >= 0 && $internal_outbound > $filters['internal_outbound_max']) {
          continue;
        }
        if ($filters['outbound_min'] >= 0 && $outbound < $filters['outbound_min']) {
          continue;
        }
        if ($filters['outbound_max'] >= 0 && $outbound > $filters['outbound_max']) {
          continue;
        }

        $pageUrl = is_array($postData) ? (string)$postData['page_url'] : (string)get_permalink($post_id);
        // Filter by page URL substring if requested
        if (!empty($filters['search_url'])) {
          if ($pageUrl === '') {
            $pageUrl = (string)get_permalink($post_id);
          }
          if ($pageUrl === '' || !$this->text_matches($pageUrl, (string)$filters['search_url'], (string)$filters['search_mode'])) {
            continue;
          }
        }

        $authorName = is_array($postData) ? (string)$postData['author_name'] : '';
        $postType = is_array($postData) ? (string)$postData['post_type'] : '';
        $postDate = is_array($postData) ? (string)$postData['post_date'] : '';
        $postModified = is_array($postData) ? (string)$postData['post_modified'] : '';

        $rows[] = [
          'post_id' => $post_id,
          'post_title' => $postTitle,
          'post_type' => $postType,
          'author_name' => $authorName,
          'post_date' => $postDate,
          'post_modified' => $postModified,
          'page_url' => $pageUrl,
          'inbound' => $inbound,
          'outbound' => $outbound,
          'internal_outbound' => $internal_outbound,
          'status' => $statusKey,
          'internal_outbound_status' => $internalOutboundStatusKey,
          'external_outbound_status' => $externalOutboundStatusKey,
        ];
      }
    }
    $this->profile_end('pages_link_assemble_rows', $assembleStarted, [
      'candidate_posts' => !empty($q->posts) ? count($q->posts) : 0,
      'result_rows' => count($rows),
    ]);

    $dir = ($filters['order'] === 'ASC') ? 1 : -1;
    $sortStarted = $this->profile_start();
    $dbOrderedFields = ['date', 'title', 'modified', 'post_id'];
    if (!in_array($orderby, $dbOrderedFields, true)) {
      usort($rows, function($a, $b) use ($orderby, $dir) {
        $numericSort = false;
        $inboundStatusRank = ['orphan' => 0, 'low' => 1, 'standard' => 2, 'excellent' => 3];
        $outboundStatusRank = ['none' => 0, 'low' => 1, 'optimal' => 2, 'excessive' => 3];
        switch ($orderby) {
          case 'post_id':
            $va = (int)($a['post_id'] ?? 0);
            $vb = (int)($b['post_id'] ?? 0);
            $numericSort = true;
            break;
          case 'title':
            $va = (string)($a['post_title'] ?? '');
            $vb = (string)($b['post_title'] ?? '');
            break;
          case 'post_type':
            $va = (string)($a['post_type'] ?? '');
            $vb = (string)($b['post_type'] ?? '');
            break;
          case 'author':
            $va = (string)($a['author_name'] ?? '');
            $vb = (string)($b['author_name'] ?? '');
            break;
          case 'modified':
            $va = (string)($a['post_modified'] ?? '');
            $vb = (string)($b['post_modified'] ?? '');
            break;
          case 'page_url':
            $va = (string)($a['page_url'] ?? '');
            $vb = (string)($b['page_url'] ?? '');
            break;
          case 'inbound':
            $va = (int)($a['inbound'] ?? 0);
            $vb = (int)($b['inbound'] ?? 0);
            $numericSort = true;
            break;
          case 'internal_outbound':
            $va = (int)($a['internal_outbound'] ?? 0);
            $vb = (int)($b['internal_outbound'] ?? 0);
            $numericSort = true;
            break;
          case 'outbound':
            $va = (int)($a['outbound'] ?? 0);
            $vb = (int)($b['outbound'] ?? 0);
            $numericSort = true;
            break;
          case 'status':
            $va = isset($inboundStatusRank[(string)($a['status'] ?? '')]) ? $inboundStatusRank[(string)($a['status'] ?? '')] : 999;
            $vb = isset($inboundStatusRank[(string)($b['status'] ?? '')]) ? $inboundStatusRank[(string)($b['status'] ?? '')] : 999;
            $numericSort = true;
            break;
          case 'internal_outbound_status':
            $va = isset($outboundStatusRank[(string)($a['internal_outbound_status'] ?? '')]) ? $outboundStatusRank[(string)($a['internal_outbound_status'] ?? '')] : 999;
            $vb = isset($outboundStatusRank[(string)($b['internal_outbound_status'] ?? '')]) ? $outboundStatusRank[(string)($b['internal_outbound_status'] ?? '')] : 999;
            $numericSort = true;
            break;
          case 'external_outbound_status':
            $va = isset($outboundStatusRank[(string)($a['external_outbound_status'] ?? '')]) ? $outboundStatusRank[(string)($a['external_outbound_status'] ?? '')] : 999;
            $vb = isset($outboundStatusRank[(string)($b['external_outbound_status'] ?? '')]) ? $outboundStatusRank[(string)($b['external_outbound_status'] ?? '')] : 999;
            $numericSort = true;
            break;
          case 'date':
          default:
            $va = (string)($a['post_date'] ?? '');
            $vb = (string)($b['post_date'] ?? '');
            break;
        }

        if ($numericSort) {
          $cmp = ($va <=> $vb);
        } else {
          $cmp = strcmp((string)$va, (string)$vb);
        }

        if ($cmp === 0) {
          $cmp = ((int)($a['post_id'] ?? 0) <=> (int)($b['post_id'] ?? 0));
        }

        return $cmp * $dir;
      });
    }

    $this->profile_end('pages_link_sort_rows', $sortStarted, [
      'result_rows' => count($rows),
      'orderby' => (string)$orderby,
      'order' => (string)$filters['order'],
      'query_order_fastpath' => in_array($orderby, $dbOrderedFields, true) ? '1' : '0',
    ]);

    $this->profile_end('pages_link_total', $profileTotalStarted, [
      'all_rows' => count((array)$all),
      'candidate_posts' => !empty($q->posts) ? count($q->posts) : 0,
      'result_rows' => count($rows),
    ]);

    return $rows;
  }

  private function inbound_status_key($count) {
    $count = (int)$count;
    $thresholds = $this->get_inbound_status_thresholds();
    if ($count <= $thresholds['orphan_max']) return 'orphan';
    if ($count <= $thresholds['low_max']) return 'low';
    if ($count <= $thresholds['standard_max']) return 'standard';
    return 'excellent';
  }

  private function inbound_status($count) {
    $key = $this->inbound_status_key($count);
    switch ($key) {
      case 'orphan':
        return 'Orphaned';
      case 'low':
        return 'Low';
      case 'standard':
        return 'Standard';
      case 'excellent':
        return 'Excellent';
      default:
        return '—';
    }
  }

  private function get_pages_link_filters_from_request() {
    $postTypes = $this->get_filterable_post_types();
    $postType = isset($_REQUEST['lm_pages_link_post_type']) ? sanitize_text_field($_REQUEST['lm_pages_link_post_type']) : 'any';
    if ($postType !== 'any' && !isset($postTypes[$postType])) $postType = 'any';

    $postCategory = isset($_REQUEST['lm_pages_link_post_category']) ? $this->sanitize_post_term_filter($_REQUEST['lm_pages_link_post_category'], 'category') : 0;
    $postTag = isset($_REQUEST['lm_pages_link_post_tag']) ? $this->sanitize_post_term_filter($_REQUEST['lm_pages_link_post_tag'], 'post_tag') : 0;

    if ($postType !== 'any' && $postType !== 'post') {
      $postCategory = 0;
      $postTag = 0;
    }

    $author = isset($_REQUEST['lm_pages_link_author']) ? intval($_REQUEST['lm_pages_link_author']) : 0;
    if ($author < 0) $author = 0;

    $search = isset($_REQUEST['lm_pages_link_search']) ? sanitize_text_field($_REQUEST['lm_pages_link_search']) : '';
    $search_url = isset($_REQUEST['lm_pages_link_search_url']) ? sanitize_text_field($_REQUEST['lm_pages_link_search_url']) : '';
    $dateFrom = isset($_REQUEST['lm_pages_link_date_from']) ? $this->sanitize_date_ymd($_REQUEST['lm_pages_link_date_from']) : '';
    $dateTo = isset($_REQUEST['lm_pages_link_date_to']) ? $this->sanitize_date_ymd($_REQUEST['lm_pages_link_date_to']) : '';
    $updatedDateFrom = isset($_REQUEST['lm_pages_link_updated_date_from']) ? $this->sanitize_date_ymd($_REQUEST['lm_pages_link_updated_date_from']) : '';
    $updatedDateTo = isset($_REQUEST['lm_pages_link_updated_date_to']) ? $this->sanitize_date_ymd($_REQUEST['lm_pages_link_updated_date_to']) : '';
    $searchMode = isset($_REQUEST['lm_pages_link_search_mode']) ? $this->sanitize_text_match_mode($_REQUEST['lm_pages_link_search_mode']) : 'contains';
    $location = isset($_REQUEST['lm_pages_link_location']) ? sanitize_text_field((string)$_REQUEST['lm_pages_link_location']) : 'any';
    if ($location === '') $location = 'any';
    $sourceType = isset($_REQUEST['lm_pages_link_source_type'])
      ? $this->sanitize_source_type_filter($_REQUEST['lm_pages_link_source_type'])
      : 'any';
    $linkType = isset($_REQUEST['lm_pages_link_link_type']) ? sanitize_text_field((string)$_REQUEST['lm_pages_link_link_type']) : 'any';
    if (!in_array($linkType, ['any','inlink','exlink'], true)) $linkType = 'any';
    $valueContains = isset($_REQUEST['lm_pages_link_value']) ? sanitize_text_field((string)$_REQUEST['lm_pages_link_value']) : '';
    $seoFlag = isset($_REQUEST['lm_pages_link_seo_flag']) ? sanitize_text_field((string)$_REQUEST['lm_pages_link_seo_flag']) : 'any';
    if (!in_array($seoFlag, ['any','dofollow','nofollow','sponsored','ugc'], true)) $seoFlag = 'any';

    $perPage = isset($_REQUEST['lm_pages_link_per_page']) ? intval($_REQUEST['lm_pages_link_per_page']) : 25;
    if ($perPage < 10) $perPage = 10;
    if ($perPage > 500) $perPage = 500;

    $paged = isset($_REQUEST['lm_pages_link_paged']) ? intval($_REQUEST['lm_pages_link_paged']) : 1;
    if ($paged < 1) $paged = 1;

    $orderby = isset($_REQUEST['lm_pages_link_orderby']) ? sanitize_text_field($_REQUEST['lm_pages_link_orderby']) : 'date';
    if (!in_array($orderby, ['post_id','date','modified','title','post_type','author','page_url','inbound','internal_outbound','outbound','status','internal_outbound_status','external_outbound_status'], true)) $orderby = 'date';

    $order = isset($_REQUEST['lm_pages_link_order']) ? sanitize_text_field($_REQUEST['lm_pages_link_order']) : 'DESC';
    $order = strtoupper($order);
    if (!in_array($order, ['ASC','DESC'], true)) $order = 'DESC';

    $inboundMinRaw = isset($_REQUEST['lm_pages_link_inbound_min']) ? trim((string)$_REQUEST['lm_pages_link_inbound_min']) : '';
    $inboundMaxRaw = isset($_REQUEST['lm_pages_link_inbound_max']) ? trim((string)$_REQUEST['lm_pages_link_inbound_max']) : '';
    $inboundMin = ($inboundMinRaw === '') ? -1 : intval($inboundMinRaw);
    $inboundMax = ($inboundMaxRaw === '') ? -1 : intval($inboundMaxRaw);
    if ($inboundMin < -1) $inboundMin = -1;
    if ($inboundMax < -1) $inboundMax = -1;

    $internalOutboundMinRaw = isset($_REQUEST['lm_pages_link_internal_outbound_min']) ? trim((string)$_REQUEST['lm_pages_link_internal_outbound_min']) : '';
    $internalOutboundMaxRaw = isset($_REQUEST['lm_pages_link_internal_outbound_max']) ? trim((string)$_REQUEST['lm_pages_link_internal_outbound_max']) : '';
    $internalOutboundMin = ($internalOutboundMinRaw === '') ? -1 : intval($internalOutboundMinRaw);
    $internalOutboundMax = ($internalOutboundMaxRaw === '') ? -1 : intval($internalOutboundMaxRaw);
    if ($internalOutboundMin < -1) $internalOutboundMin = -1;
    if ($internalOutboundMax < -1) $internalOutboundMax = -1;

    $outboundMinRaw = isset($_REQUEST['lm_pages_link_outbound_min']) ? trim((string)$_REQUEST['lm_pages_link_outbound_min']) : '';
    $outboundMaxRaw = isset($_REQUEST['lm_pages_link_outbound_max']) ? trim((string)$_REQUEST['lm_pages_link_outbound_max']) : '';
    $outboundMin = ($outboundMinRaw === '') ? -1 : intval($outboundMinRaw);
    $outboundMax = ($outboundMaxRaw === '') ? -1 : intval($outboundMaxRaw);
    if ($outboundMin < -1) $outboundMin = -1;
    if ($outboundMax < -1) $outboundMax = -1;

    $status = isset($_REQUEST['lm_pages_link_status']) ? sanitize_text_field($_REQUEST['lm_pages_link_status']) : 'any';
    $status = strtolower(trim($status));
    if ($status === 'orphaned') $status = 'orphan';
    if (!in_array($status, ['any','orphan','low','standard','excellent'], true)) $status = 'any';

    $internalOutboundStatus = isset($_REQUEST['lm_pages_link_internal_outbound_status']) ? sanitize_text_field($_REQUEST['lm_pages_link_internal_outbound_status']) : 'any';
    $internalOutboundStatus = strtolower(trim($internalOutboundStatus));
    if (!in_array($internalOutboundStatus, ['any','none','low','optimal','excessive'], true)) $internalOutboundStatus = 'any';

    $externalOutboundStatus = isset($_REQUEST['lm_pages_link_external_outbound_status']) ? sanitize_text_field($_REQUEST['lm_pages_link_external_outbound_status']) : 'any';
    $externalOutboundStatus = strtolower(trim($externalOutboundStatus));
    if (!in_array($externalOutboundStatus, ['any','none','low','optimal','excessive'], true)) $externalOutboundStatus = 'any';

    $rebuild = isset($_REQUEST['lm_pages_link_rebuild']) ? sanitize_text_field($_REQUEST['lm_pages_link_rebuild']) : '0';
    $rebuild = $rebuild === '1';

    $wpmlLang = $this->sanitize_wpml_lang_filter($this->get_wpml_current_language());

    return [
      'post_type' => $postType,
      'post_category' => $postCategory,
      'post_tag' => $postTag,
      'wpml_lang' => $wpmlLang,
      'author' => $author,
      'search' => $search,
      'search_url' => $search_url,
      'date_from' => $dateFrom,
      'date_to' => $dateTo,
      'updated_date_from' => $updatedDateFrom,
      'updated_date_to' => $updatedDateTo,
      'search_mode' => $searchMode,
      'location' => $location,
      'source_type' => $sourceType,
      'link_type' => $linkType,
      'value_contains' => $valueContains,
      'seo_flag' => $seoFlag,
      'per_page' => $perPage,
      'paged' => $paged,
      'orderby' => $orderby,
      'order' => $order,
      'inbound_min' => $inboundMin,
      'inbound_max' => $inboundMax,
      'internal_outbound_min' => $internalOutboundMin,
      'internal_outbound_max' => $internalOutboundMax,
      'outbound_min' => $outboundMin,
      'outbound_max' => $outboundMax,
      'status' => $status,
      'internal_outbound_status' => $internalOutboundStatus,
      'external_outbound_status' => $externalOutboundStatus,
      'rebuild' => $rebuild,
    ];
  }

  private function pages_link_admin_url($filters, $override = []) {
    $args = [
      'page' => 'links-manager-pages-link',
      'lm_pages_link_post_type' => $filters['post_type'],
      'lm_pages_link_post_category' => isset($filters['post_category']) ? (int)$filters['post_category'] : 0,
      'lm_pages_link_post_tag' => isset($filters['post_tag']) ? (int)$filters['post_tag'] : 0,
      'lm_pages_link_author' => $filters['author'],
      'lm_pages_link_search' => $filters['search'],
      'lm_pages_link_search_url' => isset($filters['search_url']) ? $filters['search_url'] : '',
      'lm_pages_link_date_from' => isset($filters['date_from']) ? $filters['date_from'] : '',
      'lm_pages_link_date_to' => isset($filters['date_to']) ? $filters['date_to'] : '',
      'lm_pages_link_updated_date_from' => isset($filters['updated_date_from']) ? $filters['updated_date_from'] : '',
      'lm_pages_link_updated_date_to' => isset($filters['updated_date_to']) ? $filters['updated_date_to'] : '',
      'lm_pages_link_search_mode' => isset($filters['search_mode']) ? $filters['search_mode'] : 'contains',
      'lm_pages_link_location' => isset($filters['location']) ? $filters['location'] : 'any',
      'lm_pages_link_source_type' => isset($filters['source_type']) ? $filters['source_type'] : 'any',
      'lm_pages_link_link_type' => isset($filters['link_type']) ? $filters['link_type'] : 'any',
      'lm_pages_link_value' => isset($filters['value_contains']) ? $filters['value_contains'] : '',
      'lm_pages_link_seo_flag' => isset($filters['seo_flag']) ? $filters['seo_flag'] : 'any',
      'lm_pages_link_per_page' => $filters['per_page'],
      'lm_pages_link_paged' => $filters['paged'],
      'lm_pages_link_orderby' => $filters['orderby'],
      'lm_pages_link_order' => $filters['order'],
      'lm_pages_link_inbound_min' => $filters['inbound_min'],
      'lm_pages_link_inbound_max' => $filters['inbound_max'],
      'lm_pages_link_internal_outbound_min' => $filters['internal_outbound_min'],
      'lm_pages_link_internal_outbound_max' => $filters['internal_outbound_max'],
      'lm_pages_link_outbound_min' => $filters['outbound_min'],
      'lm_pages_link_outbound_max' => $filters['outbound_max'],
      'lm_pages_link_status' => $filters['status'],
      'lm_pages_link_internal_outbound_status' => isset($filters['internal_outbound_status']) ? $filters['internal_outbound_status'] : 'any',
      'lm_pages_link_external_outbound_status' => isset($filters['external_outbound_status']) ? $filters['external_outbound_status'] : 'any',
      'lm_pages_link_rebuild' => $filters['rebuild'] ? '1' : '0',
    ];
    foreach ($override as $k => $v) $args[$k] = $v;
    return admin_url('admin.php?' . http_build_query($args));
  }

  private function build_pages_link_export_url($filters) {
    $args = [
      'action' => 'lm_export_pages_link_csv',
      self::NONCE_NAME => wp_create_nonce(self::NONCE_ACTION),
      'lm_pages_link_post_type' => $filters['post_type'],
      'lm_pages_link_post_category' => isset($filters['post_category']) ? (int)$filters['post_category'] : 0,
      'lm_pages_link_post_tag' => isset($filters['post_tag']) ? (int)$filters['post_tag'] : 0,
      'lm_pages_link_author' => $filters['author'],
      'lm_pages_link_search' => $filters['search'],
      'lm_pages_link_search_url' => isset($filters['search_url']) ? $filters['search_url'] : '',
      'lm_pages_link_date_from' => isset($filters['date_from']) ? $filters['date_from'] : '',
      'lm_pages_link_date_to' => isset($filters['date_to']) ? $filters['date_to'] : '',
      'lm_pages_link_updated_date_from' => isset($filters['updated_date_from']) ? $filters['updated_date_from'] : '',
      'lm_pages_link_updated_date_to' => isset($filters['updated_date_to']) ? $filters['updated_date_to'] : '',
      'lm_pages_link_search_mode' => isset($filters['search_mode']) ? $filters['search_mode'] : 'contains',
      'lm_pages_link_location' => isset($filters['location']) ? $filters['location'] : 'any',
      'lm_pages_link_source_type' => isset($filters['source_type']) ? $filters['source_type'] : 'any',
      'lm_pages_link_link_type' => isset($filters['link_type']) ? $filters['link_type'] : 'any',
      'lm_pages_link_value' => isset($filters['value_contains']) ? $filters['value_contains'] : '',
      'lm_pages_link_seo_flag' => isset($filters['seo_flag']) ? $filters['seo_flag'] : 'any',
      'lm_pages_link_per_page' => $filters['per_page'],
      'lm_pages_link_paged' => $filters['paged'],
      'lm_pages_link_orderby' => $filters['orderby'],
      'lm_pages_link_order' => $filters['order'],
      'lm_pages_link_inbound_min' => $filters['inbound_min'],
      'lm_pages_link_inbound_max' => $filters['inbound_max'],
      'lm_pages_link_internal_outbound_min' => $filters['internal_outbound_min'],
      'lm_pages_link_internal_outbound_max' => $filters['internal_outbound_max'],
      'lm_pages_link_outbound_min' => $filters['outbound_min'],
      'lm_pages_link_outbound_max' => $filters['outbound_max'],
      'lm_pages_link_status' => $filters['status'],
      'lm_pages_link_internal_outbound_status' => isset($filters['internal_outbound_status']) ? $filters['internal_outbound_status'] : 'any',
      'lm_pages_link_external_outbound_status' => isset($filters['external_outbound_status']) ? $filters['external_outbound_status'] : 'any',
      'lm_pages_link_rebuild' => $filters['rebuild'] ? '1' : '0',
    ];
    return admin_url('admin-post.php?' . http_build_query($args));
  }

  private function render_pages_link_pagination($filters, $paged, $totalPages) {
    if ($totalPages <= 1) return;

    echo '<div class="tablenav" style="margin:10px 0;">';
    echo '<div class="tablenav-pages">';
    echo '<span class="displaying-num">Page ' . esc_html((string)$paged) . ' of ' . esc_html((string)$totalPages) . '</span> ';

    $prev = max(1, $paged - 1);
    $next = min($totalPages, $paged + 1);

    echo '<a class="button" href="' . esc_url($this->pages_link_admin_url($filters, ['lm_pages_link_paged' => 1])) . '">&laquo; First</a> ';
    echo '<a class="button" href="' . esc_url($this->pages_link_admin_url($filters, ['lm_pages_link_paged' => $prev])) . '">&lsaquo; Previous</a> ';
    echo '<a class="button" href="' . esc_url($this->pages_link_admin_url($filters, ['lm_pages_link_paged' => $next])) . '">Next &rsaquo;</a> ';
    echo '<a class="button" href="' . esc_url($this->pages_link_admin_url($filters, ['lm_pages_link_paged' => $totalPages])) . '">Last &raquo;</a>';

    echo '</div></div>';
  }

  /* -----------------------------
   * Anchor Text Quality
   * ----------------------------- */

  private function has_weak_anchor_text($anchor) {
    $anchor = strtolower(trim((string)$anchor));
    if ($anchor === '') return true;

    $weak_patterns = $this->get_weak_anchor_patterns();

    foreach ($weak_patterns as $pattern) {
      if ($anchor === $pattern || strpos($anchor, $pattern) === 0) {
        return true;
      }
    }
    
    return false;
  }

  private function get_anchor_quality_suggestion($anchor) {
    if (empty($anchor)) {
      return ['quality' => 'bad', 'warning' => 'Empty anchor text - use descriptive text'];
    }
    
    if ($this->has_weak_anchor_text($anchor)) {
      return ['quality' => 'poor', 'warning' => 'Weak anchor text for SEO - use more descriptive text'];
    }
    
    if (strlen($anchor) < 3) {
      return ['quality' => 'poor', 'warning' => 'Anchor text too short (< 3 characters)'];
    }
    
    if (strlen($anchor) > 100) {
      return ['quality' => 'poor', 'warning' => 'Anchor text too long (> 100 characters), use a shorter version'];
    }
    
    return ['quality' => 'good', 'warning' => ''];
  }

  private function get_anchor_quality_label($anchor) {
    $anchorKey = strtolower(trim((string)$anchor));
    if (isset($this->anchor_quality_label_cache[$anchorKey])) {
      return (string)$this->anchor_quality_label_cache[$anchorKey];
    }

    $q = $this->get_anchor_quality_suggestion($anchor);
    $quality = (string)($q['quality'] ?? 'poor');
    if ($quality === 'good' || $quality === 'poor' || $quality === 'bad') {
      $this->anchor_quality_label_cache[$anchorKey] = $quality;
      return $quality;
    }
    $this->anchor_quality_label_cache[$anchorKey] = 'poor';
    return 'poor';
  }

  private function get_anchor_quality_status_help_text() {
    return 'Good = descriptive anchor text (3-100 chars), Poor = weak/generic or length issue, Bad = empty anchor text.';
  }

  /* -----------------------------
   * Audit Trail / Change Log
   * ----------------------------- */

  private function log_audit_trail($action, $post_id, $old_url, $new_url, $old_rel, $new_rel, $changed_count, $status = 'success', $message = '') {
    global $wpdb;
    
    $current_user = wp_get_current_user();
    $user_id = get_current_user_id();
    $user_name = $current_user ? $current_user->display_name : 'unknown';
    
    $wpdb->insert(
      $wpdb->prefix . 'lm_audit_log',
      [
        'user_id' => $user_id,
        'user_name' => $user_name,
        'post_id' => $post_id,
        'action' => $action,
        'old_url' => $old_url,
        'new_url' => $new_url,
        'old_rel' => $old_rel,
        'new_rel' => $new_rel,
        'changed_count' => $changed_count,
        'status' => $status,
        'message' => $message,
      ],
      ['%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s']
    );
  }

  private function get_audit_log($limit = 50) {
    global $wpdb;
    $table = $wpdb->prefix . 'lm_audit_log';
    
    $logs = $wpdb->get_results(
      $wpdb->prepare(
        "SELECT * FROM $table ORDER BY timestamp DESC LIMIT %d",
        $limit
      )
    );
    
    return $logs ?: [];
  }

  private function record_stats_snapshot($stats) {
    global $wpdb;
    $table = $wpdb->prefix . 'lm_stats_log';

    $today = gmdate('Y-m-d');
    $last = (string)get_option('lm_stats_last_date', '');
    if ($last === $today) return;

    $wpdb->replace(
      $table,
      [
        'stat_date' => $today,
        'total_links' => (int)($stats['total_links'] ?? 0),
        'internal_links' => (int)($stats['internal_links'] ?? 0),
        'external_links' => (int)($stats['external_links'] ?? 0),
      ],
      ['%s','%d','%d','%d']
    );

    update_option('lm_stats_last_date', $today);
  }

  private function get_stats_history($days = 14) {
    global $wpdb;
    $table = $wpdb->prefix . 'lm_stats_log';
    $days = max(1, (int)$days);

    $rows = $wpdb->get_results(
      $wpdb->prepare(
        "SELECT stat_date, total_links, internal_links, external_links
         FROM $table
         ORDER BY stat_date DESC
         LIMIT %d",
        $days
      ),
      ARRAY_A
    );

    return array_reverse($rows ?: []);
  }

  private function get_trend_days_from_request() {
    $days = isset($_GET['lm_trend_days']) ? intval($_GET['lm_trend_days']) : 14;
    if (!in_array($days, [7, 14, 30], true)) $days = 14;
    return $days;
  }

  /* -----------------------------
   * Filters + grouping + HTTP attach
   * ----------------------------- */

  private function apply_filters_and_group($all, $filters) {
    $locationFilter = $filters['location'];
    $valueContains  = $filters['value_contains'];
    $sourceContains = isset($filters['source_contains']) ? $filters['source_contains'] : '';
    $anchorContains = $filters['anchor_contains'];
    $altContains    = $filters['alt_contains'];
    $titleContains  = isset($filters['title_contains']) ? $filters['title_contains'] : '';
    $authorContains = isset($filters['author_contains']) ? $filters['author_contains'] : '';
    $publishDateFrom = isset($filters['publish_date_from']) ? (string)$filters['publish_date_from'] : '';
    $publishDateTo = isset($filters['publish_date_to']) ? (string)$filters['publish_date_to'] : '';
    $updatedDateFrom = isset($filters['updated_date_from']) ? (string)$filters['updated_date_from'] : '';
    $updatedDateTo = isset($filters['updated_date_to']) ? (string)$filters['updated_date_to'] : '';
    $sourceTypeFilter = isset($filters['source_type']) ? $filters['source_type'] : 'any';
    $qualityFilter = isset($filters['quality']) ? $filters['quality'] : 'any';
    $seoFlagFilter = isset($filters['seo_flag']) ? $filters['seo_flag'] : 'any';
    $textMode = isset($filters['text_match_mode']) ? $this->sanitize_text_match_mode($filters['text_match_mode']) : 'contains';
    $linkTypeFilter = $filters['link_type'];
    $valueType      = $filters['value_type'];
    $postCategoryFilter = isset($filters['post_category']) ? (int)$filters['post_category'] : 0;
    $postTagFilter = isset($filters['post_tag']) ? (int)$filters['post_tag'] : 0;
    $allowedPostIds = $this->get_post_ids_by_post_terms($postCategoryFilter, $postTagFilter);

    $relNofollow = $filters['rel_nofollow'];
    $relSponsored = $filters['rel_sponsored'];
    $relUgc = $filters['rel_ugc'];
    $relContains = $filters['rel_contains'];

    $hasValueContains = $valueContains !== '';
    $hasSourceContains = $sourceContains !== '';
    $hasTitleContains = $titleContains !== '';
    $hasAuthorContains = $authorContains !== '';
    $hasAnchorContains = $anchorContains !== '';
    $hasAltContains = $altContains !== '';
    $hasRelContains = $relContains !== '';
    $hasPublishDateFrom = $publishDateFrom !== '';
    $hasPublishDateTo = $publishDateTo !== '';
    $hasUpdatedDateFrom = $updatedDateFrom !== '';
    $hasUpdatedDateTo = $updatedDateTo !== '';

    $filtered = [];
    foreach ($all as $row) {
      if (is_array($allowedPostIds)) {
        $rowPostId = isset($row['post_id']) ? (string)intval($row['post_id']) : '';
        if ($rowPostId === '' || !isset($allowedPostIds[$rowPostId])) continue;
      }

      if ($locationFilter !== 'any' && $row['link_location'] !== $locationFilter) continue;
      if ($sourceTypeFilter !== 'any' && (string)$row['source'] !== (string)$sourceTypeFilter) continue;
      if ($linkTypeFilter !== 'any' && $row['link_type'] !== $linkTypeFilter) continue;
      if ($valueType !== 'any' && $row['value_type'] !== $valueType) continue;

      if ($hasValueContains && !$this->text_matches((string)$row['link'], $valueContains, $textMode)) {
        continue;
      }
      if ($hasSourceContains && !$this->text_matches((string)$row['page_url'], $sourceContains, $textMode)) continue;
      if ($hasTitleContains && !$this->text_matches((string)$row['post_title'], $titleContains, $textMode)) continue;
      if ($hasAuthorContains && !$this->text_matches((string)$row['post_author'], $authorContains, $textMode)) continue;

      $postDate = substr((string)($row['post_date'] ?? ''), 0, 10);
      if ($hasPublishDateFrom && ($postDate === '' || $postDate < $publishDateFrom)) continue;
      if ($hasPublishDateTo && ($postDate === '' || $postDate > $publishDateTo)) continue;

      $postUpdatedDate = substr((string)($row['post_modified'] ?? ''), 0, 10);
      if ($hasUpdatedDateFrom && ($postUpdatedDate === '' || $postUpdatedDate < $updatedDateFrom)) continue;
      if ($hasUpdatedDateTo && ($postUpdatedDate === '' || $postUpdatedDate > $updatedDateTo)) continue;

      if ($hasAnchorContains && !$this->text_matches((string)$row['anchor_text'], $anchorContains, $textMode)) {
        continue;
      }
      if ($hasAltContains && !$this->text_matches((string)$row['alt_text'], $altContains, $textMode)) continue;

      if ($hasRelContains) {
        $flags = [];
        if (($row['rel_nofollow'] ?? '0') === '1') $flags[] = 'nofollow';
        if (($row['rel_sponsored'] ?? '0') === '1') $flags[] = 'sponsored';
        if (($row['rel_ugc'] ?? '0') === '1') $flags[] = 'ugc';
        $relText = !empty($flags) ? implode(', ', $flags) : 'dofollow';
        if (!$this->text_matches($relText, $relContains, $textMode)) continue;
      }

      if ($relNofollow !== 'any' && $row['rel_nofollow'] !== ($relNofollow === '1' ? '1' : '0')) continue;
      if ($relSponsored !== 'any' && $row['rel_sponsored'] !== ($relSponsored === '1' ? '1' : '0')) continue;
      if ($relUgc !== 'any' && $row['rel_ugc'] !== ($relUgc === '1' ? '1' : '0')) continue;

      if ($qualityFilter !== 'any') {
        $quality = $this->get_anchor_quality_label((string)($row['anchor_text'] ?? ''));
        if ((string)$quality !== (string)$qualityFilter) continue;
      }

      if ($seoFlagFilter !== 'any') {
        $nofollow = isset($row['rel_nofollow']) && (string)$row['rel_nofollow'] === '1';
        $sponsored = isset($row['rel_sponsored']) && (string)$row['rel_sponsored'] === '1';
        $ugc = isset($row['rel_ugc']) && (string)$row['rel_ugc'] === '1';

        if ($seoFlagFilter === 'dofollow' && ($nofollow || $sponsored || $ugc)) continue;
        if ($seoFlagFilter === 'nofollow' && !$nofollow) continue;
        if ($seoFlagFilter === 'sponsored' && !$sponsored) continue;
        if ($seoFlagFilter === 'ugc' && !$ugc) continue;
      }

      $filtered[] = $row;
    }

    if ($filters['group'] === '1') {
      $map = [];
      foreach ($filtered as $r) {
        // NOTE: grouping loses "single occurrence" meaning. Still allowed as a view option.
        $k = $r['page_url'] . '|' . $r['link'] . '|' . $r['source'] . '|' . $r['link_location'];
        if (!isset($map[$k])) { $r['count'] = 1; $map[$k] = $r; }
        else $map[$k]['count']++;
      }
      $filtered = array_values($map);
    }

    $orderby = isset($filters['orderby']) ? $filters['orderby'] : 'date';
    $order = isset($filters['order']) ? $filters['order'] : 'DESC';
    if (in_array($orderby, ['date','title','post_type','post_author','page_url','link','source','link_location','anchor_text','quality','link_type','seo_flags','alt_text','count'], true)) {
      $dir = ($order === 'ASC') ? 1 : -1;
      usort($filtered, function($a, $b) use ($orderby, $dir) {
        $numericSort = false;
        switch ($orderby) {
          case 'title':
            $va = (string)($a['post_title'] ?? '');
            $vb = (string)($b['post_title'] ?? '');
            break;
          case 'post_type':
            $va = (string)($a['post_type'] ?? '');
            $vb = (string)($b['post_type'] ?? '');
            break;
          case 'post_author':
            $va = (string)($a['post_author'] ?? '');
            $vb = (string)($b['post_author'] ?? '');
            break;
          case 'page_url':
            $va = (string)($a['page_url'] ?? '');
            $vb = (string)($b['page_url'] ?? '');
            break;
          case 'link':
            $va = (string)($a['link'] ?? '');
            $vb = (string)($b['link'] ?? '');
            break;
          case 'source':
            $va = (string)($a['source'] ?? '');
            $vb = (string)($b['source'] ?? '');
            break;
          case 'link_location':
            $va = (string)($a['link_location'] ?? '');
            $vb = (string)($b['link_location'] ?? '');
            break;
          case 'anchor_text':
            $va = (string)($a['anchor_text'] ?? '');
            $vb = (string)($b['anchor_text'] ?? '');
            break;
          case 'quality':
            $va = $this->get_anchor_quality_label((string)($a['anchor_text'] ?? ''));
            $vb = $this->get_anchor_quality_label((string)($b['anchor_text'] ?? ''));
            break;
          case 'link_type':
            $va = (string)($a['link_type'] ?? '');
            $vb = (string)($b['link_type'] ?? '');
            break;
          case 'seo_flags':
            $af = [];
            if ((string)($a['rel_nofollow'] ?? '0') === '1') $af[] = 'nofollow';
            if ((string)($a['rel_sponsored'] ?? '0') === '1') $af[] = 'sponsored';
            if ((string)($a['rel_ugc'] ?? '0') === '1') $af[] = 'ugc';
            $bf = [];
            if ((string)($b['rel_nofollow'] ?? '0') === '1') $bf[] = 'nofollow';
            if ((string)($b['rel_sponsored'] ?? '0') === '1') $bf[] = 'sponsored';
            if ((string)($b['rel_ugc'] ?? '0') === '1') $bf[] = 'ugc';
            $va = !empty($af) ? implode(', ', $af) : 'dofollow';
            $vb = !empty($bf) ? implode(', ', $bf) : 'dofollow';
            break;
          case 'alt_text':
            $va = (string)($a['alt_text'] ?? '');
            $vb = (string)($b['alt_text'] ?? '');
            break;
          case 'count':
            $va = (int)($a['count'] ?? 0);
            $vb = (int)($b['count'] ?? 0);
            $numericSort = true;
            break;
          case 'date':
          default:
            $va = (string)($a['post_date'] ?? '');
            $vb = (string)($b['post_date'] ?? '');
            break;
        }
        if ($numericSort) {
          $cmp = ((int)$va <=> (int)$vb);
        } else {
          $cmp = strcmp((string)$va, (string)$vb);
        }
        if ($cmp === 0) {
          $cmp = strcmp((string)($a['post_title'] ?? ''), (string)($b['post_title'] ?? ''));
        }
        if ($cmp === 0) {
          foreach (['post_id', 'source', 'link_location', 'block_index', 'occurrence', 'link', 'row_id'] as $tieKey) {
            $cmp = strcmp((string)($a[$tieKey] ?? ''), (string)($b[$tieKey] ?? ''));
            if ($cmp !== 0) {
              break;
            }
          }
        }
        return $cmp * $dir;
      });
    }

    return $filtered;
  }

  /* -----------------------------
   * Precise update helpers
   * ----------------------------- */

  private function update_single_occurrence_in_html($html, $old_link, $occurrence, $new_link, $new_rel = '', $new_anchor = null, $page_url = '') {
    if (!is_string($html) || trim($html) === '') return ['html' => $html, 'changed' => 0, 'error' => 'empty_html'];

    $oldC = $this->normalize_for_compare($old_link);
    $new_link = trim((string)$new_link);
    $new_rel  = trim((string)$new_rel);

    if ($oldC === '' || $new_link === '') return ['html' => $html, 'changed' => 0, 'error' => 'invalid_input'];
    $targetOcc = (int)$occurrence;
    if ($targetOcc < 0) $targetOcc = 0;

    // Count links using the same global anchor order used when row_id/occurrence is generated.
    $linkIndex = 0;
    $matchedTotal = 0;
    $changed = 0;

    $pattern = '/<a\b[^>]*>.*?<\/a>/is';
    $contextPageUrl = (string)$page_url;
    $self = $this;
    $newHtml = preg_replace_callback($pattern, function($m) use ($oldC, $new_link, $new_rel, $new_anchor, $targetOcc, &$linkIndex, &$matchedTotal, &$changed, $self, $contextPageUrl) {
      $full = $m[0];
      $currentIndex = $linkIndex;
      $linkIndex++;

      if (!preg_match('/\bhref\s*=\s*(["\'])(.*?)\1/i', $full, $hm)) return $full;

      $quote = $hm[1];
      $hrefRaw = $hm[2];
      $hrefComparable = $hrefRaw;
      if ($contextPageUrl !== '') {
        $hrefComparable = $self->resolve_to_absolute($hrefRaw, $contextPageUrl);
      }
      $hrefNorm = (string)$self->normalize_for_compare($hrefComparable);

      if ($hrefNorm !== '' && $hrefNorm === $oldC) {
        $matchedTotal++;
      }

      // row_id occurrence is based on the global link index inside the source HTML.
      if ($currentIndex !== $targetOcc) {
        return $full;
      }

      if ($hrefNorm === '' || $hrefNorm !== $oldC) {
        return $full;
      }

      if (!preg_match('/^(<a\b[^>]*>)(.*)(<\/a>)$/is', $full, $parts)) return $full;

      $openTag = $parts[1];
      $innerHtml = $parts[2];
      $closeTag = $parts[3];

      // Replace href
      $tag2 = preg_replace('/\bhref\s*=\s*(["\'])(.*?)\1/i', 'href=' . $quote . esc_attr($new_link) . $quote, $openTag, 1);

      // Overwrite rel if provided
      if ($new_rel !== '') {
        if (preg_match('/\brel\s*=\s*(["\'])(.*?)\1/i', $tag2)) {
          $tag2 = preg_replace('/\brel\s*=\s*(["\'])(.*?)\1/i', 'rel=' . $quote . esc_attr($new_rel) . $quote, $tag2, 1);
        } else {
          $tag2 = rtrim($tag2, '>');
          $tag2 .= ' rel=' . $quote . esc_attr($new_rel) . $quote . '>';
        }
      }

      $newInner = $innerHtml;
      if ($new_anchor !== null) {
        $newInner = esc_html((string)$new_anchor);
      }

      $changed = 1;
      return $tag2 . $newInner . $closeTag;
    }, $html);

    if ($newHtml === null) $newHtml = $html;

    // Fallback: occurrence miss, but exactly one URL match exists -> update that single candidate.
    if ($changed !== 1 && $matchedTotal === 1) {
      $fallbackMatchSeen = false;
      $newHtmlFallback = preg_replace_callback($pattern, function($m) use ($oldC, $new_link, $new_rel, $new_anchor, &$fallbackMatchSeen, &$changed, $self, $contextPageUrl) {
        $full = $m[0];

        if (!preg_match('/\bhref\s*=\s*(["\'])(.*?)\1/i', $full, $hm)) return $full;

        $quote = $hm[1];
        $hrefRaw = $hm[2];
        $hrefComparable = $hrefRaw;
        if ($contextPageUrl !== '') {
          $hrefComparable = $self->resolve_to_absolute($hrefRaw, $contextPageUrl);
        }
        $hrefNorm = (string)$self->normalize_for_compare($hrefComparable);
        if ($hrefNorm === '' || $hrefNorm !== $oldC) return $full;

        if ($fallbackMatchSeen) {
          return $full;
        }
        $fallbackMatchSeen = true;

        if (!preg_match('/^(<a\b[^>]*>)(.*)(<\/a>)$/is', $full, $parts)) return $full;

        $openTag = $parts[1];
        $innerHtml = $parts[2];
        $closeTag = $parts[3];

        $tag2 = preg_replace('/\bhref\s*=\s*(["\'])(.*?)\1/i', 'href=' . $quote . esc_attr($new_link) . $quote, $openTag, 1);

        if ($new_rel !== '') {
          if (preg_match('/\brel\s*=\s*(["\'])(.*?)\1/i', $tag2)) {
            $tag2 = preg_replace('/\brel\s*=\s*(["\'])(.*?)\1/i', 'rel=' . $quote . esc_attr($new_rel) . $quote, $tag2, 1);
          } else {
            $tag2 = rtrim($tag2, '>');
            $tag2 .= ' rel=' . $quote . esc_attr($new_rel) . $quote . '>';
          }
        }

        $newInner = $innerHtml;
        if ($new_anchor !== null) {
          $newInner = esc_html((string)$new_anchor);
        }

        $changed = 1;
        return $tag2 . $newInner . $closeTag;
      }, $html);

      if ($newHtmlFallback !== null) {
        $newHtml = $newHtmlFallback;
      }
    }

    return ['html' => $newHtml, 'changed' => $changed, 'error' => ''];
  }

  private function update_post_by_context($post_id, $old_link, $source, $location, $block_index, $occurrence, $new_link, $new_rel = '', $new_anchor = null) {
    $source = (string)$source;
    $location = (string)$location;
    $block_index = (string)$block_index;

    if ($source === 'menu') {
      if (strpos($location, 'menu:') !== 0) {
        return ['ok' => false, 'msg' => 'Invalid menu target.'];
      }

      $menuName = trim(substr($location, 5));
      if ($menuName === '') {
        return ['ok' => false, 'msg' => 'Menu name is empty.'];
      }

      $item = null;
      $menuTermId = 0;

      // Preferred deterministic path: block_index carries menu item identity.
      if (strpos($block_index, 'menu_item:') === 0) {
        $itemId = (int)substr($block_index, strlen('menu_item:'));
        if ($itemId > 0) {
          $candidate = wp_setup_nav_menu_item(get_post($itemId));
          if ($candidate && !empty($candidate->ID)) {
            $item = $candidate;
            $menuTerms = wp_get_object_terms((int)$candidate->ID, 'nav_menu', ['fields' => 'ids']);
            if (is_array($menuTerms) && !empty($menuTerms)) {
              $menuTermId = (int)reset($menuTerms);
            }
          }
        }
      }

      // Backward compatibility path for old cache/CSV rows.
      if (!$item) {
        $menu = null;
        $menus = wp_get_nav_menus();
        if (is_array($menus)) {
          foreach ($menus as $candidate) {
            if ((string)($candidate->name ?? '') === $menuName) {
              $menu = $candidate;
              break;
            }
          }
        }
        if (!$menu || empty($menu->term_id)) {
          return ['ok' => false, 'msg' => 'Menu not found.'];
        }

        $menuTermId = (int)$menu->term_id;
        $items = wp_get_nav_menu_items($menuTermId);
        if (empty($items) || !is_array($items)) {
          return ['ok' => false, 'msg' => 'Menu has no items.'];
        }

        $occ = (int)$occurrence;
        if ($occ < 0 || !isset($items[$occ])) {
          return ['ok' => false, 'msg' => 'Menu target changed. Rebuild and try again.'];
        }

        $item = $items[$occ];
      }

      if (!$item || empty($item->ID)) {
        return ['ok' => false, 'msg' => 'Menu item not found.'];
      }
      if ($menuTermId <= 0) {
        return ['ok' => false, 'msg' => 'Menu context not found.'];
      }
      $itemUrlRaw = isset($item->url) ? (string)$item->url : '';
      $itemResolved = $this->normalize_url($this->resolve_to_absolute($itemUrlRaw, home_url('/')));
      if ($this->normalize_for_compare($itemResolved) !== $this->normalize_for_compare($old_link)) {
        return ['ok' => false, 'msg' => 'Target link not found in menu (menu changed?)'];
      }

      $classes = [];
      if (isset($item->classes) && is_array($item->classes)) {
        foreach ($item->classes as $className) {
          $className = trim((string)$className);
          if ($className !== '') {
            $classes[] = $className;
          }
        }
      }

      $updatedItemId = wp_update_nav_menu_item($menuTermId, (int)$item->ID, [
        'menu-item-db-id' => (int)$item->ID,
        'menu-item-object-id' => (int)($item->object_id ?? 0),
        'menu-item-object' => (string)($item->object ?? ''),
        'menu-item-parent-id' => (int)($item->menu_item_parent ?? 0),
        'menu-item-position' => (int)($item->menu_order ?? 0),
        'menu-item-type' => (string)($item->type ?? 'custom'),
        'menu-item-title' => $new_anchor !== null ? (string)$new_anchor : (string)($item->title ?? ''),
        'menu-item-url' => (string)$new_link,
        'menu-item-description' => (string)($item->description ?? ''),
        'menu-item-attr-title' => isset($item->attr_title) ? (string)$item->attr_title : '',
        'menu-item-target' => (string)($item->target ?? ''),
        'menu-item-classes' => implode(' ', $classes),
        'menu-item-xfn' => (string)($item->xfn ?? ''),
        'menu-item-status' => (string)($item->post_status ?? 'publish'),
      ]);

      if (is_wp_error($updatedItemId)) {
        return ['ok' => false, 'msg' => $updatedItemId->get_error_message()];
      }

      return ['ok' => true, 'msg' => 'Successfully updated 1 link in menu.'];
    }

    $post = get_post($post_id);
    if (!$post) return ['ok' => false, 'msg' => 'Post not found'];
    $pageUrl = get_permalink($post_id);

    // content
    if ($source === 'content') {
      $content = (string)$post->post_content;
      $blocks = function_exists('parse_blocks') ? parse_blocks($content) : [];

      // If no blocks, treat as classic
      if (empty($blocks)) {
        $res = $this->update_single_occurrence_in_html($content, $old_link, $occurrence, $new_link, $new_rel, $new_anchor, (string)$pageUrl);
        if ($res['changed'] !== 1) {
          return ['ok' => false, 'msg' => 'Target link not found (content changed?)'];
        }
        $u = wp_update_post(['ID' => $post_id, 'post_content' => $res['html']], true);
        if (is_wp_error($u)) return ['ok' => false, 'msg' => $u->get_error_message()];
        return ['ok' => true, 'msg' => 'Successfully updated 1 link in content.'];
      }

      $idx = (int)$block_index;
      if (!isset($blocks[$idx])) return ['ok' => false, 'msg' => 'Invalid block index (content changed?)'];

      // Optional guard: blockName should match location
      $bn = isset($blocks[$idx]['blockName']) && $blocks[$idx]['blockName'] ? $blocks[$idx]['blockName'] : 'classic';
      if ($location !== $bn) {
        // allow but warn via failure for safety
        return ['ok' => false, 'msg' => 'Block target changed (content updated). Rebuild and try again.'];
      }

      // Update block HTML (best-effort: innerHTML > innerContent join)
      $block = $blocks[$idx];
      $html = '';
      $mode = '';

      if (isset($block['innerHTML']) && is_string($block['innerHTML']) && trim($block['innerHTML']) !== '') {
        $html = $block['innerHTML'];
        $mode = 'innerHTML';
      } elseif (isset($block['innerContent']) && is_array($block['innerContent'])) {
        $html = implode('', array_map('strval', $block['innerContent']));
        $mode = 'innerContent';
      } else {
        // last resort: update rendered HTML won't persist; fail-safe
        return ['ok' => false, 'msg' => 'Block has no editable HTML (fail-safe).'];
      }

      $res = $this->update_single_occurrence_in_html($html, $old_link, $occurrence, $new_link, $new_rel, $new_anchor, (string)$pageUrl);
      if ($res['changed'] !== 1) {
        return ['ok' => false, 'msg' => 'Target link not found in block (content changed?)'];
      }

      if ($mode === 'innerHTML') {
        $blocks[$idx]['innerHTML'] = $res['html'];
        // Keep a minimal sync
        $blocks[$idx]['innerContent'] = [$res['html']];
      } else {
        $blocks[$idx]['innerContent'] = [$res['html']];
        $blocks[$idx]['innerHTML'] = $res['html'];
      }

      if (!function_exists('serialize_blocks')) {
        return ['ok' => false, 'msg' => 'serialize_blocks not available.'];
      }

      $newContent = serialize_blocks($blocks);
      $u = wp_update_post(['ID' => $post_id, 'post_content' => $newContent], true);
      if (is_wp_error($u)) return ['ok' => false, 'msg' => $u->get_error_message()];

      return ['ok' => true, 'msg' => 'Successfully updated 1 link in block content.'];
    }

    // excerpt
    if ($source === 'excerpt') {
      $excerpt = (string)$post->post_excerpt;
      $res = $this->update_single_occurrence_in_html($excerpt, $old_link, $occurrence, $new_link, $new_rel, $new_anchor, (string)$pageUrl);
      if ($res['changed'] !== 1) return ['ok' => false, 'msg' => 'Target link not found in excerpt (content changed?)'];
      $u = wp_update_post(['ID' => $post_id, 'post_excerpt' => $res['html']], true);
      if (is_wp_error($u)) return ['ok' => false, 'msg' => $u->get_error_message()];
      return ['ok' => true, 'msg' => 'Successfully updated 1 link in excerpt.'];
    }

    // meta
    if ($source === 'meta') {
      // location looks like meta:KEY
      if (strpos($location, 'meta:') !== 0) return ['ok' => false, 'msg' => 'Invalid meta key.'];
      $key = substr($location, 5);
      if ($key === '') return ['ok' => false, 'msg' => 'Meta key is empty.'];

      // Only allow keys from whitelist for safety
      $meta_keys = apply_filters('lm_scan_meta_keys', []);
      $meta_keys = array_values(array_unique(array_filter(array_map('strval', (array)$meta_keys))));
      if (!in_array($key, $meta_keys, true)) return ['ok' => false, 'msg' => 'Meta key not allowed (whitelist).'];

      $val = get_post_meta($post_id, $key, true);
      if (!is_string($val)) return ['ok' => false, 'msg' => 'Meta value is not a string.'];

      $res = $this->update_single_occurrence_in_html($val, $old_link, $occurrence, $new_link, $new_rel, $new_anchor, (string)$pageUrl);
      if ($res['changed'] !== 1) return ['ok' => false, 'msg' => 'Target link not found in meta (content changed?)'];

      update_post_meta($post_id, $key, $res['html']);
      return ['ok' => true, 'msg' => 'Successfully updated 1 link in meta.'];
    }

    return ['ok' => false, 'msg' => 'Source not supported for update.'];
  }

  /* -----------------------------
   * Actions: per-row update
   * ----------------------------- */

  public function handle_update_link() {
    if (!$this->current_user_can_access_plugin()) wp_die('Unauthorized');

    $nonce = isset($_POST[self::NONCE_NAME]) ? sanitize_text_field($_POST[self::NONCE_NAME]) : '';
    if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) wp_die('Invalid nonce');

    $filters = $this->get_filters_from_request();

    $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
    $old_link = isset($_POST['old_link']) ? sanitize_text_field((string)$_POST['old_link']) : '';
    $new_link = isset($_POST['new_link']) ? esc_url_raw((string)$_POST['new_link']) : '';
    $new_rel  = isset($_POST['new_rel']) ? sanitize_text_field((string)$_POST['new_rel']) : '';
    $old_anchor = isset($_POST['old_anchor']) ? sanitize_text_field((string)$_POST['old_anchor']) : '';
    $new_anchor_raw = array_key_exists('new_anchor', $_POST) ? (string)$_POST['new_anchor'] : null;
    $new_anchor = $this->normalize_new_anchor_input($new_anchor_raw, $old_anchor);
    $old_snippet = isset($_POST['old_snippet']) ? sanitize_text_field((string)$_POST['old_snippet']) : '';

    if ($new_anchor !== null && $filters['anchor_contains'] !== '') {
      $anchorFilter = $filters['anchor_contains'];
      $textMode = isset($filters['text_match_mode']) ? $this->sanitize_text_match_mode($filters['text_match_mode']) : 'contains';
      $matchesFilter = $this->text_matches((string)$new_anchor, $anchorFilter, $textMode);

      if (!$matchesFilter) {
        $filters['anchor_contains'] = $new_anchor;
      }
    }

    $source = isset($_POST['source']) ? sanitize_text_field((string)$_POST['source']) : '';
    $location = isset($_POST['link_location']) ? sanitize_text_field((string)$_POST['link_location']) : '';
    $block_index = isset($_POST['block_index']) ? sanitize_text_field((string)$_POST['block_index']) : '';
    $occurrence = isset($_POST['occurrence']) ? intval($_POST['occurrence']) : 0;

    if (!$this->current_user_can_edit_link_target($post_id, $source)) {
      wp_die('Unauthorized');
    }

    $row_id = isset($_POST['row_id']) ? sanitize_text_field((string)$_POST['row_id']) : '';

    $has_change = ($new_link !== '') || ($new_rel !== '') || ($new_anchor !== null);
    $effective_new_link = $new_link !== '' ? $new_link : $old_link;

    $isMenuSource = ($source === 'menu');

    if ((!$isMenuSource && $post_id <= 0) || $old_link === '' || $effective_new_link === '' || $source === '' || $location === '' || $row_id === '' || !$has_change) {
      $msg = 'Failed: incomplete input.';
      if (!$has_change) {
        $msg = 'Failed: no changes provided.';
      }
      if ($row_id === '' && $post_id > 0 && $old_link !== '') {
        $msg .= ' Cache needs rebuild. Check "Rebuild cache" and click "Apply Filters".';
      }
      $this->safe_redirect_back($filters, ['lm_msg' => $msg]);
    }

    // Guard: ensure row_id matches expected
    $rowIdPostId = $isMenuSource ? '' : (string)$post_id;
    $expected = $this->row_id($rowIdPostId, $source, $location, $block_index, $occurrence, $this->normalize_for_compare($old_link));
    if ($expected !== $row_id) {
      $this->safe_redirect_back($filters, ['lm_msg' => 'Failed: Row ID mismatch (data changed). Rebuild & try again.']);
    }

    $res = $this->update_post_by_context($post_id, $old_link, $source, $location, $block_index, $occurrence, $effective_new_link, $new_rel, $new_anchor);

    $this->clear_cache_all();
    $filters['rebuild'] = true;
    
    // Log audit trail
    $old_rel = isset($_POST['old_rel']) ? sanitize_text_field((string)$_POST['old_rel']) : '';
    $this->log_audit_trail(
      'update_single',
      $post_id,
      $old_link,
       $effective_new_link,
      $old_rel,
       $new_rel,
      $res['ok'] ? 1 : 0,
      $res['ok'] ? 'success' : 'failed',
      $res['msg']
    );

    $msg = $res['ok'] ? ('Success: ' . $res['msg']) : ('Failed: ' . $res['msg']);
    $this->safe_redirect_back($filters, ['lm_msg' => $msg]);
  }

  public function handle_update_link_ajax() {
    if (!$this->current_user_can_access_plugin()) {
      wp_send_json_error(['msg' => 'Unauthorized'], 403);
    }

    $nonce = isset($_POST[self::NONCE_NAME]) ? sanitize_text_field($_POST[self::NONCE_NAME]) : '';
    if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) {
      wp_send_json_error(['msg' => 'Invalid nonce'], 403);
    }

    $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
    $old_link = isset($_POST['old_link']) ? sanitize_text_field((string)$_POST['old_link']) : '';
    $new_link = isset($_POST['new_link']) ? esc_url_raw((string)$_POST['new_link']) : '';
    $new_rel  = isset($_POST['new_rel']) ? sanitize_text_field((string)$_POST['new_rel']) : '';
    $old_anchor = isset($_POST['old_anchor']) ? sanitize_text_field((string)$_POST['old_anchor']) : '';
    $new_anchor_raw = array_key_exists('new_anchor', $_POST) ? (string)$_POST['new_anchor'] : null;
    $new_anchor = $this->normalize_new_anchor_input($new_anchor_raw, $old_anchor);
    $old_snippet = isset($_POST['old_snippet']) ? sanitize_text_field((string)$_POST['old_snippet']) : '';

    $source = isset($_POST['source']) ? sanitize_text_field((string)$_POST['source']) : '';
    $location = isset($_POST['link_location']) ? sanitize_text_field((string)$_POST['link_location']) : '';
    $block_index = isset($_POST['block_index']) ? sanitize_text_field((string)$_POST['block_index']) : '';
    $occurrence = isset($_POST['occurrence']) ? intval($_POST['occurrence']) : 0;
    $row_id = isset($_POST['row_id']) ? sanitize_text_field((string)$_POST['row_id']) : '';

    if (!$this->current_user_can_edit_link_target($post_id, $source)) {
      wp_send_json_error(['msg' => 'Unauthorized'], 403);
    }

    $has_change = ($new_link !== '') || ($new_rel !== '') || ($new_anchor !== null);
    $effective_new_link = $new_link !== '' ? $new_link : $old_link;
    $isMenuSource = ($source === 'menu');

    if ((!$isMenuSource && $post_id <= 0) || $old_link === '' || $effective_new_link === '' || $source === '' || $location === '' || $row_id === '' || !$has_change) {
      $msg = 'Failed: incomplete input.';
      if (!$has_change) $msg = 'Failed: no changes provided.';
      wp_send_json_error(['msg' => $msg], 400);
    }

    $rowIdPostId = $isMenuSource ? '' : (string)$post_id;
    $expected = $this->row_id($rowIdPostId, $source, $location, $block_index, $occurrence, $this->normalize_for_compare($old_link));
    if ($expected !== $row_id) {
      wp_send_json_error(['msg' => 'Failed: Row ID mismatch (data changed).'], 409);
    }

    $res = $this->update_post_by_context($post_id, $old_link, $source, $location, $block_index, $occurrence, $effective_new_link, $new_rel, $new_anchor);
    $this->clear_cache_all();

    $old_rel = isset($_POST['old_rel']) ? sanitize_text_field((string)$_POST['old_rel']) : '';
    $this->log_audit_trail(
      'update_single',
      $post_id,
      $old_link,
      $effective_new_link,
      $old_rel,
      $new_rel,
      $res['ok'] ? 1 : 0,
      $res['ok'] ? 'success' : 'failed',
      $res['msg']
    );

    $effective_rel_raw = $new_rel !== '' ? $new_rel : $old_rel;
    $effective_flags = $this->parse_rel_flags($effective_rel_raw);
    $effective_rel_parts = [];
    if ($effective_flags['nofollow']) $effective_rel_parts[] = 'nofollow';
    if ($effective_flags['sponsored']) $effective_rel_parts[] = 'sponsored';
    if ($effective_flags['ugc']) $effective_rel_parts[] = 'ugc';
    $effective_rel_text = !empty($effective_rel_parts) ? implode(', ', $effective_rel_parts) : 'dofollow';

    $anchor_quality = $this->get_anchor_quality_suggestion($new_anchor !== null ? $new_anchor : $old_anchor);
    $anchor_quality_label = 'Good';
    if ((string)($anchor_quality['quality'] ?? '') === 'poor') $anchor_quality_label = 'Poor';
    if ((string)($anchor_quality['quality'] ?? '') === 'bad') $anchor_quality_label = 'Bad';

    $effective_anchor = $new_anchor !== null ? $new_anchor : $old_anchor;
    $updated_snippet_full = $old_snippet;
    if ($updated_snippet_full !== '' && $old_anchor !== '' && $effective_anchor !== '' && $effective_anchor !== $old_anchor) {
      $quoted_old_anchor = preg_quote($old_anchor, '/');
      $updated_snippet_full = preg_replace_callback('/' . $quoted_old_anchor . '/iu', function() use ($effective_anchor) {
        return $effective_anchor;
      }, $updated_snippet_full, 1);
    }
    $updated_snippet_display = $this->text_snippet_with_anchor_offset($updated_snippet_full, $effective_anchor, 60, 4);

    $response = [
      'msg' => $res['msg'],
      'updated_link' => $effective_new_link,
      'updated_anchor' => $effective_anchor,
      'updated_rel_raw' => $effective_rel_raw,
      'updated_rel_text' => $effective_rel_text,
      'updated_quality' => $anchor_quality_label,
      'updated_snippet_full' => $updated_snippet_full,
      'updated_snippet_display' => $updated_snippet_display,
    ];

    if ($res['ok']) {
      wp_send_json_success($response);
    }
    wp_send_json_error($response, 400);
  }

  /* -----------------------------
   * Actions: bulk update CSV (single occurrence)
   * ----------------------------- */

  public function handle_bulk_update() {
    if (!$this->current_user_can_access_plugin()) wp_die('Unauthorized');

    $nonce = isset($_POST[self::NONCE_NAME]) ? sanitize_text_field($_POST[self::NONCE_NAME]) : '';
    if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) wp_die('Invalid nonce');

    $filters = $this->get_filters_from_request();

    if (empty($_FILES['lm_csv']) || !is_array($_FILES['lm_csv'])) {
      $this->safe_redirect_back($filters, ['lm_msg' => 'Failed: CSV file not found.']);
    }

    $file = $_FILES['lm_csv'];
    $uploadError = isset($file['error']) ? (int)$file['error'] : UPLOAD_ERR_NO_FILE;
    if ($uploadError !== UPLOAD_ERR_OK) {
      $this->safe_redirect_back($filters, ['lm_msg' => 'Failed: upload error.']);
    }

    $originalName = isset($file['name']) ? sanitize_file_name((string)$file['name']) : '';
    $fileType = wp_check_filetype($originalName);
    $ext = strtolower((string)($fileType['ext'] ?? ''));
    if ($ext !== 'csv') {
      $this->safe_redirect_back($filters, ['lm_msg' => 'Failed: file must be CSV (.csv).']);
    }

    $tmp = isset($file['tmp_name']) ? (string)$file['tmp_name'] : '';
    if ($tmp === '' || !is_uploaded_file($tmp)) {
      $this->safe_redirect_back($filters, ['lm_msg' => 'Failed: invalid upload temporary file.']);
    }

    $fh = fopen($tmp, 'r');
    if (!$fh) $this->safe_redirect_back($filters, ['lm_msg' => 'Failed: cannot read CSV.']);

    $delimiter = $this->detect_csv_delimiter($tmp);
    $header = fgetcsv($fh, 0, $delimiter);
    if (!$header) { fclose($fh); $this->safe_redirect_back($filters, ['lm_msg' => 'Failed: CSV is empty.']); }

    // Strip UTF-8 BOM from first header cell (common in spreadsheet exports).
    if (isset($header[0])) {
      $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header[0]);
    }

    $header = array_map(function($h){ return strtolower(trim((string)$h)); }, $header);
    $idx = array_flip($header);

    $required = ['post_id','old_link','row_id'];
    foreach ($required as $req) {
      if (!isset($idx[$req])) {
        fclose($fh);
        $this->safe_redirect_back($filters, ['lm_msg' => 'Failed: required header: post_id, old_link, row_id (optional: new_link, new_rel, new_anchor).']);
      }
    }

    // For safety, require these optional context columns if present; otherwise we will re-crawl cache to find them
    $hasSource = isset($idx['source']);
    $hasLocation = isset($idx['link_location']);
    $hasBlockIndex = isset($idx['block_index']);
    $hasOccurrence = isset($idx['occurrence']);

    // If context not provided, we will look up in cached crawl by row_id
    $cacheAny = $this->build_or_get_cache('any', false, isset($filters['wpml_lang']) ? $filters['wpml_lang'] : 'all');
    $rowMap = [];
    foreach ($cacheAny as $r) {
      if (!empty($r['row_id'])) $rowMap[$r['row_id']] = $r;
    }

    $totalRows = 0;
    $ok = 0;
    $fail = 0;

    while (($row = fgetcsv($fh, 0, $delimiter)) !== false) {
      $totalRows++;

      $post_id = intval($row[$idx['post_id']] ?? 0);
      $old_link = sanitize_text_field((string)($row[$idx['old_link']] ?? ''));
      $row_id = sanitize_text_field((string)($row[$idx['row_id']] ?? ''));
      $new_link = isset($idx['new_link']) ? esc_url_raw((string)($row[$idx['new_link']] ?? '')) : '';
      $new_rel = isset($idx['new_rel']) ? sanitize_text_field((string)($row[$idx['new_rel']] ?? '')) : '';
      $new_anchor = array_key_exists('new_anchor', $idx)
        ? $this->normalize_new_anchor_input((string)($row[$idx['new_anchor']] ?? ''), null)
        : null;

      $has_change = ($new_link !== '') || ($new_rel !== '') || ($new_anchor !== null);
      $effective_new_link = $new_link !== '' ? $new_link : $old_link;

      if ($old_link === '' || $row_id === '' || $effective_new_link === '' || !$has_change) { $fail++; continue; }

      $source = '';
      $location = '';
      $block_index = '';
      $occurrence = 0;

      if ($hasSource && $hasLocation && $hasBlockIndex && $hasOccurrence) {
        $source = sanitize_text_field((string)($row[$idx['source']] ?? ''));
        $location = sanitize_text_field((string)($row[$idx['link_location']] ?? ''));
        $block_index = sanitize_text_field((string)($row[$idx['block_index']] ?? ''));
        $occurrence = intval($row[$idx['occurrence']] ?? 0);
      } else {
        if (!isset($rowMap[$row_id])) { $fail++; continue; }
        $found = $rowMap[$row_id];

        // guard post_id + old_link
        $expectedPostId = ((string)$found['source'] === 'menu') ? '' : (string)$post_id;
        if ((string)$found['post_id'] !== $expectedPostId) { $fail++; continue; }
        if ($this->normalize_for_compare((string)$found['link']) !== $this->normalize_for_compare($old_link)) { $fail++; continue; }

        $source = (string)$found['source'];
        $location = (string)$found['link_location'];
        $block_index = (string)$found['block_index'];
        $occurrence = intval($found['occurrence'] ?? 0);
      }

      if ($source !== 'menu' && $post_id <= 0) { $fail++; continue; }

      if (!$this->current_user_can_edit_link_target($post_id, $source)) { $fail++; continue; }

      // guard row_id
      $rowIdPostId = ($source === 'menu') ? '' : (string)$post_id;
      $expected = $this->row_id($rowIdPostId, $source, $location, $block_index, $occurrence, $this->normalize_for_compare($old_link));
      if ($expected !== $row_id) { $fail++; continue; }

      $res = $this->update_post_by_context($post_id, $old_link, $source, $location, $block_index, $occurrence, $effective_new_link, $new_rel, $new_anchor);
      
      // Log each bulk update
      $this->log_audit_trail(
        'update_bulk',
        $post_id,
        $old_link,
        $effective_new_link,
        '',
        $new_rel,
        $res['ok'] ? 1 : 0,
        $res['ok'] ? 'success' : 'failed',
        $res['msg']
      );
      
      if ($res['ok']) $ok++;
      else $fail++;
    }

    fclose($fh);
    $this->clear_cache_all();
    $filters['rebuild'] = true;

    // Bulk update can change URL, rel, and anchor values, which may invalidate the active filter set.
    // Reset restrictive filters so table data remains visible after redirect.
    $filters['post_type'] = 'any';
    $filters['post_category'] = 0;
    $filters['post_tag'] = 0;
    $filters['location'] = 'any';
    $filters['source_type'] = 'any';
    $filters['link_type'] = 'any';
    $filters['value_type'] = 'any';
    $filters['quality'] = 'any';
    $filters['seo_flag'] = 'any';
    $filters['value_contains'] = '';
    $filters['source_contains'] = '';
    $filters['title_contains'] = '';
    $filters['author_contains'] = '';
    $filters['publish_date_from'] = '';
    $filters['publish_date_to'] = '';
    $filters['updated_date_from'] = '';
    $filters['updated_date_to'] = '';
    $filters['anchor_contains'] = '';
    $filters['alt_contains'] = '';
    $filters['rel_contains'] = '';
    $filters['rel_nofollow'] = 'any';
    $filters['rel_sponsored'] = 'any';
    $filters['rel_ugc'] = 'any';
    $filters['group'] = '0';
    $filters['paged'] = 1;

    $this->safe_redirect_back($filters, [
      'lm_msg' => 'Bulk finished. Rows: ' . (int)$totalRows . ' | OK: ' . (int)$ok . ' | Failed: ' . (int)$fail
    ]);
  }

  /* -----------------------------
   * Settings / Links Target actions
   * ----------------------------- */

  public function handle_save_settings() {
    if (!current_user_can('manage_options')) wp_die('Unauthorized');

    $nonce = isset($_POST[self::NONCE_NAME]) ? sanitize_text_field($_POST[self::NONCE_NAME]) : '';
    if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) wp_die('Invalid nonce');

    $settings = $this->get_settings();
    $activeTab = isset($_POST['lm_active_tab']) ? sanitize_key((string)$_POST['lm_active_tab']) : 'performance';
    if (!in_array($activeTab, ['general', 'performance', 'data'], true)) {
      $activeTab = 'performance';
    }

    $allRolesMap = $this->get_all_roles_map();
    $validRoleKeys = array_keys($allRolesMap);
    $postedRoles = isset($_POST['lm_allowed_roles'])
      ? (array)$_POST['lm_allowed_roles']
      : (isset($settings['allowed_roles']) ? (array)$settings['allowed_roles'] : ['administrator']);
    $allowedRoles = [];
    foreach ($postedRoles as $roleKey) {
      $roleKey = sanitize_key((string)$roleKey);
      if ($roleKey !== '' && in_array($roleKey, $validRoleKeys, true)) {
        $allowedRoles[] = $roleKey;
      }
    }
    if (!in_array('administrator', $allowedRoles, true)) {
      $allowedRoles[] = 'administrator';
    }
    $settings['allowed_roles'] = array_values(array_unique($allowedRoles));

    $availablePostTypes = $this->get_available_post_types();
    $postedScanPostTypes = isset($_POST['lm_scan_post_types'])
      ? (array)$_POST['lm_scan_post_types']
      : (isset($settings['scan_post_types']) ? (array)$settings['scan_post_types'] : $this->get_default_scan_post_types($availablePostTypes));
    $settings['scan_post_types'] = $this->sanitize_scan_post_types($postedScanPostTypes, $availablePostTypes);

    $postedScanSourceTypes = isset($_POST['lm_scan_source_types'])
      ? (array)$_POST['lm_scan_source_types']
      : (isset($settings['scan_source_types']) ? (array)$settings['scan_source_types'] : $this->get_default_scan_source_types());
    $settings['scan_source_types'] = $this->sanitize_scan_source_types($postedScanSourceTypes);

    $postedScanValueTypes = isset($_POST['lm_scan_value_types'])
      ? (array)$_POST['lm_scan_value_types']
      : (isset($settings['scan_value_types']) ? (array)$settings['scan_value_types'] : $this->get_default_scan_value_types());
    $settings['scan_value_types'] = $this->sanitize_scan_value_types($postedScanValueTypes);

    $postedScanWpmlLangs = isset($_POST['lm_scan_wpml_langs'])
      ? (array)$_POST['lm_scan_wpml_langs']
      : (isset($settings['scan_wpml_langs']) ? (array)$settings['scan_wpml_langs'] : $this->get_default_scan_wpml_langs());
    $settings['scan_wpml_langs'] = $this->sanitize_scan_wpml_langs($postedScanWpmlLangs);

    $postedScanCategoryIds = isset($_POST['lm_scan_post_category_ids'])
      ? (array)$_POST['lm_scan_post_category_ids']
      : (isset($settings['scan_post_category_ids']) ? (array)$settings['scan_post_category_ids'] : []);
    $settings['scan_post_category_ids'] = $this->sanitize_scan_term_ids($postedScanCategoryIds, 'category');

    $postedScanTagIds = isset($_POST['lm_scan_post_tag_ids'])
      ? (array)$_POST['lm_scan_post_tag_ids']
      : (isset($settings['scan_post_tag_ids']) ? (array)$settings['scan_post_tag_ids'] : []);
    $settings['scan_post_tag_ids'] = $this->sanitize_scan_term_ids($postedScanTagIds, 'post_tag');

    $postedScanAuthorIds = isset($_POST['lm_scan_author_ids'])
      ? (array)$_POST['lm_scan_author_ids']
      : (isset($settings['scan_author_ids']) ? (array)$settings['scan_author_ids'] : []);
    $settings['scan_author_ids'] = $this->sanitize_scan_author_ids($postedScanAuthorIds);

    $scanModifiedWithinDays = isset($_POST['lm_scan_modified_within_days'])
      ? (int)$_POST['lm_scan_modified_within_days']
      : (int)($settings['scan_modified_within_days'] ?? 0);
    if ($scanModifiedWithinDays < 0) $scanModifiedWithinDays = 0;
    if ($scanModifiedWithinDays > 3650) $scanModifiedWithinDays = 3650;
    $settings['scan_modified_within_days'] = (string)$scanModifiedWithinDays;

    $scanExcludePatternsRaw = isset($_POST['lm_scan_exclude_url_patterns'])
      ? (string)wp_unslash($_POST['lm_scan_exclude_url_patterns'])
      : (string)($settings['scan_exclude_url_patterns'] ?? '');
    $settings['scan_exclude_url_patterns'] = implode("\n", $this->normalize_scan_exclude_url_patterns($scanExcludePatternsRaw));

    $maxPostsPerRebuild = isset($_POST['lm_max_posts_per_rebuild'])
      ? (int)$_POST['lm_max_posts_per_rebuild']
      : (int)($settings['max_posts_per_rebuild'] ?? 0);
    if ($maxPostsPerRebuild < 0) $maxPostsPerRebuild = 0;
    if ($maxPostsPerRebuild > 50000) $maxPostsPerRebuild = 50000;
    $settings['max_posts_per_rebuild'] = (string)$maxPostsPerRebuild;

    $refreshPeriodMap = $this->get_stats_refresh_period_minutes_map();
    $refreshPeriodRaw = isset($_POST['lm_stats_snapshot_ttl_period']) ? (string)$_POST['lm_stats_snapshot_ttl_period'] : '';
    $existingStatsMinutes = isset($settings['stats_snapshot_ttl_min']) ? (int)$settings['stats_snapshot_ttl_min'] : (int)(self::STATS_SNAPSHOT_TTL / MINUTE_IN_SECONDS);
    $existingStatsRefresh = $this->get_stats_refresh_value_and_period_from_minutes($existingStatsMinutes);
    $refreshValueRaw = isset($_POST['lm_stats_snapshot_ttl_value']) ? (int)$_POST['lm_stats_snapshot_ttl_value'] : (int)$existingStatsRefresh['value'];
    $refreshPeriod = $this->sanitize_stats_refresh_period($refreshPeriodRaw);
    $refreshValue = $this->sanitize_stats_refresh_value($refreshValueRaw);

    if ($refreshPeriodRaw !== '' && isset($refreshPeriodMap[$refreshPeriod])) {
      $statsSnapshotTtlMin = $refreshValue * (int)$refreshPeriodMap[$refreshPeriod];
      if ($statsSnapshotTtlMin < 1) $statsSnapshotTtlMin = 1;
      if ($statsSnapshotTtlMin > 525600) $statsSnapshotTtlMin = 525600;
    } else {
      // Backward compatibility for older form submissions that still send minutes.
      $statsSnapshotTtlMin = isset($_POST['lm_stats_snapshot_ttl_min']) ? (int)$_POST['lm_stats_snapshot_ttl_min'] : $existingStatsMinutes;
      if ($statsSnapshotTtlMin < 1) $statsSnapshotTtlMin = 1;
      if ($statsSnapshotTtlMin > 525600) $statsSnapshotTtlMin = 525600;
    }
    $settings['stats_snapshot_ttl_min'] = (string)$statsSnapshotTtlMin;

    $cacheRebuildMode = isset($_POST['lm_cache_rebuild_mode']) ? sanitize_key((string)$_POST['lm_cache_rebuild_mode']) : sanitize_key((string)($settings['cache_rebuild_mode'] ?? 'incremental'));
    if (!in_array($cacheRebuildMode, ['incremental', 'full'], true)) {
      $cacheRebuildMode = 'incremental';
    }
    $settings['cache_rebuild_mode'] = $cacheRebuildMode;

    $crawlPostBatch = isset($_POST['lm_crawl_post_batch']) ? (int)$_POST['lm_crawl_post_batch'] : (int)($settings['crawl_post_batch'] ?? self::CRAWL_POST_BATCH);
    if ($crawlPostBatch < 20) $crawlPostBatch = 20;
    $runtimeMaxBatch = $this->get_runtime_max_crawl_batch();
    if ($crawlPostBatch > $runtimeMaxBatch) $crawlPostBatch = $runtimeMaxBatch;
    $settings['crawl_post_batch'] = (string)$crawlPostBatch;

    $inboundOrphanMax = isset($_POST['lm_inbound_orphan_max']) ? (int)$_POST['lm_inbound_orphan_max'] : (int)($settings['inbound_orphan_max'] ?? 0);
    $inboundLowMax = isset($_POST['lm_inbound_low_max']) ? (int)$_POST['lm_inbound_low_max'] : (int)($settings['inbound_low_max'] ?? 5);
    $inboundStandardMax = isset($_POST['lm_inbound_standard_max']) ? (int)$_POST['lm_inbound_standard_max'] : (int)($settings['inbound_standard_max'] ?? 10);
    $thresholds = $this->sanitize_inbound_status_thresholds($inboundOrphanMax, $inboundLowMax, $inboundStandardMax);
    $settings['inbound_orphan_max'] = (string)$thresholds['orphan_max'];
    $settings['inbound_low_max'] = (string)$thresholds['low_max'];
    $settings['inbound_standard_max'] = (string)$thresholds['standard_max'];

    $internalOutboundNoneMax = isset($_POST['lm_internal_outbound_none_max']) ? (int)$_POST['lm_internal_outbound_none_max'] : (int)($settings['internal_outbound_none_max'] ?? 0);
    $internalOutboundLowMax = isset($_POST['lm_internal_outbound_low_max']) ? (int)$_POST['lm_internal_outbound_low_max'] : (int)($settings['internal_outbound_low_max'] ?? 5);
    $internalOutboundOptimalMax = isset($_POST['lm_internal_outbound_optimal_max']) ? (int)$_POST['lm_internal_outbound_optimal_max'] : (int)($settings['internal_outbound_optimal_max'] ?? 10);
    $internalOutboundThresholds = $this->sanitize_four_level_status_thresholds($internalOutboundNoneMax, $internalOutboundLowMax, $internalOutboundOptimalMax);
    $settings['internal_outbound_none_max'] = (string)$internalOutboundThresholds['none_max'];
    $settings['internal_outbound_low_max'] = (string)$internalOutboundThresholds['low_max'];
    $settings['internal_outbound_optimal_max'] = (string)$internalOutboundThresholds['optimal_max'];

    $externalOutboundNoneMax = isset($_POST['lm_external_outbound_none_max']) ? (int)$_POST['lm_external_outbound_none_max'] : (int)($settings['external_outbound_none_max'] ?? 0);
    $externalOutboundLowMax = isset($_POST['lm_external_outbound_low_max']) ? (int)$_POST['lm_external_outbound_low_max'] : (int)($settings['external_outbound_low_max'] ?? 5);
    $externalOutboundOptimalMax = isset($_POST['lm_external_outbound_optimal_max']) ? (int)$_POST['lm_external_outbound_optimal_max'] : (int)($settings['external_outbound_optimal_max'] ?? 10);
    $externalOutboundThresholds = $this->sanitize_four_level_status_thresholds($externalOutboundNoneMax, $externalOutboundLowMax, $externalOutboundOptimalMax);
    $settings['external_outbound_none_max'] = (string)$externalOutboundThresholds['none_max'];
    $settings['external_outbound_low_max'] = (string)$externalOutboundThresholds['low_max'];
    $settings['external_outbound_optimal_max'] = (string)$externalOutboundThresholds['optimal_max'];

    $resetPerformanceDefaults = isset($_POST['lm_reset_performance_defaults']) && (string)$_POST['lm_reset_performance_defaults'] === '1';
    if ($resetPerformanceDefaults) {
      $settings = array_merge($settings, $this->get_recommended_performance_settings());
    }

    $applyLowMemoryProfile = isset($_POST['lm_apply_low_memory_profile']) && (string)$_POST['lm_apply_low_memory_profile'] === '1';
    if ($applyLowMemoryProfile) {
      $settings = array_merge($settings, $this->get_low_memory_performance_settings());
    }

    $globalRebuildCache = isset($_POST['lm_global_rebuild_cache']) && (string)$_POST['lm_global_rebuild_cache'] === '1';

    $auditRetentionDays = isset($_POST['lm_audit_retention_days']) ? intval($_POST['lm_audit_retention_days']) : (int)($settings['audit_retention_days'] ?? self::AUDIT_RETENTION_DAYS);
    if ($auditRetentionDays < 30) $auditRetentionDays = 30;
    if ($auditRetentionDays > 3650) $auditRetentionDays = 3650;
    $settings['audit_retention_days'] = (string)$auditRetentionDays;

    $weakPatternsRaw = isset($_POST['lm_weak_anchor_patterns']) ? (string)wp_unslash($_POST['lm_weak_anchor_patterns']) : (string)($settings['weak_anchor_patterns'] ?? '');
    $normalizedWeakPatterns = $this->normalize_weak_anchor_patterns($weakPatternsRaw);
    $restoredDefaults = isset($_POST['lm_restore_weak_anchor_patterns']) && (string)$_POST['lm_restore_weak_anchor_patterns'] === '1';
    $clearedAll = isset($_POST['lm_clear_weak_anchor_patterns']) && (string)$_POST['lm_clear_weak_anchor_patterns'] === '1';
    if ($clearedAll) {
      $normalizedWeakPatterns = [];
    }
    if ($restoredDefaults) {
      $normalizedWeakPatterns = $this->get_default_weak_anchor_patterns();
    }
    $settings['weak_anchor_patterns'] = implode("\n", $normalizedWeakPatterns);
    $this->weak_anchor_patterns_cache = $normalizedWeakPatterns;

    $this->save_settings($settings);

    if ($globalRebuildCache) {
      // Keep backup + scan timestamp so incremental background rebuild can start immediately.
      $this->clear_main_cache_all();
      $this->schedule_background_rebuild('any', 'all', 2);
    }

    $savedMsg = 'Settings saved.';
    if ($clearedAll) {
      $savedMsg = 'Settings saved. All weak phrases cleared.';
    }
    if ($restoredDefaults) {
      $savedMsg = 'Settings saved. Default weak phrases restored.';
    }
    if ($resetPerformanceDefaults) {
      $savedMsg = 'Settings saved. Recommended speed settings applied.';
    }
    if ($applyLowMemoryProfile) {
      $savedMsg = 'Settings saved. Safe low-memory settings applied.';
    }
    if ($globalRebuildCache) {
      $savedMsg = 'Settings saved. Main cache invalidated and background incremental rebuild queued.';
    }
    wp_safe_redirect(admin_url('admin.php?page=links-manager-settings&lm_tab=' . rawurlencode($activeTab) . '&lm_msg=' . rawurlencode($savedMsg)));
    exit;
  }

  public function handle_clear_diagnostics() {
    if (!current_user_can('manage_options')) wp_die('Unauthorized');

    $nonce = isset($_POST[self::NONCE_NAME]) ? sanitize_text_field((string)$_POST[self::NONCE_NAME]) : '';
    if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) wp_die('Invalid nonce');

    delete_option(self::DIAGNOSTIC_OPTION_KEY);
    delete_option(self::RUNTIME_PROFILE_OPTION_KEY);
    wp_safe_redirect(admin_url('admin.php?page=links-manager-settings&lm_msg=' . rawurlencode('Diagnostic log cleared.')));
    exit;
  }

  

  public function handle_save_anchor_groups() {
    if (!$this->current_user_can_access_plugin()) wp_die('Unauthorized');
    $nonce = isset($_POST[self::NONCE_NAME]) ? sanitize_text_field($_POST[self::NONCE_NAME]) : '';
    if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) wp_die('Invalid nonce');

    $name = sanitize_text_field((string)($_POST['lm_group_name'] ?? ''));
    $anchorsRaw = (string)($_POST['lm_group_anchors'] ?? '');
    $anchors = $this->normalize_anchor_list($anchorsRaw);
    if ($name !== '') {
      $groups = $this->get_anchor_groups();
      $groups[] = ['name' => $name, 'anchors' => $anchors];
      $this->save_anchor_groups($groups);
    }

    wp_safe_redirect(admin_url('admin.php?page=links-manager-target&lm_msg=' . rawurlencode('Group saved.')));
    exit;
  }

  public function handle_delete_anchor_group() {
    if (!$this->current_user_can_access_plugin()) wp_die('Unauthorized');
    $nonce = isset($_GET[self::NONCE_NAME]) ? sanitize_text_field($_GET[self::NONCE_NAME]) : '';
    if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) wp_die('Invalid nonce');

    $idx = isset($_GET['lm_group_idx']) ? intval($_GET['lm_group_idx']) : -1;
    $groups = $this->get_anchor_groups();
    if ($idx >= 0 && isset($groups[$idx])) {
      array_splice($groups, $idx, 1);
      $this->save_anchor_groups($groups);
    }

    wp_safe_redirect(admin_url('admin.php?page=links-manager-target&lm_msg=' . rawurlencode('Group deleted.')));
    exit;
  }

  public function handle_bulk_delete_anchor_groups() {
    if (!$this->current_user_can_access_plugin()) wp_die('Unauthorized');
    $nonce = isset($_POST[self::NONCE_NAME]) ? sanitize_text_field($_POST[self::NONCE_NAME]) : '';
    if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) wp_die('Invalid nonce');

    $rawIndices = isset($_POST['lm_group_indices']) ? (array)$_POST['lm_group_indices'] : [];
    $indices = array_values(array_unique(array_filter(array_map('intval', $rawIndices), function($idx) {
      return $idx >= 0;
    })));

    if (empty($indices)) {
      wp_safe_redirect(admin_url('admin.php?page=links-manager-target&lm_msg=' . rawurlencode('No group selected.')));
      exit;
    }

    rsort($indices);
    $groups = $this->get_anchor_groups();
    $deleted = 0;
    foreach ($indices as $idx) {
      if (isset($groups[$idx])) {
        array_splice($groups, $idx, 1);
        $deleted++;
      }
    }

    if ($deleted > 0) {
      $this->save_anchor_groups($groups);
      $msg = 'Deleted ' . $deleted . ' group(s).';
    } else {
      $msg = 'No groups were deleted.';
    }

    wp_safe_redirect(admin_url('admin.php?page=links-manager-target&lm_msg=' . rawurlencode($msg)));
    exit;
  }

  public function handle_update_anchor_group() {
    if (!$this->current_user_can_access_plugin()) wp_die('Unauthorized');
    $nonce = isset($_POST[self::NONCE_NAME]) ? sanitize_text_field($_POST[self::NONCE_NAME]) : '';
    if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) wp_die('Invalid nonce');

    $idx = isset($_POST['lm_group_idx']) ? intval($_POST['lm_group_idx']) : -1;
    $name = sanitize_text_field((string)($_POST['lm_group_name'] ?? ''));
    $anchorsRaw = (string)($_POST['lm_group_anchors'] ?? '');
    $anchors = $this->normalize_anchor_list($anchorsRaw);

    $groups = $this->get_anchor_groups();
    if ($idx < 0 || !isset($groups[$idx])) {
      wp_safe_redirect(admin_url('admin.php?page=links-manager-target&lm_msg=' . rawurlencode('Group not found.')));
      exit;
    }

    if ($name === '') {
      wp_safe_redirect(admin_url('admin.php?page=links-manager-target&lm_msg=' . rawurlencode('Group name is required.')));
      exit;
    }

    $groups[$idx] = ['name' => $name, 'anchors' => $anchors];
    $this->save_anchor_groups($groups);

    wp_safe_redirect(admin_url('admin.php?page=links-manager-target&lm_msg=' . rawurlencode('Group updated.')));
    exit;
  }

  public function handle_save_anchor_targets() {
    if (!$this->current_user_can_access_plugin()) wp_die('Unauthorized');
    $nonce = isset($_POST[self::NONCE_NAME]) ? sanitize_text_field($_POST[self::NONCE_NAME]) : '';
    if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) wp_die('Invalid nonce');

    $mode = isset($_POST['lm_anchor_mode']) ? sanitize_text_field((string)$_POST['lm_anchor_mode']) : 'only';
    if (!in_array($mode, ['only','tags'], true)) $mode = 'only';

    $targetsRaw = (string)($_POST['lm_anchor_targets'] ?? '');
    $targets = [];
    $groups = $this->get_anchor_groups();
    $existingTargets = $this->get_anchor_targets();

    $lines = preg_split('/[\r\n]+/', $targetsRaw);
    foreach ($lines as $line) {
      $line = trim((string)$line);
      if ($line === '') continue;

      if ($mode === 'tags') {
        // format: anchor text,group anchor
        $parts = array_map('trim', explode(',', $line, 2));
        $anchor = $parts[0] ?? '';
        $groupName = $parts[1] ?? '';
        if ($anchor !== '') $targets[] = $anchor;
        if ($anchor !== '' && $groupName !== '') {
          $found = false;
          foreach ($groups as &$g) {
            if ((string)($g['name'] ?? '') === $groupName) {
              $anchors = isset($g['anchors']) ? (array)$g['anchors'] : [];
              $anchors[] = $anchor;
              $g['anchors'] = $this->normalize_anchor_list(implode("\n", $anchors));
              $found = true;
              break;
            }
          }
          unset($g);
          if (!$found) {
            $groups[] = ['name' => $groupName, 'anchors' => [$anchor]];
          }
        }
      } else {
        $targets[] = $line;
      }
    }

    $targets = $this->normalize_anchor_list(implode("\n", array_merge((array)$existingTargets, $targets)));
    if (!empty($targets)) $this->save_anchor_targets($targets);
    if (!empty($groups)) $this->save_anchor_groups($groups);

    wp_safe_redirect(admin_url('admin.php?page=links-manager-target&lm_msg=' . rawurlencode('Targets saved.')));
    exit;
  }

  public function handle_update_anchor_target() {
    if (!$this->current_user_can_access_plugin()) wp_die('Unauthorized');
    $nonce = isset($_POST[self::NONCE_NAME]) ? sanitize_text_field($_POST[self::NONCE_NAME]) : '';
    if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) wp_die('Invalid nonce');

    $idx = isset($_POST['lm_target_idx']) ? intval($_POST['lm_target_idx']) : -1;
    $newVal = isset($_POST['lm_target_value']) ? sanitize_text_field((string)$_POST['lm_target_value']) : '';
    $newVal = trim($newVal);

    $targets = $this->get_anchor_targets();
    if ($idx < 0 || !isset($targets[$idx])) {
      wp_safe_redirect(admin_url('admin.php?page=links-manager-target&lm_msg=' . rawurlencode('Target not found.')));
      exit;
    }

    if ($newVal === '') {
      wp_safe_redirect(admin_url('admin.php?page=links-manager-target&lm_msg=' . rawurlencode('Target cannot be empty.')));
      exit;
    }

    $targets[$idx] = $newVal;
    $targets = $this->normalize_anchor_list(implode("\n", $targets));
    $this->save_anchor_targets($targets);

    wp_safe_redirect(admin_url('admin.php?page=links-manager-target&lm_msg=' . rawurlencode('Target updated.')));
    exit;
  }

  public function handle_update_anchor_target_group() {
    if (!$this->current_user_can_access_plugin()) wp_die('Unauthorized');
    $nonce = isset($_POST[self::NONCE_NAME]) ? sanitize_text_field($_POST[self::NONCE_NAME]) : '';
    if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) wp_die('Invalid nonce');

    $anchor = isset($_POST['lm_anchor_value']) ? sanitize_text_field((string)$_POST['lm_anchor_value']) : '';
    $anchor = trim($anchor);
    $newGroup = isset($_POST['lm_anchor_group']) ? sanitize_text_field((string)$_POST['lm_anchor_group']) : '';
    $newGroup = trim($newGroup);

    if ($anchor === '') {
      wp_safe_redirect(admin_url('admin.php?page=links-manager-target&lm_msg=' . rawurlencode('Invalid anchor.')));
      exit;
    }

    $groups = $this->get_anchor_groups();

    // Remove anchor from all groups first
    foreach ($groups as &$g) {
      $anchors = isset($g['anchors']) ? (array)$g['anchors'] : [];
      $anchors = array_values(array_filter($anchors, function($a) use ($anchor) {
        return strtolower(trim((string)$a)) !== strtolower($anchor);
      }));
      $g['anchors'] = $anchors;
    }
    unset($g);

    // Add to selected group (if any)
    if ($newGroup !== '' && $newGroup !== 'no_group') {
      $found = false;
      foreach ($groups as &$g) {
        $gname = isset($g['name']) ? (string)$g['name'] : '';
        if ($gname === $newGroup) {
          $anchors = isset($g['anchors']) ? (array)$g['anchors'] : [];
          $anchors[] = $anchor;
          $g['anchors'] = $this->normalize_anchor_list(implode("\n", $anchors));
          $found = true;
          break;
        }
      }
      unset($g);
      if (!$found) {
        wp_safe_redirect(admin_url('admin.php?page=links-manager-target&lm_msg=' . rawurlencode('Group not found.')));
        exit;
      }
    }

    $this->save_anchor_groups($groups);

    wp_safe_redirect(admin_url('admin.php?page=links-manager-target&lm_msg=' . rawurlencode('Group updated.')));
    exit;
  }

  public function handle_update_anchor_target_group_ajax() {
    if (!$this->current_user_can_access_plugin()) {
      wp_send_json_error(['msg' => 'Unauthorized'], 403);
    }

    $nonce = isset($_POST[self::NONCE_NAME]) ? sanitize_text_field($_POST[self::NONCE_NAME]) : '';
    if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) {
      wp_send_json_error(['msg' => 'Invalid nonce'], 403);
    }

    $anchor = isset($_POST['lm_anchor_value']) ? sanitize_text_field((string)$_POST['lm_anchor_value']) : '';
    $anchor = trim($anchor);
    $newGroup = isset($_POST['lm_anchor_group']) ? sanitize_text_field((string)$_POST['lm_anchor_group']) : '';
    $newGroup = trim($newGroup);

    if ($anchor === '') {
      wp_send_json_error(['msg' => 'Invalid anchor.'], 400);
    }

    $groups = $this->get_anchor_groups();

    // Remove anchor from all groups first
    foreach ($groups as &$g) {
      $anchors = isset($g['anchors']) ? (array)$g['anchors'] : [];
      $anchors = array_values(array_filter($anchors, function($a) use ($anchor) {
        return strtolower(trim((string)$a)) !== strtolower($anchor);
      }));
      $g['anchors'] = $anchors;
    }
    unset($g);

    // Add to selected group (if any)
    if ($newGroup !== '' && $newGroup !== 'no_group') {
      $found = false;
      foreach ($groups as &$g) {
        $gname = isset($g['name']) ? (string)$g['name'] : '';
        if ($gname === $newGroup) {
          $anchors = isset($g['anchors']) ? (array)$g['anchors'] : [];
          $anchors[] = $anchor;
          $g['anchors'] = $this->normalize_anchor_list(implode("\n", $anchors));
          $found = true;
          break;
        }
      }
      unset($g);
      if (!$found) {
        wp_send_json_error(['msg' => 'Group not found.'], 404);
      }
    }

    $this->save_anchor_groups($groups);

    $response = [
      'msg' => 'Group updated successfully.',
      'updated_group' => $newGroup,
    ];

    wp_send_json_success($response);
  }

  public function handle_delete_anchor_target() {
    if (!$this->current_user_can_access_plugin()) wp_die('Unauthorized');
    $nonce = isset($_GET[self::NONCE_NAME]) ? sanitize_text_field($_GET[self::NONCE_NAME]) : '';
    if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) wp_die('Invalid nonce');

    $idx = isset($_GET['lm_target_idx']) ? intval($_GET['lm_target_idx']) : -1;
    $targets = $this->get_anchor_targets();
    $deletedAnchor = '';
    if ($idx >= 0 && isset($targets[$idx])) {
      $deletedAnchor = (string)$targets[$idx];
      array_splice($targets, $idx, 1);
      $this->save_anchor_targets($targets);
    }

    if ($deletedAnchor !== '') {
      $groups = $this->get_anchor_groups();
      foreach ($groups as &$g) {
        $anchors = isset($g['anchors']) ? (array)$g['anchors'] : [];
        $anchors = array_values(array_filter($anchors, function($a) use ($deletedAnchor) {
          return strtolower(trim((string)$a)) !== strtolower(trim($deletedAnchor));
        }));
        $g['anchors'] = $anchors;
      }
      unset($g);
      $this->save_anchor_groups($groups);
    }

    wp_safe_redirect(admin_url('admin.php?page=links-manager-target&lm_msg=' . rawurlencode('Target deleted.')));
    exit;
  }

  public function handle_bulk_delete_anchor_targets() {
    if (!$this->current_user_can_access_plugin()) wp_die('Unauthorized');
    $nonce = isset($_POST[self::NONCE_NAME]) ? sanitize_text_field($_POST[self::NONCE_NAME]) : '';
    if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) wp_die('Invalid nonce');

    $rawIndices = isset($_POST['lm_target_indices']) ? (array)$_POST['lm_target_indices'] : [];
    $indices = array_values(array_unique(array_filter(array_map('intval', $rawIndices), function($idx) {
      return $idx >= 0;
    })));

    if (empty($indices)) {
      wp_safe_redirect(admin_url('admin.php?page=links-manager-target&lm_msg=' . rawurlencode('No target selected.')));
      exit;
    }

    rsort($indices);
    $targets = $this->get_anchor_targets();
    $deletedAnchors = [];

    foreach ($indices as $idx) {
      if (!isset($targets[$idx])) continue;
      $deletedAnchors[] = (string)$targets[$idx];
      array_splice($targets, $idx, 1);
    }

    $deletedAnchors = array_values(array_unique(array_filter(array_map('trim', $deletedAnchors))));
    if (empty($deletedAnchors)) {
      wp_safe_redirect(admin_url('admin.php?page=links-manager-target&lm_msg=' . rawurlencode('No targets were deleted.')));
      exit;
    }

    $this->save_anchor_targets($targets);

    $deletedMap = [];
    foreach ($deletedAnchors as $anchor) {
      $deletedMap[strtolower($anchor)] = true;
    }

    $groups = $this->get_anchor_groups();
    foreach ($groups as &$g) {
      $anchors = isset($g['anchors']) ? (array)$g['anchors'] : [];
      $anchors = array_values(array_filter($anchors, function($a) use ($deletedMap) {
        $k = strtolower(trim((string)$a));
        return $k !== '' && !isset($deletedMap[$k]);
      }));
      $g['anchors'] = $anchors;
    }
    unset($g);
    $this->save_anchor_groups($groups);

    $msg = 'Deleted ' . count($deletedAnchors) . ' target(s).';
    wp_safe_redirect(admin_url('admin.php?page=links-manager-target&lm_msg=' . rawurlencode($msg)));
    exit;
  }

  /* -----------------------------
   * Filters request + URLs
   * ----------------------------- */

  private function sanitize_post_term_filter($rawValue, $taxonomy) {
    $termId = intval($rawValue);
    if ($termId <= 0) return 0;

    $options = $this->get_post_term_options($taxonomy, true);
    if (!isset($options[$termId])) return 0;

    return $termId;
  }

  private function get_global_scan_term_ids($taxonomy) {
    $taxonomy = (string)$taxonomy;
    if ($taxonomy === 'category') {
      $settings = $this->get_settings();
      return $this->sanitize_scan_term_ids(isset($settings['scan_post_category_ids']) ? $settings['scan_post_category_ids'] : [], 'category');
    }
    if ($taxonomy === 'post_tag') {
      $settings = $this->get_settings();
      return $this->sanitize_scan_term_ids(isset($settings['scan_post_tag_ids']) ? $settings['scan_post_tag_ids'] : [], 'post_tag');
    }

    return [];
  }

  private function get_post_term_options($taxonomy, $respectGlobalScanScope = true) {
    $termArgs = [
      'taxonomy' => $taxonomy,
      'hide_empty' => false,
    ];

    if ($respectGlobalScanScope) {
      $scopedTermIds = $this->get_global_scan_term_ids($taxonomy);
      if (!empty($scopedTermIds)) {
        $termArgs['include'] = $scopedTermIds;
      }
    }

    $terms = get_terms($termArgs);

    if (is_wp_error($terms) || empty($terms)) {
      return [];
    }

    $options = [];
    foreach ($terms as $term) {
      if (!isset($term->term_id) || !isset($term->name)) continue;
      $options[(int)$term->term_id] = (string)$term->name;
    }

    asort($options, SORT_NATURAL | SORT_FLAG_CASE);
    return $options;
  }

  private function get_post_ids_by_post_terms($categoryId, $tagId) {
    $categoryId = (int)$categoryId;
    $tagId = (int)$tagId;

    if ($categoryId <= 0 && $tagId <= 0) {
      return null;
    }

    $taxQuery = ['relation' => 'AND'];
    if ($categoryId > 0) {
      $taxQuery[] = [
        'taxonomy' => 'category',
        'field' => 'term_id',
        'terms' => [$categoryId],
      ];
    }
    if ($tagId > 0) {
      $taxQuery[] = [
        'taxonomy' => 'post_tag',
        'field' => 'term_id',
        'terms' => [$tagId],
      ];
    }

    $q = new WP_Query([
      'post_type' => 'post',
      'post_status' => 'publish',
      'posts_per_page' => -1,
      'fields' => 'ids',
      'no_found_rows' => true,
      'tax_query' => $taxQuery,
    ]);

    $ids = [];
    foreach ((array)$q->posts as $postId) {
      $ids[(string)intval($postId)] = true;
    }

    return $ids;
  }

  private function get_filters_from_request() {
    $postTypes = $this->get_filterable_post_types();

    $postType = isset($_REQUEST['lm_post_type']) ? sanitize_text_field($_REQUEST['lm_post_type']) : 'any';
    if ($postType !== 'any' && !isset($postTypes[$postType])) $postType = 'any';

    $postCategory = isset($_REQUEST['lm_post_category']) ? $this->sanitize_post_term_filter($_REQUEST['lm_post_category'], 'category') : 0;
    $postTag = isset($_REQUEST['lm_post_tag']) ? $this->sanitize_post_term_filter($_REQUEST['lm_post_tag'], 'post_tag') : 0;

    // Post category/tag filters only apply to WP posts.
    // If user selects a different post type (e.g., page), reset these filters
    // to avoid unintentionally empty result sets.
    if ($postType !== 'any' && $postType !== 'post') {
      $postCategory = 0;
      $postTag = 0;
    }

    $location = isset($_REQUEST['lm_location']) ? sanitize_text_field($_REQUEST['lm_location']) : 'any';
    $valueContains = isset($_REQUEST['lm_value']) ? sanitize_text_field($_REQUEST['lm_value']) : '';
    $sourceContains = isset($_REQUEST['lm_source']) ? sanitize_text_field($_REQUEST['lm_source']) : '';
    $anchorContains = isset($_REQUEST['lm_anchor']) ? sanitize_text_field($_REQUEST['lm_anchor']) : '';
    $altContains = isset($_REQUEST['lm_alt']) ? sanitize_text_field($_REQUEST['lm_alt']) : '';
    $titleContains = isset($_REQUEST['lm_title']) ? sanitize_text_field($_REQUEST['lm_title']) : '';
    $authorContains = isset($_REQUEST['lm_author']) ? sanitize_text_field($_REQUEST['lm_author']) : '';
    $publishDateFrom = isset($_REQUEST['lm_publish_date_from']) ? $this->sanitize_date_ymd($_REQUEST['lm_publish_date_from']) : '';
    $publishDateTo = isset($_REQUEST['lm_publish_date_to']) ? $this->sanitize_date_ymd($_REQUEST['lm_publish_date_to']) : '';
    $updatedDateFrom = isset($_REQUEST['lm_updated_date_from']) ? $this->sanitize_date_ymd($_REQUEST['lm_updated_date_from']) : '';
    $updatedDateTo = isset($_REQUEST['lm_updated_date_to']) ? $this->sanitize_date_ymd($_REQUEST['lm_updated_date_to']) : '';
    $textMatchMode = isset($_REQUEST['lm_text_mode']) ? $this->sanitize_text_match_mode($_REQUEST['lm_text_mode']) : 'contains';

    $sourceType = isset($_REQUEST['lm_source_type'])
      ? $this->sanitize_source_type_filter($_REQUEST['lm_source_type'])
      : 'any';

    $quality = isset($_REQUEST['lm_quality']) ? sanitize_text_field($_REQUEST['lm_quality']) : 'any';
    if (!in_array($quality, ['any','good','poor','bad'], true)) $quality = 'any';

    $seoFlag = isset($_REQUEST['lm_seo_flag']) ? sanitize_text_field($_REQUEST['lm_seo_flag']) : 'any';
    if (!in_array($seoFlag, ['any','dofollow','nofollow','sponsored','ugc'], true)) $seoFlag = 'any';

    $linkType = isset($_REQUEST['lm_link_type']) ? sanitize_text_field($_REQUEST['lm_link_type']) : 'any';
    if (!in_array($linkType, ['any','inlink','exlink'], true)) $linkType = 'any';

    $valueType = isset($_REQUEST['lm_value_type']) ? sanitize_text_field($_REQUEST['lm_value_type']) : 'any';
    $validValueTypes = ['any','url','relative','anchor','mailto','tel','javascript','other','empty'];
    if (!in_array($valueType, $validValueTypes, true)) $valueType = 'any';

    $relContains = isset($_REQUEST['lm_rel']) ? sanitize_text_field($_REQUEST['lm_rel']) : '';
    $relNofollow = isset($_REQUEST['lm_rel_nofollow']) ? sanitize_text_field($_REQUEST['lm_rel_nofollow']) : 'any';
    $relSponsored = isset($_REQUEST['lm_rel_sponsored']) ? sanitize_text_field($_REQUEST['lm_rel_sponsored']) : 'any';
    $relUgc = isset($_REQUEST['lm_rel_ugc']) ? sanitize_text_field($_REQUEST['lm_rel_ugc']) : 'any';
    foreach (['relNofollow','relSponsored','relUgc'] as $k) {
      $v = $$k;
      if (!in_array($v, ['any','1','0'], true)) $$k = 'any';
    }

    // Grouped dedupe mode is no longer exposed in Links Editor.
    $group = '0';

    $orderby = isset($_REQUEST['lm_orderby']) ? sanitize_text_field($_REQUEST['lm_orderby']) : 'date';
    if (!in_array($orderby, ['date','title','post_type','post_author','page_url','link','source','link_location','anchor_text','quality','link_type','seo_flags','alt_text','count'], true)) $orderby = 'date';

    $order = isset($_REQUEST['lm_order']) ? sanitize_text_field($_REQUEST['lm_order']) : 'DESC';
    $order = strtoupper($order);
    if (!in_array($order, ['ASC','DESC'], true)) $order = 'DESC';

    $rawPerPage = isset($_REQUEST['lm_per_page']) ? trim((string)$_REQUEST['lm_per_page']) : '';
    $perPage = ($rawPerPage === '') ? 25 : intval($rawPerPage);
    if ($perPage < 10) $perPage = 10;
    if ($perPage > 500) $perPage = 500;

    $paged = isset($_REQUEST['lm_paged']) ? intval($_REQUEST['lm_paged']) : 1;
    if ($paged < 1) $paged = 1;

    $rebuild = isset($_REQUEST['lm_rebuild']) ? sanitize_text_field($_REQUEST['lm_rebuild']) : '0';
    $rebuild = $rebuild === '1';

    $wpmlLang = $this->sanitize_wpml_lang_filter($this->get_wpml_current_language());

    return [
      'post_type' => $postType,
      'post_category' => $postCategory,
      'post_tag' => $postTag,
      'wpml_lang' => $wpmlLang,
      'location' => $location,
      'value_contains' => $valueContains,
      'source_contains' => $sourceContains,
      'anchor_contains' => $anchorContains,
      'alt_contains' => $altContains,
      'title_contains' => $titleContains,
      'author_contains' => $authorContains,
      'publish_date_from' => $publishDateFrom,
      'publish_date_to' => $publishDateTo,
      'updated_date_from' => $updatedDateFrom,
      'updated_date_to' => $updatedDateTo,
      'text_match_mode' => $textMatchMode,
      'source_type' => $sourceType,
      'quality' => $quality,
      'seo_flag' => $seoFlag,
      'link_type' => $linkType,
      'value_type' => $valueType,
      'rel_contains' => $relContains,
      'rel_nofollow' => $relNofollow,
      'rel_sponsored' => $relSponsored,
      'rel_ugc' => $relUgc,
      'group' => $group,
      'orderby' => $orderby,
      'order' => $order,
      'per_page' => $perPage,
      'paged' => $paged,
      'rebuild' => $rebuild,
    ];
  }

  private function base_admin_url($filters, $override = []) {
    $args = [
      'page' => self::PAGE_SLUG,
      'lm_post_type' => $filters['post_type'],
      'lm_post_category' => isset($filters['post_category']) ? (int)$filters['post_category'] : 0,
      'lm_post_tag' => isset($filters['post_tag']) ? (int)$filters['post_tag'] : 0,
      'lm_location' => $filters['location'],
      'lm_link_type' => $filters['link_type'],
      'lm_value_type' => $filters['value_type'],
      'lm_value' => $filters['value_contains'],
      'lm_source' => $filters['source_contains'],
      'lm_title' => $filters['title_contains'],
      'lm_author' => $filters['author_contains'],
      'lm_publish_date_from' => isset($filters['publish_date_from']) ? $filters['publish_date_from'] : '',
      'lm_publish_date_to' => isset($filters['publish_date_to']) ? $filters['publish_date_to'] : '',
      'lm_updated_date_from' => isset($filters['updated_date_from']) ? $filters['updated_date_from'] : '',
      'lm_updated_date_to' => isset($filters['updated_date_to']) ? $filters['updated_date_to'] : '',
      'lm_text_mode' => $filters['text_match_mode'],
      'lm_source_type' => $filters['source_type'],
      'lm_quality' => $filters['quality'],
      'lm_seo_flag' => $filters['seo_flag'],
      'lm_anchor' => $filters['anchor_contains'],
      'lm_alt' => $filters['alt_contains'],
      'lm_rel' => $filters['rel_contains'],
      'lm_rel_nofollow' => $filters['rel_nofollow'],
      'lm_rel_sponsored' => $filters['rel_sponsored'],
      'lm_rel_ugc' => $filters['rel_ugc'],
      'lm_orderby' => $filters['orderby'],
      'lm_order' => $filters['order'],
      'lm_per_page' => $filters['per_page'],
      'lm_paged' => $filters['paged'],
    ];
    foreach ($override as $k => $v) $args[$k] = $v;
    return admin_url('admin.php?' . http_build_query($args));
  }

  private function build_export_url($filters) {
    $args = [
      'action' => 'lm_export_csv',
      self::NONCE_NAME => wp_create_nonce(self::NONCE_ACTION),
      'lm_post_type' => $filters['post_type'],
      'lm_post_category' => isset($filters['post_category']) ? (int)$filters['post_category'] : 0,
      'lm_post_tag' => isset($filters['post_tag']) ? (int)$filters['post_tag'] : 0,
      'lm_location' => $filters['location'],
      'lm_link_type' => $filters['link_type'],
      'lm_value_type' => $filters['value_type'],
      'lm_value' => $filters['value_contains'],
      'lm_source' => $filters['source_contains'],
      'lm_title' => $filters['title_contains'],
      'lm_author' => $filters['author_contains'],
      'lm_publish_date_from' => isset($filters['publish_date_from']) ? $filters['publish_date_from'] : '',
      'lm_publish_date_to' => isset($filters['publish_date_to']) ? $filters['publish_date_to'] : '',
      'lm_updated_date_from' => isset($filters['updated_date_from']) ? $filters['updated_date_from'] : '',
      'lm_updated_date_to' => isset($filters['updated_date_to']) ? $filters['updated_date_to'] : '',
      'lm_text_mode' => $filters['text_match_mode'],
      'lm_source_type' => $filters['source_type'],
      'lm_quality' => $filters['quality'],
      'lm_seo_flag' => $filters['seo_flag'],
      'lm_anchor' => $filters['anchor_contains'],
      'lm_alt' => $filters['alt_contains'],
      'lm_rel' => $filters['rel_contains'],
      'lm_rel_nofollow' => $filters['rel_nofollow'],
      'lm_rel_sponsored' => $filters['rel_sponsored'],
      'lm_rel_ugc' => $filters['rel_ugc'],
      'lm_rebuild' => $filters['rebuild'] ? '1' : '0',
      'lm_orderby' => $filters['orderby'],
      'lm_order' => $filters['order'],
    ];
    return admin_url('admin-post.php?' . http_build_query($args));
  }

  private function render_pagination($filters, $paged, $totalPages) {
    if ($totalPages <= 1) return;

    echo '<div class="tablenav" style="margin:10px 0;">';
    echo '<div class="tablenav-pages">';
    echo '<span class="displaying-num">Page ' . esc_html((string)$paged) . ' of ' . esc_html((string)$totalPages) . '</span> ';

    $prev = max(1, $paged - 1);
    $next = min($totalPages, $paged + 1);

    echo '<a class="button" href="' . esc_url($this->base_admin_url($filters, ['lm_paged' => 1])) . '">&laquo; First</a> ';
    echo '<a class="button" href="' . esc_url($this->base_admin_url($filters, ['lm_paged' => $prev])) . '">&lsaquo; Previous</a> ';
    echo '<a class="button" href="' . esc_url($this->base_admin_url($filters, ['lm_paged' => $next])) . '">Next &rsaquo;</a> ';
    echo '<a class="button" href="' . esc_url($this->base_admin_url($filters, ['lm_paged' => $totalPages])) . '">Last &raquo;</a>';

    echo '</div></div>';
  }

  private function render_target_pagination($paged, $totalPages, $queryParams = []) {
    if ($totalPages <= 1) return;

    echo '<div class="tablenav" style="margin:10px 0;">';
    echo '<div class="tablenav-pages">';
    echo '<span class="displaying-num">Page ' . esc_html((string)$paged) . ' of ' . esc_html((string)$totalPages) . '</span> ';

    $prev = max(1, $paged - 1);
    $next = min($totalPages, $paged + 1);

    $baseParams = array_merge(['page' => 'links-manager-target'], $queryParams);
    
    $firstUrl = add_query_arg(array_merge($baseParams, ['lm_summary_paged' => 1]), admin_url('admin.php'));
    $prevUrl = add_query_arg(array_merge($baseParams, ['lm_summary_paged' => $prev]), admin_url('admin.php'));
    $nextUrl = add_query_arg(array_merge($baseParams, ['lm_summary_paged' => $next]), admin_url('admin.php'));
    $lastUrl = add_query_arg(array_merge($baseParams, ['lm_summary_paged' => $totalPages]), admin_url('admin.php'));

    echo '<a class="button" href="' . esc_url($firstUrl) . '">&laquo; First</a> ';
    echo '<a class="button" href="' . esc_url($prevUrl) . '">&lsaquo; Previous</a> ';
    echo '<a class="button" href="' . esc_url($nextUrl) . '">Next &rsaquo;</a> ';
    echo '<a class="button" href="' . esc_url($lastUrl) . '">Last &raquo;</a>';

    echo '</div></div>';
  }

  private function cited_domains_admin_url($filters, $override = []) {
    $args = [
      'page' => 'links-manager-cited-domains',
      'lm_cd_post_type' => $filters['post_type'],
      'lm_cd_post_category' => isset($filters['post_category']) ? (int)$filters['post_category'] : 0,
      'lm_cd_post_tag' => isset($filters['post_tag']) ? (int)$filters['post_tag'] : 0,
      'lm_cd_search' => $filters['search'],
      'lm_cd_search_mode' => $filters['search_mode'],
      'lm_cd_location' => $filters['location'],
      'lm_cd_source_type' => $filters['source_type'],
      'lm_cd_value' => $filters['value_contains'],
      'lm_cd_source' => $filters['source_contains'],
      'lm_cd_title' => $filters['title_contains'],
      'lm_cd_author' => $filters['author_contains'],
      'lm_cd_anchor' => $filters['anchor_contains'],
      'lm_cd_seo_flag' => $filters['seo_flag'],
      'lm_cd_min_cites' => $filters['min_cites'],
      'lm_cd_max_cites' => $filters['max_cites'],
      'lm_cd_min_pages' => $filters['min_pages'],
      'lm_cd_max_pages' => $filters['max_pages'],
      'lm_cd_orderby' => $filters['orderby'],
      'lm_cd_order' => $filters['order'],
      'lm_cd_per_page' => $filters['per_page'],
      'lm_cd_paged' => $filters['paged'],
      'lm_cd_rebuild' => $filters['rebuild'] ? '1' : '0',
    ];
    foreach ($override as $k => $v) $args[$k] = $v;
    return admin_url('admin.php?' . http_build_query($args));
  }

  private function build_cited_domains_export_url($filters) {
    $args = [
      'action' => 'lm_export_cited_domains_csv',
      self::NONCE_NAME => wp_create_nonce(self::NONCE_ACTION),
      'lm_cd_post_type' => $filters['post_type'],
      'lm_cd_post_category' => isset($filters['post_category']) ? (int)$filters['post_category'] : 0,
      'lm_cd_post_tag' => isset($filters['post_tag']) ? (int)$filters['post_tag'] : 0,
      'lm_cd_search' => $filters['search'],
      'lm_cd_search_mode' => $filters['search_mode'],
      'lm_cd_location' => $filters['location'],
      'lm_cd_source_type' => $filters['source_type'],
      'lm_cd_value' => $filters['value_contains'],
      'lm_cd_source' => $filters['source_contains'],
      'lm_cd_title' => $filters['title_contains'],
      'lm_cd_author' => $filters['author_contains'],
      'lm_cd_anchor' => $filters['anchor_contains'],
      'lm_cd_seo_flag' => $filters['seo_flag'],
      'lm_cd_min_cites' => $filters['min_cites'],
      'lm_cd_max_cites' => $filters['max_cites'],
      'lm_cd_min_pages' => $filters['min_pages'],
      'lm_cd_max_pages' => $filters['max_pages'],
      'lm_cd_orderby' => $filters['orderby'],
      'lm_cd_order' => $filters['order'],
      'lm_cd_per_page' => $filters['per_page'],
      'lm_cd_paged' => $filters['paged'],
      'lm_cd_rebuild' => $filters['rebuild'] ? '1' : '0',
    ];
    return admin_url('admin-post.php?' . http_build_query($args));
  }

  private function get_cited_domains_filters_from_request() {
    $postTypes = $this->get_filterable_post_types();
    $postType = isset($_REQUEST['lm_cd_post_type']) ? sanitize_key((string)$_REQUEST['lm_cd_post_type']) : 'any';
    if ($postType !== 'any' && !isset($postTypes[$postType])) $postType = 'any';

    $postCategory = isset($_REQUEST['lm_cd_post_category']) ? $this->sanitize_post_term_filter($_REQUEST['lm_cd_post_category'], 'category') : 0;
    $postTag = isset($_REQUEST['lm_cd_post_tag']) ? $this->sanitize_post_term_filter($_REQUEST['lm_cd_post_tag'], 'post_tag') : 0;
    if ($postType !== 'any' && $postType !== 'post') {
      $postCategory = 0;
      $postTag = 0;
    }

    $maxCitesRaw = isset($_REQUEST['lm_cd_max_cites']) ? trim((string)$_REQUEST['lm_cd_max_cites']) : '';
    $maxPagesRaw = isset($_REQUEST['lm_cd_max_pages']) ? trim((string)$_REQUEST['lm_cd_max_pages']) : '';

    $filters = [
      'post_type' => $postType,
      'post_category' => $postCategory,
      'post_tag' => $postTag,
      'wpml_lang' => $this->sanitize_wpml_lang_filter($this->get_wpml_current_language()),
      'search' => isset($_REQUEST['lm_cd_search']) ? sanitize_text_field((string)$_REQUEST['lm_cd_search']) : '',
      'search_mode' => isset($_REQUEST['lm_cd_search_mode']) ? $this->sanitize_text_match_mode($_REQUEST['lm_cd_search_mode']) : 'contains',
      'location' => isset($_REQUEST['lm_cd_location']) ? sanitize_text_field((string)$_REQUEST['lm_cd_location']) : 'any',
      'source_type' => isset($_REQUEST['lm_cd_source_type']) ? $this->sanitize_source_type_filter($_REQUEST['lm_cd_source_type']) : 'any',
      'value_contains' => isset($_REQUEST['lm_cd_value']) ? sanitize_text_field((string)$_REQUEST['lm_cd_value']) : '',
      'source_contains' => isset($_REQUEST['lm_cd_source']) ? sanitize_text_field((string)$_REQUEST['lm_cd_source']) : '',
      'title_contains' => isset($_REQUEST['lm_cd_title']) ? sanitize_text_field((string)$_REQUEST['lm_cd_title']) : '',
      'author_contains' => isset($_REQUEST['lm_cd_author']) ? sanitize_text_field((string)$_REQUEST['lm_cd_author']) : '',
      'anchor_contains' => isset($_REQUEST['lm_cd_anchor']) ? sanitize_text_field((string)$_REQUEST['lm_cd_anchor']) : '',
      'seo_flag' => isset($_REQUEST['lm_cd_seo_flag']) ? sanitize_text_field((string)$_REQUEST['lm_cd_seo_flag']) : 'any',
      'min_cites' => isset($_REQUEST['lm_cd_min_cites']) ? max(0, intval($_REQUEST['lm_cd_min_cites'])) : 0,
      'max_cites' => ($maxCitesRaw === '' || intval($maxCitesRaw) < 0) ? -1 : max(0, intval($maxCitesRaw)),
      'min_pages' => isset($_REQUEST['lm_cd_min_pages']) ? max(0, intval($_REQUEST['lm_cd_min_pages'])) : 0,
      'max_pages' => ($maxPagesRaw === '' || intval($maxPagesRaw) < 0) ? -1 : max(0, intval($maxPagesRaw)),
      'orderby' => isset($_REQUEST['lm_cd_orderby']) ? sanitize_text_field((string)$_REQUEST['lm_cd_orderby']) : 'cites',
      'order' => isset($_REQUEST['lm_cd_order']) ? strtoupper(sanitize_text_field((string)$_REQUEST['lm_cd_order'])) : 'DESC',
      'per_page' => isset($_REQUEST['lm_cd_per_page']) ? intval($_REQUEST['lm_cd_per_page']) : 50,
      'paged' => isset($_REQUEST['lm_cd_paged']) ? intval($_REQUEST['lm_cd_paged']) : 1,
      'rebuild' => isset($_REQUEST['lm_cd_rebuild']) && sanitize_text_field((string)$_REQUEST['lm_cd_rebuild']) === '1',
    ];

  if ((string)$filters['location'] === '') $filters['location'] = 'any';
  if (!in_array($filters['seo_flag'], ['any','dofollow','nofollow','sponsored','ugc'], true)) $filters['seo_flag'] = 'any';
    if (!in_array($filters['orderby'], ['cites', 'domain', 'pages', 'sample_url'], true)) $filters['orderby'] = 'cites';
    if (!in_array($filters['order'], ['ASC', 'DESC'], true)) $filters['order'] = 'DESC';
    if ($filters['per_page'] < 10) $filters['per_page'] = 10;
    if ($filters['per_page'] > 500) $filters['per_page'] = 500;
    if ($filters['paged'] < 1) $filters['paged'] = 1;

    return $filters;
  }

  private function build_cited_domains_summary_rows($all, $filters) {
    $allowedPostIds = $this->get_post_ids_by_post_terms(
      isset($filters['post_category']) ? (int)$filters['post_category'] : 0,
      isset($filters['post_tag']) ? (int)$filters['post_tag'] : 0
    );
    $domainMap = [];
    foreach ($all as $row) {
      if (is_array($allowedPostIds)) {
        $rowPostId = isset($row['post_id']) ? (string)intval($row['post_id']) : '';
        if ($rowPostId === '' || !isset($allowedPostIds[$rowPostId])) continue;
      }
      if (($row['link_type'] ?? '') !== 'exlink') continue;
      if (($filters['post_type'] ?? 'any') !== 'any' && (string)($row['post_type'] ?? '') !== (string)$filters['post_type']) continue;
      if (($filters['location'] ?? 'any') !== 'any' && (string)($row['link_location'] ?? '') !== (string)$filters['location']) continue;
      if (($filters['source_type'] ?? 'any') !== 'any' && (string)($row['source'] ?? '') !== (string)$filters['source_type']) continue;

      $textMode = (string)($filters['search_mode'] ?? 'contains');
      if ((string)($filters['value_contains'] ?? '') !== '' && !$this->text_matches((string)($row['link'] ?? ''), (string)$filters['value_contains'], $textMode)) continue;
      if ((string)($filters['source_contains'] ?? '') !== '' && !$this->text_matches((string)($row['page_url'] ?? ''), (string)$filters['source_contains'], $textMode)) continue;
      if ((string)($filters['title_contains'] ?? '') !== '' && !$this->text_matches((string)($row['post_title'] ?? ''), (string)$filters['title_contains'], $textMode)) continue;
      if ((string)($filters['author_contains'] ?? '') !== '' && !$this->text_matches((string)($row['post_author'] ?? ''), (string)$filters['author_contains'], $textMode)) continue;
      if ((string)($filters['anchor_contains'] ?? '') !== '' && !$this->text_matches((string)($row['anchor_text'] ?? ''), (string)$filters['anchor_contains'], $textMode)) continue;

      $seoFlag = (string)($filters['seo_flag'] ?? 'any');
      if ($seoFlag !== 'any') {
        $nofollow = (string)($row['rel_nofollow'] ?? '0') === '1';
        $sponsored = (string)($row['rel_sponsored'] ?? '0') === '1';
        $ugc = (string)($row['rel_ugc'] ?? '0') === '1';
        if ($seoFlag === 'dofollow' && ($nofollow || $sponsored || $ugc)) continue;
        if ($seoFlag === 'nofollow' && !$nofollow) continue;
        if ($seoFlag === 'sponsored' && !$sponsored) continue;
        if ($seoFlag === 'ugc' && !$ugc) continue;
      }

      $link = $this->normalize_url((string)($row['link'] ?? ''));
      $host = parse_url($link, PHP_URL_HOST);
      if (!$host) continue;
      $host = strtolower((string)$host);

      if (!isset($domainMap[$host])) {
        $domainMap[$host] = [
          'domain' => $host,
          'cites' => 0,
          'pages' => [],
          'sample_url' => $link,
        ];
      }

      $domainMap[$host]['cites']++;
      $pageUrl = (string)($row['page_url'] ?? '');
      if ($pageUrl !== '') $domainMap[$host]['pages'][$pageUrl] = true;
    }

    $rows = [];
    foreach ($domainMap as $item) {
      $rows[] = [
        'domain' => $item['domain'],
        'cites' => (int)$item['cites'],
        'pages' => count($item['pages']),
        'sample_url' => (string)$item['sample_url'],
      ];
    }

    if ($filters['search'] !== '') {
      $rows = array_values(array_filter($rows, function($r) use ($filters) {
        return $this->text_matches((string)$r['domain'], (string)$filters['search'], (string)$filters['search_mode']);
      }));
    }
    if ($filters['min_cites'] > 0) {
      $rows = array_values(array_filter($rows, function($r) use ($filters) {
        return (int)$r['cites'] >= (int)$filters['min_cites'];
      }));
    }
    if ($filters['min_pages'] > 0) {
      $rows = array_values(array_filter($rows, function($r) use ($filters) {
        return (int)$r['pages'] >= (int)$filters['min_pages'];
      }));
    }
    if (($filters['max_cites'] ?? -1) >= 0) {
      $rows = array_values(array_filter($rows, function($r) use ($filters) {
        return (int)$r['cites'] <= (int)$filters['max_cites'];
      }));
    }
    if (($filters['max_pages'] ?? -1) >= 0) {
      $rows = array_values(array_filter($rows, function($r) use ($filters) {
        return (int)$r['pages'] <= (int)$filters['max_pages'];
      }));
    }

    usort($rows, function($a, $b) use ($filters) {
      $dir = $filters['order'] === 'ASC' ? 1 : -1;
      if ($filters['orderby'] === 'domain') {
        $cmp = strcmp((string)$a['domain'], (string)$b['domain']);
        if ($cmp === 0) $cmp = ((int)$a['cites'] <=> (int)$b['cites']) * -1;
        return $cmp * $dir;
      }
      if ($filters['orderby'] === 'pages') {
        $cmp = ((int)$a['pages'] <=> (int)$b['pages']);
        if ($cmp === 0) $cmp = strcmp((string)$a['domain'], (string)$b['domain']);
        return $cmp * $dir;
      }
      if ($filters['orderby'] === 'sample_url') {
        $cmp = strcmp((string)$a['sample_url'], (string)$b['sample_url']);
        if ($cmp === 0) $cmp = strcmp((string)$a['domain'], (string)$b['domain']);
        return $cmp * $dir;
      }

      $cmp = ((int)$a['cites'] <=> (int)$b['cites']);
      if ($cmp === 0) $cmp = strcmp((string)$a['domain'], (string)$b['domain']);
      return $cmp * $dir;
    });

    return $rows;
  }

  private function render_cited_domains_pagination($filters, $paged, $totalPages) {
    if ($totalPages <= 1) return;

    echo '<div class="tablenav" style="margin:10px 0;">';
    echo '<div class="tablenav-pages">';
    echo '<span class="displaying-num">Page ' . esc_html((string)$paged) . ' of ' . esc_html((string)$totalPages) . '</span> ';

    $prev = max(1, $paged - 1);
    $next = min($totalPages, $paged + 1);

    echo '<a class="button" href="' . esc_url($this->cited_domains_admin_url($filters, ['lm_cd_paged' => 1])) . '">&laquo; First</a> ';
    echo '<a class="button" href="' . esc_url($this->cited_domains_admin_url($filters, ['lm_cd_paged' => $prev])) . '">&lsaquo; Previous</a> ';
    echo '<a class="button" href="' . esc_url($this->cited_domains_admin_url($filters, ['lm_cd_paged' => $next])) . '">Next &rsaquo;</a> ';
    echo '<a class="button" href="' . esc_url($this->cited_domains_admin_url($filters, ['lm_cd_paged' => $totalPages])) . '">Last &raquo;</a>';

    echo '</div></div>';
  }

  private function get_all_anchor_text_filters_from_request() {
    $postTypes = $this->get_filterable_post_types();
    $postType = isset($_REQUEST['lm_at_post_type']) ? sanitize_key((string)$_REQUEST['lm_at_post_type']) : 'any';
    if ($postType !== 'any' && !isset($postTypes[$postType])) $postType = 'any';

    $postCategory = isset($_REQUEST['lm_at_post_category']) ? $this->sanitize_post_term_filter($_REQUEST['lm_at_post_category'], 'category') : 0;
    $postTag = isset($_REQUEST['lm_at_post_tag']) ? $this->sanitize_post_term_filter($_REQUEST['lm_at_post_tag'], 'post_tag') : 0;
    if ($postType !== 'any' && $postType !== 'post') {
      $postCategory = 0;
      $postTag = 0;
    }

    $maxTotalRaw = isset($_REQUEST['lm_at_max_total']) ? trim((string)$_REQUEST['lm_at_max_total']) : '';
    $maxInlinkRaw = isset($_REQUEST['lm_at_max_inlink']) ? trim((string)$_REQUEST['lm_at_max_inlink']) : '';
    $maxOutboundRaw = isset($_REQUEST['lm_at_max_outbound']) ? trim((string)$_REQUEST['lm_at_max_outbound']) : '';
    $maxPagesRaw = isset($_REQUEST['lm_at_max_pages']) ? trim((string)$_REQUEST['lm_at_max_pages']) : '';
    $maxDestinationsRaw = isset($_REQUEST['lm_at_max_destinations']) ? trim((string)$_REQUEST['lm_at_max_destinations']) : '';

    $filters = [
      'post_type' => $postType,
      'post_category' => $postCategory,
      'post_tag' => $postTag,
      'wpml_lang' => $this->sanitize_wpml_lang_filter($this->get_wpml_current_language()),
      'search' => isset($_REQUEST['lm_at_search']) ? sanitize_text_field((string)$_REQUEST['lm_at_search']) : '',
      'search_mode' => isset($_REQUEST['lm_at_search_mode']) ? $this->sanitize_text_match_mode($_REQUEST['lm_at_search_mode']) : 'contains',
      'location' => isset($_REQUEST['lm_at_location']) ? sanitize_text_field((string)$_REQUEST['lm_at_location']) : 'any',
      'source_type' => isset($_REQUEST['lm_at_source_type']) ? $this->sanitize_source_type_filter($_REQUEST['lm_at_source_type']) : 'any',
      'link_type' => isset($_REQUEST['lm_at_link_type']) ? sanitize_text_field((string)$_REQUEST['lm_at_link_type']) : 'any',
      'value_contains' => isset($_REQUEST['lm_at_value']) ? sanitize_text_field((string)$_REQUEST['lm_at_value']) : '',
      'source_contains' => isset($_REQUEST['lm_at_source']) ? sanitize_text_field((string)$_REQUEST['lm_at_source']) : '',
      'title_contains' => isset($_REQUEST['lm_at_title']) ? sanitize_text_field((string)$_REQUEST['lm_at_title']) : '',
      'author_contains' => isset($_REQUEST['lm_at_author']) ? sanitize_text_field((string)$_REQUEST['lm_at_author']) : '',
      'seo_flag' => isset($_REQUEST['lm_at_seo_flag']) ? sanitize_text_field((string)$_REQUEST['lm_at_seo_flag']) : 'any',
      'usage_type' => isset($_REQUEST['lm_at_usage_type']) ? sanitize_text_field((string)$_REQUEST['lm_at_usage_type']) : 'any',
      'quality' => isset($_REQUEST['lm_at_quality']) ? sanitize_text_field((string)$_REQUEST['lm_at_quality']) : 'any',
      'group' => isset($_REQUEST['lm_at_group']) ? sanitize_text_field((string)$_REQUEST['lm_at_group']) : 'any',
      'min_total' => isset($_REQUEST['lm_at_min_total']) ? max(0, intval($_REQUEST['lm_at_min_total'])) : 0,
      'max_total' => ($maxTotalRaw === '' || intval($maxTotalRaw) < 0) ? -1 : max(0, intval($maxTotalRaw)),
      'min_inlink' => isset($_REQUEST['lm_at_min_inlink']) ? max(0, intval($_REQUEST['lm_at_min_inlink'])) : 0,
      'max_inlink' => ($maxInlinkRaw === '' || intval($maxInlinkRaw) < 0) ? -1 : max(0, intval($maxInlinkRaw)),
      'min_outbound' => isset($_REQUEST['lm_at_min_outbound']) ? max(0, intval($_REQUEST['lm_at_min_outbound'])) : 0,
      'max_outbound' => ($maxOutboundRaw === '' || intval($maxOutboundRaw) < 0) ? -1 : max(0, intval($maxOutboundRaw)),
      'min_pages' => isset($_REQUEST['lm_at_min_pages']) ? max(0, intval($_REQUEST['lm_at_min_pages'])) : 0,
      'max_pages' => ($maxPagesRaw === '' || intval($maxPagesRaw) < 0) ? -1 : max(0, intval($maxPagesRaw)),
      'min_destinations' => isset($_REQUEST['lm_at_min_destinations']) ? max(0, intval($_REQUEST['lm_at_min_destinations'])) : 0,
      'max_destinations' => ($maxDestinationsRaw === '' || intval($maxDestinationsRaw) < 0) ? -1 : max(0, intval($maxDestinationsRaw)),
      'orderby' => isset($_REQUEST['lm_at_orderby']) ? sanitize_text_field((string)$_REQUEST['lm_at_orderby']) : 'total',
      'order' => isset($_REQUEST['lm_at_order']) ? strtoupper(sanitize_text_field((string)$_REQUEST['lm_at_order'])) : 'DESC',
      'per_page' => isset($_REQUEST['lm_at_per_page']) ? intval($_REQUEST['lm_at_per_page']) : 50,
      'paged' => isset($_REQUEST['lm_at_paged']) ? intval($_REQUEST['lm_at_paged']) : 1,
      'rebuild' => isset($_REQUEST['lm_at_rebuild']) && sanitize_text_field((string)$_REQUEST['lm_at_rebuild']) === '1',
    ];

    if ((string)$filters['location'] === '') $filters['location'] = 'any';
    if (!in_array($filters['link_type'], ['any','inlink','exlink'], true)) $filters['link_type'] = 'any';
    if (!in_array($filters['seo_flag'], ['any','dofollow','nofollow','sponsored','ugc'], true)) $filters['seo_flag'] = 'any';
    if (!in_array($filters['usage_type'], ['any', 'mixed', 'inlink_only', 'outbound_only'], true)) $filters['usage_type'] = 'any';
    if (!in_array($filters['quality'], ['any', 'good', 'poor', 'bad'], true)) $filters['quality'] = 'any';
    if ($filters['group'] === '') $filters['group'] = 'any';
    if (!in_array($filters['group'], ['any', 'no_group'], true)) {
      $groupNames = [];
      foreach ($this->get_anchor_groups() as $g) {
        $gname = trim((string)($g['name'] ?? ''));
        if ($gname !== '') $groupNames[$gname] = true;
      }
      if (!isset($groupNames[$filters['group']])) {
        $filters['group'] = 'any';
      }
    }
    if (!in_array($filters['orderby'], ['total', 'inlink', 'outbound', 'anchor', 'pages', 'destinations', 'quality', 'source_types', 'usage_type'], true)) $filters['orderby'] = 'total';
    if (!in_array($filters['order'], ['ASC', 'DESC'], true)) $filters['order'] = 'DESC';
    if ($filters['per_page'] < 10) $filters['per_page'] = 10;
    if ($filters['per_page'] > 500) $filters['per_page'] = 500;
    if ($filters['paged'] < 1) $filters['paged'] = 1;

    return $filters;
  }

  private function all_anchor_text_admin_url($filters, $override = []) {
    $args = [
      'page' => 'links-manager-all-anchor-text',
      'lm_at_post_type' => $filters['post_type'],
      'lm_at_post_category' => isset($filters['post_category']) ? (int)$filters['post_category'] : 0,
      'lm_at_post_tag' => isset($filters['post_tag']) ? (int)$filters['post_tag'] : 0,
      'lm_at_search' => $filters['search'],
      'lm_at_search_mode' => $filters['search_mode'],
      'lm_at_location' => $filters['location'],
      'lm_at_source_type' => $filters['source_type'],
      'lm_at_link_type' => $filters['link_type'],
      'lm_at_value' => $filters['value_contains'],
      'lm_at_source' => $filters['source_contains'],
      'lm_at_title' => $filters['title_contains'],
      'lm_at_author' => $filters['author_contains'],
      'lm_at_seo_flag' => $filters['seo_flag'],
      'lm_at_usage_type' => $filters['usage_type'],
      'lm_at_quality' => $filters['quality'],
      'lm_at_group' => $filters['group'],
      'lm_at_min_total' => $filters['min_total'],
      'lm_at_max_total' => $filters['max_total'],
      'lm_at_min_inlink' => $filters['min_inlink'],
      'lm_at_max_inlink' => $filters['max_inlink'],
      'lm_at_min_outbound' => $filters['min_outbound'],
      'lm_at_max_outbound' => $filters['max_outbound'],
      'lm_at_min_pages' => $filters['min_pages'],
      'lm_at_max_pages' => $filters['max_pages'],
      'lm_at_min_destinations' => $filters['min_destinations'],
      'lm_at_max_destinations' => $filters['max_destinations'],
      'lm_at_orderby' => $filters['orderby'],
      'lm_at_order' => $filters['order'],
      'lm_at_per_page' => $filters['per_page'],
      'lm_at_paged' => $filters['paged'],
      'lm_at_rebuild' => $filters['rebuild'] ? '1' : '0',
    ];
    foreach ($override as $k => $v) $args[$k] = $v;
    return admin_url('admin.php?' . http_build_query($args));
  }

  private function build_all_anchor_text_export_url($filters) {
    $args = [
      'action' => 'lm_export_all_anchor_text_csv',
      self::NONCE_NAME => wp_create_nonce(self::NONCE_ACTION),
      'lm_at_post_type' => $filters['post_type'],
      'lm_at_post_category' => isset($filters['post_category']) ? (int)$filters['post_category'] : 0,
      'lm_at_post_tag' => isset($filters['post_tag']) ? (int)$filters['post_tag'] : 0,
      'lm_at_search' => $filters['search'],
      'lm_at_search_mode' => $filters['search_mode'],
      'lm_at_location' => $filters['location'],
      'lm_at_source_type' => $filters['source_type'],
      'lm_at_link_type' => $filters['link_type'],
      'lm_at_value' => $filters['value_contains'],
      'lm_at_source' => $filters['source_contains'],
      'lm_at_title' => $filters['title_contains'],
      'lm_at_author' => $filters['author_contains'],
      'lm_at_seo_flag' => $filters['seo_flag'],
      'lm_at_usage_type' => $filters['usage_type'],
      'lm_at_quality' => $filters['quality'],
      'lm_at_group' => $filters['group'],
      'lm_at_min_total' => $filters['min_total'],
      'lm_at_max_total' => $filters['max_total'],
      'lm_at_min_inlink' => $filters['min_inlink'],
      'lm_at_max_inlink' => $filters['max_inlink'],
      'lm_at_min_outbound' => $filters['min_outbound'],
      'lm_at_max_outbound' => $filters['max_outbound'],
      'lm_at_min_pages' => $filters['min_pages'],
      'lm_at_max_pages' => $filters['max_pages'],
      'lm_at_min_destinations' => $filters['min_destinations'],
      'lm_at_max_destinations' => $filters['max_destinations'],
      'lm_at_orderby' => $filters['orderby'],
      'lm_at_order' => $filters['order'],
      'lm_at_per_page' => $filters['per_page'],
      'lm_at_paged' => $filters['paged'],
      'lm_at_rebuild' => $filters['rebuild'] ? '1' : '0',
    ];
    return admin_url('admin-post.php?' . http_build_query($args));
  }

  private function build_all_anchor_text_rows($all, $filters) {
    $anchorToGroups = [];
    $groups = $this->get_anchor_groups();
    foreach ($groups as $g) {
      $gname = trim((string)($g['name'] ?? ''));
      if ($gname === '') continue;
      foreach ((array)($g['anchors'] ?? []) as $a) {
        $a = trim((string)$a);
        if ($a === '') continue;
        $k = strtolower($a);
        if (!isset($anchorToGroups[$k])) $anchorToGroups[$k] = [];
        $anchorToGroups[$k][$gname] = true;
      }
    }

    $map = [];
    $textMode = (string)($filters['search_mode'] ?? 'contains');
    $allowedPostIds = $this->get_post_ids_by_post_terms(
      isset($filters['post_category']) ? (int)$filters['post_category'] : 0,
      isset($filters['post_tag']) ? (int)$filters['post_tag'] : 0
    );

    foreach ($all as $row) {
      if (is_array($allowedPostIds)) {
        $rowPostId = isset($row['post_id']) ? (string)intval($row['post_id']) : '';
        if ($rowPostId === '' || !isset($allowedPostIds[$rowPostId])) continue;
      }
      if (($filters['post_type'] ?? 'any') !== 'any' && (string)($row['post_type'] ?? '') !== (string)$filters['post_type']) continue;
      if (($filters['location'] ?? 'any') !== 'any' && (string)($row['link_location'] ?? '') !== (string)$filters['location']) continue;
      if (($filters['source_type'] ?? 'any') !== 'any' && (string)($row['source'] ?? '') !== (string)$filters['source_type']) continue;
      if (($filters['link_type'] ?? 'any') !== 'any' && (string)($row['link_type'] ?? '') !== (string)$filters['link_type']) continue;

      if ((string)($filters['value_contains'] ?? '') !== '' && !$this->text_matches((string)($row['link'] ?? ''), (string)$filters['value_contains'], $textMode)) continue;
      if ((string)($filters['source_contains'] ?? '') !== '' && !$this->text_matches((string)($row['page_url'] ?? ''), (string)$filters['source_contains'], $textMode)) continue;
      if ((string)($filters['title_contains'] ?? '') !== '' && !$this->text_matches((string)($row['post_title'] ?? ''), (string)$filters['title_contains'], $textMode)) continue;
      if ((string)($filters['author_contains'] ?? '') !== '' && !$this->text_matches((string)($row['post_author'] ?? ''), (string)$filters['author_contains'], $textMode)) continue;

      $seoFlag = (string)($filters['seo_flag'] ?? 'any');
      if ($seoFlag !== 'any') {
        $nofollow = (string)($row['rel_nofollow'] ?? '0') === '1';
        $sponsored = (string)($row['rel_sponsored'] ?? '0') === '1';
        $ugc = (string)($row['rel_ugc'] ?? '0') === '1';
        if ($seoFlag === 'dofollow' && ($nofollow || $sponsored || $ugc)) continue;
        if ($seoFlag === 'nofollow' && !$nofollow) continue;
        if ($seoFlag === 'sponsored' && !$sponsored) continue;
        if ($seoFlag === 'ugc' && !$ugc) continue;
      }

      $anchor = trim((string)($row['anchor_text'] ?? ''));
      if ($anchor === '') continue;
      $key = strtolower($anchor);

      if (!isset($map[$key])) {
        $map[$key] = [
          'anchor_text' => $anchor,
          'total' => 0,
          'inlink' => 0,
          'outbound' => 0,
          'source_pages' => [],
          'destinations' => [],
          'source_types' => [],
        ];
      }

      $map[$key]['total']++;
      if (($row['link_type'] ?? '') === 'inlink') $map[$key]['inlink']++;
      if (($row['link_type'] ?? '') === 'exlink') $map[$key]['outbound']++;

      $sourceType = trim((string)($row['source'] ?? ''));
      if ($sourceType === '') $sourceType = 'unknown';
      $map[$key]['source_types'][$sourceType] = true;

      $pageUrl = trim((string)($row['page_url'] ?? ''));
      if ($pageUrl !== '') $map[$key]['source_pages'][$pageUrl] = true;

      $dest = trim((string)($row['link'] ?? ''));
      if ($dest !== '') $map[$key]['destinations'][$dest] = true;
    }

    $rows = [];
    foreach ($map as $item) {
      $usageType = 'mixed';
      if ($item['inlink'] > 0 && $item['outbound'] === 0) $usageType = 'inlink_only';
      if ($item['outbound'] > 0 && $item['inlink'] === 0) $usageType = 'outbound_only';

      $quality = $this->get_anchor_quality_label($item['anchor_text']);
      $sourceTypes = array_keys($item['source_types']);
      sort($sourceTypes);

      $rows[] = [
        'anchor_text' => $item['anchor_text'],
        'quality' => $quality,
        'usage_type' => $usageType,
        'total' => (int)$item['total'],
        'inlink' => (int)$item['inlink'],
        'outbound' => (int)$item['outbound'],
        'source_pages' => count($item['source_pages']),
        'destinations' => count($item['destinations']),
        'source_types' => implode(', ', $sourceTypes),
        'source_types_map' => $item['source_types'],
      ];
    }

    $filteredRows = [];
    $searchText = (string)($filters['search'] ?? '');
    $sourceTypeWanted = (string)($filters['source_type'] ?? 'any');
    $usageTypeWanted = (string)($filters['usage_type'] ?? 'any');
    $qualityWanted = (string)($filters['quality'] ?? 'any');
    $searchMode = (string)($filters['search_mode'] ?? 'contains');
    $minTotal = (int)($filters['min_total'] ?? 0);
    $maxTotal = (int)($filters['max_total'] ?? -1);
    $minInlink = (int)($filters['min_inlink'] ?? 0);
    $maxInlink = (int)($filters['max_inlink'] ?? -1);
    $minOutbound = (int)($filters['min_outbound'] ?? 0);
    $maxOutbound = (int)($filters['max_outbound'] ?? -1);
    $minPages = (int)($filters['min_pages'] ?? 0);
    $maxPages = (int)($filters['max_pages'] ?? -1);
    $minDestinations = (int)($filters['min_destinations'] ?? 0);
    $maxDestinations = (int)($filters['max_destinations'] ?? -1);
    $selectedGroup = (string)($filters['group'] ?? 'any');

    $hasSearchText = $searchText !== '';
    $hasGroupFilter = $selectedGroup !== 'any';

    foreach ($rows as $r) {
      if ($hasSearchText && !$this->text_matches((string)$r['anchor_text'], $searchText, $searchMode)) continue;
      if ($sourceTypeWanted !== 'any' && !isset($r['source_types_map'][$sourceTypeWanted])) continue;
      if ($usageTypeWanted !== 'any' && (string)$r['usage_type'] !== $usageTypeWanted) continue;
      if ($qualityWanted !== 'any' && (string)$r['quality'] !== $qualityWanted) continue;
      if ((int)$r['total'] < $minTotal) continue;
      if ($maxTotal >= 0 && (int)$r['total'] > $maxTotal) continue;
      if ((int)$r['inlink'] < $minInlink) continue;
      if ($maxInlink >= 0 && (int)$r['inlink'] > $maxInlink) continue;
      if ((int)$r['outbound'] < $minOutbound) continue;
      if ($maxOutbound >= 0 && (int)$r['outbound'] > $maxOutbound) continue;
      if ((int)$r['source_pages'] < $minPages) continue;
      if ($maxPages >= 0 && (int)$r['source_pages'] > $maxPages) continue;
      if ((int)$r['destinations'] < $minDestinations) continue;
      if ($maxDestinations >= 0 && (int)$r['destinations'] > $maxDestinations) continue;

      if ($hasGroupFilter) {
        $anchorKey = strtolower(trim((string)($r['anchor_text'] ?? '')));
        if ($anchorKey === '') continue;
        $groupsForAnchor = isset($anchorToGroups[$anchorKey]) ? array_keys($anchorToGroups[$anchorKey]) : [];
        if ($selectedGroup === 'no_group') {
          if (!empty($groupsForAnchor)) continue;
        } else {
          if (!in_array($selectedGroup, $groupsForAnchor, true)) continue;
        }
      }

      $filteredRows[] = $r;
    }

    $rows = $filteredRows;

    usort($rows, function($a, $b) use ($filters) {
      $dir = $filters['order'] === 'ASC' ? 1 : -1;
      $ord = $filters['orderby'];

      if ($ord === 'anchor') {
        $cmp = strcmp((string)$a['anchor_text'], (string)$b['anchor_text']);
        return $cmp * $dir;
      }

      if ($ord === 'inlink') {
        $cmp = ((int)$a['inlink'] <=> (int)$b['inlink']);
      } elseif ($ord === 'outbound') {
        $cmp = ((int)$a['outbound'] <=> (int)$b['outbound']);
      } elseif ($ord === 'pages') {
        $cmp = ((int)$a['source_pages'] <=> (int)$b['source_pages']);
      } elseif ($ord === 'destinations') {
        $cmp = ((int)$a['destinations'] <=> (int)$b['destinations']);
      } elseif ($ord === 'quality') {
        $cmp = strcmp((string)$a['quality'], (string)$b['quality']);
      } elseif ($ord === 'source_types') {
        $cmp = strcmp((string)$a['source_types'], (string)$b['source_types']);
      } elseif ($ord === 'usage_type') {
        $cmp = strcmp((string)$a['usage_type'], (string)$b['usage_type']);
      } else {
        $cmp = ((int)$a['total'] <=> (int)$b['total']);
      }

      if ($cmp === 0) $cmp = strcmp((string)$a['anchor_text'], (string)$b['anchor_text']);
      return $cmp * $dir;
    });

    return $rows;
  }

  private function render_all_anchor_text_pagination($filters, $paged, $totalPages) {
    if ($totalPages <= 1) return;

    echo '<div class="tablenav" style="margin:10px 0;">';
    echo '<div class="tablenav-pages">';
    echo '<span class="displaying-num">Page ' . esc_html((string)$paged) . ' of ' . esc_html((string)$totalPages) . '</span> ';

    $prev = max(1, $paged - 1);
    $next = min($totalPages, $paged + 1);

    echo '<a class="button" href="' . esc_url($this->all_anchor_text_admin_url($filters, ['lm_at_paged' => 1])) . '">&laquo; First</a> ';
    echo '<a class="button" href="' . esc_url($this->all_anchor_text_admin_url($filters, ['lm_at_paged' => $prev])) . '">&lsaquo; Previous</a> ';
    echo '<a class="button" href="' . esc_url($this->all_anchor_text_admin_url($filters, ['lm_at_paged' => $next])) . '">Next &rsaquo;</a> ';
    echo '<a class="button" href="' . esc_url($this->all_anchor_text_admin_url($filters, ['lm_at_paged' => $totalPages])) . '">Last &raquo;</a>';

    echo '</div></div>';
  }

  public function render_admin_all_anchor_text_page() {
    if (!$this->current_user_can_access_plugin()) wp_die('Unauthorized');

    $filters = $this->get_all_anchor_text_filters_from_request();
    $exportUrl = $this->build_all_anchor_text_export_url($filters);
    $postCategoryOptions = $this->get_post_term_options('category');
    $postTagOptions = $this->get_post_term_options('post_tag');
    $groupOptions = [];
    foreach ($this->get_anchor_groups() as $g) {
      $gname = trim((string)($g['name'] ?? ''));
      if ($gname !== '') $groupOptions[$gname] = $gname;
    }
    ksort($groupOptions);

    $all = $this->build_or_get_cache('any', $filters['rebuild'], isset($filters['wpml_lang']) ? $filters['wpml_lang'] : 'all');
    $rows = $this->build_all_anchor_text_rows($all, $filters);

    $total = count($rows);
    $perPage = $filters['per_page'];
    $totalPages = max(1, (int)ceil($total / $perPage));
    if ($filters['paged'] > $totalPages) $filters['paged'] = $totalPages;
    $offset = ($filters['paged'] - 1) * $perPage;
    $pageRows = array_slice($rows, $offset, $perPage);

    $qualitySummary = [
      'good' => ['total' => 0, 'inlink' => 0, 'outbound' => 0],
      'poor' => ['total' => 0, 'inlink' => 0, 'outbound' => 0],
      'bad' => ['total' => 0, 'inlink' => 0, 'outbound' => 0],
    ];
    foreach ($rows as $summaryRow) {
      $qKey = isset($summaryRow['quality']) ? (string)$summaryRow['quality'] : 'poor';
      if (!isset($qualitySummary[$qKey])) {
        $qualitySummary[$qKey] = ['total' => 0, 'inlink' => 0, 'outbound' => 0];
      }
      $qualitySummary[$qKey]['total'] += (int)($summaryRow['total'] ?? 0);
      $qualitySummary[$qKey]['inlink'] += (int)($summaryRow['inlink'] ?? 0);
      $qualitySummary[$qKey]['outbound'] += (int)($summaryRow['outbound'] ?? 0);
    }
    $qualityTotalBase = 0;
    $qualityInlinkBase = 0;
    $qualityOutboundBase = 0;
    foreach ($qualitySummary as $qRow) {
      $qualityTotalBase += (int)$qRow['total'];
      $qualityInlinkBase += (int)$qRow['inlink'];
      $qualityOutboundBase += (int)$qRow['outbound'];
    }

    echo '<div class="wrap lm-wrap">';
    echo '<h1 class="lm-page-title">Links Manager - All Anchor Text</h1>';
    echo '<div class="lm-subtle">All anchor text usage across Inlink and Outbound categories.</div>';

    echo '<div class="lm-card lm-card-full">';
    echo '<h2 style="margin-top:0;">Filter</h2>';
    echo '<form method="get" action="">';
    echo '<input type="hidden" name="page" value="links-manager-all-anchor-text"/>';
    echo '<table class="form-table lm-filter-table" role="presentation"><tbody>';
    echo '<tr><th scope="row">Post Type</th><td><select name="lm_at_post_type">';
    echo '<option value="any"' . selected($filters['post_type'], 'any', false) . '>All</option>';
    foreach ($this->get_filterable_post_types() as $ptKey => $ptLabel) {
      echo '<option value="' . esc_attr((string)$ptKey) . '"' . selected($filters['post_type'], (string)$ptKey, false) . '>' . esc_html((string)$ptLabel) . ' (' . esc_html((string)$ptKey) . ')</option>';
    }
    echo '</select></td></tr>';
    echo '<tr><th scope="row">Post Category</th><td><select name="lm_at_post_category">';
    echo '<option value="0"' . selected((int)($filters['post_category'] ?? 0), 0, false) . '>All</option>';
    foreach ($postCategoryOptions as $termId => $termLabel) {
      echo '<option value="' . esc_attr((string)$termId) . '"' . selected((int)($filters['post_category'] ?? 0), (int)$termId, false) . '>' . esc_html($termLabel) . '</option>';
    }
    echo '</select><div class="lm-small">Applied to WordPress posts only.</div></td></tr>';
    echo '<tr><th scope="row">Post Tag</th><td><select name="lm_at_post_tag">';
    echo '<option value="0"' . selected((int)($filters['post_tag'] ?? 0), 0, false) . '>All</option>';
    foreach ($postTagOptions as $termId => $termLabel) {
      echo '<option value="' . esc_attr((string)$termId) . '"' . selected((int)($filters['post_tag'] ?? 0), (int)$termId, false) . '>' . esc_html($termLabel) . '</option>';
    }
    echo '</select><div class="lm-small">Applied to WordPress posts only.</div></td></tr>';
    echo '<tr><th scope="row">Link Location</th><td><input type="text" name="lm_at_location" value="' . esc_attr($filters['location'] === 'any' ? '' : (string)$filters['location']) . '" class="regular-text" placeholder="content / excerpt / meta:xxx" /><div class="lm-small">Leave empty for All.</div></td></tr>';
    echo '<tr><th scope="row">Link Type</th><td><select name="lm_at_link_type">';
    echo '<option value="any"' . selected($filters['link_type'], 'any', false) . '>All</option>';
    echo '<option value="inlink"' . selected($filters['link_type'], 'inlink', false) . '>Internal</option>';
    echo '<option value="exlink"' . selected($filters['link_type'], 'exlink', false) . '>External</option>';
    echo '</select></td></tr>';
    echo '<tr><th scope="row">Search Destination URL</th><td><input type="text" name="lm_at_value" value="' . esc_attr($filters['value_contains']) . '" class="regular-text" placeholder="example.com / /contact" /></td></tr>';
    echo '<tr><th scope="row">Search Source URL</th><td><input type="text" name="lm_at_source" value="' . esc_attr($filters['source_contains']) . '" class="regular-text" placeholder="/category /slug" /></td></tr>';
    echo '<tr><th scope="row">Search Title</th><td><input type="text" name="lm_at_title" value="' . esc_attr($filters['title_contains']) . '" class="regular-text" placeholder="post title" /></td></tr>';
    echo '<tr><th scope="row">Search Author</th><td><input type="text" name="lm_at_author" value="' . esc_attr($filters['author_contains']) . '" class="regular-text" placeholder="author" /></td></tr>';
    echo '<tr><th scope="row">Search Anchor Text</th><td><input type="text" name="lm_at_search" value="' . esc_attr($filters['search']) . '" class="regular-text" placeholder="anchor keyword" /></td></tr>';
    echo '<tr><th scope="row">Text Search Mode</th><td><select name="lm_at_search_mode">';
    foreach ($this->get_text_match_modes() as $modeKey => $modeLabel) {
      echo '<option value="' . esc_attr($modeKey) . '"' . selected($filters['search_mode'], $modeKey, false) . '>' . esc_html($modeLabel) . '</option>';
    }
    echo '</select><div class="lm-small">Applies to all text filters on this page.</div></td></tr>';
    echo '<tr><th scope="row">Source Type</th><td><select name="lm_at_source_type">';
    foreach ($this->get_filterable_source_type_options(true) as $sourceKey => $sourceLabel) {
      echo '<option value="' . esc_attr((string)$sourceKey) . '"' . selected($filters['source_type'], (string)$sourceKey, false) . '>' . esc_html((string)$sourceLabel) . '</option>';
    }
    echo '</select></td></tr>';
    echo '<tr><th scope="row">Usage Type</th><td><select name="lm_at_usage_type">';
    echo '<option value="any"' . selected($filters['usage_type'], 'any', false) . '>All</option>';
    echo '<option value="mixed"' . selected($filters['usage_type'], 'mixed', false) . '>Mixed</option>';
    echo '<option value="inlink_only"' . selected($filters['usage_type'], 'inlink_only', false) . '>Inlink Only</option>';
    echo '<option value="outbound_only"' . selected($filters['usage_type'], 'outbound_only', false) . '>Outbound Only</option>';
    echo '</select></td></tr>';
    echo '<tr><th scope="row">Quality</th><td><select name="lm_at_quality">';
    echo '<option value="any"' . selected($filters['quality'], 'any', false) . '>All</option>';
    echo '<option value="good"' . selected($filters['quality'], 'good', false) . '>Good</option>';
    echo '<option value="poor"' . selected($filters['quality'], 'poor', false) . '>Poor</option>';
    echo '<option value="bad"' . selected($filters['quality'], 'bad', false) . '>Bad</option>';
    echo '</select></td></tr>';
    echo '<tr><th scope="row">Group</th><td><select name="lm_at_group">';
    echo '<option value="any"' . selected($filters['group'], 'any', false) . '>All</option>';
    echo '<option value="no_group"' . selected($filters['group'], 'no_group', false) . '>No Group</option>';
    foreach ($groupOptions as $groupName) {
      echo '<option value="' . esc_attr($groupName) . '"' . selected($filters['group'], $groupName, false) . '>' . esc_html($groupName) . '</option>';
    }
    echo '</select></td></tr>';
    echo '<tr><th scope="row">SEO Flags</th><td><select name="lm_at_seo_flag">';
    echo '<option value="any"' . selected($filters['seo_flag'], 'any', false) . '>All</option>';
    echo '<option value="dofollow"' . selected($filters['seo_flag'], 'dofollow', false) . '>Dofollow</option>';
    echo '<option value="nofollow"' . selected($filters['seo_flag'], 'nofollow', false) . '>Nofollow</option>';
    echo '<option value="sponsored"' . selected($filters['seo_flag'], 'sponsored', false) . '>Sponsored</option>';
    echo '<option value="ugc"' . selected($filters['seo_flag'], 'ugc', false) . '>UGC</option>';
    echo '</select></td></tr>';
    echo '<tr><th scope="row">Total (Min/Max)</th><td>';
    echo '<input type="number" name="lm_at_min_total" min="0" value="' . esc_attr((string)$filters['min_total']) . '" placeholder="Min" style="width:120px; margin-right:8px;" />';
    echo '<input type="number" name="lm_at_max_total" min="0" value="' . esc_attr($filters['max_total'] >= 0 ? (string)$filters['max_total'] : '') . '" placeholder="Max" style="width:120px;" />';
    echo '</td></tr>';
    echo '<tr><th scope="row">Inlink (Min/Max)</th><td>';
    echo '<input type="number" name="lm_at_min_inlink" min="0" value="' . esc_attr((string)$filters['min_inlink']) . '" placeholder="Min" style="width:120px; margin-right:8px;" />';
    echo '<input type="number" name="lm_at_max_inlink" min="0" value="' . esc_attr($filters['max_inlink'] >= 0 ? (string)$filters['max_inlink'] : '') . '" placeholder="Max" style="width:120px;" />';
    echo '</td></tr>';
    echo '<tr><th scope="row">Outbound (Min/Max)</th><td>';
    echo '<input type="number" name="lm_at_min_outbound" min="0" value="' . esc_attr((string)$filters['min_outbound']) . '" placeholder="Min" style="width:120px; margin-right:8px;" />';
    echo '<input type="number" name="lm_at_max_outbound" min="0" value="' . esc_attr($filters['max_outbound'] >= 0 ? (string)$filters['max_outbound'] : '') . '" placeholder="Max" style="width:120px;" />';
    echo '</td></tr>';
    echo '<tr><th scope="row">Unique Source Pages (Min/Max)</th><td>';
    echo '<input type="number" name="lm_at_min_pages" min="0" value="' . esc_attr((string)$filters['min_pages']) . '" placeholder="Min" style="width:120px; margin-right:8px;" />';
    echo '<input type="number" name="lm_at_max_pages" min="0" value="' . esc_attr($filters['max_pages'] >= 0 ? (string)$filters['max_pages'] : '') . '" placeholder="Max" style="width:120px;" />';
    echo '</td></tr>';
    echo '<tr><th scope="row">Unique Destination URLs (Min/Max)</th><td>';
    echo '<input type="number" name="lm_at_min_destinations" min="0" value="' . esc_attr((string)$filters['min_destinations']) . '" placeholder="Min" style="width:120px; margin-right:8px;" />';
    echo '<input type="number" name="lm_at_max_destinations" min="0" value="' . esc_attr($filters['max_destinations'] >= 0 ? (string)$filters['max_destinations'] : '') . '" placeholder="Max" style="width:120px;" />';
    echo '</td></tr>';
    echo '<tr><th scope="row">Order By</th><td>';
    echo '<select name="lm_at_orderby">';
    echo '<option value="total"' . selected($filters['orderby'], 'total', false) . '>Total</option>';
    echo '<option value="inlink"' . selected($filters['orderby'], 'inlink', false) . '>Inlink</option>';
    echo '<option value="outbound"' . selected($filters['orderby'], 'outbound', false) . '>External Outbound</option>';
    echo '<option value="pages"' . selected($filters['orderby'], 'pages', false) . '>Unique Source Pages</option>';
    echo '<option value="destinations"' . selected($filters['orderby'], 'destinations', false) . '>Unique Destination URLs</option>';
    echo '<option value="anchor"' . selected($filters['orderby'], 'anchor', false) . '>Anchor Text</option>';
    echo '<option value="quality"' . selected($filters['orderby'], 'quality', false) . '>Quality</option>';
    echo '<option value="source_types"' . selected($filters['orderby'], 'source_types', false) . '>Source Types</option>';
    echo '<option value="usage_type"' . selected($filters['orderby'], 'usage_type', false) . '>Usage Type</option>';
    echo '</select> ';
    echo '<select name="lm_at_order">';
    echo '<option value="DESC"' . selected($filters['order'], 'DESC', false) . '>DESC</option>';
    echo '<option value="ASC"' . selected($filters['order'], 'ASC', false) . '>ASC</option>';
    echo '</select>';
    echo '</td></tr>';
    echo '<tr><th scope="row">Cache</th><td><label><input type="checkbox" name="lm_at_rebuild" value="1"' . checked($filters['rebuild'] ? '1' : '0', '1', false) . '> Rebuild cache</label></td></tr>';
    echo '<tr><th scope="row">Per Page</th><td><input type="number" name="lm_at_per_page" min="10" max="500" value="' . esc_attr((string)$filters['per_page']) . '" style="width:90px;" /></td></tr>';
    echo '<tr><th scope="row">Export</th><td><a class="button button-secondary" href="' . esc_url($exportUrl) . '">Export CSV</a><div class="lm-small">Export follows the current filters.</div></td></tr>';
    echo '</tbody></table>';
    submit_button('Apply Filters', 'primary', 'submit', false);
    echo ' <a class="button" href="' . esc_url(admin_url('admin.php?page=links-manager-all-anchor-text')) . '">Reset Filter</a>';
    echo '</form>';
    echo '</div>';

    echo '<div class="lm-card lm-card-full">';
    echo '<div style="font-weight:bold;">Total: ' . esc_html((string)$total) . ' anchor texts</div>';
    echo '<div class="lm-small" style="margin-top:6px;">';
    echo '<strong>Quality rule:</strong> ';
    echo esc_html($this->get_anchor_quality_status_help_text());
    echo '</div>';
    echo '<div class="lm-small" style="margin-top:6px;">';
    echo '<strong>Column guide:</strong> Unique Source Pages = number of unique source page URLs using the anchor text. Unique Destination URLs = number of unique target URLs linked by the anchor text.';
    echo '</div>';
    $quickFilters = [
      'any' => 'All',
      'mixed' => 'Mixed',
      'inlink_only' => 'Inlink Only',
      'outbound_only' => 'Outbound Only',
    ];
    echo '<div style="margin-top:8px;">';
    foreach ($quickFilters as $k => $label) {
      $btnClass = ((string)$filters['usage_type'] === (string)$k) ? 'button button-primary' : 'button button-secondary';
      $url = $this->all_anchor_text_admin_url($filters, ['lm_at_usage_type' => $k, 'lm_at_paged' => 1]);
      echo '<a class="' . esc_attr($btnClass) . '" href="' . esc_url($url) . '" style="margin-right:6px; margin-top:4px;">' . esc_html($label) . '</a>';
    }
    echo '</div>';
    echo '</div>';

    echo '<div class="lm-card lm-card-full">';
    echo '<h2 style="margin-top:0;">Anchor Text Summary</h2>';
    echo '<div class="lm-small" style="margin-bottom:8px;">Based on current filtered results.</div>';
    echo '<div class="lm-small" style="margin-bottom:8px;">Quality status: ' . esc_html($this->get_anchor_quality_status_help_text()) . '</div>';
    echo '<div class="lm-table-wrap lm-summary-table-wrap">';
    echo '<table class="widefat striped lm-table">';
    echo '<thead><tr>';
    echo '<th class="lm-col-quality">Quality</th>';
    echo '<th class="lm-col-count">Total</th>';
    echo '<th class="lm-col-count">%</th>';
    echo '<th class="lm-col-count">Inlink</th>';
    echo '<th class="lm-col-count">%</th>';
    echo '<th class="lm-col-count">Outbound</th>';
    echo '<th class="lm-col-count">%</th>';
    echo '</tr></thead><tbody>';

    foreach (['good' => 'Good', 'poor' => 'Poor', 'bad' => 'Bad'] as $qualityKey => $qualityLabel) {
      $rowTotal = (int)($qualitySummary[$qualityKey]['total'] ?? 0);
      $rowInlink = (int)($qualitySummary[$qualityKey]['inlink'] ?? 0);
      $rowOutbound = (int)($qualitySummary[$qualityKey]['outbound'] ?? 0);
      $pctTotal = $qualityTotalBase > 0 ? (($rowTotal / $qualityTotalBase) * 100) : 0;
      $pctInlink = $qualityInlinkBase > 0 ? (($rowInlink / $qualityInlinkBase) * 100) : 0;
      $pctOutbound = $qualityOutboundBase > 0 ? (($rowOutbound / $qualityOutboundBase) * 100) : 0;

      echo '<tr>';
      echo '<td class="lm-col-quality">' . esc_html($qualityLabel) . '</td>';
      echo '<td class="lm-col-count" style="text-align:center;">' . esc_html((string)$rowTotal) . '</td>';
      echo '<td class="lm-col-count" style="text-align:center;">' . esc_html(number_format((float)$pctTotal, 1)) . '%</td>';
      echo '<td class="lm-col-count" style="text-align:center;">' . esc_html((string)$rowInlink) . '</td>';
      echo '<td class="lm-col-count" style="text-align:center;">' . esc_html(number_format((float)$pctInlink, 1)) . '%</td>';
      echo '<td class="lm-col-count" style="text-align:center;">' . esc_html((string)$rowOutbound) . '</td>';
      echo '<td class="lm-col-count" style="text-align:center;">' . esc_html(number_format((float)$pctOutbound, 1)) . '%</td>';
      echo '</tr>';
    }

    echo '</tbody></table></div>';
    echo '</div>';

    echo '<div class="lm-small" style="margin:0 0 8px;"><strong>Quality status:</strong> Good = descriptive anchor text (3-100 chars), Poor = weak/generic or length issue, Bad = empty anchor text.</div>';
    echo '<div class="lm-table-wrap">';
    echo '<table class="widefat striped lm-table">';
    echo '<thead><tr>';
    echo $this->table_header_with_tooltip('lm-col-postid', '#', 'Row number in current result page.', 'left');
    echo $this->table_header_with_tooltip('lm-col-anchor', 'Anchor Text', 'Visible anchor text used in links.');
    echo $this->table_header_with_tooltip('lm-col-quality', 'Quality', 'Anchor quality based on weak phrase and length rules.');
    echo $this->table_header_with_tooltip('lm-col-count', 'Total', 'Total number of uses for this anchor text.');
    echo $this->table_header_with_tooltip('lm-col-count', 'Inlink', 'Count where this anchor points to internal URLs.');
    echo $this->table_header_with_tooltip('lm-col-count', 'Outbound', 'Count where this anchor points to external URLs.');
    echo $this->table_header_with_tooltip('lm-col-count', 'Unique Source Pages', 'Number of unique source pages using this anchor.');
    echo $this->table_header_with_tooltip('lm-col-count', 'Unique Destination URLs', 'Number of unique destination URLs linked by this anchor.');
    echo $this->table_header_with_tooltip('lm-col-source', 'Source Types', 'Where links were found: content, excerpt, meta, or menu.');
    echo $this->table_header_with_tooltip('lm-col-type', 'Usage Type', 'Mixed, Inlink Only, or Outbound Only.', 'right');
    echo '</tr></thead><tbody>';

    if (empty($pageRows)) {
      echo '<tr><td colspan="10">No data.</td></tr>';
    } else {
      $rowNo = $offset + 1;
      foreach ($pageRows as $row) {
        $qualityLabel = 'Good';
        if ($row['quality'] === 'poor') $qualityLabel = 'Poor';
        if ($row['quality'] === 'bad') $qualityLabel = 'Bad';
        $usageLabel = 'Mixed';
        if ($row['usage_type'] === 'inlink_only') $usageLabel = 'Inlink Only';
        if ($row['usage_type'] === 'outbound_only') $usageLabel = 'Outbound Only';

        echo '<tr>';
        echo '<td class="lm-col-postid">' . esc_html((string)$rowNo) . '</td>';
        echo '<td class="lm-col-anchor"><span class="lm-trunc" title="' . esc_attr((string)$row['anchor_text']) . '">' . esc_html((string)$row['anchor_text']) . '</span></td>';
        echo '<td class="lm-col-quality">' . esc_html($qualityLabel) . '</td>';
        echo '<td class="lm-col-count" style="text-align:center;">' . esc_html((string)$row['total']) . '</td>';
        echo '<td class="lm-col-count" style="text-align:center;">' . esc_html((string)$row['inlink']) . '</td>';
        echo '<td class="lm-col-count" style="text-align:center;">' . esc_html((string)$row['outbound']) . '</td>';
        echo '<td class="lm-col-count" style="text-align:center;">' . esc_html((string)$row['source_pages']) . '</td>';
        echo '<td class="lm-col-count" style="text-align:center;">' . esc_html((string)$row['destinations']) . '</td>';
        echo '<td class="lm-col-source"><span class="lm-trunc" title="' . esc_attr((string)$row['source_types']) . '">' . esc_html((string)$row['source_types']) . '</span></td>';
        echo '<td class="lm-col-type">' . esc_html($usageLabel) . '</td>';
        echo '</tr>';

        $rowNo++;
      }
    }

    echo '</tbody></table></div>';
    $this->render_all_anchor_text_pagination($filters, $filters['paged'], $totalPages);
    echo '</div>';
  }

  public function render_admin_cited_domains_page() {
    if (!$this->current_user_can_access_plugin()) wp_die('Unauthorized');

    $filters = $this->get_cited_domains_filters_from_request();
    $exportUrl = $this->build_cited_domains_export_url($filters);
    $postCategoryOptions = $this->get_post_term_options('category');
    $postTagOptions = $this->get_post_term_options('post_tag');

    $all = $this->build_or_get_cache('any', $filters['rebuild'], isset($filters['wpml_lang']) ? $filters['wpml_lang'] : 'all');
    $rows = $this->build_cited_domains_summary_rows($all, $filters);

    $total = count($rows);
    $perPage = $filters['per_page'];
    $totalPages = max(1, (int)ceil($total / $perPage));
    if ($filters['paged'] > $totalPages) $filters['paged'] = $totalPages;
    $offset = ($filters['paged'] - 1) * $perPage;
    $pageRows = array_slice($rows, $offset, $perPage);

    echo '<div class="wrap lm-wrap">';
    echo '<h1 class="lm-page-title">Links Manager - Cited External Domains</h1>';
    echo '<div class="lm-subtle">List external domains most frequently cited from your published content.</div>';

    echo '<div class="lm-card lm-card-full">';
    echo '<h2 style="margin-top:0;">Filter</h2>';
    echo '<form method="get" action="">';
    echo '<input type="hidden" name="page" value="links-manager-cited-domains"/>';
    echo '<table class="form-table lm-filter-table" role="presentation"><tbody>';
    echo '<tr><th scope="row">Post Type</th><td><select name="lm_cd_post_type">';
    echo '<option value="any"' . selected($filters['post_type'], 'any', false) . '>All</option>';
    foreach ($this->get_filterable_post_types() as $ptKey => $ptLabel) {
      echo '<option value="' . esc_attr((string)$ptKey) . '"' . selected($filters['post_type'], (string)$ptKey, false) . '>' . esc_html((string)$ptLabel) . ' (' . esc_html((string)$ptKey) . ')</option>';
    }
    echo '</select></td></tr>';
    echo '<tr><th scope="row">Post Category</th><td><select name="lm_cd_post_category">';
    echo '<option value="0"' . selected((int)($filters['post_category'] ?? 0), 0, false) . '>All</option>';
    foreach ($postCategoryOptions as $termId => $termLabel) {
      echo '<option value="' . esc_attr((string)$termId) . '"' . selected((int)($filters['post_category'] ?? 0), (int)$termId, false) . '>' . esc_html($termLabel) . '</option>';
    }
    echo '</select><div class="lm-small">Applied to WordPress posts only.</div></td></tr>';
    echo '<tr><th scope="row">Post Tag</th><td><select name="lm_cd_post_tag">';
    echo '<option value="0"' . selected((int)($filters['post_tag'] ?? 0), 0, false) . '>All</option>';
    foreach ($postTagOptions as $termId => $termLabel) {
      echo '<option value="' . esc_attr((string)$termId) . '"' . selected((int)($filters['post_tag'] ?? 0), (int)$termId, false) . '>' . esc_html($termLabel) . '</option>';
    }
    echo '</select><div class="lm-small">Applied to WordPress posts only.</div></td></tr>';
    echo '<tr><th scope="row">Link Location</th><td><input type="text" name="lm_cd_location" value="' . esc_attr($filters['location'] === 'any' ? '' : (string)$filters['location']) . '" class="regular-text" placeholder="content / excerpt / meta:xxx" /><div class="lm-small">Leave empty for All.</div></td></tr>';
    echo '<tr><th scope="row">Source Type</th><td><select name="lm_cd_source_type">';
    foreach ($this->get_filterable_source_type_options(true) as $sourceKey => $sourceLabel) {
      echo '<option value="' . esc_attr((string)$sourceKey) . '"' . selected($filters['source_type'], (string)$sourceKey, false) . '>' . esc_html((string)$sourceLabel) . '</option>';
    }
    echo '</select></td></tr>';
    echo '<tr><th scope="row">Search Destination URL</th><td><input type="text" name="lm_cd_value" value="' . esc_attr($filters['value_contains']) . '" class="regular-text" placeholder="example.com / /contact" /></td></tr>';
    echo '<tr><th scope="row">Search Source URL</th><td><input type="text" name="lm_cd_source" value="' . esc_attr($filters['source_contains']) . '" class="regular-text" placeholder="/category /slug" /></td></tr>';
    echo '<tr><th scope="row">Search Title</th><td><input type="text" name="lm_cd_title" value="' . esc_attr($filters['title_contains']) . '" class="regular-text" placeholder="post title" /></td></tr>';
    echo '<tr><th scope="row">Search Author</th><td><input type="text" name="lm_cd_author" value="' . esc_attr($filters['author_contains']) . '" class="regular-text" placeholder="author" /></td></tr>';
    echo '<tr><th scope="row">Search Anchor Text</th><td><input type="text" name="lm_cd_anchor" value="' . esc_attr($filters['anchor_contains']) . '" class="regular-text" placeholder="anchor keyword" /></td></tr>';
    echo '<tr><th scope="row">Search Domain</th><td><input type="text" name="lm_cd_search" value="' . esc_attr($filters['search']) . '" class="regular-text" placeholder="example.com" /></td></tr>';
    echo '<tr><th scope="row">Text Search Mode</th><td><select name="lm_cd_search_mode">';
    foreach ($this->get_text_match_modes() as $modeKey => $modeLabel) {
      echo '<option value="' . esc_attr($modeKey) . '"' . selected($filters['search_mode'], $modeKey, false) . '>' . esc_html($modeLabel) . '</option>';
    }
    echo '</select><div class="lm-small">Applies to text filters on this page.</div></td></tr>';
    echo '<tr><th scope="row">SEO Flags</th><td><select name="lm_cd_seo_flag">';
    echo '<option value="any"' . selected($filters['seo_flag'], 'any', false) . '>All</option>';
    echo '<option value="dofollow"' . selected($filters['seo_flag'], 'dofollow', false) . '>Dofollow</option>';
    echo '<option value="nofollow"' . selected($filters['seo_flag'], 'nofollow', false) . '>Nofollow</option>';
    echo '<option value="sponsored"' . selected($filters['seo_flag'], 'sponsored', false) . '>Sponsored</option>';
    echo '<option value="ugc"' . selected($filters['seo_flag'], 'ugc', false) . '>UGC</option>';
    echo '</select></td></tr>';
    echo '<tr><th scope="row">Min Cited Count</th><td><input type="number" name="lm_cd_min_cites" min="0" value="' . esc_attr((string)$filters['min_cites']) . '" style="width:90px;" /></td></tr>';
    echo '<tr><th scope="row">Min Unique Source Pages</th><td><input type="number" name="lm_cd_min_pages" min="0" value="' . esc_attr((string)$filters['min_pages']) . '" style="width:90px;" /></td></tr>';
    $maxCitesVal = (int)$filters['max_cites'] >= 0 ? (string)$filters['max_cites'] : '';
    $maxPagesVal = (int)$filters['max_pages'] >= 0 ? (string)$filters['max_pages'] : '';
    echo '<tr><th scope="row">Max Cited Count</th><td><input type="number" name="lm_cd_max_cites" min="0" value="' . esc_attr($maxCitesVal) . '" style="width:90px;" /></td></tr>';
    echo '<tr><th scope="row">Max Unique Source Pages</th><td><input type="number" name="lm_cd_max_pages" min="0" value="' . esc_attr($maxPagesVal) . '" style="width:90px;" /></td></tr>';
    echo '<tr><th scope="row">Order By</th><td>';
    echo '<select name="lm_cd_orderby">';
    echo '<option value="cites"' . selected($filters['orderby'], 'cites', false) . '>Cited Count</option>';
    echo '<option value="pages"' . selected($filters['orderby'], 'pages', false) . '>Unique Source Pages</option>';
    echo '<option value="domain"' . selected($filters['orderby'], 'domain', false) . '>Domain</option>';
    echo '<option value="sample_url"' . selected($filters['orderby'], 'sample_url', false) . '>Sample URL</option>';
    echo '</select> ';
    echo '<select name="lm_cd_order">';
    echo '<option value="DESC"' . selected($filters['order'], 'DESC', false) . '>DESC</option>';
    echo '<option value="ASC"' . selected($filters['order'], 'ASC', false) . '>ASC</option>';
    echo '</select>';
    echo '</td></tr>';
    echo '<tr><th scope="row">Cache</th><td><label><input type="checkbox" name="lm_cd_rebuild" value="1"' . checked($filters['rebuild'] ? '1' : '0', '1', false) . '> Rebuild cache</label></td></tr>';
    echo '<tr><th scope="row">Per Page</th><td><input type="number" name="lm_cd_per_page" min="10" max="500" value="' . esc_attr((string)$filters['per_page']) . '" style="width:90px;" /></td></tr>';
    echo '<tr><th scope="row">Export</th><td><a class="button button-secondary" href="' . esc_url($exportUrl) . '">Export CSV</a><div class="lm-small">Export follows the current filters and includes source page + anchor text.</div></td></tr>';
    echo '</tbody></table>';
    submit_button('Apply Filters', 'primary', 'submit', false);
    echo ' <a class="button" href="' . esc_url(admin_url('admin.php?page=links-manager-cited-domains')) . '">Reset Filter</a>';
    echo '</form>';
    echo '</div>';

    echo '<div class="lm-card lm-card-full">';
    echo '<div style="font-weight:bold;">Total: ' . esc_html((string)$total) . ' domains</div>';
    echo '<div class="lm-small" style="margin-top:6px;"><strong>Column guide:</strong> Cited Count = total external link mentions to the domain. Unique Source Pages = number of unique source page URLs that cite the domain.</div>';
    echo '</div>';

    echo '<div class="lm-table-wrap">';
    echo '<table class="widefat striped lm-table">';
    echo '<thead><tr>';
    echo $this->table_header_with_tooltip('lm-col-postid', '#', 'Row number in current result page.', 'left');
    echo $this->table_header_with_tooltip('lm-col-link', 'Domain', 'External domain detected in outbound links.');
    echo $this->table_header_with_tooltip('lm-col-count', 'Cited Count', 'Total number of citations to this domain.');
    echo $this->table_header_with_tooltip('lm-col-count', 'Unique Source Pages', 'Number of unique pages citing this domain.');
    echo $this->table_header_with_tooltip('lm-col-link', 'Sample URL', 'One sample destination URL from this domain.', 'right');
    echo '</tr></thead><tbody>';

    if (empty($pageRows)) {
      echo '<tr><td colspan="5">No data.</td></tr>';
    } else {
      $rowNumber = $offset + 1;
      foreach ($pageRows as $row) {
        $domain = (string)$row['domain'];
        $sample = (string)$row['sample_url'];
        $domainUrl = 'https://' . ltrim($domain, '/');

        echo '<tr>';
        echo '<td class="lm-col-postid">' . esc_html((string)$rowNumber) . '</td>';
        echo '<td class="lm-col-link"><a href="' . esc_url($domainUrl) . '" target="_blank" rel="noopener noreferrer"><span class="lm-trunc" title="' . esc_attr($domain) . '">' . esc_html($domain) . '</span></a></td>';
        echo '<td class="lm-col-count" style="text-align:center;">' . esc_html((string)$row['cites']) . '</td>';
        echo '<td class="lm-col-count" style="text-align:center;">' . esc_html((string)$row['pages']) . '</td>';
        echo '<td class="lm-col-link">' . ($sample !== '' ? '<a href="' . esc_url($sample) . '" target="_blank" rel="noopener noreferrer"><span class="lm-trunc" title="' . esc_attr($sample) . '">' . esc_html($sample) . '</span></a>' : '—') . '</td>';
        echo '</tr>';

        $rowNumber++;
      }
    }

    echo '</tbody></table></div>';
    $this->render_cited_domains_pagination($filters, $filters['paged'], $totalPages);
    echo '</div>';
  }

  /* -----------------------------
   * Admin page UI
   * ----------------------------- */

  public function render_admin_stats_page() {
    if (!$this->current_user_can_access_plugin()) wp_die('Unauthorized');

    $filters = $this->get_filters_from_request();
    $msg = isset($_GET['lm_msg']) ? sanitize_text_field((string)$_GET['lm_msg']) : '';
    $msgClass = $this->notice_class_for_message($msg, 'info');

    $all = $this->build_or_get_cache($filters['post_type'], $filters['rebuild'], isset($filters['wpml_lang']) ? $filters['wpml_lang'] : 'all');

    echo '<div class="wrap lm-wrap">';
    echo '<h1 class="lm-page-title">Links Manager - Statistics</h1>';
    echo '<div class="lm-subtle">Summary of link performance and quality in published content.</div>';

    if ($msg !== '') echo '<div class="notice notice-' . esc_attr($msgClass) . '"><p>' . esc_html($msg) . '</p></div>';

    $includeOrphanPages = false;
    $snapshot = $this->get_stats_snapshot_payload($all, $filters, $includeOrphanPages);
    $stats = $snapshot['stats'];
    $tops = $snapshot['tops'];
    $postTypeBuckets = $snapshot['post_type_buckets'];
    $anchorQualityBuckets = $snapshot['anchor_quality_buckets'];
    $maxPostType = (int)$snapshot['max_post_type'];
    $maxAnchor = (int)$snapshot['max_anchor'];
    $internal_count = (int)$snapshot['internal_count'];
    $external_count = (int)$snapshot['external_count'];
    $link_total = $internal_count + $external_count;
    $internal_pct = (int)$snapshot['internal_pct'];
    $external_pct = (int)$snapshot['external_pct'];
    $non_good_count = (int)($stats['non_good_anchor_text'] ?? 0);
    $non_good_pct = (float)($snapshot['non_good_pct'] ?? 0);
    
    echo '<div class="lm-stats-wrap">';
    echo '<div class="lm-stats-grid">';

    echo '<div class="lm-stat">';
    echo '<div class="lm-stat-label">Total Links</div>';
    echo '<div class="lm-stat-value">' . esc_html((string)$stats['total_links']) . '</div>';
    echo '<div class="lm-stat-sub">Internal: ' . esc_html((string)$stats['internal_links']) . ' • External: ' . esc_html((string)$stats['external_links']) . '</div>';
    echo '</div>';

    echo '<div class="lm-stat">';
    echo '<div class="lm-stat-label">Non-Good Anchor Text</div>';
    echo '<div class="lm-stat-value">' . esc_html((string)$non_good_count) . '</div>';
    echo '<div class="lm-stat-sub"><span class="lm-pill warn">' . esc_html((string)$non_good_pct) . '%</span> non-good anchor quality (Poor/Bad)</div>';
    echo '</div>';

    echo '<div class="lm-stat">';
    echo '<div class="lm-stat-label">Internal Links</div>';
    echo '<div class="lm-stat-value">' . esc_html((string)$stats['internal']['total']) . '</div>';
    echo '<div class="lm-stat-sub">Dofollow: ' . esc_html((string)$stats['internal']['dofollow']) . ' • Nofollow: ' . esc_html((string)$stats['internal']['nofollow']) . '</div>';
    echo '</div>';

    echo '<div class="lm-stat">';
    echo '<div class="lm-stat-label">External Links</div>';
    echo '<div class="lm-stat-value">' . esc_html((string)$stats['external']['dofollow'] + $stats['external']['nofollow']) . '</div>';
    echo '<div class="lm-stat-sub">Domains: ' . esc_html((string)$stats['external']['total_domains']) . '</div>';
    echo '<div class="lm-stat-sub">Dofollow: ' . esc_html((string)$stats['external']['dofollow']) . ' • Nofollow: ' . esc_html((string)$stats['external']['nofollow']) . '</div>';
    echo '</div>';

    if ($link_total > 0) {
      $pie_style = 'background: conic-gradient(#2563eb 0 ' . esc_attr((string)$internal_pct) . '%, #f59e0b ' . esc_attr((string)$internal_pct) . '% 100%);';
    } else {
      $pie_style = 'background:#e5e7eb;';
    }
    $pie_tip = 'Internal: ' . $internal_count . ' (' . $internal_pct . '%) | External: ' . $external_count . ' (' . $external_pct . '%)';
    echo '<div class="lm-top-card lm-pie-card lm-pie-card-inline">';
    echo '<div class="lm-pie lm-tooltip" data-tooltip="' . esc_attr($pie_tip) . '" style="' . $pie_style . '"><div class="lm-pie-center">' . esc_html((string)$internal_pct) . '% / ' . esc_html((string)$external_pct) . '%</div></div>';
    echo '<div>';
    echo '<h3>Internal vs External Comparison</h3>';
    echo '<div class="lm-pie-legend">';
    echo '<div class="lm-pie-item"><span class="lm-pie-swatch" style="background:#2563eb;"></span>Internal: ' . esc_html((string)$internal_count) . '</div>';
    echo '<div class="lm-pie-item"><span class="lm-pie-swatch" style="background:#f59e0b;"></span>External: ' . esc_html((string)$external_count) . '</div>';
    echo '</div>';
    echo '</div>';
    echo '</div>';

    echo '</div>'; // lm-stats-grid
    echo '</div>'; // lm-stats-wrap

    echo '<div class="lm-chart-grid">';

    // Internal vs External per post type
    echo '<div class="lm-bar-card">';
    echo '<h3>Internal vs External per Post Type</h3>';
    echo '<div class="lm-legend"><span class="lm-legend-item"><span class="lm-legend-swatch" style="background:#2563eb;"></span>Internal</span><span class="lm-legend-item"><span class="lm-legend-swatch" style="background:#f59e0b;"></span>External</span></div>';
    echo '<div class="lm-chart-hint">Setiap bar = 100% link pada post type tersebut. Panjang warna menunjukkan proporsi internal vs external.</div>';
    if (empty($postTypeBuckets)) {
      echo '<div class="lm-empty">No post type data.</div>';
    } else {
      foreach ($postTypeBuckets as $pt => $b) {
        $totalPt = (int)$b['internal'] + (int)$b['external'];
        $inPct = $totalPt > 0 ? (int)round(($b['internal'] / $totalPt) * 100) : 0;
        $exPct = $totalPt > 0 ? 100 - $inPct : 0;
        $tip = $pt . ' | Total: ' . $totalPt . ' | Internal: ' . (int)$b['internal'] . ' (' . $inPct . '%) | External: ' . (int)$b['external'] . ' (' . $exPct . '%)';
        echo '<div class="lm-bar-row">';
        echo '<div class="lm-bar-label">' . esc_html($pt) . '</div>';
        echo '<div class="lm-stacked-wrap lm-tooltip" data-tooltip="' . esc_attr($tip) . '">';
        echo '<div class="lm-stacked-track">';
        echo '<div class="lm-stacked-seg lm-stacked-seg-internal" style="width:' . esc_attr((string)$inPct) . '%;"></div>';
        echo '<div class="lm-stacked-seg lm-stacked-seg-external" style="width:' . esc_attr((string)$exPct) . '%;"></div>';
        echo '</div>';
        echo '<div class="lm-stacked-meta">' . esc_html((string)$totalPt) . ' | I ' . esc_html((string)$inPct) . '% / E ' . esc_html((string)$exPct) . '%</div>';
        echo '</div>';
        echo '</div>';
      }
    }
    echo '</div>';

    // Dofollow vs Nofollow
    echo '<div class="lm-bar-card">';
    echo '<h3>Dofollow vs Nofollow</h3>';
    echo '<div class="lm-legend"><span class="lm-legend-item"><span class="lm-legend-swatch" style="background:#16a34a;"></span>Dofollow</span><span class="lm-legend-item"><span class="lm-legend-swatch" style="background:#f97316;"></span>Nofollow</span></div>';
    $nofollow = (int)$stats['nofollow_links'];
    $dofollow = (int)$stats['dofollow_links'];
    $followTotal = $nofollow + $dofollow;
    if ($followTotal <= 0) {
      echo '<div class="lm-empty">No rel data.</div>';
    } else {
      $nofollowPct = (int)round(($nofollow / $followTotal) * 100);
      $dofollowPct = 100 - $nofollowPct;
      $tip = 'Dofollow: ' . $dofollow . ' (' . $dofollowPct . '%)';
      echo '<div class="lm-bar-row">';
      echo '<div class="lm-bar-label">Dofollow</div>';
      echo '<div class="lm-bar-track-wrap lm-tooltip" data-tooltip="' . esc_attr($tip) . '"><div class="lm-bar-track"><div class="lm-bar-fill" style="width:' . esc_attr((string)$dofollowPct) . '%; background:#16a34a;"></div></div></div>';
      echo '<div class="lm-bar-value">' . esc_html((string)$dofollow) . '</div>';
      echo '</div>';
      $tip = 'Nofollow: ' . $nofollow . ' (' . $nofollowPct . '%)';
      echo '<div class="lm-bar-row">';
      echo '<div class="lm-bar-label">Nofollow</div>';
      echo '<div class="lm-bar-track-wrap lm-tooltip" data-tooltip="' . esc_attr($tip) . '"><div class="lm-bar-track"><div class="lm-bar-fill" style="width:' . esc_attr((string)$nofollowPct) . '%; background:#f97316;"></div></div></div>';
      echo '<div class="lm-bar-value">' . esc_html((string)$nofollow) . '</div>';
      echo '</div>';
    }
    echo '</div>';

    // Anchor text quality
    echo '<div class="lm-bar-card">';
    echo '<h3>Anchor Text Quality</h3>';
    echo '<div class="lm-chart-hint">Quality status: ' . esc_html($this->get_anchor_quality_status_help_text()) . '</div>';
    echo '<div class="lm-legend"><span class="lm-legend-item"><span class="lm-legend-swatch" style="background:#16a34a;"></span>Good</span><span class="lm-legend-item"><span class="lm-legend-swatch" style="background:#f97316;"></span>Poor</span><span class="lm-legend-item"><span class="lm-legend-swatch" style="background:#dc2626;"></span>Bad</span></div>';
    if ($maxAnchor <= 0) {
      echo '<div class="lm-empty">No anchor data.</div>';
    } else {
      $aqColors = ['good'=>'#16a34a','poor'=>'#f97316','bad'=>'#dc2626'];
      foreach ($anchorQualityBuckets as $k => $count) {
        $pct = $maxAnchor > 0 ? (int)round(($count / $maxAnchor) * 100) : 0;
        $label = $k === 'good' ? 'Good' : ($k === 'poor' ? 'Poor' : 'Bad');
        $tip = $label . ': ' . $count;
        echo '<div class="lm-bar-row">';
        echo '<div class="lm-bar-label">' . esc_html($label) . '</div>';
        echo '<div class="lm-bar-track-wrap lm-tooltip" data-tooltip="' . esc_attr($tip) . '"><div class="lm-bar-track"><div class="lm-bar-fill" style="width:' . esc_attr((string)$pct) . '%; background:' . esc_attr($aqColors[$k]) . ';"></div></div></div>';
        echo '<div class="lm-bar-value">' . esc_html((string)$count) . '</div>';
        echo '</div>';
      }
    }
    echo '</div>';

    // Top external domains (bar)
    echo '<div class="lm-bar-card">';
    echo '<h3>Top 10 Cited External Domains</h3>';
    echo '<div class="lm-legend"><span class="lm-legend-item"><span class="lm-legend-swatch" style="background:#3b82f6;"></span>Percentage of total external links</span></div>';
    if (empty($tops['external_domains'])) {
      echo '<div class="lm-empty">No data.</div>';
    } else {
      $maxDom = max($tops['external_domains'] ?: [1]);
      $externalTotal = max(1, (int)$stats['external_links']);
      foreach ($tops['external_domains'] as $domain => $count) {
        $pct = $maxDom > 0 ? (int)round(($count / $maxDom) * 100) : 0;
        $pctOfTotal = round(($count / $externalTotal) * 100, 1);
        $extra = '';
        $tip = $domain . ': ' . $count . ' (' . $pctOfTotal . '%)' . $extra;
        echo '<div class="lm-bar-row">';
        echo '<div class="lm-bar-label"><span class="lm-trunc" title="' . esc_attr($domain) . '">' . esc_html($domain) . '</span></div>';
        echo '<div class="lm-bar-track-wrap lm-tooltip" data-tooltip="' . esc_attr($tip) . '"><div class="lm-bar-track"><div class="lm-bar-fill" style="width:' . esc_attr((string)$pct) . '%; background:#3b82f6;"></div></div></div>';
        echo '<div class="lm-bar-value">' . esc_html((string)$pctOfTotal) . '%</div>';
        echo '</div>';
      }
    }
    echo '</div>';

    echo '</div>'; // lm-chart-grid

    echo '<div class="lm-top-grid">';

    echo '<div class="lm-top-card">';
    echo '<h3>Top 10 Internal Anchor Text</h3>';
    if (empty($tops['internal_anchors'])) {
      echo '<div class="lm-small">No data.</div>';
    } else {
      echo '<ol class="lm-top-list">';
      foreach ($tops['internal_anchors'] as $name => $count) {
        echo '<li><span class="lm-top-name lm-trunc" title="' . esc_attr($name) . '">' . esc_html($name) . '</span><span class="lm-top-count">' . esc_html((string)$count) . '</span></li>';
      }
      echo '</ol>';
    }
    echo '</div>';

    echo '<div class="lm-top-card">';
    echo '<h3>Top 10 External Anchor Text</h3>';
    if (empty($tops['external_anchors'])) {
      echo '<div class="lm-small">No data.</div>';
    } else {
      echo '<ol class="lm-top-list">';
      foreach ($tops['external_anchors'] as $name => $count) {
        echo '<li><span class="lm-top-name lm-trunc" title="' . esc_attr($name) . '">' . esc_html($name) . '</span><span class="lm-top-count">' . esc_html((string)$count) . '</span></li>';
      }
      echo '</ol>';
    }
    echo '</div>';

    echo '<div class="lm-top-card">';
    echo '<h3>Top 10 Cited External Domains</h3>';
    if (empty($tops['external_domains'])) {
      echo '<div class="lm-small">No data.</div>';
    } else {
      echo '<ol class="lm-top-list">';
      foreach ($tops['external_domains'] as $name => $count) {
        echo '<li><span class="lm-top-name lm-trunc" title="' . esc_attr($name) . '">' . esc_html($name) . '</span><span class="lm-top-count">' . esc_html((string)$count) . '</span></li>';
      }
      echo '</ol>';
    }
    echo '</div>';

    echo '<div class="lm-top-card">';
    echo '<h3>Top 10 Cited Internal Pages</h3>';
    if (empty($tops['internal_pages'])) {
      echo '<div class="lm-small">No data.</div>';
    } else {
      echo '<ol class="lm-top-list">';
      foreach ($tops['internal_pages'] as $name => $count) {
        echo '<li><span class="lm-top-name lm-trunc" title="' . esc_attr($name) . '">' . esc_html($name) . '</span><span class="lm-top-count">' . esc_html((string)$count) . '</span></li>';
      }
      echo '</ol>';
    }
    echo '</div>';

    echo '<div class="lm-top-card">';
    echo '<h3>Top 10 Cited External Pages</h3>';
    if (empty($tops['external_pages'])) {
      echo '<div class="lm-small">No data.</div>';
    } else {
      echo '<ol class="lm-top-list">';
      foreach ($tops['external_pages'] as $name => $count) {
        echo '<li><span class="lm-top-name lm-trunc" title="' . esc_attr($name) . '">' . esc_html($name) . '</span><span class="lm-top-count">' . esc_html((string)$count) . '</span></li>';
      }
      echo '</ol>';
    }
    echo '</div>';

    echo '</div>'; // lm-top-grid
    
    // Recent Change Log
    $audit_logs = $this->get_audit_log(10);
    if (!empty($audit_logs)) {
      echo '<div style="margin-top:15px;">';
      echo '<h3 style="margin:10px 0 6px;">Recent Change Log</h3>';
      echo '<table class="widefat striped lm-audit-table" style="font-size:12px;">';
      echo '<thead><tr>';
      echo $this->table_header_with_tooltip('', 'Time', 'Timestamp when the action was logged.', 'left');
      echo $this->table_header_with_tooltip('', 'User', 'User who triggered the action.');
      echo $this->table_header_with_tooltip('', 'Action', 'Type of operation performed.');
      echo $this->table_header_with_tooltip('', 'Post', 'Source post ID affected by action.');
      echo $this->table_header_with_tooltip('', 'Old URL', 'Original URL before update.');
      echo $this->table_header_with_tooltip('', 'New URL', 'Updated URL after action.');
      echo $this->table_header_with_tooltip('', 'Status', 'Operation result: success or failed.', 'right');
      echo '</tr></thead><tbody>';
      foreach ($audit_logs as $log) {
        $status_color = $log->status === 'success' ? '#4caf50' : '#d63638';
        echo '<tr>';
        echo '<td>' . esc_html(substr((string)$log->timestamp, 0, 19)) . '</td>';
        echo '<td>' . esc_html((string)$log->user_name) . '</td>';
        echo '<td><small>' . esc_html((string)$log->action) . '</small></td>';
        echo '<td>' . esc_html((string)$log->post_id) . '</td>';
        echo '<td><small style="max-width:150px; display:block; overflow:hidden; text-overflow:ellipsis;">' . esc_html((string)$log->old_url) . '</small></td>';
        echo '<td><small style="max-width:150px; display:block; overflow:hidden; text-overflow:ellipsis;">' . esc_html((string)$log->new_url) . '</small></td>';
        echo '<td><span style="color:' . esc_attr($status_color) . '; font-weight:bold;">' . esc_html((string)$log->status) . '</span></td>';
        echo '</tr>';
      }
      echo '</tbody></table>';
      echo '</div>';
    }
    
    echo '</div>'; // card
    echo '</div>';
  }

  public function render_admin_editor_page() {
    if (!$this->current_user_can_access_plugin()) wp_die('Unauthorized');

    $filters = $this->get_filters_from_request();
    $msg = isset($_GET['lm_msg']) ? sanitize_text_field((string)$_GET['lm_msg']) : '';
    $msgClass = $this->notice_class_for_message($msg, 'info');

    $all = $this->build_or_get_cache($filters['post_type'], $filters['rebuild'], isset($filters['wpml_lang']) ? $filters['wpml_lang'] : 'all');

    $locations = ['any' => 'All'];
    foreach ($all as $r) $locations[$r['link_location']] = $r['link_location'];
    ksort($locations);

    $sourceTypes = $this->get_filterable_source_type_options(true);

    $postTypes = $this->get_filterable_post_types();
    $postCategoryOptions = $this->get_post_term_options('category');
    $postTagOptions = $this->get_post_term_options('post_tag');
    $textModes = $this->get_text_match_modes();
    $exportUrl = $this->build_export_url($filters);

    $rows = $this->apply_filters_and_group($all, $filters);

    $total = count($rows);
    $perPage = $filters['per_page'];
    $paged = $filters['paged'];
    $totalPages = max(1, (int)ceil($total / $perPage));
    if ($paged > $totalPages) $paged = $totalPages;

    $editorHiddenFields = [
      'lm_post_type' => $filters['post_type'],
      'lm_post_category' => isset($filters['post_category']) ? (int)$filters['post_category'] : 0,
      'lm_post_tag' => isset($filters['post_tag']) ? (int)$filters['post_tag'] : 0,
      'lm_location' => $filters['location'],
      'lm_source_type' => $filters['source_type'],
      'lm_link_type' => $filters['link_type'],
      'lm_value_type' => $filters['value_type'],
      'lm_value' => $filters['value_contains'],
      'lm_source' => $filters['source_contains'],
      'lm_title' => $filters['title_contains'],
      'lm_author' => $filters['author_contains'],
      'lm_publish_date_from' => isset($filters['publish_date_from']) ? (string)$filters['publish_date_from'] : '',
      'lm_publish_date_to' => isset($filters['publish_date_to']) ? (string)$filters['publish_date_to'] : '',
      'lm_updated_date_from' => isset($filters['updated_date_from']) ? (string)$filters['updated_date_from'] : '',
      'lm_updated_date_to' => isset($filters['updated_date_to']) ? (string)$filters['updated_date_to'] : '',
      'lm_anchor' => $filters['anchor_contains'],
      'lm_quality' => $filters['quality'],
      'lm_seo_flag' => $filters['seo_flag'],
      'lm_alt' => $filters['alt_contains'],
      'lm_rel' => $filters['rel_contains'],
      'lm_text_mode' => $filters['text_match_mode'],
      'lm_rel_nofollow' => $filters['rel_nofollow'],
      'lm_rel_sponsored' => $filters['rel_sponsored'],
      'lm_rel_ugc' => $filters['rel_ugc'],
      'lm_orderby' => $filters['orderby'],
      'lm_order' => $filters['order'],
      'lm_per_page' => $perPage,
      'lm_paged' => $paged,
    ];

    $offset = ($paged - 1) * $perPage;
    $pageRows = array_slice($rows, $offset, $perPage);

    echo '<div class="wrap lm-wrap">';
    echo '<h1>Links Manager - Links Editor</h1>';

    if ($msg !== '') echo '<div class="notice notice-' . esc_attr($msgClass) . '"><p>' . esc_html($msg) . '</p></div>';

    echo '<div class="lm-grid">';

    // Filters card
    echo '<div class="lm-card lm-card-full lm-card-grouping">';
    echo '<h2 style="margin-top:0;">Filter</h2>';
    echo '<form method="get" action="">';
    echo '<input type="hidden" name="page" value="' . esc_attr(self::PAGE_SLUG) . '"/>';

    echo '<table class="form-table lm-filter-table" role="presentation"><tbody>';

    echo '<tr><th scope="row">Post Type</th><td><select name="lm_post_type">';
    echo '<option value="any"' . selected($filters['post_type'], 'any', false) . '>All</option>';
    foreach ($postTypes as $k => $label) {
      echo '<option value="' . esc_attr($k) . '"' . selected($filters['post_type'], $k, false) . '>' . esc_html($label) . ' (' . esc_html($k) . ')</option>';
    }
    echo '</select></td></tr>';

    echo '<tr><th scope="row">Post Category</th><td><select name="lm_post_category">';
    echo '<option value="0"' . selected((int)($filters['post_category'] ?? 0), 0, false) . '>All</option>';
    foreach ($postCategoryOptions as $termId => $termLabel) {
      echo '<option value="' . esc_attr((string)$termId) . '"' . selected((int)($filters['post_category'] ?? 0), (int)$termId, false) . '>' . esc_html($termLabel) . '</option>';
    }
    echo '</select><div class="lm-small">Applied to WordPress posts only.</div></td></tr>';

    echo '<tr><th scope="row">Post Tag</th><td><select name="lm_post_tag">';
    echo '<option value="0"' . selected((int)($filters['post_tag'] ?? 0), 0, false) . '>All</option>';
    foreach ($postTagOptions as $termId => $termLabel) {
      echo '<option value="' . esc_attr((string)$termId) . '"' . selected((int)($filters['post_tag'] ?? 0), (int)$termId, false) . '>' . esc_html($termLabel) . '</option>';
    }
    echo '</select><div class="lm-small">Applied to WordPress posts only.</div></td></tr>';

    echo '<tr><th scope="row">Link Location</th><td><select name="lm_location">';
    foreach ($locations as $k => $label) {
      echo '<option value="' . esc_attr($k) . '"' . selected($filters['location'], $k, false) . '>' . esc_html($label) . '</option>';
    }
    echo '</select><div class="lm-small">Example: content / excerpt / menu / meta:xxx</div></td></tr>';

    echo '<tr><th scope="row">Source Type</th><td><select name="lm_source_type">';
    foreach ($sourceTypes as $k => $label) {
      echo '<option value="' . esc_attr($k) . '"' . selected($filters['source_type'], $k, false) . '>' . esc_html($label) . '</option>';
    }
    echo '</select></td></tr>';

    echo '<tr><th scope="row">Link Type</th><td><select name="lm_link_type">';
    echo '<option value="any"' . selected($filters['link_type'], 'any', false) . '>All</option>';
    echo '<option value="inlink"' . selected($filters['link_type'], 'inlink', false) . '>Internal</option>';
    echo '<option value="exlink"' . selected($filters['link_type'], 'exlink', false) . '>External</option>';
    echo '</select></td></tr>';

    echo '<tr><th scope="row">Search Destination URL</th><td>';
    echo '<input type="text" name="lm_value" value="' . esc_attr($filters['value_contains']) . '" class="regular-text" placeholder="example.com / /contact / utm_..."/>';
    echo '</td></tr>';

    $vtypes = ['any'=>'All','url'=>'Full URL','relative'=>'Relative (/page)','anchor'=>'Anchor (#)','mailto'=>'Email (mailto)','tel'=>'Phone (tel)','javascript'=>'Javascript','other'=>'Other','empty'=>'Empty'];
    echo '<tr><th scope="row">URL Format</th><td><select name="lm_value_type">';
    foreach ($vtypes as $k => $label) {
      echo '<option value="' . esc_attr($k) . '"' . selected($filters['value_type'], $k, false) . '>' . esc_html($label) . '</option>';
    }
    echo '</select></td></tr>';

    echo '<tr><th scope="row">Text Search Mode</th><td><select name="lm_text_mode">';
    foreach ($textModes as $modeKey => $modeLabel) {
      echo '<option value="' . esc_attr($modeKey) . '"' . selected($filters['text_match_mode'], $modeKey, false) . '>' . esc_html($modeLabel) . '</option>';
    }
    echo '</select><div class="lm-small">Applies to Destination URL, Source URL, Title, Author, Anchor Text, and Alt.</div></td></tr>';

    echo '<tr><th scope="row">Search Source URL</th><td>';
    echo '<input type="text" name="lm_source" value="' . esc_attr($filters['source_contains'] ?? '') . '" class="regular-text" placeholder="page URL / /category / /slug"/>';
    echo '</td></tr>';

    echo '<tr><th scope="row">Search Title</th><td>';
    echo '<input type="text" name="lm_title" value="' . esc_attr($filters['title_contains']) . '" class="regular-text" placeholder="article title / landing page"/>';
    echo '</td></tr>';

    echo '<tr><th scope="row">Search Author</th><td>';
    echo '<input type="text" name="lm_author" value="' . esc_attr($filters['author_contains']) . '" class="regular-text" placeholder="author name"/>';
    echo '</td></tr>';

    echo '<tr><th scope="row">Published Date Range</th><td>';
    echo '<input type="date" name="lm_publish_date_from" value="' . esc_attr(isset($filters['publish_date_from']) ? (string)$filters['publish_date_from'] : '') . '" /> ';
    echo '<input type="date" name="lm_publish_date_to" value="' . esc_attr(isset($filters['publish_date_to']) ? (string)$filters['publish_date_to'] : '') . '" />';
    echo '</td></tr>';

    echo '<tr><th scope="row">Updated Date Range</th><td>';
    echo '<input type="date" name="lm_updated_date_from" value="' . esc_attr(isset($filters['updated_date_from']) ? (string)$filters['updated_date_from'] : '') . '" /> ';
    echo '<input type="date" name="lm_updated_date_to" value="' . esc_attr(isset($filters['updated_date_to']) ? (string)$filters['updated_date_to'] : '') . '" />';
    echo '</td></tr>';

    echo '<tr><th scope="row">Search Anchor Text</th><td>';
    echo '<input type="text" name="lm_anchor" value="' . esc_attr($filters['anchor_contains']) . '" class="regular-text" placeholder="list / read more / pricing"/>';
    echo '</td></tr>';

    echo '<tr><th scope="row">Search in Alt</th><td>';
    echo '<input type="text" name="lm_alt" value="' . esc_attr($filters['alt_contains']) . '" class="regular-text" placeholder="logo / banner / icon"/>';
    echo '</td></tr>';

    echo '<tr><th scope="row">Quality</th><td><select name="lm_quality">';
    echo '<option value="any"' . selected($filters['quality'], 'any', false) . '>All</option>';
    echo '<option value="good"' . selected($filters['quality'], 'good', false) . '>Good</option>';
    echo '<option value="poor"' . selected($filters['quality'], 'poor', false) . '>Poor</option>';
    echo '<option value="bad"' . selected($filters['quality'], 'bad', false) . '>Bad</option>';
    echo '</select></td></tr>';

    echo '<tr><th scope="row">SEO Flags</th><td><select name="lm_seo_flag">';
    echo '<option value="any"' . selected($filters['seo_flag'], 'any', false) . '>All</option>';
    echo '<option value="dofollow"' . selected($filters['seo_flag'], 'dofollow', false) . '>Dofollow</option>';
    echo '<option value="nofollow"' . selected($filters['seo_flag'], 'nofollow', false) . '>Nofollow</option>';
    echo '<option value="sponsored"' . selected($filters['seo_flag'], 'sponsored', false) . '>Sponsored</option>';
    echo '<option value="ugc"' . selected($filters['seo_flag'], 'ugc', false) . '>UGC</option>';
    echo '</select></td></tr>';

    echo '<tr><th scope="row">Order By</th><td>';
    echo '<select name="lm_orderby">';
    echo '<option value="date"' . selected($filters['orderby'], 'date', false) . '>Date</option>';
    echo '<option value="title"' . selected($filters['orderby'], 'title', false) . '>Title</option>';
    echo '<option value="post_type"' . selected($filters['orderby'], 'post_type', false) . '>Post Type</option>';
    echo '<option value="post_author"' . selected($filters['orderby'], 'post_author', false) . '>Author</option>';
    echo '<option value="page_url"' . selected($filters['orderby'], 'page_url', false) . '>Page URL</option>';
    echo '<option value="link"' . selected($filters['orderby'], 'link', false) . '>Destination Link</option>';
    echo '<option value="source"' . selected($filters['orderby'], 'source', false) . '>Source</option>';
    echo '<option value="link_location"' . selected($filters['orderby'], 'link_location', false) . '>Link Location</option>';
    echo '<option value="anchor_text"' . selected($filters['orderby'], 'anchor_text', false) . '>Anchor</option>';
    echo '<option value="quality"' . selected($filters['orderby'], 'quality', false) . '>Quality</option>';
    echo '<option value="link_type"' . selected($filters['orderby'], 'link_type', false) . '>Link Type</option>';
    echo '<option value="seo_flags"' . selected($filters['orderby'], 'seo_flags', false) . '>SEO Flags</option>';
    echo '<option value="alt_text"' . selected($filters['orderby'], 'alt_text', false) . '>Alt</option>';
    echo '<option value="count"' . selected($filters['orderby'], 'count', false) . '>Count</option>';
    echo '</select> ';
    echo '<select name="lm_order">';
    echo '<option value="DESC"' . selected($filters['order'], 'DESC', false) . '>DESC</option>';
    echo '<option value="ASC"' . selected($filters['order'], 'ASC', false) . '>ASC</option>';
    echo '</select>';
    echo '</td></tr>';

    echo '<tr><th scope="row">Cache</th><td>';
    echo '<label><input type="checkbox" name="lm_rebuild" value="1"' . checked($filters['rebuild'] ? '1' : '0', '1', false) . '> Rebuild cache</label>';
    echo '<div class="lm-small">Cache is used to keep this page fast.</div>';
    echo '</td></tr>';

    echo '<tr><th scope="row">Per Page</th><td>';
    echo '<input type="number" name="lm_per_page" value="' . esc_attr((string)$perPage) . '" min="10" max="500" />';
    echo '</td></tr>';

    echo '<tr><th scope="row">Export</th><td>';
    echo '<a class="button button-secondary" href="' . esc_url($exportUrl) . '">Export CSV</a>';
    echo '<div class="lm-small">Export includes row_id + occurrence for precise bulk updates.</div>';
    echo '</td></tr>';

    echo '</tbody></table>';

    submit_button('Apply Filters', 'primary', 'submit', false);
    echo ' <a class="button" href="' . esc_url(admin_url('admin.php?page=links-manager')) . '">Reset Filter</a>';
    echo '</form>';
    echo '</div>';

    // Bulk update card
    echo '<div class="lm-card lm-card-full">';
    echo '<h2 style="margin-top:0;">Bulk Update via CSV (Specific 1 Link)</h2>';
    
    echo '<div class="lm-small" style="margin-bottom:12px;">';
    echo '<strong>CSV Format Requirements:</strong><br>';
    echo '• <strong>Required headers:</strong> <code>post_id</code>, <code>old_link</code>, <code>row_id</code><br>';
    echo '• <strong>Optional headers:</strong> <code>new_link</code>, <code>new_rel</code>, <code>new_anchor</code><br>';
    echo '• Delimiter can be comma (,) or semicolon (;)<br>';
    echo '• First row must be the header row<br>';
    echo '• Encoding: UTF-8<br><br>';
    
    echo '<strong>Example CSV content:</strong><br>';
    echo '<code style="display:block; background:#f5f5f5; padding:8px; margin:4px 0; border-radius:4px; font-size:11px;">';
    echo 'post_id,old_link,row_id,new_link,new_rel,new_anchor<br>';
    echo '123,https://example.com/old,lm_abc123def4567890,https://example.com/new,nofollow,New Link Text<br>';
    echo '456,https://site.com/page,lm_xyz789abc1234567,https://newsite.com/page,,Updated Anchor';
    echo '</code>';
    
    echo '<strong>Notes:</strong><br>';
    echo '• Leave <code>new_link</code> empty to only update anchor text or rel<br>';
    echo '• Leave <code>new_anchor</code> empty to keep existing anchor text<br>';
    echo '• Leave <code>new_rel</code> empty to keep existing rel attributes<br>';
    echo '• Export the current table to get correct <code>row_id</code> values<br>';
    echo '• If content changes and target link no longer matches, the row will fail (fail-safe)<br>';
    echo '</div>';

    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" enctype="multipart/form-data" style="margin-top:10px;">';
    echo '<input type="hidden" name="action" value="lm_bulk_update"/>';
    echo '<input type="hidden" name="' . esc_attr(self::NONCE_NAME) . '" value="' . esc_attr(wp_create_nonce(self::NONCE_ACTION)) . '"/>';

    // Keep current filter params so redirect returns nicely
    foreach ($editorHiddenFields as $k => $val) {
      echo '<input type="hidden" name="' . esc_attr($k) . '" value="' . esc_attr($val) . '"/>';
    }

    echo '<input type="file" name="lm_csv" accept=".csv" required /> ';
    submit_button('Run Bulk Update', 'secondary', 'submit', false);
    echo '</form>';
    echo '</div>';

    echo '</div>'; // grid

    // Blank line kept for diff clarity
    echo '<h2>Results (' . esc_html((string)$total) . ' ' . ($total === 1 ? 'link' : 'links') . ')</h2>';
    echo '<div class="lm-small" style="margin:6px 0 10px;">';
    echo '<strong>Quality rule:</strong> '; 
    echo esc_html($this->get_anchor_quality_status_help_text());
    echo '</div>';
    $this->render_pagination($filters, $paged, $totalPages);

    // TABLE (all details in their own columns)
    echo '<div class="lm-table-wrap">';
    echo '<table class="widefat striped lm-table">';
    echo '<thead><tr>';

    $cols = [
      ['label'=>'Page URL', 'class'=>'lm-col-pageurl', 'tooltip'=>'URL of the content where this link appears.'],
      ['label'=>'Title', 'class'=>'lm-col-title', 'tooltip'=>'Title of the source post/page containing the link.'],
      ['label'=>'Author', 'class'=>'lm-col-author', 'tooltip'=>'Author of the source content.'],
      ['label'=>'Published Date', 'class'=>'lm-col-date', 'tooltip'=>'Publish date of the source content.'],
      ['label'=>'Updated Date', 'class'=>'lm-col-date', 'tooltip'=>'Last modified date of the source content.'],
      ['label'=>'Post Type', 'class'=>'lm-col-type', 'tooltip'=>'Content type (post, page, or custom post type).'],
      ['label'=>'Destination Link', 'class'=>'lm-col-link', 'tooltip'=>'The target URL found in the anchor href attribute.'],
      ['label'=>'Source', 'class'=>'lm-col-source', 'tooltip'=>'Data source scanned by plugin (content, excerpt, meta, or menu).'],
      ['label'=>'Link Location', 'class'=>'lm-col-source', 'tooltip'=>'Specific block/location in source content (for example core/paragraph or meta:key).'],
      ['label'=>'Anchor', 'class'=>'lm-col-anchor', 'tooltip'=>'Visible anchor text shown to readers.'],
      ['label'=>'Quality', 'class'=>'lm-col-quality', 'tooltip'=>'Anchor quality evaluation based on length and weak phrase rules.'],
      ['label'=>'Link Type', 'class'=>'lm-col-linktype', 'tooltip'=>'Internal (same site) or External (different domain).'],
      ['label'=>'SEO Flags', 'class'=>'lm-col-rel', 'tooltip'=>'rel attributes detected on link (dofollow, nofollow, sponsored, ugc).'],
      ['label'=>'Alt', 'class'=>'lm-col-alt', 'tooltip'=>'Image alt text when link is wrapped around an image.'],
      ['label'=>'Snippet', 'class'=>'lm-col-snippet', 'tooltip'=>'Context snippet around the link in source content (limited to 60 characters in table view).'],
      ['label'=>'Edit', 'class'=>'lm-col-edit', 'tooltip'=>'Update URL, anchor text, and rel values for this specific link row.']
    ];
    $totalCols = count($cols);
    foreach ($cols as $index => $col) {
      $label = esc_html($col['label']);
      $tooltip = isset($col['tooltip']) ? (string)$col['tooltip'] : '';
      if ($tooltip !== '') {
        $tooltipClass = 'lm-tooltip';
        if ($index <= 1) {
          $tooltipClass .= ' is-left';
        } elseif ($index >= ($totalCols - 2)) {
          $tooltipClass .= ' is-right';
        }
        $label .= ' <span class="' . esc_attr($tooltipClass) . '" data-tooltip="' . esc_attr($tooltip) . '">ⓘ</span>';
      }
      echo '<th class="' . esc_attr($col['class']) . '">' . $label . '</th>';
    }
    echo '</tr></thead><tbody>';

    if (empty($pageRows)) {
      echo '<tr><td colspan="' . count($cols) . '">No links match the filter.</td></tr>';
    } else {
      foreach ($pageRows as $r) {
        $typeLabel = ($r['link_type'] === 'exlink') ? 'External' : 'Internal';
        $relLabel  = ($r['relationship'] === 'dofollow') ? 'Dofollow' : $r['relationship'];

        echo '<tr>';

        // Page URL
        echo '<td class="lm-col-pageurl">' . ($r['page_url'] ? '<a href="' . esc_url($r['page_url']) . '" target="_blank" rel="noopener noreferrer"><span class="lm-trunc" title="' . esc_attr($r['page_url']) . '">' . esc_html($r['page_url']) . '</span></a>' : '') . '</td>';
        
        // Title
        echo '<td class="lm-col-title"><span class="lm-trunc" title="' . esc_attr((string)$r['post_title']) . '">' . esc_html((string)$r['post_title']) . '</span></td>';
        
        // Author
        echo '<td class="lm-col-author"><span class="lm-trunc" title="' . esc_attr((string)$r['post_author']) . '">' . esc_html((string)$r['post_author']) . '</span></td>';

        // Published Date
        $postDate = isset($r['post_date']) ? (string)$r['post_date'] : '';
        echo '<td class="lm-col-date"><span class="lm-trunc" title="' . esc_attr($postDate) . '">' . esc_html($postDate !== '' ? $postDate : '—') . '</span></td>';

        // Updated Date
        $postModified = isset($r['post_modified']) ? (string)$r['post_modified'] : '';
        echo '<td class="lm-col-date"><span class="lm-trunc" title="' . esc_attr($postModified) . '">' . esc_html($postModified !== '' ? $postModified : '—') . '</span></td>';
        
        // Post Type
        echo '<td class="lm-col-type">' . esc_html((string)$r['post_type']) . '</td>';
        
        // Destination Link (resolved)
        echo '<td class="lm-col-link">' . ($r['link'] ? '<a href="' . esc_url($r['link']) . '" target="_blank" rel="noopener noreferrer"><span class="lm-trunc" title="' . esc_attr($r['link']) . '">' . esc_html($r['link']) . '</span></a>' : '') . '</td>';
        
        // Source
        echo '<td class="lm-col-source">' . esc_html((string)$r['source']) . '</td>';

        // Link Location
        echo '<td class="lm-col-source"><span class="lm-trunc" title="' . esc_attr((string)$r['link_location']) . '">' . esc_html((string)$r['link_location']) . '</span></td>';
        
        // Anchor
        echo '<td class="lm-col-anchor"><span class="lm-trunc" data-anchor title="' . esc_attr((string)$r['anchor_text']) . '">' . esc_html((string)$r['anchor_text']) . '</span></td>';
        
        // Quality
        $quality = $this->get_anchor_quality_suggestion($r['anchor_text']);
        $qualityLabel = 'Good';
        if ((string)($quality['quality'] ?? '') === 'poor') $qualityLabel = 'Poor';
        if ((string)($quality['quality'] ?? '') === 'bad') $qualityLabel = 'Bad';
        echo '<td class="lm-col-quality" title="' . esc_attr((string)($quality['warning'] ?? '')) . '">' . esc_html($qualityLabel) . '</td>';
        
        // Link Type
        echo '<td class="lm-col-linktype">' . esc_html($typeLabel) . '</td>';
        
        // SEO Flags (nofollow, sponsored, ugc)
        $seo_flags = [];
        if ($r['rel_nofollow'] === '1') $seo_flags[] = 'nofollow';
        if ($r['rel_sponsored'] === '1') $seo_flags[] = 'sponsored';
        if ($r['rel_ugc'] === '1') $seo_flags[] = 'ugc';
        $seo_flags_text = !empty($seo_flags) ? implode(', ', $seo_flags) : 'dofollow';
        echo '<td class="lm-col-rel"><span class="lm-trunc" title="' . esc_attr($seo_flags_text) . '">' . esc_html($seo_flags_text) . '</span></td>';
        
        // Alt
        echo '<td class="lm-col-alt"><span class="lm-trunc" title="' . esc_attr((string)$r['alt_text']) . '">' . esc_html((string)$r['alt_text']) . '</span></td>';

        // Snippet (limited to 60 chars)
        $snippetFull = isset($r['snippet']) ? (string)$r['snippet'] : '';
        $snippetShort = $this->text_snippet_with_anchor_offset($snippetFull, isset($r['anchor_text']) ? (string)$r['anchor_text'] : '', 60, 4);
        echo '<td class="lm-col-snippet"><span class="lm-trunc" title="' . esc_attr($snippetFull) . '">' . $this->highlight_snippet_anchor_html($snippetShort, isset($r['anchor_text']) ? (string)$r['anchor_text'] : '') . '</span></td>';
        
        // Edit
        echo '<td class="lm-col-edit">';
        if (!empty($r['post_id']) && $r['source'] !== 'menu') {
          echo '<form class="lm-edit-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return false;">';
          echo '<input type="hidden" name="action" value="lm_update_link"/>';
          echo '<input type="hidden" name="' . esc_attr(self::NONCE_NAME) . '" value="' . esc_attr(wp_create_nonce(self::NONCE_ACTION)) . '"/>';

          foreach ($editorHiddenFields as $k => $val) {
            echo '<input type="hidden" name="' . esc_attr($k) . '" value="' . esc_attr($val) . '"/>';
          }

          echo '<input type="hidden" name="post_id" value="' . esc_attr((string)$r['post_id']) . '"/>';
          echo '<input type="hidden" name="row_id" value="' . esc_attr((string)$r['row_id']) . '"/>';
          echo '<input type="hidden" name="old_link" value="' . esc_attr((string)$r['link']) . '"/>';

          echo '<input type="hidden" name="old_anchor" value="' . esc_attr((string)$r['anchor_text']) . '"/>';
          echo '<input type="hidden" name="old_rel" value="' . esc_attr((string)($r['rel_raw'] ?? '')) . '"/>';
          echo '<input type="hidden" name="old_snippet" value="' . esc_attr(isset($r['snippet']) ? (string)$r['snippet'] : '') . '"/>';

          echo '<input type="hidden" name="source" value="' . esc_attr((string)$r['source']) . '"/>';
          echo '<input type="hidden" name="link_location" value="' . esc_attr((string)$r['link_location']) . '"/>';
          echo '<input type="hidden" name="block_index" value="' . esc_attr((string)$r['block_index']) . '"/>';
          echo '<input type="hidden" name="occurrence" value="' . esc_attr((string)($r['occurrence'] ?? '0')) . '"/>';

          echo '<input type="text" name="new_link" placeholder="New URL" />';
          echo '<input type="text" name="new_anchor" placeholder="New anchor text" />';
          echo '<input type="text" name="new_rel" placeholder="New rel (optional), e.g. nofollow sponsored" />';
          echo '<div class="lm-form-msg"></div>';
          echo '<button type="button" class="button button-secondary lm-edit-submit">Update</button>';
          echo '</form>';
        } else {
          echo '<span class="lm-small">—</span>';
        }
        echo '</td>';

        echo '</tr>';
      }
    }

    echo '</tbody></table></div>';

    $this->render_pagination($filters, $paged, $totalPages);

    echo '<p class="lm-small" style="margin-top:12px;">Note: Per-row edit & bulk update only modify 1 link occurrence per row_id/occurrence. If content changes, the update is cancelled (fail-safe).</p>';

    echo '</div>';
  }

  public function render_admin_pages_link_page() {
    if (!$this->current_user_can_access_plugin()) wp_die('Unauthorized');

    $filters = $this->get_pages_link_filters_from_request();
    $msg = isset($_GET['lm_msg']) ? sanitize_text_field((string)$_GET['lm_msg']) : '';
    $msgClass = $this->notice_class_for_message($msg, 'info');

    $all = $this->build_or_get_cache($filters['post_type'], $filters['rebuild'], isset($filters['wpml_lang']) ? $filters['wpml_lang'] : 'all');
    $this->compact_rows_for_pages_link($all);
    $exportUrl = $this->build_pages_link_export_url($filters);
    $orphanPostTypes = $this->get_filterable_post_types();
    $postCategoryOptions = $this->get_post_term_options('category');
    $postTagOptions = $this->get_post_term_options('post_tag');
    try {
      $pages = $this->get_pages_with_inbound_counts($all, $filters, false);
    } catch (Throwable $e) {
      $pages = [];
      if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('LM Pages Link error.');
      }
    }

    $total = count($pages);
    $perPage = $filters['per_page'];
    $paged = $filters['paged'];
    $totalPages = max(1, (int)ceil($total / $perPage));
    if ($paged > $totalPages) $paged = $totalPages;
    $offset = ($paged - 1) * $perPage;
    $pageRows = array_slice($pages, $offset, $perPage);
    foreach ($pageRows as &$pageRow) {
      if ((string)($pageRow['page_url'] ?? '') === '') {
        $pageRow['page_url'] = (string)get_permalink((int)($pageRow['post_id'] ?? 0));
      }
    }
    unset($pageRow);

    $statusSummary = [
      'orphan' => 0,
      'low' => 0,
      'standard' => 0,
      'excellent' => 0,
    ];
    foreach ($pages as $row) {
      $statusKey = $this->inbound_status_key(isset($row['inbound']) ? (int)$row['inbound'] : 0);
      if (isset($statusSummary[$statusKey])) {
        $statusSummary[$statusKey]++;
      }
    }
    $summaryRows = [
      ['key' => 'orphan', 'label' => 'Orphaned'],
      ['key' => 'low', 'label' => 'Low'],
      ['key' => 'standard', 'label' => 'Standard'],
      ['key' => 'excellent', 'label' => 'Excellent'],
    ];

    $outboundSummaryRows = [
      ['key' => 'none', 'label' => 'None'],
      ['key' => 'low', 'label' => 'Low'],
      ['key' => 'optimal', 'label' => 'Optimal'],
      ['key' => 'excessive', 'label' => 'Excessive'],
    ];
    $internalOutboundThresholds = $this->get_internal_outbound_status_thresholds();
    $externalOutboundThresholds = $this->get_external_outbound_status_thresholds();
    $internalOutboundRanges = $this->get_four_level_status_ranges_text($internalOutboundThresholds);
    $externalOutboundRanges = $this->get_four_level_status_ranges_text($externalOutboundThresholds);
    $internalOutboundSummary = [
      'none' => 0,
      'low' => 0,
      'optimal' => 0,
      'excessive' => 0,
    ];
    $externalOutboundSummary = [
      'none' => 0,
      'low' => 0,
      'optimal' => 0,
      'excessive' => 0,
    ];
    foreach ($pages as $row) {
      $internalStatusKey = $this->four_level_status_key(isset($row['internal_outbound']) ? (int)$row['internal_outbound'] : 0, $internalOutboundThresholds);
      $externalStatusKey = $this->four_level_status_key(isset($row['outbound']) ? (int)$row['outbound'] : 0, $externalOutboundThresholds);
      if (isset($internalOutboundSummary[$internalStatusKey])) {
        $internalOutboundSummary[$internalStatusKey]++;
      }
      if (isset($externalOutboundSummary[$externalStatusKey])) {
        $externalOutboundSummary[$externalStatusKey]++;
      }
    }

    echo '<div class="wrap lm-wrap">';
    echo '<h1>Links Manager - Pages Link</h1>';

    if ($msg !== '') echo '<div class="notice notice-' . esc_attr($msgClass) . '"><p>' . esc_html($msg) . '</p></div>';

    echo '<div class="lm-grid">';
    echo '<div class="lm-card lm-card-full lm-card-grouping">';
    echo '<h2 style="margin-top:0;">Filter</h2>';
    echo '<form method="get" action="">';
    echo '<input type="hidden" name="page" value="links-manager-pages-link"/>';

    echo '<table class="form-table lm-filter-table" role="presentation"><tbody>';

    echo '<tr><th scope="row">Post Type</th><td><select name="lm_pages_link_post_type">';
    echo '<option value="any"' . selected($filters['post_type'], 'any', false) . '>All</option>';
    foreach ($orphanPostTypes as $k => $label) {
      echo '<option value="' . esc_attr($k) . '"' . selected($filters['post_type'], $k, false) . '>' . esc_html($label) . ' (' . esc_html($k) . ')</option>';
    }
    echo '</select></td></tr>';

    echo '<tr><th scope="row">Post Category</th><td><select name="lm_pages_link_post_category">';
    echo '<option value="0"' . selected((int)($filters['post_category'] ?? 0), 0, false) . '>All</option>';
    foreach ($postCategoryOptions as $termId => $termLabel) {
      echo '<option value="' . esc_attr((string)$termId) . '"' . selected((int)($filters['post_category'] ?? 0), (int)$termId, false) . '>' . esc_html($termLabel) . '</option>';
    }
    echo '</select><div class="lm-small">Applied to WordPress posts only.</div></td></tr>';

    echo '<tr><th scope="row">Post Tag</th><td><select name="lm_pages_link_post_tag">';
    echo '<option value="0"' . selected((int)($filters['post_tag'] ?? 0), 0, false) . '>All</option>';
    foreach ($postTagOptions as $termId => $termLabel) {
      echo '<option value="' . esc_attr((string)$termId) . '"' . selected((int)($filters['post_tag'] ?? 0), (int)$termId, false) . '>' . esc_html($termLabel) . '</option>';
    }
    echo '</select><div class="lm-small">Applied to WordPress posts only.</div></td></tr>';

    echo '<tr><th scope="row">Author</th><td><select name="lm_pages_link_author">';
    echo '<option value="0"' . selected($filters['author'], 0, false) . '>All</option>';
    $authors = get_users(['who' => 'authors']);
    foreach ($authors as $u) {
      echo '<option value="' . esc_attr((string)$u->ID) . '"' . selected($filters['author'], $u->ID, false) . '>' . esc_html($u->display_name) . '</option>';
    }
    echo '</select></td></tr>';

    echo '<tr><th scope="row">Search Title</th><td>';
    echo '<input type="text" name="lm_pages_link_search" value="' . esc_attr($filters['search']) . '" class="regular-text" placeholder="page title..."/>';
    echo '</td></tr>';

    echo '<tr><th scope="row">Search URL</th><td>';
    echo '<input type="text" name="lm_pages_link_search_url" value="' . esc_attr(isset($filters['search_url']) ? $filters['search_url'] : '') . '" class="regular-text" placeholder="example.com/path..."/>';
    echo '</td></tr>';

    echo '<tr><th scope="row">Published Date Range</th><td>';
    echo '<input type="date" name="lm_pages_link_date_from" value="' . esc_attr(isset($filters['date_from']) ? (string)$filters['date_from'] : '') . '" /> ';
    echo '<input type="date" name="lm_pages_link_date_to" value="' . esc_attr(isset($filters['date_to']) ? (string)$filters['date_to'] : '') . '" />';
    echo '</td></tr>';

    echo '<tr><th scope="row">Updated Date Range</th><td>';
    echo '<input type="date" name="lm_pages_link_updated_date_from" value="' . esc_attr(isset($filters['updated_date_from']) ? (string)$filters['updated_date_from'] : '') . '" /> ';
    echo '<input type="date" name="lm_pages_link_updated_date_to" value="' . esc_attr(isset($filters['updated_date_to']) ? (string)$filters['updated_date_to'] : '') . '" />';
    echo '</td></tr>';

    echo '<tr><th scope="row">Text Search Mode</th><td><select name="lm_pages_link_search_mode">';
    foreach ($this->get_text_match_modes() as $modeKey => $modeLabel) {
      echo '<option value="' . esc_attr($modeKey) . '"' . selected(isset($filters['search_mode']) ? $filters['search_mode'] : 'contains', $modeKey, false) . '>' . esc_html($modeLabel) . '</option>';
    }
    echo '</select><div class="lm-small">Applies to Search Title and Search URL.</div></td></tr>';

    echo '<tr><th scope="row">Link Location</th><td>';
    echo '<input type="text" name="lm_pages_link_location" value="' . esc_attr((isset($filters['location']) && $filters['location'] !== 'any') ? (string)$filters['location'] : '') . '" class="regular-text" placeholder="content / excerpt / meta:xxx" />';
    echo '<div class="lm-small">Leave empty for All.</div>';
    echo '</td></tr>';

    echo '<tr><th scope="row">Source Type</th><td><select name="lm_pages_link_source_type">';
    foreach ($this->get_filterable_source_type_options(true) as $sourceKey => $sourceLabel) {
      echo '<option value="' . esc_attr((string)$sourceKey) . '"' . selected(isset($filters['source_type']) ? $filters['source_type'] : 'any', (string)$sourceKey, false) . '>' . esc_html((string)$sourceLabel) . '</option>';
    }
    echo '</select></td></tr>';

    echo '<tr><th scope="row">Link Type</th><td><select name="lm_pages_link_link_type">';
    echo '<option value="any"' . selected(isset($filters['link_type']) ? $filters['link_type'] : 'any', 'any', false) . '>All</option>';
    echo '<option value="inlink"' . selected(isset($filters['link_type']) ? $filters['link_type'] : 'any', 'inlink', false) . '>Internal</option>';
    echo '<option value="exlink"' . selected(isset($filters['link_type']) ? $filters['link_type'] : 'any', 'exlink', false) . '>External</option>';
    echo '</select></td></tr>';

    echo '<tr><th scope="row">Search Destination URL</th><td>';
    echo '<input type="text" name="lm_pages_link_value" value="' . esc_attr(isset($filters['value_contains']) ? (string)$filters['value_contains'] : '') . '" class="regular-text" placeholder="example.com / /contact"/>';
    echo '</td></tr>';

    echo '<tr><th scope="row">SEO Flags</th><td><select name="lm_pages_link_seo_flag">';
    echo '<option value="any"' . selected(isset($filters['seo_flag']) ? $filters['seo_flag'] : 'any', 'any', false) . '>All</option>';
    echo '<option value="dofollow"' . selected(isset($filters['seo_flag']) ? $filters['seo_flag'] : 'any', 'dofollow', false) . '>Dofollow</option>';
    echo '<option value="nofollow"' . selected(isset($filters['seo_flag']) ? $filters['seo_flag'] : 'any', 'nofollow', false) . '>Nofollow</option>';
    echo '<option value="sponsored"' . selected(isset($filters['seo_flag']) ? $filters['seo_flag'] : 'any', 'sponsored', false) . '>Sponsored</option>';
    echo '<option value="ugc"' . selected(isset($filters['seo_flag']) ? $filters['seo_flag'] : 'any', 'ugc', false) . '>UGC</option>';
    echo '</select></td></tr>';

    echo '<tr><th scope="row">Order By</th><td>';
    echo '<select name="lm_pages_link_orderby">';
    echo '<option value="post_id"' . selected($filters['orderby'], 'post_id', false) . '>Post ID</option>';
    echo '<option value="date"' . selected($filters['orderby'], 'date', false) . '>Date</option>';
    echo '<option value="modified"' . selected($filters['orderby'], 'modified', false) . '>Updated Date</option>';
    echo '<option value="title"' . selected($filters['orderby'], 'title', false) . '>Title</option>';
    echo '<option value="post_type"' . selected($filters['orderby'], 'post_type', false) . '>Post Type</option>';
    echo '<option value="author"' . selected($filters['orderby'], 'author', false) . '>Author</option>';
    echo '<option value="page_url"' . selected($filters['orderby'], 'page_url', false) . '>Page URL</option>';
    echo '<option value="inbound"' . selected($filters['orderby'], 'inbound', false) . '>Inbound</option>';
    echo '<option value="internal_outbound"' . selected($filters['orderby'], 'internal_outbound', false) . '>Internal Outbound</option>';
    echo '<option value="internal_outbound_status"' . selected($filters['orderby'], 'internal_outbound_status', false) . '>Internal Outbound Status</option>';
    echo '<option value="outbound"' . selected($filters['orderby'], 'outbound', false) . '>External Outbound</option>';
    echo '<option value="external_outbound_status"' . selected($filters['orderby'], 'external_outbound_status', false) . '>External Outbound Status</option>';
    echo '<option value="status"' . selected($filters['orderby'], 'status', false) . '>Inbound Status</option>';
    echo '</select> ';
    echo '<select name="lm_pages_link_order">';
    echo '<option value="DESC"' . selected($filters['order'], 'DESC', false) . '>DESC</option>';
    echo '<option value="ASC"' . selected($filters['order'], 'ASC', false) . '>ASC</option>';
    echo '</select>';
    echo '</td></tr>';

    echo '<tr><th scope="row">Inbound Min/Max</th><td>';
    $inMin = $filters['inbound_min'] < 0 ? '' : (string)$filters['inbound_min'];
    $inMax = $filters['inbound_max'] < 0 ? '' : (string)$filters['inbound_max'];
    echo '<input type="number" name="lm_pages_link_inbound_min" value="' . esc_attr($inMin) . '" placeholder="min" style="width:90px;" /> '; 
    echo '<input type="number" name="lm_pages_link_inbound_max" value="' . esc_attr($inMax) . '" placeholder="max" style="width:90px;" />';
    echo '</td></tr>';

    echo '<tr><th scope="row">Internal Outbound Min/Max</th><td>';
    $ioMin = $filters['internal_outbound_min'] < 0 ? '' : (string)$filters['internal_outbound_min'];
    $ioMax = $filters['internal_outbound_max'] < 0 ? '' : (string)$filters['internal_outbound_max'];
    echo '<input type="number" name="lm_pages_link_internal_outbound_min" value="' . esc_attr($ioMin) . '" placeholder="min" style="width:90px;" /> ';
    echo '<input type="number" name="lm_pages_link_internal_outbound_max" value="' . esc_attr($ioMax) . '" placeholder="max" style="width:90px;" />';
    echo '</td></tr>';

    echo '<tr><th scope="row">External Outbound Min/Max</th><td>';
    $outMin = $filters['outbound_min'] < 0 ? '' : (string)$filters['outbound_min'];
    $outMax = $filters['outbound_max'] < 0 ? '' : (string)$filters['outbound_max'];
    echo '<input type="number" name="lm_pages_link_outbound_min" value="' . esc_attr($outMin) . '" placeholder="min" style="width:90px;" /> ';
    echo '<input type="number" name="lm_pages_link_outbound_max" value="' . esc_attr($outMax) . '" placeholder="max" style="width:90px;" />';
    echo '</td></tr>';

    echo '<tr><th scope="row">Inbound Status</th><td>';
    echo '<select name="lm_pages_link_status">';
    echo '<option value="any"' . selected($filters['status'], 'any', false) . '>All</option>';
    echo '<option value="orphan"' . selected($filters['status'], 'orphan', false) . '>Orphaned</option>';
    echo '<option value="low"' . selected($filters['status'], 'low', false) . '>Low</option>';
    echo '<option value="standard"' . selected($filters['status'], 'standard', false) . '>Standard</option>';
    echo '<option value="excellent"' . selected($filters['status'], 'excellent', false) . '>Excellent</option>';
    echo '</select>';
    echo '</td></tr>';

    echo '<tr><th scope="row">Internal Outbound Status</th><td>';
    echo '<select name="lm_pages_link_internal_outbound_status">';
    echo '<option value="any"' . selected(isset($filters['internal_outbound_status']) ? $filters['internal_outbound_status'] : 'any', 'any', false) . '>All</option>';
    echo '<option value="none"' . selected(isset($filters['internal_outbound_status']) ? $filters['internal_outbound_status'] : 'any', 'none', false) . '>None</option>';
    echo '<option value="low"' . selected(isset($filters['internal_outbound_status']) ? $filters['internal_outbound_status'] : 'any', 'low', false) . '>Low</option>';
    echo '<option value="optimal"' . selected(isset($filters['internal_outbound_status']) ? $filters['internal_outbound_status'] : 'any', 'optimal', false) . '>Optimal</option>';
    echo '<option value="excessive"' . selected(isset($filters['internal_outbound_status']) ? $filters['internal_outbound_status'] : 'any', 'excessive', false) . '>Excessive</option>';
    echo '</select>';
    echo '</td></tr>';

    echo '<tr><th scope="row">External Outbound Status</th><td>';
    echo '<select name="lm_pages_link_external_outbound_status">';
    echo '<option value="any"' . selected(isset($filters['external_outbound_status']) ? $filters['external_outbound_status'] : 'any', 'any', false) . '>All</option>';
    echo '<option value="none"' . selected(isset($filters['external_outbound_status']) ? $filters['external_outbound_status'] : 'any', 'none', false) . '>None</option>';
    echo '<option value="low"' . selected(isset($filters['external_outbound_status']) ? $filters['external_outbound_status'] : 'any', 'low', false) . '>Low</option>';
    echo '<option value="optimal"' . selected(isset($filters['external_outbound_status']) ? $filters['external_outbound_status'] : 'any', 'optimal', false) . '>Optimal</option>';
    echo '<option value="excessive"' . selected(isset($filters['external_outbound_status']) ? $filters['external_outbound_status'] : 'any', 'excessive', false) . '>Excessive</option>';
    echo '</select>';
    echo '</td></tr>';

    echo '<tr><th scope="row">Cache</th><td>';
    echo '<label><input type="checkbox" name="lm_pages_link_rebuild" value="1"' . checked($filters['rebuild'] ? '1' : '0', '1', false) . '> Rebuild cache</label>';
    echo '</td></tr>';

    echo '<tr><th scope="row">Per Page</th><td>';
    echo '<input type="number" name="lm_pages_link_per_page" value="' . esc_attr((string)$perPage) . '" min="10" max="500" />';
    echo '</td></tr>';

    echo '<tr><th scope="row">Export</th><td>';
    echo '<a class="button button-secondary" href="' . esc_url($exportUrl) . '">Export CSV</a>';
    echo '<div class="lm-small">Export follows the current filters.</div>';
    echo '</td></tr>';

    echo '</tbody></table>';
    submit_button('Apply Filters', 'primary', 'submit', false);
    echo ' <a class="button" href="' . esc_url(admin_url('admin.php?page=links-manager-pages-link')) . '">Reset Filter</a>';
    echo '</form>';
    echo '</div>';

    echo '<div class="lm-card lm-card-full">';
    echo '<div class="lm-small">List of pages/posts with link counts from content, excerpt, and post meta fields.</div>';
    echo '<div class="lm-small" style="margin-top:4px;">Inbound = number of internal links from other published pages/posts pointing to this page.</div>';
    echo '<div class="lm-small" style="margin-top:2px;">Internal Outbound = number of internal links going out from this page.</div>';
    echo '<div class="lm-small" style="margin-top:2px;">External Outbound = number of external links going out from this page.</div>';
    echo '<div class="lm-small" style="margin-top:4px;">Excluded from counting: WordPress Navigation Menu links (Appearance -> Menus, including header/footer/secondary menu locations), self-links (a page linking to itself for inbound), links from non-published content, and internal links whose destination does not match a tracked published page URL.</div>';
    $statusThresholds = $this->get_inbound_status_thresholds();
    $orphanMax = (int)$statusThresholds['orphan_max'];
    $lowMax = (int)$statusThresholds['low_max'];
    $standardMax = (int)$statusThresholds['standard_max'];
    $lowMin = $orphanMax + 1;
    $standardMin = $lowMax + 1;
    $excellentMin = $standardMax + 1;
    $orphanLabel = ($orphanMax === 0) ? '0' : ('0-' . $orphanMax);
    $lowLabel = ($lowMin <= $lowMax) ? ($lowMin . '-' . $lowMax) : (string)$lowMax;
    $standardLabel = ($standardMin <= $standardMax) ? ($standardMin . '-' . $standardMax) : (string)$standardMax;
    echo '<div style="margin-top:8px; font-weight:bold;">Total: ' . esc_html((string)$total) . '</div>';
    echo '</div>';
    echo '</div>'; // grid

    echo '<div class="lm-card lm-card-full">';
    echo '<h2 style="margin-top:0;">Inbound Status Summary</h2>';
    echo '<div class="lm-small" style="margin-bottom:6px;">Status reference: Orphaned = ' . esc_html((string)$orphanLabel) . ', Low = ' . esc_html((string)$lowLabel) . ', Standard = ' . esc_html((string)$standardLabel) . ', Excellent = ' . esc_html((string)$excellentMin) . '+.</div>';
    echo '<div class="lm-table-wrap lm-summary-table-wrap">';
    echo '<table class="widefat striped lm-table">';
    echo '<thead><tr>';
    echo '<th>Status</th>';
    echo '<th>Total Page URLs</th>';
    echo '<th>%</th>';
    echo '</tr></thead><tbody>';
    foreach ($summaryRows as $summaryRow) {
      $summaryKey = (string)$summaryRow['key'];
      $summaryLabel = (string)$summaryRow['label'];
      $summaryCount = isset($statusSummary[$summaryKey]) ? (int)$statusSummary[$summaryKey] : 0;
      $summaryPercent = $total > 0 ? round(($summaryCount / $total) * 100, 1) : 0;
      $summaryFilterUrl = $this->pages_link_admin_url($filters, [
        'lm_pages_link_status' => $summaryKey,
        'lm_pages_link_paged' => 1,
      ]);

      echo '<tr>';
      echo '<td>' . esc_html($summaryLabel) . '</td>';
      echo '<td style="text-align:center;"><a href="' . esc_url($summaryFilterUrl) . '">' . esc_html((string)$summaryCount) . '</a></td>';
      echo '<td style="text-align:center;">' . esc_html((string)$summaryPercent) . '%</td>';
      echo '</tr>';
    }
    echo '</tbody></table></div>';
    echo '</div>';

    echo '<div class="lm-card lm-card-full">';
    echo '<h2 style="margin-top:0;">Internal Outbound Status Summary</h2>';
    echo '<div class="lm-small" style="margin-bottom:8px;">Status reference: None = ' . esc_html((string)$internalOutboundRanges['none']) . ', Low = ' . esc_html((string)$internalOutboundRanges['low']) . ', Optimal = ' . esc_html((string)$internalOutboundRanges['optimal']) . ', Excessive = ' . esc_html((string)$internalOutboundRanges['excessive']) . '.</div>';
    echo '<div class="lm-table-wrap lm-summary-table-wrap">';
    echo '<table class="widefat striped lm-table">';
    echo '<thead><tr>';
    echo '<th>Status</th>';
    echo '<th>Total Page URLs</th>';
    echo '<th>%</th>';
    echo '</tr></thead><tbody>';
    foreach ($outboundSummaryRows as $summaryRow) {
      $summaryKey = (string)$summaryRow['key'];
      $summaryLabel = (string)$summaryRow['label'];
      $summaryCount = isset($internalOutboundSummary[$summaryKey]) ? (int)$internalOutboundSummary[$summaryKey] : 0;
      $summaryPercent = $total > 0 ? round(($summaryCount / $total) * 100, 1) : 0;
      $summaryFilterUrl = $this->pages_link_admin_url($filters, [
        'lm_pages_link_internal_outbound_status' => $summaryKey,
        'lm_pages_link_external_outbound_status' => isset($filters['external_outbound_status']) ? $filters['external_outbound_status'] : 'any',
        'lm_pages_link_paged' => 1,
      ]);

      echo '<tr>';
      echo '<td>' . esc_html($summaryLabel) . '</td>';
      echo '<td style="text-align:center;"><a href="' . esc_url($summaryFilterUrl) . '">' . esc_html((string)$summaryCount) . '</a></td>';
      echo '<td style="text-align:center;">' . esc_html((string)$summaryPercent) . '%</td>';
      echo '</tr>';
    }
    echo '</tbody></table></div>';
    echo '</div>';

    echo '<div class="lm-card lm-card-full">';
    echo '<h2 style="margin-top:0;">External Outbound Status Summary</h2>';
    echo '<div class="lm-small" style="margin-bottom:8px;">Status reference: None = ' . esc_html((string)$externalOutboundRanges['none']) . ', Low = ' . esc_html((string)$externalOutboundRanges['low']) . ', Optimal = ' . esc_html((string)$externalOutboundRanges['optimal']) . ', Excessive = ' . esc_html((string)$externalOutboundRanges['excessive']) . '.</div>';
    echo '<div class="lm-table-wrap lm-summary-table-wrap">';
    echo '<table class="widefat striped lm-table">';
    echo '<thead><tr>';
    echo '<th>Status</th>';
    echo '<th>Total Page URLs</th>';
    echo '<th>%</th>';
    echo '</tr></thead><tbody>';
    foreach ($outboundSummaryRows as $summaryRow) {
      $summaryKey = (string)$summaryRow['key'];
      $summaryLabel = (string)$summaryRow['label'];
      $summaryCount = isset($externalOutboundSummary[$summaryKey]) ? (int)$externalOutboundSummary[$summaryKey] : 0;
      $summaryPercent = $total > 0 ? round(($summaryCount / $total) * 100, 1) : 0;
      $summaryFilterUrl = $this->pages_link_admin_url($filters, [
        'lm_pages_link_external_outbound_status' => $summaryKey,
        'lm_pages_link_internal_outbound_status' => isset($filters['internal_outbound_status']) ? $filters['internal_outbound_status'] : 'any',
        'lm_pages_link_paged' => 1,
      ]);

      echo '<tr>';
      echo '<td>' . esc_html($summaryLabel) . '</td>';
      echo '<td style="text-align:center;"><a href="' . esc_url($summaryFilterUrl) . '">' . esc_html((string)$summaryCount) . '</a></td>';
      echo '<td style="text-align:center;">' . esc_html((string)$summaryPercent) . '%</td>';
      echo '</tr>';
    }
    echo '</tbody></table></div>';
    echo '</div>';

    echo '<div class="lm-table-wrap">';
    echo '<table class="widefat striped lm-table">';
    echo '<thead><tr>';
    echo $this->table_header_with_tooltip('lm-col-postid', 'Post ID', 'WordPress post ID.', 'left');
    echo $this->table_header_with_tooltip('lm-col-title', 'Title', 'Title of the page/post.');
    echo $this->table_header_with_tooltip('lm-col-type', 'Post Type', 'Content type (post, page, custom type).');
    echo $this->table_header_with_tooltip('lm-col-author', 'Author', 'Content author.');
    echo $this->table_header_with_tooltip('lm-col-date', 'Published Date', 'Original publish timestamp.');
    echo $this->table_header_with_tooltip('lm-col-date', 'Updated Date', 'Latest modified timestamp.');
    echo $this->table_header_with_tooltip('lm-col-pageurl', 'Page URL', 'URL of the source page.');
    echo $this->table_header_with_tooltip('lm-col-count', 'Inbound', 'Internal links from other pages pointing to this page.');
    echo $this->table_header_with_tooltip('lm-col-quality', 'Inbound Status', 'Inbound health label based on inbound count.');
    echo $this->table_header_with_tooltip('lm-col-count', 'Internal Outbound', 'Internal links going out from this page.');
    echo $this->table_header_with_tooltip('lm-col-quality', 'Internal Outbound Status', 'Status label based on internal outbound count and configured thresholds.');
    echo $this->table_header_with_tooltip('lm-col-outbound', 'External Outbound', 'External links going out from this page.');
    echo $this->table_header_with_tooltip('lm-col-quality', 'External Outbound Status', 'Status label based on external outbound count and configured thresholds.');
    echo $this->table_header_with_tooltip('lm-col-edit', 'Edit', 'Open WordPress editor for this page.', 'right');
    echo '</tr></thead><tbody>';

    if (empty($pageRows)) {
      echo '<tr><td colspan="14">No data.</td></tr>';
    } else {
      foreach ($pageRows as $row) {
        $post_id = (int)$row['post_id'];
        $inbound = (int)$row['inbound'];
        $outbound = isset($row['outbound']) ? (int)$row['outbound'] : 0;
        $internal_outbound = isset($row['internal_outbound']) ? (int)$row['internal_outbound'] : 0;
        $title = get_the_title($post_id);
        $post = get_post($post_id);
        if (!$post || $post->post_status !== 'publish') continue;
        $author = $post && $post->post_author ? get_the_author_meta('display_name', $post->post_author) : '';
        $date = $post ? get_the_date('Y-m-d H:i:s', $post_id) : '';
        $updated = $post ? get_the_modified_date('Y-m-d H:i:s', $post_id) : '';
        $ptype = $post ? $post->post_type : '';
        $url = get_permalink($post_id);
        $edit_url = admin_url('post.php?post=' . (int)$post_id . '&action=edit');

        $status = $this->inbound_status($inbound);
        $internalOutboundStatus = $this->four_level_status_label(isset($row['internal_outbound_status']) ? $row['internal_outbound_status'] : 'none');
        $externalOutboundStatus = $this->four_level_status_label(isset($row['external_outbound_status']) ? $row['external_outbound_status'] : 'none');

        echo '<tr>';
        echo '<td class="lm-col-postid">' . esc_html((string)$post_id) . '</td>';
        echo '<td class="lm-col-title"><span class="lm-trunc" title="' . esc_attr((string)$title) . '">' . esc_html((string)$title) . '</span></td>';
        echo '<td class="lm-col-type">' . esc_html((string)$ptype) . '</td>';
        echo '<td class="lm-col-author"><span class="lm-trunc" title="' . esc_attr((string)$author) . '">' . esc_html((string)$author) . '</span></td>';
        echo '<td class="lm-col-date">' . esc_html((string)$date) . '</td>';
        echo '<td class="lm-col-date">' . esc_html((string)$updated) . '</td>';
        echo '<td class="lm-col-pageurl">' . ($url ? '<a href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer"><span class="lm-trunc" title="' . esc_attr($url) . '">' . esc_html($url) . '</span></a>' : '') . '</td>';
        echo '<td class="lm-col-count" style="text-align:center;">' . esc_html((string)$inbound) . '</td>';
        echo '<td class="lm-col-quality">' . esc_html($status) . '</td>';
        echo '<td class="lm-col-count" style="text-align:center;">' . esc_html((string)$internal_outbound) . '</td>';
        echo '<td class="lm-col-quality">' . esc_html((string)$internalOutboundStatus) . '</td>';
        echo '<td class="lm-col-outbound" style="text-align:center;">' . esc_html((string)$outbound) . '</td>';
        echo '<td class="lm-col-quality">' . esc_html((string)$externalOutboundStatus) . '</td>';
        echo '<td class="lm-col-edit"><a class="button button-secondary" href="' . esc_url($edit_url) . '">Edit</a></td>';
        echo '</tr>';
      }
    }

    echo '</tbody></table></div>';
    $this->render_pages_link_pagination($filters, $paged, $totalPages);
    echo '</div>';
  }

  public function render_admin_settings_page() {
    if (!current_user_can('manage_options')) wp_die('Unauthorized');

    $settings = $this->get_settings();
    $runtimeMemoryLimitBytes = $this->get_effective_memory_limit_bytes();
    $runtimeMemoryLimitLabel = $this->format_bytes_human($runtimeMemoryLimitBytes);
    $runtimeMaxRows = $this->get_runtime_max_cache_rows();
    $runtimeMaxBatch = $this->get_runtime_max_crawl_batch();
    $runtimeTransientMain = $this->get_safe_transient_limit_bytes(false);
    $runtimeTransientBackup = $this->get_safe_transient_limit_bytes(true);
    $msg = isset($_GET['lm_msg']) ? sanitize_text_field((string)$_GET['lm_msg']) : '';
    $msgClass = $this->notice_class_for_message($msg, 'success');
    $activeTab = isset($_GET['lm_tab']) ? sanitize_key((string)$_GET['lm_tab']) : 'performance';
    if (!in_array($activeTab, ['general', 'performance', 'data'], true)) {
      $activeTab = 'performance';
    }
    $settingsLabelStyle = 'display:inline-block; min-width:220px;';
    $settingsLabelTopStyle = $settingsLabelStyle . ' vertical-align:top;';
    $settingsCardStyle = 'margin:0 0 12px; padding:10px; border:1px solid #dcdcde; background:#fff; border-radius:4px;';
    $settingsCardCompactStyle = 'padding:10px; border:1px solid #dcdcde; background:#fff; border-radius:4px;';

    echo '<div class="wrap lm-wrap">';
    echo '<h1 class="lm-page-title">Links Manager - Settings</h1>';
    if ($msg !== '') echo '<div class="notice notice-' . esc_attr($msgClass) . '"><p>' . esc_html($msg) . '</p></div>';

    $this->render_settings_diagnostic_box();
    $this->render_settings_runtime_profile_box();

    echo '<div class="lm-card lm-card-full">';
    echo '<h2 class="nav-tab-wrapper" style="margin:0 0 12px;">';
    echo '<a href="' . esc_url(admin_url('admin.php?page=links-manager-settings&lm_tab=general')) . '" class="nav-tab ' . ($activeTab === 'general' ? 'nav-tab-active' : '') . '">General</a>';
    echo '<a href="' . esc_url(admin_url('admin.php?page=links-manager-settings&lm_tab=performance')) . '" class="nav-tab ' . ($activeTab === 'performance' ? 'nav-tab-active' : '') . '">Performance</a>';
    echo '<a href="' . esc_url(admin_url('admin.php?page=links-manager-settings&lm_tab=data')) . '" class="nav-tab ' . ($activeTab === 'data' ? 'nav-tab-active' : '') . '">Data &amp; Quality</a>';
    echo '</h2>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:10px;">';
    echo '<input type="hidden" name="action" value="lm_save_settings"/>';
    echo '<input type="hidden" name="' . esc_attr(self::NONCE_NAME) . '" value="' . esc_attr(wp_create_nonce(self::NONCE_ACTION)) . '"/>';
    echo '<input type="hidden" name="lm_active_tab" value="' . esc_attr($activeTab) . '"/>';

    if ($activeTab === 'general') {
      echo '<h2 style="margin-top:0;">Access Control</h2>';
      echo '<div class="lm-small">Choose which user roles can use this plugin. Administrator is always allowed.</div>';
      echo '<div style="margin-top:8px;">';
      $rolesMap = $this->get_all_roles_map();
      $allowedRoles = $this->get_allowed_roles_from_settings();
      foreach ($rolesMap as $roleKey => $roleLabel) {
        $isChecked = in_array((string)$roleKey, $allowedRoles, true) ? '1' : '0';
        $isAdminRole = ((string)$roleKey === 'administrator');
        echo '<label style="display:inline-block; min-width:180px; margin:4px 12px 4px 0;">';
        echo '<input type="checkbox" name="lm_allowed_roles[]" value="' . esc_attr((string)$roleKey) . '"' . checked($isChecked, '1', false) . ($isAdminRole ? ' disabled' : '') . '/> ';
        echo esc_html((string)$roleLabel);
        if ($isAdminRole) {
          echo ' <span class="lm-small">(required)</span>';
          echo '<input type="hidden" name="lm_allowed_roles[]" value="administrator"/>';
        }
        echo '</label>';
      }
      echo '</div>';
      echo '<hr style="margin:14px 0;"/>';
    }

    if ($activeTab === 'performance') {
    echo '<h2 style="margin-top:0;">Performance &amp; Reliability</h2>';
    echo '<div class="lm-small">Adjust how often data updates and how your server handles large scans.</div>';
    echo '<div style="margin-top:8px;">';
    echo '<div style="margin:0 0 12px; padding:10px; border-left:4px solid #2271b1; background:#f6f7f7;">';
    echo '<div style="font-weight:600; margin-bottom:4px;">Section A: What to Scan</div>';
    echo '<div class="lm-small">Choose which pages, sources, and link types are included in each scan.</div>';
    echo '</div>';

    $availablePostTypes = $this->get_available_post_types();
    $scanPostTypes = isset($settings['scan_post_types']) && is_array($settings['scan_post_types'])
      ? $this->sanitize_scan_post_types($settings['scan_post_types'], $availablePostTypes)
      : array_keys($availablePostTypes);
    $scanSourceTypeOptions = $this->get_scan_source_type_options();
    $scanSourceTypes = isset($settings['scan_source_types']) && is_array($settings['scan_source_types'])
      ? $this->sanitize_scan_source_types($settings['scan_source_types'])
      : $this->get_default_scan_source_types();
    $scanValueTypeOptions = $this->get_scan_value_type_options();
    $scanValueTypes = isset($settings['scan_value_types']) && is_array($settings['scan_value_types'])
      ? $this->sanitize_scan_value_types($settings['scan_value_types'])
      : $this->get_default_scan_value_types();
    $scanWpmlLangs = isset($settings['scan_wpml_langs']) && is_array($settings['scan_wpml_langs'])
      ? $this->sanitize_scan_wpml_langs($settings['scan_wpml_langs'])
      : $this->get_default_scan_wpml_langs();
    $wpmlLanguagesMap = $this->get_wpml_languages_map();
    $scanCategoryOptions = $this->get_post_term_options('category', false);
    $scanTagOptions = $this->get_post_term_options('post_tag', false);
    $scanCategoryIds = $this->sanitize_scan_term_ids(isset($settings['scan_post_category_ids']) ? $settings['scan_post_category_ids'] : [], 'category');
    $scanTagIds = $this->sanitize_scan_term_ids(isset($settings['scan_post_tag_ids']) ? $settings['scan_post_tag_ids'] : [], 'post_tag');
    $scanAuthorOptions = $this->get_scan_author_options();
    $scanAuthorIds = $this->sanitize_scan_author_ids(isset($settings['scan_author_ids']) ? $settings['scan_author_ids'] : [], $scanAuthorOptions);
    $scanModifiedWithinDays = isset($settings['scan_modified_within_days']) ? (int)$settings['scan_modified_within_days'] : 0;
    if ($scanModifiedWithinDays < 0) $scanModifiedWithinDays = 0;
    if ($scanModifiedWithinDays > 3650) $scanModifiedWithinDays = 3650;
    $scanExcludePatterns = (string)($settings['scan_exclude_url_patterns'] ?? '');
    $maxPostsPerRebuild = (int)($settings['max_posts_per_rebuild'] ?? 0);

    $scopeEstimate = $this->get_scan_scope_estimate_summary();
    echo '<div style="' . esc_attr($settingsCardStyle) . '">';
    echo '<div style="font-weight:600; margin-bottom:6px;">Scan estimate</div>';
    echo '<div class="lm-small">Estimated posts based on current scan settings:</div>';
    echo '<table class="widefat striped" style="margin-top:8px; max-width:760px;">';
    echo '<tbody>';
    echo '<tr><th style="width:260px;">Estimated posts to scan</th><td>' . esc_html(number_format((int)($scopeEstimate['estimated_posts'] ?? 0))) . '</td></tr>';
    echo '<tr><th>Selected post types</th><td>' . esc_html((string)($scopeEstimate['post_types_count'] ?? 0)) . '</td></tr>';
    echo '<tr><th>Selected authors</th><td>' . esc_html((int)($scopeEstimate['authors_count'] ?? 0) > 0 ? (string)$scopeEstimate['authors_count'] : 'All authors') . '</td></tr>';
    echo '<tr><th>Modified date window</th><td>' . esc_html((int)($scopeEstimate['modified_within_days'] ?? 0) > 0 ? ('Last ' . (int)$scopeEstimate['modified_within_days'] . ' day(s)') : 'All history') . '</td></tr>';
    echo '<tr><th>Enabled URL types</th><td>' . esc_html((string)($scopeEstimate['value_types_count'] ?? 0)) . '</td></tr>';
    echo '<tr><th>WPML language scope</th><td>' . esc_html(($scopeEstimate['wpml_all'] ?? '1') === '1' ? 'All languages' : ((int)($scopeEstimate['wpml_langs_count'] ?? 0) . ' selected language(s)')) . '</td></tr>';
    echo '</tbody>';
    echo '</table>';
    echo '<div class="lm-small" style="margin-top:6px;">Note: this estimate is calculated before per-link filters are applied.</div>';
    echo '</div>';

    echo '<div style="margin:0 0 8px; padding:8px 10px; border-left:3px solid #2271b1; background:#fff;">';
    echo '<div style="font-weight:600; margin-bottom:4px;">Core Scan Options</div>';
    echo '<div class="lm-small">Start with these options first. Most sites only need this section.</div>';
    echo '</div>';

    echo '<p style="margin:0 0 10px;">';
    echo '<label class="lm-small" style="' . esc_attr($settingsLabelTopStyle) . '">Post types to scan:</label>';
    echo '<span style="display:inline-block;">';
    foreach ($availablePostTypes as $ptKey => $ptLabel) {
      $checked = in_array((string)$ptKey, $scanPostTypes, true) ? '1' : '0';
      echo '<label style="display:block; margin:2px 0;">';
      echo '<input type="checkbox" name="lm_scan_post_types[]" value="' . esc_attr((string)$ptKey) . '"' . checked($checked, '1', false) . '/> ';
      echo esc_html((string)$ptLabel);
      echo '</label>';
    }
    echo '</span>';
    echo '<div class="lm-small" style="margin-top:4px;">Only selected post types will be scanned.</div>';
    echo '</p>';

    echo '<p style="margin:0 0 10px;">';
    echo '<label class="lm-small" style="' . esc_attr($settingsLabelTopStyle) . '">Content sources:</label>';
    echo '<span style="display:inline-block;">';
    foreach ($scanSourceTypeOptions as $sourceKey => $sourceLabel) {
      $checked = in_array((string)$sourceKey, $scanSourceTypes, true) ? '1' : '0';
      echo '<label style="display:block; margin:2px 0;">';
      echo '<input type="checkbox" name="lm_scan_source_types[]" value="' . esc_attr((string)$sourceKey) . '"' . checked($checked, '1', false) . '/> ';
      echo esc_html((string)$sourceLabel);
      echo '</label>';
    }
    echo '</span>';
    echo '<div class="lm-small" style="margin-top:4px;">Content is usually enough. Enable Excerpt/Meta/Menu only when needed.</div>';
    echo '</p>';

    echo '<p style="margin:0 0 10px;">';
    echo '<label class="lm-small" style="' . esc_attr($settingsLabelTopStyle) . '">Link types to include:</label>';
    echo '<span style="display:inline-block;">';
    foreach ($scanValueTypeOptions as $valueTypeKey => $valueTypeLabel) {
      $checked = in_array((string)$valueTypeKey, $scanValueTypes, true) ? '1' : '0';
      echo '<label style="display:block; margin:2px 0;">';
      echo '<input type="checkbox" name="lm_scan_value_types[]" value="' . esc_attr((string)$valueTypeKey) . '"' . checked($checked, '1', false) . '/> ';
      echo esc_html((string)$valueTypeLabel);
      echo '</label>';
    }
    echo '</span>';
    echo '<div class="lm-small" style="margin-top:4px;">Unchecked link types are skipped to keep scans lighter.</div>';
    echo '</p>';

    echo '<p style="margin:0 0 10px;">';
    echo '<label class="lm-small" style="' . esc_attr($settingsLabelTopStyle) . '">Languages (WPML):</label>';
    if ($this->is_wpml_active() && !empty($wpmlLanguagesMap)) {
      echo '<span style="display:inline-block;">';
      $allChecked = in_array('all', $scanWpmlLangs, true) ? '1' : '0';
      echo '<label style="display:block; margin:2px 0;"><input type="checkbox" name="lm_scan_wpml_langs[]" value="all"' . checked($allChecked, '1', false) . '/> All languages</label>';
      foreach ($wpmlLanguagesMap as $langCode => $langLabel) {
        $checked = in_array((string)$langCode, $scanWpmlLangs, true) ? '1' : '0';
        echo '<label style="display:block; margin:2px 0;">';
        echo '<input type="checkbox" name="lm_scan_wpml_langs[]" value="' . esc_attr((string)$langCode) . '"' . checked($checked, '1', false) . '/> ';
        echo esc_html((string)$langLabel) . ' (' . esc_html((string)$langCode) . ')';
        echo '</label>';
      }
      echo '</span>';
      echo '<div class="lm-small" style="margin-top:4px;">Uncheck All languages only if you want to scan specific languages.</div>';
    } else {
      echo '<span class="lm-small">WPML not active. This setting is ignored.</span>';
    }
    echo '</p>';

    echo '<details style="margin:12px 0; border:1px solid #dcdcde; border-radius:4px; background:#fff;">';
    echo '<summary style="padding:10px 12px; cursor:pointer; font-weight:600;">Optional filters and limits (advanced)</summary>';
    echo '<div style="padding:8px 12px 12px;">';
    echo '<div class="lm-small" style="margin:0 0 10px;">Use this only if you need tighter filters or lower server load during rebuild.</div>';

    echo '<p style="margin:0 0 10px;">';
    echo '<label class="lm-small" style="' . esc_attr($settingsLabelTopStyle) . '">Categories (optional):</label>';
    echo '<span style="display:inline-block; max-height:140px; overflow:auto; border:1px solid #dcdcde; padding:6px 8px; min-width:260px; background:#fff;">';
    if (empty($scanCategoryOptions)) {
      echo '<span class="lm-small">No categories found.</span>';
    } else {
      foreach ($scanCategoryOptions as $termId => $termLabel) {
        $checked = in_array((int)$termId, $scanCategoryIds, true) ? '1' : '0';
        echo '<label style="display:block; margin:2px 0;">';
        echo '<input type="checkbox" name="lm_scan_post_category_ids[]" value="' . esc_attr((string)$termId) . '"' . checked($checked, '1', false) . '/> ';
        echo esc_html((string)$termLabel);
        echo '</label>';
      }
    }
    echo '</span>';
    echo '<div class="lm-small" style="margin-top:4px;">Leave empty to include all categories.</div>';
    echo '</p>';

    echo '<p style="margin:0 0 10px;">';
    echo '<label class="lm-small" style="' . esc_attr($settingsLabelTopStyle) . '">Tags (optional):</label>';
    echo '<span style="display:inline-block; max-height:140px; overflow:auto; border:1px solid #dcdcde; padding:6px 8px; min-width:260px; background:#fff;">';
    if (empty($scanTagOptions)) {
      echo '<span class="lm-small">No tags found.</span>';
    } else {
      foreach ($scanTagOptions as $termId => $termLabel) {
        $checked = in_array((int)$termId, $scanTagIds, true) ? '1' : '0';
        echo '<label style="display:block; margin:2px 0;">';
        echo '<input type="checkbox" name="lm_scan_post_tag_ids[]" value="' . esc_attr((string)$termId) . '"' . checked($checked, '1', false) . '/> ';
        echo esc_html((string)$termLabel);
        echo '</label>';
      }
    }
    echo '</span>';
    echo '<div class="lm-small" style="margin-top:4px;">Leave empty to include all tags.</div>';
    echo '</p>';

    echo '<p style="margin:0 0 10px;">';
    echo '<label class="lm-small" style="' . esc_attr($settingsLabelTopStyle) . '">Authors (optional):</label>';
    echo '<span style="display:inline-block; max-height:140px; overflow:auto; border:1px solid #dcdcde; padding:6px 8px; min-width:260px; background:#fff;">';
    if (empty($scanAuthorOptions)) {
      echo '<span class="lm-small">No authors found.</span>';
    } else {
      foreach ($scanAuthorOptions as $authorId => $authorLabel) {
        $checked = in_array((int)$authorId, $scanAuthorIds, true) ? '1' : '0';
        echo '<label style="display:block; margin:2px 0;">';
        echo '<input type="checkbox" name="lm_scan_author_ids[]" value="' . esc_attr((string)$authorId) . '"' . checked($checked, '1', false) . '/> ';
        echo esc_html((string)$authorLabel);
        echo '</label>';
      }
    }
    echo '</span>';
    echo '<div class="lm-small" style="margin-top:4px;">Leave empty to include all authors.</div>';
    echo '</p>';

    echo '<p style="margin:0 0 10px;">';
    echo '<label class="lm-small" style="' . esc_attr($settingsLabelStyle) . '">Recent updates only (days):</label>';
    echo '<input type="number" name="lm_scan_modified_within_days" min="0" max="3650" value="' . esc_attr((string)$scanModifiedWithinDays) . '" style="width:110px;" />';
    echo '<span class="lm-small" style="margin-left:8px;">Use 0 to scan all history.</span>';
    echo '</p>';

    echo '<p style="margin:0 0 10px;">';
    echo '<label class="lm-small" style="' . esc_attr($settingsLabelTopStyle) . '">Skip URL patterns:</label>';
    echo '<textarea name="lm_scan_exclude_url_patterns" rows="5" style="width:100%; max-width:520px;" placeholder="One pattern per line. Example:\n/product/*\n/category/old-news/*\nhttps://example.com/landing-old">' . esc_textarea($scanExcludePatterns) . '</textarea>';
    echo '<div class="lm-small" style="margin-top:4px;">URLs matching these patterns are skipped during scan. Use * as wildcard.</div>';
    echo '</p>';

    echo '<p style="margin:0 0 10px;">';
    echo '<label class="lm-small" style="' . esc_attr($settingsLabelStyle) . '">Posts per rebuild limit:</label>';
    echo '<input type="number" name="lm_max_posts_per_rebuild" min="0" max="50000" value="' . esc_attr((string)$maxPostsPerRebuild) . '" style="width:110px;" />';
    echo '<span class="lm-small" style="margin-left:8px;">Use 0 for no limit. Lower values reduce server load.</span>';
    echo '</p>';

    echo '</div>';
    echo '</details>';

    echo '<hr style="margin:14px 0;"/>';
    echo '<div style="margin:0 0 12px; padding:10px; border-left:4px solid #00a32a; background:#f6f7f7;">';
    echo '<div style="font-weight:600; margin-bottom:4px;">Section B: Cache &amp; Rebuild</div>';
    echo '<div class="lm-small">Control refresh frequency and rebuild workload for stable performance.</div>';
    echo '</div>';

    echo '<div style="' . esc_attr($settingsCardStyle) . '">';
    echo '<div style="font-weight:600; margin-bottom:6px;">Automatic Safety Limits (Auto)</div>';
    echo '<div class="lm-small">These limits are auto-calculated from your server memory.</div>';
    echo '<table class="widefat striped" style="margin-top:8px; max-width:760px;">';
    echo '<tbody>';
    echo '<tr><th style="width:260px;">Server memory limit</th><td>' . esc_html($runtimeMemoryLimitLabel) . '</td></tr>';
    echo '<tr><th>Max cached rows per scan</th><td>' . esc_html(number_format((int)$runtimeMaxRows)) . '</td></tr>';
    echo '<tr><th>Max pages scanned per request</th><td>' . esc_html((string)$runtimeMaxBatch) . ' pages/request</td></tr>';
    echo '<tr><th>Main cache size cap</th><td>' . esc_html($this->format_bytes_human($runtimeTransientMain)) . '</td></tr>';
    echo '<tr><th>Backup cache size cap</th><td>' . esc_html($this->format_bytes_human($runtimeTransientBackup)) . '</td></tr>';
    echo '</tbody>';
    echo '</table>';
    echo '</div>';

    $statsRefreshMinutes = isset($settings['stats_snapshot_ttl_min']) ? (int)$settings['stats_snapshot_ttl_min'] : (int)(self::STATS_SNAPSHOT_TTL / MINUTE_IN_SECONDS);
    $statsRefresh = $this->get_stats_refresh_value_and_period_from_minutes($statsRefreshMinutes);
    echo '<p style="margin:0 0 10px;">';
    echo '<label class="lm-small" style="' . esc_attr($settingsLabelStyle) . '">Refresh dashboard data every:</label>';
    echo '<input type="number" name="lm_stats_snapshot_ttl_value" min="1" max="12" value="' . esc_attr((string)$statsRefresh['value']) . '" style="width:90px;" /> ';
    echo '<select name="lm_stats_snapshot_ttl_period">';
    echo '<option value="hour"' . selected($statsRefresh['period'], 'hour', false) . '>Hour(s)</option>';
    echo '<option value="day"' . selected($statsRefresh['period'], 'day', false) . '>Day(s)</option>';
    echo '<option value="week"' . selected($statsRefresh['period'], 'week', false) . '>Week(s)</option>';
    echo '<option value="month"' . selected($statsRefresh['period'], 'month', false) . '>Month(s)</option>';
    echo '</select>';
    echo '<span class="lm-small" style="margin-left:8px;">Set 1-12 units. Shorter interval gives fresher data, longer interval reduces server load.</span>';
    echo '</p>';

    echo '<p style="margin:0 0 10px;">';
    echo '<label class="lm-small" style="' . esc_attr($settingsLabelStyle) . '">Rebuild mode:</label>';
    echo '<select name="lm_cache_rebuild_mode">';
    echo '<option value="incremental"' . selected((string)($settings['cache_rebuild_mode'] ?? 'incremental'), 'incremental', false) . '>Smart update (recommended)</option>';
    echo '<option value="full"' . selected((string)($settings['cache_rebuild_mode'] ?? 'incremental'), 'full', false) . '>Full rescan (slower)</option>';
    echo '</select>';
    echo '</p>';

    echo '<p style="margin:0 0 6px;">';
    echo '<label class="lm-small" style="' . esc_attr($settingsLabelStyle) . '">Pages processed per request:</label>';
    echo '<input type="number" name="lm_crawl_post_batch" min="20" max="' . esc_attr((string)$runtimeMaxBatch) . '" value="' . esc_attr((string)($settings['crawl_post_batch'] ?? (string)self::CRAWL_POST_BATCH)) . '" style="width:90px;" />';
    echo '<span class="lm-small" style="margin-left:8px;">Higher is faster but uses more resources. Lower is safer on small hosting plans.</span>';
    echo '<div class="lm-small" style="margin-top:4px;">Auto safety limit for this server: ' . esc_html((string)$runtimeMaxBatch) . ' pages/request.</div>';
    echo '</p>';

    $restBatchDefault = (int)($settings['crawl_post_batch'] ?? (string)self::CRAWL_POST_BATCH);
    if ($restBatchDefault < 20) $restBatchDefault = 20;
    if ($restBatchDefault > $runtimeMaxBatch) $restBatchDefault = (int)$runtimeMaxBatch;
    echo '<hr style="margin:14px 0;"/>';
    echo '<div id="lm-rest-rebuild-controls" style="margin:0 0 12px; padding:12px; border:1px solid #dcdcde; background:#fff; border-radius:4px;">';
    echo '<div style="font-weight:600; margin-bottom:6px;">Background Rebuild (REST)</div>';
    echo '<div class="lm-small" style="margin-bottom:10px;">Runs rebuild in small batches to reduce timeout risk on large sites.</div>';
    echo '<p style="margin:0 0 10px;">';
    echo '<label class="lm-small" style="' . esc_attr($settingsLabelStyle) . '">Pages per background step:</label>';
    echo '<input type="number" data-lm-rest-rebuild-batch min="20" max="' . esc_attr((string)$runtimeMaxBatch) . '" value="' . esc_attr((string)$restBatchDefault) . '" style="width:90px;" />';
    echo '<span class="lm-small" style="margin-left:8px;">Allowed range: 20-' . esc_html((string)$runtimeMaxBatch) . '.</span>';
    echo '</p>';
    echo '<p style="margin:0 0 10px;">';
    echo '<button type="button" class="button button-secondary" data-lm-rest-rebuild-run>Start / Continue REST Rebuild</button>';
    echo ' <button type="button" class="button" data-lm-rest-rebuild-refresh>Refresh Status</button>';
    echo '</p>';
    echo '<div data-lm-rest-rebuild-status class="lm-small" style="font-weight:600; margin-bottom:8px;">Checking status...</div>';
    echo '<div style="width:100%; max-width:620px; height:12px; border:1px solid #c3c4c7; border-radius:999px; background:#f0f0f1; overflow:hidden;">';
    echo '<div data-lm-rest-rebuild-bar role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" style="height:100%; width:0%; background:#2271b1; transition:width .2s ease;"></div>';
    echo '</div>';
    echo '<div data-lm-rest-rebuild-progress class="lm-small" style="margin-top:6px;">No active rebuild job.</div>';
    echo '<div data-lm-rest-rebuild-meta class="lm-small" style="margin-top:4px; color:#646970;">Status: idle | Rows: 0 | Batch: - | Updated: -</div>';
    echo '</div>';

    echo '</div>';
    }
    if ($activeTab === 'data') {
      echo '<h2 style="margin-top:0;">Data Cleanup Settings</h2>';
      echo '<div class="lm-small">Automatically remove old audit logs during daily maintenance runs.</div>';
      echo '<div style="margin-top:8px;">';
      echo '<label class="lm-small" style="margin-right:8px;">Keep audit logs for (days): </label>';
      echo '<input type="number" name="lm_audit_retention_days" min="30" max="3650" value="' . esc_attr((string)($settings['audit_retention_days'] ?? (string)self::AUDIT_RETENTION_DAYS)) . '" style="width:90px;" />';
      echo '</div>';
      echo '<div class="lm-small" style="margin-top:6px;">Range: 30–3650 days. Default: ' . esc_html((string)self::AUDIT_RETENTION_DAYS) . ' days.</div>';

      echo '<hr style="margin:14px 0;"/>';
      echo '<h2 style="margin-top:0;">Link Status Thresholds</h2>';
      echo '<div class="lm-small">Define status ranges for inbound, internal outbound, and external outbound links.</div>';
      echo '<div class="lm-small" style="margin-top:6px;">Tip: keep values ascending from left to right so status labels stay consistent.</div>';
      echo '<p style="margin:12px 0 8px; font-weight:600;">Inbound Link Thresholds</p>';
      echo '<div class="lm-small">Used in Pages Link status: Orphaned, Low, Standard, and Excellent.</div>';
      echo '<div style="margin-top:8px;">';
      echo '<div style="' . esc_attr($settingsCardStyle) . '">';
      echo '<div class="lm-small" style="margin:0 0 8px;">These values control the status label for inbound links per page.</div>';
      echo '<p style="margin:0 0 10px;">';
      echo '<label class="lm-small" style="' . esc_attr($settingsLabelStyle) . '">Orphaned if inbound links <=</label>';
      echo '<input type="number" name="lm_inbound_orphan_max" min="0" max="1000000" value="' . esc_attr((string)($settings['inbound_orphan_max'] ?? '0')) . '" style="width:110px;" />';
      echo '<span class="lm-small" style="margin-left:8px;">Default 0.</span>';
      echo '</p>';

      echo '<p style="margin:0 0 10px;">';
      echo '<label class="lm-small" style="' . esc_attr($settingsLabelStyle) . '">Low if inbound links <=</label>';
      echo '<input type="number" name="lm_inbound_low_max" min="0" max="1000000" value="' . esc_attr((string)($settings['inbound_low_max'] ?? '5')) . '" style="width:110px;" />';
      echo '<span class="lm-small" style="margin-left:8px;">Default 5.</span>';
      echo '</p>';

      echo '<p style="margin:0 0 6px;">';
      echo '<label class="lm-small" style="' . esc_attr($settingsLabelStyle) . '">Standard if inbound links <=</label>';
      echo '<input type="number" name="lm_inbound_standard_max" min="0" max="1000000" value="' . esc_attr((string)($settings['inbound_standard_max'] ?? '10')) . '" style="width:110px;" />';
      echo '<span class="lm-small" style="margin-left:8px;">Excellent starts above this value.</span>';
      echo '</p>';
      echo '</div>';

      echo '<p style="margin:12px 0 8px; font-weight:600;">Internal Outbound Thresholds (None / Low / Optimal / Excessive)</p>';
      echo '<div style="' . esc_attr($settingsCardStyle) . '">';
      echo '<div class="lm-small" style="margin:0 0 8px;">These values control status labels for outbound links to your own pages.</div>';
      echo '<p style="margin:0 0 10px;">';
      echo '<label class="lm-small" style="' . esc_attr($settingsLabelStyle) . '">None if internal outbound links <=</label>';
      echo '<input type="number" name="lm_internal_outbound_none_max" min="0" max="1000000" value="' . esc_attr((string)($settings['internal_outbound_none_max'] ?? '0')) . '" style="width:110px;" />';
      echo '<span class="lm-small" style="margin-left:8px;">Default 0.</span>';
      echo '</p>';

      echo '<p style="margin:0 0 10px;">';
      echo '<label class="lm-small" style="' . esc_attr($settingsLabelStyle) . '">Low if internal outbound links <=</label>';
      echo '<input type="number" name="lm_internal_outbound_low_max" min="0" max="1000000" value="' . esc_attr((string)($settings['internal_outbound_low_max'] ?? '5')) . '" style="width:110px;" />';
      echo '<span class="lm-small" style="margin-left:8px;">Default 5.</span>';
      echo '</p>';

      echo '<p style="margin:0 0 10px;">';
      echo '<label class="lm-small" style="' . esc_attr($settingsLabelStyle) . '">Optimal if internal outbound links <=</label>';
      echo '<input type="number" name="lm_internal_outbound_optimal_max" min="0" max="1000000" value="' . esc_attr((string)($settings['internal_outbound_optimal_max'] ?? '10')) . '" style="width:110px;" />';
      echo '<span class="lm-small" style="margin-left:8px;">Excessive starts above this value.</span>';
      echo '</p>';
      echo '</div>';

      echo '<p style="margin:12px 0 8px; font-weight:600;">External Outbound Thresholds (None / Low / Optimal / Excessive)</p>';
      echo '<div style="' . esc_attr($settingsCardStyle) . '">';
      echo '<div class="lm-small" style="margin:0 0 8px;">These values control status labels for outbound links to external websites.</div>';
      echo '<p style="margin:0 0 10px;">';
      echo '<label class="lm-small" style="' . esc_attr($settingsLabelStyle) . '">None if external outbound links <=</label>';
      echo '<input type="number" name="lm_external_outbound_none_max" min="0" max="1000000" value="' . esc_attr((string)($settings['external_outbound_none_max'] ?? '0')) . '" style="width:110px;" />';
      echo '<span class="lm-small" style="margin-left:8px;">Default 0.</span>';
      echo '</p>';

      echo '<p style="margin:0 0 10px;">';
      echo '<label class="lm-small" style="' . esc_attr($settingsLabelStyle) . '">Low if external outbound links <=</label>';
      echo '<input type="number" name="lm_external_outbound_low_max" min="0" max="1000000" value="' . esc_attr((string)($settings['external_outbound_low_max'] ?? '5')) . '" style="width:110px;" />';
      echo '<span class="lm-small" style="margin-left:8px;">Default 5.</span>';
      echo '</p>';

      echo '<p style="margin:0 0 6px;">';
      echo '<label class="lm-small" style="' . esc_attr($settingsLabelStyle) . '">Optimal if external outbound links <=</label>';
      echo '<input type="number" name="lm_external_outbound_optimal_max" min="0" max="1000000" value="' . esc_attr((string)($settings['external_outbound_optimal_max'] ?? '10')) . '" style="width:110px;" />';
      echo '<span class="lm-small" style="margin-left:8px;">Excessive starts above this value.</span>';
      echo '</p>';
      echo '</div>';
      echo '</div>';

      echo '<hr style="margin:14px 0;"/>';
      echo '<h2 style="margin-top:0;">Anchor Quality - Weak Phrase Rules</h2>';
      echo '<div class="lm-small">List words or phrases considered weak anchor text. Use one phrase per line or separate with commas. Empty anchor text is always Bad.</div>';
      echo '<div style="margin-top:8px;">';
      echo '<textarea name="lm_weak_anchor_patterns" rows="12" style="width:100%; max-width:760px;">' . esc_textarea((string)($settings['weak_anchor_patterns'] ?? '')) . '</textarea>';
      echo '</div>';
      echo '<div class="lm-small" style="margin-top:6px;">Even if this list is empty, length rules and empty-anchor checks still apply.</div>';
    }

    echo '<hr style="margin:14px 0;"/>';
    echo '<div class="lm-settings-actions">';
    echo '<div class="lm-settings-actions-primary">';
    submit_button('Save Settings', 'primary', 'submit', false);
    echo '<div class="lm-small lm-help-tip">Save your current tab settings.</div>';
    echo '</div>';

    if ($activeTab === 'performance') {
      echo '<div class="lm-settings-actions-card" style="' . esc_attr($settingsCardCompactStyle) . '">';
      echo '<div class="lm-settings-actions-title">Quick Performance Actions</div>';
      echo '<div class="lm-small lm-settings-actions-note">Use these presets to apply performance-focused defaults instantly.</div>';
      submit_button('Optimize for Speed', 'secondary', 'lm_reset_performance_defaults', false, ['value' => '1']);
      submit_button('Enable Low Memory Mode', 'secondary', 'lm_apply_low_memory_profile', false, ['value' => '1', 'style' => 'margin-left:8px;']);
      echo '<div class="lm-small lm-settings-actions-subtitle">Advanced maintenance:</div>';
      submit_button('Rebuild All Cache', 'secondary', 'lm_global_rebuild_cache', false, ['value' => '1']);
      echo '<div class="lm-small lm-help-tip">Use this only when cache data seems out of date.</div>';
      echo '</div>';
    }

    if ($activeTab === 'data') {
      echo '<div class="lm-settings-actions-card" style="' . esc_attr($settingsCardCompactStyle) . '">';
      echo '<div class="lm-settings-actions-title">Weak Phrase Actions</div>';
      echo '<div class="lm-small lm-settings-actions-note">Manage the weak phrase list used by anchor quality checks.</div>';
      submit_button('Reset Phrases to Defaults', 'secondary', 'lm_restore_weak_anchor_patterns', false, ['value' => '1']);
      echo '<div class="lm-danger-zone">';
      echo '<div class="lm-small lm-danger-text"><strong>Danger zone:</strong> this action removes all phrases in the editor field.</div>';
      submit_button('Delete All Phrases', 'delete', 'lm_clear_weak_anchor_patterns', false, [
        'value' => '1',
        'onclick' => "return confirm('Delete all weak phrases from this field? This cannot be undone.');",
      ]);
      echo '<div class="lm-small lm-help-tip">Tip: click "Reset Phrases to Defaults" if you only want to restore the default list.</div>';
      echo '</div>';
      echo '</div>';
    }
    echo '</div>';
    echo '</form>';
    echo '</div>';
    echo '</div>';
  }

  public function render_admin_links_target_page() {
    if (!$this->current_user_can_access_plugin()) wp_die('Unauthorized');

    $msg = isset($_GET['lm_msg']) ? sanitize_text_field((string)$_GET['lm_msg']) : '';
    $msgClass = $this->notice_class_for_message($msg, 'success');
    $groupOrderby = isset($_GET['lm_group_orderby']) ? sanitize_text_field((string)$_GET['lm_group_orderby']) : 'tag';
    if (!in_array($groupOrderby, ['tag', 'total_anchors', 'total_usage', 'inlink_usage', 'outbound_usage'], true)) $groupOrderby = 'tag';
    $groupOrder = isset($_GET['lm_group_order']) ? strtoupper(sanitize_text_field((string)$_GET['lm_group_order'])) : 'ASC';
    if (!in_array($groupOrder, ['ASC', 'DESC'], true)) $groupOrder = 'ASC';
    $groups = $this->get_anchor_groups();
    $groupNames = [];
    foreach ($groups as $g) {
      $gname = trim((string)($g['name'] ?? ''));
      if ($gname !== '') $groupNames[] = $gname;
    }
    $groupNames = array_values(array_unique($groupNames));
    $groupFilterRaw = isset($_GET['lm_group_filter']) ? wp_unslash($_GET['lm_group_filter']) : [];
    if (!is_array($groupFilterRaw)) {
      $groupFilterRaw = $groupFilterRaw === '' ? [] : [$groupFilterRaw];
    }
    $groupFilterSelected = [];
    foreach ($groupFilterRaw as $item) {
      $item = trim(sanitize_text_field((string)$item));
      if ($item === '') continue;
      if ($item === 'no_group' || in_array($item, $groupNames, true)) {
        $groupFilterSelected[$item] = true;
      }
    }
    $groupFilterSelected = array_keys($groupFilterSelected);
    $groupSearch = isset($_GET['lm_group_search']) ? sanitize_text_field((string)$_GET['lm_group_search']) : '';
    $groupSearchMode = isset($_GET['lm_group_search_mode']) ? $this->sanitize_text_match_mode((string)$_GET['lm_group_search_mode']) : 'contains';
    $groupingExportUrl = add_query_arg([
      'action' => 'lm_export_anchor_grouping_csv',
      self::NONCE_NAME => wp_create_nonce(self::NONCE_ACTION),
      'lm_group_orderby' => $groupOrderby,
      'lm_group_order' => $groupOrder,
      'lm_group_search' => $groupSearch,
      'lm_group_search_mode' => $groupSearchMode,
      'lm_group_filter' => $groupFilterSelected,
    ], admin_url('admin-post.php'));
    $targets = $this->sync_targets_with_groups($this->get_anchor_targets(), $groups);
    $targetsMap = [];
    $groupAnchorsMap = [];
    foreach ($targets as $t) {
      $t = trim((string)$t);
      if ($t === '') continue;
      $k = strtolower($t);
      if (!isset($targetsMap[$k])) $targetsMap[$k] = $t;
    }

    echo '<div class="wrap lm-wrap">';
    echo '<h1 class="lm-page-title">Links Manager - Links Target</h1>';
    if ($msg !== '') echo '<div class="notice notice-' . esc_attr($msgClass) . '"><p>' . esc_html($msg) . '</p></div>';

    echo '<div class="lm-grid">';
    echo '<div class="lm-card lm-card-full">';
    echo '<h2 style="margin-top:0;">Anchor Grouping</h2>';
    echo '<form method="get" action="" style="margin:0 0 8px;">';
    echo '<input type="hidden" name="page" value="links-manager-target"/>';
    echo '<div class="lm-filter-grid">';
    echo '<div class="lm-filter-field">';
    echo '<div class="lm-small" style="margin-bottom:6px;">Order By</div>';
    echo '<select name="lm_group_orderby">';
    echo '<option value="tag"' . selected($groupOrderby, 'tag', false) . '>Group</option>';
    echo '<option value="total_anchors"' . selected($groupOrderby, 'total_anchors', false) . '>Total Anchors</option>';
    echo '<option value="total_usage"' . selected($groupOrderby, 'total_usage', false) . '>Total Usage</option>';
    echo '<option value="inlink_usage"' . selected($groupOrderby, 'inlink_usage', false) . '>Total Inlinks</option>';
    echo '<option value="outbound_usage"' . selected($groupOrderby, 'outbound_usage', false) . '>Total Outbound</option>';
    echo '</select>';
    echo '</div>';
    echo '<div class="lm-filter-field">';
    echo '<div class="lm-small" style="margin-bottom:6px;">Sort</div>';
    echo '<select name="lm_group_order">';
    echo '<option value="ASC"' . selected($groupOrder, 'ASC', false) . '>ASC</option>';
    echo '<option value="DESC"' . selected($groupOrder, 'DESC', false) . '>DESC</option>';
    echo '</select>';
    echo '</div>';
    echo '<div class="lm-filter-field">';
    echo '<div class="lm-small" style="margin-bottom:6px;">Search Group Name</div>';
    echo '<input type="text" name="lm_group_search" value="' . esc_attr($groupSearch) . '" class="regular-text" placeholder="group keyword" />';
    echo '</div>';
    echo '<div class="lm-filter-field">';
    echo '<div class="lm-small" style="margin-bottom:6px;">Text Search Mode</div>';
    echo '<select name="lm_group_search_mode">';
    foreach ($this->get_text_match_modes() as $modeKey => $modeLabel) {
      echo '<option value="' . esc_attr($modeKey) . '"' . selected($groupSearchMode, $modeKey, false) . '>' . esc_html($modeLabel) . '</option>';
    }
    echo '</select>';
    echo '</div>';
    echo '<div class="lm-filter-field lm-filter-field-wide">';
    echo '<div class="lm-small" style="margin-bottom:6px;">Filter Groups (checklist)</div>';
    echo '<div class="lm-checklist">';
    echo '<label style="display:block; margin:0 0 4px;"><input type="checkbox" name="lm_group_filter[]" value="no_group"' . checked(in_array('no_group', $groupFilterSelected, true), true, false) . ' /> No Group</label>';
    foreach ($groupNames as $gn) {
      echo '<label style="display:block; margin:0 0 4px;"><input type="checkbox" name="lm_group_filter[]" value="' . esc_attr($gn) . '"' . checked(in_array($gn, $groupFilterSelected, true), true, false) . ' /> ' . esc_html($gn) . '</label>';
    }
    echo '</div>';
    echo '</div>';
    echo '<div class="lm-filter-field lm-filter-field-full">';
    submit_button('Apply', 'secondary', 'submit', false);
    echo ' <a class="button button-secondary" href="' . esc_url($groupingExportUrl) . '">Export CSV</a>';
    echo ' <a class="button" href="' . esc_url(admin_url('admin.php?page=links-manager-target')) . '">Reset Filter</a>';
    echo '</div>';
    echo '</div>';
    echo '</form>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:0 0 8px; padding:10px; border:1px solid #e5e7eb; border-radius:6px; background:#f9fafb;">';
    echo '<input type="hidden" name="action" value="lm_save_anchor_groups"/>';
    echo '<input type="hidden" name="' . esc_attr(self::NONCE_NAME) . '" value="' . esc_attr(wp_create_nonce(self::NONCE_ACTION)) . '"/>';
    echo '<label class="lm-small" style="display:block; margin-bottom:6px;">Add new group:</label>';
    echo '<input type="text" name="lm_group_name" class="regular-text" placeholder="Group name" required />';
    submit_button('Save Group', 'secondary', 'submit', false);
    echo '</form>';
    echo '<form id="lm-bulk-delete-groups-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:0 0 8px;">';
    echo '<input type="hidden" name="action" value="lm_bulk_delete_anchor_groups"/>';
    echo '<input type="hidden" name="' . esc_attr(self::NONCE_NAME) . '" value="' . esc_attr(wp_create_nonce(self::NONCE_ACTION)) . '"/>';
    submit_button('Delete Selected Groups', 'delete', 'submit', false, ['onclick' => "return confirm('Delete selected groups?');"]);
    echo '</form>';
    echo '<div class="lm-table-wrap lm-summary-table-wrap">';
    echo '<table class="widefat striped lm-table">';
    echo '<thead><tr>';
    echo '<th class="lm-col-block"><input type="checkbox" id="lm-select-all-groups" /></th>';
    echo $this->table_header_with_tooltip('lm-col-group', 'Group', 'Anchor group name.', 'left');
    echo $this->table_header_with_tooltip('lm-col-count', 'Total Anchors', 'Number of anchors inside this group.');
    echo $this->table_header_with_tooltip('lm-col-total', 'Total Usage Across All Pages (All Link Types)', 'Combined uses of all anchors in this group.');
    echo $this->table_header_with_tooltip('lm-col-count', '%', 'Share of total usage among groups.');
    echo $this->table_header_with_tooltip('lm-col-inlink', 'Total Use as Inlinks', 'Usage count when links are internal.');
    echo $this->table_header_with_tooltip('lm-col-count', '%', 'Share of inlink usage among groups.');
    echo $this->table_header_with_tooltip('lm-col-outbound', 'Total Use as Outbound', 'Usage count when links are outbound.');
    echo $this->table_header_with_tooltip('lm-col-count', '%', 'Share of outbound usage among groups.');
    echo $this->table_header_with_tooltip('lm-col-action', 'Action', 'Edit or delete actions for group.', 'right');
    echo '</tr></thead><tbody>';

    if (empty($groups)) {
      echo '<tr><td colspan="10">No groups yet.</td></tr>';
    } else {
      // Get usage data for all anchors
      $all = $this->build_or_get_cache('any', false, $this->sanitize_wpml_lang_filter($this->get_wpml_current_language()));
      $anchorUsage = [];
      foreach ($all as $row) {
        $a = trim((string)($row['anchor_text'] ?? ''));
        if ($a === '') continue;
        $k = strtolower($a);
        if (!isset($anchorUsage[$k])) $anchorUsage[$k] = ['total' => 0, 'inlink' => 0, 'outbound' => 0];
        $anchorUsage[$k]['total']++;
        if (($row['link_type'] ?? '') === 'inlink') $anchorUsage[$k]['inlink']++;
        if (($row['link_type'] ?? '') === 'exlink') $anchorUsage[$k]['outbound']++;
      }

      $groupCounts = [];
      $groupUsage = [];
      $totalAnchors = 0;
      $groupIndexByName = [];
      $groupedKeys = [];
      $groupAnchorsMap = [];
      foreach ($groups as $idx => $g) {
        $gname = trim((string)($g['name'] ?? ''));
        if ($gname === '') continue;
        if (!isset($groupIndexByName[$gname])) $groupIndexByName[$gname] = $idx;
        $anchors = isset($g['anchors']) ? (array)$g['anchors'] : [];
        $count = 0;
        $gUsage = ['total' => 0, 'inlink' => 0, 'outbound' => 0];
        foreach ($anchors as $a) {
          $a = trim((string)$a);
          if ($a === '') continue;
          $count++;
          $k = strtolower($a);
          $groupedKeys[$k] = true;
          if (!isset($groupAnchorsMap[$k])) $groupAnchorsMap[$k] = $a;
          // Add usage stats for this anchor to group total
          if (isset($anchorUsage[$k])) {
            $gUsage['total'] += $anchorUsage[$k]['total'];
            $gUsage['inlink'] += $anchorUsage[$k]['inlink'];
            $gUsage['outbound'] += $anchorUsage[$k]['outbound'];
          }
        }
        $groupCounts[$gname] = $count;
        $groupUsage[$gname] = $gUsage;
        $totalAnchors += $count;
      }

      if (empty($groupCounts)) {
        echo '<tr><td colspan="10">No groups yet.</td></tr>';
      } else {
        $noGroupCount = 0;
        $noGroupUsage = ['total' => 0, 'inlink' => 0, 'outbound' => 0];
        foreach ($targetsMap as $k => $label) {
          if (!isset($groupedKeys[$k])) {
            $noGroupCount++;
            // Add usage stats for ungrouped anchors
            if (isset($anchorUsage[$k])) {
              $noGroupUsage['total'] += $anchorUsage[$k]['total'];
              $noGroupUsage['inlink'] += $anchorUsage[$k]['inlink'];
              $noGroupUsage['outbound'] += $anchorUsage[$k]['outbound'];
            }
          }
        }
        if ($noGroupCount > 0) {
          if (!isset($groupCounts['No Group'])) $groupCounts['No Group'] = 0;
          $groupCounts['No Group'] += $noGroupCount;
          if (!isset($groupUsage['No Group'])) $groupUsage['No Group'] = ['total' => 0, 'inlink' => 0, 'outbound' => 0];
          $groupUsage['No Group']['total'] += $noGroupUsage['total'];
          $groupUsage['No Group']['inlink'] += $noGroupUsage['inlink'];
          $groupUsage['No Group']['outbound'] += $noGroupUsage['outbound'];
          $totalAnchors += $noGroupCount;
        }
        // Calculate totals for percentage calculation.
        // When group filter is active, percentages are based on selected groups only.
        $filteredGroupUsage = [];
        foreach ($groupUsage as $gname => $usage) {
          $usageKey = $gname === 'No Group' ? 'no_group' : $gname;
          if (!empty($groupFilterSelected) && !in_array($usageKey, $groupFilterSelected, true)) {
            continue;
          }
          if ($groupSearch !== '' && !$this->text_matches((string)$gname, (string)$groupSearch, (string)$groupSearchMode)) {
            continue;
          }
          $filteredGroupUsage[$gname] = $usage;
        }

        $totalUsage = 0;
        $totalInlinks = 0;
        $totalOutbound = 0;

        // Total usage bases for percentage distribution among rendered groups
        foreach ($filteredGroupUsage as $usage) {
          $totalUsage += (int)$usage['total'];
          $totalInlinks += (int)$usage['inlink'];
          $totalOutbound += (int)$usage['outbound'];
        }

        $entries = [];
        $sumBase = 0;
        $sumBaseInlink = 0;
        $sumBaseOutbound = 0;
        $i = 0;
        foreach ($filteredGroupUsage as $gname => $usageRow) {
          $count = isset($groupCounts[$gname]) ? (int)$groupCounts[$gname] : 0;
          $usage = (int)($usageRow['total'] ?? 0);
          $usageInlink = (int)($usageRow['inlink'] ?? 0);
          $usageOutbound = (int)($usageRow['outbound'] ?? 0);
          
          $raw = ($totalUsage > 0) ? (($usage / $totalUsage) * 100) : 0;
          $base = (int)floor($raw);
          $frac = $raw - $base;
          
          $rawInlink = ($totalInlinks > 0) ? (($usageInlink / $totalInlinks) * 100) : 0;
          
          $rawOutbound = ($totalOutbound > 0) ? (($usageOutbound / $totalOutbound) * 100) : 0;
          $baseOutbound = (int)floor($rawOutbound);
          $fracOutbound = $rawOutbound - $baseOutbound;
          
          $entries[] = [
            'name' => $gname,
            'count' => $count,
            'usage' => $usage,
            'usageInlink' => $usageInlink,
            'usageOutbound' => $usageOutbound,
            'base' => $base,
            'frac' => $frac,
            'rawInlink' => $rawInlink,
            'baseOutbound' => $baseOutbound,
            'fracOutbound' => $fracOutbound,
            'order' => $i,
          ];
          $sumBase += $base;
          $sumBaseOutbound += $baseOutbound;
          $i++;
        }

        $remainder = max(0, 100 - $sumBase);
        if ($totalUsage > 0 && $remainder > 0 && count($entries) > 0) {
          usort($entries, function($a, $b) {
            if ($a['frac'] === $b['frac']) return $a['order'] <=> $b['order'];
            return ($a['frac'] < $b['frac']) ? 1 : -1;
          });
          $len = count($entries);
          for ($k = 0; $k < $remainder; $k++) {
            $entries[$k % $len]['base']++;
          }
          usort($entries, function($a, $b) {
            return $a['order'] <=> $b['order'];
          });
        }
        
        // Note: No remainder distribution for inlinks percentage
        // Inlinks percentage shows coverage (can be < 100% if not all inlinks use targeted anchors)
        
        // Distribute remainder for outbound percentage
        $remainderOutbound = max(0, 100 - $sumBaseOutbound);
        if ($totalOutbound > 0 && $remainderOutbound > 0 && count($entries) > 0) {
          usort($entries, function($a, $b) {
            if ($a['fracOutbound'] === $b['fracOutbound']) return $a['order'] <=> $b['order'];
            return ($a['fracOutbound'] < $b['fracOutbound']) ? 1 : -1;
          });
          $len = count($entries);
          for ($k = 0; $k < $remainderOutbound; $k++) {
            $entries[$k % $len]['baseOutbound']++;
          }
          usort($entries, function($a, $b) {
            return $a['order'] <=> $b['order'];
          });
        }

        usort($entries, function($a, $b) use ($groupOrderby, $groupOrder) {
          $dir = $groupOrder === 'ASC' ? 1 : -1;
          $cmp = 0;
          switch ($groupOrderby) {
            case 'total_anchors':
              $cmp = ((int)$a['count'] <=> (int)$b['count']);
              break;
            case 'total_usage':
              $cmp = ((int)$a['usage'] <=> (int)$b['usage']);
              break;
            case 'inlink_usage':
              $cmp = ((int)$a['usageInlink'] <=> (int)$b['usageInlink']);
              break;
            case 'outbound_usage':
              $cmp = ((int)$a['usageOutbound'] <=> (int)$b['usageOutbound']);
              break;
            case 'tag':
            default:
              $cmp = strcmp((string)$a['name'], (string)$b['name']);
              break;
          }
          if ($cmp === 0) {
            $cmp = strcmp((string)$a['name'], (string)$b['name']);
          }
          return $cmp * $dir;
        });

        foreach ($entries as $e) {
          $gidx = isset($groupIndexByName[$e['name']]) ? (int)$groupIndexByName[$e['name']] : -1;
          $editGroupUrl = $gidx >= 0 ? admin_url('admin.php?page=links-manager-target&lm_edit_group=' . $gidx) : '';
          $delGroupUrl = $gidx >= 0 ? admin_url('admin-post.php?action=lm_delete_anchor_group&' . self::NONCE_NAME . '=' . wp_create_nonce(self::NONCE_ACTION) . '&lm_group_idx=' . $gidx) : '';
          $gUsage = isset($groupUsage[$e['name']]) ? $groupUsage[$e['name']] : ['total' => 0, 'inlink' => 0, 'outbound' => 0];
          echo '<tr>';
          if ($gidx >= 0) {
            echo '<td class="lm-col-block" style="text-align:center;"><input type="checkbox" class="lm-group-check" name="lm_group_indices[]" value="' . esc_attr((string)$gidx) . '" form="lm-bulk-delete-groups-form" /></td>';
          } else {
            echo '<td class="lm-col-block" style="text-align:center;">—</td>';
          }
          echo '<td class="lm-col-group"><span class="lm-trunc" title="' . esc_attr($e['name']) . '">' . esc_html($e['name']) . '</span></td>';
          echo '<td class="lm-col-count" style="text-align:center;">' . esc_html((string)$e['count']) . '</td>';
          echo '<td class="lm-col-total">' . esc_html((string)$gUsage['total']) . '</td>';
          $pctLabel = number_format((float)$e['base'], 1);
          echo '<td class="lm-col-count" style="text-align:center;">' . esc_html($pctLabel) . '%</td>';
          echo '<td class="lm-col-inlink">' . esc_html((string)$gUsage['inlink']) . '</td>';
          $pctInlinkLabel = number_format((float)$e['rawInlink'], 1);
          echo '<td class="lm-col-count" style="text-align:center;">' . esc_html($pctInlinkLabel) . '%</td>';
          echo '<td class="lm-col-outbound">' . esc_html((string)$gUsage['outbound']) . '</td>';
          $pctOutboundLabel = number_format((float)$e['baseOutbound'], 1);
          echo '<td class="lm-col-count" style="text-align:center;">' . esc_html($pctOutboundLabel) . '%</td>';
          if ($gidx >= 0) {
            echo '<td class="lm-col-action"><a class="button button-small" href="' . esc_url($editGroupUrl) . '">Edit</a> <a class="button button-small" href="' . esc_url($delGroupUrl) . '">Delete</a></td>';
          } else {
            echo '<td class="lm-col-action">—</td>';
          }
          echo '</tr>';
        }
      }
    }

    echo '</tbody></table></div>';

    $editGroupIdx = isset($_GET['lm_edit_group']) ? intval($_GET['lm_edit_group']) : -1;
    if ($editGroupIdx >= 0 && isset($groups[$editGroupIdx])) {
      $g = $groups[$editGroupIdx];
      $gname = (string)($g['name'] ?? '');
      $ganchors = isset($g['anchors']) ? (array)$g['anchors'] : [];
      echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:10px 0 0; padding:10px; border:1px solid #e5e7eb; border-radius:6px; background:#f9fafb;">';
      echo '<input type="hidden" name="action" value="lm_update_anchor_group"/>';
      echo '<input type="hidden" name="' . esc_attr(self::NONCE_NAME) . '" value="' . esc_attr(wp_create_nonce(self::NONCE_ACTION)) . '"/>';
      echo '<input type="hidden" name="lm_group_idx" value="' . esc_attr((string)$editGroupIdx) . '"/>';
      echo '<label class="lm-small" style="display:block; margin-bottom:6px;">Edit group:</label>';
      echo '<input type="text" name="lm_group_name" value="' . esc_attr($gname) . '" class="regular-text" placeholder="Group name" />';
      echo '<textarea name="lm_group_anchors" class="large-text" rows="4" style="margin-top:6px;" placeholder="One anchor per line or comma">' . esc_textarea(implode("\n", $ganchors)) . '</textarea>';
      submit_button('Save Changes', 'primary', 'submit', false);
      echo ' <a class="button" href="' . esc_url(admin_url('admin.php?page=links-manager-target')) . '">Cancel</a>';
      echo '</form>';
    }

    echo '</div>';

    echo '<div class="lm-card lm-card-target">';
    echo '<h2 style="margin-top:0;">Target Anchor Text</h2>';
    echo '<div class="lm-small">Targets are checked across all public posts/pages.</div>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:8px;" enctype="multipart/form-data">';
    echo '<input type="hidden" name="action" value="lm_save_anchor_targets"/>';
    echo '<input type="hidden" name="' . esc_attr(self::NONCE_NAME) . '" value="' . esc_attr(wp_create_nonce(self::NONCE_ACTION)) . '"/>';
    echo '<div class="lm-tabs" role="tablist" aria-label="Target Anchor Mode">';
    echo '<button type="button" class="lm-tab is-active" data-lm-tab="only" aria-selected="true">Only anchor</button>';
    echo '<button type="button" class="lm-tab" data-lm-tab="tags" aria-selected="false">Anchor with groups</button>';
    echo '</div>';
    echo '<input type="hidden" name="lm_anchor_mode" value="only"/>';
    echo '<div class="lm-textarea-wrap">';
    echo '<textarea name="lm_anchor_targets" placeholder="Enter anchors, one per line or comma-separated (e.g. buy shoes, contact us)"></textarea>';
    echo '<div class="lm-textarea-hint" data-lm-hint="only">Enter anchors, one per line or comma-separated</div>';
    echo '<div class="lm-textarea-hint" data-lm-hint="tags" style="display:none;">Format: anchor text, group</div>';
    echo '<div class="lm-textarea-actions">';
    echo '<label>CSV or TXT <input type="file" name="lm_anchor_file" accept=".csv,.txt" /></label>';
    echo '</div>';
    echo '</div>';
    submit_button('Save Targets', 'secondary', 'submit', false);
    echo '</form>';

    $editIdx = isset($_GET['lm_edit_target']) ? intval($_GET['lm_edit_target']) : -1;
    if ($editIdx >= 0 && isset($targets[$editIdx])) {
      echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:10px; padding:10px; border:1px solid #e5e7eb; border-radius:6px; background:#f9fafb;">';
      echo '<input type="hidden" name="action" value="lm_update_anchor_target"/>';
      echo '<input type="hidden" name="' . esc_attr(self::NONCE_NAME) . '" value="' . esc_attr(wp_create_nonce(self::NONCE_ACTION)) . '"/>';
      echo '<input type="hidden" name="lm_target_idx" value="' . esc_attr((string)$editIdx) . '"/>';
      echo '<label class="lm-small" style="display:block; margin-bottom:6px;">Edit target:</label>';
      echo '<input type="text" name="lm_target_value" value="' . esc_attr((string)$targets[$editIdx]) . '" class="regular-text" />';
      submit_button('Save Changes', 'primary', 'submit', false);
      echo ' <a class="button" href="' . esc_url(admin_url('admin.php?page=links-manager-target')) . '">Cancel</a>';
      echo '</form>';
    }

    // Summary table (section 3)
    echo '</div>';
    echo '<div class="lm-card lm-card-summary lm-card-full">';
    echo '<h2 style="margin-top:0;">Anchor Target Summary</h2>';
    echo '<form id="lm-bulk-delete-targets-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:0 0 8px;">';
    echo '<input type="hidden" name="action" value="lm_bulk_delete_anchor_targets"/>';
    echo '<input type="hidden" name="' . esc_attr(self::NONCE_NAME) . '" value="' . esc_attr(wp_create_nonce(self::NONCE_ACTION)) . '"/>';
    submit_button('Delete Selected Targets', 'delete', 'submit', false, ['onclick' => "return confirm('Delete selected targets?');"]);
    echo '</form>';
    $summaryGroupsRaw = isset($_GET['lm_summary_groups']) ? wp_unslash($_GET['lm_summary_groups']) : [];
    if (!is_array($summaryGroupsRaw)) {
      $summaryGroupsRaw = $summaryGroupsRaw === '' ? [] : [$summaryGroupsRaw];
    }
    // Backward compatibility for old single-select query parameter.
    if (empty($summaryGroupsRaw) && isset($_GET['lm_summary_group'])) {
      $legacySummaryGroup = trim(sanitize_text_field((string)$_GET['lm_summary_group']));
      if ($legacySummaryGroup !== '') $summaryGroupsRaw[] = $legacySummaryGroup;
    }
    $summaryGroupSelected = [];
    foreach ($summaryGroupsRaw as $item) {
      $item = trim(sanitize_text_field((string)$item));
      if ($item === '') continue;
      if ($item === 'no_group' || in_array($item, $groupNames, true)) {
        $summaryGroupSelected[$item] = true;
      }
    }
    $summaryGroupSelected = array_keys($summaryGroupSelected);
    $summaryGroupSearch = isset($_GET['lm_summary_group_search']) ? sanitize_text_field((string)$_GET['lm_summary_group_search']) : '';
    $summaryAnchor = isset($_GET['lm_summary_anchor']) ? sanitize_text_field((string)$_GET['lm_summary_anchor']) : '';
    $summaryAnchorSearch = isset($_GET['lm_summary_anchor_search']) ? sanitize_text_field((string)$_GET['lm_summary_anchor_search']) : '';
    $summarySearchMode = isset($_GET['lm_summary_search_mode']) ? $this->sanitize_text_match_mode((string)$_GET['lm_summary_search_mode']) : 'contains';
    if ($summaryAnchorSearch === '' && $summaryAnchor !== '') {
      $summaryAnchorSearch = $summaryAnchor;
      $summarySearchMode = 'exact';
    }
    $summaryPostTypeOptions = $this->get_filterable_post_types();
    $summaryPostCategoryOptions = $this->get_post_term_options('category');
    $summaryPostTagOptions = $this->get_post_term_options('post_tag');
    $summaryPostType = isset($_GET['lm_summary_post_type']) ? sanitize_key((string)$_GET['lm_summary_post_type']) : 'any';
    if ($summaryPostType !== 'any' && !isset($summaryPostTypeOptions[$summaryPostType])) $summaryPostType = 'any';
    $summaryPostCategory = isset($_GET['lm_summary_post_category']) ? $this->sanitize_post_term_filter($_GET['lm_summary_post_category'], 'category') : 0;
    $summaryPostTag = isset($_GET['lm_summary_post_tag']) ? $this->sanitize_post_term_filter($_GET['lm_summary_post_tag'], 'post_tag') : 0;
    if ($summaryPostType !== 'any' && $summaryPostType !== 'post') {
      $summaryPostCategory = 0;
      $summaryPostTag = 0;
    }
    $summaryLocation = isset($_GET['lm_summary_location']) ? sanitize_text_field((string)$_GET['lm_summary_location']) : 'any';
    if ($summaryLocation === '') $summaryLocation = 'any';
    $summarySourceType = isset($_GET['lm_summary_source_type'])
      ? $this->sanitize_source_type_filter($_GET['lm_summary_source_type'])
      : 'any';
    $summaryLinkType = isset($_GET['lm_summary_link_type']) ? sanitize_text_field((string)$_GET['lm_summary_link_type']) : 'any';
    if (!in_array($summaryLinkType, ['any','inlink','exlink'], true)) $summaryLinkType = 'any';
    $summaryValueContains = isset($_GET['lm_summary_value']) ? sanitize_text_field((string)$_GET['lm_summary_value']) : '';
    $summarySourceContains = isset($_GET['lm_summary_source']) ? sanitize_text_field((string)$_GET['lm_summary_source']) : '';
    $summaryTitleContains = isset($_GET['lm_summary_title']) ? sanitize_text_field((string)$_GET['lm_summary_title']) : '';
    $summaryAuthorContains = isset($_GET['lm_summary_author']) ? sanitize_text_field((string)$_GET['lm_summary_author']) : '';
    $summarySeoFlag = isset($_GET['lm_summary_seo_flag']) ? sanitize_text_field((string)$_GET['lm_summary_seo_flag']) : 'any';
    if (!in_array($summarySeoFlag, ['any','dofollow','nofollow','sponsored','ugc'], true)) $summarySeoFlag = 'any';
    $summaryTotalMin = isset($_GET['lm_summary_total_min']) ? (string)$_GET['lm_summary_total_min'] : '';
    $summaryTotalMax = isset($_GET['lm_summary_total_max']) ? (string)$_GET['lm_summary_total_max'] : '';
    $summaryInMin = isset($_GET['lm_summary_in_min']) ? (string)$_GET['lm_summary_in_min'] : '';
    $summaryInMax = isset($_GET['lm_summary_in_max']) ? (string)$_GET['lm_summary_in_max'] : '';
    $summaryOutMin = isset($_GET['lm_summary_out_min']) ? (string)$_GET['lm_summary_out_min'] : '';
    $summaryOutMax = isset($_GET['lm_summary_out_max']) ? (string)$_GET['lm_summary_out_max'] : '';
    $summaryOrderby = isset($_GET['lm_summary_orderby']) ? sanitize_text_field((string)$_GET['lm_summary_orderby']) : 'anchor';
    if (!in_array($summaryOrderby, ['group', 'anchor', 'total', 'inlink', 'outbound'], true)) $summaryOrderby = 'anchor';
    $summaryOrder = isset($_GET['lm_summary_order']) ? strtoupper(sanitize_text_field((string)$_GET['lm_summary_order'])) : 'ASC';
    if (!in_array($summaryOrder, ['ASC', 'DESC'], true)) $summaryOrder = 'ASC';
    $summaryPerPage = isset($_GET['lm_summary_per_page']) ? intval($_GET['lm_summary_per_page']) : 50;
    if ($summaryPerPage < 10) $summaryPerPage = 10;
    if ($summaryPerPage > 500) $summaryPerPage = 500;
    $summaryPaged = isset($_GET['lm_summary_paged']) ? intval($_GET['lm_summary_paged']) : 1;
    if ($summaryPaged < 1) $summaryPaged = 1;
    $summaryTotalMinNum = $summaryTotalMin === '' ? null : intval($summaryTotalMin);
    $summaryTotalMaxNum = $summaryTotalMax === '' ? null : intval($summaryTotalMax);
    $summaryInMinNum = $summaryInMin === '' ? null : intval($summaryInMin);
    $summaryInMaxNum = $summaryInMax === '' ? null : intval($summaryInMax);
    $summaryOutMinNum = $summaryOutMin === '' ? null : intval($summaryOutMin);
    $summaryOutMaxNum = $summaryOutMax === '' ? null : intval($summaryOutMax);
    $summaryExportUrl = add_query_arg([
      'action' => 'lm_export_links_target_csv',
      self::NONCE_NAME => wp_create_nonce(self::NONCE_ACTION),
      'lm_summary_groups' => $summaryGroupSelected,
      'lm_summary_group_search' => $summaryGroupSearch,
      'lm_summary_post_type' => $summaryPostType,
      'lm_summary_post_category' => $summaryPostCategory,
      'lm_summary_post_tag' => $summaryPostTag,
      'lm_summary_location' => $summaryLocation,
      'lm_summary_source_type' => $summarySourceType,
      'lm_summary_link_type' => $summaryLinkType,
      'lm_summary_value' => $summaryValueContains,
      'lm_summary_source' => $summarySourceContains,
      'lm_summary_title' => $summaryTitleContains,
      'lm_summary_author' => $summaryAuthorContains,
      'lm_summary_seo_flag' => $summarySeoFlag,
      'lm_summary_anchor' => $summaryAnchor,
      'lm_summary_anchor_search' => $summaryAnchorSearch,
      'lm_summary_search_mode' => $summarySearchMode,
      'lm_summary_total_min' => $summaryTotalMin,
      'lm_summary_total_max' => $summaryTotalMax,
      'lm_summary_in_min' => $summaryInMin,
      'lm_summary_in_max' => $summaryInMax,
      'lm_summary_out_min' => $summaryOutMin,
      'lm_summary_out_max' => $summaryOutMax,
      'lm_summary_orderby' => $summaryOrderby,
      'lm_summary_order' => $summaryOrder,
    ], admin_url('admin-post.php'));
    echo '<form method="get" action="" style="margin:8px 0 10px;">';
    echo '<input type="hidden" name="page" value="links-manager-target"/>';
    echo '<div class="lm-filter-grid">';
    echo '<div class="lm-filter-field lm-filter-field-wide">';
    echo '<div class="lm-small" style="margin-bottom:6px;">Group (checklist)</div>';
    echo '<div class="lm-checklist">';
    echo '<label style="display:block; margin:0 0 4px;"><input type="checkbox" name="lm_summary_groups[]" value="no_group"' . checked(in_array('no_group', $summaryGroupSelected, true), true, false) . ' /> No Group</label>';
    foreach ($groupNames as $gn) {
      echo '<label style="display:block; margin:0 0 4px;"><input type="checkbox" name="lm_summary_groups[]" value="' . esc_attr($gn) . '"' . checked(in_array($gn, $summaryGroupSelected, true), true, false) . ' /> ' . esc_html($gn) . '</label>';
    }
    echo '</div>';
    echo '<div class="lm-small" style="margin-top:6px;">Leave all unchecked to show all groups.</div>';
    echo '</div>';

    echo '<div class="lm-filter-field">';
    echo '<div class="lm-small" style="margin-bottom:6px;">Post Type</div>';
    echo '<select name="lm_summary_post_type" class="lm-filter-select">';
    echo '<option value="any"' . selected($summaryPostType, 'any', false) . '>All</option>';
    foreach ($summaryPostTypeOptions as $ptKey => $ptLabel) {
      echo '<option value="' . esc_attr((string)$ptKey) . '"' . selected($summaryPostType, (string)$ptKey, false) . '>' . esc_html((string)$ptLabel) . ' (' . esc_html((string)$ptKey) . ')</option>';
    }
    echo '</select>';
    echo '</div>';

    echo '<div class="lm-filter-field">';
    echo '<div class="lm-small" style="margin-bottom:6px;">Post Category</div>';
    echo '<select name="lm_summary_post_category" class="lm-filter-select">';
    echo '<option value="0"' . selected((int)$summaryPostCategory, 0, false) . '>All</option>';
    foreach ($summaryPostCategoryOptions as $termId => $termLabel) {
      echo '<option value="' . esc_attr((string)$termId) . '"' . selected((int)$summaryPostCategory, (int)$termId, false) . '>' . esc_html($termLabel) . '</option>';
    }
    echo '</select>';
    echo '</div>';

    echo '<div class="lm-filter-field">';
    echo '<div class="lm-small" style="margin-bottom:6px;">Post Tag</div>';
    echo '<select name="lm_summary_post_tag" class="lm-filter-select">';
    echo '<option value="0"' . selected((int)$summaryPostTag, 0, false) . '>All</option>';
    foreach ($summaryPostTagOptions as $termId => $termLabel) {
      echo '<option value="' . esc_attr((string)$termId) . '"' . selected((int)$summaryPostTag, (int)$termId, false) . '>' . esc_html($termLabel) . '</option>';
    }
    echo '</select>';
    echo '</div>';

    echo '<div class="lm-filter-field">';
    echo '<div class="lm-small" style="margin-bottom:6px;">Link Type</div>';
    echo '<select name="lm_summary_link_type" class="lm-filter-select">';
    echo '<option value="any"' . selected($summaryLinkType, 'any', false) . '>All</option>';
    echo '<option value="inlink"' . selected($summaryLinkType, 'inlink', false) . '>Internal</option>';
    echo '<option value="exlink"' . selected($summaryLinkType, 'exlink', false) . '>External</option>';
    echo '</select>';
    echo '</div>';

    echo '<div class="lm-filter-field">';
    echo '<div class="lm-small" style="margin-bottom:6px;">Source Type</div>';
    echo '<select name="lm_summary_source_type" class="lm-filter-select">';
    foreach ($this->get_filterable_source_type_options(true) as $sourceKey => $sourceLabel) {
      echo '<option value="' . esc_attr((string)$sourceKey) . '"' . selected($summarySourceType, (string)$sourceKey, false) . '>' . esc_html((string)$sourceLabel) . '</option>';
    }
    echo '</select>';
    echo '</div>';

    echo '<div class="lm-filter-field">';
    echo '<div class="lm-small" style="margin-bottom:6px;">SEO Flags</div>';
    echo '<select name="lm_summary_seo_flag" class="lm-filter-select">';
    echo '<option value="any"' . selected($summarySeoFlag, 'any', false) . '>All</option>';
    echo '<option value="dofollow"' . selected($summarySeoFlag, 'dofollow', false) . '>Dofollow</option>';
    echo '<option value="nofollow"' . selected($summarySeoFlag, 'nofollow', false) . '>Nofollow</option>';
    echo '<option value="sponsored"' . selected($summarySeoFlag, 'sponsored', false) . '>Sponsored</option>';
    echo '<option value="ugc"' . selected($summarySeoFlag, 'ugc', false) . '>UGC</option>';
    echo '</select>';
    echo '</div>';

    echo '<div class="lm-filter-field">';
    echo '<div class="lm-small" style="margin-bottom:6px;">Link Location</div>';
    echo '<input type="text" name="lm_summary_location" value="' . esc_attr($summaryLocation === 'any' ? '' : (string)$summaryLocation) . '" class="regular-text" placeholder="content / excerpt / meta:xxx" />';
    echo '</div>';

    echo '<div class="lm-filter-field">';
    echo '<div class="lm-small" style="margin-bottom:6px;">Search Group Name</div>';
    echo '<input type="text" name="lm_summary_group_search" value="' . esc_attr($summaryGroupSearch) . '" class="regular-text" placeholder="group keyword" />';
    echo '</div>';

    echo '<div class="lm-filter-field">';
    echo '<div class="lm-small" style="margin-bottom:6px;">Search Destination URL</div>';
    echo '<input type="text" name="lm_summary_value" value="' . esc_attr($summaryValueContains) . '" class="regular-text" placeholder="example.com / /contact" />';
    echo '</div>';

    echo '<div class="lm-filter-field">';
    echo '<div class="lm-small" style="margin-bottom:6px;">Search Source URL</div>';
    echo '<input type="text" name="lm_summary_source" value="' . esc_attr($summarySourceContains) . '" class="regular-text" placeholder="/category /slug" />';
    echo '</div>';

    echo '<div class="lm-filter-field">';
    echo '<div class="lm-small" style="margin-bottom:6px;">Search Title</div>';
    echo '<input type="text" name="lm_summary_title" value="' . esc_attr($summaryTitleContains) . '" class="regular-text" placeholder="post title" />';
    echo '</div>';

    echo '<div class="lm-filter-field">';
    echo '<div class="lm-small" style="margin-bottom:6px;">Search Author</div>';
    echo '<input type="text" name="lm_summary_author" value="' . esc_attr($summaryAuthorContains) . '" class="regular-text" placeholder="author" />';
    echo '</div>';

    echo '<div class="lm-filter-field">';
    echo '<div class="lm-small" style="margin-bottom:6px;">Search Anchor Text</div>';
    echo '<input type="text" name="lm_summary_anchor_search" value="' . esc_attr($summaryAnchorSearch) . '" class="regular-text" placeholder="anchor keyword" />';
    echo '</div>';

    echo '<div class="lm-filter-field">';
    echo '<div class="lm-small" style="margin-bottom:6px;">Text Search Mode</div>';
    echo '<select name="lm_summary_search_mode">';
    foreach ($this->get_text_match_modes() as $modeKey => $modeLabel) {
      echo '<option value="' . esc_attr($modeKey) . '"' . selected($summarySearchMode, $modeKey, false) . '>' . esc_html($modeLabel) . '</option>';
    }
    echo '</select>';
    echo '</div>';

    echo '<div class="lm-filter-field">';
    echo '<div class="lm-small" style="margin-bottom:6px;">Total min</div>';
    echo '<input type="number" name="lm_summary_total_min" value="' . esc_attr($summaryTotalMin) . '" placeholder="Total min" />';
    echo '</div>';

    echo '<div class="lm-filter-field">';
    echo '<div class="lm-small" style="margin-bottom:6px;">Total max</div>';
    echo '<input type="number" name="lm_summary_total_max" value="' . esc_attr($summaryTotalMax) . '" placeholder="Total max" />';
    echo '</div>';

    echo '<div class="lm-filter-field">';
    echo '<div class="lm-small" style="margin-bottom:6px;">Inlink min</div>';
    echo '<input type="number" name="lm_summary_in_min" value="' . esc_attr($summaryInMin) . '" placeholder="Inlink min" />';
    echo '</div>';

    echo '<div class="lm-filter-field">';
    echo '<div class="lm-small" style="margin-bottom:6px;">Inlink max</div>';
    echo '<input type="number" name="lm_summary_in_max" value="' . esc_attr($summaryInMax) . '" placeholder="Inlink max" />';
    echo '</div>';

    echo '<div class="lm-filter-field">';
    echo '<div class="lm-small" style="margin-bottom:6px;">Outbound min</div>';
    echo '<input type="number" name="lm_summary_out_min" value="' . esc_attr($summaryOutMin) . '" placeholder="Outbound min" />';
    echo '</div>';

    echo '<div class="lm-filter-field">';
    echo '<div class="lm-small" style="margin-bottom:6px;">Outbound max</div>';
    echo '<input type="number" name="lm_summary_out_max" value="' . esc_attr($summaryOutMax) . '" placeholder="Outbound max" />';
    echo '</div>';

    echo '<div class="lm-filter-field">';
    echo '<div class="lm-small" style="margin-bottom:6px;">Order By</div>';
    echo '<select name="lm_summary_orderby">';
    echo '<option value="group"' . selected($summaryOrderby, 'group', false) . '>Group</option>';
    echo '<option value="anchor"' . selected($summaryOrderby, 'anchor', false) . '>Anchor Text</option>';
    echo '<option value="total"' . selected($summaryOrderby, 'total', false) . '>Total Usage</option>';
    echo '<option value="inlink"' . selected($summaryOrderby, 'inlink', false) . '>Total Inlinks</option>';
    echo '<option value="outbound"' . selected($summaryOrderby, 'outbound', false) . '>Total Outbound</option>';
    echo '</select>';
    echo '</div>';

    echo '<div class="lm-filter-field">';
    echo '<div class="lm-small" style="margin-bottom:6px;">Sort</div>';
    echo '<select name="lm_summary_order">';
    echo '<option value="ASC"' . selected($summaryOrder, 'ASC', false) . '>ASC</option>';
    echo '<option value="DESC"' . selected($summaryOrder, 'DESC', false) . '>DESC</option>';
    echo '</select>';
    echo '</div>';

    echo '<div class="lm-filter-field">';
    echo '<div class="lm-small" style="margin-bottom:6px;">Per Page</div>';
    echo '<input type="number" name="lm_summary_per_page" value="' . esc_attr((string)$summaryPerPage) . '" min="10" max="500" />';
    echo '</div>';

    echo '<div class="lm-filter-field lm-filter-field-full">';
    echo '<div class="lm-small" style="margin:0 0 6px;">Applies to Search Group Name, Search Destination URL, Search Source URL, Search Title, Search Author, and Search Anchor Text.</div>';
    submit_button('Apply Filters', 'secondary', 'submit', false);
    echo ' <a class="button button-secondary" href="' . esc_url($summaryExportUrl) . '">Export CSV</a>';
    echo ' <a class="button" href="' . esc_url(admin_url('admin.php?page=links-manager-target')) . '">Reset</a>';
    echo '</div>';
    echo '</div>';
    echo '</form>';

    // Build the data first to get total count
    $summaryTargetsMap = $targetsMap + $groupAnchorsMap;
    $targetIndexByKey = [];
    foreach ($targets as $i => $t) {
      $k = strtolower(trim((string)$t));
      if ($k === '' || isset($targetIndexByKey[$k])) continue;
      $targetIndexByKey[$k] = $i;
    }

    $totalFiltered = 0;
    $totalPages = 1;
    $filteredRows = [];
    
    if (!empty($summaryTargetsMap)) {
      $all = $this->build_or_get_cache('any', false, $this->sanitize_wpml_lang_filter($this->get_wpml_current_language()));
      $allowedSummaryPostIds = $this->get_post_ids_by_post_terms((int)$summaryPostCategory, (int)$summaryPostTag);

      $counts = [];
      foreach ($all as $row) {
        if (is_array($allowedSummaryPostIds)) {
          $rowPostId = isset($row['post_id']) ? (string)intval($row['post_id']) : '';
          if ($rowPostId === '' || !isset($allowedSummaryPostIds[$rowPostId])) continue;
        }
        if ($summaryPostType !== 'any' && (string)($row['post_type'] ?? '') !== (string)$summaryPostType) continue;
        if ($summaryLocation !== 'any' && (string)($row['link_location'] ?? '') !== (string)$summaryLocation) continue;
        if ($summarySourceType !== 'any' && (string)($row['source'] ?? '') !== (string)$summarySourceType) continue;
        if ($summaryLinkType !== 'any' && (string)($row['link_type'] ?? '') !== (string)$summaryLinkType) continue;
        if ($summaryValueContains !== '' && !$this->text_matches((string)($row['link'] ?? ''), $summaryValueContains, $summarySearchMode)) continue;
        if ($summarySourceContains !== '' && !$this->text_matches((string)($row['page_url'] ?? ''), $summarySourceContains, $summarySearchMode)) continue;
        if ($summaryTitleContains !== '' && !$this->text_matches((string)($row['post_title'] ?? ''), $summaryTitleContains, $summarySearchMode)) continue;
        if ($summaryAuthorContains !== '' && !$this->text_matches((string)($row['post_author'] ?? ''), $summaryAuthorContains, $summarySearchMode)) continue;
        if ($summarySeoFlag !== 'any') {
          $nofollow = (string)($row['rel_nofollow'] ?? '0') === '1';
          $sponsored = (string)($row['rel_sponsored'] ?? '0') === '1';
          $ugc = (string)($row['rel_ugc'] ?? '0') === '1';
          if ($summarySeoFlag === 'dofollow' && ($nofollow || $sponsored || $ugc)) continue;
          if ($summarySeoFlag === 'nofollow' && !$nofollow) continue;
          if ($summarySeoFlag === 'sponsored' && !$sponsored) continue;
          if ($summarySeoFlag === 'ugc' && !$ugc) continue;
        }
        $a = trim((string)($row['anchor_text'] ?? ''));
        if ($a === '') continue;
        $k = strtolower($a);
        if (!isset($counts[$k])) $counts[$k] = ['total' => 0, 'inlink' => 0, 'outbound' => 0];
        $counts[$k]['total']++;
        if (($row['link_type'] ?? '') === 'inlink') $counts[$k]['inlink']++;
        if (($row['link_type'] ?? '') === 'exlink') $counts[$k]['outbound']++;
      }

      $anchorToGroups = [];
      foreach ($groups as $g) {
        $gname = (string)($g['name'] ?? '');
        $anchors = (array)($g['anchors'] ?? []);
        foreach ($anchors as $a) {
          $a = trim((string)$a);
          if ($a === '') continue;
          $key = strtolower($a);
          if (!isset($anchorToGroups[$key])) $anchorToGroups[$key] = [];
          if ($gname !== '') $anchorToGroups[$key][$gname] = true;
        }
      }
      
      foreach ($summaryTargetsMap as $tKey => $tLabel) {
        $c = $counts[$tKey] ?? ['total' => 0, 'inlink' => 0, 'outbound' => 0];
        $glist = isset($anchorToGroups[$tKey]) ? implode(', ', array_keys($anchorToGroups[$tKey])) : '—';
        $groupSearchText = isset($anchorToGroups[$tKey]) && !empty($anchorToGroups[$tKey]) ? $glist : 'No Group';
        if (!empty($summaryGroupSelected)) {
          $gset = isset($anchorToGroups[$tKey]) ? array_keys($anchorToGroups[$tKey]) : [];
          $includeByGroup = false;
          foreach ($summaryGroupSelected as $selectedGroup) {
            if ($selectedGroup === 'no_group' && empty($gset)) {
              $includeByGroup = true;
              break;
            }
            if ($selectedGroup !== 'no_group' && in_array($selectedGroup, $gset, true)) {
              $includeByGroup = true;
              break;
            }
          }
          if (!$includeByGroup) continue;
        }
        if ($summaryGroupSearch !== '' && !$this->text_matches((string)$groupSearchText, (string)$summaryGroupSearch, (string)$summarySearchMode)) continue;
        if ($summaryAnchorSearch !== '' && !$this->text_matches((string)$tLabel, (string)$summaryAnchorSearch, (string)$summarySearchMode)) continue;
        if ($summaryTotalMinNum !== null && (int)$c['total'] < $summaryTotalMinNum) continue;
        if ($summaryTotalMaxNum !== null && (int)$c['total'] > $summaryTotalMaxNum) continue;
        if ($summaryInMinNum !== null && (int)$c['inlink'] < $summaryInMinNum) continue;
        if ($summaryInMaxNum !== null && (int)$c['inlink'] > $summaryInMaxNum) continue;
        if ($summaryOutMinNum !== null && (int)$c['outbound'] < $summaryOutMinNum) continue;
        if ($summaryOutMaxNum !== null && (int)$c['outbound'] > $summaryOutMaxNum) continue;
        if (isset($anchorToGroups[$tKey]) && is_array($anchorToGroups[$tKey]) && count($anchorToGroups[$tKey]) > 0) {
          $keys = array_keys($anchorToGroups[$tKey]);
          $currentGroup = (string)$keys[0];
        } else {
          $currentGroup = 'no_group';
        }
        $idx = isset($targetIndexByKey[$tKey]) ? (int)$targetIndexByKey[$tKey] : -1;
        
        $filteredRows[] = [
          'tKey' => $tKey,
          'tLabel' => $tLabel,
          'c' => $c,
          'glist' => $glist,
          'currentGroup' => $currentGroup,
          'idx' => $idx,
        ];
      }

      usort($filteredRows, function($a, $b) use ($summaryOrderby, $summaryOrder) {
        $dir = $summaryOrder === 'ASC' ? 1 : -1;
        $cmp = 0;
        switch ($summaryOrderby) {
          case 'group':
            $cmp = strcmp((string)$a['glist'], (string)$b['glist']);
            break;
          case 'total':
            $cmp = ((int)($a['c']['total'] ?? 0) <=> (int)($b['c']['total'] ?? 0));
            break;
          case 'inlink':
            $cmp = ((int)($a['c']['inlink'] ?? 0) <=> (int)($b['c']['inlink'] ?? 0));
            break;
          case 'outbound':
            $cmp = ((int)($a['c']['outbound'] ?? 0) <=> (int)($b['c']['outbound'] ?? 0));
            break;
          case 'anchor':
          default:
            $cmp = strcmp((string)$a['tLabel'], (string)$b['tLabel']);
            break;
        }
        if ($cmp === 0) {
          $cmp = strcmp((string)$a['tLabel'], (string)$b['tLabel']);
        }
        return $cmp * $dir;
      });
      
      $totalFiltered = count($filteredRows);
      $totalPages = max(1, (int)ceil($totalFiltered / $summaryPerPage));
      if ($summaryPaged > $totalPages) $summaryPaged = $totalPages;
    }
    
    // Now render the total count and table
    echo '<div style="margin:8px 0; font-weight:bold;">Total: <span id="lm-total-filtered">' . esc_html((string)$totalFiltered) . '</span> target anchors</div>';
    echo '<div class="lm-table-wrap lm-summary-table-wrap">';
    echo '<table class="widefat striped lm-table">';
    echo '<thead><tr>';
    echo '<th class="lm-col-block"><input type="checkbox" id="lm-select-all-targets" /></th>';
    echo $this->table_header_with_tooltip('lm-col-postid', '#', 'Row number in current result page.', 'left');
    echo $this->table_header_with_tooltip('lm-col-group', 'Group', 'Current group assignment for anchor.');
    echo $this->table_header_with_tooltip('lm-col-anchor', 'Anchor Text', 'Tracked anchor text target.');
    echo $this->table_header_with_tooltip('lm-col-total', 'Total Usage Across All Pages (All Link Types)', 'Total uses of this anchor across all content.');
    echo $this->table_header_with_tooltip('lm-col-inlink', 'Total Use as Inlinks', 'Usage count as internal links.');
    echo $this->table_header_with_tooltip('lm-col-outbound', 'Total Use as Outbound', 'Usage count as outbound links.');
    echo $this->table_header_with_tooltip('lm-col-action', 'Action', 'Move group, update, or delete this target.', 'right');
    echo '</tr></thead><tbody>';
    
    if (empty($summaryTargetsMap)) {
      echo '<tr><td colspan="8">No target anchors yet.</td></tr>';
    } else {
      // Render paged rows
      $offset = ($summaryPaged - 1) * $summaryPerPage;
      $pagedRows = array_slice($filteredRows, $offset, $summaryPerPage);
      $rowNum = $offset + 1;
      
      foreach ($pagedRows as $row) {
        $tKey = $row['tKey'];
        $tLabel = $row['tLabel'];
        $c = $row['c'];
        $glist = $row['glist'];
        $currentGroup = $row['currentGroup'];
        $idx = $row['idx'];
        $editUrl = $idx >= 0 ? admin_url('admin.php?page=links-manager-target&lm_edit_target=' . $idx) : '';
        $del = $idx >= 0 ? admin_url('admin-post.php?action=lm_delete_anchor_target&' . self::NONCE_NAME . '=' . wp_create_nonce(self::NONCE_ACTION) . '&lm_target_idx=' . $idx) : '';

        echo '<tr>';
        if ($idx >= 0) {
          echo '<td class="lm-col-block" style="text-align:center;"><input type="checkbox" class="lm-target-check" name="lm_target_indices[]" value="' . esc_attr((string)$idx) . '" form="lm-bulk-delete-targets-form" /></td>';
        } else {
          echo '<td class="lm-col-block" style="text-align:center;">—</td>';
        }
        echo '<td class="lm-col-postid">' . esc_html((string)$rowNum) . '</td>';
        echo '<td class="lm-col-group"><span class="lm-trunc" title="' . esc_attr($glist) . '">' . esc_html($glist) . '</span></td>';
        echo '<td class="lm-col-anchor"><span class="lm-trunc" title="' . esc_attr($tLabel) . '">' . esc_html($tLabel) . '</span></td>';
        echo '<td class="lm-col-total">' . esc_html((string)$c['total']) . '</td>';
        echo '<td class="lm-col-inlink">' . esc_html((string)$c['inlink']) . '</td>';
        echo '<td class="lm-col-outbound">' . esc_html((string)$c['outbound']) . '</td>';
        echo '<td class="lm-col-action">';
        if ($idx >= 0) {
          echo '<a class="button button-small" href="' . esc_url($editUrl) . '">Edit</a> ';
          echo '<a class="button button-small" href="' . esc_url($del) . '">Delete</a> ';
        } else {
          echo '<span class="lm-small">—</span> ';
        }
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="lm-target-group-form" style="margin-top:6px;">';
        echo '<input type="hidden" name="action" value="lm_update_anchor_target_group"/>';
        echo '<input type="hidden" name="' . esc_attr(self::NONCE_NAME) . '" value="' . esc_attr(wp_create_nonce(self::NONCE_ACTION)) . '"/>';
        echo '<input type="hidden" name="lm_anchor_value" value="' . esc_attr($tLabel) . '"/>';
        echo '<div class="lm-form-msg"></div>';
        echo '<select name="lm_anchor_group" style="min-width:120px; font-size:11px; margin-right:6px;">';
        echo '<option value="no_group"' . selected($currentGroup, 'no_group', false) . '>No Group</option>';
        foreach ($groupNames as $gn) {
          echo '<option value="' . esc_attr($gn) . '"' . selected($currentGroup, $gn, false) . '>' . esc_html($gn) . '</option>';
        }
        echo '</select>';
        submit_button('Change Group', 'secondary', 'submit', false);
        echo '</form>';
        echo '</td>';
        echo '</tr>';
        $rowNum++;
      }
    }

    echo '</tbody></table></div>';
    
    // Render pagination
    $paginationParams = [
      'lm_summary_groups' => $summaryGroupSelected,
      'lm_summary_group_search' => $summaryGroupSearch,
      'lm_summary_post_type' => $summaryPostType,
      'lm_summary_post_category' => $summaryPostCategory,
      'lm_summary_post_tag' => $summaryPostTag,
      'lm_summary_location' => $summaryLocation,
      'lm_summary_source_type' => $summarySourceType,
      'lm_summary_link_type' => $summaryLinkType,
      'lm_summary_value' => $summaryValueContains,
      'lm_summary_source' => $summarySourceContains,
      'lm_summary_title' => $summaryTitleContains,
      'lm_summary_author' => $summaryAuthorContains,
      'lm_summary_seo_flag' => $summarySeoFlag,
      'lm_summary_anchor' => $summaryAnchor,
      'lm_summary_anchor_search' => $summaryAnchorSearch,
      'lm_summary_search_mode' => $summarySearchMode,
      'lm_summary_total_min' => $summaryTotalMin,
      'lm_summary_total_max' => $summaryTotalMax,
      'lm_summary_in_min' => $summaryInMin,
      'lm_summary_in_max' => $summaryInMax,
      'lm_summary_out_min' => $summaryOutMin,
      'lm_summary_out_max' => $summaryOutMax,
      'lm_summary_orderby' => $summaryOrderby,
      'lm_summary_order' => $summaryOrder,
      'lm_summary_per_page' => $summaryPerPage,
    ];
    $this->render_target_pagination($summaryPaged, $totalPages, $paginationParams);
    
    echo '</div>';
    echo '<script>
      (function(){
        var cards = document.querySelectorAll(".lm-card");
        cards.forEach(function(card){
          var tabs = card.querySelectorAll(".lm-tab");
          var hidden = card.querySelector("input[name=lm_anchor_mode]");
          if (!tabs.length || !hidden) return;
          tabs.forEach(function(btn){
            btn.addEventListener("click", function(){
              tabs.forEach(function(b){ b.classList.remove("is-active"); b.setAttribute("aria-selected","false"); });
              btn.classList.add("is-active");
              btn.setAttribute("aria-selected","true");
              hidden.value = btn.getAttribute("data-lm-tab") || "only";
              var hints = card.querySelectorAll("[data-lm-hint]");
              hints.forEach(function(h){
                h.style.display = (h.getAttribute("data-lm-hint") === hidden.value) ? "block" : "none";
              });
            });
          });
        });
      })();

      (function(){
        var groupAll = document.getElementById("lm-select-all-groups");
        if (groupAll) {
          groupAll.addEventListener("change", function(){
            document.querySelectorAll(".lm-group-check").forEach(function(el){ el.checked = groupAll.checked; });
          });
        }

        var targetAll = document.getElementById("lm-select-all-targets");
        if (targetAll) {
          targetAll.addEventListener("change", function(){
            document.querySelectorAll(".lm-target-check").forEach(function(el){ el.checked = targetAll.checked; });
          });
        }
      })();
    </script>';
    echo '</div>';
    echo '</div>'; // lm-grid
    echo '</div>'; // wrap
  }

  /* -----------------------------
   * CSV Export
   * ----------------------------- */

  private function csv_write_row($out, $row, $delimiter = ',', $enclosure = '"') {
    $escaped = [];
    foreach ((array)$row as $value) {
      $cell = (string)$value;
      // Convert HTML entities (e.g. &amp;) to plain text to keep CSV values stable across spreadsheet parsers.
      $cell = wp_specialchars_decode($cell, ENT_QUOTES);
      $cell = str_replace($enclosure, $enclosure . $enclosure, $cell);
      $escaped[] = $enclosure . $cell . $enclosure;
    }
    fwrite($out, implode($delimiter, $escaped) . "\r\n");
  }

  private function detect_csv_delimiter($filePath) {
    $line = '';
    $fh = @fopen($filePath, 'r');
    if ($fh) {
      while (($candidate = fgets($fh)) !== false) {
        if (trim($candidate) !== '') {
          $line = $candidate;
          break;
        }
      }
      fclose($fh);
    }

    if ($line === '') {
      return ',';
    }

    $line = preg_replace('/^\xEF\xBB\xBF/', '', (string)$line);
    $comma = substr_count($line, ',');
    $semicolon = substr_count($line, ';');

    return ($semicolon > $comma) ? ';' : ',';
  }

  public function handle_export_pages_link_csv() {
    if (!$this->current_user_can_access_plugin()) wp_die('Unauthorized');

    $nonce = isset($_GET[self::NONCE_NAME]) ? sanitize_text_field($_GET[self::NONCE_NAME]) : '';
    if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) wp_die('Invalid nonce');

    $filters = $this->get_pages_link_filters_from_request();
    $all = $this->build_or_get_cache($filters['post_type'], $filters['rebuild'], isset($filters['wpml_lang']) ? $filters['wpml_lang'] : 'all');
    $this->compact_rows_for_pages_link($all);
    $rows = $this->get_pages_with_inbound_counts($all, $filters, true);

    $filename = 'links-manager-pages-link-export-' . date('Y-m-d-His') . '.csv';

    nocache_headers();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));

    $this->csv_write_row($out, [
      'Post ID',
      'Title',
      'Post Type',
      'Author',
      'Published Date',
      'Updated Date',
      'Page URL',
      'Inbound',
      'Inbound Status',
      'Internal Outbound',
      'Internal Outbound Status',
      'External Outbound',
      'External Outbound Status',
    ]);

    foreach ($rows as $r) {
      $post_id = (int)($r['post_id'] ?? 0);
      if ($post_id <= 0) continue;

      $title = wp_specialchars_decode(wp_strip_all_tags((string)($r['post_title'] ?? '')), ENT_QUOTES);
      $author = (string)($r['author_name'] ?? '');
      $date = (string)($r['post_date'] ?? '');
      $updated = (string)($r['post_modified'] ?? '');
      $ptype = (string)($r['post_type'] ?? '');
      $url = (string)($r['page_url'] ?? '');
      if ($url === '') {
        $url = (string)get_permalink($post_id);
      }
      $status = $this->inbound_status((int)$r['inbound']);
      $internal_outbound = isset($r['internal_outbound']) ? (int)$r['internal_outbound'] : 0;
      $outbound = isset($r['outbound']) ? (int)$r['outbound'] : 0;
      $internalOutboundStatus = $this->four_level_status_label(isset($r['internal_outbound_status']) ? (string)$r['internal_outbound_status'] : 'none');
      $externalOutboundStatus = $this->four_level_status_label(isset($r['external_outbound_status']) ? (string)$r['external_outbound_status'] : 'none');

      $this->csv_write_row($out, [
        $post_id,
        $title,
        $ptype,
        $author,
        $date,
        $updated,
        $url,
        (int)$r['inbound'],
        $status,
        $internal_outbound,
        $internalOutboundStatus,
        $outbound,
        $externalOutboundStatus,
      ]);
    }

    fclose($out);
    exit;
  }

  public function handle_export_cited_domains_csv() {
    if (!$this->current_user_can_access_plugin()) wp_die('Unauthorized');

    $nonce = isset($_GET[self::NONCE_NAME]) ? sanitize_text_field($_GET[self::NONCE_NAME]) : '';
    if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) wp_die('Invalid nonce');

    $filters = $this->get_cited_domains_filters_from_request();
    $all = $this->build_or_get_cache('any', $filters['rebuild'], isset($filters['wpml_lang']) ? $filters['wpml_lang'] : 'all');
    $summaryRows = $this->build_cited_domains_summary_rows($all, $filters);

    $allowedDomains = [];
    $domainStats = [];
    foreach ($summaryRows as $r) {
      $domain = strtolower((string)($r['domain'] ?? ''));
      if ($domain === '') continue;
      $allowedDomains[$domain] = true;
      $domainStats[$domain] = [
        'cites' => (int)($r['cites'] ?? 0),
        'pages' => (int)($r['pages'] ?? 0),
      ];
    }

    $allowedPostIds = $this->get_post_ids_by_post_terms(
      isset($filters['post_category']) ? (int)$filters['post_category'] : 0,
      isset($filters['post_tag']) ? (int)$filters['post_tag'] : 0
    );
    $textMode = (string)($filters['search_mode'] ?? 'contains');

    $filename = 'links-manager-cited-domains-export-' . date('Y-m-d-His') . '.csv';

    nocache_headers();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));

    $this->csv_write_row($out, [
      'domain',
      'domain_cited_count',
      'domain_unique_source_pages',
      'destination_url',
      'source_page_url',
      'source_post_id',
      'source_post_title',
      'source_post_type',
      'source_link_location',
      'anchor_text',
      'rel_raw',
    ]);

    foreach ($all as $row) {
      if (is_array($allowedPostIds)) {
        $rowPostId = isset($row['post_id']) ? (string)intval($row['post_id']) : '';
        if ($rowPostId === '' || !isset($allowedPostIds[$rowPostId])) continue;
      }

      if (($row['link_type'] ?? '') !== 'exlink') continue;
      if (($filters['post_type'] ?? 'any') !== 'any' && (string)($row['post_type'] ?? '') !== (string)$filters['post_type']) continue;
      if (($filters['location'] ?? 'any') !== 'any' && (string)($row['link_location'] ?? '') !== (string)$filters['location']) continue;
      if (($filters['source_type'] ?? 'any') !== 'any' && (string)($row['source'] ?? '') !== (string)$filters['source_type']) continue;

      if ((string)($filters['value_contains'] ?? '') !== '' && !$this->text_matches((string)($row['link'] ?? ''), (string)$filters['value_contains'], $textMode)) continue;
      if ((string)($filters['source_contains'] ?? '') !== '' && !$this->text_matches((string)($row['page_url'] ?? ''), (string)$filters['source_contains'], $textMode)) continue;
      if ((string)($filters['title_contains'] ?? '') !== '' && !$this->text_matches((string)($row['post_title'] ?? ''), (string)$filters['title_contains'], $textMode)) continue;
      if ((string)($filters['author_contains'] ?? '') !== '' && !$this->text_matches((string)($row['post_author'] ?? ''), (string)$filters['author_contains'], $textMode)) continue;
      if ((string)($filters['anchor_contains'] ?? '') !== '' && !$this->text_matches((string)($row['anchor_text'] ?? ''), (string)$filters['anchor_contains'], $textMode)) continue;

      $seoFlag = (string)($filters['seo_flag'] ?? 'any');
      if ($seoFlag !== 'any') {
        $nofollow = (string)($row['rel_nofollow'] ?? '0') === '1';
        $sponsored = (string)($row['rel_sponsored'] ?? '0') === '1';
        $ugc = (string)($row['rel_ugc'] ?? '0') === '1';
        if ($seoFlag === 'dofollow' && ($nofollow || $sponsored || $ugc)) continue;
        if ($seoFlag === 'nofollow' && !$nofollow) continue;
        if ($seoFlag === 'sponsored' && !$sponsored) continue;
        if ($seoFlag === 'ugc' && !$ugc) continue;
      }

      $destination = $this->normalize_url((string)($row['link'] ?? ''));
      $domain = strtolower((string)parse_url($destination, PHP_URL_HOST));
      if ($domain === '' || !isset($allowedDomains[$domain])) continue;

      $stats = $domainStats[$domain] ?? ['cites' => 0, 'pages' => 0];

      $this->csv_write_row($out, [
        $domain,
        (int)$stats['cites'],
        (int)$stats['pages'],
        $destination,
        (string)($row['page_url'] ?? ''),
        (string)($row['post_id'] ?? ''),
        (string)($row['post_title'] ?? ''),
        (string)($row['post_type'] ?? ''),
        (string)($row['link_location'] ?? ''),
        (string)($row['anchor_text'] ?? ''),
        (string)($row['rel_raw'] ?? ''),
      ]);
    }

    fclose($out);
    exit;
  }

  public function handle_export_anchor_grouping_csv() {
    if (!$this->current_user_can_access_plugin()) wp_die('Unauthorized');

    $nonce = isset($_GET[self::NONCE_NAME]) ? sanitize_text_field($_GET[self::NONCE_NAME]) : '';
    if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) wp_die('Invalid nonce');

    $groupOrderby = isset($_GET['lm_group_orderby']) ? sanitize_text_field((string)$_GET['lm_group_orderby']) : 'tag';
    if (!in_array($groupOrderby, ['tag', 'total_anchors', 'total_usage', 'inlink_usage', 'outbound_usage'], true)) $groupOrderby = 'tag';
    $groupOrder = isset($_GET['lm_group_order']) ? strtoupper(sanitize_text_field((string)$_GET['lm_group_order'])) : 'ASC';
    if (!in_array($groupOrder, ['ASC', 'DESC'], true)) $groupOrder = 'ASC';

    $groups = $this->get_anchor_groups();
    $groupNames = [];
    foreach ($groups as $g) {
      $gname = trim((string)($g['name'] ?? ''));
      if ($gname !== '') $groupNames[] = $gname;
    }
    $groupNames = array_values(array_unique($groupNames));

    $groupFilterRaw = isset($_GET['lm_group_filter']) ? wp_unslash($_GET['lm_group_filter']) : [];
    if (!is_array($groupFilterRaw)) {
      $groupFilterRaw = $groupFilterRaw === '' ? [] : [$groupFilterRaw];
    }
    $groupFilterSelected = [];
    foreach ($groupFilterRaw as $item) {
      $item = trim(sanitize_text_field((string)$item));
      if ($item === '') continue;
      if ($item === 'no_group' || in_array($item, $groupNames, true)) {
        $groupFilterSelected[$item] = true;
      }
    }
    $groupFilterSelected = array_keys($groupFilterSelected);
    $groupSearch = isset($_GET['lm_group_search']) ? sanitize_text_field((string)$_GET['lm_group_search']) : '';
    $groupSearchMode = isset($_GET['lm_group_search_mode']) ? $this->sanitize_text_match_mode((string)$_GET['lm_group_search_mode']) : 'contains';

    $rows = [];
    if (!empty($groups)) {
      $targets = $this->sync_targets_with_groups($this->get_anchor_targets(), $groups);
      $targetsMap = [];
      foreach ($targets as $t) {
        $t = trim((string)$t);
        if ($t === '') continue;
        $k = strtolower($t);
        if (!isset($targetsMap[$k])) $targetsMap[$k] = $t;
      }

      $all = $this->build_or_get_cache('any', false, $this->sanitize_wpml_lang_filter($this->get_wpml_current_language()));
      $anchorUsage = [];
      foreach ($all as $row) {
        $a = trim((string)($row['anchor_text'] ?? ''));
        if ($a === '') continue;
        $k = strtolower($a);
        if (!isset($anchorUsage[$k])) $anchorUsage[$k] = ['total' => 0, 'inlink' => 0, 'outbound' => 0];
        $anchorUsage[$k]['total']++;
        if (($row['link_type'] ?? '') === 'inlink') $anchorUsage[$k]['inlink']++;
        if (($row['link_type'] ?? '') === 'exlink') $anchorUsage[$k]['outbound']++;
      }

      $groupCounts = [];
      $groupUsage = [];
      $groupedKeys = [];
      foreach ($groups as $g) {
        $gname = trim((string)($g['name'] ?? ''));
        if ($gname === '') continue;
        $anchors = isset($g['anchors']) ? (array)$g['anchors'] : [];
        $count = 0;
        $gUsage = ['total' => 0, 'inlink' => 0, 'outbound' => 0];
        foreach ($anchors as $a) {
          $a = trim((string)$a);
          if ($a === '') continue;
          $count++;
          $k = strtolower($a);
          $groupedKeys[$k] = true;
          if (isset($anchorUsage[$k])) {
            $gUsage['total'] += $anchorUsage[$k]['total'];
            $gUsage['inlink'] += $anchorUsage[$k]['inlink'];
            $gUsage['outbound'] += $anchorUsage[$k]['outbound'];
          }
        }
        $groupCounts[$gname] = $count;
        $groupUsage[$gname] = $gUsage;
      }

      if (!empty($groupCounts)) {
        $noGroupCount = 0;
        $noGroupUsage = ['total' => 0, 'inlink' => 0, 'outbound' => 0];
        foreach ($targetsMap as $k => $label) {
          if (!isset($groupedKeys[$k])) {
            $noGroupCount++;
            if (isset($anchorUsage[$k])) {
              $noGroupUsage['total'] += $anchorUsage[$k]['total'];
              $noGroupUsage['inlink'] += $anchorUsage[$k]['inlink'];
              $noGroupUsage['outbound'] += $anchorUsage[$k]['outbound'];
            }
          }
        }
        if ($noGroupCount > 0) {
          if (!isset($groupCounts['No Group'])) $groupCounts['No Group'] = 0;
          $groupCounts['No Group'] += $noGroupCount;
          if (!isset($groupUsage['No Group'])) $groupUsage['No Group'] = ['total' => 0, 'inlink' => 0, 'outbound' => 0];
          $groupUsage['No Group']['total'] += $noGroupUsage['total'];
          $groupUsage['No Group']['inlink'] += $noGroupUsage['inlink'];
          $groupUsage['No Group']['outbound'] += $noGroupUsage['outbound'];
        }

        $filteredGroupUsage = [];
        foreach ($groupUsage as $gname => $usage) {
          $usageKey = $gname === 'No Group' ? 'no_group' : $gname;
          if (!empty($groupFilterSelected) && !in_array($usageKey, $groupFilterSelected, true)) continue;
          if ($groupSearch !== '' && !$this->text_matches((string)$gname, (string)$groupSearch, (string)$groupSearchMode)) continue;
          $filteredGroupUsage[$gname] = $usage;
        }

        $totalUsage = 0;
        $totalInlinks = 0;
        $totalOutbound = 0;
        foreach ($filteredGroupUsage as $usage) {
          $totalUsage += (int)$usage['total'];
          $totalInlinks += (int)$usage['inlink'];
          $totalOutbound += (int)$usage['outbound'];
        }

        $entries = [];
        $sumBase = 0;
        $sumBaseOutbound = 0;
        $i = 0;
        foreach ($groupCounts as $gname => $count) {
          if (!isset($filteredGroupUsage[$gname])) continue;
          $usage = isset($groupUsage[$gname]) ? (int)$groupUsage[$gname]['total'] : 0;
          $usageInlink = isset($groupUsage[$gname]) ? (int)$groupUsage[$gname]['inlink'] : 0;
          $usageOutbound = isset($groupUsage[$gname]) ? (int)$groupUsage[$gname]['outbound'] : 0;
          $raw = ($totalUsage > 0) ? (($usage / $totalUsage) * 100) : 0;
          $base = (int)floor($raw);
          $frac = $raw - $base;
          $rawInlink = ($totalInlinks > 0) ? (($usageInlink / $totalInlinks) * 100) : 0;
          $rawOutbound = ($totalOutbound > 0) ? (($usageOutbound / $totalOutbound) * 100) : 0;
          $baseOutbound = (int)floor($rawOutbound);
          $fracOutbound = $rawOutbound - $baseOutbound;

          $entries[] = [
            'name' => $gname,
            'count' => (int)$count,
            'usage' => $usage,
            'usageInlink' => $usageInlink,
            'usageOutbound' => $usageOutbound,
            'base' => $base,
            'frac' => $frac,
            'rawInlink' => $rawInlink,
            'baseOutbound' => $baseOutbound,
            'fracOutbound' => $fracOutbound,
            'order' => $i,
          ];
          $sumBase += $base;
          $sumBaseOutbound += $baseOutbound;
          $i++;
        }

        $remainder = max(0, 100 - $sumBase);
        if ($totalUsage > 0 && $remainder > 0 && count($entries) > 0) {
          usort($entries, function($a, $b) {
            if ($a['frac'] === $b['frac']) return $a['order'] <=> $b['order'];
            return ($a['frac'] < $b['frac']) ? 1 : -1;
          });
          $len = count($entries);
          for ($k = 0; $k < $remainder; $k++) {
            $entries[$k % $len]['base']++;
          }
          usort($entries, function($a, $b) {
            return $a['order'] <=> $b['order'];
          });
        }

        $remainderOutbound = max(0, 100 - $sumBaseOutbound);
        if ($totalOutbound > 0 && $remainderOutbound > 0 && count($entries) > 0) {
          usort($entries, function($a, $b) {
            if ($a['fracOutbound'] === $b['fracOutbound']) return $a['order'] <=> $b['order'];
            return ($a['fracOutbound'] < $b['fracOutbound']) ? 1 : -1;
          });
          $len = count($entries);
          for ($k = 0; $k < $remainderOutbound; $k++) {
            $entries[$k % $len]['baseOutbound']++;
          }
          usort($entries, function($a, $b) {
            return $a['order'] <=> $b['order'];
          });
        }

        usort($entries, function($a, $b) use ($groupOrderby, $groupOrder) {
          $dir = $groupOrder === 'ASC' ? 1 : -1;
          $cmp = 0;
          switch ($groupOrderby) {
            case 'total_anchors':
              $cmp = ((int)$a['count'] <=> (int)$b['count']);
              break;
            case 'total_usage':
              $cmp = ((int)$a['usage'] <=> (int)$b['usage']);
              break;
            case 'inlink_usage':
              $cmp = ((int)$a['usageInlink'] <=> (int)$b['usageInlink']);
              break;
            case 'outbound_usage':
              $cmp = ((int)$a['usageOutbound'] <=> (int)$b['usageOutbound']);
              break;
            case 'tag':
            default:
              $cmp = strcmp((string)$a['name'], (string)$b['name']);
              break;
          }
          if ($cmp === 0) $cmp = strcmp((string)$a['name'], (string)$b['name']);
          return $cmp * $dir;
        });

        foreach ($entries as $e) {
          $rows[] = [
            'group' => (string)$e['name'],
            'total_anchors' => (int)$e['count'],
            'total_usage' => (int)$e['usage'],
            'total_usage_pct' => number_format((float)$e['base'], 1),
            'total_inlinks' => (int)$e['usageInlink'],
            'total_inlinks_pct' => number_format((float)$e['rawInlink'], 1),
            'total_outbound' => (int)$e['usageOutbound'],
            'total_outbound_pct' => number_format((float)$e['baseOutbound'], 1),
          ];
        }
      }
    }

    $filename = 'links-manager-anchor-grouping-export-' . date('Y-m-d-His') . '.csv';
    nocache_headers();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));

    $this->csv_write_row($out, [
      'group',
      'total_anchors',
      'total_usage',
      'total_usage_pct',
      'total_inlinks',
      'total_inlinks_pct',
      'total_outbound',
      'total_outbound_pct',
    ]);

    foreach ($rows as $row) {
      $this->csv_write_row($out, [
        $row['group'],
        $row['total_anchors'],
        $row['total_usage'],
        $row['total_usage_pct'],
        $row['total_inlinks'],
        $row['total_inlinks_pct'],
        $row['total_outbound'],
        $row['total_outbound_pct'],
      ]);
    }

    fclose($out);
    exit;
  }

  public function handle_export_links_target_csv() {
    if (!$this->current_user_can_access_plugin()) wp_die('Unauthorized');

    $nonce = isset($_GET[self::NONCE_NAME]) ? sanitize_text_field($_GET[self::NONCE_NAME]) : '';
    if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) wp_die('Invalid nonce');

    $groups = $this->get_anchor_groups();
    $targets = $this->sync_targets_with_groups($this->get_anchor_targets(), $groups);

    $groupNames = [];
    foreach ($groups as $g) {
      $gname = trim((string)($g['name'] ?? ''));
      if ($gname !== '') $groupNames[] = $gname;
    }
    $groupNames = array_values(array_unique($groupNames));

    $summaryGroupsRaw = isset($_GET['lm_summary_groups']) ? wp_unslash($_GET['lm_summary_groups']) : [];
    if (!is_array($summaryGroupsRaw)) {
      $summaryGroupsRaw = $summaryGroupsRaw === '' ? [] : [$summaryGroupsRaw];
    }
    // Backward compatibility for old single-select query parameter.
    if (empty($summaryGroupsRaw) && isset($_GET['lm_summary_group'])) {
      $legacySummaryGroup = trim(sanitize_text_field((string)$_GET['lm_summary_group']));
      if ($legacySummaryGroup !== '') $summaryGroupsRaw[] = $legacySummaryGroup;
    }
    $summaryGroupSelected = [];
    foreach ($summaryGroupsRaw as $item) {
      $item = trim(sanitize_text_field((string)$item));
      if ($item === '') continue;
      if ($item === 'no_group' || in_array($item, $groupNames, true)) {
        $summaryGroupSelected[$item] = true;
      }
    }
    $summaryGroupSelected = array_keys($summaryGroupSelected);
    $summaryGroupSearch = isset($_GET['lm_summary_group_search']) ? sanitize_text_field((string)$_GET['lm_summary_group_search']) : '';
    $summaryAnchor = isset($_GET['lm_summary_anchor']) ? sanitize_text_field((string)$_GET['lm_summary_anchor']) : '';
    $summaryAnchorSearch = isset($_GET['lm_summary_anchor_search']) ? sanitize_text_field((string)$_GET['lm_summary_anchor_search']) : '';
    $summarySearchMode = isset($_GET['lm_summary_search_mode']) ? $this->sanitize_text_match_mode((string)$_GET['lm_summary_search_mode']) : 'contains';
    if ($summaryAnchorSearch === '' && $summaryAnchor !== '') {
      $summaryAnchorSearch = $summaryAnchor;
      $summarySearchMode = 'exact';
    }
    $summaryPostTypeOptions = $this->get_filterable_post_types();
    $summaryPostType = isset($_GET['lm_summary_post_type']) ? sanitize_key((string)$_GET['lm_summary_post_type']) : 'any';
    if ($summaryPostType !== 'any' && !isset($summaryPostTypeOptions[$summaryPostType])) $summaryPostType = 'any';
    $summaryPostCategory = isset($_GET['lm_summary_post_category']) ? $this->sanitize_post_term_filter($_GET['lm_summary_post_category'], 'category') : 0;
    $summaryPostTag = isset($_GET['lm_summary_post_tag']) ? $this->sanitize_post_term_filter($_GET['lm_summary_post_tag'], 'post_tag') : 0;
    if ($summaryPostType !== 'any' && $summaryPostType !== 'post') {
      $summaryPostCategory = 0;
      $summaryPostTag = 0;
    }
    $summaryLocation = isset($_GET['lm_summary_location']) ? sanitize_text_field((string)$_GET['lm_summary_location']) : 'any';
    if ($summaryLocation === '') $summaryLocation = 'any';
    $summarySourceType = isset($_GET['lm_summary_source_type'])
      ? $this->sanitize_source_type_filter($_GET['lm_summary_source_type'])
      : 'any';
    $summaryLinkType = isset($_GET['lm_summary_link_type']) ? sanitize_text_field((string)$_GET['lm_summary_link_type']) : 'any';
    if (!in_array($summaryLinkType, ['any','inlink','exlink'], true)) $summaryLinkType = 'any';
    $summaryValueContains = isset($_GET['lm_summary_value']) ? sanitize_text_field((string)$_GET['lm_summary_value']) : '';
    $summarySourceContains = isset($_GET['lm_summary_source']) ? sanitize_text_field((string)$_GET['lm_summary_source']) : '';
    $summaryTitleContains = isset($_GET['lm_summary_title']) ? sanitize_text_field((string)$_GET['lm_summary_title']) : '';
    $summaryAuthorContains = isset($_GET['lm_summary_author']) ? sanitize_text_field((string)$_GET['lm_summary_author']) : '';
    $summarySeoFlag = isset($_GET['lm_summary_seo_flag']) ? sanitize_text_field((string)$_GET['lm_summary_seo_flag']) : 'any';
    if (!in_array($summarySeoFlag, ['any','dofollow','nofollow','sponsored','ugc'], true)) $summarySeoFlag = 'any';
    $summaryTotalMin = isset($_GET['lm_summary_total_min']) ? (string)$_GET['lm_summary_total_min'] : '';
    $summaryTotalMax = isset($_GET['lm_summary_total_max']) ? (string)$_GET['lm_summary_total_max'] : '';
    $summaryInMin = isset($_GET['lm_summary_in_min']) ? (string)$_GET['lm_summary_in_min'] : '';
    $summaryInMax = isset($_GET['lm_summary_in_max']) ? (string)$_GET['lm_summary_in_max'] : '';
    $summaryOutMin = isset($_GET['lm_summary_out_min']) ? (string)$_GET['lm_summary_out_min'] : '';
    $summaryOutMax = isset($_GET['lm_summary_out_max']) ? (string)$_GET['lm_summary_out_max'] : '';
    $summaryOrderby = isset($_GET['lm_summary_orderby']) ? sanitize_text_field((string)$_GET['lm_summary_orderby']) : 'anchor';
    if (!in_array($summaryOrderby, ['group', 'anchor', 'total', 'inlink', 'outbound'], true)) $summaryOrderby = 'anchor';
    $summaryOrder = isset($_GET['lm_summary_order']) ? strtoupper(sanitize_text_field((string)$_GET['lm_summary_order'])) : 'ASC';
    if (!in_array($summaryOrder, ['ASC', 'DESC'], true)) $summaryOrder = 'ASC';

    $summaryTotalMinNum = $summaryTotalMin === '' ? null : intval($summaryTotalMin);
    $summaryTotalMaxNum = $summaryTotalMax === '' ? null : intval($summaryTotalMax);
    $summaryInMinNum = $summaryInMin === '' ? null : intval($summaryInMin);
    $summaryInMaxNum = $summaryInMax === '' ? null : intval($summaryInMax);
    $summaryOutMinNum = $summaryOutMin === '' ? null : intval($summaryOutMin);
    $summaryOutMaxNum = $summaryOutMax === '' ? null : intval($summaryOutMax);

    $targetsMap = [];
    foreach ($targets as $t) {
      $t = trim((string)$t);
      if ($t === '') continue;
      $k = strtolower($t);
      if (!isset($targetsMap[$k])) $targetsMap[$k] = $t;
    }

    $groupAnchorsMap = [];
    $anchorToGroups = [];
    foreach ($groups as $g) {
      $gname = (string)($g['name'] ?? '');
      $anchors = (array)($g['anchors'] ?? []);
      foreach ($anchors as $a) {
        $a = trim((string)$a);
        if ($a === '') continue;
        $k = strtolower($a);
        if (!isset($groupAnchorsMap[$k])) $groupAnchorsMap[$k] = $a;
        if (!isset($anchorToGroups[$k])) $anchorToGroups[$k] = [];
        if ($gname !== '') $anchorToGroups[$k][$gname] = true;
      }
    }

    $summaryTargetsMap = $targetsMap + $groupAnchorsMap;
    $all = $this->build_or_get_cache('any', false, $this->sanitize_wpml_lang_filter($this->get_wpml_current_language()));
    $allowedSummaryPostIds = $this->get_post_ids_by_post_terms((int)$summaryPostCategory, (int)$summaryPostTag);

    $counts = [];
    foreach ($all as $row) {
      if (is_array($allowedSummaryPostIds)) {
        $rowPostId = isset($row['post_id']) ? (string)intval($row['post_id']) : '';
        if ($rowPostId === '' || !isset($allowedSummaryPostIds[$rowPostId])) continue;
      }
      if ($summaryPostType !== 'any' && (string)($row['post_type'] ?? '') !== (string)$summaryPostType) continue;
      if ($summaryLocation !== 'any' && (string)($row['link_location'] ?? '') !== (string)$summaryLocation) continue;
      if ($summarySourceType !== 'any' && (string)($row['source'] ?? '') !== (string)$summarySourceType) continue;
      if ($summaryLinkType !== 'any' && (string)($row['link_type'] ?? '') !== (string)$summaryLinkType) continue;
      if ($summaryValueContains !== '' && !$this->text_matches((string)($row['link'] ?? ''), $summaryValueContains, $summarySearchMode)) continue;
      if ($summarySourceContains !== '' && !$this->text_matches((string)($row['page_url'] ?? ''), $summarySourceContains, $summarySearchMode)) continue;
      if ($summaryTitleContains !== '' && !$this->text_matches((string)($row['post_title'] ?? ''), $summaryTitleContains, $summarySearchMode)) continue;
      if ($summaryAuthorContains !== '' && !$this->text_matches((string)($row['post_author'] ?? ''), $summaryAuthorContains, $summarySearchMode)) continue;
      if ($summarySeoFlag !== 'any') {
        $nofollow = (string)($row['rel_nofollow'] ?? '0') === '1';
        $sponsored = (string)($row['rel_sponsored'] ?? '0') === '1';
        $ugc = (string)($row['rel_ugc'] ?? '0') === '1';
        if ($summarySeoFlag === 'dofollow' && ($nofollow || $sponsored || $ugc)) continue;
        if ($summarySeoFlag === 'nofollow' && !$nofollow) continue;
        if ($summarySeoFlag === 'sponsored' && !$sponsored) continue;
        if ($summarySeoFlag === 'ugc' && !$ugc) continue;
      }
      $a = trim((string)($row['anchor_text'] ?? ''));
      if ($a === '') continue;
      $k = strtolower($a);
      if (!isset($counts[$k])) $counts[$k] = ['total' => 0, 'inlink' => 0, 'outbound' => 0];
      $counts[$k]['total']++;
      if (($row['link_type'] ?? '') === 'inlink') $counts[$k]['inlink']++;
      if (($row['link_type'] ?? '') === 'exlink') $counts[$k]['outbound']++;
    }

    $rows = [];
    foreach ($summaryTargetsMap as $tKey => $tLabel) {
      $c = $counts[$tKey] ?? ['total' => 0, 'inlink' => 0, 'outbound' => 0];
      $glist = isset($anchorToGroups[$tKey]) ? implode(', ', array_keys($anchorToGroups[$tKey])) : '—';
      $groupSearchText = isset($anchorToGroups[$tKey]) && !empty($anchorToGroups[$tKey]) ? $glist : 'No Group';

      if (!empty($summaryGroupSelected)) {
        $gset = isset($anchorToGroups[$tKey]) ? array_keys($anchorToGroups[$tKey]) : [];
        $includeByGroup = false;
        foreach ($summaryGroupSelected as $selectedGroup) {
          if ($selectedGroup === 'no_group' && empty($gset)) {
            $includeByGroup = true;
            break;
          }
          if ($selectedGroup !== 'no_group' && in_array($selectedGroup, $gset, true)) {
            $includeByGroup = true;
            break;
          }
        }
        if (!$includeByGroup) continue;
      }
      if ($summaryGroupSearch !== '' && !$this->text_matches((string)$groupSearchText, (string)$summaryGroupSearch, (string)$summarySearchMode)) continue;
      if ($summaryAnchorSearch !== '' && !$this->text_matches((string)$tLabel, (string)$summaryAnchorSearch, (string)$summarySearchMode)) continue;
      if ($summaryTotalMinNum !== null && (int)$c['total'] < $summaryTotalMinNum) continue;
      if ($summaryTotalMaxNum !== null && (int)$c['total'] > $summaryTotalMaxNum) continue;
      if ($summaryInMinNum !== null && (int)$c['inlink'] < $summaryInMinNum) continue;
      if ($summaryInMaxNum !== null && (int)$c['inlink'] > $summaryInMaxNum) continue;
      if ($summaryOutMinNum !== null && (int)$c['outbound'] < $summaryOutMinNum) continue;
      if ($summaryOutMaxNum !== null && (int)$c['outbound'] > $summaryOutMaxNum) continue;

      $rows[] = [
        'group' => $glist,
        'anchor_text' => (string)$tLabel,
        'total_usage' => (int)$c['total'],
        'inlink_usage' => (int)$c['inlink'],
        'outbound_usage' => (int)$c['outbound'],
      ];
    }

    usort($rows, function($a, $b) use ($summaryOrderby, $summaryOrder) {
      $dir = $summaryOrder === 'ASC' ? 1 : -1;
      $cmp = 0;
      switch ($summaryOrderby) {
        case 'group':
          $cmp = strcmp((string)$a['group'], (string)$b['group']);
          break;
        case 'total':
          $cmp = ((int)$a['total_usage'] <=> (int)$b['total_usage']);
          break;
        case 'inlink':
          $cmp = ((int)$a['inlink_usage'] <=> (int)$b['inlink_usage']);
          break;
        case 'outbound':
          $cmp = ((int)$a['outbound_usage'] <=> (int)$b['outbound_usage']);
          break;
        case 'anchor':
        default:
          $cmp = strcmp((string)$a['anchor_text'], (string)$b['anchor_text']);
          break;
      }
      if ($cmp === 0) {
        $cmp = strcmp((string)$a['anchor_text'], (string)$b['anchor_text']);
      }
      return $cmp * $dir;
    });

    $filename = 'links-manager-target-summary-export-' . date('Y-m-d-His') . '.csv';

    nocache_headers();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));

    $this->csv_write_row($out, [
      'group',
      'anchor_text',
      'total_usage',
      'inlink_usage',
      'outbound_usage',
    ]);

    foreach ($rows as $row) {
      $this->csv_write_row($out, [
        $row['group'],
        $row['anchor_text'],
        $row['total_usage'],
        $row['inlink_usage'],
        $row['outbound_usage'],
      ]);
    }

    fclose($out);
    exit;
  }

  public function handle_export_all_anchor_text_csv() {
    if (!$this->current_user_can_access_plugin()) wp_die('Unauthorized');

    $nonce = isset($_GET[self::NONCE_NAME]) ? sanitize_text_field($_GET[self::NONCE_NAME]) : '';
    if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) wp_die('Invalid nonce');

    $filters = $this->get_all_anchor_text_filters_from_request();
    $all = $this->build_or_get_cache('any', $filters['rebuild'], isset($filters['wpml_lang']) ? $filters['wpml_lang'] : 'all');
    $rows = $this->build_all_anchor_text_rows($all, $filters);

    $filename = 'links-manager-all-anchor-text-export-' . date('Y-m-d-His') . '.csv';

    nocache_headers();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));

    $this->csv_write_row($out, [
      'anchor_text',
      'quality',
      'usage_type',
      'total_usage',
      'inlink_usage',
      'outbound_usage',
      'unique_source_pages',
      'unique_destination_urls',
      'source_types',
    ]);

    foreach ($rows as $row) {
      $this->csv_write_row($out, [
        (string)$row['anchor_text'],
        (string)$row['quality'],
        (string)$row['usage_type'],
        (int)$row['total'],
        (int)$row['inlink'],
        (int)$row['outbound'],
        (int)$row['source_pages'],
        (int)$row['destinations'],
        (string)$row['source_types'],
      ]);
    }

    fclose($out);
    exit;
  }

  public function handle_export_csv() {
    if (!$this->current_user_can_access_plugin()) wp_die('Unauthorized');

    $nonce = isset($_GET[self::NONCE_NAME]) ? sanitize_text_field($_GET[self::NONCE_NAME]) : '';
    if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) wp_die('Invalid nonce');

    $filters = $this->get_filters_from_request();
    $all = $this->build_or_get_cache($filters['post_type'], $filters['rebuild'], isset($filters['wpml_lang']) ? $filters['wpml_lang'] : 'all');
    $rows = $this->apply_filters_and_group($all, $filters);

    $filename = 'links-manager-export-' . date('Y-m-d-His') . '.csv';

    nocache_headers();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));

    // Include fields needed for bulk precise update
    $this->csv_write_row($out, [
      'post_id','old_link','row_id','new_link','new_rel','new_anchor', // convenience headers for bulk
      'source','link_location','block_index','occurrence',
      'post_title','post_type','post_author','post_date','post_modified',
      'page_url','link_resolved','link_raw','anchor_text','alt_text','snippet',
      'link_type','relationship','value_type','count'
    ]);

    foreach ($rows as $r) {
      $this->csv_write_row($out, [
        $r['post_id'],
        $r['link'],
        $r['row_id'],
        '', // new_link (fill later)
        '', // new_rel (fill later)
        '', // new_anchor (fill later)

        $r['source'],
        $r['link_location'],
        $r['block_index'],
        $r['occurrence'] ?? '',

        $r['post_title'],
        $r['post_type'],
        $r['post_author'],
        $r['post_date'],
        $r['post_modified'],

        $r['page_url'],
        $r['link'],
        $r['link_raw'],
        $r['anchor_text'],
        $r['alt_text'],
        $r['snippet'],

        $r['link_type'],
        $r['relationship'],
        $r['value_type'],
        isset($r['count']) ? $r['count'] : 1,
      ]);
    }

    fclose($out);
    exit;
  }
}

new LM_Links_Manager();