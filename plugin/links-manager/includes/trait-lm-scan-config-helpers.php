<?php
/**
 * Scan configuration and scope helper methods.
 */

if (!defined('ABSPATH')) {
  exit;
}

trait LM_Scan_Config_Helpers_Trait {
  private function get_available_post_types() {
    $pts = get_post_types(['public' => true], 'objects');
    $out = [];
    foreach ($pts as $pt) {
      $out[$pt->name] = $pt->labels->singular_name;
    }
    return $out;
  }

  private function get_default_scan_post_types($availablePostTypes = null) {
    if (!is_array($availablePostTypes)) {
      $availablePostTypes = $this->get_available_post_types();
    }

    $defaults = [];
    if (isset($availablePostTypes['post'])) {
      $defaults[] = 'post';
    }
    if (isset($availablePostTypes['page'])) {
      $defaults[] = 'page';
    }

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

  private function get_author_users_with_edit_posts() {
    $users = get_users([
      'capability' => 'edit_posts',
      'fields' => ['ID', 'display_name'],
      'orderby' => 'display_name',
      'order' => 'ASC',
    ]);

    if (empty($users) || !is_array($users)) {
      return [];
    }

    return $users;
  }

  private function get_scan_author_options() {
    $users = $this->get_author_users_with_edit_posts();

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
    return $this->scan_meta_keys_cache;
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
    return $this->enabled_scan_value_types_map_cache;
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
}
