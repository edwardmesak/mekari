<?php
/**
 * Plugin Name: Links Manager
 * Description: Manage and analyze all links across your WordPress site with precision. Edit link URLs, anchor texts, and relationship attributes in a user-friendly interface. Identify orphan pages and export link data for SEO audits.
 * Requires at least: 5.0
 * Requires PHP: 7.2
 * Version: 4.4.4
 * Author: Edward Mesak Dua Padang
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: links-manager
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) {
  exit;
}

require_once __DIR__ . '/includes/trait-lm-plugin-bootstrap.php';
require_once __DIR__ . '/includes/trait-lm-schema.php';
require_once __DIR__ . '/includes/trait-lm-settings-admin.php';
require_once __DIR__ . '/includes/trait-lm-statistics-admin.php';
require_once __DIR__ . '/includes/trait-lm-cited-domains-admin.php';
require_once __DIR__ . '/includes/trait-lm-all-anchor-text-admin.php';
require_once __DIR__ . '/includes/trait-lm-pages-link-admin.php';
require_once __DIR__ . '/includes/trait-lm-editor-admin.php';
require_once __DIR__ . '/includes/trait-lm-links-target-admin.php';
require_once __DIR__ . '/includes/trait-lm-filter-helpers.php';
require_once __DIR__ . '/includes/trait-lm-wpml-scan-helpers.php';
require_once __DIR__ . '/includes/trait-lm-scan-config-helpers.php';
require_once __DIR__ . '/includes/trait-lm-export-handlers.php';
require_once __DIR__ . '/includes/trait-lm-action-handlers.php';
require_once __DIR__ . '/includes/trait-lm-link-update-helpers.php';
require_once __DIR__ . '/includes/trait-lm-anchor-helpers.php';
require_once __DIR__ . '/includes/trait-lm-request-url-helpers.php';
require_once __DIR__ . '/includes/trait-lm-pagination-helpers.php';
require_once __DIR__ . '/includes/trait-lm-anchor-quality-helpers.php';
require_once __DIR__ . '/includes/trait-lm-audit-stats.php';
require_once __DIR__ . '/includes/trait-lm-summary-builders.php';
require_once __DIR__ . '/includes/trait-lm-pages-link-analytics.php';
require_once __DIR__ . '/includes/trait-lm-indexed-aggregation.php';
require_once __DIR__ . '/includes/trait-lm-indexed-datastore.php';
require_once __DIR__ . '/includes/trait-lm-rest-api.php';
require_once __DIR__ . '/includes/trait-lm-cache-rebuild.php';
require_once __DIR__ . '/includes/trait-lm-crawl-parse.php';
require_once __DIR__ . '/includes/trait-lm-diagnostics.php';
require_once __DIR__ . '/includes/trait-lm-settings-access.php';
require_once __DIR__ . '/includes/trait-lm-housekeeping.php';
require_once __DIR__ . '/includes/trait-lm-admin-feedback.php';
require_once __DIR__ . '/includes/trait-lm-admin-ui.php';
require_once __DIR__ . '/includes/trait-lm-runtime-rebuild-helpers.php';
require_once __DIR__ . '/includes/trait-lm-dashboard-stats.php';
require_once __DIR__ . '/includes/trait-lm-cache-index-sync.php';
require_once __DIR__ . '/includes/trait-lm-core-utils.php';

class LM_Links_Manager {
  use LM_Plugin_Bootstrap_Trait;
  use LM_Schema_Trait;
  use LM_Settings_Admin_Trait;
  use LM_Statistics_Admin_Trait;
  use LM_Cited_Domains_Admin_Trait;
  use LM_All_Anchor_Text_Admin_Trait;
  use LM_Pages_Link_Admin_Trait;
  use LM_Editor_Admin_Trait;
  use LM_Links_Target_Admin_Trait;
  use LM_Filter_Helpers_Trait;
  use LM_WPML_Scan_Helpers_Trait;
  use LM_Scan_Config_Helpers_Trait;
  use LM_Export_Handlers_Trait;
  use LM_Action_Handlers_Trait;
  use LM_Link_Update_Helpers_Trait;
  use LM_Anchor_Helpers_Trait;
  use LM_Request_URL_Helpers_Trait;
  use LM_Pagination_Helpers_Trait;
  use LM_Anchor_Quality_Helpers_Trait;
  use LM_Audit_Stats_Trait;
  use LM_Summary_Builders_Trait;
  use LM_Pages_Link_Analytics_Trait;
  use LM_Indexed_Aggregation_Trait;
  use LM_Indexed_Datastore_Trait;
  use LM_REST_API_Trait;
  use LM_Cache_Rebuild_Trait;
  use LM_Crawl_Parse_Trait;
  use LM_Diagnostics_Trait;
  use LM_Settings_Access_Trait;
  use LM_Housekeeping_Trait;
  use LM_Admin_Feedback_Trait;
  use LM_Admin_UI_Trait;
  use LM_Runtime_Rebuild_Helpers_Trait;
  use LM_Dashboard_Stats_Trait;
  use LM_Cache_Index_Sync_Trait;
  use LM_Core_Utils_Trait;

  const PAGE_SLUG = 'links-manager-editor';
  const NONCE_ACTION = 'lm_links_manager_nonce_action';
  const NONCE_NAME = 'lm_nonce';
  const DB_VERSION = '5.0';

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
  private $runtime_profile_request_started_at = null;
  private $rest_response_runtime_cache = [];
  private $rest_response_cache_hits = 0;
  private $rest_response_cache_misses = 0;

  public function __construct($register_hooks = true) {
    if (!$register_hooks) {
      return;
    }

    add_action('plugins_loaded', [$this, 'load_textdomain']);
    add_action('plugins_loaded', [$this, 'maybe_upgrade_schema'], 20);
    add_action('admin_menu', [$this, 'admin_menu']);
    add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);

    add_action('admin_post_lm_export_csv', [$this, 'handle_export_csv']);
    add_action('admin_post_lm_export_pages_link_csv', [$this, 'handle_export_pages_link_csv']);
    add_action('admin_post_lm_export_cited_domains_csv', [$this, 'handle_export_cited_domains_csv']);
    add_action('admin_post_lm_export_links_target_csv', [$this, 'handle_export_links_target_csv']);
    add_action('admin_post_lm_export_all_anchor_text_csv', [$this, 'handle_export_all_anchor_text_csv']);
    add_action('admin_post_lm_export_anchor_grouping_csv', [$this, 'handle_export_anchor_grouping_csv']);
    add_action('admin_post_lm_download_bulk_update_template', [$this, 'handle_download_bulk_update_template']);
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
    add_action('admin_post_lm_bulk_update_anchor_target_group', [$this, 'handle_bulk_update_anchor_target_group']);
    add_action('wp_ajax_lm_update_anchor_target_group_ajax', [$this, 'handle_update_anchor_target_group_ajax']);
    add_action('admin_post_lm_delete_anchor_target', [$this, 'handle_delete_anchor_target']);
    add_action('admin_post_lm_bulk_delete_anchor_targets', [$this, 'handle_bulk_delete_anchor_targets']);
    add_action('rest_api_init', [$this, 'register_rest_routes']);

    add_action('init', [$this, 'ensure_scheduled_cache_rebuild']);
    add_action('admin_init', [$this, 'run_daily_maintenance']);
    add_action('shutdown', [$this, 'capture_fatal_diagnostic']);
    add_action('shutdown', [$this, 'persist_runtime_profile']);
    add_action('lm_background_rebuild_cache', [$this, 'run_background_rebuild_cache'], 10, 2);
    add_action('lm_scheduled_cache_rebuild', [$this, 'run_scheduled_cache_rebuild']);
    add_action('lm_prewarm_rest_list_cache', [$this, 'run_rest_list_prewarm'], 10, 2);
    add_action('save_post', [$this, 'handle_post_change_schedule_incremental_refresh'], 20, 3);
    add_action('before_delete_post', [$this, 'handle_deleted_post_schedule_incremental_refresh'], 20, 2);
  }

  
  public function admin_menu() {
    if (!$this->current_user_can_access_plugin()) {
      return;
    }

    // Top-level menu with hover submenus
    add_menu_page(
      __('Links Manager', 'links-manager'),
      __('Links Manager', 'links-manager'),
      'read',
      self::PAGE_SLUG,
      [$this, 'render_admin_editor_page'],
      'dashicons-admin-links',
      80
    );

    add_submenu_page(
      self::PAGE_SLUG,
      __('Statistics', 'links-manager'),
      __('Statistics', 'links-manager'),
      'read',
      'links-manager-stats',
      [$this, 'render_admin_stats_page']
    );

    add_submenu_page(
      self::PAGE_SLUG,
      __('Links Editor', 'links-manager'),
      __('Links Editor', 'links-manager'),
      'read',
      self::PAGE_SLUG,
      [$this, 'render_admin_editor_page']
    );

    add_submenu_page(
      self::PAGE_SLUG,
      __('Pages Link', 'links-manager'),
      __('Pages Link', 'links-manager'),
      'read',
      'links-manager-pages-link',
      [$this, 'render_admin_pages_link_page']
    );

    add_submenu_page(
      self::PAGE_SLUG,
      __('Links Target', 'links-manager'),
      __('Links Target', 'links-manager'),
      'read',
      'links-manager-target',
      [$this, 'render_admin_links_target_page']
    );

    add_submenu_page(
      self::PAGE_SLUG,
      __('Cited External Domains', 'links-manager'),
      __('Cited Domains', 'links-manager'),
      'read',
      'links-manager-cited-domains',
      [$this, 'render_admin_cited_domains_page']
    );

    add_submenu_page(
      self::PAGE_SLUG,
      __('All Anchor Text', 'links-manager'),
      __('All Anchor Text', 'links-manager'),
      'read',
      'links-manager-all-anchor-text',
      [$this, 'render_admin_all_anchor_text_page']
    );

    add_submenu_page(
      self::PAGE_SLUG,
      __('Settings', 'links-manager'),
      __('Settings', 'links-manager'),
      'manage_options',
      'links-manager-settings',
      [$this, 'render_admin_settings_page']
    );

    // Hide the auto-added top-level submenu entry
    remove_submenu_page(self::PAGE_SLUG, self::PAGE_SLUG);
  }

  public function enqueue_admin_assets($hook) {
    $currentPage = isset($_GET['page']) ? sanitize_key((string)$_GET['page']) : '';
    $allowed = [
      'toplevel_page_' . self::PAGE_SLUG,
      'toplevel_page_links-manager',
      'links-manager-editor_page_links-manager-stats',
      'links-manager-editor_page_links-manager-pages-link',
      'links-manager-editor_page_links-manager-target',
      'links-manager-editor_page_links-manager-cited-domains',
      'links-manager-editor_page_links-manager-all-anchor-text',
      'links-manager-editor_page_links-manager-settings',
      'links-manager_page_links-manager-stats',
      'links-manager_page_links-manager-pages-link',
      'links-manager_page_links-manager-target',
      'links-manager_page_links-manager-cited-domains',
      'links-manager_page_links-manager-all-anchor-text',
      'links-manager_page_links-manager-settings',
    ];
    $allowedPages = [
      self::PAGE_SLUG,
      'links-manager',
      'links-manager-stats',
      'links-manager-pages-link',
      'links-manager-target',
      'links-manager-cited-domains',
      'links-manager-all-anchor-text',
      'links-manager-settings',
    ];
    if (!in_array($hook, $allowed, true) && !in_array($currentPage, $allowedPages, true)) return;

    $css = "
    .lm-wrap{max-width:100%; padding:8px 12px 96px 0; --lm-table-sticky-top:32px;}
    .lm-page-hero{
      display:flex;
      align-items:flex-start;
      justify-content:space-between;
      gap:16px;
      margin:0 0 18px;
      padding:20px 22px;
      border:1px solid #d8e1ea;
      border-radius:18px;
      background:
        radial-gradient(circle at top right, rgba(34,113,177,.18), transparent 34%),
        linear-gradient(135deg, #ffffff 0%, #f4f8fb 100%);
      box-shadow:0 10px 30px rgba(15, 23, 42, .05);
    }
    .lm-page-hero__content{min-width:0; max-width:920px;}
    .lm-page-hero__content > * + *{margin-top:10px;}
    .lm-page-title{font-size:28px; line-height:1.2; font-weight:700; letter-spacing:-.02em; margin:0;}
    .lm-page-subtitle{margin:8px 0 0; color:#52606d; font-size:13px; line-height:1.6; max-width:760px;}
    .lm-subtle{color:#6b7280; font-size:12px;}
    .lm-section-intro{display:flex; flex-direction:column; gap:4px; margin:0 0 16px;}
    .lm-section-title{margin:0; font-size:18px; line-height:1.3; font-weight:650; color:#0f172a;}
    .lm-section-description{margin:0; color:#5b6776; font-size:13px; line-height:1.55;}
    .lm-table-wrap{
      position:relative;
      overflow:auto;
      max-height:calc(100vh - 180px);
      width:100%;
      border:1px solid #d9e1e8;
      background:#fff;
      border-radius:14px;
      box-shadow:0 8px 24px rgba(15, 23, 42, .04);
    }
    .lm-summary-table-wrap{
      width:100%;
      max-width:none;
      max-height:none;
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
      border-collapse:separate;
      border-spacing:0;
      font-size:12px;
    }
    .lm-wrap .widefat thead th,
    .lm-table th{
      position:sticky;
      top:var(--lm-table-sticky-top);
      z-index:5;
      background:#f8fafc;
      background-clip:padding-box;
      padding:10px 12px;
      text-align:left;
      border:1px solid #e6edf3;
      font-weight:600;
      white-space:normal;
      word-break:break-word;
      box-shadow:inset 0 -1px 0 #e6edf3;
    }
    .lm-table-wrap .widefat thead th,
    .lm-table-wrap .lm-table th{
      top:0;
    }
    .lm-table td{
      padding:10px 12px;
      border:1px solid #eef2f6;
      vertical-align:top;
      word-break:break-word;
    }
    .lm-table tbody tr:hover{background:#f8fbff;}
    .lm-table td:first-child, .lm-table th:first-child{border-left:3px solid #2271b1;}
    
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
    .lm-small{color:#646970; font-size:11px; line-height:1.5;}
    .lm-settings-actions{display:grid; grid-template-columns:minmax(220px, 280px) repeat(auto-fit, minmax(300px, 1fr)); gap:12px; align-items:stretch;}
    .lm-settings-actions-primary,
    .lm-settings-actions-card{padding:14px; border:1px solid #dde5ec; border-radius:14px; background:#fff; box-shadow:0 8px 24px rgba(15,23,42,.04);}
    .lm-settings-actions-primary .submit,
    .lm-settings-actions-card .submit{margin:0 8px 8px 0; display:inline-block;}
    .lm-settings-actions-primary .button,
    .lm-settings-actions-card .button{min-height:32px;}
    .lm-settings-actions-title{font-weight:600; margin-bottom:6px;}
    .lm-settings-actions-note{margin-bottom:8px;}
    .lm-settings-actions-subtitle{margin:8px 0 6px;}
    .lm-danger-zone{margin:10px 0 0; padding-top:10px; border-top:1px solid #dcdcde;}
    .lm-danger-text{color:#b32d2e; margin-bottom:6px;}
    .lm-help-tip{margin-top:4px;}
    .lm-chip{display:inline-block; padding:2px 6px; border:1px solid #dcdcde; border-radius:3px; font-size:11px;}
    .lm-chip.bad{border-color:#d63638; color:#d63638; background:#fff5f5;}
    .lm-chip.ok{border-color:#00a32a; color:#00a32a; background:#f0f7f0;}
    .lm-settings-two-col{display:grid; grid-template-columns:repeat(2, minmax(280px, 1fr)); gap:12px 14px; align-items:start; margin:0 0 10px;}
    .lm-settings-two-col > p{margin:0 !important; padding:14px; border:1px solid #e3e8ee; border-radius:12px; background:linear-gradient(180deg, #ffffff 0%, #fbfdff 100%);}
    .lm-settings-two-col-stack{grid-template-columns:1fr;}
    .lm-settings-role-grid{display:grid; grid-template-columns:repeat(3, minmax(180px, 1fr)); gap:8px 12px; margin-top:8px;}
    .lm-settings-role-item{display:flex; align-items:center; gap:8px; padding:10px 12px; border:1px solid #e4eaf0; border-radius:12px; background:#fff;}
    .lm-settings-role-item.is-required{border-color:#bfd8f0; background:#f7fbff;}
    .lm-stack-sm > * + *,
    .lm-stack-md > * + *,
    .lm-stack-lg > * + *{display:block;}
    .lm-stack-sm > * + *{margin-top:8px;}
    .lm-stack-md > * + *{margin-top:12px;}
    .lm-stack-lg > * + *{margin-top:16px;}

    .lm-tabs{display:inline-flex; gap:6px; background:#f3f4f6; padding:4px; border-radius:8px; border:1px solid #e5e7eb;}
    .lm-tab{background:transparent; border:0; padding:6px 10px; font-size:12px; border-radius:6px; cursor:pointer;}
    .lm-tab.is-active{background:#b45309; color:#fff;}
    .lm-textarea-wrap{background:#111827; border-radius:10px; padding:12px; margin-top:10px;}
    .lm-textarea-wrap textarea{
      background:#ffffff;
      color:#1f2937;
      border:0;
      box-shadow:none;
      width:100%;
      min-height:140px;
      resize:vertical;
    }
    .lm-textarea-wrap textarea::placeholder{color:#9ca3af;}
    .lm-textarea-wrap textarea:focus{
      background:#ffffff;
      color:#111827;
      box-shadow:0 0 0 2px rgba(34,113,177,.22);
    }
    .lm-textarea-hint{color:#9ca3af; font-size:11px; margin-top:8px;}
    .lm-textarea-actions{display:flex; align-items:center; gap:10px; margin-top:12px; color:#9ca3af; font-size:11px;}
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
    body.links-manager-editor_page_links-manager-target .lm-grid,
    body.links-manager_page_links-manager-target .lm-grid{grid-auto-flow:row;}
    body.links-manager-editor_page_links-manager-target .lm-card-grouping,
    body.links-manager_page_links-manager-target .lm-card-grouping{order:1;}
    body.links-manager-editor_page_links-manager-target .lm-card-target,
    body.links-manager_page_links-manager-target .lm-card-target{order:2;}
    body.links-manager-editor_page_links-manager-target .lm-card-summary,
    body.links-manager_page_links-manager-target .lm-card-summary{order:3;}
    .lm-card{background:linear-gradient(180deg, #ffffff 0%, #fbfdff 100%); border:1px solid #d9e1e8; padding:18px; border-radius:18px; margin:12px 0; width:100%; box-sizing:border-box; box-shadow:0 10px 30px rgba(15,23,42,.04);}
    .lm-card-full{grid-column:1 / -1; width:100%;}
    .lm-card .widefat{margin:0;}
    .lm-card .tablenav{clear:both;}
    .lm-card > * + *{margin-top:14px;}
    .lm-card > .lm-section-intro + *{margin-top:0;}
    .lm-card form > * + *{margin-top:12px;}
    .lm-wrap .notice{border-radius:12px; box-shadow:0 8px 24px rgba(15,23,42,.04);}
    .lm-wrap .button{border-radius:10px; min-height:36px; padding:0 14px;}
    .lm-wrap .button-primary{box-shadow:none;}
    .lm-wrap input[type=text],
    .lm-wrap input[type=search],
    .lm-wrap input[type=number],
    .lm-wrap input[type=date],
    .lm-wrap input[type=url],
    .lm-wrap textarea,
    .lm-wrap select{
      border-color:#c8d3df;
      border-radius:10px;
      min-height:38px;
      box-shadow:none;
      padding:0 12px;
      background:#fff;
    }
    .lm-wrap textarea{padding:10px 12px; min-height:110px;}
    .lm-wrap input[type=checkbox],
    .lm-wrap input[type=radio]{border-color:#8ba0b3;}
    .lm-wrap input[type=text]:focus,
    .lm-wrap input[type=search]:focus,
    .lm-wrap input[type=number]:focus,
    .lm-wrap input[type=date]:focus,
    .lm-wrap input[type=url]:focus,
    .lm-wrap textarea:focus,
    .lm-wrap select:focus{
      border-color:#2271b1;
      box-shadow:0 0 0 1px #2271b1;
    }
    .lm-wrap select{
      appearance:none;
      -webkit-appearance:none;
      -moz-appearance:none;
      cursor:pointer;
      padding-right:42px;
      background-color:#fff;
      background-image:
        linear-gradient(45deg, transparent 50%, #52606d 50%),
        linear-gradient(135deg, #52606d 50%, transparent 50%),
        linear-gradient(to right, #d8e1ea, #d8e1ea);
      background-position:
        calc(100% - 18px) calc(50% - 1px),
        calc(100% - 12px) calc(50% - 1px),
        calc(100% - 38px) 50%;
      background-size:6px 6px, 6px 6px, 1px 18px;
      background-repeat:no-repeat;
    }
    .lm-wrap select:hover{
      border-color:#9fb4c8;
      background-image:
        linear-gradient(45deg, transparent 50%, #334155 50%),
        linear-gradient(135deg, #334155 50%, transparent 50%),
        linear-gradient(to right, #c5d2de, #c5d2de);
    }
    .lm-wrap select:focus{
      background-image:
        linear-gradient(45deg, transparent 50%, #2271b1 50%),
        linear-gradient(135deg, #2271b1 50%, transparent 50%),
        linear-gradient(to right, #bfd3e6, #bfd3e6);
    }
    .lm-filter-actions{display:flex; flex-wrap:wrap; gap:10px; align-items:center; margin-top:16px; padding-top:4px;}
    .lm-filter-summary{
      display:flex;
      flex-wrap:wrap;
      gap:12px;
      align-items:center;
      justify-content:space-between;
      padding:14px 16px;
      border:1px solid #dbe5ed;
      border-radius:14px;
      background:linear-gradient(135deg, #f9fbfd 0%, #f3f8fc 100%);
    }
    .lm-filter-summary strong{font-size:14px; color:#0f172a;}
    .lm-quick-actions{display:flex; flex-wrap:wrap; gap:8px; align-items:center;}
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
    .lm-filter-table th{display:block; width:auto; padding:0 0 6px 0; margin:0; font-size:11px; color:#54606e; font-weight:600; text-align:left; text-transform:uppercase; letter-spacing:.04em;}
    .lm-filter-table td{display:block; width:auto; padding:0; margin:0;}
    .lm-filter-table td > * + *{margin-top:8px;}
    .lm-filter-table tr.lm-filter-full{grid-column:1 / -1;}
    .lm-filter-table input[type=text],
    .lm-filter-table input[type=number],
    .lm-filter-table select{width:100%; max-width:none;}
    .lm-filter-table .regular-text{width:100%; max-width:none;}
    .lm-table-wrap + .tablenav,
    .tablenav + .lm-table-wrap{margin-top:14px;}
    .lm-wrap .tablenav{
      margin:14px 0 !important;
      height:auto;
      padding:12px 14px;
      border:1px solid #dde5ec;
      border-radius:14px;
      background:#fff;
      box-sizing:border-box;
    }
    .lm-wrap .tablenav-pages{
      display:flex;
      flex-wrap:wrap;
      align-items:center;
      gap:8px;
      float:none;
      margin:0;
    }
    .lm-wrap .lm-pagination-summary{
      color:#334155;
      font-weight:600;
      margin-right:10px;
    }
    .lm-wrap .displaying-num{margin-right:6px; color:#54606e;}
    .lm-stats-wrap{background:linear-gradient(135deg,#f8fbff 0%,#eef4f8 100%); border:1px solid #dde6ee; padding:18px; border-radius:18px; margin:12px 0 16px; box-shadow:0 12px 30px rgba(15,23,42,.04);}
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
    .lm-pie-card-inline .lm-tooltip:hover::after,
    .lm-pie-card-inline .lm-tooltip:focus::after,
    .lm-pie-card-inline .lm-tooltip:focus-visible::after{
      top:auto;
      bottom:calc(100% + 8px);
    }
    .lm-pie-card-inline .lm-tooltip:hover::before,
    .lm-pie-card-inline .lm-tooltip:focus::before,
    .lm-pie-card-inline .lm-tooltip:focus-visible::before{
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
    .lm-tooltip:focus,
    .lm-tooltip:focus-visible{outline:2px solid #2271b1; outline-offset:2px;}
    .lm-tooltip:hover::after,
    .lm-tooltip:focus::after,
    .lm-tooltip:focus-visible::after{
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
    .lm-tooltip:hover::before,
    .lm-tooltip:focus::before,
    .lm-tooltip:focus-visible::before{
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
    .lm-tooltip.is-left:hover::after,
    .lm-tooltip.is-left:focus::after,
    .lm-tooltip.is-left:focus-visible::after{
      left:0;
      transform:none;
    }
    .lm-tooltip.is-left:hover::before,
    .lm-tooltip.is-left:focus::before,
    .lm-tooltip.is-left:focus-visible::before{
      left:14px;
      transform:none;
    }
    .lm-tooltip.is-right:hover::after,
    .lm-tooltip.is-right:focus::after,
    .lm-tooltip.is-right:focus-visible::after{
      left:auto;
      right:0;
      transform:none;
    }
    .lm-tooltip.is-right:hover::before,
    .lm-tooltip.is-right:focus::before,
    .lm-tooltip.is-right:focus-visible::before{
      left:auto;
      right:14px;
      transform:none;
    }
    .lm-settings-tabs{margin:0 0 18px;}
    .lm-settings-tabs__divider{
      height:1px;
      margin-top:10px;
      background:#d6dee6;
    }
    .lm-wrap .nav-tab-wrapper{
      display:flex;
      flex-wrap:wrap;
      gap:8px;
      margin:0 !important;
      padding:0;
      border-bottom:0 !important;
      box-shadow:none !important;
      align-items:flex-start;
    }
    .lm-wrap .nav-tab{
      margin:0 !important;
      margin-bottom:0 !important;
      border:1px solid #d6e0e8;
      border-radius:999px;
      background:#fff;
      color:#334155;
      padding:9px 14px;
      line-height:1.2;
      transition:all .15s ease;
    }
    .lm-wrap .nav-tab:hover{background:#f8fbff; color:#0f172a;}
    .lm-wrap .nav-tab-active,
    .lm-wrap .nav-tab-active:hover,
    .lm-wrap .nav-tab[aria-current=page]{
      border-color:#2271b1;
      background:#2271b1;
      color:#fff;
    }
    .lm-wrap details{
      border:1px solid #dde5ec !important;
      border-radius:14px !important;
      background:#fff !important;
      overflow:hidden;
    }
    .lm-wrap details > summary{
      padding:14px 16px !important;
      background:#f8fbfc;
    }
    .lm-wrap details > *:not(summary){margin:0;}
    .lm-wrap .submit{margin:0;}
    .lm-wrap .submit .button,
    .lm-wrap .submit .button-primary,
    .lm-wrap .submit .button-secondary{margin:0;}
    .lm-wrap hr{margin:20px 0; border-color:#e5ebf1;}
    body.toplevel_page_links-manager-editor #wpfooter,
    body.toplevel_page_links-manager #wpfooter,
    body.links-manager-editor_page_links-manager-stats #wpfooter,
    body.links-manager_page_links-manager-stats #wpfooter,
    body.links-manager-editor_page_links-manager-pages-link #wpfooter,
    body.links-manager_page_links-manager-pages-link #wpfooter,
    body.links-manager-editor_page_links-manager-target #wpfooter,
    body.links-manager_page_links-manager-target #wpfooter,
    body.links-manager-editor_page_links-manager-cited-domains #wpfooter,
    body.links-manager_page_links-manager-cited-domains #wpfooter,
    body.links-manager-editor_page_links-manager-all-anchor-text #wpfooter,
    body.links-manager_page_links-manager-all-anchor-text #wpfooter,
    body.links-manager-editor_page_links-manager-settings #wpfooter{
      position:static;
    }
    body.toplevel_page_links-manager-editor #wpbody-content,
    body.toplevel_page_links-manager #wpbody-content,
    body.links-manager-editor_page_links-manager-stats #wpbody-content,
    body.links-manager_page_links-manager-stats #wpbody-content,
    body.links-manager-editor_page_links-manager-pages-link #wpbody-content,
    body.links-manager_page_links-manager-pages-link #wpbody-content,
    body.links-manager-editor_page_links-manager-target #wpbody-content,
    body.links-manager_page_links-manager-target #wpbody-content,
    body.links-manager-editor_page_links-manager-cited-domains #wpbody-content,
    body.links-manager_page_links-manager-cited-domains #wpbody-content,
    body.links-manager-editor_page_links-manager-all-anchor-text #wpbody-content,
    body.links-manager_page_links-manager-all-anchor-text #wpbody-content,
    body.links-manager-editor_page_links-manager-settings #wpbody-content{
      padding-bottom:48px;
    }
    body.links-manager-editor_page_links-manager-target #wpbody-content,
    body.links-manager_page_links-manager-target #wpbody-content{
      max-width:none;
    }
    body.links-manager-editor_page_links-manager-target #wpbody-content .wrap,
    body.links-manager_page_links-manager-target #wpbody-content .wrap{
      max-width:none;
    }
    body.links-manager-editor_page_links-manager-target .lm-card-grouping,
    body.links-manager_page_links-manager-target .lm-card-grouping{order:1;}
    body.links-manager-editor_page_links-manager-target .lm-card-target,
    body.links-manager_page_links-manager-target .lm-card-target{order:2;}
    body.links-manager-editor_page_links-manager-target .lm-card-summary,
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
      .lm-page-hero{padding:18px;}
      .lm-stats-grid{grid-template-columns:repeat(2, minmax(0,1fr));}
      .lm-top-grid{grid-template-columns:1fr;}
      .lm-filter-grid{grid-template-columns:repeat(2, minmax(160px, 1fr));}
      .lm-filter-table tbody{grid-template-columns:repeat(2, minmax(160px, 1fr));}
      .lm-settings-two-col{grid-template-columns:1fr;}
    }
    @media (max-width: 782px){
      .lm-wrap{--lm-table-sticky-top:46px;}
    }
    @media (max-width: 640px){
      .lm-wrap{padding-right:0;}
      .lm-table-wrap{max-height:calc(100vh - 140px);}
      .lm-page-hero{padding:16px; border-radius:16px;}
      .lm-page-title{font-size:24px;}
      .lm-page-subtitle{font-size:12px;}
      .lm-stats-grid{grid-template-columns:1fr;}
      .lm-pie-card-inline{flex-direction:column; gap:10px;}
      .lm-filter-grid{grid-template-columns:1fr;}
      .lm-filter-field-wide{grid-column:1 / -1;}
      .lm-filter-table tbody{grid-template-columns:1fr;}
      .lm-settings-actions{grid-template-columns:1fr;}
      .lm-settings-actions-primary,
      .lm-settings-actions-card{padding:10px;}
      .lm-settings-actions-primary .submit,
      .lm-settings-actions-card .submit{margin:0 0 8px 0; display:block;}
      .lm-settings-actions-primary .button,
      .lm-settings-actions-card .button{width:100%;}
      .lm-settings-role-grid{grid-template-columns:1fr;}
      .lm-filter-actions{flex-direction:column; align-items:stretch;}
      .lm-filter-actions .button,
      .lm-filter-actions .button-primary{width:100%; justify-content:center;}
      .lm-wrap .tablenav-pages{align-items:stretch;}
      .lm-wrap .tablenav-pages .button{width:100%; text-align:center;}
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
        const statusEl = rebuildRoot.querySelector('[data-lm-rest-rebuild-status]');
        const progressEl = rebuildRoot.querySelector('[data-lm-rest-rebuild-progress]');
        const metaEl = rebuildRoot.querySelector('[data-lm-rest-rebuild-meta]');
        const barEl = rebuildRoot.querySelector('[data-lm-rest-rebuild-bar]');
        const config = (window.LM_REBUILD_REST && typeof window.LM_REBUILD_REST === 'object') ? window.LM_REBUILD_REST : null;

        let loopTimer = null;
        let isLooping = false;
        const scopePostType = (config && config.scope_post_type) ? String(config.scope_post_type) : 'any';
        const scopeWpmlLang = (config && config.scope_wpml_lang) ? String(config.scope_wpml_lang) : 'all';
        const scopeLabel = (config && config.scope_label) ? String(config.scope_label) : 'all scopes / all languages';

        const setRunningUi = (running) => {
          if (runBtn) {
            runBtn.disabled = !!running;
            runBtn.textContent = running ? 'Refreshing...' : 'Refresh Data Now';
          }
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
          const runningPct = total > 0 ? Math.max(0, Math.min(99, Math.floor((processed / total) * 100))) : 0;
          const pct = (status === 'done' || status === 'partial')
            ? 100
            : (status === 'finalizing' ? 99 : runningPct);

          if (progressEl) {
            if (status === 'partial' && total > 0) {
              progressEl.textContent = processed.toLocaleString() + ' / ' + total.toLocaleString() + ' posts scanned before the safety limit (' + Math.max(0, Math.min(100, Math.floor((processed / total) * 100))) + '% of scope)';
            } else if (total > 0) {
              progressEl.textContent = processed.toLocaleString() + ' / ' + total.toLocaleString() + ' posts (' + pct + '%)';
            } else if (status === 'running' || status === 'finalizing' || status === 'done' || status === 'partial') {
              progressEl.textContent = processed.toLocaleString() + ' posts processed';
            } else {
              progressEl.textContent = " . wp_json_encode(__('No active refresh job.', 'links-manager')) . ";
            }
          }

          if (metaEl) {
            const updatedAt = state && state.updated_at ? String(state.updated_at) : '-';
            const batch = Math.max(0, parseInt((state && state.batch_size) ? state.batch_size : 0, 10) || 0);
            metaEl.textContent = 'Scope: ' + scopeLabel + ' | Status: ' + status + ' | Rows: ' + rows.toLocaleString() + ' | Batch: ' + (batch > 0 ? batch.toLocaleString() : '-') + ' | Updated: ' + updatedAt;
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

        const refreshStatus = () => {
          return apiCall('/rebuild/status', 'GET')
            .then(state => {
              updateProgress(state || {});
              const status = String((state && state.status) ? state.status : 'idle');
              if (status === 'running') {
                setStatusText('Refresh is running for ' + scopeLabel + '. Progress updates will appear automatically.', false);
              } else if (status === 'finalizing') {
                const detail = String((state && state.message) ? state.message : 'Refresh is finalizing cached summaries...');
                setStatusText(detail, false);
              } else if (status === 'done') {
                setStatusText('Refresh finished for ' + scopeLabel + '. Cached data is up to date.', false);
              } else if (status === 'partial') {
                const detail = String((state && state.message) ? state.message : 'Refresh stopped before the full scope completed.');
                setStatusText(detail, true);
              } else if (status === 'error') {
                setStatusText('Refresh failed: ' + String((state && state.last_error) ? state.last_error : 'Unknown error'), true);
              } else {
                setStatusText(" . wp_json_encode(__('No active refresh job.', 'links-manager')) . ", false);
              }
              return state;
            });
        };

        const runStepLoop = () => {
          if (isLooping) return;
          isLooping = true;
          setRunningUi(true);

          const iterate = () => {
            apiCall('/rebuild/step', 'POST', {})
              .then(state => {
                updateProgress(state || {});
                const status = String((state && state.status) ? state.status : 'idle');
                if (status === 'running' || status === 'finalizing') {
                  if (status === 'finalizing') {
                    const detail = String((state && state.message) ? state.message : 'Refresh is finalizing cached summaries...');
                    setStatusText(detail, false);
                  } else {
                  setStatusText('Refresh in progress for ' + scopeLabel + '...', false);
                  }
                  const hinted = parseInt((state && state.poll_ms) ? state.poll_ms : 400, 10);
                  const delay = Number.isFinite(hinted) ? Math.max(200, Math.min(5000, hinted)) : 400;
                  loopTimer = window.setTimeout(iterate, delay);
                  return;
                }

                isLooping = false;
                setRunningUi(false);
                if (status === 'done') {
                  setStatusText('Refresh finished for ' + scopeLabel + '. Cached data is up to date.', false);
                } else if (status === 'partial') {
                  const detail = String((state && state.message) ? state.message : 'Refresh stopped before the full scope completed.');
                  setStatusText(detail, true);
                } else if (status === 'error') {
                  setStatusText('Refresh failed: ' + String((state && state.last_error) ? state.last_error : 'Unknown error'), true);
                } else {
                  setStatusText(" . wp_json_encode(__('No active refresh job.', 'links-manager')) . ", false);
                }
              })
              .catch(err => {
                isLooping = false;
                setRunningUi(false);
                setStatusText('Refresh error: ' + err.message, true);
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

            setStatusText('Starting refresh for ' + scopeLabel + '...', false);
            const startPayload = {
              post_type: scopePostType,
              wpml_lang: scopeWpmlLang,
            };

            apiCall('/rebuild/start', 'POST', startPayload)
              .then(state => {
                updateProgress(state || {});
                const status = String((state && state.status) ? state.status : 'idle');
                if (status === 'done') {
                  setStatusText('Refresh finished immediately for ' + scopeLabel + '.', false);
                  setRunningUi(false);
                  return;
                }
                if (status === 'partial') {
                  const detail = String((state && state.message) ? state.message : 'Refresh stopped before the full scope completed.');
                  setStatusText(detail, true);
                  setRunningUi(false);
                  return;
                }
                if (status === 'error') {
                  setStatusText('Failed to start refresh: ' + String((state && state.last_error) ? state.last_error : 'Unknown error'), true);
                  setRunningUi(false);
                  return;
                }
                setStatusText('Refresh started for ' + scopeLabel + '. Processing chunks...', false);
                runStepLoop();
              })
              .catch(err => {
                setRunningUi(false);
                setStatusText('Refresh error: ' + err.message, true);
              });
          });
        }

        refreshStatus()
          .then(state => {
            const status = String((state && state.status) ? state.status : 'idle');
            if (status === 'running' || status === 'finalizing') {
              runStepLoop();
            } else {
              setRunningUi(false);
            }
          })
          .catch(err => {
            setRunningUi(false);
            setStatusText('REST status error: ' + err.message, true);
          });
      }

      const restConfig = (window.LM_REBUILD_REST && typeof window.LM_REBUILD_REST === 'object') ? window.LM_REBUILD_REST : null;
      const escHtml = (val) => {
        return String(val == null ? '' : val)
          .replace(/&/g, '&amp;')
          .replace(/</g, '&lt;')
          .replace(/>/g, '&gt;')
          .replace(/\"/g, '&quot;')
          .replace(/'/g, '&#39;');
      };

    });
    ";
    wp_register_script('lm-admin-js', false);
    wp_enqueue_script('lm-admin-js');
    $rebuildScopeWpmlLang = 'all';
    $rebuildScopePostType = 'any';
    $rebuildScopeLabel = __('all scopes / all languages', 'links-manager');
    wp_add_inline_script('lm-admin-js', 'window.LM_REBUILD_REST = ' . wp_json_encode([
      'base' => esc_url_raw(rest_url('links-manager/v1')),
      'nonce' => wp_create_nonce('wp_rest'),
      'scope_post_type' => $rebuildScopePostType,
      'scope_wpml_lang' => $rebuildScopeWpmlLang,
      'scope_label' => $rebuildScopeLabel,
    ]) . ';', 'before');
    wp_add_inline_script('lm-admin-js', $js);
  }

  /* -----------------------------
   * Utils
   * ----------------------------- */


}


register_activation_hook(__FILE__, ['LM_Links_Manager', 'activate']);
register_deactivation_hook(__FILE__, ['LM_Links_Manager', 'deactivate']);

new LM_Links_Manager();
