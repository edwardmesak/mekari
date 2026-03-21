<?php
/**
 * Links Target admin page rendering helpers.
 */

if (!defined('ABSPATH')) {
  exit;
}

trait LM_Links_Target_Admin_Trait {
  private function get_links_target_summary_filters_from_request($groupNames, $postTypeOptions) {
    $summaryGroupsRaw = $this->request_array('lm_summary_groups');
    if (empty($summaryGroupsRaw) && $this->request_has('lm_summary_group')) {
      $legacySummaryGroup = trim($this->request_text('lm_summary_group', ''));
      if ($legacySummaryGroup !== '') {
        $summaryGroupsRaw = [$legacySummaryGroup];
      }
    }

    $summaryGroupSelected = [];
    foreach ($summaryGroupsRaw as $item) {
      $item = trim(sanitize_text_field((string)$item));
      if ($item === '') continue;
      if ($item === 'no_group' || in_array($item, $groupNames, true)) {
        $summaryGroupSelected[$item] = true;
      }
    }

    $summaryPostType = $this->request_key('lm_summary_post_type', 'any');
    if ($summaryPostType !== 'any' && !isset($postTypeOptions[$summaryPostType])) {
      $summaryPostType = 'any';
    }

    $summaryPostCategory = $this->request_has('lm_summary_post_category') ? $this->sanitize_post_term_filter($this->request_raw('lm_summary_post_category', 0), 'category') : 0;
    $summaryPostTag = $this->request_has('lm_summary_post_tag') ? $this->sanitize_post_term_filter($this->request_raw('lm_summary_post_tag', 0), 'post_tag') : 0;
    if ($summaryPostType !== 'any' && $summaryPostType !== 'post') {
      $summaryPostCategory = 0;
      $summaryPostTag = 0;
    }

    $summaryLocation = $this->request_text('lm_summary_location', 'any');
    if ($summaryLocation === '') $summaryLocation = 'any';

    $summarySourceType = $this->request_source_type('lm_summary_source_type', 'any');
    $summaryLinkType = $this->request_text('lm_summary_link_type', 'any');
    if (!in_array($summaryLinkType, ['any', 'inlink', 'exlink'], true)) $summaryLinkType = 'any';

    $summarySearchMode = $this->request_text_mode('lm_summary_search_mode', 'contains');
    $summaryAnchor = $this->request_text('lm_summary_anchor', '');
    $summaryAnchorSearch = $this->request_text('lm_summary_anchor_search', '');
    if ($summaryAnchorSearch === '' && $summaryAnchor !== '') {
      $summaryAnchorSearch = $summaryAnchor;
      $summarySearchMode = 'exact';
    }

    $summarySeoFlag = $this->request_text('lm_summary_seo_flag', 'any');
    if (!in_array($summarySeoFlag, ['any', 'dofollow', 'nofollow', 'sponsored', 'ugc'], true)) $summarySeoFlag = 'any';

    $summaryOrderby = $this->request_text('lm_summary_orderby', 'anchor');
    if (!in_array($summaryOrderby, ['group', 'anchor', 'total', 'inlink', 'outbound'], true)) $summaryOrderby = 'anchor';
    $summaryOrder = strtoupper($this->request_text('lm_summary_order', 'ASC'));
    if (!in_array($summaryOrder, ['ASC', 'DESC'], true)) $summaryOrder = 'ASC';

    $summaryPerPage = $this->request_int('lm_summary_per_page', 25);
    if ($summaryPerPage < 10) $summaryPerPage = 10;
    if ($summaryPerPage > 500) $summaryPerPage = 500;
    $summaryPaged = $this->request_int('lm_summary_paged', 1);
    if ($summaryPaged < 1) $summaryPaged = 1;

    return [
      'summary_groups' => array_keys($summaryGroupSelected),
      'summary_group_search' => $this->request_text('lm_summary_group_search', ''),
      'summary_anchor' => $summaryAnchor,
      'summary_anchor_search' => $summaryAnchorSearch,
      'summary_search_mode' => $summarySearchMode,
      'summary_post_type' => $summaryPostType,
      'summary_post_category' => $summaryPostCategory,
      'summary_post_tag' => $summaryPostTag,
      'summary_location' => $summaryLocation,
      'summary_source_type' => $summarySourceType,
      'summary_link_type' => $summaryLinkType,
      'summary_value' => $this->request_text('lm_summary_value', ''),
      'summary_source' => $this->request_text('lm_summary_source', ''),
      'summary_title' => $this->request_text('lm_summary_title', ''),
      'summary_author' => $this->request_text('lm_summary_author', ''),
      'summary_seo_flag' => $summarySeoFlag,
      'summary_total_min' => $this->request_text('lm_summary_total_min', ''),
      'summary_total_max' => $this->request_text('lm_summary_total_max', ''),
      'summary_in_min' => $this->request_text('lm_summary_in_min', ''),
      'summary_in_max' => $this->request_text('lm_summary_in_max', ''),
      'summary_out_min' => $this->request_text('lm_summary_out_min', ''),
      'summary_out_max' => $this->request_text('lm_summary_out_max', ''),
      'summary_orderby' => $summaryOrderby,
      'summary_order' => $summaryOrder,
      'summary_per_page' => $summaryPerPage,
      'summary_paged' => $summaryPaged,
    ];
  }

  private function get_links_target_summary_query_args($summaryState, $override = []) {
    $args = [
      'page' => 'links-manager-target',
      'lm_summary_groups' => isset($summaryState['summary_groups']) ? (array)$summaryState['summary_groups'] : [],
      'lm_summary_group_search' => isset($summaryState['summary_group_search']) ? $summaryState['summary_group_search'] : '',
      'lm_summary_post_type' => isset($summaryState['summary_post_type']) ? $summaryState['summary_post_type'] : 'any',
      'lm_summary_post_category' => isset($summaryState['summary_post_category']) ? $summaryState['summary_post_category'] : 0,
      'lm_summary_post_tag' => isset($summaryState['summary_post_tag']) ? $summaryState['summary_post_tag'] : 0,
      'lm_summary_location' => isset($summaryState['summary_location']) ? $summaryState['summary_location'] : 'any',
      'lm_summary_source_type' => isset($summaryState['summary_source_type']) ? $summaryState['summary_source_type'] : 'any',
      'lm_summary_link_type' => isset($summaryState['summary_link_type']) ? $summaryState['summary_link_type'] : 'any',
      'lm_summary_value' => isset($summaryState['summary_value']) ? $summaryState['summary_value'] : '',
      'lm_summary_source' => isset($summaryState['summary_source']) ? $summaryState['summary_source'] : '',
      'lm_summary_title' => isset($summaryState['summary_title']) ? $summaryState['summary_title'] : '',
      'lm_summary_author' => isset($summaryState['summary_author']) ? $summaryState['summary_author'] : '',
      'lm_summary_seo_flag' => isset($summaryState['summary_seo_flag']) ? $summaryState['summary_seo_flag'] : 'any',
      'lm_summary_anchor' => isset($summaryState['summary_anchor']) ? $summaryState['summary_anchor'] : '',
      'lm_summary_anchor_search' => isset($summaryState['summary_anchor_search']) ? $summaryState['summary_anchor_search'] : '',
      'lm_summary_search_mode' => isset($summaryState['summary_search_mode']) ? $summaryState['summary_search_mode'] : 'contains',
      'lm_summary_total_min' => isset($summaryState['summary_total_min']) ? $summaryState['summary_total_min'] : '',
      'lm_summary_total_max' => isset($summaryState['summary_total_max']) ? $summaryState['summary_total_max'] : '',
      'lm_summary_in_min' => isset($summaryState['summary_in_min']) ? $summaryState['summary_in_min'] : '',
      'lm_summary_in_max' => isset($summaryState['summary_in_max']) ? $summaryState['summary_in_max'] : '',
      'lm_summary_out_min' => isset($summaryState['summary_out_min']) ? $summaryState['summary_out_min'] : '',
      'lm_summary_out_max' => isset($summaryState['summary_out_max']) ? $summaryState['summary_out_max'] : '',
      'lm_summary_orderby' => isset($summaryState['summary_orderby']) ? $summaryState['summary_orderby'] : 'anchor',
      'lm_summary_order' => isset($summaryState['summary_order']) ? $summaryState['summary_order'] : 'ASC',
      'lm_summary_per_page' => isset($summaryState['summary_per_page']) ? $summaryState['summary_per_page'] : 25,
    ];
    foreach ($override as $key => $value) {
      $args[$key] = $value;
    }
    return $args;
  }

  private function links_target_usage_cache_key($filters, $anchorKeys) {
    $payload = [
      'filters' => is_array($filters) ? $filters : [],
      'anchors' => array_values(array_map('strtolower', (array)$anchorKeys)),
      'blog_id' => get_current_blog_id(),
      'version' => (int)get_option('lm_stats_snapshot_version', 1),
      'dataset_version' => $this->get_dataset_cache_version(),
    ];
    return 'lm_links_target_usage_' . md5(wp_json_encode($payload));
  }

  private function links_target_grouping_cache_key($groupOrderby, $groupOrder, $groupFilterSelected, $groupSearch, $groupSearchMode) {
    $payload = [
      'orderby' => (string)$groupOrderby,
      'order' => (string)$groupOrder,
      'group_filter' => array_values((array)$groupFilterSelected),
      'group_search' => (string)$groupSearch,
      'group_search_mode' => (string)$groupSearchMode,
      'blog_id' => get_current_blog_id(),
      'dataset_version' => $this->get_dataset_cache_version(),
      'anchor_config_version' => $this->get_anchor_config_version(),
      'wpml_lang' => $this->sanitize_wpml_lang_filter($this->get_wpml_current_language()),
    ];
    return 'lm_links_target_grouping_' . md5(wp_json_encode($payload));
  }

  private function links_target_summary_cache_key($filters, $targetKeys, $summaryPage, $summaryPerPage, $summaryOrderby, $summaryOrder, $baseFilters = []) {
    $payload = [
      'filters' => is_array($filters) ? $filters : [],
      'base_filters' => is_array($baseFilters) ? $baseFilters : [],
      'targets' => array_values(array_map('strtolower', (array)$targetKeys)),
      'page' => max(1, (int)$summaryPage),
      'per_page' => max(10, (int)$summaryPerPage),
      'orderby' => (string)$summaryOrderby,
      'order' => (string)$summaryOrder,
      'blog_id' => get_current_blog_id(),
      'version' => (int)get_option('lm_stats_snapshot_version', 1),
      'dataset_version' => $this->get_dataset_cache_version(),
      'anchor_config_version' => $this->get_anchor_config_version(),
    ];
    return 'lm_links_target_summary_' . md5(wp_json_encode($payload));
  }

  private function links_target_summary_base_cache_key($filters, $targetKeys) {
    $payload = [
      'filters' => is_array($filters) ? $filters : [],
      'targets' => array_values(array_map('strtolower', (array)$targetKeys)),
      'blog_id' => get_current_blog_id(),
      'dataset_version' => $this->get_dataset_cache_version(),
      'anchor_config_version' => $this->get_anchor_config_version(),
    ];
    return 'lm_links_target_summary_base_' . md5(wp_json_encode($payload));
  }

  private function get_links_target_anchor_usage_map($filters, $anchorKeys = []) {
    $filters = is_array($filters) ? $filters : [];
    $anchorKeys = array_values(array_unique(array_filter(array_map('strval', (array)$anchorKeys), static function($value) {
      return trim($value) !== '';
    })));
    $cacheKey = $this->links_target_usage_cache_key($filters, $anchorKeys);
    $cached = get_transient($cacheKey);
    if (is_array($cached)) {
      return $cached;
    }

    if (!empty($anchorKeys) && $this->is_indexed_datastore_ready()) {
      $summaryFilters = $filters;
      $anchorKeyMap = [];
      foreach ($anchorKeys as $anchorKey) {
        $anchorKeyMap[strtolower(trim($anchorKey))] = true;
      }

      $parts = $this->build_indexed_custom_aggregation_query_parts($summaryFilters, 'any');
      if (is_array($parts)) {
        global $wpdb;
        $table = (string)$parts['table'];
        $whereSql = (string)$parts['where_sql'];
        $params = (array)$parts['params'];
        $anchorList = array_keys($anchorKeyMap);
        if (!empty($anchorList)) {
          $placeholders = implode(',', array_fill(0, count($anchorList), '%s'));
          $whereSql .= " AND anchor_text IN ($placeholders)";
          foreach ($anchorList as $anchorKey) {
            $params[] = $anchorKey;
          }
        }

        $sql = "SELECT
          LOWER(anchor_text) AS anchor_key,
          COUNT(*) AS total,
          SUM(CASE WHEN link_type = 'inlink' THEN 1 ELSE 0 END) AS inlink,
          SUM(CASE WHEN link_type = 'exlink' THEN 1 ELSE 0 END) AS outbound
          FROM $table
          $whereSql
          AND TRIM(COALESCE(anchor_text, '')) <> ''
          GROUP BY anchor_key";

        $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
        $counts = [];
        foreach ((array)$rows as $row) {
          $key = strtolower(trim((string)($row['anchor_key'] ?? '')));
          if ($key === '') {
            continue;
          }
          $counts[$key] = [
            'total' => (int)($row['total'] ?? 0),
            'inlink' => (int)($row['inlink'] ?? 0),
            'outbound' => (int)($row['outbound'] ?? 0),
          ];
        }
        set_transient($cacheKey, $counts, self::CACHE_TTL);
        return $counts;
      }
    }

    $indexedSummaryRows = $this->get_indexed_all_anchor_text_summary_rows($filters);
    if (!empty($indexedSummaryRows)) {
      $counts = [];
      foreach ($indexedSummaryRows as $summaryRow) {
        $key = strtolower(trim((string)($summaryRow['anchor_text'] ?? '')));
        if ($key === '') {
          continue;
        }
        $counts[$key] = [
          'total' => (int)($summaryRow['total'] ?? 0),
          'inlink' => (int)($summaryRow['inlink'] ?? 0),
          'outbound' => (int)($summaryRow['outbound'] ?? 0),
        ];
      }
      set_transient($cacheKey, $counts, self::CACHE_TTL);
      return $counts;
    }

    $all = $this->get_canonical_rows_for_scope(
      isset($filters['post_type']) ? (string)$filters['post_type'] : 'any',
      false,
      isset($filters['wpml_lang']) ? (string)$filters['wpml_lang'] : $this->sanitize_wpml_lang_filter($this->get_wpml_current_language()),
      $filters
    );

    $allowedSummaryPostIds = $this->get_post_ids_by_post_terms(
      isset($filters['post_category']) ? (int)$filters['post_category'] : 0,
      isset($filters['post_tag']) ? (int)$filters['post_tag'] : 0
    );

    $counts = [];
    foreach ((array)$all as $row) {
      if (is_array($allowedSummaryPostIds)) {
        $rowPostId = isset($row['post_id']) ? (string)intval($row['post_id']) : '';
        if ($rowPostId === '' || !isset($allowedSummaryPostIds[$rowPostId])) continue;
      }
      if (isset($filters['post_type']) && (string)$filters['post_type'] !== 'any' && (string)($row['post_type'] ?? '') !== (string)$filters['post_type']) continue;
      if (isset($filters['location']) && (string)$filters['location'] !== 'any' && (string)($row['link_location'] ?? '') !== (string)$filters['location']) continue;
      if (isset($filters['source_type']) && (string)$filters['source_type'] !== 'any' && (string)($row['source'] ?? '') !== (string)$filters['source_type']) continue;
      if (isset($filters['link_type']) && (string)$filters['link_type'] !== 'any' && (string)($row['link_type'] ?? '') !== (string)$filters['link_type']) continue;
      if (!empty($filters['value_contains']) && !$this->text_matches((string)($row['link'] ?? ''), (string)$filters['value_contains'], (string)($filters['search_mode'] ?? 'contains'))) continue;
      if (!empty($filters['source_contains']) && !$this->text_matches((string)($row['page_url'] ?? ''), (string)$filters['source_contains'], (string)($filters['search_mode'] ?? 'contains'))) continue;
      if (!empty($filters['title_contains']) && !$this->text_matches((string)($row['post_title'] ?? ''), (string)$filters['title_contains'], (string)($filters['search_mode'] ?? 'contains'))) continue;
      if (!empty($filters['author_contains']) && !$this->text_matches((string)($row['post_author'] ?? ''), (string)$filters['author_contains'], (string)($filters['search_mode'] ?? 'contains'))) continue;
      if (isset($filters['seo_flag']) && (string)$filters['seo_flag'] !== 'any') {
        $nofollow = (string)($row['rel_nofollow'] ?? '0') === '1';
        $sponsored = (string)($row['rel_sponsored'] ?? '0') === '1';
        $ugc = (string)($row['rel_ugc'] ?? '0') === '1';
        if ($filters['seo_flag'] === 'dofollow' && ($nofollow || $sponsored || $ugc)) continue;
        if ($filters['seo_flag'] === 'nofollow' && !$nofollow) continue;
        if ($filters['seo_flag'] === 'sponsored' && !$sponsored) continue;
        if ($filters['seo_flag'] === 'ugc' && !$ugc) continue;
      }

      $anchor = trim((string)($row['anchor_text'] ?? ''));
      if ($anchor === '') continue;
      $key = strtolower($anchor);
      if (!isset($counts[$key])) $counts[$key] = ['total' => 0, 'inlink' => 0, 'outbound' => 0];
      $counts[$key]['total']++;
      if (($row['link_type'] ?? '') === 'inlink') $counts[$key]['inlink']++;
      if (($row['link_type'] ?? '') === 'exlink') $counts[$key]['outbound']++;
    }

    set_transient($cacheKey, $counts, self::CACHE_TTL);
    return $counts;
  }

  private function get_links_target_summary_base_rows($summaryTargetsMap, $anchorToGroups, $counts, $targetIndexByKey, $baseFilters) {
    $cacheKey = $this->links_target_summary_base_cache_key($baseFilters, array_keys((array)$summaryTargetsMap));
    $cached = get_transient($cacheKey);
    if (is_array($cached)) {
      return $cached;
    }

    $baseRows = [];
    foreach ((array)$summaryTargetsMap as $tKey => $tLabel) {
      $c = $counts[$tKey] ?? ['total' => 0, 'inlink' => 0, 'outbound' => 0];
      $glist = isset($anchorToGroups[$tKey]) ? implode(', ', array_keys($anchorToGroups[$tKey])) : '—';
      if (isset($anchorToGroups[$tKey]) && is_array($anchorToGroups[$tKey]) && count($anchorToGroups[$tKey]) > 0) {
        $keys = array_keys($anchorToGroups[$tKey]);
        $currentGroup = (string)$keys[0];
      } else {
        $currentGroup = 'no_group';
      }
      $idx = isset($targetIndexByKey[$tKey]) ? (int)$targetIndexByKey[$tKey] : -1;

      $baseRows[] = [
        'tKey' => $tKey,
        'tLabel' => $tLabel,
        'c' => $c,
        'glist' => $glist,
        'currentGroup' => $currentGroup,
        'idx' => $idx,
      ];
    }

    set_transient($cacheKey, $baseRows, self::CACHE_TTL);
    return $baseRows;
  }

  private function get_links_target_summary_rows($summaryTargetsMap, $anchorToGroups, $counts, $summaryGroupSelected, $summaryGroupSearch, $summaryAnchorSearch, $summarySearchMode, $summaryTotalMinNum, $summaryTotalMaxNum, $summaryInMinNum, $summaryInMaxNum, $summaryOutMinNum, $summaryOutMaxNum, $summaryOrderby, $summaryOrder, $summaryPaged, $summaryPerPage, $targetIndexByKey, $baseFilters = []) {
    $cacheKey = $this->links_target_summary_cache_key(
      [
        'group_selected' => $summaryGroupSelected,
        'group_search' => $summaryGroupSearch,
        'anchor_search' => $summaryAnchorSearch,
        'search_mode' => $summarySearchMode,
        'min_total' => $summaryTotalMinNum,
        'max_total' => $summaryTotalMaxNum,
        'min_inlink' => $summaryInMinNum,
        'max_inlink' => $summaryInMaxNum,
        'min_outbound' => $summaryOutMinNum,
        'max_outbound' => $summaryOutMaxNum,
      ],
      array_keys((array)$summaryTargetsMap),
      $summaryPaged,
      $summaryPerPage,
      $summaryOrderby,
      $summaryOrder,
      $baseFilters
    );

    $cached = get_transient($cacheKey);
    if (is_array($cached)) {
      return $cached;
    }

    $baseRows = $this->get_links_target_summary_base_rows(
      $summaryTargetsMap,
      $anchorToGroups,
      $counts,
      $targetIndexByKey,
      $baseFilters
    );

    $filteredRows = [];
    foreach ((array)$baseRows as $baseRow) {
      $tKey = (string)($baseRow['tKey'] ?? '');
      $tLabel = (string)($baseRow['tLabel'] ?? '');
      $c = isset($baseRow['c']) && is_array($baseRow['c']) ? $baseRow['c'] : ['total' => 0, 'inlink' => 0, 'outbound' => 0];
      $glist = (string)($baseRow['glist'] ?? '—');
      $groupSearchText = $glist !== '—' ? $glist : 'No Group';
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

      $filteredRows[] = [
        'tKey' => $tKey,
        'tLabel' => $tLabel,
        'c' => $c,
        'glist' => $glist,
        'currentGroup' => (string)($baseRow['currentGroup'] ?? 'no_group'),
        'idx' => (int)($baseRow['idx'] ?? -1),
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
    $summaryPaged = min(max(1, (int)$summaryPaged), $totalPages);
    $offset = ($summaryPaged - 1) * $summaryPerPage;
    $pagedRows = array_slice($filteredRows, $offset, $summaryPerPage);

    $result = [
      'paged_rows' => $pagedRows,
      'total_filtered' => $totalFiltered,
      'total_pages' => $totalPages,
      'summary_paged' => $summaryPaged,
      'summary_per_page' => $summaryPerPage,
    ];

    set_transient($cacheKey, $result, self::CACHE_TTL);
    return $result;
  }

  private function get_links_target_grouping_rows($groups, $targetsMap, $groupOrderby, $groupOrder, $groupFilterSelected, $groupSearch, $groupSearchMode) {
    $cacheKey = $this->links_target_grouping_cache_key($groupOrderby, $groupOrder, $groupFilterSelected, $groupSearch, $groupSearchMode);
    $cached = get_transient($cacheKey);
    if (is_array($cached)) {
      return $cached;
    }

    $groupIndexByName = [];
    $groupedKeys = [];
    $groupCounts = [];
    $groupUsage = [];
    $totalAnchors = 0;

    $anchorUsage = $this->get_links_target_anchor_usage_map([
      'post_type' => 'any',
      'post_category' => 0,
      'post_tag' => 0,
      'wpml_lang' => $this->sanitize_wpml_lang_filter($this->get_wpml_current_language()),
      'location' => 'any',
      'source_type' => 'any',
      'link_type' => 'any',
      'value_contains' => '',
      'source_contains' => '',
      'title_contains' => '',
      'author_contains' => '',
      'seo_flag' => 'any',
      'search_mode' => 'contains',
    ], array_keys((array)$targetsMap));

    foreach ((array)$groups as $idx => $g) {
      $gname = trim((string)($g['name'] ?? ''));
      if ($gname === '') {
        continue;
      }
      if (!isset($groupIndexByName[$gname])) {
        $groupIndexByName[$gname] = $idx;
      }
      $anchors = isset($g['anchors']) ? (array)$g['anchors'] : [];
      $count = 0;
      $gUsage = ['total' => 0, 'inlink' => 0, 'outbound' => 0];
      foreach ($anchors as $a) {
        $a = trim((string)$a);
        if ($a === '') {
          continue;
        }
        $count++;
        $k = strtolower($a);
        $groupedKeys[$k] = true;
        if (isset($anchorUsage[$k])) {
          $gUsage['total'] += (int)$anchorUsage[$k]['total'];
          $gUsage['inlink'] += (int)$anchorUsage[$k]['inlink'];
          $gUsage['outbound'] += (int)$anchorUsage[$k]['outbound'];
        }
      }
      $groupCounts[$gname] = $count;
      $groupUsage[$gname] = $gUsage;
      $totalAnchors += $count;
    }

    foreach ((array)$targetsMap as $k => $label) {
      if (isset($groupedKeys[$k])) {
        continue;
      }
      if (!isset($groupCounts['No Group'])) {
        $groupCounts['No Group'] = 0;
      }
      if (!isset($groupUsage['No Group'])) {
        $groupUsage['No Group'] = ['total' => 0, 'inlink' => 0, 'outbound' => 0];
      }
      $groupCounts['No Group']++;
      if (isset($anchorUsage[$k])) {
        $groupUsage['No Group']['total'] += (int)$anchorUsage[$k]['total'];
        $groupUsage['No Group']['inlink'] += (int)$anchorUsage[$k]['inlink'];
        $groupUsage['No Group']['outbound'] += (int)$anchorUsage[$k]['outbound'];
      }
      $totalAnchors++;
    }

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
    foreach ($filteredGroupUsage as $usage) {
      $totalUsage += (int)$usage['total'];
      $totalInlinks += (int)$usage['inlink'];
      $totalOutbound += (int)$usage['outbound'];
    }

    $entries = [];
    $sumBase = 0;
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

    $result = [
      'entries' => $entries,
      'group_usage' => $groupUsage,
      'group_index_by_name' => $groupIndexByName,
      'total_anchors' => $totalAnchors,
    ];

    set_transient($cacheKey, $result, self::CACHE_TTL);
    return $result;
  }

  public function render_admin_links_target_page() {
    if (!$this->current_user_can_access_plugin()) wp_die($this->unauthorized_message());

    $msg = $this->request_text('lm_msg', '');
    $msgClass = $this->notice_class_for_message($msg, 'success');
    $groupOrderby = $this->request_text('lm_group_orderby', 'tag');
    if (!in_array($groupOrderby, ['tag', 'total_anchors', 'total_usage', 'inlink_usage', 'outbound_usage'], true)) $groupOrderby = 'tag';
    $groupOrder = strtoupper($this->request_text('lm_group_order', 'ASC'));
    if (!in_array($groupOrder, ['ASC', 'DESC'], true)) $groupOrder = 'ASC';
    $groups = $this->get_anchor_groups();
    $groupNames = [];
    foreach ($groups as $g) {
      $gname = trim((string)($g['name'] ?? ''));
      if ($gname !== '') $groupNames[] = $gname;
    }
    $groupNames = array_values(array_unique($groupNames));
    $groupFilterRaw = $this->request_array('lm_group_filter');
    $groupFilterSelected = [];
    foreach ($groupFilterRaw as $item) {
      $item = trim(sanitize_text_field((string)$item));
      if ($item === '') continue;
      if ($item === 'no_group' || in_array($item, $groupNames, true)) {
        $groupFilterSelected[$item] = true;
      }
    }
    $groupFilterSelected = array_keys($groupFilterSelected);
    $groupSearch = $this->request_text('lm_group_search', '');
    $groupSearchMode = $this->request_text_mode('lm_group_search_mode', 'contains');
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
    $this->render_admin_page_header(
      __('Links Manager - Links Target', 'links-manager'),
      __('Manage anchor groups, target phrases, and usage summaries in one responsive workspace.', 'links-manager')
    );
    if ($msg !== '') echo '<div class="notice notice-' . esc_attr($msgClass) . '"><p>' . esc_html($msg) . '</p></div>';

    echo '<div class="lm-grid">';
    echo '<div class="lm-card lm-card-full">';
    $this->render_admin_section_intro(
      __('Anchor Text Target Grouping', 'links-manager'),
      __('Review grouped anchors, total usage, and internal versus outbound usage before editing or exporting groups.', 'links-manager')
    );
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
    echo '<div class="lm-filter-actions">';
    submit_button('Apply', 'secondary', 'submit', false);
    echo '<a class="button button-secondary" href="' . esc_url($groupingExportUrl) . '">Export CSV</a>';
    echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=links-manager-target')) . '">Reset Filter</a>';
    echo '</div>';
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
    $entries = [];
    $groupUsage = [];
    $groupIndexByName = [];
    if (!empty($groups)) {
      foreach ($groups as $g) {
        $anchors = isset($g['anchors']) ? (array)$g['anchors'] : [];
        foreach ($anchors as $a) {
          $a = trim((string)$a);
          if ($a === '') continue;
          $k = strtolower($a);
          if (!isset($groupAnchorsMap[$k])) $groupAnchorsMap[$k] = $a;
        }
      }

      $groupingPack = $this->get_links_target_grouping_rows(
        $groups,
        $targetsMap,
        $groupOrderby,
        $groupOrder,
        $groupFilterSelected,
        $groupSearch,
        $groupSearchMode
      );
      $entries = is_array($groupingPack['entries'] ?? null) ? $groupingPack['entries'] : [];
      $groupUsage = is_array($groupingPack['group_usage'] ?? null) ? $groupingPack['group_usage'] : [];
      $groupIndexByName = is_array($groupingPack['group_index_by_name'] ?? null) ? $groupingPack['group_index_by_name'] : [];
    }

    $groupPerPage = 25;
    $groupPaged = max(1, $this->request_int('lm_group_paged', 1));
    $groupTotal = count($entries);
    $groupTotalPages = max(1, (int)ceil(max(1, $groupTotal) / $groupPerPage));
    if ($groupPaged > $groupTotalPages) {
      $groupPaged = $groupTotalPages;
    }
    $groupOffset = ($groupPaged - 1) * $groupPerPage;
    $groupEntries = array_slice($entries, $groupOffset, $groupPerPage);
    $groupPaginationParams = [
      'lm_group_orderby' => $groupOrderby,
      'lm_group_order' => $groupOrder,
      'lm_group_search' => $groupSearch,
      'lm_group_search_mode' => $groupSearchMode,
      'lm_group_filter' => $groupFilterSelected,
    ];

    $this->render_query_pagination('links-manager-target', 'lm_group_paged', $groupPaged, $groupTotalPages, $groupPaginationParams, $groupTotal, $groupPerPage);
    echo '<div class="lm-table-wrap lm-summary-table-wrap">';
    echo '<table class="widefat striped lm-table">';
    echo '<thead><tr>';
    echo '<th class="lm-col-block"><input type="checkbox" id="lm-select-all-groups" /></th>';
    echo $this->table_header_with_tooltip('lm-col-postid', '#', 'Row number in current result page.', 'left');
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

    if (empty($entries)) {
      echo '<tr><td colspan="11">No groups yet.</td></tr>';
    } else {
      $groupRowNo = $groupOffset + 1;
      foreach ($groupEntries as $e) {
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
        echo '<td class="lm-col-postid">' . esc_html((string)$groupRowNo) . '</td>';
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
        $groupRowNo++;
      }
    }

    echo '</tbody></table></div>';
    $this->render_query_pagination('links-manager-target', 'lm_group_paged', $groupPaged, $groupTotalPages, $groupPaginationParams, $groupTotal, $groupPerPage);

    $editGroupIdx = $this->request_int('lm_edit_group', -1);
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
    $this->render_admin_section_intro(
      __('Target Anchor Text', 'links-manager'),
      __('Add anchor targets to monitor across public content, either as plain phrases or grouped entries.', 'links-manager')
    );
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
    submit_button(__('Save Targets', 'links-manager'), 'secondary', 'submit', false);
    echo '</form>';

    $editIdx = $this->request_int('lm_edit_target', -1);
    if ($editIdx >= 0 && isset($targets[$editIdx])) {
      echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:10px; padding:10px; border:1px solid #e5e7eb; border-radius:6px; background:#f9fafb;">';
      echo '<input type="hidden" name="action" value="lm_update_anchor_target"/>';
      echo '<input type="hidden" name="' . esc_attr(self::NONCE_NAME) . '" value="' . esc_attr(wp_create_nonce(self::NONCE_ACTION)) . '"/>';
      echo '<input type="hidden" name="lm_target_idx" value="' . esc_attr((string)$editIdx) . '"/>';
      echo '<label class="lm-small" style="display:block; margin-bottom:6px;">Edit target:</label>';
      echo '<input type="text" name="lm_target_value" value="' . esc_attr((string)$targets[$editIdx]) . '" class="regular-text" />';
      submit_button(__('Save Changes', 'links-manager'), 'primary', 'submit', false);
      echo ' <a class="button" href="' . esc_url(admin_url('admin.php?page=links-manager-target')) . '">Cancel</a>';
      echo '</form>';
    }

    echo '</div>';
    echo '<div class="lm-card lm-card-summary lm-card-full">';
    $this->render_admin_section_intro(
      __('Anchor Text Target', 'links-manager'),
      __('Review tracked targets, grouped coverage, and usage totals across the current filtered content set.', 'links-manager')
    );
    echo '<form id="lm-bulk-delete-targets-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:0 0 8px;">';
    echo '<input type="hidden" name="action" value="lm_bulk_delete_anchor_targets"/>';
    echo '<input type="hidden" name="' . esc_attr(self::NONCE_NAME) . '" value="' . esc_attr(wp_create_nonce(self::NONCE_ACTION)) . '"/>';
    submit_button(__('Delete Selected Targets', 'links-manager'), 'delete', 'submit', false, ['onclick' => "return confirm('" . esc_js(__('Delete selected targets?', 'links-manager')) . "');"]);
    echo '</form>';
    $summaryPostTypeOptions = $this->get_filterable_post_types();
    $summaryPostCategoryOptions = $this->get_post_term_options('category');
    $summaryPostTagOptions = $this->get_post_term_options('post_tag');
    $summaryState = $this->get_links_target_summary_filters_from_request($groupNames, $summaryPostTypeOptions);
    $summaryGroupSelected = $summaryState['summary_groups'];
    $summaryGroupSearch = $summaryState['summary_group_search'];
    $summaryAnchor = $summaryState['summary_anchor'];
    $summaryAnchorSearch = $summaryState['summary_anchor_search'];
    $summarySearchMode = $summaryState['summary_search_mode'];
    $summaryPostType = $summaryState['summary_post_type'];
    $summaryPostCategory = $summaryState['summary_post_category'];
    $summaryPostTag = $summaryState['summary_post_tag'];
    $summaryLocation = $summaryState['summary_location'];
    $summarySourceType = $summaryState['summary_source_type'];
    $summaryLinkType = $summaryState['summary_link_type'];
    $summaryValueContains = $summaryState['summary_value'];
    $summarySourceContains = $summaryState['summary_source'];
    $summaryTitleContains = $summaryState['summary_title'];
    $summaryAuthorContains = $summaryState['summary_author'];
    $summarySeoFlag = $summaryState['summary_seo_flag'];
    $summaryTotalMin = $summaryState['summary_total_min'];
    $summaryTotalMax = $summaryState['summary_total_max'];
    $summaryInMin = $summaryState['summary_in_min'];
    $summaryInMax = $summaryState['summary_in_max'];
    $summaryOutMin = $summaryState['summary_out_min'];
    $summaryOutMax = $summaryState['summary_out_max'];
    $summaryOrderby = $summaryState['summary_orderby'];
    $summaryOrder = $summaryState['summary_order'];
    $summaryPerPage = $summaryState['summary_per_page'];
    $summaryPaged = $summaryState['summary_paged'];
    $summaryTotalMinNum = $summaryTotalMin === '' ? null : intval($summaryTotalMin);
    $summaryTotalMaxNum = $summaryTotalMax === '' ? null : intval($summaryTotalMax);
    $summaryInMinNum = $summaryInMin === '' ? null : intval($summaryInMin);
    $summaryInMaxNum = $summaryInMax === '' ? null : intval($summaryInMax);
    $summaryOutMinNum = $summaryOutMin === '' ? null : intval($summaryOutMin);
    $summaryOutMaxNum = $summaryOutMax === '' ? null : intval($summaryOutMax);
    $summaryExportUrl = add_query_arg(array_merge([
      'action' => 'lm_export_links_target_csv',
      self::NONCE_NAME => wp_create_nonce(self::NONCE_ACTION),
    ], $this->get_links_target_summary_query_args($summaryState)), admin_url('admin-post.php'));
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
    echo '<div class="lm-small" style="margin-bottom:6px;">' . esc_html__('Search Group Name', 'links-manager') . '</div>';
    echo '<input type="text" name="lm_summary_group_search" value="' . esc_attr($summaryGroupSearch) . '" class="regular-text" placeholder="group keyword" />';
    echo '</div>';

    echo '<div class="lm-filter-field">';
    echo '<div class="lm-small" style="margin-bottom:6px;">' . esc_html__('Search Destination URL', 'links-manager') . '</div>';
    echo '<input type="text" name="lm_summary_value" value="' . esc_attr($summaryValueContains) . '" class="regular-text" placeholder="example.com / /contact" />';
    echo '</div>';

    echo '<div class="lm-filter-field">';
    echo '<div class="lm-small" style="margin-bottom:6px;">' . esc_html__('Search Source URL', 'links-manager') . '</div>';
    echo '<input type="text" name="lm_summary_source" value="' . esc_attr($summarySourceContains) . '" class="regular-text" placeholder="/category /slug" />';
    echo '</div>';

    echo '<div class="lm-filter-field">';
    echo '<div class="lm-small" style="margin-bottom:6px;">' . esc_html__('Search Title', 'links-manager') . '</div>';
    echo '<input type="text" name="lm_summary_title" value="' . esc_attr($summaryTitleContains) . '" class="regular-text" placeholder="post title" />';
    echo '</div>';

    echo '<div class="lm-filter-field">';
    echo '<div class="lm-small" style="margin-bottom:6px;">' . esc_html__('Search Author', 'links-manager') . '</div>';
    echo '<input type="text" name="lm_summary_author" value="' . esc_attr($summaryAuthorContains) . '" class="regular-text" placeholder="author" />';
    echo '</div>';

    echo '<div class="lm-filter-field">';
    echo '<div class="lm-small" style="margin-bottom:6px;">' . esc_html__('Search Anchor Text', 'links-manager') . '</div>';
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
    echo '<div class="lm-small" style="margin-bottom:6px;">' . esc_html__('Order By', 'links-manager') . '</div>';
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
    echo '<div class="lm-small" style="margin-bottom:6px;">' . esc_html__('Per Page', 'links-manager') . '</div>';
    echo '<input type="number" name="lm_summary_per_page" value="' . esc_attr((string)$summaryPerPage) . '" min="10" max="500" />';
    echo '</div>';

    echo '<div class="lm-filter-field lm-filter-field-full">';
    echo '<div class="lm-small" style="margin:0 0 6px;">Applies to Search Group Name, Search Destination URL, Search Source URL, Search Title, Search Author, and Search Anchor Text.</div>';
    submit_button(__('Apply Filters', 'links-manager'), 'secondary', 'submit', false);
    echo ' <a class="button button-secondary" href="' . esc_url($summaryExportUrl) . '">' . esc_html__('Export CSV', 'links-manager') . '</a>';
    echo ' <a class="button" href="' . esc_url(admin_url('admin.php?page=links-manager-target')) . '">' . esc_html__('Reset', 'links-manager') . '</a>';
    echo '</div>';
    echo '</div>';
    echo '</form>';

    $summaryTargetsMap = $targetsMap + $groupAnchorsMap;
    $targetIndexByKey = [];
    foreach ($targets as $i => $t) {
      $k = strtolower(trim((string)$t));
      if ($k === '' || isset($targetIndexByKey[$k])) continue;
      $targetIndexByKey[$k] = $i;
    }

    $totalFiltered = 0;
    $totalPages = 1;
    $pagedRows = [];

    if (!empty($summaryTargetsMap)) {
      $counts = [];

      $summaryFilters = [
        'post_type' => $summaryPostType,
        'post_category' => (int)$summaryPostCategory,
        'post_tag' => (int)$summaryPostTag,
        'wpml_lang' => $this->sanitize_wpml_lang_filter($this->get_wpml_current_language()),
        'location' => $summaryLocation,
        'source_type' => $summarySourceType,
        'link_type' => $summaryLinkType,
        'value_contains' => $summaryValueContains,
        'source_contains' => $summarySourceContains,
        'title_contains' => $summaryTitleContains,
        'author_contains' => $summaryAuthorContains,
        'seo_flag' => $summarySeoFlag,
        'search_mode' => $summarySearchMode,
      ];

      $counts = $this->get_links_target_anchor_usage_map($summaryFilters, array_keys($summaryTargetsMap));

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

      $summaryPack = $this->get_links_target_summary_rows(
        $summaryTargetsMap,
        $anchorToGroups,
        $counts,
        $summaryGroupSelected,
        $summaryGroupSearch,
        $summaryAnchorSearch,
        $summarySearchMode,
        $summaryTotalMinNum,
        $summaryTotalMaxNum,
        $summaryInMinNum,
        $summaryInMaxNum,
        $summaryOutMinNum,
        $summaryOutMaxNum,
        $summaryOrderby,
        $summaryOrder,
        $summaryPaged,
        $summaryPerPage,
        $targetIndexByKey,
        $summaryFilters
      );
      if (is_array($summaryPack)) {
        $pagedRows = isset($summaryPack['paged_rows']) && is_array($summaryPack['paged_rows']) ? $summaryPack['paged_rows'] : [];
        $totalFiltered = (int)($summaryPack['total_filtered'] ?? 0);
        $totalPages = max(1, (int)($summaryPack['total_pages'] ?? 1));
        $summaryPaged = max(1, (int)($summaryPack['summary_paged'] ?? $summaryPaged));
        $summaryPerPage = max(10, (int)($summaryPack['summary_per_page'] ?? $summaryPerPage));
      }
    }

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
      $offset = ($summaryPaged - 1) * $summaryPerPage;
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
        submit_button(__('Change Group', 'links-manager'), 'secondary', 'submit', false);
        echo '</form>';
        echo '</td>';
        echo '</tr>';
        $rowNum++;
      }
    }

    echo '</tbody></table></div>';

    $paginationParams = $this->get_links_target_summary_query_args($summaryState);
    unset($paginationParams['page']);
    $this->render_target_pagination($summaryPaged, $totalPages, $paginationParams, $totalFiltered, $summaryPerPage);

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
    echo '</div>'; // grid
    echo '</div>'; // wrap
  }
}
