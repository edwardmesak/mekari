<?php
/**
 * Link crawling, URL normalization, and HTML parsing helpers.
 */

if (!defined('ABSPATH')) {
  exit;
}

trait LM_Crawl_Parse_Trait {
  private function site_hosts() {
    $hosts = [];
    $homeHost = parse_url(home_url(), PHP_URL_HOST);
    if ($homeHost) {
      $hosts[] = strtolower($homeHost);
      if (strpos($homeHost, 'www.') === 0) $hosts[] = strtolower(substr($homeHost, 4));
      else $hosts[] = 'www.' . strtolower($homeHost);
    }
    $hosts = apply_filters('lm_internal_hosts', $hosts);
    $hosts = array_values(array_unique(array_filter(array_map('strtolower', (array)$hosts))));
    return $hosts;
  }

  private function normalize_url($url) {
    $url = trim((string)$url);
    if ($url === '') return '';
    if (strpos($url, '//') === 0) $url = (is_ssl() ? 'https:' : 'http:') . $url;
    return $url;
  }

  private function normalize_for_compare($url) {
    $url = trim((string)$url);
    if ($url === '') return '';
    $url = $this->normalize_url($url);
    return rtrim($url, '/');
  }

  private function detect_link_value_type($href) {
    $href = trim((string)$href);
    if ($href === '') return 'empty';
    $lower = strtolower($href);

    if (strpos($lower, '#') === 0) return 'anchor';
    if (strpos($lower, 'mailto:') === 0) return 'mailto';
    if (strpos($lower, 'tel:') === 0) return 'tel';
    if (strpos($lower, 'javascript:') === 0) return 'javascript';

    if (preg_match('#^https?://#i', $href) || strpos($href, '//') === 0) return 'url';
    if (strpos($href, '/') === 0 || strpos($href, './') === 0 || strpos($href, '../') === 0) return 'relative';

    return 'other';
  }

  private function resolve_to_absolute($href, $pageUrl) {
    $href = trim((string)$href);
    if ($href === '') return '';

    $type = $this->detect_link_value_type($href);
    if (in_array($type, ['anchor', 'mailto', 'tel', 'javascript', 'empty'], true)) return $this->normalize_url($href);

    if (preg_match('#^https?://#i', $href) || strpos($href, '//') === 0) return $this->normalize_url($href);

    if (strpos($href, '/') === 0) return home_url($href);

    $base = $pageUrl ? $pageUrl : home_url('/');
    $baseParts = wp_parse_url($base);
    if (empty($baseParts['scheme']) || empty($baseParts['host'])) return $this->normalize_url($href);

    $scheme = $baseParts['scheme'];
    $host = $baseParts['host'];
    $port = isset($baseParts['port']) ? ':' . $baseParts['port'] : '';
    $path = isset($baseParts['path']) ? $baseParts['path'] : '/';
    $dir = preg_replace('#/[^/]*$#', '/', $path);

    $combined = $dir . $href;
    $segments = [];
    foreach (explode('/', $combined) as $seg) {
      if ($seg === '' || $seg === '.') continue;
      if ($seg === '..') {
        array_pop($segments);
        continue;
      }
      $segments[] = $seg;
    }
    $finalPath = '/' . implode('/', $segments);
    return $scheme . '://' . $host . $port . $finalPath;
  }

  private function is_external($url) {
    $url = $this->normalize_url($url);
    if ($url === '') return false;

    $lower = strtolower($url);
    if (strpos($lower, '#') === 0) return false;
    if (strpos($lower, 'mailto:') === 0) return false;
    if (strpos($lower, 'tel:') === 0) return false;
    if (strpos($lower, 'javascript:') === 0) return false;

    $host = parse_url($url, PHP_URL_HOST);
    if (!$host) return false;
    $host = strtolower($host);
    return !in_array($host, $this->site_hosts(), true);
  }

  private function parse_rel_flags($relRaw) {
    $relRaw = trim((string)$relRaw);
    $rel = strtolower(preg_replace('/\s+/', ' ', $relRaw));
    $parts = array_filter(explode(' ', $rel));
    $flags = array_fill_keys($parts, true);
    return [
      'raw' => $relRaw === '' ? '' : $rel,
      'nofollow' => isset($flags['nofollow']),
      'sponsored' => isset($flags['sponsored']),
      'ugc' => isset($flags['ugc']),
      'noreferrer' => isset($flags['noreferrer']),
      'noopener' => isset($flags['noopener']),
    ];
  }

  private function relationship_label($relRaw) {
    $relRaw = trim((string)$relRaw);
    if ($relRaw === '') return 'dofollow';
    return strtolower(preg_replace('/\s+/', ' ', $relRaw));
  }

  private function text_snippet($text, $max = 140) {
    $text = trim(preg_replace('/\s+/', ' ', (string)$text));
    if ($text === '') return '';
    if (function_exists('mb_strlen') && mb_strlen($text) > $max) return mb_substr($text, 0, $max) . '…';
    if (strlen($text) > $max) return substr($text, 0, $max) . '…';
    return $text;
  }

  private function normalize_snippet_match_word($word) {
    $word = trim((string)$word);
    if ($word === '') return '';

    $normalized = preg_replace('/^[^\p{L}\p{N}]+|[^\p{L}\p{N}]+$/u', '', $word);
    if ($normalized === null) {
      $normalized = preg_replace('/^[^A-Za-z0-9]+|[^A-Za-z0-9]+$/', '', $word);
    }

    return strtolower(trim((string)$normalized));
  }

  private function build_anchor_context_snippet($text, $anchor, $beforeWords = 3, $afterWords = 12, $fallbackMax = 220) {
    $text = trim(preg_replace('/\s+/', ' ', (string)$text));
    $anchor = trim(preg_replace('/\s+/', ' ', (string)$anchor));
    if ($text === '') return '';
    if ($anchor === '') return $this->text_snippet($text, $fallbackMax);

    $textWords = preg_split('/\s+/', $text);
    $anchorWords = preg_split('/\s+/', $anchor);
    if (empty($textWords) || empty($anchorWords)) return $this->text_snippet($text, $fallbackMax);

    $normalizedTextWords = array_values(array_map([$this, 'normalize_snippet_match_word'], $textWords));
    $normalizedAnchorWords = array_values(array_filter(array_map([$this, 'normalize_snippet_match_word'], $anchorWords), function($word) {
      return $word !== '';
    }));

    if (!empty($normalizedAnchorWords)) {
      $anchorWordCount = count($normalizedAnchorWords);
      $lastPossibleIndex = count($normalizedTextWords) - $anchorWordCount;

      for ($index = 0; $index <= $lastPossibleIndex; $index++) {
        $slice = array_slice($normalizedTextWords, $index, $anchorWordCount);
        if ($slice === $normalizedAnchorWords) {
          $startIndex = max(0, $index - max(0, (int)$beforeWords));
          $endIndex = min(count($textWords), $index + $anchorWordCount + max(0, (int)$afterWords));
          $windowWords = array_slice($textWords, $startIndex, $endIndex - $startIndex);
          $windowText = implode(' ', $windowWords);
          if ($startIndex > 0) $windowText = '… ' . $windowText;
          if ($endIndex < count($textWords)) $windowText .= ' …';
          return $this->text_snippet($windowText, $fallbackMax);
        }
      }
    }

    return $this->text_snippet($text, $fallbackMax);
  }

  private function text_snippet_with_anchor_offset($text, $anchor, $max = 60, $anchorWordPosition = 4) {
    $text = trim(preg_replace('/\s+/', ' ', (string)$text));
    $anchor = trim(preg_replace('/\s+/', ' ', (string)$anchor));
    if ($text === '') return '';
    if ($anchor === '' || $anchorWordPosition <= 1) return $this->text_snippet($text, $max);

    $textWords = preg_split('/\s+/', $text);
    $anchorWords = preg_split('/\s+/', $anchor);
    if (empty($textWords) || empty($anchorWords)) return $this->text_snippet($text, $max);

    $normalizedTextWords = array_values(array_map([$this, 'normalize_snippet_match_word'], $textWords));
    $normalizedAnchorWords = array_values(array_filter(array_map([$this, 'normalize_snippet_match_word'], $anchorWords), function($word) {
      return $word !== '';
    }));

    if (!empty($normalizedAnchorWords)) {
      $anchorWordCount = count($normalizedAnchorWords);
      $lastPossibleIndex = count($normalizedTextWords) - $anchorWordCount;

      for ($index = 0; $index <= $lastPossibleIndex; $index++) {
        $slice = array_slice($normalizedTextWords, $index, $anchorWordCount);
        if ($slice === $normalizedAnchorWords) {
          $startIndex = max(0, $index - ($anchorWordPosition - 1));
          $windowText = implode(' ', array_slice($textWords, $startIndex));
          return $this->text_snippet($windowText, $max);
        }
      }
    }

    if (function_exists('mb_stripos')) {
      $anchorPos = mb_stripos($text, $anchor, 0, 'UTF-8');
      if ($anchorPos === false) return $this->text_snippet($text, $max);
      $beforeText = trim(mb_substr($text, 0, $anchorPos, 'UTF-8'));
    } else {
      $anchorPos = stripos($text, $anchor);
      if ($anchorPos === false) return $this->text_snippet($text, $max);
      $beforeText = trim(substr($text, 0, $anchorPos));
    }

    $words = preg_split('/\s+/', $text);
    if (empty($words)) return '';

    $beforeWords = $beforeText === '' ? [] : preg_split('/\s+/', $beforeText);
    $anchorStartIndex = count($beforeWords);
    $startIndex = max(0, $anchorStartIndex - ($anchorWordPosition - 1));
    $windowText = implode(' ', array_slice($words, $startIndex));

    return $this->text_snippet($windowText, $max);
  }

  private function highlight_snippet_anchor_html($snippet, $anchor) {
    $snippet = (string)$snippet;
    $anchor = trim((string)$anchor);
    $escapedSnippet = esc_html($snippet);
    if ($snippet === '' || $anchor === '') return $escapedSnippet;

    $quotedAnchor = preg_quote($anchor, '/');
    $highlighted = preg_replace('/(' . $quotedAnchor . ')/iu', '<mark class="lm-snippet-anchor">$1</mark>', $escapedSnippet, 1);
    return $highlighted !== null ? $highlighted : $escapedSnippet;
  }

  private function row_id($post_id, $source, $location, $block_index, $occurrence, $link_resolved) {
    $raw = implode('|', [
      (string)$post_id,
      (string)$source,
      (string)$location,
      (string)$block_index,
      (string)$occurrence,
      (string)$this->normalize_for_compare($link_resolved),
    ]);
    return 'lm_' . substr(md5($raw), 0, 16);
  }

  private function parse_links_from_html($html, $context) {
    $parseStartedAt = microtime(true);
    $results = [];
    if (trim((string)$html) === '') {
      $this->record_parse_runtime_stats($parseStartedAt, $context, 0, 'parse_skip_empty');
      return $results;
    }
    if (stripos((string)$html, '<a') === false && stripos((string)$html, 'href=') === false) {
      $this->record_parse_runtime_stats($parseStartedAt, $context, 0, 'parse_skip_no_marker');
      return $results;
    }

    $enabledValueTypesMap = $this->get_enabled_scan_value_types_map_cached();

    libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    $wrapped = '<?xml encoding="utf-8" ?>' . $html;
    $loaded = $doc->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    if (!$loaded) {
      libxml_clear_errors();
      $this->record_parse_runtime_stats($parseStartedAt, $context, 0, 'parse_load_failed');
      return $results;
    }

    $links = $doc->getElementsByTagName('a');

    $occ = 0;
    foreach ($links as $a) {
      $href = $a->getAttribute('href');

      $valueType = $this->detect_link_value_type($href);

      if (!$this->is_scan_value_type_enabled($valueType, $enabledValueTypesMap)) {
        $occ++;
        continue;
      }

      $resolved = $this->normalize_url($this->resolve_to_absolute($href, $context['page_url']));
      $linkType = $this->is_external($resolved) ? 'exlink' : 'inlink';

      $anchorText = $this->normalize_anchor_text_value($a->textContent, true);

      $altText = '';
      $imgs = $a->getElementsByTagName('img');
      if ($imgs && $imgs->length > 0) {
        $img = $imgs->item(0);
        $altText = $this->normalize_anchor_text_value($img->getAttribute('alt'), true);
      }

      $rel = $a->getAttribute('rel');
      $relFlags = $this->parse_rel_flags($rel);

      $snippetContextText = $a->parentNode ? $a->parentNode->textContent : $a->textContent;
      $snippetAnchorBasis = $anchorText !== '' ? $anchorText : $altText;
      $snippet = $this->build_anchor_context_snippet($snippetContextText, $snippetAnchorBasis, 3, 8, 120);

      $rowId = $this->row_id(
        $context['post_id'],
        $context['source'],
        $context['link_location'],
        $context['block_index'],
        $occ,
        $resolved
      );

      $results[] = array_merge($context, [
        'row_id' => $rowId,
        'occurrence' => (string)$occ,
        'link' => $resolved,
        'link_raw' => $href,
        'anchor_text' => $anchorText,
        'alt_text' => $altText,
        'snippet' => $snippet,
        'link_type' => $linkType,
        'relationship' => $this->relationship_label($rel),
        'rel_raw' => $relFlags['raw'],
        'rel_nofollow' => $relFlags['nofollow'] ? '1' : '0',
        'rel_sponsored' => $relFlags['sponsored'] ? '1' : '0',
        'rel_ugc' => $relFlags['ugc'] ? '1' : '0',
        'value_type' => $valueType,
      ]);

      $occ++;
    }

    libxml_clear_errors();
    $this->record_parse_runtime_stats($parseStartedAt, $context, count($results));
    return $results;
  }

  private function crawl_row_dedupe_signature($row) {
    if (!is_array($row)) {
      return '';
    }

    $signatureParts = [
      (string)($row['post_id'] ?? ''),
      (string)($row['source'] ?? ''),
      (string)($row['link_type'] ?? ''),
      (string)$this->normalize_for_compare((string)($row['link'] ?? '')),
      strtolower(trim((string)($row['anchor_text'] ?? ''))),
      strtolower(trim((string)($row['alt_text'] ?? ''))),
      strtolower(trim((string)($row['rel_raw'] ?? ''))),
      (string)($row['value_type'] ?? ''),
    ];

    return implode('|', $signatureParts);
  }

  private function merge_block_and_full_html_rows($blockRows, $fullHtmlRows) {
    $blockRows = is_array($blockRows) ? array_values($blockRows) : [];
    $fullHtmlRows = is_array($fullHtmlRows) ? array_values($fullHtmlRows) : [];

    if (empty($blockRows)) {
      return $fullHtmlRows;
    }
    if (empty($fullHtmlRows)) {
      return $blockRows;
    }

    $blockSignatureCounts = [];
    foreach ($blockRows as $row) {
      $signature = $this->crawl_row_dedupe_signature($row);
      if ($signature === '') {
        continue;
      }
      $blockSignatureCounts[$signature] = (int)($blockSignatureCounts[$signature] ?? 0) + 1;
    }

    $dedupedFullHtmlRows = [];
    foreach ($fullHtmlRows as $row) {
      $signature = $this->crawl_row_dedupe_signature($row);
      if ($signature !== '' && !empty($blockSignatureCounts[$signature])) {
        $blockSignatureCounts[$signature]--;
        continue;
      }
      $dedupedFullHtmlRows[] = $row;
    }

    $merged = $blockRows;
    $this->append_rows($merged, $dedupedFullHtmlRows);
    return $merged;
  }

  private function render_block_html_best_effort($block) {
    $inner = isset($block['innerHTML']) ? (string)$block['innerHTML'] : '';
    if (trim($inner) !== '') return $inner;
    if (isset($block['innerContent']) && is_array($block['innerContent'])) {
      $fallback = implode('', array_map('strval', $block['innerContent']));
      if (trim($fallback) !== '') return $fallback;
    }

    $hasUrlishAttr = false;
    if (isset($block['attrs']) && is_array($block['attrs'])) {
      foreach ($block['attrs'] as $attrVal) {
        if (is_string($attrVal) && (
          stripos($attrVal, 'http://') !== false ||
          stripos($attrVal, 'https://') !== false ||
          stripos($attrVal, 'mailto:') !== false ||
          stripos($attrVal, 'tel:') !== false ||
          stripos($attrVal, 'href') !== false ||
          stripos($attrVal, '/') !== false
        )) {
          $hasUrlishAttr = true;
          break;
        }
      }
    }

    if (!$hasUrlishAttr) {
      return $inner;
    }

    if (function_exists('render_block')) {
      $rendered = render_block($block);
      if (is_string($rendered) && trim($rendered) !== '') return $rendered;
    }
    return $inner;
  }

  private function block_may_contain_link_marker($block) {
    if (!is_array($block)) {
      return false;
    }

    $inner = isset($block['innerHTML']) ? (string)$block['innerHTML'] : '';
    if ($inner !== '' && (stripos($inner, '<a') !== false || stripos($inner, 'href=') !== false)) {
      return true;
    }

    if (isset($block['innerContent']) && is_array($block['innerContent'])) {
      foreach ($block['innerContent'] as $piece) {
        if (!is_string($piece)) {
          continue;
        }
        if (stripos($piece, '<a') !== false || stripos($piece, 'href=') !== false) {
          return true;
        }
      }
    }

    if (isset($block['attrs']) && is_array($block['attrs'])) {
      foreach ($block['attrs'] as $attrVal) {
        if (is_string($attrVal) && (
          stripos($attrVal, 'http://') !== false ||
          stripos($attrVal, 'https://') !== false ||
          stripos($attrVal, 'mailto:') !== false ||
          stripos($attrVal, 'tel:') !== false ||
          stripos($attrVal, 'href') !== false ||
          stripos($attrVal, '/') !== false
        )) {
          return true;
        }
      }
    }

    return false;
  }

  private function get_author_display_name_cached($authorId) {
    $authorId = (int)$authorId;
    if ($authorId < 1) {
      return '';
    }

    if (isset($this->author_display_name_cache[$authorId])) {
      return (string)$this->author_display_name_cache[$authorId];
    }

    $name = (string)get_the_author_meta('display_name', $authorId);
    $this->author_display_name_cache[$authorId] = $name;
    return $name;
  }

  private function crawl_post($postRef, $enabledSources = null) {
    $post = null;
    $post_id = 0;

    if (is_object($postRef)) {
      $post = $postRef;
      $post_id = isset($post->ID) ? (int)$post->ID : 0;
    } else {
      $post_id = (int)$postRef;
      if ($post_id > 0) {
        $post = get_post($post_id);
      }
    }

    if (!$post || $post_id < 1 || !isset($post->post_status) || (string)$post->post_status !== 'publish') return [];
    $this->add_crawl_runtime_stat('posts_seen', 1);

    if (!is_array($enabledSources)) {
      $enabledSources = $this->get_enabled_scan_source_types();
    }

    $enabledSourcesMap = [];
    foreach ((array)$enabledSources as $src) {
      $enabledSourcesMap[sanitize_key((string)$src)] = true;
    }

    $content = isset($enabledSourcesMap['content']) ? (string)$post->post_content : '';
    $contentHasLinkMarkers = ($content !== '') && ((stripos($content, '<a') !== false) || (stripos($content, 'href=') !== false));
    $excerpt = isset($enabledSourcesMap['excerpt']) ? (string)$post->post_excerpt : '';
    $excerptHasLinkMarkers = ($excerpt !== '') && ((stripos($excerpt, '<a') !== false) || (stripos($excerpt, 'href=') !== false));
    $metaKeys = isset($enabledSourcesMap['meta']) ? $this->get_scan_meta_keys_cached() : [];
    $needsMetaScan = !empty($metaKeys);

    if (!$contentHasLinkMarkers && !$excerptHasLinkMarkers && !$needsMetaScan) {
      $this->add_crawl_runtime_stat('posts_skipped_no_source_markers', 1);
      return [];
    }

    $pageUrl = get_permalink($post_id);
    if ($this->url_matches_scan_exclude_patterns((string)$pageUrl)) {
      $this->add_crawl_runtime_stat('posts_skipped_excluded_url', 1);
      return [];
    }
    $author = $post->post_author ? $this->get_author_display_name_cached($post->post_author) : '';

    $baseContext = [
      'post_id' => (string)$post_id,
      'post_title' => isset($post->post_title) ? (string)$post->post_title : '',
      'post_type' => (string)$post->post_type,
      'post_date' => isset($post->post_date) ? (string)$post->post_date : '',
      'post_modified' => isset($post->post_modified) ? (string)$post->post_modified : '',
      'post_author' => (string)$author,
      'page_url' => (string)$pageUrl,
    ];

    $rows = [];

    if (isset($enabledSourcesMap['content'])) {
      if ($contentHasLinkMarkers) {
        $this->add_crawl_runtime_stat('content_marker_posts', 1);
      }
      $hasBlockMarkup = (strpos($content, '<!-- wp:') !== false);
      $blocks = ($contentHasLinkMarkers && $hasBlockMarkup && function_exists('parse_blocks')) ? parse_blocks($content) : [];
      $contentRowsBeforeScan = count($rows);

      $blockRows = [];
      if (!empty($blocks)) {
        $this->add_crawl_runtime_stat('content_block_posts', 1);
        $this->add_crawl_runtime_stat('content_blocks_total', count($blocks));
        $i = 0;
        foreach ($blocks as $block) {
          if (!$this->block_may_contain_link_marker($block)) {
            $this->add_crawl_runtime_stat('content_blocks_skipped_no_link_marker', 1);
            $i++;
            continue;
          }

          $blockName = isset($block['blockName']) && $block['blockName'] ? $block['blockName'] : 'classic';
          $html = $this->render_block_html_best_effort($block);
          if (stripos($html, '<a') === false && stripos($html, 'href=') === false) {
            $this->add_crawl_runtime_stat('content_blocks_skipped_no_link_marker', 1);
            $i++;
            continue;
          }

          $context = array_merge($baseContext, [
            'source' => 'content',
            'link_location' => $blockName,
            'block_index' => (string)$i,
          ]);

          $this->append_rows($blockRows, $this->parse_links_from_html($html, $context));
          $i++;
        }
      }

      $contentRowsFound = count($blockRows);
      $fullHtmlRows = [];
      if ($contentHasLinkMarkers) {
        if (!empty($blocks)) {
          $this->add_crawl_runtime_stat('content_block_fallback_full_html', 1);
        }
        $context = array_merge($baseContext, [
          'source' => 'content',
          'link_location' => 'classic',
          'block_index' => '',
        ]);
        $fullHtmlRows = $this->parse_links_from_html($content, $context);
      }

      if (!empty($blocks)) {
        $contentRows = $this->merge_block_and_full_html_rows($blockRows, $fullHtmlRows);
      } else {
        $contentRows = $fullHtmlRows;
      }
      $this->append_rows($rows, $contentRows);
    }

    if (isset($enabledSourcesMap['excerpt'])) {
      if ($excerptHasLinkMarkers) {
        $this->add_crawl_runtime_stat('excerpt_marker_posts', 1);
        $context = array_merge($baseContext, [
          'source' => 'excerpt',
          'link_location' => 'excerpt',
          'block_index' => '',
        ]);
        $this->append_rows($rows, $this->parse_links_from_html($excerpt, $context));
      }
    }

    if (isset($enabledSourcesMap['meta'])) {
      foreach ($metaKeys as $key) {
        $this->add_crawl_runtime_stat('meta_keys_total_checked', 1);
        $val = get_post_meta($post_id, $key, true);
        if (is_string($val) && trim($val) !== '' && (stripos($val, '<a') !== false || stripos($val, 'href=') !== false)) {
          $this->add_crawl_runtime_stat('meta_values_with_link_markers', 1);
          $context = array_merge($baseContext, [
            'source' => 'meta',
            'link_location' => 'meta:' . $key,
            'block_index' => '',
          ]);
          $this->append_rows($rows, $this->parse_links_from_html($val, $context));
        }
      }
    }

    return $this->dedupe_crawl_rows_by_row_id($rows);
  }

  private function dedupe_crawl_rows_by_row_id($rows) {
    if (!is_array($rows) || empty($rows)) {
      return [];
    }

    $deduped = [];
    $seen = [];
    foreach ($rows as $row) {
      if (!is_array($row)) {
        continue;
      }
      $rowId = isset($row['row_id']) ? (string)$row['row_id'] : '';
      if ($rowId !== '') {
        if (isset($seen[$rowId])) {
          continue;
        }
        $seen[$rowId] = true;
      }
      $deduped[] = $row;
    }

    return $deduped;
  }

  private function crawl_menus($enabledSources = null) {
    if (!is_array($enabledSources)) {
      $enabledSources = $this->get_enabled_scan_source_types();
    }
    if (!in_array('menu', array_map('sanitize_key', (array)$enabledSources), true)) {
      return [];
    }

    $rows = [];
    $menus = wp_get_nav_menus();
    if (empty($menus) || !is_array($menus)) return $rows;

    $baseContext = [
      'post_id' => '',
      'post_title' => '',
      'post_type' => 'menu',
      'post_date' => '',
      'post_modified' => '',
      'post_author' => '',
      'page_url' => '',
    ];

    $enabledValueTypesMap = $this->get_enabled_scan_value_types_map_cached();

    foreach ($menus as $menu) {
      $items = wp_get_nav_menu_items($menu->term_id);
      if (empty($items)) continue;

      $occ = 0;
      foreach ($items as $item) {
        $url = isset($item->url) ? (string)$item->url : '';
        $title = isset($item->title) ? (string)$item->title : '';

        $resolved = $this->normalize_url($this->resolve_to_absolute($url, home_url('/')));
        $valueType = $this->detect_link_value_type($url);
        if (!$this->is_scan_value_type_enabled($valueType, $enabledValueTypesMap)) {
          $occ++;
          continue;
        }

        $linkType = $this->is_external($resolved) ? 'exlink' : 'inlink';

        $menuBlockIndex = 'menu_item:' . (string)($item->ID ?? '');
        $rowId = $this->row_id('', 'menu', 'menu:' . (string)$menu->name, $menuBlockIndex, $occ, $resolved);

        $rows[] = array_merge($baseContext, [
          'row_id' => $rowId,
          'occurrence' => (string)$occ,
          'source' => 'menu',
          'link_location' => 'menu:' . (string)$menu->name,
          'block_index' => $menuBlockIndex,
          'link' => $resolved,
          'link_raw' => $url,
          'anchor_text' => $title,
          'alt_text' => '',
          'snippet' => '',
          'link_type' => $linkType,
          'relationship' => 'dofollow',
          'rel_raw' => '',
          'rel_nofollow' => '0',
          'rel_sponsored' => '0',
          'rel_ugc' => '0',
          'value_type' => $valueType,
        ]);

        $occ++;
      }
    }

    return $rows;
  }
}
