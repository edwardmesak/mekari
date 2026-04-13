<?php
/**
 * Statistics admin page rendering helpers.
 */

if (!defined('ABSPATH')) {
  exit;
}

trait LM_Statistics_Admin_Trait {
  public function render_admin_stats_page() {
    if (!$this->current_user_can_access_plugin()) wp_die($this->unauthorized_message());

    $filters = $this->get_filters_from_request();
    $msg = $this->request_text('lm_msg', '');
    $msgClass = $this->notice_class_for_message($msg, 'info');
    $statsScopePostType = sanitize_key((string)($filters['post_type'] ?? 'any'));
    if ($statsScopePostType === '') {
      $statsScopePostType = 'any';
    }
    $statsWpmlLang = $this->get_requested_view_wpml_lang((string)($filters['wpml_lang'] ?? 'all'));
    $dataNotice = $this->get_report_data_notice($statsScopePostType, $statsWpmlLang);

    echo '<div class="wrap lm-wrap">';
    $this->render_admin_page_header(
      __('Links Manager - Statistics', 'links-manager'),
      __('Summary of link performance, distribution, and anchor quality across your published content.', 'links-manager')
    );

    if ($msg !== '') echo '<div class="notice notice-' . esc_attr($msgClass) . '"><p>' . esc_html($msg) . '</p></div>';
    $this->render_refresh_data_status_banner($statsScopePostType, $statsWpmlLang);
    if ($dataNotice !== '') echo '<div class="notice notice-warning"><p>' . esc_html($dataNotice) . '</p></div>';

    $includeOrphanPages = false;
    $snapshot = null;
    $snapshot = $this->get_precomputed_stats_snapshot_if_available(
      $this->default_stats_snapshot_filters($statsScopePostType, $statsWpmlLang),
      $includeOrphanPages
    );
    if (!is_array($snapshot)) {
      $all = [];
      if (
        $this->indexed_dataset_has_rows($statsScopePostType, $statsWpmlLang)
      ) {
        $all = $this->get_lightweight_indexed_stats_rows($statsScopePostType, $statsWpmlLang);
      }
      if (empty($all)) {
        $all = $this->get_report_scope_rows_or_empty($filters['post_type'], isset($filters['wpml_lang']) ? $filters['wpml_lang'] : 'all', $filters, false);
      }
      $snapshot = $this->get_stats_snapshot_payload($all, $filters, $includeOrphanPages);
    }
    $stats = $snapshot['stats'];
    $tops = $snapshot['tops'];
    $postTypeBuckets = $snapshot['post_type_buckets'];
    $anchorQualityBuckets = $snapshot['anchor_quality_buckets'];
    $maxPostType = (int)$snapshot['max_post_type'];
    $maxAnchor = (int)$snapshot['max_anchor'];
    $internal_count = (int)$snapshot['internal_count'];
    $external_count = (int)$snapshot['external_count'];
    $link_total = $internal_count + $external_count;
    $internal_pct = (int)$snapshot['internal_pct'];
    $external_pct = (int)$snapshot['external_pct'];
    $non_good_count = (int)($stats['non_good_anchor_text'] ?? 0);
    $non_good_pct = (float)($snapshot['non_good_pct'] ?? 0);
    $anchor_quality_total = (int)($snapshot['anchor_quality_total'] ?? 0);
    
    echo '<div class="lm-stats-wrap">';
    echo '<div class="lm-stats-grid">';

    echo '<div class="lm-stat">';
    echo '<div class="lm-stat-label">Total Links</div>';
    echo '<div class="lm-stat-value">' . esc_html((string)$stats['total_links']) . '</div>';
    echo '<div class="lm-stat-sub">Internal: ' . esc_html((string)$stats['internal_links']) . ' • External: ' . esc_html((string)$stats['external_links']) . '</div>';
    echo '</div>';

    echo '<div class="lm-stat">';
    echo '<div class="lm-stat-label">Non-Good Anchor Text</div>';
    echo '<div class="lm-stat-value">' . esc_html((string)$non_good_count) . '</div>';
    echo '<div class="lm-stat-sub"><span class="lm-pill warn">' . esc_html(number_format($non_good_pct, 1)) . '%</span> of anchor summary base is non-good (Poor/Bad)</div>';
    echo '<div class="lm-stat-sub">Anchor summary base: ' . esc_html((string)$anchor_quality_total) . '</div>';
    echo '</div>';

    echo '<div class="lm-stat">';
    echo '<div class="lm-stat-label">Internal Links</div>';
    echo '<div class="lm-stat-value">' . esc_html((string)$stats['internal']['total']) . '</div>';
    echo '<div class="lm-stat-sub">Dofollow: ' . esc_html((string)$stats['internal']['dofollow']) . ' • Nofollow: ' . esc_html((string)$stats['internal']['nofollow']) . '</div>';
    echo '</div>';

    echo '<div class="lm-stat">';
    echo '<div class="lm-stat-label">External Links</div>';
    echo '<div class="lm-stat-value">' . esc_html((string)$stats['external']['dofollow'] + $stats['external']['nofollow']) . '</div>';
    echo '<div class="lm-stat-sub">Domains: ' . esc_html((string)$stats['external']['total_domains']) . '</div>';
    echo '<div class="lm-stat-sub">Dofollow: ' . esc_html((string)$stats['external']['dofollow']) . ' • Nofollow: ' . esc_html((string)$stats['external']['nofollow']) . '</div>';
    echo '</div>';

    if ($link_total > 0) {
      $pie_style = 'background: conic-gradient(#2563eb 0 ' . esc_attr((string)$internal_pct) . '%, #f59e0b ' . esc_attr((string)$internal_pct) . '% 100%);';
    } else {
      $pie_style = 'background:#e5e7eb;';
    }
    $pie_tip = 'Internal: ' . $internal_count . ' (' . $internal_pct . '%) | External: ' . $external_count . ' (' . $external_pct . '%)';
    echo '<div class="lm-top-card lm-pie-card lm-pie-card-inline">';
    echo '<div class="lm-pie lm-tooltip" data-tooltip="' . esc_attr($pie_tip) . '" tabindex="0" role="img" aria-label="' . esc_attr($pie_tip) . '" style="' . esc_attr($pie_style) . '"><div class="lm-pie-center">' . esc_html((string)$internal_pct) . '% / ' . esc_html((string)$external_pct) . '%</div></div>';
    echo '<div>';
    echo '<h3>Internal vs External Comparison</h3>';
    echo '<div class="lm-pie-legend">';
    echo '<div class="lm-pie-item"><span class="lm-pie-swatch" style="background:#2563eb;"></span>Internal: ' . esc_html((string)$internal_count) . '</div>';
    echo '<div class="lm-pie-item"><span class="lm-pie-swatch" style="background:#f59e0b;"></span>External: ' . esc_html((string)$external_count) . '</div>';
    echo '</div>';
    echo '</div>';
    echo '</div>';

    echo '</div>'; // lm-stats-grid
    echo '</div>'; // lm-stats-wrap

    echo '<div class="lm-chart-grid">';

    // Internal vs External per post type
    echo '<div class="lm-bar-card">';
    echo '<h3>Internal vs External per Post Type</h3>';
    echo '<div class="lm-legend"><span class="lm-legend-item"><span class="lm-legend-swatch" style="background:#2563eb;"></span>Internal</span><span class="lm-legend-item"><span class="lm-legend-swatch" style="background:#f59e0b;"></span>External</span></div>';
    echo '<div class="lm-chart-hint">Setiap bar = 100% link pada post type tersebut. Panjang warna menunjukkan proporsi internal vs external.</div>';
    if (empty($postTypeBuckets)) {
      echo '<div class="lm-empty">No post type data.</div>';
    } else {
      foreach ($postTypeBuckets as $pt => $b) {
        $totalPt = (int)$b['internal'] + (int)$b['external'];
        $inPct = $totalPt > 0 ? (int)round(($b['internal'] / $totalPt) * 100) : 0;
        $exPct = $totalPt > 0 ? 100 - $inPct : 0;
        $tip = $pt . ' | Total: ' . $totalPt . ' | Internal: ' . (int)$b['internal'] . ' (' . $inPct . '%) | External: ' . (int)$b['external'] . ' (' . $exPct . '%)';
        echo '<div class="lm-bar-row">';
        echo '<div class="lm-bar-label">' . esc_html($pt) . '</div>';
        echo '<div class="lm-stacked-wrap lm-tooltip" data-tooltip="' . esc_attr($tip) . '" tabindex="0" role="img" aria-label="' . esc_attr($tip) . '">';
        echo '<div class="lm-stacked-track">';
        echo '<div class="lm-stacked-seg lm-stacked-seg-internal" style="width:' . esc_attr((string)$inPct) . '%;"></div>';
        echo '<div class="lm-stacked-seg lm-stacked-seg-external" style="width:' . esc_attr((string)$exPct) . '%;"></div>';
        echo '</div>';
        echo '<div class="lm-stacked-meta">' . esc_html((string)$totalPt) . ' | I ' . esc_html((string)$inPct) . '% / E ' . esc_html((string)$exPct) . '%</div>';
        echo '</div>';
        echo '</div>';
      }
    }
    echo '</div>';

    // Dofollow vs Nofollow
    echo '<div class="lm-bar-card">';
    echo '<h3>Dofollow vs Nofollow</h3>';
    echo '<div class="lm-legend"><span class="lm-legend-item"><span class="lm-legend-swatch" style="background:#16a34a;"></span>Dofollow</span><span class="lm-legend-item"><span class="lm-legend-swatch" style="background:#f97316;"></span>Nofollow</span></div>';
    $nofollow = (int)$stats['nofollow_links'];
    $dofollow = (int)$stats['dofollow_links'];
    $followTotal = $nofollow + $dofollow;
    if ($followTotal <= 0) {
      echo '<div class="lm-empty">No rel data.</div>';
    } else {
      $nofollowPct = (int)round(($nofollow / $followTotal) * 100);
      $dofollowPct = 100 - $nofollowPct;
      $tip = 'Dofollow: ' . $dofollow . ' (' . $dofollowPct . '%)';
      echo '<div class="lm-bar-row">';
      echo '<div class="lm-bar-label">Dofollow</div>';
      echo '<div class="lm-bar-track-wrap lm-tooltip" data-tooltip="' . esc_attr($tip) . '" tabindex="0" role="img" aria-label="' . esc_attr($tip) . '"><div class="lm-bar-track"><div class="lm-bar-fill" style="width:' . esc_attr((string)$dofollowPct) . '%; background:#16a34a;"></div></div></div>';
      echo '<div class="lm-bar-value">' . esc_html((string)$dofollow) . '</div>';
      echo '</div>';
      $tip = 'Nofollow: ' . $nofollow . ' (' . $nofollowPct . '%)';
      echo '<div class="lm-bar-row">';
      echo '<div class="lm-bar-label">Nofollow</div>';
      echo '<div class="lm-bar-track-wrap lm-tooltip" data-tooltip="' . esc_attr($tip) . '" tabindex="0" role="img" aria-label="' . esc_attr($tip) . '"><div class="lm-bar-track"><div class="lm-bar-fill" style="width:' . esc_attr((string)$nofollowPct) . '%; background:#f97316;"></div></div></div>';
      echo '<div class="lm-bar-value">' . esc_html((string)$nofollow) . '</div>';
      echo '</div>';
    }
    echo '</div>';

    // Anchor text quality
    echo '<div class="lm-bar-card">';
    echo '<h3>Anchor Text Quality</h3>';
    echo '<div class="lm-chart-hint">Quality status: ' . esc_html($this->get_anchor_quality_status_help_text()) . '</div>';
    echo '<div class="lm-legend"><span class="lm-legend-item"><span class="lm-legend-swatch" style="background:#16a34a;"></span>Good</span><span class="lm-legend-item"><span class="lm-legend-swatch" style="background:#f97316;"></span>Poor</span><span class="lm-legend-item"><span class="lm-legend-swatch" style="background:#dc2626;"></span>Bad</span></div>';
    if ($maxAnchor <= 0) {
      echo '<div class="lm-empty">No anchor data.</div>';
    } else {
      $aqColors = ['good'=>'#16a34a','poor'=>'#f97316','bad'=>'#dc2626'];
      foreach ($anchorQualityBuckets as $k => $count) {
        $pct = $maxAnchor > 0 ? (int)round(($count / $maxAnchor) * 100) : 0;
        $label = $k === 'good' ? 'Good' : ($k === 'poor' ? 'Poor' : 'Bad');
        $tip = $label . ': ' . $count;
        echo '<div class="lm-bar-row">';
        echo '<div class="lm-bar-label">' . esc_html($label) . '</div>';
        echo '<div class="lm-bar-track-wrap lm-tooltip" data-tooltip="' . esc_attr($tip) . '" tabindex="0" role="img" aria-label="' . esc_attr($tip) . '"><div class="lm-bar-track"><div class="lm-bar-fill" style="width:' . esc_attr((string)$pct) . '%; background:' . esc_attr($aqColors[$k]) . ';"></div></div></div>';
        echo '<div class="lm-bar-value">' . esc_html((string)$count) . '</div>';
        echo '</div>';
      }
    }
    echo '</div>';

    // Top external domains (bar)
    echo '<div class="lm-bar-card">';
    echo '<h3>Top 10 Cited External Domains</h3>';
    echo '<div class="lm-legend"><span class="lm-legend-item"><span class="lm-legend-swatch" style="background:#3b82f6;"></span>Percentage of total external links</span></div>';
    if (empty($tops['external_domains'])) {
      echo '<div class="lm-empty">No data.</div>';
    } else {
      $maxDom = max($tops['external_domains'] ?: [1]);
      $externalTotal = max(1, (int)$stats['external_links']);
      foreach ($tops['external_domains'] as $domain => $count) {
        $pct = $maxDom > 0 ? (int)round(($count / $maxDom) * 100) : 0;
        $pctOfTotal = round(($count / $externalTotal) * 100, 1);
        $extra = '';
        $tip = $domain . ': ' . $count . ' (' . $pctOfTotal . '%)' . $extra;
        echo '<div class="lm-bar-row">';
        echo '<div class="lm-bar-label"><span class="lm-trunc" title="' . esc_attr($domain) . '">' . esc_html($domain) . '</span></div>';
        echo '<div class="lm-bar-track-wrap lm-tooltip" data-tooltip="' . esc_attr($tip) . '" tabindex="0" role="img" aria-label="' . esc_attr($tip) . '"><div class="lm-bar-track"><div class="lm-bar-fill" style="width:' . esc_attr((string)$pct) . '%; background:#3b82f6;"></div></div></div>';
        echo '<div class="lm-bar-value">' . esc_html((string)$pctOfTotal) . '%</div>';
        echo '</div>';
      }
    }
    echo '</div>';

    echo '</div>'; // lm-chart-grid

    echo '<div class="lm-top-grid">';

    echo '<div class="lm-top-card">';
    echo '<h3>Top 10 Internal Anchor Text</h3>';
    if (empty($tops['internal_anchors'])) {
      echo '<div class="lm-small">No data.</div>';
    } else {
      echo '<ol class="lm-top-list">';
      foreach ($tops['internal_anchors'] as $name => $count) {
        echo '<li><span class="lm-top-name lm-trunc" title="' . esc_attr($name) . '">' . esc_html($name) . '</span><span class="lm-top-count">' . esc_html((string)$count) . '</span></li>';
      }
      echo '</ol>';
    }
    echo '</div>';

    echo '<div class="lm-top-card">';
    echo '<h3>Top 10 External Anchor Text</h3>';
    if (empty($tops['external_anchors'])) {
      echo '<div class="lm-small">No data.</div>';
    } else {
      echo '<ol class="lm-top-list">';
      foreach ($tops['external_anchors'] as $name => $count) {
        echo '<li><span class="lm-top-name lm-trunc" title="' . esc_attr($name) . '">' . esc_html($name) . '</span><span class="lm-top-count">' . esc_html((string)$count) . '</span></li>';
      }
      echo '</ol>';
    }
    echo '</div>';

    echo '<div class="lm-top-card">';
    echo '<h3>Top 10 Cited External Domains</h3>';
    if (empty($tops['external_domains'])) {
      echo '<div class="lm-small">No data.</div>';
    } else {
      echo '<ol class="lm-top-list">';
      foreach ($tops['external_domains'] as $name => $count) {
        echo '<li><span class="lm-top-name lm-trunc" title="' . esc_attr($name) . '">' . esc_html($name) . '</span><span class="lm-top-count">' . esc_html((string)$count) . '</span></li>';
      }
      echo '</ol>';
    }
    echo '</div>';

    echo '<div class="lm-top-card">';
    echo '<h3>Top 10 Cited Internal Pages</h3>';
    if (empty($tops['internal_pages'])) {
      echo '<div class="lm-small">No data.</div>';
    } else {
      echo '<ol class="lm-top-list">';
      foreach ($tops['internal_pages'] as $name => $count) {
        echo '<li><span class="lm-top-name lm-trunc" title="' . esc_attr($name) . '">' . esc_html($name) . '</span><span class="lm-top-count">' . esc_html((string)$count) . '</span></li>';
      }
      echo '</ol>';
    }
    echo '</div>';

    echo '<div class="lm-top-card">';
    echo '<h3>Top 10 Cited External Pages</h3>';
    if (empty($tops['external_pages'])) {
      echo '<div class="lm-small">No data.</div>';
    } else {
      echo '<ol class="lm-top-list">';
      foreach ($tops['external_pages'] as $name => $count) {
        echo '<li><span class="lm-top-name lm-trunc" title="' . esc_attr($name) . '">' . esc_html($name) . '</span><span class="lm-top-count">' . esc_html((string)$count) . '</span></li>';
      }
      echo '</ol>';
    }
    echo '</div>';

    echo '</div>'; // lm-top-grid
    
    // Recent Change Log
    $audit_logs = $this->get_audit_log(10);
    if (!empty($audit_logs)) {
      echo '<div class="lm-card lm-card-full lm-stack-sm">';
      $this->render_admin_section_intro(
        __('Recent Change Log', 'links-manager'),
        __('Review the latest link updates recorded from the editor and bulk update tools.', 'links-manager')
      );
      echo '<div class="lm-table-wrap">';
      echo '<table class="widefat striped lm-table lm-audit-table" style="font-size:12px;">';
      echo '<thead><tr>';
      echo $this->table_header_with_tooltip('', '#', 'Row number in current table.', 'left');
      echo $this->table_header_with_tooltip('', 'Time', 'Timestamp when the action was logged.', 'left');
      echo $this->table_header_with_tooltip('', 'User', 'User who triggered the action.');
      echo $this->table_header_with_tooltip('', 'Action', 'Type of operation performed.');
      echo $this->table_header_with_tooltip('', 'Post', 'Source post ID affected by action.');
      echo $this->table_header_with_tooltip('', 'Old URL', 'Original URL before update.');
      echo $this->table_header_with_tooltip('', 'New URL', 'Updated URL after action.');
      echo $this->table_header_with_tooltip('', 'Status', 'Operation result: success or failed.', 'right');
      echo '</tr></thead><tbody>';
      $auditRowNo = 1;
      foreach ($audit_logs as $log) {
        $status_color = $log->status === 'success' ? '#4caf50' : '#d63638';
        echo '<tr>';
        echo '<td>' . esc_html((string)$auditRowNo) . '</td>';
        echo '<td>' . esc_html(substr((string)$log->timestamp, 0, 19)) . '</td>';
        echo '<td>' . esc_html((string)$log->user_name) . '</td>';
        echo '<td><small>' . esc_html((string)$log->action) . '</small></td>';
        echo '<td>' . esc_html((string)$log->post_id) . '</td>';
        echo '<td><span class="lm-trunc" title="' . esc_attr((string)$log->old_url) . '">' . esc_html((string)$log->old_url) . '</span></td>';
        echo '<td><span class="lm-trunc" title="' . esc_attr((string)$log->new_url) . '">' . esc_html((string)$log->new_url) . '</span></td>';
        echo '<td><span style="color:' . esc_attr($status_color) . '; font-weight:bold;">' . esc_html((string)$log->status) . '</span></td>';
        echo '</tr>';
        $auditRowNo++;
      }
      echo '</tbody></table>';
      echo '</div>';
      echo '</div>';
    }
    
    echo '</div>';
  }
}
