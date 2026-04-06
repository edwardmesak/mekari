<?php
/**
 * Anchor target and grouping helper methods.
 */

if (!defined('ABSPATH')) {
  exit;
}

trait LM_Anchor_Helpers_Trait {
  private function get_anchor_config_version() {
    return (int)get_option('lm_anchor_config_version', 1);
  }

  private function bump_anchor_config_version() {
    $version = $this->get_anchor_config_version();
    update_option('lm_anchor_config_version', $version + 1, false);
    return $version + 1;
  }

  private function normalize_weak_anchor_patterns($raw) {
    $parts = preg_split('/[\r\n,]+/', (string)$raw);
    $clean = [];
    foreach ($parts as $p) {
      $t = strtolower(trim((string)$p));
      if ($t === '') continue;
      $clean[] = $t;
    }
    return array_values(array_unique($clean));
  }

  private function get_default_weak_anchor_patterns() {
    return [
      'click here', 'read more', 'read full post', 'learn more', 'find out more',
      'more information', 'see more', 'view more', 'go to', 'link', 'click',
      'here', 'this', 'that', 'it', 'download', 'get', 'buy', 'order',
      '...', '->', '>>', '>', '->->', '=>', 'lihat lebih lanjut', 'klik di sini',
      'baca selengkapnya', 'lihat selengkapnya', 'pelajari', 'dapatkan',
      'click this', 'click link', 'this link', 'continue reading', 'read article',
      'read post', 'see details', 'view details', 'discover more', 'selengkapnya',
      'baca lebih lanjut', 'baca lebih lengkap', 'klik', 'klik di sini sekarang',
      'lihat', 'lihat detail', 'lihat info', 'cek', 'kunjungi', 'lanjut',
      'lanjutkan membaca', 'pelajari lebih lanjut', 'info lengkap', 'itu', 'ini',
      '>>>', '--', '[...]', 'selengkapnya...'
    ];
  }

  private function get_legacy_default_weak_anchor_patterns() {
    return [
      'click here', 'read more', 'read full post', 'learn more', 'find out more',
      'more information', 'see more', 'view more', 'go to', 'link', 'click',
      'here', 'this', 'that', 'it', 'download', 'get', 'buy', 'order',
      '...', '->', '>>', '>', '->->', '=>', 'lihat lebih lanjut', 'klik di sini',
      'baca selengkapnya', 'lihat selengkapnya', 'pelajari', 'dapatkan',
      'learn', 'more', 'read', 'details', 'view', 'open', 'check', 'visit',
      'click this', 'click link', 'this link', 'continue reading', 'read article',
      'read post', 'see details', 'view details', 'discover more', 'selengkapnya',
      'baca lebih lanjut', 'baca lebih lengkap', 'klik', 'klik di sini sekarang',
      'lihat', 'lihat detail', 'lihat info', 'cek', 'kunjungi', 'lanjut',
      'lanjutkan membaca', 'pelajari lebih lanjut', 'info lengkap', 'itu', 'ini',
      'halaman', 'artikel', '>>>', '--', '[...]', 'selengkapnya...'
    ];
  }

  private function maybe_migrate_legacy_weak_anchor_patterns() {
    $settings = $this->get_settings();
    $raw = (string)($settings['weak_anchor_patterns'] ?? '');
    $currentPatterns = $this->normalize_weak_anchor_patterns($raw);
    if (empty($currentPatterns)) {
      return;
    }

    $legacyDefaults = $this->get_legacy_default_weak_anchor_patterns();
    if ($currentPatterns !== $legacyDefaults) {
      return;
    }

    $newDefaults = $this->get_default_weak_anchor_patterns();
    $settings['weak_anchor_patterns'] = implode("\n", $newDefaults);
    $this->weak_anchor_patterns_cache = $newDefaults;
    $this->save_settings($settings);
    $this->bump_anchor_config_version();
    $this->clear_cache_all();
    $this->schedule_background_rebuild('any', 'all', 2);
  }

  private function get_weak_anchor_patterns() {
    if (is_array($this->weak_anchor_patterns_cache)) {
      return $this->weak_anchor_patterns_cache;
    }

    $settings = $this->get_settings();
    $patterns = $this->normalize_weak_anchor_patterns((string)($settings['weak_anchor_patterns'] ?? ''));

    $this->weak_anchor_patterns_cache = $patterns;
    return $patterns;
  }

  private function get_anchor_groups() {
    $groups = get_option('lm_anchor_groups', []);
    return is_array($groups) ? $groups : [];
  }

  private function get_anchor_targets() {
    $targets = get_option('lm_anchor_targets', []);
    return is_array($targets) ? $targets : [];
  }

  private function save_anchor_groups($groups) {
    update_option('lm_anchor_groups', $groups, false);
    $this->bump_anchor_config_version();
  }

  private function save_anchor_targets($targets) {
    update_option('lm_anchor_targets', $targets, false);
    $this->bump_anchor_config_version();
  }

  private function sync_targets_with_groups($targets, $groups) {
    $targets = is_array($targets) ? $targets : [];
    $groups = is_array($groups) ? $groups : [];
    $all = $targets;
    foreach ($groups as $g) {
      $anchors = isset($g['anchors']) ? (array)$g['anchors'] : [];
      foreach ($anchors as $a) {
        $a = trim((string)$a);
        if ($a === '') continue;
        $all[] = $a;
      }
    }
    $merged = $this->normalize_anchor_list(implode("\n", $all));
    if ($merged !== $targets) {
      $this->save_anchor_targets($merged);
    }
    return $merged;
  }

  private function normalize_anchor_list($raw) {
    $parts = preg_split('/[\r\n,]+/', (string)$raw);
    $clean = [];
    foreach ($parts as $p) {
      $t = trim($p);
      if ($t === '') continue;
      $clean[] = $t;
    }
    return array_values(array_unique($clean));
  }
}
