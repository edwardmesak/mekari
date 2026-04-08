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

  private function get_finalize_time_budget_seconds() {
    $budget = $this->get_crawl_time_budget_seconds();
    if ($budget > 15) {
      $budget = 15;
    }
    if ($budget < 5) {
      $budget = 5;
    }
    return $budget;
  }

  private function should_abort_rebuild_phase($startedAt, $phase = 'crawl') {
    $elapsed = microtime(true) - (float)$startedAt;
    $budget = ($phase === 'finalize')
      ? $this->get_finalize_time_budget_seconds()
      : $this->get_crawl_time_budget_seconds();

    if ($elapsed >= $budget) {
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

  private function should_abort_crawl($startedAt) {
    return $this->should_abort_rebuild_phase($startedAt, 'crawl');
  }

  private function should_abort_finalize($startedAt) {
    return $this->should_abort_rebuild_phase($startedAt, 'finalize');
  }

  private function get_default_finalize_chunk_size() {
    $runtimeBatch = $this->get_runtime_max_crawl_batch();
    if ($runtimeBatch <= 40) {
      return 15;
    }
    if ($runtimeBatch <= 60) {
      return 20;
    }
    if ($runtimeBatch <= 100) {
      return 25;
    }
    return 40;
  }

  private function get_default_finalize_seed_chunk_size() {
    return $this->normalize_finalize_chunk_size(max(25, (int)floor($this->get_default_finalize_chunk_size() * 2)));
  }

  private function get_default_finalize_inbound_chunk_size() {
    return $this->normalize_finalize_chunk_size($this->get_default_finalize_chunk_size());
  }

  private function normalize_finalize_chunk_size($size) {
    $size = (int)$size;
    if ($size < 10) {
      $size = 10;
    }
    if ($size > 100) {
      $size = 100;
    }
    return $size;
  }

  private function get_finalize_chunk_size_from_state($state) {
    $saved = is_array($state) ? (int)($state['finalize_chunk_size'] ?? 0) : 0;
    if ($saved > 0) {
      return $this->normalize_finalize_chunk_size($saved);
    }
    return $this->normalize_finalize_chunk_size($this->get_default_finalize_chunk_size());
  }

  private function get_finalize_stage_chunk_size_from_state($state, $stage) {
    $state = is_array($state) ? $state : [];
    $stage = sanitize_key((string)$stage);
    if ($stage === 'summary_seed') {
      $saved = (int)($state['finalize_seed_chunk_size'] ?? 0);
      if ($saved > 0) {
        return $this->normalize_finalize_chunk_size($saved);
      }
      return $this->get_default_finalize_seed_chunk_size();
    }

    if ($stage === 'inbound_finalize') {
      $saved = (int)($state['finalize_inbound_chunk_size'] ?? 0);
      if ($saved > 0) {
        return $this->normalize_finalize_chunk_size($saved);
      }
      return $this->get_default_finalize_inbound_chunk_size();
    }

    return $this->get_finalize_chunk_size_from_state($state);
  }

  private function tune_finalize_chunk_size($currentSize, $stepMs) {
    $currentSize = $this->normalize_finalize_chunk_size($currentSize);
    $stepMs = max(0, (int)$stepMs);
    $budgetMs = $this->get_finalize_time_budget_seconds() * 1000;

    if ($stepMs >= (int)floor($budgetMs * 0.7)) {
      return $this->normalize_finalize_chunk_size((int)floor($currentSize / 2));
    }
    if ($stepMs > 0 && $stepMs <= (int)floor($budgetMs * 0.25)) {
      return $this->normalize_finalize_chunk_size($currentSize + 10);
    }

    return $currentSize;
  }

  private function rebuild_job_state_option_key() {
    return 'lm_rebuild_job_state_' . get_current_blog_id();
  }

  private function rebuild_job_partial_rows_key($scopePostType, $wpmlLang) {
    return 'lm_rebuild_partial_' . md5((string)$scopePostType . '|' . (string)$wpmlLang . '|' . get_current_blog_id());
  }

  private function rebuild_last_finalize_metrics_option_key() {
    return 'lm_rebuild_last_finalize_metrics_' . get_current_blog_id();
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

  private function get_last_finalize_metrics() {
    $metrics = get_option($this->rebuild_last_finalize_metrics_option_key(), []);
    return is_array($metrics) ? $metrics : [];
  }

  private function save_last_finalize_metrics($metrics) {
    if (!is_array($metrics)) {
      $metrics = [];
    }
    update_option($this->rebuild_last_finalize_metrics_option_key(), $metrics, false);
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
      'normalized_backfill_last_id' => max(0, (int)($state['normalized_backfill_last_id'] ?? 0)),
      'normalized_backfill_processed' => max(0, (int)($state['normalized_backfill_processed'] ?? 0)),
      'normalized_backfill_step_ms' => max(0, (int)($state['normalized_backfill_step_ms'] ?? 0)),
      'normalized_backfill_chunk_size' => max(0, (int)($state['normalized_backfill_chunk_size'] ?? 0)),
      'normalized_backfill_done' => !empty($state['normalized_backfill_done']),
      'finalize_stage' => sanitize_key((string)($state['finalize_stage'] ?? '')),
      'finalize_seed_last_post_id' => max(0, (int)($state['finalize_seed_last_post_id'] ?? 0)),
      'finalize_seed_processed_posts' => max(0, (int)($state['finalize_seed_processed_posts'] ?? 0)),
      'finalize_seed_step_ms' => max(0, (int)($state['finalize_seed_step_ms'] ?? 0)),
      'finalize_seed_chunk_size' => max(0, (int)($state['finalize_seed_chunk_size'] ?? 0)),
      'finalize_inbound_last_post_id' => max(0, (int)($state['finalize_inbound_last_post_id'] ?? 0)),
      'finalize_inbound_processed_posts' => max(0, (int)($state['finalize_inbound_processed_posts'] ?? 0)),
      'finalize_inbound_step_ms' => max(0, (int)($state['finalize_inbound_step_ms'] ?? 0)),
      'finalize_inbound_chunk_size' => max(0, (int)($state['finalize_inbound_chunk_size'] ?? 0)),
      'finalize_last_summary_query_ms' => max(0, (int)($state['finalize_last_summary_query_ms'] ?? 0)),
      'finalize_last_inbound_query_ms' => max(0, (int)($state['finalize_last_inbound_query_ms'] ?? 0)),
      'last_error' => sanitize_text_field((string)($state['last_error'] ?? '')),
      'message' => sanitize_text_field((string)($state['message'] ?? '')),
      'poll_ms' => max(0, (int)$this->get_rebuild_poll_ms($state)),
    ];
  }
}
