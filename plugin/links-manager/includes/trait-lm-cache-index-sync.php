<?php
/**
 * Cache persistence and indexed datastore synchronization helpers.
 */

trait LM_Cache_Index_Sync_Trait {
  private function get_dataset_cache_version() {
    return (int)get_option('lm_dataset_cache_version', 1);
  }

  private function bump_dataset_cache_version() {
    $version = $this->get_dataset_cache_version();
    update_option('lm_dataset_cache_version', $version + 1, false);
    return $version + 1;
  }

  private function cache_key($scope_post_type, $wpml_lang = 'all') {
    return 'lm_cache_' . md5((string)$scope_post_type . '|' . (string)$wpml_lang . '|' . get_current_blog_id());
  }

  private function cache_backup_key($scope_post_type, $wpml_lang = 'all') {
    return 'lm_cache_base_' . md5((string)$scope_post_type . '|' . (string)$wpml_lang . '|' . get_current_blog_id());
  }

  private function cache_scan_option_key($scope_post_type, $wpml_lang = 'all') {
    return 'lm_cache_scan_' . md5((string)$scope_post_type . '|' . (string)$wpml_lang . '|' . get_current_blog_id());
  }

  private function purge_oversized_transient($transientKey, $maxBytes = 67108864) {
    global $wpdb;

    $transientKey = trim((string)$transientKey);
    if ($transientKey === '') return;

    $optionName = '_transient_' . $transientKey;
    $size = (int)$wpdb->get_var(
      $wpdb->prepare(
        "SELECT OCTET_LENGTH(option_value) FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
        $optionName
      )
    );

    if ($size > (int)$maxBytes) {
      delete_transient($transientKey);
      if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('LM oversized transient purged: ' . $transientKey);
      }
    }
  }

  private function persist_cache_payload($scope_post_type, $wpml_lang, $rows) {
    set_transient($this->cache_key($scope_post_type, $wpml_lang), $rows, self::CACHE_TTL);
    set_transient($this->cache_backup_key($scope_post_type, $wpml_lang), $rows, self::CACHE_BASE_TTL);
    update_option($this->cache_scan_option_key($scope_post_type, $wpml_lang), gmdate('Y-m-d H:i:s'), false);
    $this->bump_dataset_cache_version();

    $scope_post_type = sanitize_key((string)$scope_post_type);
    if ($scope_post_type === 'any') {
      $this->sync_indexed_datastore_from_rows($rows, $wpml_lang);
    }
    if ($scope_post_type === 'any') {
      $this->warm_common_precomputed_stats_snapshots($rows, $wpml_lang, false);
    }
  }

  private function clear_cache_payload($scope_post_type, $wpml_lang) {
    delete_transient($this->cache_key($scope_post_type, $wpml_lang));
    delete_transient($this->cache_backup_key($scope_post_type, $wpml_lang));
  }

  private function normalize_db_datetime_or_null($raw) {
    $raw = trim((string)$raw);
    if ($raw === '' || $raw === '0000-00-00 00:00:00') {
      return null;
    }
    return $raw;
  }

  private function sync_indexed_datastore_from_rows($rows, $wpml_lang = 'all') {
    $wpml_lang = $this->normalize_rebuild_wpml_lang((string)$wpml_lang);
    $rows = is_array($rows) ? $rows : [];
    $this->reset_indexed_datastore_for_refresh_scope($wpml_lang);
    if (!empty($rows)) {
      $this->append_indexed_datastore_rows($rows, $wpml_lang);
    }
    foreach ($this->get_indexed_summary_refresh_langs_from_rows($rows, $wpml_lang) as $lang) {
      $this->rebuild_indexed_summary_for_lang($lang);
    }
  }

  private function clear_indexed_summary_for_lang($wpml_lang = 'all') {
    global $wpdb;
    $summaryTable = $wpdb->prefix . 'lm_link_post_summary';
    $domainSummaryTable = $wpdb->prefix . 'lm_link_domain_summary';
    $anchorSummaryTable = $wpdb->prefix . 'lm_anchor_text_summary';

    $wpml_lang = $this->normalize_rebuild_wpml_lang((string)$wpml_lang);
    $wpdb->query($wpdb->prepare("DELETE FROM $summaryTable WHERE wpml_lang = %s", $wpml_lang));
    $wpdb->query($wpdb->prepare("DELETE FROM $domainSummaryTable WHERE wpml_lang = %s", $wpml_lang));
    $wpdb->query($wpdb->prepare("DELETE FROM $anchorSummaryTable WHERE wpml_lang = %s", $wpml_lang));
  }

  private function reset_indexed_datastore_for_lang($wpml_lang = 'all') {
    global $wpdb;
    $factTable = $wpdb->prefix . 'lm_link_fact';
    $summaryTable = $wpdb->prefix . 'lm_link_post_summary';
    $domainSummaryTable = $wpdb->prefix . 'lm_link_domain_summary';
    $anchorSummaryTable = $wpdb->prefix . 'lm_anchor_text_summary';

    $wpml_lang = $this->normalize_rebuild_wpml_lang((string)$wpml_lang);
    $wpdb->query($wpdb->prepare("DELETE FROM $factTable WHERE wpml_lang = %s", $wpml_lang));
    $wpdb->query($wpdb->prepare("DELETE FROM $summaryTable WHERE wpml_lang = %s", $wpml_lang));
    $wpdb->query($wpdb->prepare("DELETE FROM $domainSummaryTable WHERE wpml_lang = %s", $wpml_lang));
    $wpdb->query($wpdb->prepare("DELETE FROM $anchorSummaryTable WHERE wpml_lang = %s", $wpml_lang));
  }

  private function reset_indexed_datastore_for_refresh_scope($wpml_lang = 'all') {
    $wpml_lang = $this->normalize_rebuild_wpml_lang((string)$wpml_lang);
    if ($wpml_lang !== 'all') {
      $this->reset_indexed_datastore_for_lang($wpml_lang);
      return;
    }

    foreach ($this->get_indexed_datastore_refresh_langs(true) as $lang) {
      $this->reset_indexed_datastore_for_lang($lang);
    }
  }

  private function get_indexed_summary_refresh_langs_from_rows($rows, $wpml_lang = 'all') {
    $wpml_lang = $this->normalize_rebuild_wpml_lang((string)$wpml_lang);
    if ($wpml_lang !== 'all') {
      return [$wpml_lang];
    }

    $langs = [];
    foreach ((array)$rows as $row) {
      $rowLang = $this->resolve_fact_row_wpml_lang($row, $wpml_lang);
      if ($rowLang !== '' && $rowLang !== 'all') {
        $langs[] = $rowLang;
      }
    }

    $langs = array_values(array_unique(array_filter(array_map([$this, 'sanitize_wpml_lang_filter'], $langs))));
    $langs[] = 'all';

    return array_values(array_unique(array_filter($langs)));
  }

  private function resolve_fact_row_wpml_lang($row, $fallbackLang = 'all') {
    $fallbackLang = $this->normalize_rebuild_wpml_lang((string)$fallbackLang);
    if (!is_array($row)) {
      return $fallbackLang;
    }

    $rowLang = $this->sanitize_wpml_lang_filter((string)($row['wpml_lang'] ?? ''));
    if ($rowLang !== 'all') {
      return $rowLang;
    }

    $postId = isset($row['post_id']) ? (int)$row['post_id'] : 0;
    return $this->resolve_post_wpml_lang($postId, $fallbackLang);
  }

  private function append_indexed_datastore_rows($rows, $wpml_lang = 'all') {
    global $wpdb;
    $factTable = $wpdb->prefix . 'lm_link_fact';
    $wpml_lang = $this->normalize_rebuild_wpml_lang((string)$wpml_lang);
    $rows = is_array($rows) ? $rows : [];
    if (empty($rows)) {
      return 0;
    }

    $factBatch = [];
    $factChunkSize = 200;
    $insertedRows = 0;
    $processedSourceRows = 0;

    foreach ($rows as $row) {
      $postId = isset($row['post_id']) ? (int)$row['post_id'] : 0;
      $rowId = sanitize_text_field((string)($row['row_id'] ?? ''));
      if ($rowId === '') {
        continue;
      }
      $processedSourceRows++;

      $rowLang = $this->resolve_fact_row_wpml_lang(is_array($row) ? $row : [], $wpml_lang);

      $postTitle = sanitize_text_field((string)($row['post_title'] ?? ''));
      $postType = sanitize_key((string)($row['post_type'] ?? ''));
      $postAuthor = sanitize_text_field((string)($row['post_author'] ?? ''));
      $postAuthorId = isset($row['post_author_id']) ? max(0, (int)$row['post_author_id']) : 0;
      $postDate = $this->normalize_db_datetime_or_null($row['post_date'] ?? '');
      $postModified = $this->normalize_db_datetime_or_null($row['post_modified'] ?? '');
      $pageUrl = esc_url_raw((string)($row['page_url'] ?? ''));
      $normalizedPageUrl = sanitize_text_field($this->normalize_for_compare($pageUrl));
      $source = sanitize_key((string)($row['source'] ?? ''));
      $linkLocation = sanitize_text_field((string)($row['link_location'] ?? ''));
      $blockIndex = sanitize_text_field((string)($row['block_index'] ?? ''));
      $occurrence = sanitize_text_field((string)($row['occurrence'] ?? ''));
      $linkType = sanitize_key((string)($row['link_type'] ?? ''));
      $link = esc_url_raw((string)($row['link'] ?? ''));
      $normalizedLink = sanitize_text_field($this->normalize_for_compare($link));
      $linkDomain = strtolower((string)parse_url($link, PHP_URL_HOST));
      $anchorText = sanitize_text_field($this->normalize_anchor_text_value((string)($row['anchor_text'] ?? ''), true));
      $altText = sanitize_text_field((string)($row['alt_text'] ?? ''));
      $snippet = sanitize_textarea_field((string)($row['snippet'] ?? ''));
      $relRaw = sanitize_text_field((string)($row['rel_raw'] ?? ''));
      $relationship = sanitize_text_field((string)($row['relationship'] ?? ''));
      $relNofollow = !empty($row['rel_nofollow']) ? 1 : 0;
      $relSponsored = !empty($row['rel_sponsored']) ? 1 : 0;
      $relUgc = !empty($row['rel_ugc']) ? 1 : 0;
      $valueType = sanitize_key((string)($row['value_type'] ?? ''));

      $baseFactRow = [
        'row_id' => $rowId,
        'post_id' => $postId,
        'post_title' => $postTitle,
        'post_type' => $postType,
        'post_author' => $postAuthor,
        'post_author_id' => $postAuthorId,
        'post_date' => $postDate,
        'post_modified' => $postModified,
        'page_url' => $pageUrl,
        'normalized_page_url' => $normalizedPageUrl,
        'source' => $source,
        'link_location' => $linkLocation,
        'block_index' => $blockIndex,
        'occurrence' => $occurrence,
        'link_type' => $linkType,
        'link' => $link,
        'normalized_link' => $normalizedLink,
        'link_domain' => $linkDomain,
        'anchor_text' => $anchorText,
        'alt_text' => $altText,
        'snippet' => $snippet,
        'rel_raw' => $relRaw,
        'relationship' => $relationship,
        'rel_nofollow' => $relNofollow,
        'rel_sponsored' => $relSponsored,
        'rel_ugc' => $relUgc,
        'value_type' => $valueType,
      ];

      if ($rowLang !== '') {
        $factBatch[] = array_merge(['wpml_lang' => $rowLang], $baseFactRow);
      }
      if ($wpml_lang === 'all' && $rowLang !== 'all') {
        $factBatch[] = array_merge(['wpml_lang' => 'all'], $baseFactRow);
      }

      if (count($factBatch) >= $factChunkSize) {
        $this->insert_indexed_fact_batch($factTable, $factBatch);
        $factBatch = [];
      }
    }

    if (!empty($factBatch)) {
      $this->insert_indexed_fact_batch($factTable, $factBatch);
    }

    $insertedRows = $processedSourceRows;
    return $insertedRows;
  }

  private function rebuild_indexed_summary_for_lang($wpml_lang = 'all') {
    $wpml_lang = $this->normalize_rebuild_wpml_lang((string)$wpml_lang);
    if ($wpml_lang === 'all') {
      $this->rebuild_indexed_summary_for_all_from_language_summaries();
      return;
    }

    $afterFactId = 0;
    do {
      $backfill = $this->backfill_normalized_url_chunk_for_lang($wpml_lang, $afterFactId, $this->get_default_finalize_chunk_size());
      $afterFactId = (int)($backfill['last_fact_id'] ?? 0);
    } while (empty($backfill['done']));

    $this->clear_indexed_summary_for_lang($wpml_lang);

    $afterPostId = 0;
    do {
      $result = $this->build_indexed_summary_seed_chunk_for_lang($wpml_lang, $afterPostId, $this->get_default_finalize_seed_chunk_size());
      $afterPostId = (int)($result['last_post_id'] ?? 0);
    } while (empty($result['done']));

    $afterInboundPostId = 0;
    do {
      $result = $this->finalize_indexed_summary_inbound_chunk_for_lang($wpml_lang, $afterInboundPostId, $this->get_default_finalize_inbound_chunk_size());
      $afterInboundPostId = (int)($result['last_post_id'] ?? 0);
    } while (empty($result['done']));

    $afterDomainPostId = 0;
    do {
      $result = $this->build_indexed_domain_summary_chunk_for_lang($wpml_lang, $afterDomainPostId, $this->get_default_finalize_seed_chunk_size());
      $afterDomainPostId = (int)($result['last_post_id'] ?? 0);
    } while (empty($result['done']));

    $afterAnchorPostId = 0;
    do {
      $result = $this->build_indexed_anchor_summary_chunk_for_lang($wpml_lang, $afterAnchorPostId, $this->get_default_finalize_seed_chunk_size());
      $afterAnchorPostId = (int)($result['last_post_id'] ?? 0);
    } while (empty($result['done']));
  }

  private function rebuild_indexed_summary_for_all_from_language_summaries() {
    $this->clear_indexed_summary_for_lang('all');

    $afterPostId = 0;
    do {
      $result = $this->build_indexed_post_summary_all_chunk($afterPostId, $this->get_default_finalize_seed_chunk_size());
      $afterPostId = (int)($result['last_post_id'] ?? 0);
    } while (empty($result['done']));

    $afterDomainPostId = 0;
    do {
      $result = $this->build_indexed_domain_summary_all_chunk($afterDomainPostId, $this->get_default_finalize_seed_chunk_size());
      $afterDomainPostId = (int)($result['last_post_id'] ?? 0);
    } while (empty($result['done']));

    $afterAnchorPostId = 0;
    do {
      $result = $this->build_indexed_anchor_summary_all_chunk($afterAnchorPostId, $this->get_default_finalize_seed_chunk_size());
      $afterAnchorPostId = (int)($result['last_post_id'] ?? 0);
    } while (empty($result['done']));
  }

  private function build_indexed_post_summary_all_chunk($afterPostId = 0, $limit = 100) {
    global $wpdb;

    $summaryTable = $wpdb->prefix . 'lm_link_post_summary';
    $afterPostId = max(0, (int)$afterPostId);
    $limit = max(1, min(500, (int)$limit));
    $chunkStartedAt = microtime(true);

    $postIds = $wpdb->get_col(
      $wpdb->prepare(
        "SELECT DISTINCT post_id
         FROM $summaryTable
         WHERE wpml_lang <> %s
           AND post_id > %d
         ORDER BY post_id ASC
         LIMIT %d",
        'all',
        $afterPostId,
        $limit
      )
    );
    $postIds = array_values(array_filter(array_map('intval', (array)$postIds)));
    if (empty($postIds)) {
      return [
        'done' => true,
        'processed_posts' => 0,
        'last_post_id' => $afterPostId,
        'summary_rows' => 0,
        'step_ms' => max(0, (int)round((microtime(true) - $chunkStartedAt) * 1000)),
      ];
    }

    $placeholders = implode(',', array_fill(0, count($postIds), '%d'));
    $sql = "SELECT
      %s AS wpml_lang,
      src.post_id,
      MAX(src.post_title) AS post_title,
      MAX(src.post_type) AS post_type,
      MAX(src.post_author) AS post_author,
      MAX(src.post_author_id) AS post_author_id,
      MAX(src.post_date) AS post_date,
      MAX(src.post_modified) AS post_modified,
      MAX(src.page_url) AS page_url,
      MAX(src.inbound) AS inbound,
      MAX(src.internal_outbound) AS internal_outbound,
      MAX(src.outbound) AS outbound
    FROM $summaryTable src
    WHERE src.wpml_lang <> %s
      AND src.post_id IN ($placeholders)
    GROUP BY src.post_id";
    $params = array_merge(['all', 'all'], $postIds);
    $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);

    $batch = [];
    foreach ((array)$rows as $row) {
      $postId = isset($row['post_id']) ? (int)$row['post_id'] : 0;
      if ($postId < 1) {
        continue;
      }
      $batch[] = [
        'wpml_lang' => 'all',
        'post_id' => $postId,
        'post_title' => sanitize_text_field((string)($row['post_title'] ?? '')),
        'post_type' => sanitize_key((string)($row['post_type'] ?? '')),
        'post_author' => sanitize_text_field((string)($row['post_author'] ?? '')),
        'post_author_id' => max(0, (int)($row['post_author_id'] ?? 0)),
        'post_date' => $this->normalize_db_datetime_or_null($row['post_date'] ?? ''),
        'post_modified' => $this->normalize_db_datetime_or_null($row['post_modified'] ?? ''),
        'page_url' => esc_url_raw((string)($row['page_url'] ?? '')),
        'inbound' => max(0, (int)($row['inbound'] ?? 0)),
        'internal_outbound' => max(0, (int)($row['internal_outbound'] ?? 0)),
        'outbound' => max(0, (int)($row['outbound'] ?? 0)),
      ];
    }

    if (!empty($batch)) {
      $this->insert_indexed_summary_batch($summaryTable, $batch);
    }

    return [
      'done' => count($postIds) < $limit,
      'processed_posts' => count($postIds),
      'last_post_id' => max($postIds),
      'summary_rows' => count($batch),
      'step_ms' => max(0, (int)round((microtime(true) - $chunkStartedAt) * 1000)),
    ];
  }

  private function build_indexed_domain_summary_all_chunk($afterPostId = 0, $limit = 100) {
    global $wpdb;

    $summaryTable = $wpdb->prefix . 'lm_link_domain_summary';
    $afterPostId = max(0, (int)$afterPostId);
    $limit = max(1, min(500, (int)$limit));
    $chunkStartedAt = microtime(true);

    $postIds = $wpdb->get_col(
      $wpdb->prepare(
        "SELECT DISTINCT post_id
         FROM $summaryTable
         WHERE wpml_lang <> %s
           AND post_id > %d
         ORDER BY post_id ASC
         LIMIT %d",
        'all',
        $afterPostId,
        $limit
      )
    );
    $postIds = array_values(array_filter(array_map('intval', (array)$postIds)));
    if (empty($postIds)) {
      return [
        'done' => true,
        'processed_posts' => 0,
        'last_post_id' => $afterPostId,
        'summary_rows' => 0,
        'step_ms' => max(0, (int)round((microtime(true) - $chunkStartedAt) * 1000)),
      ];
    }

    $placeholders = implode(',', array_fill(0, count($postIds), '%d'));
    $sql = "SELECT
      %s AS wpml_lang,
      src.post_id,
      MAX(src.post_title) AS post_title,
      MAX(src.post_type) AS post_type,
      MAX(src.post_author) AS post_author,
      MAX(src.post_author_id) AS post_author_id,
      MAX(src.post_date) AS post_date,
      MAX(src.post_modified) AS post_modified,
      MAX(src.page_url) AS page_url,
      src.source_type,
      src.link_location,
      src.rel_nofollow,
      src.rel_sponsored,
      src.rel_ugc,
      src.domain,
      MAX(src.link_url) AS link_url,
      src.link_hash,
      MAX(src.anchor_text) AS anchor_text,
      src.anchor_hash,
      SUM(src.cites) AS cites
    FROM $summaryTable src
    WHERE src.wpml_lang <> %s
      AND src.post_id IN ($placeholders)
    GROUP BY src.post_id, src.source_type, src.link_location, src.rel_nofollow, src.rel_sponsored, src.rel_ugc, src.domain, src.link_hash, src.anchor_hash";
    $params = array_merge(['all', 'all'], $postIds);
    $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);

    $batch = [];
    foreach ((array)$rows as $row) {
      $batch[] = [
        'wpml_lang' => 'all',
        'post_id' => (int)($row['post_id'] ?? 0),
        'post_title' => sanitize_text_field((string)($row['post_title'] ?? '')),
        'post_type' => sanitize_key((string)($row['post_type'] ?? '')),
        'post_author' => sanitize_text_field((string)($row['post_author'] ?? '')),
        'post_author_id' => max(0, (int)($row['post_author_id'] ?? 0)),
        'post_date' => $this->normalize_db_datetime_or_null($row['post_date'] ?? ''),
        'post_modified' => $this->normalize_db_datetime_or_null($row['post_modified'] ?? ''),
        'page_url' => esc_url_raw((string)($row['page_url'] ?? '')),
        'source_type' => sanitize_key((string)($row['source_type'] ?? '')),
        'link_location' => sanitize_text_field((string)($row['link_location'] ?? '')),
        'rel_nofollow' => (int)($row['rel_nofollow'] ?? 0),
        'rel_sponsored' => (int)($row['rel_sponsored'] ?? 0),
        'rel_ugc' => (int)($row['rel_ugc'] ?? 0),
        'domain' => strtolower((string)($row['domain'] ?? '')),
        'link_url' => esc_url_raw((string)($row['link_url'] ?? '')),
        'link_hash' => sanitize_text_field((string)($row['link_hash'] ?? '')),
        'anchor_text' => sanitize_text_field((string)($row['anchor_text'] ?? '')),
        'anchor_hash' => sanitize_text_field((string)($row['anchor_hash'] ?? '')),
        'cites' => max(0, (int)($row['cites'] ?? 0)),
      ];
    }

    if (!empty($batch)) {
      $this->insert_indexed_domain_summary_batch($summaryTable, $batch);
    }

    return [
      'done' => count($postIds) < $limit,
      'processed_posts' => count($postIds),
      'last_post_id' => max($postIds),
      'summary_rows' => count($batch),
      'step_ms' => max(0, (int)round((microtime(true) - $chunkStartedAt) * 1000)),
    ];
  }

  private function build_indexed_anchor_summary_all_chunk($afterPostId = 0, $limit = 100) {
    global $wpdb;

    $summaryTable = $wpdb->prefix . 'lm_anchor_text_summary';
    $afterPostId = max(0, (int)$afterPostId);
    $limit = max(1, min(500, (int)$limit));
    $chunkStartedAt = microtime(true);

    $postIds = $wpdb->get_col(
      $wpdb->prepare(
        "SELECT DISTINCT post_id
         FROM $summaryTable
         WHERE wpml_lang <> %s
           AND post_id > %d
         ORDER BY post_id ASC
         LIMIT %d",
        'all',
        $afterPostId,
        $limit
      )
    );
    $postIds = array_values(array_filter(array_map('intval', (array)$postIds)));
    if (empty($postIds)) {
      return [
        'done' => true,
        'processed_posts' => 0,
        'last_post_id' => $afterPostId,
        'summary_rows' => 0,
        'step_ms' => max(0, (int)round((microtime(true) - $chunkStartedAt) * 1000)),
      ];
    }

    $placeholders = implode(',', array_fill(0, count($postIds), '%d'));
    $sql = "SELECT
      %s AS wpml_lang,
      src.post_id,
      MAX(src.post_title) AS post_title,
      MAX(src.post_type) AS post_type,
      MAX(src.post_author) AS post_author,
      MAX(src.post_author_id) AS post_author_id,
      MAX(src.post_date) AS post_date,
      MAX(src.post_modified) AS post_modified,
      MAX(src.page_url) AS page_url,
      src.source_type,
      src.link_location,
      src.link_type,
      src.rel_nofollow,
      src.rel_sponsored,
      src.rel_ugc,
      MAX(src.anchor_text) AS anchor_text,
      src.anchor_hash,
      MAX(src.quality) AS quality,
      MAX(src.link_url) AS link_url,
      src.link_hash,
      SUM(src.uses) AS uses
    FROM $summaryTable src
    WHERE src.wpml_lang <> %s
      AND src.post_id IN ($placeholders)
    GROUP BY src.post_id, src.source_type, src.link_location, src.link_type, src.rel_nofollow, src.rel_sponsored, src.rel_ugc, src.anchor_hash, src.link_hash";
    $params = array_merge(['all', 'all'], $postIds);
    $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);

    $batch = [];
    foreach ((array)$rows as $row) {
      $batch[] = [
        'wpml_lang' => 'all',
        'post_id' => (int)($row['post_id'] ?? 0),
        'post_title' => sanitize_text_field((string)($row['post_title'] ?? '')),
        'post_type' => sanitize_key((string)($row['post_type'] ?? '')),
        'post_author' => sanitize_text_field((string)($row['post_author'] ?? '')),
        'post_author_id' => max(0, (int)($row['post_author_id'] ?? 0)),
        'post_date' => $this->normalize_db_datetime_or_null($row['post_date'] ?? ''),
        'post_modified' => $this->normalize_db_datetime_or_null($row['post_modified'] ?? ''),
        'page_url' => esc_url_raw((string)($row['page_url'] ?? '')),
        'source_type' => sanitize_key((string)($row['source_type'] ?? '')),
        'link_location' => sanitize_text_field((string)($row['link_location'] ?? '')),
        'link_type' => sanitize_key((string)($row['link_type'] ?? '')),
        'rel_nofollow' => (int)($row['rel_nofollow'] ?? 0),
        'rel_sponsored' => (int)($row['rel_sponsored'] ?? 0),
        'rel_ugc' => (int)($row['rel_ugc'] ?? 0),
        'anchor_text' => sanitize_text_field((string)($row['anchor_text'] ?? '')),
        'anchor_hash' => sanitize_text_field((string)($row['anchor_hash'] ?? '')),
        'quality' => sanitize_key((string)($row['quality'] ?? '')),
        'link_url' => esc_url_raw((string)($row['link_url'] ?? '')),
        'link_hash' => sanitize_text_field((string)($row['link_hash'] ?? '')),
        'uses' => max(0, (int)($row['uses'] ?? 0)),
      ];
    }

    if (!empty($batch)) {
      $this->insert_indexed_anchor_summary_batch($summaryTable, $batch);
    }

    return [
      'done' => count($postIds) < $limit,
      'processed_posts' => count($postIds),
      'last_post_id' => max($postIds),
      'summary_rows' => count($batch),
      'step_ms' => max(0, (int)round((microtime(true) - $chunkStartedAt) * 1000)),
    ];
  }

  private function backfill_normalized_url_chunk_for_lang($wpml_lang = 'all', $afterFactId = 0, $limit = 25) {
    global $wpdb;

    if (!$this->indexed_fact_has_normalized_url_columns()) {
      return [
        'done' => true,
        'processed_rows' => 0,
        'last_fact_id' => max(0, (int)$afterFactId),
        'step_ms' => 0,
      ];
    }

    $chunkStartedAt = microtime(true);
    $factTable = $wpdb->prefix . 'lm_link_fact';
    $wpml_lang = $this->normalize_rebuild_wpml_lang((string)$wpml_lang);
    $afterFactId = max(0, (int)$afterFactId);
    $limit = max(10, min(100, (int)$limit));

    $rows = $wpdb->get_results(
      $wpdb->prepare(
        "SELECT id, page_url, link
         FROM $factTable
         WHERE wpml_lang = %s
           AND id > %d
           AND (
             (normalized_page_url = '' AND page_url <> '')
             OR (normalized_link = '' AND link <> '')
           )
         ORDER BY id ASC
         LIMIT %d",
        $wpml_lang,
        $afterFactId,
        $limit
      ),
      ARRAY_A
    );

    $rows = is_array($rows) ? $rows : [];
    if (empty($rows)) {
      return [
        'done' => true,
        'processed_rows' => 0,
        'last_fact_id' => $afterFactId,
        'step_ms' => max(0, (int)round((microtime(true) - $chunkStartedAt) * 1000)),
      ];
    }

    foreach ($rows as $row) {
      $factId = isset($row['id']) ? (int)$row['id'] : 0;
      if ($factId < 1) {
        continue;
      }

      $normalizedPageUrl = sanitize_text_field($this->normalize_for_compare((string)($row['page_url'] ?? '')));
      $normalizedLink = sanitize_text_field($this->normalize_for_compare((string)($row['link'] ?? '')));
      $wpdb->query($wpdb->prepare(
        "UPDATE $factTable
         SET normalized_page_url = %s,
             normalized_link = %s
         WHERE id = %d",
        $normalizedPageUrl,
        $normalizedLink,
        $factId
      ));
    }

    $lastFactId = max(array_map('intval', wp_list_pluck($rows, 'id')));
    return [
      'done' => count($rows) < $limit,
      'processed_rows' => count($rows),
      'last_fact_id' => $lastFactId,
      'step_ms' => max(0, (int)round((microtime(true) - $chunkStartedAt) * 1000)),
    ];
  }

  private function build_indexed_summary_seed_chunk_for_lang($wpml_lang = 'all', $afterPostId = 0, $limit = 100) {
    global $wpdb;

    $factTable = $wpdb->prefix . 'lm_link_fact';
    $summaryTable = $wpdb->prefix . 'lm_link_post_summary';
    $wpml_lang = $this->normalize_rebuild_wpml_lang((string)$wpml_lang);
    $afterPostId = max(0, (int)$afterPostId);
    $limit = max(1, min(500, (int)$limit));
    $chunkStartedAt = microtime(true);

    $postIds = $wpdb->get_col(
      $wpdb->prepare(
        "SELECT DISTINCT post_id
         FROM $factTable
         WHERE wpml_lang = %s
           AND post_id > %d
           AND post_id > 0
         ORDER BY post_id ASC
         LIMIT %d",
        $wpml_lang,
        $afterPostId,
        $limit
      )
    );

    $postIds = array_values(array_filter(array_map('intval', (array)$postIds)));
    if (empty($postIds)) {
      return [
        'done' => true,
        'processed_posts' => 0,
        'last_post_id' => $afterPostId,
        'chunk_limit' => $limit,
        'summary_rows' => 0,
        'inbound_rows' => 0,
        'target_only_summary_rows' => 0,
        'summary_query_ms' => 0,
        'inbound_query_ms' => 0,
        'step_ms' => max(0, (int)round((microtime(true) - $chunkStartedAt) * 1000)),
      ];
    }

    $placeholders = implode(',', array_fill(0, count($postIds), '%d'));
    $summaryParams = array_merge([$wpml_lang], $postIds);
    $summaryQueryStartedAt = microtime(true);
    $summarySql = "SELECT
      fact.wpml_lang,
      fact.post_id,
      MAX(fact.post_title) AS post_title,
      MAX(fact.post_type) AS post_type,
      MAX(fact.post_author) AS post_author,
      MAX(fact.post_author_id) AS post_author_id,
      MAX(fact.post_date) AS post_date,
      MAX(fact.post_modified) AS post_modified,
      MAX(fact.page_url) AS page_url,
      SUM(CASE WHEN fact.link_type = 'inlink' THEN 1 ELSE 0 END) AS internal_outbound,
      SUM(CASE WHEN fact.link_type = 'exlink' THEN 1 ELSE 0 END) AS outbound
    FROM $factTable fact
    WHERE fact.wpml_lang = %s
      AND fact.post_id IN ($placeholders)
    GROUP BY fact.wpml_lang, fact.post_id";
    $summaryRows = $wpdb->get_results($wpdb->prepare($summarySql, $summaryParams), ARRAY_A);
    $summaryQueryMs = max(0, (int)round((microtime(true) - $summaryQueryStartedAt) * 1000));

    $batch = [];
    foreach ((array)$summaryRows as $row) {
      $postId = isset($row['post_id']) ? (int)$row['post_id'] : 0;
      if ($postId < 1) {
        continue;
      }
      $batch[] = [
        'wpml_lang' => (string)($row['wpml_lang'] ?? $wpml_lang),
        'post_id' => $postId,
        'post_title' => sanitize_text_field((string)($row['post_title'] ?? '')),
        'post_type' => sanitize_key((string)($row['post_type'] ?? '')),
        'post_author' => sanitize_text_field((string)($row['post_author'] ?? '')),
        'post_author_id' => max(0, (int)($row['post_author_id'] ?? 0)),
        'post_date' => $this->normalize_db_datetime_or_null($row['post_date'] ?? ''),
        'post_modified' => $this->normalize_db_datetime_or_null($row['post_modified'] ?? ''),
        'page_url' => esc_url_raw((string)($row['page_url'] ?? '')),
        'inbound' => 0,
        'internal_outbound' => (int)($row['internal_outbound'] ?? 0),
        'outbound' => (int)($row['outbound'] ?? 0),
      ];
    }

    if (!empty($batch)) {
      $this->insert_indexed_summary_batch($summaryTable, $batch);
    }

    $lastPostId = max($postIds);
    return [
      'done' => count($postIds) < $limit,
      'processed_posts' => count($postIds),
      'last_post_id' => $lastPostId,
      'chunk_limit' => $limit,
      'summary_rows' => count((array)$summaryRows),
      'inbound_rows' => 0,
      'target_only_summary_rows' => 0,
      'summary_query_ms' => $summaryQueryMs,
      'inbound_query_ms' => 0,
      'step_ms' => max(0, (int)round((microtime(true) - $chunkStartedAt) * 1000)),
    ];
  }

  private function finalize_indexed_summary_inbound_chunk_for_lang($wpml_lang = 'all', $afterPostId = 0, $limit = 100) {
    global $wpdb;

    $factTable = $wpdb->prefix . 'lm_link_fact';
    $summaryTable = $wpdb->prefix . 'lm_link_post_summary';
    $wpml_lang = $this->normalize_rebuild_wpml_lang((string)$wpml_lang);
    $afterPostId = max(0, (int)$afterPostId);
    $limit = max(1, min(500, (int)$limit));
    $chunkStartedAt = microtime(true);

    $candidatePostIds = $this->query_pages_link_summary_candidate_post_ids_chunk($wpml_lang, $afterPostId, $limit);
    if (empty($candidatePostIds)) {
      return [
        'done' => true,
        'processed_posts' => 0,
        'last_post_id' => $afterPostId,
        'chunk_limit' => $limit,
        'summary_rows' => 0,
        'inbound_rows' => 0,
        'target_only_summary_rows' => 0,
        'summary_query_ms' => 0,
        'inbound_query_ms' => 0,
        'step_ms' => max(0, (int)round((microtime(true) - $chunkStartedAt) * 1000)),
      ];
    }

    $postTypes = array_keys($this->get_filterable_post_types());
    $postDataMap = $this->get_pages_link_post_data_map($candidatePostIds, $postTypes, true, [], $wpml_lang);
    $targetUrlMap = [];
    $targetPostIds = array_values(array_unique(array_map('intval', $candidatePostIds)));
    foreach ($targetPostIds as $postId) {
      if ($postId < 1) {
        continue;
      }
      $postData = isset($postDataMap[(string)$postId]) && is_array($postDataMap[(string)$postId]) ? $postDataMap[(string)$postId] : [];
      $pageUrl = (string)($postData['page_url'] ?? '');
      if ($pageUrl === '') {
        continue;
      }
      $targetVariants = $this->build_pages_link_target_variants($pageUrl);
      foreach ($targetVariants as $targetVariant) {
        $targetVariant = sanitize_text_field((string)$targetVariant);
        if ($targetVariant === '') {
          continue;
        }
        if (!isset($targetUrlMap[$targetVariant])) {
          $targetUrlMap[$targetVariant] = [];
        }
        $targetUrlMap[$targetVariant][] = $postId;
      }
    }

    $lastPostId = max($targetPostIds);
    if (empty($targetUrlMap) || empty($targetPostIds)) {
      $zeroBatch = [];
      $existingSummaryIds = $this->get_existing_indexed_summary_post_ids_for_lang($wpml_lang, $candidatePostIds);
      foreach ($existingSummaryIds as $postId) {
        if ($postId > 0) {
          $zeroBatch[] = ['wpml_lang' => $wpml_lang, 'post_id' => $postId, 'inbound' => 0];
        }
      }
      if (!empty($zeroBatch)) {
        $this->update_indexed_summary_inbound_batch($summaryTable, $zeroBatch);
      }
      return [
        'done' => count($candidatePostIds) < $limit,
        'processed_posts' => count($candidatePostIds),
        'last_post_id' => $lastPostId,
        'chunk_limit' => $limit,
        'summary_rows' => 0,
        'inbound_rows' => 0,
        'target_only_summary_rows' => 0,
        'summary_query_ms' => 0,
        'inbound_query_ms' => 0,
        'step_ms' => max(0, (int)round((microtime(true) - $chunkStartedAt) * 1000)),
      ];
    }

    $normalizedUrls = array_keys($targetUrlMap);
    $urlPlaceholders = implode(',', array_fill(0, count($normalizedUrls), '%s'));
    $inboundParams = array_merge([$wpml_lang], $normalizedUrls);
    $inboundQueryStartedAt = microtime(true);
    $inboundSql = "SELECT normalized_link, post_id
      FROM $factTable
      WHERE wpml_lang = %s
        AND link_type = 'inlink'
        AND normalized_link <> ''
        AND normalized_link IN ($urlPlaceholders)";
    $sourceRows = $wpdb->get_results($wpdb->prepare($inboundSql, $inboundParams), ARRAY_A);
    $inboundQueryMs = max(0, (int)round((microtime(true) - $inboundQueryStartedAt) * 1000));

    $existingSummaryIds = $this->get_existing_indexed_summary_post_ids_for_lang($wpml_lang, $candidatePostIds);
    $existingSummaryMap = [];
    foreach ($existingSummaryIds as $existingSummaryId) {
      $existingSummaryMap[(int)$existingSummaryId] = true;
    }
    $inboundCounts = [];
    foreach ($targetPostIds as $postId) {
      $inboundCounts[$postId] = 0;
    }
    foreach ((array)$sourceRows as $row) {
      $normalizedLink = sanitize_text_field((string)($row['normalized_link'] ?? ''));
      $sourcePostId = isset($row['post_id']) ? (int)$row['post_id'] : 0;
      if ($normalizedLink === '' || !isset($targetUrlMap[$normalizedLink])) {
        continue;
      }
      foreach ($targetUrlMap[$normalizedLink] as $targetPostId) {
        if ($targetPostId === $sourcePostId) {
          continue;
        }
        $inboundCounts[$targetPostId] = (int)($inboundCounts[$targetPostId] ?? 0) + 1;
      }
    }

    $targetOnlyBatch = [];
    foreach ($inboundCounts as $postId => $inbound) {
      if ($inbound < 1 || isset($existingSummaryMap[(int)$postId])) {
        continue;
      }
      $postData = isset($postDataMap[(string)$postId]) && is_array($postDataMap[(string)$postId]) ? $postDataMap[(string)$postId] : [];
      $targetOnlyBatch[] = [
        'wpml_lang' => $wpml_lang,
        'post_id' => (int)$postId,
        'post_title' => sanitize_text_field((string)($postData['post_title'] ?? '')),
        'post_type' => sanitize_key((string)($postData['post_type'] ?? '')),
        'post_author' => sanitize_text_field((string)($postData['author_name'] ?? '')),
        'post_author_id' => max(0, (int)($postData['author_id'] ?? 0)),
        'post_date' => $this->normalize_db_datetime_or_null($postData['post_date'] ?? ''),
        'post_modified' => $this->normalize_db_datetime_or_null($postData['post_modified'] ?? ''),
        'page_url' => esc_url_raw((string)($postData['page_url'] ?? '')),
        'inbound' => (int)$inbound,
        'internal_outbound' => 0,
        'outbound' => 0,
      ];
    }
    if (!empty($targetOnlyBatch)) {
      $this->insert_indexed_summary_batch($summaryTable, $targetOnlyBatch);
    }

    $batch = [];
    foreach ($inboundCounts as $postId => $inbound) {
      if (!isset($existingSummaryMap[(int)$postId])) {
        continue;
      }
      $batch[] = [
        'wpml_lang' => $wpml_lang,
        'post_id' => (int)$postId,
        'inbound' => (int)$inbound,
      ];
    }
    if (!empty($batch)) {
      $this->update_indexed_summary_inbound_batch($summaryTable, $batch);
    }

    return [
      'done' => count($candidatePostIds) < $limit,
      'processed_posts' => count($candidatePostIds),
      'last_post_id' => $lastPostId,
      'chunk_limit' => $limit,
      'summary_rows' => count($targetOnlyBatch) + count($existingSummaryIds),
      'inbound_rows' => count((array)$sourceRows),
      'target_only_summary_rows' => count($targetOnlyBatch),
      'summary_query_ms' => 0,
      'inbound_query_ms' => $inboundQueryMs,
      'step_ms' => max(0, (int)round((microtime(true) - $chunkStartedAt) * 1000)),
    ];
  }

  private function query_pages_link_summary_candidate_post_ids_chunk($wpml_lang = 'all', $afterPostId = 0, $limit = 100) {
    $postTypes = array_keys($this->get_filterable_post_types());
    $postTypes = array_values(array_unique(array_filter(array_map('sanitize_key', (array)$postTypes))));
    if (empty($postTypes)) {
      return [];
    }

    $afterPostId = max(0, (int)$afterPostId);
    $limit = max(1, (int)$limit);

    $queryArgs = [
      'post_type' => $postTypes,
      'post_status' => 'publish',
      'posts_per_page' => $limit,
      'fields' => 'ids',
      'suppress_filters' => false,
      'no_found_rows' => true,
      'orderby' => 'ID',
      'order' => 'ASC',
      'update_post_meta_cache' => false,
      'update_post_term_cache' => false,
      'cache_results' => false,
    ];

    if ($this->is_wpml_active()) {
      $queryArgs['lang'] = ($wpml_lang === 'all') ? '' : $wpml_lang;
    }

    $idWhereFilter = null;
    if ($afterPostId > 0) {
      global $wpdb;
      $idWhereFilter = function($where) use ($wpdb, $afterPostId) {
        return $where . $wpdb->prepare(" AND {$wpdb->posts}.ID > %d", $afterPostId);
      };
      add_filter('posts_where', $idWhereFilter, 10, 1);
    }

    try {
      $query = $this->run_wpml_scan_query_in_language_context($wpml_lang, function() use ($queryArgs) {
        return new WP_Query($queryArgs);
      });
    } finally {
      if ($idWhereFilter !== null) {
        remove_filter('posts_where', $idWhereFilter, 10);
      }
    }

    if (empty($query->posts)) {
      return [];
    }

    return array_values(array_unique(array_map('intval', (array)$query->posts)));
  }

  private function get_existing_indexed_summary_post_ids_for_lang($wpml_lang, $postIds) {
    global $wpdb;

    $postIds = array_values(array_unique(array_filter(array_map('intval', (array)$postIds))));
    if (empty($postIds)) {
      return [];
    }

    $summaryTable = $wpdb->prefix . 'lm_link_post_summary';
    $placeholders = implode(',', array_fill(0, count($postIds), '%d'));
    $params = array_merge([(string)$wpml_lang], $postIds);
    $sql = "SELECT post_id FROM $summaryTable WHERE wpml_lang = %s AND post_id IN ($placeholders)";
    $rows = $wpdb->get_col($wpdb->prepare($sql, $params));
    return array_values(array_unique(array_filter(array_map('intval', (array)$rows))));
  }

  private function insert_indexed_fact_batch($table, $batch) {
    global $wpdb;
    if (empty($batch) || !is_array($batch)) {
      return;
    }

    $supportsLinkDomain = $this->indexed_fact_has_link_domain_column();
    $supportsNormalizedUrls = $this->indexed_fact_has_normalized_url_columns();
    $columns = [
      'wpml_lang',
      'row_id',
      'post_id',
      'post_title',
      'post_type',
      'post_author',
      'post_author_id',
      'post_date',
      'post_modified',
      'page_url',
    ];
    if ($supportsNormalizedUrls) {
      $columns[] = 'normalized_page_url';
    }
    $columns = array_merge($columns, [
      'source',
      'link_location',
      'block_index',
      'occurrence',
      'link_type',
      'link',
    ]);
    if ($supportsNormalizedUrls) {
      $columns[] = 'normalized_link';
    }
    if ($supportsLinkDomain) {
      $columns[] = 'link_domain';
    }
    $columns = array_merge($columns, [
      'anchor_text',
      'alt_text',
      'snippet',
      'rel_raw',
      'relationship',
      'rel_nofollow',
      'rel_sponsored',
      'rel_ugc',
      'value_type',
    ]);

    $valuesSql = [];
    $params = [];
    foreach ($batch as $row) {
      $rowPlaceholders = ['%s','%s','%d','%s','%s','%s','%d','%s','%s','%s'];
      $params[] = (string)($row['wpml_lang'] ?? 'all');
      $params[] = (string)($row['row_id'] ?? '');
      $params[] = (int)($row['post_id'] ?? 0);
      $params[] = (string)($row['post_title'] ?? '');
      $params[] = (string)($row['post_type'] ?? '');
      $params[] = (string)($row['post_author'] ?? '');
      $params[] = (int)($row['post_author_id'] ?? 0);
      $params[] = $row['post_date'];
      $params[] = $row['post_modified'];
      $params[] = (string)($row['page_url'] ?? '');
      if ($supportsNormalizedUrls) {
        $rowPlaceholders[] = '%s';
        $params[] = (string)($row['normalized_page_url'] ?? '');
      }
      $rowPlaceholders = array_merge($rowPlaceholders, ['%s','%s','%s','%s','%s','%s']);
      $params[] = (string)($row['source'] ?? '');
      $params[] = (string)($row['link_location'] ?? '');
      $params[] = (string)($row['block_index'] ?? '');
      $params[] = (string)($row['occurrence'] ?? '');
      $params[] = (string)($row['link_type'] ?? '');
      $params[] = (string)($row['link'] ?? '');
      if ($supportsNormalizedUrls) {
        $rowPlaceholders[] = '%s';
        $params[] = (string)($row['normalized_link'] ?? '');
      }
      if ($supportsLinkDomain) {
        $rowPlaceholders[] = '%s';
        $params[] = (string)($row['link_domain'] ?? '');
      }
      $rowPlaceholders = array_merge($rowPlaceholders, ['%s','%s','%s','%s','%s','%d','%d','%d','%s']);
      $params[] = (string)($row['anchor_text'] ?? '');
      $params[] = (string)($row['alt_text'] ?? '');
      $params[] = (string)($row['snippet'] ?? '');
      $params[] = (string)($row['rel_raw'] ?? '');
      $params[] = (string)($row['relationship'] ?? '');
      $params[] = (int)($row['rel_nofollow'] ?? 0);
      $params[] = (int)($row['rel_sponsored'] ?? 0);
      $params[] = (int)($row['rel_ugc'] ?? 0);
      $params[] = (string)($row['value_type'] ?? '');
      $valuesSql[] = '(' . implode(',', $rowPlaceholders) . ')';
    }

    $updateParts = [
      'post_id=VALUES(post_id)',
      'post_title=VALUES(post_title)',
      'post_type=VALUES(post_type)',
      'post_author=VALUES(post_author)',
      'post_author_id=VALUES(post_author_id)',
      'post_date=VALUES(post_date)',
      'post_modified=VALUES(post_modified)',
      'page_url=VALUES(page_url)',
    ];
    if ($supportsNormalizedUrls) {
      $updateParts[] = 'normalized_page_url=VALUES(normalized_page_url)';
    }
    $updateParts = array_merge($updateParts, [
      'source=VALUES(source)',
      'link_location=VALUES(link_location)',
      'block_index=VALUES(block_index)',
      'occurrence=VALUES(occurrence)',
      'link_type=VALUES(link_type)',
      'link=VALUES(link)',
    ]);
    if ($supportsNormalizedUrls) {
      $updateParts[] = 'normalized_link=VALUES(normalized_link)';
    }
    if ($supportsLinkDomain) {
      $updateParts[] = 'link_domain=VALUES(link_domain)';
    }
    $updateParts = array_merge($updateParts, [
      'anchor_text=VALUES(anchor_text)',
      'alt_text=VALUES(alt_text)',
      'snippet=VALUES(snippet)',
      'rel_raw=VALUES(rel_raw)',
      'relationship=VALUES(relationship)',
      'rel_nofollow=VALUES(rel_nofollow)',
      'rel_sponsored=VALUES(rel_sponsored)',
      'rel_ugc=VALUES(rel_ugc)',
      'value_type=VALUES(value_type)',
    ]);

    $sql = "INSERT INTO $table (
      " . implode(',', $columns) . "
    ) VALUES " . implode(',', $valuesSql) . "
    ON DUPLICATE KEY UPDATE
      " . implode(",\n      ", $updateParts);

    $wpdb->query($wpdb->prepare($sql, $params));
  }

  private function insert_indexed_summary_batch($table, $batch) {
    global $wpdb;
    if (empty($batch) || !is_array($batch)) {
      return;
    }

    $valuesSql = [];
    $params = [];
    foreach ($batch as $row) {
      $valuesSql[] = '(%s,%d,%s,%s,%s,%d,%s,%s,%s,%d,%d,%d)';
      $params[] = (string)($row['wpml_lang'] ?? 'all');
      $params[] = (int)($row['post_id'] ?? 0);
      $params[] = (string)($row['post_title'] ?? '');
      $params[] = (string)($row['post_type'] ?? '');
      $params[] = (string)($row['post_author'] ?? '');
      $params[] = (int)($row['post_author_id'] ?? 0);
      $params[] = $row['post_date'];
      $params[] = $row['post_modified'];
      $params[] = (string)($row['page_url'] ?? '');
      $params[] = (int)($row['inbound'] ?? 0);
      $params[] = (int)($row['internal_outbound'] ?? 0);
      $params[] = (int)($row['outbound'] ?? 0);
    }

    $sql = "INSERT INTO $table (
      wpml_lang,post_id,post_title,post_type,post_author,post_author_id,post_date,post_modified,page_url,inbound,internal_outbound,outbound
    ) VALUES " . implode(',', $valuesSql) . "
    ON DUPLICATE KEY UPDATE
      post_title=VALUES(post_title),
      post_type=VALUES(post_type),
      post_author=VALUES(post_author),
      post_author_id=VALUES(post_author_id),
      post_date=VALUES(post_date),
      post_modified=VALUES(post_modified),
      page_url=VALUES(page_url),
      inbound=VALUES(inbound),
      internal_outbound=VALUES(internal_outbound),
      outbound=VALUES(outbound)";

    $wpdb->query($wpdb->prepare($sql, $params));
  }

  private function insert_indexed_domain_summary_batch($table, $batch) {
    global $wpdb;
    if (empty($batch) || !is_array($batch)) {
      return;
    }

    $valuesSql = [];
    $params = [];
    foreach ($batch as $row) {
      $valuesSql[] = '(%s,%d,%s,%s,%s,%d,%s,%s,%s,%s,%s,%d,%d,%d,%s,%s,%s,%s,%s,%d)';
      $params[] = (string)($row['wpml_lang'] ?? 'all');
      $params[] = (int)($row['post_id'] ?? 0);
      $params[] = (string)($row['post_title'] ?? '');
      $params[] = (string)($row['post_type'] ?? '');
      $params[] = (string)($row['post_author'] ?? '');
      $params[] = (int)($row['post_author_id'] ?? 0);
      $params[] = $row['post_date'];
      $params[] = $row['post_modified'];
      $params[] = (string)($row['page_url'] ?? '');
      $params[] = (string)($row['source_type'] ?? '');
      $params[] = (string)($row['link_location'] ?? '');
      $params[] = (int)($row['rel_nofollow'] ?? 0);
      $params[] = (int)($row['rel_sponsored'] ?? 0);
      $params[] = (int)($row['rel_ugc'] ?? 0);
      $params[] = (string)($row['domain'] ?? '');
      $params[] = (string)($row['link_url'] ?? '');
      $params[] = (string)($row['link_hash'] ?? '');
      $params[] = (string)($row['anchor_text'] ?? '');
      $params[] = (string)($row['anchor_hash'] ?? '');
      $params[] = (int)($row['cites'] ?? 0);
    }

    $sql = "INSERT INTO $table (
      wpml_lang,post_id,post_title,post_type,post_author,post_author_id,post_date,post_modified,page_url,source_type,link_location,rel_nofollow,rel_sponsored,rel_ugc,domain,link_url,link_hash,anchor_text,anchor_hash,cites
    ) VALUES " . implode(',', $valuesSql) . "
    ON DUPLICATE KEY UPDATE
      post_title=VALUES(post_title),
      post_type=VALUES(post_type),
      post_author=VALUES(post_author),
      post_author_id=VALUES(post_author_id),
      post_date=VALUES(post_date),
      post_modified=VALUES(post_modified),
      page_url=VALUES(page_url),
      domain=VALUES(domain),
      link_url=VALUES(link_url),
      anchor_text=VALUES(anchor_text),
      cites=VALUES(cites)";

    $wpdb->query($wpdb->prepare($sql, $params));
  }

  private function insert_indexed_anchor_summary_batch($table, $batch) {
    global $wpdb;
    if (empty($batch) || !is_array($batch)) {
      return;
    }

    $valuesSql = [];
    $params = [];
    foreach ($batch as $row) {
      $valuesSql[] = '(%s,%d,%s,%s,%s,%d,%s,%s,%s,%s,%s,%s,%d,%d,%d,%s,%s,%s,%s,%s,%d)';
      $params[] = (string)($row['wpml_lang'] ?? 'all');
      $params[] = (int)($row['post_id'] ?? 0);
      $params[] = (string)($row['post_title'] ?? '');
      $params[] = (string)($row['post_type'] ?? '');
      $params[] = (string)($row['post_author'] ?? '');
      $params[] = (int)($row['post_author_id'] ?? 0);
      $params[] = $row['post_date'];
      $params[] = $row['post_modified'];
      $params[] = (string)($row['page_url'] ?? '');
      $params[] = (string)($row['source_type'] ?? '');
      $params[] = (string)($row['link_location'] ?? '');
      $params[] = (string)($row['link_type'] ?? '');
      $params[] = (int)($row['rel_nofollow'] ?? 0);
      $params[] = (int)($row['rel_sponsored'] ?? 0);
      $params[] = (int)($row['rel_ugc'] ?? 0);
      $params[] = (string)($row['anchor_text'] ?? '');
      $params[] = (string)($row['anchor_hash'] ?? '');
      $params[] = (string)($row['quality'] ?? '');
      $params[] = (string)($row['link_url'] ?? '');
      $params[] = (string)($row['link_hash'] ?? '');
      $params[] = (int)($row['uses'] ?? 0);
    }

    $sql = "INSERT INTO $table (
      wpml_lang,post_id,post_title,post_type,post_author,post_author_id,post_date,post_modified,page_url,source_type,link_location,link_type,rel_nofollow,rel_sponsored,rel_ugc,anchor_text,anchor_hash,quality,link_url,link_hash,uses
    ) VALUES " . implode(',', $valuesSql) . "
    ON DUPLICATE KEY UPDATE
      post_title=VALUES(post_title),
      post_type=VALUES(post_type),
      post_author=VALUES(post_author),
      post_author_id=VALUES(post_author_id),
      post_date=VALUES(post_date),
      post_modified=VALUES(post_modified),
      page_url=VALUES(page_url),
      anchor_text=VALUES(anchor_text),
      quality=VALUES(quality),
      link_url=VALUES(link_url),
      uses=VALUES(uses)";

    $wpdb->query($wpdb->prepare($sql, $params));
  }

  private function build_indexed_domain_summary_chunk_for_lang($wpml_lang = 'all', $afterPostId = 0, $limit = 100) {
    global $wpdb;

    $factTable = $wpdb->prefix . 'lm_link_fact';
    $summaryTable = $wpdb->prefix . 'lm_link_domain_summary';
    $wpml_lang = $this->normalize_rebuild_wpml_lang((string)$wpml_lang);
    $afterPostId = max(0, (int)$afterPostId);
    $limit = max(1, min(500, (int)$limit));
    $chunkStartedAt = microtime(true);

    $postIds = $wpdb->get_col(
      $wpdb->prepare(
        "SELECT DISTINCT post_id
         FROM $factTable
         WHERE wpml_lang = %s
           AND post_id > %d
           AND post_id > 0
           AND link_type = 'exlink'
           AND link_domain <> ''
         ORDER BY post_id ASC
         LIMIT %d",
        $wpml_lang,
        $afterPostId,
        $limit
      )
    );
    $postIds = array_values(array_filter(array_map('intval', (array)$postIds)));
    if (empty($postIds)) {
      return [
        'done' => true,
        'processed_posts' => 0,
        'last_post_id' => $afterPostId,
        'summary_rows' => 0,
        'step_ms' => max(0, (int)round((microtime(true) - $chunkStartedAt) * 1000)),
      ];
    }

    $placeholders = implode(',', array_fill(0, count($postIds), '%d'));
    $params = array_merge([$wpml_lang], $postIds);
    $sql = "SELECT
      fact.wpml_lang,
      fact.post_id,
      MAX(fact.post_title) AS post_title,
      MAX(fact.post_type) AS post_type,
      MAX(fact.post_author) AS post_author,
      MAX(fact.post_author_id) AS post_author_id,
      MAX(fact.post_date) AS post_date,
      MAX(fact.post_modified) AS post_modified,
      MAX(fact.page_url) AS page_url,
      fact.source AS source_type,
      fact.link_location,
      fact.rel_nofollow,
      fact.rel_sponsored,
      fact.rel_ugc,
      fact.link_domain AS domain,
      fact.link AS link_url,
      MD5(COALESCE(NULLIF(fact.normalized_link, ''), fact.link)) AS link_hash,
      LEFT(COALESCE(fact.anchor_text, ''), 255) AS anchor_text,
      MD5(COALESCE(fact.anchor_text, '')) AS anchor_hash,
      COUNT(*) AS cites
    FROM $factTable fact
    WHERE fact.wpml_lang = %s
      AND fact.post_id IN ($placeholders)
      AND fact.link_type = 'exlink'
      AND fact.link_domain <> ''
    GROUP BY fact.wpml_lang, fact.post_id, fact.source, fact.link_location, fact.rel_nofollow, fact.rel_sponsored, fact.rel_ugc, fact.link_domain, fact.link, fact.anchor_text";
    $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);

    $batch = [];
    foreach ((array)$rows as $row) {
      $batch[] = [
        'wpml_lang' => (string)($row['wpml_lang'] ?? $wpml_lang),
        'post_id' => (int)($row['post_id'] ?? 0),
        'post_title' => sanitize_text_field((string)($row['post_title'] ?? '')),
        'post_type' => sanitize_key((string)($row['post_type'] ?? '')),
        'post_author' => sanitize_text_field((string)($row['post_author'] ?? '')),
        'post_author_id' => max(0, (int)($row['post_author_id'] ?? 0)),
        'post_date' => $this->normalize_db_datetime_or_null($row['post_date'] ?? ''),
        'post_modified' => $this->normalize_db_datetime_or_null($row['post_modified'] ?? ''),
        'page_url' => esc_url_raw((string)($row['page_url'] ?? '')),
        'source_type' => sanitize_key((string)($row['source_type'] ?? '')),
        'link_location' => sanitize_text_field((string)($row['link_location'] ?? '')),
        'rel_nofollow' => (int)($row['rel_nofollow'] ?? 0),
        'rel_sponsored' => (int)($row['rel_sponsored'] ?? 0),
        'rel_ugc' => (int)($row['rel_ugc'] ?? 0),
        'domain' => strtolower((string)($row['domain'] ?? '')),
        'link_url' => esc_url_raw((string)($row['link_url'] ?? '')),
        'link_hash' => sanitize_text_field((string)($row['link_hash'] ?? '')),
        'anchor_text' => sanitize_text_field((string)($row['anchor_text'] ?? '')),
        'anchor_hash' => sanitize_text_field((string)($row['anchor_hash'] ?? '')),
        'cites' => (int)($row['cites'] ?? 0),
      ];
    }

    if (!empty($batch)) {
      $this->insert_indexed_domain_summary_batch($summaryTable, $batch);
    }

    return [
      'done' => count($postIds) < $limit,
      'processed_posts' => count($postIds),
      'last_post_id' => max($postIds),
      'summary_rows' => count($batch),
      'step_ms' => max(0, (int)round((microtime(true) - $chunkStartedAt) * 1000)),
    ];
  }

  private function build_indexed_anchor_summary_chunk_for_lang($wpml_lang = 'all', $afterPostId = 0, $limit = 100) {
    global $wpdb;

    $factTable = $wpdb->prefix . 'lm_link_fact';
    $summaryTable = $wpdb->prefix . 'lm_anchor_text_summary';
    $wpml_lang = $this->normalize_rebuild_wpml_lang((string)$wpml_lang);
    $afterPostId = max(0, (int)$afterPostId);
    $limit = max(1, min(500, (int)$limit));
    $chunkStartedAt = microtime(true);

    $postIds = $wpdb->get_col(
      $wpdb->prepare(
        "SELECT DISTINCT post_id
         FROM $factTable
         WHERE wpml_lang = %s
           AND post_id > %d
           AND post_id > 0
         ORDER BY post_id ASC
         LIMIT %d",
        $wpml_lang,
        $afterPostId,
        $limit
      )
    );
    $postIds = array_values(array_filter(array_map('intval', (array)$postIds)));
    if (empty($postIds)) {
      return [
        'done' => true,
        'processed_posts' => 0,
        'last_post_id' => $afterPostId,
        'summary_rows' => 0,
        'step_ms' => max(0, (int)round((microtime(true) - $chunkStartedAt) * 1000)),
      ];
    }

    $placeholders = implode(',', array_fill(0, count($postIds), '%d'));
    $params = array_merge([$wpml_lang], $postIds);
    $sql = "SELECT
      fact.wpml_lang,
      fact.post_id,
      MAX(fact.post_title) AS post_title,
      MAX(fact.post_type) AS post_type,
      MAX(fact.post_author) AS post_author,
      MAX(fact.post_author_id) AS post_author_id,
      MAX(fact.post_date) AS post_date,
      MAX(fact.post_modified) AS post_modified,
      MAX(fact.page_url) AS page_url,
      fact.source AS source_type,
      fact.link_location,
      fact.link_type,
      fact.rel_nofollow,
      fact.rel_sponsored,
      fact.rel_ugc,
      LEFT(COALESCE(fact.anchor_text, ''), 255) AS anchor_text,
      MD5(COALESCE(fact.anchor_text, '')) AS anchor_hash,
      fact.link AS link_url,
      MD5(COALESCE(NULLIF(fact.normalized_link, ''), fact.link)) AS link_hash,
      COUNT(*) AS uses
    FROM $factTable fact
    WHERE fact.wpml_lang = %s
      AND fact.post_id IN ($placeholders)
    GROUP BY fact.wpml_lang, fact.post_id, fact.source, fact.link_location, fact.link_type, fact.rel_nofollow, fact.rel_sponsored, fact.rel_ugc, fact.anchor_text, fact.link";
    $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);

    $batch = [];
    foreach ((array)$rows as $row) {
      $anchorText = sanitize_text_field((string)($row['anchor_text'] ?? ''));
      $batch[] = [
        'wpml_lang' => (string)($row['wpml_lang'] ?? $wpml_lang),
        'post_id' => (int)($row['post_id'] ?? 0),
        'post_title' => sanitize_text_field((string)($row['post_title'] ?? '')),
        'post_type' => sanitize_key((string)($row['post_type'] ?? '')),
        'post_author' => sanitize_text_field((string)($row['post_author'] ?? '')),
        'post_author_id' => max(0, (int)($row['post_author_id'] ?? 0)),
        'post_date' => $this->normalize_db_datetime_or_null($row['post_date'] ?? ''),
        'post_modified' => $this->normalize_db_datetime_or_null($row['post_modified'] ?? ''),
        'page_url' => esc_url_raw((string)($row['page_url'] ?? '')),
        'source_type' => sanitize_key((string)($row['source_type'] ?? '')),
        'link_location' => sanitize_text_field((string)($row['link_location'] ?? '')),
        'link_type' => sanitize_key((string)($row['link_type'] ?? '')),
        'rel_nofollow' => (int)($row['rel_nofollow'] ?? 0),
        'rel_sponsored' => (int)($row['rel_sponsored'] ?? 0),
        'rel_ugc' => (int)($row['rel_ugc'] ?? 0),
        'anchor_text' => $anchorText,
        'anchor_hash' => sanitize_text_field((string)($row['anchor_hash'] ?? '')),
        'quality' => sanitize_key((string)$this->get_anchor_quality_label($anchorText)),
        'link_url' => esc_url_raw((string)($row['link_url'] ?? '')),
        'link_hash' => sanitize_text_field((string)($row['link_hash'] ?? '')),
        'uses' => (int)($row['uses'] ?? 0),
      ];
    }

    if (!empty($batch)) {
      $this->insert_indexed_anchor_summary_batch($summaryTable, $batch);
    }

    return [
      'done' => count($postIds) < $limit,
      'processed_posts' => count($postIds),
      'last_post_id' => max($postIds),
      'summary_rows' => count($batch),
      'step_ms' => max(0, (int)round((microtime(true) - $chunkStartedAt) * 1000)),
    ];
  }

  private function update_indexed_summary_inbound_batch($table, $batch) {
    global $wpdb;
    if (empty($batch) || !is_array($batch)) {
      return;
    }

    $valuesSql = [];
    $params = [];
    foreach ($batch as $row) {
      $valuesSql[] = '(%s,%d,%d)';
      $params[] = (string)($row['wpml_lang'] ?? 'all');
      $params[] = (int)($row['post_id'] ?? 0);
      $params[] = (int)($row['inbound'] ?? 0);
    }

    $sql = "INSERT INTO $table (
      wpml_lang,post_id,inbound
    ) VALUES " . implode(',', $valuesSql) . "
    ON DUPLICATE KEY UPDATE
      inbound=VALUES(inbound)";

    $wpdb->query($wpdb->prepare($sql, $params));
  }

  private function append_rows(&$dest, $rows) {
    foreach ((array)$rows as $row) {
      $dest[] = $row;
    }
  }

  private function compact_rows_for_pages_link(&$rows) {
    if (!is_array($rows) || empty($rows)) {
      return;
    }

    foreach ($rows as &$row) {
      $row = [
        'post_id' => (string)($row['post_id'] ?? ''),
        'page_url' => (string)($row['page_url'] ?? ''),
        'source' => (string)($row['source'] ?? ''),
        'link_type' => (string)($row['link_type'] ?? ''),
        'link' => (string)($row['link'] ?? ''),
        'link_location' => (string)($row['link_location'] ?? ''),
        'rel_nofollow' => (string)($row['rel_nofollow'] ?? '0'),
        'rel_sponsored' => (string)($row['rel_sponsored'] ?? '0'),
        'rel_ugc' => (string)($row['rel_ugc'] ?? '0'),
      ];
    }
    unset($row);
  }
}
