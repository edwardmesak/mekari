<?php
/**
 * Cited Domains admin page rendering helpers.
 */

if (!defined('ABSPATH')) {
  exit;
}

trait LM_Cited_Domains_Admin_Trait {
  public function render_admin_cited_domains_page() {
    if (!$this->current_user_can_access_plugin()) wp_die($this->unauthorized_message());

    $profileStarted = $this->profile_start();
    $filters = $this->get_cited_domains_filters_from_request();
    $exportUrl = $this->build_cited_domains_export_url($filters);
    $scopePostType = sanitize_key((string)($filters['post_type'] ?? 'any'));
    if ($scopePostType === '') {
      $scopePostType = 'any';
    }
    $scopeWpmlLang = $this->get_requested_view_wpml_lang((string)($filters['wpml_lang'] ?? 'all'));
    $dataNotice = $this->get_report_data_notice($scopePostType, $scopeWpmlLang);
    $postCategoryOptions = $this->get_post_term_options('category');
    $postTagOptions = $this->get_post_term_options('post_tag');
    $authorOptions = $this->get_scan_author_options();

    $rows = [];
    $total = 0;
    $perPage = $filters['per_page'];
    $totalPages = 1;
    $pageRows = [];
    $warningNotice = '';
    $executionMode = 'cited_domains_indexed_paged';
    $guardState = $this->get_indexed_aggregation_guard_state($filters, 'exlink', 'cited_domains_aggregation', 'Cited Domains');
    $runtimeMeta = (array)($guardState['runtime_meta'] ?? []);

    if (!empty($guardState['blocked'])) {
      $warningNotice = (string)($guardState['warning_notice'] ?? '');
      $executionMode = 'cited_domains_aggregation_blocked';
    } else {
      $usedIndexedPagedFastpath = false;
      $indexedPaged = $this->get_indexed_cited_domains_paged_result($filters);
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
        $filters['per_page'] = $perPage;
        $filters['paged'] = max(1, (int)($pagination['paged'] ?? $filters['paged']));
        $totalPages = max(1, (int)($pagination['total_pages'] ?? 1));
      }

      if (!$usedIndexedPagedFastpath) {
        $rows = $this->get_indexed_cited_domains_summary_rows($filters);
        $executionMode = 'cited_domains_indexed_summary';
        if (empty($rows)) {
          $scopeRows = $this->get_report_scope_rows_or_empty('any', isset($filters['wpml_lang']) ? $filters['wpml_lang'] : 'all', $filters, false);
          $rows = $this->build_cited_domains_summary_rows($scopeRows, $filters);
          $executionMode = 'cited_domains_php_summary';
        }
        $total = count($rows);
        $perPage = $filters['per_page'];
        $filters['per_page'] = $perPage;
        $totalPages = max(1, (int)ceil($total / $perPage));
        if ($filters['paged'] > $totalPages) $filters['paged'] = $totalPages;
        $offset = ($filters['paged'] - 1) * $perPage;
        $pageRows = array_slice($rows, $offset, $perPage);
      }
    }
    $offset = ($filters['paged'] - 1) * $perPage;
    $profileMeta = [
      'execution_mode' => $executionMode,
      'estimated_rows' => (int)($guardState['estimated_rows'] ?? 0),
      'total' => (int)$total,
      'warning' => $warningNotice !== '' ? '1' : '0',
    ];
    $this->profile_end('cited_domains_load', $profileStarted, array_merge($profileMeta, $runtimeMeta));

    echo '<div class="wrap lm-wrap">';
    $this->render_admin_page_header(
      __('Links Manager - Cited External Domains', 'links-manager'),
      __('Review the domains most often referenced from your content and refine outbound linking with faster filters.', 'links-manager')
    );
    $this->render_refresh_data_status_banner($scopePostType, $scopeWpmlLang);
    if ($dataNotice !== '') echo '<div class="notice notice-warning"><p>' . esc_html($dataNotice) . '</p></div>';
    if ($warningNotice !== '') echo '<div class="notice notice-warning"><p>' . esc_html($warningNotice) . '</p></div>';

    echo '<div class="lm-card lm-card-full">';
    $this->render_admin_section_intro(
      __('Filter', 'links-manager'),
      __('Narrow the report by post scope, source, SEO flags, and citation thresholds.', 'links-manager')
    );
    echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '" onsubmit="var b=this.querySelector(\'button[type=submit],input[type=submit]\');if(b){b.disabled=true;if(\'value\' in b){b.value=\'Applying...\';}else{b.textContent=\'Applying...\';}}">';
    echo '<input type="hidden" name="page" value="links-manager-cited-domains"/>';
    foreach ($this->get_wpml_admin_lang_url_args() as $langKey => $langValue) {
      echo '<input type="hidden" name="' . esc_attr((string)$langKey) . '" value="' . esc_attr((string)$langValue) . '"/>';
    }
    echo '<input type="hidden" name="lm_cd_paged" value="1"/>';
    echo '<table class="form-table lm-filter-table" role="presentation"><tbody>';
    echo '<tr><th scope="row">Post Type</th><td><select name="lm_cd_post_type">';
    echo '<option value="any"' . selected($filters['post_type'], 'any', false) . '>All</option>';
    foreach ($this->get_filterable_post_types() as $ptKey => $ptLabel) {
      echo '<option value="' . esc_attr((string)$ptKey) . '"' . selected($filters['post_type'], (string)$ptKey, false) . '>' . esc_html((string)$ptLabel) . ' (' . esc_html((string)$ptKey) . ')</option>';
    }
    echo '</select></td></tr>';
    echo '<tr><th scope="row">Post Category</th><td><select name="lm_cd_post_category">';
    echo '<option value="0"' . selected((int)($filters['post_category'] ?? 0), 0, false) . '>All</option>';
    foreach ($postCategoryOptions as $termId => $termLabel) {
      echo '<option value="' . esc_attr((string)$termId) . '"' . selected((int)($filters['post_category'] ?? 0), (int)$termId, false) . '>' . esc_html($termLabel) . '</option>';
    }
    echo '</select><div class="lm-small">Applied to WordPress posts only.</div></td></tr>';
    echo '<tr><th scope="row">Post Tag</th><td><select name="lm_cd_post_tag">';
    echo '<option value="0"' . selected((int)($filters['post_tag'] ?? 0), 0, false) . '>All</option>';
    foreach ($postTagOptions as $termId => $termLabel) {
      echo '<option value="' . esc_attr((string)$termId) . '"' . selected((int)($filters['post_tag'] ?? 0), (int)$termId, false) . '>' . esc_html($termLabel) . '</option>';
    }
    echo '</select><div class="lm-small">Applied to WordPress posts only.</div></td></tr>';
    echo '<tr><th scope="row">Link Location</th><td><input type="text" name="lm_cd_location" value="' . esc_attr($filters['location'] === 'any' ? '' : (string)$filters['location']) . '" class="regular-text" placeholder="content / excerpt / meta:xxx" /><div class="lm-small">Leave empty for All.</div></td></tr>';
    echo '<tr><th scope="row">Source Type</th><td><select name="lm_cd_source_type">';
    foreach ($this->get_filterable_source_type_options(true) as $sourceKey => $sourceLabel) {
      echo '<option value="' . esc_attr((string)$sourceKey) . '"' . selected($filters['source_type'], (string)$sourceKey, false) . '>' . esc_html((string)$sourceLabel) . '</option>';
    }
    echo '</select></td></tr>';
    echo '<tr><th scope="row">' . esc_html__('Search Destination URL', 'links-manager') . '</th><td><input type="text" name="lm_cd_value" value="' . esc_attr($filters['value_contains']) . '" class="regular-text" placeholder="example.com / /contact" /></td></tr>';
    echo '<tr><th scope="row">' . esc_html__('Search Source URL', 'links-manager') . '</th><td><input type="text" name="lm_cd_source" value="' . esc_attr($filters['source_contains']) . '" class="regular-text" placeholder="/category /slug" /></td></tr>';
    echo '<tr><th scope="row">' . esc_html__('Search Title', 'links-manager') . '</th><td><input type="text" name="lm_cd_title" value="' . esc_attr($filters['title_contains']) . '" class="regular-text" placeholder="post title" /></td></tr>';
    echo '<tr><th scope="row">' . esc_html__('Author', 'links-manager') . '</th><td><select name="lm_cd_author">';
    echo '<option value="0"' . selected((int)($filters['author'] ?? 0), 0, false) . '>All</option>';
    foreach ($authorOptions as $authorId => $authorLabel) {
      echo '<option value="' . esc_attr((string)$authorId) . '"' . selected((int)($filters['author'] ?? 0), (int)$authorId, false) . '>' . esc_html((string)$authorLabel) . '</option>';
    }
    echo '</select></td></tr>';
    echo '<tr><th scope="row">' . esc_html__('Search Anchor Text', 'links-manager') . '</th><td><input type="text" name="lm_cd_anchor" value="' . esc_attr($filters['anchor_contains']) . '" class="regular-text" placeholder="anchor keyword" /></td></tr>';
    echo '<tr><th scope="row">' . esc_html__('Search Domain', 'links-manager') . '</th><td><input type="text" name="lm_cd_search" value="' . esc_attr($filters['search']) . '" class="regular-text" placeholder="example.com" /></td></tr>';
    echo '<tr><th scope="row">Text Search Mode</th><td><select name="lm_cd_search_mode">';
    foreach ($this->get_text_match_modes() as $modeKey => $modeLabel) {
      echo '<option value="' . esc_attr($modeKey) . '"' . selected($filters['search_mode'], $modeKey, false) . '>' . esc_html($modeLabel) . '</option>';
    }
    echo '</select><div class="lm-small">Applies to text filters on this page.</div></td></tr>';
    echo '<tr><th scope="row">SEO Flags</th><td><select name="lm_cd_seo_flag">';
    echo '<option value="any"' . selected($filters['seo_flag'], 'any', false) . '>All</option>';
    echo '<option value="dofollow"' . selected($filters['seo_flag'], 'dofollow', false) . '>Dofollow</option>';
    echo '<option value="nofollow"' . selected($filters['seo_flag'], 'nofollow', false) . '>Nofollow</option>';
    echo '<option value="sponsored"' . selected($filters['seo_flag'], 'sponsored', false) . '>Sponsored</option>';
    echo '<option value="ugc"' . selected($filters['seo_flag'], 'ugc', false) . '>UGC</option>';
    echo '</select></td></tr>';
    $minCitesVal = (int)$filters['min_cites'] > 0 ? (string)$filters['min_cites'] : '';
    $minPagesVal = (int)$filters['min_pages'] > 0 ? (string)$filters['min_pages'] : '';
    echo '<tr><th scope="row">Min Cited Count</th><td><input type="number" name="lm_cd_min_cites" min="0" value="' . esc_attr($minCitesVal) . '" style="width:90px;" /></td></tr>';
    echo '<tr><th scope="row">Min Unique Source Pages</th><td><input type="number" name="lm_cd_min_pages" min="0" value="' . esc_attr($minPagesVal) . '" style="width:90px;" /></td></tr>';
    $maxCitesVal = (int)$filters['max_cites'] >= 0 ? (string)$filters['max_cites'] : '';
    $maxPagesVal = (int)$filters['max_pages'] >= 0 ? (string)$filters['max_pages'] : '';
    echo '<tr><th scope="row">Max Cited Count</th><td><input type="number" name="lm_cd_max_cites" min="0" value="' . esc_attr($maxCitesVal) . '" style="width:90px;" /></td></tr>';
    echo '<tr><th scope="row">Max Unique Source Pages</th><td><input type="number" name="lm_cd_max_pages" min="0" value="' . esc_attr($maxPagesVal) . '" style="width:90px;" /></td></tr>';
    echo '<tr><th scope="row">' . esc_html__('Order By', 'links-manager') . '</th><td>';
    echo '<select name="lm_cd_orderby">';
    echo '<option value="cites"' . selected($filters['orderby'], 'cites', false) . '>Cited Count</option>';
    echo '<option value="pages"' . selected($filters['orderby'], 'pages', false) . '>Unique Source Pages</option>';
    echo '<option value="domain"' . selected($filters['orderby'], 'domain', false) . '>Domain</option>';
    echo '<option value="sample_url"' . selected($filters['orderby'], 'sample_url', false) . '>Sample URL</option>';
    echo '</select> ';
    echo '<select name="lm_cd_order">';
    echo '<option value="DESC"' . selected($filters['order'], 'DESC', false) . '>DESC</option>';
    echo '<option value="ASC"' . selected($filters['order'], 'ASC', false) . '>ASC</option>';
    echo '</select>';
    echo '</td></tr>';
    echo '<tr><th scope="row">' . esc_html__('Per Page', 'links-manager') . '</th><td><input type="number" name="lm_cd_per_page" min="10" max="500" value="' . esc_attr((string)$filters['per_page']) . '" style="width:90px;" /></td></tr>';
    echo '<tr><th scope="row">' . esc_html__('Export', 'links-manager') . '</th><td><a class="button button-secondary" href="' . esc_url($exportUrl) . '">' . esc_html__('Export CSV', 'links-manager') . '</a><div class="lm-small">' . esc_html__('Export includes all filtered results and keeps source page + anchor text.', 'links-manager') . '</div></td></tr>';
    echo '</tbody></table>';
    echo '<div class="lm-filter-actions">';
    submit_button(__('Apply Filters', 'links-manager'), 'primary', 'submit', false);
    echo '<a class="button" href="' . esc_url($this->admin_page_url('links-manager-cited-domains')) . '">' . esc_html__('Reset Filter', 'links-manager') . '</a>';
    echo '</div>';
    echo '</form>';
    echo '</div>';

    $this->render_admin_section_intro(
      __('Cited External Domains', 'links-manager'),
      __('Review which external domains are referenced most often across the current filtered content set.', 'links-manager')
    );
    echo '<div class="lm-small" style="margin:0 0 10px;"><strong>' . sprintf(esc_html__('Total: %s domains', 'links-manager'), number_format_i18n((int)$total)) . '</strong></div>';
    $this->render_cited_domains_pagination($filters, $filters['paged'], $totalPages, $total, $perPage);
    echo '<div class="lm-table-wrap">';
    echo '<table class="widefat striped lm-table">';
    echo '<thead><tr>';
    echo $this->table_header_with_tooltip('lm-col-postid', '#', 'Row number in current result page.', 'left');
    echo $this->table_header_with_tooltip('lm-col-link', 'Domain', 'External domain detected in outbound links.');
    echo $this->table_header_with_tooltip('lm-col-count', 'Cited Count', 'Total number of citations to this domain.');
    echo $this->table_header_with_tooltip('lm-col-count', 'Unique Source Pages', 'Number of unique pages citing this domain.');
    echo $this->table_header_with_tooltip('lm-col-link', 'Sample URL', 'One sample destination URL from this domain.', 'right');
    echo '</tr></thead><tbody>';

    if (empty($pageRows)) {
      echo '<tr><td colspan="5">No data.</td></tr>';
    } else {
      $rowNumber = $offset + 1;
      foreach ($pageRows as $row) {
        $domain = (string)$row['domain'];
        $sample = (string)$row['sample_url'];
        $domainUrl = 'https://' . ltrim($domain, '/');

        echo '<tr>';
        echo '<td class="lm-col-postid">' . esc_html((string)$rowNumber) . '</td>';
        echo '<td class="lm-col-link"><a href="' . esc_url($domainUrl) . '" target="_blank" rel="noopener noreferrer"><span class="lm-trunc" title="' . esc_attr($domain) . '">' . esc_html($domain) . '</span></a></td>';
        echo '<td class="lm-col-count" style="text-align:center;">' . esc_html((string)$row['cites']) . '</td>';
        echo '<td class="lm-col-count" style="text-align:center;">' . esc_html((string)$row['pages']) . '</td>';
        echo '<td class="lm-col-link">' . ($sample !== '' ? '<a href="' . esc_url($sample) . '" target="_blank" rel="noopener noreferrer"><span class="lm-trunc" title="' . esc_attr($sample) . '">' . esc_html($sample) . '</span></a>' : '—') . '</td>';
        echo '</tr>';

        $rowNumber++;
      }
    }

    echo '</tbody></table></div>';
    $this->render_cited_domains_pagination($filters, $filters['paged'], $totalPages, $total, $perPage);
    echo '</div>';
  }
}
