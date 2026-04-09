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

    $profileStarted = $this->profile_start();

    $settings = $this->get_settings();
    $debugModeEnabled = isset($settings['debug_mode']) && (string)$settings['debug_mode'] === '1';
    $msg = $this->request_text('lm_msg', '');
    $msgClass = $this->notice_class_for_message($msg, 'success');
    $activeTab = $this->request_key('lm_tab', 'general');
    if (!in_array($activeTab, ['general', 'performance', 'status', 'data', 'debug'], true)) {
      $activeTab = 'general';
    }
    $settingsLabelStyle = 'display:block; margin:0 0 6px; font-weight:600; color:#1d2327;';
    $settingsLabelTopStyle = $settingsLabelStyle;
    $settingsCardStyle = 'margin:0 0 12px; padding:12px; border:1px solid #dcdcde; background:#fff; border-radius:8px; box-shadow:0 1px 1px rgba(0,0,0,.02);';
    $settingsCardCompactStyle = 'padding:12px; border:1px solid #dcdcde; background:#fff; border-radius:8px; box-shadow:0 1px 1px rgba(0,0,0,.02);';
    $autoPerformance = $this->get_auto_managed_performance_settings();

    echo '<div class="wrap lm-wrap">';
    $this->render_admin_page_header(
      __('Links Manager - Settings', 'links-manager'),
      __('Configure access, performance, data quality, and troubleshooting in a cleaner settings experience that still follows WordPress conventions.', 'links-manager')
    );
    if ($msg !== '') echo '<div class="notice notice-' . esc_attr($msgClass) . '"><p>' . esc_html($msg) . '</p></div>';

    echo '<div class="lm-card lm-card-full">';
    echo '<div class="lm-settings-tabs">';
    echo '<h2 class="nav-tab-wrapper">';
    echo '<a href="' . esc_url(admin_url('admin.php?page=links-manager-settings&lm_tab=general')) . '" class="nav-tab ' . ($activeTab === 'general' ? 'nav-tab-active' : '') . '"' . ($activeTab === 'general' ? ' aria-current="page"' : '') . '>' . esc_html__('General', 'links-manager') . '</a>';
    echo '<a href="' . esc_url(admin_url('admin.php?page=links-manager-settings&lm_tab=performance')) . '" class="nav-tab ' . ($activeTab === 'performance' ? 'nav-tab-active' : '') . '"' . ($activeTab === 'performance' ? ' aria-current="page"' : '') . '>' . esc_html__('Performance', 'links-manager') . '</a>';
    echo '<a href="' . esc_url(admin_url('admin.php?page=links-manager-settings&lm_tab=status')) . '" class="nav-tab ' . ($activeTab === 'status' ? 'nav-tab-active' : '') . '"' . ($activeTab === 'status' ? ' aria-current="page"' : '') . '>' . esc_html__('Status', 'links-manager') . '</a>';
    echo '<a href="' . esc_url(admin_url('admin.php?page=links-manager-settings&lm_tab=data')) . '" class="nav-tab ' . ($activeTab === 'data' ? 'nav-tab-active' : '') . '"' . ($activeTab === 'data' ? ' aria-current="page"' : '') . '>' . esc_html__('Data & Quality', 'links-manager') . '</a>';
    echo '<a href="' . esc_url(admin_url('admin.php?page=links-manager-settings&lm_tab=debug')) . '" class="nav-tab ' . ($activeTab === 'debug' ? 'nav-tab-active' : '') . '"' . ($activeTab === 'debug' ? ' aria-current="page"' : '') . '>' . esc_html__('Troubleshooting', 'links-manager') . '</a>';
    echo '</h2>';
    echo '<div class="lm-settings-tabs__divider" aria-hidden="true"></div>';
    echo '</div>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:10px;">';
    echo '<input type="hidden" name="action" value="lm_save_settings"/>';
    echo '<input type="hidden" name="' . esc_attr(self::NONCE_NAME) . '" value="' . esc_attr(wp_create_nonce(self::NONCE_ACTION)) . '"/>';
    echo '<input type="hidden" name="lm_active_tab" value="' . esc_attr($activeTab) . '"/>';

    if ($activeTab === 'general') {
      $this->render_admin_section_intro(
        __('Access Control', 'links-manager'),
        __('Choose which user roles can use this plugin. Administrator access always remains enabled.', 'links-manager')
      );
      echo '<div style="' . esc_attr($settingsCardStyle) . '">';
      echo '<div class="lm-settings-role-grid">';
      $rolesMap = $this->get_all_roles_map();
      $allowedRoles = $this->get_allowed_roles_from_settings();
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

    }

    if ($activeTab === 'performance') {
    $this->render_admin_section_intro(
      __('Performance & Reliability', 'links-manager'),
      __('Adjust how often data updates and how your server handles large scans.', 'links-manager')
    );
    echo '<div style="margin-top:8px;">';
    echo '<div style="margin:0 0 12px; padding:10px; border-left:4px solid #2271b1; background:#f6f7f7;">';
    echo '<div style="font-weight:600; margin-bottom:4px;">' . esc_html__('What to Scan', 'links-manager') . '</div>';
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

    echo '</div>';

    echo '</div>';
    echo '</details>';

    echo '<div style="' . esc_attr($settingsCardStyle) . '">';
    echo '<div style="font-weight:600; margin-bottom:6px;">' . esc_html__('Performance behavior', 'links-manager') . '</div>';
    echo '<div class="lm-small" style="margin-bottom:8px;">' . esc_html__('The system manages cache and refresh tuning automatically. Use the Status tab to monitor refresh health and run a manual refresh when needed.', 'links-manager') . '</div>';
    echo '<table class="widefat striped" style="margin-top:8px; max-width:760px;">';
    echo '<tbody>';
    echo '<tr><th style="width:260px;">' . esc_html__('Refresh method', 'links-manager') . '</th><td>' . esc_html__('Smart incremental refresh with automatic fallback when needed.', 'links-manager') . '</td></tr>';
    echo '<tr><th>' . esc_html__('Refresh capacity', 'links-manager') . '</th><td>' . esc_html(sprintf(__('%d pages per request, adjusted automatically for your server.', 'links-manager'), (int)$autoPerformance['crawl_post_batch'])) . '</td></tr>';
    echo '<tr><th>' . esc_html__('Report cache freshness', 'links-manager') . '</th><td>' . esc_html(sprintf(__('Up to %d seconds for repeated admin requests.', 'links-manager'), (int)$autoPerformance['rest_response_cache_ttl_sec'])) . '</td></tr>';
    echo '<tr><th>' . esc_html__('Dashboard stats refresh', 'links-manager') . '</th><td>' . esc_html($this->describe_stats_refresh_minutes((int)$autoPerformance['stats_snapshot_ttl_min'])) . '</td></tr>';
    echo '</tbody>';
    echo '</table>';
    echo '</div>';

    }

    if ($activeTab === 'status') {
    echo '<h2 style="margin-top:0;">' . esc_html__('Status & Refresh', 'links-manager') . '</h2>';
    echo '<div class="lm-small">' . esc_html__('Use this tab to monitor global data readiness and run a manual refresh when needed.', 'links-manager') . '</div>';
    echo '<div style="margin-top:8px;">';
    echo '<div style="margin:0 0 12px; padding:10px; border-left:4px solid #00a32a; background:#f6f7f7;">';
    echo '<div style="font-weight:600; margin-bottom:4px;">' . esc_html__('Global Refresh Status', 'links-manager') . '</div>';
    echo '<div class="lm-small">' . esc_html__('These details summarize global plugin data across all scopes and languages.', 'links-manager') . '</div>';
    echo '<div class="lm-small" style="margin-top:6px;">Status legend: <span class="lm-pill ok">Green = healthy</span> <span class="lm-pill warn">Amber = monitor</span> <span class="lm-pill bad">Red = action recommended</span>.</div>';
    echo '</div>';

    $nextScheduledTs = wp_next_scheduled('lm_scheduled_cache_rebuild');
    $schedulerEnabled = $nextScheduledTs !== false && $nextScheduledTs > 0;
    $nextScheduledLabel = $schedulerEnabled ? wp_date('Y-m-d H:i:s', (int)$nextScheduledTs) : 'Not scheduled';
    $schedulerStatusClass = $schedulerEnabled ? 'ok' : 'bad';

    $restState = $this->get_rebuild_job_state();
    $restStatus = isset($restState['status']) ? sanitize_key((string)$restState['status']) : 'idle';
    if ($restStatus === '') $restStatus = 'idle';
    $restProcessed = isset($restState['processed_posts']) ? max(0, (int)$restState['processed_posts']) : 0;
    $restTotal = isset($restState['total_posts']) ? max(0, (int)$restState['total_posts']) : 0;
    $restProgressLabel = $restTotal > 0
      ? number_format($restProcessed) . ' / ' . number_format($restTotal) . ' posts'
      : number_format($restProcessed) . ' posts processed';
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
    $readinessWpmlLang = $this->get_requested_view_wpml_lang((string)($this->get_wpml_admin_lang_url_args()['lang'] ?? 'all'));
    $readinessScopeLabel = __('all scopes / all languages', 'links-manager');
    if ($this->is_wpml_active() && $readinessWpmlLang !== '' && $readinessWpmlLang !== 'all') {
      $wpmlLanguagesMap = $this->get_wpml_languages_map();
      $langLabel = isset($wpmlLanguagesMap[$readinessWpmlLang]) ? (string)$wpmlLanguagesMap[$readinessWpmlLang] : strtoupper($readinessWpmlLang);
      $readinessScopeLabel = sprintf(__('all scopes / language: %s', 'links-manager'), $langLabel);
    }

    $scopedRowsCount = $this->get_indexed_fact_count_exact_scope('any', $readinessWpmlLang);
    $globalRowsCount = $this->get_indexed_fact_count_exact_scope('any', 'all');
    if ($globalRowsCount < 1) {
      $globalRowsCount = max(0, (int)($restState['rows_count'] ?? 0));
    }
    $effectiveCacheRowsCount = $scopedRowsCount;

    $restRecommendation = __('Data looks ready. Manual refresh is optional.', 'links-manager');
    $restRecommendationClass = 'ok';
    if ($restStatus === 'running') {
      $restRecommendation = __('A refresh is currently running. No manual trigger is needed right now.', 'links-manager');
      $restRecommendationClass = 'warn';
    } elseif ($lastScanLabel === 'Never') {
      $restRecommendation = __('No recent data is available. Recommended: run a manual refresh.', 'links-manager');
      $restRecommendationClass = 'bad';
    } elseif ($effectiveCacheRowsCount === 0 && $readinessWpmlLang !== 'all' && $globalRowsCount > 0) {
      $restRecommendation = __('Refresh completed successfully. This language scope currently has 0 rows, but the global dataset is available.', 'links-manager');
      $restRecommendationClass = 'warn';
    } elseif ($effectiveCacheRowsCount === 0) {
      $restRecommendation = __('Refresh completed successfully, but no link rows were found in the current scope.', 'links-manager');
      $restRecommendationClass = 'warn';
    } elseif ($cacheAgeHours >= 24) {
      $restRecommendation = __('Data is older than 24 hours. Consider a manual refresh if you need the latest scan.', 'links-manager');
      $restRecommendationClass = 'warn';
    }

    echo '<div style="' . esc_attr($settingsCardStyle) . '">';
    echo '<div style="font-weight:600; margin-bottom:6px;">' . esc_html__('Automatic Refresh', 'links-manager') . '</div>';
    echo '<table class="widefat striped" style="margin-top:8px; max-width:760px;">';
    echo '<tbody>';
    echo '<tr><th style="width:260px;">' . esc_html__('Automatic refresh', 'links-manager') . '</th><td><span class="lm-pill ' . esc_attr($schedulerStatusClass) . '">' . esc_html($schedulerEnabled ? __('On', 'links-manager') : __('Off', 'links-manager')) . '</span></td></tr>';
    echo '<tr><th>' . esc_html__('Next scheduled run', 'links-manager') . '</th><td>' . esc_html($nextScheduledLabel) . '</td></tr>';
    echo '</tbody>';
    echo '</table>';
    echo '<div class="lm-small" style="margin-top:6px;">' . esc_html__('Automatic refresh runs through WP-Cron and depends on site traffic.', 'links-manager') . '</div>';
    echo '</div>';

    echo '<div style="' . esc_attr($settingsCardStyle) . '">';
    echo '<div style="font-weight:600; margin-bottom:6px;">' . esc_html__('Current Data Status', 'links-manager') . '</div>';
    echo '<div class="lm-small" style="margin-bottom:6px;">' . esc_html(sprintf(__('Counts below summarize the current report scope: %s.', 'links-manager'), $readinessScopeLabel)) . '</div>';
    echo '<table class="widefat striped" style="margin-top:8px; max-width:760px;">';
    echo '<tbody>';
    echo '<tr><th style="width:260px;">' . esc_html__('Available report rows', 'links-manager') . '</th><td>' . esc_html(number_format($effectiveCacheRowsCount)) . '</td></tr>';
    if ($readinessWpmlLang !== 'all') {
      echo '<tr><th>' . esc_html__('Available global rows', 'links-manager') . '</th><td>' . esc_html(number_format($globalRowsCount)) . '</td></tr>';
    }
    echo '<tr><th>' . esc_html__('Last successful refresh', 'links-manager') . '</th><td>' . esc_html($lastScanLabel) . '</td></tr>';
    echo '<tr><th>' . esc_html__('Last global refresh job update', 'links-manager') . '</th><td>' . esc_html($restUpdatedAt) . '</td></tr>';
    if ($restStatus === 'running') {
      echo '<tr><th>' . esc_html__('Refresh progress', 'links-manager') . '</th><td>' . esc_html($restProgressLabel) . '</td></tr>';
    }
    echo '</tbody>';
    echo '</table>';
    echo '<div class="lm-small" style="margin-top:6px;"><strong>' . esc_html__('Recommendation:', 'links-manager') . '</strong> <span class="lm-pill ' . esc_attr($restRecommendationClass) . '">' . esc_html($restRecommendation) . '</span></div>';
    echo '</div>';

    echo '<div style="' . esc_attr($settingsCardStyle) . '">';
    echo '<div style="font-weight:600; margin-bottom:6px;">' . esc_html__('System-managed performance', 'links-manager') . '</div>';
    echo '<div class="lm-small" style="margin-bottom:8px;">' . esc_html__('These values are shown for awareness only. They are managed automatically by the system.', 'links-manager') . '</div>';
    echo '<table class="widefat striped" style="margin-top:8px; max-width:760px;">';
    echo '<tbody>';
    echo '<tr><th style="width:260px;">' . esc_html__('Refresh method', 'links-manager') . '</th><td>' . esc_html__('Smart incremental refresh with automatic fallback when needed.', 'links-manager') . '</td></tr>';
    echo '<tr><th>' . esc_html__('Refresh capacity', 'links-manager') . '</th><td>' . esc_html(sprintf(__('%d pages per request, adjusted automatically for your server.', 'links-manager'), (int)$autoPerformance['crawl_post_batch'])) . '</td></tr>';
    echo '<tr><th>' . esc_html__('Report cache freshness', 'links-manager') . '</th><td>' . esc_html(sprintf(__('Up to %d seconds for repeated admin requests.', 'links-manager'), (int)$autoPerformance['rest_response_cache_ttl_sec'])) . '</td></tr>';
    echo '<tr><th>' . esc_html__('Dashboard stats refresh', 'links-manager') . '</th><td>' . esc_html($this->describe_stats_refresh_minutes((int)$autoPerformance['stats_snapshot_ttl_min'])) . '</td></tr>';
    echo '</tbody>';
    echo '</table>';
    echo '</div>';

    echo '<hr style="margin:14px 0;"/>';
    echo '<div id="lm-rest-rebuild-controls" style="margin:0 0 12px; padding:12px; border:1px solid #dcdcde; background:#fff; border-radius:4px;">';
    echo '<div style="font-weight:600; margin-bottom:6px;">' . esc_html__('Refresh Data', 'links-manager') . '</div>';
    echo '<div class="lm-small" style="margin-bottom:10px;">' . esc_html__('Use this when you want to refresh all cached scan data manually across all scopes and languages. System safety limits are applied automatically.', 'links-manager') . '</div>';
    echo '<p style="margin:0 0 10px;">';
    echo '<button type="button" class="button button-secondary" data-lm-rest-rebuild-run>' . esc_html__('Refresh Data Now', 'links-manager') . '</button>';
    echo '</p>';
    echo '<div data-lm-rest-rebuild-status class="lm-small" style="font-weight:600; margin-bottom:8px;" aria-live="polite" aria-atomic="true">' . esc_html__('Checking status...', 'links-manager') . '</div>';
    echo '<div style="width:100%; max-width:620px; height:12px; border:1px solid #c3c4c7; border-radius:999px; background:#f0f0f1; overflow:hidden;">';
    echo '<div data-lm-rest-rebuild-bar role="progressbar" aria-label="REST rebuild progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" style="height:100%; width:0%; background:#2271b1; transition:width .2s ease;"></div>';
    echo '</div>';
    echo '<div data-lm-rest-rebuild-progress class="lm-small" style="margin-top:6px;" aria-live="polite" aria-atomic="true">' . esc_html__('No active refresh job.', 'links-manager') . '</div>';
    echo '<div data-lm-rest-rebuild-meta class="lm-small" style="margin-top:4px; color:#646970;" aria-live="polite" aria-atomic="true">' . esc_html__('Scope: all scopes / all languages | Status: idle | Rows: 0 | Batch: auto | Updated: -', 'links-manager') . '</div>';
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
      echo '<p style="margin:12px 0 8px; font-weight:600;">Internal Inbound Link Thresholds</p>';
      echo '<div class="lm-small">Used in Pages Link status: Orphaned, Low, Standard, and Excellent.</div>';
      echo '<div style="margin-top:8px;">';
      echo '<div style="' . esc_attr($settingsCardStyle) . '">';
      echo '<div class="lm-small" style="margin:0 0 8px;">These values control the status label for internal inbound links per page.</div>';
      echo '<div class="lm-settings-two-col">';
      echo '<p style="margin:0 0 10px;">';
      echo '<label class="lm-small" style="' . esc_attr($settingsLabelStyle) . '">Orphaned if internal inbound links <=</label>';
      echo '<input type="number" name="lm_inbound_orphan_max" min="0" max="1000000" value="' . esc_attr((string)($settings['inbound_orphan_max'] ?? '0')) . '" style="width:110px;" />';
      echo '<span class="lm-small" style="margin-left:8px;">Default 0.</span>';
      echo '</p>';

      echo '<p style="margin:0 0 10px;">';
      echo '<label class="lm-small" style="' . esc_attr($settingsLabelStyle) . '">Low if internal inbound links <=</label>';
      echo '<input type="number" name="lm_inbound_low_max" min="0" max="1000000" value="' . esc_attr((string)($settings['inbound_low_max'] ?? '5')) . '" style="width:110px;" />';
      echo '<span class="lm-small" style="margin-left:8px;">Default 5.</span>';
      echo '</p>';

      echo '<p style="margin:0 0 6px;">';
      echo '<label class="lm-small" style="' . esc_attr($settingsLabelStyle) . '">Standard if internal inbound links <=</label>';
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
      echo '<div class="lm-small">' . esc_html__('List words or phrases considered weak anchor text. Use one phrase per line or separate with commas. Matching is exact after trim/lowercase normalization. Empty anchor text is always Bad.', 'links-manager') . '</div>';
      echo '<div class="lm-settings-two-col" style="margin-top:10px;">';
      echo '<p style="margin:0 0 10px;">';
      echo '<label class="lm-small" style="' . esc_attr($settingsLabelStyle) . '">' . esc_html__('Poor if anchor length is below:', 'links-manager') . '</label>';
      echo '<input type="number" name="lm_anchor_poor_short_min" min="1" max="1000" value="' . esc_attr((string)($settings['anchor_poor_short_min'] ?? '3')) . '" style="width:110px;" />';
      echo '<span class="lm-small" style="margin-left:8px;">' . esc_html__('Default 3 characters.', 'links-manager') . '</span>';
      echo '</p>';
      echo '<p style="margin:0 0 10px;">';
      echo '<label class="lm-small" style="' . esc_attr($settingsLabelStyle) . '">' . esc_html__('Poor if anchor length is above:', 'links-manager') . '</label>';
      echo '<input type="number" name="lm_anchor_poor_long_max" min="1" max="1000" value="' . esc_attr((string)($settings['anchor_poor_long_max'] ?? '100')) . '" style="width:110px;" />';
      echo '<span class="lm-small" style="margin-left:8px;">' . esc_html__('Default 100 characters.', 'links-manager') . '</span>';
      echo '</p>';
      echo '</div>';
      echo '<div class="lm-small" style="margin-top:2px;">' . esc_html__('If the maximum is set below the minimum, it will be adjusted automatically when saving.', 'links-manager') . '</div>';
      echo '<div style="margin-top:8px;">';
      echo '<textarea name="lm_weak_anchor_patterns" rows="12" style="width:100%; max-width:760px;">' . esc_textarea((string)($settings['weak_anchor_patterns'] ?? '')) . '</textarea>';
      echo '</div>';
      echo '<div class="lm-small" style="margin-top:6px;">' . esc_html__('Even if this list is empty, length rules and empty-anchor checks still apply.', 'links-manager') . '</div>';
    }

    if ($activeTab === 'debug') {
      $troubleshootingSchedulerTs = wp_next_scheduled('lm_scheduled_cache_rebuild');
      $troubleshootingSchedulerOn = $troubleshootingSchedulerTs !== false && $troubleshootingSchedulerTs > 0;
      $troubleshootingRestState = $this->get_rebuild_job_state();
      $troubleshootingRestStatus = isset($troubleshootingRestState['status']) ? sanitize_key((string)$troubleshootingRestState['status']) : 'idle';
      if ($troubleshootingRestStatus === '') {
        $troubleshootingRestStatus = 'idle';
      }
      $troubleshootingLastScanGmt = (string)get_option($this->cache_scan_option_key('any', 'all'), '');
      $troubleshootingLastScanLabel = 'Never';
      $troubleshootingAgeHours = -1;
      if ($troubleshootingLastScanGmt !== '') {
        $troubleshootingLastScanTs = strtotime((string)$troubleshootingLastScanGmt . ' GMT');
        if ($troubleshootingLastScanTs !== false && $troubleshootingLastScanTs > 0) {
          $troubleshootingLastScanLabel = wp_date('Y-m-d H:i:s', $troubleshootingLastScanTs);
          $troubleshootingAgeHours = (int)floor((time() - $troubleshootingLastScanTs) / HOUR_IN_SECONDS);
        }
      }
      $troubleshootingRows = $this->get_indexed_fact_count('any', 'all');
      $troubleshootingIssues = [];
      if (!$troubleshootingSchedulerOn) {
        $troubleshootingIssues[] = __('Automatic refresh is off. Data may become outdated until you run a manual refresh.', 'links-manager');
      }
      if ($troubleshootingLastScanLabel === 'Never') {
        $troubleshootingIssues[] = __('No successful refresh has been recorded yet.', 'links-manager');
      } elseif ($troubleshootingAgeHours >= 24) {
        $troubleshootingIssues[] = __('Global data is older than 24 hours.', 'links-manager');
      }
      if ($troubleshootingRows === 0) {
        $troubleshootingIssues[] = __('No report rows are currently available.', 'links-manager');
      }
      if ($troubleshootingRestStatus === 'error') {
        $troubleshootingIssues[] = __('The last refresh job ended with an error.', 'links-manager');
      }

      echo '<h2 style="margin-top:0;">' . esc_html__('Troubleshooting', 'links-manager') . '</h2>';
      echo '<div class="lm-small">' . esc_html__('Use this tab to understand common problems and the next recommended action.', 'links-manager') . '</div>';

      echo '<div style="' . esc_attr($settingsCardStyle) . '">';
      echo '<div style="font-weight:600; margin-bottom:6px;">' . esc_html__('Issue Summary', 'links-manager') . '</div>';
      echo '<div class="lm-small" style="margin-bottom:8px;">' . esc_html__('This tab highlights likely problems and what to do next. Use the Status tab for live system values and counts.', 'links-manager') . '</div>';
      if (empty($troubleshootingIssues)) {
        echo '<div class="lm-small" style="margin-top:8px;"><strong>' . esc_html__('Recommended next action:', 'links-manager') . '</strong> ' . esc_html__('No action needed. Your data looks healthy.', 'links-manager') . '</div>';
      } else {
        echo '<div class="lm-small" style="margin-top:8px;"><strong>' . esc_html__('Recommended next action:', 'links-manager') . '</strong> ' . esc_html__('Review the items below and refresh data if needed.', 'links-manager') . '</div>';
        echo '<ul class="lm-small" style="margin:8px 0 0 18px;">';
        foreach ($troubleshootingIssues as $issue) {
          echo '<li>' . esc_html($issue) . '</li>';
        }
        echo '</ul>';
      }
      echo '</div>';

      $this->render_settings_refresh_troubleshooting_box($troubleshootingRestState, $settingsCardStyle);

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
      echo '<div class="lm-small" style="margin-top:4px;">' . esc_html__('Detailed technical data appears below only on this Troubleshooting tab when Debug mode is enabled and data is available.', 'links-manager') . '</div>';
      echo '</div>';

      $debugPostId = max(0, $this->request_int('lm_debug_post_id', 0));
      $debugNeedle = trim($this->request_text('lm_debug_link_search', ''));
      $debugPostResult = $debugPostId > 0 ? $this->build_post_scan_debug_result($debugPostId) : null;
      $pagesLinkParityIdsRaw = trim($this->request_text('lm_debug_pages_link_post_ids', ''));
      $pagesLinkParityResult = $pagesLinkParityIdsRaw !== '' ? $this->build_pages_link_parity_debug_result($pagesLinkParityIdsRaw) : null;
      $debugInspectUrlBase = admin_url('admin.php?page=links-manager-settings&lm_tab=debug');
      echo '<div style="' . esc_attr($settingsCardStyle) . '">';
      echo '<div style="font-weight:600; margin-bottom:6px;">' . esc_html__('Post Scan Debug', 'links-manager') . '</div>';
      echo '<div class="lm-small" style="margin-bottom:8px;">' . esc_html__('Use this to inspect whether a specific published post is being scanned and which links are extracted from its stored content.', 'links-manager') . '</div>';
      echo '<label class="lm-small" for="lm-debug-post-id" style="display:block; margin-bottom:4px;">' . esc_html__('Post ID', 'links-manager') . '</label>';
      echo '<input id="lm-debug-post-id" type="number" min="1" value="' . esc_attr($debugPostId > 0 ? (string)$debugPostId : '') . '" style="width:120px; margin-right:8px;" />';
      echo '<label class="lm-small" for="lm-debug-link-search" style="display:block; margin:10px 0 4px;">' . esc_html__('Optional link/domain search', 'links-manager') . '</label>';
      echo '<input id="lm-debug-link-search" type="text" value="' . esc_attr($debugNeedle) . '" placeholder="officeless.mekari.com" style="width:280px; max-width:100%; margin-right:8px;" />';
      echo '<a href="' . esc_url($debugInspectUrlBase) . '" class="button button-secondary" id="lm-debug-post-inspect-link">' . esc_html__('Inspect Post', 'links-manager') . '</a>';
      echo '<script>(function(){var postInput=document.getElementById("lm-debug-post-id");var searchInput=document.getElementById("lm-debug-link-search");var link=document.getElementById("lm-debug-post-inspect-link");if(!postInput||!link)return;var base=' . wp_json_encode($debugInspectUrlBase) . ';var sync=function(){var params=[];var postVal=parseInt(postInput.value||"",10);var searchVal=searchInput?String(searchInput.value||"").trim():"";if(postVal>0){params.push("lm_debug_post_id="+encodeURIComponent(String(postVal)));}if(searchVal!==""){params.push("lm_debug_link_search="+encodeURIComponent(searchVal));}link.href=base+(params.length?"&"+params.join("&"):"");};postInput.addEventListener("input",sync);postInput.addEventListener("change",sync);if(searchInput){searchInput.addEventListener("input",sync);searchInput.addEventListener("change",sync);}sync();})();</script>';

      if (is_array($debugPostResult)) {
        echo '<table class="widefat striped" style="margin-top:8px; max-width:900px;">';
        echo '<tbody>';
        echo '<tr><th style="width:260px;">' . esc_html__('Post found', 'links-manager') . '</th><td>' . esc_html(!empty($debugPostResult['post_found']) ? __('Yes', 'links-manager') : __('No', 'links-manager')) . '</td></tr>';
        echo '<tr><th>' . esc_html__('Post status', 'links-manager') . '</th><td>' . esc_html((string)($debugPostResult['post_status'] ?? '—')) . '</td></tr>';
        echo '<tr><th>' . esc_html__('Post type', 'links-manager') . '</th><td>' . esc_html((string)($debugPostResult['post_type'] ?? '—')) . '</td></tr>';
        echo '<tr><th>' . esc_html__('Permalink', 'links-manager') . '</th><td>' . esc_html((string)($debugPostResult['page_url'] ?? '—')) . '</td></tr>';
        echo '<tr><th>' . esc_html__('Content has link marker', 'links-manager') . '</th><td>' . esc_html(!empty($debugPostResult['content_has_link_markers']) ? __('Yes', 'links-manager') : __('No', 'links-manager')) . '</td></tr>';
        echo '<tr><th>' . esc_html__('Content has block markup', 'links-manager') . '</th><td>' . esc_html(!empty($debugPostResult['has_block_markup']) ? __('Yes', 'links-manager') : __('No', 'links-manager')) . '</td></tr>';
        echo '<tr><th>' . esc_html__('Extracted rows from crawl_post()', 'links-manager') . '</th><td>' . esc_html(number_format((int)($debugPostResult['rows_count'] ?? 0))) . '</td></tr>';
        echo '<tr><th>' . esc_html__('Rows stored in indexed datastore', 'links-manager') . '</th><td>' . esc_html(number_format((int)($debugPostResult['indexed_rows_count'] ?? 0))) . '</td></tr>';
        if ($debugNeedle !== '') {
          echo '<tr><th>' . esc_html__('Raw post_content contains search', 'links-manager') . '</th><td>' . esc_html(!empty($debugPostResult['content_contains_search']) ? __('Yes', 'links-manager') : __('No', 'links-manager')) . '</td></tr>';
          echo '<tr><th>' . esc_html__('crawl_post() matches for search', 'links-manager') . '</th><td>' . esc_html(number_format((int)($debugPostResult['search_rows_count'] ?? 0))) . '</td></tr>';
          echo '<tr><th>' . esc_html__('Indexed datastore matches for search', 'links-manager') . '</th><td>' . esc_html(number_format((int)($debugPostResult['indexed_search_rows_count'] ?? 0))) . '</td></tr>';
        }
        echo '</tbody>';
        echo '</table>';

        $sampleLinks = isset($debugPostResult['sample_links']) && is_array($debugPostResult['sample_links']) ? $debugPostResult['sample_links'] : [];
        $matchedLinks = isset($debugPostResult['matched_links']) && is_array($debugPostResult['matched_links']) ? $debugPostResult['matched_links'] : [];
        if ($debugNeedle !== '') {
          echo '<div class="lm-small" style="margin-top:10px; font-weight:600;">' . esc_html__('Search matches in crawl_post()', 'links-manager') . '</div>';
          if (!empty($matchedLinks)) {
            echo '<table class="widefat striped" style="margin-top:8px; max-width:900px;">';
            echo '<thead><tr><th style="width:60px;">#</th><th>' . esc_html__('Link', 'links-manager') . '</th><th>' . esc_html__('Source', 'links-manager') . '</th><th>' . esc_html__('Location', 'links-manager') . '</th><th>' . esc_html__('Anchor', 'links-manager') . '</th></tr></thead>';
            echo '<tbody>';
            foreach ($matchedLinks as $index => $sampleRow) {
              echo '<tr>';
              echo '<td>' . esc_html((string)($index + 1)) . '</td>';
              echo '<td>' . esc_html((string)($sampleRow['link'] ?? '')) . '</td>';
              echo '<td>' . esc_html((string)($sampleRow['source'] ?? '')) . '</td>';
              echo '<td>' . esc_html((string)($sampleRow['link_location'] ?? '')) . '</td>';
              echo '<td>' . esc_html((string)($sampleRow['anchor_text'] ?? '')) . '</td>';
              echo '</tr>';
            }
            echo '</tbody>';
            echo '</table>';
          } else {
            echo '<div class="lm-small" style="margin-top:8px;">' . esc_html__('No crawl_post() rows matched the search term.', 'links-manager') . '</div>';
          }
        }
        if (!empty($sampleLinks)) {
          echo '<div class="lm-small" style="margin-top:10px; font-weight:600;">' . esc_html__('Sample extracted links', 'links-manager') . '</div>';
          echo '<table class="widefat striped" style="margin-top:8px; max-width:900px;">';
          echo '<thead><tr><th style="width:60px;">#</th><th>' . esc_html__('Link', 'links-manager') . '</th><th>' . esc_html__('Source', 'links-manager') . '</th><th>' . esc_html__('Location', 'links-manager') . '</th><th>' . esc_html__('Anchor', 'links-manager') . '</th></tr></thead>';
          echo '<tbody>';
          foreach ($sampleLinks as $index => $sampleRow) {
            echo '<tr>';
            echo '<td>' . esc_html((string)($index + 1)) . '</td>';
            echo '<td>' . esc_html((string)($sampleRow['link'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string)($sampleRow['source'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string)($sampleRow['link_location'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string)($sampleRow['anchor_text'] ?? '')) . '</td>';
            echo '</tr>';
          }
          echo '</tbody>';
          echo '</table>';
        } elseif ($debugPostId > 0) {
          echo '<div class="lm-small" style="margin-top:10px;">' . esc_html__('No links were extracted from this post by crawl_post().', 'links-manager') . '</div>';
        }
      }
      echo '</div>';

      echo '<div style="' . esc_attr($settingsCardStyle) . '">';
      echo '<div style="font-weight:600; margin-bottom:6px;">' . esc_html__('Pages Link Parity Audit', 'links-manager') . '</div>';
      echo '<div class="lm-small" style="margin-bottom:8px;">' . esc_html__('Compare row-based Pages Link counts against indexed summary counts for specific post IDs before expanding indexed fast paths.', 'links-manager') . '</div>';
      echo '<label class="lm-small" for="lm-debug-pages-link-post-ids" style="display:block; margin-bottom:4px;">' . esc_html__('Post IDs (comma-separated)', 'links-manager') . '</label>';
      echo '<input id="lm-debug-pages-link-post-ids" type="text" value="' . esc_attr($pagesLinkParityIdsRaw) . '" placeholder="12,34,56" style="width:320px; max-width:100%; margin-right:8px;" />';
      echo '<a href="' . esc_url($debugInspectUrlBase) . '" class="button button-secondary" id="lm-debug-pages-link-parity-link">' . esc_html__('Run Parity Audit', 'links-manager') . '</a>';
      echo '<div class="lm-small" style="margin-top:6px;">' . esc_html__('Uses current Pages Link filters from the admin request. User-facing Pages Link screen remains row-based.', 'links-manager') . '</div>';
      echo '<script>(function(){var idsInput=document.getElementById("lm-debug-pages-link-post-ids");var link=document.getElementById("lm-debug-pages-link-parity-link");if(!idsInput||!link)return;var sync=function(){var url=new URL(window.location.href);url.searchParams.set("page","links-manager-settings");url.searchParams.set("lm_tab","debug");var idsVal=String(idsInput.value||"").trim();if(idsVal!==""){url.searchParams.set("lm_debug_pages_link_post_ids",idsVal);}else{url.searchParams.delete("lm_debug_pages_link_post_ids");}link.href=url.toString();};idsInput.addEventListener("input",sync);idsInput.addEventListener("change",sync);sync();})();</script>';

      if (is_array($pagesLinkParityResult)) {
        $auditRows = isset($pagesLinkParityResult['rows']) && is_array($pagesLinkParityResult['rows']) ? $pagesLinkParityResult['rows'] : [];
        echo '<table class="widefat striped" style="margin-top:8px; max-width:900px;">';
        echo '<tbody>';
        echo '<tr><th style="width:260px;">' . esc_html__('Requested post IDs', 'links-manager') . '</th><td>' . esc_html((string)($pagesLinkParityResult['requested_ids_label'] ?? '—')) . '</td></tr>';
        echo '<tr><th>' . esc_html__('Matched audited posts', 'links-manager') . '</th><td>' . esc_html(number_format((int)($pagesLinkParityResult['matched_posts'] ?? 0))) . '</td></tr>';
        echo '<tr><th>' . esc_html__('Pages Link scope used', 'links-manager') . '</th><td>' . esc_html((string)($pagesLinkParityResult['scope_label'] ?? '—')) . '</td></tr>';
        echo '<tr><th>' . esc_html__('Posts with any delta', 'links-manager') . '</th><td>' . esc_html(number_format((int)($pagesLinkParityResult['delta_posts'] ?? 0))) . '</td></tr>';
        echo '</tbody>';
        echo '</table>';

        if (!empty($auditRows)) {
          echo '<table class="widefat striped" style="margin-top:8px; max-width:100%;">';
          echo '<thead><tr>';
          echo '<th style="width:80px;">' . esc_html__('Post ID', 'links-manager') . '</th>';
          echo '<th>' . esc_html__('Page URL', 'links-manager') . '</th>';
          echo '<th>' . esc_html__('Inbound', 'links-manager') . '</th>';
          echo '<th>' . esc_html__('Indexed Inbound', 'links-manager') . '</th>';
          echo '<th>' . esc_html__('Int. Outbound', 'links-manager') . '</th>';
          echo '<th>' . esc_html__('Indexed Int. Outbound', 'links-manager') . '</th>';
          echo '<th>' . esc_html__('Outbound', 'links-manager') . '</th>';
          echo '<th>' . esc_html__('Indexed Outbound', 'links-manager') . '</th>';
          echo '<th>' . esc_html__('Delta Flags', 'links-manager') . '</th>';
          echo '</tr></thead><tbody>';
          foreach ($auditRows as $auditRow) {
            echo '<tr>';
            echo '<td>' . esc_html((string)($auditRow['post_id'] ?? '')) . '</td>';
            echo '<td><code>' . esc_html((string)($auditRow['page_url'] ?? '')) . '</code></td>';
            echo '<td>' . esc_html(number_format((int)($auditRow['row_based_inbound'] ?? 0))) . '</td>';
            echo '<td>' . esc_html(number_format((int)($auditRow['indexed_inbound'] ?? 0))) . '</td>';
            echo '<td>' . esc_html(number_format((int)($auditRow['row_based_internal_outbound'] ?? 0))) . '</td>';
            echo '<td>' . esc_html(number_format((int)($auditRow['indexed_internal_outbound'] ?? 0))) . '</td>';
            echo '<td>' . esc_html(number_format((int)($auditRow['row_based_outbound'] ?? 0))) . '</td>';
            echo '<td>' . esc_html(number_format((int)($auditRow['indexed_outbound'] ?? 0))) . '</td>';
            echo '<td>' . esc_html((string)($auditRow['delta_flags_label'] ?? 'match')) . '</td>';
            echo '</tr>';
          }
          echo '</tbody></table>';
        } else {
          echo '<div class="lm-small" style="margin-top:8px;">' . esc_html__('No matching candidate posts were found for the requested IDs under the current Pages Link filters.', 'links-manager') . '</div>';
        }
      }
      echo '</div>';

      if ($debugModeEnabled) {
        $this->render_settings_diagnostic_box();
        $this->render_settings_runtime_profile_box();
      }
    }

    echo '<hr style="margin:14px 0;"/>';
    echo '<div class="lm-settings-actions">';
    if ($activeTab !== 'status') {
      echo '<div class="lm-settings-actions-primary">';
      submit_button(__('Save Settings', 'links-manager'), 'primary', 'submit', false);
      echo '<div class="lm-small lm-help-tip">' . esc_html__('Save your current tab settings.', 'links-manager') . '</div>';
      echo '</div>';
    }

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
      echo '<div class="lm-settings-actions-title">' . esc_html__('Performance Actions', 'links-manager') . '</div>';
      echo '<div class="lm-small lm-settings-actions-note">' . esc_html__('Restore scan scope and performance defaults for this tab.', 'links-manager') . '</div>';
      submit_button(__('Reset Performance Settings', 'links-manager'), 'secondary', 'lm_reset_performance_settings', false, [
        'value' => '1',
        'onclick' => "return confirm('" . esc_js(__('Reset Performance settings to defaults?', 'links-manager')) . "');",
      ]);
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

    $this->profile_end('settings_page_render', $profileStarted, [
      'tab' => $activeTab,
    ]);
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

  private function render_settings_refresh_troubleshooting_box($state, $cardStyle) {
    $state = is_array($state) ? $state : [];
    $status = isset($state['status']) ? sanitize_key((string)$state['status']) : 'idle';
    if ($status === '') {
      $status = 'idle';
    }

    $scopePostType = sanitize_key((string)($state['scope_post_type'] ?? 'any'));
    if ($scopePostType === '') {
      $scopePostType = 'any';
    }
    $wpmlLang = sanitize_key((string)($state['wpml_lang'] ?? 'all'));
    if ($wpmlLang === '') {
      $wpmlLang = 'all';
    }

    $message = sanitize_text_field((string)($state['message'] ?? ''));
    $storageMode = sanitize_key((string)($state['storage_mode'] ?? ''));
    if ($storageMode === '') {
      $storageMode = 'indexed_stream';
    }
    $processedPosts = max(0, (int)($state['processed_posts'] ?? 0));
    $totalPosts = max(0, (int)($state['total_posts'] ?? 0));
    $rowsCount = max(0, (int)($state['rows_count'] ?? 0));
    $batchSize = max(0, (int)($state['batch_size'] ?? 0));
    $crawlStepMs = max(0, (int)($state['step_ms'] ?? 0));
    $updatedAt = (string)($state['updated_at'] ?? '');
    if ($updatedAt === '') {
      $updatedAt = '—';
    }

    $lastRefreshScopePostType = $scopePostType;
    $lastRefreshScopeWpmlLang = $wpmlLang;
    $normalizedHealth = $this->get_indexed_normalized_url_health_for_scope('any', 'all');
    $normalizedSchemaReady = !empty($normalizedHealth['schema_ready']);
    $normalizedTotalRows = max(0, (int)($normalizedHealth['rows_total'] ?? 0));
    $normalizedActionableRows = max(0, (int)($normalizedHealth['rows_actionable_total'] ?? 0));
    $normalizedMissingRows = max(0, (int)($normalizedHealth['rows_missing_normalized'] ?? 0));
    $normalizedEmptySourceRows = max(0, (int)($normalizedHealth['rows_empty_source'] ?? 0));
    $lastFinalizeMetrics = $this->get_last_finalize_metrics();

    $normalizedBackfillDone = !empty($state['normalized_backfill_done']);
    $normalizedBackfillProcessed = max(0, (int)($state['normalized_backfill_processed'] ?? 0));
    $normalizedBackfillLastId = max(0, (int)($state['normalized_backfill_last_id'] ?? 0));
    $normalizedBackfillStepMs = max(0, (int)($state['normalized_backfill_step_ms'] ?? 0));
    $normalizedBackfillChunkSize = max(0, (int)($state['normalized_backfill_chunk_size'] ?? 0));
    $finalizeStage = sanitize_key((string)($state['finalize_stage'] ?? ''));

    $finalizeProcessedPosts = max(0, (int)($state['finalize_processed_posts'] ?? 0));
    $finalizeChunkSize = max(0, (int)($state['finalize_chunk_size'] ?? 0));
    $finalizeStepMs = max(0, (int)($state['finalize_step_ms'] ?? 0));
    $finalizeSummaryRows = max(0, (int)($state['finalize_summary_rows'] ?? 0));
    $finalizeInboundRows = max(0, (int)($state['finalize_inbound_rows'] ?? 0));
    $finalizeSeedProcessedPosts = max(0, (int)($state['finalize_seed_processed_posts'] ?? 0));
    $finalizeSeedStepMs = max(0, (int)($state['finalize_seed_step_ms'] ?? 0));
    $finalizeSeedChunkSize = max(0, (int)($state['finalize_seed_chunk_size'] ?? 0));
    $finalizeInboundProcessedPosts = max(0, (int)($state['finalize_inbound_processed_posts'] ?? 0));
    $finalizeInboundStepMs = max(0, (int)($state['finalize_inbound_step_ms'] ?? 0));
    $finalizeInboundChunkSize = max(0, (int)($state['finalize_inbound_chunk_size'] ?? 0));
    $finalizeLastSummaryQueryMs = max(0, (int)($state['finalize_last_summary_query_ms'] ?? 0));
    $finalizeLastInboundQueryMs = max(0, (int)($state['finalize_last_inbound_query_ms'] ?? 0));
    $finalizeTargetOnlyRowsAdded = max(0, (int)($state['finalize_target_only_rows_added'] ?? 0));

    $hasLiveFinalizeMetrics = ($finalizeProcessedPosts > 0 || $finalizeStepMs > 0 || $finalizeSummaryRows > 0 || $finalizeInboundRows > 0 || $finalizeSeedProcessedPosts > 0 || $finalizeInboundProcessedPosts > 0 || $normalizedBackfillProcessed > 0 || $normalizedBackfillStepMs > 0);
    if (!$hasLiveFinalizeMetrics && is_array($lastFinalizeMetrics) && !empty($lastFinalizeMetrics)) {
      $normalizedBackfillDone = !empty($lastFinalizeMetrics['normalized_backfill_done']);
      $normalizedBackfillProcessed = max(0, (int)($lastFinalizeMetrics['normalized_backfill_processed'] ?? 0));
      $normalizedBackfillStepMs = max(0, (int)($lastFinalizeMetrics['normalized_backfill_step_ms'] ?? 0));
      $normalizedBackfillChunkSize = max(0, (int)($lastFinalizeMetrics['normalized_backfill_chunk_size'] ?? 0));
      $finalizeStage = sanitize_key((string)($lastFinalizeMetrics['finalize_stage'] ?? ''));
      $finalizeProcessedPosts = max(0, (int)($lastFinalizeMetrics['finalize_processed_posts'] ?? 0));
      $finalizeChunkSize = max(0, (int)($lastFinalizeMetrics['finalize_chunk_size'] ?? 0));
      $finalizeStepMs = max(0, (int)($lastFinalizeMetrics['finalize_step_ms'] ?? 0));
      $finalizeSummaryRows = max(0, (int)($lastFinalizeMetrics['finalize_summary_rows'] ?? 0));
      $finalizeInboundRows = max(0, (int)($lastFinalizeMetrics['finalize_inbound_rows'] ?? 0));
      $finalizeSeedProcessedPosts = max(0, (int)($lastFinalizeMetrics['finalize_seed_processed_posts'] ?? 0));
      $finalizeSeedStepMs = max(0, (int)($lastFinalizeMetrics['finalize_seed_step_ms'] ?? 0));
      $finalizeSeedChunkSize = max(0, (int)($lastFinalizeMetrics['finalize_seed_chunk_size'] ?? 0));
      $finalizeInboundProcessedPosts = max(0, (int)($lastFinalizeMetrics['finalize_inbound_processed_posts'] ?? 0));
      $finalizeInboundStepMs = max(0, (int)($lastFinalizeMetrics['finalize_inbound_step_ms'] ?? 0));
      $finalizeInboundChunkSize = max(0, (int)($lastFinalizeMetrics['finalize_inbound_chunk_size'] ?? 0));
      $finalizeLastSummaryQueryMs = max(0, (int)($lastFinalizeMetrics['finalize_last_summary_query_ms'] ?? 0));
      $finalizeLastInboundQueryMs = max(0, (int)($lastFinalizeMetrics['finalize_last_inbound_query_ms'] ?? 0));
      $finalizeTargetOnlyRowsAdded = max(0, (int)($lastFinalizeMetrics['finalize_target_only_rows_added'] ?? 0));
    }

    if (is_array($lastFinalizeMetrics) && !empty($lastFinalizeMetrics)) {
      $snapshotScopePostType = sanitize_key((string)($lastFinalizeMetrics['scope_post_type'] ?? $lastRefreshScopePostType));
      $snapshotScopeWpmlLang = sanitize_key((string)($lastFinalizeMetrics['wpml_lang'] ?? $lastRefreshScopeWpmlLang));
      if ($snapshotScopePostType !== '') {
        $lastRefreshScopePostType = $snapshotScopePostType;
      }
      if ($snapshotScopeWpmlLang !== '') {
        $lastRefreshScopeWpmlLang = $snapshotScopeWpmlLang;
      }
    }

    $lastScopeHealth = $this->get_indexed_normalized_url_health_for_scope($lastRefreshScopePostType, $lastRefreshScopeWpmlLang);
    $lastScopeActionableRows = max(0, (int)($lastScopeHealth['rows_actionable_total'] ?? 0));
    $lastScopeMissingRows = max(0, (int)($lastScopeHealth['rows_missing_normalized'] ?? 0));
    $lastScopeEmptySourceRows = max(0, (int)($lastScopeHealth['rows_empty_source'] ?? 0));

    $scopeLabel = $scopePostType . ' / ' . $wpmlLang;
    $phaseLabel = $message !== '' ? $message : __('No active refresh phase.', 'links-manager');
    $normalizedCoverage = '—';
    if ($normalizedSchemaReady) {
      $normalizedReadyRows = max(0, $normalizedActionableRows - $normalizedMissingRows);
      $normalizedCoverage = number_format($normalizedReadyRows) . ' / ' . number_format($normalizedActionableRows) . ' actionable rows';
    }
    $normalizedBackfillDone = ($normalizedSchemaReady && $normalizedMissingRows === 0);
    $lastFinalizeCapturedAt = is_array($lastFinalizeMetrics) ? (string)($lastFinalizeMetrics['captured_at'] ?? '') : '';
    if ($lastFinalizeCapturedAt === '') {
      $lastFinalizeCapturedAt = '—';
    }
    $finalizeStageLabel = $finalizeStage !== '' ? $finalizeStage : __('Not active', 'links-manager');
    if ($status === 'done') {
      $finalizeSummaryScopePostType = $scopePostType;
      $finalizeSummaryScopeWpmlLang = $wpmlLang;
      if (is_array($lastFinalizeMetrics) && !empty($lastFinalizeMetrics)) {
        $snapshotScopePostType = sanitize_key((string)($lastFinalizeMetrics['scope_post_type'] ?? $finalizeSummaryScopePostType));
        $snapshotScopeWpmlLang = sanitize_key((string)($lastFinalizeMetrics['wpml_lang'] ?? $finalizeSummaryScopeWpmlLang));
        if ($snapshotScopePostType !== '') {
          $finalizeSummaryScopePostType = $snapshotScopePostType;
        }
        if ($snapshotScopeWpmlLang !== '') {
          $finalizeSummaryScopeWpmlLang = $snapshotScopeWpmlLang;
        }
      }
      $finalizeProcessedPosts = $this->get_indexed_summary_count_exact_scope($finalizeSummaryScopePostType, $finalizeSummaryScopeWpmlLang);
    }

    $lastScopeCoverage = '—';
    if (!empty($lastScopeHealth['schema_ready'])) {
      $lastScopeReadyRows = max(0, $lastScopeActionableRows - $lastScopeMissingRows);
      $lastScopeCoverage = number_format($lastScopeReadyRows) . ' / ' . number_format($lastScopeActionableRows) . ' actionable rows';
    }

    echo '<div style="' . esc_attr($cardStyle) . '">';
    echo '<div style="font-weight:600; margin-bottom:6px;">' . esc_html__('Refresh Diagnostics', 'links-manager') . '</div>';
    echo '<div class="lm-small" style="margin-bottom:8px;">' . esc_html__('Use these values to identify whether issues come from the most recent refresh job or from the current indexed datastore health.', 'links-manager') . '</div>';

    echo '<div style="font-weight:600; margin:12px 0 6px;">' . esc_html__('Last Refresh Job', 'links-manager') . '</div>';
    echo '<div class="lm-small" style="margin-bottom:6px;">' . esc_html__('Crawl progress counts every post examined. Finalized summary posts count only posts that produced summary rows from extracted link data.', 'links-manager') . '</div>';
    echo '<table class="widefat striped" style="margin-top:8px; max-width:900px;">';
    echo '<tbody>';
    echo '<tr><th style="width:280px;">' . esc_html__('Job status', 'links-manager') . '</th><td>' . esc_html($status) . '</td></tr>';
    echo '<tr><th>' . esc_html__('Current phase', 'links-manager') . '</th><td>' . esc_html($phaseLabel) . '</td></tr>';
    echo '<tr><th>' . esc_html__('Active refresh scope', 'links-manager') . '</th><td>' . esc_html($scopeLabel) . '</td></tr>';
    echo '<tr><th>' . esc_html__('Storage mode', 'links-manager') . '</th><td>' . esc_html($storageMode) . '</td></tr>';
    echo '<tr><th>' . esc_html__('Crawl progress', 'links-manager') . '</th><td>' . esc_html(number_format($processedPosts)) . ' / ' . esc_html(number_format($totalPosts)) . ' posts</td></tr>';
    echo '<tr><th>' . esc_html__('Indexed rows seen by refresh', 'links-manager') . '</th><td>' . esc_html(number_format($rowsCount)) . '</td></tr>';
    echo '<tr><th>' . esc_html__('Current crawl batch', 'links-manager') . '</th><td>' . esc_html($batchSize > 0 ? number_format($batchSize) : 'auto') . '</td></tr>';
    echo '<tr><th>' . esc_html__('Last crawl step time', 'links-manager') . '</th><td>' . esc_html(number_format($crawlStepMs)) . ' ms</td></tr>';
    echo '<tr><th>' . esc_html__('Last job update', 'links-manager') . '</th><td>' . esc_html($updatedAt) . '</td></tr>';
    echo '<tr><th>' . esc_html__('Last backfill step from most recent refresh', 'links-manager') . '</th><td>' . esc_html(number_format($normalizedBackfillProcessed)) . ' rows | last fact ID ' . esc_html(number_format($normalizedBackfillLastId)) . ' | ' . esc_html(number_format($normalizedBackfillStepMs)) . ' ms | chunk ' . esc_html($normalizedBackfillChunkSize > 0 ? number_format($normalizedBackfillChunkSize) : 'auto') . '</td></tr>';
    echo '<tr><th>' . esc_html__('Active finalizing stage', 'links-manager') . '</th><td>' . esc_html($finalizeStageLabel) . '</td></tr>';
    echo '<tr><th>' . esc_html__('Finalized summary posts', 'links-manager') . '</th><td>' . esc_html(number_format($finalizeProcessedPosts)) . ' posts with summary rows</td></tr>';
    echo '<tr><th>' . esc_html__('Summary seed progress', 'links-manager') . '</th><td>' . esc_html(number_format($finalizeSeedProcessedPosts)) . ' posts | ' . esc_html(number_format($finalizeSeedStepMs)) . ' ms | chunk ' . esc_html($finalizeSeedChunkSize > 0 ? number_format($finalizeSeedChunkSize) : 'auto') . '</td></tr>';
    echo '<tr><th>' . esc_html__('Inbound finalize progress', 'links-manager') . '</th><td>' . esc_html(number_format($finalizeInboundProcessedPosts)) . ' posts | ' . esc_html(number_format($finalizeInboundStepMs)) . ' ms | chunk ' . esc_html($finalizeInboundChunkSize > 0 ? number_format($finalizeInboundChunkSize) : 'auto') . '</td></tr>';
    echo '<tr><th>' . esc_html__('Target-only summary rows added', 'links-manager') . '</th><td>' . esc_html(number_format($finalizeTargetOnlyRowsAdded)) . '</td></tr>';
    echo '<tr><th>' . esc_html__('Finalizing last step', 'links-manager') . '</th><td>' . esc_html(number_format($finalizeStepMs)) . ' ms | chunk ' . esc_html($finalizeChunkSize > 0 ? number_format($finalizeChunkSize) : 'auto') . '</td></tr>';
    echo '<tr><th>' . esc_html__('Last summary query time', 'links-manager') . '</th><td>' . esc_html(number_format($finalizeLastSummaryQueryMs)) . ' ms</td></tr>';
    echo '<tr><th>' . esc_html__('Last inbound query time', 'links-manager') . '</th><td>' . esc_html(number_format($finalizeLastInboundQueryMs)) . ' ms</td></tr>';
    echo '<tr><th>' . esc_html__('Summary rows written in last step', 'links-manager') . '</th><td>' . esc_html(number_format($finalizeSummaryRows)) . '</td></tr>';
    echo '<tr><th>' . esc_html__('Inbound rows computed in last step', 'links-manager') . '</th><td>' . esc_html(number_format($finalizeInboundRows)) . '</td></tr>';
    echo '<tr><th>' . esc_html__('Last finalizing snapshot', 'links-manager') . '</th><td>' . esc_html($lastFinalizeCapturedAt) . '</td></tr>';
    echo '</tbody>';
    echo '</table>';

    echo '<div style="font-weight:600; margin:12px 0 6px;">' . esc_html__('Current Indexed Datastore Health', 'links-manager') . '</div>';
    echo '<div class="lm-small" style="margin-bottom:6px;">' . esc_html__('This section shows current datastore health. Needs backfill here refers to actionable rows still missing normalized values, not to the last refresh snapshot.', 'links-manager') . '</div>';
    echo '<table class="widefat striped" style="margin-top:8px; max-width:900px;">';
    echo '<tbody>';
    echo '<tr><th style="width:280px;">' . esc_html__('Normalized URL schema', 'links-manager') . '</th><td>' . esc_html($normalizedSchemaReady ? __('Ready', 'links-manager') : __('Not ready', 'links-manager')) . '</td></tr>';
    echo '<tr><th>' . esc_html__('Global normalized coverage', 'links-manager') . '</th><td>' . esc_html($normalizedCoverage) . '</td></tr>';
    echo '<tr><th>' . esc_html__('Global rows still needing normalized backfill', 'links-manager') . '</th><td>' . esc_html(number_format($normalizedMissingRows)) . '</td></tr>';
    echo '<tr><th>' . esc_html__('Global rows with empty source values', 'links-manager') . '</th><td>' . esc_html(number_format($normalizedEmptySourceRows)) . '</td></tr>';
    echo '<tr><th>' . esc_html__('Global normalized backfill status', 'links-manager') . '</th><td>' . esc_html($normalizedBackfillDone ? __('Done', 'links-manager') : __('Needs backfill', 'links-manager')) . '</td></tr>';
    echo '<tr><th>' . esc_html__('Last refresh scope health', 'links-manager') . '</th><td>' . esc_html($lastRefreshScopePostType . ' / ' . $lastRefreshScopeWpmlLang) . '</td></tr>';
    echo '<tr><th>' . esc_html__('Last refresh scope normalized coverage', 'links-manager') . '</th><td>' . esc_html($lastScopeCoverage) . '</td></tr>';
    echo '<tr><th>' . esc_html__('Last refresh scope rows needing normalized backfill', 'links-manager') . '</th><td>' . esc_html(number_format($lastScopeMissingRows)) . '</td></tr>';
    echo '<tr><th>' . esc_html__('Last refresh scope rows with empty source values', 'links-manager') . '</th><td>' . esc_html(number_format($lastScopeEmptySourceRows)) . '</td></tr>';
    echo '</tbody>';
    echo '</table>';
    echo '<div class="lm-small" style="margin-top:8px;">' . esc_html__('Optimization hints: actionable backfill rows only count records that still have a source URL/link to normalize. Rows with empty source values are shown separately and do not indicate a backfill failure.', 'links-manager') . '</div>';
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

    $profileEntriesPerPage = 25;
    $profileEntriesPaged = max(1, $this->request_int('lm_profile_entries_paged', 1));
    $profileEntriesTotal = count($entries);
    $profileEntriesTotalPages = max(1, (int)ceil(max(1, $profileEntriesTotal) / $profileEntriesPerPage));
    if ($profileEntriesPaged > $profileEntriesTotalPages) {
      $profileEntriesPaged = $profileEntriesTotalPages;
    }
    $profileEntriesOffset = ($profileEntriesPaged - 1) * $profileEntriesPerPage;
    $pagedEntries = array_slice($entries, $profileEntriesOffset, $profileEntriesPerPage);
    $profilePaginationParams = [
      'lm_tab' => 'debug',
    ];

    echo '<details>';
    echo '<summary style="cursor:pointer; font-weight:600;">Show Full Profile Entries</summary>';
    echo '<div style="margin-top:8px;">';
    $this->render_query_pagination('links-manager-settings', 'lm_profile_entries_paged', $profileEntriesPaged, $profileEntriesTotalPages, $profilePaginationParams, $profileEntriesTotal, $profileEntriesPerPage);
    echo '<table class="widefat striped">';
    echo '<thead><tr><th>Phase</th><th style="width:120px;">Elapsed</th><th>Meta</th></tr></thead>';
    echo '<tbody>';
    foreach ($pagedEntries as $entry) {
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
    $this->render_query_pagination('links-manager-settings', 'lm_profile_entries_paged', $profileEntriesPaged, $profileEntriesTotalPages, $profilePaginationParams, $profileEntriesTotal, $profileEntriesPerPage);
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

  private function get_auto_managed_performance_settings() {
    $runtimeMaxBatch = $this->get_runtime_max_crawl_batch();
    $defaultBatch = min(self::CRAWL_POST_BATCH, $runtimeMaxBatch);
    if ($defaultBatch < 20) {
      $defaultBatch = 20;
    }

    return [
      'stats_snapshot_ttl_min' => (string)(int)(self::STATS_SNAPSHOT_TTL / MINUTE_IN_SECONDS),
      'rest_response_cache_ttl_sec' => '90',
      'cache_rebuild_mode' => 'incremental',
      'crawl_post_batch' => (string)$defaultBatch,
      'max_posts_per_rebuild' => '0',
      'performance_preset' => 'auto',
    ];
  }

  private function describe_stats_refresh_minutes($minutes) {
    $minutes = max(1, (int)$minutes);
    $refresh = $this->get_stats_refresh_value_and_period_from_minutes($minutes);
    $value = max(1, (int)($refresh['value'] ?? 1));
    $period = (string)($refresh['period'] ?? 'week');

    if ($period === 'hour') {
      return sprintf(_n('%d hour', '%d hours', $value, 'links-manager'), $value);
    }
    if ($period === 'day') {
      return sprintf(_n('%d day', '%d days', $value, 'links-manager'), $value);
    }
    if ($period === 'month') {
      return sprintf(_n('%d month', '%d months', $value, 'links-manager'), $value);
    }
    return sprintf(_n('%d week', '%d weeks', $value, 'links-manager'), $value);
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

  private function sanitize_anchor_length_thresholds($source) {
    $source = is_array($source) ? $source : [];

    $shortMin = isset($source['lm_anchor_poor_short_min']) ? (int)$source['lm_anchor_poor_short_min'] : 3;
    $longMax = isset($source['lm_anchor_poor_long_max']) ? (int)$source['lm_anchor_poor_long_max'] : 100;

    $shortMin = max(1, min(1000, $shortMin));
    $longMax = max($shortMin, min(1000, $longMax));

    return [
      'short_min' => $shortMin,
      'long_max' => $longMax,
    ];
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

  private function build_post_scan_debug_result($postId) {
    global $wpdb;

    $postId = (int)$postId;
    $post = $postId > 0 ? get_post($postId) : null;
    if (!$post || !isset($post->ID)) {
      return [
        'post_found' => false,
        'post_status' => 'not found',
        'post_type' => '',
        'page_url' => '',
        'content_has_link_markers' => false,
        'has_block_markup' => false,
        'rows_count' => 0,
        'indexed_rows_count' => 0,
        'sample_links' => [],
      ];
    }

    $content = isset($post->post_content) ? (string)$post->post_content : '';
    $contentHasLinkMarkers = ($content !== '') && ((stripos($content, '<a') !== false) || (stripos($content, 'href=') !== false));
    $hasBlockMarkup = strpos($content, '<!-- wp:') !== false;
    $rows = $this->crawl_post($postId, ['content', 'excerpt', 'meta', 'menu']);
    $sampleLinks = array_slice(array_values((array)$rows), 0, 10);
    $needle = trim($this->request_text('lm_debug_link_search', ''));
    $contentContainsSearch = false;
    $matchedLinks = [];
    if ($needle !== '') {
      $contentContainsSearch = strpos(strtolower($content), strtolower($needle)) !== false;
      $needleLower = strtolower($needle);
      foreach ((array)$rows as $row) {
        $haystack = strtolower(
          (string)($row['link'] ?? '') . "\n" .
          (string)($row['anchor_text'] ?? '') . "\n" .
          (string)($row['link_location'] ?? '') . "\n" .
          (string)($row['source'] ?? '')
        );
        if ($haystack !== '' && strpos($haystack, $needleLower) !== false) {
          $matchedLinks[] = $row;
        }
      }
      $matchedLinks = array_slice(array_values($matchedLinks), 0, 20);
    }

    $indexedRowsCount = 0;
    $indexedSearchRowsCount = 0;
    if ($this->is_indexed_datastore_ready()) {
      $factTable = $wpdb->prefix . 'lm_link_fact';
      $indexedRowsCount = (int)$wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $factTable WHERE post_id = %d",
        $postId
      ));
      if ($needle !== '') {
        $like = '%' . $wpdb->esc_like($needle) . '%';
        $indexedSearchRowsCount = (int)$wpdb->get_var($wpdb->prepare(
          "SELECT COUNT(*) FROM $factTable WHERE post_id = %d AND (link LIKE %s OR anchor_text LIKE %s OR link_location LIKE %s OR source LIKE %s)",
          $postId,
          $like,
          $like,
          $like,
          $like
        ));
      }
    }

    return [
      'post_found' => true,
      'post_status' => isset($post->post_status) ? (string)$post->post_status : '',
      'post_type' => isset($post->post_type) ? (string)$post->post_type : '',
      'page_url' => (string)get_permalink($postId),
      'content_has_link_markers' => $contentHasLinkMarkers,
      'has_block_markup' => $hasBlockMarkup,
      'rows_count' => count((array)$rows),
      'indexed_rows_count' => max(0, $indexedRowsCount),
      'content_contains_search' => $contentContainsSearch,
      'search_rows_count' => count((array)$matchedLinks),
      'indexed_search_rows_count' => max(0, $indexedSearchRowsCount),
      'matched_links' => $matchedLinks,
      'sample_links' => $sampleLinks,
    ];
  }

  private function build_pages_link_parity_debug_result($postIdsRaw) {
    $postIdsRaw = trim((string)$postIdsRaw);
    if ($postIdsRaw === '') {
      return null;
    }

    $requestedPostIds = array_values(array_unique(array_filter(array_map('intval', preg_split('/[\s,]+/', $postIdsRaw))))); 
    if (empty($requestedPostIds)) {
      return [
        'requested_ids_label' => $postIdsRaw,
        'matched_posts' => 0,
        'delta_posts' => 0,
        'scope_label' => '—',
        'rows' => [],
      ];
    }

    $filters = $this->get_pages_link_filters_from_request();
    $scopeWpmlLang = $this->get_effective_scan_wpml_lang((string)($filters['wpml_lang'] ?? 'all'));
    $scopeLabel = (string)($filters['post_type'] ?? 'any') . ' / ' . $scopeWpmlLang;

    $rowBasedMap = [];
    $indexedMap = [];

    try {
      $all = $this->get_canonical_rows_for_scope('any', false, isset($filters['wpml_lang']) ? $filters['wpml_lang'] : 'all', $filters);
      $this->compact_rows_for_pages_link($all);
      $rowBasedRows = $this->get_pages_with_inbound_counts($all, $filters, true);
      foreach ((array)$rowBasedRows as $row) {
        $pid = isset($row['post_id']) ? (int)$row['post_id'] : 0;
        if ($pid > 0) {
          $rowBasedMap[$pid] = $row;
        }
      }
    } catch (Throwable $e) {
      $rowBasedMap = [];
    }

    try {
      $indexedRows = $this->get_pages_with_inbound_counts_from_indexed_summary($filters);
      foreach ((array)$indexedRows as $row) {
        $pid = isset($row['post_id']) ? (int)$row['post_id'] : 0;
        if ($pid > 0) {
          $indexedMap[$pid] = $row;
        }
      }
    } catch (Throwable $e) {
      $indexedMap = [];
    }

    $rows = [];
    $deltaPosts = 0;
    foreach ($requestedPostIds as $postId) {
      $rowBased = isset($rowBasedMap[$postId]) && is_array($rowBasedMap[$postId]) ? $rowBasedMap[$postId] : null;
      $indexed = isset($indexedMap[$postId]) && is_array($indexedMap[$postId]) ? $indexedMap[$postId] : null;
      if (!$rowBased && !$indexed) {
        continue;
      }

      $pageUrl = '';
      if ($rowBased && isset($rowBased['page_url'])) {
        $pageUrl = (string)$rowBased['page_url'];
      } elseif ($indexed && isset($indexed['page_url'])) {
        $pageUrl = (string)$indexed['page_url'];
      } else {
        $pageUrl = (string)get_permalink($postId);
      }

      $rowInbound = (int)($rowBased['inbound'] ?? 0);
      $indexedInbound = (int)($indexed['inbound'] ?? 0);
      $rowInternalOutbound = (int)($rowBased['internal_outbound'] ?? 0);
      $indexedInternalOutbound = (int)($indexed['internal_outbound'] ?? 0);
      $rowOutbound = (int)($rowBased['outbound'] ?? 0);
      $indexedOutbound = (int)($indexed['outbound'] ?? 0);

      $deltaFlags = [];
      if ($rowInbound !== $indexedInbound) {
        $deltaFlags[] = 'inbound';
      }
      if ($rowInternalOutbound !== $indexedInternalOutbound) {
        $deltaFlags[] = 'internal_outbound';
      }
      if ($rowOutbound !== $indexedOutbound) {
        $deltaFlags[] = 'outbound';
      }
      if (!empty($deltaFlags)) {
        $deltaPosts++;
      }

      $rows[] = [
        'post_id' => $postId,
        'page_url' => $pageUrl,
        'row_based_inbound' => $rowInbound,
        'indexed_inbound' => $indexedInbound,
        'row_based_internal_outbound' => $rowInternalOutbound,
        'indexed_internal_outbound' => $indexedInternalOutbound,
        'row_based_outbound' => $rowOutbound,
        'indexed_outbound' => $indexedOutbound,
        'delta_flags' => $deltaFlags,
        'delta_flags_label' => !empty($deltaFlags) ? implode(', ', $deltaFlags) : 'match',
      ];
    }

    return [
      'requested_ids_label' => implode(', ', $requestedPostIds),
      'matched_posts' => count($rows),
      'delta_posts' => $deltaPosts,
      'scope_label' => $scopeLabel,
      'rows' => $rows,
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

  private function get_performance_cache_dependency_state($settings) {
    $settings = is_array($settings) ? $settings : [];

    return [
      'stats_snapshot_ttl_min' => (string)($settings['stats_snapshot_ttl_min'] ?? ''),
      'rest_response_cache_ttl_sec' => (string)($settings['rest_response_cache_ttl_sec'] ?? ''),
      'cache_rebuild_mode' => (string)($settings['cache_rebuild_mode'] ?? ''),
      'crawl_post_batch' => (string)($settings['crawl_post_batch'] ?? ''),
      'max_posts_per_rebuild' => (string)($settings['max_posts_per_rebuild'] ?? ''),
      'scan_post_types' => array_values((array)($settings['scan_post_types'] ?? [])),
      'scan_source_types' => array_values((array)($settings['scan_source_types'] ?? [])),
      'scan_value_types' => array_values((array)($settings['scan_value_types'] ?? [])),
      'scan_wpml_langs' => array_values((array)($settings['scan_wpml_langs'] ?? [])),
      'scan_post_category_ids' => array_values((array)($settings['scan_post_category_ids'] ?? [])),
      'scan_post_tag_ids' => array_values((array)($settings['scan_post_tag_ids'] ?? [])),
      'scan_author_ids' => array_values((array)($settings['scan_author_ids'] ?? [])),
      'scan_modified_within_days' => (string)($settings['scan_modified_within_days'] ?? ''),
      'scan_exclude_url_patterns' => (string)($settings['scan_exclude_url_patterns'] ?? ''),
    ];
  }

  private function get_anchor_config_dependency_state($settings) {
    $settings = is_array($settings) ? $settings : [];

    return [
      'anchor_poor_short_min' => (string)($settings['anchor_poor_short_min'] ?? ''),
      'anchor_poor_long_max' => (string)($settings['anchor_poor_long_max'] ?? ''),
      'weak_anchor_patterns' => (string)($settings['weak_anchor_patterns'] ?? ''),
    ];
  }

  public function handle_save_settings() {
    if (!current_user_can('manage_options')) wp_die($this->unauthorized_message());

    $nonce = $this->request_text(self::NONCE_NAME, '');
    if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) wp_die($this->invalid_nonce_message());

    $settings = $this->get_settings();
    $previousPerformanceState = $this->get_performance_cache_dependency_state($settings);
    $previousAnchorConfigState = $this->get_anchor_config_dependency_state($settings);
    $availablePostTypes = $this->get_default_scan_post_types();
    $activeTab = $this->request_key('lm_active_tab', 'general');
    if (!in_array($activeTab, ['general', 'performance', 'status', 'data', 'debug'], true)) {
      $activeTab = 'general';
    }

    $resetGeneralSettings = $this->request_has('lm_reset_general_settings');
    $resetPerformanceSettings = $this->request_has('lm_reset_performance_settings');
    $resetDataSettings = $this->request_has('lm_reset_data_settings');
    $restoredDefaults = $this->request_has('lm_restore_weak_anchor_patterns');
    $clearedAll = $this->request_has('lm_clear_weak_anchor_patterns');

    if ($activeTab === 'general' || $resetGeneralSettings) {
      $allowedRolesRaw = $this->request_array('lm_allowed_roles');
      $settings['allowed_roles'] = $this->sanitize_allowed_roles($allowedRolesRaw);
    }

    if ($activeTab === 'debug') {
      $settings['debug_mode'] = $this->request_has('lm_debug_mode') ? '1' : '0';
    }

    if ($activeTab === 'performance' || $resetPerformanceSettings) {
      $scanPostTypesRaw = $this->request_array('lm_scan_post_types');
      $settings['scan_post_types'] = $this->sanitize_scan_post_types($scanPostTypesRaw);

      $scanSourceTypesRaw = $this->request_array('lm_scan_source_types');
      $settings['scan_source_types'] = $this->sanitize_scan_source_types($scanSourceTypesRaw);

      $scanValueTypesRaw = $this->request_array('lm_scan_value_types');
      $settings['scan_value_types'] = $this->sanitize_scan_value_types($scanValueTypesRaw);

      $scanWpmlLangsRaw = $this->request_array('lm_scan_wpml_langs');
      $settings['scan_wpml_langs'] = $this->sanitize_scan_wpml_langs($scanWpmlLangsRaw);

      $settings['scan_post_category_ids'] = $this->sanitize_int_list($this->request_array('lm_scan_post_category_ids'));
      $settings['scan_post_tag_ids'] = $this->sanitize_int_list($this->request_array('lm_scan_post_tag_ids'));
      $settings['scan_author_ids'] = $this->sanitize_int_list($this->request_array('lm_scan_author_ids'));

      $scanModifiedWithinDays = $this->request_int('lm_scan_modified_within_days', 0);
      if ($scanModifiedWithinDays < 0) $scanModifiedWithinDays = 0;
      if ($scanModifiedWithinDays > 3650) $scanModifiedWithinDays = 3650;
      $settings['scan_modified_within_days'] = (string)$scanModifiedWithinDays;

      $scanExcludePatterns = (string)wp_unslash($this->request_raw('lm_scan_exclude_url_patterns', ''));
      $settings['scan_exclude_url_patterns'] = $this->normalize_multiline_textarea($scanExcludePatterns);

      $settings = array_merge($settings, $this->get_auto_managed_performance_settings());
      $settings['performance_preset'] = 'auto';
    }

    if ($resetGeneralSettings) {
      $settings['allowed_roles'] = ['administrator'];
      $settings['debug_mode'] = '0';
    }

    if ($resetPerformanceSettings) {
      $settings = array_merge($settings, $this->get_auto_managed_performance_settings());
      $settings['scan_post_types'] = $this->get_default_scan_post_types($availablePostTypes);
      $settings['scan_source_types'] = $this->get_default_scan_source_types();
      $settings['scan_value_types'] = $this->get_default_scan_value_types();
      $settings['scan_wpml_langs'] = $this->get_default_scan_wpml_langs();
      $settings['scan_post_category_ids'] = [];
      $settings['scan_post_tag_ids'] = [];
      $settings['scan_author_ids'] = [];
      $settings['scan_modified_within_days'] = '0';
      $settings['scan_exclude_url_patterns'] = implode("\n", $this->get_default_scan_exclude_url_patterns());
    }

    if ($activeTab === 'data' || $resetDataSettings || $restoredDefaults || $clearedAll) {
      $requestSource = $this->get_active_request_input();
      $inboundThresholds = $this->sanitize_inbound_thresholds($requestSource);
      $settings['inbound_orphan_max'] = (string)$inboundThresholds['orphan_max'];
      $settings['inbound_low_max'] = (string)$inboundThresholds['low_max'];
      $settings['inbound_standard_max'] = (string)$inboundThresholds['standard_max'];

      $internalOutboundThresholds = $this->sanitize_outbound_thresholds($requestSource, 'internal');
      $settings['internal_outbound_none_max'] = (string)$internalOutboundThresholds['none_max'];
      $settings['internal_outbound_low_max'] = (string)$internalOutboundThresholds['low_max'];
      $settings['internal_outbound_optimal_max'] = (string)$internalOutboundThresholds['optimal_max'];

      $externalOutboundThresholds = $this->sanitize_outbound_thresholds($requestSource, 'external');
      $settings['external_outbound_none_max'] = (string)$externalOutboundThresholds['none_max'];
      $settings['external_outbound_low_max'] = (string)$externalOutboundThresholds['low_max'];
      $settings['external_outbound_optimal_max'] = (string)$externalOutboundThresholds['optimal_max'];

      $auditRetentionDays = $this->request_int('lm_audit_retention_days', (int)($settings['audit_retention_days'] ?? self::AUDIT_RETENTION_DAYS));
      if ($auditRetentionDays < 30) $auditRetentionDays = 30;
      if ($auditRetentionDays > 3650) $auditRetentionDays = 3650;
      $settings['audit_retention_days'] = (string)$auditRetentionDays;

      $anchorLengthThresholds = $this->sanitize_anchor_length_thresholds($requestSource);
      $settings['anchor_poor_short_min'] = (string)$anchorLengthThresholds['short_min'];
      $settings['anchor_poor_long_max'] = (string)$anchorLengthThresholds['long_max'];

      $weakPatternsRaw = (string)wp_unslash($this->request_raw('lm_weak_anchor_patterns', (string)($settings['weak_anchor_patterns'] ?? '')));
      $normalizedWeakPatterns = $this->normalize_weak_anchor_patterns($weakPatternsRaw);
      if ($clearedAll) {
        $normalizedWeakPatterns = [];
      }
      if ($restoredDefaults) {
        $normalizedWeakPatterns = $this->get_default_weak_anchor_patterns();
      }
      $settings['weak_anchor_patterns'] = implode("\n", $normalizedWeakPatterns);
      $this->weak_anchor_patterns_cache = $normalizedWeakPatterns;
    }

    if ($resetDataSettings) {
      $settings['audit_retention_days'] = (string)self::AUDIT_RETENTION_DAYS;
      $settings['anchor_poor_short_min'] = '3';
      $settings['anchor_poor_long_max'] = '100';
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

    $this->save_settings($settings);

    $currentPerformanceState = $this->get_performance_cache_dependency_state($settings);
    $performanceChanged = ($currentPerformanceState !== $previousPerformanceState);
    $currentAnchorConfigState = $this->get_anchor_config_dependency_state($settings);
    $anchorConfigChanged = ($currentAnchorConfigState !== $previousAnchorConfigState);
    if ($performanceChanged) {
      $this->clear_main_cache_all();
      $this->schedule_background_rebuild('any', 'all', 2);
    }
    if ($anchorConfigChanged) {
      $this->bump_anchor_config_version();
      $this->clear_cache_all();
      $this->schedule_background_rebuild('any', 'all', 2);
    }

    $savedMsg = __('Settings saved.', 'links-manager');
    if ($clearedAll) {
      $savedMsg = __('Settings saved. All weak phrases cleared.', 'links-manager');
    }
    if ($restoredDefaults) {
      $savedMsg = __('Settings saved. Default weak phrases restored.', 'links-manager');
    }
    if ($resetGeneralSettings) {
      $savedMsg = __('Settings reset. General tab restored to defaults.', 'links-manager');
    }
    if ($resetPerformanceSettings) {
      $savedMsg = __('Settings reset. Performance tab restored to defaults and cache refresh queued.', 'links-manager');
    }
    if ($resetDataSettings) {
      $savedMsg = __('Settings reset. Data tab restored to defaults.', 'links-manager');
    }
    if (!$resetPerformanceSettings && $performanceChanged) {
      $savedMsg = __('Settings saved. Performance-related cache was invalidated and refresh queued.', 'links-manager');
    }
    wp_safe_redirect(admin_url('admin.php?page=links-manager-settings&lm_tab=' . rawurlencode($activeTab) . '&lm_msg=' . rawurlencode($savedMsg)));
    exit;
  }

  public function handle_clear_diagnostics() {
    if (!$this->can_access_debug_diagnostics()) wp_die($this->unauthorized_message());

    $nonce = $this->request_text(self::NONCE_NAME, '');
    if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) wp_die($this->invalid_nonce_message());

    delete_option(self::DIAGNOSTIC_OPTION_KEY);
    delete_option(self::RUNTIME_PROFILE_OPTION_KEY);
    wp_safe_redirect(admin_url('admin.php?page=links-manager-settings&lm_msg=' . rawurlencode(__('Diagnostic log cleared.', 'links-manager'))));
    exit;
  }

}
