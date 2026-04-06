<?php
/**
 * Shared filter sanitization and text matching helpers.
 */

if (!defined('ABSPATH')) {
  exit;
}

trait LM_Filter_Helpers_Trait {
  private function get_filterable_post_types() {
    $availablePostTypes = $this->get_available_post_types();
    $enabledPostTypes = $this->get_enabled_scan_post_types();

    $filtered = [];
    foreach ($enabledPostTypes as $pt) {
      $pt = sanitize_key((string)$pt);
      if ($pt !== '' && isset($availablePostTypes[$pt])) {
        $filtered[$pt] = $availablePostTypes[$pt];
      }
    }

    if (empty($filtered)) {
      return $availablePostTypes;
    }

    return $filtered;
  }

  private function get_filterable_source_type_options($includeAny = true) {
    $allOptions = $this->get_scan_source_type_options();
    $enabled = $this->get_enabled_scan_source_types();

    $filtered = [];
    foreach ($enabled as $sourceKey) {
      $sourceKey = sanitize_key((string)$sourceKey);
      if ($sourceKey !== '' && isset($allOptions[$sourceKey])) {
        $filtered[$sourceKey] = (string)$allOptions[$sourceKey];
      }
    }

    if (empty($filtered)) {
      $filtered = $allOptions;
    }

    if (!$includeAny) {
      return $filtered;
    }

    return ['any' => 'All'] + $filtered;
  }

  private function sanitize_source_type_filter($rawValue, $allowAny = true) {
    $sourceType = sanitize_key((string)$rawValue);
    if ($allowAny && $sourceType === 'any') {
      return 'any';
    }

    $allowed = $this->get_filterable_source_type_options(false);
    if ($sourceType !== '' && isset($allowed[$sourceType])) {
      return $sourceType;
    }

    return $allowAny ? 'any' : '';
  }

  private function sanitize_text_match_mode($mode) {
    $mode = sanitize_key((string)$mode);
    if (!in_array($mode, ['contains', 'doesnt_contain', 'exact', 'regex', 'starts_with', 'ends_with'], true)) {
      $mode = 'contains';
    }
    return $mode;
  }

  private function sanitize_date_ymd($value) {
    $value = trim((string)$value);
    if ($value === '') return '';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) return '';
    $dt = DateTime::createFromFormat('Y-m-d', $value);
    if (!$dt || $dt->format('Y-m-d') !== $value) return '';
    return $value;
  }

  private function get_text_match_modes() {
    return [
      'contains' => 'Contains',
      'doesnt_contain' => "Doesn't contain",
      'exact' => 'Exact match',
      'regex' => 'Regex',
      'starts_with' => 'Starts with',
      'ends_with' => 'Ends with',
    ];
  }

  private function build_regex_pattern($input) {
    $input = trim((string)$input);
    if ($input === '') return '';

    if (array_key_exists($input, $this->regex_pattern_cache)) {
      return (string)$this->regex_pattern_cache[$input];
    }

    if (@preg_match($input, '') !== false) {
      $this->regex_pattern_cache[$input] = $input;
      return $input;
    }

    $escaped = preg_quote($input, '/');
    $pattern = '/' . $escaped . '/i';
    if (@preg_match($pattern, '') === false) {
      $this->regex_pattern_cache[$input] = '';
      return '';
    }
    $this->regex_pattern_cache[$input] = $pattern;
    return $pattern;
  }

  private function text_matches($haystack, $needle, $mode) {
    $needle = (string)$needle;
    if ($needle === '') return true;

    $haystack = (string)$haystack;
    $mode = $this->sanitize_text_match_mode($mode);

    if ($mode === 'exact') {
      return strcasecmp(trim($haystack), trim($needle)) === 0;
    }

    if ($mode === 'regex') {
      $pattern = $this->build_regex_pattern($needle);
      if ($pattern === '') return false;
      return preg_match($pattern, $haystack) === 1;
    }

    if ($mode === 'starts_with') {
      return stripos($haystack, $needle) === 0;
    }

    if ($mode === 'ends_with') {
      $haystackLower = strtolower($haystack);
      $needleLower = strtolower($needle);
      if ($needleLower === '') return true;
      $needleLen = strlen($needleLower);
      if ($needleLen > strlen($haystackLower)) return false;
      return substr($haystackLower, -$needleLen) === $needleLower;
    }

    if ($mode === 'doesnt_contain') {
      return stripos($haystack, $needle) === false;
    }

    return stripos($haystack, $needle) !== false;
  }
}
