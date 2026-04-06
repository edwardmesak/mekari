<?php
/**
 * All Anchor Text admin page rendering helpers.
 */

if (!defined('ABSPATH')) {
  exit;
}

trait LM_All_Anchor_Text_Admin_Trait {
  public function render_admin_all_anchor_text_page() {
    if (!$this->current_user_can_access_plugin()) wp_die($this->unauthorized_message());

    $filters = $this->get_all_anchor_text_filters_from_request();
    $exportUrl = $this->build_all_anchor_text_export_url($filters);
    $postCategoryOptions = $this->get_post_term_options('category');
    $postTagOptions = $this->get_post_term_options('post_tag');
    $groupOptions = [];
    foreach ($this->get_anchor_groups() as $g) {
      $gname = trim((string)($g['name'] ?? ''));
      if ($gname !== '') $groupOptions[$gname] = $gname;
    }
    ksort($groupOptions);

    $rows = [];
    $total = 0;
    $perPage = $filters['per_page'];
    $totalPages = 1;
    $pageRows = [];

    $usedIndexedPagedFastpath = false;
    if (empty($filters['rebuild'])) {
      $indexedPaged = $this->get_indexed_all_anchor_text_paged_result($filters);
      if (is_array($indexedPaged)
        && isset($indexedPaged['items'])
        && isset($indexedPaged['pagination'])
        && is_array($indexedPaged['items'])
        && is_array($indexedPaged['pagination'])
        && (!empty($indexedPaged['items']) || (int)($indexedPaged['pagination']['total'] ?? 0) > 0)) {
        $usedIndexedPagedFastpath = true;
        $pageRows = array_values((array)$indexedPaged['items']);
        $pagination = (array)$indexedPaged['pagination'];
        $total = max(0, (int)($pagination['total'] ?? 0));
        $perPage = max(10, (int)($pagination['per_page'] ?? $perPage));
        $filters['paged'] = max(1, (int)($pagination['paged'] ?? $filters['paged']));
        $totalPages = max(1, (int)($pagination['total_pages'] ?? 1));
      }
    }

    if (!$usedIndexedPagedFastpath) {
      $rows = $this->build_all_anchor_text_rows([], $filters);
      $total = count($rows);
      $perPage = $filters['per_page'];
      $totalPages = max(1, (int)ceil($total / $perPage));
      if ($filters['paged'] > $totalPages) $filters['paged'] = $totalPages;
      $offset = ($filters['paged'] - 1) * $perPage;
      $pageRows = array_slice($rows, $offset, $perPage);
    }
    $offset = ($filters['paged'] - 1) * $perPage;

    $qualitySummary = [
      'good' => ['total' => 0, 'inlink' => 0, 'outbound' => 0],
      'poor' => ['total' => 0, 'inlink' => 0, 'outbound' => 0],
      'bad' => ['total' => 0, 'inlink' => 0, 'outbound' => 0],
    ];
    $qualityTotalBase = 0;
    $qualityInlinkBase = 0;
    $qualityOutboundBase = 0;
    if ($usedIndexedPagedFastpath) {
      $qualityPack = isset($indexedPaged['quality_summary']) ? $indexedPaged['quality_summary'] : null;
      if (is_array($qualityPack) && isset($qualityPack['summary']) && is_array($qualityPack['summary'])) {
        $qualitySummary = $qualityPack['summary'];
        $qualityTotalBase = max(0, (int)($qualityPack['total_base'] ?? 0));
        $qualityInlinkBase = max(0, (int)($qualityPack['inlink_base'] ?? 0));
        $qualityOutboundBase = max(0, (int)($qualityPack['outbound_base'] ?? 0));
      }
    }
    if (!$usedIndexedPagedFastpath || ($qualityTotalBase === 0 && $qualityInlinkBase === 0 && $qualityOutboundBase === 0 && !empty($rows))) {
      foreach ($rows as $summaryRow) {
        $qKey = isset($summaryRow['quality']) ? (string)$summaryRow['quality'] : 'poor';
        if (!isset($qualitySummary[$qKey])) {
          $qualitySummary[$qKey] = ['total' => 0, 'inlink' => 0, 'outbound' => 0];
        }
        $qualitySummary[$qKey]['total'] += (int)($summaryRow['total'] ?? 0);
        $qualitySummary[$qKey]['inlink'] += (int)($summaryRow['inlink'] ?? 0);
        $qualitySummary[$qKey]['outbound'] += (int)($summaryRow['outbound'] ?? 0);
      }
      $qualityTotalBase = 0;
      $qualityInlinkBase = 0;
      $qualityOutboundBase = 0;
      foreach ($qualitySummary as $qRow) {
        $qualityTotalBase += (int)$qRow['total'];
        $qualityInlinkBase += (int)$qRow['inlink'];
        $qualityOutboundBase += (int)$qRow['outbound'];
      }
    }

    echo '<div class="wrap lm-wrap">';
    $this->render_admin_page_header(
      __('Links Manager - All Anchor Text', 'links-manager'),
      __('Explore anchor text usage across internal and external links, then spot overused, weak, or missing patterns more quickly.', 'links-manager')
    );

    echo '<div class="lm-card lm-card-full">';
    $this->render_admin_section_intro(
      __('Filter', 'links-manager'),
      __('Filter by scope, quality, group, and usage type to focus on the anchor patterns that matter.', 'links-manager')
    );
    echo '<form method="get" action="">';
    echo '<input type="hidden" name="page" value="links-manager-all-anchor-text"/>';
    foreach ($this->get_wpml_admin_lang_url_args() as $langKey => $langValue) {
      echo '<input type="hidden" name="' . esc_attr((string)$langKey) . '" value="' . esc_attr((string)$langValue) . '"/>';
    }
    echo '<table class="form-table lm-filter-table" role="presentation"><tbody>';
    echo '<tr><th scope="row">Post Type</th><td><select name="lm_at_post_type">';
    echo '<option value="any"' . selected($filters['post_type'], 'any', false) . '>All</option>';
    foreach ($this->get_filterable_post_types() as $ptKey => $ptLabel) {
      echo '<option value="' . esc_attr((string)$ptKey) . '"' . selected($filters['post_type'], (string)$ptKey, false) . '>' . esc_html((string)$ptLabel) . ' (' . esc_html((string)$ptKey) . ')</option>';
    }
    echo '</select></td></tr>';
    echo '<tr><th scope="row">Post Category</th><td><select name="lm_at_post_category">';
    echo '<option value="0"' . selected((int)($filters['post_category'] ?? 0), 0, false) . '>All</option>';
    foreach ($postCategoryOptions as $termId => $termLabel) {
      echo '<option value="' . esc_attr((string)$termId) . '"' . selected((int)($filters['post_category'] ?? 0), (int)$termId, false) . '>' . esc_html($termLabel) . '</option>';
    }
    echo '</select><div class="lm-small">Applied to WordPress posts only.</div></td></tr>';
    echo '<tr><th scope="row">Post Tag</th><td><select name="lm_at_post_tag">';
    echo '<option value="0"' . selected((int)($filters['post_tag'] ?? 0), 0, false) . '>All</option>';
    foreach ($postTagOptions as $termId => $termLabel) {
      echo '<option value="' . esc_attr((string)$termId) . '"' . selected((int)($filters['post_tag'] ?? 0), (int)$termId, false) . '>' . esc_html($termLabel) . '</option>';
    }
    echo '</select><div class="lm-small">Applied to WordPress posts only.</div></td></tr>';
    echo '<tr><th scope="row">Link Location</th><td><input type="text" name="lm_at_location" value="' . esc_attr($filters['location'] === 'any' ? '' : (string)$filters['location']) . '" class="regular-text" placeholder="content / excerpt / meta:xxx" /><div class="lm-small">Leave empty for All.</div></td></tr>';
    echo '<tr><th scope="row">Link Type</th><td><select name="lm_at_link_type">';
    echo '<option value="any"' . selected($filters['link_type'], 'any', false) . '>All</option>';
    echo '<option value="inlink"' . selected($filters['link_type'], 'inlink', false) . '>Internal</option>';
    echo '<option value="exlink"' . selected($filters['link_type'], 'exlink', false) . '>External</option>';
    echo '</select></td></tr>';
    echo '<tr><th scope="row">' . esc_html__('Search Destination URL', 'links-manager') . '</th><td><input type="text" name="lm_at_value" value="' . esc_attr($filters['value_contains']) . '" class="regular-text" placeholder="example.com / /contact" /></td></tr>';
    echo '<tr><th scope="row">' . esc_html__('Search Source URL', 'links-manager') . '</th><td><input type="text" name="lm_at_source" value="' . esc_attr($filters['source_contains']) . '" class="regular-text" placeholder="/category /slug" /></td></tr>';
    echo '<tr><th scope="row">' . esc_html__('Search Title', 'links-manager') . '</th><td><input type="text" name="lm_at_title" value="' . esc_attr($filters['title_contains']) . '" class="regular-text" placeholder="post title" /></td></tr>';
    echo '<tr><th scope="row">' . esc_html__('Search Author', 'links-manager') . '</th><td><input type="text" name="lm_at_author" value="' . esc_attr($filters['author_contains']) . '" class="regular-text" placeholder="author" /></td></tr>';
    echo '<tr><th scope="row">' . esc_html__('Search Anchor Text', 'links-manager') . '</th><td><input type="text" name="lm_at_search" value="' . esc_attr($filters['search']) . '" class="regular-text" placeholder="anchor keyword" /></td></tr>';
    echo '<tr><th scope="row">Text Search Mode</th><td><select name="lm_at_search_mode">';
    foreach ($this->get_text_match_modes() as $modeKey => $modeLabel) {
      echo '<option value="' . esc_attr($modeKey) . '"' . selected($filters['search_mode'], $modeKey, false) . '>' . esc_html($modeLabel) . '</option>';
    }
    echo '</select><div class="lm-small">Applies to all text filters on this page.</div></td></tr>';
    echo '<tr><th scope="row">Source Type</th><td><select name="lm_at_source_type">';
    foreach ($this->get_filterable_source_type_options(true) as $sourceKey => $sourceLabel) {
      echo '<option value="' . esc_attr((string)$sourceKey) . '"' . selected($filters['source_type'], (string)$sourceKey, false) . '>' . esc_html((string)$sourceLabel) . '</option>';
    }
    echo '</select></td></tr>';
    echo '<tr><th scope="row">Usage Type</th><td><select name="lm_at_usage_type">';
    echo '<option value="any"' . selected($filters['usage_type'], 'any', false) . '>All</option>';
    echo '<option value="mixed"' . selected($filters['usage_type'], 'mixed', false) . '>Mixed</option>';
    echo '<option value="inlink_only"' . selected($filters['usage_type'], 'inlink_only', false) . '>Inlink Only</option>';
    echo '<option value="outbound_only"' . selected($filters['usage_type'], 'outbound_only', false) . '>Outbound Only</option>';
    echo '</select></td></tr>';
    echo '<tr><th scope="row">Quality</th><td><select name="lm_at_quality">';
    echo '<option value="any"' . selected($filters['quality'], 'any', false) . '>All</option>';
    echo '<option value="good"' . selected($filters['quality'], 'good', false) . '>Good</option>';
    echo '<option value="poor"' . selected($filters['quality'], 'poor', false) . '>Poor</option>';
    echo '<option value="bad"' . selected($filters['quality'], 'bad', false) . '>Bad</option>';
    echo '</select></td></tr>';
    echo '<tr><th scope="row">Group</th><td><select name="lm_at_group">';
    echo '<option value="any"' . selected($filters['group'], 'any', false) . '>All</option>';
    echo '<option value="no_group"' . selected($filters['group'], 'no_group', false) . '>No Group</option>';
    foreach ($groupOptions as $groupName) {
      echo '<option value="' . esc_attr($groupName) . '"' . selected($filters['group'], $groupName, false) . '>' . esc_html($groupName) . '</option>';
    }
    echo '</select></td></tr>';
    echo '<tr><th scope="row">SEO Flags</th><td><select name="lm_at_seo_flag">';
    echo '<option value="any"' . selected($filters['seo_flag'], 'any', false) . '>All</option>';
    echo '<option value="dofollow"' . selected($filters['seo_flag'], 'dofollow', false) . '>Dofollow</option>';
    echo '<option value="nofollow"' . selected($filters['seo_flag'], 'nofollow', false) . '>Nofollow</option>';
    echo '<option value="sponsored"' . selected($filters['seo_flag'], 'sponsored', false) . '>Sponsored</option>';
    echo '<option value="ugc"' . selected($filters['seo_flag'], 'ugc', false) . '>UGC</option>';
    echo '</select></td></tr>';
    echo '<tr><th scope="row">Total (Min/Max)</th><td>';
    echo '<input type="number" name="lm_at_min_total" min="0" value="' . esc_attr((string)$filters['min_total']) . '" placeholder="Min" style="width:120px; margin-right:8px;" />';
    echo '<input type="number" name="lm_at_max_total" min="0" value="' . esc_attr($filters['max_total'] >= 0 ? (string)$filters['max_total'] : '') . '" placeholder="Max" style="width:120px;" />';
    echo '</td></tr>';
    echo '<tr><th scope="row">Inlink (Min/Max)</th><td>';
    echo '<input type="number" name="lm_at_min_inlink" min="0" value="' . esc_attr((string)$filters['min_inlink']) . '" placeholder="Min" style="width:120px; margin-right:8px;" />';
    echo '<input type="number" name="lm_at_max_inlink" min="0" value="' . esc_attr($filters['max_inlink'] >= 0 ? (string)$filters['max_inlink'] : '') . '" placeholder="Max" style="width:120px;" />';
    echo '</td></tr>';
    echo '<tr><th scope="row">Outbound (Min/Max)</th><td>';
    echo '<input type="number" name="lm_at_min_outbound" min="0" value="' . esc_attr((string)$filters['min_outbound']) . '" placeholder="Min" style="width:120px; margin-right:8px;" />';
    echo '<input type="number" name="lm_at_max_outbound" min="0" value="' . esc_attr($filters['max_outbound'] >= 0 ? (string)$filters['max_outbound'] : '') . '" placeholder="Max" style="width:120px;" />';
    echo '</td></tr>';
    echo '<tr><th scope="row">Unique Source Pages (Min/Max)</th><td>';
    echo '<input type="number" name="lm_at_min_pages" min="0" value="' . esc_attr((string)$filters['min_pages']) . '" placeholder="Min" style="width:120px; margin-right:8px;" />';
    echo '<input type="number" name="lm_at_max_pages" min="0" value="' . esc_attr($filters['max_pages'] >= 0 ? (string)$filters['max_pages'] : '') . '" placeholder="Max" style="width:120px;" />';
    echo '</td></tr>';
    echo '<tr><th scope="row">Unique Destination URLs (Min/Max)</th><td>';
    echo '<input type="number" name="lm_at_min_destinations" min="0" value="' . esc_attr((string)$filters['min_destinations']) . '" placeholder="Min" style="width:120px; margin-right:8px;" />';
    echo '<input type="number" name="lm_at_max_destinations" min="0" value="' . esc_attr($filters['max_destinations'] >= 0 ? (string)$filters['max_destinations'] : '') . '" placeholder="Max" style="width:120px;" />';
    echo '</td></tr>';
    echo '<tr><th scope="row">' . esc_html__('Order By', 'links-manager') . '</th><td>';
    echo '<select name="lm_at_orderby">';
    echo '<option value="total"' . selected($filters['orderby'], 'total', false) . '>Total</option>';
    echo '<option value="inlink"' . selected($filters['orderby'], 'inlink', false) . '>Inlink</option>';
    echo '<option value="outbound"' . selected($filters['orderby'], 'outbound', false) . '>External Outbound</option>';
    echo '<option value="pages"' . selected($filters['orderby'], 'pages', false) . '>Unique Source Pages</option>';
    echo '<option value="destinations"' . selected($filters['orderby'], 'destinations', false) . '>Unique Destination URLs</option>';
    echo '<option value="anchor"' . selected($filters['orderby'], 'anchor', false) . '>Anchor Text</option>';
    echo '<option value="quality"' . selected($filters['orderby'], 'quality', false) . '>Quality</option>';
    echo '<option value="source_types"' . selected($filters['orderby'], 'source_types', false) . '>Source Types</option>';
    echo '<option value="usage_type"' . selected($filters['orderby'], 'usage_type', false) . '>Usage Type</option>';
    echo '</select> ';
    echo '<select name="lm_at_order">';
    echo '<option value="DESC"' . selected($filters['order'], 'DESC', false) . '>DESC</option>';
    echo '<option value="ASC"' . selected($filters['order'], 'ASC', false) . '>ASC</option>';
    echo '</select>';
    echo '</td></tr>';
    echo '<tr><th scope="row">' . esc_html__('Cache', 'links-manager') . '</th><td><label><input type="checkbox" name="lm_at_rebuild" value="1"' . checked($filters['rebuild'] ? '1' : '0', '1', false) . '> ' . esc_html__('Rebuild cache', 'links-manager') . '</label></td></tr>';
    echo '<tr><th scope="row">' . esc_html__('Per Page', 'links-manager') . '</th><td><input type="number" name="lm_at_per_page" min="10" max="500" value="' . esc_attr((string)$filters['per_page']) . '" style="width:90px;" /></td></tr>';
    echo '<tr><th scope="row">' . esc_html__('Export', 'links-manager') . '</th><td><a class="button button-secondary" href="' . esc_url($exportUrl) . '">' . esc_html__('Export CSV', 'links-manager') . '</a><div class="lm-small">' . esc_html__('Export includes all filtered results.', 'links-manager') . '</div></td></tr>';
    echo '</tbody></table>';
    echo '<div class="lm-filter-actions">';
    submit_button(__('Apply Filters', 'links-manager'), 'primary', 'submit', false);
    echo '<a class="button" href="' . esc_url($this->admin_page_url('links-manager-all-anchor-text')) . '">' . esc_html__('Reset Filter', 'links-manager') . '</a>';
    echo '</div>';
    echo '</form>';
    echo '</div>';

    echo '<div class="lm-card lm-card-full lm-stack-sm">';
    echo '<div style="font-weight:bold;">Total: ' . esc_html((string)$total) . ' anchor texts</div>';
    echo '<div class="lm-small">';
    echo '<strong>Quality rule:</strong> ';
    echo esc_html($this->get_anchor_quality_status_help_text());
    echo '</div>';
    $quickFilters = [
      'any' => 'All',
      'mixed' => 'Mixed',
      'inlink_only' => 'Inlink Only',
      'outbound_only' => 'Outbound Only',
    ];
    echo '<div class="lm-quick-actions">';
    foreach ($quickFilters as $k => $label) {
      $btnClass = ((string)$filters['usage_type'] === (string)$k) ? 'button button-primary' : 'button button-secondary';
      $url = $this->all_anchor_text_admin_url($filters, ['lm_at_usage_type' => $k, 'lm_at_paged' => 1]);
      echo '<a class="' . esc_attr($btnClass) . '" href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
    }
    echo '</div>';
    echo '</div>';

    echo '<div class="lm-card lm-card-full lm-stack-sm">';
    $this->render_admin_section_intro(
      __('Anchor Text Summary', 'links-manager'),
      __('Compare anchor quality distribution across the current filtered anchor set.', 'links-manager')
    );
    echo '<div class="lm-table-wrap lm-summary-table-wrap">';
    echo '<table class="widefat striped lm-table">';
    echo '<thead><tr>';
    echo '<th class="lm-col-quality">Quality</th>';
    echo '<th class="lm-col-count">Total</th>';
    echo '<th class="lm-col-count">%</th>';
    echo '<th class="lm-col-count">Inlink</th>';
    echo '<th class="lm-col-count">%</th>';
    echo '<th class="lm-col-count">Outbound</th>';
    echo '<th class="lm-col-count">%</th>';
    echo '</tr></thead><tbody>';

    foreach (['good' => 'Good', 'poor' => 'Poor', 'bad' => 'Bad'] as $qualityKey => $qualityLabel) {
      $rowTotal = (int)($qualitySummary[$qualityKey]['total'] ?? 0);
      $rowInlink = (int)($qualitySummary[$qualityKey]['inlink'] ?? 0);
      $rowOutbound = (int)($qualitySummary[$qualityKey]['outbound'] ?? 0);
      $pctTotal = $qualityTotalBase > 0 ? (($rowTotal / $qualityTotalBase) * 100) : 0;
      $pctInlink = $qualityInlinkBase > 0 ? (($rowInlink / $qualityInlinkBase) * 100) : 0;
      $pctOutbound = $qualityOutboundBase > 0 ? (($rowOutbound / $qualityOutboundBase) * 100) : 0;

      echo '<tr>';
      echo '<td class="lm-col-quality">' . esc_html($qualityLabel) . '</td>';
      echo '<td class="lm-col-count" style="text-align:center;">' . esc_html((string)$rowTotal) . '</td>';
      echo '<td class="lm-col-count" style="text-align:center;">' . esc_html(number_format((float)$pctTotal, 1)) . '%</td>';
      echo '<td class="lm-col-count" style="text-align:center;">' . esc_html((string)$rowInlink) . '</td>';
      echo '<td class="lm-col-count" style="text-align:center;">' . esc_html(number_format((float)$pctInlink, 1)) . '%</td>';
      echo '<td class="lm-col-count" style="text-align:center;">' . esc_html((string)$rowOutbound) . '</td>';
      echo '<td class="lm-col-count" style="text-align:center;">' . esc_html(number_format((float)$pctOutbound, 1)) . '%</td>';
      echo '</tr>';
    }

    echo '</tbody></table></div>';
    echo '</div>';

    $this->render_admin_section_intro(
      __('Anchor Text Results', 'links-manager'),
      __('Review anchor usage totals, source coverage, destination variety, and quality for each phrase.', 'links-manager')
    );
    $this->render_all_anchor_text_pagination($filters, $filters['paged'], $totalPages, $total, $perPage);
    echo '<div class="lm-table-wrap">';
    echo '<table class="widefat striped lm-table">';
    echo '<thead><tr>';
    echo $this->table_header_with_tooltip('lm-col-postid', '#', 'Row number in current result page.', 'left');
    echo $this->table_header_with_tooltip('lm-col-anchor', 'Anchor Text', 'Visible anchor text used in links.');
    echo $this->table_header_with_tooltip('lm-col-quality', 'Quality', 'Anchor quality based on weak phrase and length rules.');
    echo $this->table_header_with_tooltip('lm-col-count', 'Total', 'Total number of uses for this anchor text.');
    echo $this->table_header_with_tooltip('lm-col-count', 'Inlink', 'Count where this anchor points to internal URLs.');
    echo $this->table_header_with_tooltip('lm-col-count', 'Outbound', 'Count where this anchor points to external URLs.');
    echo $this->table_header_with_tooltip('lm-col-count', 'Unique Source Pages', 'Number of unique source pages using this anchor.');
    echo $this->table_header_with_tooltip('lm-col-count', 'Unique Destination URLs', 'Number of unique destination URLs linked by this anchor.');
    echo $this->table_header_with_tooltip('lm-col-source', 'Source Types', 'Where links were found: content, excerpt, meta, or menu.');
    echo $this->table_header_with_tooltip('lm-col-type', 'Usage Type', 'Mixed, Inlink Only, or Outbound Only.', 'right');
    echo '</tr></thead><tbody>';

    if (empty($pageRows)) {
      echo '<tr><td colspan="10">No data.</td></tr>';
    } else {
      $rowNo = $offset + 1;
      foreach ($pageRows as $row) {
        $qualityLabel = 'Good';
        if ($row['quality'] === 'poor') $qualityLabel = 'Poor';
        if ($row['quality'] === 'bad') $qualityLabel = 'Bad';
        $usageLabel = 'Mixed';
        if ($row['usage_type'] === 'inlink_only') $usageLabel = 'Inlink Only';
        if ($row['usage_type'] === 'outbound_only') $usageLabel = 'Outbound Only';
        $anchorText = $this->normalize_anchor_text_value((string)($row['anchor_text'] ?? ''), true);
        $anchorDisplay = $anchorText === '' ? '(Empty anchor text)' : $anchorText;

        echo '<tr>';
        echo '<td class="lm-col-postid">' . esc_html((string)$rowNo) . '</td>';
        echo '<td class="lm-col-anchor"><span class="lm-trunc" title="' . esc_attr($anchorDisplay) . '">' . esc_html($anchorDisplay) . '</span></td>';
        echo '<td class="lm-col-quality">' . esc_html($qualityLabel) . '</td>';
        echo '<td class="lm-col-count" style="text-align:center;">' . esc_html((string)$row['total']) . '</td>';
        echo '<td class="lm-col-count" style="text-align:center;">' . esc_html((string)$row['inlink']) . '</td>';
        echo '<td class="lm-col-count" style="text-align:center;">' . esc_html((string)$row['outbound']) . '</td>';
        echo '<td class="lm-col-count" style="text-align:center;">' . esc_html((string)$row['source_pages']) . '</td>';
        echo '<td class="lm-col-count" style="text-align:center;">' . esc_html((string)$row['destinations']) . '</td>';
        echo '<td class="lm-col-source"><span class="lm-trunc" title="' . esc_attr((string)$row['source_types']) . '">' . esc_html((string)$row['source_types']) . '</span></td>';
        echo '<td class="lm-col-type">' . esc_html($usageLabel) . '</td>';
        echo '</tr>';

        $rowNo++;
      }
    }

    echo '</tbody></table></div>';
    $this->render_all_anchor_text_pagination($filters, $filters['paged'], $totalPages, $total, $perPage);
    echo '</div>';
  }
}
