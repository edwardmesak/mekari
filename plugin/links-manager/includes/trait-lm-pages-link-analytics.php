<?php
/**
 * Pages Link analytics and threshold evaluation helpers.
 */

trait LM_Pages_Link_Analytics_Trait {
  private function build_pages_link_candidate_query_args($filters, $postsPerPage = -1, $paged = 1, $withFoundRows = false) {
    $ptList = $this->get_filterable_post_types();
    $postTypes = ($filters['post_type'] === 'any') ? array_keys($ptList) : [$filters['post_type']];

    $taxQuery = [];
    $postCategoryFilter = isset($filters['post_category']) ? (int)$filters['post_category'] : 0;
    $postTagFilter = isset($filters['post_tag']) ? (int)$filters['post_tag'] : 0;
    if ($postCategoryFilter > 0 || $postTagFilter > 0) {
      if ($filters['post_type'] !== 'any' && $filters['post_type'] !== 'post') {
        return null;
      }
      $taxQuery = ['relation' => 'AND'];
      if ($postCategoryFilter > 0) {
        $taxQuery[] = [
          'taxonomy' => 'category',
          'field' => 'term_id',
          'terms' => [$postCategoryFilter],
        ];
      }
      if ($postTagFilter > 0) {
        $taxQuery[] = [
          'taxonomy' => 'post_tag',
          'field' => 'term_id',
          'terms' => [$postTagFilter],
        ];
      }
    }

    $queryOrderbyMap = [
      'date' => 'date',
      'title' => 'title',
      'modified' => 'modified',
      'post_id' => 'ID',
    ];
    $orderby = isset($filters['orderby']) ? (string)$filters['orderby'] : 'date';
    $queryOrderby = isset($queryOrderbyMap[$orderby]) ? $queryOrderbyMap[$orderby] : 'date';

    $queryArgs = [
      'post_type' => $postTypes,
      'post_status' => 'publish',
      'posts_per_page' => (int)$postsPerPage,
      'paged' => max(1, (int)$paged),
      'fields' => 'ids',
      'no_found_rows' => !$withFoundRows,
      'orderby' => $queryOrderby,
      'order' => isset($filters['order']) ? (string)$filters['order'] : 'DESC',
      'author' => isset($filters['author']) ? (int)$filters['author'] : 0,
      's' => '',
      'update_post_meta_cache' => false,
      'update_post_term_cache' => false,
      'cache_results' => false,
    ];
    if (!empty($taxQuery)) {
      $queryArgs['tax_query'] = $taxQuery;
    }

    $dateQuery = [];
    if (!empty($filters['date_from'])) {
      $dateQuery['after'] = (string)$filters['date_from'];
      $dateQuery['inclusive'] = true;
    }
    if (!empty($filters['date_to'])) {
      $dateQuery['before'] = (string)$filters['date_to'];
      $dateQuery['inclusive'] = true;
    }
    $updatedDateQuery = [];
    if (!empty($filters['updated_date_from'])) {
      $updatedDateQuery['after'] = (string)$filters['updated_date_from'];
      $updatedDateQuery['inclusive'] = true;
      $updatedDateQuery['column'] = 'post_modified';
    }
    if (!empty($filters['updated_date_to'])) {
      $updatedDateQuery['before'] = (string)$filters['updated_date_to'];
      $updatedDateQuery['inclusive'] = true;
      $updatedDateQuery['column'] = 'post_modified';
    }
    $dateQueryClauses = [];
    if (!empty($dateQuery)) $dateQueryClauses[] = $dateQuery;
    if (!empty($updatedDateQuery)) $dateQueryClauses[] = $updatedDateQuery;
    if (!empty($dateQueryClauses)) {
      if (count($dateQueryClauses) > 1) {
        $dateQueryClauses['relation'] = 'AND';
      }
      $queryArgs['date_query'] = $dateQueryClauses;
    }

    return [$queryArgs, $postTypes];
  }

  private function get_pages_link_status_summaries_from_ids($candidatePostIds, $summaryMap) {
    $statusSummary = [
      'orphan' => 0,
      'low' => 0,
      'standard' => 0,
      'excellent' => 0,
    ];
    $internalOutboundSummary = [
      'none' => 0,
      'low' => 0,
      'optimal' => 0,
      'excessive' => 0,
    ];
    $externalOutboundSummary = [
      'none' => 0,
      'low' => 0,
      'optimal' => 0,
      'excessive' => 0,
    ];

    $internalOutboundThresholds = $this->get_internal_outbound_status_thresholds();
    $externalOutboundThresholds = $this->get_external_outbound_status_thresholds();
    foreach ((array)$candidatePostIds as $postId) {
      $pid = (string)(int)$postId;
      $counts = isset($summaryMap[$pid]) && is_array($summaryMap[$pid]) ? $summaryMap[$pid] : [
        'inbound' => 0,
        'internal_outbound' => 0,
        'outbound' => 0,
      ];
      $statusKey = $this->inbound_status_key((int)($counts['inbound'] ?? 0));
      $internalStatusKey = $this->four_level_status_key((int)($counts['internal_outbound'] ?? 0), $internalOutboundThresholds);
      $externalStatusKey = $this->four_level_status_key((int)($counts['outbound'] ?? 0), $externalOutboundThresholds);
      if (isset($statusSummary[$statusKey])) {
        $statusSummary[$statusKey]++;
      }
      if (isset($internalOutboundSummary[$internalStatusKey])) {
        $internalOutboundSummary[$internalStatusKey]++;
      }
      if (isset($externalOutboundSummary[$externalStatusKey])) {
        $externalOutboundSummary[$externalStatusKey]++;
      }
    }

    return [
      'status' => $statusSummary,
      'internal_outbound' => $internalOutboundSummary,
      'external_outbound' => $externalOutboundSummary,
    ];
  }

  private function get_pages_link_paged_result_from_indexed_summary($filters) {
    $queryConfig = $this->build_pages_link_candidate_query_args($filters, (int)$filters['per_page'], (int)$filters['paged'], true);
    if (!is_array($queryConfig)) {
      return null;
    }
    list($pageQueryArgs, $postTypes) = $queryConfig;

    $pageQuery = new WP_Query($pageQueryArgs);
    $candidatePostIds = !empty($pageQuery->posts) ? array_values(array_unique(array_map('intval', (array)$pageQuery->posts))) : [];

    $scopePostType = sanitize_key((string)($filters['post_type'] ?? 'any'));
    if ($scopePostType === '') {
      $scopePostType = 'any';
    }
    $scopeWpmlLang = $this->get_effective_scan_wpml_lang((string)($filters['wpml_lang'] ?? 'all'));
    $summaryMap = $this->get_indexed_summary_map($scopePostType, $scopeWpmlLang);
    if (empty($summaryMap) && ($scopePostType !== 'any' || $scopeWpmlLang !== 'all')) {
      $summaryMap = $this->get_indexed_summary_map('any', 'all');
    }

    $needAllPageUrls = ((string)($filters['orderby'] ?? 'date') === 'page_url') || !empty($filters['search_url']);
    $postDataMap = $this->get_pages_link_post_data_map($candidatePostIds, $postTypes, $needAllPageUrls, []);

    $rows = [];
    foreach ($candidatePostIds as $postId) {
      $pid = (string)$postId;
      $counts = isset($summaryMap[$pid]) && is_array($summaryMap[$pid]) ? $summaryMap[$pid] : [
        'inbound' => 0,
        'internal_outbound' => 0,
        'outbound' => 0,
      ];
      $postData = isset($postDataMap[$pid]) && is_array($postDataMap[$pid]) ? $postDataMap[$pid] : [];
      $rows[] = [
        'post_id' => $postId,
        'post_title' => isset($postData['post_title']) ? (string)$postData['post_title'] : (string)get_the_title($postId),
        'post_type' => isset($postData['post_type']) ? (string)$postData['post_type'] : '',
        'author_name' => isset($postData['author_name']) ? (string)$postData['author_name'] : '',
        'post_date' => isset($postData['post_date']) ? (string)$postData['post_date'] : '',
        'post_modified' => isset($postData['post_modified']) ? (string)$postData['post_modified'] : '',
        'page_url' => isset($postData['page_url']) ? (string)$postData['page_url'] : '',
        'inbound' => (int)($counts['inbound'] ?? 0),
        'outbound' => (int)($counts['outbound'] ?? 0),
        'internal_outbound' => (int)($counts['internal_outbound'] ?? 0),
        'status' => $this->inbound_status_key((int)($counts['inbound'] ?? 0)),
        'internal_outbound_status' => $this->four_level_status_key((int)($counts['internal_outbound'] ?? 0), $this->get_internal_outbound_status_thresholds()),
        'external_outbound_status' => $this->four_level_status_key((int)($counts['outbound'] ?? 0), $this->get_external_outbound_status_thresholds()),
      ];
    }

    $summaryQueryConfig = $this->build_pages_link_candidate_query_args($filters, -1, 1, false);
    $summaryPostIds = [];
    if (is_array($summaryQueryConfig)) {
      list($summaryQueryArgs) = $summaryQueryConfig;
      $summaryQuery = new WP_Query($summaryQueryArgs);
      $summaryPostIds = !empty($summaryQuery->posts) ? array_values(array_unique(array_map('intval', (array)$summaryQuery->posts))) : [];
    }
    $summaries = $this->get_pages_link_status_summaries_from_ids($summaryPostIds, $summaryMap);

    $total = max(0, (int)$pageQuery->found_posts);
    $perPage = max(10, (int)$filters['per_page']);
    $totalPages = max(1, (int)ceil($total / max(1, $perPage)));
    $paged = max(1, min((int)$filters['paged'], $totalPages));

    return [
      'pages' => $rows,
      'total' => $total,
      'per_page' => $perPage,
      'paged' => $paged,
      'total_pages' => $totalPages,
      'status_summary' => $summaries['status'],
      'internal_outbound_summary' => $summaries['internal_outbound'],
      'external_outbound_summary' => $summaries['external_outbound'],
    ];
  }

  private function build_pages_link_target_variants($url) {
    $url = trim((string)$url);
    if ($url === '') {
      return [];
    }

    $base = $this->normalize_for_compare($url);
    if ($base === '') {
      return [];
    }

    $variants = [$base => true];
    $noHash = preg_replace('/#.*$/', '', $base);
    if (is_string($noHash) && $noHash !== '' && $noHash !== $base) {
      $variants[$noHash] = true;
    }

    $noQuery = preg_replace('/\?.*$/', '', (string)$noHash);
    if (is_string($noQuery) && $noQuery !== '' && !isset($variants[$noQuery])) {
      $variants[$noQuery] = true;
    }

    $noTrail = untrailingslashit((string)$noQuery);
    if ($noTrail !== '' && !isset($variants[$noTrail])) {
      $variants[$noTrail] = true;
    }

    $parts = wp_parse_url($base);
    if (is_array($parts) && isset($parts['path'])) {
      $path = (string)$parts['path'];
      if ($path !== '') {
        $pathNorm = $this->normalize_for_compare($path);
        if ($pathNorm !== '' && !isset($variants[$pathNorm])) {
          $variants[$pathNorm] = true;
        }
        $pathNoTrail = untrailingslashit($pathNorm);
        if ($pathNoTrail !== '' && !isset($variants[$pathNoTrail])) {
          $variants[$pathNoTrail] = true;
        }
      }
    }

    return array_keys($variants);
  }

  private function resolve_target_post_id_for_pages_link($targetNorm, $allowedPostIdsMap, &$resolutionCache, $allowedTargetMap = [], &$fallbackState = null, $hydrateTargetMapCb = null) {
    $targetNorm = (string)$targetNorm;
    if ($targetNorm === '') {
      return '';
    }

    if (isset($resolutionCache[$targetNorm])) {
      return (string)$resolutionCache[$targetNorm];
    }

    if (is_array($allowedTargetMap)) {
      $variants = $this->build_pages_link_target_variants($targetNorm);
      foreach ($variants as $variant) {
        if (isset($allowedTargetMap[$variant])) {
          $targetPid = (string)$allowedTargetMap[$variant];
          $resolutionCache[$targetNorm] = $targetPid;
          return $targetPid;
        }
      }

      if (is_callable($hydrateTargetMapCb)) {
        call_user_func($hydrateTargetMapCb);
        foreach ($variants as $variant) {
          if (isset($allowedTargetMap[$variant])) {
            $targetPid = (string)$allowedTargetMap[$variant];
            $resolutionCache[$targetNorm] = $targetPid;
            return $targetPid;
          }
        }
      }
    }

    if (is_array($fallbackState)) {
      $maxFallback = isset($fallbackState['max']) ? (int)$fallbackState['max'] : 120;
      $usedFallback = isset($fallbackState['used']) ? (int)$fallbackState['used'] : 0;
      if ($maxFallback >= 0 && $usedFallback >= $maxFallback) {
        $resolutionCache[$targetNorm] = '';
        return '';
      }
      $fallbackState['used'] = $usedFallback + 1;
    }

    $targetPid = (int)url_to_postid($targetNorm);
    if ($targetPid < 1) {
      $alt = untrailingslashit($targetNorm);
      if ($alt !== $targetNorm && $alt !== '') {
        $targetPid = (int)url_to_postid($alt);
      }
    }

    if ($targetPid > 0 && isset($allowedPostIdsMap[(string)$targetPid])) {
      $resolutionCache[$targetNorm] = (string)$targetPid;
      return (string)$targetPid;
    }

    $resolutionCache[$targetNorm] = '';
    return '';
  }

  private function get_pages_with_inbound_counts_from_indexed_summary($filters) {
    $ptList = $this->get_filterable_post_types();
    $postTypes = ($filters['post_type'] === 'any') ? array_keys($ptList) : [$filters['post_type']];

    $taxQuery = [];
    $postCategoryFilter = isset($filters['post_category']) ? (int)$filters['post_category'] : 0;
    $postTagFilter = isset($filters['post_tag']) ? (int)$filters['post_tag'] : 0;
    if ($postCategoryFilter > 0 || $postTagFilter > 0) {
      if ($filters['post_type'] !== 'any' && $filters['post_type'] !== 'post') {
        return [];
      }
      $taxQuery = ['relation' => 'AND'];
      if ($postCategoryFilter > 0) {
        $taxQuery[] = [
          'taxonomy' => 'category',
          'field' => 'term_id',
          'terms' => [$postCategoryFilter],
        ];
      }
      if ($postTagFilter > 0) {
        $taxQuery[] = [
          'taxonomy' => 'post_tag',
          'field' => 'term_id',
          'terms' => [$postTagFilter],
        ];
      }
    }

    $queryOrderbyMap = [
      'date' => 'date',
      'title' => 'title',
      'modified' => 'modified',
      'post_id' => 'ID',
    ];
    $orderby = isset($filters['orderby']) ? (string)$filters['orderby'] : 'date';
    $queryOrderby = isset($queryOrderbyMap[$orderby]) ? $queryOrderbyMap[$orderby] : 'date';

    $queryArgs = [
      'post_type' => $postTypes,
      'post_status' => 'publish',
      'posts_per_page' => -1,
      'fields' => 'ids',
      'no_found_rows' => true,
      'orderby' => $queryOrderby,
      'order' => isset($filters['order']) ? (string)$filters['order'] : 'DESC',
      'author' => isset($filters['author']) ? (int)$filters['author'] : 0,
      's' => '',
      'update_post_meta_cache' => false,
      'update_post_term_cache' => false,
      'cache_results' => false,
    ];
    if (!empty($taxQuery)) {
      $queryArgs['tax_query'] = $taxQuery;
    }

    $dateQuery = [];
    if (!empty($filters['date_from'])) {
      $dateQuery['after'] = (string)$filters['date_from'];
      $dateQuery['inclusive'] = true;
    }
    if (!empty($filters['date_to'])) {
      $dateQuery['before'] = (string)$filters['date_to'];
      $dateQuery['inclusive'] = true;
    }
    $updatedDateQuery = [];
    if (!empty($filters['updated_date_from'])) {
      $updatedDateQuery['after'] = (string)$filters['updated_date_from'];
      $updatedDateQuery['inclusive'] = true;
      $updatedDateQuery['column'] = 'post_modified';
    }
    if (!empty($filters['updated_date_to'])) {
      $updatedDateQuery['before'] = (string)$filters['updated_date_to'];
      $updatedDateQuery['inclusive'] = true;
      $updatedDateQuery['column'] = 'post_modified';
    }
    $dateQueryClauses = [];
    if (!empty($dateQuery)) $dateQueryClauses[] = $dateQuery;
    if (!empty($updatedDateQuery)) $dateQueryClauses[] = $updatedDateQuery;
    if (!empty($dateQueryClauses)) {
      if (count($dateQueryClauses) > 1) {
        $dateQueryClauses['relation'] = 'AND';
      }
      $queryArgs['date_query'] = $dateQueryClauses;
    }

    $q = new WP_Query($queryArgs);
    if (empty($q->posts)) {
      return [];
    }

    $scopePostType = sanitize_key((string)($filters['post_type'] ?? 'any'));
    if ($scopePostType === '') {
      $scopePostType = 'any';
    }
    $scopeWpmlLang = $this->get_effective_scan_wpml_lang((string)($filters['wpml_lang'] ?? 'all'));
    $summaryMap = $this->get_indexed_summary_map($scopePostType, $scopeWpmlLang);
    if (empty($summaryMap) && ($scopePostType !== 'any' || $scopeWpmlLang !== 'all')) {
      $summaryMap = $this->get_indexed_summary_map('any', 'all');
    }

    $candidatePostIds = array_values(array_unique(array_map('intval', (array)$q->posts)));
    $needAllPageUrls = ((string)$orderby === 'page_url') || !empty($filters['search_url']);
    $postDataMap = $this->get_pages_link_post_data_map($candidatePostIds, $postTypes, $needAllPageUrls, []);

    $rows = [];
    $internalOutboundThresholds = $this->get_internal_outbound_status_thresholds();
    $externalOutboundThresholds = $this->get_external_outbound_status_thresholds();
    foreach ($candidatePostIds as $postId) {
      $pid = (string)$postId;
      $counts = isset($summaryMap[$pid]) && is_array($summaryMap[$pid]) ? $summaryMap[$pid] : [
        'inbound' => 0,
        'internal_outbound' => 0,
        'outbound' => 0,
      ];

      $postData = isset($postDataMap[$pid]) && is_array($postDataMap[$pid]) ? $postDataMap[$pid] : [];
      $postTitle = isset($postData['post_title']) ? (string)$postData['post_title'] : (string)get_the_title($postId);
      if (!empty($filters['search']) && !$this->text_matches($postTitle, (string)$filters['search'], (string)($filters['search_mode'] ?? 'contains'))) {
        continue;
      }

      $pageUrl = isset($postData['page_url']) ? (string)$postData['page_url'] : '';
      if (!empty($filters['search_url'])) {
        if ($pageUrl === '') {
          $pageUrl = (string)get_permalink($postId);
        }
        if ($pageUrl === '' || !$this->text_matches($pageUrl, (string)$filters['search_url'], (string)($filters['search_mode'] ?? 'contains'))) {
          continue;
        }
      }

      $inbound = (int)($counts['inbound'] ?? 0);
      $internalOutbound = (int)($counts['internal_outbound'] ?? 0);
      $outbound = (int)($counts['outbound'] ?? 0);
      $statusKey = $this->inbound_status_key($inbound);
      $internalOutboundStatusKey = $this->four_level_status_key($internalOutbound, $internalOutboundThresholds);
      $externalOutboundStatusKey = $this->four_level_status_key($outbound, $externalOutboundThresholds);

      if (($filters['status'] ?? 'any') !== 'any' && (string)$filters['status'] !== $statusKey) continue;
      if (($filters['internal_outbound_status'] ?? 'any') !== 'any' && (string)$filters['internal_outbound_status'] !== $internalOutboundStatusKey) continue;
      if (($filters['external_outbound_status'] ?? 'any') !== 'any' && (string)$filters['external_outbound_status'] !== $externalOutboundStatusKey) continue;

      if ((int)($filters['inbound_min'] ?? -1) >= 0 && $inbound < (int)$filters['inbound_min']) continue;
      if ((int)($filters['inbound_max'] ?? -1) >= 0 && $inbound > (int)$filters['inbound_max']) continue;
      if ((int)($filters['internal_outbound_min'] ?? -1) >= 0 && $internalOutbound < (int)$filters['internal_outbound_min']) continue;
      if ((int)($filters['internal_outbound_max'] ?? -1) >= 0 && $internalOutbound > (int)$filters['internal_outbound_max']) continue;
      if ((int)($filters['outbound_min'] ?? -1) >= 0 && $outbound < (int)$filters['outbound_min']) continue;
      if ((int)($filters['outbound_max'] ?? -1) >= 0 && $outbound > (int)$filters['outbound_max']) continue;

      $rows[] = [
        'post_id' => $postId,
        'post_title' => $postTitle,
        'post_type' => isset($postData['post_type']) ? (string)$postData['post_type'] : '',
        'author_name' => isset($postData['author_name']) ? (string)$postData['author_name'] : '',
        'post_date' => isset($postData['post_date']) ? (string)$postData['post_date'] : '',
        'post_modified' => isset($postData['post_modified']) ? (string)$postData['post_modified'] : '',
        'page_url' => $pageUrl,
        'inbound' => $inbound,
        'outbound' => $outbound,
        'internal_outbound' => $internalOutbound,
        'status' => $statusKey,
        'internal_outbound_status' => $internalOutboundStatusKey,
        'external_outbound_status' => $externalOutboundStatusKey,
      ];
    }

    $dir = ((string)($filters['order'] ?? 'DESC') === 'ASC') ? 1 : -1;
    usort($rows, function($a, $b) use ($filters, $dir) {
      $orderby = isset($filters['orderby']) ? (string)$filters['orderby'] : 'date';
      $numericSort = false;
      $inboundStatusRank = ['orphan' => 0, 'low' => 1, 'standard' => 2, 'excellent' => 3];
      $outboundStatusRank = ['none' => 0, 'low' => 1, 'optimal' => 2, 'excessive' => 3];
      switch ($orderby) {
        case 'post_id':
          $va = (int)($a['post_id'] ?? 0);
          $vb = (int)($b['post_id'] ?? 0);
          $numericSort = true;
          break;
        case 'title':
          $va = (string)($a['post_title'] ?? '');
          $vb = (string)($b['post_title'] ?? '');
          break;
        case 'post_type':
          $va = (string)($a['post_type'] ?? '');
          $vb = (string)($b['post_type'] ?? '');
          break;
        case 'author':
          $va = (string)($a['author_name'] ?? '');
          $vb = (string)($b['author_name'] ?? '');
          break;
        case 'modified':
          $va = (string)($a['post_modified'] ?? '');
          $vb = (string)($b['post_modified'] ?? '');
          break;
        case 'page_url':
          $va = (string)($a['page_url'] ?? '');
          $vb = (string)($b['page_url'] ?? '');
          break;
        case 'inbound':
          $va = (int)($a['inbound'] ?? 0);
          $vb = (int)($b['inbound'] ?? 0);
          $numericSort = true;
          break;
        case 'internal_outbound':
          $va = (int)($a['internal_outbound'] ?? 0);
          $vb = (int)($b['internal_outbound'] ?? 0);
          $numericSort = true;
          break;
        case 'outbound':
          $va = (int)($a['outbound'] ?? 0);
          $vb = (int)($b['outbound'] ?? 0);
          $numericSort = true;
          break;
        case 'status':
          $va = isset($inboundStatusRank[(string)($a['status'] ?? '')]) ? $inboundStatusRank[(string)($a['status'] ?? '')] : 999;
          $vb = isset($inboundStatusRank[(string)($b['status'] ?? '')]) ? $inboundStatusRank[(string)($b['status'] ?? '')] : 999;
          $numericSort = true;
          break;
        case 'internal_outbound_status':
          $va = isset($outboundStatusRank[(string)($a['internal_outbound_status'] ?? '')]) ? $outboundStatusRank[(string)($a['internal_outbound_status'] ?? '')] : 999;
          $vb = isset($outboundStatusRank[(string)($b['internal_outbound_status'] ?? '')]) ? $outboundStatusRank[(string)($b['internal_outbound_status'] ?? '')] : 999;
          $numericSort = true;
          break;
        case 'external_outbound_status':
          $va = isset($outboundStatusRank[(string)($a['external_outbound_status'] ?? '')]) ? $outboundStatusRank[(string)($a['external_outbound_status'] ?? '')] : 999;
          $vb = isset($outboundStatusRank[(string)($b['external_outbound_status'] ?? '')]) ? $outboundStatusRank[(string)($b['external_outbound_status'] ?? '')] : 999;
          $numericSort = true;
          break;
        case 'date':
        default:
          $va = (string)($a['post_date'] ?? '');
          $vb = (string)($b['post_date'] ?? '');
          break;
      }

      $cmp = $numericSort ? ($va <=> $vb) : strcmp((string)$va, (string)$vb);
      if ($cmp === 0) {
        $cmp = ((int)($a['post_id'] ?? 0) <=> (int)($b['post_id'] ?? 0));
      }
      return $cmp * $dir;
    });

    return $rows;
  }

  private function get_pages_link_post_data_map($candidatePostIds, $postTypes, $needAllPageUrls, $candidatePageUrlMap = []) {
    $candidatePostIds = array_values(array_unique(array_filter(array_map('intval', (array)$candidatePostIds), function($id) {
      return $id > 0;
    })));
    if (empty($candidatePostIds)) {
      return [];
    }

    $postTypes = array_values(array_unique(array_filter(array_map('sanitize_key', (array)$postTypes), function($pt) {
      return $pt !== '';
    })));
    if (empty($postTypes)) {
      return [];
    }

    global $wpdb;
    $idPlaceholders = implode(',', array_fill(0, count($candidatePostIds), '%d'));
    $typePlaceholders = implode(',', array_fill(0, count($postTypes), '%s'));
    $sql = "SELECT ID, post_title, post_type, post_author, post_date, post_modified\n"
      . "FROM {$wpdb->posts}\n"
      . "WHERE ID IN ($idPlaceholders)\n"
      . "  AND post_status = 'publish'\n"
      . "  AND post_type IN ($typePlaceholders)";

    $queryParams = array_merge($candidatePostIds, $postTypes);
    $postRows = $wpdb->get_results($wpdb->prepare($sql, $queryParams));
    if (empty($postRows)) {
      return [];
    }

    $authorIds = [];
    foreach ($postRows as $postRow) {
      $authorId = isset($postRow->post_author) ? (int)$postRow->post_author : 0;
      if ($authorId > 0) {
        $authorIds[$authorId] = true;
      }
    }

    $authorMap = [];
    if (!empty($authorIds)) {
      $authorIdList = array_keys($authorIds);
      $authorPlaceholders = implode(',', array_fill(0, count($authorIdList), '%d'));
      $authorSql = "SELECT ID, display_name FROM {$wpdb->users} WHERE ID IN ($authorPlaceholders)";
      $authorRows = $wpdb->get_results($wpdb->prepare($authorSql, $authorIdList));
      foreach ((array)$authorRows as $authorRow) {
        $authorMap[(int)$authorRow->ID] = (string)$authorRow->display_name;
      }
    }

    $postDataMap = [];
    foreach ($postRows as $postRow) {
      $pid = isset($postRow->ID) ? (int)$postRow->ID : 0;
      if ($pid < 1) {
        continue;
      }
      $pidKey = (string)$pid;
      $authorId = isset($postRow->post_author) ? (int)$postRow->post_author : 0;

      $pageUrl = isset($candidatePageUrlMap[$pidKey]) ? (string)$candidatePageUrlMap[$pidKey] : '';
      if ($needAllPageUrls && $pageUrl === '') {
        $pageUrl = (string)get_permalink($pid);
      }

      $postDataMap[$pidKey] = [
        'post_title' => isset($postRow->post_title) ? (string)$postRow->post_title : '',
        'post_type' => isset($postRow->post_type) ? (string)$postRow->post_type : '',
        'author_name' => isset($authorMap[$authorId]) ? (string)$authorMap[$authorId] : '',
        'post_date' => isset($postRow->post_date) ? (string)$postRow->post_date : '',
        'post_modified' => isset($postRow->post_modified) ? (string)$postRow->post_modified : '',
        'page_url' => $pageUrl,
      ];
    }

    return $postDataMap;
  }

  private function sanitize_inbound_status_thresholds($orphanMax, $lowMax, $standardMax) {
    $orphanMax = (int)$orphanMax;
    $lowMax = (int)$lowMax;
    $standardMax = (int)$standardMax;

    if ($orphanMax < 0) $orphanMax = 0;
    if ($orphanMax > 1000000) $orphanMax = 1000000;

    if ($lowMax < $orphanMax) $lowMax = $orphanMax;
    if ($lowMax > 1000000) $lowMax = 1000000;

    if ($standardMax < $lowMax) $standardMax = $lowMax;
    if ($standardMax > 1000000) $standardMax = 1000000;

    return [
      'orphan_max' => $orphanMax,
      'low_max' => $lowMax,
      'standard_max' => $standardMax,
    ];
  }

  private function get_inbound_status_thresholds() {
    $settings = $this->get_settings();
    $orphanMax = isset($settings['inbound_orphan_max']) ? (int)$settings['inbound_orphan_max'] : 0;
    $lowMax = isset($settings['inbound_low_max']) ? (int)$settings['inbound_low_max'] : 5;
    $standardMax = isset($settings['inbound_standard_max']) ? (int)$settings['inbound_standard_max'] : 10;
    return $this->sanitize_inbound_status_thresholds($orphanMax, $lowMax, $standardMax);
  }

  private function sanitize_four_level_status_thresholds($noneMax, $lowMax, $optimalMax) {
    $noneMax = (int)$noneMax;
    $lowMax = (int)$lowMax;
    $optimalMax = (int)$optimalMax;

    if ($noneMax < 0) $noneMax = 0;
    if ($noneMax > 1000000) $noneMax = 1000000;

    if ($lowMax < $noneMax) $lowMax = $noneMax;
    if ($lowMax > 1000000) $lowMax = 1000000;

    if ($optimalMax < $lowMax) $optimalMax = $lowMax;
    if ($optimalMax > 1000000) $optimalMax = 1000000;

    return [
      'none_max' => $noneMax,
      'low_max' => $lowMax,
      'optimal_max' => $optimalMax,
    ];
  }

  private function get_internal_outbound_status_thresholds() {
    $settings = $this->get_settings();
    $noneMax = isset($settings['internal_outbound_none_max']) ? (int)$settings['internal_outbound_none_max'] : 0;
    $lowMax = isset($settings['internal_outbound_low_max']) ? (int)$settings['internal_outbound_low_max'] : 5;
    $optimalMax = isset($settings['internal_outbound_optimal_max']) ? (int)$settings['internal_outbound_optimal_max'] : 10;
    return $this->sanitize_four_level_status_thresholds($noneMax, $lowMax, $optimalMax);
  }

  private function get_external_outbound_status_thresholds() {
    $settings = $this->get_settings();
    $noneMax = isset($settings['external_outbound_none_max']) ? (int)$settings['external_outbound_none_max'] : 0;
    $lowMax = isset($settings['external_outbound_low_max']) ? (int)$settings['external_outbound_low_max'] : 5;
    $optimalMax = isset($settings['external_outbound_optimal_max']) ? (int)$settings['external_outbound_optimal_max'] : 10;
    return $this->sanitize_four_level_status_thresholds($noneMax, $lowMax, $optimalMax);
  }

  private function four_level_status_key($count, $thresholds) {
    $count = (int)$count;
    if ($count <= (int)$thresholds['none_max']) return 'none';
    if ($count <= (int)$thresholds['low_max']) return 'low';
    if ($count <= (int)$thresholds['optimal_max']) return 'optimal';
    return 'excessive';
  }

  private function warm_pages_link_target_map_for_missing_posts($candidatePostIds, &$candidatePageUrlMap, &$allowedTargetMap, $limit = 200) {
    $candidatePostIds = array_values(array_unique(array_filter(array_map('intval', (array)$candidatePostIds), function($id) {
      return $id > 0;
    })));
    if (empty($candidatePostIds)) {
      return 0;
    }

    $limit = (int)$limit;
    if ($limit < 1) {
      return 0;
    }

    $missing = [];
    foreach ($candidatePostIds as $pid) {
      $pidKey = (string)$pid;
      if (!isset($candidatePageUrlMap[$pidKey]) || trim((string)$candidatePageUrlMap[$pidKey]) === '') {
        $missing[] = $pid;
      }
      if (count($missing) >= $limit) {
        break;
      }
    }

    if (empty($missing)) {
      return 0;
    }

    $warmed = 0;
    foreach ($missing as $pid) {
      $pidKey = (string)$pid;
      $url = (string)get_permalink($pid);
      if ($url === '') {
        continue;
      }

      $candidatePageUrlMap[$pidKey] = $url;
      $variants = $this->build_pages_link_target_variants($url);
      foreach ($variants as $variant) {
        if (!isset($allowedTargetMap[$variant])) {
          $allowedTargetMap[$variant] = $pidKey;
        }
      }
      $warmed++;
    }

    return $warmed;
  }

  private function get_pages_with_inbound_counts($all, $filters, $forceAllPageUrls = false) {
    $profileTotalStarted = $this->profile_start();
    $queryStarted = $this->profile_start();
    $ptList = $this->get_filterable_post_types();
    $post_types = ($filters['post_type'] === 'any') ? array_keys($ptList) : [$filters['post_type']];

    $orderby = $filters['orderby'];
    $queryOrderbyMap = [
      'date' => 'date',
      'title' => 'title',
      'modified' => 'modified',
      'post_id' => 'ID',
    ];
    $queryOrderby = isset($queryOrderbyMap[$orderby]) ? $queryOrderbyMap[$orderby] : 'date';
    $needAllPageUrls = (bool)$forceAllPageUrls || $orderby === 'page_url' || !empty($filters['search_url']);

    $taxQuery = [];
    $postCategoryFilter = isset($filters['post_category']) ? (int)$filters['post_category'] : 0;
    $postTagFilter = isset($filters['post_tag']) ? (int)$filters['post_tag'] : 0;
    if ($postCategoryFilter > 0 || $postTagFilter > 0) {
      if ($filters['post_type'] !== 'any' && $filters['post_type'] !== 'post') {
        return [];
      }
      $taxQuery = ['relation' => 'AND'];
      if ($postCategoryFilter > 0) {
        $taxQuery[] = [
          'taxonomy' => 'category',
          'field' => 'term_id',
          'terms' => [$postCategoryFilter],
        ];
      }
      if ($postTagFilter > 0) {
        $taxQuery[] = [
          'taxonomy' => 'post_tag',
          'field' => 'term_id',
          'terms' => [$postTagFilter],
        ];
      }
    }

    $queryArgs = [
      'post_type' => $post_types,
      'post_status' => 'publish',
      'posts_per_page' => -1,
      'fields' => 'ids',
      'no_found_rows' => true,
      'orderby' => $queryOrderby,
      'order' => $filters['order'],
      'author' => $filters['author'],
      's' => '',
      'update_post_meta_cache' => false,
      'update_post_term_cache' => false,
      'cache_results' => false,
    ];
    if (!empty($taxQuery)) {
      $queryArgs['tax_query'] = $taxQuery;
    }
    $dateQuery = [];
    if (!empty($filters['date_from'])) {
      $dateQuery['after'] = $filters['date_from'];
      $dateQuery['inclusive'] = true;
    }
    if (!empty($filters['date_to'])) {
      $dateQuery['before'] = $filters['date_to'];
      $dateQuery['inclusive'] = true;
    }
    $updatedDateQuery = [];
    if (!empty($filters['updated_date_from'])) {
      $updatedDateQuery['after'] = $filters['updated_date_from'];
      $updatedDateQuery['inclusive'] = true;
      $updatedDateQuery['column'] = 'post_modified';
    }
    if (!empty($filters['updated_date_to'])) {
      $updatedDateQuery['before'] = $filters['updated_date_to'];
      $updatedDateQuery['inclusive'] = true;
      $updatedDateQuery['column'] = 'post_modified';
    }

    $dateQueryClauses = [];
    if (!empty($dateQuery)) $dateQueryClauses[] = $dateQuery;
    if (!empty($updatedDateQuery)) $dateQueryClauses[] = $updatedDateQuery;
    if (!empty($dateQueryClauses)) {
      if (count($dateQueryClauses) > 1) {
        $dateQueryClauses['relation'] = 'AND';
      }
      $queryArgs['date_query'] = $dateQueryClauses;
    }
    $q = new WP_Query($queryArgs);
    $this->profile_end('pages_link_query_candidates', $queryStarted, [
      'candidate_posts' => !empty($q->posts) ? count($q->posts) : 0,
      'post_type_scope' => (string)$filters['post_type'],
    ]);

    $allowedPostIdsMap = [];
    if (!empty($q->posts)) {
      foreach ($q->posts as $post_id) {
        $allowedPostIdsMap[(string)$post_id] = true;
      }
    }

    $prefetchTargetsStarted = $this->profile_start();
    $allowedTargetMap = [];
    $candidatePageUrlMap = [];
    $postDataMap = [];
    $candidatePostIds = !empty($q->posts) ? array_values(array_unique(array_map('intval', (array)$q->posts))) : [];

    if (!empty($allowedPostIdsMap) && !empty($all)) {
      foreach ($all as $row) {
        $rowPostId = (string)($row['post_id'] ?? '');
        if ($rowPostId === '' || !isset($allowedPostIdsMap[$rowPostId])) {
          continue;
        }
        if (!isset($candidatePageUrlMap[$rowPostId])) {
          $candidatePageUrlMap[$rowPostId] = (string)($row['page_url'] ?? '');
        }
        $targetVariants = $this->build_pages_link_target_variants((string)($row['page_url'] ?? ''));
        foreach ($targetVariants as $variant) {
          if (!isset($allowedTargetMap[$variant])) {
            $allowedTargetMap[$variant] = $rowPostId;
          }
        }
      }
    }

    if (!empty($candidatePostIds)) {
      if ($forceAllPageUrls) {
        $warmupLimit = min(140, max(40, (int)ceil(count($candidatePostIds) * 0.06)));
      } else {
        $warmupLimit = min(80, max(20, (int)ceil(count($candidatePostIds) * 0.03)));
      }
    } else {
      $warmupLimit = 0;
    }
    $warmedTargetUrls = $this->warm_pages_link_target_map_for_missing_posts($candidatePostIds, $candidatePageUrlMap, $allowedTargetMap, $warmupLimit);

    $this->profile_end('pages_link_prefetch_target_map', $prefetchTargetsStarted, [
      'candidate_posts' => !empty($q->posts) ? count($q->posts) : 0,
      'target_map_size' => count($allowedTargetMap),
      'warmed_target_urls' => (int)$warmedTargetUrls,
    ]);

    $inbound_counts = [];
    $outbound_counts = [];
    $internal_outbound_counts = [];
    $targetResolutionCache = [];
    $adaptiveFallbackMax = 120;
    if (!empty($allowedPostIdsMap)) {
      $adaptiveFallbackMax = max(10, min(120, (int)ceil(count($allowedPostIdsMap) * 0.01)));
    }
    $targetFallbackState = ['used' => 0, 'max' => $adaptiveFallbackMax];
    $scanStarted = $this->profile_start();
    foreach ($all as $row) {
      if (($filters['location'] ?? 'any') !== 'any' && (string)($row['link_location'] ?? '') !== (string)$filters['location']) continue;
      if (($filters['source_type'] ?? 'any') !== 'any' && (string)($row['source'] ?? '') !== (string)$filters['source_type']) continue;
      if (($filters['link_type'] ?? 'any') !== 'any' && (string)($row['link_type'] ?? '') !== (string)$filters['link_type']) continue;
      if ((string)($filters['value_contains'] ?? '') !== '' && !$this->text_matches((string)($row['link'] ?? ''), (string)$filters['value_contains'], (string)($filters['search_mode'] ?? 'contains'))) continue;

      $seoFlag = (string)($filters['seo_flag'] ?? 'any');
      if ($seoFlag !== 'any') {
        $nofollow = (string)($row['rel_nofollow'] ?? '0') === '1';
        $sponsored = (string)($row['rel_sponsored'] ?? '0') === '1';
        $ugc = (string)($row['rel_ugc'] ?? '0') === '1';
        if ($seoFlag === 'dofollow' && ($nofollow || $sponsored || $ugc)) continue;
        if ($seoFlag === 'nofollow' && !$nofollow) continue;
        if ($seoFlag === 'sponsored' && !$sponsored) continue;
        if ($seoFlag === 'ugc' && !$ugc) continue;
      }

      if ($row['source'] === 'menu') continue;

      $sid = (string)$row['post_id'];
      $isCandidateSource = ($sid !== '' && isset($allowedPostIdsMap[$sid]));

      if (!$isCandidateSource && (string)($row['link_type'] ?? '') !== 'inlink') {
        continue;
      }

      if ($row['link_type'] === 'inlink') {
        $targetNorm = $this->normalize_for_compare((string)($row['link'] ?? ''));
        if ($targetNorm !== '') {
          $targetPid = $this->resolve_target_post_id_for_pages_link($targetNorm, $allowedPostIdsMap, $targetResolutionCache, $allowedTargetMap, $targetFallbackState);
          if ($targetPid !== '' && $targetPid !== $sid) {
            if (!isset($inbound_counts[$targetPid])) $inbound_counts[$targetPid] = 0;
            $inbound_counts[$targetPid]++;
          }
        }
      }

      if ($isCandidateSource) {
        if ($row['link_type'] === 'inlink') {
          if (!isset($internal_outbound_counts[$sid])) $internal_outbound_counts[$sid] = 0;
          $internal_outbound_counts[$sid]++;
        }
        if ($row['link_type'] === 'exlink') {
          if (!isset($outbound_counts[$sid])) $outbound_counts[$sid] = 0;
          $outbound_counts[$sid]++;
        }
      }
    }
    $this->profile_end('pages_link_scan_cache_rows', $scanStarted, [
      'all_rows' => count((array)$all),
      'resolved_targets' => count($targetResolutionCache),
      'url_to_postid_fallback_used' => isset($targetFallbackState['used']) ? (int)$targetFallbackState['used'] : 0,
    ]);

    $rows = [];
    $internalOutboundThresholds = $this->get_internal_outbound_status_thresholds();
    $externalOutboundThresholds = $this->get_external_outbound_status_thresholds();

    $prefetchPostDataStarted = $this->profile_start();
    if (empty($postDataMap) && !empty($candidatePostIds)) {
      $postDataMap = $this->get_pages_link_post_data_map($candidatePostIds, $post_types, $needAllPageUrls, $candidatePageUrlMap);
    }
    $this->profile_end('pages_link_prefetch_post_data', $prefetchPostDataStarted, [
      'candidate_posts' => !empty($q->posts) ? count($q->posts) : 0,
      'post_data_rows' => count($postDataMap),
    ]);

    $assembleStarted = $this->profile_start();
    if (!empty($q->posts)) {
      foreach ($q->posts as $post_id) {
        $pid = (string)$post_id;
        $postData = isset($postDataMap[$pid]) && is_array($postDataMap[$pid]) ? $postDataMap[$pid] : null;

        $postTitle = is_array($postData) ? (string)$postData['post_title'] : (string)get_the_title($post_id);
        if (!empty($filters['search'])) {
          if (!$this->text_matches($postTitle, (string)$filters['search'], (string)$filters['search_mode'])) {
            continue;
          }
        }

        $inbound = $inbound_counts[$pid] ?? 0;
        $outbound = $outbound_counts[$pid] ?? 0;
        $internal_outbound = $internal_outbound_counts[$pid] ?? 0;
        $statusKey = $this->inbound_status_key($inbound);
        $internalOutboundStatusKey = $this->four_level_status_key($internal_outbound, $internalOutboundThresholds);
        $externalOutboundStatusKey = $this->four_level_status_key($outbound, $externalOutboundThresholds);

        if ($filters['status'] !== 'any' && $filters['status'] !== $statusKey) continue;
        if (($filters['internal_outbound_status'] ?? 'any') !== 'any' && (string)$filters['internal_outbound_status'] !== $internalOutboundStatusKey) continue;
        if (($filters['external_outbound_status'] ?? 'any') !== 'any' && (string)$filters['external_outbound_status'] !== $externalOutboundStatusKey) continue;
        if ($filters['inbound_min'] >= 0 && $inbound < $filters['inbound_min']) continue;
        if ($filters['inbound_max'] >= 0 && $inbound > $filters['inbound_max']) continue;
        if ($filters['internal_outbound_min'] >= 0 && $internal_outbound < $filters['internal_outbound_min']) continue;
        if ($filters['internal_outbound_max'] >= 0 && $internal_outbound > $filters['internal_outbound_max']) continue;
        if ($filters['outbound_min'] >= 0 && $outbound < $filters['outbound_min']) continue;
        if ($filters['outbound_max'] >= 0 && $outbound > $filters['outbound_max']) continue;

        $pageUrl = is_array($postData) ? (string)$postData['page_url'] : (string)get_permalink($post_id);
        if (!empty($filters['search_url'])) {
          if ($pageUrl === '') {
            $pageUrl = (string)get_permalink($post_id);
          }
          if ($pageUrl === '' || !$this->text_matches($pageUrl, (string)$filters['search_url'], (string)$filters['search_mode'])) {
            continue;
          }
        }

        $authorName = is_array($postData) ? (string)$postData['author_name'] : '';
        $postType = is_array($postData) ? (string)$postData['post_type'] : '';
        $postDate = is_array($postData) ? (string)$postData['post_date'] : '';
        $postModified = is_array($postData) ? (string)$postData['post_modified'] : '';

        $rows[] = [
          'post_id' => $post_id,
          'post_title' => $postTitle,
          'post_type' => $postType,
          'author_name' => $authorName,
          'post_date' => $postDate,
          'post_modified' => $postModified,
          'page_url' => $pageUrl,
          'inbound' => $inbound,
          'outbound' => $outbound,
          'internal_outbound' => $internal_outbound,
          'status' => $statusKey,
          'internal_outbound_status' => $internalOutboundStatusKey,
          'external_outbound_status' => $externalOutboundStatusKey,
        ];
      }
    }
    $this->profile_end('pages_link_assemble_rows', $assembleStarted, [
      'candidate_posts' => !empty($q->posts) ? count($q->posts) : 0,
      'result_rows' => count($rows),
    ]);

    $dir = ($filters['order'] === 'ASC') ? 1 : -1;
    $sortStarted = $this->profile_start();
    $dbOrderedFields = ['date', 'title', 'modified', 'post_id'];
    if (!in_array($orderby, $dbOrderedFields, true)) {
      usort($rows, function($a, $b) use ($orderby, $dir) {
        $numericSort = false;
        $inboundStatusRank = ['orphan' => 0, 'low' => 1, 'standard' => 2, 'excellent' => 3];
        $outboundStatusRank = ['none' => 0, 'low' => 1, 'optimal' => 2, 'excessive' => 3];
        switch ($orderby) {
          case 'post_id':
            $va = (int)($a['post_id'] ?? 0);
            $vb = (int)($b['post_id'] ?? 0);
            $numericSort = true;
            break;
          case 'title':
            $va = (string)($a['post_title'] ?? '');
            $vb = (string)($b['post_title'] ?? '');
            break;
          case 'post_type':
            $va = (string)($a['post_type'] ?? '');
            $vb = (string)($b['post_type'] ?? '');
            break;
          case 'author':
            $va = (string)($a['author_name'] ?? '');
            $vb = (string)($b['author_name'] ?? '');
            break;
          case 'modified':
            $va = (string)($a['post_modified'] ?? '');
            $vb = (string)($b['post_modified'] ?? '');
            break;
          case 'page_url':
            $va = (string)($a['page_url'] ?? '');
            $vb = (string)($b['page_url'] ?? '');
            break;
          case 'inbound':
            $va = (int)($a['inbound'] ?? 0);
            $vb = (int)($b['inbound'] ?? 0);
            $numericSort = true;
            break;
          case 'internal_outbound':
            $va = (int)($a['internal_outbound'] ?? 0);
            $vb = (int)($b['internal_outbound'] ?? 0);
            $numericSort = true;
            break;
          case 'outbound':
            $va = (int)($a['outbound'] ?? 0);
            $vb = (int)($b['outbound'] ?? 0);
            $numericSort = true;
            break;
          case 'status':
            $va = isset($inboundStatusRank[(string)($a['status'] ?? '')]) ? $inboundStatusRank[(string)($a['status'] ?? '')] : 999;
            $vb = isset($inboundStatusRank[(string)($b['status'] ?? '')]) ? $inboundStatusRank[(string)($b['status'] ?? '')] : 999;
            $numericSort = true;
            break;
          case 'internal_outbound_status':
            $va = isset($outboundStatusRank[(string)($a['internal_outbound_status'] ?? '')]) ? $outboundStatusRank[(string)($a['internal_outbound_status'] ?? '')] : 999;
            $vb = isset($outboundStatusRank[(string)($b['internal_outbound_status'] ?? '')]) ? $outboundStatusRank[(string)($b['internal_outbound_status'] ?? '')] : 999;
            $numericSort = true;
            break;
          case 'external_outbound_status':
            $va = isset($outboundStatusRank[(string)($a['external_outbound_status'] ?? '')]) ? $outboundStatusRank[(string)($a['external_outbound_status'] ?? '')] : 999;
            $vb = isset($outboundStatusRank[(string)($b['external_outbound_status'] ?? '')]) ? $outboundStatusRank[(string)($b['external_outbound_status'] ?? '')] : 999;
            $numericSort = true;
            break;
          case 'date':
          default:
            $va = (string)($a['post_date'] ?? '');
            $vb = (string)($b['post_date'] ?? '');
            break;
        }

        if ($numericSort) {
          $cmp = ($va <=> $vb);
        } else {
          $cmp = strcmp((string)$va, (string)$vb);
        }

        if ($cmp === 0) {
          $cmp = ((int)($a['post_id'] ?? 0) <=> (int)($b['post_id'] ?? 0));
        }

        return $cmp * $dir;
      });
    }

    $this->profile_end('pages_link_sort_rows', $sortStarted, [
      'result_rows' => count($rows),
      'orderby' => (string)$orderby,
      'order' => (string)$filters['order'],
      'query_order_fastpath' => in_array($orderby, $dbOrderedFields, true) ? '1' : '0',
    ]);

    $this->profile_end('pages_link_total', $profileTotalStarted, [
      'all_rows' => count((array)$all),
      'candidate_posts' => !empty($q->posts) ? count($q->posts) : 0,
      'result_rows' => count($rows),
    ]);

    return $rows;
  }

  private function inbound_status_key($count) {
    $count = (int)$count;
    $thresholds = $this->get_inbound_status_thresholds();
    if ($count <= $thresholds['orphan_max']) return 'orphan';
    if ($count <= $thresholds['low_max']) return 'low';
    if ($count <= $thresholds['standard_max']) return 'standard';
    return 'excellent';
  }

  private function inbound_status($count) {
    $key = $this->inbound_status_key($count);
    switch ($key) {
      case 'orphan':
        return 'Orphaned';
      case 'low':
        return 'Low';
      case 'standard':
        return 'Standard';
      case 'excellent':
        return 'Excellent';
      default:
        return '—';
    }
  }
}
