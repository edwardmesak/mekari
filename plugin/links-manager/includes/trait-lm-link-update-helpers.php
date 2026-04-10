<?php
/**
 * Link mutation helpers for content, excerpt, meta, and menu contexts.
 */

if (!defined('ABSPATH')) {
  exit;
}

trait LM_Link_Update_Helpers_Trait {
  private function serialize_dom_document_html($doc) {
    if (!$doc instanceof DOMDocument) {
      return '';
    }

    $html = '';
    foreach ($doc->childNodes as $childNode) {
      if ($childNode instanceof DOMProcessingInstruction) {
        continue;
      }
      $html .= $doc->saveHTML($childNode);
    }

    return (string)$html;
  }

  private function update_single_occurrence_in_html_dom($html, $old_link, $occurrence, $new_link, $new_rel = '', $new_anchor = null, $page_url = '') {
    if (!is_string($html) || trim($html) === '') {
      return ['html' => $html, 'changed' => 0, 'error' => 'empty_html'];
    }

    $oldC = $this->normalize_for_compare($old_link);
    $new_link = trim((string)$new_link);
    $new_rel = trim((string)$new_rel);
    if ($oldC === '' || $new_link === '') {
      return ['html' => $html, 'changed' => 0, 'error' => 'invalid_input'];
    }

    $targetOcc = max(0, (int)$occurrence);
    $contextPageUrl = (string)$page_url;

    libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    $wrapped = '<?xml encoding="utf-8" ?>' . $html;
    $loaded = $doc->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    if (!$loaded) {
      libxml_clear_errors();
      return ['html' => $html, 'changed' => 0, 'error' => 'dom_load_failed'];
    }

    $links = $doc->getElementsByTagName('a');
    if (!$links || $links->length < 1) {
      libxml_clear_errors();
      return ['html' => $html, 'changed' => 0, 'error' => 'target_not_found'];
    }

    $matchedIndexes = [];
    for ($i = 0; $i < $links->length; $i++) {
      $linkNode = $links->item($i);
      if (!$linkNode instanceof DOMElement) {
        continue;
      }

      $hrefRaw = $linkNode->getAttribute('href');
      $hrefComparable = $contextPageUrl !== '' ? $this->resolve_to_absolute($hrefRaw, $contextPageUrl) : $hrefRaw;
      $hrefNorm = (string)$this->normalize_for_compare($hrefComparable);
      if ($hrefNorm !== '' && $hrefNorm === $oldC) {
        $matchedIndexes[] = $i;
      }
    }

    $targetIndex = null;
    if (in_array($targetOcc, $matchedIndexes, true)) {
      $targetIndex = $targetOcc;
    } elseif (count($matchedIndexes) === 1) {
      $targetIndex = (int)$matchedIndexes[0];
    }

    if ($targetIndex === null) {
      libxml_clear_errors();
      return ['html' => $html, 'changed' => 0, 'error' => 'target_not_found'];
    }

    $targetNode = $links->item($targetIndex);
    if (!$targetNode instanceof DOMElement) {
      libxml_clear_errors();
      return ['html' => $html, 'changed' => 0, 'error' => 'target_not_found'];
    }

    $targetNode->setAttribute('href', (string)$new_link);
    if ($new_rel !== '') {
      $targetNode->setAttribute('rel', (string)$new_rel);
    }

    if ($new_anchor !== null) {
      while ($targetNode->firstChild) {
        $targetNode->removeChild($targetNode->firstChild);
      }
      $targetNode->appendChild($doc->createTextNode((string)$new_anchor));
    }

    $newHtml = $this->serialize_dom_document_html($doc);
    libxml_clear_errors();

    return ['html' => $newHtml !== '' ? $newHtml : $html, 'changed' => 1, 'error' => ''];
  }

  private function update_single_occurrence_in_html($html, $old_link, $occurrence, $new_link, $new_rel = '', $new_anchor = null, $page_url = '') {
    if (!is_string($html) || trim($html) === '') return ['html' => $html, 'changed' => 0, 'error' => 'empty_html'];

    $oldC = $this->normalize_for_compare($old_link);
    $new_link = trim((string)$new_link);
    $new_rel  = trim((string)$new_rel);

    if ($oldC === '' || $new_link === '') return ['html' => $html, 'changed' => 0, 'error' => 'invalid_input'];
    $targetOcc = (int)$occurrence;
    if ($targetOcc < 0) $targetOcc = 0;

    $linkIndex = 0;
    $matchedTotal = 0;
    $changed = 0;

    $pattern = '/<a\b[^>]*>.*?<\/a>/is';
    $contextPageUrl = (string)$page_url;
    $self = $this;
    $newHtml = preg_replace_callback($pattern, function($m) use ($oldC, $new_link, $new_rel, $new_anchor, $targetOcc, &$linkIndex, &$matchedTotal, &$changed, $self, $contextPageUrl) {
      $full = $m[0];
      $currentIndex = $linkIndex;
      $linkIndex++;

      if (!preg_match('/\bhref\s*=\s*(["\'])(.*?)\1/i', $full, $hm)) return $full;

      $quote = $hm[1];
      $hrefRaw = $hm[2];
      $hrefComparable = $hrefRaw;
      if ($contextPageUrl !== '') {
        $hrefComparable = $self->resolve_to_absolute($hrefRaw, $contextPageUrl);
      }
      $hrefNorm = (string)$self->normalize_for_compare($hrefComparable);

      if ($hrefNorm !== '' && $hrefNorm === $oldC) {
        $matchedTotal++;
      }

      if ($currentIndex !== $targetOcc) {
        return $full;
      }

      if ($hrefNorm === '' || $hrefNorm !== $oldC) {
        return $full;
      }

      if (!preg_match('/^(<a\b[^>]*>)(.*)(<\/a>)$/is', $full, $parts)) return $full;

      $openTag = $parts[1];
      $innerHtml = $parts[2];
      $closeTag = $parts[3];

      $tag2 = preg_replace('/\bhref\s*=\s*(["\'])(.*?)\1/i', 'href=' . $quote . esc_attr($new_link) . $quote, $openTag, 1);

      if ($new_rel !== '') {
        if (preg_match('/\brel\s*=\s*(["\'])(.*?)\1/i', $tag2)) {
          $tag2 = preg_replace('/\brel\s*=\s*(["\'])(.*?)\1/i', 'rel=' . $quote . esc_attr($new_rel) . $quote, $tag2, 1);
        } else {
          $tag2 = rtrim($tag2, '>');
          $tag2 .= ' rel=' . $quote . esc_attr($new_rel) . $quote . '>';
        }
      }

      $newInner = $innerHtml;
      if ($new_anchor !== null) {
        $newInner = esc_html((string)$new_anchor);
      }

      $changed = 1;
      return $tag2 . $newInner . $closeTag;
    }, $html);

    if ($newHtml === null) $newHtml = $html;

    if ($changed !== 1 && $matchedTotal === 1) {
      $fallbackMatchSeen = false;
      $newHtmlFallback = preg_replace_callback($pattern, function($m) use ($oldC, $new_link, $new_rel, $new_anchor, &$fallbackMatchSeen, &$changed, $self, $contextPageUrl) {
        $full = $m[0];

        if (!preg_match('/\bhref\s*=\s*(["\'])(.*?)\1/i', $full, $hm)) return $full;

        $quote = $hm[1];
        $hrefRaw = $hm[2];
        $hrefComparable = $hrefRaw;
        if ($contextPageUrl !== '') {
          $hrefComparable = $self->resolve_to_absolute($hrefRaw, $contextPageUrl);
        }
        $hrefNorm = (string)$self->normalize_for_compare($hrefComparable);
        if ($hrefNorm === '' || $hrefNorm !== $oldC) return $full;

        if ($fallbackMatchSeen) {
          return $full;
        }
        $fallbackMatchSeen = true;

        if (!preg_match('/^(<a\b[^>]*>)(.*)(<\/a>)$/is', $full, $parts)) return $full;

        $openTag = $parts[1];
        $innerHtml = $parts[2];
        $closeTag = $parts[3];

        $tag2 = preg_replace('/\bhref\s*=\s*(["\'])(.*?)\1/i', 'href=' . $quote . esc_attr($new_link) . $quote, $openTag, 1);

        if ($new_rel !== '') {
          if (preg_match('/\brel\s*=\s*(["\'])(.*?)\1/i', $tag2)) {
            $tag2 = preg_replace('/\brel\s*=\s*(["\'])(.*?)\1/i', 'rel=' . $quote . esc_attr($new_rel) . $quote, $tag2, 1);
          } else {
            $tag2 = rtrim($tag2, '>');
            $tag2 .= ' rel=' . $quote . esc_attr($new_rel) . $quote . '>';
          }
        }

        $newInner = $innerHtml;
        if ($new_anchor !== null) {
          $newInner = esc_html((string)$new_anchor);
        }

        $changed = 1;
        return $tag2 . $newInner . $closeTag;
      }, $html);

      if ($newHtmlFallback !== null) {
        $newHtml = $newHtmlFallback;
      }
    }

    if ($changed !== 1) {
      $domFallback = $this->update_single_occurrence_in_html_dom($html, $old_link, $occurrence, $new_link, $new_rel, $new_anchor, $page_url);
      if ((int)($domFallback['changed'] ?? 0) === 1) {
        return $domFallback;
      }
    }

    return ['html' => $newHtml, 'changed' => $changed, 'error' => ''];
  }

  private function get_current_row_for_update_context($post_id, $source, $location, $block_index, $occurrence) {
    $source = (string)$source;
    $location = (string)$location;
    $block_index = (string)$block_index;
    $occurrence = max(0, (int)$occurrence);

    if ($source === 'menu') {
      if (strpos($location, 'menu:') !== 0) {
        return ['ok' => false, 'msg' => 'Invalid menu target.'];
      }

      $menuName = trim(substr($location, 5));
      if ($menuName === '') {
        return ['ok' => false, 'msg' => 'Menu name is empty.'];
      }

      $item = null;
      if (strpos($block_index, 'menu_item:') === 0) {
        $itemId = (int)substr($block_index, strlen('menu_item:'));
        if ($itemId > 0) {
          $candidate = wp_setup_nav_menu_item(get_post($itemId));
          if ($candidate && !empty($candidate->ID)) {
            $item = $candidate;
          }
        }
      }

      if (!$item) {
        $menu = null;
        $menus = wp_get_nav_menus();
        if (is_array($menus)) {
          foreach ($menus as $candidate) {
            if ((string)($candidate->name ?? '') === $menuName) {
              $menu = $candidate;
              break;
            }
          }
        }
        if (!$menu || empty($menu->term_id)) {
          return ['ok' => false, 'msg' => 'Menu not found.'];
        }

        $items = wp_get_nav_menu_items((int)$menu->term_id);
        if (!is_array($items) || !isset($items[$occurrence])) {
          return ['ok' => false, 'msg' => 'Menu target changed. Rebuild and try again.'];
        }
        $item = $items[$occurrence];
      }

      if (!$item || empty($item->ID)) {
        return ['ok' => false, 'msg' => 'Menu item not found.'];
      }

      $resolved = $this->normalize_url($this->resolve_to_absolute((string)($item->url ?? ''), home_url('/')));
      return [
        'ok' => true,
        'row' => [
          'post_id' => '',
          'source' => 'menu',
          'link_location' => $location,
          'block_index' => $block_index,
          'occurrence' => (string)$occurrence,
          'link' => $resolved,
          'row_id' => $this->row_id('', 'menu', $location, $block_index, $occurrence, $resolved),
        ],
      ];
    }

    $post_id = (int)$post_id;
    $post = get_post($post_id);
    if (!$post) {
      return ['ok' => false, 'msg' => 'Post not found'];
    }

    $pageUrl = get_permalink($post_id);
    $rows = [];

    if ($source === 'content') {
      $content = (string)$post->post_content;
      $blocks = function_exists('parse_blocks') ? parse_blocks($content) : [];
      $isClassicContentTarget = ($location === 'classic' || trim($block_index) === '');

      if (empty($blocks) || $isClassicContentTarget) {
        $rows = $this->parse_links_from_html($content, [
          'post_id' => (string)$post_id,
          'source' => 'content',
          'link_location' => 'classic',
          'block_index' => '',
          'page_url' => (string)$pageUrl,
        ]);
      } else {
        $idx = (int)$block_index;
        if (!isset($blocks[$idx])) {
          return ['ok' => false, 'msg' => 'Invalid block index (content changed?)'];
        }

        $block = $blocks[$idx];
        $blockName = isset($block['blockName']) && $block['blockName'] ? (string)$block['blockName'] : 'classic';
        if ($location !== $blockName) {
          return ['ok' => false, 'msg' => 'Block target changed (content updated). Rebuild and try again.'];
        }

        $html = $this->render_block_html_best_effort($block);
        $rows = $this->parse_links_from_html($html, [
          'post_id' => (string)$post_id,
          'source' => 'content',
          'link_location' => $location,
          'block_index' => $block_index,
          'page_url' => (string)$pageUrl,
        ]);
      }
    } elseif ($source === 'excerpt') {
      $rows = $this->parse_links_from_html((string)$post->post_excerpt, [
        'post_id' => (string)$post_id,
        'source' => 'excerpt',
        'link_location' => 'excerpt',
        'block_index' => '',
        'page_url' => (string)$pageUrl,
      ]);
    } elseif ($source === 'meta') {
      if (strpos($location, 'meta:') !== 0) {
        return ['ok' => false, 'msg' => 'Invalid meta key.'];
      }
      $key = substr($location, 5);
      if ($key === '') {
        return ['ok' => false, 'msg' => 'Meta key is empty.'];
      }

      $val = get_post_meta($post_id, $key, true);
      if (!is_string($val)) {
        return ['ok' => false, 'msg' => 'Meta value is not a string.'];
      }

      $rows = $this->parse_links_from_html($val, [
        'post_id' => (string)$post_id,
        'source' => 'meta',
        'link_location' => $location,
        'block_index' => '',
        'page_url' => (string)$pageUrl,
      ]);
    } else {
      return ['ok' => false, 'msg' => 'Source not supported for update.'];
    }

    if (!isset($rows[$occurrence]) || !is_array($rows[$occurrence])) {
      return ['ok' => false, 'msg' => 'Target link not found (content changed?)'];
    }

    return ['ok' => true, 'row' => $rows[$occurrence]];
  }

  private function update_post_by_context($post_id, $old_link, $source, $location, $block_index, $occurrence, $new_link, $new_rel = '', $new_anchor = null) {
    $source = (string)$source;
    $location = (string)$location;
    $block_index = (string)$block_index;

    if ($source === 'menu') {
      if (strpos($location, 'menu:') !== 0) {
        return ['ok' => false, 'msg' => 'Invalid menu target.'];
      }

      $menuName = trim(substr($location, 5));
      if ($menuName === '') {
        return ['ok' => false, 'msg' => 'Menu name is empty.'];
      }

      $item = null;
      $menuTermId = 0;

      if (strpos($block_index, 'menu_item:') === 0) {
        $itemId = (int)substr($block_index, strlen('menu_item:'));
        if ($itemId > 0) {
          $candidate = wp_setup_nav_menu_item(get_post($itemId));
          if ($candidate && !empty($candidate->ID)) {
            $item = $candidate;
            $menuTerms = wp_get_object_terms((int)$candidate->ID, 'nav_menu', ['fields' => 'ids']);
            if (is_array($menuTerms) && !empty($menuTerms)) {
              $menuTermId = (int)reset($menuTerms);
            }
          }
        }
      }

      if (!$item) {
        $menu = null;
        $menus = wp_get_nav_menus();
        if (is_array($menus)) {
          foreach ($menus as $candidate) {
            if ((string)($candidate->name ?? '') === $menuName) {
              $menu = $candidate;
              break;
            }
          }
        }
        if (!$menu || empty($menu->term_id)) {
          return ['ok' => false, 'msg' => 'Menu not found.'];
        }

        $menuTermId = (int)$menu->term_id;
        $items = wp_get_nav_menu_items($menuTermId);
        if (empty($items) || !is_array($items)) {
          return ['ok' => false, 'msg' => 'Menu has no items.'];
        }

        $occ = (int)$occurrence;
        if ($occ < 0 || !isset($items[$occ])) {
          return ['ok' => false, 'msg' => 'Menu target changed. Rebuild and try again.'];
        }

        $item = $items[$occ];
      }

      if (!$item || empty($item->ID)) {
        return ['ok' => false, 'msg' => 'Menu item not found.'];
      }
      if ($menuTermId <= 0) {
        return ['ok' => false, 'msg' => 'Menu context not found.'];
      }
      $itemUrlRaw = isset($item->url) ? (string)$item->url : '';
      $itemResolved = $this->normalize_url($this->resolve_to_absolute($itemUrlRaw, home_url('/')));
      if ($this->normalize_for_compare($itemResolved) !== $this->normalize_for_compare($old_link)) {
        return ['ok' => false, 'msg' => 'Target link not found in menu (menu changed?)'];
      }

      $classes = [];
      if (isset($item->classes) && is_array($item->classes)) {
        foreach ($item->classes as $className) {
          $className = trim((string)$className);
          if ($className !== '') {
            $classes[] = $className;
          }
        }
      }

      $updatedItemId = wp_update_nav_menu_item($menuTermId, (int)$item->ID, [
        'menu-item-db-id' => (int)$item->ID,
        'menu-item-object-id' => (int)($item->object_id ?? 0),
        'menu-item-object' => (string)($item->object ?? ''),
        'menu-item-parent-id' => (int)($item->menu_item_parent ?? 0),
        'menu-item-position' => (int)($item->menu_order ?? 0),
        'menu-item-type' => (string)($item->type ?? 'custom'),
        'menu-item-title' => $new_anchor !== null ? (string)$new_anchor : (string)($item->title ?? ''),
        'menu-item-url' => (string)$new_link,
        'menu-item-description' => (string)($item->description ?? ''),
        'menu-item-attr-title' => isset($item->attr_title) ? (string)$item->attr_title : '',
        'menu-item-target' => (string)($item->target ?? ''),
        'menu-item-classes' => implode(' ', $classes),
        'menu-item-xfn' => (string)($item->xfn ?? ''),
        'menu-item-status' => (string)($item->post_status ?? 'publish'),
      ]);

      if (is_wp_error($updatedItemId)) {
        return ['ok' => false, 'msg' => $updatedItemId->get_error_message()];
      }

      return ['ok' => true, 'msg' => 'Successfully updated 1 link in menu.'];
    }

    $post = get_post($post_id);
    if (!$post) return ['ok' => false, 'msg' => 'Post not found'];
    $pageUrl = get_permalink($post_id);

    if ($source === 'content') {
      $content = (string)$post->post_content;
      $blocks = function_exists('parse_blocks') ? parse_blocks($content) : [];
      $isClassicContentTarget = ($location === 'classic' || trim((string)$block_index) === '');

      if (empty($blocks) || $isClassicContentTarget) {
        $res = $this->update_single_occurrence_in_html($content, $old_link, $occurrence, $new_link, $new_rel, $new_anchor, (string)$pageUrl);
        if ($res['changed'] !== 1) {
          return ['ok' => false, 'msg' => 'Target link not found (content changed?)'];
        }
        $u = wp_update_post(['ID' => $post_id, 'post_content' => $res['html']], true);
        if (is_wp_error($u)) return ['ok' => false, 'msg' => $u->get_error_message()];
        return ['ok' => true, 'msg' => 'Successfully updated 1 link in content.'];
      }

      $idx = (int)$block_index;
      if (!isset($blocks[$idx])) return ['ok' => false, 'msg' => 'Invalid block index (content changed?)'];

      $bn = isset($blocks[$idx]['blockName']) && $blocks[$idx]['blockName'] ? $blocks[$idx]['blockName'] : 'classic';
      if ($location !== $bn) {
        return ['ok' => false, 'msg' => 'Block target changed (content updated). Rebuild and try again.'];
      }

      $block = $blocks[$idx];
      $html = '';
      $mode = '';

      if (isset($block['innerHTML']) && is_string($block['innerHTML']) && trim($block['innerHTML']) !== '') {
        $html = $block['innerHTML'];
        $mode = 'innerHTML';
      } elseif (isset($block['innerContent']) && is_array($block['innerContent'])) {
        $html = implode('', array_map('strval', $block['innerContent']));
        $mode = 'innerContent';
      } else {
        return ['ok' => false, 'msg' => 'Block has no editable HTML (fail-safe).'];
      }

      $res = $this->update_single_occurrence_in_html($html, $old_link, $occurrence, $new_link, $new_rel, $new_anchor, (string)$pageUrl);
      if ($res['changed'] !== 1) {
        return ['ok' => false, 'msg' => 'Target link not found in block (content changed?)'];
      }

      if ($mode === 'innerHTML') {
        $blocks[$idx]['innerHTML'] = $res['html'];
        $blocks[$idx]['innerContent'] = [$res['html']];
      } else {
        $blocks[$idx]['innerContent'] = [$res['html']];
        $blocks[$idx]['innerHTML'] = $res['html'];
      }

      if (!function_exists('serialize_blocks')) {
        return ['ok' => false, 'msg' => 'serialize_blocks not available.'];
      }

      $newContent = serialize_blocks($blocks);
      $u = wp_update_post(['ID' => $post_id, 'post_content' => $newContent], true);
      if (is_wp_error($u)) return ['ok' => false, 'msg' => $u->get_error_message()];

      return ['ok' => true, 'msg' => 'Successfully updated 1 link in block content.'];
    }

    if ($source === 'excerpt') {
      $excerpt = (string)$post->post_excerpt;
      $res = $this->update_single_occurrence_in_html($excerpt, $old_link, $occurrence, $new_link, $new_rel, $new_anchor, (string)$pageUrl);
      if ($res['changed'] !== 1) return ['ok' => false, 'msg' => 'Target link not found in excerpt (content changed?)'];
      $u = wp_update_post(['ID' => $post_id, 'post_excerpt' => $res['html']], true);
      if (is_wp_error($u)) return ['ok' => false, 'msg' => $u->get_error_message()];
      return ['ok' => true, 'msg' => 'Successfully updated 1 link in excerpt.'];
    }

    if ($source === 'meta') {
      if (strpos($location, 'meta:') !== 0) return ['ok' => false, 'msg' => 'Invalid meta key.'];
      $key = substr($location, 5);
      if ($key === '') return ['ok' => false, 'msg' => 'Meta key is empty.'];

      $meta_keys = apply_filters('lm_scan_meta_keys', []);
      $meta_keys = array_values(array_unique(array_filter(array_map('strval', (array)$meta_keys))));
      if (!in_array($key, $meta_keys, true)) return ['ok' => false, 'msg' => 'Meta key not allowed (whitelist).'];

      $val = get_post_meta($post_id, $key, true);
      if (!is_string($val)) return ['ok' => false, 'msg' => 'Meta value is not a string.'];

      $res = $this->update_single_occurrence_in_html($val, $old_link, $occurrence, $new_link, $new_rel, $new_anchor, (string)$pageUrl);
      if ($res['changed'] !== 1) return ['ok' => false, 'msg' => 'Target link not found in meta (content changed?)'];

      update_post_meta($post_id, $key, $res['html']);
      return ['ok' => true, 'msg' => 'Successfully updated 1 link in meta.'];
    }

    return ['ok' => false, 'msg' => 'Source not supported for update.'];
  }
}
