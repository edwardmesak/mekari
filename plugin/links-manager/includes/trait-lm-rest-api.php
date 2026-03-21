<?php
/**
 * REST API routes, handlers, and self-test helpers.
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

    register_rest_route('links-manager/v1', '/self-test/run', [
      'methods' => 'POST',
      'callback' => [$this, 'rest_self_test_run'],
      'permission_callback' => [$this, 'rest_can_manage_links_manager'],
      'args' => [
        'sample_size' => [
          'required' => false,
          'type' => 'integer',
          'sanitize_callback' => 'absint',
          'validate_callback' => function($value) {
            return is_numeric($value);
          },
        ],
      ],
    ]);

    register_rest_route('links-manager/v1', '/self-test/deep', [
      'methods' => 'POST',
      'callback' => [$this, 'rest_self_test_deep'],
      'permission_callback' => [$this, 'rest_can_manage_links_manager'],
      'args' => [
        'iterations' => [
          'required' => false,
          'type' => 'integer',
          'sanitize_callback' => 'absint',
          'validate_callback' => function($value) {
            return is_numeric($value);
          },
        ],
        'per_page' => [
          'required' => false,
          'type' => 'integer',
          'sanitize_callback' => 'absint',
          'validate_callback' => function($value) {
            return is_numeric($value);
          },
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
    $job['total_posts'] = 0;

    set_transient($this->rebuild_job_partial_rows_key($scopePostType, $wpmlLang), [], self::CACHE_TTL);
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

    $partialKey = $this->rebuild_job_partial_rows_key($scopePostType, $wpmlLang);
    $allRows = get_transient($partialKey);
    if (!is_array($allRows)) {
      $allRows = [];
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

        $this->append_rows($allRows, $this->crawl_post($postId, $enabledSources));
        $processedPosts++;

        if (count($allRows) >= $maxRows) {
          $allRows = array_slice($allRows, 0, $maxRows);
          break;
        }
        if ($this->should_abort_crawl($crawlStartedAt)) {
          break;
        }
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
    $done = ($lastBatchCount < $batch) || (($totalPosts > 0) && ($newOffset >= $totalPosts)) || ($maxPosts > 0 && $processedPosts >= $maxPosts) || (count($allRows) >= $maxRows);

    if ($done) {
      if (count($allRows) < $maxRows) {
        $this->append_rows($allRows, $this->crawl_menus($enabledSources));
      }
      $this->persist_cache_payload($scopePostType, $wpmlLang, $allRows);
      $this->schedule_rest_list_prewarm($scopePostType, $wpmlLang, 2);
      if ($this->is_wpml_active()) {
        update_option('lm_last_wpml_lang_context', (string)$wpmlLang, false);
      }
      delete_transient($partialKey);
      $state['status'] = 'done';
    } else {
      set_transient($partialKey, $allRows, self::CACHE_TTL);
      $state['status'] = 'running';
    }

    $state['offset'] = $newOffset;
    $state['last_seen_id'] = $newLastSeenId;
    $state['processed_posts'] = $processedPosts;
    $state['rows_count'] = count((array)$allRows);
    $state['updated_at'] = current_time('mysql');
    $state['batch_size'] = $batch;
    $state['step_ms'] = max(0, (int)round((microtime(true) - $crawlStartedAt) * 1000));
    if ((string)$state['status'] === 'running') {
      $state['message'] = '';
    }
    $this->save_rebuild_job_state($state);

    return rest_ensure_response($this->get_public_rebuild_job_state($state));
  }

  private function with_request_overrides($overrides, $callback) {
    $backupRequest = $_REQUEST;
    try {
      if (is_array($overrides)) {
        foreach ($overrides as $key => $value) {
          $_REQUEST[(string)$key] = $value;
        }
      }
      return call_user_func($callback);
    } finally {
      $_REQUEST = $backupRequest;
    }
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
    $map = [
      'post_type' => 'lm_pages_link_post_type',
      'post_category' => 'lm_pages_link_post_category',
      'post_tag' => 'lm_pages_link_post_tag',
      'author' => 'lm_pages_link_author',
      'search' => 'lm_pages_link_search',
      'search_url' => 'lm_pages_link_search_url',
      'date_from' => 'lm_pages_link_date_from',
      'date_to' => 'lm_pages_link_date_to',
      'updated_date_from' => 'lm_pages_link_updated_date_from',
      'updated_date_to' => 'lm_pages_link_updated_date_to',
      'search_mode' => 'lm_pages_link_search_mode',
      'location' => 'lm_pages_link_location',
      'source_type' => 'lm_pages_link_source_type',
      'link_type' => 'lm_pages_link_link_type',
      'value' => 'lm_pages_link_value',
      'value_contains' => 'lm_pages_link_value',
      'seo_flag' => 'lm_pages_link_seo_flag',
      'orderby' => 'lm_pages_link_orderby',
      'order' => 'lm_pages_link_order',
      'inbound_min' => 'lm_pages_link_inbound_min',
      'inbound_max' => 'lm_pages_link_inbound_max',
      'internal_outbound_min' => 'lm_pages_link_internal_outbound_min',
      'internal_outbound_max' => 'lm_pages_link_internal_outbound_max',
      'outbound_min' => 'lm_pages_link_outbound_min',
      'outbound_max' => 'lm_pages_link_outbound_max',
      'status' => 'lm_pages_link_status',
      'internal_outbound_status' => 'lm_pages_link_internal_outbound_status',
      'external_outbound_status' => 'lm_pages_link_external_outbound_status',
      'cursor' => 'lm_pages_link_cursor',
      'rebuild' => 'lm_pages_link_rebuild',
      'paged' => 'lm_pages_link_paged',
      'per_page' => 'lm_pages_link_per_page',
    ];

    $overrides = $this->build_request_overrides_from_map($request, $map);
    return rest_ensure_response($this->with_request_overrides($overrides, function() {
      $filters = $this->get_pages_link_filters_from_request();

      $scopePostType = sanitize_key((string)($filters['post_type'] ?? 'any'));
      if ($scopePostType === '') {
        $scopePostType = 'any';
      }
      $scopeWpmlLang = $this->get_effective_scan_wpml_lang((string)($filters['wpml_lang'] ?? 'all'));
      $rebuildRequested = !empty($filters['rebuild']);
      $cacheStamp = (string)get_option($this->cache_scan_option_key($scopePostType, $scopeWpmlLang), '');
      $cacheKey = $this->build_rest_response_cache_key('pages_link_list', [
        'filters' => $filters,
        'cache_stamp' => $cacheStamp,
      ]);

      if (!$rebuildRequested) {
        $cachedResponse = $this->get_rest_response_cache($cacheKey);
        if (is_array($cachedResponse)
          && isset($cachedResponse['items'])
          && isset($cachedResponse['pagination'])
          && is_array($cachedResponse['items'])
          && is_array($cachedResponse['pagination'])) {
          return $this->attach_rest_execution_meta($cachedResponse, 'pages_link_list', 'response_cache_hit', true);
        }
      }

      $executionMode = 'cache_scan';
      $pages = null;
      if (!$rebuildRequested && $this->is_indexed_datastore_ready() && $this->can_use_indexed_pages_link_summary_fastpath($filters)) {
        $pages = $this->get_pages_with_inbound_counts_from_indexed_summary($filters);
        if (is_array($pages) && !empty($pages)) {
          $executionMode = 'indexed_summary_fastpath';
        } else {
          $pages = null;
        }
      }

      if (!is_array($pages)) {
        $all = null;
        $usedExistingCache = false;
        $usedIndexedAuthority = false;
        $usedRebuild = false;
        if (!$rebuildRequested) {
          $all = $this->get_existing_cache_rows_for_rest($scopePostType, $scopeWpmlLang, true);
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
            $scopePostType,
            $rebuildRequested,
            $scopeWpmlLang,
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
      }

      $total = count($pages);
      $perPage = max(10, (int)$filters['per_page']);
      $paged = max(1, (int)$filters['paged']);
      $cursor = $this->decode_pages_link_keyset_cursor((string)($filters['cursor'] ?? ''));
      $totalPages = max(1, (int)ceil($total / $perPage));

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
        $paged = 1;
        $totalPages = max(1, (int)ceil(count($pages) / $perPage));
      } else {
        if ($paged > $totalPages) {
          $paged = $totalPages;
        }
      }

      $offset = ($paged - 1) * $perPage;
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

      $response = [
        'items' => array_values($pageRows),
        'pagination' => [
          'total' => $total,
          'per_page' => $perPage,
          'paged' => $paged,
          'total_pages' => $totalPages,
          'next_cursor' => $nextCursor,
        ],
      ];
      $response = $this->attach_rest_execution_meta($response, 'pages_link_list', $executionMode, false);

      if (!$rebuildRequested) {
        $this->set_rest_response_cache($cacheKey, $response);
      }

      return $response;
    }));
  }

  public function rest_editor_list($request) {
    $map = [
      'post_type' => 'lm_post_type',
      'post_category' => 'lm_post_category',
      'post_tag' => 'lm_post_tag',
      'location' => 'lm_location',
      'source_type' => 'lm_source_type',
      'link_type' => 'lm_link_type',
      'value_type' => 'lm_value_type',
      'value' => 'lm_value',
      'source' => 'lm_source',
      'title' => 'lm_title',
      'author' => 'lm_author',
      'publish_date_from' => 'lm_publish_date_from',
      'publish_date_to' => 'lm_publish_date_to',
      'updated_date_from' => 'lm_updated_date_from',
      'updated_date_to' => 'lm_updated_date_to',
      'anchor' => 'lm_anchor',
      'quality' => 'lm_quality',
      'seo_flag' => 'lm_seo_flag',
      'alt' => 'lm_alt',
      'rel' => 'lm_rel',
      'text_mode' => 'lm_text_mode',
      'rel_nofollow' => 'lm_rel_nofollow',
      'rel_sponsored' => 'lm_rel_sponsored',
      'rel_ugc' => 'lm_rel_ugc',
      'orderby' => 'lm_orderby',
      'order' => 'lm_order',
      'cursor' => 'lm_cursor',
      'rebuild' => 'lm_rebuild',
      'paged' => 'lm_paged',
      'per_page' => 'lm_per_page',
    ];

    $overrides = $this->build_request_overrides_from_map($request, $map);
    return rest_ensure_response($this->with_request_overrides($overrides, function() {
      $filters = $this->get_filters_from_request();

      $scopePostType = sanitize_key((string)($filters['post_type'] ?? 'any'));
      if ($scopePostType === '') {
        $scopePostType = 'any';
      }
      $scopeWpmlLang = $this->get_effective_scan_wpml_lang((string)($filters['wpml_lang'] ?? 'all'));
      $rebuildRequested = !empty($filters['rebuild']);
      $cacheStamp = (string)get_option($this->cache_scan_option_key($scopePostType, $scopeWpmlLang), '');
      $cacheKey = $this->build_rest_response_cache_key('editor_list', [
        'filters' => $filters,
        'cache_stamp' => $cacheStamp,
      ]);

      if (!$rebuildRequested) {
        $cachedResponse = $this->get_rest_response_cache($cacheKey);
        if (is_array($cachedResponse)
          && isset($cachedResponse['items'])
          && isset($cachedResponse['pagination'])
          && is_array($cachedResponse['items'])
          && is_array($cachedResponse['pagination'])) {
          return $this->attach_rest_execution_meta($cachedResponse, 'editor_list', 'response_cache_hit', true);
        }
      }

      if (!$rebuildRequested) {
        $indexedFastResponse = $this->get_indexed_editor_list_fastpath_response($scopePostType, $scopeWpmlLang, $filters);
        if (is_array($indexedFastResponse)
          && isset($indexedFastResponse['items'])
          && isset($indexedFastResponse['pagination'])
          && is_array($indexedFastResponse['items'])
          && is_array($indexedFastResponse['pagination'])) {
          $indexedFastResponse = $this->attach_rest_execution_meta($indexedFastResponse, 'editor_list', 'indexed_sql_fastpath', false);
          $this->set_rest_response_cache($cacheKey, $indexedFastResponse);
          return $indexedFastResponse;
        }
      }

      $all = null;
      $executionMode = 'cache_scan_fallback';
      $usedIndexedPrefilter = false;
      $usedIndexedAuthority = false;
      $usedExistingCache = false;
      $usedRebuild = false;
      if (!$rebuildRequested && $this->is_indexed_datastore_ready()) {
        $all = $this->get_indexed_fact_rows($scopePostType, $scopeWpmlLang, $filters);
        if (is_array($all) && !empty($all)) {
          $usedIndexedAuthority = true;
          $usedIndexedPrefilter = true;
        }
        if (!$usedIndexedAuthority && ($scopePostType !== 'any' || $scopeWpmlLang !== 'all')) {
          $all = $this->get_indexed_fact_rows('any', 'all', $filters);
          if (is_array($all) && !empty($all)) {
            $usedIndexedAuthority = true;
            $usedIndexedPrefilter = true;
          }
        }
      }

      if (!is_array($all)) {
        $all = null;
      }
      if (empty($all) && !$rebuildRequested && !$usedIndexedAuthority) {
        $all = $this->get_existing_cache_rows_for_rest($scopePostType, $scopeWpmlLang, true);
        if (is_array($all)) {
          $usedExistingCache = true;
        }
      }
      if (!is_array($all)) {
        $all = $this->get_canonical_rows_for_scope(
          $scopePostType,
          $rebuildRequested,
          $scopeWpmlLang,
          $filters
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
      $rows = $this->apply_filters_and_group($all, $filters);
      $cursor = $this->decode_editor_keyset_cursor((string)($filters['cursor'] ?? ''));
      $orderby = isset($filters['orderby']) ? (string)$filters['orderby'] : 'date';
      $isAsc = ((string)($filters['order'] ?? 'DESC') === 'ASC');
      if (is_array($cursor)) {
        $cursorValueRaw = (string)$cursor['order'];
        $cursorPostId = (int)$cursor['post_id'];
        $cursorRowId = (int)$cursor['row_id'];
        $rows = array_values(array_filter($rows, function($row) use ($orderby, $isAsc, $cursorValueRaw, $cursorPostId, $cursorRowId) {
          $meta = $this->get_editor_sort_meta_for_cursor((array)$row, $orderby);
          $rowValue = $meta['numeric'] ? (int)$meta['value'] : (string)$meta['value'];
          $cursorValue = $meta['numeric'] ? (int)$cursorValueRaw : (string)$cursorValueRaw;
          $cmp = $meta['numeric'] ? (((int)$rowValue <=> (int)$cursorValue)) : strcmp((string)$rowValue, (string)$cursorValue);
          if ($cmp === 0) {
            $cmp = ((int)($row['post_id'] ?? 0) <=> $cursorPostId);
          }
          if ($cmp === 0) {
            $cmp = ((int)($row['row_id'] ?? 0) <=> $cursorRowId);
          }
          return $isAsc ? ($cmp > 0) : ($cmp < 0);
        }));
      }

      $total = count($rows);
      $perPage = max(10, (int)$filters['per_page']);
      $paged = is_array($cursor) ? 1 : max(1, (int)$filters['paged']);
      $totalPages = max(1, (int)ceil($total / $perPage));
      if ($paged > $totalPages) {
        $paged = $totalPages;
      }

      $offset = ($paged - 1) * $perPage;
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

      $response = [
        'items' => array_values($pageRows),
        'pagination' => [
          'total' => $total,
          'per_page' => $perPage,
          'paged' => $paged,
          'total_pages' => $totalPages,
          'next_cursor' => $nextCursor,
        ],
      ];
      $response = $this->attach_rest_execution_meta($response, 'editor_list', $executionMode, false);

      if (!$rebuildRequested) {
        $this->set_rest_response_cache($cacheKey, $response);
      }

      return $response;
    }));
  }

  private function build_self_test_result_row($name, $ok, $durationMs, $details) {
    return [
      'name' => sanitize_text_field((string)$name),
      'status' => $ok ? 'pass' : 'fail',
      'ok' => (bool)$ok,
      'duration_ms' => max(0, (int)$durationMs),
      'details' => sanitize_text_field((string)$details),
    ];
  }

  private function normalize_rest_response_data($response) {
    if ($response instanceof WP_REST_Response) {
      return $response->get_data();
    }
    if (is_array($response)) {
      return $response;
    }
    return [];
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

  private function get_rest_execution_mode_from_response($responseData, $default = 'unknown') {
    if (!is_array($responseData)) {
      return sanitize_key((string)$default);
    }
    $meta = isset($responseData['meta']) && is_array($responseData['meta']) ? $responseData['meta'] : [];
    $mode = isset($meta['execution_mode']) ? sanitize_key((string)$meta['execution_mode']) : '';
    if ($mode === '') {
      $mode = sanitize_key((string)$default);
    }
    return $mode === '' ? 'unknown' : $mode;
  }

  private function summarize_rest_execution_modes($modes) {
    $counts = $this->count_rest_execution_modes($modes);
    if (empty($counts)) {
      return 'mode n/a';
    }

    $parts = [];
    foreach ($counts as $mode => $count) {
      $parts[] = $mode . ' x' . (int)$count;
    }
    return 'mode ' . implode(', ', $parts);
  }

  private function count_rest_execution_modes($modes) {
    $counts = [];
    foreach ((array)$modes as $mode) {
      $key = sanitize_key((string)$mode);
      if ($key === '') {
        $key = 'unknown';
      }
      if (!isset($counts[$key])) {
        $counts[$key] = 0;
      }
      $counts[$key]++;
    }

    ksort($counts);
    return $counts;
  }

  public function rest_self_test_run($request) {
    $suiteStartedAt = microtime(true);
    $sampleSize = (int)$request->get_param('sample_size');
    if ($sampleSize < 1) {
      $sampleSize = 1;
    }
    if ($sampleSize > 3) {
      $sampleSize = 3;
    }

    $results = [];

    $t = microtime(true);
    $routes = rest_get_server()->get_routes();
    $requiredRoutes = [
      '/links-manager/v1/rebuild/start',
      '/links-manager/v1/rebuild/status',
      '/links-manager/v1/rebuild/step',
      '/links-manager/v1/pages-link/list',
      '/links-manager/v1/editor/list',
      '/links-manager/v1/self-test/run',
    ];
    $missingRoutes = [];
    foreach ($requiredRoutes as $routePath) {
      if (!isset($routes[$routePath])) {
        $missingRoutes[] = $routePath;
      }
    }
    $results[] = $this->build_self_test_result_row(
      'REST routes registered',
      empty($missingRoutes),
      round((microtime(true) - $t) * 1000),
      empty($missingRoutes) ? 'All required routes are available.' : ('Missing: ' . implode(', ', $missingRoutes))
    );

    $t = microtime(true);
    $state = $this->get_public_rebuild_job_state($this->get_rebuild_job_state());
    $stateOk = is_array($state) && !empty((string)($state['status'] ?? ''));
    $results[] = $this->build_self_test_result_row(
      'Rebuild state readable',
      $stateOk,
      round((microtime(true) - $t) * 1000),
      $stateOk ? ('Status: ' . (string)$state['status']) : 'Rebuild state is invalid.'
    );

    $t = microtime(true);
    $lockAcquired = $this->acquire_rebuild_job_lock(10);
    if ($lockAcquired) {
      $this->release_rebuild_job_lock();
    }
    $results[] = $this->build_self_test_result_row(
      'Rebuild lock cycle',
      $lockAcquired,
      round((microtime(true) - $t) * 1000),
      $lockAcquired ? 'Lock acquire/release succeeded.' : 'Lock is currently busy (another step may be running).'
    );

    $t = microtime(true);
    $probeKey = 'lm_self_test_' . substr(wp_hash((string)microtime(true) . wp_rand()), 0, 12);
    $probePayload = [
      'time' => current_time('mysql'),
      'salt' => wp_rand(1000, 9999),
    ];
    set_transient($probeKey, $probePayload, 60);
    $probeRead = get_transient($probeKey);
    delete_transient($probeKey);
    $transientOk = is_array($probeRead)
      && isset($probeRead['time'])
      && isset($probeRead['salt'])
      && (string)$probeRead['time'] === (string)$probePayload['time']
      && (int)$probeRead['salt'] === (int)$probePayload['salt'];
    $results[] = $this->build_self_test_result_row(
      'Transient read/write',
      $transientOk,
      round((microtime(true) - $t) * 1000),
      $transientOk ? 'Transient storage works.' : 'Transient probe mismatch detected.'
    );

    $t = microtime(true);
    $cacheScanAnyAll = (string)get_option($this->cache_scan_option_key('any', 'all'), '');
    $cacheStampOk = $cacheScanAnyAll !== '';
    $results[] = $this->build_self_test_result_row(
      'Cache metadata available',
      $cacheStampOk,
      round((microtime(true) - $t) * 1000),
      $cacheStampOk ? ('Last any/all scan: ' . $cacheScanAnyAll) : 'No any/all cache scan timestamp found yet.'
    );

    $t = microtime(true);
    $probeIds = [];
    $probeOk = true;
    $probeDetail = '';
    try {
      $enabledPostTypes = $this->get_enabled_scan_post_types();
      if (empty($enabledPostTypes)) {
        $probeOk = false;
        $probeDetail = 'No enabled post type configured.';
      } else {
        $probeIds = $this->query_cache_post_ids_chunk($enabledPostTypes, 'all', '', 0, $sampleSize);
        $probeOk = is_array($probeIds);
        $probeDetail = 'Sampled ' . count($probeIds) . ' post id(s) from configured post types.';
      }
    } catch (Throwable $e) {
      $probeOk = false;
      $probeDetail = 'Exception: ' . sanitize_text_field($e->getMessage());
    }
    $results[] = $this->build_self_test_result_row(
      'Post query probe (light)',
      $probeOk,
      round((microtime(true) - $t) * 1000),
      $probeDetail
    );

    $t = microtime(true);
    $results[] = $this->build_self_test_result_row(
      'Heavy probes intentionally skipped',
      true,
      round((microtime(true) - $t) * 1000),
      'List endpoint probes are skipped in self-test to avoid heavy cache rebuild on large sites.'
    );

    $total = count($results);
    $failed = 0;
    foreach ($results as $resultRow) {
      if (empty($resultRow['ok'])) {
        $failed++;
      }
    }
    $passed = max(0, $total - $failed);
    $suiteStatus = $failed > 0 ? 'fail' : 'pass';

    return rest_ensure_response([
      'status' => $suiteStatus,
      'generated_at' => current_time('mysql'),
      'summary' => [
        'total' => $total,
        'passed' => $passed,
        'failed' => $failed,
        'duration_ms' => max(0, (int)round((microtime(true) - $suiteStartedAt) * 1000)),
      ],
      'results' => $results,
    ]);
  }

  private function percentile_ms_value($samples, $percentile) {
    $values = array_values(array_filter(array_map('intval', (array)$samples), function($v) {
      return $v >= 0;
    }));
    if (empty($values)) {
      return 0;
    }

    sort($values, SORT_NUMERIC);
    $p = max(0.0, min(1.0, (float)$percentile));
    $index = (int)ceil(($p * count($values)) - 1);
    if ($index < 0) {
      $index = 0;
    }
    if ($index >= count($values)) {
      $index = count($values) - 1;
    }

    return (int)$values[$index];
  }

  private function deep_probe_result_row($name, $durations, $requestedRuns, $errors = [], $extraDetail = '', $coldMs = -1) {
    $durations = array_values(array_map('intval', (array)$durations));
    $errors = array_values(array_filter(array_map('sanitize_text_field', (array)$errors), function($msg) {
      return (string)$msg !== '';
    }));
    $ok = empty($errors) && !empty($durations);

    $executed = count($durations);
    $avg = $executed > 0 ? (int)round(array_sum($durations) / $executed) : 0;
    $p50 = $this->percentile_ms_value($durations, 0.50);
    $p95 = $this->percentile_ms_value($durations, 0.95);
    $max = $executed > 0 ? max($durations) : 0;

    $detail = 'runs ' . $executed . '/' . max(1, (int)$requestedRuns)
      . ' | avg ' . $avg . ' ms'
      . ' | p50 ' . $p50 . ' ms'
      . ' | p95 ' . $p95 . ' ms'
      . ' | max ' . $max . ' ms';
    if ((int)$coldMs >= 0) {
      $detail .= ' | cold ' . (int)$coldMs . ' ms';
    }
    if ((string)$extraDetail !== '') {
      $detail .= ' | ' . sanitize_text_field((string)$extraDetail);
    }
    if (!empty($errors)) {
      $detail .= ' | error: ' . (string)$errors[0];
    }

    $durationMs = $executed > 0 ? max($durations) : 0;
    return $this->build_self_test_result_row($name, $ok, $durationMs, $detail);
  }

  public function rest_self_test_deep($request) {
    $suiteStartedAt = microtime(true);
    $iterations = (int)$request->get_param('iterations');
    if ($iterations < 1) {
      $iterations = 3;
    }
    if ($iterations > 5) {
      $iterations = 5;
    }

    $perPage = (int)$request->get_param('per_page');
    if ($perPage < 5) {
      $perPage = 20;
    }
    if ($perPage > 50) {
      $perPage = 50;
    }

    $results = [];
    $pagesModeCounts = [];
    $editorModeCounts = [];

    $mainRows = get_transient($this->cache_key('any', 'all'));
    $backupRows = get_transient($this->cache_backup_key('any', 'all'));
    $mainCount = is_array($mainRows) ? count($mainRows) : 0;
    $backupCount = is_array($backupRows) ? count($backupRows) : 0;
    $indexedCount = $this->get_indexed_fact_count('any', 'all');
    $effectiveCount = max($mainCount, $backupCount, $indexedCount);
    $cacheReady = $effectiveCount > 0;
    $cacheSources = [];
    if ($mainCount > 0) {
      $cacheSources[] = 'transient_main=' . number_format($mainCount);
    }
    if ($backupCount > 0) {
      $cacheSources[] = 'transient_backup=' . number_format($backupCount);
    }
    if ($indexedCount > 0) {
      $cacheSources[] = 'indexed_fact=' . number_format($indexedCount);
    }
    $cacheSourceDetail = !empty($cacheSources) ? (' Sources: ' . implode(', ', $cacheSources) . '.') : '';
    $results[] = $this->build_self_test_result_row(
      'Cache readiness for deep test',
      $cacheReady,
      0,
      $cacheReady
        ? ('Effective cache rows: ' . number_format($effectiveCount) . '.' . $cacheSourceDetail)
        : 'No cache rows found for any/all (transient + indexed). Run REST rebuild start/continue first to avoid heavy on-demand rebuild during deep test.'
    );

    if ($cacheReady) {
      $deadline = microtime(true) + 45.0;

      $pagesDurations = [];
      $pagesErrors = [];
      $pagesColdMs = -1;
      $pagesModes = [];
      $pagesCacheStatsBefore = $this->get_rest_response_cache_stats();
      $pagesWarmupStartedAt = microtime(true);
      try {
        $pagesWarmupRequest = new WP_REST_Request('GET', '/links-manager/v1/pages-link/list');
        $pagesWarmupRequest->set_param('post_type', 'any');
        $pagesWarmupRequest->set_param('wpml_lang', 'all');
        $pagesWarmupRequest->set_param('paged', 1);
        $pagesWarmupRequest->set_param('per_page', $perPage);
        $pagesWarmupRequest->set_param('rebuild', 0);
        $warmupData = $this->normalize_rest_response_data($this->rest_pages_link_list($pagesWarmupRequest));
        $warmupItems = is_array($warmupData['items'] ?? null) ? $warmupData['items'] : null;
        $warmupPagination = is_array($warmupData['pagination'] ?? null) ? $warmupData['pagination'] : null;
        if ($warmupItems === null || $warmupPagination === null) {
          throw new RuntimeException('Invalid schema from pages-link/list warm-up.');
        }
        $pagesModes[] = $this->get_rest_execution_mode_from_response($warmupData, 'unknown');
        $pagesColdMs = (int)round((microtime(true) - $pagesWarmupStartedAt) * 1000);
      } catch (Throwable $e) {
        $pagesErrors[] = sanitize_text_field($e->getMessage());
      }
      for ($i = 0; $i < $iterations; $i++) {
        if (!empty($pagesErrors)) {
          break;
        }
        if (microtime(true) >= $deadline) {
          $pagesErrors[] = 'Budget exceeded (45s).';
          break;
        }
        $startedAt = microtime(true);
        try {
          $pagesRequest = new WP_REST_Request('GET', '/links-manager/v1/pages-link/list');
          $pagesRequest->set_param('post_type', 'any');
          $pagesRequest->set_param('wpml_lang', 'all');
          $pagesRequest->set_param('paged', 1);
          $pagesRequest->set_param('per_page', $perPage);
          $pagesRequest->set_param('rebuild', 0);
          $pagesData = $this->normalize_rest_response_data($this->rest_pages_link_list($pagesRequest));
          $items = is_array($pagesData['items'] ?? null) ? $pagesData['items'] : null;
          $pagination = is_array($pagesData['pagination'] ?? null) ? $pagesData['pagination'] : null;
          if ($items === null || $pagination === null) {
            throw new RuntimeException('Invalid schema from pages-link/list.');
          }
          $pagesModes[] = $this->get_rest_execution_mode_from_response($pagesData, 'unknown');
        } catch (Throwable $e) {
          $pagesErrors[] = sanitize_text_field($e->getMessage());
          break;
        }
        $pagesDurations[] = (int)round((microtime(true) - $startedAt) * 1000);
      }
      $pagesCacheStatsAfter = $this->get_rest_response_cache_stats();
      $pagesHits = max(0, (int)$pagesCacheStatsAfter['hits'] - (int)$pagesCacheStatsBefore['hits']);
      $pagesMisses = max(0, (int)$pagesCacheStatsAfter['misses'] - (int)$pagesCacheStatsBefore['misses']);
      $pagesTotalCache = $pagesHits + $pagesMisses;
      $pagesHitRate = $pagesTotalCache > 0 ? round(($pagesHits / $pagesTotalCache) * 100, 1) : 0;
      $pagesModeDetail = $this->summarize_rest_execution_modes($pagesModes);
      $pagesModeCounts = $this->count_rest_execution_modes($pagesModes);
      $pagesCacheDetail = 'cache hit ' . $pagesHits . ', miss ' . $pagesMisses . ', hit-rate ' . $pagesHitRate . '% | ' . $pagesModeDetail;
      $results[] = $this->deep_probe_result_row('Pages Link list deep probe', $pagesDurations, $iterations, $pagesErrors, $pagesCacheDetail, $pagesColdMs);

      $editorDurations = [];
      $editorErrors = [];
      $editorColdMs = -1;
      $editorModes = [];
      $editorCacheStatsBefore = $this->get_rest_response_cache_stats();
      $editorWarmupStartedAt = microtime(true);
      try {
        $editorWarmupRequest = new WP_REST_Request('GET', '/links-manager/v1/editor/list');
        $editorWarmupRequest->set_param('post_type', 'any');
        $editorWarmupRequest->set_param('wpml_lang', 'all');
        $editorWarmupRequest->set_param('paged', 1);
        $editorWarmupRequest->set_param('per_page', $perPage);
        $editorWarmupRequest->set_param('rebuild', 0);
        $warmupData = $this->normalize_rest_response_data($this->rest_editor_list($editorWarmupRequest));
        $warmupItems = is_array($warmupData['items'] ?? null) ? $warmupData['items'] : null;
        $warmupPagination = is_array($warmupData['pagination'] ?? null) ? $warmupData['pagination'] : null;
        if ($warmupItems === null || $warmupPagination === null) {
          throw new RuntimeException('Invalid schema from editor/list warm-up.');
        }
        $editorModes[] = $this->get_rest_execution_mode_from_response($warmupData, 'unknown');
        $editorColdMs = (int)round((microtime(true) - $editorWarmupStartedAt) * 1000);
      } catch (Throwable $e) {
        $editorErrors[] = sanitize_text_field($e->getMessage());
      }
      for ($i = 0; $i < $iterations; $i++) {
        if (!empty($editorErrors)) {
          break;
        }
        if (microtime(true) >= $deadline) {
          $editorErrors[] = 'Budget exceeded (45s).';
          break;
        }
        $startedAt = microtime(true);
        try {
          $editorRequest = new WP_REST_Request('GET', '/links-manager/v1/editor/list');
          $editorRequest->set_param('post_type', 'any');
          $editorRequest->set_param('wpml_lang', 'all');
          $editorRequest->set_param('paged', 1);
          $editorRequest->set_param('per_page', $perPage);
          $editorRequest->set_param('rebuild', 0);
          $editorData = $this->normalize_rest_response_data($this->rest_editor_list($editorRequest));
          $items = is_array($editorData['items'] ?? null) ? $editorData['items'] : null;
          $pagination = is_array($editorData['pagination'] ?? null) ? $editorData['pagination'] : null;
          if ($items === null || $pagination === null) {
            throw new RuntimeException('Invalid schema from editor/list.');
          }
          $editorModes[] = $this->get_rest_execution_mode_from_response($editorData, 'unknown');
        } catch (Throwable $e) {
          $editorErrors[] = sanitize_text_field($e->getMessage());
          break;
        }
        $editorDurations[] = (int)round((microtime(true) - $startedAt) * 1000);
      }
      $editorCacheStatsAfter = $this->get_rest_response_cache_stats();
      $editorHits = max(0, (int)$editorCacheStatsAfter['hits'] - (int)$editorCacheStatsBefore['hits']);
      $editorMisses = max(0, (int)$editorCacheStatsAfter['misses'] - (int)$editorCacheStatsBefore['misses']);
      $editorTotalCache = $editorHits + $editorMisses;
      $editorHitRate = $editorTotalCache > 0 ? round(($editorHits / $editorTotalCache) * 100, 1) : 0;
      $editorModeDetail = $this->summarize_rest_execution_modes($editorModes);
      $editorModeCounts = $this->count_rest_execution_modes($editorModes);
      $editorCacheDetail = 'cache hit ' . $editorHits . ', miss ' . $editorMisses . ', hit-rate ' . $editorHitRate . '% | ' . $editorModeDetail;
      $results[] = $this->deep_probe_result_row('Editor list deep probe', $editorDurations, $iterations, $editorErrors, $editorCacheDetail, $editorColdMs);
    }

    $total = count($results);
    $failed = 0;
    foreach ($results as $resultRow) {
      if (empty($resultRow['ok'])) {
        $failed++;
      }
    }
    $passed = max(0, $total - $failed);
    $suiteStatus = $failed > 0 ? 'fail' : 'pass';

    return rest_ensure_response([
      'status' => $suiteStatus,
      'mode' => 'deep',
      'generated_at' => current_time('mysql'),
      'summary' => [
        'total' => $total,
        'passed' => $passed,
        'failed' => $failed,
        'duration_ms' => max(0, (int)round((microtime(true) - $suiteStartedAt) * 1000)),
        'iterations' => $iterations,
        'per_page' => $perPage,
        'execution_modes' => [
          'pages_link_list' => $pagesModeCounts,
          'editor_list' => $editorModeCounts,
        ],
      ],
      'results' => $results,
    ]);
  }
}
