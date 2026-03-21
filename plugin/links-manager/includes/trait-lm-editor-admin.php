<?php
/**
 * Links Editor admin page rendering helpers.
 */

if (!defined('ABSPATH')) {
  exit;
}

trait LM_Editor_Admin_Trait {
  public function render_admin_editor_page() {
    if (!$this->current_user_can_access_plugin()) wp_die($this->unauthorized_message());

    $filters = $this->get_filters_from_request();
    $msg = isset($_GET['lm_msg']) ? sanitize_text_field((string)$_GET['lm_msg']) : '';
    $msgClass = $this->notice_class_for_message($msg, 'info');

    $scopePostType = sanitize_key((string)($filters['post_type'] ?? 'any'));
    if ($scopePostType === '') {
      $scopePostType = 'any';
    }
    $scopeWpmlLang = $this->get_effective_scan_wpml_lang((string)($filters['wpml_lang'] ?? 'all'));

    $perPage = $filters['per_page'];
    $paged = $filters['paged'];
    $locations = $this->get_indexed_editor_location_options($scopePostType, $scopeWpmlLang);
    if (!is_array($locations)) {
      $locations = ['any' => 'All'];
      $locationRows = null;
      if (!$filters['rebuild']) {
        $locationRows = $this->get_existing_cache_rows_for_rest($scopePostType, $scopeWpmlLang, true);
        if (!is_array($locationRows) && ($scopePostType !== 'any' || $scopeWpmlLang !== 'all')) {
          $locationRows = $this->get_existing_cache_rows_for_rest('any', 'all', true);
        }
      }
      foreach ((array)$locationRows as $r) {
        $locationKey = isset($r['link_location']) ? (string)$r['link_location'] : '';
        if ($locationKey === '') {
          continue;
        }
        $locations[$locationKey] = $locationKey;
      }
      ksort($locations);
    }
    if ((string)$filters['location'] !== 'any' && !isset($locations[(string)$filters['location']])) {
      $locations[(string)$filters['location']] = (string)$filters['location'];
      ksort($locations);
    }

    $sourceTypes = $this->get_filterable_source_type_options(true);

    $postTypes = $this->get_filterable_post_types();
    $postCategoryOptions = $this->get_post_term_options('category');
    $postTagOptions = $this->get_post_term_options('post_tag');
    $textModes = $this->get_text_match_modes();
    $exportUrl = $this->build_export_url($filters);

    $editorHiddenFields = $this->get_editor_hidden_fields($filters, $perPage, $paged);
    $editorData = $this->get_editor_page_data($filters);
    $pageRows = isset($editorData['items']) && is_array($editorData['items']) ? $editorData['items'] : [];
    $total = max(0, (int)($editorData['total'] ?? 0));
    $paged = max(1, (int)($editorData['paged'] ?? $paged));
    $totalPages = max(1, (int)($editorData['total_pages'] ?? 1));
    $editorHiddenFields = $this->get_editor_hidden_fields($filters, $perPage, $paged);

    echo '<div class="wrap lm-wrap">';
    echo '<h1>' . esc_html__('Links Manager - Links Editor', 'links-manager') . '</h1>';

    if ($msg !== '') echo '<div class="notice notice-' . esc_attr($msgClass) . '"><p>' . esc_html($msg) . '</p></div>';

    echo '<div class="lm-grid">';

    echo '<div class="lm-card lm-card-full lm-card-grouping">';
    echo '<h2 style="margin-top:0;">' . esc_html__('Filter', 'links-manager') . '</h2>';
    echo '<form method="get" action="">';
    echo '<input type="hidden" name="page" value="' . esc_attr(self::PAGE_SLUG) . '"/>';

    echo '<table class="form-table lm-filter-table" role="presentation"><tbody>';

    echo '<tr><th scope="row">Post Type</th><td><select name="lm_post_type">';
    echo '<option value="any"' . selected($filters['post_type'], 'any', false) . '>All</option>';
    foreach ($postTypes as $k => $label) {
      echo '<option value="' . esc_attr($k) . '"' . selected($filters['post_type'], $k, false) . '>' . esc_html($label) . ' (' . esc_html($k) . ')</option>';
    }
    echo '</select></td></tr>';

    echo '<tr><th scope="row">Post Category</th><td><select name="lm_post_category">';
    echo '<option value="0"' . selected((int)($filters['post_category'] ?? 0), 0, false) . '>All</option>';
    foreach ($postCategoryOptions as $termId => $termLabel) {
      echo '<option value="' . esc_attr((string)$termId) . '"' . selected((int)($filters['post_category'] ?? 0), (int)$termId, false) . '>' . esc_html($termLabel) . '</option>';
    }
    echo '</select><div class="lm-small">Applied to WordPress posts only.</div></td></tr>';

    echo '<tr><th scope="row">Post Tag</th><td><select name="lm_post_tag">';
    echo '<option value="0"' . selected((int)($filters['post_tag'] ?? 0), 0, false) . '>All</option>';
    foreach ($postTagOptions as $termId => $termLabel) {
      echo '<option value="' . esc_attr((string)$termId) . '"' . selected((int)($filters['post_tag'] ?? 0), (int)$termId, false) . '>' . esc_html($termLabel) . '</option>';
    }
    echo '</select><div class="lm-small">Applied to WordPress posts only.</div></td></tr>';

    echo '<tr><th scope="row">Link Location</th><td><select name="lm_location">';
    foreach ($locations as $k => $label) {
      echo '<option value="' . esc_attr($k) . '"' . selected($filters['location'], $k, false) . '>' . esc_html($label) . '</option>';
    }
    echo '</select><div class="lm-small">Example: content / excerpt / menu / meta:xxx</div></td></tr>';

    echo '<tr><th scope="row">Source Type</th><td><select name="lm_source_type">';
    foreach ($sourceTypes as $k => $label) {
      echo '<option value="' . esc_attr($k) . '"' . selected($filters['source_type'], $k, false) . '>' . esc_html($label) . '</option>';
    }
    echo '</select></td></tr>';

    echo '<tr><th scope="row">Link Type</th><td><select name="lm_link_type">';
    echo '<option value="any"' . selected($filters['link_type'], 'any', false) . '>All</option>';
    echo '<option value="inlink"' . selected($filters['link_type'], 'inlink', false) . '>Internal</option>';
    echo '<option value="exlink"' . selected($filters['link_type'], 'exlink', false) . '>External</option>';
    echo '</select></td></tr>';

    echo '<tr><th scope="row">' . esc_html__('Search Destination URL', 'links-manager') . '</th><td>';
    echo '<input type="text" name="lm_value" value="' . esc_attr($filters['value_contains']) . '" class="regular-text" placeholder="example.com / /contact / utm_..."/>';
    echo '</td></tr>';

    $vtypes = ['any' => 'All', 'url' => 'Full URL', 'relative' => 'Relative (/page)', 'anchor' => 'Anchor (#)', 'mailto' => 'Email (mailto)', 'tel' => 'Phone (tel)', 'javascript' => 'Javascript', 'other' => 'Other', 'empty' => 'Empty'];
    echo '<tr><th scope="row">URL Format</th><td><select name="lm_value_type">';
    foreach ($vtypes as $k => $label) {
      echo '<option value="' . esc_attr($k) . '"' . selected($filters['value_type'], $k, false) . '>' . esc_html($label) . '</option>';
    }
    echo '</select></td></tr>';

    echo '<tr><th scope="row">Text Search Mode</th><td><select name="lm_text_mode">';
    foreach ($textModes as $modeKey => $modeLabel) {
      echo '<option value="' . esc_attr($modeKey) . '"' . selected($filters['text_match_mode'], $modeKey, false) . '>' . esc_html($modeLabel) . '</option>';
    }
    echo '</select><div class="lm-small">Applies to Destination URL, Source URL, Title, Author, Anchor Text, and Alt.</div></td></tr>';

    echo '<tr><th scope="row">' . esc_html__('Search Source URL', 'links-manager') . '</th><td>';
    echo '<input type="text" name="lm_source" value="' . esc_attr($filters['source_contains'] ?? '') . '" class="regular-text" placeholder="page URL / /category / /slug"/>';
    echo '</td></tr>';

    echo '<tr><th scope="row">' . esc_html__('Search Title', 'links-manager') . '</th><td>';
    echo '<input type="text" name="lm_title" value="' . esc_attr($filters['title_contains']) . '" class="regular-text" placeholder="article title / landing page"/>';
    echo '</td></tr>';

    echo '<tr><th scope="row">' . esc_html__('Search Author', 'links-manager') . '</th><td>';
    echo '<input type="text" name="lm_author" value="' . esc_attr($filters['author_contains']) . '" class="regular-text" placeholder="author name"/>';
    echo '</td></tr>';

    echo '<tr><th scope="row">Published Date Range</th><td>';
    echo '<input type="date" name="lm_publish_date_from" value="' . esc_attr(isset($filters['publish_date_from']) ? (string)$filters['publish_date_from'] : '') . '" /> ';
    echo '<input type="date" name="lm_publish_date_to" value="' . esc_attr(isset($filters['publish_date_to']) ? (string)$filters['publish_date_to'] : '') . '" />';
    echo '</td></tr>';

    echo '<tr><th scope="row">Updated Date Range</th><td>';
    echo '<input type="date" name="lm_updated_date_from" value="' . esc_attr(isset($filters['updated_date_from']) ? (string)$filters['updated_date_from'] : '') . '" /> ';
    echo '<input type="date" name="lm_updated_date_to" value="' . esc_attr(isset($filters['updated_date_to']) ? (string)$filters['updated_date_to'] : '') . '" />';
    echo '</td></tr>';

    echo '<tr><th scope="row">' . esc_html__('Search Anchor Text', 'links-manager') . '</th><td>';
    echo '<input type="text" name="lm_anchor" value="' . esc_attr($filters['anchor_contains']) . '" class="regular-text" placeholder="list / read more / pricing"/>';
    echo '</td></tr>';

    echo '<tr><th scope="row">' . esc_html__('Search in Alt', 'links-manager') . '</th><td>';
    echo '<input type="text" name="lm_alt" value="' . esc_attr($filters['alt_contains']) . '" class="regular-text" placeholder="logo / banner / icon"/>';
    echo '</td></tr>';

    echo '<tr><th scope="row">Quality</th><td><select name="lm_quality">';
    echo '<option value="any"' . selected($filters['quality'], 'any', false) . '>All</option>';
    echo '<option value="good"' . selected($filters['quality'], 'good', false) . '>Good</option>';
    echo '<option value="poor"' . selected($filters['quality'], 'poor', false) . '>Poor</option>';
    echo '<option value="bad"' . selected($filters['quality'], 'bad', false) . '>Bad</option>';
    echo '</select></td></tr>';

    echo '<tr><th scope="row">SEO Flags</th><td><select name="lm_seo_flag">';
    echo '<option value="any"' . selected($filters['seo_flag'], 'any', false) . '>All</option>';
    echo '<option value="dofollow"' . selected($filters['seo_flag'], 'dofollow', false) . '>Dofollow</option>';
    echo '<option value="nofollow"' . selected($filters['seo_flag'], 'nofollow', false) . '>Nofollow</option>';
    echo '<option value="sponsored"' . selected($filters['seo_flag'], 'sponsored', false) . '>Sponsored</option>';
    echo '<option value="ugc"' . selected($filters['seo_flag'], 'ugc', false) . '>UGC</option>';
    echo '</select></td></tr>';

    echo '<tr><th scope="row">' . esc_html__('Order By', 'links-manager') . '</th><td>';
    echo '<select name="lm_orderby">';
    echo '<option value="date"' . selected($filters['orderby'], 'date', false) . '>Date</option>';
    echo '<option value="title"' . selected($filters['orderby'], 'title', false) . '>Title</option>';
    echo '<option value="post_type"' . selected($filters['orderby'], 'post_type', false) . '>Post Type</option>';
    echo '<option value="post_author"' . selected($filters['orderby'], 'post_author', false) . '>Author</option>';
    echo '<option value="page_url"' . selected($filters['orderby'], 'page_url', false) . '>Page URL</option>';
    echo '<option value="link"' . selected($filters['orderby'], 'link', false) . '>Destination Link</option>';
    echo '<option value="source"' . selected($filters['orderby'], 'source', false) . '>Source</option>';
    echo '<option value="link_location"' . selected($filters['orderby'], 'link_location', false) . '>Link Location</option>';
    echo '<option value="anchor_text"' . selected($filters['orderby'], 'anchor_text', false) . '>Anchor</option>';
    echo '<option value="quality"' . selected($filters['orderby'], 'quality', false) . '>Quality</option>';
    echo '<option value="link_type"' . selected($filters['orderby'], 'link_type', false) . '>Link Type</option>';
    echo '<option value="seo_flags"' . selected($filters['orderby'], 'seo_flags', false) . '>SEO Flags</option>';
    echo '<option value="alt_text"' . selected($filters['orderby'], 'alt_text', false) . '>Alt</option>';
    echo '<option value="count"' . selected($filters['orderby'], 'count', false) . '>Count</option>';
    echo '</select> ';
    echo '<select name="lm_order">';
    echo '<option value="DESC"' . selected($filters['order'], 'DESC', false) . '>DESC</option>';
    echo '<option value="ASC"' . selected($filters['order'], 'ASC', false) . '>ASC</option>';
    echo '</select>';
    echo '</td></tr>';

    echo '<tr><th scope="row">' . esc_html__('Cache', 'links-manager') . '</th><td>';
    echo '<label><input type="checkbox" name="lm_rebuild" value="1"' . checked($filters['rebuild'] ? '1' : '0', '1', false) . '> ' . esc_html__('Rebuild cache', 'links-manager') . '</label>';
    echo '<div class="lm-small">' . esc_html__('Cache is used to keep this page fast.', 'links-manager') . '</div>';
    echo '</td></tr>';

    echo '<tr><th scope="row">' . esc_html__('Per Page', 'links-manager') . '</th><td>';
    echo '<input type="number" name="lm_per_page" value="' . esc_attr((string)$perPage) . '" min="10" max="500" />';
    echo '</td></tr>';

    echo '<tr><th scope="row">Export</th><td>';
    echo '<a class="button button-secondary" href="' . esc_url($exportUrl) . '">' . esc_html__('Export CSV', 'links-manager') . '</a>';
    echo '<div class="lm-small">' . esc_html__('Export includes row_id + occurrence for precise bulk updates.', 'links-manager') . '</div>';
    echo '</td></tr>';

    echo '</tbody></table>';

    submit_button(__('Apply Filters', 'links-manager'), 'primary', 'submit', false);
    echo ' <a class="button" href="' . esc_url(admin_url('admin.php?page=' . self::PAGE_SLUG)) . '">' . esc_html__('Reset Filter', 'links-manager') . '</a>';
    echo '</form>';
    echo '</div>';

    echo '<div class="lm-card lm-card-full">';
    echo '<h2 style="margin-top:0;">' . esc_html__('Bulk Update via CSV (Specific 1 Link)', 'links-manager') . '</h2>';

    echo '<div class="lm-small" style="margin-bottom:12px;">';
    echo '<strong>CSV Format Requirements:</strong><br>';
    echo '• <strong>Required headers:</strong> <code>post_id</code>, <code>old_link</code>, <code>row_id</code><br>';
    echo '• <strong>Optional headers:</strong> <code>new_link</code>, <code>new_rel</code>, <code>new_anchor</code><br>';
    echo '• Delimiter can be comma (,) or semicolon (;)<br>';
    echo '• First row must be the header row<br>';
    echo '• Encoding: UTF-8<br><br>';

    echo '<strong>Example CSV content:</strong><br>';
    echo '<code style="display:block; background:#f5f5f5; padding:8px; margin:4px 0; border-radius:4px; font-size:11px;">';
    echo 'post_id,old_link,row_id,new_link,new_rel,new_anchor<br>';
    echo '123,https://example.com/old,lm_abc123def4567890,https://example.com/new,nofollow,New Link Text<br>';
    echo '456,https://site.com/page,lm_xyz789abc1234567,https://newsite.com/page,,Updated Anchor';
    echo '</code>';

    echo '<strong>Notes:</strong><br>';
    echo '• Leave <code>new_link</code> empty to only update anchor text or rel<br>';
    echo '• Leave <code>new_anchor</code> empty to keep existing anchor text<br>';
    echo '• Leave <code>new_rel</code> empty to keep existing rel attributes<br>';
    echo '• Export the current table to get correct <code>row_id</code> values<br>';
    echo '• If content changes and target link no longer matches, the row will fail (fail-safe)<br>';
    echo '</div>';

    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" enctype="multipart/form-data" style="margin-top:10px;">';
    echo '<input type="hidden" name="action" value="lm_bulk_update"/>';
    echo '<input type="hidden" name="' . esc_attr(self::NONCE_NAME) . '" value="' . esc_attr(wp_create_nonce(self::NONCE_ACTION)) . '"/>';

    foreach ($editorHiddenFields as $k => $val) {
      echo '<input type="hidden" name="' . esc_attr($k) . '" value="' . esc_attr($val) . '"/>';
    }

    echo '<input type="file" name="lm_csv" accept=".csv" required /> ';
    submit_button(__('Run Bulk Update', 'links-manager'), 'secondary', 'submit', false);
    echo '</form>';
    echo '</div>';

    echo '</div>';

    echo '<div>';
    echo '<h2><span>' . esc_html($this->get_editor_results_count_text($total)) . '</span></h2>';
    echo '<div class="lm-small" style="margin:6px 0 10px;">';
    echo '<strong>Quality rule:</strong> ';
    echo esc_html($this->get_anchor_quality_status_help_text());
    echo '</div>';
    $this->render_pagination($filters, $paged, $totalPages);

    echo '<div class="lm-table-wrap">';
    echo '<table class="widefat striped lm-table">';
    echo '<thead><tr>';

    $cols = [
      ['label' => '#', 'class' => 'lm-col-postid', 'tooltip' => 'Row number in current result page.'],
      ['label' => 'Page URL', 'class' => 'lm-col-pageurl', 'tooltip' => 'URL of the content where this link appears.'],
      ['label' => 'Title', 'class' => 'lm-col-title', 'tooltip' => 'Title of the source post/page containing the link.'],
      ['label' => 'Author', 'class' => 'lm-col-author', 'tooltip' => 'Author of the source content.'],
      ['label' => 'Published Date', 'class' => 'lm-col-date', 'tooltip' => 'Publish date of the source content.'],
      ['label' => 'Updated Date', 'class' => 'lm-col-date', 'tooltip' => 'Last modified date of the source content.'],
      ['label' => 'Post Type', 'class' => 'lm-col-type', 'tooltip' => 'Content type (post, page, or custom post type).'],
      ['label' => 'Destination Link', 'class' => 'lm-col-link', 'tooltip' => 'The target URL found in the anchor href attribute.'],
      ['label' => 'Source', 'class' => 'lm-col-source', 'tooltip' => 'Data source scanned by plugin (content, excerpt, meta, or menu).'],
      ['label' => 'Link Location', 'class' => 'lm-col-source', 'tooltip' => 'Specific block/location in source content (for example core/paragraph or meta:key).'],
      ['label' => 'Anchor', 'class' => 'lm-col-anchor', 'tooltip' => 'Visible anchor text shown to readers.'],
      ['label' => 'Quality', 'class' => 'lm-col-quality', 'tooltip' => 'Anchor quality evaluation based on length and weak phrase rules.'],
      ['label' => 'Link Type', 'class' => 'lm-col-linktype', 'tooltip' => 'Internal (same site) or External (different domain).'],
      ['label' => 'SEO Flags', 'class' => 'lm-col-rel', 'tooltip' => 'rel attributes detected on link (dofollow, nofollow, sponsored, ugc).'],
      ['label' => 'Alt', 'class' => 'lm-col-alt', 'tooltip' => 'Image alt text when link is wrapped around an image.'],
      ['label' => 'Snippet', 'class' => 'lm-col-snippet', 'tooltip' => 'Context snippet around the link in source content (limited to 60 characters in table view).'],
      ['label' => 'Edit', 'class' => 'lm-col-edit', 'tooltip' => 'Update URL, anchor text, and rel values for this specific link row.']
    ];
    $totalCols = count($cols);
    foreach ($cols as $index => $col) {
      $label = esc_html($col['label']);
      $tooltip = isset($col['tooltip']) ? (string)$col['tooltip'] : '';
      if ($tooltip !== '') {
        $tooltipClass = 'lm-tooltip';
        if ($index <= 1) {
          $tooltipClass .= ' is-left';
        } elseif ($index >= ($totalCols - 2)) {
          $tooltipClass .= ' is-right';
        }
        $label .= ' <span class="' . esc_attr($tooltipClass) . '" data-tooltip="' . esc_attr($tooltip) . '" tabindex="0" role="img" aria-label="' . esc_attr($tooltip) . '">ⓘ</span>';
      }
      echo '<th class="' . esc_attr($col['class']) . '">' . $label . '</th>';
    }
    echo '</tr></thead><tbody>';
    echo $this->get_editor_results_tbody_html($pageRows, $editorHiddenFields, (($paged - 1) * $perPage) + 1);

    echo '</tbody></table></div>';

    $this->render_pagination($filters, $paged, $totalPages);

    echo '<p class="lm-small" style="margin-top:12px;">Note: Per-row edit & bulk update only modify 1 link occurrence per row_id/occurrence. If content changes, the update is cancelled (fail-safe).</p>';

    echo '</div>';
    echo '</div>';
  }

  private function get_editor_hidden_fields($filters, $perPage, $paged) {
    return [
      'lm_post_type' => $filters['post_type'],
      'lm_post_category' => isset($filters['post_category']) ? (int)$filters['post_category'] : 0,
      'lm_post_tag' => isset($filters['post_tag']) ? (int)$filters['post_tag'] : 0,
      'lm_location' => $filters['location'],
      'lm_source_type' => $filters['source_type'],
      'lm_link_type' => $filters['link_type'],
      'lm_value_type' => $filters['value_type'],
      'lm_value' => $filters['value_contains'],
      'lm_source' => $filters['source_contains'],
      'lm_title' => $filters['title_contains'],
      'lm_author' => $filters['author_contains'],
      'lm_publish_date_from' => isset($filters['publish_date_from']) ? (string)$filters['publish_date_from'] : '',
      'lm_publish_date_to' => isset($filters['publish_date_to']) ? (string)$filters['publish_date_to'] : '',
      'lm_updated_date_from' => isset($filters['updated_date_from']) ? (string)$filters['updated_date_from'] : '',
      'lm_updated_date_to' => isset($filters['updated_date_to']) ? (string)$filters['updated_date_to'] : '',
      'lm_anchor' => $filters['anchor_contains'],
      'lm_quality' => $filters['quality'],
      'lm_seo_flag' => $filters['seo_flag'],
      'lm_alt' => $filters['alt_contains'],
      'lm_rel' => $filters['rel_contains'],
      'lm_text_mode' => $filters['text_match_mode'],
      'lm_rel_nofollow' => $filters['rel_nofollow'],
      'lm_rel_sponsored' => $filters['rel_sponsored'],
      'lm_rel_ugc' => $filters['rel_ugc'],
      'lm_orderby' => $filters['orderby'],
      'lm_order' => $filters['order'],
      'lm_per_page' => $perPage,
      'lm_paged' => $paged,
    ];
  }

  private function get_editor_results_count_text($total) {
    $total = max(0, (int)$total);
    return sprintf('Results (%d %s)', $total, $total === 1 ? 'link' : 'links');
  }

  private function get_editor_page_data($filters) {
    $filters = is_array($filters) ? $filters : [];
    $scopePostType = sanitize_key((string)($filters['post_type'] ?? 'any'));
    if ($scopePostType === '') {
      $scopePostType = 'any';
    }
    $scopeWpmlLang = $this->get_effective_scan_wpml_lang((string)($filters['wpml_lang'] ?? 'all'));
    $rebuildRequested = !empty($filters['rebuild']);
    $all = null;
    $usedIndexedAuthority = false;
    $usedExistingCache = false;
    $indexedFastResponse = null;

    if (!$rebuildRequested) {
      $indexedFastResponse = $this->get_indexed_editor_list_fastpath_response($scopePostType, $scopeWpmlLang, $filters);
      if (
        is_array($indexedFastResponse)
        && isset($indexedFastResponse['items'])
        && isset($indexedFastResponse['pagination'])
        && is_array($indexedFastResponse['items'])
        && is_array($indexedFastResponse['pagination'])
      ) {
        $pagination = (array)$indexedFastResponse['pagination'];
        return [
          'items' => array_values((array)$indexedFastResponse['items']),
          'total' => max(0, (int)($pagination['total'] ?? 0)),
          'per_page' => max(10, (int)($pagination['per_page'] ?? ($filters['per_page'] ?? 50))),
          'paged' => max(1, (int)($pagination['paged'] ?? ($filters['paged'] ?? 1))),
          'total_pages' => max(1, (int)($pagination['total_pages'] ?? 1)),
          'data_source' => 'indexed_fastpath',
        ];
      }
    }

    if (!$rebuildRequested && $this->is_indexed_datastore_ready()) {
      $all = $this->get_indexed_fact_rows($scopePostType, $scopeWpmlLang, $filters);
      if (is_array($all) && !empty($all)) {
        $usedIndexedAuthority = true;
      }
      if (!$usedIndexedAuthority && ($scopePostType !== 'any' || $scopeWpmlLang !== 'all')) {
        $all = $this->get_indexed_fact_rows('any', 'all', $filters);
        if (is_array($all) && !empty($all)) {
          $usedIndexedAuthority = true;
        }
      }
    }

    if (!is_array($all)) {
      $all = null;
    }
    if (empty($all) && !$rebuildRequested && !$usedIndexedAuthority) {
      $all = $this->get_existing_cache_rows_for_rest($scopePostType, $scopeWpmlLang, true);
      if (is_array($all)) {
        $usedExistingCache = true;
      }
    }
    if (!is_array($all)) {
      $all = $this->get_canonical_rows_for_scope($scopePostType, $rebuildRequested, $scopeWpmlLang, $filters);
    }

    $rows = $this->apply_filters_and_group($all, $filters);
    $total = count($rows);
    $perPage = max(10, (int)($filters['per_page'] ?? 50));
    $requestedPage = max(1, (int)($filters['paged'] ?? 1));
    $totalPages = max(1, (int)ceil($total / $perPage));
    $paged = min($requestedPage, $totalPages);
    $offset = ($paged - 1) * $perPage;
    $pageRows = array_slice($rows, $offset, $perPage);

    return [
      'items' => array_values($pageRows),
      'total' => $total,
      'per_page' => $perPage,
      'paged' => $paged,
      'total_pages' => $totalPages,
      'data_source' => $usedIndexedAuthority ? 'indexed' : ($usedExistingCache ? 'cache' : 'canonical'),
    ];
  }

  private function get_editor_results_tbody_html($pageRows, $editorHiddenFields, $rowNumberStart = 1) {
    $pageRows = is_array($pageRows) ? $pageRows : [];
    $rowNumber = max(1, (int)$rowNumberStart);
    ob_start();

    if (empty($pageRows)) {
      echo '<tr><td colspan="17">No links match the filter.</td></tr>';
      return (string)ob_get_clean();
    }

    foreach ($pageRows as $r) {
      $typeLabel = ($r['link_type'] === 'exlink') ? 'External' : 'Internal';

      echo '<tr>';
      echo '<td class="lm-col-postid">' . esc_html((string)$rowNumber) . '</td>';
      echo '<td class="lm-col-pageurl">' . ($r['page_url'] ? '<a href="' . esc_url($r['page_url']) . '" target="_blank" rel="noopener noreferrer"><span class="lm-trunc" title="' . esc_attr($r['page_url']) . '">' . esc_html($r['page_url']) . '</span></a>' : '') . '</td>';
      echo '<td class="lm-col-title"><span class="lm-trunc" title="' . esc_attr((string)$r['post_title']) . '">' . esc_html((string)$r['post_title']) . '</span></td>';
      echo '<td class="lm-col-author"><span class="lm-trunc" title="' . esc_attr((string)$r['post_author']) . '">' . esc_html((string)$r['post_author']) . '</span></td>';

      $postDate = isset($r['post_date']) ? (string)$r['post_date'] : '';
      echo '<td class="lm-col-date"><span class="lm-trunc" title="' . esc_attr($postDate) . '">' . esc_html($postDate !== '' ? $postDate : '—') . '</span></td>';

      $postModified = isset($r['post_modified']) ? (string)$r['post_modified'] : '';
      echo '<td class="lm-col-date"><span class="lm-trunc" title="' . esc_attr($postModified) . '">' . esc_html($postModified !== '' ? $postModified : '—') . '</span></td>';

      echo '<td class="lm-col-type">' . esc_html((string)$r['post_type']) . '</td>';
      echo '<td class="lm-col-link">' . ($r['link'] ? '<a href="' . esc_url($r['link']) . '" target="_blank" rel="noopener noreferrer"><span class="lm-trunc" title="' . esc_attr($r['link']) . '">' . esc_html($r['link']) . '</span></a>' : '') . '</td>';
      echo '<td class="lm-col-source">' . esc_html((string)$r['source']) . '</td>';
      echo '<td class="lm-col-source"><span class="lm-trunc" title="' . esc_attr((string)$r['link_location']) . '">' . esc_html((string)$r['link_location']) . '</span></td>';
      echo '<td class="lm-col-anchor"><span class="lm-trunc" data-anchor title="' . esc_attr((string)$r['anchor_text']) . '">' . esc_html((string)$r['anchor_text']) . '</span></td>';

      $quality = $this->get_anchor_quality_suggestion($r['anchor_text']);
      $qualityLabel = 'Good';
      if ((string)($quality['quality'] ?? '') === 'poor') $qualityLabel = 'Poor';
      if ((string)($quality['quality'] ?? '') === 'bad') $qualityLabel = 'Bad';
      echo '<td class="lm-col-quality" title="' . esc_attr((string)($quality['warning'] ?? '')) . '">' . esc_html($qualityLabel) . '</td>';

      echo '<td class="lm-col-linktype">' . esc_html($typeLabel) . '</td>';

      $seoFlags = [];
      if (($r['rel_nofollow'] ?? '') === '1') $seoFlags[] = 'nofollow';
      if (($r['rel_sponsored'] ?? '') === '1') $seoFlags[] = 'sponsored';
      if (($r['rel_ugc'] ?? '') === '1') $seoFlags[] = 'ugc';
      $seoFlagsText = !empty($seoFlags) ? implode(', ', $seoFlags) : 'dofollow';
      echo '<td class="lm-col-rel"><span class="lm-trunc" title="' . esc_attr($seoFlagsText) . '">' . esc_html($seoFlagsText) . '</span></td>';

      echo '<td class="lm-col-alt"><span class="lm-trunc" title="' . esc_attr((string)$r['alt_text']) . '">' . esc_html((string)$r['alt_text']) . '</span></td>';

      $snippetFull = isset($r['snippet']) ? (string)$r['snippet'] : '';
      $snippetShort = $this->text_snippet_with_anchor_offset($snippetFull, isset($r['anchor_text']) ? (string)$r['anchor_text'] : '', 60, 4);
      echo '<td class="lm-col-snippet"><span class="lm-trunc" title="' . esc_attr($snippetFull) . '">' . $this->highlight_snippet_anchor_html($snippetShort, isset($r['anchor_text']) ? (string)$r['anchor_text'] : '') . '</span></td>';

      echo '<td class="lm-col-edit">';
      if (!empty($r['post_id']) && $r['source'] !== 'menu') {
        echo '<form class="lm-edit-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return false;">';
        echo '<input type="hidden" name="action" value="lm_update_link"/>';
        echo '<input type="hidden" name="' . esc_attr(self::NONCE_NAME) . '" value="' . esc_attr(wp_create_nonce(self::NONCE_ACTION)) . '"/>';

        foreach ($editorHiddenFields as $k => $val) {
          echo '<input type="hidden" name="' . esc_attr($k) . '" value="' . esc_attr($val) . '"/>';
        }

        echo '<input type="hidden" name="post_id" value="' . esc_attr((string)$r['post_id']) . '"/>';
        echo '<input type="hidden" name="row_id" value="' . esc_attr((string)$r['row_id']) . '"/>';
        echo '<input type="hidden" name="old_link" value="' . esc_attr((string)$r['link']) . '"/>';
        echo '<input type="hidden" name="old_anchor" value="' . esc_attr((string)$r['anchor_text']) . '"/>';
        echo '<input type="hidden" name="old_rel" value="' . esc_attr((string)($r['rel_raw'] ?? '')) . '"/>';
        echo '<input type="hidden" name="old_snippet" value="' . esc_attr(isset($r['snippet']) ? (string)$r['snippet'] : '') . '"/>';
        echo '<input type="hidden" name="source" value="' . esc_attr((string)$r['source']) . '"/>';
        echo '<input type="hidden" name="link_location" value="' . esc_attr((string)$r['link_location']) . '"/>';
        echo '<input type="hidden" name="block_index" value="' . esc_attr((string)$r['block_index']) . '"/>';
        echo '<input type="hidden" name="occurrence" value="' . esc_attr((string)($r['occurrence'] ?? '0')) . '"/>';
        echo '<input type="text" name="new_link" placeholder="New URL" />';
        echo '<input type="text" name="new_anchor" placeholder="New anchor text" />';
        echo '<input type="text" name="new_rel" placeholder="New rel (optional), e.g. nofollow sponsored" />';
        echo '<div class="lm-form-msg"></div>';
        echo '<button type="button" class="button button-secondary lm-edit-submit">Update</button>';
        echo '</form>';
      } else {
        echo '<span class="lm-small">—</span>';
      }
      echo '</td>';
      echo '</tr>';
      $rowNumber++;
    }

    return (string)ob_get_clean();
  }
}
