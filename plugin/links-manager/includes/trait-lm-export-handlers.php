<?php
/**
 * CSV export handlers for Links Manager admin pages.
 */

if (!defined('ABSPATH')) {
  exit;
}

trait LM_Export_Handlers_Trait {
  private function get_export_quality_label($quality, $anchorText = '') {
    $qualityKey = sanitize_key((string)$quality);
    if ($qualityKey === '') {
      $qualityKey = $this->get_anchor_quality_label((string)$anchorText);
    }

    if ($qualityKey === 'bad') {
      return 'Bad';
    }
    if ($qualityKey === 'poor') {
      return 'Poor';
    }

    return 'Good';
  }

  private function get_export_usage_type_label($usageType) {
    $usageType = sanitize_key((string)$usageType);
    if ($usageType === 'inlink_only') {
      return 'Inlink Only';
    }
    if ($usageType === 'outbound_only') {
      return 'Outbound Only';
    }

    return 'Mixed';
  }

  private function csv_write_row($out, $row, $delimiter = ',', $enclosure = '"') {
    $escaped = [];
    foreach ((array)$row as $value) {
      $cell = (string)$value;
      // Convert HTML entities (e.g. &amp;) to plain text to keep CSV values stable across spreadsheet parsers.
      $cell = wp_specialchars_decode($cell, ENT_QUOTES);
      // Neutralize potential spreadsheet formula execution when CSV is opened.
      if ($cell !== '' && preg_match('/^[\t\r\n ]*[=+\-@]/', $cell)) {
        $cell = "'" . $cell;
      }
      $cell = str_replace($enclosure, $enclosure . $enclosure, $cell);
      $escaped[] = $enclosure . $cell . $enclosure;
    }
    fwrite($out, implode($delimiter, $escaped) . "\r\n");
  }

  private function detect_csv_delimiter($filePath) {
    $line = '';
    $fh = @fopen($filePath, 'r');
    if ($fh) {
      while (($candidate = fgets($fh)) !== false) {
        if (trim($candidate) !== '') {
          $line = $candidate;
          break;
        }
      }
      fclose($fh);
    }

    if ($line === '') {
      return ',';
    }

    $line = preg_replace('/^\xEF\xBB\xBF/', '', (string)$line);
    $comma = substr_count($line, ',');
    $semicolon = substr_count($line, ';');

    return ($semicolon > $comma) ? ';' : ',';
  }

  private function stream_indexed_editor_export_rows($out, $filters) {
    $filters = is_array($filters) ? $filters : [];
    $scopePostType = sanitize_key((string)($filters['post_type'] ?? 'any'));
    if ($scopePostType === '') {
      $scopePostType = 'any';
    }
    $scopeWpmlLang = $this->get_requested_view_wpml_lang((string)($filters['wpml_lang'] ?? 'all'));
    $cursor = '';
    $perPage = 1000;
    $exportFilters = $filters;
    $exportFilters['per_page'] = $perPage;
    $exportFilters['paged'] = 1;

    while (true) {
      $exportFilters['cursor'] = $cursor;
      $response = $this->get_indexed_editor_list_fastpath_response($scopePostType, $scopeWpmlLang, $exportFilters);
      if (!is_array($response)) {
        return false;
      }

      $items = isset($response['items']) && is_array($response['items']) ? array_values($response['items']) : [];
      foreach ($items as $r) {
        $this->csv_write_row($out, [
          $r['post_id'] ?? '',
          $r['link'] ?? '',
          $r['row_id'] ?? '',
          '',
          '',
          '',
          $r['source'] ?? '',
          $r['link_location'] ?? '',
          $r['block_index'] ?? '',
          $r['occurrence'] ?? '',
          $r['post_title'] ?? '',
          $r['post_type'] ?? '',
          $r['post_author'] ?? '',
          $r['post_date'] ?? '',
          $r['post_modified'] ?? '',
          $r['page_url'] ?? '',
          $r['link'] ?? '',
          $r['link'] ?? '',
          $r['anchor_text'] ?? '',
          $this->get_export_quality_label('', (string)($r['anchor_text'] ?? '')),
          $r['alt_text'] ?? '',
          $r['snippet'] ?? '',
          $r['link_type'] ?? '',
          $r['relationship'] ?? '',
          $r['value_type'] ?? '',
          1,
        ]);
      }

      $pagination = isset($response['pagination']) && is_array($response['pagination']) ? $response['pagination'] : [];
      $cursor = (string)($pagination['next_cursor'] ?? '');
      if ($cursor === '' || empty($items)) {
        break;
      }
    }

    return true;
  }

  private function get_pages_link_export_rows($filters) {
    $filters = is_array($filters) ? $filters : [];
    $exportFilters = $filters;
    $exportFilters['paged'] = 1;
    $exportFilters['per_page'] = max(500, (int)($filters['per_page'] ?? 25));

    if ($this->can_use_pages_link_indexed_fastpath($exportFilters)) {
      $rows = [];
      $page = 1;
      do {
        $exportFilters['paged'] = $page;
        $pagedResult = $this->get_pages_link_paged_result_from_indexed_summary($exportFilters);
        if (!is_array($pagedResult)) {
          break;
        }
        $batch = array_values((array)($pagedResult['pages'] ?? []));
        foreach ($batch as &$batchRow) {
          if ((string)($batchRow['page_url'] ?? '') === '') {
            $batchRow['page_url'] = (string)get_permalink((int)($batchRow['post_id'] ?? 0));
          }
        }
        unset($batchRow);
        $this->append_rows($rows, $batch);
        $totalPages = max(1, (int)($pagedResult['total_pages'] ?? 1));
        $page++;
      } while ($page <= $totalPages);

      return $rows;
    }

    if ($this->can_use_pages_link_indexed_summary_path($exportFilters)) {
      return $this->get_pages_with_inbound_counts_from_indexed_summary($exportFilters);
    }

    $all = $this->get_pages_link_runtime_rows($exportFilters, isset($exportFilters['wpml_lang']) ? $exportFilters['wpml_lang'] : 'all', false);
    return $this->get_pages_with_inbound_counts($all, $exportFilters, true);
  }

  private function get_indexed_cited_domains_export_rows($filters) {
    $filters = is_array($filters) ? $filters : [];
    $exportFilters = $filters;
    $exportFilters['paged'] = 1;
    $exportFilters['per_page'] = max(500, (int)($filters['per_page'] ?? 25));

    $rows = [];
    $page = 1;
    do {
      $exportFilters['paged'] = $page;
      $pagedResult = $this->get_indexed_cited_domains_paged_result($exportFilters);
      if (!is_array($pagedResult)) {
        return [];
      }

      $batch = array_values((array)($pagedResult['items'] ?? []));
      $this->append_rows($rows, $batch);
      $pagination = isset($pagedResult['pagination']) && is_array($pagedResult['pagination']) ? $pagedResult['pagination'] : [];
      $totalPages = max(1, (int)($pagination['total_pages'] ?? 1));
      $page++;
    } while ($page <= $totalPages);

    return $rows;
  }

  private function get_cited_domains_export_rows($filters) {
    $filters = is_array($filters) ? $filters : [];
    $guardState = $this->get_indexed_aggregation_guard_state($filters, 'exlink', 'cited_domains_aggregation', 'Cited Domains');
    if (!empty($guardState['blocked'])) {
      return [];
    }

    $rows = $this->get_indexed_cited_domains_export_rows($filters);
    if (!empty($rows)) {
      return $rows;
    }

    $rows = $this->get_indexed_cited_domains_summary_rows($filters);
    if (!empty($rows)) {
      return $rows;
    }

    $scopeRows = $this->get_report_scope_rows_or_empty('any', isset($filters['wpml_lang']) ? $filters['wpml_lang'] : 'all', $filters, false);
    return $this->build_cited_domains_summary_rows($scopeRows, $filters);
  }

  private function get_indexed_all_anchor_text_export_rows($filters) {
    $filters = is_array($filters) ? $filters : [];
    $exportFilters = $filters;
    $exportFilters['paged'] = 1;
    $exportFilters['per_page'] = max(500, (int)($filters['per_page'] ?? 25));

    $rows = [];
    $page = 1;
    do {
      $exportFilters['paged'] = $page;
      $pagedResult = $this->get_indexed_all_anchor_text_paged_result($exportFilters);
      if (!is_array($pagedResult)) {
        return [];
      }

      $batch = array_values((array)($pagedResult['items'] ?? []));
      $this->append_rows($rows, $batch);
      $pagination = isset($pagedResult['pagination']) && is_array($pagedResult['pagination']) ? $pagedResult['pagination'] : [];
      $totalPages = max(1, (int)($pagination['total_pages'] ?? 1));
      $page++;
    } while ($page <= $totalPages);

    return $rows;
  }

  private function get_all_anchor_text_export_rows($filters) {
    $filters = is_array($filters) ? $filters : [];
    $guardState = $this->get_indexed_aggregation_guard_state($filters, 'any', 'all_anchor_text_aggregation', 'All Anchor Text');
    if (!empty($guardState['blocked'])) {
      $rebuildState = $this->get_rebuild_job_state();
      $rebuildStatus = sanitize_key((string)($rebuildState['status'] ?? 'idle'));
      $canServeStaleRows = !empty($guardState['readiness_blocked'])
        && in_array($rebuildStatus, ['running', 'finalizing'], true);
      if ($canServeStaleRows) {
        return $this->get_stale_indexed_all_anchor_text_summary_rows($filters);
      }
      return [];
    }

    $rows = $this->get_indexed_all_anchor_text_export_rows($filters);
    if (!empty($rows)) {
      return $rows;
    }

    $rows = $this->get_indexed_all_anchor_text_summary_rows($filters);
    if (!empty($rows)) {
      return $rows;
    }

    $scopeRows = $this->get_report_scope_rows_or_empty('any', isset($filters['wpml_lang']) ? $filters['wpml_lang'] : 'all', $filters, false);
    return $this->build_all_anchor_text_rows($scopeRows, $filters);
  }

  public function handle_export_pages_link_csv() {
    if (!$this->current_user_can_access_plugin()) wp_die($this->unauthorized_message());

    $nonce = $this->request_text(self::NONCE_NAME, '');
    if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) wp_die($this->invalid_nonce_message());

    $filters = $this->get_pages_link_filters_from_request();
    $rows = $this->get_pages_link_export_rows($filters);

    $filename = 'links-manager-pages-link-export-' . date('Y-m-d-His') . '.csv';

    nocache_headers();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

    $this->csv_write_row($out, [
      'Post ID',
      'Title',
      'Post Type',
      'Author',
      'Published Date',
      'Updated Date',
      'Page URL',
      'Internal Inbound',
      'Internal Inbound Status',
      'Internal Outbound',
      'Internal Outbound Status',
      'External Outbound',
      'External Outbound Status',
    ]);

    foreach ($rows as $r) {
      $post_id = (int)($r['post_id'] ?? 0);
      if ($post_id <= 0) continue;

      $post = get_post($post_id);
      $title = wp_specialchars_decode(wp_strip_all_tags((string)($r['post_title'] ?? '')), ENT_QUOTES);
      if ($title === '' && $post) {
        $title = wp_specialchars_decode(wp_strip_all_tags((string)get_the_title($post_id)), ENT_QUOTES);
      }
      $author = (string)($r['author_name'] ?? '');
      if ($author === '' && $post) {
        $author = $post->post_author ? (string)get_the_author_meta('display_name', $post->post_author) : '';
      }
      $date = (string)($r['post_date'] ?? '');
      if ($date === '' && $post) {
        $date = (string)$post->post_date;
      }
      $updated = (string)($r['post_modified'] ?? '');
      if ($updated === '' && $post) {
        $updated = (string)$post->post_modified;
      }
      $ptype = (string)($r['post_type'] ?? '');
      if ($ptype === '' && $post) {
        $ptype = (string)$post->post_type;
      }
      $url = (string)($r['page_url'] ?? '');
      if ($url === '') {
        $url = (string)get_permalink($post_id);
      }
      $status = $this->inbound_status((int)$r['inbound']);
      $internal_outbound = isset($r['internal_outbound']) ? (int)$r['internal_outbound'] : 0;
      $outbound = isset($r['outbound']) ? (int)$r['outbound'] : 0;
      $internalOutboundStatus = $this->four_level_status_label(isset($r['internal_outbound_status']) ? (string)$r['internal_outbound_status'] : 'none');
      $externalOutboundStatus = $this->four_level_status_label(isset($r['external_outbound_status']) ? (string)$r['external_outbound_status'] : 'none');

      $this->csv_write_row($out, [
        $post_id,
        $title,
        $ptype,
        $author,
        $date,
        $updated,
        $url,
        (int)$r['inbound'],
        $status,
        $internal_outbound,
        $internalOutboundStatus,
        $outbound,
        $externalOutboundStatus,
      ]);
    }

    fclose($out);
    exit;
  }

  public function handle_export_cited_domains_csv() {
    if (!$this->current_user_can_access_plugin()) wp_die($this->unauthorized_message());

    $nonce = $this->request_text(self::NONCE_NAME, '');
    if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) wp_die($this->invalid_nonce_message());

    $filters = $this->get_cited_domains_filters_from_request();
    $rows = $this->get_cited_domains_export_rows($filters);

    $filename = 'links-manager-cited-domains-export-' . date('Y-m-d-His') . '.csv';

    nocache_headers();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

    $this->csv_write_row($out, [
      'domain',
      'cited_count',
      'unique_source_pages',
      'sample_url',
    ]);

    foreach ($rows as $row) {
      $this->csv_write_row($out, [
        (string)($row['domain'] ?? ''),
        (int)($row['cites'] ?? 0),
        (int)($row['pages'] ?? 0),
        (string)($row['sample_url'] ?? ''),
      ]);
    }

    fclose($out);
    exit;
  }

  public function handle_export_anchor_grouping_csv() {
    if (!$this->current_user_can_access_plugin()) wp_die($this->unauthorized_message());

    $nonce = $this->request_text(self::NONCE_NAME, '');
    if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) wp_die($this->invalid_nonce_message());

    $groupOrderby = $this->request_text('lm_group_orderby', 'group');
    if (!in_array($groupOrderby, ['group', 'total_anchors', 'total_usage', 'inlink_usage', 'outbound_usage'], true)) $groupOrderby = 'group';
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

    $rows = [];
    if (!empty($groups)) {
      $targets = $this->sync_targets_with_groups($this->get_anchor_targets(), $groups);
      $targetsMap = [];
      foreach ($targets as $t) {
        $t = trim((string)$t);
        if ($t === '') continue;
        $k = strtolower($t);
        if (!isset($targetsMap[$k])) $targetsMap[$k] = $t;
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

      foreach ((array)($groupingPack['entries'] ?? []) as $e) {
        $rows[] = [
          'group' => (string)($e['name'] ?? ''),
          'total_anchors' => (int)($e['count'] ?? 0),
          'total_usage' => (int)($e['usage'] ?? 0),
          'total_usage_pct' => number_format((float)($e['base'] ?? 0), 1),
          'total_inlinks' => (int)($e['usageInlink'] ?? 0),
          'total_inlinks_pct' => number_format((float)($e['rawInlink'] ?? 0), 1),
          'total_outbound' => (int)($e['usageOutbound'] ?? 0),
          'total_outbound_pct' => number_format((float)($e['baseOutbound'] ?? 0), 1),
        ];
      }
    }

    $filename = 'links-manager-anchor-grouping-export-' . date('Y-m-d-His') . '.csv';
    nocache_headers();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

    $this->csv_write_row($out, [
      'note',
      'Anchors assigned to multiple groups are counted in each selected group.',
      '',
      '',
      '',
      '',
      '',
      '',
    ]);

    $this->csv_write_row($out, [
      'group',
      'total_anchors',
      'total_usage',
      'total_usage_pct',
      'total_inlinks',
      'total_inlinks_pct',
      'total_outbound',
      'total_outbound_pct',
    ]);

    foreach ($rows as $row) {
      $this->csv_write_row($out, [
        $row['group'],
        $row['total_anchors'],
        $row['total_usage'],
        $row['total_usage_pct'],
        $row['total_inlinks'],
        $row['total_inlinks_pct'],
        $row['total_outbound'],
        $row['total_outbound_pct'],
      ]);
    }

    fclose($out);
    exit;
  }

  public function handle_download_bulk_update_template() {
    if (!$this->current_user_can_access_plugin()) wp_die($this->unauthorized_message());

    $nonce = $this->request_text(self::NONCE_NAME, '');
    if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) wp_die($this->invalid_nonce_message());

    $filename = 'links-manager-bulk-update-template.csv';

    nocache_headers();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

    $this->csv_write_row($out, [
      'post_id',
      'old_link',
      'row_id',
      'new_link',
      'new_rel',
      'new_anchor',
    ]);

    $this->csv_write_row($out, [
      '123',
      'https://example.com/old-url',
      'lm_example_row_id_123',
      'https://example.com/new-url',
      'nofollow',
      'Updated anchor text',
    ]);

    $this->csv_write_row($out, [
      '456',
      'https://example.com/another-url',
      'lm_example_row_id_456',
      '',
      '',
      'Keep URL but update anchor',
    ]);

    fclose($out);
    exit;
  }

  public function handle_export_links_target_csv() {
    if (!$this->current_user_can_access_plugin()) wp_die($this->unauthorized_message());

    $nonce = $this->request_text(self::NONCE_NAME, '');
    if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) wp_die($this->invalid_nonce_message());

    $groups = $this->get_anchor_groups();
    $targets = $this->sync_targets_with_groups($this->get_anchor_targets(), $groups);

    $groupNames = [];
    foreach ($groups as $g) {
      $gname = trim((string)($g['name'] ?? ''));
      if ($gname !== '') $groupNames[] = $gname;
    }
    $groupNames = array_values(array_unique($groupNames));
    $summaryPostTypeOptions = $this->get_filterable_post_types();
    $summaryState = $this->get_links_target_summary_filters_from_request($groupNames, $summaryPostTypeOptions);
    $summaryGroupSelected = $summaryState['summary_groups'];
    $summaryGroupSearch = $summaryState['summary_group_search'];
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
    $summaryAuthor = isset($summaryState['summary_author']) ? (int)$summaryState['summary_author'] : 0;
    $summarySeoFlag = $summaryState['summary_seo_flag'];
    $summaryTotalMin = $summaryState['summary_total_min'];
    $summaryTotalMax = $summaryState['summary_total_max'];
    $summaryInMin = $summaryState['summary_in_min'];
    $summaryInMax = $summaryState['summary_in_max'];
    $summaryOutMin = $summaryState['summary_out_min'];
    $summaryOutMax = $summaryState['summary_out_max'];
    $summaryOrderby = $summaryState['summary_orderby'];
    $summaryOrder = $summaryState['summary_order'];

    $summaryTotalMinNum = $summaryTotalMin === '' ? null : intval($summaryTotalMin);
    $summaryTotalMaxNum = $summaryTotalMax === '' ? null : intval($summaryTotalMax);
    $summaryInMinNum = $summaryInMin === '' ? null : intval($summaryInMin);
    $summaryInMaxNum = $summaryInMax === '' ? null : intval($summaryInMax);
    $summaryOutMinNum = $summaryOutMin === '' ? null : intval($summaryOutMin);
    $summaryOutMaxNum = $summaryOutMax === '' ? null : intval($summaryOutMax);

    $targetsMap = [];
    foreach ($targets as $t) {
      $t = trim((string)$t);
      if ($t === '') continue;
      $k = strtolower($t);
      if (!isset($targetsMap[$k])) $targetsMap[$k] = $t;
    }

    $groupAnchorsMap = [];
    $anchorToGroups = [];
    foreach ($groups as $g) {
      $gname = (string)($g['name'] ?? '');
      $anchors = (array)($g['anchors'] ?? []);
      foreach ($anchors as $a) {
        $a = trim((string)$a);
        if ($a === '') continue;
        $k = strtolower($a);
        if (!isset($groupAnchorsMap[$k])) $groupAnchorsMap[$k] = $a;
        if (!isset($anchorToGroups[$k])) $anchorToGroups[$k] = [];
        if ($gname !== '') $anchorToGroups[$k][$gname] = true;
      }
    }

    $summaryTargetsMap = $targetsMap + $groupAnchorsMap;

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
      'author' => $summaryAuthor,
      'seo_flag' => $summarySeoFlag,
      'search_mode' => $summarySearchMode,
    ];
    try {
      $counts = $this->get_links_target_anchor_usage_map($summaryFilters, array_keys($summaryTargetsMap));
    } catch (Throwable $e) {
      $counts = [];
      if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('LM Links Target export summary error: ' . sanitize_text_field($e->getMessage()));
      }
    }

    $targetIndexByKey = [];
    foreach ($targets as $i => $t) {
      $k = strtolower(trim((string)$t));
      if ($k === '' || isset($targetIndexByKey[$k])) continue;
      $targetIndexByKey[$k] = $i;
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
      1,
      max(10, count($summaryTargetsMap)),
      $targetIndexByKey,
      $summaryFilters
    );

    $rows = [];
    foreach ((array)($summaryPack['paged_rows'] ?? []) as $row) {
      $rows[] = [
        'group' => (string)($row['glist'] ?? '—'),
        'anchor_text' => (string)($row['tLabel'] ?? ''),
        'total_usage' => (int)(($row['c']['total'] ?? 0)),
        'inlink_usage' => (int)(($row['c']['inlink'] ?? 0)),
        'outbound_usage' => (int)(($row['c']['outbound'] ?? 0)),
      ];
    }

    $filename = 'links-manager-target-summary-export-' . date('Y-m-d-His') . '.csv';

    nocache_headers();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

    $this->csv_write_row($out, [
      'group',
      'anchor_text',
      'total_usage',
      'inlink_usage',
      'outbound_usage',
    ]);

    foreach ($rows as $row) {
      $this->csv_write_row($out, [
        $row['group'],
        $row['anchor_text'],
        $row['total_usage'],
        $row['inlink_usage'],
        $row['outbound_usage'],
      ]);
    }

    fclose($out);
    exit;
  }

  public function handle_export_all_anchor_text_csv() {
    if (!$this->current_user_can_access_plugin()) wp_die($this->unauthorized_message());

    $nonce = $this->request_text(self::NONCE_NAME, '');
    if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) wp_die($this->invalid_nonce_message());

    $filters = $this->get_all_anchor_text_filters_from_request();
    $rows = $this->get_all_anchor_text_export_rows($filters);

    $filename = 'links-manager-all-anchor-text-export-' . date('Y-m-d-His') . '.csv';

    nocache_headers();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

    $this->csv_write_row($out, [
      'anchor_text',
      'quality',
      'usage_type',
      'total_usage',
      'inlink_usage',
      'outbound_usage',
      'unique_source_pages',
      'unique_destination_urls',
      'source_types',
    ]);

    foreach ($rows as $row) {
      $this->csv_write_row($out, [
        (string)$row['anchor_text'],
        $this->get_export_quality_label((string)($row['quality'] ?? ''), (string)($row['anchor_text'] ?? '')),
        $this->get_export_usage_type_label((string)($row['usage_type'] ?? '')),
        (int)$row['total'],
        (int)$row['inlink'],
        (int)$row['outbound'],
        (int)$row['source_pages'],
        (int)$row['destinations'],
        (string)$row['source_types'],
      ]);
    }

    fclose($out);
    exit;
  }

  public function handle_export_csv() {
    if (!$this->current_user_can_access_plugin()) wp_die($this->unauthorized_message());

    $nonce = $this->request_text(self::NONCE_NAME, '');
    if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) wp_die($this->invalid_nonce_message());

    $filters = $this->get_filters_from_request();

    $filename = 'links-manager-export-' . date('Y-m-d-His') . '.csv';

    nocache_headers();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

    $this->csv_write_row($out, [
      'post_id', 'old_link', 'row_id', 'new_link', 'new_rel', 'new_anchor',
      'source', 'link_location', 'block_index', 'occurrence',
      'post_title', 'post_type', 'post_author', 'post_date', 'post_modified',
      'page_url', 'link_resolved', 'link_raw', 'anchor_text', 'quality', 'alt_text', 'snippet',
      'link_type', 'relationship', 'value_type', 'count'
    ]);

    if ($this->is_indexed_datastore_ready() && $this->stream_indexed_editor_export_rows($out, $filters)) {
      fclose($out);
      exit;
    }

    $all = $this->get_report_scope_rows_or_empty($filters['post_type'], isset($filters['wpml_lang']) ? $filters['wpml_lang'] : 'all', $filters, false);
    $rows = $this->apply_filters_and_group($all, $filters);

    foreach ($rows as $r) {
      $this->csv_write_row($out, [
        $r['post_id'],
        $r['link'],
        $r['row_id'],
        '',
        '',
        '',
        $r['source'],
        $r['link_location'],
        $r['block_index'],
        $r['occurrence'] ?? '',
        $r['post_title'],
        $r['post_type'],
        $r['post_author'],
        $r['post_date'],
        $r['post_modified'],
        $r['page_url'],
        $r['link'],
        $r['link_raw'],
        $r['anchor_text'],
        $this->get_export_quality_label('', (string)($r['anchor_text'] ?? '')),
        $r['alt_text'],
        $r['snippet'],
        $r['link_type'],
        $r['relationship'],
        $r['value_type'],
        isset($r['count']) ? $r['count'] : 1,
      ]);
    }

    fclose($out);
    exit;
  }
}
