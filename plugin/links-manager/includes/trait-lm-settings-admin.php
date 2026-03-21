<?php
/**
 * Settings admin rendering and handlers for Links Manager.
 */

if (!defined('ABSPATH')) {
  exit;
}

trait LM_Settings_Admin_Trait {
  public function render_admin_settings_page() {
    if (!current_user_can('manage_options')) wp_die($this->unauthorized_message());

    $settings = $this->get_settings();
    $runtimeMemoryLimitBytes = $this->get_effective_memory_limit_bytes();
    $runtimeMemoryLimitLabel = $this->format_bytes_human($runtimeMemoryLimitBytes);
    $runtimeMaxRows = $this->get_runtime_max_cache_rows();
    $runtimeMaxBatch = $this->get_runtime_max_crawl_batch();
    $runtimeTransientMain = $this->get_safe_transient_limit_bytes(false);
    $runtimeTransientBackup = $this->get_safe_transient_limit_bytes(true);
    $msg = isset($_GET['lm_msg']) ? sanitize_text_field((string)$_GET['lm_msg']) : '';
    $msgClass = $this->notice_class_for_message($msg, 'success');
    $activeTab = isset($_GET['lm_tab']) ? sanitize_key((string)$_GET['lm_tab']) : 'general';
    if (!in_array($activeTab, ['general', 'performance', 'data'], true)) {
      $activeTab = 'general';
    }
    $settingsLabelStyle = 'display:block; margin:0 0 6px; font-weight:600; color:#1d2327;';
    $settingsLabelTopStyle = $settingsLabelStyle;
    $settingsCardStyle = 'margin:0 0 12px; padding:12px; border:1px solid #dcdcde; background:#fff; border-radius:8px; box-shadow:0 1px 1px rgba(0,0,0,.02);';
    $settingsCardCompactStyle = 'padding:12px; border:1px solid #dcdcde; background:#fff; border-radius:8px; box-shadow:0 1px 1px rgba(0,0,0,.02);';

    echo '<div class="wrap lm-wrap">';
    echo '<h1 class="lm-page-title">' . esc_html__('Links Manager - Settings', 'links-manager') . '</h1>';
    if ($msg !== '') echo '<div class="notice notice-' . esc_attr($msgClass) . '"><p>' . esc_html($msg) . '</p></div>';

    $this->render_settings_diagnostic_box();
    $this->render_settings_runtime_profile_box();

    echo '<div class="lm-card lm-card-full">';
    echo '<h2 class="nav-tab-wrapper" style="margin:0 0 12px;">';
    echo '<a href="' . esc_url(admin_url('admin.php?page=links-manager-settings&lm_tab=general')) . '" class="nav-tab ' . ($activeTab === 'general' ? 'nav-tab-active' : '') . '"' . ($activeTab === 'general' ? ' aria-current="page"' : '') . '>' . esc_html__('General', 'links-manager') . '</a>';
    echo '<a href="' . esc_url(admin_url('admin.php?page=links-manager-settings&lm_tab=performance')) . '" class="nav-tab ' . ($activeTab === 'performance' ? 'nav-tab-active' : '') . '"' . ($activeTab === 'performance' ? ' aria-current="page"' : '') . '>' . esc_html__('Performance', 'links-manager') . '</a>';
    echo '<a href="' . esc_url(admin_url('admin.php?page=links-manager-settings&lm_tab=data')) . '" class="nav-tab ' . ($activeTab === 'data' ? 'nav-tab-active' : '') . '"' . ($activeTab === 'data' ? ' aria-current="page"' : '') . '>' . esc_html__('Data & Quality', 'links-manager') . '</a>';
    echo '</h2>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:10px;">';
    echo '<input type="hidden" name="action" value="lm_save_settings"/>';
    echo '<input type="hidden" name="' . esc_attr(self::NONCE_NAME) . '" value="' . esc_attr(wp_create_nonce(self::NONCE_ACTION)) . '"/>';
    echo '<input type="hidden" name="lm_active_tab" value="' . esc_attr($activeTab) . '"/>';

    if ($activeTab === 'general') {
      echo '<h2 style="margin-top:0;">' . esc_html__('Access Control', 'links-manager') . '</h2>';
      echo '<div class="lm-small">' . esc_html__('Choose which user roles can use this plugin. Administrator is always allowed.', 'links-manager') . '</div>';
      echo '<div style="' . esc_attr($settingsCardStyle) . '">';
      echo '<div class="lm-settings-role-grid">';
      $rolesMap = $this->get_all_roles_map();
      $allowedRoles = $this->get_allowed_roles_from_settings();
      $debugModeEnabled = isset($settings['debug_mode']) && (string)$settings['debug_mode'] === '1';
      foreach ($rolesMap as $roleKey => $roleLabel) {
        $isChecked = in_array((string)$roleKey, $allowedRoles, true) ? '1' : '0';
        $isAdminRole = ((string)$roleKey === 'administrator');
        echo '<label class="lm-settings-role-item' . ($isAdminRole ? ' is-required' : '') . '">';
        echo '<input type="checkbox" name="lm_allowed_roles[]" value="' . esc_attr((string)$roleKey) . '"' . checked($isChecked, '1', false) . ($isAdminRole ? ' disabled' : '') . '/> ';
        echo esc_html((string)$roleLabel);
        if ($isAdminRole) {
          echo ' <span class="lm-small">(' . esc_html__('required', 'links-manager') . ')</span>';
          echo '<input type="hidden" name="lm_allowed_roles[]" value="administrator"/>';
        }
        echo '</label>';
      }
      echo '</div>';
      echo '</div>';

      echo '<div style="' . esc_attr($settingsCardStyle) . '">';
      echo '<div style="font-weight:600; margin-bottom:6px;">' . esc_html__('Debug mode', 'links-manager') . '</div>';
      echo '<label style="display:inline-flex; align-items:center; gap:8px;">';
      echo '<input type="checkbox" name="lm_debug_mode" value="1"' . checked($debugModeEnabled ? '1' : '0', '1', false) . '/>';
      echo '<span>' . wp_kses(
        __('Enable view for <strong>Last Fatal Diagnostic</strong> and <strong>Last Runtime Profile</strong>.', 'links-manager'),
        [
          'strong' => [],
        ]
      ) . '</span>';
      echo '</label>';
      echo '<div class="lm-small" style="margin-top:6px;">' . esc_html__('When disabled, diagnostic panels are hidden for all users. When enabled, only Administrator can view and clear diagnostics.', 'links-manager') . '</div>';
      echo '<div class="lm-small" style="margin-top:6px;">' . esc_html__('How to use: enable Debug mode, save settings, then reproduce the problem or load a profiled admin request. Fatal errors are captured automatically. Runtime profile data appears when profiling is active.', 'links-manager') . '</div>';
      echo '<div class="lm-small" style="margin-top:4px;">' . esc_html__('Profiling URL suffix: add ?lm_profile=1 to the admin page URL, or &lm_profile=1 if the URL already has query parameters.', 'links-manager') . '</div>';
      echo '<div class="lm-small" style="margin-top:4px;">' . esc_html__('Where to see the result: return to this Settings page. The panels Last Fatal Diagnostic and Last Runtime Profile will appear at the top when data is available.', 'links-manager') . '</div>';
      echo '</div>';
      echo '<hr style="margin:14px 0;"/>';
    }

    if ($activeTab === 'performance') {
    echo '<h2 style="margin-top:0;">' . esc_html__('Performance & Reliability', 'links-manager') . '</h2>';
    echo '<div class="lm-small">' . esc_html__('Adjust how often data updates and how your server handles large scans.', 'links-manager') . '</div>';
    echo '<div style="margin-top:8px;">';
    echo '<div style="margin:0 0 12px; padding:10px; border-left:4px solid #2271b1; background:#f6f7f7;">';
    echo '<div style="font-weight:600; margin-bottom:4px;">' . esc_html__('Section A: What to Scan', 'links-manager') . '</div>';
    echo '<div class="lm-small">' . esc_html__('Choose which pages, sources, and link types are included in each scan.', 'links-manager') . '</div>';
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
    echo '<div style="font-weight:600; margin-bottom:6px;">' . esc_html__('Scan estimate', 'links-manager') . '</div>';
    echo '<div class="lm-small">' . esc_html__('Estimated posts based on current scan settings:', 'links-manager') . '</div>';
    echo '<table class="widefat striped" style="margin-top:8px; max-width:760px;">';
    echo '<tbody>';
    echo '<tr><th style="width:260px;">' . esc_html__('Estimated posts to scan', 'links-manager') . '</th><td>' . esc_html(number_format((int)($scopeEstimate['estimated_posts'] ?? 0))) . '</td></tr>';
    echo '<tr><th>' . esc_html__('Selected post types', 'links-manager') . '</th><td>' . esc_html((string)($scopeEstimate['post_types_count'] ?? 0)) . '</td></tr>';
    echo '<tr><th>' . esc_html__('Selected authors', 'links-manager') . '</th><td>' . esc_html((int)($scopeEstimate['authors_count'] ?? 0) > 0 ? (string)$scopeEstimate['authors_count'] : __('All authors', 'links-manager')) . '</td></tr>';
    echo '<tr><th>' . esc_html__('Modified date window', 'links-manager') . '</th><td>' . esc_html((int)($scopeEstimate['modified_within_days'] ?? 0) > 0 ? sprintf(__('Last %d day(s)', 'links-manager'), (int) $scopeEstimate['modified_within_days']) : __('All history', 'links-manager')) . '</td></tr>';
    echo '<tr><th>' . esc_html__('Enabled URL types', 'links-manager') . '</th><td>' . esc_html((string)($scopeEstimate['value_types_count'] ?? 0)) . '</td></tr>';
    echo '<tr><th>' . esc_html__('WPML language scope', 'links-manager') . '</th><td>' . esc_html(($scopeEstimate['wpml_all'] ?? '1') === '1' ? __('All languages', 'links-manager') : sprintf(__('%d selected language(s)', 'links-manager'), (int)($scopeEstimate['wpml_langs_count'] ?? 0))) . '</td></tr>';
    echo '</tbody>';
    echo '</table>';
    echo '<div class="lm-small" style="margin-top:6px;">' . esc_html__('Note: this estimate is calculated before per-link filters are applied.', 'links-manager') . '</div>';
    echo '</div>';

    echo '<div style="margin:0 0 8px; padding:8px 10px; border-left:3px solid #2271b1; background:#fff;">';
    echo '<div style="font-weight:600; margin-bottom:4px;">' . esc_html__('Core Scan Options', 'links-manager') . '</div>';
    echo '<div class="lm-small">' . esc_html__('Start with these options first. Most sites only need this section.', 'links-manager') . '</div>';
    echo '</div>';

    echo '<div class="lm-settings-two-col lm-settings-two-col-stack">';
    echo '<p style="margin:0 0 10px;">';
    echo '<label class="lm-small" style="' . esc_attr($settingsLabelTopStyle) . '">' . esc_html__('Post types to scan:', 'links-manager') . '</label>';
    echo '<span style="display:inline-block;">';
    foreach ($availablePostTypes as $ptKey => $ptLabel) {
      $checked = in_array((string)$ptKey, $scanPostTypes, true) ? '1' : '0';
      echo '<label style="display:block; margin:2px 0;">';
      echo '<input type="checkbox" name="lm_scan_post_types[]" value="' . esc_attr((string)$ptKey) . '"' . checked($checked, '1', false) . '/> ';
      echo esc_html((string)$ptLabel);
      echo '</label>';
    }
    echo '</span>';
    echo '<span class="lm-small" style="display:block; margin-top:4px;">' . esc_html__('Only selected post types will be scanned.', 'links-manager') . '</span>';
    echo '</p>';

    echo '<p style="margin:0 0 10px;">';
    echo '<label class="lm-small" style="' . esc_attr($settingsLabelTopStyle) . '">' . esc_html__('Content sources:', 'links-manager') . '</label>';
    echo '<span style="display:inline-block;">';
    foreach ($scanSourceTypeOptions as $sourceKey => $sourceLabel) {
      $checked = in_array((string)$sourceKey, $scanSourceTypes, true) ? '1' : '0';
      echo '<label style="display:block; margin:2px 0;">';
      echo '<input type="checkbox" name="lm_scan_source_types[]" value="' . esc_attr((string)$sourceKey) . '"' . checked($checked, '1', false) . '/> ';
      echo esc_html((string)$sourceLabel);
      echo '</label>';
    }
    echo '</span>';
    echo '<span class="lm-small" style="display:block; margin-top:4px;">' . esc_html__('Content is usually enough. Enable Excerpt/Meta/Menu only when needed.', 'links-manager') . '</span>';
    echo '</p>';

    echo '<p style="margin:0 0 10px;">';
    echo '<label class="lm-small" style="' . esc_attr($settingsLabelTopStyle) . '">' . esc_html__('Link types to include:', 'links-manager') . '</label>';
    echo '<span style="display:inline-block;">';
    foreach ($scanValueTypeOptions as $valueTypeKey => $valueTypeLabel) {
      $checked = in_array((string)$valueTypeKey, $scanValueTypes, true) ? '1' : '0';
      echo '<label style="display:block; margin:2px 0;">';
      echo '<input type="checkbox" name="lm_scan_value_types[]" value="' . esc_attr((string)$valueTypeKey) . '"' . checked($checked, '1', false) . '/> ';
      echo esc_html((string)$valueTypeLabel);
      echo '</label>';
    }
    echo '</span>';
    echo '<span class="lm-small" style="display:block; margin-top:4px;">' . esc_html__('Unchecked link types are skipped to keep scans lighter.', 'links-manager') . '</span>';
    echo '</p>';

    echo '<p style="margin:0 0 10px;">';
    echo '<label class="lm-small" style="' . esc_attr($settingsLabelTopStyle) . '">' . esc_html__('Languages (WPML):', 'links-manager') . '</label>';
    if ($this->is_wpml_active() && !empty($wpmlLanguagesMap)) {
      echo '<span style="display:inline-block;">';
      $allChecked = in_array('all', $scanWpmlLangs, true) ? '1' : '0';
      echo '<label style="display:block; margin:2px 0;"><input type="checkbox" name="lm_scan_wpml_langs[]" value="all"' . checked($allChecked, '1', false) . '/> ' . esc_html__('All languages', 'links-manager') . '</label>';
      foreach ($wpmlLanguagesMap as $langCode => $langLabel) {
        $checked = in_array((string)$langCode, $scanWpmlLangs, true) ? '1' : '0';
        echo '<label style="display:block; margin:2px 0;">';
        echo '<input type="checkbox" name="lm_scan_wpml_langs[]" value="' . esc_attr((string)$langCode) . '"' . checked($checked, '1', false) . '/> ';
        echo esc_html((string)$langLabel) . ' (' . esc_html((string)$langCode) . ')';
        echo '</label>';
      }
      echo '</span>';
      echo '<span class="lm-small" style="display:block; margin-top:4px;">' . esc_html__('Uncheck All languages only if you want to scan specific languages.', 'links-manager') . '</span>';
    } else {
      echo '<span class="lm-small">' . esc_html__('WPML not active. This setting is ignored.', 'links-manager') . '</span>';
    }
    echo '</p>';
    echo '</div>';

    echo '<details style="margin:12px 0; border:1px solid #dcdcde; border-radius:4px; background:#fff;">';
    echo '<summary style="padding:10px 12px; cursor:pointer; font-weight:600;">' . esc_html__('Optional filters and limits (advanced)', 'links-manager') . '</summary>';
    echo '<div style="padding:8px 12px 12px;">';
    echo '<div class="lm-small" style="margin:0 0 10px;">' . esc_html__('Use this only if you need tighter filters or lower server load during rebuild.', 'links-manager') . '</div>';

    echo '<div class="lm-settings-two-col lm-settings-two-col-stack">';
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
    echo '<span class="lm-small" style="display:block; margin-top:4px;">Leave empty to include all categories.</span>';
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
    echo '<span class="lm-small" style="display:block; margin-top:4px;">Leave empty to include all tags.</span>';
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
    echo '<span class="lm-small" style="display:block; margin-top:4px;">Leave empty to include all authors.</span>';
    echo '</p>';

    echo '<p style="margin:0 0 10px;">';
    echo '<label class="lm-small" style="' . esc_attr($settingsLabelStyle) . '">Recent updates only (days):</label>';
    echo '<input type="number" name="lm_scan_modified_within_days" min="0" max="3650" value="' . esc_attr((string)$scanModifiedWithinDays) . '" style="width:110px;" />';
    echo '<span class="lm-small" style="margin-left:8px;">Use 0 to scan all history.</span>';
    echo '</p>';

    echo '<p style="margin:0 0 10px;">';
    echo '<label class="lm-small" style="' . esc_attr($settingsLabelTopStyle) . '">Skip URL patterns:</label>';
    echo '<textarea name="lm_scan_exclude_url_patterns" rows="5" style="width:100%; max-width:520px;" placeholder="One pattern per line. Example:\n/product/*\n/category/old-news/*\nhttps://example.com/landing-old">' . esc_textarea($scanExcludePatterns) . '</textarea>';
    echo '<span class="lm-small" style="margin-top:4px; display:block;">URLs matching these patterns are skipped during scan.</span>';
    echo '<span class="lm-small" style="margin-top:2px; display:block;">Directory example: <code>/blog/category/</code> (skips URLs containing that path).</span>';
    echo '<span class="lm-small" style="margin-top:2px; display:block;">Specific URL example: <code>https://example.com/landing-old*</code> (skips that page and query/hash variants).</span>';
    echo '<span class="lm-small" style="margin-top:2px; display:block;">Use one pattern per line. Use <code>*</code> as wildcard.</span>';
    echo '</p>';

    echo '<p style="margin:0 0 10px;">';
    echo '<label class="lm-small" style="' . esc_attr($settingsLabelStyle) . '">Posts per rebuild limit:</label>';
    echo '<input type="number" name="lm_max_posts_per_rebuild" min="0" max="50000" value="' . esc_attr((string)$maxPostsPerRebuild) . '" style="width:110px;" />';
    echo '<span class="lm-small" style="margin-left:8px;">Use 0 for no limit. Lower values reduce server load.</span>';
    echo '</p>';
    echo '</div>';

    echo '</div>';
    echo '</details>';

    echo '<hr style="margin:14px 0;"/>';
    echo '<div style="margin:0 0 12px; padding:10px; border-left:4px solid #00a32a; background:#f6f7f7;">';
    echo '<div style="font-weight:600; margin-bottom:4px;">Section B: Cache &amp; Rebuild</div>';
    echo '<div class="lm-small">Control refresh frequency and rebuild workload for stable performance.</div>';
    echo '<div class="lm-small" style="margin-top:6px;">Status legend: <span class="lm-pill ok">Green = healthy</span> <span class="lm-pill warn">Amber = monitor</span> <span class="lm-pill bad">Red = action recommended</span>.</div>';
    echo '</div>';

    $nextScheduledTs = wp_next_scheduled('lm_scheduled_cache_rebuild');
    $schedulerEnabled = $nextScheduledTs !== false && $nextScheduledTs > 0;
    $nextScheduledLabel = $schedulerEnabled ? wp_date('Y-m-d H:i:s', (int)$nextScheduledTs) : 'Not scheduled';
    $schedulerStatusClass = $schedulerEnabled ? 'ok' : 'bad';

    $restState = $this->get_rebuild_job_state();
    $restStatus = isset($restState['status']) ? sanitize_key((string)$restState['status']) : 'idle';
    if ($restStatus === '') $restStatus = 'idle';
    $restRows = isset($restState['rows_count']) ? max(0, (int)$restState['rows_count']) : 0;
    $restProcessed = isset($restState['processed_posts']) ? max(0, (int)$restState['processed_posts']) : 0;
    $restTotal = isset($restState['total_posts']) ? max(0, (int)$restState['total_posts']) : 0;
    $restUpdatedAt = isset($restState['updated_at']) ? (string)$restState['updated_at'] : '';
    if ($restUpdatedAt === '') {
      $restUpdatedAt = '—';
    }

    $lastScanGmt = (string)get_option($this->cache_scan_option_key('any', 'all'), '');
    $lastScanLabel = 'Never';
    $cacheAgeHours = -1;
    if ($lastScanGmt !== '') {
      $lastScanTs = strtotime((string)$lastScanGmt . ' GMT');
      if ($lastScanTs !== false && $lastScanTs > 0) {
        $lastScanLabel = wp_date('Y-m-d H:i:s', $lastScanTs);
        $cacheAgeHours = (int)floor((time() - $lastScanTs) / HOUR_IN_SECONDS);
      }
    }
    $lastFullGmt = (string)get_option($this->cache_scan_option_key('any', 'all_full'), '');
    $lastFullLabel = $lastFullGmt !== '' ? wp_date('Y-m-d H:i:s', strtotime($lastFullGmt . ' UTC')) : 'No full rebuild yet';
    $lastSnapshotAt = (string)get_option('lm_last_stats_snapshot_at', '');
    $lastSnapshotLabel = $lastSnapshotAt !== '' ? $lastSnapshotAt : 'No snapshot yet';

    $mainCacheRows = get_transient($this->cache_key('any', 'all'));
    $mainCacheRowsCount = is_array($mainCacheRows) ? count($mainCacheRows) : 0;
    $backupCacheRows = get_transient($this->cache_backup_key('any', 'all'));
    $backupCacheRowsCount = is_array($backupCacheRows) ? count($backupCacheRows) : 0;
    $effectiveCacheRowsCount = max($mainCacheRowsCount, $backupCacheRowsCount);
    $usingBackupFallback = ($mainCacheRowsCount === 0 && $backupCacheRowsCount > 0);

    $restRecommendation = __('Cache looks usable. Manual REST rebuild is optional.', 'links-manager');
    $restRecommendationClass = 'ok';
    if ($restStatus === 'running') {
      $restRecommendation = __('REST rebuild is currently running. No manual trigger needed now.', 'links-manager');
      $restRecommendationClass = 'warn';
    } elseif ($effectiveCacheRowsCount === 0 || $lastScanLabel === 'Never') {
      $restRecommendation = __('No recent cache detected. Recommended: trigger manual REST rebuild.', 'links-manager');
      $restRecommendationClass = 'bad';
    } elseif ($cacheAgeHours >= 24) {
      $restRecommendation = __('Main cache is older than 24 hours. Consider manual REST rebuild if data must be fresh.', 'links-manager');
      $restRecommendationClass = 'warn';
    }

    echo '<div style="' . esc_attr($settingsCardStyle) . '">';
    echo '<div style="font-weight:600; margin-bottom:6px;">' . esc_html__('Scheduler Status', 'links-manager') . '</div>';
    echo '<table class="widefat striped" style="margin-top:8px; max-width:760px;">';
    echo '<tbody>';
    echo '<tr><th style="width:260px;">' . esc_html__('Hourly scheduler (WP-Cron)', 'links-manager') . '</th><td><span class="lm-pill ' . esc_attr($schedulerStatusClass) . '">' . esc_html($schedulerEnabled ? __('Enabled', 'links-manager') : __('Disabled', 'links-manager')) . '</span></td></tr>';
    echo '<tr><th>' . esc_html__('Next scheduled run', 'links-manager') . '</th><td>' . esc_html($nextScheduledLabel) . '</td></tr>';
    echo '<tr><th>' . esc_html__('Last dashboard snapshot', 'links-manager') . '</th><td>' . esc_html($lastSnapshotLabel) . '</td></tr>';
    echo '</tbody>';
    echo '</table>';
    echo '<div class="lm-small" style="margin-top:6px;">' . esc_html__('Scheduler runs through WP-Cron and depends on site traffic. If disabled, cache refresh will rely more on manual or interactive actions.', 'links-manager') . '</div>';
    echo '</div>';

    echo '<div style="' . esc_attr($settingsCardStyle) . '">';
    echo '<div style="font-weight:600; margin-bottom:6px;">' . esc_html__('REST Rebuild Readiness', 'links-manager') . '</div>';
    echo '<table class="widefat striped" style="margin-top:8px; max-width:760px;">';
    echo '<tbody>';
    echo '<tr><th style="width:260px;">' . esc_html__('Current REST job status', 'links-manager') . '</th><td>' . esc_html(ucfirst((string)$restStatus)) . '</td></tr>';
    echo '<tr><th>' . esc_html__('REST progress', 'links-manager') . '</th><td>' . esc_html(number_format($restProcessed) . ' / ' . number_format($restTotal) . ' posts') . '</td></tr>';
    echo '<tr><th>' . esc_html__('REST rows collected', 'links-manager') . '</th><td>' . esc_html(number_format($restRows)) . '</td></tr>';
    echo '<tr><th>' . esc_html__('REST state updated at', 'links-manager') . '</th><td>' . esc_html($restUpdatedAt) . '</td></tr>';
    echo '<tr><th>' . esc_html__('Main cache rows (any/all)', 'links-manager') . '</th><td>' . esc_html(number_format($mainCacheRowsCount)) . '</td></tr>';
    echo '<tr><th>' . esc_html__('Backup cache rows (any/all)', 'links-manager') . '</th><td>' . esc_html(number_format($backupCacheRowsCount)) . '</td></tr>';
    echo '<tr><th>' . esc_html__('Effective cache rows (main/backup)', 'links-manager') . '</th><td>' . esc_html(number_format($effectiveCacheRowsCount)) . '</td></tr>';
    if ($usingBackupFallback) {
      echo '<tr><th>' . esc_html__('Cache source', 'links-manager') . '</th><td><span class="lm-pill warn">' . esc_html__('Using backup cache fallback', 'links-manager') . '</span></td></tr>';
    }
    echo '<tr><th>' . esc_html__('Last main cache scan', 'links-manager') . '</th><td>' . esc_html($lastScanLabel) . '</td></tr>';
    echo '<tr><th>' . esc_html__('Last full rebuild', 'links-manager') . '</th><td>' . esc_html($lastFullLabel) . '</td></tr>';
    echo '</tbody>';
    echo '</table>';
    echo '<div class="lm-small" style="margin-top:6px;"><strong>' . esc_html__('Recommendation:', 'links-manager') . '</strong> <span class="lm-pill ' . esc_attr($restRecommendationClass) . '">' . esc_html($restRecommendation) . '</span></div>';
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
    echo '<div class="lm-settings-two-col lm-settings-two-col-stack">';
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
    echo '<span class="lm-small" style="display:block; margin-top:4px;">Auto safety limit for this server: ' . esc_html((string)$runtimeMaxBatch) . ' pages/request.</span>';
    echo '</p>';

    echo '<p style="margin:0 0 6px;">';
    echo '<label class="lm-small" style="' . esc_attr($settingsLabelStyle) . '">REST response cache TTL (seconds):</label>';
    echo '<input type="number" name="lm_rest_response_cache_ttl_sec" min="30" max="600" value="' . esc_attr((string)($settings['rest_response_cache_ttl_sec'] ?? '90')) . '" style="width:90px;" />';
    echo '<span class="lm-small" style="margin-left:8px;">Used by REST list endpoint response cache. Recommended: 60-180s.</span>';
    echo '</p>';
    echo '</div>';

    $restBatchDefault = (int)($settings['crawl_post_batch'] ?? (string)self::CRAWL_POST_BATCH);
    if ($restBatchDefault < 20) $restBatchDefault = 20;
    if ($restBatchDefault > $runtimeMaxBatch) $restBatchDefault = (int)$runtimeMaxBatch;
    echo '<hr style="margin:14px 0;"/>';
    echo '<div id="lm-rest-rebuild-controls" style="margin:0 0 12px; padding:12px; border:1px solid #dcdcde; background:#fff; border-radius:4px;">';
    echo '<div style="font-weight:600; margin-bottom:6px;">' . esc_html__('Background Rebuild (REST)', 'links-manager') . '</div>';
    echo '<div class="lm-small" style="margin-bottom:10px;">' . esc_html__('Runs rebuild in small batches to reduce timeout risk on large sites.', 'links-manager') . '</div>';
    echo '<p style="margin:0 0 10px;">';
    echo '<label class="lm-small" style="' . esc_attr($settingsLabelStyle) . '">' . esc_html__('Pages per background step:', 'links-manager') . '</label>';
    echo '<input type="number" data-lm-rest-rebuild-batch min="20" max="' . esc_attr((string)$runtimeMaxBatch) . '" value="' . esc_attr((string)$restBatchDefault) . '" style="width:90px;" />';
    echo '<span class="lm-small" style="margin-left:8px;">' . sprintf(esc_html__('Allowed range: 20-%s.', 'links-manager'), esc_html((string)$runtimeMaxBatch)) . '</span>';
    echo '</p>';
    echo '<p style="margin:0 0 10px;">';
    echo '<button type="button" class="button button-secondary" data-lm-rest-rebuild-run>' . esc_html__('Start / Continue REST Rebuild', 'links-manager') . '</button>';
    echo ' <button type="button" class="button" data-lm-rest-rebuild-refresh>' . esc_html__('Refresh Status', 'links-manager') . '</button>';
    echo '</p>';
    echo '<div data-lm-rest-rebuild-status class="lm-small" style="font-weight:600; margin-bottom:8px;" aria-live="polite" aria-atomic="true">' . esc_html__('Checking status...', 'links-manager') . '</div>';
    echo '<div style="width:100%; max-width:620px; height:12px; border:1px solid #c3c4c7; border-radius:999px; background:#f0f0f1; overflow:hidden;">';
    echo '<div data-lm-rest-rebuild-bar role="progressbar" aria-label="REST rebuild progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" style="height:100%; width:0%; background:#2271b1; transition:width .2s ease;"></div>';
    echo '</div>';
    echo '<div data-lm-rest-rebuild-progress class="lm-small" style="margin-top:6px;" aria-live="polite" aria-atomic="true">' . esc_html__('No active rebuild job.', 'links-manager') . '</div>';
    echo '<div data-lm-rest-rebuild-meta class="lm-small" style="margin-top:4px; color:#646970;" aria-live="polite" aria-atomic="true">' . esc_html__('Status: idle | Rows: 0 | Batch: - | Updated: -', 'links-manager') . '</div>';
    echo '</div>';

    echo '<div data-lm-rest-self-test-root style="margin:0 0 12px; padding:12px; border:1px solid #dcdcde; background:#fff; border-radius:4px;">';
    echo '<div style="font-weight:600; margin-bottom:6px;">' . esc_html__('Automated REST Self-Test', 'links-manager') . '</div>';
    echo '<div class="lm-small" style="margin-bottom:10px;">' . esc_html__('Run built-in diagnostics for routes, cache/transient I/O, and lock cycle. Use Deep Test for endpoint latency sampling (manual).', 'links-manager') . '</div>';
    echo '<p style="margin:0 0 10px;">';
    echo '<button type="button" class="button button-secondary" data-lm-rest-self-test-run>' . esc_html__('Run Self-Test', 'links-manager') . '</button>';
    echo ' <button type="button" class="button" data-lm-rest-self-test-deep-run>' . esc_html__('Run Deep Test', 'links-manager') . '</button>';
    echo ' <span class="lm-small" data-lm-rest-self-test-status style="margin-left:8px; font-weight:600;" aria-live="polite" aria-atomic="true">' . esc_html__('Idle', 'links-manager') . '</span>';
    echo '</p>';
    echo '<p style="margin:0 0 10px;">';
    echo '<label class="lm-small" style="display:inline-block; margin-right:10px;">Deep iterations: <input type="number" data-lm-rest-self-test-deep-iterations min="1" max="5" value="3" style="width:70px;" /></label>';
    echo '<label class="lm-small" style="display:inline-block;">Deep per page: <input type="number" data-lm-rest-self-test-deep-per-page min="5" max="50" value="20" style="width:70px;" /></label>';
    echo '</p>';
    echo '<div class="lm-small" style="margin:0 0 8px; color:#646970;">Deep Test runs one cold warm-up (not counted), then measures warm runs and reports avg/p50/p95/max plus cache hit/miss. It only runs when cache is ready.</div>';
    echo '<div data-lm-rest-self-test-summary class="lm-small" style="margin:0 0 8px;">' . esc_html__('No test run yet.', 'links-manager') . '</div>';
    echo '<div data-lm-rest-self-test-modes class="lm-small" style="margin:0 0 8px; color:#1d2327;"><span style="color:#646970;">Execution mode stats will appear after test run.</span></div>';
    echo '<div class="lm-table-wrap lm-summary-table-wrap" style="max-width:960px;">';
    echo '<table class="widefat striped lm-table">';
    echo '<thead><tr>';
    echo '<th style="width:260px;">Check</th>';
    echo '<th style="width:90px;">Status</th>';
    echo '<th style="width:90px;">Duration</th>';
    echo '<th>Details</th>';
    echo '</tr></thead><tbody data-lm-rest-self-test-results>';
    echo '<tr><td colspan="4">' . esc_html__('No results yet. Click Run Self-Test.', 'links-manager') . '</td></tr>';
    echo '</tbody></table>';
    echo '</div>';
    echo '</div>';
    }
    if ($activeTab === 'data') {
      echo '<h2 style="margin-top:0;">' . esc_html__('Data Cleanup Settings', 'links-manager') . '</h2>';
      echo '<div class="lm-small">' . esc_html__('Automatically remove old audit logs during daily maintenance runs.', 'links-manager') . '</div>';
      echo '<div class="lm-settings-two-col" style="margin-top:8px;">';
      echo '<p style="margin:0 0 10px;">';
      echo '<label class="lm-small" style="' . esc_attr($settingsLabelStyle) . '" for="lm_audit_retention_days">' . esc_html__('Keep audit logs for (days):', 'links-manager') . '</label>';
      echo '<input id="lm_audit_retention_days" type="number" name="lm_audit_retention_days" min="30" max="3650" value="' . esc_attr((string)($settings['audit_retention_days'] ?? (string)self::AUDIT_RETENTION_DAYS)) . '" style="width:90px;" />';
      echo '</p>';
      echo '</div>';
      echo '<div class="lm-small" style="margin-top:6px;">' . sprintf(esc_html__('Range: 30-3650 days. Default: %s days.', 'links-manager'), esc_html((string)self::AUDIT_RETENTION_DAYS)) . '</div>';

      echo '<hr style="margin:14px 0;"/>';
      echo '<h2 style="margin-top:0;">' . esc_html__('Link Status Thresholds', 'links-manager') . '</h2>';
      echo '<div class="lm-small">' . esc_html__('Define status ranges for inbound, internal outbound, and external outbound links.', 'links-manager') . '</div>';
      echo '<div class="lm-small" style="margin-top:6px;">' . esc_html__('Tip: keep values ascending from left to right so status labels stay consistent.', 'links-manager') . '</div>';
      echo '<p style="margin:12px 0 8px; font-weight:600;">Inbound Link Thresholds</p>';
      echo '<div class="lm-small">Used in Pages Link status: Orphaned, Low, Standard, and Excellent.</div>';
      echo '<div style="margin-top:8px;">';
      echo '<div style="' . esc_attr($settingsCardStyle) . '">';
      echo '<div class="lm-small" style="margin:0 0 8px;">These values control the status label for inbound links per page.</div>';
      echo '<div class="lm-settings-two-col">';
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
      echo '</div>';

      echo '<p style="margin:12px 0 8px; font-weight:600;">Internal Outbound Thresholds (None / Low / Optimal / Excessive)</p>';
      echo '<div style="' . esc_attr($settingsCardStyle) . '">';
      echo '<div class="lm-small" style="margin:0 0 8px;">These values control status labels for outbound links to your own pages.</div>';
      echo '<div class="lm-settings-two-col">';
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
      echo '</div>';

      echo '<p style="margin:12px 0 8px; font-weight:600;">External Outbound Thresholds (None / Low / Optimal / Excessive)</p>';
      echo '<div style="' . esc_attr($settingsCardStyle) . '">';
      echo '<div class="lm-small" style="margin:0 0 8px;">These values control status labels for outbound links to external websites.</div>';
      echo '<div class="lm-settings-two-col">';
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
      echo '</div>';

      echo '<hr style="margin:14px 0;"/>';
      echo '<h2 style="margin-top:0;">' . esc_html__('Anchor Quality - Weak Phrase Rules', 'links-manager') . '</h2>';
      echo '<div class="lm-small">' . esc_html__('List words or phrases considered weak anchor text. Use one phrase per line or separate with commas. Empty anchor text is always Bad.', 'links-manager') . '</div>';
      echo '<div style="margin-top:8px;">';
      echo '<textarea name="lm_weak_anchor_patterns" rows="12" style="width:100%; max-width:760px;">' . esc_textarea((string)($settings['weak_anchor_patterns'] ?? '')) . '</textarea>';
      echo '</div>';
      echo '<div class="lm-small" style="margin-top:6px;">' . esc_html__('Even if this list is empty, length rules and empty-anchor checks still apply.', 'links-manager') . '</div>';
    }

    echo '<hr style="margin:14px 0;"/>';
    echo '<div class="lm-settings-actions">';
    echo '<div class="lm-settings-actions-primary">';
    submit_button(__('Save Settings', 'links-manager'), 'primary', 'submit', false);
    echo '<div class="lm-small lm-help-tip">' . esc_html__('Save your current tab settings.', 'links-manager') . '</div>';
    echo '</div>';

    if ($activeTab === 'general') {
      echo '<div class="lm-settings-actions-card" style="' . esc_attr($settingsCardCompactStyle) . '">';
      echo '<div class="lm-settings-actions-title">' . esc_html__('General Actions', 'links-manager') . '</div>';
      echo '<div class="lm-small lm-settings-actions-note">' . esc_html__('Restore General tab configuration to safe defaults.', 'links-manager') . '</div>';
      submit_button(__('Reset General Settings', 'links-manager'), 'secondary', 'lm_reset_general_settings', false, [
        'value' => '1',
        'onclick' => "return confirm('" . esc_js(__('Reset General settings to defaults?', 'links-manager')) . "');",
      ]);
      echo '</div>';
    }

    if ($activeTab === 'performance') {
      echo '<div class="lm-settings-actions-card" style="' . esc_attr($settingsCardCompactStyle) . '">';
      echo '<div class="lm-settings-actions-title">' . esc_html__('Quick Performance Actions', 'links-manager') . '</div>';
      echo '<div class="lm-small lm-settings-actions-note">' . esc_html__('Use these presets to apply performance-focused defaults instantly.', 'links-manager') . '</div>';
      submit_button(__('Optimize for Speed', 'links-manager'), 'secondary', 'lm_reset_performance_defaults', false, ['value' => '1']);
      submit_button(__('Enable Low Memory Mode', 'links-manager'), 'secondary', 'lm_apply_low_memory_profile', false, ['value' => '1', 'style' => 'margin-left:8px;']);
      submit_button(__('Reset Performance Settings', 'links-manager'), 'secondary', 'lm_reset_performance_settings', false, [
        'value' => '1',
        'style' => 'margin-left:8px;',
        'onclick' => "return confirm('" . esc_js(__('Reset Performance settings to defaults?', 'links-manager')) . "');",
      ]);
      echo '<div class="lm-small lm-settings-actions-subtitle">' . esc_html__('Advanced maintenance:', 'links-manager') . '</div>';
      submit_button(__('Rebuild All Cache', 'links-manager'), 'secondary', 'lm_global_rebuild_cache', false, ['value' => '1']);
      echo '<div class="lm-small lm-help-tip">' . esc_html__('Use this only when cache data seems out of date.', 'links-manager') . '</div>';
      echo '</div>';
    }

    if ($activeTab === 'data') {
      echo '<div class="lm-settings-actions-card" style="' . esc_attr($settingsCardCompactStyle) . '">';
      echo '<div class="lm-settings-actions-title">' . esc_html__('Weak Phrase Actions', 'links-manager') . '</div>';
      echo '<div class="lm-small lm-settings-actions-note">' . esc_html__('Manage the weak phrase list used by anchor quality checks.', 'links-manager') . '</div>';
      submit_button(__('Reset Phrases to Defaults', 'links-manager'), 'secondary', 'lm_restore_weak_anchor_patterns', false, ['value' => '1']);
      submit_button(__('Reset Data Settings', 'links-manager'), 'secondary', 'lm_reset_data_settings', false, [
        'value' => '1',
        'style' => 'margin-left:8px;',
        'onclick' => "return confirm('" . esc_js(__('Reset Data settings to defaults?', 'links-manager')) . "');",
      ]);
      echo '<div class="lm-danger-zone">';
      echo '<div class="lm-small lm-danger-text"><strong>Danger zone:</strong> this action removes all phrases in the editor field.</div>';
      submit_button(__('Delete All Phrases', 'links-manager'), 'delete', 'lm_clear_weak_anchor_patterns', false, [
        'value' => '1',
        'onclick' => "return confirm('" . esc_js(__('Delete all weak phrases from this field? This cannot be undone.', 'links-manager')) . "');",
      ]);
      echo '<div class="lm-small lm-help-tip">' . esc_html__('Tip: click "Reset Phrases to Defaults" if you only want to restore the default list.', 'links-manager') . '</div>';
      echo '</div>';
      echo '</div>';
    }
    echo '</div>';
    echo '</form>';
    echo '</div>';
    echo '</div>';
  }

  private function render_settings_diagnostic_box() {
    if (!$this->can_access_debug_diagnostics()) {
      return;
    }

    $diag = $this->get_last_fatal_diagnostic();
    if (empty($diag)) {
      return;
    }

    echo '<div class="lm-card lm-card-full" style="border-left:4px solid #d63638; margin-bottom:14px;">';
    echo '<h2 style="margin-top:0;">' . esc_html__('Last Fatal Diagnostic', 'links-manager') . '</h2>';
    echo '<div class="lm-small" style="margin-bottom:10px;">Captured automatically when a fatal error occurs on Links Manager request. Share this data to debug root cause.</div>';

    $capturedAt = isset($diag['captured_at']) ? (string)$diag['captured_at'] : '';
    $errorType = isset($diag['error_type']) ? (int)$diag['error_type'] : 0;
    $message = isset($diag['message']) ? (string)$diag['message'] : '';
    $file = isset($diag['file']) ? (string)$diag['file'] : '';
    $line = isset($diag['line']) ? (int)$diag['line'] : 0;
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
    submit_button(__('Clear Diagnostic', 'links-manager'), 'secondary', 'submit', false);
    echo '</form>';
    echo '</div>';
  }

  private function render_settings_runtime_profile_box() {
    if (!$this->can_access_debug_diagnostics()) {
      return;
    }

    $profile = $this->get_last_runtime_profile();
    if (empty($profile)) {
      return;
    }

    $entries = isset($profile['entries']) && is_array($profile['entries']) ? $profile['entries'] : [];
    if (empty($entries)) {
      return;
    }

    $totalElapsedMs = 0.0;
    foreach ($entries as $entry) {
      $totalElapsedMs += isset($entry['elapsed_ms']) ? (float)$entry['elapsed_ms'] : 0.0;
    }

    $entries = array_slice($entries, 0, 50);

    $slowestEntries = $entries;
    usort($slowestEntries, function($a, $b) {
      $aMs = isset($a['elapsed_ms']) ? (float)$a['elapsed_ms'] : 0.0;
      $bMs = isset($b['elapsed_ms']) ? (float)$b['elapsed_ms'] : 0.0;
      return $bMs <=> $aMs;
    });
    $slowestEntries = array_slice($slowestEntries, 0, 3);

    echo '<div class="lm-card lm-card-full" style="border-left:4px solid #2271b1; margin-bottom:14px;">';
    echo '<h2 style="margin-top:0;">' . esc_html__('Last Runtime Profile', 'links-manager') . '</h2>';
    echo '<div class="lm-small" style="margin-bottom:10px;">Timing profile for the latest profiled request. Enable with <code>?lm_profile=1</code> or WP_DEBUG.</div>';
    echo '<table class="widefat striped" style="max-width:100%; margin-bottom:10px;">';
    echo '<tbody>';
    echo '<tr><th style="width:220px;">Captured At</th><td>' . esc_html((string)($profile['captured_at'] ?? '')) . '</td></tr>';
    echo '<tr><th>Page</th><td>' . esc_html((string)($profile['request_page'] ?? '')) . '</td></tr>';
    echo '<tr><th>Action</th><td>' . esc_html((string)($profile['request_action'] ?? '')) . '</td></tr>';
    echo '<tr><th>Request URI</th><td><code>' . esc_html((string)($profile['request_uri'] ?? '')) . '</code></td></tr>';
    echo '<tr><th>Total Elapsed</th><td>' . esc_html(number_format($totalElapsedMs, 2)) . ' ms</td></tr>';
    echo '<tr><th>Total Entries</th><td>' . esc_html((string)count($entries)) . '</td></tr>';
    echo '</tbody>';
    echo '</table>';

    echo '<div style="margin:0 0 8px; font-weight:600;">Slowest Phases</div>';
    echo '<table class="widefat striped" style="max-width:100%; margin-bottom:10px;">';
    echo '<thead><tr><th>Phase</th><th style="width:120px;">Elapsed</th><th>Meta</th></tr></thead>';
    echo '<tbody>';
    foreach ($slowestEntries as $entry) {
      $phase = isset($entry['name']) ? (string)$entry['name'] : '';
      $elapsedMs = isset($entry['elapsed_ms']) ? number_format((float)$entry['elapsed_ms'], 2) : '0.00';
      $meta = $this->format_debug_meta_for_display(isset($entry['meta']) ? $entry['meta'] : []);
      echo '<tr>';
      echo '<td><code>' . esc_html($phase) . '</code></td>';
      echo '<td>' . esc_html($elapsedMs) . ' ms</td>';
      echo '<td><code>' . esc_html($meta) . '</code></td>';
      echo '</tr>';
    }
    echo '</tbody>';
    echo '</table>';

    echo '<details>';
    echo '<summary style="cursor:pointer; font-weight:600;">Show Full Profile Entries</summary>';
    echo '<div style="margin-top:8px;">';
    echo '<table class="widefat striped">';
    echo '<thead><tr><th>Phase</th><th style="width:120px;">Elapsed</th><th>Meta</th></tr></thead>';
    echo '<tbody>';
    foreach ($entries as $entry) {
      $phase = isset($entry['name']) ? (string)$entry['name'] : '';
      $elapsedMs = isset($entry['elapsed_ms']) ? (string)$entry['elapsed_ms'] : '0';
      $meta = $this->format_debug_meta_for_display(isset($entry['meta']) ? $entry['meta'] : []);
      echo '<tr>';
      echo '<td><code>' . esc_html($phase) . '</code></td>';
      echo '<td>' . esc_html($elapsedMs) . '</td>';
      echo '<td><code>' . esc_html($meta) . '</code></td>';
      echo '</tr>';
    }
    echo '</tbody>';
    echo '</table>';
    echo '</div>';
    echo '</details>';
    echo '</div>';
  }

  private function format_debug_meta_for_display($meta) {
    if (is_array($meta) && isset($meta['crawl_stats']) && is_array($meta['crawl_stats'])) {
      $crawlStats = $meta['crawl_stats'];
      $meta['crawl_stats'] = [
        'posts_seen' => (int)($crawlStats['posts_seen'] ?? 0),
        'parse_calls_total' => (int)($crawlStats['parse_calls_total'] ?? 0),
        'parse_links_total' => (int)($crawlStats['parse_links_total'] ?? 0),
        'parse_ms_total' => round((float)($crawlStats['parse_ms_total'] ?? 0), 2),
      ];
    }

    $encoded = wp_json_encode($meta);
    if (!is_string($encoded) || $encoded === '') {
      return '';
    }

    if (strlen($encoded) > 2000) {
      $encoded = substr($encoded, 0, 2000) . '...';
    }

    return $encoded;
  }

  private function sanitize_allowed_roles($roles) {
    $roles = is_array($roles) ? $roles : [];
    $validRoles = array_keys($this->get_all_roles_map());
    $sanitized = [];

    foreach ($roles as $role) {
      $role = sanitize_key((string)$role);
      if ($role !== '' && in_array($role, $validRoles, true)) {
        $sanitized[] = $role;
      }
    }

    $sanitized[] = 'administrator';
    $sanitized = array_values(array_unique($sanitized));

    return $sanitized;
  }

  private function sanitize_int_list($values) {
    $values = is_array($values) ? $values : [];
    $sanitized = [];

    foreach ($values as $value) {
      $value = (int)$value;
      if ($value > 0) {
        $sanitized[] = $value;
      }
    }

    $sanitized = array_values(array_unique($sanitized));
    sort($sanitized, SORT_NUMERIC);

    return $sanitized;
  }

  private function normalize_multiline_textarea($value) {
    $value = (string)$value;
    if ($value === '') {
      return '';
    }

    $lines = preg_split("/\r\n|\r|\n/", $value);
    $normalized = [];

    foreach ($lines as $line) {
      $line = sanitize_text_field(trim((string)$line));
      if ($line !== '') {
        $normalized[] = $line;
      }
    }

    $normalized = array_values(array_unique($normalized));

    return implode("\n", $normalized);
  }

  private function sanitize_inbound_thresholds($source) {
    $source = is_array($source) ? $source : [];
    $orphanMax = isset($source['lm_inbound_orphan_max']) ? (int)$source['lm_inbound_orphan_max'] : 0;
    $lowMax = isset($source['lm_inbound_low_max']) ? (int)$source['lm_inbound_low_max'] : 5;
    $standardMax = isset($source['lm_inbound_standard_max']) ? (int)$source['lm_inbound_standard_max'] : 10;

    $orphanMax = max(0, min(1000000, $orphanMax));
    $lowMax = max($orphanMax, min(1000000, $lowMax));
    $standardMax = max($lowMax, min(1000000, $standardMax));

    return [
      'orphan_max' => $orphanMax,
      'low_max' => $lowMax,
      'standard_max' => $standardMax,
    ];
  }

  private function sanitize_outbound_thresholds($source, $prefix) {
    $source = is_array($source) ? $source : [];
    $prefix = sanitize_key((string)$prefix);

    $noneKey = 'lm_' . $prefix . '_outbound_none_max';
    $lowKey = 'lm_' . $prefix . '_outbound_low_max';
    $optimalKey = 'lm_' . $prefix . '_outbound_optimal_max';

    $noneMax = isset($source[$noneKey]) ? (int)$source[$noneKey] : 0;
    $lowMax = isset($source[$lowKey]) ? (int)$source[$lowKey] : 5;
    $optimalMax = isset($source[$optimalKey]) ? (int)$source[$optimalKey] : 10;

    $noneMax = max(0, min(1000000, $noneMax));
    $lowMax = max($noneMax, min(1000000, $lowMax));
    $optimalMax = max($lowMax, min(1000000, $optimalMax));

    return [
      'none_max' => $noneMax,
      'low_max' => $lowMax,
      'optimal_max' => $optimalMax,
    ];
  }

  public function handle_save_settings() {
    if (!current_user_can('manage_options')) wp_die($this->unauthorized_message());

    $nonce = isset($_POST[self::NONCE_NAME]) ? sanitize_text_field($_POST[self::NONCE_NAME]) : '';
    if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) wp_die($this->invalid_nonce_message());

    $settings = $this->get_settings();
    $availablePostTypes = $this->get_default_scan_post_types();
    $activeTab = isset($_POST['lm_active_tab']) ? sanitize_key((string)$_POST['lm_active_tab']) : 'general';
    if (!in_array($activeTab, ['general', 'performance', 'data'], true)) {
      $activeTab = 'general';
    }

    $resetGeneralSettings = isset($_POST['lm_reset_general_settings']);
    $resetPerformanceSettings = isset($_POST['lm_reset_performance_settings']);
    $resetDataSettings = isset($_POST['lm_reset_data_settings']);

    $allowedRolesRaw = isset($_POST['lm_allowed_roles']) ? (array)wp_unslash($_POST['lm_allowed_roles']) : [];
    $settings['allowed_roles'] = $this->sanitize_allowed_roles($allowedRolesRaw);
    $settings['debug_mode'] = isset($_POST['lm_debug_mode']) ? '1' : '0';

    $scanPostTypesRaw = isset($_POST['lm_scan_post_types']) ? (array)wp_unslash($_POST['lm_scan_post_types']) : [];
    $settings['scan_post_types'] = $this->sanitize_scan_post_types($scanPostTypesRaw);

    $scanSourceTypesRaw = isset($_POST['lm_scan_source_types']) ? (array)wp_unslash($_POST['lm_scan_source_types']) : [];
    $settings['scan_source_types'] = $this->sanitize_scan_source_types($scanSourceTypesRaw);

    $scanValueTypesRaw = isset($_POST['lm_scan_value_types']) ? (array)wp_unslash($_POST['lm_scan_value_types']) : [];
    $settings['scan_value_types'] = $this->sanitize_scan_value_types($scanValueTypesRaw);

    $scanWpmlLangsRaw = isset($_POST['lm_scan_wpml_langs']) ? (array)wp_unslash($_POST['lm_scan_wpml_langs']) : [];
    $settings['scan_wpml_langs'] = $this->sanitize_scan_wpml_langs($scanWpmlLangsRaw);

    $settings['scan_post_category_ids'] = isset($_POST['lm_scan_post_category_ids']) ? $this->sanitize_int_list((array)wp_unslash($_POST['lm_scan_post_category_ids'])) : [];
    $settings['scan_post_tag_ids'] = isset($_POST['lm_scan_post_tag_ids']) ? $this->sanitize_int_list((array)wp_unslash($_POST['lm_scan_post_tag_ids'])) : [];
    $settings['scan_author_ids'] = isset($_POST['lm_scan_author_ids']) ? $this->sanitize_int_list((array)wp_unslash($_POST['lm_scan_author_ids'])) : [];

    $scanModifiedWithinDays = isset($_POST['lm_scan_modified_within_days']) ? intval($_POST['lm_scan_modified_within_days']) : 0;
    if ($scanModifiedWithinDays < 0) $scanModifiedWithinDays = 0;
    if ($scanModifiedWithinDays > 3650) $scanModifiedWithinDays = 3650;
    $settings['scan_modified_within_days'] = (string)$scanModifiedWithinDays;

    $scanExcludePatterns = isset($_POST['lm_scan_exclude_url_patterns']) ? (string)wp_unslash($_POST['lm_scan_exclude_url_patterns']) : '';
    $settings['scan_exclude_url_patterns'] = $this->normalize_multiline_textarea($scanExcludePatterns);

    $maxPostsPerRebuild = isset($_POST['lm_max_posts_per_rebuild']) ? intval($_POST['lm_max_posts_per_rebuild']) : 0;
    if ($maxPostsPerRebuild < 0) $maxPostsPerRebuild = 0;
    if ($maxPostsPerRebuild > 50000) $maxPostsPerRebuild = 50000;
    $settings['max_posts_per_rebuild'] = (string)$maxPostsPerRebuild;

    $statsRefreshValue = isset($_POST['lm_stats_snapshot_ttl_value']) ? intval($_POST['lm_stats_snapshot_ttl_value']) : 1;
    if ($statsRefreshValue < 1) $statsRefreshValue = 1;
    if ($statsRefreshValue > 12) $statsRefreshValue = 12;
    $statsRefreshPeriod = isset($_POST['lm_stats_snapshot_ttl_period']) ? sanitize_key((string)$_POST['lm_stats_snapshot_ttl_period']) : 'week';
    if (!in_array($statsRefreshPeriod, ['hour', 'day', 'week', 'month'], true)) {
      $statsRefreshPeriod = 'week';
    }
    $settings['stats_snapshot_ttl_min'] = (string)$this->convert_stats_refresh_to_minutes($statsRefreshValue, $statsRefreshPeriod);

    $cacheRebuildMode = isset($_POST['lm_cache_rebuild_mode']) ? sanitize_key((string)$_POST['lm_cache_rebuild_mode']) : 'incremental';
    if (!in_array($cacheRebuildMode, ['incremental', 'full'], true)) {
      $cacheRebuildMode = 'incremental';
    }
    $settings['cache_rebuild_mode'] = $cacheRebuildMode;

    $crawlBatch = isset($_POST['lm_crawl_post_batch']) ? intval($_POST['lm_crawl_post_batch']) : self::CRAWL_POST_BATCH;
    $runtimeMaxBatch = $this->get_runtime_max_crawl_batch();
    if ($crawlBatch < 20) $crawlBatch = 20;
    if ($crawlBatch > $runtimeMaxBatch) $crawlBatch = $runtimeMaxBatch;
    $settings['crawl_post_batch'] = (string)$crawlBatch;

    $restCacheTtl = isset($_POST['lm_rest_response_cache_ttl_sec']) ? intval($_POST['lm_rest_response_cache_ttl_sec']) : 90;
    if ($restCacheTtl < 30) $restCacheTtl = 30;
    if ($restCacheTtl > 600) $restCacheTtl = 600;
    $settings['rest_response_cache_ttl_sec'] = (string)$restCacheTtl;

    $inboundThresholds = $this->sanitize_inbound_thresholds($_POST);
    $settings['inbound_orphan_max'] = (string)$inboundThresholds['orphan_max'];
    $settings['inbound_low_max'] = (string)$inboundThresholds['low_max'];
    $settings['inbound_standard_max'] = (string)$inboundThresholds['standard_max'];

    $internalOutboundThresholds = $this->sanitize_outbound_thresholds($_POST, 'internal');
    $settings['internal_outbound_none_max'] = (string)$internalOutboundThresholds['none_max'];
    $settings['internal_outbound_low_max'] = (string)$internalOutboundThresholds['low_max'];
    $settings['internal_outbound_optimal_max'] = (string)$internalOutboundThresholds['optimal_max'];

    $externalOutboundThresholds = $this->sanitize_outbound_thresholds($_POST, 'external');
    $settings['external_outbound_none_max'] = (string)$externalOutboundThresholds['none_max'];
    $settings['external_outbound_low_max'] = (string)$externalOutboundThresholds['low_max'];
    $settings['external_outbound_optimal_max'] = (string)$externalOutboundThresholds['optimal_max'];

    $resetPerformanceDefaults = isset($_POST['lm_reset_performance_defaults']);
    if ($resetPerformanceDefaults) {
      $settings = array_merge($settings, $this->get_recommended_performance_settings());
      $settings['performance_preset'] = 'speed';
    }

    $applyLowMemoryProfile = isset($_POST['lm_apply_low_memory_profile']);
    if ($applyLowMemoryProfile) {
      $settings = array_merge($settings, $this->get_low_memory_performance_settings());
      $settings['performance_preset'] = 'low_memory';
    }

    if ($activeTab === 'performance' && !$resetPerformanceDefaults && !$applyLowMemoryProfile) {
      $settings['performance_preset'] = 'custom';
    }

    if ($resetGeneralSettings) {
      $settings['allowed_roles'] = ['administrator'];
      $settings['debug_mode'] = '0';
    }

    if ($resetPerformanceSettings) {
      $defaultBatch = min(self::CRAWL_POST_BATCH, $this->get_runtime_max_crawl_batch());
      if ($defaultBatch < 20) $defaultBatch = 20;

      $settings['stats_snapshot_ttl_min'] = (string)(int)(self::STATS_SNAPSHOT_TTL / MINUTE_IN_SECONDS);
      $settings['rest_response_cache_ttl_sec'] = '90';
      $settings['cache_rebuild_mode'] = 'incremental';
      $settings['crawl_post_batch'] = (string)$defaultBatch;
      $settings['scan_post_types'] = $this->get_default_scan_post_types($availablePostTypes);
      $settings['scan_source_types'] = $this->get_default_scan_source_types();
      $settings['scan_value_types'] = $this->get_default_scan_value_types();
      $settings['scan_wpml_langs'] = $this->get_default_scan_wpml_langs();
      $settings['scan_post_category_ids'] = [];
      $settings['scan_post_tag_ids'] = [];
      $settings['scan_author_ids'] = [];
      $settings['scan_modified_within_days'] = '0';
      $settings['scan_exclude_url_patterns'] = implode("\n", $this->get_default_scan_exclude_url_patterns());
      $settings['max_posts_per_rebuild'] = '0';
      $settings['performance_preset'] = 'custom';
    }

    if ($resetDataSettings) {
      $settings['audit_retention_days'] = (string)self::AUDIT_RETENTION_DAYS;
      $settings['inbound_orphan_max'] = '0';
      $settings['inbound_low_max'] = '5';
      $settings['inbound_standard_max'] = '10';
      $settings['internal_outbound_none_max'] = '0';
      $settings['internal_outbound_low_max'] = '5';
      $settings['internal_outbound_optimal_max'] = '10';
      $settings['external_outbound_none_max'] = '0';
      $settings['external_outbound_low_max'] = '5';
      $settings['external_outbound_optimal_max'] = '10';
      $settings['weak_anchor_patterns'] = implode("\n", $this->get_default_weak_anchor_patterns());
      $this->weak_anchor_patterns_cache = $this->get_default_weak_anchor_patterns();
    }

    $globalRebuildCache = isset($_POST['lm_global_rebuild_cache']);

    $auditRetentionDays = isset($_POST['lm_audit_retention_days']) ? intval($_POST['lm_audit_retention_days']) : (int)($settings['audit_retention_days'] ?? self::AUDIT_RETENTION_DAYS);
    if ($auditRetentionDays < 30) $auditRetentionDays = 30;
    if ($auditRetentionDays > 3650) $auditRetentionDays = 3650;
    $settings['audit_retention_days'] = (string)$auditRetentionDays;

    $weakPatternsRaw = isset($_POST['lm_weak_anchor_patterns']) ? (string)wp_unslash($_POST['lm_weak_anchor_patterns']) : (string)($settings['weak_anchor_patterns'] ?? '');
    $normalizedWeakPatterns = $this->normalize_weak_anchor_patterns($weakPatternsRaw);
    $restoredDefaults = isset($_POST['lm_restore_weak_anchor_patterns']);
    $clearedAll = isset($_POST['lm_clear_weak_anchor_patterns']);
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
      $this->clear_main_cache_all();
      $this->schedule_background_rebuild('any', 'all', 2);
    }

    $savedMsg = __('Settings saved.', 'links-manager');
    if ($clearedAll) {
      $savedMsg = __('Settings saved. All weak phrases cleared.', 'links-manager');
    }
    if ($restoredDefaults) {
      $savedMsg = __('Settings saved. Default weak phrases restored.', 'links-manager');
    }
    if ($resetPerformanceDefaults) {
      $savedMsg = __('Settings saved. Recommended speed settings applied.', 'links-manager');
    }
    if ($applyLowMemoryProfile) {
      $savedMsg = __('Settings saved. Safe low-memory settings applied.', 'links-manager');
    }
    if ($resetGeneralSettings) {
      $savedMsg = __('Settings reset. General tab restored to defaults.', 'links-manager');
    }
    if ($resetPerformanceSettings) {
      $savedMsg = __('Settings reset. Performance tab restored to defaults.', 'links-manager');
    }
    if ($resetDataSettings) {
      $savedMsg = __('Settings reset. Data tab restored to defaults.', 'links-manager');
    }
    if ($globalRebuildCache) {
      $savedMsg = __('Settings saved. Main cache invalidated and background incremental rebuild queued.', 'links-manager');
    }
    wp_safe_redirect(admin_url('admin.php?page=links-manager-settings&lm_tab=' . rawurlencode($activeTab) . '&lm_msg=' . rawurlencode($savedMsg)));
    exit;
  }

  public function handle_clear_diagnostics() {
    if (!$this->can_access_debug_diagnostics()) wp_die($this->unauthorized_message());

    $nonce = isset($_POST[self::NONCE_NAME]) ? sanitize_text_field((string)$_POST[self::NONCE_NAME]) : '';
    if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) wp_die($this->invalid_nonce_message());

    delete_option(self::DIAGNOSTIC_OPTION_KEY);
    delete_option(self::RUNTIME_PROFILE_OPTION_KEY);
    wp_safe_redirect(admin_url('admin.php?page=links-manager-settings&lm_msg=' . rawurlencode(__('Diagnostic log cleared.', 'links-manager'))));
    exit;
  }

}
