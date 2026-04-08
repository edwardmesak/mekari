<?php
/**
 * Anchor quality classification helpers.
 */

if (!defined('ABSPATH')) {
  exit;
}

trait LM_Anchor_Quality_Helpers_Trait {
  private function get_anchor_quality_length_thresholds() {
    $settings = $this->get_settings();
    $shortMin = isset($settings['anchor_poor_short_min']) ? (int)$settings['anchor_poor_short_min'] : 3;
    $longMax = isset($settings['anchor_poor_long_max']) ? (int)$settings['anchor_poor_long_max'] : 100;

    $shortMin = max(1, min(1000, $shortMin));
    $longMax = max($shortMin, min(1000, $longMax));

    return [
      'short_min' => $shortMin,
      'long_max' => $longMax,
    ];
  }

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
    $anchor = $this->normalize_anchor_text_value($anchor, true);
    $lengthThresholds = $this->get_anchor_quality_length_thresholds();
    $shortMin = (int)$lengthThresholds['short_min'];
    $longMax = (int)$lengthThresholds['long_max'];

    if ($anchor === '') {
      return ['quality' => 'bad', 'warning' => 'Empty anchor text - use descriptive text'];
    }

    if ($this->has_weak_anchor_text($anchor)) {
      return ['quality' => 'poor', 'warning' => 'Weak anchor text for SEO - use more descriptive text'];
    }

    if (strlen($anchor) < $shortMin) {
      return ['quality' => 'poor', 'warning' => sprintf('Anchor text too short (< %d characters)', $shortMin)];
    }

    if (strlen($anchor) > $longMax) {
      return ['quality' => 'poor', 'warning' => sprintf('Anchor text too long (> %d characters), use a shorter version', $longMax)];
    }

    return ['quality' => 'good', 'warning' => ''];
  }

  private function get_anchor_quality_label($anchor) {
    $anchorKey = strtolower($this->normalize_anchor_text_value($anchor, true));
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
    $lengthThresholds = $this->get_anchor_quality_length_thresholds();

    return sprintf(
      'Good = descriptive anchor text (%d-%d chars), Poor = weak/generic or length issue, Bad = empty anchor text.',
      (int)$lengthThresholds['short_min'],
      (int)$lengthThresholds['long_max']
    );
  }
}
