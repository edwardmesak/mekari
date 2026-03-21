<?php
/**
 * Dashboard statistics aggregation and cached snapshot helpers.
 */

trait LM_Dashboard_Stats_Trait {
  private function default_stats_snapshot_filters($scopePostType = 'any', $wpmlLang = 'all') {
    return [
      'post_type' => sanitize_key((string)$scopePostType) ?: 'any',
      'wpml_lang' => $this->get_effective_scan_wpml_lang((string)$wpmlLang),
    ];
  }

  private function get_precomputed_stats_snapshot_if_available($filters, $includeOrphanPages = false) {
    $filters = is_array($filters) ? $filters : [];
    $key = $this->stats_snapshot_key($filters, $includeOrphanPages);
    $cached = get_transient($key);
    return is_array($cached) ? $cached : null;
  }

  private function warm_precomputed_stats_snapshot($rows, $scopePostType = 'any', $wpmlLang = 'all', $includeOrphanPages = false) {
    $rows = is_array($rows) ? $rows : [];
    $filters = $this->default_stats_snapshot_filters($scopePostType, $wpmlLang);
    $payload = $this->build_stats_snapshot_payload($rows, $includeOrphanPages);
    set_transient($this->stats_snapshot_key($filters, $includeOrphanPages), $payload, $this->get_stats_snapshot_ttl());
    update_option('lm_last_stats_snapshot_at', current_time('mysql'), false);
    return $payload;
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
      $anchor = trim((string)($row['anchor_text'] ?? ''));
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

  private function stats_snapshot_key($filters, $includeOrphanPages) {
    $version = (int)get_option('lm_stats_snapshot_version', 1);
    $schemaVersion = 2;
    $scope = (string)($filters['post_type'] ?? 'any');
    $lang = (string)($filters['wpml_lang'] ?? 'all');
    $orphanFlag = $includeOrphanPages ? '1' : '0';
    return 'lm_stats_snapshot_' . md5($scope . '|' . $lang . '|' . $orphanFlag . '|' . get_current_blog_id() . '|' . $version . '|schema_' . $schemaVersion);
  }

  private function build_stats_snapshot_payload($all, $includeOrphanPages) {
    $stats = $this->get_dashboard_stats($all, $includeOrphanPages);
    $tops = $this->build_top_lists($all, 10);

    $postTypeBuckets = [];
    $anchorQualityBuckets = ['good' => 0, 'poor' => 0, 'bad' => 0];
    foreach ($all as $row) {
      $pt = (string)($row['post_type'] ?? '');
      if ($pt !== '') {
        if (!isset($postTypeBuckets[$pt])) $postTypeBuckets[$pt] = ['internal' => 0, 'external' => 0];
        if (($row['link_type'] ?? '') === 'inlink') $postTypeBuckets[$pt]['internal']++;
        if (($row['link_type'] ?? '') === 'exlink') $postTypeBuckets[$pt]['external']++;
      }

      $q = $this->get_anchor_quality_suggestion((string)($row['anchor_text'] ?? ''));
      if (($q['quality'] ?? '') === 'good') $anchorQualityBuckets['good']++;
      elseif (($q['quality'] ?? '') === 'poor') $anchorQualityBuckets['poor']++;
      else $anchorQualityBuckets['bad']++;
    }

    ksort($postTypeBuckets);
    $maxPostType = 1;
    foreach ($postTypeBuckets as $bucket) {
      $maxPostType = max($maxPostType, (int)$bucket['internal'] + (int)$bucket['external']);
    }

    $internalCount = (int)($stats['internal_links'] ?? 0);
    $externalCount = (int)($stats['external_links'] ?? 0);
    $linkTotal = $internalCount + $externalCount;

    return [
      'stats' => $stats,
      'tops' => $tops,
      'post_type_buckets' => $postTypeBuckets,
      'anchor_quality_buckets' => $anchorQualityBuckets,
      'max_post_type' => $maxPostType,
      'max_anchor' => max($anchorQualityBuckets ?: [1]),
      'internal_count' => $internalCount,
      'external_count' => $externalCount,
      'internal_pct' => $linkTotal > 0 ? (int)round(($internalCount / $linkTotal) * 100) : 0,
      'external_pct' => $linkTotal > 0 ? (100 - ((int)round(($internalCount / $linkTotal) * 100))) : 0,
      'non_good_pct' => ($stats['total_links'] ?? 0) > 0 ? round((($stats['non_good_anchor_text'] ?? 0) / $stats['total_links']) * 100) : 0,
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

    $payload = $this->build_stats_snapshot_payload($all, $includeOrphanPages);
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
