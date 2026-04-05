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
    $maxRows = $this->get_runtime_max_cache_rows();
    if (is_array($rows) && count($rows) > $maxRows) {
      $rows = array_slice($rows, 0, $maxRows);
    }
    set_transient($this->cache_key($scope_post_type, $wpml_lang), $rows, self::CACHE_TTL);
    set_transient($this->cache_backup_key($scope_post_type, $wpml_lang), $rows, self::CACHE_BASE_TTL);
    update_option($this->cache_scan_option_key($scope_post_type, $wpml_lang), gmdate('Y-m-d H:i:s'), false);
    $this->bump_dataset_cache_version();

    $scope_post_type = sanitize_key((string)$scope_post_type);
    if ($scope_post_type === 'any') {
      $this->sync_indexed_datastore_from_rows($rows, $wpml_lang);
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
    $wpml_lang = $this->get_effective_scan_wpml_lang((string)$wpml_lang);
    $rows = is_array($rows) ? $rows : [];
    $this->reset_indexed_datastore_for_lang($wpml_lang);
    if (!empty($rows)) {
      $this->append_indexed_datastore_rows($rows, $wpml_lang);
    }
    $this->rebuild_indexed_summary_for_lang($wpml_lang);
  }

  private function clear_indexed_summary_for_lang($wpml_lang = 'all') {
    global $wpdb;
    $summaryTable = $wpdb->prefix . 'lm_link_post_summary';

    $wpml_lang = $this->get_effective_scan_wpml_lang((string)$wpml_lang);
    $wpdb->query($wpdb->prepare("DELETE FROM $summaryTable WHERE wpml_lang = %s", $wpml_lang));
  }

  private function reset_indexed_datastore_for_lang($wpml_lang = 'all') {
    global $wpdb;
    $factTable = $wpdb->prefix . 'lm_link_fact';
    $summaryTable = $wpdb->prefix . 'lm_link_post_summary';

    $wpml_lang = $this->get_effective_scan_wpml_lang((string)$wpml_lang);
    $wpdb->query($wpdb->prepare("DELETE FROM $factTable WHERE wpml_lang = %s", $wpml_lang));
    $wpdb->query($wpdb->prepare("DELETE FROM $summaryTable WHERE wpml_lang = %s", $wpml_lang));
  }

  private function append_indexed_datastore_rows($rows, $wpml_lang = 'all') {
    global $wpdb;
    $factTable = $wpdb->prefix . 'lm_link_fact';
    $wpml_lang = $this->get_effective_scan_wpml_lang((string)$wpml_lang);
    $rows = is_array($rows) ? $rows : [];
    if (empty($rows)) {
      return 0;
    }

    $factBatch = [];
    $factChunkSize = 200;
    $insertedRows = 0;

    foreach ($rows as $row) {
      $postId = isset($row['post_id']) ? (int)$row['post_id'] : 0;
      $rowId = sanitize_text_field((string)($row['row_id'] ?? ''));
      if ($rowId === '') {
        continue;
      }

      $postTitle = sanitize_text_field((string)($row['post_title'] ?? ''));
      $postType = sanitize_key((string)($row['post_type'] ?? ''));
      $postAuthor = sanitize_text_field((string)($row['post_author'] ?? ''));
      $postDate = $this->normalize_db_datetime_or_null($row['post_date'] ?? '');
      $postModified = $this->normalize_db_datetime_or_null($row['post_modified'] ?? '');
      $pageUrl = esc_url_raw((string)($row['page_url'] ?? ''));
      $source = sanitize_key((string)($row['source'] ?? ''));
      $linkLocation = sanitize_text_field((string)($row['link_location'] ?? ''));
      $blockIndex = sanitize_text_field((string)($row['block_index'] ?? ''));
      $occurrence = sanitize_text_field((string)($row['occurrence'] ?? ''));
      $linkType = sanitize_key((string)($row['link_type'] ?? ''));
      $link = esc_url_raw((string)($row['link'] ?? ''));
      $linkDomain = strtolower((string)parse_url($link, PHP_URL_HOST));
      $anchorText = sanitize_text_field((string)($row['anchor_text'] ?? ''));
      $altText = sanitize_text_field((string)($row['alt_text'] ?? ''));
      $snippet = sanitize_textarea_field((string)($row['snippet'] ?? ''));
      $relRaw = sanitize_text_field((string)($row['rel_raw'] ?? ''));
      $relationship = sanitize_text_field((string)($row['relationship'] ?? ''));
      $relNofollow = !empty($row['rel_nofollow']) ? 1 : 0;
      $relSponsored = !empty($row['rel_sponsored']) ? 1 : 0;
      $relUgc = !empty($row['rel_ugc']) ? 1 : 0;
      $valueType = sanitize_key((string)($row['value_type'] ?? ''));

      $factBatch[] = [
        'wpml_lang' => $wpml_lang,
        'row_id' => $rowId,
        'post_id' => $postId,
        'post_title' => $postTitle,
        'post_type' => $postType,
        'post_author' => $postAuthor,
        'post_date' => $postDate,
        'post_modified' => $postModified,
        'page_url' => $pageUrl,
        'source' => $source,
        'link_location' => $linkLocation,
        'block_index' => $blockIndex,
        'occurrence' => $occurrence,
        'link_type' => $linkType,
        'link' => $link,
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

      if (count($factBatch) >= $factChunkSize) {
        $this->insert_indexed_fact_batch($factTable, $factBatch);
        $insertedRows += count($factBatch);
        $factBatch = [];
      }
    }

    if (!empty($factBatch)) {
      $this->insert_indexed_fact_batch($factTable, $factBatch);
      $insertedRows += count($factBatch);
    }

    return $insertedRows;
  }

  private function rebuild_indexed_summary_for_lang($wpml_lang = 'all') {
    $wpml_lang = $this->get_effective_scan_wpml_lang((string)$wpml_lang);
    $this->clear_indexed_summary_for_lang($wpml_lang);

    $afterPostId = 0;
    do {
      $result = $this->rebuild_indexed_summary_chunk_for_lang($wpml_lang, $afterPostId, 100);
      $afterPostId = (int)($result['last_post_id'] ?? 0);
    } while (empty($result['done']));
  }

  private function rebuild_indexed_summary_chunk_for_lang($wpml_lang = 'all', $afterPostId = 0, $limit = 100) {
    global $wpdb;

    $factTable = $wpdb->prefix . 'lm_link_fact';
    $summaryTable = $wpdb->prefix . 'lm_link_post_summary';
    $wpml_lang = $this->get_effective_scan_wpml_lang((string)$wpml_lang);
    $afterPostId = max(0, (int)$afterPostId);
    $limit = max(1, min(500, (int)$limit));

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
      ];
    }

    $placeholders = implode(',', array_fill(0, count($postIds), '%d'));
    $summaryParams = array_merge([$wpml_lang], $postIds);
    $summarySql = "SELECT
      fact.wpml_lang,
      fact.post_id,
      MAX(fact.post_title) AS post_title,
      MAX(fact.post_type) AS post_type,
      MAX(fact.post_author) AS post_author,
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

    $normalizedTargetPageExpr = "TRIM(TRAILING '/' FROM target.page_url)";
    $normalizedSourceLinkExpr = "TRIM(TRAILING '/' FROM src.link)";
    $inboundParams = array_merge([$wpml_lang], $postIds);
    $inboundSql = "SELECT
      target.post_id AS post_id,
      COUNT(*) AS inbound
    FROM $factTable src
    INNER JOIN $factTable target
      ON target.wpml_lang = src.wpml_lang
      AND $normalizedTargetPageExpr = $normalizedSourceLinkExpr
      AND target.post_id <> src.post_id
    WHERE src.wpml_lang = %s
      AND src.link_type = 'inlink'
      AND src.link <> ''
      AND target.page_url <> ''
      AND target.post_id IN ($placeholders)
    GROUP BY target.post_id";
    $inboundRows = $wpdb->get_results($wpdb->prepare($inboundSql, $inboundParams), ARRAY_A);

    $inboundMap = [];
    foreach ((array)$inboundRows as $row) {
      $postId = isset($row['post_id']) ? (int)$row['post_id'] : 0;
      if ($postId < 1) {
        continue;
      }
      $inboundMap[$postId] = (int)($row['inbound'] ?? 0);
    }

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
        'post_date' => $this->normalize_db_datetime_or_null($row['post_date'] ?? ''),
        'post_modified' => $this->normalize_db_datetime_or_null($row['post_modified'] ?? ''),
        'page_url' => esc_url_raw((string)($row['page_url'] ?? '')),
        'inbound' => (int)($inboundMap[$postId] ?? 0),
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
    ];
  }

  private function insert_indexed_fact_batch($table, $batch) {
    global $wpdb;
    if (empty($batch) || !is_array($batch)) {
      return;
    }

    $supportsLinkDomain = $this->indexed_fact_has_link_domain_column();
    $valuesSql = [];
    $params = [];
    foreach ($batch as $row) {
      if ($supportsLinkDomain) {
        $valuesSql[] = '(%s,%s,%d,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%d,%d,%d,%s)';
      } else {
        $valuesSql[] = '(%s,%s,%d,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%d,%d,%d,%s)';
      }
      $params[] = (string)($row['wpml_lang'] ?? 'all');
      $params[] = (string)($row['row_id'] ?? '');
      $params[] = (int)($row['post_id'] ?? 0);
      $params[] = (string)($row['post_title'] ?? '');
      $params[] = (string)($row['post_type'] ?? '');
      $params[] = (string)($row['post_author'] ?? '');
      $params[] = $row['post_date'];
      $params[] = $row['post_modified'];
      $params[] = (string)($row['page_url'] ?? '');
      $params[] = (string)($row['source'] ?? '');
      $params[] = (string)($row['link_location'] ?? '');
      $params[] = (string)($row['block_index'] ?? '');
      $params[] = (string)($row['occurrence'] ?? '');
      $params[] = (string)($row['link_type'] ?? '');
      $params[] = (string)($row['link'] ?? '');
      if ($supportsLinkDomain) {
        $params[] = (string)($row['link_domain'] ?? '');
      }
      $params[] = (string)($row['anchor_text'] ?? '');
      $params[] = (string)($row['alt_text'] ?? '');
      $params[] = (string)($row['snippet'] ?? '');
      $params[] = (string)($row['rel_raw'] ?? '');
      $params[] = (string)($row['relationship'] ?? '');
      $params[] = (int)($row['rel_nofollow'] ?? 0);
      $params[] = (int)($row['rel_sponsored'] ?? 0);
      $params[] = (int)($row['rel_ugc'] ?? 0);
      $params[] = (string)($row['value_type'] ?? '');
    }

    if ($supportsLinkDomain) {
      $sql = "INSERT INTO $table (
        wpml_lang,row_id,post_id,post_title,post_type,post_author,post_date,post_modified,page_url,
        source,link_location,block_index,occurrence,link_type,link,link_domain,anchor_text,alt_text,snippet,rel_raw,relationship,rel_nofollow,rel_sponsored,rel_ugc,value_type
      ) VALUES " . implode(',', $valuesSql) . "
      ON DUPLICATE KEY UPDATE
        post_id=VALUES(post_id),
        post_title=VALUES(post_title),
        post_type=VALUES(post_type),
        post_author=VALUES(post_author),
        post_date=VALUES(post_date),
        post_modified=VALUES(post_modified),
        page_url=VALUES(page_url),
        source=VALUES(source),
        link_location=VALUES(link_location),
        block_index=VALUES(block_index),
        occurrence=VALUES(occurrence),
        link_type=VALUES(link_type),
        link=VALUES(link),
        link_domain=VALUES(link_domain),
        anchor_text=VALUES(anchor_text),
        alt_text=VALUES(alt_text),
        snippet=VALUES(snippet),
        rel_raw=VALUES(rel_raw),
        relationship=VALUES(relationship),
        rel_nofollow=VALUES(rel_nofollow),
        rel_sponsored=VALUES(rel_sponsored),
        rel_ugc=VALUES(rel_ugc),
        value_type=VALUES(value_type)";
    } else {
      $sql = "INSERT INTO $table (
        wpml_lang,row_id,post_id,post_title,post_type,post_author,post_date,post_modified,page_url,
        source,link_location,block_index,occurrence,link_type,link,anchor_text,alt_text,snippet,rel_raw,relationship,rel_nofollow,rel_sponsored,rel_ugc,value_type
      ) VALUES " . implode(',', $valuesSql) . "
      ON DUPLICATE KEY UPDATE
        post_id=VALUES(post_id),
        post_title=VALUES(post_title),
        post_type=VALUES(post_type),
        post_author=VALUES(post_author),
        post_date=VALUES(post_date),
        post_modified=VALUES(post_modified),
        page_url=VALUES(page_url),
        source=VALUES(source),
        link_location=VALUES(link_location),
        block_index=VALUES(block_index),
        occurrence=VALUES(occurrence),
        link_type=VALUES(link_type),
        link=VALUES(link),
        anchor_text=VALUES(anchor_text),
        alt_text=VALUES(alt_text),
        snippet=VALUES(snippet),
        rel_raw=VALUES(rel_raw),
        relationship=VALUES(relationship),
        rel_nofollow=VALUES(rel_nofollow),
        rel_sponsored=VALUES(rel_sponsored),
        rel_ugc=VALUES(rel_ugc),
        value_type=VALUES(value_type)";
    }

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
      $valuesSql[] = '(%s,%d,%s,%s,%s,%s,%s,%s,%d,%d,%d)';
      $params[] = (string)($row['wpml_lang'] ?? 'all');
      $params[] = (int)($row['post_id'] ?? 0);
      $params[] = (string)($row['post_title'] ?? '');
      $params[] = (string)($row['post_type'] ?? '');
      $params[] = (string)($row['post_author'] ?? '');
      $params[] = $row['post_date'];
      $params[] = $row['post_modified'];
      $params[] = (string)($row['page_url'] ?? '');
      $params[] = (int)($row['inbound'] ?? 0);
      $params[] = (int)($row['internal_outbound'] ?? 0);
      $params[] = (int)($row['outbound'] ?? 0);
    }

    $sql = "INSERT INTO $table (
      wpml_lang,post_id,post_title,post_type,post_author,post_date,post_modified,page_url,inbound,internal_outbound,outbound
    ) VALUES " . implode(',', $valuesSql) . "
    ON DUPLICATE KEY UPDATE
      post_title=VALUES(post_title),
      post_type=VALUES(post_type),
      post_author=VALUES(post_author),
      post_date=VALUES(post_date),
      post_modified=VALUES(post_modified),
      page_url=VALUES(page_url),
      inbound=VALUES(inbound),
      internal_outbound=VALUES(internal_outbound),
      outbound=VALUES(outbound)";

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
