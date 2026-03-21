<?php
/**
 * Small shared utility helpers used across admin actions and settings.
 */

trait LM_Core_Utils_Trait {
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
