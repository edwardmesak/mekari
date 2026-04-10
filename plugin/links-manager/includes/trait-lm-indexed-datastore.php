<?php
/**
 * Indexed datastore access helpers for fact and summary tables.
 */

trait LM_Indexed_Datastore_Trait {
  private function indexed_fact_has_link_domain_column() {
    static $hasColumn = null;
    if ($hasColumn !== null) {
      return (bool)$hasColumn;
    }

    global $wpdb;
    $table = $wpdb->prefix . 'lm_link_fact';
    $column = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM $table LIKE %s", 'link_domain'));
    $hasColumn = !empty($column);
    return (bool)$hasColumn;
  }

  private function indexed_fact_has_normalized_url_columns() {
    static $hasColumns = null;
    if ($hasColumns !== null) {
      return (bool)$hasColumns;
    }

    global $wpdb;
    $table = $wpdb->prefix . 'lm_link_fact';
    $normalizedPageColumn = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM $table LIKE %s", 'normalized_page_url'));
    $normalizedLinkColumn = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM $table LIKE %s", 'normalized_link'));
    $hasColumns = (!empty($normalizedPageColumn) && !empty($normalizedLinkColumn));
    return (bool)$hasColumns;
  }

  private function get_indexed_normalized_url_health($wpmlLang = 'all') {
    return $this->get_indexed_normalized_url_health_for_scope('any', $wpmlLang);
  }

  private function get_indexed_normalized_url_health_for_scope($scopePostType = 'any', $wpmlLang = 'all') {
    $health = [
      'schema_ready' => false,
      'scope_post_type' => 'any',
      'wpml_lang' => 'all',
      'rows_total' => 0,
      'rows_actionable_total' => 0,
      'rows_missing_normalized' => 0,
      'rows_empty_source' => 0,
    ];

    if (!$this->is_indexed_datastore_ready() || !$this->indexed_fact_has_normalized_url_columns()) {
      return $health;
    }

    global $wpdb;
    $table = $wpdb->prefix . 'lm_link_fact';
    $scopePostType = sanitize_key((string)$scopePostType);
    if ($scopePostType === '') {
      $scopePostType = 'any';
    }
    $wpmlLang = sanitize_key((string)$wpmlLang);
    if ($wpmlLang === '') {
      $wpmlLang = 'all';
    }

    $health['scope_post_type'] = $scopePostType;
    $health['wpml_lang'] = $wpmlLang;

    $whereParts = [];
    $params = [];
    if ($wpmlLang !== 'all') {
      $whereParts[] = 'wpml_lang = %s';
      $params[] = $wpmlLang;
    }
    if ($scopePostType !== 'any') {
      $whereParts[] = 'post_type = %s';
      $params[] = $scopePostType;
    }

    $whereSql = '';
    if (!empty($whereParts)) {
      $whereSql = ' WHERE ' . implode(' AND ', $whereParts);
    }

    $totalSql = "SELECT COUNT(*) FROM $table" . $whereSql;
    if (!empty($params)) {
      $totalSql = $wpdb->prepare($totalSql, $params);
    }
    $health['rows_total'] = max(0, (int)$wpdb->get_var($totalSql));

    $actionableSql = "SELECT COUNT(*) FROM $table" . $whereSql;
    $actionableSql .= ($whereSql === '' ? ' WHERE ' : ' AND ');
    $actionableSql .= "(page_url <> '' OR link <> '')";
    if (!empty($params)) {
      $actionableSql = $wpdb->prepare($actionableSql, $params);
    }
    $health['rows_actionable_total'] = max(0, (int)$wpdb->get_var($actionableSql));

    $missingSql = "SELECT COUNT(*) FROM $table" . $whereSql;
    $missingSql .= ($whereSql === '' ? ' WHERE ' : ' AND ');
    $missingSql .= "(
      (normalized_page_url = '' AND page_url <> '')
      OR (normalized_link = '' AND link <> '')
    )";
    if (!empty($params)) {
      $missingSql = $wpdb->prepare($missingSql, $params);
    }
    $health['rows_missing_normalized'] = max(0, (int)$wpdb->get_var($missingSql));

    $health['rows_empty_source'] = max(0, $health['rows_total'] - $health['rows_actionable_total']);
    $health['schema_ready'] = true;

    return $health;
  }

  private function get_indexed_link_domain_sql_expression() {
    $fallbackExpr = "LOWER(SUBSTRING_INDEX(SUBSTRING_INDEX(SUBSTRING_INDEX(REPLACE(REPLACE(link, 'https://', ''), 'http://', ''), '/', 1), '?', 1), ':', 1))";
    if ($this->indexed_fact_has_link_domain_column()) {
      return "COALESCE(NULLIF(link_domain, ''), $fallbackExpr)";
    }
    return $fallbackExpr;
  }

  private function get_indexed_datastore_tables() {
    global $wpdb;
    return [
      'fact' => $wpdb->prefix . 'lm_link_fact',
      'summary' => $wpdb->prefix . 'lm_link_post_summary',
      'domain_summary' => $wpdb->prefix . 'lm_link_domain_summary',
      'anchor_summary' => $wpdb->prefix . 'lm_anchor_text_summary',
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

  private function get_indexed_report_summary_count($type, $wpmlLang = 'all') {
    global $wpdb;

    $tables = $this->get_indexed_datastore_tables();
    $type = sanitize_key((string)$type);
    $table = '';
    if ($type === 'domain') {
      $table = (string)($tables['domain_summary'] ?? '');
    } elseif ($type === 'anchor') {
      $table = (string)($tables['anchor_summary'] ?? '');
    }
    if ($table === '') {
      return 0;
    }

    $exists = (string)$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
    if ($exists !== $table) {
      return 0;
    }

    $wpmlLang = $this->get_requested_view_wpml_lang((string)$wpmlLang);
    $sql = "SELECT COUNT(*) FROM $table WHERE wpml_lang = %s";
    return max(0, (int)$wpdb->get_var($wpdb->prepare($sql, [$wpmlLang])));
  }

  private function maybe_self_heal_indexed_datastore($wpmlLang = 'all') {
    if (!$this->is_indexed_datastore_ready()) {
      return false;
    }

    $wpmlLang = $this->get_requested_view_wpml_lang((string)$wpmlLang);
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
      if ($lang !== 'all') {
        continue;
      }
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

  private function resolve_indexed_datastore_scope($scopePostType = 'any', $wpmlLang = 'all', $allowAnyAllFallback = true) {
    if (!$this->is_indexed_datastore_ready()) {
      return null;
    }

    $scopePostType = sanitize_key((string)$scopePostType);
    if ($scopePostType === '') {
      $scopePostType = 'any';
    }
    $wpmlLang = $this->get_requested_view_wpml_lang((string)$wpmlLang);

    $this->maybe_self_heal_indexed_datastore($wpmlLang);

    if ($this->get_indexed_fact_count($scopePostType, $wpmlLang) > 0) {
      return [
        'scope_post_type' => $scopePostType,
        'wpml_lang' => $wpmlLang,
      ];
    }

    if ($allowAnyAllFallback && ($scopePostType !== 'any' || $wpmlLang !== 'all') && $this->get_indexed_fact_count('any', 'all') > 0) {
      return [
        'scope_post_type' => 'any',
        'wpml_lang' => 'all',
      ];
    }

    return null;
  }

  private function has_exact_language_scope($wpmlLang = 'all') {
    return $this->get_requested_view_wpml_lang((string)$wpmlLang) !== 'all';
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

    if ($mode === 'doesnt_contain') {
      $whereParts[] = "LOWER(COALESCE($column, '')) NOT LIKE %s";
      $params[] = '%' . strtolower($wpdb->esc_like($needle)) . '%';
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
    $wpmlLang = $this->get_requested_view_wpml_lang((string)$wpmlLang);

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
      $selectedAuthorId = isset($filters['author']) ? (int)$filters['author'] : 0;
      $selectedAuthorName = $this->get_author_filter_display_name($selectedAuthorId);

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
      $this->append_indexed_text_match_clause($whereParts, $params, 'anchor_text', isset($filters['anchor_contains']) ? $filters['anchor_contains'] : '', $textMode);
      $this->append_indexed_text_match_clause($whereParts, $params, 'alt_text', isset($filters['alt_contains']) ? $filters['alt_contains'] : '', $textMode);
      $this->append_indexed_text_match_clause($whereParts, $params, $this->get_indexed_rel_text_sql_expression(), isset($filters['rel_contains']) ? $filters['rel_contains'] : '', $textMode);
      $this->append_indexed_author_filter_clause($whereParts, $params, $selectedAuthorId, $selectedAuthorName);
      $this->append_indexed_quality_clause($whereParts, $params, isset($filters['quality']) ? (string)$filters['quality'] : 'any');

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

      $postCategoryFilter = isset($filters['post_category']) ? (int)$filters['post_category'] : 0;
      $postTagFilter = isset($filters['post_tag']) ? (int)$filters['post_tag'] : 0;
      $allowedPostIds = $this->get_post_ids_by_post_terms($postCategoryFilter, $postTagFilter);
      if (is_array($allowedPostIds)) {
        $allowedIds = array_values(array_map('intval', array_keys($allowedPostIds)));
        if (empty($allowedIds)) {
          return [];
        }
        $inPlaceholders = implode(',', array_fill(0, count($allowedIds), '%d'));
        $whereParts[] = "post_id IN ($inPlaceholders)";
        foreach ($allowedIds as $postId) {
          $params[] = $postId;
        }
      }
    }

    $where = 'WHERE ' . implode(' AND ', $whereParts);

    $sql = "SELECT
      row_id, post_id, post_title, post_type, post_author, post_author_id, post_date, post_modified,
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
        'post_author_id' => (string)((int)($row['post_author_id'] ?? 0)),
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
    $wpmlLang = $this->get_requested_view_wpml_lang((string)$wpmlLang);

    $whereParts = ['wpml_lang = %s'];
    $params = [$wpmlLang];
    if ($scopePostType !== 'any') {
      $whereParts[] = 'post_type = %s';
      $params[] = $scopePostType;
    }

    $sql = "SELECT post_id, inbound, internal_outbound, outbound FROM $table WHERE " . implode(' AND ', $whereParts);
    $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
    if ((!is_array($rows) || empty($rows)) && $wpmlLang !== 'all' && $this->get_indexed_fact_count_exact_scope($scopePostType, $wpmlLang) > 0) {
      $this->rebuild_indexed_summary_for_lang($wpmlLang);
      $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
    }
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

  private function get_indexed_summary_count_exact_scope($scopePostType = 'any', $wpmlLang = 'all') {
    global $wpdb;

    if (!$this->is_indexed_datastore_ready()) {
      return 0;
    }

    $table = $wpdb->prefix . 'lm_link_post_summary';
    $scopePostType = sanitize_key((string)$scopePostType);
    if ($scopePostType === '') {
      $scopePostType = 'any';
    }
    $wpmlLang = sanitize_key((string)$wpmlLang);
    if ($wpmlLang === '') {
      $wpmlLang = 'all';
    }

    $whereParts = ['wpml_lang = %s'];
    $params = [$wpmlLang];
    if ($scopePostType !== 'any') {
      $whereParts[] = 'post_type = %s';
      $params[] = $scopePostType;
    }

    $sql = "SELECT COUNT(*) FROM $table WHERE " . implode(' AND ', $whereParts);
    return max(0, (int)$wpdb->get_var($wpdb->prepare($sql, $params)));
  }

  private function get_indexed_fact_count($scopePostType = 'any', $wpmlLang = 'all') {
    return $this->get_indexed_fact_count_exact_scope($scopePostType, $wpmlLang);
  }

  private function get_indexed_fact_count_exact_scope($scopePostType = 'any', $wpmlLang = 'all') {
    global $wpdb;

    if (!$this->is_indexed_datastore_ready()) {
      return 0;
    }

    $table = $wpdb->prefix . 'lm_link_fact';
    $scopePostType = sanitize_key((string)$scopePostType);
    if ($scopePostType === '') {
      $scopePostType = 'any';
    }
    $wpmlLang = $this->get_requested_view_wpml_lang((string)$wpmlLang);

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

    if ((string)($filters['group'] ?? '0') !== '0') return false;

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
      'row_id' => (string)$rowId,
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
      'row_id' => (string)$payload['row_id'],
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

    $lengthThresholds = $this->get_anchor_quality_length_thresholds();
    $shortMin = (int)$lengthThresholds['short_min'];
    $longMax = (int)$lengthThresholds['long_max'];

    $anchorExpr = "LOWER(TRIM(COALESCE(anchor_text, '')))";
    $isEmptyExpr = "$anchorExpr = ''";
    $isShortExpr = 'CHAR_LENGTH(TRIM(COALESCE(anchor_text, \'\'))) < ' . $shortMin;
    $isLongExpr = 'CHAR_LENGTH(TRIM(COALESCE(anchor_text, \'\'))) > ' . $longMax;

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
    $selectedAuthorId = isset($filters['author']) ? (int)$filters['author'] : 0;
    $selectedAuthorName = $this->get_author_filter_display_name($selectedAuthorId);
    $this->append_indexed_text_match_clause($whereParts, $params, 'link', (string)($filters['value_contains'] ?? ''), $textMode);
    $this->append_indexed_text_match_clause($whereParts, $params, 'page_url', (string)($filters['source_contains'] ?? ''), $textMode);
    $this->append_indexed_text_match_clause($whereParts, $params, 'post_title', (string)($filters['title_contains'] ?? ''), $textMode);
    $this->append_indexed_text_match_clause($whereParts, $params, 'anchor_text', (string)($filters['anchor_contains'] ?? ''), $textMode);
    $this->append_indexed_text_match_clause($whereParts, $params, 'alt_text', (string)($filters['alt_contains'] ?? ''), $textMode);
    $this->append_indexed_text_match_clause($whereParts, $params, $this->get_indexed_rel_text_sql_expression(), (string)($filters['rel_contains'] ?? ''), $textMode);
    $this->append_indexed_author_filter_clause($whereParts, $params, $selectedAuthorId, $selectedAuthorName);

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
        return ['items' => [], 'pagination' => ['total' => 0, 'per_page' => 25, 'paged' => 1, 'total_pages' => 1]];
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
        $dataWhereParts[] = "($orderColumn > %s OR ($orderColumn = %s AND (post_id > %d OR (post_id = %d AND row_id > %s))))";
      } else {
        $dataWhereParts[] = "($orderColumn < %s OR ($orderColumn = %s AND (post_id < %d OR (post_id = %d AND row_id < %s))))";
      }
      $dataParams[] = (string)$cursor['order'];
      $dataParams[] = (string)$cursor['order'];
      $dataParams[] = (int)$cursor['post_id'];
      $dataParams[] = (int)$cursor['post_id'];
      $dataParams[] = (string)$cursor['row_id'];
    }
    $dataWhereSql = 'WHERE ' . implode(' AND ', $dataWhereParts);

    $perPage = max(10, (int)($filters['per_page'] ?? 25));
    $paged = max(1, (int)($filters['paged'] ?? 1));
    $offset = ($paged - 1) * $perPage;

    $countSql = is_array($cursor)
      ? "SELECT COUNT(*) FROM $table $dataWhereSql"
      : "SELECT COUNT(*) FROM $table $whereSql";
    $countParams = is_array($cursor) ? $dataParams : $params;
    $total = (int)$wpdb->get_var($wpdb->prepare($countSql, $countParams));
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
        row_id, post_id, post_title, post_type, post_author, post_author_id, post_date, post_modified,
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
            row_id, post_id, post_title, post_type, post_author, post_author_id, post_date, post_modified,
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
        'post_author_id' => (string)((int)($row['post_author_id'] ?? 0)),
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
        (string)($last['row_id'] ?? '')
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
    $scopeWpmlLang = $this->get_requested_view_wpml_lang((string)$scopeWpmlLang);

    $response = $this->query_indexed_editor_fastpath_once($scopePostType, $scopeWpmlLang, $filters);
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
    $wpmlLang = $this->get_requested_view_wpml_lang((string)$wpmlLang);

    $resolvedScope = $this->resolve_indexed_datastore_scope($scopePostType, $wpmlLang, false);
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
    $wpmlLang = $this->get_requested_view_wpml_lang((string)$wpmlLang);

    $main = get_transient($this->cache_key($scopePostType, $wpmlLang));
    if (is_array($main)) {
      return $main;
    }

    $backup = get_transient($this->cache_backup_key($scopePostType, $wpmlLang));
    if (is_array($backup)) {
      return $backup;
    }

    if ($allowAnyAllFallback && ($scopePostType !== 'any' || $wpmlLang !== 'all')) {
      $mainAnyAll = get_transient($this->cache_key('any', 'all'));
      if (is_array($mainAnyAll)) {
        return $mainAnyAll;
      }

      $backupAnyAll = get_transient($this->cache_backup_key('any', 'all'));
      if (is_array($backupAnyAll)) {
        return $backupAnyAll;
      }
    }

    return null;
  }

  private function has_refresh_dataset_for_scope($scopePostType = 'any', $wpmlLang = 'all', $allowAnyAllFallback = true) {
    $scopePostType = sanitize_key((string)$scopePostType);
    if ($scopePostType === '') {
      $scopePostType = 'any';
    }
    $wpmlLang = $this->get_requested_view_wpml_lang((string)$wpmlLang);

    if ($this->is_indexed_datastore_ready() && $this->indexed_dataset_has_rows($scopePostType, $wpmlLang)) {
      return true;
    }

    $cacheRows = $this->get_existing_cache_rows_for_rest($scopePostType, $wpmlLang, $allowAnyAllFallback);
    if (is_array($cacheRows)) {
      return true;
    }

    $mainStamp = get_option($this->cache_scan_option_key($scopePostType, $wpmlLang), '');
    if ((string)$mainStamp !== '') {
      return true;
    }

    if ($allowAnyAllFallback && ($scopePostType !== 'any' || $wpmlLang !== 'all')) {
      $fallbackStamp = get_option($this->cache_scan_option_key('any', 'all'), '');
      if ((string)$fallbackStamp !== '') {
        return true;
      }
    }

    return false;
  }

  private function has_nonempty_refresh_dataset_for_scope($scopePostType = 'any', $wpmlLang = 'all', $allowAnyAllFallback = true) {
    $scopePostType = sanitize_key((string)$scopePostType);
    if ($scopePostType === '') {
      $scopePostType = 'any';
    }
    $wpmlLang = $this->get_requested_view_wpml_lang((string)$wpmlLang);

    if ($this->is_indexed_datastore_ready() && $this->get_indexed_fact_count_exact_scope($scopePostType, $wpmlLang) > 0) {
      return true;
    }

    $cacheRows = $this->get_existing_cache_rows_for_rest($scopePostType, $wpmlLang, $allowAnyAllFallback);
    if (is_array($cacheRows) && !empty($cacheRows)) {
      return true;
    }

    return false;
  }

  private function get_report_data_notice($scopePostType = 'any', $wpmlLang = 'all') {
    $state = $this->get_rebuild_job_state();
    $status = sanitize_key((string)($state['status'] ?? ''));
    if (in_array($status, ['running', 'finalizing'], true)) {
      return __('Refresh Data is still running. Report data will appear automatically when the refresh completes.', 'links-manager');
    }

    if (!$this->has_nonempty_refresh_dataset_for_scope('any', 'all', false) && !$this->has_refresh_dataset_for_scope('any', 'all', false)) {
      return __('No refreshed dataset is available yet. Run Refresh Data first to load report data.', 'links-manager');
    }

    if (!$this->has_nonempty_refresh_dataset_for_scope($scopePostType, $wpmlLang, false) && !$this->has_refresh_dataset_for_scope($scopePostType, $wpmlLang, false)) {
      return __('No rows are available yet for the current scope. Run Refresh Data or switch to another language scope.', 'links-manager');
    }

    return '';
  }

  private function get_report_scope_rows_or_empty($scopePostType = 'any', $wpmlLang = 'all', $filters = null, $allowAnyAllFallback = true) {
    if (!$this->has_refresh_dataset_for_scope($scopePostType, $wpmlLang, $allowAnyAllFallback)) {
      return [];
    }

    return $this->get_canonical_rows_for_scope($scopePostType, false, $wpmlLang, $filters, $allowAnyAllFallback);
  }

  private function get_canonical_rows_for_scope($scopePostType = 'any', $forceRebuild = false, $wpmlLang = 'all', $filters = null, $allowAnyAllFallback = true) {
    $scopePostType = sanitize_key((string)$scopePostType);
    if ($scopePostType === '') {
      $scopePostType = 'any';
    }
    $wpmlLang = $this->get_requested_view_wpml_lang((string)$wpmlLang);

    if (!$forceRebuild && $this->is_indexed_datastore_ready() && $this->indexed_dataset_has_rows($scopePostType, $wpmlLang)) {
      $indexedRows = $this->get_indexed_fact_rows($scopePostType, $wpmlLang, is_array($filters) ? $filters : null);
      if (!empty($indexedRows)) {
        return $indexedRows;
      }
      if ($allowAnyAllFallback && !$this->has_exact_language_scope($wpmlLang) && ($scopePostType !== 'any' || $wpmlLang !== 'all')) {
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
    $wpmlLang = $this->get_requested_view_wpml_lang((string)$wpmlLang);

    $this->maybe_self_heal_indexed_datastore($wpmlLang);

    if ($this->get_indexed_fact_count($scopePostType, $wpmlLang) > 0) {
      return true;
    }

    if (!$this->has_exact_language_scope($wpmlLang) && ($scopePostType !== 'any' || $wpmlLang !== 'all')) {
      if ($this->get_indexed_fact_count('any', 'all') > 0) {
        return true;
      }
    }

    return false;
  }
}
