<?php
/**
 * Runtime limit, rebuild job state, and lock coordination helpers.
 */

trait LM_Runtime_Rebuild_Helpers_Trait {
  private function format_refresh_eta_label($seconds) {
    $seconds = max(0, (int)$seconds);
    if ($seconds < 1) {
      return __('Ready soon', 'links-manager');
    }
    if ($seconds < 60) {
      return __('Less than 1 minute remaining', 'links-manager');
    }

    $minutes = (int)ceil($seconds / 60);
    if ($minutes < 60) {
      return sprintf(_n('About %d minute remaining', 'About %d minutes remaining', $minutes, 'links-manager'), $minutes);
    }

    $hours = (int)floor($minutes / 60);
    $remainingMinutes = $minutes % 60;
    if ($remainingMinutes < 1) {
      return sprintf(_n('About %d hour remaining', 'About %d hours remaining', $hours, 'links-manager'), $hours);
    }

    return sprintf(__('About %1$d hour %2$d minute remaining', 'links-manager'), $hours, $remainingMinutes);
  }

  private function get_refresh_stage_label($phaseKey) {
    $phaseKey = sanitize_key((string)$phaseKey);

    switch ($phaseKey) {
      case 'prepare':
        return __('Preparing refresh job', 'links-manager');
      case 'crawl_languages':
        return __('Scanning published content', 'links-manager');
      case 'finalize_language':
        return __('Finalizing language summaries', 'links-manager');
      case 'aggregate_all':
        return __('Aggregating all languages', 'links-manager');
      case 'prewarm':
        return __('Prewarming report caches', 'links-manager');
      case 'done':
        return __('Refresh complete', 'links-manager');
      default:
        return __('Preparing data', 'links-manager');
    }
  }

  private function get_refresh_finalize_stage_fraction($state) {
    $state = is_array($state) ? $state : [];
    $finalizeStage = sanitize_key((string)($state['finalize_stage'] ?? ''));
    $normalizedDone = !empty($state['normalized_backfill_done']);

    if (!$normalizedDone) {
      return 0.1;
    }

    switch ($finalizeStage) {
      case 'summary_seed':
        return 0.35;
      case 'inbound_finalize':
        return 0.6;
      case 'domain_summary_finalize':
        return 0.8;
      case 'anchor_summary_finalize':
        return 0.95;
      default:
        return 0.2;
    }
  }

  private function get_refresh_average_language_post_count($state, $languageTotal) {
    $state = is_array($state) ? $state : [];
    $languageTotal = max(1, (int)$languageTotal);
    $totalPosts = max(0, (int)($state['total_posts'] ?? 0));
    if ($totalPosts <= 0) {
      return 0;
    }

    return (int)max(1, ceil($totalPosts / $languageTotal));
  }

  private function get_refresh_stage_step_ms($state, $stage) {
    $state = is_array($state) ? $state : [];
    $stage = sanitize_key((string)$stage);

    switch ($stage) {
      case 'normalized_backfill':
        return max(0, (int)($state['normalized_backfill_step_ms'] ?? 0));
      case 'summary_seed':
        return max(0, (int)($state['finalize_seed_step_ms'] ?? 0));
      case 'inbound_finalize':
        return max(0, (int)($state['finalize_inbound_step_ms'] ?? 0));
      case 'domain_summary_finalize':
        return max(0, (int)($state['finalize_domain_step_ms'] ?? 0));
      case 'anchor_summary_finalize':
        return max(0, (int)($state['finalize_anchor_step_ms'] ?? 0));
      default:
        return max(0, (int)($state['finalize_step_ms'] ?? $state['step_ms'] ?? 0));
    }
  }

  private function get_refresh_stage_processed_posts($state, $stage) {
    $state = is_array($state) ? $state : [];
    $stage = sanitize_key((string)$stage);

    switch ($stage) {
      case 'normalized_backfill':
        return max(0, (int)($state['normalized_backfill_processed'] ?? 0));
      case 'summary_seed':
        return max(0, (int)($state['finalize_seed_processed_posts'] ?? 0));
      case 'inbound_finalize':
        return max(0, (int)($state['finalize_inbound_processed_posts'] ?? 0));
      case 'domain_summary_finalize':
        return max(0, (int)($state['finalize_domain_processed_posts'] ?? 0));
      case 'anchor_summary_finalize':
        return max(0, (int)($state['finalize_anchor_processed_posts'] ?? 0));
      default:
        return max(0, (int)($state['finalize_processed_posts'] ?? 0));
    }
  }

  private function get_refresh_stage_chunk_size($state, $stage) {
    $stage = sanitize_key((string)$stage);
    if ($stage === 'normalized_backfill') {
      $saved = is_array($state) ? (int)($state['normalized_backfill_chunk_size'] ?? 0) : 0;
      if ($saved > 0) {
        return max(1, $saved);
      }
      return max(1, $this->get_runtime_max_crawl_batch());
    }
    return max(1, $this->get_finalize_stage_chunk_size_from_state($state, $stage));
  }

  private function get_refresh_stage_fallback_step_ms($stage) {
    $stage = sanitize_key((string)$stage);
    $budgetMs = max(5000, $this->get_finalize_time_budget_seconds() * 1000);

    switch ($stage) {
      case 'normalized_backfill':
        return (int)max(800, floor($budgetMs * 0.12));
      case 'summary_seed':
        return (int)max(1200, floor($budgetMs * 0.22));
      case 'inbound_finalize':
        return (int)max(1500, floor($budgetMs * 0.28));
      case 'domain_summary_finalize':
        return (int)max(1800, floor($budgetMs * 0.34));
      case 'anchor_summary_finalize':
        return (int)max(2200, floor($budgetMs * 0.4));
      default:
        return (int)max(1200, floor($budgetMs * 0.25));
    }
  }

  private function estimate_refresh_stage_seconds($state, $stage, $estimatedPostsTotal) {
    $estimatedPostsTotal = max(0, (int)$estimatedPostsTotal);
    if ($estimatedPostsTotal <= 0) {
      return 0;
    }

    $processedPosts = $this->get_refresh_stage_processed_posts($state, $stage);
    $remainingPosts = max(0, $estimatedPostsTotal - $processedPosts);
    if ($remainingPosts <= 0) {
      return 0;
    }

    $chunkSize = max(1, $this->get_refresh_stage_chunk_size($state, $stage));
    $stepMs = $this->get_refresh_stage_step_ms($state, $stage);
    if ($stepMs <= 0) {
      $stepMs = $this->get_refresh_stage_fallback_step_ms($stage);
    }

    $remainingSteps = (int)ceil($remainingPosts / $chunkSize);
    return (int)max(1, ceil(($remainingSteps * $stepMs) / 1000));
  }

  private function estimate_refresh_crawl_seconds($state) {
    $state = is_array($state) ? $state : [];
    $totalPosts = max(0, (int)($state['total_posts'] ?? 0));
    $processedPosts = max(0, (int)($state['processed_posts'] ?? 0));
    $remainingPosts = max(0, $totalPosts - $processedPosts);
    if ($remainingPosts <= 0) {
      return 0;
    }

    $batchSize = max(1, (int)($state['batch_size'] ?? 0));
    if ($batchSize <= 0) {
      $batchSize = max(1, $this->get_runtime_max_crawl_batch());
    }

    $stepMs = max(0, (int)($state['step_ms'] ?? 0));
    if ($stepMs > 0 && $processedPosts > 0) {
      $msPerPost = $stepMs / max(1, min($batchSize, $processedPosts));
      return (int)max(1, ceil(($remainingPosts * max(10, $msPerPost)) / 1000));
    }

    $startedAt = (string)($state['started_at'] ?? '');
    $startedTs = $startedAt !== '' ? strtotime($startedAt) : false;
    if ($startedTs !== false && $startedTs > 0 && $processedPosts > 0) {
      $elapsed = max(1, time() - $startedTs);
      $secondsPerPost = $elapsed / max(1, $processedPosts);
      return (int)max(1, ceil($remainingPosts * max(0.05, $secondsPerPost)));
    }

    return (int)max(60, ceil($remainingPosts / max(1, $batchSize)) * 2);
  }

  private function estimate_refresh_finalize_seconds($state, $isGlobal, $activeLanguageTotal, $finalizeIndex, $activeFinalizeLang) {
    $state = is_array($state) ? $state : [];
    $activeLanguageTotal = max(1, (int)$activeLanguageTotal);
    $finalizeIndex = max(0, (int)$finalizeIndex);
    $activeFinalizeLang = sanitize_key((string)$activeFinalizeLang);
    $estimatedLanguagePosts = $this->get_refresh_average_language_post_count($state, $activeLanguageTotal);
    if ($estimatedLanguagePosts <= 0) {
      $estimatedLanguagePosts = max(1, (int)($state['processed_posts'] ?? 0));
    }

    $stageOrder = ['normalized_backfill', 'summary_seed', 'inbound_finalize', 'domain_summary_finalize', 'anchor_summary_finalize'];
    $currentStage = sanitize_key((string)($state['finalize_stage'] ?? ''));
    if ($currentStage === '') {
      $currentStage = 'normalized_backfill';
    }

    $secondsRemaining = 0;
    $currentStageFound = false;
    foreach ($stageOrder as $stage) {
      if (!$currentStageFound && $stage !== $currentStage) {
        continue;
      }
      $currentStageFound = true;
      $secondsRemaining += $this->estimate_refresh_stage_seconds($state, $stage, $estimatedLanguagePosts);
      if ($stage !== $currentStage) {
        continue;
      }
    }

    $remainingLanguagesAfterCurrent = max(0, $activeLanguageTotal - ($finalizeIndex + 1));
    if ($remainingLanguagesAfterCurrent > 0) {
      $fullLanguageSeconds = 0;
      foreach ($stageOrder as $stage) {
        $fullLanguageSeconds += $this->estimate_refresh_stage_seconds([], $stage, $estimatedLanguagePosts);
      }
      $secondsRemaining += ($remainingLanguagesAfterCurrent * $fullLanguageSeconds);
    }

    if ($isGlobal && $activeFinalizeLang !== 'all') {
      $aggregateEstimate = 0;
      $globalEstimatedPosts = max($estimatedLanguagePosts, max(0, (int)($state['total_posts'] ?? 0)));
      foreach (['summary_seed', 'domain_summary_finalize', 'anchor_summary_finalize'] as $aggregateStage) {
        $aggregateEstimate += $this->estimate_refresh_stage_seconds([], $aggregateStage, $globalEstimatedPosts);
      }
      $secondsRemaining += $aggregateEstimate;
    }

    return max(1, (int)$secondsRemaining);
  }

  private function estimate_refresh_aggregate_seconds($state) {
    $state = is_array($state) ? $state : [];
    $estimatedPosts = max(0, (int)($state['total_posts'] ?? 0));
    if ($estimatedPosts <= 0) {
      return 0;
    }

    $currentStage = sanitize_key((string)($state['finalize_stage'] ?? 'summary_seed'));
    $stageOrder = ['summary_seed', 'domain_summary_finalize', 'anchor_summary_finalize'];
    $secondsRemaining = 0;
    $currentStageFound = false;
    foreach ($stageOrder as $stage) {
      if (!$currentStageFound && $stage !== $currentStage) {
        continue;
      }
      $currentStageFound = true;
      $secondsRemaining += $this->estimate_refresh_stage_seconds($state, $stage, $estimatedPosts);
    }

    return max(1, (int)$secondsRemaining);
  }

  private function estimate_refresh_remaining_seconds($state, $status, $progressPercent, $isGlobal, $activeLanguageTotal, $finalizeIndex, $activeFinalizeLang) {
    $status = sanitize_key((string)$status);
    if (!in_array($status, ['running', 'finalizing'], true)) {
      return null;
    }

    if ($status === 'running') {
      $estimate = $this->estimate_refresh_crawl_seconds($state);
      return $estimate > 0 ? $estimate : null;
    }

    if ($isGlobal && sanitize_key((string)$activeFinalizeLang) === 'all') {
      $estimate = $this->estimate_refresh_aggregate_seconds($state);
      return $estimate > 0 ? $estimate : null;
    }

    $estimate = $this->estimate_refresh_finalize_seconds($state, $isGlobal, $activeLanguageTotal, $finalizeIndex, $activeFinalizeLang);
    if ($estimate > 0) {
      return $estimate;
    }

    if ($progressPercent >= 10) {
      $startedAt = (string)($state['started_at'] ?? '');
      $startedTs = $startedAt !== '' ? strtotime($startedAt) : false;
      if ($startedTs !== false && $startedTs > 0) {
        $elapsed = max(1, time() - $startedTs);
        return (int)max(1, round(($elapsed * (100 - $progressPercent)) / max(1, $progressPercent)));
      }
    }

    return null;
  }

  private function get_refresh_progress_meta($state) {
    $state = is_array($state) ? $state : [];
    $status = sanitize_key((string)($state['status'] ?? 'idle'));
    $requestedWpmlLang = $this->normalize_rebuild_wpml_lang((string)($state['requested_wpml_lang'] ?? ($state['wpml_lang'] ?? 'all')));
    $isGlobal = ($requestedWpmlLang === 'all');
    $totalStages = $isGlobal ? 5 : 4;
    $stageIndex = 1;
    $phaseKey = 'prepare';
    $progressPercent = 0;
    $activeLanguageCode = '';
    $activeLanguageIndex = 0;
    $activeLanguageTotal = 0;

    $crawlQueue = isset($state['crawl_lang_queue']) && is_array($state['crawl_lang_queue']) ? array_values(array_map('sanitize_key', (array)$state['crawl_lang_queue'])) : [];
    $crawlQueueForProgress = $isGlobal ? array_values(array_filter($crawlQueue, function($lang) {
      return $lang !== 'all';
    })) : $crawlQueue;
    $crawlQueueCount = count($crawlQueueForProgress);
    $crawlIndex = max(0, (int)($state['crawl_lang_index'] ?? 0));
    $activeCrawlLang = sanitize_key((string)($state['active_crawl_wpml_lang'] ?? ''));

    $finalizeQueue = isset($state['finalize_lang_queue']) && is_array($state['finalize_lang_queue']) ? array_values(array_map('sanitize_key', (array)$state['finalize_lang_queue'])) : [];
    $finalizeQueueForProgress = $isGlobal ? array_values(array_filter($finalizeQueue, function($lang) {
      return $lang !== 'all';
    })) : $finalizeQueue;
    $finalizeQueueCount = count($finalizeQueueForProgress);
    $finalizeIndex = max(0, (int)($state['finalize_lang_index'] ?? 0));
    $activeFinalizeLang = sanitize_key((string)($state['finalize_wpml_lang'] ?? ''));

    if ($status === 'running') {
      $stageIndex = min(2, $totalStages);
      $phaseKey = 'crawl_languages';
      $activeLanguageCode = $activeCrawlLang;
      $activeLanguageTotal = max(1, $crawlQueueCount);
      $activeLanguageIndex = $activeLanguageTotal > 0 ? min($activeLanguageTotal, max(1, $crawlIndex + 1)) : 1;
      $totalPosts = max(0, (int)($state['total_posts'] ?? 0));
      $processedPosts = max(0, (int)($state['processed_posts'] ?? 0));
      $crawlFraction = $totalPosts > 0 ? min(1, $processedPosts / $totalPosts) : 0;
      $progressPercent = (int)max(1, min(95, round(5 + ($crawlFraction * 45))));
    } elseif ($status === 'finalizing') {
      if ($isGlobal && $activeFinalizeLang === 'all') {
        $stageIndex = 4;
        $phaseKey = 'aggregate_all';
        $progressPercent = (int)max(85, min(99, round(85 + ($this->get_refresh_finalize_stage_fraction($state) * 10))));
      } else {
        $stageIndex = min(3, $totalStages);
        $phaseKey = 'finalize_language';
        $activeLanguageCode = $activeFinalizeLang;
        $activeLanguageTotal = max(1, $finalizeQueueCount);
        $activeLanguageIndex = $activeLanguageTotal > 0 ? min($activeLanguageTotal, max(1, $finalizeIndex + 1)) : 1;
        $languageProgressBase = $activeLanguageTotal > 0 ? ($finalizeIndex / $activeLanguageTotal) : 0;
        $languageProgressCurrent = $activeLanguageTotal > 0 ? ($this->get_refresh_finalize_stage_fraction($state) / $activeLanguageTotal) : 0;
        $progressPercent = (int)max(50, min(94, round(50 + (($languageProgressBase + $languageProgressCurrent) * 35))));
      }
    } elseif ($status === 'done') {
      $stageIndex = $totalStages;
      $phaseKey = !empty($state['prewarm_pending']) ? 'prewarm' : 'done';
      $progressPercent = 100;
    } elseif ($status === 'partial' || $status === 'error') {
      $stageIndex = max(1, min($totalStages, (int)($state['progress_stage_index'] ?? 1)));
      $phaseKey = sanitize_key((string)($state['progress_phase_key'] ?? 'prepare'));
      $progressPercent = max(0, min(100, (int)($state['progress_percent'] ?? 0)));
    }

    $languagesMap = $this->get_wpml_languages_map();
    $activeLanguageLabel = '';
    if ($activeLanguageCode !== '' && $activeLanguageCode !== 'all') {
      $activeLanguageLabel = isset($languagesMap[$activeLanguageCode]) ? (string)$languagesMap[$activeLanguageCode] : strtoupper($activeLanguageCode);
    } elseif ($activeLanguageCode === 'all') {
      $activeLanguageLabel = __('All languages', 'links-manager');
    }

    $estimatedSecondsRemaining = $this->estimate_refresh_remaining_seconds(
      $state,
      $status,
      $progressPercent,
      $isGlobal,
      $activeLanguageTotal,
      $finalizeIndex,
      $activeFinalizeLang
    );
    $estimatedReadyAt = '';
    if ($estimatedSecondsRemaining !== null && $estimatedSecondsRemaining > 0) {
      $estimatedReadyAt = wp_date('Y-m-d H:i:s', time() + $estimatedSecondsRemaining);
    }

    $stageLabel = $this->get_refresh_stage_label($phaseKey);
    if ($phaseKey === 'finalize_language' && $activeLanguageLabel !== '') {
      $stageLabel = sprintf(__('Finalizing %s summaries', 'links-manager'), $activeLanguageLabel);
    } elseif ($phaseKey === 'crawl_languages' && $activeLanguageLabel !== '') {
      $stageLabel = sprintf(__('Scanning %s content', 'links-manager'), $activeLanguageLabel);
    }

    $estimatedLabel = __('Estimating time remaining...', 'links-manager');
    if ($status === 'done') {
      $estimatedLabel = __('Ready now', 'links-manager');
    } elseif ($estimatedSecondsRemaining !== null) {
      $estimatedLabel = $this->format_refresh_eta_label($estimatedSecondsRemaining);
    }

    return [
      'progress_percent' => $progressPercent,
      'current_stage_index' => $stageIndex,
      'total_stages' => $totalStages,
      'current_stage_label' => $stageLabel,
      'current_phase_key' => $phaseKey,
      'estimated_seconds_remaining' => $estimatedSecondsRemaining,
      'estimated_ready_at' => $estimatedReadyAt,
      'estimated_label' => $estimatedLabel,
      'active_language_index' => $activeLanguageIndex,
      'active_language_total' => $activeLanguageTotal,
      'active_language_code' => $activeLanguageCode,
      'active_language_label' => $activeLanguageLabel,
    ];
  }

  private function get_refresh_readiness_state($scopePostType = 'any', $wpmlLang = 'all') {
    $scopePostType = sanitize_key((string)$scopePostType);
    if ($scopePostType === '') {
      $scopePostType = 'any';
    }
    $wpmlLang = $this->get_requested_view_wpml_lang((string)$wpmlLang);
    if ($wpmlLang === '') {
      $wpmlLang = 'all';
    }

    $state = $this->get_rebuild_job_state();
    $status = sanitize_key((string)($state['status'] ?? 'idle'));
    $globalReady = $this->has_nonempty_refresh_dataset_for_scope('any', 'all', false) || $this->has_refresh_dataset_for_scope('any', 'all', false);
    $scopeReady = $this->has_nonempty_refresh_dataset_for_scope($scopePostType, $wpmlLang, false) || $this->has_refresh_dataset_for_scope($scopePostType, $wpmlLang, false);

    if (in_array($status, ['running', 'finalizing'], true)) {
      if (!$globalReady) {
        return 'initializing';
      }
      if (!$scopeReady && $wpmlLang !== 'all') {
        return 'current_language_pending';
      }
      return 'stale_but_available';
    }

    if (!$globalReady) {
      return 'empty';
    }
    if (!$scopeReady && $wpmlLang !== 'all') {
      return 'current_language_pending';
    }

    return 'ready';
  }

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

    $progressMeta = $this->get_refresh_progress_meta($state);

    return [
      'status' => sanitize_key((string)($state['status'] ?? 'idle')),
      'refresh_mode' => sanitize_key((string)($state['refresh_mode'] ?? 'full_rebuild')),
      'scope_post_type' => sanitize_key((string)($state['scope_post_type'] ?? 'any')),
      'wpml_lang' => sanitize_key((string)($state['wpml_lang'] ?? 'all')),
      'requested_wpml_lang' => sanitize_key((string)($state['requested_wpml_lang'] ?? ($state['wpml_lang'] ?? 'all'))),
      'active_crawl_wpml_lang' => sanitize_key((string)($state['active_crawl_wpml_lang'] ?? '')),
      'crawl_lang_queue' => isset($state['crawl_lang_queue']) && is_array($state['crawl_lang_queue']) ? array_values(array_map('sanitize_key', (array)$state['crawl_lang_queue'])) : [],
      'crawl_lang_index' => max(0, (int)($state['crawl_lang_index'] ?? 0)),
      'completed_crawl_langs' => isset($state['completed_crawl_langs']) && is_array($state['completed_crawl_langs']) ? array_values(array_map('sanitize_key', (array)$state['completed_crawl_langs'])) : [],
      'aggregate_all_started' => !empty($state['aggregate_all_started']),
      'aggregate_all_done' => !empty($state['aggregate_all_done']),
      'execution_mode' => sanitize_key((string)($state['execution_mode'] ?? 'foreground')),
      'worker_scheduled' => !empty($state['worker_scheduled']),
      'last_worker_started_at' => (string)($state['last_worker_started_at'] ?? ''),
      'last_worker_completed_at' => (string)($state['last_worker_completed_at'] ?? ''),
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
      'finalize_lang_index' => max(0, (int)($state['finalize_lang_index'] ?? 0)),
      'active_finalize_wpml_lang' => sanitize_key((string)($state['finalize_wpml_lang'] ?? '')),
      'last_error' => sanitize_text_field((string)($state['last_error'] ?? '')),
      'message' => sanitize_text_field((string)($state['message'] ?? '')),
      'poll_ms' => max(0, (int)$this->get_rebuild_poll_ms($state)),
      'progress_percent' => max(0, min(100, (int)($progressMeta['progress_percent'] ?? 0))),
      'current_stage_index' => max(1, (int)($progressMeta['current_stage_index'] ?? 1)),
      'total_stages' => max(1, (int)($progressMeta['total_stages'] ?? 1)),
      'current_stage_label' => sanitize_text_field((string)($progressMeta['current_stage_label'] ?? '')),
      'current_phase_key' => sanitize_key((string)($progressMeta['current_phase_key'] ?? '')),
      'estimated_seconds_remaining' => isset($progressMeta['estimated_seconds_remaining']) ? max(0, (int)$progressMeta['estimated_seconds_remaining']) : null,
      'estimated_ready_at' => sanitize_text_field((string)($progressMeta['estimated_ready_at'] ?? '')),
      'estimated_label' => sanitize_text_field((string)($progressMeta['estimated_label'] ?? '')),
      'active_language_index' => max(0, (int)($progressMeta['active_language_index'] ?? 0)),
      'active_language_total' => max(0, (int)($progressMeta['active_language_total'] ?? 0)),
      'active_language_code' => sanitize_key((string)($progressMeta['active_language_code'] ?? '')),
      'active_language_label' => sanitize_text_field((string)($progressMeta['active_language_label'] ?? '')),
    ];
  }
}
