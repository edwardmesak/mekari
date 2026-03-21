<?php
/**
 * CSV export handlers for Links Manager admin pages.
 */

if (!defined('ABSPATH')) {
  exit;
}

trait LM_Export_Handlers_Trait {
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

  public function handle_export_pages_link_csv() {
    if (!$this->current_user_can_access_plugin()) wp_die($this->unauthorized_message());

    $nonce = isset($_GET[self::NONCE_NAME]) ? sanitize_text_field($_GET[self::NONCE_NAME]) : '';
    if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) wp_die($this->invalid_nonce_message());

    $filters = $this->get_pages_link_filters_from_request();
    $rows = null;
    if (!$filters['rebuild']
      && $this->is_indexed_datastore_ready()
      && $this->indexed_dataset_has_rows($filters['post_type'], isset($filters['wpml_lang']) ? $filters['wpml_lang'] : 'all')
      && $this->can_use_indexed_pages_link_summary_fastpath($filters)) {
      $rows = $this->get_pages_with_inbound_counts_from_indexed_summary($filters);
      if (is_array($rows) && empty($rows)) {
        $rows = null;
      }
    }
    if (!is_array($rows)) {
      $all = $this->get_canonical_rows_for_scope($filters['post_type'], $filters['rebuild'], isset($filters['wpml_lang']) ? $filters['wpml_lang'] : 'all', $filters);
      $this->compact_rows_for_pages_link($all);
      $rows = $this->get_pages_with_inbound_counts($all, $filters, true);
    }

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
      'Inbound',
      'Inbound Status',
      'Internal Outbound',
      'Internal Outbound Status',
      'External Outbound',
      'External Outbound Status',
    ]);

    foreach ($rows as $r) {
      $post_id = (int)($r['post_id'] ?? 0);
      if ($post_id <= 0) continue;

      $title = wp_specialchars_decode(wp_strip_all_tags((string)($r['post_title'] ?? '')), ENT_QUOTES);
      $author = (string)($r['author_name'] ?? '');
      $date = (string)($r['post_date'] ?? '');
      $updated = (string)($r['post_modified'] ?? '');
      $ptype = (string)($r['post_type'] ?? '');
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

    $nonce = isset($_GET[self::NONCE_NAME]) ? sanitize_text_field($_GET[self::NONCE_NAME]) : '';
    if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) wp_die($this->invalid_nonce_message());

    $filters = $this->get_cited_domains_filters_from_request();
    $all = $this->get_indexed_rows_for_custom_aggregation($filters, 'exlink');
    if (empty($all)) {
      $all = $this->get_canonical_rows_for_scope('any', $filters['rebuild'], isset($filters['wpml_lang']) ? $filters['wpml_lang'] : 'all', $filters);
    }
    $summaryRows = $this->build_cited_domains_summary_rows($all, $filters);

    $allowedDomains = [];
    $domainStats = [];
    foreach ($summaryRows as $r) {
      $domain = strtolower((string)($r['domain'] ?? ''));
      if ($domain === '') continue;
      $allowedDomains[$domain] = true;
      $domainStats[$domain] = [
        'cites' => (int)($r['cites'] ?? 0),
        'pages' => (int)($r['pages'] ?? 0),
      ];
    }

    $allowedPostIds = $this->get_post_ids_by_post_terms(
      isset($filters['post_category']) ? (int)$filters['post_category'] : 0,
      isset($filters['post_tag']) ? (int)$filters['post_tag'] : 0
    );
    $textMode = (string)($filters['search_mode'] ?? 'contains');

    $filename = 'links-manager-cited-domains-export-' . date('Y-m-d-His') . '.csv';

    nocache_headers();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

    $this->csv_write_row($out, [
      'domain',
      'domain_cited_count',
      'domain_unique_source_pages',
      'destination_url',
      'source_page_url',
      'source_post_id',
      'source_post_title',
      'source_post_type',
      'source_link_location',
      'anchor_text',
      'rel_raw',
    ]);

    foreach ($all as $row) {
      if (is_array($allowedPostIds)) {
        $rowPostId = isset($row['post_id']) ? (string)intval($row['post_id']) : '';
        if ($rowPostId === '' || !isset($allowedPostIds[$rowPostId])) continue;
      }

      if (($row['link_type'] ?? '') !== 'exlink') continue;
      if (($filters['post_type'] ?? 'any') !== 'any' && (string)($row['post_type'] ?? '') !== (string)$filters['post_type']) continue;
      if (($filters['location'] ?? 'any') !== 'any' && (string)($row['link_location'] ?? '') !== (string)$filters['location']) continue;
      if (($filters['source_type'] ?? 'any') !== 'any' && (string)($row['source'] ?? '') !== (string)$filters['source_type']) continue;

      if ((string)($filters['value_contains'] ?? '') !== '' && !$this->text_matches((string)($row['link'] ?? ''), (string)$filters['value_contains'], $textMode)) continue;
      if ((string)($filters['source_contains'] ?? '') !== '' && !$this->text_matches((string)($row['page_url'] ?? ''), (string)$filters['source_contains'], $textMode)) continue;
      if ((string)($filters['title_contains'] ?? '') !== '' && !$this->text_matches((string)($row['post_title'] ?? ''), (string)$filters['title_contains'], $textMode)) continue;
      if ((string)($filters['author_contains'] ?? '') !== '' && !$this->text_matches((string)($row['post_author'] ?? ''), (string)$filters['author_contains'], $textMode)) continue;
      if ((string)($filters['anchor_contains'] ?? '') !== '' && !$this->text_matches((string)($row['anchor_text'] ?? ''), (string)$filters['anchor_contains'], $textMode)) continue;

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

      $destination = $this->normalize_url((string)($row['link'] ?? ''));
      $domain = strtolower((string)parse_url($destination, PHP_URL_HOST));
      if ($domain === '' || !isset($allowedDomains[$domain])) continue;

      $stats = $domainStats[$domain] ?? ['cites' => 0, 'pages' => 0];

      $this->csv_write_row($out, [
        $domain,
        (int)$stats['cites'],
        (int)$stats['pages'],
        $destination,
        (string)($row['page_url'] ?? ''),
        (string)($row['post_id'] ?? ''),
        (string)($row['post_title'] ?? ''),
        (string)($row['post_type'] ?? ''),
        (string)($row['link_location'] ?? ''),
        (string)($row['anchor_text'] ?? ''),
        (string)($row['rel_raw'] ?? ''),
      ]);
    }

    fclose($out);
    exit;
  }

  public function handle_export_anchor_grouping_csv() {
    if (!$this->current_user_can_access_plugin()) wp_die($this->unauthorized_message());

    $nonce = isset($_GET[self::NONCE_NAME]) ? sanitize_text_field($_GET[self::NONCE_NAME]) : '';
    if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) wp_die($this->invalid_nonce_message());

    $groupOrderby = isset($_GET['lm_group_orderby']) ? sanitize_text_field((string)$_GET['lm_group_orderby']) : 'tag';
    if (!in_array($groupOrderby, ['tag', 'total_anchors', 'total_usage', 'inlink_usage', 'outbound_usage'], true)) $groupOrderby = 'tag';
    $groupOrder = isset($_GET['lm_group_order']) ? strtoupper(sanitize_text_field((string)$_GET['lm_group_order'])) : 'ASC';
    if (!in_array($groupOrder, ['ASC', 'DESC'], true)) $groupOrder = 'ASC';

    $groups = $this->get_anchor_groups();
    $groupNames = [];
    foreach ($groups as $g) {
      $gname = trim((string)($g['name'] ?? ''));
      if ($gname !== '') $groupNames[] = $gname;
    }
    $groupNames = array_values(array_unique($groupNames));

    $groupFilterRaw = isset($_GET['lm_group_filter']) ? wp_unslash($_GET['lm_group_filter']) : [];
    if (!is_array($groupFilterRaw)) {
      $groupFilterRaw = $groupFilterRaw === '' ? [] : [$groupFilterRaw];
    }
    $groupFilterSelected = [];
    foreach ($groupFilterRaw as $item) {
      $item = trim(sanitize_text_field((string)$item));
      if ($item === '') continue;
      if ($item === 'no_group' || in_array($item, $groupNames, true)) {
        $groupFilterSelected[$item] = true;
      }
    }
    $groupFilterSelected = array_keys($groupFilterSelected);
    $groupSearch = isset($_GET['lm_group_search']) ? sanitize_text_field((string)$_GET['lm_group_search']) : '';
    $groupSearchMode = isset($_GET['lm_group_search_mode']) ? $this->sanitize_text_match_mode((string)$_GET['lm_group_search_mode']) : 'contains';

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

  public function handle_export_links_target_csv() {
    if (!$this->current_user_can_access_plugin()) wp_die($this->unauthorized_message());

    $nonce = isset($_GET[self::NONCE_NAME]) ? sanitize_text_field($_GET[self::NONCE_NAME]) : '';
    if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) wp_die($this->invalid_nonce_message());

    $groups = $this->get_anchor_groups();
    $targets = $this->sync_targets_with_groups($this->get_anchor_targets(), $groups);

    $groupNames = [];
    foreach ($groups as $g) {
      $gname = trim((string)($g['name'] ?? ''));
      if ($gname !== '') $groupNames[] = $gname;
    }
    $groupNames = array_values(array_unique($groupNames));

    $summaryGroupsRaw = isset($_GET['lm_summary_groups']) ? wp_unslash($_GET['lm_summary_groups']) : [];
    if (!is_array($summaryGroupsRaw)) {
      $summaryGroupsRaw = $summaryGroupsRaw === '' ? [] : [$summaryGroupsRaw];
    }
    if (empty($summaryGroupsRaw) && isset($_GET['lm_summary_group'])) {
      $legacySummaryGroup = trim(sanitize_text_field((string)$_GET['lm_summary_group']));
      if ($legacySummaryGroup !== '') $summaryGroupsRaw[] = $legacySummaryGroup;
    }
    $summaryGroupSelected = [];
    foreach ($summaryGroupsRaw as $item) {
      $item = trim(sanitize_text_field((string)$item));
      if ($item === '') continue;
      if ($item === 'no_group' || in_array($item, $groupNames, true)) {
        $summaryGroupSelected[$item] = true;
      }
    }
    $summaryGroupSelected = array_keys($summaryGroupSelected);
    $summaryGroupSearch = isset($_GET['lm_summary_group_search']) ? sanitize_text_field((string)$_GET['lm_summary_group_search']) : '';
    $summaryAnchor = isset($_GET['lm_summary_anchor']) ? sanitize_text_field((string)$_GET['lm_summary_anchor']) : '';
    $summaryAnchorSearch = isset($_GET['lm_summary_anchor_search']) ? sanitize_text_field((string)$_GET['lm_summary_anchor_search']) : '';
    $summarySearchMode = isset($_GET['lm_summary_search_mode']) ? $this->sanitize_text_match_mode((string)$_GET['lm_summary_search_mode']) : 'contains';
    if ($summaryAnchorSearch === '' && $summaryAnchor !== '') {
      $summaryAnchorSearch = $summaryAnchor;
      $summarySearchMode = 'exact';
    }
    $summaryPostTypeOptions = $this->get_filterable_post_types();
    $summaryPostType = isset($_GET['lm_summary_post_type']) ? sanitize_key((string)$_GET['lm_summary_post_type']) : 'any';
    if ($summaryPostType !== 'any' && !isset($summaryPostTypeOptions[$summaryPostType])) $summaryPostType = 'any';
    $summaryPostCategory = isset($_GET['lm_summary_post_category']) ? $this->sanitize_post_term_filter($_GET['lm_summary_post_category'], 'category') : 0;
    $summaryPostTag = isset($_GET['lm_summary_post_tag']) ? $this->sanitize_post_term_filter($_GET['lm_summary_post_tag'], 'post_tag') : 0;
    if ($summaryPostType !== 'any' && $summaryPostType !== 'post') {
      $summaryPostCategory = 0;
      $summaryPostTag = 0;
    }
    $summaryLocation = isset($_GET['lm_summary_location']) ? sanitize_text_field((string)$_GET['lm_summary_location']) : 'any';
    if ($summaryLocation === '') $summaryLocation = 'any';
    $summarySourceType = isset($_GET['lm_summary_source_type'])
      ? $this->sanitize_source_type_filter($_GET['lm_summary_source_type'])
      : 'any';
    $summaryLinkType = isset($_GET['lm_summary_link_type']) ? sanitize_text_field((string)$_GET['lm_summary_link_type']) : 'any';
    if (!in_array($summaryLinkType, ['any', 'inlink', 'exlink'], true)) $summaryLinkType = 'any';
    $summaryValueContains = isset($_GET['lm_summary_value']) ? sanitize_text_field((string)$_GET['lm_summary_value']) : '';
    $summarySourceContains = isset($_GET['lm_summary_source']) ? sanitize_text_field((string)$_GET['lm_summary_source']) : '';
    $summaryTitleContains = isset($_GET['lm_summary_title']) ? sanitize_text_field((string)$_GET['lm_summary_title']) : '';
    $summaryAuthorContains = isset($_GET['lm_summary_author']) ? sanitize_text_field((string)$_GET['lm_summary_author']) : '';
    $summarySeoFlag = isset($_GET['lm_summary_seo_flag']) ? sanitize_text_field((string)$_GET['lm_summary_seo_flag']) : 'any';
    if (!in_array($summarySeoFlag, ['any', 'dofollow', 'nofollow', 'sponsored', 'ugc'], true)) $summarySeoFlag = 'any';
    $summaryTotalMin = isset($_GET['lm_summary_total_min']) ? (string)$_GET['lm_summary_total_min'] : '';
    $summaryTotalMax = isset($_GET['lm_summary_total_max']) ? (string)$_GET['lm_summary_total_max'] : '';
    $summaryInMin = isset($_GET['lm_summary_in_min']) ? (string)$_GET['lm_summary_in_min'] : '';
    $summaryInMax = isset($_GET['lm_summary_in_max']) ? (string)$_GET['lm_summary_in_max'] : '';
    $summaryOutMin = isset($_GET['lm_summary_out_min']) ? (string)$_GET['lm_summary_out_min'] : '';
    $summaryOutMax = isset($_GET['lm_summary_out_max']) ? (string)$_GET['lm_summary_out_max'] : '';
    $summaryOrderby = isset($_GET['lm_summary_orderby']) ? sanitize_text_field((string)$_GET['lm_summary_orderby']) : 'anchor';
    if (!in_array($summaryOrderby, ['group', 'anchor', 'total', 'inlink', 'outbound'], true)) $summaryOrderby = 'anchor';
    $summaryOrder = isset($_GET['lm_summary_order']) ? strtoupper(sanitize_text_field((string)$_GET['lm_summary_order'])) : 'ASC';
    if (!in_array($summaryOrder, ['ASC', 'DESC'], true)) $summaryOrder = 'ASC';

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
      'author_contains' => $summaryAuthorContains,
      'seo_flag' => $summarySeoFlag,
      'search_mode' => $summarySearchMode,
    ];
    $counts = $this->get_links_target_anchor_usage_map($summaryFilters, array_keys($summaryTargetsMap));

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

    $nonce = isset($_GET[self::NONCE_NAME]) ? sanitize_text_field($_GET[self::NONCE_NAME]) : '';
    if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) wp_die($this->invalid_nonce_message());

    $filters = $this->get_all_anchor_text_filters_from_request();
    $rows = [];
    $indexedRows = $this->get_indexed_all_anchor_text_summary_rows($filters);
    if (!empty($indexedRows)) {
      $rows = $indexedRows;
    } else {
      $rows = $this->build_all_anchor_text_rows([], $filters);
    }

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
        (string)$row['quality'],
        (string)$row['usage_type'],
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

    $nonce = isset($_GET[self::NONCE_NAME]) ? sanitize_text_field($_GET[self::NONCE_NAME]) : '';
    if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) wp_die($this->invalid_nonce_message());

    $filters = $this->get_filters_from_request();
    $all = $this->get_canonical_rows_for_scope($filters['post_type'], $filters['rebuild'], isset($filters['wpml_lang']) ? $filters['wpml_lang'] : 'all', $filters);
    $rows = $this->apply_filters_and_group($all, $filters);

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
      'page_url', 'link_resolved', 'link_raw', 'anchor_text', 'alt_text', 'snippet',
      'link_type', 'relationship', 'value_type', 'count'
    ]);

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
