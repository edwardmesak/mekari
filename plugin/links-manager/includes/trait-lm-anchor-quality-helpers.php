<?php
/**
 * Anchor quality classification helpers.
 */

if (!defined('ABSPATH')) {
  exit;
}

trait LM_Anchor_Quality_Helpers_Trait {
  private function has_weak_anchor_text($anchor) {
    $anchor = strtolower(trim((string)$anchor));
    if ($anchor === '') return true;

    $weak_patterns = $this->get_weak_anchor_patterns();

    foreach ($weak_patterns as $pattern) {
      if ($anchor === $pattern) {
        return true;
      }
    }

    return false;
  }

  private function get_anchor_quality_suggestion($anchor) {
    if (empty($anchor)) {
      return ['quality' => 'bad', 'warning' => 'Empty anchor text - use descriptive text'];
    }

    if ($this->has_weak_anchor_text($anchor)) {
      return ['quality' => 'poor', 'warning' => 'Weak anchor text for SEO - use more descriptive text'];
    }

    if (strlen($anchor) < 3) {
      return ['quality' => 'poor', 'warning' => 'Anchor text too short (< 3 characters)'];
    }

    if (strlen($anchor) > 100) {
      return ['quality' => 'poor', 'warning' => 'Anchor text too long (> 100 characters), use a shorter version'];
    }

    return ['quality' => 'good', 'warning' => ''];
  }

  private function get_anchor_quality_label($anchor) {
    $anchorKey = strtolower(trim((string)$anchor));
    if (isset($this->anchor_quality_label_cache[$anchorKey])) {
      return (string)$this->anchor_quality_label_cache[$anchorKey];
    }

    $q = $this->get_anchor_quality_suggestion($anchor);
    $quality = (string)($q['quality'] ?? 'poor');
    if ($quality === 'good' || $quality === 'poor' || $quality === 'bad') {
      $this->anchor_quality_label_cache[$anchorKey] = $quality;
      return $quality;
    }
    $this->anchor_quality_label_cache[$anchorKey] = 'poor';
    return 'poor';
  }

  private function get_anchor_quality_status_help_text() {
    return 'Good = descriptive anchor text (3-100 chars), Poor = weak/generic or length issue, Bad = empty anchor text.';
  }
}
