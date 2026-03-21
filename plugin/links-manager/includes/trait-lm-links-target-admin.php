<?php
/**
 * Links Target admin page rendering helpers.
 */

if (!defined('ABSPATH')) {
  exit;
}

trait LM_Links_Target_Admin_Trait {
  public function render_admin_links_target_page() {
    if (!$this->current_user_can_access_plugin()) wp_die($this->unauthorized_message());

    $msg = isset($_GET['lm_msg']) ? sanitize_text_field((string)$_GET['lm_msg']) : '';
    $msgClass = $this->notice_class_for_message($msg, 'success');
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
    $groupingExportUrl = add_query_arg([
      'action' => 'lm_export_anchor_grouping_csv',
      self::NONCE_NAME => wp_create_nonce(self::NONCE_ACTION),
      'lm_group_orderby' => $groupOrderby,
      'lm_group_order' => $groupOrder,
      'lm_group_search' => $groupSearch,
      'lm_group_search_mode' => $groupSearchMode,
      'lm_group_filter' => $groupFilterSelected,
    ], admin_url('admin-post.php'));
    $targets = $this->sync_targets_with_groups($this->get_anchor_targets(), $groups);
    $targetsMap = [];
    $groupAnchorsMap = [];
    foreach ($targets as $t) {
      $t = trim((string)$t);
      if ($t === '') continue;
      $k = strtolower($t);
      if (!isset($targetsMap[$k])) $targetsMap[$k] = $t;
    }

    echo '<div class="wrap lm-wrap">';
    echo '<h1 class="lm-page-title">Links Manager - Links Target</h1>';
    if ($msg !== '') echo '<div class="notice notice-' . esc_attr($msgClass) . '"><p>' . esc_html($msg) . '</p></div>';

    echo '<div class="lm-grid">';
    echo '<div class="lm-card lm-card-full">';
    echo '<h2 style="margin-top:0;">Anchor Grouping</h2>';
    echo '<form method="get" action="" style="margin:0 0 8px;">';
    echo '<input type="hidden" name="page" value="links-manager-target"/>';
    echo '<div class="lm-filter-grid">';
    echo '<div class="lm-filter-field">';
    echo '<div class="lm-small" style="margin-bottom:6px;">Order By</div>';
    echo '<select name="lm_group_orderby">';
    echo '<option value="tag"' . selected($groupOrderby, 'tag', false) . '>Group</option>';
    echo '<option value="total_anchors"' . selected($groupOrderby, 'total_anchors', false) . '>Total Anchors</option>';
    echo '<option value="total_usage"' . selected($groupOrderby, 'total_usage', false) . '>Total Usage</option>';
    echo '<option value="inlink_usage"' . selected($groupOrderby, 'inlink_usage', false) . '>Total Inlinks</option>';
    echo '<option value="outbound_usage"' . selected($groupOrderby, 'outbound_usage', false) . '>Total Outbound</option>';
    echo '</select>';
    echo '</div>';
    echo '<div class="lm-filter-field">';
    echo '<div class="lm-small" style="margin-bottom:6px;">Sort</div>';
    echo '<select name="lm_group_order">';
    echo '<option value="ASC"' . selected($groupOrder, 'ASC', false) . '>ASC</option>';
    echo '<option value="DESC"' . selected($groupOrder, 'DESC', false) . '>DESC</option>';
    echo '</select>';
    echo '</div>';
    echo '<div class="lm-filter-field">';
    echo '<div class="lm-small" style="margin-bottom:6px;">Search Group Name</div>';
    echo '<input type="text" name="lm_group_search" value="' . esc_attr($groupSearch) . '" class="regular-text" placeholder="group keyword" />';
    echo '</div>';
    echo '<div class="lm-filter-field">';
    echo '<div class="lm-small" style="margin-bottom:6px;">Text Search Mode</div>';
    echo '<select name="lm_group_search_mode">';
    foreach ($this->get_text_match_modes() as $modeKey => $modeLabel) {
      echo '<option value="' . esc_attr($modeKey) . '"' . selected($groupSearchMode, $modeKey, false) . '>' . esc_html($modeLabel) . '</option>';
    }
    echo '</select>';
    echo '</div>';
    echo '<div class="lm-filter-field lm-filter-field-wide">';
    echo '<div class="lm-small" style="margin-bottom:6px;">Filter Groups (checklist)</div>';
    echo '<div class="lm-checklist">';
    echo '<label style="display:block; margin:0 0 4px;"><input type="checkbox" name="lm_group_filter[]" value="no_group"' . checked(in_array('no_group', $groupFilterSelected, true), true, false) . ' /> No Group</label>';
    foreach ($groupNames as $gn) {
      echo '<label style="display:block; margin:0 0 4px;"><input type="checkbox" name="lm_group_filter[]" value="' . esc_attr($gn) . '"' . checked(in_array($gn, $groupFilterSelected, true), true, false) . ' /> ' . esc_html($gn) . '</label>';
    }
    echo '</div>';
    echo '</div>';
    echo '<div class="lm-filter-field lm-filter-field-full">';
    submit_button('Apply', 'secondary', 'submit', false);
    echo ' <a class="button button-secondary" href="' . esc_url($groupingExportUrl) . '">Export CSV</a>';
    echo ' <a class="button" href="' . esc_url(admin_url('admin.php?page=links-manager-target')) . '">Reset Filter</a>';
    echo '</div>';
    echo '</div>';
    echo '</form>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:0 0 8px; padding:10px; border:1px solid #e5e7eb; border-radius:6px; background:#f9fafb;">';
    echo '<input type="hidden" name="action" value="lm_save_anchor_groups"/>';
    echo '<input type="hidden" name="' . esc_attr(self::NONCE_NAME) . '" value="' . esc_attr(wp_create_nonce(self::NONCE_ACTION)) . '"/>';
    echo '<label class="lm-small" style="display:block; margin-bottom:6px;">Add new group:</label>';
    echo '<input type="text" name="lm_group_name" class="regular-text" placeholder="Group name" required />';
    submit_button('Save Group', 'secondary', 'submit', false);
    echo '</form>';
    echo '<form id="lm-bulk-delete-groups-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:0 0 8px;">';
    echo '<input type="hidden" name="action" value="lm_bulk_delete_anchor_groups"/>';
    echo '<input type="hidden" name="' . esc_attr(self::NONCE_NAME) . '" value="' . esc_attr(wp_create_nonce(self::NONCE_ACTION)) . '"/>';
    submit_button('Delete Selected Groups', 'delete', 'submit', false, ['onclick' => "return confirm('Delete selected groups?');"]);
    echo '</form>';
    echo '<div class="lm-table-wrap lm-summary-table-wrap">';
    echo '<table class="widefat striped lm-table">';
    echo '<thead><tr>';
    echo '<th class="lm-col-block"><input type="checkbox" id="lm-select-all-groups" /></th>';
    echo $this->table_header_with_tooltip('lm-col-group', 'Group', 'Anchor group name.', 'left');
    echo $this->table_header_with_tooltip('lm-col-count', 'Total Anchors', 'Number of anchors inside this group.');
    echo $this->table_header_with_tooltip('lm-col-total', 'Total Usage Across All Pages (All Link Types)', 'Combined uses of all anchors in this group.');
    echo $this->table_header_with_tooltip('lm-col-count', '%', 'Share of total usage among groups.');
    echo $this->table_header_with_tooltip('lm-col-inlink', 'Total Use as Inlinks', 'Usage count when links are internal.');
    echo $this->table_header_with_tooltip('lm-col-count', '%', 'Share of inlink usage among groups.');
    echo $this->table_header_with_tooltip('lm-col-outbound', 'Total Use as Outbound', 'Usage count when links are outbound.');
    echo $this->table_header_with_tooltip('lm-col-count', '%', 'Share of outbound usage among groups.');
    echo $this->table_header_with_tooltip('lm-col-action', 'Action', 'Edit or delete actions for group.', 'right');
    echo '</tr></thead><tbody>';

    if (empty($groups)) {
      echo '<tr><td colspan="10">No groups yet.</td></tr>';
    } else {
      $all = $this->build_or_get_cache('any', false, $this->sanitize_wpml_lang_filter($this->get_wpml_current_language()));
      $anchorUsage = [];
      foreach ($all as $row) {
        $a = trim((string)($row['anchor_text'] ?? ''));
        if ($a === '') continue;
        $k = strtolower($a);
        if (!isset($anchorUsage[$k])) $anchorUsage[$k] = ['total' => 0, 'inlink' => 0, 'outbound' => 0];
        $anchorUsage[$k]['total']++;
        if (($row['link_type'] ?? '') === 'inlink') $anchorUsage[$k]['inlink']++;
        if (($row['link_type'] ?? '') === 'exlink') $anchorUsage[$k]['outbound']++;
      }

      $groupCounts = [];
      $groupUsage = [];
      $totalAnchors = 0;
      $groupIndexByName = [];
      $groupedKeys = [];
      $groupAnchorsMap = [];
      foreach ($groups as $idx => $g) {
        $gname = trim((string)($g['name'] ?? ''));
        if ($gname === '') continue;
        if (!isset($groupIndexByName[$gname])) $groupIndexByName[$gname] = $idx;
        $anchors = isset($g['anchors']) ? (array)$g['anchors'] : [];
        $count = 0;
        $gUsage = ['total' => 0, 'inlink' => 0, 'outbound' => 0];
        foreach ($anchors as $a) {
          $a = trim((string)$a);
          if ($a === '') continue;
          $count++;
          $k = strtolower($a);
          $groupedKeys[$k] = true;
          if (!isset($groupAnchorsMap[$k])) $groupAnchorsMap[$k] = $a;
          if (isset($anchorUsage[$k])) {
            $gUsage['total'] += $anchorUsage[$k]['total'];
            $gUsage['inlink'] += $anchorUsage[$k]['inlink'];
            $gUsage['outbound'] += $anchorUsage[$k]['outbound'];
          }
        }
        $groupCounts[$gname] = $count;
        $groupUsage[$gname] = $gUsage;
        $totalAnchors += $count;
      }

      if (empty($groupCounts)) {
        echo '<tr><td colspan="10">No groups yet.</td></tr>';
      } else {
        $noGroupCount = 0;
        $noGroupUsage = ['total' => 0, 'inlink' => 0, 'outbound' => 0];
        foreach ($targetsMap as $k => $label) {
          if (!isset($groupedKeys[$k])) {
            $noGroupCount++;
            if (isset($anchorUsage[$k])) {
              $noGroupUsage['total'] += $anchorUsage[$k]['total'];
              $noGroupUsage['inlink'] += $anchorUsage[$k]['inlink'];
              $noGroupUsage['outbound'] += $anchorUsage[$k]['outbound'];
            }
          }
        }
        if ($noGroupCount > 0) {
          if (!isset($groupCounts['No Group'])) $groupCounts['No Group'] = 0;
          $groupCounts['No Group'] += $noGroupCount;
          if (!isset($groupUsage['No Group'])) $groupUsage['No Group'] = ['total' => 0, 'inlink' => 0, 'outbound' => 0];
          $groupUsage['No Group']['total'] += $noGroupUsage['total'];
          $groupUsage['No Group']['inlink'] += $noGroupUsage['inlink'];
          $groupUsage['No Group']['outbound'] += $noGroupUsage['outbound'];
          $totalAnchors += $noGroupCount;
        }

        $filteredGroupUsage = [];
        foreach ($groupUsage as $gname => $usage) {
          $usageKey = $gname === 'No Group' ? 'no_group' : $gname;
          if (!empty($groupFilterSelected) && !in_array($usageKey, $groupFilterSelected, true)) {
            continue;
          }
          if ($groupSearch !== '' && !$this->text_matches((string)$gname, (string)$groupSearch, (string)$groupSearchMode)) {
            continue;
          }
          $filteredGroupUsage[$gname] = $usage;
        }

        $totalUsage = 0;
        $totalInlinks = 0;
        $totalOutbound = 0;
        foreach ($filteredGroupUsage as $usage) {
          $totalUsage += (int)$usage['total'];
          $totalInlinks += (int)$usage['inlink'];
          $totalOutbound += (int)$usage['outbound'];
        }

        $entries = [];
        $sumBase = 0;
        $sumBaseOutbound = 0;
        $i = 0;
        foreach ($filteredGroupUsage as $gname => $usageRow) {
          $count = isset($groupCounts[$gname]) ? (int)$groupCounts[$gname] : 0;
          $usage = (int)($usageRow['total'] ?? 0);
          $usageInlink = (int)($usageRow['inlink'] ?? 0);
          $usageOutbound = (int)($usageRow['outbound'] ?? 0);

          $raw = ($totalUsage > 0) ? (($usage / $totalUsage) * 100) : 0;
          $base = (int)floor($raw);
          $frac = $raw - $base;
          $rawInlink = ($totalInlinks > 0) ? (($usageInlink / $totalInlinks) * 100) : 0;
          $rawOutbound = ($totalOutbound > 0) ? (($usageOutbound / $totalOutbound) * 100) : 0;
          $baseOutbound = (int)floor($rawOutbound);
          $fracOutbound = $rawOutbound - $baseOutbound;

          $entries[] = [
            'name' => $gname,
            'count' => $count,
            'usage' => $usage,
            'usageInlink' => $usageInlink,
            'usageOutbound' => $usageOutbound,
            'base' => $base,
            'frac' => $frac,
            'rawInlink' => $rawInlink,
            'baseOutbound' => $baseOutbound,
            'fracOutbound' => $fracOutbound,
            'order' => $i,
          ];
          $sumBase += $base;
          $sumBaseOutbound += $baseOutbound;
          $i++;
        }

        $remainder = max(0, 100 - $sumBase);
        if ($totalUsage > 0 && $remainder > 0 && count($entries) > 0) {
          usort($entries, function($a, $b) {
            if ($a['frac'] === $b['frac']) return $a['order'] <=> $b['order'];
            return ($a['frac'] < $b['frac']) ? 1 : -1;
          });
          $len = count($entries);
          for ($k = 0; $k < $remainder; $k++) {
            $entries[$k % $len]['base']++;
          }
          usort($entries, function($a, $b) {
            return $a['order'] <=> $b['order'];
          });
        }

        $remainderOutbound = max(0, 100 - $sumBaseOutbound);
        if ($totalOutbound > 0 && $remainderOutbound > 0 && count($entries) > 0) {
          usort($entries, function($a, $b) {
            if ($a['fracOutbound'] === $b['fracOutbound']) return $a['order'] <=> $b['order'];
            return ($a['fracOutbound'] < $b['fracOutbound']) ? 1 : -1;
          });
          $len = count($entries);
          for ($k = 0; $k < $remainderOutbound; $k++) {
            $entries[$k % $len]['baseOutbound']++;
          }
          usort($entries, function($a, $b) {
            return $a['order'] <=> $b['order'];
          });
        }

        usort($entries, function($a, $b) use ($groupOrderby, $groupOrder) {
          $dir = $groupOrder === 'ASC' ? 1 : -1;
          $cmp = 0;
          switch ($groupOrderby) {
            case 'total_anchors':
              $cmp = ((int)$a['count'] <=> (int)$b['count']);
              break;
            case 'total_usage':
              $cmp = ((int)$a['usage'] <=> (int)$b['usage']);
              break;
            case 'inlink_usage':
              $cmp = ((int)$a['usageInlink'] <=> (int)$b['usageInlink']);
              break;
            case 'outbound_usage':
              $cmp = ((int)$a['usageOutbound'] <=> (int)$b['usageOutbound']);
              break;
            case 'tag':
            default:
              $cmp = strcmp((string)$a['name'], (string)$b['name']);
              break;
          }
          if ($cmp === 0) {
            $cmp = strcmp((string)$a['name'], (string)$b['name']);
          }
          return $cmp * $dir;
        });

        foreach ($entries as $e) {
          $gidx = isset($groupIndexByName[$e['name']]) ? (int)$groupIndexByName[$e['name']] : -1;
          $editGroupUrl = $gidx >= 0 ? admin_url('admin.php?page=links-manager-target&lm_edit_group=' . $gidx) : '';
          $delGroupUrl = $gidx >= 0 ? admin_url('admin-post.php?action=lm_delete_anchor_group&' . self::NONCE_NAME . '=' . wp_create_nonce(self::NONCE_ACTION) . '&lm_group_idx=' . $gidx) : '';
          $gUsage = isset($groupUsage[$e['name']]) ? $groupUsage[$e['name']] : ['total' => 0, 'inlink' => 0, 'outbound' => 0];
          echo '<tr>';
          if ($gidx >= 0) {
            echo '<td class="lm-col-block" style="text-align:center;"><input type="checkbox" class="lm-group-check" name="lm_group_indices[]" value="' . esc_attr((string)$gidx) . '" form="lm-bulk-delete-groups-form" /></td>';
          } else {
            echo '<td class="lm-col-block" style="text-align:center;">—</td>';
          }
          echo '<td class="lm-col-group"><span class="lm-trunc" title="' . esc_attr($e['name']) . '">' . esc_html($e['name']) . '</span></td>';
          echo '<td class="lm-col-count" style="text-align:center;">' . esc_html((string)$e['count']) . '</td>';
          echo '<td class="lm-col-total">' . esc_html((string)$gUsage['total']) . '</td>';
          $pctLabel = number_format((float)$e['base'], 1);
          echo '<td class="lm-col-count" style="text-align:center;">' . esc_html($pctLabel) . '%</td>';
          echo '<td class="lm-col-inlink">' . esc_html((string)$gUsage['inlink']) . '</td>';
          $pctInlinkLabel = number_format((float)$e['rawInlink'], 1);
          echo '<td class="lm-col-count" style="text-align:center;">' . esc_html($pctInlinkLabel) . '%</td>';
          echo '<td class="lm-col-outbound">' . esc_html((string)$gUsage['outbound']) . '</td>';
          $pctOutboundLabel = number_format((float)$e['baseOutbound'], 1);
          echo '<td class="lm-col-count" style="text-align:center;">' . esc_html($pctOutboundLabel) . '%</td>';
          if ($gidx >= 0) {
            echo '<td class="lm-col-action"><a class="button button-small" href="' . esc_url($editGroupUrl) . '">Edit</a> <a class="button button-small" href="' . esc_url($delGroupUrl) . '">Delete</a></td>';
          } else {
            echo '<td class="lm-col-action">—</td>';
          }
          echo '</tr>';
        }
      }
    }

    echo '</tbody></table></div>';

    $editGroupIdx = isset($_GET['lm_edit_group']) ? intval($_GET['lm_edit_group']) : -1;
    if ($editGroupIdx >= 0 && isset($groups[$editGroupIdx])) {
      $g = $groups[$editGroupIdx];
      $gname = (string)($g['name'] ?? '');
      $ganchors = isset($g['anchors']) ? (array)$g['anchors'] : [];
      echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:10px 0 0; padding:10px; border:1px solid #e5e7eb; border-radius:6px; background:#f9fafb;">';
      echo '<input type="hidden" name="action" value="lm_update_anchor_group"/>';
      echo '<input type="hidden" name="' . esc_attr(self::NONCE_NAME) . '" value="' . esc_attr(wp_create_nonce(self::NONCE_ACTION)) . '"/>';
      echo '<input type="hidden" name="lm_group_idx" value="' . esc_attr((string)$editGroupIdx) . '"/>';
      echo '<label class="lm-small" style="display:block; margin-bottom:6px;">Edit group:</label>';
      echo '<input type="text" name="lm_group_name" value="' . esc_attr($gname) . '" class="regular-text" placeholder="Group name" />';
      echo '<textarea name="lm_group_anchors" class="large-text" rows="4" style="margin-top:6px;" placeholder="One anchor per line or comma">' . esc_textarea(implode("\n", $ganchors)) . '</textarea>';
      submit_button('Save Changes', 'primary', 'submit', false);
      echo ' <a class="button" href="' . esc_url(admin_url('admin.php?page=links-manager-target')) . '">Cancel</a>';
      echo '</form>';
    }

    echo '</div>';

    echo '<div class="lm-card lm-card-target">';
    echo '<h2 style="margin-top:0;">' . esc_html__('Target Anchor Text', 'links-manager') . '</h2>';
    echo '<div class="lm-small">Targets are checked across all public posts/pages.</div>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:8px;" enctype="multipart/form-data">';
    echo '<input type="hidden" name="action" value="lm_save_anchor_targets"/>';
    echo '<input type="hidden" name="' . esc_attr(self::NONCE_NAME) . '" value="' . esc_attr(wp_create_nonce(self::NONCE_ACTION)) . '"/>';
    echo '<div class="lm-tabs" role="tablist" aria-label="Target Anchor Mode">';
    echo '<button type="button" class="lm-tab is-active" data-lm-tab="only" aria-selected="true">Only anchor</button>';
    echo '<button type="button" class="lm-tab" data-lm-tab="tags" aria-selected="false">Anchor with groups</button>';
    echo '</div>';
    echo '<input type="hidden" name="lm_anchor_mode" value="only"/>';
    echo '<div class="lm-textarea-wrap">';
    echo '<textarea name="lm_anchor_targets" placeholder="Enter anchors, one per line or comma-separated (e.g. buy shoes, contact us)"></textarea>';
    echo '<div class="lm-textarea-hint" data-lm-hint="only">Enter anchors, one per line or comma-separated</div>';
    echo '<div class="lm-textarea-hint" data-lm-hint="tags" style="display:none;">Format: anchor text, group</div>';
    echo '<div class="lm-textarea-actions">';
    echo '<label>CSV or TXT <input type="file" name="lm_anchor_file" accept=".csv,.txt" /></label>';
    echo '</div>';
    echo '</div>';
    submit_button(__('Save Targets', 'links-manager'), 'secondary', 'submit', false);
    echo '</form>';

    $editIdx = isset($_GET['lm_edit_target']) ? intval($_GET['lm_edit_target']) : -1;
    if ($editIdx >= 0 && isset($targets[$editIdx])) {
      echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:10px; padding:10px; border:1px solid #e5e7eb; border-radius:6px; background:#f9fafb;">';
      echo '<input type="hidden" name="action" value="lm_update_anchor_target"/>';
      echo '<input type="hidden" name="' . esc_attr(self::NONCE_NAME) . '" value="' . esc_attr(wp_create_nonce(self::NONCE_ACTION)) . '"/>';
      echo '<input type="hidden" name="lm_target_idx" value="' . esc_attr((string)$editIdx) . '"/>';
      echo '<label class="lm-small" style="display:block; margin-bottom:6px;">Edit target:</label>';
      echo '<input type="text" name="lm_target_value" value="' . esc_attr((string)$targets[$editIdx]) . '" class="regular-text" />';
      submit_button(__('Save Changes', 'links-manager'), 'primary', 'submit', false);
      echo ' <a class="button" href="' . esc_url(admin_url('admin.php?page=links-manager-target')) . '">Cancel</a>';
      echo '</form>';
    }

    echo '</div>';
    echo '<div class="lm-card lm-card-summary lm-card-full">';
    echo '<h2 style="margin-top:0;">' . esc_html__('Anchor Target Summary', 'links-manager') . '</h2>';
    echo '<form id="lm-bulk-delete-targets-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:0 0 8px;">';
    echo '<input type="hidden" name="action" value="lm_bulk_delete_anchor_targets"/>';
    echo '<input type="hidden" name="' . esc_attr(self::NONCE_NAME) . '" value="' . esc_attr(wp_create_nonce(self::NONCE_ACTION)) . '"/>';
    submit_button(__('Delete Selected Targets', 'links-manager'), 'delete', 'submit', false, ['onclick' => "return confirm('" . esc_js(__('Delete selected targets?', 'links-manager')) . "');"]);
    echo '</form>';
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
    $summaryPostCategoryOptions = $this->get_post_term_options('category');
    $summaryPostTagOptions = $this->get_post_term_options('post_tag');
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
    $summaryPerPage = isset($_GET['lm_summary_per_page']) ? intval($_GET['lm_summary_per_page']) : 50;
    if ($summaryPerPage < 10) $summaryPerPage = 10;
    if ($summaryPerPage > 500) $summaryPerPage = 500;
    $summaryPaged = isset($_GET['lm_summary_paged']) ? intval($_GET['lm_summary_paged']) : 1;
    if ($summaryPaged < 1) $summaryPaged = 1;
    $summaryTotalMinNum = $summaryTotalMin === '' ? null : intval($summaryTotalMin);
    $summaryTotalMaxNum = $summaryTotalMax === '' ? null : intval($summaryTotalMax);
    $summaryInMinNum = $summaryInMin === '' ? null : intval($summaryInMin);
    $summaryInMaxNum = $summaryInMax === '' ? null : intval($summaryInMax);
    $summaryOutMinNum = $summaryOutMin === '' ? null : intval($summaryOutMin);
    $summaryOutMaxNum = $summaryOutMax === '' ? null : intval($summaryOutMax);
    $summaryExportUrl = add_query_arg([
      'action' => 'lm_export_links_target_csv',
      self::NONCE_NAME => wp_create_nonce(self::NONCE_ACTION),
      'lm_summary_groups' => $summaryGroupSelected,
      'lm_summary_group_search' => $summaryGroupSearch,
      'lm_summary_post_type' => $summaryPostType,
      'lm_summary_post_category' => $summaryPostCategory,
      'lm_summary_post_tag' => $summaryPostTag,
      'lm_summary_location' => $summaryLocation,
      'lm_summary_source_type' => $summarySourceType,
      'lm_summary_link_type' => $summaryLinkType,
      'lm_summary_value' => $summaryValueContains,
      'lm_summary_source' => $summarySourceContains,
      'lm_summary_title' => $summaryTitleContains,
      'lm_summary_author' => $summaryAuthorContains,
      'lm_summary_seo_flag' => $summarySeoFlag,
      'lm_summary_anchor' => $summaryAnchor,
      'lm_summary_anchor_search' => $summaryAnchorSearch,
      'lm_summary_search_mode' => $summarySearchMode,
      'lm_summary_total_min' => $summaryTotalMin,
      'lm_summary_total_max' => $summaryTotalMax,
      'lm_summary_in_min' => $summaryInMin,
      'lm_summary_in_max' => $summaryInMax,
      'lm_summary_out_min' => $summaryOutMin,
      'lm_summary_out_max' => $summaryOutMax,
      'lm_summary_orderby' => $summaryOrderby,
      'lm_summary_order' => $summaryOrder,
    ], admin_url('admin-post.php'));
    echo '<form method="get" action="" style="margin:8px 0 10px;">';
    echo '<input type="hidden" name="page" value="links-manager-target"/>';
    echo '<div class="lm-filter-grid">';
    echo '<div class="lm-filter-field lm-filter-field-wide">';
    echo '<div class="lm-small" style="margin-bottom:6px;">Group (checklist)</div>';
    echo '<div class="lm-checklist">';
    echo '<label style="display:block; margin:0 0 4px;"><input type="checkbox" name="lm_summary_groups[]" value="no_group"' . checked(in_array('no_group', $summaryGroupSelected, true), true, false) . ' /> No Group</label>';
    foreach ($groupNames as $gn) {
      echo '<label style="display:block; margin:0 0 4px;"><input type="checkbox" name="lm_summary_groups[]" value="' . esc_attr($gn) . '"' . checked(in_array($gn, $summaryGroupSelected, true), true, false) . ' /> ' . esc_html($gn) . '</label>';
    }
    echo '</div>';
    echo '<div class="lm-small" style="margin-top:6px;">Leave all unchecked to show all groups.</div>';
    echo '</div>';

    echo '<div class="lm-filter-field">';
    echo '<div class="lm-small" style="margin-bottom:6px;">Post Type</div>';
    echo '<select name="lm_summary_post_type" class="lm-filter-select">';
    echo '<option value="any"' . selected($summaryPostType, 'any', false) . '>All</option>';
    foreach ($summaryPostTypeOptions as $ptKey => $ptLabel) {
      echo '<option value="' . esc_attr((string)$ptKey) . '"' . selected($summaryPostType, (string)$ptKey, false) . '>' . esc_html((string)$ptLabel) . ' (' . esc_html((string)$ptKey) . ')</option>';
    }
    echo '</select>';
    echo '</div>';

    echo '<div class="lm-filter-field">';
    echo '<div class="lm-small" style="margin-bottom:6px;">Post Category</div>';
    echo '<select name="lm_summary_post_category" class="lm-filter-select">';
    echo '<option value="0"' . selected((int)$summaryPostCategory, 0, false) . '>All</option>';
    foreach ($summaryPostCategoryOptions as $termId => $termLabel) {
      echo '<option value="' . esc_attr((string)$termId) . '"' . selected((int)$summaryPostCategory, (int)$termId, false) . '>' . esc_html($termLabel) . '</option>';
    }
    echo '</select>';
    echo '</div>';

    echo '<div class="lm-filter-field">';
    echo '<div class="lm-small" style="margin-bottom:6px;">Post Tag</div>';
    echo '<select name="lm_summary_post_tag" class="lm-filter-select">';
    echo '<option value="0"' . selected((int)$summaryPostTag, 0, false) . '>All</option>';
    foreach ($summaryPostTagOptions as $termId => $termLabel) {
      echo '<option value="' . esc_attr((string)$termId) . '"' . selected((int)$summaryPostTag, (int)$termId, false) . '>' . esc_html($termLabel) . '</option>';
    }
    echo '</select>';
    echo '</div>';

    echo '<div class="lm-filter-field">';
    echo '<div class="lm-small" style="margin-bottom:6px;">Link Type</div>';
    echo '<select name="lm_summary_link_type" class="lm-filter-select">';
    echo '<option value="any"' . selected($summaryLinkType, 'any', false) . '>All</option>';
    echo '<option value="inlink"' . selected($summaryLinkType, 'inlink', false) . '>Internal</option>';
    echo '<option value="exlink"' . selected($summaryLinkType, 'exlink', false) . '>External</option>';
    echo '</select>';
    echo '</div>';

    echo '<div class="lm-filter-field">';
    echo '<div class="lm-small" style="margin-bottom:6px;">Source Type</div>';
    echo '<select name="lm_summary_source_type" class="lm-filter-select">';
    foreach ($this->get_filterable_source_type_options(true) as $sourceKey => $sourceLabel) {
      echo '<option value="' . esc_attr((string)$sourceKey) . '"' . selected($summarySourceType, (string)$sourceKey, false) . '>' . esc_html((string)$sourceLabel) . '</option>';
    }
    echo '</select>';
    echo '</div>';

    echo '<div class="lm-filter-field">';
    echo '<div class="lm-small" style="margin-bottom:6px;">SEO Flags</div>';
    echo '<select name="lm_summary_seo_flag" class="lm-filter-select">';
    echo '<option value="any"' . selected($summarySeoFlag, 'any', false) . '>All</option>';
    echo '<option value="dofollow"' . selected($summarySeoFlag, 'dofollow', false) . '>Dofollow</option>';
    echo '<option value="nofollow"' . selected($summarySeoFlag, 'nofollow', false) . '>Nofollow</option>';
    echo '<option value="sponsored"' . selected($summarySeoFlag, 'sponsored', false) . '>Sponsored</option>';
    echo '<option value="ugc"' . selected($summarySeoFlag, 'ugc', false) . '>UGC</option>';
    echo '</select>';
    echo '</div>';

    echo '<div class="lm-filter-field">';
    echo '<div class="lm-small" style="margin-bottom:6px;">Link Location</div>';
    echo '<input type="text" name="lm_summary_location" value="' . esc_attr($summaryLocation === 'any' ? '' : (string)$summaryLocation) . '" class="regular-text" placeholder="content / excerpt / meta:xxx" />';
    echo '</div>';

    echo '<div class="lm-filter-field">';
    echo '<div class="lm-small" style="margin-bottom:6px;">' . esc_html__('Search Group Name', 'links-manager') . '</div>';
    echo '<input type="text" name="lm_summary_group_search" value="' . esc_attr($summaryGroupSearch) . '" class="regular-text" placeholder="group keyword" />';
    echo '</div>';

    echo '<div class="lm-filter-field">';
    echo '<div class="lm-small" style="margin-bottom:6px;">' . esc_html__('Search Destination URL', 'links-manager') . '</div>';
    echo '<input type="text" name="lm_summary_value" value="' . esc_attr($summaryValueContains) . '" class="regular-text" placeholder="example.com / /contact" />';
    echo '</div>';

    echo '<div class="lm-filter-field">';
    echo '<div class="lm-small" style="margin-bottom:6px;">' . esc_html__('Search Source URL', 'links-manager') . '</div>';
    echo '<input type="text" name="lm_summary_source" value="' . esc_attr($summarySourceContains) . '" class="regular-text" placeholder="/category /slug" />';
    echo '</div>';

    echo '<div class="lm-filter-field">';
    echo '<div class="lm-small" style="margin-bottom:6px;">' . esc_html__('Search Title', 'links-manager') . '</div>';
    echo '<input type="text" name="lm_summary_title" value="' . esc_attr($summaryTitleContains) . '" class="regular-text" placeholder="post title" />';
    echo '</div>';

    echo '<div class="lm-filter-field">';
    echo '<div class="lm-small" style="margin-bottom:6px;">' . esc_html__('Search Author', 'links-manager') . '</div>';
    echo '<input type="text" name="lm_summary_author" value="' . esc_attr($summaryAuthorContains) . '" class="regular-text" placeholder="author" />';
    echo '</div>';

    echo '<div class="lm-filter-field">';
    echo '<div class="lm-small" style="margin-bottom:6px;">' . esc_html__('Search Anchor Text', 'links-manager') . '</div>';
    echo '<input type="text" name="lm_summary_anchor_search" value="' . esc_attr($summaryAnchorSearch) . '" class="regular-text" placeholder="anchor keyword" />';
    echo '</div>';

    echo '<div class="lm-filter-field">';
    echo '<div class="lm-small" style="margin-bottom:6px;">Text Search Mode</div>';
    echo '<select name="lm_summary_search_mode">';
    foreach ($this->get_text_match_modes() as $modeKey => $modeLabel) {
      echo '<option value="' . esc_attr($modeKey) . '"' . selected($summarySearchMode, $modeKey, false) . '>' . esc_html($modeLabel) . '</option>';
    }
    echo '</select>';
    echo '</div>';

    echo '<div class="lm-filter-field">';
    echo '<div class="lm-small" style="margin-bottom:6px;">Total min</div>';
    echo '<input type="number" name="lm_summary_total_min" value="' . esc_attr($summaryTotalMin) . '" placeholder="Total min" />';
    echo '</div>';

    echo '<div class="lm-filter-field">';
    echo '<div class="lm-small" style="margin-bottom:6px;">Total max</div>';
    echo '<input type="number" name="lm_summary_total_max" value="' . esc_attr($summaryTotalMax) . '" placeholder="Total max" />';
    echo '</div>';

    echo '<div class="lm-filter-field">';
    echo '<div class="lm-small" style="margin-bottom:6px;">Inlink min</div>';
    echo '<input type="number" name="lm_summary_in_min" value="' . esc_attr($summaryInMin) . '" placeholder="Inlink min" />';
    echo '</div>';

    echo '<div class="lm-filter-field">';
    echo '<div class="lm-small" style="margin-bottom:6px;">Inlink max</div>';
    echo '<input type="number" name="lm_summary_in_max" value="' . esc_attr($summaryInMax) . '" placeholder="Inlink max" />';
    echo '</div>';

    echo '<div class="lm-filter-field">';
    echo '<div class="lm-small" style="margin-bottom:6px;">Outbound min</div>';
    echo '<input type="number" name="lm_summary_out_min" value="' . esc_attr($summaryOutMin) . '" placeholder="Outbound min" />';
    echo '</div>';

    echo '<div class="lm-filter-field">';
    echo '<div class="lm-small" style="margin-bottom:6px;">Outbound max</div>';
    echo '<input type="number" name="lm_summary_out_max" value="' . esc_attr($summaryOutMax) . '" placeholder="Outbound max" />';
    echo '</div>';

    echo '<div class="lm-filter-field">';
    echo '<div class="lm-small" style="margin-bottom:6px;">' . esc_html__('Order By', 'links-manager') . '</div>';
    echo '<select name="lm_summary_orderby">';
    echo '<option value="group"' . selected($summaryOrderby, 'group', false) . '>Group</option>';
    echo '<option value="anchor"' . selected($summaryOrderby, 'anchor', false) . '>Anchor Text</option>';
    echo '<option value="total"' . selected($summaryOrderby, 'total', false) . '>Total Usage</option>';
    echo '<option value="inlink"' . selected($summaryOrderby, 'inlink', false) . '>Total Inlinks</option>';
    echo '<option value="outbound"' . selected($summaryOrderby, 'outbound', false) . '>Total Outbound</option>';
    echo '</select>';
    echo '</div>';

    echo '<div class="lm-filter-field">';
    echo '<div class="lm-small" style="margin-bottom:6px;">Sort</div>';
    echo '<select name="lm_summary_order">';
    echo '<option value="ASC"' . selected($summaryOrder, 'ASC', false) . '>ASC</option>';
    echo '<option value="DESC"' . selected($summaryOrder, 'DESC', false) . '>DESC</option>';
    echo '</select>';
    echo '</div>';

    echo '<div class="lm-filter-field">';
    echo '<div class="lm-small" style="margin-bottom:6px;">' . esc_html__('Per Page', 'links-manager') . '</div>';
    echo '<input type="number" name="lm_summary_per_page" value="' . esc_attr((string)$summaryPerPage) . '" min="10" max="500" />';
    echo '</div>';

    echo '<div class="lm-filter-field lm-filter-field-full">';
    echo '<div class="lm-small" style="margin:0 0 6px;">Applies to Search Group Name, Search Destination URL, Search Source URL, Search Title, Search Author, and Search Anchor Text.</div>';
    submit_button(__('Apply Filters', 'links-manager'), 'secondary', 'submit', false);
    echo ' <a class="button button-secondary" href="' . esc_url($summaryExportUrl) . '">' . esc_html__('Export CSV', 'links-manager') . '</a>';
    echo ' <a class="button" href="' . esc_url(admin_url('admin.php?page=links-manager-target')) . '">' . esc_html__('Reset', 'links-manager') . '</a>';
    echo '</div>';
    echo '</div>';
    echo '</form>';

    $summaryTargetsMap = $targetsMap + $groupAnchorsMap;
    $targetIndexByKey = [];
    foreach ($targets as $i => $t) {
      $k = strtolower(trim((string)$t));
      if ($k === '' || isset($targetIndexByKey[$k])) continue;
      $targetIndexByKey[$k] = $i;
    }

    $totalFiltered = 0;
    $totalPages = 1;
    $filteredRows = [];

    if (!empty($summaryTargetsMap)) {
      $counts = [];

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

      $indexedSummaryRows = $this->get_indexed_all_anchor_text_summary_rows($summaryFilters);
      if (!empty($indexedSummaryRows)) {
        foreach ($indexedSummaryRows as $summaryRow) {
          $k = strtolower(trim((string)($summaryRow['anchor_text'] ?? '')));
          if ($k === '') continue;
          $counts[$k] = [
            'total' => (int)($summaryRow['total'] ?? 0),
            'inlink' => (int)($summaryRow['inlink'] ?? 0),
            'outbound' => (int)($summaryRow['outbound'] ?? 0),
          ];
        }
      } else {
        $all = $this->get_canonical_rows_for_scope('any', false, $this->sanitize_wpml_lang_filter($this->get_wpml_current_language()), $summaryFilters);
        $allowedSummaryPostIds = $this->get_post_ids_by_post_terms((int)$summaryPostCategory, (int)$summaryPostTag);

        foreach ($all as $row) {
          if (is_array($allowedSummaryPostIds)) {
            $rowPostId = isset($row['post_id']) ? (string)intval($row['post_id']) : '';
            if ($rowPostId === '' || !isset($allowedSummaryPostIds[$rowPostId])) continue;
          }
          if ($summaryPostType !== 'any' && (string)($row['post_type'] ?? '') !== (string)$summaryPostType) continue;
          if ($summaryLocation !== 'any' && (string)($row['link_location'] ?? '') !== (string)$summaryLocation) continue;
          if ($summarySourceType !== 'any' && (string)($row['source'] ?? '') !== (string)$summarySourceType) continue;
          if ($summaryLinkType !== 'any' && (string)($row['link_type'] ?? '') !== (string)$summaryLinkType) continue;
          if ($summaryValueContains !== '' && !$this->text_matches((string)($row['link'] ?? ''), $summaryValueContains, $summarySearchMode)) continue;
          if ($summarySourceContains !== '' && !$this->text_matches((string)($row['page_url'] ?? ''), $summarySourceContains, $summarySearchMode)) continue;
          if ($summaryTitleContains !== '' && !$this->text_matches((string)($row['post_title'] ?? ''), $summaryTitleContains, $summarySearchMode)) continue;
          if ($summaryAuthorContains !== '' && !$this->text_matches((string)($row['post_author'] ?? ''), $summaryAuthorContains, $summarySearchMode)) continue;
          if ($summarySeoFlag !== 'any') {
            $nofollow = (string)($row['rel_nofollow'] ?? '0') === '1';
            $sponsored = (string)($row['rel_sponsored'] ?? '0') === '1';
            $ugc = (string)($row['rel_ugc'] ?? '0') === '1';
            if ($summarySeoFlag === 'dofollow' && ($nofollow || $sponsored || $ugc)) continue;
            if ($summarySeoFlag === 'nofollow' && !$nofollow) continue;
            if ($summarySeoFlag === 'sponsored' && !$sponsored) continue;
            if ($summarySeoFlag === 'ugc' && !$ugc) continue;
          }
          $a = trim((string)($row['anchor_text'] ?? ''));
          if ($a === '') continue;
          $k = strtolower($a);
          if (!isset($counts[$k])) $counts[$k] = ['total' => 0, 'inlink' => 0, 'outbound' => 0];
          $counts[$k]['total']++;
          if (($row['link_type'] ?? '') === 'inlink') $counts[$k]['inlink']++;
          if (($row['link_type'] ?? '') === 'exlink') $counts[$k]['outbound']++;
        }
      }

      $anchorToGroups = [];
      foreach ($groups as $g) {
        $gname = (string)($g['name'] ?? '');
        $anchors = (array)($g['anchors'] ?? []);
        foreach ($anchors as $a) {
          $a = trim((string)$a);
          if ($a === '') continue;
          $key = strtolower($a);
          if (!isset($anchorToGroups[$key])) $anchorToGroups[$key] = [];
          if ($gname !== '') $anchorToGroups[$key][$gname] = true;
        }
      }

      foreach ($summaryTargetsMap as $tKey => $tLabel) {
        $c = $counts[$tKey] ?? ['total' => 0, 'inlink' => 0, 'outbound' => 0];
        $glist = isset($anchorToGroups[$tKey]) ? implode(', ', array_keys($anchorToGroups[$tKey])) : '—';
        $groupSearchText = isset($anchorToGroups[$tKey]) && !empty($anchorToGroups[$tKey]) ? $glist : 'No Group';
        if (!empty($summaryGroupSelected)) {
          $gset = isset($anchorToGroups[$tKey]) ? array_keys($anchorToGroups[$tKey]) : [];
          $includeByGroup = false;
          foreach ($summaryGroupSelected as $selectedGroup) {
            if ($selectedGroup === 'no_group' && empty($gset)) {
              $includeByGroup = true;
              break;
            }
            if ($selectedGroup !== 'no_group' && in_array($selectedGroup, $gset, true)) {
              $includeByGroup = true;
              break;
            }
          }
          if (!$includeByGroup) continue;
        }
        if ($summaryGroupSearch !== '' && !$this->text_matches((string)$groupSearchText, (string)$summaryGroupSearch, (string)$summarySearchMode)) continue;
        if ($summaryAnchorSearch !== '' && !$this->text_matches((string)$tLabel, (string)$summaryAnchorSearch, (string)$summarySearchMode)) continue;
        if ($summaryTotalMinNum !== null && (int)$c['total'] < $summaryTotalMinNum) continue;
        if ($summaryTotalMaxNum !== null && (int)$c['total'] > $summaryTotalMaxNum) continue;
        if ($summaryInMinNum !== null && (int)$c['inlink'] < $summaryInMinNum) continue;
        if ($summaryInMaxNum !== null && (int)$c['inlink'] > $summaryInMaxNum) continue;
        if ($summaryOutMinNum !== null && (int)$c['outbound'] < $summaryOutMinNum) continue;
        if ($summaryOutMaxNum !== null && (int)$c['outbound'] > $summaryOutMaxNum) continue;
        if (isset($anchorToGroups[$tKey]) && is_array($anchorToGroups[$tKey]) && count($anchorToGroups[$tKey]) > 0) {
          $keys = array_keys($anchorToGroups[$tKey]);
          $currentGroup = (string)$keys[0];
        } else {
          $currentGroup = 'no_group';
        }
        $idx = isset($targetIndexByKey[$tKey]) ? (int)$targetIndexByKey[$tKey] : -1;

        $filteredRows[] = [
          'tKey' => $tKey,
          'tLabel' => $tLabel,
          'c' => $c,
          'glist' => $glist,
          'currentGroup' => $currentGroup,
          'idx' => $idx,
        ];
      }

      usort($filteredRows, function($a, $b) use ($summaryOrderby, $summaryOrder) {
        $dir = $summaryOrder === 'ASC' ? 1 : -1;
        $cmp = 0;
        switch ($summaryOrderby) {
          case 'group':
            $cmp = strcmp((string)$a['glist'], (string)$b['glist']);
            break;
          case 'total':
            $cmp = ((int)($a['c']['total'] ?? 0) <=> (int)($b['c']['total'] ?? 0));
            break;
          case 'inlink':
            $cmp = ((int)($a['c']['inlink'] ?? 0) <=> (int)($b['c']['inlink'] ?? 0));
            break;
          case 'outbound':
            $cmp = ((int)($a['c']['outbound'] ?? 0) <=> (int)($b['c']['outbound'] ?? 0));
            break;
          case 'anchor':
          default:
            $cmp = strcmp((string)$a['tLabel'], (string)$b['tLabel']);
            break;
        }
        if ($cmp === 0) {
          $cmp = strcmp((string)$a['tLabel'], (string)$b['tLabel']);
        }
        return $cmp * $dir;
      });

      $totalFiltered = count($filteredRows);
      $totalPages = max(1, (int)ceil($totalFiltered / $summaryPerPage));
      if ($summaryPaged > $totalPages) $summaryPaged = $totalPages;
    }

    echo '<div style="margin:8px 0; font-weight:bold;">Total: <span id="lm-total-filtered">' . esc_html((string)$totalFiltered) . '</span> target anchors</div>';
    echo '<div class="lm-table-wrap lm-summary-table-wrap">';
    echo '<table class="widefat striped lm-table">';
    echo '<thead><tr>';
    echo '<th class="lm-col-block"><input type="checkbox" id="lm-select-all-targets" /></th>';
    echo $this->table_header_with_tooltip('lm-col-postid', '#', 'Row number in current result page.', 'left');
    echo $this->table_header_with_tooltip('lm-col-group', 'Group', 'Current group assignment for anchor.');
    echo $this->table_header_with_tooltip('lm-col-anchor', 'Anchor Text', 'Tracked anchor text target.');
    echo $this->table_header_with_tooltip('lm-col-total', 'Total Usage Across All Pages (All Link Types)', 'Total uses of this anchor across all content.');
    echo $this->table_header_with_tooltip('lm-col-inlink', 'Total Use as Inlinks', 'Usage count as internal links.');
    echo $this->table_header_with_tooltip('lm-col-outbound', 'Total Use as Outbound', 'Usage count as outbound links.');
    echo $this->table_header_with_tooltip('lm-col-action', 'Action', 'Move group, update, or delete this target.', 'right');
    echo '</tr></thead><tbody>';

    if (empty($summaryTargetsMap)) {
      echo '<tr><td colspan="8">No target anchors yet.</td></tr>';
    } else {
      $offset = ($summaryPaged - 1) * $summaryPerPage;
      $pagedRows = array_slice($filteredRows, $offset, $summaryPerPage);
      $rowNum = $offset + 1;

      foreach ($pagedRows as $row) {
        $tKey = $row['tKey'];
        $tLabel = $row['tLabel'];
        $c = $row['c'];
        $glist = $row['glist'];
        $currentGroup = $row['currentGroup'];
        $idx = $row['idx'];
        $editUrl = $idx >= 0 ? admin_url('admin.php?page=links-manager-target&lm_edit_target=' . $idx) : '';
        $del = $idx >= 0 ? admin_url('admin-post.php?action=lm_delete_anchor_target&' . self::NONCE_NAME . '=' . wp_create_nonce(self::NONCE_ACTION) . '&lm_target_idx=' . $idx) : '';

        echo '<tr>';
        if ($idx >= 0) {
          echo '<td class="lm-col-block" style="text-align:center;"><input type="checkbox" class="lm-target-check" name="lm_target_indices[]" value="' . esc_attr((string)$idx) . '" form="lm-bulk-delete-targets-form" /></td>';
        } else {
          echo '<td class="lm-col-block" style="text-align:center;">—</td>';
        }
        echo '<td class="lm-col-postid">' . esc_html((string)$rowNum) . '</td>';
        echo '<td class="lm-col-group"><span class="lm-trunc" title="' . esc_attr($glist) . '">' . esc_html($glist) . '</span></td>';
        echo '<td class="lm-col-anchor"><span class="lm-trunc" title="' . esc_attr($tLabel) . '">' . esc_html($tLabel) . '</span></td>';
        echo '<td class="lm-col-total">' . esc_html((string)$c['total']) . '</td>';
        echo '<td class="lm-col-inlink">' . esc_html((string)$c['inlink']) . '</td>';
        echo '<td class="lm-col-outbound">' . esc_html((string)$c['outbound']) . '</td>';
        echo '<td class="lm-col-action">';
        if ($idx >= 0) {
          echo '<a class="button button-small" href="' . esc_url($editUrl) . '">Edit</a> ';
          echo '<a class="button button-small" href="' . esc_url($del) . '">Delete</a> ';
        } else {
          echo '<span class="lm-small">—</span> ';
        }
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="lm-target-group-form" style="margin-top:6px;">';
        echo '<input type="hidden" name="action" value="lm_update_anchor_target_group"/>';
        echo '<input type="hidden" name="' . esc_attr(self::NONCE_NAME) . '" value="' . esc_attr(wp_create_nonce(self::NONCE_ACTION)) . '"/>';
        echo '<input type="hidden" name="lm_anchor_value" value="' . esc_attr($tLabel) . '"/>';
        echo '<div class="lm-form-msg"></div>';
        echo '<select name="lm_anchor_group" style="min-width:120px; font-size:11px; margin-right:6px;">';
        echo '<option value="no_group"' . selected($currentGroup, 'no_group', false) . '>No Group</option>';
        foreach ($groupNames as $gn) {
          echo '<option value="' . esc_attr($gn) . '"' . selected($currentGroup, $gn, false) . '>' . esc_html($gn) . '</option>';
        }
        echo '</select>';
        submit_button(__('Change Group', 'links-manager'), 'secondary', 'submit', false);
        echo '</form>';
        echo '</td>';
        echo '</tr>';
        $rowNum++;
      }
    }

    echo '</tbody></table></div>';

    $paginationParams = [
      'lm_summary_groups' => $summaryGroupSelected,
      'lm_summary_group_search' => $summaryGroupSearch,
      'lm_summary_post_type' => $summaryPostType,
      'lm_summary_post_category' => $summaryPostCategory,
      'lm_summary_post_tag' => $summaryPostTag,
      'lm_summary_location' => $summaryLocation,
      'lm_summary_source_type' => $summarySourceType,
      'lm_summary_link_type' => $summaryLinkType,
      'lm_summary_value' => $summaryValueContains,
      'lm_summary_source' => $summarySourceContains,
      'lm_summary_title' => $summaryTitleContains,
      'lm_summary_author' => $summaryAuthorContains,
      'lm_summary_seo_flag' => $summarySeoFlag,
      'lm_summary_anchor' => $summaryAnchor,
      'lm_summary_anchor_search' => $summaryAnchorSearch,
      'lm_summary_search_mode' => $summarySearchMode,
      'lm_summary_total_min' => $summaryTotalMin,
      'lm_summary_total_max' => $summaryTotalMax,
      'lm_summary_in_min' => $summaryInMin,
      'lm_summary_in_max' => $summaryInMax,
      'lm_summary_out_min' => $summaryOutMin,
      'lm_summary_out_max' => $summaryOutMax,
      'lm_summary_orderby' => $summaryOrderby,
      'lm_summary_order' => $summaryOrder,
      'lm_summary_per_page' => $summaryPerPage,
    ];
    $this->render_target_pagination($summaryPaged, $totalPages, $paginationParams);

    echo '</div>';
    echo '<script>
      (function(){
        var cards = document.querySelectorAll(".lm-card");
        cards.forEach(function(card){
          var tabs = card.querySelectorAll(".lm-tab");
          var hidden = card.querySelector("input[name=lm_anchor_mode]");
          if (!tabs.length || !hidden) return;
          tabs.forEach(function(btn){
            btn.addEventListener("click", function(){
              tabs.forEach(function(b){ b.classList.remove("is-active"); b.setAttribute("aria-selected","false"); });
              btn.classList.add("is-active");
              btn.setAttribute("aria-selected","true");
              hidden.value = btn.getAttribute("data-lm-tab") || "only";
              var hints = card.querySelectorAll("[data-lm-hint]");
              hints.forEach(function(h){
                h.style.display = (h.getAttribute("data-lm-hint") === hidden.value) ? "block" : "none";
              });
            });
          });
        });
      })();

      (function(){
        var groupAll = document.getElementById("lm-select-all-groups");
        if (groupAll) {
          groupAll.addEventListener("change", function(){
            document.querySelectorAll(".lm-group-check").forEach(function(el){ el.checked = groupAll.checked; });
          });
        }

        var targetAll = document.getElementById("lm-select-all-targets");
        if (targetAll) {
          targetAll.addEventListener("change", function(){
            document.querySelectorAll(".lm-target-check").forEach(function(el){ el.checked = targetAll.checked; });
          });
        }
      })();
    </script>';
    echo '</div>';
    echo '</div>';
    echo '</div>';
  }
}
