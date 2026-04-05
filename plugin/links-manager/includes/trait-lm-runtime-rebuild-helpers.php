<?php
/**
 * Runtime limit, rebuild job state, and lock coordination helpers.
 */

trait LM_Runtime_Rebuild_Helpers_Trait {
  private function parse_php_bytes($value) {
    $value = trim((string)$value);
    if ($value === '') return 0;

    $last = strtolower(substr($value, -1));
    $num = (float)$value;
    if (!is_finite($num) || $num <= 0) return 0;

    if ($last === 'g') return (int)($num * 1024 * 1024 * 1024);
    if ($last === 'm') return (int)($num * 1024 * 1024);
    if ($last === 'k') return (int)($num * 1024);
    return (int)$num;
  }

  private function get_effective_memory_limit_bytes() {
    $bytes = $this->parse_php_bytes(ini_get('memory_limit'));
    if ($bytes <= 0) {
      return 268435456;
    }
    return $bytes;
  }

  private function get_runtime_max_cache_rows() {
    $limit = $this->get_effective_memory_limit_bytes();
    if ($limit <= 268435456) return 5000;
    if ($limit <= 402653184) return 8000;
    if ($limit <= 536870912) return 12000;
    return self::MAX_CACHE_ROWS;
  }

  private function get_runtime_max_crawl_batch() {
    $limit = $this->get_effective_memory_limit_bytes();
    if ($limit <= 268435456) return 40;
    if ($limit <= 402653184) return 60;
    if ($limit <= 536870912) return 100;
    return 200;
  }

  private function get_safe_transient_limit_bytes($isBackup = false) {
    $limit = $this->get_effective_memory_limit_bytes();
    $ratio = $isBackup ? 0.2 : 0.12;
    $cap = (int)floor($limit * $ratio);

    $min = $isBackup ? 33554432 : 16777216;
    $max = $isBackup ? 134217728 : 67108864;
    if ($cap < $min) $cap = $min;
    if ($cap > $max) $cap = $max;

    return $cap;
  }

  private function get_crawl_time_budget_seconds() {
    $maxExecution = (int)ini_get('max_execution_time');
    if ($maxExecution <= 0) {
      return 20;
    }

    $budget = $maxExecution - 5;
    if ($budget < 5) $budget = 5;
    if ($budget > 20) $budget = 20;
    return $budget;
  }

  private function should_abort_crawl($startedAt) {
    $elapsed = microtime(true) - (float)$startedAt;
    if ($elapsed >= $this->get_crawl_time_budget_seconds()) {
      return true;
    }

    $memoryLimit = $this->parse_php_bytes(ini_get('memory_limit'));
    if ($memoryLimit > 0) {
      $used = memory_get_usage(true);
      if ($used >= (int)($memoryLimit * 0.9)) {
        return true;
      }
    }

    return false;
  }

  private function rebuild_job_state_option_key() {
    return 'lm_rebuild_job_state_' . get_current_blog_id();
  }

  private function rebuild_job_partial_rows_key($scopePostType, $wpmlLang) {
    return 'lm_rebuild_partial_' . md5((string)$scopePostType . '|' . (string)$wpmlLang . '|' . get_current_blog_id());
  }

  private function get_rebuild_job_state() {
    $state = get_option($this->rebuild_job_state_option_key(), []);
    return is_array($state) ? $state : [];
  }

  private function save_rebuild_job_state($state) {
    if (!is_array($state)) {
      $state = [];
    }
    update_option($this->rebuild_job_state_option_key(), $state, false);
  }

  private function clear_rebuild_job_state() {
    $state = $this->get_rebuild_job_state();
    if (!empty($state['scope_post_type']) && !empty($state['wpml_lang'])) {
      delete_transient($this->rebuild_job_partial_rows_key((string)$state['scope_post_type'], (string)$state['wpml_lang']));
    }
    delete_option($this->rebuild_job_state_option_key());
  }

  private function rebuild_job_lock_key() {
    return 'lm_rebuild_job_lock_' . get_current_blog_id();
  }

  private function acquire_rebuild_job_lock($ttl = 30) {
    global $wpdb;

    $ttl = max(5, (int)$ttl);
    $key = $this->rebuild_job_lock_key();
    $now = time();
    $expiresAt = $now + $ttl;

    if (add_option($key, (string)$expiresAt, '', false)) {
      return true;
    }

    $currentRaw = get_option($key, '0');
    $current = (int)$currentRaw;
    if ($current > $now) {
      return false;
    }

    $updated = $wpdb->query(
      $wpdb->prepare(
        "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND CAST(option_value AS UNSIGNED) <= %d",
        (string)$expiresAt,
        $key,
        $now
      )
    );

    return (int)$updated > 0;
  }

  private function release_rebuild_job_lock() {
    $key = $this->rebuild_job_lock_key();
    delete_option($key);
    delete_transient($key);
  }

  private function is_rebuild_job_stale($state, $maxAgeSeconds = 1800) {
    if (!is_array($state) || empty($state)) {
      return false;
    }
    if (!in_array(sanitize_key((string)($state['status'] ?? '')), ['running', 'finalizing'], true)) {
      return false;
    }

    $updatedAt = (string)($state['updated_at'] ?? '');
    if ($updatedAt === '') {
      return false;
    }

    $updatedTs = strtotime($updatedAt);
    if ($updatedTs === false || $updatedTs <= 0) {
      return false;
    }

    return (time() - $updatedTs) > max(60, (int)$maxAgeSeconds);
  }

  private function recover_stale_rebuild_job($state, $maxAgeSeconds = 1800) {
    if (!$this->is_rebuild_job_stale($state, $maxAgeSeconds)) {
      return $state;
    }

    $scopePostType = sanitize_key((string)($state['scope_post_type'] ?? 'any'));
    $wpmlLang = $this->get_effective_scan_wpml_lang((string)($state['wpml_lang'] ?? 'all'));
    delete_transient($this->rebuild_job_partial_rows_key($scopePostType, $wpmlLang));
    $this->release_rebuild_job_lock();

    $state['status'] = 'error';
    $state['last_error'] = 'Rebuild job recovered after stale timeout.';
    $state['message'] = 'Previous rebuild was stale and has been recovered. Please start again.';
    $state['updated_at'] = current_time('mysql');
    $this->save_rebuild_job_state($state);

    return $state;
  }

  private function get_rebuild_poll_ms($state) {
    $status = sanitize_key((string)($state['status'] ?? 'idle'));
    $message = strtolower((string)($state['message'] ?? ''));
    $stepMs = max(0, (int)($state['step_ms'] ?? 0));

    if (!in_array($status, ['running', 'finalizing'], true)) {
      return 0;
    }

    if ($message !== '' && strpos($message, 'already running') !== false) {
      return 1200;
    }

    if ($stepMs >= 3000) {
      return 1500;
    }
    if ($stepMs >= 1500) {
      return 900;
    }

    return 400;
  }

  private function get_public_rebuild_job_state($state) {
    if (!is_array($state) || empty($state)) {
      return [
        'status' => 'idle',
      ];
    }

    return [
      'status' => sanitize_key((string)($state['status'] ?? 'idle')),
      'scope_post_type' => sanitize_key((string)($state['scope_post_type'] ?? 'any')),
      'wpml_lang' => sanitize_key((string)($state['wpml_lang'] ?? 'all')),
      'offset' => max(0, (int)($state['offset'] ?? 0)),
      'total_posts' => max(0, (int)($state['total_posts'] ?? 0)),
      'processed_posts' => max(0, (int)($state['processed_posts'] ?? 0)),
      'rows_count' => max(0, (int)($state['rows_count'] ?? 0)),
      'started_at' => (string)($state['started_at'] ?? ''),
      'updated_at' => (string)($state['updated_at'] ?? ''),
      'batch_size' => max(0, (int)($state['batch_size'] ?? 0)),
      'step_ms' => max(0, (int)($state['step_ms'] ?? 0)),
      'last_error' => sanitize_text_field((string)($state['last_error'] ?? '')),
      'message' => sanitize_text_field((string)($state['message'] ?? '')),
      'poll_ms' => max(0, (int)$this->get_rebuild_poll_ms($state)),
    ];
  }
}
