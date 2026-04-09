<?php
/**
 * Dashboard statistics aggregation and cached snapshot helpers.
 */

trait LM_Dashboard_Stats_Trait {
  private function get_lightweight_indexed_stats_rows($scopePostType = 'any', $wpmlLang = 'all') {
    global $wpdb;

    if (!$this->is_indexed_datastore_ready()) {
      return [];
    }

    $table = $wpdb->prefix . 'lm_link_fact';
    $scopePostType = sanitize_key((string)$scopePostType);
    if ($scopePostType === '') {
      $scopePostType = 'any';
    }
    $wpmlLang = $this->get_effective_scan_wpml_lang((string)$wpmlLang);

    $whereParts = ['wpml_lang = %s'];
    $params = [$wpmlLang];
    if ($scopePostType !== 'any') {
      $whereParts[] = 'post_type = %s';
      $params[] = $scopePostType;
    }

    $whereSql = 'WHERE ' . implode(' AND ', $whereParts);
    $sql = "SELECT post_type, link_type, link, anchor_text, rel_nofollow
      FROM $table
      $whereSql";

    $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
    if (!is_array($rows) || empty($rows)) {
      if (($scopePostType !== 'any' || $wpmlLang !== 'all') && $this->indexed_dataset_has_rows('any', 'all')) {
        return $this->get_lightweight_indexed_stats_rows('any', 'all');
      }
      return [];
    }

    return $rows;
  }

  private function default_stats_snapshot_filters($scopePostType = 'any', $wpmlLang = 'all') {
    return [
      'post_type' => sanitize_key((string)$scopePostType) ?: 'any',
      'wpml_lang' => $this->get_effective_scan_wpml_lang((string)$wpmlLang),
    ];
  }

  private function filter_rows_for_stats_snapshot_scope($rows, $scopePostType) {
    $rows = is_array($rows) ? $rows : [];
    $scopePostType = sanitize_key((string)$scopePostType);
    if ($scopePostType === '' || $scopePostType === 'any') {
      return $rows;
    }

    return array_values(array_filter($rows, static function($row) use ($scopePostType) {
      return sanitize_key((string)($row['post_type'] ?? '')) === $scopePostType;
    }));
  }

  private function get_precomputed_stats_snapshot_if_available($filters, $includeOrphanPages = false) {
    $filters = is_array($filters) ? $filters : [];
    $key = $this->stats_snapshot_key($filters, $includeOrphanPages);
    $cached = get_transient($key);
    return is_array($cached) ? $cached : null;
  }

  private function warm_precomputed_stats_snapshot($rows, $scopePostType = 'any', $wpmlLang = 'all', $includeOrphanPages = false) {
    $rows = is_array($rows) ? $rows : [];
    $rows = $this->filter_rows_for_stats_snapshot_scope($rows, $scopePostType);
    $filters = $this->default_stats_snapshot_filters($scopePostType, $wpmlLang);
    $payload = $this->build_stats_snapshot_payload($rows, $filters, $includeOrphanPages);
    set_transient($this->stats_snapshot_key($filters, $includeOrphanPages), $payload, $this->get_stats_snapshot_ttl());
    update_option('lm_last_stats_snapshot_at', current_time('mysql'), false);
    return $payload;
  }

  private function warm_common_precomputed_stats_snapshots($rows, $wpmlLang = 'all', $includeOrphanPages = false) {
    $rows = is_array($rows) ? $rows : [];
    $wpmlLang = $this->get_effective_scan_wpml_lang((string)$wpmlLang);

    $this->warm_precomputed_stats_snapshot($rows, 'any', $wpmlLang, $includeOrphanPages);

    $enabledPostTypes = array_values(array_filter(array_map('sanitize_key', (array)$this->get_enabled_scan_post_types())));
    if (empty($enabledPostTypes)) {
      return;
    }

    $presentPostTypes = [];
    foreach ($rows as $row) {
      $postType = sanitize_key((string)($row['post_type'] ?? ''));
      if ($postType !== '') {
        $presentPostTypes[$postType] = true;
      }
    }

    foreach ($enabledPostTypes as $postType) {
      if (!isset($presentPostTypes[$postType])) {
        continue;
      }
      $this->warm_precomputed_stats_snapshot($rows, $postType, $wpmlLang, $includeOrphanPages);
    }
  }

  private function get_dashboard_stats($all, $includeOrphanPages = false) {
    $stats = [
      'total_links' => count($all),
      'internal_links' => 0,
      'external_links' => 0,
      'dofollow_links' => 0,
      'nofollow_links' => 0,
      'orphan_pages' => 0,
      'internal' => [
        'total' => 0,
        'dofollow' => 0,
        'nofollow' => 0,
      ],
      'external' => [
        'total_domains' => 0,
        'dofollow' => 0,
        'nofollow' => 0,
      ],
      'by_type' => [],
      'non_good_anchor_text' => 0,
    ];

    $external_domains = [];

    foreach ($all as $row) {
      if ($row['link_type'] === 'inlink') $stats['internal_links']++;
      if ($row['link_type'] === 'exlink') $stats['external_links']++;

      if ($row['rel_nofollow'] === '1') $stats['nofollow_links']++;
      else $stats['dofollow_links']++;

      if ($row['link_type'] === 'inlink') {
        $stats['internal']['total']++;
        if ($row['rel_nofollow'] === '1') $stats['internal']['nofollow']++;
        else $stats['internal']['dofollow']++;
      } elseif ($row['link_type'] === 'exlink') {
        $host = parse_url($this->normalize_url((string)$row['link']), PHP_URL_HOST);
        if ($host) $external_domains[strtolower($host)] = true;
        if ($row['rel_nofollow'] === '1') $stats['external']['nofollow']++;
        else $stats['external']['dofollow']++;
      }

      $type = $row['link_type'];
      if (!isset($stats['by_type'][$type])) $stats['by_type'][$type] = 0;
      $stats['by_type'][$type]++;

      if ($this->get_anchor_quality_label((string)($row['anchor_text'] ?? '')) !== 'good') {
        $stats['non_good_anchor_text']++;
      }
    }

    $stats['external']['total_domains'] = count($external_domains);
    if ($includeOrphanPages) {
      $stats['orphan_pages'] = count($this->get_orphan_pages($all));
    }

    return $stats;
  }

  private function build_top_lists($all, $limit = 10) {
    $internalAnchorCounts = [];
    $externalAnchorCounts = [];
    $externalDomainCounts = [];
    $internalPageCounts = [];
    $externalPageCounts = [];

    foreach ($all as $row) {
      $anchor = $this->normalize_anchor_text_value((string)($row['anchor_text'] ?? ''), true);
      if ($anchor !== '') {
        if ($row['link_type'] === 'inlink') {
          if (!isset($internalAnchorCounts[$anchor])) $internalAnchorCounts[$anchor] = 0;
          $internalAnchorCounts[$anchor]++;
        } elseif ($row['link_type'] === 'exlink') {
          if (!isset($externalAnchorCounts[$anchor])) $externalAnchorCounts[$anchor] = 0;
          $externalAnchorCounts[$anchor]++;
        }
      }

      $link = (string)($row['link'] ?? '');
      if ($link === '') continue;

      if ($row['link_type'] === 'exlink') {
        $host = parse_url($this->normalize_url($link), PHP_URL_HOST);
        if ($host) {
          $host = strtolower($host);
          if (!isset($externalDomainCounts[$host])) $externalDomainCounts[$host] = 0;
          $externalDomainCounts[$host]++;
        }
        if (!isset($externalPageCounts[$link])) $externalPageCounts[$link] = 0;
        $externalPageCounts[$link]++;
      } elseif ($row['link_type'] === 'inlink') {
        if (!isset($internalPageCounts[$link])) $internalPageCounts[$link] = 0;
        $internalPageCounts[$link]++;
      }
    }

    $sortDesc = function (&$arr) use ($limit) {
      arsort($arr);
      return array_slice($arr, 0, $limit, true);
    };

    return [
      'internal_anchors' => $sortDesc($internalAnchorCounts),
      'external_anchors' => $sortDesc($externalAnchorCounts),
      'external_domains' => $sortDesc($externalDomainCounts),
      'internal_pages' => $sortDesc($internalPageCounts),
      'external_pages' => $sortDesc($externalPageCounts),
    ];
  }

  private function get_stats_anchor_quality_summary($all, $filters) {
    $filters = is_array($filters) ? $filters : [];
    $summaryFilters = [
      'post_type' => isset($filters['post_type']) ? (string)$filters['post_type'] : 'any',
      'post_category' => isset($filters['post_category']) ? (int)$filters['post_category'] : 0,
      'post_tag' => isset($filters['post_tag']) ? (int)$filters['post_tag'] : 0,
      'wpml_lang' => isset($filters['wpml_lang']) ? (string)$filters['wpml_lang'] : 'all',
      'search' => isset($filters['anchor_contains']) ? (string)$filters['anchor_contains'] : '',
      'search_mode' => isset($filters['text_match_mode']) ? (string)$filters['text_match_mode'] : 'contains',
      'location' => isset($filters['location']) ? (string)$filters['location'] : 'any',
      'source_type' => isset($filters['source_type']) ? (string)$filters['source_type'] : 'any',
      'link_type' => isset($filters['link_type']) ? (string)$filters['link_type'] : 'any',
      'value_contains' => isset($filters['value_contains']) ? (string)$filters['value_contains'] : '',
      'source_contains' => isset($filters['source_contains']) ? (string)$filters['source_contains'] : '',
      'title_contains' => isset($filters['title_contains']) ? (string)$filters['title_contains'] : '',
      'author_contains' => isset($filters['author_contains']) ? (string)$filters['author_contains'] : '',
      'seo_flag' => isset($filters['seo_flag']) ? (string)$filters['seo_flag'] : 'any',
      'usage_type' => 'any',
      'quality' => 'any',
      'group' => 'any',
      'min_total' => 0,
      'max_total' => -1,
      'min_inlink' => 0,
      'max_inlink' => -1,
      'min_outbound' => 0,
      'max_outbound' => -1,
      'min_pages' => 0,
      'max_pages' => -1,
      'min_destinations' => 0,
      'max_destinations' => -1,
      'orderby' => 'total',
      'order' => 'DESC',
      'per_page' => 25,
      'paged' => 1,
      'rebuild' => !empty($filters['rebuild']),
    ];

    $rows = $this->build_all_anchor_text_rows($all, $summaryFilters);
    $qualityPack = $this->build_anchor_quality_summary_from_summary_rows($rows);
    $qualitySummary = (array)($qualityPack['summary'] ?? []);
    $buckets = [
      'good' => (int)($qualitySummary['good']['anchors'] ?? 0),
      'poor' => (int)($qualitySummary['poor']['anchors'] ?? 0),
      'bad' => (int)($qualitySummary['bad']['anchors'] ?? 0),
    ];

    return $buckets;
  }

  private function stats_snapshot_key($filters, $includeOrphanPages) {
    $version = (int)get_option('lm_stats_snapshot_version', 1);
    $schemaVersion = 3;
    $scope = (string)($filters['post_type'] ?? 'any');
    $lang = (string)($filters['wpml_lang'] ?? 'all');
    $orphanFlag = $includeOrphanPages ? '1' : '0';
    return 'lm_stats_snapshot_' . md5($scope . '|' . $lang . '|' . $orphanFlag . '|' . get_current_blog_id() . '|' . $version . '|schema_' . $schemaVersion);
  }

  private function build_stats_snapshot_payload($all, $filters, $includeOrphanPages) {
    $stats = $this->get_dashboard_stats($all, $includeOrphanPages);
    $tops = $this->build_top_lists($all, 10);

    $postTypeBuckets = [];
    foreach ($all as $row) {
      $pt = (string)($row['post_type'] ?? '');
      if ($pt !== '') {
        if (!isset($postTypeBuckets[$pt])) $postTypeBuckets[$pt] = ['internal' => 0, 'external' => 0];
        if (($row['link_type'] ?? '') === 'inlink') $postTypeBuckets[$pt]['internal']++;
        if (($row['link_type'] ?? '') === 'exlink') $postTypeBuckets[$pt]['external']++;
      }
    }

    $anchorQualityBuckets = $this->get_stats_anchor_quality_summary($all, $filters);
    $stats['non_good_anchor_text'] = (int)($anchorQualityBuckets['poor'] ?? 0) + (int)($anchorQualityBuckets['bad'] ?? 0);

    ksort($postTypeBuckets);
    $maxPostType = 1;
    foreach ($postTypeBuckets as $bucket) {
      $maxPostType = max($maxPostType, (int)$bucket['internal'] + (int)$bucket['external']);
    }

    $internalCount = (int)($stats['internal_links'] ?? 0);
    $externalCount = (int)($stats['external_links'] ?? 0);
    $linkTotal = $internalCount + $externalCount;
    $anchorQualityTotal = 0;
    foreach ((array)$anchorQualityBuckets as $bucketCount) {
      $anchorQualityTotal += (int)$bucketCount;
    }

    return [
      'stats' => $stats,
      'tops' => $tops,
      'post_type_buckets' => $postTypeBuckets,
      'anchor_quality_buckets' => $anchorQualityBuckets,
      'max_post_type' => $maxPostType,
      'max_anchor' => max($anchorQualityBuckets ?: [1]),
      'anchor_quality_total' => $anchorQualityTotal,
      'internal_count' => $internalCount,
      'external_count' => $externalCount,
      'internal_pct' => $linkTotal > 0 ? (int)round(($internalCount / $linkTotal) * 100) : 0,
      'external_pct' => $linkTotal > 0 ? (100 - ((int)round(($internalCount / $linkTotal) * 100))) : 0,
      'non_good_pct' => $anchorQualityTotal > 0 ? round((($stats['non_good_anchor_text'] ?? 0) / $anchorQualityTotal) * 100, 1) : 0,
    ];
  }

  private function get_stats_snapshot_payload($all, $filters, $includeOrphanPages) {
    $profileStarted = $this->profile_start();
    $key = $this->stats_snapshot_key($filters, $includeOrphanPages);
    $cached = get_transient($key);
    if (is_array($cached)) {
      $this->profile_end('stats_snapshot_hit', $profileStarted, [
        'all_rows' => count((array)$all),
      ]);
      return $cached;
    }

    $payload = $this->build_stats_snapshot_payload($all, $filters, $includeOrphanPages);
    set_transient($key, $payload, $this->get_stats_snapshot_ttl());
    update_option('lm_last_stats_snapshot_at', current_time('mysql'), false);
    $this->profile_end('stats_snapshot_build', $profileStarted, [
      'all_rows' => count((array)$all),
    ]);
    return $payload;
  }

  private function get_orphan_pages($all) {
    return $this->get_orphan_pages_filtered($all, $this->get_pages_link_filters_from_request());
  }
}
