<?php
/**
 * Cache persistence and indexed datastore synchronization helpers.
 */

trait LM_Cache_Index_Sync_Trait {
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

    $scope_post_type = sanitize_key((string)$scope_post_type);
    if ($scope_post_type === 'any') {
      $this->sync_indexed_datastore_from_rows($rows, $wpml_lang);
      $this->warm_precomputed_stats_snapshot($rows, 'any', $wpml_lang, false);
    }
  }

  private function normalize_db_datetime_or_null($raw) {
    $raw = trim((string)$raw);
    if ($raw === '' || $raw === '0000-00-00 00:00:00') {
      return null;
    }
    return $raw;
  }

  private function sync_indexed_datastore_from_rows($rows, $wpml_lang = 'all') {
    global $wpdb;
    $factTable = $wpdb->prefix . 'lm_link_fact';
    $summaryTable = $wpdb->prefix . 'lm_link_post_summary';

    $wpml_lang = $this->get_effective_scan_wpml_lang((string)$wpml_lang);
    $rows = is_array($rows) ? $rows : [];

    $wpdb->query($wpdb->prepare("DELETE FROM $factTable WHERE wpml_lang = %s", $wpml_lang));
    $wpdb->query($wpdb->prepare("DELETE FROM $summaryTable WHERE wpml_lang = %s", $wpml_lang));

    if (empty($rows)) {
      return;
    }

    $factBatch = [];
    $factChunkSize = 200;

    $postMeta = [];
    $urlToPost = [];
    $internalOutbound = [];
    $outbound = [];
    $inbound = [];

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
        $factBatch = [];
      }

      if ($postId > 0) {
        if (!isset($postMeta[$postId])) {
          $postMeta[$postId] = [
            'post_id' => $postId,
            'post_title' => $postTitle,
            'post_type' => $postType,
            'post_author' => $postAuthor,
            'post_date' => $postDate,
            'post_modified' => $postModified,
            'page_url' => $pageUrl,
          ];
          if ($pageUrl !== '') {
            $urlToPost[$this->normalize_for_compare($pageUrl)] = $postId;
          }
        }

        if ($linkType === 'inlink') {
          $internalOutbound[$postId] = (int)($internalOutbound[$postId] ?? 0) + 1;
        } elseif ($linkType === 'exlink') {
          $outbound[$postId] = (int)($outbound[$postId] ?? 0) + 1;
        }
      }
    }

    if (!empty($factBatch)) {
      $this->insert_indexed_fact_batch($factTable, $factBatch);
    }

    foreach ($rows as $row) {
      $sourcePostId = isset($row['post_id']) ? (int)$row['post_id'] : 0;
      if ($sourcePostId < 1) {
        continue;
      }
      $linkType = sanitize_key((string)($row['link_type'] ?? ''));
      if ($linkType !== 'inlink') {
        continue;
      }
      $targetUrl = $this->normalize_for_compare((string)($row['link'] ?? ''));
      if ($targetUrl === '' || !isset($urlToPost[$targetUrl])) {
        continue;
      }
      $targetPostId = (int)$urlToPost[$targetUrl];
      if ($targetPostId > 0 && $targetPostId !== $sourcePostId) {
        $inbound[$targetPostId] = (int)($inbound[$targetPostId] ?? 0) + 1;
      }
    }

    $summaryBatch = [];
    $summaryChunkSize = 300;
    foreach ($postMeta as $postId => $meta) {
      $summaryBatch[] = [
        'wpml_lang' => $wpml_lang,
        'post_id' => (int)$postId,
        'post_title' => sanitize_text_field((string)($meta['post_title'] ?? '')),
        'post_type' => sanitize_key((string)($meta['post_type'] ?? '')),
        'post_author' => sanitize_text_field((string)($meta['post_author'] ?? '')),
        'post_date' => $this->normalize_db_datetime_or_null($meta['post_date'] ?? ''),
        'post_modified' => $this->normalize_db_datetime_or_null($meta['post_modified'] ?? ''),
        'page_url' => esc_url_raw((string)($meta['page_url'] ?? '')),
        'inbound' => (int)($inbound[$postId] ?? 0),
        'internal_outbound' => (int)($internalOutbound[$postId] ?? 0),
        'outbound' => (int)($outbound[$postId] ?? 0),
      ];

      if (count($summaryBatch) >= $summaryChunkSize) {
        $this->insert_indexed_summary_batch($summaryTable, $summaryBatch);
        $summaryBatch = [];
      }
    }

    if (!empty($summaryBatch)) {
      $this->insert_indexed_summary_batch($summaryTable, $summaryBatch);
    }
  }

  private function insert_indexed_fact_batch($table, $batch) {
    global $wpdb;
    if (empty($batch) || !is_array($batch)) {
      return;
    }

    $valuesSql = [];
    $params = [];
    foreach ($batch as $row) {
      $valuesSql[] = '(%s,%s,%d,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%d,%d,%d,%s)';
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
