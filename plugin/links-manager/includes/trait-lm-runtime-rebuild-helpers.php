<?php
/**
 * Runtime limit, rebuild job state, and lock coordination helpers.
 */

trait LM_Runtime_Rebuild_Helpers_Trait {
  private function get_runtime_guard_policy($workload = 'editor_php_fallback') {
    $workload = sanitize_key((string)$workload);

    switch ($workload) {
      case 'cited_domains_aggregation':
        return [
          'memory_baselines' => [
            268435456 => 4000,
            402653184 => 6500,
            536870912 => 9000,
            805306368 => 12000,
            'default' => 15000,
          ],
          'time_caps' => [
            15 => 5000,
            30 => 8000,
            60 => 11000,
            'default' => 15000,
            'unlimited' => 15000,
          ],
          'min_threshold' => 3000,
          'max_threshold' => 15000,
        ];
      case 'all_anchor_text_aggregation':
        return [
          'memory_baselines' => [
            268435456 => 4000,
            402653184 => 6000,
            536870912 => 8500,
            805306368 => 11000,
            'default' => 14000,
          ],
          'time_caps' => [
            15 => 4500,
            30 => 7000,
            60 => 10000,
            'default' => 14000,
            'unlimited' => 14000,
          ],
          'min_threshold' => 3000,
          'max_threshold' => 14000,
        ];
      case 'editor_php_fallback':
      default:
        return [
          'memory_baselines' => [
            268435456 => 4000,
            402653184 => 7000,
            536870912 => 10000,
            805306368 => 14000,
            'default' => 18000,
          ],
          'time_caps' => [
            15 => 5000,
            30 => 9000,
            60 => 14000,
            'default' => 20000,
            'unlimited' => 20000,
          ],
          'min_threshold' => 3000,
          'max_threshold' => 20000,
        ];
    }
  }

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

  private function get_runtime_max_execution_time_seconds() {
    $maxExecution = (int)ini_get('max_execution_time');
    if ($maxExecution < 0) {
      $maxExecution = 0;
    }
    return $maxExecution;
  }

  private function get_runtime_guard_memory_baseline_rows($workload = 'editor_php_fallback') {
    $limit = $this->get_effective_memory_limit_bytes();
    $policy = $this->get_runtime_guard_policy($workload);
    $memoryBaselines = (array)($policy['memory_baselines'] ?? []);

    foreach ($memoryBaselines as $cap => $rows) {
      if ($cap === 'default') {
        continue;
      }
      if ($limit <= (int)$cap) {
        return (int)$rows;
      }
    }

    return (int)($memoryBaselines['default'] ?? 18000);
  }

  private function get_runtime_guard_time_cap_rows($workload = 'editor_php_fallback') {
    $maxExecution = $this->get_runtime_max_execution_time_seconds();
    $policy = $this->get_runtime_guard_policy($workload);
    $timeCaps = (array)($policy['time_caps'] ?? []);

    if ($maxExecution <= 0) {
      return (int)($timeCaps['unlimited'] ?? $timeCaps['default'] ?? 20000);
    }

    foreach ($timeCaps as $cap => $rows) {
      if ($cap === 'default' || $cap === 'unlimited') {
        continue;
      }
      if ($maxExecution <= (int)$cap) {
        return (int)$rows;
      }
    }

    return (int)($timeCaps['default'] ?? 20000);
  }

  private function get_runtime_guard_runtime_meta($workload = 'editor_php_fallback') {
    $memoryLimitBytes = $this->get_effective_memory_limit_bytes();
    $maxExecution = $this->get_runtime_max_execution_time_seconds();
    $policy = $this->get_runtime_guard_policy($workload);
    $memoryBaseline = $this->get_runtime_guard_memory_baseline_rows($workload);
    $timeCap = $this->get_runtime_guard_time_cap_rows($workload);

    $threshold = min($memoryBaseline, $timeCap, (int)($policy['max_threshold'] ?? 20000));
    if ($threshold < (int)($policy['min_threshold'] ?? 3000)) {
      $threshold = (int)($policy['min_threshold'] ?? 3000);
    }

    return [
      'workload' => sanitize_key((string)$workload),
      'threshold_rows' => (int)$threshold,
      'memory_limit_bytes' => (int)$memoryLimitBytes,
      'memory_limit_label' => function_exists('size_format') ? size_format((int)$memoryLimitBytes) : (string)$memoryLimitBytes,
      'memory_baseline_rows' => (int)$memoryBaseline,
      'time_cap_rows' => (int)$timeCap,
      'max_execution_time' => (int)$maxExecution,
      'min_threshold_rows' => (int)($policy['min_threshold'] ?? 3000),
      'max_threshold_rows' => (int)($policy['max_threshold'] ?? 20000),
    ];
  }

  private function get_editor_php_fallback_memory_baseline_rows() {
    return $this->get_runtime_guard_memory_baseline_rows('editor_php_fallback');
  }

  private function get_editor_php_fallback_time_cap_rows() {
    return $this->get_runtime_guard_time_cap_rows('editor_php_fallback');
  }

  private function get_editor_php_fallback_runtime_meta() {
    return $this->get_runtime_guard_runtime_meta('editor_php_fallback');
  }

  private function get_editor_php_fallback_row_limit() {
    $meta = $this->get_editor_php_fallback_runtime_meta();
    return max(3000, (int)($meta['threshold_rows'] ?? 3000));
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
    $maxExecution = $this->get_runtime_max_execution_time_seconds();
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
    if ($budget > 25) {
      $budget = 25;
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

    $memoryLimit = $this->get_effective_memory_limit_bytes();
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
    $maxExecution = $this->get_runtime_max_execution_time_seconds();
    $hasLongExecutionWindow = ($maxExecution <= 0 || $maxExecution >= 300);

    if ($hasLongExecutionWindow) {
      if ($runtimeBatch <= 40) {
        return 25;
      }
      if ($runtimeBatch <= 60) {
        return 30;
      }
      if ($runtimeBatch <= 100) {
        return 40;
      }
      return 50;
    }

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
    $base = $this->get_default_finalize_chunk_size();
    $seed = (int)floor($base * 2);
    if ($base >= 25) {
      $seed = max($seed, 60);
    }
    return $this->normalize_finalize_chunk_size(max(25, $seed));
  }

  private function get_default_finalize_inbound_chunk_size() {
    $base = $this->get_default_finalize_chunk_size();
    if ($base >= 25) {
      return $this->normalize_finalize_chunk_size(max($base, 30));
    }
    return $this->normalize_finalize_chunk_size($base);
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
      return $this->normalize_finalize_chunk_size($currentSize + 15);
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
    $wpmlLang = $this->normalize_rebuild_wpml_lang((string)($state['wpml_lang'] ?? 'all'));
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
      'requested_wpml_lang' => sanitize_key((string)($state['requested_wpml_lang'] ?? ($state['wpml_lang'] ?? 'all'))),
      'active_crawl_wpml_lang' => sanitize_key((string)($state['active_crawl_wpml_lang'] ?? '')),
      'crawl_lang_queue' => isset($state['crawl_lang_queue']) && is_array($state['crawl_lang_queue']) ? array_values(array_map('sanitize_key', (array)$state['crawl_lang_queue'])) : [],
      'completed_crawl_langs' => isset($state['completed_crawl_langs']) && is_array($state['completed_crawl_langs']) ? array_values(array_map('sanitize_key', (array)$state['completed_crawl_langs'])) : [],
      'aggregate_all_started' => !empty($state['aggregate_all_started']),
      'aggregate_all_done' => !empty($state['aggregate_all_done']),
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
      'finalize_target_only_rows_added' => max(0, (int)($state['finalize_target_only_rows_added'] ?? 0)),
      'last_error' => sanitize_text_field((string)($state['last_error'] ?? '')),
      'message' => sanitize_text_field((string)($state['message'] ?? '')),
      'poll_ms' => max(0, (int)$this->get_rebuild_poll_ms($state)),
    ];
  }
}
