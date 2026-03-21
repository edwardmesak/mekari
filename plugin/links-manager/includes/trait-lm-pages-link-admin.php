<?php
/**
 * Pages Link admin page rendering helpers.
 */

if (!defined('ABSPATH')) {
  exit;
}

trait LM_Pages_Link_Admin_Trait {
  public function render_admin_pages_link_page() {
    if (!$this->current_user_can_access_plugin()) wp_die($this->unauthorized_message());

    $filters = $this->get_pages_link_filters_from_request();
    $msg = $this->request_text('lm_msg', '');
    $msgClass = $this->notice_class_for_message($msg, 'info');

    $exportUrl = $this->build_pages_link_export_url($filters);
    $orphanPostTypes = $this->get_filterable_post_types();
    $postCategoryOptions = $this->get_post_term_options('category');
    $postTagOptions = $this->get_post_term_options('post_tag');
    try {
      $pages = null;
      $pagedResult = null;
      if (
        !$filters['rebuild']
        && $this->is_indexed_datastore_ready()
        && $this->indexed_dataset_has_rows($filters['post_type'], isset($filters['wpml_lang']) ? $filters['wpml_lang'] : 'all')
        && $this->can_use_indexed_pages_link_summary_fastpath($filters)
      ) {
        if ($this->can_use_indexed_pages_link_paged_fastpath($filters)) {
          $pagedResult = $this->get_pages_link_paged_result_from_indexed_summary($filters);
          if (is_array($pagedResult)) {
            $pages = isset($pagedResult['pages']) && is_array($pagedResult['pages']) ? $pagedResult['pages'] : [];
          }
        }
        if (!is_array($pagedResult)) {
          $pages = $this->get_pages_with_inbound_counts_from_indexed_summary($filters);
          if (is_array($pages) && empty($pages)) {
            $pages = null;
          }
        }
      }
      if (!is_array($pages)) {
        $all = $this->get_canonical_rows_for_scope($filters['post_type'], $filters['rebuild'], isset($filters['wpml_lang']) ? $filters['wpml_lang'] : 'all', $filters);
        $this->compact_rows_for_pages_link($all);
        $pages = $this->get_pages_with_inbound_counts($all, $filters, false);
      }
    } catch (Throwable $e) {
      $pagedResult = null;
      $pages = [];
      if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('LM Pages Link error.');
      }
    }

    if (is_array($pagedResult)) {
      $total = (int)($pagedResult['total'] ?? 0);
      $perPage = (int)($pagedResult['per_page'] ?? $filters['per_page']);
      $paged = (int)($pagedResult['paged'] ?? $filters['paged']);
      $totalPages = (int)($pagedResult['total_pages'] ?? max(1, (int)ceil(max(0, $total) / max(1, $perPage))));
      $pageRows = $pages;
    } else {
      $total = count($pages);
      $perPage = $filters['per_page'];
      $paged = $filters['paged'];
      $totalPages = max(1, (int)ceil($total / $perPage));
      if ($paged > $totalPages) $paged = $totalPages;
      $offset = ($paged - 1) * $perPage;
      $pageRows = array_slice($pages, $offset, $perPage);
    }
    foreach ($pageRows as &$pageRow) {
      if ((string)($pageRow['page_url'] ?? '') === '') {
        $pageRow['page_url'] = (string)get_permalink((int)($pageRow['post_id'] ?? 0));
      }
    }
    unset($pageRow);

    $statusSummary = is_array($pagedResult) && isset($pagedResult['status_summary']) && is_array($pagedResult['status_summary'])
      ? $pagedResult['status_summary']
      : [
        'orphan' => 0,
        'low' => 0,
        'standard' => 0,
        'excellent' => 0,
      ];
    if (!is_array($pagedResult)) {
      foreach ($pages as $row) {
        $statusKey = $this->inbound_status_key(isset($row['inbound']) ? (int)$row['inbound'] : 0);
        if (isset($statusSummary[$statusKey])) {
          $statusSummary[$statusKey]++;
        }
      }
    }
    $summaryRows = [
      ['key' => 'orphan', 'label' => 'Orphaned'],
      ['key' => 'low', 'label' => 'Low'],
      ['key' => 'standard', 'label' => 'Standard'],
      ['key' => 'excellent', 'label' => 'Excellent'],
    ];

    $outboundSummaryRows = [
      ['key' => 'none', 'label' => 'None'],
      ['key' => 'low', 'label' => 'Low'],
      ['key' => 'optimal', 'label' => 'Optimal'],
      ['key' => 'excessive', 'label' => 'Excessive'],
    ];
    $internalOutboundThresholds = $this->get_internal_outbound_status_thresholds();
    $externalOutboundThresholds = $this->get_external_outbound_status_thresholds();
    $internalOutboundRanges = $this->get_four_level_status_ranges_text($internalOutboundThresholds);
    $externalOutboundRanges = $this->get_four_level_status_ranges_text($externalOutboundThresholds);
    $internalOutboundSummary = is_array($pagedResult) && isset($pagedResult['internal_outbound_summary']) && is_array($pagedResult['internal_outbound_summary'])
      ? $pagedResult['internal_outbound_summary']
      : [
        'none' => 0,
        'low' => 0,
        'optimal' => 0,
        'excessive' => 0,
      ];
    $externalOutboundSummary = is_array($pagedResult) && isset($pagedResult['external_outbound_summary']) && is_array($pagedResult['external_outbound_summary'])
      ? $pagedResult['external_outbound_summary']
      : [
        'none' => 0,
        'low' => 0,
        'optimal' => 0,
        'excessive' => 0,
      ];
    if (!is_array($pagedResult)) {
      foreach ($pages as $row) {
        $internalStatusKey = $this->four_level_status_key(isset($row['internal_outbound']) ? (int)$row['internal_outbound'] : 0, $internalOutboundThresholds);
        $externalStatusKey = $this->four_level_status_key(isset($row['outbound']) ? (int)$row['outbound'] : 0, $externalOutboundThresholds);
        if (isset($internalOutboundSummary[$internalStatusKey])) {
          $internalOutboundSummary[$internalStatusKey]++;
        }
        if (isset($externalOutboundSummary[$externalStatusKey])) {
          $externalOutboundSummary[$externalStatusKey]++;
        }
      }
    }

    echo '<div class="wrap lm-wrap">';
    $this->render_admin_page_header(
      __('Links Manager - Pages Link', 'links-manager'),
      __('Track inbound and outbound link coverage per page with a friendlier layout and filters that remain fast to scan.', 'links-manager')
    );

    if ($msg !== '') echo '<div class="notice notice-' . esc_attr($msgClass) . '"><p>' . esc_html($msg) . '</p></div>';

    echo '<div class="lm-grid">';
    echo '<div class="lm-card lm-card-full lm-card-grouping">';
    $this->render_admin_section_intro(
      __('Filter', 'links-manager'),
      __('Filter by content type, author, dates, and link characteristics to focus on the pages that need attention.', 'links-manager')
    );
    echo '<form method="get" action="">';
    echo '<input type="hidden" name="page" value="links-manager-pages-link"/>';

    echo '<table class="form-table lm-filter-table" role="presentation"><tbody>';

    echo '<tr><th scope="row">Post Type</th><td><select name="lm_pages_link_post_type">';
    echo '<option value="any"' . selected($filters['post_type'], 'any', false) . '>All</option>';
    foreach ($orphanPostTypes as $k => $label) {
      echo '<option value="' . esc_attr($k) . '"' . selected($filters['post_type'], $k, false) . '>' . esc_html($label) . ' (' . esc_html($k) . ')</option>';
    }
    echo '</select></td></tr>';

    echo '<tr><th scope="row">Post Category</th><td><select name="lm_pages_link_post_category">';
    echo '<option value="0"' . selected((int)($filters['post_category'] ?? 0), 0, false) . '>All</option>';
    foreach ($postCategoryOptions as $termId => $termLabel) {
      echo '<option value="' . esc_attr((string)$termId) . '"' . selected((int)($filters['post_category'] ?? 0), (int)$termId, false) . '>' . esc_html($termLabel) . '</option>';
    }
    echo '</select><div class="lm-small">Applied to WordPress posts only.</div></td></tr>';

    echo '<tr><th scope="row">Post Tag</th><td><select name="lm_pages_link_post_tag">';
    echo '<option value="0"' . selected((int)($filters['post_tag'] ?? 0), 0, false) . '>All</option>';
    foreach ($postTagOptions as $termId => $termLabel) {
      echo '<option value="' . esc_attr((string)$termId) . '"' . selected((int)($filters['post_tag'] ?? 0), (int)$termId, false) . '>' . esc_html($termLabel) . '</option>';
    }
    echo '</select><div class="lm-small">Applied to WordPress posts only.</div></td></tr>';

    echo '<tr><th scope="row">Author</th><td><select name="lm_pages_link_author">';
    echo '<option value="0"' . selected($filters['author'], 0, false) . '>All</option>';
    $authors = get_users(['who' => 'authors']);
    foreach ($authors as $u) {
      echo '<option value="' . esc_attr((string)$u->ID) . '"' . selected($filters['author'], $u->ID, false) . '>' . esc_html($u->display_name) . '</option>';
    }
    echo '</select></td></tr>';

    echo '<tr><th scope="row">' . esc_html__('Search Title', 'links-manager') . '</th><td>';
    echo '<input type="text" name="lm_pages_link_search" value="' . esc_attr($filters['search']) . '" class="regular-text" placeholder="page title..."/>';
    echo '</td></tr>';

    echo '<tr><th scope="row">' . esc_html__('Search URL', 'links-manager') . '</th><td>';
    echo '<input type="text" name="lm_pages_link_search_url" value="' . esc_attr(isset($filters['search_url']) ? $filters['search_url'] : '') . '" class="regular-text" placeholder="example.com/path..."/>';
    echo '</td></tr>';

    echo '<tr><th scope="row">Published Date Range</th><td>';
    echo '<input type="date" name="lm_pages_link_date_from" value="' . esc_attr(isset($filters['date_from']) ? (string)$filters['date_from'] : '') . '" /> ';
    echo '<input type="date" name="lm_pages_link_date_to" value="' . esc_attr(isset($filters['date_to']) ? (string)$filters['date_to'] : '') . '" />';
    echo '</td></tr>';

    echo '<tr><th scope="row">Updated Date Range</th><td>';
    echo '<input type="date" name="lm_pages_link_updated_date_from" value="' . esc_attr(isset($filters['updated_date_from']) ? (string)$filters['updated_date_from'] : '') . '" /> ';
    echo '<input type="date" name="lm_pages_link_updated_date_to" value="' . esc_attr(isset($filters['updated_date_to']) ? (string)$filters['updated_date_to'] : '') . '" />';
    echo '</td></tr>';

    echo '<tr><th scope="row">Text Search Mode</th><td><select name="lm_pages_link_search_mode">';
    foreach ($this->get_text_match_modes() as $modeKey => $modeLabel) {
      echo '<option value="' . esc_attr($modeKey) . '"' . selected(isset($filters['search_mode']) ? $filters['search_mode'] : 'contains', $modeKey, false) . '>' . esc_html($modeLabel) . '</option>';
    }
    echo '</select><div class="lm-small">Applies to Search Title and Search URL.</div></td></tr>';

    echo '<tr><th scope="row">Link Location</th><td>';
    echo '<input type="text" name="lm_pages_link_location" value="' . esc_attr((isset($filters['location']) && $filters['location'] !== 'any') ? (string)$filters['location'] : '') . '" class="regular-text" placeholder="content / excerpt / meta:xxx" />';
    echo '<div class="lm-small">Leave empty for All.</div>';
    echo '</td></tr>';

    echo '<tr><th scope="row">Source Type</th><td><select name="lm_pages_link_source_type">';
    foreach ($this->get_filterable_source_type_options(true) as $sourceKey => $sourceLabel) {
      echo '<option value="' . esc_attr((string)$sourceKey) . '"' . selected(isset($filters['source_type']) ? $filters['source_type'] : 'any', (string)$sourceKey, false) . '>' . esc_html((string)$sourceLabel) . '</option>';
    }
    echo '</select></td></tr>';

    echo '<tr><th scope="row">Link Type</th><td><select name="lm_pages_link_link_type">';
    echo '<option value="any"' . selected(isset($filters['link_type']) ? $filters['link_type'] : 'any', 'any', false) . '>All</option>';
    echo '<option value="inlink"' . selected(isset($filters['link_type']) ? $filters['link_type'] : 'any', 'inlink', false) . '>Internal</option>';
    echo '<option value="exlink"' . selected(isset($filters['link_type']) ? $filters['link_type'] : 'any', 'exlink', false) . '>External</option>';
    echo '</select></td></tr>';

    echo '<tr><th scope="row">' . esc_html__('Search Destination URL', 'links-manager') . '</th><td>';
    echo '<input type="text" name="lm_pages_link_value" value="' . esc_attr(isset($filters['value_contains']) ? (string)$filters['value_contains'] : '') . '" class="regular-text" placeholder="example.com / /contact"/>';
    echo '</td></tr>';

    echo '<tr><th scope="row">SEO Flags</th><td><select name="lm_pages_link_seo_flag">';
    echo '<option value="any"' . selected(isset($filters['seo_flag']) ? $filters['seo_flag'] : 'any', 'any', false) . '>All</option>';
    echo '<option value="dofollow"' . selected(isset($filters['seo_flag']) ? $filters['seo_flag'] : 'any', 'dofollow', false) . '>Dofollow</option>';
    echo '<option value="nofollow"' . selected(isset($filters['seo_flag']) ? $filters['seo_flag'] : 'any', 'nofollow', false) . '>Nofollow</option>';
    echo '<option value="sponsored"' . selected(isset($filters['seo_flag']) ? $filters['seo_flag'] : 'any', 'sponsored', false) . '>Sponsored</option>';
    echo '<option value="ugc"' . selected(isset($filters['seo_flag']) ? $filters['seo_flag'] : 'any', 'ugc', false) . '>UGC</option>';
    echo '</select></td></tr>';

    echo '<tr><th scope="row">' . esc_html__('Order By', 'links-manager') . '</th><td>';
    echo '<select name="lm_pages_link_orderby">';
    echo '<option value="post_id"' . selected($filters['orderby'], 'post_id', false) . '>Post ID</option>';
    echo '<option value="date"' . selected($filters['orderby'], 'date', false) . '>Date</option>';
    echo '<option value="modified"' . selected($filters['orderby'], 'modified', false) . '>Updated Date</option>';
    echo '<option value="title"' . selected($filters['orderby'], 'title', false) . '>Title</option>';
    echo '<option value="post_type"' . selected($filters['orderby'], 'post_type', false) . '>Post Type</option>';
    echo '<option value="author"' . selected($filters['orderby'], 'author', false) . '>Author</option>';
    echo '<option value="page_url"' . selected($filters['orderby'], 'page_url', false) . '>Page URL</option>';
    echo '<option value="inbound"' . selected($filters['orderby'], 'inbound', false) . '>Internal Inbound</option>';
    echo '<option value="internal_outbound"' . selected($filters['orderby'], 'internal_outbound', false) . '>Internal Outbound</option>';
    echo '<option value="internal_outbound_status"' . selected($filters['orderby'], 'internal_outbound_status', false) . '>Internal Outbound Status</option>';
    echo '<option value="outbound"' . selected($filters['orderby'], 'outbound', false) . '>External Outbound</option>';
    echo '<option value="external_outbound_status"' . selected($filters['orderby'], 'external_outbound_status', false) . '>External Outbound Status</option>';
    echo '<option value="status"' . selected($filters['orderby'], 'status', false) . '>Internal Inbound Status</option>';
    echo '</select> ';
    echo '<select name="lm_pages_link_order">';
    echo '<option value="DESC"' . selected($filters['order'], 'DESC', false) . '>DESC</option>';
    echo '<option value="ASC"' . selected($filters['order'], 'ASC', false) . '>ASC</option>';
    echo '</select>';
    echo '</td></tr>';

    echo '<tr><th scope="row">Internal Inbound Min/Max</th><td>';
    $inMin = $filters['inbound_min'] < 0 ? '' : (string)$filters['inbound_min'];
    $inMax = $filters['inbound_max'] < 0 ? '' : (string)$filters['inbound_max'];
    echo '<input type="number" name="lm_pages_link_inbound_min" value="' . esc_attr($inMin) . '" placeholder="min" style="width:90px;" /> ';
    echo '<input type="number" name="lm_pages_link_inbound_max" value="' . esc_attr($inMax) . '" placeholder="max" style="width:90px;" />';
    echo '</td></tr>';

    echo '<tr><th scope="row">Internal Outbound Min/Max</th><td>';
    $ioMin = $filters['internal_outbound_min'] < 0 ? '' : (string)$filters['internal_outbound_min'];
    $ioMax = $filters['internal_outbound_max'] < 0 ? '' : (string)$filters['internal_outbound_max'];
    echo '<input type="number" name="lm_pages_link_internal_outbound_min" value="' . esc_attr($ioMin) . '" placeholder="min" style="width:90px;" /> ';
    echo '<input type="number" name="lm_pages_link_internal_outbound_max" value="' . esc_attr($ioMax) . '" placeholder="max" style="width:90px;" />';
    echo '</td></tr>';

    echo '<tr><th scope="row">External Outbound Min/Max</th><td>';
    $outMin = $filters['outbound_min'] < 0 ? '' : (string)$filters['outbound_min'];
    $outMax = $filters['outbound_max'] < 0 ? '' : (string)$filters['outbound_max'];
    echo '<input type="number" name="lm_pages_link_outbound_min" value="' . esc_attr($outMin) . '" placeholder="min" style="width:90px;" /> ';
    echo '<input type="number" name="lm_pages_link_outbound_max" value="' . esc_attr($outMax) . '" placeholder="max" style="width:90px;" />';
    echo '</td></tr>';

    echo '<tr><th scope="row">Internal Inbound Status</th><td>';
    echo '<select name="lm_pages_link_status">';
    echo '<option value="any"' . selected($filters['status'], 'any', false) . '>All</option>';
    echo '<option value="orphan"' . selected($filters['status'], 'orphan', false) . '>Orphaned</option>';
    echo '<option value="low"' . selected($filters['status'], 'low', false) . '>Low</option>';
    echo '<option value="standard"' . selected($filters['status'], 'standard', false) . '>Standard</option>';
    echo '<option value="excellent"' . selected($filters['status'], 'excellent', false) . '>Excellent</option>';
    echo '</select>';
    echo '</td></tr>';

    echo '<tr><th scope="row">Internal Outbound Status</th><td>';
    echo '<select name="lm_pages_link_internal_outbound_status">';
    echo '<option value="any"' . selected(isset($filters['internal_outbound_status']) ? $filters['internal_outbound_status'] : 'any', 'any', false) . '>All</option>';
    echo '<option value="none"' . selected(isset($filters['internal_outbound_status']) ? $filters['internal_outbound_status'] : 'any', 'none', false) . '>None</option>';
    echo '<option value="low"' . selected(isset($filters['internal_outbound_status']) ? $filters['internal_outbound_status'] : 'any', 'low', false) . '>Low</option>';
    echo '<option value="optimal"' . selected(isset($filters['internal_outbound_status']) ? $filters['internal_outbound_status'] : 'any', 'optimal', false) . '>Optimal</option>';
    echo '<option value="excessive"' . selected(isset($filters['internal_outbound_status']) ? $filters['internal_outbound_status'] : 'any', 'excessive', false) . '>Excessive</option>';
    echo '</select>';
    echo '</td></tr>';

    echo '<tr><th scope="row">External Outbound Status</th><td>';
    echo '<select name="lm_pages_link_external_outbound_status">';
    echo '<option value="any"' . selected(isset($filters['external_outbound_status']) ? $filters['external_outbound_status'] : 'any', 'any', false) . '>All</option>';
    echo '<option value="none"' . selected(isset($filters['external_outbound_status']) ? $filters['external_outbound_status'] : 'any', 'none', false) . '>None</option>';
    echo '<option value="low"' . selected(isset($filters['external_outbound_status']) ? $filters['external_outbound_status'] : 'any', 'low', false) . '>Low</option>';
    echo '<option value="optimal"' . selected(isset($filters['external_outbound_status']) ? $filters['external_outbound_status'] : 'any', 'optimal', false) . '>Optimal</option>';
    echo '<option value="excessive"' . selected(isset($filters['external_outbound_status']) ? $filters['external_outbound_status'] : 'any', 'excessive', false) . '>Excessive</option>';
    echo '</select>';
    echo '</td></tr>';

    echo '<tr><th scope="row">' . esc_html__('Cache', 'links-manager') . '</th><td>';
    echo '<label><input type="checkbox" name="lm_pages_link_rebuild" value="1"' . checked($filters['rebuild'] ? '1' : '0', '1', false) . '> ' . esc_html__('Rebuild cache', 'links-manager') . '</label>';
    echo '</td></tr>';

    echo '<tr><th scope="row">' . esc_html__('Per Page', 'links-manager') . '</th><td>';
    echo '<input type="number" name="lm_pages_link_per_page" value="' . esc_attr((string)$perPage) . '" min="10" max="500" />';
    echo '</td></tr>';

    echo '<tr><th scope="row">Export</th><td>';
    echo '<a class="button button-secondary" href="' . esc_url($exportUrl) . '">' . esc_html__('Export CSV', 'links-manager') . '</a>';
    echo '<div class="lm-small">' . esc_html__('Export includes all filtered results.', 'links-manager') . '</div>';
    echo '</td></tr>';

    echo '</tbody></table>';
    echo '<div class="lm-filter-actions">';
    submit_button(__('Apply Filters', 'links-manager'), 'primary', 'submit', false);
    echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=links-manager-pages-link')) . '">' . esc_html__('Reset Filter', 'links-manager') . '</a>';
    echo '</div>';
    echo '</form>';
    echo '</div>';

    $statusThresholds = $this->get_inbound_status_thresholds();
    $orphanMax = (int)$statusThresholds['orphan_max'];
    $lowMax = (int)$statusThresholds['low_max'];
    $standardMax = (int)$statusThresholds['standard_max'];
    $lowMin = $orphanMax + 1;
    $standardMin = $lowMax + 1;
    $excellentMin = $standardMax + 1;
    $orphanLabel = ($orphanMax === 0) ? '0' : ('0-' . $orphanMax);
    $lowLabel = ($lowMin <= $lowMax) ? ($lowMin . '-' . $lowMax) : (string)$lowMax;
    $standardLabel = ($standardMin <= $standardMax) ? ($standardMin . '-' . $standardMax) : (string)$standardMax;
    echo '</div>'; // grid

    echo '<div class="lm-card lm-card-full">';
    $this->render_admin_section_intro(
      __('Internal Inbound Status Summary', 'links-manager'),
      __('See how many published pages receive links from other internal published pages.', 'links-manager')
    );
    echo '<div class="lm-small" style="margin-bottom:6px;">Status reference: Orphaned = ' . esc_html((string)$orphanLabel) . ', Low = ' . esc_html((string)$lowLabel) . ', Standard = ' . esc_html((string)$standardLabel) . ', Excellent = ' . esc_html((string)$excellentMin) . '+.</div>';
    echo '<div class="lm-table-wrap lm-summary-table-wrap">';
    echo '<table class="widefat striped lm-table">';
    echo '<thead><tr>';
    echo '<th>#</th>';
    echo '<th>Status</th>';
    echo '<th>Total Page URLs</th>';
    echo '<th>%</th>';
    echo '</tr></thead><tbody>';
    $summaryRowNo = 1;
    foreach ($summaryRows as $summaryRow) {
      $summaryKey = (string)$summaryRow['key'];
      $summaryLabel = (string)$summaryRow['label'];
      $summaryCount = isset($statusSummary[$summaryKey]) ? (int)$statusSummary[$summaryKey] : 0;
      $summaryPercent = $total > 0 ? round(($summaryCount / $total) * 100, 1) : 0;
      $summaryFilterUrl = $this->pages_link_admin_url($filters, [
        'lm_pages_link_status' => $summaryKey,
        'lm_pages_link_paged' => 1,
      ]);

      echo '<tr>';
      echo '<td style="text-align:center;">' . esc_html((string)$summaryRowNo) . '</td>';
      echo '<td>' . esc_html($summaryLabel) . '</td>';
      echo '<td style="text-align:center;"><a href="' . esc_url($summaryFilterUrl) . '">' . esc_html((string)$summaryCount) . '</a></td>';
      echo '<td style="text-align:center;">' . esc_html((string)$summaryPercent) . '%</td>';
      echo '</tr>';
      $summaryRowNo++;
    }
    echo '</tbody></table></div>';
    echo '</div>';

    echo '<div class="lm-card lm-card-full">';
    $this->render_admin_section_intro(
      __('Internal Outbound Status Summary', 'links-manager'),
      __('See how many published pages link to other internal published pages.', 'links-manager')
    );
    echo '<div class="lm-small" style="margin-bottom:8px;">Status reference: None = ' . esc_html((string)$internalOutboundRanges['none']) . ', Low = ' . esc_html((string)$internalOutboundRanges['low']) . ', Optimal = ' . esc_html((string)$internalOutboundRanges['optimal']) . ', Excessive = ' . esc_html((string)$internalOutboundRanges['excessive']) . '.</div>';
    echo '<div class="lm-table-wrap lm-summary-table-wrap">';
    echo '<table class="widefat striped lm-table">';
    echo '<thead><tr>';
    echo '<th>#</th>';
    echo '<th>Status</th>';
    echo '<th>Total Page URLs</th>';
    echo '<th>%</th>';
    echo '</tr></thead><tbody>';
    $internalSummaryRowNo = 1;
    foreach ($outboundSummaryRows as $summaryRow) {
      $summaryKey = (string)$summaryRow['key'];
      $summaryLabel = (string)$summaryRow['label'];
      $summaryCount = isset($internalOutboundSummary[$summaryKey]) ? (int)$internalOutboundSummary[$summaryKey] : 0;
      $summaryPercent = $total > 0 ? round(($summaryCount / $total) * 100, 1) : 0;
      $summaryFilterUrl = $this->pages_link_admin_url($filters, [
        'lm_pages_link_internal_outbound_status' => $summaryKey,
        'lm_pages_link_external_outbound_status' => isset($filters['external_outbound_status']) ? $filters['external_outbound_status'] : 'any',
        'lm_pages_link_paged' => 1,
      ]);

      echo '<tr>';
      echo '<td style="text-align:center;">' . esc_html((string)$internalSummaryRowNo) . '</td>';
      echo '<td>' . esc_html($summaryLabel) . '</td>';
      echo '<td style="text-align:center;"><a href="' . esc_url($summaryFilterUrl) . '">' . esc_html((string)$summaryCount) . '</a></td>';
      echo '<td style="text-align:center;">' . esc_html((string)$summaryPercent) . '%</td>';
      echo '</tr>';
      $internalSummaryRowNo++;
    }
    echo '</tbody></table></div>';
    echo '</div>';

    echo '<div class="lm-card lm-card-full">';
    $this->render_admin_section_intro(
      __('External Outbound Status Summary', 'links-manager'),
      __('See how many published pages link to pages on external websites.', 'links-manager')
    );
    echo '<div class="lm-small" style="margin-bottom:8px;">Status reference: None = ' . esc_html((string)$externalOutboundRanges['none']) . ', Low = ' . esc_html((string)$externalOutboundRanges['low']) . ', Optimal = ' . esc_html((string)$externalOutboundRanges['optimal']) . ', Excessive = ' . esc_html((string)$externalOutboundRanges['excessive']) . '.</div>';
    echo '<div class="lm-table-wrap lm-summary-table-wrap">';
    echo '<table class="widefat striped lm-table">';
    echo '<thead><tr>';
    echo '<th>#</th>';
    echo '<th>Status</th>';
    echo '<th>Total Page URLs</th>';
    echo '<th>%</th>';
    echo '</tr></thead><tbody>';
    $externalSummaryRowNo = 1;
    foreach ($outboundSummaryRows as $summaryRow) {
      $summaryKey = (string)$summaryRow['key'];
      $summaryLabel = (string)$summaryRow['label'];
      $summaryCount = isset($externalOutboundSummary[$summaryKey]) ? (int)$externalOutboundSummary[$summaryKey] : 0;
      $summaryPercent = $total > 0 ? round(($summaryCount / $total) * 100, 1) : 0;
      $summaryFilterUrl = $this->pages_link_admin_url($filters, [
        'lm_pages_link_external_outbound_status' => $summaryKey,
        'lm_pages_link_internal_outbound_status' => isset($filters['internal_outbound_status']) ? $filters['internal_outbound_status'] : 'any',
        'lm_pages_link_paged' => 1,
      ]);

      echo '<tr>';
      echo '<td style="text-align:center;">' . esc_html((string)$externalSummaryRowNo) . '</td>';
      echo '<td>' . esc_html($summaryLabel) . '</td>';
      echo '<td style="text-align:center;"><a href="' . esc_url($summaryFilterUrl) . '">' . esc_html((string)$summaryCount) . '</a></td>';
      echo '<td style="text-align:center;">' . esc_html((string)$summaryPercent) . '%</td>';
      echo '</tr>';
      $externalSummaryRowNo++;
    }
    echo '</tbody></table></div>';
    echo '</div>';

    $this->render_admin_section_intro(
      __('Pages Link Results', 'links-manager'),
      __('Review published pages and posts with internal inbound, internal outbound, and external outbound link totals.', 'links-manager')
    );
    echo '<div class="lm-small" style="margin:0 0 10px;">' . esc_html__('Excluded: WordPress menu links, self-links, non-published content links, and unmatched internal destinations.', 'links-manager') . ' <strong>' . esc_html__('Total:', 'links-manager') . '</strong> ' . esc_html((string)$total) . '</div>';
    $this->render_pages_link_pagination($filters, $paged, $totalPages, $total, $perPage);
    echo '<div class="lm-table-wrap">';
    echo '<table class="widefat striped lm-table">';
    echo '<thead><tr>';
    echo $this->table_header_with_tooltip('lm-col-postid', '#', 'Row number in current result page.', 'left');
    echo $this->table_header_with_tooltip('lm-col-postid', 'Post ID', 'WordPress post ID.', 'left');
    echo $this->table_header_with_tooltip('lm-col-title', 'Title', 'Title of the page/post.');
    echo $this->table_header_with_tooltip('lm-col-type', 'Post Type', 'Content type (post, page, custom type).');
    echo $this->table_header_with_tooltip('lm-col-author', 'Author', 'Content author.');
    echo $this->table_header_with_tooltip('lm-col-date', 'Published Date', 'Original publish timestamp.');
    echo $this->table_header_with_tooltip('lm-col-date', 'Updated Date', 'Latest modified timestamp.');
    echo $this->table_header_with_tooltip('lm-col-pageurl', 'Page URL', 'URL of the source page.');
    echo $this->table_header_with_tooltip('lm-col-count', 'Internal Inbound', 'Internal links from other pages pointing to this page.');
    echo $this->table_header_with_tooltip('lm-col-quality', 'Internal Inbound Status', 'Internal inbound health label based on inbound count.');
    echo $this->table_header_with_tooltip('lm-col-count', 'Internal Outbound', 'Internal links going out from this page.');
    echo $this->table_header_with_tooltip('lm-col-quality', 'Internal Outbound Status', 'Status label based on internal outbound count and configured thresholds.');
    echo $this->table_header_with_tooltip('lm-col-outbound', 'External Outbound', 'External links going out from this page.');
    echo $this->table_header_with_tooltip('lm-col-quality', 'External Outbound Status', 'Status label based on external outbound count and configured thresholds.');
    echo $this->table_header_with_tooltip('lm-col-edit', 'Edit', 'Open WordPress editor for this page.', 'right');
    echo '</tr></thead><tbody>';

    echo $this->get_pages_link_results_tbody_html($pageRows, (($paged - 1) * $perPage) + 1);

    echo '</tbody></table></div>';
    $this->render_pages_link_pagination($filters, $paged, $totalPages, $total, $perPage);
    echo '</div>';
  }

  private function get_pages_link_results_tbody_html($pageRows, $rowNumberStart = 1) {
    $pageRows = is_array($pageRows) ? $pageRows : [];
    $rowNumber = max(1, (int)$rowNumberStart);

    ob_start();

    if (empty($pageRows)) {
      echo '<tr><td colspan="15">No data.</td></tr>';
      return (string)ob_get_clean();
    }

    foreach ($pageRows as $row) {
      $post_id = (int)($row['post_id'] ?? 0);
      $inbound = (int)($row['inbound'] ?? 0);
      $outbound = isset($row['outbound']) ? (int)$row['outbound'] : 0;
      $internal_outbound = isset($row['internal_outbound']) ? (int)$row['internal_outbound'] : 0;
      $title = get_the_title($post_id);
      $post = get_post($post_id);
      if (!$post || $post->post_status !== 'publish') {
        continue;
      }

      $author = $post->post_author ? get_the_author_meta('display_name', $post->post_author) : '';
      $date = get_the_date('Y-m-d H:i:s', $post_id);
      $updated = get_the_modified_date('Y-m-d H:i:s', $post_id);
      $ptype = (string)$post->post_type;
      $url = get_permalink($post_id);
      $edit_url = admin_url('post.php?post=' . $post_id . '&action=edit');

      $status = $this->inbound_status($inbound);
      $internalOutboundStatus = $this->four_level_status_label(isset($row['internal_outbound_status']) ? $row['internal_outbound_status'] : 'none');
      $externalOutboundStatus = $this->four_level_status_label(isset($row['external_outbound_status']) ? $row['external_outbound_status'] : 'none');

      echo '<tr>';
      echo '<td class="lm-col-postid">' . esc_html((string)$rowNumber) . '</td>';
      echo '<td class="lm-col-postid">' . esc_html((string)$post_id) . '</td>';
      echo '<td class="lm-col-title"><span class="lm-trunc" title="' . esc_attr((string)$title) . '">' . esc_html((string)$title) . '</span></td>';
      echo '<td class="lm-col-type">' . esc_html($ptype) . '</td>';
      echo '<td class="lm-col-author"><span class="lm-trunc" title="' . esc_attr((string)$author) . '">' . esc_html((string)$author) . '</span></td>';
      echo '<td class="lm-col-date">' . esc_html((string)$date) . '</td>';
      echo '<td class="lm-col-date">' . esc_html((string)$updated) . '</td>';
      echo '<td class="lm-col-pageurl">' . ($url ? '<a href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer"><span class="lm-trunc" title="' . esc_attr($url) . '">' . esc_html($url) . '</span></a>' : '') . '</td>';
      echo '<td class="lm-col-count" style="text-align:center;">' . esc_html((string)$inbound) . '</td>';
      echo '<td class="lm-col-quality">' . esc_html($status) . '</td>';
      echo '<td class="lm-col-count" style="text-align:center;">' . esc_html((string)$internal_outbound) . '</td>';
      echo '<td class="lm-col-quality">' . esc_html((string)$internalOutboundStatus) . '</td>';
      echo '<td class="lm-col-outbound" style="text-align:center;">' . esc_html((string)$outbound) . '</td>';
      echo '<td class="lm-col-quality">' . esc_html((string)$externalOutboundStatus) . '</td>';
      echo '<td class="lm-col-edit"><a class="button button-secondary" href="' . esc_url($edit_url) . '">Edit</a></td>';
      echo '</tr>';
      $rowNumber++;
    }

    $html = (string)ob_get_clean();
    if ($html === '') {
      return '<tr><td colspan="15">No data.</td></tr>';
    }

    return $html;
  }

  private function get_pages_link_summaries_html($pages, $filters) {
    $pages = is_array($pages) ? $pages : [];
    $total = count($pages);

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

    $summaryRows = [
      ['key' => 'orphan', 'label' => 'Orphaned'],
      ['key' => 'low', 'label' => 'Low'],
      ['key' => 'standard', 'label' => 'Standard'],
      ['key' => 'excellent', 'label' => 'Excellent'],
    ];
    $outboundSummaryRows = [
      ['key' => 'none', 'label' => 'None'],
      ['key' => 'low', 'label' => 'Low'],
      ['key' => 'optimal', 'label' => 'Optimal'],
      ['key' => 'excessive', 'label' => 'Excessive'],
    ];

    $internalOutboundThresholds = $this->get_internal_outbound_status_thresholds();
    $externalOutboundThresholds = $this->get_external_outbound_status_thresholds();
    $internalOutboundRanges = $this->get_four_level_status_ranges_text($internalOutboundThresholds);
    $externalOutboundRanges = $this->get_four_level_status_ranges_text($externalOutboundThresholds);

    foreach ($pages as $row) {
      $statusKey = $this->inbound_status_key(isset($row['inbound']) ? (int)$row['inbound'] : 0);
      if (isset($statusSummary[$statusKey])) {
        $statusSummary[$statusKey]++;
      }

      $internalStatusKey = $this->four_level_status_key(isset($row['internal_outbound']) ? (int)$row['internal_outbound'] : 0, $internalOutboundThresholds);
      $externalStatusKey = $this->four_level_status_key(isset($row['outbound']) ? (int)$row['outbound'] : 0, $externalOutboundThresholds);
      if (isset($internalOutboundSummary[$internalStatusKey])) {
        $internalOutboundSummary[$internalStatusKey]++;
      }
      if (isset($externalOutboundSummary[$externalStatusKey])) {
        $externalOutboundSummary[$externalStatusKey]++;
      }
    }

    $statusThresholds = $this->get_inbound_status_thresholds();
    $orphanMax = (int)$statusThresholds['orphan_max'];
    $lowMax = (int)$statusThresholds['low_max'];
    $standardMax = (int)$statusThresholds['standard_max'];
    $lowMin = $orphanMax + 1;
    $standardMin = $lowMax + 1;
    $excellentMin = $standardMax + 1;
    $orphanLabel = ($orphanMax === 0) ? '0' : ('0-' . $orphanMax);
    $lowLabel = ($lowMin <= $lowMax) ? ($lowMin . '-' . $lowMax) : (string)$lowMax;
    $standardLabel = ($standardMin <= $standardMax) ? ($standardMin . '-' . $standardMax) : (string)$standardMax;

    ob_start();

    echo '<div class="lm-card lm-card-full">';
    $this->render_admin_section_intro(
      __('Internal Inbound Status Summary', 'links-manager'),
      __('See how many published pages receive links from other internal published pages.', 'links-manager')
    );
    echo '<div class="lm-small" style="margin-bottom:6px;">Status reference: Orphaned = ' . esc_html((string)$orphanLabel) . ', Low = ' . esc_html((string)$lowLabel) . ', Standard = ' . esc_html((string)$standardLabel) . ', Excellent = ' . esc_html((string)$excellentMin) . '+.</div>';
    echo '<div class="lm-table-wrap lm-summary-table-wrap"><table class="widefat striped lm-table"><thead><tr><th>#</th><th>Status</th><th>Total Page URLs</th><th>%</th></tr></thead><tbody>';
    $summaryRowNo = 1;
    foreach ($summaryRows as $summaryRow) {
      $summaryKey = (string)$summaryRow['key'];
      $summaryCount = isset($statusSummary[$summaryKey]) ? (int)$statusSummary[$summaryKey] : 0;
      $summaryPercent = $total > 0 ? round(($summaryCount / $total) * 100, 1) : 0;
      $summaryFilterUrl = $this->pages_link_admin_url($filters, [
        'lm_pages_link_status' => $summaryKey,
        'lm_pages_link_paged' => 1,
      ]);

      echo '<tr>';
      echo '<td style="text-align:center;">' . esc_html((string)$summaryRowNo) . '</td>';
      echo '<td>' . esc_html((string)$summaryRow['label']) . '</td>';
      echo '<td style="text-align:center;"><a href="' . esc_url($summaryFilterUrl) . '">' . esc_html((string)$summaryCount) . '</a></td>';
      echo '<td style="text-align:center;">' . esc_html((string)$summaryPercent) . '%</td>';
      echo '</tr>';
      $summaryRowNo++;
    }
    echo '</tbody></table></div></div>';

    echo '<div class="lm-card lm-card-full">';
    $this->render_admin_section_intro(
      __('Internal Outbound Status Summary', 'links-manager'),
      __('See how many published pages link to other internal published pages.', 'links-manager')
    );
    echo '<div class="lm-small" style="margin-bottom:8px;">Status reference: None = ' . esc_html((string)$internalOutboundRanges['none']) . ', Low = ' . esc_html((string)$internalOutboundRanges['low']) . ', Optimal = ' . esc_html((string)$internalOutboundRanges['optimal']) . ', Excessive = ' . esc_html((string)$internalOutboundRanges['excessive']) . '.</div>';
    echo '<div class="lm-table-wrap lm-summary-table-wrap"><table class="widefat striped lm-table"><thead><tr><th>#</th><th>Status</th><th>Total Page URLs</th><th>%</th></tr></thead><tbody>';
    $internalSummaryRowNo = 1;
    foreach ($outboundSummaryRows as $summaryRow) {
      $summaryKey = (string)$summaryRow['key'];
      $summaryCount = isset($internalOutboundSummary[$summaryKey]) ? (int)$internalOutboundSummary[$summaryKey] : 0;
      $summaryPercent = $total > 0 ? round(($summaryCount / $total) * 100, 1) : 0;
      $summaryFilterUrl = $this->pages_link_admin_url($filters, [
        'lm_pages_link_internal_outbound_status' => $summaryKey,
        'lm_pages_link_external_outbound_status' => isset($filters['external_outbound_status']) ? $filters['external_outbound_status'] : 'any',
        'lm_pages_link_paged' => 1,
      ]);

      echo '<tr>';
      echo '<td style="text-align:center;">' . esc_html((string)$internalSummaryRowNo) . '</td>';
      echo '<td>' . esc_html((string)$summaryRow['label']) . '</td>';
      echo '<td style="text-align:center;"><a href="' . esc_url($summaryFilterUrl) . '">' . esc_html((string)$summaryCount) . '</a></td>';
      echo '<td style="text-align:center;">' . esc_html((string)$summaryPercent) . '%</td>';
      echo '</tr>';
      $internalSummaryRowNo++;
    }
    echo '</tbody></table></div></div>';

    echo '<div class="lm-card lm-card-full">';
    $this->render_admin_section_intro(
      __('External Outbound Status Summary', 'links-manager'),
      __('See how many published pages link to pages on external websites.', 'links-manager')
    );
    echo '<div class="lm-small" style="margin-bottom:8px;">Status reference: None = ' . esc_html((string)$externalOutboundRanges['none']) . ', Low = ' . esc_html((string)$externalOutboundRanges['low']) . ', Optimal = ' . esc_html((string)$externalOutboundRanges['optimal']) . ', Excessive = ' . esc_html((string)$externalOutboundRanges['excessive']) . '.</div>';
    echo '<div class="lm-table-wrap lm-summary-table-wrap"><table class="widefat striped lm-table"><thead><tr><th>#</th><th>Status</th><th>Total Page URLs</th><th>%</th></tr></thead><tbody>';
    $externalSummaryRowNo = 1;
    foreach ($outboundSummaryRows as $summaryRow) {
      $summaryKey = (string)$summaryRow['key'];
      $summaryCount = isset($externalOutboundSummary[$summaryKey]) ? (int)$externalOutboundSummary[$summaryKey] : 0;
      $summaryPercent = $total > 0 ? round(($summaryCount / $total) * 100, 1) : 0;
      $summaryFilterUrl = $this->pages_link_admin_url($filters, [
        'lm_pages_link_external_outbound_status' => $summaryKey,
        'lm_pages_link_internal_outbound_status' => isset($filters['internal_outbound_status']) ? $filters['internal_outbound_status'] : 'any',
        'lm_pages_link_paged' => 1,
      ]);

      echo '<tr>';
      echo '<td style="text-align:center;">' . esc_html((string)$externalSummaryRowNo) . '</td>';
      echo '<td>' . esc_html((string)$summaryRow['label']) . '</td>';
      echo '<td style="text-align:center;"><a href="' . esc_url($summaryFilterUrl) . '">' . esc_html((string)$summaryCount) . '</a></td>';
      echo '<td style="text-align:center;">' . esc_html((string)$summaryPercent) . '%</td>';
      echo '</tr>';
      $externalSummaryRowNo++;
    }
    echo '</tbody></table></div></div>';

    return (string)ob_get_clean();
  }
}
