<?php
/**
 * Small shared utility helpers used across admin actions and settings.
 */

trait LM_Core_Utils_Trait {
  private function normalize_anchor_text_value($value, $collapseWhitespace = false) {
    $value = html_entity_decode((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $value = str_replace("\xC2\xA0", ' ', $value);
    $value = preg_replace('/[\x{00A0}\x{1680}\x{180E}\x{2000}-\x{200D}\x{2028}\x{2029}\x{202F}\x{205F}\x{2060}\x{3000}\x{FEFF}]/u', ' ', $value);
    if (!is_string($value)) {
      $value = (string)$value;
    }
    if ($collapseWhitespace) {
      $value = preg_replace('/\s+/u', ' ', $value);
      if (!is_string($value)) {
        $value = (string)$value;
      }
    }
    return trim($value);
  }

  private function normalize_new_anchor_input($rawValue, $oldAnchor = null) {
    if ($rawValue === null) {
      return null;
    }

    $clean = sanitize_text_field((string)$rawValue);
    if (trim($clean) === '') {
      return null;
    }

    if ($oldAnchor !== null) {
      $oldClean = sanitize_text_field((string)$oldAnchor);
      if ($clean === $oldClean) {
        return null;
      }
    }

    return $clean;
  }

  private function get_global_scan_term_ids($taxonomy) {
    $taxonomy = (string)$taxonomy;
    if ($taxonomy === 'category') {
      $settings = $this->get_settings();
      return $this->sanitize_scan_term_ids(isset($settings['scan_post_category_ids']) ? $settings['scan_post_category_ids'] : [], 'category');
    }
    if ($taxonomy === 'post_tag') {
      $settings = $this->get_settings();
      return $this->sanitize_scan_term_ids(isset($settings['scan_post_tag_ids']) ? $settings['scan_post_tag_ids'] : [], 'post_tag');
    }

    return [];
  }
}
