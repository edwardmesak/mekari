<?php
/**
 * Request parsing and admin URL builder helpers.
 */

if (!defined('ABSPATH')) {
  exit;
}

trait LM_Request_URL_Helpers_Trait {
  private $request_override_data = null;

  private function get_active_request_input() {
    if (is_array($this->request_override_data)) {
      return $this->request_override_data;
    }
    return $_REQUEST;
  }

  private function with_request_input($input, $callback) {
    $previous = $this->request_override_data;
    try {
      $this->request_override_data = is_array($input) ? $input : [];
      return call_user_func($callback);
    } finally {
      $this->request_override_data = $previous;
    }
  }

  private function get_editor_filter_request_param_map() {
    return [
      'post_type' => 'lm_post_type',
      'post_category' => 'lm_post_category',
      'post_tag' => 'lm_post_tag',
      'location' => 'lm_location',
      'source_type' => 'lm_source_type',
      'link_type' => 'lm_link_type',
      'value_type' => 'lm_value_type',
      'value_contains' => 'lm_value',
      'source_contains' => 'lm_source',
      'title_contains' => 'lm_title',
      'author_contains' => 'lm_author',
      'publish_date_from' => 'lm_publish_date_from',
      'publish_date_to' => 'lm_publish_date_to',
      'updated_date_from' => 'lm_updated_date_from',
      'updated_date_to' => 'lm_updated_date_to',
      'anchor_contains' => 'lm_anchor',
      'quality' => 'lm_quality',
      'seo_flag' => 'lm_seo_flag',
      'alt_contains' => 'lm_alt',
      'rel_contains' => 'lm_rel',
      'text_match_mode' => 'lm_text_mode',
      'orderby' => 'lm_orderby',
      'order' => 'lm_order',
      'per_page' => 'lm_per_page',
      'paged' => 'lm_paged',
      'rebuild' => 'lm_rebuild',
    ];
  }

  private function get_editor_rest_request_override_map() {
    return [
      'post_type' => 'lm_post_type',
      'post_category' => 'lm_post_category',
      'post_tag' => 'lm_post_tag',
      'location' => 'lm_location',
      'source_type' => 'lm_source_type',
      'link_type' => 'lm_link_type',
      'value_type' => 'lm_value_type',
      'value' => 'lm_value',
      'source' => 'lm_source',
      'title' => 'lm_title',
      'author' => 'lm_author',
      'publish_date_from' => 'lm_publish_date_from',
      'publish_date_to' => 'lm_publish_date_to',
      'updated_date_from' => 'lm_updated_date_from',
      'updated_date_to' => 'lm_updated_date_to',
      'anchor' => 'lm_anchor',
      'quality' => 'lm_quality',
      'seo_flag' => 'lm_seo_flag',
      'alt' => 'lm_alt',
      'rel' => 'lm_rel',
      'text_mode' => 'lm_text_mode',
      'orderby' => 'lm_orderby',
      'order' => 'lm_order',
      'cursor' => 'lm_cursor',
      'rebuild' => 'lm_rebuild',
      'paged' => 'lm_paged',
      'per_page' => 'lm_per_page',
    ];
  }

  private function get_editor_filter_query_args($filters, $override = []) {
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
      'lm_orderby' => $filters['orderby'],
      'lm_order' => $filters['order'],
      'lm_per_page' => $filters['per_page'],
      'lm_paged' => $filters['paged'],
      'lm_rebuild' => $filters['rebuild'] ? '1' : '0',
    ];
    foreach ($override as $k => $v) {
      $args[$k] = $v;
    }
    return $args;
  }

  private function get_pages_link_filter_request_param_map() {
    return [
      'post_type' => 'lm_pages_link_post_type',
      'post_category' => 'lm_pages_link_post_category',
      'post_tag' => 'lm_pages_link_post_tag',
      'author' => 'lm_pages_link_author',
      'search' => 'lm_pages_link_search',
      'search_url' => 'lm_pages_link_search_url',
      'date_from' => 'lm_pages_link_date_from',
      'date_to' => 'lm_pages_link_date_to',
      'updated_date_from' => 'lm_pages_link_updated_date_from',
      'updated_date_to' => 'lm_pages_link_updated_date_to',
      'search_mode' => 'lm_pages_link_search_mode',
      'location' => 'lm_pages_link_location',
      'source_type' => 'lm_pages_link_source_type',
      'link_type' => 'lm_pages_link_link_type',
      'value_contains' => 'lm_pages_link_value',
      'seo_flag' => 'lm_pages_link_seo_flag',
      'per_page' => 'lm_pages_link_per_page',
      'paged' => 'lm_pages_link_paged',
      'cursor' => 'lm_pages_link_cursor',
      'orderby' => 'lm_pages_link_orderby',
      'order' => 'lm_pages_link_order',
      'inbound_min' => 'lm_pages_link_inbound_min',
      'inbound_max' => 'lm_pages_link_inbound_max',
      'internal_outbound_min' => 'lm_pages_link_internal_outbound_min',
      'internal_outbound_max' => 'lm_pages_link_internal_outbound_max',
      'outbound_min' => 'lm_pages_link_outbound_min',
      'outbound_max' => 'lm_pages_link_outbound_max',
      'status' => 'lm_pages_link_status',
      'internal_outbound_status' => 'lm_pages_link_internal_outbound_status',
      'external_outbound_status' => 'lm_pages_link_external_outbound_status',
      'rebuild' => 'lm_pages_link_rebuild',
    ];
  }

  private function get_pages_link_rest_request_override_map() {
    return [
      'post_type' => 'lm_pages_link_post_type',
      'post_category' => 'lm_pages_link_post_category',
      'post_tag' => 'lm_pages_link_post_tag',
      'author' => 'lm_pages_link_author',
      'search' => 'lm_pages_link_search',
      'search_url' => 'lm_pages_link_search_url',
      'date_from' => 'lm_pages_link_date_from',
      'date_to' => 'lm_pages_link_date_to',
      'updated_date_from' => 'lm_pages_link_updated_date_from',
      'updated_date_to' => 'lm_pages_link_updated_date_to',
      'search_mode' => 'lm_pages_link_search_mode',
      'location' => 'lm_pages_link_location',
      'source_type' => 'lm_pages_link_source_type',
      'link_type' => 'lm_pages_link_link_type',
      'value' => 'lm_pages_link_value',
      'value_contains' => 'lm_pages_link_value',
      'seo_flag' => 'lm_pages_link_seo_flag',
      'orderby' => 'lm_pages_link_orderby',
      'order' => 'lm_pages_link_order',
      'inbound_min' => 'lm_pages_link_inbound_min',
      'inbound_max' => 'lm_pages_link_inbound_max',
      'internal_outbound_min' => 'lm_pages_link_internal_outbound_min',
      'internal_outbound_max' => 'lm_pages_link_internal_outbound_max',
      'outbound_min' => 'lm_pages_link_outbound_min',
      'outbound_max' => 'lm_pages_link_outbound_max',
      'status' => 'lm_pages_link_status',
      'internal_outbound_status' => 'lm_pages_link_internal_outbound_status',
      'external_outbound_status' => 'lm_pages_link_external_outbound_status',
      'cursor' => 'lm_pages_link_cursor',
      'rebuild' => 'lm_pages_link_rebuild',
      'paged' => 'lm_pages_link_paged',
      'per_page' => 'lm_pages_link_per_page',
    ];
  }

  private function get_pages_link_filter_query_args($filters, $override = []) {
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
      'lm_pages_link_cursor' => isset($filters['cursor']) ? $filters['cursor'] : '',
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
    foreach ($override as $k => $v) {
      $args[$k] = $v;
    }
    return $args;
  }

  private function get_cited_domains_filter_request_param_map() {
    return [
      'post_type' => 'lm_cd_post_type',
      'post_category' => 'lm_cd_post_category',
      'post_tag' => 'lm_cd_post_tag',
      'search' => 'lm_cd_search',
      'search_mode' => 'lm_cd_search_mode',
      'location' => 'lm_cd_location',
      'source_type' => 'lm_cd_source_type',
      'value_contains' => 'lm_cd_value',
      'source_contains' => 'lm_cd_source',
      'title_contains' => 'lm_cd_title',
      'author_contains' => 'lm_cd_author',
      'anchor_contains' => 'lm_cd_anchor',
      'seo_flag' => 'lm_cd_seo_flag',
      'min_cites' => 'lm_cd_min_cites',
      'max_cites' => 'lm_cd_max_cites',
      'min_pages' => 'lm_cd_min_pages',
      'max_pages' => 'lm_cd_max_pages',
      'orderby' => 'lm_cd_orderby',
      'order' => 'lm_cd_order',
      'per_page' => 'lm_cd_per_page',
      'paged' => 'lm_cd_paged',
      'rebuild' => 'lm_cd_rebuild',
    ];
  }

  private function get_cited_domains_filter_query_args($filters, $override = []) {
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
    foreach ($override as $k => $v) {
      $args[$k] = $v;
    }
    return $args;
  }

  private function get_all_anchor_text_filter_request_param_map() {
    return [
      'post_type' => 'lm_at_post_type',
      'post_category' => 'lm_at_post_category',
      'post_tag' => 'lm_at_post_tag',
      'search' => 'lm_at_search',
      'search_mode' => 'lm_at_search_mode',
      'location' => 'lm_at_location',
      'source_type' => 'lm_at_source_type',
      'link_type' => 'lm_at_link_type',
      'value_contains' => 'lm_at_value',
      'source_contains' => 'lm_at_source',
      'title_contains' => 'lm_at_title',
      'author_contains' => 'lm_at_author',
      'seo_flag' => 'lm_at_seo_flag',
      'usage_type' => 'lm_at_usage_type',
      'quality' => 'lm_at_quality',
      'group' => 'lm_at_group',
      'min_total' => 'lm_at_min_total',
      'max_total' => 'lm_at_max_total',
      'min_inlink' => 'lm_at_min_inlink',
      'max_inlink' => 'lm_at_max_inlink',
      'min_outbound' => 'lm_at_min_outbound',
      'max_outbound' => 'lm_at_max_outbound',
      'min_pages' => 'lm_at_min_pages',
      'max_pages' => 'lm_at_max_pages',
      'min_destinations' => 'lm_at_min_destinations',
      'max_destinations' => 'lm_at_max_destinations',
      'orderby' => 'lm_at_orderby',
      'order' => 'lm_at_order',
      'per_page' => 'lm_at_per_page',
      'paged' => 'lm_at_paged',
      'rebuild' => 'lm_at_rebuild',
    ];
  }

  private function get_all_anchor_text_filter_query_args($filters, $override = []) {
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
    foreach ($override as $k => $v) {
      $args[$k] = $v;
    }
    return $args;
  }

  private function safe_redirect_back($filters, $extra = []) {
    $url = $this->base_admin_url($filters, array_merge(['lm_paged' => 1], $extra));
    wp_safe_redirect($url);
    exit;
  }

  private function sanitize_post_term_filter($rawValue, $taxonomy) {
    $termId = intval($rawValue);
    if ($termId <= 0) return 0;

    $options = $this->get_post_term_options($taxonomy, true);
    if (!isset($options[$termId])) return 0;

    return $termId;
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

    $query = new WP_Query([
      'post_type' => 'post',
      'post_status' => 'publish',
      'posts_per_page' => -1,
      'fields' => 'ids',
      'no_found_rows' => true,
      'tax_query' => $taxQuery,
    ]);

    if (empty($query->posts) || !is_array($query->posts)) {
      return [];
    }

    $map = [];
    foreach ($query->posts as $postId) {
      $map[(string)intval($postId)] = true;
    }

    return $map;
  }

  private function request_has($key) {
    if (!is_string($key) || $key === '') {
      return false;
    }
    $input = $this->get_active_request_input();
    return isset($input[$key]);
  }

  private function request_raw($key, $default = null) {
    if (!$this->request_has($key)) {
      return $default;
    }
    $input = $this->get_active_request_input();
    return $input[$key];
  }

  private function request_text($key, $default = '') {
    if (!$this->request_has($key)) {
      return (string)$default;
    }
    return sanitize_text_field((string)$this->request_raw($key, ''));
  }

  private function request_key($key, $default = '') {
    if (!$this->request_has($key)) {
      return sanitize_key((string)$default);
    }
    return sanitize_key((string)$this->request_raw($key, ''));
  }

  private function request_array($key) {
    if (!$this->request_has($key)) {
      return [];
    }
    $value = wp_unslash($this->request_raw($key, []));
    if (is_array($value)) {
      return $value;
    }
    if ($value === '' || $value === null) {
      return [];
    }
    return [$value];
  }

  private function request_int($key, $default = 0) {
    if (!$this->request_has($key)) {
      return (int)$default;
    }
    return intval($this->request_raw($key, $default));
  }

  private function request_int_or_default($key, $default = -1, $min = -1) {
    if (!$this->request_has($key)) {
      return (int)$default;
    }
    $raw = trim((string)$this->request_raw($key, ''));
    if ($raw === '') {
      return (int)$default;
    }
    $value = intval($raw);
    if ($value < $min) {
      return (int)$min;
    }
    return $value;
  }

  private function request_bool_flag($key) {
    return $this->request_text($key, '0') === '1';
  }

  private function request_date_ymd($key, $default = '') {
    if (!$this->request_has($key)) {
      return (string)$default;
    }
    return $this->sanitize_date_ymd($this->request_raw($key, ''));
  }

  private function request_text_mode($key, $default = 'contains') {
    if (!$this->request_has($key)) {
      return $this->sanitize_text_match_mode($default);
    }
    return $this->sanitize_text_match_mode($this->request_raw($key, $default));
  }

  private function request_source_type($key, $default = 'any') {
    if (!$this->request_has($key)) {
      return $this->sanitize_source_type_filter($default);
    }
    return $this->sanitize_source_type_filter($this->request_raw($key, $default));
  }

  private function request_enum($key, $allowed, $default = 'any', $normalizeOrAliases = null) {
    $value = strtolower(trim($this->request_text($key, $default)));
    if (is_array($normalizeOrAliases) && isset($normalizeOrAliases[$value])) {
      $value = $normalizeOrAliases[$value];
    } elseif (is_callable($normalizeOrAliases)) {
      $value = (string)call_user_func($normalizeOrAliases, $value);
    }
    if (!in_array($value, $allowed, true)) {
      return (string)$default;
    }
    return $value;
  }

  private function get_filters_from_request() {
    $paramMap = $this->get_editor_filter_request_param_map();
    $postTypes = $this->get_filterable_post_types();
    $postTypeParam = $paramMap['post_type'];
    $postType = $this->request_text($postTypeParam, 'any');
    if ($postType !== 'any' && !isset($postTypes[$postType])) $postType = 'any';

    $postCategoryParam = $paramMap['post_category'];
    $postTagParam = $paramMap['post_tag'];
    $postCategory = $this->request_has($postCategoryParam) ? $this->sanitize_post_term_filter($this->request_raw($postCategoryParam, 0), 'category') : 0;
    $postTag = $this->request_has($postTagParam) ? $this->sanitize_post_term_filter($this->request_raw($postTagParam, 0), 'post_tag') : 0;
    if ($postType !== 'any' && $postType !== 'post') {
      $postCategory = 0;
      $postTag = 0;
    }

    $filters = [
      'post_type' => $postType,
      'post_category' => $postCategory,
      'post_tag' => $postTag,
      'wpml_lang' => $this->sanitize_wpml_lang_filter($this->get_wpml_current_language()),
      'location' => $this->request_text($paramMap['location'], 'any'),
      'source_type' => $this->request_source_type($paramMap['source_type'], 'any'),
      'link_type' => $this->request_text($paramMap['link_type'], 'any'),
      'value_type' => $this->request_text($paramMap['value_type'], 'any'),
      'value_contains' => $this->request_text($paramMap['value_contains'], ''),
      'source_contains' => $this->request_text($paramMap['source_contains'], ''),
      'title_contains' => $this->request_text($paramMap['title_contains'], ''),
      'author_contains' => $this->request_text($paramMap['author_contains'], ''),
      'publish_date_from' => $this->request_date_ymd($paramMap['publish_date_from'], ''),
      'publish_date_to' => $this->request_date_ymd($paramMap['publish_date_to'], ''),
      'updated_date_from' => $this->request_date_ymd($paramMap['updated_date_from'], ''),
      'updated_date_to' => $this->request_date_ymd($paramMap['updated_date_to'], ''),
      'anchor_contains' => $this->request_text($paramMap['anchor_contains'], ''),
      'quality' => $this->request_text($paramMap['quality'], 'any'),
      'seo_flag' => $this->request_text($paramMap['seo_flag'], 'any'),
      'alt_contains' => $this->request_text($paramMap['alt_contains'], ''),
      'rel_contains' => $this->request_text($paramMap['rel_contains'], ''),
      'text_match_mode' => $this->request_text_mode($paramMap['text_match_mode'], 'contains'),
      'orderby' => $this->request_text($paramMap['orderby'], 'date'),
      'order' => strtoupper($this->request_text($paramMap['order'], 'DESC')),
      'per_page' => $this->request_int($paramMap['per_page'], 25),
      'paged' => $this->request_int($paramMap['paged'], 1),
      'rebuild' => $this->request_bool_flag($paramMap['rebuild']),
      'group' => $this->request_text('lm_group', '0'),
    ];

    if ($filters['location'] === '') $filters['location'] = 'any';
    if (!in_array($filters['source_type'], array_keys($this->get_filterable_source_type_options(true)), true)) $filters['source_type'] = 'any';
    if (!in_array($filters['link_type'], ['any', 'inlink', 'exlink'], true)) $filters['link_type'] = 'any';
    if (!in_array($filters['value_type'], ['any', 'url', 'relative', 'anchor', 'mailto', 'tel', 'javascript', 'other', 'empty'], true)) $filters['value_type'] = 'any';
    if (!in_array($filters['quality'], ['any', 'good', 'poor', 'bad'], true)) $filters['quality'] = 'any';
    if (!in_array($filters['seo_flag'], ['any', 'dofollow', 'nofollow', 'sponsored', 'ugc'], true)) $filters['seo_flag'] = 'any';
    if (!in_array($filters['orderby'], ['date', 'title', 'post_type', 'post_author', 'page_url', 'link', 'source', 'link_location', 'anchor_text', 'quality', 'link_type', 'seo_flags', 'alt_text', 'count'], true)) $filters['orderby'] = 'date';
    if (!in_array($filters['order'], ['ASC', 'DESC'], true)) $filters['order'] = 'DESC';
    if ($filters['per_page'] < 10) $filters['per_page'] = 10;
    if ($filters['per_page'] > 500) $filters['per_page'] = 500;
    if ($filters['paged'] < 1) $filters['paged'] = 1;

    return $filters;
  }

  private function build_export_url($filters) {
    $args = [
      'action' => 'lm_export_csv',
      self::NONCE_NAME => wp_create_nonce(self::NONCE_ACTION),
    ];
    foreach ($this->get_editor_filter_query_args($filters) as $key => $value) {
      if (in_array($key, ['page', 'lm_per_page', 'lm_paged'], true)) continue;
      $args[$key] = $value;
    }
    return admin_url('admin-post.php?' . http_build_query($args));
  }

  private function base_admin_url($filters, $override = []) {
    $args = $this->get_editor_filter_query_args($filters, $override);
    return admin_url('admin.php?' . http_build_query($args));
  }

  private function get_pages_link_filters_from_request() {
    $paramMap = $this->get_pages_link_filter_request_param_map();
    $postTypes = $this->get_filterable_post_types();
    $postType = $this->request_text($paramMap['post_type'], 'any');
    if ($postType !== 'any' && !isset($postTypes[$postType])) $postType = 'any';

    $postCategory = $this->request_has($paramMap['post_category']) ? $this->sanitize_post_term_filter($this->request_raw($paramMap['post_category'], 0), 'category') : 0;
    $postTag = $this->request_has($paramMap['post_tag']) ? $this->sanitize_post_term_filter($this->request_raw($paramMap['post_tag'], 0), 'post_tag') : 0;

    if ($postType !== 'any' && $postType !== 'post') {
      $postCategory = 0;
      $postTag = 0;
    }

    $author = $this->request_int($paramMap['author'], 0);
    if ($author < 0) $author = 0;

    $search = $this->request_text($paramMap['search'], '');
    $search_url = $this->request_text($paramMap['search_url'], '');
    $dateFrom = $this->request_date_ymd($paramMap['date_from'], '');
    $dateTo = $this->request_date_ymd($paramMap['date_to'], '');
    $updatedDateFrom = $this->request_date_ymd($paramMap['updated_date_from'], '');
    $updatedDateTo = $this->request_date_ymd($paramMap['updated_date_to'], '');
    $searchMode = $this->request_text_mode($paramMap['search_mode'], 'contains');
    $location = $this->request_text($paramMap['location'], 'any');
    if ($location === '') $location = 'any';
    $sourceType = $this->request_source_type($paramMap['source_type'], 'any');
    $linkType = $this->request_text($paramMap['link_type'], 'any');
    if (!in_array($linkType, ['any', 'inlink', 'exlink'], true)) $linkType = 'any';
    $valueContains = $this->request_text($paramMap['value_contains'], '');
    $seoFlag = $this->request_text($paramMap['seo_flag'], 'any');
    if (!in_array($seoFlag, ['any', 'dofollow', 'nofollow', 'sponsored', 'ugc'], true)) $seoFlag = 'any';

    $perPage = $this->request_int($paramMap['per_page'], 25);
    if ($perPage < 10) $perPage = 10;
    if ($perPage > 500) $perPage = 500;

    $paged = $this->request_int($paramMap['paged'], 1);
    if ($paged < 1) $paged = 1;

    $cursor = $this->request_text($paramMap['cursor'], '');
    if (strlen($cursor) > 512) {
      $cursor = '';
    }

    $orderby = $this->request_text($paramMap['orderby'], 'date');
    if (!in_array($orderby, ['post_id', 'date', 'modified', 'title', 'post_type', 'author', 'page_url', 'inbound', 'internal_outbound', 'outbound', 'status', 'internal_outbound_status', 'external_outbound_status'], true)) $orderby = 'date';

    $order = $this->request_text($paramMap['order'], 'DESC');
    $order = strtoupper($order);
    if (!in_array($order, ['ASC', 'DESC'], true)) $order = 'DESC';

    $inboundMin = $this->request_int_or_default($paramMap['inbound_min'], -1, -1);
    $inboundMax = $this->request_int_or_default($paramMap['inbound_max'], -1, -1);

    $internalOutboundMin = $this->request_int_or_default($paramMap['internal_outbound_min'], -1, -1);
    $internalOutboundMax = $this->request_int_or_default($paramMap['internal_outbound_max'], -1, -1);

    $outboundMin = $this->request_int_or_default($paramMap['outbound_min'], -1, -1);
    $outboundMax = $this->request_int_or_default($paramMap['outbound_max'], -1, -1);

    $status = $this->request_enum(
      $paramMap['status'],
      ['any', 'orphan', 'low', 'standard', 'excellent'],
      'any',
      ['orphaned' => 'orphan']
    );

    $internalOutboundStatus = $this->request_enum(
      $paramMap['internal_outbound_status'],
      ['any', 'none', 'low', 'optimal', 'excessive'],
      'any'
    );

    $externalOutboundStatus = $this->request_enum(
      $paramMap['external_outbound_status'],
      ['any', 'none', 'low', 'optimal', 'excessive'],
      'any'
    );

    $rebuild = $this->request_bool_flag($paramMap['rebuild']);

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
      'cursor' => $cursor,
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
    $args = $this->get_pages_link_filter_query_args($filters, $override);
    return admin_url('admin.php?' . http_build_query($args));
  }

  private function build_pages_link_export_url($filters) {
    $args = [
      'action' => 'lm_export_pages_link_csv',
      self::NONCE_NAME => wp_create_nonce(self::NONCE_ACTION),
    ];
    foreach ($this->pages_link_admin_url_query_args($filters) as $key => $value) {
      if (in_array($key, ['page', 'lm_pages_link_per_page', 'lm_pages_link_paged', 'lm_pages_link_cursor'], true)) continue;
      $args[$key] = $value;
    }
    return admin_url('admin-post.php?' . http_build_query($args));
  }

  private function pages_link_admin_url_query_args($filters) {
    return $this->get_pages_link_filter_query_args($filters);
  }

  private function cited_domains_admin_url($filters, $override = []) {
    $args = $this->get_cited_domains_filter_query_args($filters, $override);
    return admin_url('admin.php?' . http_build_query($args));
  }

  private function build_cited_domains_export_url($filters) {
    $args = [
      'action' => 'lm_export_cited_domains_csv',
      self::NONCE_NAME => wp_create_nonce(self::NONCE_ACTION),
    ];
    foreach ($this->get_cited_domains_filter_query_args($filters) as $key => $value) {
      if (in_array($key, ['page', 'lm_cd_per_page', 'lm_cd_paged'], true)) continue;
      if ($key === 'lm_cd_value') continue;
      $args[$key] = $value;
    }
    return admin_url('admin-post.php?' . http_build_query($args));
  }

  private function get_cited_domains_filters_from_request() {
    $paramMap = $this->get_cited_domains_filter_request_param_map();
    $postTypes = $this->get_filterable_post_types();
    $postType = $this->request_key($paramMap['post_type'], 'any');
    if ($postType !== 'any' && !isset($postTypes[$postType])) $postType = 'any';

    $postCategory = $this->request_has($paramMap['post_category']) ? $this->sanitize_post_term_filter($this->request_raw($paramMap['post_category'], 0), 'category') : 0;
    $postTag = $this->request_has($paramMap['post_tag']) ? $this->sanitize_post_term_filter($this->request_raw($paramMap['post_tag'], 0), 'post_tag') : 0;
    if ($postType !== 'any' && $postType !== 'post') {
      $postCategory = 0;
      $postTag = 0;
    }

    $filters = [
      'post_type' => $postType,
      'post_category' => $postCategory,
      'post_tag' => $postTag,
      'wpml_lang' => $this->sanitize_wpml_lang_filter($this->get_wpml_current_language()),
      'search' => $this->request_text($paramMap['search'], ''),
      'search_mode' => $this->request_text_mode($paramMap['search_mode'], 'contains'),
      'location' => $this->request_text($paramMap['location'], 'any'),
      'source_type' => $this->request_source_type($paramMap['source_type'], 'any'),
      'value_contains' => $this->request_text($paramMap['value_contains'], ''),
      'source_contains' => $this->request_text($paramMap['source_contains'], ''),
      'title_contains' => $this->request_text($paramMap['title_contains'], ''),
      'author_contains' => $this->request_text($paramMap['author_contains'], ''),
      'anchor_contains' => $this->request_text($paramMap['anchor_contains'], ''),
      'seo_flag' => $this->request_text($paramMap['seo_flag'], 'any'),
      'min_cites' => max(0, $this->request_int_or_default($paramMap['min_cites'], 0, 0)),
      'max_cites' => $this->request_int_or_default($paramMap['max_cites'], -1, -1),
      'min_pages' => max(0, $this->request_int_or_default($paramMap['min_pages'], 0, 0)),
      'max_pages' => $this->request_int_or_default($paramMap['max_pages'], -1, -1),
      'orderby' => $this->request_text($paramMap['orderby'], 'cites'),
      'order' => strtoupper($this->request_text($paramMap['order'], 'DESC')),
      'per_page' => $this->request_int($paramMap['per_page'], 25),
      'paged' => $this->request_int($paramMap['paged'], 1),
      'rebuild' => $this->request_bool_flag($paramMap['rebuild']),
    ];

    if ($filters['location'] === '') $filters['location'] = 'any';
    if (!in_array($filters['seo_flag'], ['any', 'dofollow', 'nofollow', 'sponsored', 'ugc'], true)) $filters['seo_flag'] = 'any';
    if (!in_array($filters['orderby'], ['domain', 'cites', 'pages', 'sample_url'], true)) $filters['orderby'] = 'cites';
    if (!in_array($filters['order'], ['ASC', 'DESC'], true)) $filters['order'] = 'DESC';
    if ($filters['per_page'] < 10) $filters['per_page'] = 10;
    if ($filters['per_page'] > 500) $filters['per_page'] = 500;
    if ($filters['paged'] < 1) $filters['paged'] = 1;

    return $filters;
  }

  private function get_all_anchor_text_filters_from_request() {
    $paramMap = $this->get_all_anchor_text_filter_request_param_map();
    $postTypes = $this->get_filterable_post_types();
    $postType = $this->request_key($paramMap['post_type'], 'any');
    if ($postType !== 'any' && !isset($postTypes[$postType])) $postType = 'any';

    $postCategory = $this->request_has($paramMap['post_category']) ? $this->sanitize_post_term_filter($this->request_raw($paramMap['post_category'], 0), 'category') : 0;
    $postTag = $this->request_has($paramMap['post_tag']) ? $this->sanitize_post_term_filter($this->request_raw($paramMap['post_tag'], 0), 'post_tag') : 0;
    if ($postType !== 'any' && $postType !== 'post') {
      $postCategory = 0;
      $postTag = 0;
    }

    $filters = [
      'post_type' => $postType,
      'post_category' => $postCategory,
      'post_tag' => $postTag,
      'wpml_lang' => $this->sanitize_wpml_lang_filter($this->get_wpml_current_language()),
      'search' => $this->request_text($paramMap['search'], ''),
      'search_mode' => $this->request_text_mode($paramMap['search_mode'], 'contains'),
      'location' => $this->request_text($paramMap['location'], 'any'),
      'source_type' => $this->request_source_type($paramMap['source_type'], 'any'),
      'link_type' => $this->request_text($paramMap['link_type'], 'any'),
      'value_contains' => $this->request_text($paramMap['value_contains'], ''),
      'source_contains' => $this->request_text($paramMap['source_contains'], ''),
      'title_contains' => $this->request_text($paramMap['title_contains'], ''),
      'author_contains' => $this->request_text($paramMap['author_contains'], ''),
      'seo_flag' => $this->request_text($paramMap['seo_flag'], 'any'),
      'usage_type' => $this->request_text($paramMap['usage_type'], 'any'),
      'quality' => $this->request_text($paramMap['quality'], 'any'),
      'group' => $this->request_text($paramMap['group'], 'any'),
      'min_total' => max(0, $this->request_int_or_default($paramMap['min_total'], 0, 0)),
      'max_total' => $this->request_int_or_default($paramMap['max_total'], -1, -1),
      'min_inlink' => max(0, $this->request_int_or_default($paramMap['min_inlink'], 0, 0)),
      'max_inlink' => $this->request_int_or_default($paramMap['max_inlink'], -1, -1),
      'min_outbound' => max(0, $this->request_int_or_default($paramMap['min_outbound'], 0, 0)),
      'max_outbound' => $this->request_int_or_default($paramMap['max_outbound'], -1, -1),
      'min_pages' => max(0, $this->request_int_or_default($paramMap['min_pages'], 0, 0)),
      'max_pages' => $this->request_int_or_default($paramMap['max_pages'], -1, -1),
      'min_destinations' => max(0, $this->request_int_or_default($paramMap['min_destinations'], 0, 0)),
      'max_destinations' => $this->request_int_or_default($paramMap['max_destinations'], -1, -1),
      'orderby' => $this->request_text($paramMap['orderby'], 'total'),
      'order' => strtoupper($this->request_text($paramMap['order'], 'DESC')),
      'per_page' => $this->request_int($paramMap['per_page'], 25),
      'paged' => $this->request_int($paramMap['paged'], 1),
      'rebuild' => $this->request_bool_flag($paramMap['rebuild']),
    ];

    if ((string)$filters['location'] === '') $filters['location'] = 'any';
    if (!in_array($filters['link_type'], ['any', 'inlink', 'exlink'], true)) $filters['link_type'] = 'any';
    if (!in_array($filters['seo_flag'], ['any', 'dofollow', 'nofollow', 'sponsored', 'ugc'], true)) $filters['seo_flag'] = 'any';
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
    $args = $this->get_all_anchor_text_filter_query_args($filters, $override);
    return admin_url('admin.php?' . http_build_query($args));
  }

  private function build_all_anchor_text_export_url($filters) {
    $args = [
      'action' => 'lm_export_all_anchor_text_csv',
      self::NONCE_NAME => wp_create_nonce(self::NONCE_ACTION),
    ];
    foreach ($this->get_all_anchor_text_filter_query_args($filters) as $key => $value) {
      if (in_array($key, ['page', 'lm_at_per_page', 'lm_at_paged'], true)) continue;
      $args[$key] = $value;
    }
    return admin_url('admin-post.php?' . http_build_query($args));
  }
}
