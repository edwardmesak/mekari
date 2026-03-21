<?php
/**
 * Request parsing and admin URL builder helpers.
 */

if (!defined('ABSPATH')) {
  exit;
}

trait LM_Request_URL_Helpers_Trait {
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

  private function get_filters_from_request() {
    $postTypes = $this->get_filterable_post_types();
    $postType = isset($_REQUEST['lm_post_type']) ? sanitize_text_field($_REQUEST['lm_post_type']) : 'any';
    if ($postType !== 'any' && !isset($postTypes[$postType])) $postType = 'any';

    $postCategory = isset($_REQUEST['lm_post_category']) ? $this->sanitize_post_term_filter($_REQUEST['lm_post_category'], 'category') : 0;
    $postTag = isset($_REQUEST['lm_post_tag']) ? $this->sanitize_post_term_filter($_REQUEST['lm_post_tag'], 'post_tag') : 0;
    if ($postType !== 'any' && $postType !== 'post') {
      $postCategory = 0;
      $postTag = 0;
    }

    $filters = [
      'post_type' => $postType,
      'post_category' => $postCategory,
      'post_tag' => $postTag,
      'wpml_lang' => $this->sanitize_wpml_lang_filter($this->get_wpml_current_language()),
      'location' => isset($_REQUEST['lm_location']) ? sanitize_text_field((string)$_REQUEST['lm_location']) : 'any',
      'source_type' => isset($_REQUEST['lm_source_type']) ? $this->sanitize_source_type_filter($_REQUEST['lm_source_type']) : 'any',
      'link_type' => isset($_REQUEST['lm_link_type']) ? sanitize_text_field((string)$_REQUEST['lm_link_type']) : 'any',
      'value_type' => isset($_REQUEST['lm_value_type']) ? sanitize_text_field((string)$_REQUEST['lm_value_type']) : 'any',
      'value_contains' => isset($_REQUEST['lm_value']) ? sanitize_text_field((string)$_REQUEST['lm_value']) : '',
      'source_contains' => isset($_REQUEST['lm_source']) ? sanitize_text_field((string)$_REQUEST['lm_source']) : '',
      'title_contains' => isset($_REQUEST['lm_title']) ? sanitize_text_field((string)$_REQUEST['lm_title']) : '',
      'author_contains' => isset($_REQUEST['lm_author']) ? sanitize_text_field((string)$_REQUEST['lm_author']) : '',
      'publish_date_from' => isset($_REQUEST['lm_publish_date_from']) ? $this->sanitize_date_ymd($_REQUEST['lm_publish_date_from']) : '',
      'publish_date_to' => isset($_REQUEST['lm_publish_date_to']) ? $this->sanitize_date_ymd($_REQUEST['lm_publish_date_to']) : '',
      'updated_date_from' => isset($_REQUEST['lm_updated_date_from']) ? $this->sanitize_date_ymd($_REQUEST['lm_updated_date_from']) : '',
      'updated_date_to' => isset($_REQUEST['lm_updated_date_to']) ? $this->sanitize_date_ymd($_REQUEST['lm_updated_date_to']) : '',
      'anchor_contains' => isset($_REQUEST['lm_anchor']) ? sanitize_text_field((string)$_REQUEST['lm_anchor']) : '',
      'quality' => isset($_REQUEST['lm_quality']) ? sanitize_text_field((string)$_REQUEST['lm_quality']) : 'any',
      'seo_flag' => isset($_REQUEST['lm_seo_flag']) ? sanitize_text_field((string)$_REQUEST['lm_seo_flag']) : 'any',
      'alt_contains' => isset($_REQUEST['lm_alt']) ? sanitize_text_field((string)$_REQUEST['lm_alt']) : '',
      'rel_contains' => isset($_REQUEST['lm_rel']) ? sanitize_text_field((string)$_REQUEST['lm_rel']) : '',
      'text_match_mode' => isset($_REQUEST['lm_text_mode']) ? $this->sanitize_text_match_mode($_REQUEST['lm_text_mode']) : 'contains',
      'rel_nofollow' => isset($_REQUEST['lm_rel_nofollow']) ? sanitize_text_field((string)$_REQUEST['lm_rel_nofollow']) : 'any',
      'rel_sponsored' => isset($_REQUEST['lm_rel_sponsored']) ? sanitize_text_field((string)$_REQUEST['lm_rel_sponsored']) : 'any',
      'rel_ugc' => isset($_REQUEST['lm_rel_ugc']) ? sanitize_text_field((string)$_REQUEST['lm_rel_ugc']) : 'any',
      'orderby' => isset($_REQUEST['lm_orderby']) ? sanitize_text_field((string)$_REQUEST['lm_orderby']) : 'date',
      'order' => isset($_REQUEST['lm_order']) ? strtoupper(sanitize_text_field((string)$_REQUEST['lm_order'])) : 'DESC',
      'per_page' => isset($_REQUEST['lm_per_page']) ? intval($_REQUEST['lm_per_page']) : 25,
      'paged' => isset($_REQUEST['lm_paged']) ? intval($_REQUEST['lm_paged']) : 1,
      'rebuild' => isset($_REQUEST['lm_rebuild']) && sanitize_text_field((string)$_REQUEST['lm_rebuild']) === '1',
      'group' => isset($_REQUEST['lm_group']) ? sanitize_text_field((string)$_REQUEST['lm_group']) : '0',
    ];

    if ($filters['location'] === '') $filters['location'] = 'any';
    if (!in_array($filters['source_type'], array_keys($this->get_filterable_source_type_options(true)), true)) $filters['source_type'] = 'any';
    if (!in_array($filters['link_type'], ['any', 'inlink', 'exlink'], true)) $filters['link_type'] = 'any';
    if (!in_array($filters['value_type'], ['any', 'url', 'relative', 'anchor', 'mailto', 'tel', 'javascript', 'other', 'empty'], true)) $filters['value_type'] = 'any';
    if (!in_array($filters['quality'], ['any', 'good', 'poor', 'bad'], true)) $filters['quality'] = 'any';
    if (!in_array($filters['seo_flag'], ['any', 'dofollow', 'nofollow', 'sponsored', 'ugc'], true)) $filters['seo_flag'] = 'any';
    if (!in_array($filters['rel_nofollow'], ['any', 'yes', 'no'], true)) $filters['rel_nofollow'] = 'any';
    if (!in_array($filters['rel_sponsored'], ['any', 'yes', 'no'], true)) $filters['rel_sponsored'] = 'any';
    if (!in_array($filters['rel_ugc'], ['any', 'yes', 'no'], true)) $filters['rel_ugc'] = 'any';
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
      'lm_publish_date_from' => isset($filters['publish_date_from']) ? $filters['publish_date_from'] : '',
      'lm_publish_date_to' => isset($filters['publish_date_to']) ? $filters['publish_date_to'] : '',
      'lm_updated_date_from' => isset($filters['updated_date_from']) ? $filters['updated_date_from'] : '',
      'lm_updated_date_to' => isset($filters['updated_date_to']) ? $filters['updated_date_to'] : '',
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
      'lm_per_page' => $filters['per_page'],
      'lm_paged' => $filters['paged'],
      'lm_rebuild' => $filters['rebuild'] ? '1' : '0',
    ];
    return admin_url('admin-post.php?' . http_build_query($args));
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
    if (!in_array($linkType, ['any', 'inlink', 'exlink'], true)) $linkType = 'any';
    $valueContains = isset($_REQUEST['lm_pages_link_value']) ? sanitize_text_field((string)$_REQUEST['lm_pages_link_value']) : '';
    $seoFlag = isset($_REQUEST['lm_pages_link_seo_flag']) ? sanitize_text_field((string)$_REQUEST['lm_pages_link_seo_flag']) : 'any';
    if (!in_array($seoFlag, ['any', 'dofollow', 'nofollow', 'sponsored', 'ugc'], true)) $seoFlag = 'any';

    $perPage = isset($_REQUEST['lm_pages_link_per_page']) ? intval($_REQUEST['lm_pages_link_per_page']) : 25;
    if ($perPage < 10) $perPage = 10;
    if ($perPage > 500) $perPage = 500;

    $paged = isset($_REQUEST['lm_pages_link_paged']) ? intval($_REQUEST['lm_pages_link_paged']) : 1;
    if ($paged < 1) $paged = 1;

    $cursor = isset($_REQUEST['lm_pages_link_cursor']) ? sanitize_text_field((string)$_REQUEST['lm_pages_link_cursor']) : '';
    if (strlen($cursor) > 512) {
      $cursor = '';
    }

    $orderby = isset($_REQUEST['lm_pages_link_orderby']) ? sanitize_text_field($_REQUEST['lm_pages_link_orderby']) : 'date';
    if (!in_array($orderby, ['post_id', 'date', 'modified', 'title', 'post_type', 'author', 'page_url', 'inbound', 'internal_outbound', 'outbound', 'status', 'internal_outbound_status', 'external_outbound_status'], true)) $orderby = 'date';

    $order = isset($_REQUEST['lm_pages_link_order']) ? sanitize_text_field($_REQUEST['lm_pages_link_order']) : 'DESC';
    $order = strtoupper($order);
    if (!in_array($order, ['ASC', 'DESC'], true)) $order = 'DESC';

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
    if (!in_array($status, ['any', 'orphan', 'low', 'standard', 'excellent'], true)) $status = 'any';

    $internalOutboundStatus = isset($_REQUEST['lm_pages_link_internal_outbound_status']) ? sanitize_text_field($_REQUEST['lm_pages_link_internal_outbound_status']) : 'any';
    $internalOutboundStatus = strtolower(trim($internalOutboundStatus));
    if (!in_array($internalOutboundStatus, ['any', 'none', 'low', 'optimal', 'excessive'], true)) $internalOutboundStatus = 'any';

    $externalOutboundStatus = isset($_REQUEST['lm_pages_link_external_outbound_status']) ? sanitize_text_field($_REQUEST['lm_pages_link_external_outbound_status']) : 'any';
    $externalOutboundStatus = strtolower(trim($externalOutboundStatus));
    if (!in_array($externalOutboundStatus, ['any', 'none', 'low', 'optimal', 'excessive'], true)) $externalOutboundStatus = 'any';

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
    foreach ($override as $k => $v) $args[$k] = $v;
    return admin_url('admin.php?' . http_build_query($args));
  }

  private function build_pages_link_export_url($filters) {
    $args = [
      'action' => 'lm_export_pages_link_csv',
      self::NONCE_NAME => wp_create_nonce(self::NONCE_ACTION),
    ];
    foreach ($this->pages_link_admin_url_query_args($filters) as $key => $value) {
      if ($key === 'page') continue;
      $args[$key] = $value;
    }
    return admin_url('admin-post.php?' . http_build_query($args));
  }

  private function pages_link_admin_url_query_args($filters) {
    return [
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
      'max_cites' => isset($_REQUEST['lm_cd_max_cites']) ? max(-1, intval($_REQUEST['lm_cd_max_cites'])) : -1,
      'min_pages' => isset($_REQUEST['lm_cd_min_pages']) ? max(0, intval($_REQUEST['lm_cd_min_pages'])) : 0,
      'max_pages' => isset($_REQUEST['lm_cd_max_pages']) ? max(-1, intval($_REQUEST['lm_cd_max_pages'])) : -1,
      'orderby' => isset($_REQUEST['lm_cd_orderby']) ? sanitize_text_field((string)$_REQUEST['lm_cd_orderby']) : 'cites',
      'order' => isset($_REQUEST['lm_cd_order']) ? strtoupper(sanitize_text_field((string)$_REQUEST['lm_cd_order'])) : 'DESC',
      'per_page' => isset($_REQUEST['lm_cd_per_page']) ? intval($_REQUEST['lm_cd_per_page']) : 50,
      'paged' => isset($_REQUEST['lm_cd_paged']) ? intval($_REQUEST['lm_cd_paged']) : 1,
      'rebuild' => isset($_REQUEST['lm_cd_rebuild']) && sanitize_text_field((string)$_REQUEST['lm_cd_rebuild']) === '1',
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
}
