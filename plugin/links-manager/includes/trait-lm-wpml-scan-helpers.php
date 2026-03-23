<?php
/**
 * WPML-aware scan helpers and language resolution utilities.
 */

if (!defined('ABSPATH')) {
  exit;
}

trait LM_WPML_Scan_Helpers_Trait {
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

  private function get_requested_view_wpml_lang($requestedLang) {
    $requestedLang = $this->sanitize_wpml_lang_filter($requestedLang);

    if ($requestedLang !== '') {
      return $requestedLang;
    }

    return 'all';
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

    $current = $this->request_text('lang', '');
    if (!is_string($current) || trim($current) === '') {
      $current = $this->safe_wpml_apply_filters('wpml_current_language', null);
    }

    $current = $this->sanitize_wpml_lang_filter($current);
    return $current === '' ? 'all' : $current;
  }
}
