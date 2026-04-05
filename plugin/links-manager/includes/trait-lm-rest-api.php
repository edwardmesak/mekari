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
    $wpmlLang = $this->get_effective_scan_wpml_lang($wpmlLang);

    $enabledPostTypes = $this->get_enabled_scan_post_types();
    $postTypes = ($scopePostType === 'any')
      ? $enabledPostTypes
      : (in_array($scopePostType, $enabledPostTypes, true) ? [$scopePostType] : []);

    $currentState = $this->recover_stale_rebuild_job($this->get_rebuild_job_state(), 1800);
    if (!empty($currentState) && (string)($currentState['status'] ?? '') === 'running') {
      $runningScope = sanitize_key((string)($currentState['scope_post_type'] ?? 'any'));
      $runningLang = $this->get_effective_scan_wpml_lang((string)($currentState['wpml_lang'] ?? 'all'));
      if ($runningScope === $scopePostType && $runningLang === $wpmlLang) {
        $currentState['message'] = 'Rebuild job is already running. Continuing existing job.';
        return rest_ensure_response($this->get_public_rebuild_job_state($currentState));
      }
      $currentState['message'] = 'Another rebuild job is currently running. Please wait until it finishes.';
      return rest_ensure_response($this->get_public_rebuild_job_state($currentState));
    }

    $job = [
      'status' => 'running',
      'scope_post_type' => $scopePostType,
      'wpml_lang' => $wpmlLang,
      'post_types' => $postTypes,
      'storage_mode' => ($scopePostType === 'any' && $this->is_indexed_datastore_ready()) ? 'indexed_stream' : 'transient_cache',
      'scan_modified_after_gmt' => '',
      'last_seen_id' => 0,
      'offset' => 0,
      'total_posts' => 0,
      'processed_posts' => 0,
      'rows_count' => 0,
      'started_at' => current_time('mysql'),
      'updated_at' => current_time('mysql'),
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
    $job['total_posts'] = $this->count_cache_post_ids($postTypes, $wpmlLang, $job['scan_modified_after_gmt']);
    if ((string)$job['storage_mode'] === 'indexed_stream') {
      $this->reset_indexed_datastore_for_lang($wpmlLang);
      $this->clear_cache_payload($scopePostType, $wpmlLang);
    } else {
      set_transient($this->rebuild_job_partial_rows_key($scopePostType, $wpmlLang), [], self::CACHE_TTL);
    }
    $this->save_rebuild_job_state($job);

    return rest_ensure_response($this->get_public_rebuild_job_state($job));
  }

  public function rest_rebuild_status($request) {
    $state = $this->get_rebuild_job_state();
    return rest_ensure_response($this->get_public_rebuild_job_state($state));
  }

  public function rest_rebuild_step($request) {
    $state = $this->recover_stale_rebuild_job($this->get_rebuild_job_state(), 1800);
    if (empty($state) || (string)($state['status'] ?? '') !== 'running') {
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
    $wpmlLang = $this->get_effective_scan_wpml_lang((string)($state['wpml_lang'] ?? 'all'));
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
    $maxRows = $this->get_runtime_max_cache_rows();
    $maxPosts = $this->get_max_posts_per_rebuild();
    $processedPosts = (int)($state['processed_posts'] ?? 0);
    $crawlStartedAt = microtime(true);

    $wpmlWasSwitched = false;
    $wpmlPreviousLang = '';
    try {
      if ($this->is_wpml_active()) {
        $prev = $this->safe_wpml_apply_filters('wpml_current_language', null);
        if (is_string($prev)) {
          $wpmlPreviousLang = sanitize_key($prev);
        }
        $switchLang = ($wpmlLang === 'all') ? 'all' : $wpmlLang;
        $wpmlWasSwitched = $this->safe_wpml_switch_language($switchLang);
      }

      $batchPostIds = $this->query_cache_post_ids_chunk($postTypes, $wpmlLang, $scanModifiedAfterGmt, $lastSeenId, $batch);
      $end = count($batchPostIds);
      $nextOffset = $offset;
      $nextLastSeenId = $lastSeenId;
      $batchRows = [];

      for ($i = 0; $i < $end; $i++) {
        $nextOffset = $offset + $i + 1;

        if ($maxPosts > 0 && $processedPosts >= $maxPosts) {
          break;
        }

        $postId = isset($batchPostIds[$i]) ? (int)$batchPostIds[$i] : 0;
        if ($postId > $nextLastSeenId) {
          $nextLastSeenId = $postId;
        }
        if ($postId < 1) continue;

        $postRows = $this->crawl_post($postId, $enabledSources);
        if ($storageMode === 'indexed_stream') {
          $this->append_rows($batchRows, $postRows);
        } else {
          $this->append_rows($allRows, $postRows);
        }
        $processedPosts++;

        if ($storageMode !== 'indexed_stream' && count($allRows) >= $maxRows) {
          $allRows = array_slice($allRows, 0, $maxRows);
          break;
        }
        if ($this->should_abort_crawl($crawlStartedAt)) {
          break;
        }
      }

      if ($storageMode === 'indexed_stream' && !empty($batchRows)) {
        $insertedRows = (int)$this->append_indexed_datastore_rows($batchRows, $wpmlLang);
        $state['rows_count'] = max(0, (int)($state['rows_count'] ?? 0)) + $insertedRows;
      }
    } catch (Throwable $e) {
      $state['status'] = 'error';
      $state['last_error'] = sanitize_text_field($e->getMessage());
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

    $newOffset = max(0, (int)(isset($nextOffset) ? $nextOffset : $offset));
    $newLastSeenId = max(0, (int)(isset($nextLastSeenId) ? $nextLastSeenId : $lastSeenId));
    $lastBatchCount = isset($batchPostIds) && is_array($batchPostIds) ? count($batchPostIds) : 0;
    $hitMaxPosts = ($maxPosts > 0 && $processedPosts >= $maxPosts);
    $hitMaxRows = ($storageMode !== 'indexed_stream' && count($allRows) >= $maxRows);
    $exhaustedPosts = ($lastBatchCount === 0) || (($totalPosts > 0) && ($newOffset >= $totalPosts));
    $done = $exhaustedPosts || $hitMaxPosts || $hitMaxRows;

    if ($done) {
      if ($storageMode === 'indexed_stream') {
        $menuRows = $this->crawl_menus($enabledSources);
        if (!empty($menuRows)) {
          $state['rows_count'] = max(0, (int)($state['rows_count'] ?? 0)) + (int)$this->append_indexed_datastore_rows($menuRows, $wpmlLang);
        }
        $this->rebuild_indexed_summary_for_lang($wpmlLang);
        $this->clear_cache_payload($scopePostType, $wpmlLang);
        update_option($this->cache_scan_option_key($scopePostType, $wpmlLang), gmdate('Y-m-d H:i:s'), false);
        $this->bump_dataset_cache_version();
      } else {
        if (count($allRows) < $maxRows) {
          $this->append_rows($allRows, $this->crawl_menus($enabledSources));
        }
        $this->persist_cache_payload($scopePostType, $wpmlLang, $allRows);
      }
      $this->schedule_rest_list_prewarm($scopePostType, $wpmlLang, 2);
      if ($this->is_wpml_active()) {
        update_option('lm_last_wpml_lang_context', (string)$wpmlLang, false);
      }
      if ($partialKey !== '') {
        delete_transient($partialKey);
      }
      if ($hitMaxRows) {
        $state['status'] = 'partial';
        $state['message'] = sprintf(
          'Refresh stopped at the safety cache row limit (%1$s rows) before all posts were scanned.',
          number_format_i18n($maxRows)
        );
      } elseif ($hitMaxPosts) {
        $state['status'] = 'partial';
        $state['message'] = sprintf(
          'Refresh stopped at the configured post limit (%1$s posts) before all posts were scanned.',
          number_format_i18n($maxPosts)
        );
      } else {
        $state['status'] = 'done';
      }
    } else {
      if ($partialKey !== '') {
        set_transient($partialKey, $allRows, self::CACHE_TTL);
      }
      $state['status'] = 'running';
    }

    $state['offset'] = $newOffset;
    $state['last_seen_id'] = $newLastSeenId;
    $state['processed_posts'] = $processedPosts;
    if ($storageMode !== 'indexed_stream') {
      $state['rows_count'] = count((array)$allRows);
    }
    $state['updated_at'] = current_time('mysql');
    $state['batch_size'] = $batch;
    $state['step_ms'] = max(0, (int)round((microtime(true) - $crawlStartedAt) * 1000));
    if ((string)$state['status'] === 'running') {
      $state['message'] = '';
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
    $rebuildRequested = !empty($filters['rebuild']);
    $cacheStamp = (string)get_option($this->cache_scan_option_key($scopePostType, $scopeWpmlLang), '');

    return [
      'namespace' => sanitize_key((string)$namespace),
      'scope_post_type' => $scopePostType,
      'scope_wpml_lang' => $scopeWpmlLang,
      'rebuild_requested' => $rebuildRequested,
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
    $rebuildRequested = !empty($context['rebuild_requested']);
    $all = null;
    $usedExistingCache = false;
    $usedIndexedAuthority = false;
    $usedRebuild = false;

    if (!$rebuildRequested) {
      // Pages Link summaries need all source rows so inbound counts still include links from other post types.
      $all = $this->get_existing_cache_rows_for_rest('any', $context['scope_wpml_lang'], true);
      if (is_array($all)) {
        if ($this->is_indexed_datastore_ready()) {
          $usedIndexedAuthority = true;
        } else {
          $usedExistingCache = true;
        }
      }
    }

    if (!is_array($all)) {
      $all = $this->get_canonical_rows_for_scope(
        'any',
        $rebuildRequested,
        $context['scope_wpml_lang'],
        $filters
      );
      $usedRebuild = $rebuildRequested;
    }

    $this->compact_rows_for_pages_link($all);
    $pages = $this->get_pages_with_inbound_counts($all, $filters, false);

    if ($usedRebuild) {
      $executionMode = 'rebuild_cache_scan';
    } elseif ($usedIndexedAuthority) {
      $executionMode = 'indexed_prefilter_php';
    } elseif ($usedExistingCache) {
      $executionMode = 'cache_scan';
    } else {
      $executionMode = 'cache_scan_fallback';
    }

    return [
      'pages' => is_array($pages) ? $pages : [],
      'execution_mode' => $executionMode,
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
    $rebuildRequested = !empty($context['rebuild_requested']);

    if (!$rebuildRequested) {
      $indexedFastResponse = $this->get_indexed_editor_list_fastpath_response($context['scope_post_type'], $context['scope_wpml_lang'], $filters);
      if (is_array($indexedFastResponse)
        && isset($indexedFastResponse['items'])
        && isset($indexedFastResponse['pagination'])
        && is_array($indexedFastResponse['items'])
        && is_array($indexedFastResponse['pagination'])) {
        return [
          'response' => $indexedFastResponse,
          'execution_mode' => 'indexed_sql_fastpath',
        ];
      }
    }

    $all = null;
    $executionMode = 'cache_scan_fallback';
    $usedIndexedAuthority = false;
    $usedExistingCache = false;
    $usedRebuild = false;

    if (!$rebuildRequested && $this->is_indexed_datastore_ready()) {
      $all = $this->get_indexed_fact_rows($context['scope_post_type'], $context['scope_wpml_lang'], $filters);
      if (is_array($all) && !empty($all)) {
        $usedIndexedAuthority = true;
      }
    }

    if (!is_array($all)) {
      $all = null;
    }
    if (empty($all) && !$rebuildRequested && !$usedIndexedAuthority) {
      $all = $this->get_existing_cache_rows_for_rest($context['scope_post_type'], $context['scope_wpml_lang'], false);
      if (is_array($all)) {
        $usedExistingCache = true;
      }
    }
    if (!is_array($all)) {
      $all = $this->get_canonical_rows_for_scope(
        $context['scope_post_type'],
        $rebuildRequested,
        $context['scope_wpml_lang'],
        $filters,
        false
      );
      $usedRebuild = $rebuildRequested;
    }

    if ($usedRebuild) {
      $executionMode = 'rebuild_cache_scan';
    } elseif ($usedIndexedAuthority) {
      $executionMode = 'indexed_prefilter_php';
    } elseif ($usedExistingCache) {
      $executionMode = 'cache_scan';
    }

    return [
      'rows' => $this->apply_filters_and_group($all, $filters),
      'execution_mode' => $executionMode,
    ];
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
        (int)($last['row_id'] ?? 0)
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
      'author_contains' => '',
      'seo_flag' => 'any',
      'search_mode' => 'contains',
    ];

    $anchorUsage = [];
    $indexedSummaryRows = $this->get_indexed_all_anchor_text_summary_rows($summaryFilters);
    if (!empty($indexedSummaryRows)) {
      foreach ($indexedSummaryRows as $summaryRow) {
        $k = strtolower(trim((string)($summaryRow['anchor_text'] ?? '')));
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
      $a = trim((string)($row['anchor_text'] ?? ''));
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
          row_id, post_id, post_title, post_type, post_author, post_date, post_modified,
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
    if ($allowAnyAllFallback && count($result) < count($rowIds) && $wpmlLang !== 'all') {
      $fetchRows('all');
    }

    return $result;
  }

  public function rest_pages_link_list($request) {
    $overrides = $this->build_request_overrides_from_map($request, $this->get_pages_link_rest_request_override_map());
    return rest_ensure_response($this->with_request_input($overrides, function() {
      $filters = $this->get_pages_link_filters_from_request();
      $context = $this->build_rest_endpoint_context('pages_link_list', $filters);

      if (empty($context['rebuild_requested'])) {
        $cachedResponse = $this->get_valid_rest_cached_response($context['cache_key']);
        if (is_array($cachedResponse)) {
          $cachedResponse = $this->attach_rest_execution_meta($cachedResponse, 'pages_link_list', 'response_cache_hit', true);
          return $this->enrich_pages_link_rest_response($cachedResponse, $filters);
        }
      }

      $pagesResult = $this->load_pages_link_rest_summary_rows($filters, $context);
      $response = $this->paginate_pages_link_rest_response((array)($pagesResult['pages'] ?? []), $filters);
      $response = $this->attach_rest_execution_meta($response, 'pages_link_list', (string)($pagesResult['execution_mode'] ?? 'cache_scan_fallback'), false);

      if (empty($context['rebuild_requested'])) {
        $this->set_rest_response_cache($context['cache_key'], $response);
      }

      return $this->enrich_pages_link_rest_response($response, $filters);
    }));
  }

  public function rest_editor_list($request) {
    $overrides = $this->build_request_overrides_from_map($request, $this->get_editor_rest_request_override_map());
    return rest_ensure_response($this->with_request_input($overrides, function() {
      $filters = $this->get_filters_from_request();
      $context = $this->build_rest_endpoint_context('editor_list', $filters);

      if (empty($context['rebuild_requested'])) {
        $cachedResponse = $this->get_valid_rest_cached_response($context['cache_key']);
        if (is_array($cachedResponse)) {
          $cachedResponse = $this->attach_rest_execution_meta($cachedResponse, 'editor_list', 'response_cache_hit', true);
          return $this->enrich_editor_rest_response($cachedResponse, $filters);
        }
      }

      $editorResult = $this->load_editor_rest_rows($filters, $context);
      if (isset($editorResult['response']) && is_array($editorResult['response'])) {
        $response = $this->attach_rest_execution_meta($editorResult['response'], 'editor_list', (string)($editorResult['execution_mode'] ?? 'indexed_sql_fastpath'), false);
      } else {
        $response = $this->paginate_editor_rest_response((array)($editorResult['rows'] ?? []), $filters);
        $response = $this->attach_rest_execution_meta($response, 'editor_list', (string)($editorResult['execution_mode'] ?? 'cache_scan_fallback'), false);
      }

      if (empty($context['rebuild_requested'])) {
        $this->set_rest_response_cache($context['cache_key'], $response);
      }

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
    $paged = max(1, (int)($pagination['paged'] ?? 1));
    $totalPages = max(1, (int)($pagination['total_pages'] ?? 1));
    $total = max(0, (int)($pagination['total'] ?? count($items)));

    $response['rendered'] = [
      'tbody_html' => $this->get_pages_link_results_tbody_html($items),
      'pagination_html' => $this->get_rest_pagination_html($paged, $totalPages, $total, (int)($pagination['per_page'] ?? ($filters['per_page'] ?? 25))),
      'total_text' => (string)$total,
      'summaries_html' => $this->get_pages_link_summaries_html($summaryPages, $filters),
    ];
    unset($response['summary_pages']);

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

}
