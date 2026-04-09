<?php
/**
 * Indexed datastore aggregation helpers for summary-style admin pages.
 */

trait LM_Indexed_Aggregation_Trait {
  private function all_anchor_text_result_cache_key($filters) {
    $payload = [
      'filters' => is_array($filters) ? $filters : [],
      'blog_id' => get_current_blog_id(),
      'db_version' => (string)get_option('lm_db_version', '0'),
      'stats_version' => (int)get_option('lm_stats_snapshot_version', 1),
      'dataset_version' => $this->get_dataset_cache_version(),
      'logic_version' => 2,
    ];
    return 'lm_all_anchor_text_paged_' . md5(wp_json_encode($payload));
  }

  private function get_indexed_rows_for_custom_aggregation($filters, $forceLinkType = 'any') {
    if (!is_array($filters) || !$this->is_indexed_datastore_ready()) {
      return [];
    }

    $scopePostType = sanitize_key((string)($filters['post_type'] ?? 'any'));
    if ($scopePostType === '') {
      $scopePostType = 'any';
    }
    $wpmlLang = $this->get_effective_scan_wpml_lang((string)($filters['wpml_lang'] ?? 'all'));

    $factFilters = [
      'location' => isset($filters['location']) ? (string)$filters['location'] : 'any',
      'source_type' => isset($filters['source_type']) ? (string)$filters['source_type'] : 'any',
      'link_type' => $forceLinkType !== 'any'
        ? sanitize_key((string)$forceLinkType)
        : (isset($filters['link_type']) ? (string)$filters['link_type'] : 'any'),
      'seo_flag' => isset($filters['seo_flag']) ? (string)$filters['seo_flag'] : 'any',
      'text_match_mode' => isset($filters['search_mode'])
        ? (string)$filters['search_mode']
        : (isset($filters['text_match_mode']) ? (string)$filters['text_match_mode'] : 'contains'),
      'value_contains' => isset($filters['value_contains']) ? (string)$filters['value_contains'] : '',
      'source_contains' => isset($filters['source_contains']) ? (string)$filters['source_contains'] : '',
      'title_contains' => isset($filters['title_contains']) ? (string)$filters['title_contains'] : '',
      'author' => isset($filters['author']) ? (int)$filters['author'] : 0,
      'anchor_contains' => isset($filters['anchor_contains'])
        ? (string)$filters['anchor_contains']
        : (isset($filters['search']) ? (string)$filters['search'] : ''),
      'publish_date_from' => isset($filters['publish_date_from']) ? (string)$filters['publish_date_from'] : '',
      'publish_date_to' => isset($filters['publish_date_to']) ? (string)$filters['publish_date_to'] : '',
      'updated_date_from' => isset($filters['updated_date_from']) ? (string)$filters['updated_date_from'] : '',
      'updated_date_to' => isset($filters['updated_date_to']) ? (string)$filters['updated_date_to'] : '',
      'value_type' => isset($filters['value_type']) ? (string)$filters['value_type'] : 'any',
      'rel_nofollow' => isset($filters['rel_nofollow']) ? (string)$filters['rel_nofollow'] : 'any',
      'rel_sponsored' => isset($filters['rel_sponsored']) ? (string)$filters['rel_sponsored'] : 'any',
      'rel_ugc' => isset($filters['rel_ugc']) ? (string)$filters['rel_ugc'] : 'any',
    ];

    $rows = $this->get_indexed_fact_rows($scopePostType, $wpmlLang, $factFilters);
    if (!empty($rows)) {
      return $rows;
    }
    if (!$this->has_exact_language_scope($wpmlLang) && ($scopePostType !== 'any' || $wpmlLang !== 'all')) {
      return $this->get_indexed_fact_rows('any', 'all', $factFilters);
    }

    return [];
  }

  private function build_indexed_custom_aggregation_query_parts($filters, $forceLinkType = 'any') {
    global $wpdb;

    if (!is_array($filters) || !$this->is_indexed_datastore_ready()) {
      return null;
    }

    $table = $wpdb->prefix . 'lm_link_fact';
    $scopePostType = sanitize_key((string)($filters['post_type'] ?? 'any'));
    if ($scopePostType === '') {
      $scopePostType = 'any';
    }
    $wpmlLang = $this->get_effective_scan_wpml_lang((string)($filters['wpml_lang'] ?? 'all'));

    $resolvedScope = $this->resolve_indexed_datastore_scope($scopePostType, $wpmlLang, !$this->has_exact_language_scope($wpmlLang));
    if (!is_array($resolvedScope)) {
      return null;
    }

    $scopePostType = (string)$resolvedScope['scope_post_type'];
    $wpmlLang = (string)$resolvedScope['wpml_lang'];

    $whereParts = ['wpml_lang = %s'];
    $params = [$wpmlLang];
    if ($scopePostType !== 'any') {
      $whereParts[] = 'post_type = %s';
      $params[] = $scopePostType;
    }

    $location = isset($filters['location']) ? (string)$filters['location'] : 'any';
    if ($location !== '' && $location !== 'any') {
      $whereParts[] = 'link_location = %s';
      $params[] = $location;
    }

    $sourceType = isset($filters['source_type']) ? (string)$filters['source_type'] : 'any';
    if ($sourceType !== '' && $sourceType !== 'any') {
      $whereParts[] = 'source = %s';
      $params[] = $sourceType;
    }

    $linkType = $forceLinkType !== 'any'
      ? sanitize_key((string)$forceLinkType)
      : (isset($filters['link_type']) ? (string)$filters['link_type'] : 'any');
    if ($linkType !== '' && $linkType !== 'any') {
      $whereParts[] = 'link_type = %s';
      $params[] = $linkType;
    }

    $valueType = isset($filters['value_type']) ? (string)$filters['value_type'] : 'any';
    if ($valueType !== '' && $valueType !== 'any') {
      $whereParts[] = 'value_type = %s';
      $params[] = $valueType;
    }

    foreach (['rel_nofollow', 'rel_sponsored', 'rel_ugc'] as $relKey) {
      $relVal = isset($filters[$relKey]) ? (string)$filters[$relKey] : 'any';
      if ($relVal === '0' || $relVal === '1') {
        $whereParts[] = $relKey . ' = %d';
        $params[] = (int)$relVal;
      }
    }

    $seoFlag = isset($filters['seo_flag']) ? (string)$filters['seo_flag'] : 'any';
    if ($seoFlag === 'dofollow') {
      $whereParts[] = 'rel_nofollow = 0 AND rel_sponsored = 0 AND rel_ugc = 0';
    } elseif ($seoFlag === 'nofollow') {
      $whereParts[] = 'rel_nofollow = 1';
    } elseif ($seoFlag === 'sponsored') {
      $whereParts[] = 'rel_sponsored = 1';
    } elseif ($seoFlag === 'ugc') {
      $whereParts[] = 'rel_ugc = 1';
    }

    $textMode = isset($filters['search_mode'])
      ? (string)$filters['search_mode']
      : (isset($filters['text_match_mode']) ? (string)$filters['text_match_mode'] : 'contains');

    $this->append_indexed_text_match_clause($whereParts, $params, 'link', (string)($filters['value_contains'] ?? ''), $textMode);
    $this->append_indexed_text_match_clause($whereParts, $params, 'page_url', (string)($filters['source_contains'] ?? ''), $textMode);
    $this->append_indexed_text_match_clause($whereParts, $params, 'post_title', (string)($filters['title_contains'] ?? ''), $textMode);
    $this->append_indexed_text_match_clause($whereParts, $params, 'anchor_text', (string)($filters['anchor_contains'] ?? ''), $textMode);
    $this->append_indexed_author_filter_clause(
      $whereParts,
      $params,
      isset($filters['author']) ? (int)$filters['author'] : 0,
      $this->get_author_filter_display_name(isset($filters['author']) ? (int)$filters['author'] : 0)
    );

    $publishDateFrom = trim((string)($filters['publish_date_from'] ?? ''));
    $publishDateTo = trim((string)($filters['publish_date_to'] ?? ''));
    $updatedDateFrom = trim((string)($filters['updated_date_from'] ?? ''));
    $updatedDateTo = trim((string)($filters['updated_date_to'] ?? ''));
    if ($publishDateFrom !== '') {
      $whereParts[] = 'DATE(post_date) >= %s';
      $params[] = $publishDateFrom;
    }
    if ($publishDateTo !== '') {
      $whereParts[] = 'DATE(post_date) <= %s';
      $params[] = $publishDateTo;
    }
    if ($updatedDateFrom !== '') {
      $whereParts[] = 'DATE(post_modified) >= %s';
      $params[] = $updatedDateFrom;
    }
    if ($updatedDateTo !== '') {
      $whereParts[] = 'DATE(post_modified) <= %s';
      $params[] = $updatedDateTo;
    }

    $allowedPostIds = $this->get_post_ids_by_post_terms(
      isset($filters['post_category']) ? (int)$filters['post_category'] : 0,
      isset($filters['post_tag']) ? (int)$filters['post_tag'] : 0
    );
    if (is_array($allowedPostIds)) {
      $allowedIds = array_values(array_map('intval', array_keys($allowedPostIds)));
      if (empty($allowedIds)) {
        $whereParts[] = '1 = 0';
      } else {
        $inPlaceholders = implode(',', array_fill(0, count($allowedIds), '%d'));
        $whereParts[] = "post_id IN ($inPlaceholders)";
        foreach ($allowedIds as $pid) {
          $params[] = (int)$pid;
        }
      }
    }

    return [
      'table' => $table,
      'where_sql' => 'WHERE ' . implode(' AND ', $whereParts),
      'params' => $params,
    ];
  }

  private function get_indexed_cited_domains_summary_rows($filters) {
    global $wpdb;

    $parts = $this->build_indexed_custom_aggregation_query_parts($filters, 'exlink');
    if (!is_array($parts)) {
      return [];
    }

    $table = (string)$parts['table'];
    $whereSql = (string)$parts['where_sql'];
    $params = (array)$parts['params'];

    $domainExpr = $this->get_indexed_link_domain_sql_expression();

    $sql = "SELECT
      $domainExpr AS domain,
      COUNT(*) AS cites,
      COUNT(DISTINCT page_url) AS pages,
      MIN(link) AS sample_url
      FROM $table
      $whereSql
      GROUP BY domain
      HAVING domain <> ''";

    $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
    if (!is_array($rows) || empty($rows)) {
      return [];
    }

    $out = [];
    foreach ($rows as $row) {
      $out[] = [
        'domain' => strtolower((string)($row['domain'] ?? '')),
        'cites' => (int)($row['cites'] ?? 0),
        'pages' => (int)($row['pages'] ?? 0),
        'sample_url' => (string)($row['sample_url'] ?? ''),
      ];
    }

    return $out;
  }

  private function get_indexed_cited_domains_paged_result($filters) {
    global $wpdb;

    if (!is_array($filters) || !$this->is_indexed_datastore_ready()) {
      return null;
    }

    $parts = $this->build_indexed_custom_aggregation_query_parts($filters, 'exlink');
    if (!is_array($parts)) {
      return null;
    }

    $table = (string)$parts['table'];
    $whereSql = (string)$parts['where_sql'];
    $baseParams = (array)$parts['params'];

    $domainExpr = $this->get_indexed_link_domain_sql_expression();
    $baseAggSql = "SELECT
      $domainExpr AS domain,
      COUNT(*) AS cites,
      COUNT(DISTINCT page_url) AS pages,
      MIN(link) AS sample_url
      FROM $table
      $whereSql
      GROUP BY domain
      HAVING domain <> ''";

    $outerWhereParts = [];
    $outerParams = [];

    $searchValue = trim((string)($filters['search'] ?? ''));
    if ($searchValue !== '') {
      $searchMode = isset($filters['search_mode']) ? (string)$filters['search_mode'] : 'contains';
      $this->append_indexed_text_match_clause($outerWhereParts, $outerParams, 'domain', $searchValue, $searchMode);
    }

    $minCites = max(0, (int)($filters['min_cites'] ?? 0));
    $minPages = max(0, (int)($filters['min_pages'] ?? 0));
    $maxCites = (int)($filters['max_cites'] ?? -1);
    $maxPages = (int)($filters['max_pages'] ?? -1);
    if ($minCites > 0) {
      $outerWhereParts[] = 'cites >= %d';
      $outerParams[] = $minCites;
    }
    if ($minPages > 0) {
      $outerWhereParts[] = 'pages >= %d';
      $outerParams[] = $minPages;
    }
    if ($maxCites >= 0) {
      $outerWhereParts[] = 'cites <= %d';
      $outerParams[] = $maxCites;
    }
    if ($maxPages >= 0) {
      $outerWhereParts[] = 'pages <= %d';
      $outerParams[] = $maxPages;
    }

    $outerWhereSql = '';
    if (!empty($outerWhereParts)) {
      $outerWhereSql = ' WHERE ' . implode(' AND ', $outerWhereParts);
    }

    $countSql = "SELECT COUNT(*) FROM ($baseAggSql) domain_summary" . $outerWhereSql;
    $countParams = array_merge($baseParams, $outerParams);
    $total = (int)$wpdb->get_var($wpdb->prepare($countSql, $countParams));
    if ($total < 0) {
      $total = 0;
    }

    $perPage = max(10, (int)($filters['per_page'] ?? 25));
    $paged = max(1, (int)($filters['paged'] ?? 1));
    $totalPages = max(1, (int)ceil($total / max(1, $perPage)));
    if ($paged > $totalPages) {
      $paged = $totalPages;
    }
    $offset = ($paged - 1) * $perPage;

    $orderBy = (string)($filters['orderby'] ?? 'cites');
    $orderDir = (strtoupper((string)($filters['order'] ?? 'DESC')) === 'ASC') ? 'ASC' : 'DESC';
    $orderMap = [
      'domain' => 'domain',
      'pages' => 'pages',
      'sample_url' => 'sample_url',
      'cites' => 'cites',
    ];
    $orderColumn = isset($orderMap[$orderBy]) ? $orderMap[$orderBy] : 'cites';

    $dataSql = "SELECT domain, cites, pages, sample_url
      FROM ($baseAggSql) domain_summary" .
      $outerWhereSql .
      " ORDER BY $orderColumn $orderDir, domain $orderDir
      LIMIT %d OFFSET %d";
    $dataParams = array_merge($baseParams, $outerParams, [(int)$perPage, (int)$offset]);
    $rows = $wpdb->get_results($wpdb->prepare($dataSql, $dataParams), ARRAY_A);

    $items = [];
    foreach ((array)$rows as $row) {
      $items[] = [
        'domain' => strtolower((string)($row['domain'] ?? '')),
        'cites' => (int)($row['cites'] ?? 0),
        'pages' => (int)($row['pages'] ?? 0),
        'sample_url' => (string)($row['sample_url'] ?? ''),
      ];
    }

    return [
      'items' => $items,
      'pagination' => [
        'total' => $total,
        'per_page' => $perPage,
        'paged' => $paged,
        'total_pages' => $totalPages,
      ],
    ];
  }

  private function get_indexed_all_anchor_text_summary_rows($filters) {
    global $wpdb;

    $parts = $this->build_indexed_custom_aggregation_query_parts($filters, 'any');
    if (!is_array($parts)) {
      return [];
    }

    $table = (string)$parts['table'];
    $whereSql = (string)$parts['where_sql'];
    $params = (array)$parts['params'];

    $sql = "SELECT
      anchor_text,
      COUNT(*) AS total,
      SUM(CASE WHEN link_type = 'inlink' THEN 1 ELSE 0 END) AS inlink,
      SUM(CASE WHEN link_type = 'exlink' THEN 1 ELSE 0 END) AS outbound,
      COUNT(DISTINCT page_url) AS source_pages,
      COUNT(DISTINCT link) AS destinations,
      GROUP_CONCAT(DISTINCT source ORDER BY source SEPARATOR ',') AS source_types
      FROM $table
      $whereSql
      GROUP BY anchor_text";

    $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
    if (!is_array($rows) || empty($rows)) {
      return [];
    }

    $anchorToGroups = [];
    foreach ($this->get_anchor_groups() as $g) {
      $groupName = trim((string)($g['name'] ?? ''));
      if ($groupName === '') {
        continue;
      }
      foreach ((array)($g['anchors'] ?? []) as $anchorValue) {
        $anchorValue = trim((string)$anchorValue);
        if ($anchorValue === '') {
          continue;
        }
        $anchorKey = strtolower($anchorValue);
        if (!isset($anchorToGroups[$anchorKey])) {
          $anchorToGroups[$anchorKey] = [];
        }
        $anchorToGroups[$anchorKey][$groupName] = true;
      }
    }

    $out = [];
    foreach ($rows as $row) {
      $anchor = $this->normalize_anchor_text_value((string)($row['anchor_text'] ?? ''), true);
      $inlink = (int)($row['inlink'] ?? 0);
      $outbound = (int)($row['outbound'] ?? 0);
      $usageType = 'mixed';
      if ($inlink > 0 && $outbound === 0) {
        $usageType = 'inlink_only';
      } elseif ($outbound > 0 && $inlink === 0) {
        $usageType = 'outbound_only';
      }

      $sourceTypeRaw = trim((string)($row['source_types'] ?? ''));
      $sourceTypes = [];
      $sourceTypesMap = [];
      if ($sourceTypeRaw !== '') {
        foreach (explode(',', $sourceTypeRaw) as $sourceType) {
          $sourceType = trim((string)$sourceType);
          if ($sourceType === '') {
            continue;
          }
          $sourceTypesMap[$sourceType] = true;
          $sourceTypes[] = $sourceType;
        }
      }

      $out[] = [
        'anchor_text' => $anchor,
        'quality' => $this->get_anchor_quality_label($anchor),
        'usage_type' => $usageType,
        'total' => (int)($row['total'] ?? 0),
        'inlink' => $inlink,
        'outbound' => $outbound,
        'source_pages' => (int)($row['source_pages'] ?? 0),
        'destinations' => (int)($row['destinations'] ?? 0),
        'source_types' => implode(', ', $sourceTypes),
        'source_types_map' => $sourceTypesMap,
      ];
    }

    $filteredRows = [];
    $qualityWanted = (string)($filters['quality'] ?? 'any');
    $usageTypeWanted = (string)($filters['usage_type'] ?? 'any');
    $selectedGroup = (string)($filters['group'] ?? 'any');
    $minTotal = max(0, (int)($filters['min_total'] ?? 0));
    $maxTotal = (int)($filters['max_total'] ?? -1);
    $minInlink = max(0, (int)($filters['min_inlink'] ?? 0));
    $maxInlink = (int)($filters['max_inlink'] ?? -1);
    $minOutbound = max(0, (int)($filters['min_outbound'] ?? 0));
    $maxOutbound = (int)($filters['max_outbound'] ?? -1);
    $minPages = max(0, (int)($filters['min_pages'] ?? 0));
    $maxPages = (int)($filters['max_pages'] ?? -1);
    $minDestinations = max(0, (int)($filters['min_destinations'] ?? 0));
    $maxDestinations = (int)($filters['max_destinations'] ?? -1);

    foreach ($out as $summaryRow) {
      if ($qualityWanted !== 'any' && (string)($summaryRow['quality'] ?? '') !== $qualityWanted) {
        continue;
      }
      if ($usageTypeWanted !== 'any' && (string)($summaryRow['usage_type'] ?? '') !== $usageTypeWanted) {
        continue;
      }
      if ((int)($summaryRow['total'] ?? 0) < $minTotal) {
        continue;
      }
      if ($maxTotal >= 0 && (int)($summaryRow['total'] ?? 0) > $maxTotal) {
        continue;
      }
      if ((int)($summaryRow['inlink'] ?? 0) < $minInlink) {
        continue;
      }
      if ($maxInlink >= 0 && (int)($summaryRow['inlink'] ?? 0) > $maxInlink) {
        continue;
      }
      if ((int)($summaryRow['outbound'] ?? 0) < $minOutbound) {
        continue;
      }
      if ($maxOutbound >= 0 && (int)($summaryRow['outbound'] ?? 0) > $maxOutbound) {
        continue;
      }
      if ((int)($summaryRow['source_pages'] ?? 0) < $minPages) {
        continue;
      }
      if ($maxPages >= 0 && (int)($summaryRow['source_pages'] ?? 0) > $maxPages) {
        continue;
      }
      if ((int)($summaryRow['destinations'] ?? 0) < $minDestinations) {
        continue;
      }
      if ($maxDestinations >= 0 && (int)($summaryRow['destinations'] ?? 0) > $maxDestinations) {
        continue;
      }

      if ($selectedGroup !== 'any') {
        $anchorKey = strtolower($this->normalize_anchor_text_value((string)($summaryRow['anchor_text'] ?? ''), true));
        $groupsForAnchor = isset($anchorToGroups[$anchorKey]) ? array_keys($anchorToGroups[$anchorKey]) : [];
        if ($selectedGroup === 'no_group') {
          if (!empty($groupsForAnchor)) {
            continue;
          }
        } elseif (!in_array($selectedGroup, $groupsForAnchor, true)) {
          continue;
        }
      }

      $filteredRows[] = $summaryRow;
    }

    usort($filteredRows, function($a, $b) use ($filters) {
      $dir = ((string)($filters['order'] ?? 'DESC') === 'ASC') ? 1 : -1;
      $ord = (string)($filters['orderby'] ?? 'total');

      if ($ord === 'anchor') {
        $cmp = strcmp((string)($a['anchor_text'] ?? ''), (string)($b['anchor_text'] ?? ''));
      } elseif ($ord === 'quality') {
        $cmp = strcmp((string)($a['quality'] ?? ''), (string)($b['quality'] ?? ''));
      } elseif ($ord === 'usage_type') {
        $cmp = strcmp((string)($a['usage_type'] ?? ''), (string)($b['usage_type'] ?? ''));
      } elseif ($ord === 'pages') {
        $cmp = ((int)($a['source_pages'] ?? 0) <=> (int)($b['source_pages'] ?? 0));
      } elseif ($ord === 'destinations') {
        $cmp = ((int)($a['destinations'] ?? 0) <=> (int)($b['destinations'] ?? 0));
      } elseif ($ord === 'inlink') {
        $cmp = ((int)($a['inlink'] ?? 0) <=> (int)($b['inlink'] ?? 0));
      } elseif ($ord === 'outbound') {
        $cmp = ((int)($a['outbound'] ?? 0) <=> (int)($b['outbound'] ?? 0));
      } elseif ($ord === 'source_types') {
        $cmp = strcmp((string)($a['source_types'] ?? ''), (string)($b['source_types'] ?? ''));
      } else {
        $cmp = ((int)($a['total'] ?? 0) <=> (int)($b['total'] ?? 0));
      }

      if ($cmp === 0) {
        $cmp = strcmp((string)($a['anchor_text'] ?? ''), (string)($b['anchor_text'] ?? ''));
      }

      return $cmp * $dir;
    });

    return $filteredRows;
  }

  private function can_use_indexed_all_anchor_text_paged_fastpath($filters) {
    if (!is_array($filters) || !$this->is_indexed_datastore_ready()) {
      return false;
    }

    $searchMode = isset($filters['search_mode']) ? (string)$filters['search_mode'] : 'contains';
    if ($this->sanitize_text_match_mode($searchMode) === 'regex') {
      return false;
    }

    if ((string)($filters['group'] ?? 'any') !== 'any') {
      return false;
    }
    if ((string)($filters['quality'] ?? 'any') !== 'any') {
      return false;
    }

    $orderby = (string)($filters['orderby'] ?? 'total');
    $allowedOrderby = ['total', 'inlink', 'outbound', 'anchor', 'pages', 'destinations', 'usage_type'];
    if (!in_array($orderby, $allowedOrderby, true)) {
      return false;
    }

    return true;
  }

  private function get_indexed_all_anchor_text_outer_filter_parts($filters) {
    $outerWhereParts = [];
    $outerParams = [];
    $searchMode = isset($filters['search_mode']) ? (string)$filters['search_mode'] : 'contains';

    $searchValue = trim((string)($filters['search'] ?? ''));
    if ($searchValue !== '') {
      $this->append_indexed_text_match_clause($outerWhereParts, $outerParams, 'anchor_text', $searchValue, $searchMode);
    }

    $usageType = (string)($filters['usage_type'] ?? 'any');
    if ($usageType === 'inlink_only') {
      $outerWhereParts[] = 'inlink > 0 AND outbound = 0';
    } elseif ($usageType === 'outbound_only') {
      $outerWhereParts[] = 'outbound > 0 AND inlink = 0';
    } elseif ($usageType === 'mixed') {
      $outerWhereParts[] = 'inlink > 0 AND outbound > 0';
    }

    $minTotal = max(0, (int)($filters['min_total'] ?? 0));
    $maxTotal = (int)($filters['max_total'] ?? -1);
    $minInlink = max(0, (int)($filters['min_inlink'] ?? 0));
    $maxInlink = (int)($filters['max_inlink'] ?? -1);
    $minOutbound = max(0, (int)($filters['min_outbound'] ?? 0));
    $maxOutbound = (int)($filters['max_outbound'] ?? -1);
    $minPages = max(0, (int)($filters['min_pages'] ?? 0));
    $maxPages = (int)($filters['max_pages'] ?? -1);
    $minDestinations = max(0, (int)($filters['min_destinations'] ?? 0));
    $maxDestinations = (int)($filters['max_destinations'] ?? -1);

    if ($minTotal > 0) {
      $outerWhereParts[] = 'total >= %d';
      $outerParams[] = $minTotal;
    }
    if ($maxTotal >= 0) {
      $outerWhereParts[] = 'total <= %d';
      $outerParams[] = $maxTotal;
    }
    if ($minInlink > 0) {
      $outerWhereParts[] = 'inlink >= %d';
      $outerParams[] = $minInlink;
    }
    if ($maxInlink >= 0) {
      $outerWhereParts[] = 'inlink <= %d';
      $outerParams[] = $maxInlink;
    }
    if ($minOutbound > 0) {
      $outerWhereParts[] = 'outbound >= %d';
      $outerParams[] = $minOutbound;
    }
    if ($maxOutbound >= 0) {
      $outerWhereParts[] = 'outbound <= %d';
      $outerParams[] = $maxOutbound;
    }
    if ($minPages > 0) {
      $outerWhereParts[] = 'source_pages >= %d';
      $outerParams[] = $minPages;
    }
    if ($maxPages >= 0) {
      $outerWhereParts[] = 'source_pages <= %d';
      $outerParams[] = $maxPages;
    }
    if ($minDestinations > 0) {
      $outerWhereParts[] = 'destinations >= %d';
      $outerParams[] = $minDestinations;
    }
    if ($maxDestinations >= 0) {
      $outerWhereParts[] = 'destinations <= %d';
      $outerParams[] = $maxDestinations;
    }

    return [
      'where_parts' => $outerWhereParts,
      'params' => $outerParams,
    ];
  }

  private function get_indexed_all_anchor_text_quality_summary($filters) {
    global $wpdb;

    if (!$this->can_use_indexed_all_anchor_text_paged_fastpath($filters)) {
      return null;
    }

    $parts = $this->build_indexed_custom_aggregation_query_parts($filters, 'any');
    if (!is_array($parts)) {
      return null;
    }

    $table = (string)$parts['table'];
    $whereSql = (string)$parts['where_sql'];
    $baseParams = (array)$parts['params'];

    $baseAggSql = "SELECT
      anchor_text,
      COUNT(*) AS total,
      SUM(CASE WHEN link_type = 'inlink' THEN 1 ELSE 0 END) AS inlink,
      SUM(CASE WHEN link_type = 'exlink' THEN 1 ELSE 0 END) AS outbound,
      COUNT(DISTINCT page_url) AS source_pages,
      COUNT(DISTINCT link) AS destinations
      FROM $table
      $whereSql
      GROUP BY anchor_text";

    $outerParts = $this->get_indexed_all_anchor_text_outer_filter_parts($filters);
    $outerWhereParts = (array)($outerParts['where_parts'] ?? []);
    $outerParams = (array)($outerParts['params'] ?? []);
    $outerWhereSql = '';
    if (!empty($outerWhereParts)) {
      $outerWhereSql = ' WHERE ' . implode(' AND ', $outerWhereParts);
    }

    $sql = "SELECT anchor_text, total, inlink, outbound
      FROM ($baseAggSql) anchor_summary" . $outerWhereSql;
    $params = array_merge($baseParams, $outerParams);
    $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);

    $summary = [
      'good' => ['anchors' => 0, 'total' => 0, 'inlink' => 0, 'outbound' => 0],
      'poor' => ['anchors' => 0, 'total' => 0, 'inlink' => 0, 'outbound' => 0],
      'bad' => ['anchors' => 0, 'total' => 0, 'inlink' => 0, 'outbound' => 0],
    ];
    $anchorBase = 0;
    $totalBase = 0;
    $inlinkBase = 0;
    $outboundBase = 0;

    foreach ((array)$rows as $row) {
      $quality = $this->get_anchor_quality_label((string)($row['anchor_text'] ?? ''));
      if (!isset($summary[$quality])) {
        $summary[$quality] = ['anchors' => 0, 'total' => 0, 'inlink' => 0, 'outbound' => 0];
      }
      $rowTotal = (int)($row['total'] ?? 0);
      $rowInlink = (int)($row['inlink'] ?? 0);
      $rowOutbound = (int)($row['outbound'] ?? 0);
      $summary[$quality]['anchors']++;
      $summary[$quality]['total'] += $rowTotal;
      $summary[$quality]['inlink'] += $rowInlink;
      $summary[$quality]['outbound'] += $rowOutbound;
      $anchorBase++;
      $totalBase += $rowTotal;
      $inlinkBase += $rowInlink;
      $outboundBase += $rowOutbound;
    }

    return [
      'summary' => $summary,
      'anchor_base' => $anchorBase,
      'total_base' => $totalBase,
      'inlink_base' => $inlinkBase,
      'outbound_base' => $outboundBase,
    ];
  }

  private function get_indexed_all_anchor_text_paged_result($filters) {
    global $wpdb;

    if (!$this->can_use_indexed_all_anchor_text_paged_fastpath($filters)) {
      return null;
    }

    $cacheKey = $this->all_anchor_text_result_cache_key($filters);
    $cached = get_transient($cacheKey);
    if (is_array($cached)) {
      return $cached;
    }

    $parts = $this->build_indexed_custom_aggregation_query_parts($filters, 'any');
    if (!is_array($parts)) {
      return null;
    }

    $table = (string)$parts['table'];
    $whereSql = (string)$parts['where_sql'];
    $baseParams = (array)$parts['params'];

    $baseAggSql = "SELECT
      anchor_text,
      COUNT(*) AS total,
      SUM(CASE WHEN link_type = 'inlink' THEN 1 ELSE 0 END) AS inlink,
      SUM(CASE WHEN link_type = 'exlink' THEN 1 ELSE 0 END) AS outbound,
      COUNT(DISTINCT page_url) AS source_pages,
      COUNT(DISTINCT link) AS destinations,
      GROUP_CONCAT(DISTINCT source ORDER BY source SEPARATOR ',') AS source_types
      FROM $table
      $whereSql
      GROUP BY anchor_text";

    $outerParts = $this->get_indexed_all_anchor_text_outer_filter_parts($filters);
    $outerWhereParts = (array)($outerParts['where_parts'] ?? []);
    $outerParams = (array)($outerParts['params'] ?? []);
    $outerWhereSql = '';
    if (!empty($outerWhereParts)) {
      $outerWhereSql = ' WHERE ' . implode(' AND ', $outerWhereParts);
    }

    $countSql = "SELECT COUNT(*) FROM ($baseAggSql) anchor_summary" . $outerWhereSql;
    $countParams = array_merge($baseParams, $outerParams);
    $total = (int)$wpdb->get_var($wpdb->prepare($countSql, $countParams));
    if ($total < 0) {
      $total = 0;
    }

    $perPage = max(10, (int)($filters['per_page'] ?? 25));
    $paged = max(1, (int)($filters['paged'] ?? 1));
    $totalPages = max(1, (int)ceil($total / max(1, $perPage)));
    if ($paged > $totalPages) {
      $paged = $totalPages;
    }
    $offset = ($paged - 1) * $perPage;

    $orderBy = (string)($filters['orderby'] ?? 'total');
    $orderDir = (strtoupper((string)($filters['order'] ?? 'DESC')) === 'ASC') ? 'ASC' : 'DESC';
    $orderMap = [
      'total' => 'total',
      'inlink' => 'inlink',
      'outbound' => 'outbound',
      'anchor' => 'anchor_text',
      'pages' => 'source_pages',
      'destinations' => 'destinations',
      'usage_type' => "CASE WHEN inlink > 0 AND outbound > 0 THEN 'mixed' WHEN inlink > 0 THEN 'inlink_only' WHEN outbound > 0 THEN 'outbound_only' ELSE 'mixed' END",
    ];
    $orderColumn = isset($orderMap[$orderBy]) ? $orderMap[$orderBy] : 'total';

    $dataSql = "SELECT anchor_text, total, inlink, outbound, source_pages, destinations, source_types
      FROM ($baseAggSql) anchor_summary" .
      $outerWhereSql .
      " ORDER BY $orderColumn $orderDir, anchor_text $orderDir
      LIMIT %d OFFSET %d";
    $dataParams = array_merge($baseParams, $outerParams, [(int)$perPage, (int)$offset]);
    $rows = $wpdb->get_results($wpdb->prepare($dataSql, $dataParams), ARRAY_A);

    $items = [];
    foreach ((array)$rows as $row) {
      $anchor = $this->normalize_anchor_text_value((string)($row['anchor_text'] ?? ''), true);
      $inlink = (int)($row['inlink'] ?? 0);
      $outbound = (int)($row['outbound'] ?? 0);
      $usageType = 'mixed';
      if ($inlink > 0 && $outbound === 0) {
        $usageType = 'inlink_only';
      } elseif ($outbound > 0 && $inlink === 0) {
        $usageType = 'outbound_only';
      }

      $sourceTypesRaw = trim((string)($row['source_types'] ?? ''));
      $sourceTypesMap = [];
      if ($sourceTypesRaw !== '') {
        foreach (explode(',', $sourceTypesRaw) as $sourceType) {
          $sourceType = trim((string)$sourceType);
          if ($sourceType === '') {
            continue;
          }
          $sourceTypesMap[$sourceType] = true;
        }
      }
      $sourceTypesList = array_keys($sourceTypesMap);
      sort($sourceTypesList);

      $items[] = [
        'anchor_text' => $anchor,
        'quality' => $this->get_anchor_quality_label($anchor),
        'usage_type' => $usageType,
        'total' => (int)($row['total'] ?? 0),
        'inlink' => $inlink,
        'outbound' => $outbound,
        'source_pages' => (int)($row['source_pages'] ?? 0),
        'destinations' => (int)($row['destinations'] ?? 0),
        'source_types' => implode(', ', $sourceTypesList),
        'source_types_map' => $sourceTypesMap,
      ];
    }

    $qualitySummary = $this->get_indexed_all_anchor_text_quality_summary($filters);

    $result = [
      'items' => $items,
      'pagination' => [
        'total' => $total,
        'per_page' => $perPage,
        'paged' => $paged,
        'total_pages' => $totalPages,
      ],
      'quality_summary' => is_array($qualitySummary) ? $qualitySummary : null,
    ];

    set_transient($cacheKey, $result, self::CACHE_TTL);
    return $result;
  }
}
