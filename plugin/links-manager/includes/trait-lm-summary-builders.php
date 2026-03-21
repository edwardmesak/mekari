<?php
/**
 * Summary row builders and filtered aggregation helpers.
 */

trait LM_Summary_Builders_Trait {
  private function get_orphan_pages_filtered($all, $filters) {
    // Orphan page: published post/page with zero internal links in its content/excerpt/meta
    $ptList = $this->get_filterable_post_types();
    $post_types = ($filters['post_type'] === 'any') ? array_keys($ptList) : [$filters['post_type']];

    $orderby = $filters['orderby'];
    $queryOrderbyMap = [
      'date' => 'date',
      'title' => 'title',
      'modified' => 'modified',
      'post_id' => 'ID',
    ];
    $queryOrderby = isset($queryOrderbyMap[$orderby]) ? $queryOrderbyMap[$orderby] : 'date';
    $q = new WP_Query([
      'post_type' => $post_types,
      'post_status' => 'publish',
      'posts_per_page' => -1,
      'fields' => 'ids',
      'no_found_rows' => true,
      'orderby' => $queryOrderby,
      'order' => $filters['order'],
      'author' => $filters['author'],
      's' => $filters['search'],
    ]);

    $has_internal = [];
    foreach ($all as $row) {
      if ($row['source'] === 'menu') continue;
      if ($row['link_type'] !== 'inlink') continue;
      $pid = (string)$row['post_id'];
      if ($pid !== '') $has_internal[$pid] = true;
    }

    $orphans = [];
    if (!empty($q->posts)) {
      foreach ($q->posts as $post_id) {
        $pid = (string)$post_id;
        if (!isset($has_internal[$pid])) {
          $orphans[] = $post_id;
        }
      }
    }

    if (in_array($filters['orderby'], ['post_type', 'author'], true)) {
      $dir = $filters['order'] === 'ASC' ? 1 : -1;
      $sortMetaMap = $this->get_post_sort_meta_map($orphans);
      usort($orphans, function($a, $b) use ($filters, $dir, $sortMetaMap) {
        $aKey = (int)$a;
        $bKey = (int)$b;
        if ($filters['orderby'] === 'post_type') {
          $va = isset($sortMetaMap[$aKey]['post_type']) ? (string)$sortMetaMap[$aKey]['post_type'] : '';
          $vb = isset($sortMetaMap[$bKey]['post_type']) ? (string)$sortMetaMap[$bKey]['post_type'] : '';
        } else {
          $va = isset($sortMetaMap[$aKey]['post_author']) ? (string)$sortMetaMap[$aKey]['post_author'] : '';
          $vb = isset($sortMetaMap[$bKey]['post_author']) ? (string)$sortMetaMap[$bKey]['post_author'] : '';
        }

        $cmp = strcmp($va, $vb);
        if ($cmp === 0) {
          $cmp = ($aKey <=> $bKey);
        }
        return $cmp * $dir;
      });
    }

    return $orphans;
  }

  private function get_post_sort_meta_map($postIds) {
    $postIds = array_values(array_unique(array_filter(array_map('intval', (array)$postIds), function($id) {
      return $id > 0;
    })));
    if (empty($postIds)) {
      return [];
    }

    global $wpdb;
    $placeholders = implode(',', array_fill(0, count($postIds), '%d'));
    $sql = "SELECT ID, post_type, post_author FROM {$wpdb->posts} WHERE ID IN ($placeholders)";
    $rows = $wpdb->get_results($wpdb->prepare($sql, $postIds));
    if (empty($rows)) {
      return [];
    }

    $out = [];
    foreach ($rows as $row) {
      $id = isset($row->ID) ? (int)$row->ID : 0;
      if ($id < 1) {
        continue;
      }
      $out[$id] = [
        'post_type' => isset($row->post_type) ? (string)$row->post_type : '',
        'post_author' => isset($row->post_author) ? (string)$row->post_author : '',
      ];
    }

    return $out;
  }

  private function apply_filters_and_group($all, $filters) {
    $locationFilter = $filters['location'];
    $valueContains = $filters['value_contains'];
    $sourceContains = isset($filters['source_contains']) ? $filters['source_contains'] : '';
    $anchorContains = $filters['anchor_contains'];
    $altContains = $filters['alt_contains'];
    $titleContains = isset($filters['title_contains']) ? $filters['title_contains'] : '';
    $authorContains = isset($filters['author_contains']) ? $filters['author_contains'] : '';
    $publishDateFrom = isset($filters['publish_date_from']) ? (string)$filters['publish_date_from'] : '';
    $publishDateTo = isset($filters['publish_date_to']) ? (string)$filters['publish_date_to'] : '';
    $updatedDateFrom = isset($filters['updated_date_from']) ? (string)$filters['updated_date_from'] : '';
    $updatedDateTo = isset($filters['updated_date_to']) ? (string)$filters['updated_date_to'] : '';
    $sourceTypeFilter = isset($filters['source_type']) ? $filters['source_type'] : 'any';
    $qualityFilter = isset($filters['quality']) ? $filters['quality'] : 'any';
    $seoFlagFilter = isset($filters['seo_flag']) ? $filters['seo_flag'] : 'any';
    $textMode = isset($filters['text_match_mode']) ? $this->sanitize_text_match_mode($filters['text_match_mode']) : 'contains';
    $linkTypeFilter = $filters['link_type'];
    $valueType = $filters['value_type'];
    $postCategoryFilter = isset($filters['post_category']) ? (int)$filters['post_category'] : 0;
    $postTagFilter = isset($filters['post_tag']) ? (int)$filters['post_tag'] : 0;
    $allowedPostIds = $this->get_post_ids_by_post_terms($postCategoryFilter, $postTagFilter);

    $relNofollow = $filters['rel_nofollow'];
    $relSponsored = $filters['rel_sponsored'];
    $relUgc = $filters['rel_ugc'];
    $relContains = $filters['rel_contains'];

    $hasValueContains = $valueContains !== '';
    $hasSourceContains = $sourceContains !== '';
    $hasTitleContains = $titleContains !== '';
    $hasAuthorContains = $authorContains !== '';
    $hasAnchorContains = $anchorContains !== '';
    $hasAltContains = $altContains !== '';
    $hasRelContains = $relContains !== '';
    $hasPublishDateFrom = $publishDateFrom !== '';
    $hasPublishDateTo = $publishDateTo !== '';
    $hasUpdatedDateFrom = $updatedDateFrom !== '';
    $hasUpdatedDateTo = $updatedDateTo !== '';

    $filtered = [];
    foreach ($all as $row) {
      if (is_array($allowedPostIds)) {
        $rowPostId = isset($row['post_id']) ? (string)intval($row['post_id']) : '';
        if ($rowPostId === '' || !isset($allowedPostIds[$rowPostId])) continue;
      }

      if ($locationFilter !== 'any' && $row['link_location'] !== $locationFilter) continue;
      if ($sourceTypeFilter !== 'any' && (string)$row['source'] !== (string)$sourceTypeFilter) continue;
      if ($linkTypeFilter !== 'any' && $row['link_type'] !== $linkTypeFilter) continue;
      if ($valueType !== 'any' && $row['value_type'] !== $valueType) continue;

      if ($hasValueContains && !$this->text_matches((string)$row['link'], $valueContains, $textMode)) continue;
      if ($hasSourceContains && !$this->text_matches((string)$row['page_url'], $sourceContains, $textMode)) continue;
      if ($hasTitleContains && !$this->text_matches((string)$row['post_title'], $titleContains, $textMode)) continue;
      if ($hasAuthorContains && !$this->text_matches((string)$row['post_author'], $authorContains, $textMode)) continue;

      $postDate = substr((string)($row['post_date'] ?? ''), 0, 10);
      if ($hasPublishDateFrom && ($postDate === '' || $postDate < $publishDateFrom)) continue;
      if ($hasPublishDateTo && ($postDate === '' || $postDate > $publishDateTo)) continue;

      $postUpdatedDate = substr((string)($row['post_modified'] ?? ''), 0, 10);
      if ($hasUpdatedDateFrom && ($postUpdatedDate === '' || $postUpdatedDate < $updatedDateFrom)) continue;
      if ($hasUpdatedDateTo && ($postUpdatedDate === '' || $postUpdatedDate > $updatedDateTo)) continue;

      if ($hasAnchorContains && !$this->text_matches((string)$row['anchor_text'], $anchorContains, $textMode)) continue;
      if ($hasAltContains && !$this->text_matches((string)$row['alt_text'], $altContains, $textMode)) continue;

      if ($hasRelContains) {
        $flags = [];
        if (($row['rel_nofollow'] ?? '0') === '1') $flags[] = 'nofollow';
        if (($row['rel_sponsored'] ?? '0') === '1') $flags[] = 'sponsored';
        if (($row['rel_ugc'] ?? '0') === '1') $flags[] = 'ugc';
        $relText = !empty($flags) ? implode(', ', $flags) : 'dofollow';
        if (!$this->text_matches($relText, $relContains, $textMode)) continue;
      }

      if ($relNofollow !== 'any' && $row['rel_nofollow'] !== ($relNofollow === '1' ? '1' : '0')) continue;
      if ($relSponsored !== 'any' && $row['rel_sponsored'] !== ($relSponsored === '1' ? '1' : '0')) continue;
      if ($relUgc !== 'any' && $row['rel_ugc'] !== ($relUgc === '1' ? '1' : '0')) continue;

      if ($qualityFilter !== 'any') {
        $quality = $this->get_anchor_quality_label((string)($row['anchor_text'] ?? ''));
        if ((string)$quality !== (string)$qualityFilter) continue;
      }

      if ($seoFlagFilter !== 'any') {
        $nofollow = isset($row['rel_nofollow']) && (string)$row['rel_nofollow'] === '1';
        $sponsored = isset($row['rel_sponsored']) && (string)$row['rel_sponsored'] === '1';
        $ugc = isset($row['rel_ugc']) && (string)$row['rel_ugc'] === '1';

        if ($seoFlagFilter === 'dofollow' && ($nofollow || $sponsored || $ugc)) continue;
        if ($seoFlagFilter === 'nofollow' && !$nofollow) continue;
        if ($seoFlagFilter === 'sponsored' && !$sponsored) continue;
        if ($seoFlagFilter === 'ugc' && !$ugc) continue;
      }

      $filtered[] = $row;
    }

    if ($filters['group'] === '1') {
      $map = [];
      foreach ($filtered as $r) {
        $k = $r['page_url'] . '|' . $r['link'] . '|' . $r['source'] . '|' . $r['link_location'];
        if (!isset($map[$k])) {
          $r['count'] = 1;
          $map[$k] = $r;
        } else {
          $map[$k]['count']++;
        }
      }
      $filtered = array_values($map);
    }

    $orderby = isset($filters['orderby']) ? $filters['orderby'] : 'date';
    $order = isset($filters['order']) ? $filters['order'] : 'DESC';
    if (in_array($orderby, ['date', 'title', 'post_type', 'post_author', 'page_url', 'link', 'source', 'link_location', 'anchor_text', 'quality', 'link_type', 'seo_flags', 'alt_text', 'count'], true)) {
      $dir = ($order === 'ASC') ? 1 : -1;
      usort($filtered, function($a, $b) use ($orderby, $dir) {
        $numericSort = false;
        switch ($orderby) {
          case 'title':
            $va = (string)($a['post_title'] ?? '');
            $vb = (string)($b['post_title'] ?? '');
            break;
          case 'post_type':
            $va = (string)($a['post_type'] ?? '');
            $vb = (string)($b['post_type'] ?? '');
            break;
          case 'post_author':
            $va = (string)($a['post_author'] ?? '');
            $vb = (string)($b['post_author'] ?? '');
            break;
          case 'page_url':
            $va = (string)($a['page_url'] ?? '');
            $vb = (string)($b['page_url'] ?? '');
            break;
          case 'link':
            $va = (string)($a['link'] ?? '');
            $vb = (string)($b['link'] ?? '');
            break;
          case 'source':
            $va = (string)($a['source'] ?? '');
            $vb = (string)($b['source'] ?? '');
            break;
          case 'link_location':
            $va = (string)($a['link_location'] ?? '');
            $vb = (string)($b['link_location'] ?? '');
            break;
          case 'anchor_text':
            $va = (string)($a['anchor_text'] ?? '');
            $vb = (string)($b['anchor_text'] ?? '');
            break;
          case 'quality':
            $va = $this->get_anchor_quality_label((string)($a['anchor_text'] ?? ''));
            $vb = $this->get_anchor_quality_label((string)($b['anchor_text'] ?? ''));
            break;
          case 'link_type':
            $va = (string)($a['link_type'] ?? '');
            $vb = (string)($b['link_type'] ?? '');
            break;
          case 'seo_flags':
            $af = [];
            if ((string)($a['rel_nofollow'] ?? '0') === '1') $af[] = 'nofollow';
            if ((string)($a['rel_sponsored'] ?? '0') === '1') $af[] = 'sponsored';
            if ((string)($a['rel_ugc'] ?? '0') === '1') $af[] = 'ugc';
            $bf = [];
            if ((string)($b['rel_nofollow'] ?? '0') === '1') $bf[] = 'nofollow';
            if ((string)($b['rel_sponsored'] ?? '0') === '1') $bf[] = 'sponsored';
            if ((string)($b['rel_ugc'] ?? '0') === '1') $bf[] = 'ugc';
            $va = !empty($af) ? implode(', ', $af) : 'dofollow';
            $vb = !empty($bf) ? implode(', ', $bf) : 'dofollow';
            break;
          case 'alt_text':
            $va = (string)($a['alt_text'] ?? '');
            $vb = (string)($b['alt_text'] ?? '');
            break;
          case 'count':
            $va = (int)($a['count'] ?? 0);
            $vb = (int)($b['count'] ?? 0);
            $numericSort = true;
            break;
          case 'date':
          default:
            $va = (string)($a['post_date'] ?? '');
            $vb = (string)($b['post_date'] ?? '');
            break;
        }
        if ($numericSort) {
          $cmp = ((int)$va <=> (int)$vb);
        } else {
          $cmp = strcmp((string)$va, (string)$vb);
        }
        if ($cmp === 0) {
          $cmp = strcmp((string)($a['post_title'] ?? ''), (string)($b['post_title'] ?? ''));
        }
        if ($cmp === 0) {
          foreach (['post_id', 'source', 'link_location', 'block_index', 'occurrence', 'link', 'row_id'] as $tieKey) {
            $cmp = strcmp((string)($a[$tieKey] ?? ''), (string)($b[$tieKey] ?? ''));
            if ($cmp !== 0) {
              break;
            }
          }
        }
        return $cmp * $dir;
      });
    }

    return $filtered;
  }

  private function build_cited_domains_summary_rows($all, $filters) {
    $rows = [];
    $hasIndexedSummaryRows = false;
    if (!is_array($all) || empty($all)) {
      $scopePostType = isset($filters['post_type']) ? (string)$filters['post_type'] : 'any';
      $scopeWpmlLang = isset($filters['wpml_lang']) ? (string)$filters['wpml_lang'] : 'all';
      $useIndexedSummary = !empty($filters['rebuild']) ? false : $this->indexed_dataset_has_rows($scopePostType, $scopeWpmlLang);
      $indexedSummaryRows = $useIndexedSummary ? $this->get_indexed_cited_domains_summary_rows($filters) : [];
      if ($useIndexedSummary && !empty($indexedSummaryRows)) {
        $rows = $indexedSummaryRows;
        $hasIndexedSummaryRows = true;
      } else {
        $all = $this->get_canonical_rows_for_scope(
          isset($filters['post_type']) ? $filters['post_type'] : 'any',
          !empty($filters['rebuild']),
          isset($filters['wpml_lang']) ? $filters['wpml_lang'] : 'all'
        );
      }
    }

    if (!$hasIndexedSummaryRows) {
      $allowedPostIds = $this->get_post_ids_by_post_terms(
        isset($filters['post_category']) ? (int)$filters['post_category'] : 0,
        isset($filters['post_tag']) ? (int)$filters['post_tag'] : 0
      );
      $domainMap = [];
      foreach ($all as $row) {
        if (is_array($allowedPostIds)) {
          $rowPostId = isset($row['post_id']) ? (string)intval($row['post_id']) : '';
          if ($rowPostId === '' || !isset($allowedPostIds[$rowPostId])) continue;
        }
        if (($row['link_type'] ?? '') !== 'exlink') continue;
        if (($filters['post_type'] ?? 'any') !== 'any' && (string)($row['post_type'] ?? '') !== (string)$filters['post_type']) continue;
        if (($filters['location'] ?? 'any') !== 'any' && (string)($row['link_location'] ?? '') !== (string)$filters['location']) continue;
        if (($filters['source_type'] ?? 'any') !== 'any' && (string)($row['source'] ?? '') !== (string)$filters['source_type']) continue;

        $textMode = (string)($filters['search_mode'] ?? 'contains');
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

        $link = $this->normalize_url((string)($row['link'] ?? ''));
        $host = parse_url($link, PHP_URL_HOST);
        if (!$host) continue;
        $host = strtolower((string)$host);

        if (!isset($domainMap[$host])) {
          $domainMap[$host] = [
            'domain' => $host,
            'cites' => 0,
            'pages' => [],
            'sample_url' => $link,
          ];
        }

        $domainMap[$host]['cites']++;
        $pageUrl = (string)($row['page_url'] ?? '');
        if ($pageUrl !== '') $domainMap[$host]['pages'][$pageUrl] = true;
      }

      foreach ($domainMap as $item) {
        $rows[] = [
          'domain' => $item['domain'],
          'cites' => (int)$item['cites'],
          'pages' => count($item['pages']),
          'sample_url' => (string)$item['sample_url'],
        ];
      }
    }

    if ($filters['search'] !== '') {
      $rows = array_values(array_filter($rows, function($r) use ($filters) {
        return $this->text_matches((string)$r['domain'], (string)$filters['search'], (string)$filters['search_mode']);
      }));
    }
    if ($filters['min_cites'] > 0) {
      $rows = array_values(array_filter($rows, function($r) use ($filters) {
        return (int)$r['cites'] >= (int)$filters['min_cites'];
      }));
    }
    if ($filters['min_pages'] > 0) {
      $rows = array_values(array_filter($rows, function($r) use ($filters) {
        return (int)$r['pages'] >= (int)$filters['min_pages'];
      }));
    }
    if (($filters['max_cites'] ?? -1) >= 0) {
      $rows = array_values(array_filter($rows, function($r) use ($filters) {
        return (int)$r['cites'] <= (int)$filters['max_cites'];
      }));
    }
    if (($filters['max_pages'] ?? -1) >= 0) {
      $rows = array_values(array_filter($rows, function($r) use ($filters) {
        return (int)$r['pages'] <= (int)$filters['max_pages'];
      }));
    }

    usort($rows, function($a, $b) use ($filters) {
      $dir = $filters['order'] === 'ASC' ? 1 : -1;
      if ($filters['orderby'] === 'domain') {
        $cmp = strcmp((string)$a['domain'], (string)$b['domain']);
        if ($cmp === 0) $cmp = ((int)$a['cites'] <=> (int)$b['cites']) * -1;
        return $cmp * $dir;
      }
      if ($filters['orderby'] === 'pages') {
        $cmp = ((int)$a['pages'] <=> (int)$b['pages']);
        if ($cmp === 0) $cmp = strcmp((string)$a['domain'], (string)$b['domain']);
        return $cmp * $dir;
      }
      if ($filters['orderby'] === 'sample_url') {
        $cmp = strcmp((string)$a['sample_url'], (string)$b['sample_url']);
        if ($cmp === 0) $cmp = strcmp((string)$a['domain'], (string)$b['domain']);
        return $cmp * $dir;
      }

      $cmp = ((int)$a['cites'] <=> (int)$b['cites']);
      if ($cmp === 0) $cmp = strcmp((string)$a['domain'], (string)$b['domain']);
      return $cmp * $dir;
    });

    return $rows;
  }

  private function build_all_anchor_text_rows($all, $filters) {
    $rows = [];
    $hasIndexedSummaryRows = false;
    if (!is_array($all) || empty($all)) {
      $scopePostType = isset($filters['post_type']) ? (string)$filters['post_type'] : 'any';
      $scopeWpmlLang = isset($filters['wpml_lang']) ? (string)$filters['wpml_lang'] : 'all';
      $useIndexedSummary = !empty($filters['rebuild']) ? false : $this->indexed_dataset_has_rows($scopePostType, $scopeWpmlLang);
      $indexedSummaryRows = $useIndexedSummary ? $this->get_indexed_all_anchor_text_summary_rows($filters) : [];
      if ($useIndexedSummary && !empty($indexedSummaryRows)) {
        $rows = $indexedSummaryRows;
        $hasIndexedSummaryRows = true;
      } else {
        $all = $this->get_canonical_rows_for_scope(
          isset($filters['post_type']) ? $filters['post_type'] : 'any',
          !empty($filters['rebuild']),
          isset($filters['wpml_lang']) ? $filters['wpml_lang'] : 'all'
        );
      }
    }

    $anchorToGroups = [];
    $groups = $this->get_anchor_groups();
    foreach ($groups as $g) {
      $gname = trim((string)($g['name'] ?? ''));
      if ($gname === '') continue;
      foreach ((array)($g['anchors'] ?? []) as $a) {
        $a = trim((string)$a);
        if ($a === '') continue;
        $k = strtolower($a);
        if (!isset($anchorToGroups[$k])) $anchorToGroups[$k] = [];
        $anchorToGroups[$k][$gname] = true;
      }
    }

    if (!$hasIndexedSummaryRows) {
      $map = [];
      $textMode = (string)($filters['search_mode'] ?? 'contains');
      $allowedPostIds = $this->get_post_ids_by_post_terms(
        isset($filters['post_category']) ? (int)$filters['post_category'] : 0,
        isset($filters['post_tag']) ? (int)$filters['post_tag'] : 0
      );

      foreach ($all as $row) {
        if (is_array($allowedPostIds)) {
          $rowPostId = isset($row['post_id']) ? (string)intval($row['post_id']) : '';
          if ($rowPostId === '' || !isset($allowedPostIds[$rowPostId])) continue;
        }
        if (($filters['post_type'] ?? 'any') !== 'any' && (string)($row['post_type'] ?? '') !== (string)$filters['post_type']) continue;
        if (($filters['location'] ?? 'any') !== 'any' && (string)($row['link_location'] ?? '') !== (string)$filters['location']) continue;
        if (($filters['source_type'] ?? 'any') !== 'any' && (string)($row['source'] ?? '') !== (string)$filters['source_type']) continue;
        if (($filters['link_type'] ?? 'any') !== 'any' && (string)($row['link_type'] ?? '') !== (string)$filters['link_type']) continue;

        if ((string)($filters['value_contains'] ?? '') !== '' && !$this->text_matches((string)($row['link'] ?? ''), (string)$filters['value_contains'], $textMode)) continue;
        if ((string)($filters['source_contains'] ?? '') !== '' && !$this->text_matches((string)($row['page_url'] ?? ''), (string)$filters['source_contains'], $textMode)) continue;
        if ((string)($filters['title_contains'] ?? '') !== '' && !$this->text_matches((string)($row['post_title'] ?? ''), (string)$filters['title_contains'], $textMode)) continue;
        if ((string)($filters['author_contains'] ?? '') !== '' && !$this->text_matches((string)($row['post_author'] ?? ''), (string)$filters['author_contains'], $textMode)) continue;

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

        $anchor = trim((string)($row['anchor_text'] ?? ''));
        if ($anchor === '') continue;
        $key = strtolower($anchor);

        if (!isset($map[$key])) {
          $map[$key] = [
            'anchor_text' => $anchor,
            'total' => 0,
            'inlink' => 0,
            'outbound' => 0,
            'source_pages' => [],
            'destinations' => [],
            'source_types' => [],
          ];
        }

        $map[$key]['total']++;
        if (($row['link_type'] ?? '') === 'inlink') $map[$key]['inlink']++;
        if (($row['link_type'] ?? '') === 'exlink') $map[$key]['outbound']++;

        $sourceType = trim((string)($row['source'] ?? ''));
        if ($sourceType === '') $sourceType = 'unknown';
        $map[$key]['source_types'][$sourceType] = true;

        $pageUrl = trim((string)($row['page_url'] ?? ''));
        if ($pageUrl !== '') $map[$key]['source_pages'][$pageUrl] = true;

        $dest = trim((string)($row['link'] ?? ''));
        if ($dest !== '') $map[$key]['destinations'][$dest] = true;
      }

      foreach ($map as $item) {
        $usageType = 'mixed';
        if ($item['inlink'] > 0 && $item['outbound'] === 0) $usageType = 'inlink_only';
        if ($item['outbound'] > 0 && $item['inlink'] === 0) $usageType = 'outbound_only';

        $quality = $this->get_anchor_quality_label($item['anchor_text']);
        $sourceTypes = array_keys($item['source_types']);
        sort($sourceTypes);

        $rows[] = [
          'anchor_text' => $item['anchor_text'],
          'quality' => $quality,
          'usage_type' => $usageType,
          'total' => (int)$item['total'],
          'inlink' => (int)$item['inlink'],
          'outbound' => (int)$item['outbound'],
          'source_pages' => count($item['source_pages']),
          'destinations' => count($item['destinations']),
          'source_types' => implode(', ', $sourceTypes),
          'source_types_map' => $item['source_types'],
        ];
      }
    }

    $filteredRows = [];
    $searchText = (string)($filters['search'] ?? '');
    $sourceTypeWanted = (string)($filters['source_type'] ?? 'any');
    $usageTypeWanted = (string)($filters['usage_type'] ?? 'any');
    $qualityWanted = (string)($filters['quality'] ?? 'any');
    $searchMode = (string)($filters['search_mode'] ?? 'contains');
    $minTotal = (int)($filters['min_total'] ?? 0);
    $maxTotal = (int)($filters['max_total'] ?? -1);
    $minInlink = (int)($filters['min_inlink'] ?? 0);
    $maxInlink = (int)($filters['max_inlink'] ?? -1);
    $minOutbound = (int)($filters['min_outbound'] ?? 0);
    $maxOutbound = (int)($filters['max_outbound'] ?? -1);
    $minPages = (int)($filters['min_pages'] ?? 0);
    $maxPages = (int)($filters['max_pages'] ?? -1);
    $minDestinations = (int)($filters['min_destinations'] ?? 0);
    $maxDestinations = (int)($filters['max_destinations'] ?? -1);
    $selectedGroup = (string)($filters['group'] ?? 'any');

    $hasSearchText = $searchText !== '';
    $hasGroupFilter = $selectedGroup !== 'any';

    foreach ($rows as $r) {
      if ($hasSearchText && !$this->text_matches((string)$r['anchor_text'], $searchText, $searchMode)) continue;
      if ($sourceTypeWanted !== 'any' && !isset($r['source_types_map'][$sourceTypeWanted])) continue;
      if ($usageTypeWanted !== 'any' && (string)$r['usage_type'] !== $usageTypeWanted) continue;
      if ($qualityWanted !== 'any' && (string)$r['quality'] !== $qualityWanted) continue;
      if ((int)$r['total'] < $minTotal) continue;
      if ($maxTotal >= 0 && (int)$r['total'] > $maxTotal) continue;
      if ((int)$r['inlink'] < $minInlink) continue;
      if ($maxInlink >= 0 && (int)$r['inlink'] > $maxInlink) continue;
      if ((int)$r['outbound'] < $minOutbound) continue;
      if ($maxOutbound >= 0 && (int)$r['outbound'] > $maxOutbound) continue;
      if ((int)$r['source_pages'] < $minPages) continue;
      if ($maxPages >= 0 && (int)$r['source_pages'] > $maxPages) continue;
      if ((int)$r['destinations'] < $minDestinations) continue;
      if ($maxDestinations >= 0 && (int)$r['destinations'] > $maxDestinations) continue;

      if ($hasGroupFilter) {
        $anchorKey = strtolower(trim((string)($r['anchor_text'] ?? '')));
        if ($anchorKey === '') continue;
        $groupsForAnchor = isset($anchorToGroups[$anchorKey]) ? array_keys($anchorToGroups[$anchorKey]) : [];
        if ($selectedGroup === 'no_group') {
          if (!empty($groupsForAnchor)) continue;
        } else {
          if (!in_array($selectedGroup, $groupsForAnchor, true)) continue;
        }
      }

      $filteredRows[] = $r;
    }

    $rows = $filteredRows;

    usort($rows, function($a, $b) use ($filters) {
      $dir = $filters['order'] === 'ASC' ? 1 : -1;
      $ord = $filters['orderby'];

      if ($ord === 'anchor') {
        $cmp = strcmp((string)$a['anchor_text'], (string)$b['anchor_text']);
        return $cmp * $dir;
      }

      if ($ord === 'inlink') {
        $cmp = ((int)$a['inlink'] <=> (int)$b['inlink']);
      } elseif ($ord === 'outbound') {
        $cmp = ((int)$a['outbound'] <=> (int)$b['outbound']);
      } elseif ($ord === 'pages') {
        $cmp = ((int)$a['source_pages'] <=> (int)$b['source_pages']);
      } elseif ($ord === 'destinations') {
        $cmp = ((int)$a['destinations'] <=> (int)$b['destinations']);
      } elseif ($ord === 'quality') {
        $cmp = strcmp((string)$a['quality'], (string)$b['quality']);
      } elseif ($ord === 'source_types') {
        $cmp = strcmp((string)$a['source_types'], (string)$b['source_types']);
      } elseif ($ord === 'usage_type') {
        $cmp = strcmp((string)$a['usage_type'], (string)$b['usage_type']);
      } else {
        $cmp = ((int)$a['total'] <=> (int)$b['total']);
      }

      if ($cmp === 0) $cmp = strcmp((string)$a['anchor_text'], (string)$b['anchor_text']);
      return $cmp * $dir;
    });

    return $rows;
  }
}
