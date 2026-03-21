<?php
/**
 * Indexed datastore access helpers for fact and summary tables.
 */

trait LM_Indexed_Datastore_Trait {
  private function get_indexed_datastore_tables() {
    global $wpdb;
    return [
      'fact' => $wpdb->prefix . 'lm_link_fact',
      'summary' => $wpdb->prefix . 'lm_link_post_summary',
    ];
  }

  private function is_indexed_datastore_ready() {
    static $ready = null;
    if ($ready !== null) {
      return (bool)$ready;
    }

    global $wpdb;
    $tables = $this->get_indexed_datastore_tables();
    $fact = (string)$tables['fact'];
    $summary = (string)$tables['summary'];

    $factExists = (string)$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $fact));
    $summaryExists = (string)$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $summary));
    $ready = ($factExists === $fact && $summaryExists === $summary);
    return (bool)$ready;
  }

  private function maybe_self_heal_indexed_datastore($wpmlLang = 'all') {
    if (!$this->is_indexed_datastore_ready()) {
      return false;
    }

    $wpmlLang = $this->get_effective_scan_wpml_lang((string)$wpmlLang);
    $langsToTry = [$wpmlLang];
    if ($wpmlLang !== 'all') {
      $langsToTry[] = 'all';
    }
    $langsToTry = array_values(array_unique(array_filter(array_map('strval', $langsToTry))));

    foreach ($langsToTry as $lang) {
      if ($this->get_indexed_fact_count('any', $lang) > 0) {
        return true;
      }
    }

    static $attempted = [];
    $attemptKey = get_current_blog_id() . '|' . implode('|', $langsToTry);
    if (isset($attempted[$attemptKey])) {
      return false;
    }
    $attempted[$attemptKey] = true;

    foreach ($langsToTry as $lang) {
      $mainRows = get_transient($this->cache_key('any', $lang));
      if (is_array($mainRows) && !empty($mainRows)) {
        $this->sync_indexed_datastore_from_rows($mainRows, $lang);
        if ($this->get_indexed_fact_count('any', $lang) > 0) {
          return true;
        }
      }

      $backupRows = get_transient($this->cache_backup_key('any', $lang));
      if (is_array($backupRows) && !empty($backupRows)) {
        $this->sync_indexed_datastore_from_rows($backupRows, $lang);
        if ($this->get_indexed_fact_count('any', $lang) > 0) {
          return true;
        }
      }
    }

    foreach ($langsToTry as $lang) {
      $this->schedule_background_rebuild('any', $lang, 1);
    }

    return false;
  }

  private function resolve_indexed_datastore_scope($scopePostType = 'any', $wpmlLang = 'all') {
    if (!$this->is_indexed_datastore_ready()) {
      return null;
    }

    $scopePostType = sanitize_key((string)$scopePostType);
    if ($scopePostType === '') {
      $scopePostType = 'any';
    }
    $wpmlLang = $this->get_effective_scan_wpml_lang((string)$wpmlLang);

    $this->maybe_self_heal_indexed_datastore($wpmlLang);

    if ($this->get_indexed_fact_count($scopePostType, $wpmlLang) > 0) {
      return [
        'scope_post_type' => $scopePostType,
        'wpml_lang' => $wpmlLang,
      ];
    }

    if (($scopePostType !== 'any' || $wpmlLang !== 'all') && $this->get_indexed_fact_count('any', 'all') > 0) {
      return [
        'scope_post_type' => 'any',
        'wpml_lang' => 'all',
      ];
    }

    return null;
  }

  private function append_indexed_text_match_clause(&$whereParts, &$params, $column, $needle, $mode) {
    global $wpdb;

    $needle = trim((string)$needle);
    if ($needle === '') {
      return false;
    }

    $mode = $this->sanitize_text_match_mode($mode);
    if ($mode === 'regex') {
      return false;
    }

    $needleLower = strtolower($needle);
    if ($mode === 'exact') {
      $whereParts[] = "LOWER($column) = %s";
      $params[] = $needleLower;
      return true;
    }

    if ($mode === 'starts_with') {
      $whereParts[] = "LOWER($column) LIKE %s";
      $params[] = strtolower($wpdb->esc_like($needle)) . '%';
      return true;
    }

    if ($mode === 'ends_with') {
      $whereParts[] = "LOWER($column) LIKE %s";
      $params[] = '%' . strtolower($wpdb->esc_like($needle));
      return true;
    }

    $whereParts[] = "LOWER($column) LIKE %s";
    $params[] = '%' . strtolower($wpdb->esc_like($needle)) . '%';
    return true;
  }

  private function get_indexed_fact_rows($scopePostType = 'any', $wpmlLang = 'all', $filters = null) {
    global $wpdb;
    $table = $wpdb->prefix . 'lm_link_fact';

    $scopePostType = sanitize_key((string)$scopePostType);
    if ($scopePostType === '') {
      $scopePostType = 'any';
    }
    $wpmlLang = $this->get_effective_scan_wpml_lang((string)$wpmlLang);

    if (!$this->is_indexed_datastore_ready()) {
      return [];
    }

    $whereParts = ['wpml_lang = %s'];
    $params = [$wpmlLang];
    if ($scopePostType !== 'any') {
      $whereParts[] = 'post_type = %s';
      $params[] = $scopePostType;
    }

    if (is_array($filters) && !empty($filters)) {
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

      $linkType = isset($filters['link_type']) ? (string)$filters['link_type'] : 'any';
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

      $textMode = isset($filters['text_match_mode']) ? $filters['text_match_mode'] : 'contains';
      $this->append_indexed_text_match_clause($whereParts, $params, 'link', isset($filters['value_contains']) ? $filters['value_contains'] : '', $textMode);
      $this->append_indexed_text_match_clause($whereParts, $params, 'page_url', isset($filters['source_contains']) ? $filters['source_contains'] : '', $textMode);
      $this->append_indexed_text_match_clause($whereParts, $params, 'post_title', isset($filters['title_contains']) ? $filters['title_contains'] : '', $textMode);
      $this->append_indexed_text_match_clause($whereParts, $params, 'post_author', isset($filters['author_contains']) ? $filters['author_contains'] : '', $textMode);
      $this->append_indexed_text_match_clause($whereParts, $params, 'anchor_text', isset($filters['anchor_contains']) ? $filters['anchor_contains'] : '', $textMode);

      $publishDateFrom = isset($filters['publish_date_from']) ? trim((string)$filters['publish_date_from']) : '';
      $publishDateTo = isset($filters['publish_date_to']) ? trim((string)$filters['publish_date_to']) : '';
      $updatedDateFrom = isset($filters['updated_date_from']) ? trim((string)$filters['updated_date_from']) : '';
      $updatedDateTo = isset($filters['updated_date_to']) ? trim((string)$filters['updated_date_to']) : '';
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
    }

    $where = 'WHERE ' . implode(' AND ', $whereParts);

    $sql = "SELECT
      row_id, post_id, post_title, post_type, post_author, post_date, post_modified,
      page_url, source, link_location, block_index, occurrence, link_type, link, anchor_text,
      alt_text, snippet, rel_raw, relationship, rel_nofollow, rel_sponsored, rel_ugc, value_type
      FROM $table
      $where";

    $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
    if (!is_array($rows) || empty($rows)) {
      return [];
    }

    $out = [];
    foreach ($rows as $row) {
      $out[] = [
        'row_id' => (string)($row['row_id'] ?? ''),
        'post_id' => (string)((int)($row['post_id'] ?? 0)),
        'post_title' => (string)($row['post_title'] ?? ''),
        'post_type' => (string)($row['post_type'] ?? ''),
        'post_author' => (string)($row['post_author'] ?? ''),
        'post_date' => (string)($row['post_date'] ?? ''),
        'post_modified' => (string)($row['post_modified'] ?? ''),
        'page_url' => (string)($row['page_url'] ?? ''),
        'source' => (string)($row['source'] ?? ''),
        'link_location' => (string)($row['link_location'] ?? ''),
        'block_index' => (string)($row['block_index'] ?? ''),
        'occurrence' => (string)($row['occurrence'] ?? ''),
        'link_type' => (string)($row['link_type'] ?? ''),
        'link' => (string)($row['link'] ?? ''),
        'anchor_text' => (string)($row['anchor_text'] ?? ''),
        'alt_text' => (string)($row['alt_text'] ?? ''),
        'snippet' => (string)($row['snippet'] ?? ''),
        'relationship' => (string)($row['relationship'] ?? ''),
        'rel_raw' => (string)($row['rel_raw'] ?? ''),
        'rel_nofollow' => !empty($row['rel_nofollow']) ? '1' : '0',
        'rel_sponsored' => !empty($row['rel_sponsored']) ? '1' : '0',
        'rel_ugc' => !empty($row['rel_ugc']) ? '1' : '0',
        'value_type' => (string)($row['value_type'] ?? ''),
      ];
    }

    return $out;
  }

  private function get_indexed_summary_map($scopePostType = 'any', $wpmlLang = 'all') {
    global $wpdb;
    if (!$this->is_indexed_datastore_ready()) {
      return [];
    }

    $table = $wpdb->prefix . 'lm_link_post_summary';
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

    $sql = "SELECT post_id, inbound, internal_outbound, outbound FROM $table WHERE " . implode(' AND ', $whereParts);
    $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
    if (!is_array($rows) || empty($rows)) {
      return [];
    }

    $out = [];
    foreach ($rows as $row) {
      $postId = isset($row['post_id']) ? (int)$row['post_id'] : 0;
      if ($postId < 1) {
        continue;
      }
      $out[(string)$postId] = [
        'inbound' => (int)($row['inbound'] ?? 0),
        'internal_outbound' => (int)($row['internal_outbound'] ?? 0),
        'outbound' => (int)($row['outbound'] ?? 0),
      ];
    }

    return $out;
  }

  private function get_indexed_fact_count($scopePostType = 'any', $wpmlLang = 'all') {
    global $wpdb;

    if (!$this->is_indexed_datastore_ready()) {
      return 0;
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

    $sql = "SELECT COUNT(*) FROM $table WHERE " . implode(' AND ', $whereParts);
    return max(0, (int)$wpdb->get_var($wpdb->prepare($sql, $params)));
  }

  private function can_use_indexed_pages_link_summary_fastpath($filters) {
    if (!is_array($filters)) {
      return false;
    }

    if ((string)($filters['location'] ?? 'any') !== 'any') return false;
    if ((string)($filters['source_type'] ?? 'any') !== 'any') return false;
    if ((string)($filters['link_type'] ?? 'any') !== 'any') return false;
    if ((string)($filters['seo_flag'] ?? 'any') !== 'any') return false;
    if (trim((string)($filters['value_contains'] ?? '')) !== '') return false;

    return true;
  }

  private function can_use_indexed_pages_link_paged_fastpath($filters) {
    if (!$this->can_use_indexed_pages_link_summary_fastpath($filters)) {
      return false;
    }

    if (trim((string)($filters['search'] ?? '')) !== '') return false;
    if (trim((string)($filters['search_url'] ?? '')) !== '') return false;

    if ((int)($filters['inbound_min'] ?? -1) >= 0) return false;
    if ((int)($filters['inbound_max'] ?? -1) >= 0) return false;
    if ((int)($filters['internal_outbound_min'] ?? -1) >= 0) return false;
    if ((int)($filters['internal_outbound_max'] ?? -1) >= 0) return false;
    if ((int)($filters['outbound_min'] ?? -1) >= 0) return false;
    if ((int)($filters['outbound_max'] ?? -1) >= 0) return false;

    if ((string)($filters['status'] ?? 'any') !== 'any') return false;
    if ((string)($filters['internal_outbound_status'] ?? 'any') !== 'any') return false;
    if ((string)($filters['external_outbound_status'] ?? 'any') !== 'any') return false;

    $orderby = (string)($filters['orderby'] ?? 'date');
    return in_array($orderby, ['post_id', 'date', 'modified', 'title'], true);
  }

  private function can_use_indexed_editor_fastpath($filters) {
    if (!is_array($filters)) {
      return false;
    }

    $textMode = $this->sanitize_text_match_mode((string)($filters['text_match_mode'] ?? 'contains'));
    if ($textMode === 'regex') return false;
    $hasEditorTextSearch = false;
    foreach (['source_contains', 'title_contains', 'author_contains', 'anchor_contains', 'rel_contains'] as $textKey) {
      if (trim((string)($filters[$textKey] ?? '')) !== '') {
        $hasEditorTextSearch = true;
        break;
      }
    }
    if ($hasEditorTextSearch && !in_array($textMode, ['exact', 'starts_with'], true)) {
      return false;
    }

    if ((string)($filters['group'] ?? '0') !== '0') return false;
    if (trim((string)($filters['alt_contains'] ?? '')) !== '') return false;

    $orderby = (string)($filters['orderby'] ?? 'date');
    $allowedOrderby = ['date','title','post_type','post_author','page_url','link','source','link_location','anchor_text','link_type'];
    if (!in_array($orderby, $allowedOrderby, true)) {
      return false;
    }

    return true;
  }

  private function encode_editor_keyset_cursor($orderValue, $postId, $rowId) {
    $payload = [
      'order' => (string)$orderValue,
      'post_id' => (int)$postId,
      'row_id' => (int)$rowId,
    ];
    return base64_encode(wp_json_encode($payload));
  }

  private function decode_editor_keyset_cursor($cursor) {
    $cursor = trim((string)$cursor);
    if ($cursor === '') {
      return null;
    }
    $decoded = base64_decode($cursor, true);
    if (!is_string($decoded) || $decoded === '') {
      return null;
    }
    $payload = json_decode($decoded, true);
    if (!is_array($payload)) {
      return null;
    }
    if (!isset($payload['order']) || !isset($payload['post_id']) || !isset($payload['row_id'])) {
      return null;
    }

    return [
      'order' => (string)$payload['order'],
      'post_id' => (int)$payload['post_id'],
      'row_id' => (int)$payload['row_id'],
    ];
  }

  private function encode_pages_link_keyset_cursor($orderValue, $postId) {
    $payload = [
      'order' => (string)$orderValue,
      'post_id' => (int)$postId,
    ];
    return base64_encode(wp_json_encode($payload));
  }

  private function decode_pages_link_keyset_cursor($cursor) {
    $cursor = trim((string)$cursor);
    if ($cursor === '') {
      return null;
    }
    $decoded = base64_decode($cursor, true);
    if (!is_string($decoded) || $decoded === '') {
      return null;
    }
    $payload = json_decode($decoded, true);
    if (!is_array($payload)) {
      return null;
    }
    if (!isset($payload['order']) || !isset($payload['post_id'])) {
      return null;
    }

    return [
      'order' => (string)$payload['order'],
      'post_id' => (int)$payload['post_id'],
    ];
  }

  private function get_editor_sort_meta_for_cursor($row, $orderby) {
    $numeric = false;
    switch ((string)$orderby) {
      case 'title':
        $value = (string)($row['post_title'] ?? '');
        break;
      case 'post_type':
        $value = (string)($row['post_type'] ?? '');
        break;
      case 'post_author':
        $value = (string)($row['post_author'] ?? '');
        break;
      case 'page_url':
        $value = (string)($row['page_url'] ?? '');
        break;
      case 'link':
        $value = (string)($row['link'] ?? '');
        break;
      case 'source':
        $value = (string)($row['source'] ?? '');
        break;
      case 'link_location':
        $value = (string)($row['link_location'] ?? '');
        break;
      case 'anchor_text':
        $value = (string)($row['anchor_text'] ?? '');
        break;
      case 'quality':
        $value = $this->get_anchor_quality_label((string)($row['anchor_text'] ?? ''));
        break;
      case 'link_type':
        $value = (string)($row['link_type'] ?? '');
        break;
      case 'seo_flags':
        $flags = [];
        if ((string)($row['rel_nofollow'] ?? '0') === '1') $flags[] = 'nofollow';
        if ((string)($row['rel_sponsored'] ?? '0') === '1') $flags[] = 'sponsored';
        if ((string)($row['rel_ugc'] ?? '0') === '1') $flags[] = 'ugc';
        $value = !empty($flags) ? implode(', ', $flags) : 'dofollow';
        break;
      case 'alt_text':
        $value = (string)($row['alt_text'] ?? '');
        break;
      case 'count':
        $value = (int)($row['count'] ?? 0);
        $numeric = true;
        break;
      case 'date':
      default:
        $value = (string)($row['post_date'] ?? '');
        break;
    }

    return ['value' => $value, 'numeric' => $numeric];
  }

  private function get_pages_link_cursor_sort_meta($row, $orderby) {
    $inboundStatusRank = ['orphan' => 0, 'low' => 1, 'standard' => 2, 'excellent' => 3];
    $outboundStatusRank = ['none' => 0, 'low' => 1, 'optimal' => 2, 'excessive' => 3];

    switch ((string)$orderby) {
      case 'post_id':
        return ['value' => (int)($row['post_id'] ?? 0), 'numeric' => true];
      case 'title':
        return ['value' => (string)($row['post_title'] ?? ''), 'numeric' => false];
      case 'post_type':
        return ['value' => (string)($row['post_type'] ?? ''), 'numeric' => false];
      case 'author':
        return ['value' => (string)($row['author_name'] ?? ''), 'numeric' => false];
      case 'modified':
        return ['value' => (string)($row['post_modified'] ?? ''), 'numeric' => false];
      case 'page_url':
        return ['value' => (string)($row['page_url'] ?? ''), 'numeric' => false];
      case 'inbound':
        return ['value' => (int)($row['inbound'] ?? 0), 'numeric' => true];
      case 'internal_outbound':
        return ['value' => (int)($row['internal_outbound'] ?? 0), 'numeric' => true];
      case 'outbound':
        return ['value' => (int)($row['outbound'] ?? 0), 'numeric' => true];
      case 'status':
        $status = (string)($row['status'] ?? '');
        return ['value' => isset($inboundStatusRank[$status]) ? $inboundStatusRank[$status] : 999, 'numeric' => true];
      case 'internal_outbound_status':
        $status = (string)($row['internal_outbound_status'] ?? '');
        return ['value' => isset($outboundStatusRank[$status]) ? $outboundStatusRank[$status] : 999, 'numeric' => true];
      case 'external_outbound_status':
        $status = (string)($row['external_outbound_status'] ?? '');
        return ['value' => isset($outboundStatusRank[$status]) ? $outboundStatusRank[$status] : 999, 'numeric' => true];
      case 'date':
      default:
        return ['value' => (string)($row['post_date'] ?? ''), 'numeric' => false];
    }
  }

  private function get_indexed_rel_text_sql_expression() {
    return "CASE
      WHEN rel_nofollow = 0 AND rel_sponsored = 0 AND rel_ugc = 0 THEN 'dofollow'
      ELSE TRIM(TRAILING ', ' FROM CONCAT(
        IF(rel_nofollow = 1, 'nofollow, ', ''),
        IF(rel_sponsored = 1, 'sponsored, ', ''),
        IF(rel_ugc = 1, 'ugc, ', '')
      ))
    END";
  }

  private function append_indexed_quality_clause(&$whereParts, &$params, $quality) {
    $quality = sanitize_key((string)$quality);
    if ($quality === '' || $quality === 'any') {
      return;
    }

    $anchorExpr = "LOWER(TRIM(COALESCE(anchor_text, '')))";
    $isEmptyExpr = "$anchorExpr = ''";
    $isShortExpr = "CHAR_LENGTH(TRIM(COALESCE(anchor_text, ''))) < 3";
    $isLongExpr = "CHAR_LENGTH(TRIM(COALESCE(anchor_text, ''))) > 100";

    if ($quality === 'bad') {
      $whereParts[] = $isEmptyExpr;
      return;
    }

    $weakPatternClauses = [];
    $weakPatternParams = [];
    $weakPatterns = $this->get_weak_anchor_patterns();
    foreach ((array)$weakPatterns as $pattern) {
      $pattern = strtolower(trim((string)$pattern));
      if ($pattern === '') {
        continue;
      }
      $weakPatternClauses[] = "$anchorExpr = %s";
      $weakPatternParams[] = $pattern;
      $weakPatternClauses[] = "$anchorExpr LIKE %s";
      $weakPatternParams[] = $pattern . '%';
    }

    $poorDetailClauses = [$isShortExpr, $isLongExpr];
    if (!empty($weakPatternClauses)) {
      $poorDetailClauses[] = '(' . implode(' OR ', $weakPatternClauses) . ')';
    }
    $poorDetailExpr = '(' . implode(' OR ', $poorDetailClauses) . ')';

    if ($quality === 'poor') {
      $whereParts[] = "(NOT ($isEmptyExpr) AND $poorDetailExpr)";
      foreach ($weakPatternParams as $p) {
        $params[] = $p;
      }
      return;
    }

    if ($quality === 'good') {
      $whereParts[] = "(NOT ($isEmptyExpr) AND NOT $poorDetailExpr)";
      foreach ($weakPatternParams as $p) {
        $params[] = $p;
      }
      return;
    }
  }

  private function query_indexed_editor_fastpath_once($scopePostType, $wpmlLang, $filters) {
    global $wpdb;
    $table = $wpdb->prefix . 'lm_link_fact';

    $whereParts = ['wpml_lang = %s'];
    $params = [$wpmlLang];
    if ($scopePostType !== 'any') {
      $whereParts[] = 'post_type = %s';
      $params[] = $scopePostType;
    }

    $location = (string)($filters['location'] ?? 'any');
    if ($location !== 'any' && $location !== '') {
      $whereParts[] = 'link_location = %s';
      $params[] = $location;
    }

    $sourceType = (string)($filters['source_type'] ?? 'any');
    if ($sourceType !== 'any' && $sourceType !== '') {
      $whereParts[] = 'source = %s';
      $params[] = $sourceType;
    }

    $linkType = (string)($filters['link_type'] ?? 'any');
    if ($linkType !== 'any' && $linkType !== '') {
      $whereParts[] = 'link_type = %s';
      $params[] = $linkType;
    }

    $valueType = (string)($filters['value_type'] ?? 'any');
    if ($valueType !== 'any' && $valueType !== '') {
      $whereParts[] = 'value_type = %s';
      $params[] = $valueType;
    }

    foreach (['rel_nofollow', 'rel_sponsored', 'rel_ugc'] as $relKey) {
      $relVal = (string)($filters[$relKey] ?? 'any');
      if ($relVal === '0' || $relVal === '1') {
        $whereParts[] = $relKey . ' = %d';
        $params[] = (int)$relVal;
      }
    }

    $seoFlag = (string)($filters['seo_flag'] ?? 'any');
    if ($seoFlag === 'dofollow') {
      $whereParts[] = 'rel_nofollow = 0 AND rel_sponsored = 0 AND rel_ugc = 0';
    } elseif ($seoFlag === 'nofollow') {
      $whereParts[] = 'rel_nofollow = 1';
    } elseif ($seoFlag === 'sponsored') {
      $whereParts[] = 'rel_sponsored = 1';
    } elseif ($seoFlag === 'ugc') {
      $whereParts[] = 'rel_ugc = 1';
    }

    $textMode = $this->sanitize_text_match_mode((string)($filters['text_match_mode'] ?? 'contains'));
    $this->append_indexed_text_match_clause($whereParts, $params, 'link', (string)($filters['value_contains'] ?? ''), $textMode);
    $this->append_indexed_text_match_clause($whereParts, $params, 'page_url', (string)($filters['source_contains'] ?? ''), $textMode);
    $this->append_indexed_text_match_clause($whereParts, $params, 'post_title', (string)($filters['title_contains'] ?? ''), $textMode);
    $this->append_indexed_text_match_clause($whereParts, $params, 'post_author', (string)($filters['author_contains'] ?? ''), $textMode);
    $this->append_indexed_text_match_clause($whereParts, $params, 'anchor_text', (string)($filters['anchor_contains'] ?? ''), $textMode);
    $this->append_indexed_text_match_clause($whereParts, $params, $this->get_indexed_rel_text_sql_expression(), (string)($filters['rel_contains'] ?? ''), $textMode);

    $this->append_indexed_quality_clause($whereParts, $params, (string)($filters['quality'] ?? 'any'));

    $publishDateFrom = trim((string)($filters['publish_date_from'] ?? ''));
    $publishDateTo = trim((string)($filters['publish_date_to'] ?? ''));
    $updatedDateFrom = trim((string)($filters['updated_date_from'] ?? ''));
    $updatedDateTo = trim((string)($filters['updated_date_to'] ?? ''));
    if ($publishDateFrom !== '') {
      $whereParts[] = 'post_date >= %s';
      $params[] = $publishDateFrom . ' 00:00:00';
    }
    if ($publishDateTo !== '') {
      $whereParts[] = 'post_date <= %s';
      $params[] = $publishDateTo . ' 23:59:59';
    }
    if ($updatedDateFrom !== '') {
      $whereParts[] = 'post_modified >= %s';
      $params[] = $updatedDateFrom . ' 00:00:00';
    }
    if ($updatedDateTo !== '') {
      $whereParts[] = 'post_modified <= %s';
      $params[] = $updatedDateTo . ' 23:59:59';
    }

    $postCategoryFilter = isset($filters['post_category']) ? (int)$filters['post_category'] : 0;
    $postTagFilter = isset($filters['post_tag']) ? (int)$filters['post_tag'] : 0;
    $allowedPostIds = $this->get_post_ids_by_post_terms($postCategoryFilter, $postTagFilter);
    if (is_array($allowedPostIds)) {
      $allowedIds = array_values(array_map('intval', array_keys($allowedPostIds)));
      if (empty($allowedIds)) {
        return ['items' => [], 'pagination' => ['total' => 0, 'per_page' => 10, 'paged' => 1, 'total_pages' => 1]];
      }
      $inPlaceholders = implode(',', array_fill(0, count($allowedIds), '%d'));
      $whereParts[] = "post_id IN ($inPlaceholders)";
      foreach ($allowedIds as $pid) {
        $params[] = (int)$pid;
      }
    }

    $whereSql = 'WHERE ' . implode(' AND ', $whereParts);

    $orderMap = [
      'date' => 'post_date',
      'title' => 'post_title',
      'post_type' => 'post_type',
      'post_author' => 'post_author',
      'page_url' => 'page_url',
      'link' => 'link',
      'source' => 'source',
      'link_location' => 'link_location',
      'anchor_text' => 'anchor_text',
      'link_type' => 'link_type',
    ];
    $orderby = (string)($filters['orderby'] ?? 'date');
    $orderColumn = isset($orderMap[$orderby]) ? $orderMap[$orderby] : 'post_date';
    $orderDir = (strtoupper((string)($filters['order'] ?? 'DESC')) === 'ASC') ? 'ASC' : 'DESC';
    $cursor = $this->decode_editor_keyset_cursor((string)($filters['cursor'] ?? ''));
    $dataWhereParts = $whereParts;
    $dataParams = $params;
    if (is_array($cursor)) {
      if ($orderDir === 'ASC') {
        $dataWhereParts[] = "($orderColumn > %s OR ($orderColumn = %s AND (post_id > %d OR (post_id = %d AND row_id > %d))))";
      } else {
        $dataWhereParts[] = "($orderColumn < %s OR ($orderColumn = %s AND (post_id < %d OR (post_id = %d AND row_id < %d))))";
      }
      $dataParams[] = (string)$cursor['order'];
      $dataParams[] = (string)$cursor['order'];
      $dataParams[] = (int)$cursor['post_id'];
      $dataParams[] = (int)$cursor['post_id'];
      $dataParams[] = (int)$cursor['row_id'];
    }
    $dataWhereSql = 'WHERE ' . implode(' AND ', $dataWhereParts);

    $perPage = max(10, (int)($filters['per_page'] ?? 25));
    $paged = max(1, (int)($filters['paged'] ?? 1));
    $offset = ($paged - 1) * $perPage;

    $countSql = "SELECT COUNT(*) FROM $table $whereSql";
    $total = (int)$wpdb->get_var($wpdb->prepare($countSql, $params));
    if ($total < 0) {
      $total = 0;
    }

    $totalPages = max(1, (int)ceil($total / max(1, $perPage)));
    if (!is_array($cursor) && $paged > $totalPages) {
      $paged = $totalPages;
      $offset = ($paged - 1) * $perPage;
    }

    $limitSize = is_array($cursor) ? ($perPage + 1) : $perPage;
    $rows = [];
    if (is_array($cursor)) {
      $dataSql = "SELECT
        row_id, post_id, post_title, post_type, post_author, post_date, post_modified,
        page_url, source, link_location, block_index, occurrence, link_type, link, anchor_text,
        alt_text, snippet, rel_raw, relationship, rel_nofollow, rel_sponsored, rel_ugc, value_type
        FROM $table
        $dataWhereSql
        ORDER BY $orderColumn $orderDir, post_id $orderDir, row_id $orderDir
        LIMIT %d";

      $dataParams[] = (int)$limitSize;
      $rows = $wpdb->get_results($wpdb->prepare($dataSql, $dataParams), ARRAY_A);
    } else {
      $idSql = "SELECT row_id
        FROM $table
        $dataWhereSql
        ORDER BY $orderColumn $orderDir, post_id $orderDir, row_id $orderDir
        LIMIT %d OFFSET %d";
      $idParams = $dataParams;
      $idParams[] = (int)$limitSize;
      $idParams[] = (int)$offset;
      $rowIds = $wpdb->get_col($wpdb->prepare($idSql, $idParams));

      if (!empty($rowIds)) {
        $rowIds = array_values(array_filter(array_map('strval', $rowIds), static function($rowId) {
          return $rowId !== '';
        }));
        if (!empty($rowIds)) {
          $inPlaceholders = implode(',', array_fill(0, count($rowIds), '%s'));
          $detailSql = "SELECT
            row_id, post_id, post_title, post_type, post_author, post_date, post_modified,
            page_url, source, link_location, block_index, occurrence, link_type, link, anchor_text,
            alt_text, snippet, rel_raw, relationship, rel_nofollow, rel_sponsored, rel_ugc, value_type
            FROM $table
            WHERE wpml_lang = %s
              AND row_id IN ($inPlaceholders)";
          $detailParams = array_merge([(string)$wpmlLang], $rowIds);
          $detailRows = $wpdb->get_results($wpdb->prepare($detailSql, $detailParams), ARRAY_A);
          $detailMap = [];
          foreach ((array)$detailRows as $detailRow) {
            $detailRowId = (string)($detailRow['row_id'] ?? '');
            if ($detailRowId === '') {
              continue;
            }
            $detailMap[$detailRowId] = $detailRow;
          }

          foreach ($rowIds as $rowId) {
            if (isset($detailMap[$rowId])) {
              $rows[] = $detailMap[$rowId];
            }
          }
        }
      }
    }

    $hasMore = false;
    if (is_array($cursor) && count((array)$rows) > $perPage) {
      $hasMore = true;
      $rows = array_slice((array)$rows, 0, $perPage);
    }

    $items = [];
    foreach ((array)$rows as $row) {
      $items[] = [
        'row_id' => (string)($row['row_id'] ?? ''),
        'post_id' => (string)((int)($row['post_id'] ?? 0)),
        'post_title' => (string)($row['post_title'] ?? ''),
        'post_type' => (string)($row['post_type'] ?? ''),
        'post_author' => (string)($row['post_author'] ?? ''),
        'post_date' => (string)($row['post_date'] ?? ''),
        'post_modified' => (string)($row['post_modified'] ?? ''),
        'page_url' => (string)($row['page_url'] ?? ''),
        'source' => (string)($row['source'] ?? ''),
        'link_location' => (string)($row['link_location'] ?? ''),
        'block_index' => (string)($row['block_index'] ?? ''),
        'occurrence' => (string)($row['occurrence'] ?? ''),
        'link_type' => (string)($row['link_type'] ?? ''),
        'link' => (string)($row['link'] ?? ''),
        'anchor_text' => (string)($row['anchor_text'] ?? ''),
        'alt_text' => (string)($row['alt_text'] ?? ''),
        'snippet' => (string)($row['snippet'] ?? ''),
        'relationship' => (string)($row['relationship'] ?? ''),
        'rel_raw' => (string)($row['rel_raw'] ?? ''),
        'rel_nofollow' => !empty($row['rel_nofollow']) ? '1' : '0',
        'rel_sponsored' => !empty($row['rel_sponsored']) ? '1' : '0',
        'rel_ugc' => !empty($row['rel_ugc']) ? '1' : '0',
        'value_type' => (string)($row['value_type'] ?? ''),
      ];
    }

    $nextCursor = '';
    if (!empty($items) && (!is_array($cursor) || $hasMore)) {
      $last = $rows[count($rows) - 1];
      $nextCursor = $this->encode_editor_keyset_cursor(
        (string)($last[$orderColumn] ?? ''),
        (int)($last['post_id'] ?? 0),
        (int)($last['row_id'] ?? 0)
      );
    }

    return [
      'items' => $items,
      'pagination' => [
        'total' => $total,
        'per_page' => $perPage,
        'paged' => $paged,
        'total_pages' => $totalPages,
        'next_cursor' => $nextCursor,
      ],
    ];
  }

  private function get_indexed_editor_list_fastpath_response($scopePostType, $scopeWpmlLang, $filters) {
    if (!$this->is_indexed_datastore_ready()) {
      return null;
    }
    if (!$this->can_use_indexed_editor_fastpath($filters)) {
      return null;
    }

    $scopePostType = sanitize_key((string)$scopePostType);
    if ($scopePostType === '') {
      $scopePostType = 'any';
    }
    $scopeWpmlLang = $this->get_effective_scan_wpml_lang((string)$scopeWpmlLang);

    $response = $this->query_indexed_editor_fastpath_once($scopePostType, $scopeWpmlLang, $filters);
    if (($response['pagination']['total'] ?? 0) > 0) {
      return $response;
    }

    if ($scopePostType !== 'any' || $scopeWpmlLang !== 'all') {
      return $this->query_indexed_editor_fastpath_once('any', 'all', $filters);
    }

    return $response;
  }

  private function get_indexed_editor_location_options($scopePostType, $wpmlLang) {
    if (!$this->is_indexed_datastore_ready()) {
      return null;
    }

    global $wpdb;
    $table = $wpdb->prefix . 'lm_link_fact';

    $scopePostType = sanitize_key((string)$scopePostType);
    if ($scopePostType === '') {
      $scopePostType = 'any';
    }
    $wpmlLang = $this->get_effective_scan_wpml_lang((string)$wpmlLang);

    $resolvedScope = $this->resolve_indexed_datastore_scope($scopePostType, $wpmlLang);
    if (is_array($resolvedScope)) {
      $scopePostType = (string)$resolvedScope['scope_post_type'];
      $wpmlLang = (string)$resolvedScope['wpml_lang'];
    }

    $whereParts = ['wpml_lang = %s'];
    $params = [$wpmlLang];
    if ($scopePostType !== 'any') {
      $whereParts[] = 'post_type = %s';
      $params[] = $scopePostType;
    }

    $sql = "SELECT DISTINCT link_location
      FROM $table
      WHERE " . implode(' AND ', $whereParts) . "
      ORDER BY link_location ASC
      LIMIT 500";
    $rows = $wpdb->get_col($wpdb->prepare($sql, $params));

    $locations = ['any' => 'All'];
    foreach ((array)$rows as $location) {
      $location = (string)$location;
      if ($location === '') {
        continue;
      }
      $locations[$location] = $location;
    }

    return $locations;
  }

  private function get_existing_cache_rows_for_rest($scopePostType, $wpmlLang, $allowAnyAllFallback = true) {
    $scopePostType = sanitize_key((string)$scopePostType);
    if ($scopePostType === '') {
      $scopePostType = 'any';
    }
    $wpmlLang = $this->get_effective_scan_wpml_lang((string)$wpmlLang);

    if ($this->is_indexed_datastore_ready()) {
      $indexedRows = $this->get_indexed_fact_rows($scopePostType, $wpmlLang);
      if (!empty($indexedRows)) {
        return $indexedRows;
      }
      if ($allowAnyAllFallback && ($scopePostType !== 'any' || $wpmlLang !== 'all')) {
        $indexedRowsAnyAll = $this->get_indexed_fact_rows('any', 'all');
        if (is_array($indexedRowsAnyAll) && !empty($indexedRowsAnyAll)) {
          return $indexedRowsAnyAll;
        }
      }
    }

    $main = get_transient($this->cache_key($scopePostType, $wpmlLang));
    if (is_array($main) && !empty($main)) {
      return $main;
    }

    $backup = get_transient($this->cache_backup_key($scopePostType, $wpmlLang));
    if (is_array($backup) && !empty($backup)) {
      return $backup;
    }

    if ($allowAnyAllFallback && ($scopePostType !== 'any' || $wpmlLang !== 'all')) {
      $mainAnyAll = get_transient($this->cache_key('any', 'all'));
      if (is_array($mainAnyAll) && !empty($mainAnyAll)) {
        return $mainAnyAll;
      }

      $backupAnyAll = get_transient($this->cache_backup_key('any', 'all'));
      if (is_array($backupAnyAll) && !empty($backupAnyAll)) {
        return $backupAnyAll;
      }
    }

    return null;
  }

  private function get_canonical_rows_for_scope($scopePostType = 'any', $forceRebuild = false, $wpmlLang = 'all', $filters = null) {
    $scopePostType = sanitize_key((string)$scopePostType);
    if ($scopePostType === '') {
      $scopePostType = 'any';
    }
    $wpmlLang = $this->get_effective_scan_wpml_lang((string)$wpmlLang);

    if (!$forceRebuild && $this->is_indexed_datastore_ready() && $this->indexed_dataset_has_rows($scopePostType, $wpmlLang)) {
      $indexedRows = $this->get_indexed_fact_rows($scopePostType, $wpmlLang, is_array($filters) ? $filters : null);
      if (!empty($indexedRows)) {
        return $indexedRows;
      }
      if ($scopePostType !== 'any' || $wpmlLang !== 'all') {
        $indexedRowsAnyAll = $this->get_indexed_fact_rows('any', 'all', is_array($filters) ? $filters : null);
        if (is_array($indexedRowsAnyAll) && !empty($indexedRowsAnyAll)) {
          return $indexedRowsAnyAll;
        }
      }
    }

    return $this->build_or_get_cache($scopePostType, $forceRebuild, $wpmlLang);
  }

  private function indexed_dataset_has_rows($scopePostType = 'any', $wpmlLang = 'all') {
    if (!$this->is_indexed_datastore_ready()) {
      return false;
    }

    $scopePostType = sanitize_key((string)$scopePostType);
    if ($scopePostType === '') {
      $scopePostType = 'any';
    }
    $wpmlLang = $this->get_effective_scan_wpml_lang((string)$wpmlLang);

    $this->maybe_self_heal_indexed_datastore($wpmlLang);

    if ($this->get_indexed_fact_count($scopePostType, $wpmlLang) > 0) {
      return true;
    }

    if ($scopePostType !== 'any' || $wpmlLang !== 'all') {
      if ($this->get_indexed_fact_count('any', 'all') > 0) {
        return true;
      }
    }

    return false;
  }
}
