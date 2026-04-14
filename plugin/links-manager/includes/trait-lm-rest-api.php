<?php
/**
 * REST API routes and handlers.
 */

if (!defined('ABSPATH')) {
  exit;
}

trait LM_REST_API_Trait {
  public function rest_can_manage_links_manager($request = null) {
    return $this->current_user_can_access_plugin();
  }

  public function register_rest_routes() {
    register_rest_route('links-manager/v1', '/rebuild/start', [
      'methods' => 'POST',
      'callback' => [$this, 'rest_rebuild_start'],
      'permission_callback' => [$this, 'rest_can_manage_links_manager'],
      'args' => [
        'post_type' => [
          'required' => false,
          'type' => 'string',
          'sanitize_callback' => 'sanitize_key',
        ],
        'wpml_lang' => [
          'required' => false,
          'type' => 'string',
          'sanitize_callback' => 'sanitize_key',
        ],
      ],
    ]);

    register_rest_route('links-manager/v1', '/rebuild/status', [
      'methods' => 'GET',
      'callback' => [$this, 'rest_rebuild_status'],
      'permission_callback' => [$this, 'rest_can_manage_links_manager'],
    ]);

    register_rest_route('links-manager/v1', '/rebuild/step', [
      'methods' => 'POST',
      'callback' => [$this, 'rest_rebuild_step'],
      'permission_callback' => [$this, 'rest_can_manage_links_manager'],
      'args' => [
        'batch' => [
          'required' => false,
          'type' => 'integer',
          'sanitize_callback' => 'absint',
          'validate_callback' => function($value) {
            return is_numeric($value);
          },
        ],
      ],
    ]);

    register_rest_route('links-manager/v1', '/pages-link/list', [
      'methods' => 'GET',
      'callback' => [$this, 'rest_pages_link_list'],
      'permission_callback' => [$this, 'rest_can_manage_links_manager'],
      'args' => [
        'cursor' => [
          'required' => false,
          'type' => 'string',
          'sanitize_callback' => 'sanitize_text_field',
        ],
        'paged' => [
          'required' => false,
          'type' => 'integer',
          'sanitize_callback' => 'absint',
        ],
        'per_page' => [
          'required' => false,
          'type' => 'integer',
          'sanitize_callback' => 'absint',
        ],
      ],
    ]);

    register_rest_route('links-manager/v1', '/editor/list', [
      'methods' => 'GET',
      'callback' => [$this, 'rest_editor_list'],
      'permission_callback' => [$this, 'rest_can_manage_links_manager'],
      'args' => [
        'cursor' => [
          'required' => false,
          'type' => 'string',
          'sanitize_callback' => 'sanitize_text_field',
        ],
        'paged' => [
          'required' => false,
          'type' => 'integer',
          'sanitize_callback' => 'absint',
        ],
        'per_page' => [
          'required' => false,
          'type' => 'integer',
          'sanitize_callback' => 'absint',
        ],
      ],
    ]);

  }

  public function rest_rebuild_start($request) {
    $scopePostType = sanitize_key((string)$request->get_param('post_type'));
    if ($scopePostType === '') $scopePostType = 'any';

    $wpmlLang = sanitize_key((string)$request->get_param('wpml_lang'));
    if ($wpmlLang === '') $wpmlLang = 'all';
    $wpmlLang = $this->normalize_rebuild_wpml_lang($wpmlLang);

    $enabledPostTypes = $this->get_enabled_scan_post_types();
    $postTypes = ($scopePostType === 'any')
      ? $enabledPostTypes
      : (in_array($scopePostType, $enabledPostTypes, true) ? [$scopePostType] : []);

    $currentState = $this->recover_stale_rebuild_job($this->get_rebuild_job_state(), 1800);
    if (!empty($currentState) && in_array((string)($currentState['status'] ?? ''), ['running', 'finalizing'], true)) {
      $runningScope = sanitize_key((string)($currentState['scope_post_type'] ?? 'any'));
      $runningLang = $this->normalize_rebuild_wpml_lang((string)($currentState['wpml_lang'] ?? 'all'));
      if ($runningScope === $scopePostType && $runningLang === $wpmlLang) {
        $this->ensure_active_rebuild_step_worker();
        $currentState['message'] = ((string)($currentState['status'] ?? '') === 'finalizing')
          ? 'Rebuild job is still finalizing. Continuing existing job.'
          : 'Rebuild job is already running. Continuing existing job.';
        return rest_ensure_response($this->get_public_rebuild_job_state($currentState));
      }
      $currentState['message'] = ((string)($currentState['status'] ?? '') === 'finalizing')
        ? 'Another rebuild job is still finalizing. Please wait until it finishes.'
        : 'Another rebuild job is currently running. Please wait until it finishes.';
      return rest_ensure_response($this->get_public_rebuild_job_state($currentState));
    }

    $crawlLangQueue = $this->get_rebuild_crawl_lang_queue($wpmlLang);
    $job = [
      'status' => 'running',
      'scope_post_type' => $scopePostType,
      'wpml_lang' => $wpmlLang,
      'requested_wpml_lang' => $wpmlLang,
      'post_types' => $postTypes,
      'storage_mode' => ($scopePostType === 'any' && $this->is_indexed_datastore_ready()) ? 'indexed_stream' : 'transient_cache',
      'crawl_lang_queue' => $crawlLangQueue,
      'crawl_lang_index' => 0,
      'active_crawl_wpml_lang' => (string)($crawlLangQueue[0] ?? $wpmlLang),
      'completed_crawl_langs' => [],
      'crawl_lang_offsets' => [],
      'crawl_lang_last_seen_ids' => [],
      'crawl_lang_processed_posts' => [],
      'aggregate_all_started' => ($wpmlLang === 'all') ? '0' : '1',
      'aggregate_all_done' => ($wpmlLang === 'all') ? '0' : '1',
      'scan_modified_after_gmt' => '',
      'last_seen_id' => 0,
      'offset' => 0,
      'total_posts' => 0,
      'processed_posts' => 0,
      'rows_count' => 0,
      'started_at' => current_time('mysql'),
      'updated_at' => current_time('mysql'),
      'execution_mode' => 'background',
      'worker_scheduled' => '0',
      'last_worker_started_at' => '',
      'last_worker_completed_at' => '',
      'last_error' => '',
    ];

    if (empty($postTypes)) {
      $rows = $this->crawl_menus($this->get_enabled_scan_source_types());
      $this->persist_cache_payload($scopePostType, $wpmlLang, $rows);
      $this->schedule_rest_list_prewarm($scopePostType, $wpmlLang, 2);
      $job['status'] = 'done';
      $job['rows_count'] = count((array)$rows);
      $this->save_rebuild_job_state($job);
      return rest_ensure_response($this->get_public_rebuild_job_state($job));
    }

    $job['scan_modified_after_gmt'] = $this->get_scan_modified_after_gmt('');
    $totalPosts = 0;
    foreach ($crawlLangQueue as $crawlLang) {
      $totalPosts += (int)$this->count_cache_post_ids($postTypes, (string)$crawlLang, $job['scan_modified_after_gmt']);
    }
    $job['total_posts'] = max(0, (int)$totalPosts);
    if ((int)$job['total_posts'] < 1) {
      $rows = $this->crawl_menus($this->get_enabled_scan_source_types());
      $this->persist_cache_payload($scopePostType, $wpmlLang, $rows);
      $this->schedule_rest_list_prewarm($scopePostType, $wpmlLang, 2);
      $job['status'] = 'done';
      $job['rows_count'] = count((array)$rows);
      $this->save_rebuild_job_state($job);
      return rest_ensure_response($this->get_public_rebuild_job_state($job));
    }

    if ((string)$job['storage_mode'] === 'indexed_stream') {
      $this->reset_indexed_datastore_for_refresh_scope($wpmlLang);
      $this->clear_main_cache_payload($scopePostType, $wpmlLang);
    } else {
      set_transient($this->rebuild_job_partial_rows_key($scopePostType, $wpmlLang), [], self::CACHE_TTL);
    }
    $this->save_rebuild_job_state($job);
    $this->schedule_rebuild_step_worker(1);
    $job['worker_scheduled'] = '1';
    $job['updated_at'] = current_time('mysql');
    $this->save_rebuild_job_state($job);

    return rest_ensure_response($this->get_public_rebuild_job_state($job));
  }

  public function rest_rebuild_status($request) {
    $state = $this->get_rebuild_job_state();
    return rest_ensure_response($this->get_public_rebuild_job_state($state));
  }

  public function rest_rebuild_step($request) {
    $state = $this->recover_stale_rebuild_job($this->get_rebuild_job_state(), 1800);
    $currentStatus = sanitize_key((string)($state['status'] ?? 'idle'));
    if (empty($state) || !in_array($currentStatus, ['running', 'finalizing'], true)) {
      return rest_ensure_response([
        'status' => 'idle',
        'message' => 'No running rebuild job.',
      ]);
    }

    $lockAcquired = $this->acquire_rebuild_job_lock(30);
    if (!$lockAcquired) {
      $state['message'] = 'A rebuild step is already running.';
      return rest_ensure_response($this->get_public_rebuild_job_state($state));
    }

    $scopePostType = sanitize_key((string)($state['scope_post_type'] ?? 'any'));
    $wpmlLang = $this->normalize_rebuild_wpml_lang((string)($state['wpml_lang'] ?? 'all'));
    $storageMode = sanitize_key((string)($state['storage_mode'] ?? 'transient_cache'));
    $scanModifiedAfterGmt = (string)($state['scan_modified_after_gmt'] ?? '');
    $postTypes = array_values(array_unique(array_map('sanitize_key', (array)($state['post_types'] ?? []))));
    $lastSeenId = max(0, (int)($state['last_seen_id'] ?? 0));
    $offset = max(0, (int)($state['offset'] ?? 0));
    $totalPosts = max(0, (int)($state['total_posts'] ?? 0));
    $batch = (int)$request->get_param('batch');
    if ($batch < 1) {
      $batch = $this->get_crawl_post_batch_size();
    }
    if ($batch > $this->get_runtime_max_crawl_batch()) {
      $batch = $this->get_runtime_max_crawl_batch();
    }

    $partialKey = '';
    $allRows = [];
    if ($storageMode !== 'indexed_stream') {
      $partialKey = $this->rebuild_job_partial_rows_key($scopePostType, $wpmlLang);
      $allRows = get_transient($partialKey);
      if (!is_array($allRows)) {
        $allRows = [];
      }
    }

    $enabledSources = $this->get_enabled_scan_source_types();
    $maxPosts = $this->get_max_posts_per_rebuild();
    $processedPosts = (int)($state['processed_posts'] ?? 0);
    $crawlStartedAt = microtime(true);

    if ($currentStatus === 'finalizing') {
      try {
        if ($storageMode === 'indexed_stream') {
          $finalizeLangQueue = $this->get_indexed_finalize_lang_queue_from_state($state, $wpmlLang);
          $activeFinalizeLang = $this->get_active_indexed_finalize_lang($state, $wpmlLang);
          $allFinalizeStrategy = ($activeFinalizeLang === 'all')
            ? $this->get_indexed_all_finalize_strategy($state, $wpmlLang)
            : '';
          if ($activeFinalizeLang === 'all') {
            $state['finalize_all_strategy'] = ($allFinalizeStrategy !== '') ? $allFinalizeStrategy : 'direct_from_all_facts';
          } else {
            unset($state['finalize_all_strategy']);
          }
          if ($activeFinalizeLang === 'all' && $allFinalizeStrategy === 'aggregate_from_language_summaries') {
            $finalizeStage = sanitize_key((string)($state['finalize_stage'] ?? 'summary_seed'));
            if ($finalizeStage === '' || $finalizeStage === 'inbound_finalize') {
              $finalizeStage = 'summary_seed';
            }

            if ($finalizeStage === 'summary_seed') {
              $finalizeLastPostId = max(0, (int)($state['finalize_seed_last_post_id'] ?? 0));
              $finalizeChunkSize = $this->get_finalize_stage_chunk_size_from_state($state, 'summary_seed');
              $result = $this->build_indexed_post_summary_all_chunk($finalizeLastPostId, $finalizeChunkSize);
              $finalizeStepMs = max(0, (int)($result['step_ms'] ?? 0));
              $state['finalize_stage'] = 'summary_seed';
              $state['finalize_seed_last_post_id'] = max(0, (int)($result['last_post_id'] ?? $finalizeLastPostId));
              $state['finalize_seed_processed_posts'] = max(0, (int)($state['finalize_seed_processed_posts'] ?? 0)) + max(0, (int)($result['processed_posts'] ?? 0));
              $state['finalize_seed_chunk_size'] = $this->tune_finalize_chunk_size($finalizeChunkSize, $finalizeStepMs);
              $state['finalize_seed_step_ms'] = $finalizeStepMs;
              $state['finalize_summary_rows'] = max(0, (int)($result['summary_rows'] ?? 0));
              $state['finalize_last_summary_query_ms'] = $finalizeStepMs;
              $state['finalize_step_ms'] = $finalizeStepMs;
              $state['finalize_processed_posts'] = max(0, (int)($state['finalize_seed_processed_posts'] ?? 0));
              $state['finalize_chunk_size'] = max(0, (int)($state['finalize_seed_chunk_size'] ?? 0));
              $shouldPauseFinalizing = (empty($result['done']) && $this->should_abort_finalize($crawlStartedAt));

              if (empty($result['done'])) {
                $state['status'] = 'finalizing';
                $state['message'] = $shouldPauseFinalizing
                  ? 'Aggregating global post summaries in smaller steps to avoid server timeouts...'
                  : 'Aggregating global post summaries...';
              } else {
                $state['finalize_stage'] = 'domain_summary_finalize';
                $state['message'] = 'Aggregating global cited domains summary...';
              }
            } elseif ($finalizeStage === 'domain_summary_finalize') {
              $finalizeLastPostId = max(0, (int)($state['finalize_domain_last_post_id'] ?? 0));
              $finalizeChunkSize = $this->get_finalize_stage_chunk_size_from_state($state, 'summary_seed');
              $result = $this->build_indexed_domain_summary_all_chunk($finalizeLastPostId, $finalizeChunkSize);
              $finalizeStepMs = max(0, (int)($result['step_ms'] ?? 0));
              $state['finalize_stage'] = 'domain_summary_finalize';
              $state['finalize_domain_last_post_id'] = max(0, (int)($result['last_post_id'] ?? $finalizeLastPostId));
              $state['finalize_domain_processed_posts'] = max(0, (int)($state['finalize_domain_processed_posts'] ?? 0)) + max(0, (int)($result['processed_posts'] ?? 0));
              $state['finalize_domain_chunk_size'] = $this->tune_finalize_chunk_size($finalizeChunkSize, $finalizeStepMs);
              $state['finalize_domain_step_ms'] = $finalizeStepMs;
              $state['finalize_domain_rows'] = max(0, (int)($result['summary_rows'] ?? 0));
              $state['finalize_step_ms'] = $finalizeStepMs;
              $shouldPauseFinalizing = (empty($result['done']) && $this->should_abort_finalize($crawlStartedAt));

              if (empty($result['done'])) {
                $state['status'] = 'finalizing';
                $state['message'] = $shouldPauseFinalizing
                  ? 'Aggregating global cited domains summary in smaller steps to avoid server timeouts...'
                  : 'Aggregating global cited domains summary...';
              } else {
                $state['finalize_stage'] = 'anchor_summary_finalize';
                $state['message'] = 'Aggregating global anchor text summary...';
              }
            } else {
              $finalizeLastPostId = max(0, (int)($state['finalize_anchor_last_post_id'] ?? 0));
              $finalizeChunkSize = $this->get_finalize_stage_chunk_size_from_state($state, 'summary_seed');
              $result = $this->build_indexed_anchor_summary_all_chunk($finalizeLastPostId, $finalizeChunkSize);
              $finalizeStepMs = max(0, (int)($result['step_ms'] ?? 0));
              $state['finalize_stage'] = 'anchor_summary_finalize';
              $state['finalize_anchor_last_post_id'] = max(0, (int)($result['last_post_id'] ?? $finalizeLastPostId));
              $state['finalize_anchor_processed_posts'] = max(0, (int)($state['finalize_anchor_processed_posts'] ?? 0)) + max(0, (int)($result['processed_posts'] ?? 0));
              $state['finalize_anchor_chunk_size'] = $this->tune_finalize_chunk_size($finalizeChunkSize, $finalizeStepMs);
              $state['finalize_anchor_step_ms'] = $finalizeStepMs;
              $state['finalize_anchor_rows'] = max(0, (int)($result['summary_rows'] ?? 0));
              $state['finalize_step_ms'] = $finalizeStepMs;
              $shouldPauseFinalizing = (empty($result['done']) && $this->should_abort_finalize($crawlStartedAt));

              if (empty($result['done'])) {
                $state['status'] = 'finalizing';
                $state['message'] = $shouldPauseFinalizing
                  ? 'Aggregating global anchor text summary in smaller steps to avoid server timeouts...'
                  : 'Aggregating global anchor text summary...';
              } else {
                $completedFinalizeLang = $activeFinalizeLang;
                if ($this->advance_indexed_finalize_lang($state, $finalizeLangQueue, $wpmlLang)) {
                  $nextFinalizeLang = (string)($state['finalize_wpml_lang'] ?? $wpmlLang);
                  if ($nextFinalizeLang === 'all') {
                    $state['aggregate_all_started'] = '1';
                  }
                  $state['status'] = 'finalizing';
                  $state['message'] = sprintf('Summary refresh for %s completed. Continuing with %s...', $completedFinalizeLang, $nextFinalizeLang);
                } else {
                  $this->clear_main_cache_payload($scopePostType, $wpmlLang);
                  update_option($this->cache_scan_option_key($scopePostType, $wpmlLang), gmdate('Y-m-d H:i:s'), false);
                  $this->bump_dataset_cache_version();
                  $this->save_last_finalize_metrics([
                    'captured_at' => current_time('mysql'),
                    'scope_post_type' => (string)$scopePostType,
                    'wpml_lang' => 'all',
                    'status' => 'done',
                    'finalize_stage' => 'done',
                    'normalized_backfill_done' => true,
                    'normalized_backfill_processed' => 0,
                    'normalized_backfill_step_ms' => 0,
                    'normalized_backfill_chunk_size' => 0,
                    'finalize_processed_posts' => max(0, (int)($state['finalize_processed_posts'] ?? 0)),
                    'finalize_chunk_size' => max(0, (int)($state['finalize_chunk_size'] ?? 0)),
                    'finalize_step_ms' => max(0, (int)($state['finalize_step_ms'] ?? 0)),
                    'finalize_seed_processed_posts' => max(0, (int)($state['finalize_seed_processed_posts'] ?? 0)),
                    'finalize_seed_chunk_size' => max(0, (int)($state['finalize_seed_chunk_size'] ?? 0)),
                    'finalize_seed_step_ms' => max(0, (int)($state['finalize_seed_step_ms'] ?? 0)),
                    'finalize_inbound_processed_posts' => 0,
                    'finalize_inbound_chunk_size' => 0,
                    'finalize_inbound_step_ms' => 0,
                    'finalize_last_summary_query_ms' => max(0, (int)($state['finalize_last_summary_query_ms'] ?? 0)),
                    'finalize_last_inbound_query_ms' => 0,
                    'finalize_summary_rows' => max(0, (int)($state['finalize_summary_rows'] ?? 0)),
                    'finalize_inbound_rows' => 0,
                    'finalize_target_only_rows_added' => 0,
                    'finalize_domain_rows' => max(0, (int)($state['finalize_domain_rows'] ?? 0)),
                    'finalize_anchor_rows' => max(0, (int)($state['finalize_anchor_rows'] ?? 0)),
                  ]);
                  $state['aggregate_all_done'] = '1';
                  $state['status'] = 'done';
                  $state['message'] = '';
                  unset($state['finalize_lang_queue'], $state['finalize_lang_index'], $state['finalize_wpml_lang']);
                  unset($state['finalize_stage'], $state['finalize_last_post_id'], $state['finalize_processed_posts'], $state['finalize_chunk_size'], $state['finalize_step_ms'], $state['finalize_summary_rows'], $state['finalize_inbound_rows'], $state['finalize_target_only_rows_added'], $state['finalize_seed_last_post_id'], $state['finalize_seed_processed_posts'], $state['finalize_seed_step_ms'], $state['finalize_seed_chunk_size'], $state['finalize_inbound_last_post_id'], $state['finalize_inbound_processed_posts'], $state['finalize_inbound_step_ms'], $state['finalize_inbound_chunk_size'], $state['finalize_last_summary_query_ms'], $state['finalize_last_inbound_query_ms'], $state['normalized_backfill_last_id'], $state['normalized_backfill_processed'], $state['normalized_backfill_step_ms'], $state['normalized_backfill_chunk_size'], $state['normalized_backfill_done'], $state['finalize_domain_last_post_id'], $state['finalize_domain_processed_posts'], $state['finalize_domain_chunk_size'], $state['finalize_domain_step_ms'], $state['finalize_domain_rows'], $state['finalize_anchor_last_post_id'], $state['finalize_anchor_processed_posts'], $state['finalize_anchor_chunk_size'], $state['finalize_anchor_step_ms'], $state['finalize_anchor_rows']);
                }
              }
            }
          } else {
            $backfillDone = !empty($state['normalized_backfill_done']);
            if (!$backfillDone) {
            $backfillLastId = max(0, (int)($state['normalized_backfill_last_id'] ?? 0));
            $backfillChunkSize = $this->normalize_finalize_chunk_size((int)($state['normalized_backfill_chunk_size'] ?? $this->get_default_finalize_chunk_size()));
            $backfillResult = $this->backfill_normalized_url_chunk_for_lang($activeFinalizeLang, $backfillLastId, $backfillChunkSize);
            $backfillStepMs = max(0, (int)($backfillResult['step_ms'] ?? 0));
            $state['normalized_backfill_last_id'] = max(0, (int)($backfillResult['last_fact_id'] ?? $backfillLastId));
            $state['normalized_backfill_processed'] = max(0, (int)($state['normalized_backfill_processed'] ?? 0)) + max(0, (int)($backfillResult['processed_rows'] ?? 0));
            $state['normalized_backfill_step_ms'] = $backfillStepMs;
            $state['normalized_backfill_chunk_size'] = $this->tune_finalize_chunk_size($backfillChunkSize, $backfillStepMs);
            $state['normalized_backfill_done'] = !empty($backfillResult['done']);
            $state['status'] = 'finalizing';
            $state['message'] = !empty($state['normalized_backfill_done'])
              ? 'Preparing normalized link data completed. Continuing summary refresh...'
              : 'Preparing normalized link data...';
            if (!empty($state['normalized_backfill_done']) && empty($state['finalize_stage'])) {
              $state['finalize_stage'] = 'summary_seed';
            }
            } else {
            $finalizeStage = sanitize_key((string)($state['finalize_stage'] ?? 'summary_seed'));
            if ($finalizeStage === '') {
              $finalizeStage = 'summary_seed';
            }

            if ($finalizeStage === 'summary_seed') {
              $finalizeLastPostId = max(0, (int)($state['finalize_seed_last_post_id'] ?? 0));
              $finalizeChunkSize = $this->get_finalize_stage_chunk_size_from_state($state, 'summary_seed');
              $result = $this->build_indexed_summary_seed_chunk_for_lang($activeFinalizeLang, $finalizeLastPostId, $finalizeChunkSize);
              $finalizeStepMs = max(0, (int)($result['step_ms'] ?? 0));
              $state['finalize_stage'] = 'summary_seed';
              $state['finalize_seed_last_post_id'] = max(0, (int)($result['last_post_id'] ?? $finalizeLastPostId));
              $state['finalize_seed_processed_posts'] = max(0, (int)($state['finalize_seed_processed_posts'] ?? 0)) + max(0, (int)($result['processed_posts'] ?? 0));
              $state['finalize_seed_chunk_size'] = $this->tune_finalize_chunk_size($finalizeChunkSize, $finalizeStepMs);
              $state['finalize_seed_step_ms'] = $finalizeStepMs;
              $state['finalize_summary_rows'] = max(0, (int)($result['summary_rows'] ?? 0));
              $state['finalize_last_summary_query_ms'] = max(0, (int)($result['summary_query_ms'] ?? 0));
              $state['finalize_step_ms'] = $finalizeStepMs;
              $state['finalize_processed_posts'] = max(0, (int)($state['finalize_seed_processed_posts'] ?? 0));
              $state['finalize_chunk_size'] = max(0, (int)($state['finalize_seed_chunk_size'] ?? 0));
              $shouldPauseFinalizing = (empty($result['done']) && $this->should_abort_finalize($crawlStartedAt));

              $this->save_last_finalize_metrics([
                'captured_at' => current_time('mysql'),
                'scope_post_type' => (string)$scopePostType,
                'wpml_lang' => (string)$activeFinalizeLang,
                'status' => 'finalizing',
                'finalize_stage' => 'summary_seed',
                'normalized_backfill_done' => !empty($state['normalized_backfill_done']),
                'normalized_backfill_processed' => max(0, (int)($state['normalized_backfill_processed'] ?? 0)),
                'normalized_backfill_step_ms' => max(0, (int)($state['normalized_backfill_step_ms'] ?? 0)),
                'normalized_backfill_chunk_size' => max(0, (int)($state['normalized_backfill_chunk_size'] ?? 0)),
                'finalize_processed_posts' => max(0, (int)($state['finalize_processed_posts'] ?? 0)),
                'finalize_chunk_size' => max(0, (int)($state['finalize_chunk_size'] ?? 0)),
                'finalize_step_ms' => max(0, (int)($state['finalize_step_ms'] ?? 0)),
                'finalize_seed_processed_posts' => max(0, (int)($state['finalize_seed_processed_posts'] ?? 0)),
                'finalize_seed_chunk_size' => max(0, (int)($state['finalize_seed_chunk_size'] ?? 0)),
                'finalize_seed_step_ms' => max(0, (int)($state['finalize_seed_step_ms'] ?? 0)),
                'finalize_inbound_processed_posts' => max(0, (int)($state['finalize_inbound_processed_posts'] ?? 0)),
                'finalize_inbound_chunk_size' => max(0, (int)($state['finalize_inbound_chunk_size'] ?? 0)),
                'finalize_inbound_step_ms' => max(0, (int)($state['finalize_inbound_step_ms'] ?? 0)),
                'finalize_last_summary_query_ms' => max(0, (int)($state['finalize_last_summary_query_ms'] ?? 0)),
                'finalize_last_inbound_query_ms' => max(0, (int)($state['finalize_last_inbound_query_ms'] ?? 0)),
                'finalize_summary_rows' => max(0, (int)($state['finalize_summary_rows'] ?? 0)),
                'finalize_inbound_rows' => max(0, (int)($state['finalize_inbound_rows'] ?? 0)),
                'finalize_target_only_rows_added' => max(0, (int)($state['finalize_target_only_rows_added'] ?? 0)),
              ]);

              if (empty($result['done'])) {
                $state['status'] = 'finalizing';
                $state['message'] = $shouldPauseFinalizing
                  ? 'Refreshing cached summaries in smaller steps to avoid server timeouts...'
                  : 'Refreshing cached summaries from scanned rows...';
              } else {
                $state['finalize_stage'] = 'inbound_finalize';
                $state['message'] = 'Computing inbound summary from prepared rows...';
              }
            } elseif ($finalizeStage === 'inbound_finalize') {
              $finalizeLastPostId = max(0, (int)($state['finalize_inbound_last_post_id'] ?? 0));
              $finalizeChunkSize = $this->get_finalize_stage_chunk_size_from_state($state, 'inbound_finalize');
              $result = $this->finalize_indexed_summary_inbound_chunk_for_lang($activeFinalizeLang, $finalizeLastPostId, $finalizeChunkSize);
              $finalizeStepMs = max(0, (int)($result['step_ms'] ?? 0));
              $state['finalize_stage'] = 'inbound_finalize';
              $state['finalize_inbound_last_post_id'] = max(0, (int)($result['last_post_id'] ?? $finalizeLastPostId));
              $state['finalize_inbound_processed_posts'] = max(0, (int)($state['finalize_inbound_processed_posts'] ?? 0)) + max(0, (int)($result['processed_posts'] ?? 0));
              $state['finalize_inbound_chunk_size'] = $this->tune_finalize_chunk_size($finalizeChunkSize, $finalizeStepMs);
              $state['finalize_inbound_step_ms'] = $finalizeStepMs;
              $state['finalize_inbound_rows'] = max(0, (int)($result['inbound_rows'] ?? 0));
              $state['finalize_target_only_rows_added'] = max(0, (int)($state['finalize_target_only_rows_added'] ?? 0)) + max(0, (int)($result['target_only_summary_rows'] ?? 0));
              $state['finalize_last_inbound_query_ms'] = max(0, (int)($result['inbound_query_ms'] ?? 0));
              $state['finalize_step_ms'] = $finalizeStepMs;
              $state['finalize_processed_posts'] = max(0, (int)($state['finalize_inbound_processed_posts'] ?? 0));
              $state['finalize_chunk_size'] = max(0, (int)($state['finalize_inbound_chunk_size'] ?? 0));
              $shouldPauseFinalizing = (empty($result['done']) && $this->should_abort_finalize($crawlStartedAt));

              $this->save_last_finalize_metrics([
                'captured_at' => current_time('mysql'),
                'scope_post_type' => (string)$scopePostType,
                'wpml_lang' => (string)$activeFinalizeLang,
                'status' => 'finalizing',
                'finalize_stage' => 'inbound_finalize',
                'normalized_backfill_done' => !empty($state['normalized_backfill_done']),
                'normalized_backfill_processed' => max(0, (int)($state['normalized_backfill_processed'] ?? 0)),
                'normalized_backfill_step_ms' => max(0, (int)($state['normalized_backfill_step_ms'] ?? 0)),
                'normalized_backfill_chunk_size' => max(0, (int)($state['normalized_backfill_chunk_size'] ?? 0)),
                'finalize_processed_posts' => max(0, (int)($state['finalize_processed_posts'] ?? 0)),
                'finalize_chunk_size' => max(0, (int)($state['finalize_chunk_size'] ?? 0)),
                'finalize_step_ms' => max(0, (int)($state['finalize_step_ms'] ?? 0)),
                'finalize_seed_processed_posts' => max(0, (int)($state['finalize_seed_processed_posts'] ?? 0)),
                'finalize_seed_chunk_size' => max(0, (int)($state['finalize_seed_chunk_size'] ?? 0)),
                'finalize_seed_step_ms' => max(0, (int)($state['finalize_seed_step_ms'] ?? 0)),
                'finalize_inbound_processed_posts' => max(0, (int)($state['finalize_inbound_processed_posts'] ?? 0)),
                'finalize_inbound_chunk_size' => max(0, (int)($state['finalize_inbound_chunk_size'] ?? 0)),
                'finalize_inbound_step_ms' => max(0, (int)($state['finalize_inbound_step_ms'] ?? 0)),
                'finalize_last_summary_query_ms' => max(0, (int)($state['finalize_last_summary_query_ms'] ?? 0)),
                'finalize_last_inbound_query_ms' => max(0, (int)($state['finalize_last_inbound_query_ms'] ?? 0)),
                'finalize_summary_rows' => max(0, (int)($state['finalize_summary_rows'] ?? 0)),
                'finalize_inbound_rows' => max(0, (int)($state['finalize_inbound_rows'] ?? 0)),
                'finalize_target_only_rows_added' => max(0, (int)($state['finalize_target_only_rows_added'] ?? 0)),
              ]);

              if (empty($result['done'])) {
                $state['status'] = 'finalizing';
                $state['message'] = $shouldPauseFinalizing
                  ? 'Computing inbound summary in smaller steps to avoid server timeouts...'
                  : 'Computing inbound summary from prepared rows...';
              } else {
                $state['finalize_stage'] = 'domain_summary_finalize';
                $state['message'] = 'Building cited domains summary index...';
              }
            } elseif ($finalizeStage === 'domain_summary_finalize') {
              $finalizeLastPostId = max(0, (int)($state['finalize_domain_last_post_id'] ?? 0));
              $finalizeChunkSize = $this->get_finalize_stage_chunk_size_from_state($state, 'summary_seed');
              $result = $this->build_indexed_domain_summary_chunk_for_lang($activeFinalizeLang, $finalizeLastPostId, $finalizeChunkSize);
              $finalizeStepMs = max(0, (int)($result['step_ms'] ?? 0));
              $state['finalize_stage'] = 'domain_summary_finalize';
              $state['finalize_domain_last_post_id'] = max(0, (int)($result['last_post_id'] ?? $finalizeLastPostId));
              $state['finalize_domain_processed_posts'] = max(0, (int)($state['finalize_domain_processed_posts'] ?? 0)) + max(0, (int)($result['processed_posts'] ?? 0));
              $state['finalize_domain_chunk_size'] = $this->tune_finalize_chunk_size($finalizeChunkSize, $finalizeStepMs);
              $state['finalize_domain_step_ms'] = $finalizeStepMs;
              $state['finalize_domain_rows'] = max(0, (int)($result['summary_rows'] ?? 0));
              $state['finalize_step_ms'] = $finalizeStepMs;
              $shouldPauseFinalizing = (empty($result['done']) && $this->should_abort_finalize($crawlStartedAt));

              if (empty($result['done'])) {
                $state['status'] = 'finalizing';
                $state['message'] = $shouldPauseFinalizing
                  ? 'Building cited domains summary index in smaller steps to avoid server timeouts...'
                  : 'Building cited domains summary index...';
              } else {
                $state['finalize_stage'] = 'anchor_summary_finalize';
                $state['message'] = 'Building anchor text summary index...';
              }
            } else {
              $finalizeLastPostId = max(0, (int)($state['finalize_anchor_last_post_id'] ?? 0));
              $finalizeChunkSize = $this->get_finalize_stage_chunk_size_from_state($state, 'summary_seed');
              $result = $this->build_indexed_anchor_summary_chunk_for_lang($activeFinalizeLang, $finalizeLastPostId, $finalizeChunkSize);
              $finalizeStepMs = max(0, (int)($result['step_ms'] ?? 0));
              $state['finalize_stage'] = 'anchor_summary_finalize';
              $state['finalize_anchor_last_post_id'] = max(0, (int)($result['last_post_id'] ?? $finalizeLastPostId));
              $state['finalize_anchor_processed_posts'] = max(0, (int)($state['finalize_anchor_processed_posts'] ?? 0)) + max(0, (int)($result['processed_posts'] ?? 0));
              $state['finalize_anchor_chunk_size'] = $this->tune_finalize_chunk_size($finalizeChunkSize, $finalizeStepMs);
              $state['finalize_anchor_step_ms'] = $finalizeStepMs;
              $state['finalize_anchor_rows'] = max(0, (int)($result['summary_rows'] ?? 0));
              $state['finalize_step_ms'] = $finalizeStepMs;
              $shouldPauseFinalizing = (empty($result['done']) && $this->should_abort_finalize($crawlStartedAt));

              if (empty($result['done'])) {
                $state['status'] = 'finalizing';
                $state['message'] = $shouldPauseFinalizing
                  ? 'Building anchor text summary index in smaller steps to avoid server timeouts...'
                  : 'Building anchor text summary index...';
              } else {
                $completedFinalizeLang = $activeFinalizeLang;
                $this->clear_indexed_anchor_summary_backup_for_lang($completedFinalizeLang);
                if ($this->advance_indexed_finalize_lang($state, $finalizeLangQueue, $wpmlLang)) {
                  $nextFinalizeLang = (string)($state['finalize_wpml_lang'] ?? $wpmlLang);
                  if ($nextFinalizeLang === 'all') {
                    $state['aggregate_all_started'] = '1';
                    $this->clear_indexed_summary_for_lang('all');
                  }
                  $state['status'] = 'finalizing';
                  $state['message'] = sprintf('Summary refresh for %s completed. Continuing with %s...', $completedFinalizeLang, $nextFinalizeLang);
                } else {
                  $this->clear_main_cache_payload($scopePostType, $wpmlLang);
                  update_option($this->cache_scan_option_key($scopePostType, $wpmlLang), gmdate('Y-m-d H:i:s'), false);
                  $this->bump_dataset_cache_version();
                  $this->save_last_finalize_metrics([
                    'captured_at' => current_time('mysql'),
                    'scope_post_type' => (string)$scopePostType,
                    'wpml_lang' => (string)$completedFinalizeLang,
                    'status' => 'done',
                    'finalize_stage' => 'done',
                    'normalized_backfill_done' => true,
                    'normalized_backfill_processed' => max(0, (int)($state['normalized_backfill_processed'] ?? 0)),
                    'normalized_backfill_step_ms' => max(0, (int)($state['normalized_backfill_step_ms'] ?? 0)),
                    'normalized_backfill_chunk_size' => max(0, (int)($state['normalized_backfill_chunk_size'] ?? 0)),
                    'finalize_processed_posts' => max(0, (int)($state['finalize_processed_posts'] ?? 0)),
                    'finalize_chunk_size' => max(0, (int)($state['finalize_chunk_size'] ?? 0)),
                    'finalize_step_ms' => max(0, (int)($state['finalize_step_ms'] ?? 0)),
                    'finalize_seed_processed_posts' => max(0, (int)($state['finalize_seed_processed_posts'] ?? 0)),
                    'finalize_seed_chunk_size' => max(0, (int)($state['finalize_seed_chunk_size'] ?? 0)),
                    'finalize_seed_step_ms' => max(0, (int)($state['finalize_seed_step_ms'] ?? 0)),
                    'finalize_inbound_processed_posts' => max(0, (int)($state['finalize_inbound_processed_posts'] ?? 0)),
                    'finalize_inbound_chunk_size' => max(0, (int)($state['finalize_inbound_chunk_size'] ?? 0)),
                    'finalize_inbound_step_ms' => max(0, (int)($state['finalize_inbound_step_ms'] ?? 0)),
                    'finalize_last_summary_query_ms' => max(0, (int)($state['finalize_last_summary_query_ms'] ?? 0)),
                    'finalize_last_inbound_query_ms' => max(0, (int)($state['finalize_last_inbound_query_ms'] ?? 0)),
                    'finalize_summary_rows' => max(0, (int)($state['finalize_summary_rows'] ?? 0)),
                    'finalize_inbound_rows' => max(0, (int)($state['finalize_inbound_rows'] ?? 0)),
                    'finalize_target_only_rows_added' => max(0, (int)($state['finalize_target_only_rows_added'] ?? 0)),
                    'finalize_domain_rows' => max(0, (int)($state['finalize_domain_rows'] ?? 0)),
                    'finalize_anchor_rows' => max(0, (int)($state['finalize_anchor_rows'] ?? 0)),
                  ]);
                  $state['aggregate_all_done'] = ($requestedWpmlLang === 'all') ? '1' : '1';
                  $state['status'] = 'done';
                  $state['message'] = '';
                  unset($state['finalize_lang_queue'], $state['finalize_lang_index'], $state['finalize_wpml_lang']);
                  unset($state['finalize_stage'], $state['finalize_last_post_id'], $state['finalize_processed_posts'], $state['finalize_chunk_size'], $state['finalize_step_ms'], $state['finalize_summary_rows'], $state['finalize_inbound_rows'], $state['finalize_target_only_rows_added'], $state['finalize_seed_last_post_id'], $state['finalize_seed_processed_posts'], $state['finalize_seed_step_ms'], $state['finalize_seed_chunk_size'], $state['finalize_inbound_last_post_id'], $state['finalize_inbound_processed_posts'], $state['finalize_inbound_step_ms'], $state['finalize_inbound_chunk_size'], $state['finalize_last_summary_query_ms'], $state['finalize_last_inbound_query_ms'], $state['normalized_backfill_last_id'], $state['normalized_backfill_processed'], $state['normalized_backfill_step_ms'], $state['normalized_backfill_chunk_size'], $state['normalized_backfill_done'], $state['finalize_domain_last_post_id'], $state['finalize_domain_processed_posts'], $state['finalize_domain_chunk_size'], $state['finalize_domain_step_ms'], $state['finalize_domain_rows'], $state['finalize_anchor_last_post_id'], $state['finalize_anchor_processed_posts'], $state['finalize_anchor_chunk_size'], $state['finalize_anchor_step_ms'], $state['finalize_anchor_rows']);
                }
              }
            }
          }
          }
        } else {
          $this->append_rows($allRows, $this->crawl_menus($enabledSources));
          $this->persist_cache_payload($scopePostType, $wpmlLang, $allRows);
          $state['status'] = 'done';
          $state['message'] = '';
        }
        if ((string)($state['status'] ?? '') === 'done') {
          $this->schedule_rest_list_prewarm($scopePostType, $wpmlLang, 2);
          if ($this->is_wpml_active()) {
            update_option('lm_last_wpml_lang_context', (string)$wpmlLang, false);
          }
          if ($partialKey !== '') {
            delete_transient($partialKey);
          }
        }
      } catch (Throwable $e) {
        $state['status'] = 'error';
        $state['last_error'] = sanitize_text_field($e->getMessage());
        if ($storageMode === 'indexed_stream') {
          $this->restore_indexed_datastore_from_backup($scopePostType, $wpmlLang);
        }
        $state['worker_scheduled'] = '0';
        $state['last_worker_completed_at'] = current_time('mysql');
      } finally {
        if ($lockAcquired) {
          $this->release_rebuild_job_lock();
        }
      }
      $state['updated_at'] = current_time('mysql');
      $state['batch_size'] = $batch;
      $state['step_ms'] = max(0, (int)round((microtime(true) - $crawlStartedAt) * 1000));
      $state['execution_mode'] = 'background';
      $state['last_worker_completed_at'] = current_time('mysql');
      if (in_array((string)($state['status'] ?? ''), ['running', 'finalizing'], true)) {
        $this->schedule_rebuild_step_worker(1);
        $state['worker_scheduled'] = '1';
      } else {
        $state['worker_scheduled'] = '0';
      }
      $this->save_rebuild_job_state($state);
      return rest_ensure_response($this->get_public_rebuild_job_state($state));
    }

    $requestedWpmlLang = $this->normalize_rebuild_wpml_lang((string)($state['requested_wpml_lang'] ?? $wpmlLang));
    $activeCrawlLang = $this->get_active_rebuild_crawl_lang($state, $requestedWpmlLang);
    $activeLangOffsets = isset($state['crawl_lang_offsets']) && is_array($state['crawl_lang_offsets']) ? $state['crawl_lang_offsets'] : [];
    $activeLangLastSeenIds = isset($state['crawl_lang_last_seen_ids']) && is_array($state['crawl_lang_last_seen_ids']) ? $state['crawl_lang_last_seen_ids'] : [];
    $activeLangProcessedMap = isset($state['crawl_lang_processed_posts']) && is_array($state['crawl_lang_processed_posts']) ? $state['crawl_lang_processed_posts'] : [];
    $activeLangOffset = max(0, (int)($activeLangOffsets[$activeCrawlLang] ?? 0));
    $activeLangLastSeenId = max(0, (int)($activeLangLastSeenIds[$activeCrawlLang] ?? 0));
    $activeLangProcessed = max(0, (int)($activeLangProcessedMap[$activeCrawlLang] ?? 0));

    $wpmlWasSwitched = false;
    $wpmlPreviousLang = '';
    try {
      if ($this->is_wpml_active()) {
        $prev = $this->safe_wpml_apply_filters('wpml_current_language', null);
        if (is_string($prev)) {
          $wpmlPreviousLang = sanitize_key($prev);
        }
        $switchLang = ($activeCrawlLang === 'all') ? null : $activeCrawlLang;
        $wpmlWasSwitched = $this->safe_wpml_switch_language($switchLang);
      }

      $batchPostIds = $this->query_cache_post_ids_chunk($postTypes, $activeCrawlLang, $scanModifiedAfterGmt, $activeLangLastSeenId, $batch);
      $end = count($batchPostIds);
      $nextOffset = $activeLangOffset;
      $nextLastSeenId = $activeLangLastSeenId;
      $batchRows = [];

      for ($i = 0; $i < $end; $i++) {
        $nextOffset = $activeLangOffset + $i + 1;

        if ($maxPosts > 0 && $processedPosts >= $maxPosts) {
          break;
        }

        $postId = isset($batchPostIds[$i]) ? (int)$batchPostIds[$i] : 0;
        if ($postId > $nextLastSeenId) {
          $nextLastSeenId = $postId;
        }
        if ($postId < 1) continue;

        $postRows = $this->crawl_post_for_cache_language($postId, $activeCrawlLang, $enabledSources);
        if ($storageMode === 'indexed_stream') {
          $this->append_rows($batchRows, $postRows);
        } else {
          $this->append_rows($allRows, $postRows);
        }
        $processedPosts++;
        $activeLangProcessed++;

        if ($this->should_abort_crawl($crawlStartedAt)) {
          break;
        }
      }

      if ($storageMode === 'indexed_stream' && !empty($batchRows)) {
        $insertTargetLang = ($requestedWpmlLang === 'all') ? 'all' : $activeCrawlLang;
        $insertedRows = (int)$this->append_indexed_datastore_rows($batchRows, $insertTargetLang);
        $state['rows_count'] = max(0, (int)($state['rows_count'] ?? 0)) + $insertedRows;
      }
    } catch (Throwable $e) {
      $state['status'] = 'error';
      $state['last_error'] = sanitize_text_field($e->getMessage());
      if ($storageMode === 'indexed_stream') {
        $this->restore_indexed_datastore_from_backup($scopePostType, $wpmlLang);
      }
      $state['worker_scheduled'] = '0';
      $state['last_worker_completed_at'] = current_time('mysql');
      $state['updated_at'] = current_time('mysql');
      $this->save_rebuild_job_state($state);
      return rest_ensure_response($this->get_public_rebuild_job_state($state));
    } finally {
      if ($wpmlWasSwitched) {
        if ($wpmlPreviousLang !== '') {
          $this->safe_wpml_switch_language($wpmlPreviousLang);
        } else {
          $this->safe_wpml_switch_language(null);
        }
      }
      if ($lockAcquired) {
        $this->release_rebuild_job_lock();
      }
    }

    $newOffset = max(0, (int)(isset($nextOffset) ? $nextOffset : $activeLangOffset));
    $newLastSeenId = max(0, (int)(isset($nextLastSeenId) ? $nextLastSeenId : $activeLangLastSeenId));
    $lastBatchCount = isset($batchPostIds) && is_array($batchPostIds) ? count($batchPostIds) : 0;
    $hitMaxPosts = ($maxPosts > 0 && $processedPosts >= $maxPosts);
    $exhaustedPosts = ($lastBatchCount === 0) || ($lastBatchCount < $batch);
    $activeLangOffsets[$activeCrawlLang] = $newOffset;
    $activeLangLastSeenIds[$activeCrawlLang] = $newLastSeenId;
    $activeLangProcessedMap[$activeCrawlLang] = $activeLangProcessed;
    $state['crawl_lang_offsets'] = $activeLangOffsets;
    $state['crawl_lang_last_seen_ids'] = $activeLangLastSeenIds;
    $state['crawl_lang_processed_posts'] = $activeLangProcessedMap;
    $done = $hitMaxPosts;

    if ($exhaustedPosts) {
      $completedLangs = isset($state['completed_crawl_langs']) && is_array($state['completed_crawl_langs']) ? $state['completed_crawl_langs'] : [];
      if (!in_array($activeCrawlLang, $completedLangs, true)) {
        $completedLangs[] = $activeCrawlLang;
      }
      $state['completed_crawl_langs'] = array_values(array_unique(array_filter(array_map('strval', $completedLangs))));

      if (!$hitMaxPosts && $this->advance_rebuild_crawl_lang($state, $requestedWpmlLang)) {
        $state['status'] = 'running';
        $state['message'] = sprintf('Completed crawl for %s. Continuing with %s...', $activeCrawlLang, (string)($state['active_crawl_wpml_lang'] ?? $requestedWpmlLang));
      } else {
        $done = true;
      }
    }

    if ($done) {
      if ($storageMode === 'indexed_stream') {
        $menuRows = $this->crawl_menus($enabledSources);
        if (!empty($menuRows)) {
          $menuInsertLang = ($requestedWpmlLang === 'all') ? 'all' : $requestedWpmlLang;
          $state['rows_count'] = max(0, (int)($state['rows_count'] ?? 0)) + (int)$this->append_indexed_datastore_rows($menuRows, $menuInsertLang);
        }
        if (!$hitMaxPosts) {
          $state['aggregate_all_started'] = ($requestedWpmlLang === 'all') ? '1' : '0';
          $state['aggregate_all_done'] = ($requestedWpmlLang === 'all') ? '0' : '1';
          $state['status'] = 'finalizing';
          $state['message'] = 'Preparing normalized link data...';
          $state['normalized_backfill_last_id'] = 0;
          $state['normalized_backfill_processed'] = 0;
          $state['normalized_backfill_step_ms'] = 0;
          $state['normalized_backfill_chunk_size'] = $this->get_default_finalize_chunk_size();
          $state['normalized_backfill_done'] = false;
          $state['finalize_stage'] = 'summary_seed';
          $state['finalize_last_post_id'] = 0;
          $state['finalize_processed_posts'] = 0;
          $state['finalize_chunk_size'] = $this->get_default_finalize_chunk_size();
          $state['finalize_step_ms'] = 0;
          $state['finalize_summary_rows'] = 0;
          $state['finalize_inbound_rows'] = 0;
          $state['finalize_seed_last_post_id'] = 0;
          $state['finalize_seed_processed_posts'] = 0;
          $state['finalize_seed_step_ms'] = 0;
          $state['finalize_seed_chunk_size'] = $this->get_default_finalize_seed_chunk_size();
          $state['finalize_inbound_last_post_id'] = 0;
          $state['finalize_inbound_processed_posts'] = 0;
          $state['finalize_inbound_step_ms'] = 0;
          $state['finalize_inbound_chunk_size'] = $this->get_default_finalize_inbound_chunk_size();
          $state['finalize_last_summary_query_ms'] = 0;
          $state['finalize_last_inbound_query_ms'] = 0;
          $state['finalize_target_only_rows_added'] = 0;
        }
      } else {
        $this->append_rows($allRows, $this->crawl_menus($enabledSources));
        $state['status'] = 'finalizing';
        $state['message'] = 'Finalizing cached rows...';
        unset($state['finalize_stage'], $state['finalize_last_post_id'], $state['finalize_processed_posts'], $state['finalize_chunk_size'], $state['finalize_step_ms'], $state['finalize_summary_rows'], $state['finalize_inbound_rows'], $state['finalize_target_only_rows_added'], $state['finalize_seed_last_post_id'], $state['finalize_seed_processed_posts'], $state['finalize_seed_step_ms'], $state['finalize_seed_chunk_size'], $state['finalize_inbound_last_post_id'], $state['finalize_inbound_processed_posts'], $state['finalize_inbound_step_ms'], $state['finalize_inbound_chunk_size'], $state['finalize_last_summary_query_ms'], $state['finalize_last_inbound_query_ms'], $state['normalized_backfill_last_id'], $state['normalized_backfill_processed'], $state['normalized_backfill_step_ms'], $state['normalized_backfill_chunk_size'], $state['normalized_backfill_done']);
      }
      if ($hitMaxPosts) {
        if ($storageMode === 'indexed_stream') {
          $this->restore_indexed_datastore_from_backup($scopePostType, $wpmlLang);
        }
        $state['status'] = 'partial';
        $state['message'] = sprintf(
          'Refresh stopped at the configured post limit (%1$s posts) before all posts were scanned.',
          number_format_i18n($maxPosts)
        );
        unset($state['finalize_stage'], $state['finalize_last_post_id'], $state['finalize_processed_posts'], $state['finalize_chunk_size'], $state['finalize_step_ms'], $state['finalize_summary_rows'], $state['finalize_inbound_rows'], $state['finalize_target_only_rows_added'], $state['finalize_seed_last_post_id'], $state['finalize_seed_processed_posts'], $state['finalize_seed_step_ms'], $state['finalize_seed_chunk_size'], $state['finalize_inbound_last_post_id'], $state['finalize_inbound_processed_posts'], $state['finalize_inbound_step_ms'], $state['finalize_inbound_chunk_size'], $state['finalize_last_summary_query_ms'], $state['finalize_last_inbound_query_ms'], $state['normalized_backfill_last_id'], $state['normalized_backfill_processed'], $state['normalized_backfill_step_ms'], $state['normalized_backfill_chunk_size'], $state['normalized_backfill_done']);
      }
    } else {
      if ($partialKey !== '') {
        set_transient($partialKey, $allRows, self::CACHE_TTL);
      }
      if (!isset($state['status']) || $state['status'] !== 'running') {
        $state['status'] = 'running';
      }
    }

    $state['offset'] = max(0, (int)$processedPosts);
    $state['last_seen_id'] = $newLastSeenId;
    $state['processed_posts'] = $processedPosts;
    if ($storageMode !== 'indexed_stream') {
      $state['rows_count'] = count((array)$allRows);
    }
    $state['updated_at'] = current_time('mysql');
    $state['batch_size'] = $batch;
    $state['step_ms'] = max(0, (int)round((microtime(true) - $crawlStartedAt) * 1000));
    $state['execution_mode'] = 'background';
    $state['last_worker_completed_at'] = current_time('mysql');
    if (in_array((string)$state['status'], ['running', 'finalizing'], true) && !isset($state['message'])) {
      $state['message'] = '';
    }
    if (in_array((string)($state['status'] ?? ''), ['running', 'finalizing'], true)) {
      $this->schedule_rebuild_step_worker(1);
      $state['worker_scheduled'] = '1';
    } else {
      $state['worker_scheduled'] = '0';
    }
    $this->save_rebuild_job_state($state);

    return rest_ensure_response($this->get_public_rebuild_job_state($state));
  }

  private function build_request_overrides_from_map($request, $map) {
    $overrides = [];
    foreach ((array)$map as $sourceKey => $targetKey) {
      if ($request->has_param((string)$sourceKey)) {
        $overrides[(string)$targetKey] = $request->get_param((string)$sourceKey);
      }
      if ($request->has_param((string)$targetKey)) {
        $overrides[(string)$targetKey] = $request->get_param((string)$targetKey);
      }
    }
    return $overrides;
  }

  private function normalize_cache_signature_value($value) {
    if (is_array($value)) {
      $isAssoc = array_keys($value) !== range(0, count($value) - 1);
      if ($isAssoc) {
        ksort($value);
      }
      $normalized = [];
      foreach ($value as $k => $v) {
        $normalized[$k] = $this->normalize_cache_signature_value($v);
      }
      return $normalized;
    }

    if (is_object($value)) {
      return $this->normalize_cache_signature_value((array)$value);
    }

    if (is_bool($value)) {
      return $value ? 1 : 0;
    }

    if (is_scalar($value) || $value === null) {
      return $value;
    }

    return (string)$value;
  }

  private function build_rest_response_cache_key($namespace, $payload) {
    $normalized = $this->normalize_cache_signature_value($payload);
    $hash = md5((string)wp_json_encode($normalized));
    $prefix = 'lm_rest_' . sanitize_key((string)$namespace) . '_';
    return $prefix . $hash;
  }

  private function get_rest_response_cache_ttl() {
    $settings = $this->get_settings();
    $ttlSec = isset($settings['rest_response_cache_ttl_sec']) ? (int)$settings['rest_response_cache_ttl_sec'] : 90;
    if ($ttlSec < 30) $ttlSec = 30;
    if ($ttlSec > 600) $ttlSec = 600;
    return $ttlSec;
  }

  private function get_rest_response_cache($cacheKey) {
    $cacheKey = (string)$cacheKey;
    if ($cacheKey === '') {
      return null;
    }

    if (isset($this->rest_response_runtime_cache[$cacheKey])) {
      $this->rest_response_cache_hits++;
      return $this->rest_response_runtime_cache[$cacheKey];
    }

    $cached = get_transient($cacheKey);
    if (is_array($cached)) {
      $this->rest_response_runtime_cache[$cacheKey] = $cached;
      $this->rest_response_cache_hits++;
      return $cached;
    }

    $this->rest_response_cache_misses++;

    return null;
  }

  private function set_rest_response_cache($cacheKey, $payload) {
    $cacheKey = (string)$cacheKey;
    if ($cacheKey === '' || !is_array($payload)) {
      return;
    }

    $this->rest_response_runtime_cache[$cacheKey] = $payload;
    set_transient($cacheKey, $payload, $this->get_rest_response_cache_ttl());
  }

  private function get_rest_response_cache_stats() {
    return [
      'hits' => max(0, (int)$this->rest_response_cache_hits),
      'misses' => max(0, (int)$this->rest_response_cache_misses),
    ];
  }

  private function build_rest_endpoint_context($namespace, $filters) {
    $scopePostType = sanitize_key((string)($filters['post_type'] ?? 'any'));
    if ($scopePostType === '') {
      $scopePostType = 'any';
    }

    $scopeWpmlLang = $this->get_requested_view_wpml_lang((string)($filters['wpml_lang'] ?? 'all'));
    $cacheStamp = (string)$this->get_dataset_cache_version();

    return [
      'namespace' => sanitize_key((string)$namespace),
      'scope_post_type' => $scopePostType,
      'scope_wpml_lang' => $scopeWpmlLang,
      'cache_stamp' => $cacheStamp,
      'cache_key' => $this->build_rest_response_cache_key($namespace, [
        'filters' => $filters,
        'cache_stamp' => $cacheStamp,
      ]),
    ];
  }

  private function get_valid_rest_cached_response($cacheKey) {
    $cachedResponse = $this->get_rest_response_cache($cacheKey);
    if (!is_array($cachedResponse)
      || !isset($cachedResponse['items'])
      || !isset($cachedResponse['pagination'])
      || !is_array($cachedResponse['items'])
      || !is_array($cachedResponse['pagination'])) {
      return null;
    }

    return $cachedResponse;
  }

  private function load_pages_link_rest_summary_rows($filters, $context) {
    $all = $this->get_pages_link_runtime_rows($filters, $context['scope_wpml_lang'], true);
    $pages = $this->get_pages_with_inbound_counts($all, $filters, false);

    return [
      'pages' => is_array($pages) ? $pages : [],
      'execution_mode' => $this->is_indexed_datastore_ready() ? 'indexed_lightweight_rows' : 'cache_scan_fallback',
    ];
  }

  private function paginate_pages_link_rest_response($pages, $filters) {
    $perPage = max(10, (int)$filters['per_page']);
    $requestedPage = max(1, (int)$filters['paged']);
    $cursor = $this->decode_pages_link_keyset_cursor((string)($filters['cursor'] ?? ''));

    if (is_array($cursor)) {
      $orderby = isset($filters['orderby']) ? (string)$filters['orderby'] : 'date';
      $isAsc = ((string)($filters['order'] ?? 'DESC') === 'ASC');
      $cursorValueRaw = (string)$cursor['order'];
      $cursorPostId = (int)$cursor['post_id'];

      $pages = array_values(array_filter($pages, function($row) use ($orderby, $isAsc, $cursorValueRaw, $cursorPostId) {
        $meta = $this->get_pages_link_cursor_sort_meta((array)$row, $orderby);
        $rowValue = $meta['numeric'] ? (int)$meta['value'] : (string)$meta['value'];
        $cursorValue = $meta['numeric'] ? (int)$cursorValueRaw : (string)$cursorValueRaw;

        if ($meta['numeric']) {
          $cmp = ((int)$rowValue <=> (int)$cursorValue);
        } else {
          $cmp = strcmp((string)$rowValue, (string)$cursorValue);
        }

        if ($cmp === 0) {
          $cmp = ((int)($row['post_id'] ?? 0) <=> $cursorPostId);
        }

        return $isAsc ? ($cmp > 0) : ($cmp < 0);
      }));
    } else {
      $offset = 0;
    }

    $total = count($pages);
    $totalPages = max(1, (int)ceil($total / $perPage));
    $paged = min($requestedPage, $totalPages);
    if (!is_array($cursor)) {
      $offset = ($paged - 1) * $perPage;
    }

    $pageRows = array_slice($pages, $offset, $perPage);
    $hasMore = ($offset + count($pageRows)) < count($pages);
    $nextCursor = '';
    if (!empty($pageRows) && $hasMore) {
      $lastRow = $pageRows[count($pageRows) - 1];
      $lastMeta = $this->get_pages_link_cursor_sort_meta((array)$lastRow, isset($filters['orderby']) ? (string)$filters['orderby'] : 'date');
      $nextCursor = $this->encode_pages_link_keyset_cursor((string)$lastMeta['value'], (int)($lastRow['post_id'] ?? 0));
    }

    foreach ($pageRows as &$row) {
      if ((string)($row['page_url'] ?? '') === '') {
        $row['page_url'] = (string)get_permalink((int)($row['post_id'] ?? 0));
      }
    }
    unset($row);

    return [
      'items' => array_values($pageRows),
      'summary_pages' => array_values($pages),
      'pagination' => [
        'total' => $total,
        'per_page' => $perPage,
        'paged' => $paged,
        'total_pages' => $totalPages,
        'next_cursor' => $nextCursor,
      ],
    ];
  }

  private function load_editor_rest_rows($filters, $context) {
    return $this->load_editor_rows_for_request(
      $context['scope_post_type'],
      $context['scope_wpml_lang'],
      $filters
    );
  }

  private function paginate_editor_rest_response($rows, $filters) {
    $cursor = $this->decode_editor_keyset_cursor((string)($filters['cursor'] ?? ''));
    $orderby = isset($filters['orderby']) ? (string)$filters['orderby'] : 'date';
    $isAsc = ((string)($filters['order'] ?? 'DESC') === 'ASC');
    $requestedPage = max(1, (int)$filters['paged']);

    if (is_array($cursor)) {
      $cursorValueRaw = (string)$cursor['order'];
      $cursorPostId = (int)$cursor['post_id'];
      $cursorRowId = (string)$cursor['row_id'];
      $rows = array_values(array_filter($rows, function($row) use ($orderby, $isAsc, $cursorValueRaw, $cursorPostId, $cursorRowId) {
        $meta = $this->get_editor_sort_meta_for_cursor((array)$row, $orderby);
        $rowValue = $meta['numeric'] ? (int)$meta['value'] : (string)$meta['value'];
        $cursorValue = $meta['numeric'] ? (int)$cursorValueRaw : (string)$cursorValueRaw;
        $cmp = $meta['numeric'] ? (((int)$rowValue <=> (int)$cursorValue)) : strcmp((string)$rowValue, (string)$cursorValue);
        if ($cmp === 0) {
          $cmp = ((int)($row['post_id'] ?? 0) <=> $cursorPostId);
        }
        if ($cmp === 0) {
          $cmp = strcmp((string)($row['row_id'] ?? ''), $cursorRowId);
        }
        return $isAsc ? ($cmp > 0) : ($cmp < 0);
      }));
    }

    $perPage = max(10, (int)$filters['per_page']);
    $total = count($rows);
    $totalPages = max(1, (int)ceil($total / $perPage));
    if (is_array($cursor)) {
      $paged = min($requestedPage, $totalPages);
      $offset = 0;
    } else {
      $paged = min($requestedPage, $totalPages);
      $offset = ($paged - 1) * $perPage;
    }

    $pageRows = array_slice($rows, $offset, $perPage);
    $hasMore = ($offset + count($pageRows)) < $total;
    $nextCursor = '';
    if (!empty($pageRows) && $hasMore) {
      $last = $pageRows[count($pageRows) - 1];
      $lastMeta = $this->get_editor_sort_meta_for_cursor((array)$last, $orderby);
      $nextCursor = $this->encode_editor_keyset_cursor(
        (string)$lastMeta['value'],
        (int)($last['post_id'] ?? 0),
        (string)($last['row_id'] ?? '')
      );
    }

    return [
      'items' => array_values($pageRows),
      'pagination' => [
        'total' => $total,
        'per_page' => $perPage,
        'paged' => $paged,
        'total_pages' => $totalPages,
        'next_cursor' => $nextCursor,
      ],
    ];
  }

  private function get_anchor_usage_map_indexed_first($wpmlLang = 'all') {
    $wpmlLang = $this->get_effective_scan_wpml_lang((string)$wpmlLang);
    $summaryFilters = [
      'post_type' => 'any',
      'post_category' => 0,
      'post_tag' => 0,
      'wpml_lang' => $wpmlLang,
      'location' => 'any',
      'source_type' => 'any',
      'link_type' => 'any',
      'value_contains' => '',
      'source_contains' => '',
      'title_contains' => '',
      'author' => 0,
      'seo_flag' => 'any',
      'search_mode' => 'contains',
    ];

    $anchorUsage = [];
    $indexedSummaryRows = $this->get_indexed_all_anchor_text_summary_rows($summaryFilters);
    if (!empty($indexedSummaryRows)) {
      foreach ($indexedSummaryRows as $summaryRow) {
        $k = strtolower($this->normalize_anchor_text_value((string)($summaryRow['anchor_text'] ?? ''), true));
        if ($k === '') {
          continue;
        }
        $anchorUsage[$k] = [
          'total' => (int)($summaryRow['total'] ?? 0),
          'inlink' => (int)($summaryRow['inlink'] ?? 0),
          'outbound' => (int)($summaryRow['outbound'] ?? 0),
        ];
      }
      return $anchorUsage;
    }

    $all = $this->get_canonical_rows_for_scope('any', false, $wpmlLang, $summaryFilters);
    foreach ($all as $row) {
      $a = $this->normalize_anchor_text_value((string)($row['anchor_text'] ?? ''), true);
      if ($a === '') continue;
      $k = strtolower($a);
      if (!isset($anchorUsage[$k])) $anchorUsage[$k] = ['total' => 0, 'inlink' => 0, 'outbound' => 0];
      $anchorUsage[$k]['total']++;
      if (($row['link_type'] ?? '') === 'inlink') $anchorUsage[$k]['inlink']++;
      if (($row['link_type'] ?? '') === 'exlink') $anchorUsage[$k]['outbound']++;
    }

    return $anchorUsage;
  }

  private function get_indexed_fact_row_map_by_row_ids($rowIds, $wpmlLang = 'all', $allowAnyAllFallback = true) {
    global $wpdb;

    if (!$this->is_indexed_datastore_ready()) {
      return [];
    }

    $normalizedIds = [];
    foreach ((array)$rowIds as $rowId) {
      $rowId = trim((string)$rowId);
      if ($rowId === '') continue;
      $normalizedIds[$rowId] = true;
    }
    $rowIds = array_keys($normalizedIds);
    if (empty($rowIds)) {
      return [];
    }

    $table = $wpdb->prefix . 'lm_link_fact';
    $wpmlLang = $this->get_effective_scan_wpml_lang((string)$wpmlLang);
    $result = [];

    $fetchRows = function($lang) use ($wpdb, $table, $rowIds, &$result) {
      foreach (array_chunk($rowIds, 500) as $chunk) {
        $placeholders = implode(',', array_fill(0, count($chunk), '%s'));
        $sql = "SELECT
          row_id, post_id, post_title, post_type, post_author, post_author_id, post_date, post_modified,
          page_url, source, link_location, block_index, occurrence, link_type, link, anchor_text,
          alt_text, snippet, rel_raw, relationship, rel_nofollow, rel_sponsored, rel_ugc, value_type
          FROM $table
          WHERE wpml_lang = %s AND row_id IN ($placeholders)";
        $params = array_merge([(string)$lang], $chunk);
        $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
        foreach ((array)$rows as $row) {
          $rid = (string)($row['row_id'] ?? '');
          if ($rid === '' || isset($result[$rid])) continue;
          $result[$rid] = [
            'row_id' => $rid,
            'post_id' => (string)((int)($row['post_id'] ?? 0)),
            'post_title' => (string)($row['post_title'] ?? ''),
            'post_type' => (string)($row['post_type'] ?? ''),
            'post_author' => (string)($row['post_author'] ?? ''),
            'post_author_id' => (string)((int)($row['post_author_id'] ?? 0)),
            'post_date' => (string)($row['post_date'] ?? ''),
            'post_modified' => (string)($row['post_modified'] ?? ''),
            'page_url' => (string)($row['page_url'] ?? ''),
            'source' => (string)($row['source'] ?? ''),
            'link_location' => (string)($row['link_location'] ?? ''),
            'block_index' => (string)($row['block_index'] ?? ''),
            'occurrence' => (string)($row['occurrence'] ?? ''),
            'link_type' => (string)($row['link_type'] ?? ''),
            'link' => (string)($row['link'] ?? ''),
            'anchor_text' => (string)($row['anchor_text'] ?? ''),
            'alt_text' => (string)($row['alt_text'] ?? ''),
            'snippet' => (string)($row['snippet'] ?? ''),
            'relationship' => (string)($row['relationship'] ?? ''),
            'rel_raw' => (string)($row['rel_raw'] ?? ''),
            'rel_nofollow' => !empty($row['rel_nofollow']) ? '1' : '0',
            'rel_sponsored' => !empty($row['rel_sponsored']) ? '1' : '0',
            'rel_ugc' => !empty($row['rel_ugc']) ? '1' : '0',
            'value_type' => (string)($row['value_type'] ?? ''),
          ];
        }
      }
    };

    $fetchRows($wpmlLang);
    if ($allowAnyAllFallback && !$this->has_exact_language_scope($wpmlLang) && count($result) < count($rowIds) && $wpmlLang !== 'all') {
      $fetchRows('all');
    }

    return $result;
  }

  public function rest_pages_link_list($request) {
    $overrides = $this->build_request_overrides_from_map($request, $this->get_pages_link_rest_request_override_map());
    return rest_ensure_response($this->with_request_input($overrides, function() {
      $filters = $this->get_pages_link_filters_from_request();
      $context = $this->build_rest_endpoint_context('pages_link_list', $filters);

      $cachedResponse = $this->get_valid_rest_cached_response($context['cache_key']);
      if (is_array($cachedResponse)) {
        $cachedResponse = $this->attach_rest_execution_meta($cachedResponse, 'pages_link_list', 'response_cache_hit', true);
        return $this->enrich_pages_link_rest_response($cachedResponse, $filters);
      }

      if ($this->can_use_pages_link_indexed_fastpath($filters)) {
        $indexedPagedResult = $this->get_pages_link_paged_result_from_indexed_summary($filters);
        if (is_array($indexedPagedResult)) {
          $response = [
            'items' => array_values((array)($indexedPagedResult['pages'] ?? [])),
            'pagination' => [
              'total' => max(0, (int)($indexedPagedResult['total'] ?? 0)),
              'per_page' => max(10, (int)($indexedPagedResult['per_page'] ?? ($filters['per_page'] ?? 25))),
              'paged' => max(1, (int)($indexedPagedResult['paged'] ?? ($filters['paged'] ?? 1))),
              'total_pages' => max(1, (int)($indexedPagedResult['total_pages'] ?? 1)),
              'next_cursor' => '',
            ],
            'status_summary' => isset($indexedPagedResult['status_summary']) && is_array($indexedPagedResult['status_summary']) ? $indexedPagedResult['status_summary'] : [],
            'internal_outbound_summary' => isset($indexedPagedResult['internal_outbound_summary']) && is_array($indexedPagedResult['internal_outbound_summary']) ? $indexedPagedResult['internal_outbound_summary'] : [],
            'external_outbound_summary' => isset($indexedPagedResult['external_outbound_summary']) && is_array($indexedPagedResult['external_outbound_summary']) ? $indexedPagedResult['external_outbound_summary'] : [],
          ];
          $response = $this->attach_rest_execution_meta($response, 'pages_link_list', 'indexed_summary_fastpath', false);
          $this->set_rest_response_cache($context['cache_key'], $response);
          return $this->enrich_pages_link_rest_response($response, $filters);
        }
      } elseif ($this->can_use_pages_link_indexed_summary_path($filters)) {
        $indexedRows = $this->get_pages_with_inbound_counts_from_indexed_summary($filters);
        $statusSummary = ['orphan' => 0, 'low' => 0, 'standard' => 0, 'excellent' => 0];
        $internalOutboundSummary = ['none' => 0, 'low' => 0, 'optimal' => 0, 'excessive' => 0];
        $externalOutboundSummary = ['none' => 0, 'low' => 0, 'optimal' => 0, 'excessive' => 0];
        foreach ((array)$indexedRows as $indexedRow) {
          $statusKey = (string)($indexedRow['status'] ?? '');
          $internalKey = (string)($indexedRow['internal_outbound_status'] ?? '');
          $externalKey = (string)($indexedRow['external_outbound_status'] ?? '');
          if (isset($statusSummary[$statusKey])) {
            $statusSummary[$statusKey]++;
          }
          if (isset($internalOutboundSummary[$internalKey])) {
            $internalOutboundSummary[$internalKey]++;
          }
          if (isset($externalOutboundSummary[$externalKey])) {
            $externalOutboundSummary[$externalKey]++;
          }
        }
        $response = $this->paginate_pages_link_rest_response((array)$indexedRows, $filters);
        $response['status_summary'] = $statusSummary;
        $response['internal_outbound_summary'] = $internalOutboundSummary;
        $response['external_outbound_summary'] = $externalOutboundSummary;
        $response = $this->attach_rest_execution_meta($response, 'pages_link_list', 'indexed_summary_filtered', false);
        $this->set_rest_response_cache($context['cache_key'], $response);
        return $this->enrich_pages_link_rest_response($response, $filters);
      }

      $pagesResult = $this->load_pages_link_rest_summary_rows($filters, $context);
      $response = $this->paginate_pages_link_rest_response((array)($pagesResult['pages'] ?? []), $filters);
      $response = $this->attach_rest_execution_meta($response, 'pages_link_list', (string)($pagesResult['execution_mode'] ?? 'cache_scan_fallback'), false);

      $this->set_rest_response_cache($context['cache_key'], $response);

      return $this->enrich_pages_link_rest_response($response, $filters);
    }));
  }

  public function rest_editor_list($request) {
    $overrides = $this->build_request_overrides_from_map($request, $this->get_editor_rest_request_override_map());
    return rest_ensure_response($this->with_request_input($overrides, function() {
      $filters = $this->get_filters_from_request();
      $context = $this->build_rest_endpoint_context('editor_list', $filters);

      $cachedResponse = $this->get_valid_rest_cached_response($context['cache_key']);
      if (is_array($cachedResponse)) {
        $cachedResponse = $this->attach_rest_execution_meta($cachedResponse, 'editor_list', 'response_cache_hit', true);
        return $this->enrich_editor_rest_response($cachedResponse, $filters);
      }

      $editorResult = $this->load_editor_rest_rows($filters, $context);
      if (isset($editorResult['response']) && is_array($editorResult['response'])) {
        $response = $this->attach_rest_execution_meta($editorResult['response'], 'editor_list', (string)($editorResult['execution_mode'] ?? 'indexed_sql_fastpath'), false);
      } else {
        $response = $this->paginate_editor_rest_response((array)($editorResult['rows'] ?? []), $filters);
        $response = $this->attach_rest_execution_meta($response, 'editor_list', (string)($editorResult['execution_mode'] ?? 'cache_scan_fallback'), false);
      }
      if (!empty($editorResult['warning_notice'])) {
        $response['warning_notice'] = (string)$editorResult['warning_notice'];
      }

      $this->set_rest_response_cache($context['cache_key'], $response);

      return $this->enrich_editor_rest_response($response, $filters);
    }));
  }

  private function enrich_pages_link_rest_response($response, $filters) {
    if (!is_array($response)) {
      return $response;
    }

    $pagination = isset($response['pagination']) && is_array($response['pagination']) ? $response['pagination'] : [];
    $items = isset($response['items']) && is_array($response['items']) ? array_values($response['items']) : [];
    $summaryPages = isset($response['summary_pages']) && is_array($response['summary_pages']) ? array_values($response['summary_pages']) : [];
    $statusSummary = isset($response['status_summary']) && is_array($response['status_summary']) ? $response['status_summary'] : null;
    $internalOutboundSummary = isset($response['internal_outbound_summary']) && is_array($response['internal_outbound_summary']) ? $response['internal_outbound_summary'] : null;
    $externalOutboundSummary = isset($response['external_outbound_summary']) && is_array($response['external_outbound_summary']) ? $response['external_outbound_summary'] : null;
    $paged = max(1, (int)($pagination['paged'] ?? 1));
    $totalPages = max(1, (int)($pagination['total_pages'] ?? 1));
    $total = max(0, (int)($pagination['total'] ?? count($items)));

    if (is_array($statusSummary) && is_array($internalOutboundSummary) && is_array($externalOutboundSummary)) {
      $summariesHtml = $this->get_pages_link_summaries_html_from_counts(
        $filters,
        $total,
        $statusSummary,
        $internalOutboundSummary,
        $externalOutboundSummary
      );
    } else {
      $summariesHtml = $this->get_pages_link_summaries_html($summaryPages, $filters);
    }

    $response['rendered'] = [
      'tbody_html' => $this->get_pages_link_results_tbody_html($items),
      'pagination_html' => $this->get_rest_pagination_html($paged, $totalPages, $total, (int)($pagination['per_page'] ?? ($filters['per_page'] ?? 25))),
      'total_text' => (string)$total,
      'summaries_html' => $summariesHtml,
    ];
    unset($response['summary_pages'], $response['status_summary'], $response['internal_outbound_summary'], $response['external_outbound_summary']);

    return $response;
  }

  private function enrich_editor_rest_response($response, $filters) {
    if (!is_array($response)) {
      return $response;
    }

    $pagination = isset($response['pagination']) && is_array($response['pagination']) ? $response['pagination'] : [];
    $items = isset($response['items']) && is_array($response['items']) ? array_values($response['items']) : [];
    $paged = max(1, (int)($pagination['paged'] ?? 1));
    $totalPages = max(1, (int)($pagination['total_pages'] ?? 1));
    $perPage = max(10, (int)($pagination['per_page'] ?? ($filters['per_page'] ?? 25)));
    $total = max(0, (int)($pagination['total'] ?? count($items)));
    $hiddenFields = $this->get_editor_hidden_fields($filters, $perPage, $paged);

    $response['rendered'] = [
      'tbody_html' => $this->get_editor_results_tbody_html($items, $hiddenFields),
      'pagination_html' => $this->get_rest_pagination_html($paged, $totalPages, $total, $perPage),
      'results_count_text' => $this->get_editor_results_count_text($total),
    ];

    return $response;
  }

  private function attach_rest_execution_meta($response, $endpoint, $mode, $fromCache = false) {
    if (!is_array($response)) {
      return $response;
    }

    $meta = isset($response['meta']) && is_array($response['meta']) ? $response['meta'] : [];
    $meta['endpoint'] = sanitize_text_field((string)$endpoint);
    $meta['execution_mode'] = sanitize_key((string)$mode);
    $meta['response_cache'] = !empty($fromCache) ? 'hit' : 'miss';
    $response['meta'] = $meta;
    return $response;
  }

  private function get_indexed_finalize_lang_queue_from_state($state, $jobWpmlLang = 'all') {
    $queue = [];
    if (isset($state['finalize_lang_queue']) && is_array($state['finalize_lang_queue'])) {
      $queue = array_map([$this, 'sanitize_wpml_lang_filter'], (array)$state['finalize_lang_queue']);
    }
    if (empty($queue)) {
      $queue = $this->get_rebuild_finalize_lang_queue($jobWpmlLang);
    }

    $queue = array_values(array_unique(array_filter($queue)));
    if (empty($queue)) {
      return ['all'];
    }

    return $queue;
  }

  private function get_indexed_all_finalize_strategy($state, $jobWpmlLang = 'all') {
    $queue = $this->get_indexed_finalize_lang_queue_from_state($state, $jobWpmlLang);
    foreach ((array)$queue as $lang) {
      $lang = $this->sanitize_wpml_lang_filter((string)$lang);
      if ($lang !== '' && $lang !== 'all') {
        return 'aggregate_from_language_summaries';
      }
    }

    return 'direct_from_all_facts';
  }

  private function get_active_rebuild_crawl_lang(&$state, $requestedWpmlLang = 'all') {
    $queue = isset($state['crawl_lang_queue']) && is_array($state['crawl_lang_queue'])
      ? array_values(array_filter(array_map([$this, 'sanitize_wpml_lang_filter'], (array)$state['crawl_lang_queue'])))
      : $this->get_rebuild_crawl_lang_queue($requestedWpmlLang);
    if (empty($queue)) {
      $queue = [$this->normalize_rebuild_wpml_lang((string)$requestedWpmlLang)];
    }

    $index = max(0, (int)($state['crawl_lang_index'] ?? 0));
    if (!isset($queue[$index])) {
      $index = 0;
    }

    $activeLang = sanitize_key((string)($state['active_crawl_wpml_lang'] ?? ''));
    if ($activeLang === '' || !in_array($activeLang, $queue, true)) {
      $activeLang = (string)$queue[$index];
    }

    $state['crawl_lang_queue'] = $queue;
    $state['crawl_lang_index'] = $index;
    $state['active_crawl_wpml_lang'] = $activeLang;

    return $activeLang;
  }

  private function advance_rebuild_crawl_lang(&$state, $requestedWpmlLang = 'all') {
    $queue = isset($state['crawl_lang_queue']) && is_array($state['crawl_lang_queue'])
      ? array_values(array_filter(array_map([$this, 'sanitize_wpml_lang_filter'], (array)$state['crawl_lang_queue'])))
      : $this->get_rebuild_crawl_lang_queue($requestedWpmlLang);
    if (empty($queue)) {
      return false;
    }

    $index = max(0, (int)($state['crawl_lang_index'] ?? 0)) + 1;
    if (!isset($queue[$index])) {
      return false;
    }

    $state['crawl_lang_queue'] = $queue;
    $state['crawl_lang_index'] = $index;
    $state['active_crawl_wpml_lang'] = (string)$queue[$index];
    return true;
  }

  private function get_active_indexed_finalize_lang(&$state, $jobWpmlLang = 'all') {
    $queue = $this->get_indexed_finalize_lang_queue_from_state($state, $jobWpmlLang);
    $index = max(0, (int)($state['finalize_lang_index'] ?? 0));
    if (!isset($queue[$index])) {
      $index = 0;
    }

    $activeLang = sanitize_key((string)($state['finalize_wpml_lang'] ?? ''));
    if ($activeLang === '' || !in_array($activeLang, $queue, true)) {
      $activeLang = (string)$queue[$index];
    }

    $state['finalize_lang_queue'] = $queue;
    $state['finalize_lang_index'] = $index;
    $state['finalize_wpml_lang'] = $activeLang;

    return $activeLang;
  }

  private function advance_indexed_finalize_lang(&$state, $queue, $jobWpmlLang = 'all') {
    $queue = $this->get_indexed_finalize_lang_queue_from_state(['finalize_lang_queue' => $queue], $jobWpmlLang);
    $index = max(0, (int)($state['finalize_lang_index'] ?? 0)) + 1;
    if (!isset($queue[$index])) {
      return false;
    }

    $state['finalize_lang_queue'] = $queue;
    $state['finalize_lang_index'] = $index;
    $state['finalize_wpml_lang'] = (string)$queue[$index];
    $state['finalize_stage'] = 'summary_seed';
    unset(
      $state['finalize_last_post_id'],
      $state['finalize_processed_posts'],
      $state['finalize_chunk_size'],
      $state['finalize_step_ms'],
      $state['finalize_summary_rows'],
      $state['finalize_inbound_rows'],
      $state['finalize_target_only_rows_added'],
      $state['finalize_seed_last_post_id'],
      $state['finalize_seed_processed_posts'],
      $state['finalize_seed_step_ms'],
      $state['finalize_seed_chunk_size'],
      $state['finalize_inbound_last_post_id'],
      $state['finalize_inbound_processed_posts'],
      $state['finalize_inbound_step_ms'],
      $state['finalize_inbound_chunk_size'],
      $state['finalize_last_summary_query_ms'],
      $state['finalize_last_inbound_query_ms'],
      $state['normalized_backfill_last_id'],
      $state['normalized_backfill_processed'],
      $state['normalized_backfill_step_ms'],
      $state['normalized_backfill_chunk_size'],
      $state['normalized_backfill_done'],
      $state['finalize_domain_last_post_id'],
      $state['finalize_domain_processed_posts'],
      $state['finalize_domain_chunk_size'],
      $state['finalize_domain_step_ms'],
      $state['finalize_domain_rows'],
      $state['finalize_anchor_last_post_id'],
      $state['finalize_anchor_processed_posts'],
      $state['finalize_anchor_chunk_size'],
      $state['finalize_anchor_step_ms'],
      $state['finalize_anchor_rows']
    );

    return true;
  }

}
