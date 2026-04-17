<?php
/**
 * Settings persistence and access control helpers.
 */

if (!defined('ABSPATH')) {
  exit;
}

trait LM_Settings_Access_Trait {
  private function get_settings() {
    $availablePostTypes = $this->get_available_post_types();

    $defaults = [
      'debug_mode' => '0',
      'auto_refresh_enabled' => '1',
      'auto_refresh_frequency' => 'weekly',
      'auto_refresh_hourly_interval' => '1',
      'auto_refresh_time' => '21:00',
      'auto_refresh_weekday' => 'saturday',
      'auto_refresh_monthday' => '1',
      'performance_preset' => 'auto',
      'scan_exclude_defaults_initialized' => '0',
      'stats_snapshot_ttl_min' => (string)(int)(self::STATS_SNAPSHOT_TTL / MINUTE_IN_SECONDS),
      'rest_response_cache_ttl_sec' => '90',
      'crawl_post_batch' => (string)self::CRAWL_POST_BATCH,
      'scan_post_types' => $this->get_default_scan_post_types($availablePostTypes),
      'scan_source_types' => $this->get_default_scan_source_types(),
      'scan_value_types' => $this->get_default_scan_value_types(),
      'scan_wpml_langs' => $this->get_default_scan_wpml_langs(),
      'scan_post_category_ids' => [],
      'scan_post_tag_ids' => [],
      'scan_author_ids' => [],
      'scan_modified_within_days' => '0',
      'scan_exclude_url_patterns' => implode("\n", $this->get_default_scan_exclude_url_patterns()),
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
      'anchor_poor_short_min' => '3',
      'anchor_poor_long_max' => '100',
      'weak_anchor_patterns' => implode("\n", $this->get_default_weak_anchor_patterns()),
      'allowed_roles' => ['administrator'],
      'settings_allowed_roles' => ['administrator'],
    ];
    $opt = get_option('lm_settings', []);
    if (!is_array($opt)) $opt = [];

    if (!isset($opt['allowed_roles']) || !is_array($opt['allowed_roles'])) {
      $opt['allowed_roles'] = ['administrator'];
    }
    if (!isset($opt['settings_allowed_roles']) || !is_array($opt['settings_allowed_roles'])) {
      $opt['settings_allowed_roles'] = ['administrator'];
    }

    $opt['debug_mode'] = (isset($opt['debug_mode']) && (string)$opt['debug_mode'] === '1') ? '1' : '0';
    $opt['auto_refresh_enabled'] = (isset($opt['auto_refresh_enabled']) && (string)$opt['auto_refresh_enabled'] === '0') ? '0' : '1';
    $autoRefreshFrequency = sanitize_key((string)($opt['auto_refresh_frequency'] ?? 'weekly'));
    if (!in_array($autoRefreshFrequency, ['hourly', 'daily', 'weekly', 'monthly'], true)) {
      $autoRefreshFrequency = 'weekly';
    }
    $opt['auto_refresh_frequency'] = $autoRefreshFrequency;

    $autoRefreshHourlyInterval = isset($opt['auto_refresh_hourly_interval']) ? (int)$opt['auto_refresh_hourly_interval'] : 1;
    if ($autoRefreshHourlyInterval < 1) $autoRefreshHourlyInterval = 1;
    if ($autoRefreshHourlyInterval > 24) $autoRefreshHourlyInterval = 24;
    $opt['auto_refresh_hourly_interval'] = (string)$autoRefreshHourlyInterval;

    $autoRefreshTime = isset($opt['auto_refresh_time']) ? (string)$opt['auto_refresh_time'] : '21:00';
    if (!preg_match('/^\d{2}:\d{2}$/', $autoRefreshTime)) {
      $autoRefreshTime = '21:00';
    } else {
      $timeParts = explode(':', $autoRefreshTime);
      $hours = isset($timeParts[0]) ? (int)$timeParts[0] : 21;
      $minutes = isset($timeParts[1]) ? (int)$timeParts[1] : 0;
      if ($hours < 0 || $hours > 23 || $minutes < 0 || $minutes > 59) {
        $autoRefreshTime = '21:00';
      } else {
        $autoRefreshTime = sprintf('%02d:%02d', $hours, $minutes);
      }
    }
    $opt['auto_refresh_time'] = $autoRefreshTime;

    $autoRefreshWeekday = sanitize_key((string)($opt['auto_refresh_weekday'] ?? 'saturday'));
    if (!in_array($autoRefreshWeekday, ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'], true)) {
      $autoRefreshWeekday = 'saturday';
    }
    $opt['auto_refresh_weekday'] = $autoRefreshWeekday;

    $autoRefreshMonthday = isset($opt['auto_refresh_monthday']) ? (int)$opt['auto_refresh_monthday'] : 1;
    if ($autoRefreshMonthday < 1) $autoRefreshMonthday = 1;
    if ($autoRefreshMonthday > 31) $autoRefreshMonthday = 31;
    $opt['auto_refresh_monthday'] = (string)$autoRefreshMonthday;

    $opt['scan_exclude_defaults_initialized'] = (isset($opt['scan_exclude_defaults_initialized']) && (string)$opt['scan_exclude_defaults_initialized'] === '1') ? '1' : '0';
    $opt['performance_preset'] = 'auto';
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

    $scanExcludeSource = isset($opt['scan_exclude_url_patterns']) ? $opt['scan_exclude_url_patterns'] : implode("\n", $this->get_default_scan_exclude_url_patterns());
    if ((string)$opt['scan_exclude_defaults_initialized'] !== '1' && trim((string)$scanExcludeSource) === '') {
      $scanExcludeSource = implode("\n", $this->get_default_scan_exclude_url_patterns());
    }
    $opt['scan_exclude_url_patterns'] = implode("\n", $this->normalize_scan_exclude_url_patterns($scanExcludeSource));

    $restResponseCacheTtlSec = isset($opt['rest_response_cache_ttl_sec']) ? (int)$opt['rest_response_cache_ttl_sec'] : 90;
    if ($restResponseCacheTtlSec < 30) $restResponseCacheTtlSec = 30;
    if ($restResponseCacheTtlSec > 600) $restResponseCacheTtlSec = 600;
    $opt['rest_response_cache_ttl_sec'] = (string)$restResponseCacheTtlSec;

    $maxPostsPerRebuild = isset($opt['max_posts_per_rebuild']) ? (int)$opt['max_posts_per_rebuild'] : 0;
    if ($maxPostsPerRebuild < 0) $maxPostsPerRebuild = 0;
    if ($maxPostsPerRebuild > 50000) $maxPostsPerRebuild = 50000;
    $opt['max_posts_per_rebuild'] = (string)$maxPostsPerRebuild;

    $anchorPoorShortMin = isset($opt['anchor_poor_short_min']) ? (int)$opt['anchor_poor_short_min'] : 3;
    $anchorPoorLongMax = isset($opt['anchor_poor_long_max']) ? (int)$opt['anchor_poor_long_max'] : 100;
    $anchorPoorShortMin = max(1, min(1000, $anchorPoorShortMin));
    $anchorPoorLongMax = max($anchorPoorShortMin, min(1000, $anchorPoorLongMax));
    $opt['anchor_poor_short_min'] = (string)$anchorPoorShortMin;
    $opt['anchor_poor_long_max'] = (string)$anchorPoorLongMax;

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

  private function sanitize_role_selection($roles, $requiredRoles = []) {
    $roles = is_array($roles) ? $roles : [];
    $requiredRoles = is_array($requiredRoles) ? $requiredRoles : [];
    $validRoles = array_keys($this->get_all_roles_map());
    $clean = [];

    foreach ($roles as $role) {
      $roleKey = sanitize_key((string)$role);
      if ($roleKey !== '' && in_array($roleKey, $validRoles, true)) {
        $clean[] = $roleKey;
      }
    }

    foreach ($requiredRoles as $role) {
      $roleKey = sanitize_key((string)$role);
      if ($roleKey !== '' && in_array($roleKey, $validRoles, true)) {
        $clean[] = $roleKey;
      }
    }

    return array_values(array_unique($clean));
  }

  private function get_allowed_roles_from_settings() {
    $settings = $this->get_settings();
    $stored = isset($settings['allowed_roles']) && is_array($settings['allowed_roles']) ? $settings['allowed_roles'] : ['administrator'];
    return $this->sanitize_role_selection($stored, ['administrator']);
  }

  private function get_settings_allowed_roles_from_settings() {
    $settings = $this->get_settings();
    $stored = isset($settings['settings_allowed_roles']) && is_array($settings['settings_allowed_roles']) ? $settings['settings_allowed_roles'] : ['administrator'];
    return $this->sanitize_role_selection($stored, ['administrator']);
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

  private function current_user_can_access_settings() {
    if (!is_user_logged_in()) return false;
    if (current_user_can('manage_options')) return true;

    $allowedRoles = $this->get_settings_allowed_roles_from_settings();
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

  private function save_settings($settings) {
    if (is_array($settings) && array_key_exists('cache_rebuild_mode', $settings)) {
      unset($settings['cache_rebuild_mode']);
    }
    update_option('lm_settings', $settings);
  }
}
