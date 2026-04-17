<?php
/**
 * Cache rebuild orchestration and incremental refresh helpers.
 */

if (!defined('ABSPATH')) {
  exit;
}

trait LM_Cache_Rebuild_Trait {
  private function rebuild_step_worker_args() {
    return [get_current_blog_id()];
  }

  private function background_rebuild_event_args($scope_post_type = 'any', $wpml_lang = 'all', $refreshMode = 'full_rebuild') {
    $scope_post_type = sanitize_key((string)$scope_post_type);
    if ($scope_post_type === '') {
      $scope_post_type = 'any';
    }
    $wpml_lang = $this->normalize_rebuild_wpml_lang((string)$wpml_lang);
    $refreshMode = sanitize_key((string)$refreshMode);
    if (!in_array($refreshMode, ['changed_only', 'full_rebuild'], true)) {
      $refreshMode = 'full_rebuild';
    }

    return [$scope_post_type, $wpml_lang, $refreshMode];
  }

  private function rest_list_prewarm_event_args($scope_post_type = 'any', $wpml_lang = 'all') {
    return $this->background_rebuild_event_args($scope_post_type, $wpml_lang);
  }

  private function schedule_rebuild_step_worker($delaySeconds = 1) {
    $delaySeconds = max(1, (int)$delaySeconds);
    $args = $this->rebuild_step_worker_args();
    if (!wp_next_scheduled('lm_rebuild_step_worker', $args)) {
      wp_schedule_single_event(time() + $delaySeconds, 'lm_rebuild_step_worker', $args);
    }
  }

  public function ensure_active_rebuild_step_worker() {
    $state = $this->get_rebuild_job_state();
    $status = sanitize_key((string)($state['status'] ?? 'idle'));
    if (!in_array($status, ['running', 'finalizing'], true)) {
      return;
    }

    $args = $this->rebuild_step_worker_args();
    if (!wp_next_scheduled('lm_rebuild_step_worker', $args)) {
      $state['worker_scheduled'] = '1';
      $state['updated_at'] = current_time('mysql');
      $this->save_rebuild_job_state($state);
      $this->schedule_rebuild_step_worker(1);
    }
  }

  public function run_rebuild_step_worker($blogId = 0) {
    $state = $this->get_rebuild_job_state();
    if (!is_array($state) || empty($state)) {
      return;
    }

    $status = sanitize_key((string)($state['status'] ?? 'idle'));
    if (!in_array($status, ['running', 'finalizing'], true)) {
      $state['worker_scheduled'] = '0';
      $state['last_worker_completed_at'] = current_time('mysql');
      $state['updated_at'] = current_time('mysql');
      $this->save_rebuild_job_state($state);
      return;
    }

    $state['execution_mode'] = 'background';
    $state['worker_scheduled'] = '0';
    $state['last_worker_started_at'] = current_time('mysql');
    $state['updated_at'] = current_time('mysql');
    $this->save_rebuild_job_state($state);

    $request = new WP_REST_Request('POST', '/links-manager/v1/rebuild/step');
    $request->set_param('batch', 0);
    $this->rest_rebuild_step($request);
  }

  private function get_auto_refresh_timezone_offset_string($offsetHours = null) {
    $offsetHours = is_numeric($offsetHours) ? (float)$offsetHours : (float)get_option('gmt_offset', 0);
    $offsetMinutesTotal = (int)round($offsetHours * 60);
    $sign = ($offsetMinutesTotal < 0) ? '-' : '+';
    $offsetMinutesTotal = abs($offsetMinutesTotal);
    $hours = (int)floor($offsetMinutesTotal / 60);
    $minutes = (int)($offsetMinutesTotal % 60);

    return sprintf('%s%02d:%02d', $sign, $hours, $minutes);
  }

  private function count_cache_post_ids($post_types, $wpml_lang = 'all', $modified_after_gmt = '') {
    $post_types = array_values(array_unique(array_map('sanitize_key', (array)$post_types)));
    if (empty($post_types)) {
      return 0;
    }

    $scanAuthorIds = $this->get_enabled_scan_author_ids();
    $effectiveAfterGmt = $this->get_scan_modified_after_gmt($modified_after_gmt);

    $queryArgs = [
      'post_type' => $post_types,
      'post_status' => 'publish',
      'posts_per_page' => 1,
      'fields' => 'ids',
      'suppress_filters' => false,
      'no_found_rows' => false,
      'orderby' => 'ID',
      'order' => 'ASC',
      'update_post_meta_cache' => false,
      'update_post_term_cache' => false,
      'cache_results' => false,
    ];

    if (!empty($scanAuthorIds)) {
      $queryArgs['author__in'] = array_values(array_map('intval', $scanAuthorIds));
    }

    if ($this->is_wpml_active()) {
      $queryArgs['lang'] = ($wpml_lang === 'all') ? '' : $wpml_lang;
    }

    if ($effectiveAfterGmt !== '') {
      $queryArgs['date_query'] = [[
        'column' => 'post_modified_gmt',
        'after' => $effectiveAfterGmt,
        'inclusive' => false,
      ]];
    }

    $globalTaxQuery = $this->get_global_scan_tax_query($post_types);
    if (!empty($globalTaxQuery)) {
      $queryArgs['tax_query'] = $globalTaxQuery;
    }

    $q = $this->run_wpml_scan_query_in_language_context($wpml_lang, function() use ($queryArgs) {
      return new WP_Query($queryArgs);
    });
    return max(0, (int)$q->found_posts);
  }

  private function query_cache_post_ids($post_types, $wpml_lang = 'all', $modified_after_gmt = '') {
    $post_types = array_values(array_unique(array_map('sanitize_key', (array)$post_types)));
    $scanAuthorIds = $this->get_enabled_scan_author_ids();
    $effectiveAfterGmt = $this->get_scan_modified_after_gmt($modified_after_gmt);

    $queryArgs = [
      'post_type' => $post_types,
      'post_status' => 'publish',
      'posts_per_page' => -1,
      'fields' => 'ids',
      'suppress_filters' => false,
      'no_found_rows' => true,
      'orderby' => 'ID',
      'order' => 'ASC',
      'update_post_meta_cache' => false,
      'update_post_term_cache' => false,
      'cache_results' => false,
    ];

    if (!empty($scanAuthorIds)) {
      $queryArgs['author__in'] = array_values(array_map('intval', $scanAuthorIds));
    }

    if ($this->is_wpml_active()) {
      $queryArgs['lang'] = ($wpml_lang === 'all') ? '' : $wpml_lang;
    }

    if ($effectiveAfterGmt !== '') {
      $queryArgs['date_query'] = [[
        'column' => 'post_modified_gmt',
        'after' => $effectiveAfterGmt,
        'inclusive' => false,
      ]];
    }

    $globalTaxQuery = $this->get_global_scan_tax_query($post_types);
    if (!empty($globalTaxQuery)) {
      $queryArgs['tax_query'] = $globalTaxQuery;
    }

    $q = $this->run_wpml_scan_query_in_language_context($wpml_lang, function() use ($queryArgs) {
      return new WP_Query($queryArgs);
    });
    if (empty($q->posts)) return [];

    return array_values(array_unique(array_map('intval', (array)$q->posts)));
  }

  private function query_cache_post_ids_chunk($post_types, $wpml_lang = 'all', $modified_after_gmt = '', $after_post_id = 0, $limit = 100) {
    $post_types = array_values(array_unique(array_map('sanitize_key', (array)$post_types)));
    if (empty($post_types)) {
      return [];
    }

    $scanAuthorIds = $this->get_enabled_scan_author_ids();
    $effectiveAfterGmt = $this->get_scan_modified_after_gmt($modified_after_gmt);
    $afterPostId = max(0, (int)$after_post_id);
    $limit = max(1, (int)$limit);

    $queryArgs = [
      'post_type' => $post_types,
      'post_status' => 'publish',
      'posts_per_page' => $limit,
      'fields' => 'ids',
      'suppress_filters' => false,
      'no_found_rows' => true,
      'orderby' => 'ID',
      'order' => 'ASC',
      'update_post_meta_cache' => false,
      'update_post_term_cache' => false,
      'cache_results' => false,
    ];

    if (!empty($scanAuthorIds)) {
      $queryArgs['author__in'] = array_values(array_map('intval', $scanAuthorIds));
    }

    if ($this->is_wpml_active()) {
      $queryArgs['lang'] = ($wpml_lang === 'all') ? '' : $wpml_lang;
    }

    if ($effectiveAfterGmt !== '') {
      $queryArgs['date_query'] = [[
        'column' => 'post_modified_gmt',
        'after' => $effectiveAfterGmt,
        'inclusive' => false,
      ]];
    }

    $globalTaxQuery = $this->get_global_scan_tax_query($post_types);
    if (!empty($globalTaxQuery)) {
      $queryArgs['tax_query'] = $globalTaxQuery;
    }

    $idWhereFilter = null;
    if ($afterPostId > 0) {
      global $wpdb;
      $idWhereFilter = function($where) use ($wpdb, $afterPostId) {
        return $where . $wpdb->prepare(" AND {$wpdb->posts}.ID > %d", $afterPostId);
      };
      add_filter('posts_where', $idWhereFilter, 10, 1);
    }

    try {
      $q = $this->run_wpml_scan_query_in_language_context($wpml_lang, function() use ($queryArgs) {
        return new WP_Query($queryArgs);
      });
    } finally {
      if ($idWhereFilter !== null) {
        remove_filter('posts_where', $idWhereFilter, 10);
      }
    }
    if (empty($q->posts)) return [];

    return array_values(array_unique(array_map('intval', (array)$q->posts)));
  }

  private function get_post_modified_gmt_map($postIds) {
    $postIds = array_values(array_unique(array_filter(array_map('intval', (array)$postIds), function($id) {
      return $id > 0;
    })));
    if (empty($postIds)) {
      return [];
    }

    global $wpdb;
    $placeholders = implode(',', array_fill(0, count($postIds), '%d'));
    $sql = "SELECT ID, post_modified_gmt FROM {$wpdb->posts} WHERE ID IN ($placeholders)";
    $rows = $wpdb->get_results($wpdb->prepare($sql, $postIds));
    if (empty($rows)) {
      return [];
    }

    $out = [];
    foreach ((array)$rows as $row) {
      $id = isset($row->ID) ? (int)$row->ID : 0;
      if ($id < 1) {
        continue;
      }
      $out[$id] = isset($row->post_modified_gmt) ? (string)$row->post_modified_gmt : '';
    }

    return $out;
  }

  private function get_cache_rebuild_post_data_map($postIds) {
    $postIds = array_values(array_unique(array_filter(array_map('intval', (array)$postIds), function($id) {
      return $id > 0;
    })));
    if (empty($postIds)) {
      return [];
    }

    global $wpdb;
    $placeholders = implode(',', array_fill(0, count($postIds), '%d'));
    $sql = "SELECT ID, post_status, post_content, post_excerpt, post_title, post_type, post_date, post_modified, post_author\n"
      . "FROM {$wpdb->posts}\n"
      . "WHERE ID IN ($placeholders)";
    $rows = $wpdb->get_results($wpdb->prepare($sql, $postIds));
    if (empty($rows)) {
      return [];
    }

    $out = [];
    foreach ((array)$rows as $row) {
      $id = isset($row->ID) ? (int)$row->ID : 0;
      if ($id < 1) {
        continue;
      }
      $out[$id] = $row;
    }

    return $out;
  }

  private function build_incremental_cache_from_backup($backupRows, $post_types, $wpml_lang, $last_scan_gmt, $enabledSources = null) {
    if (!is_array($backupRows) || $last_scan_gmt === '') {
      return null;
    }

    if (!is_array($enabledSources)) {
      $enabledSources = $this->get_enabled_scan_source_types();
    }

    $enabledSourcesMap = [];
    foreach ((array)$enabledSources as $src) {
      $enabledSourcesMap[sanitize_key((string)$src)] = true;
    }

    $rowsByPostId = [];
    foreach ($backupRows as $row) {
      if ((string)($row['source'] ?? '') === 'menu') continue;
      $rowSource = sanitize_key((string)($row['source'] ?? ''));
      if ($rowSource !== '' && !isset($enabledSourcesMap[$rowSource])) continue;
      $postId = (int)($row['post_id'] ?? 0);
      if ($postId < 1) continue;
      if (!isset($rowsByPostId[$postId])) $rowsByPostId[$postId] = [];
      $rowsByPostId[$postId][] = $row;
    }

    $currentPostIds = $this->query_cache_post_ids($post_types, $wpml_lang, '');
    $changedMap = [];
    if (!empty($currentPostIds)) {
      $modifiedMap = $this->get_post_modified_gmt_map($currentPostIds);
      foreach ($currentPostIds as $pid) {
        $pid = (int)$pid;
        if ($pid < 1) {
          continue;
        }
        $modifiedGmt = isset($modifiedMap[$pid]) ? (string)$modifiedMap[$pid] : '';
        if ($modifiedGmt === '' || $modifiedGmt > $last_scan_gmt) {
          $changedMap[$pid] = true;
        }
      }
    }

    $all = [];
    $maxPosts = $this->get_max_posts_per_rebuild();
    $processedPosts = 0;
    foreach ($currentPostIds as $postId) {
      if ($maxPosts > 0 && $processedPosts >= $maxPosts) {
        break;
      }

      $postId = (int)$postId;
      if ($postId < 1) continue;

      if (isset($changedMap[$postId]) || !isset($rowsByPostId[$postId])) {
        $this->append_rows($all, $this->crawl_post($postId, $enabledSources));
      } else {
        $this->append_rows($all, $rowsByPostId[$postId]);
      }

      $processedPosts++;
    }

    $this->append_rows($all, $this->crawl_menus($enabledSources));
    return $all;
  }

  private function crawl_rows_for_rebuild_lang_queue($post_types, $crawlLangQueue, $scanModifiedAfterGmt, $enabledSources, $maxPostsPerRebuild, $chunkSize) {
    $all = [];
    $processedPosts = 0;
    $crawlAborted = false;
    $completedLangs = [];
    $crawlStartedAt = microtime(true);

    foreach ((array)$crawlLangQueue as $crawlLang) {
      $crawlLang = $this->normalize_rebuild_wpml_lang((string)$crawlLang);
      $lastSeenId = 0;

      while (true) {
        $postIds = $this->query_cache_post_ids_chunk($post_types, $crawlLang, $scanModifiedAfterGmt, $lastSeenId, $chunkSize);
        if (empty($postIds)) {
          break;
        }

        foreach ($postIds as $post_id) {
          $post_id = (int)$post_id;
          if ($post_id > $lastSeenId) {
            $lastSeenId = $post_id;
          }
          if ($post_id < 1) {
            continue;
          }

          if ($maxPostsPerRebuild > 0 && $processedPosts >= $maxPostsPerRebuild) {
            $crawlAborted = true;
            break 3;
          }

          $this->append_rows($all, $this->crawl_post_for_cache_language($post_id, $crawlLang, $enabledSources));
          $processedPosts++;
          if ($this->should_abort_crawl($crawlStartedAt)) {
            $crawlAborted = true;
            break 3;
          }
        }

        if (count($postIds) < $chunkSize) {
          break;
        }
      }

      $completedLangs[] = $crawlLang;
    }

    return [
      'rows' => $all,
      'processed_posts' => $processedPosts,
      'crawl_aborted' => $crawlAborted ? '1' : '0',
      'completed_langs' => array_values(array_unique(array_filter(array_map('strval', $completedLangs)))),
    ];
  }

  private function get_stats_snapshot_ttl() {
    $settings = $this->get_settings();
    $minutes = isset($settings['stats_snapshot_ttl_min']) ? (int)$settings['stats_snapshot_ttl_min'] : 15;
    if ($minutes < 1) $minutes = 1;
    if ($minutes > 525600) $minutes = 525600;
    return $minutes * MINUTE_IN_SECONDS;
  }

  private function get_stats_refresh_period_minutes_map() {
    return [
      'hour' => 60,
      'day' => 1440,
      'week' => 10080,
      'month' => 43200,
    ];
  }

  private function sanitize_stats_refresh_period($period) {
    $period = sanitize_key((string)$period);
    $map = $this->get_stats_refresh_period_minutes_map();
    return isset($map[$period]) ? $period : 'hour';
  }

  private function convert_stats_refresh_to_minutes($value, $period) {
    $value = $this->sanitize_stats_refresh_value($value);
    $period = $this->sanitize_stats_refresh_period($period);
    $map = $this->get_stats_refresh_period_minutes_map();
    $unit = isset($map[$period]) ? (int)$map[$period] : 60;
    if ($unit < 1) {
      $unit = 60;
    }

    return $value * $unit;
  }

  private function get_stats_refresh_period_from_minutes($minutes) {
    $minutes = (int)$minutes;
    if ($minutes <= 60) return 'hour';
    if ($minutes <= 1440) return 'day';
    if ($minutes <= 10080) return 'week';
    return 'month';
  }

  private function sanitize_stats_refresh_value($value) {
    $value = (int)$value;
    if ($value < 1) $value = 1;
    if ($value > 12) $value = 12;
    return $value;
  }

  private function get_stats_refresh_value_and_period_from_minutes($minutes) {
    $minutes = (int)$minutes;
    if ($minutes < 1) $minutes = 1;
    if ($minutes > 525600) $minutes = 525600;

    $map = $this->get_stats_refresh_period_minutes_map();

    foreach (['month', 'week', 'day', 'hour'] as $period) {
      $unit = (int)$map[$period];
      if ($unit < 1) continue;
      if ($minutes % $unit !== 0) continue;
      $value = (int)($minutes / $unit);
      if ($value >= 1 && $value <= 12) {
        return ['value' => $value, 'period' => $period];
      }
    }

    $hourValue = (int)round($minutes / 60);
    $hourValue = $this->sanitize_stats_refresh_value($hourValue);
    return ['value' => $hourValue, 'period' => 'hour'];
  }

  private function is_incremental_rebuild_enabled() {
    return true;
  }

  private function get_crawl_post_batch_size() {
    $settings = $this->get_settings();
    $batch = isset($settings['crawl_post_batch']) ? (int)$settings['crawl_post_batch'] : self::CRAWL_POST_BATCH;
    if ($batch < 20) $batch = 20;
    $runtimeMax = $this->get_runtime_max_crawl_batch();
    if ($batch > $runtimeMax) $batch = $runtimeMax;
    return $batch;
  }

  private function get_default_scan_exclude_url_patterns() {
    return [
      '/blog/category/',
      '/blog/author/',
      '/blog/tag/',
      '/careers/',
      '/reviewer/',
    ];
  }

  private function four_level_status_label($key) {
    switch ((string)$key) {
      case 'none':
        return 'None';
      case 'low':
        return 'Low';
      case 'optimal':
        return 'Optimal';
      case 'excessive':
        return 'Excessive';
      default:
        return '—';
    }
  }

  private function get_four_level_status_ranges_text($thresholds) {
    $noneMax = (int)$thresholds['none_max'];
    $lowMax = (int)$thresholds['low_max'];
    $optimalMax = (int)$thresholds['optimal_max'];

    $lowMin = $noneMax + 1;
    $optimalMin = $lowMax + 1;
    $excessiveMin = $optimalMax + 1;

    $noneLabel = ($noneMax === 0) ? '0' : ('0-' . $noneMax);
    $lowLabel = ($lowMin <= $lowMax) ? ($lowMin . '-' . $lowMax) : (string)$lowMax;
    $optimalLabel = ($optimalMin <= $optimalMax) ? ($optimalMin . '-' . $optimalMax) : (string)$optimalMax;
    $excessiveLabel = $excessiveMin . '+';

    return [
      'none' => $noneLabel,
      'low' => $lowLabel,
      'optimal' => $optimalLabel,
      'excessive' => $excessiveLabel,
    ];
  }

  private function clear_cache_all() {
    $langs = ['all'];
    if ($this->is_wpml_active()) {
      $langs = array_merge($langs, array_keys($this->get_wpml_languages_map()));
      $currentLang = $this->sanitize_wpml_lang_filter($this->get_wpml_current_language());
      if ($currentLang !== '') {
        $langs[] = $currentLang;
      }
    }
    $langs = array_values(array_unique(array_filter(array_map('strval', $langs))));

    $scopes = array_merge(['any'], array_keys($this->get_available_post_types()));
    foreach ($langs as $lang) {
      foreach ($scopes as $scope) {
        delete_transient($this->cache_key($scope, $lang));
        delete_transient($this->cache_backup_key($scope, $lang));
        delete_option($this->cache_scan_option_key($scope, $lang));
      }
    }

    delete_option('lm_last_wpml_lang_context');
    $v = (int)get_option('lm_stats_snapshot_version', 1);
    update_option('lm_stats_snapshot_version', $v + 1, false);
    $this->bump_dataset_cache_version();
  }

  private function clear_main_cache_all() {
    $langs = ['all'];
    if ($this->is_wpml_active()) {
      $langs = array_merge($langs, array_keys($this->get_wpml_languages_map()));
      $currentLang = $this->sanitize_wpml_lang_filter($this->get_wpml_current_language());
      if ($currentLang !== '') {
        $langs[] = $currentLang;
      }
    }
    $langs = array_values(array_unique(array_filter(array_map('strval', $langs))));

    $scopes = array_merge(['any'], array_keys($this->get_available_post_types()));
    foreach ($langs as $lang) {
      foreach ($scopes as $scope) {
        delete_transient($this->cache_key($scope, $lang));
      }
    }
    $this->bump_dataset_cache_version();
  }

  private function schedule_background_rebuild($scope_post_type = 'any', $wpml_lang = 'all', $delaySeconds = 5, $refreshMode = 'full_rebuild') {
    $delaySeconds = max(1, (int)$delaySeconds);

    $args = $this->background_rebuild_event_args($scope_post_type, $wpml_lang, $refreshMode);
    if (!wp_next_scheduled('lm_background_rebuild_cache', $args)) {
      wp_schedule_single_event(time() + $delaySeconds, 'lm_background_rebuild_cache', $args);
    }
  }

  private function should_trigger_incremental_refresh_for_post($post) {
    if (!is_object($post) || !isset($post->ID)) {
      return false;
    }

    $postId = (int)$post->ID;
    if ($postId < 1) {
      return false;
    }

    if (wp_is_post_revision($postId) || wp_is_post_autosave($postId)) {
      return false;
    }

    $enabledPostTypes = $this->get_enabled_scan_post_types();
    $postType = sanitize_key((string)($post->post_type ?? ''));
    if ($postType === '' || !in_array($postType, $enabledPostTypes, true)) {
      return false;
    }

    $status = sanitize_key((string)($post->post_status ?? ''));
    if (!in_array($status, ['publish', 'future', 'private', 'draft', 'pending', 'trash'], true)) {
      return false;
    }

    return true;
  }

  private function schedule_incremental_refresh_fallback($delaySeconds = 4) {
    $debounceKey = 'lm_post_change_rebuild_debounce';
    $lastQueuedTs = (int)get_transient($debounceKey);
    $now = time();
    if ($lastQueuedTs > 0 && ($now - $lastQueuedTs) < 15) {
      return;
    }

    set_transient($debounceKey, $now, 30);
    $this->schedule_background_rebuild('any', 'all', max(1, (int)$delaySeconds), 'full_rebuild');
  }

  private function incremental_row_patch_transient_key($postId) {
    $postId = max(0, (int)$postId);
    return 'lm_incremental_row_patch_' . get_current_blog_id() . '_' . $postId;
  }

  private function queue_incremental_row_patch($postId, $currentRow, $newLink, $newRel = '', $newAnchor = null) {
    $postId = max(0, (int)$postId);
    if ($postId < 1 || !is_array($currentRow) || empty($currentRow)) {
      return false;
    }

    $rowId = isset($currentRow['row_id']) ? trim((string)$currentRow['row_id']) : '';
    if ($rowId === '') {
      return false;
    }

    return set_transient(
      $this->incremental_row_patch_transient_key($postId),
      [
        'current_row' => $currentRow,
        'new_link' => (string)$newLink,
        'new_rel' => (string)$newRel,
        'new_anchor' => ($newAnchor !== null) ? (string)$newAnchor : null,
      ],
      10 * MINUTE_IN_SECONDS
    );
  }

  private function consume_incremental_row_patch($postId) {
    $postId = max(0, (int)$postId);
    if ($postId < 1) {
      return null;
    }

    $key = $this->incremental_row_patch_transient_key($postId);
    $payload = get_transient($key);
    delete_transient($key);

    return is_array($payload) ? $payload : null;
  }

  private function clear_incremental_row_patch($postId) {
    $postId = max(0, (int)$postId);
    if ($postId > 0) {
      delete_transient($this->incremental_row_patch_transient_key($postId));
    }
  }

  public function capture_pre_post_update_snapshot($postId, $data = []) {
    $postId = max(0, (int)$postId);
    if ($postId < 1) {
      return;
    }

    $post = get_post($postId);
    if (!$post || !is_object($post)) {
      return;
    }

    $enabledPostTypes = $this->get_enabled_scan_post_types();
    $postType = sanitize_key((string)($post->post_type ?? ''));
    if ($postType === '' || !in_array($postType, $enabledPostTypes, true)) {
      return;
    }

    $this->pre_post_update_snapshots[$postId] = clone $post;
  }

  private function consume_pre_post_update_snapshot($postId) {
    $postId = max(0, (int)$postId);
    if ($postId < 1 || !isset($this->pre_post_update_snapshots[$postId])) {
      return null;
    }

    $snapshot = $this->pre_post_update_snapshots[$postId];
    unset($this->pre_post_update_snapshots[$postId]);

    return (is_object($snapshot) && isset($snapshot->ID)) ? $snapshot : null;
  }

  private function update_incremental_row_snippet($snippet, $oldAnchor, $newAnchor) {
    $snippet = (string)$snippet;
    $oldAnchor = trim((string)$oldAnchor);
    $newAnchor = trim((string)$newAnchor);
    if ($snippet === '' || $oldAnchor === '' || $newAnchor === '' || $oldAnchor === $newAnchor) {
      return $snippet;
    }

    $quotedOldAnchor = preg_quote($oldAnchor, '/');
    $updatedSnippet = preg_replace('/' . $quotedOldAnchor . '/iu', $newAnchor, $snippet, 1);
    return is_string($updatedSnippet) && $updatedSnippet !== '' ? $updatedSnippet : $snippet;
  }

  private function get_incremental_context_key($row) {
    if (!is_array($row)) {
      return '';
    }

    return implode('|', [
      (string)($row['source'] ?? ''),
      (string)($row['link_location'] ?? ''),
      (string)($row['block_index'] ?? ''),
    ]);
  }

  private function group_incremental_rows_by_context($rows) {
    $grouped = [];
    foreach ((array)$rows as $row) {
      if (!is_array($row)) {
        continue;
      }
      $key = $this->get_incremental_context_key($row);
      if ($key === '') {
        continue;
      }
      if (!isset($grouped[$key])) {
        $grouped[$key] = [];
      }
      $grouped[$key][] = $row;
    }

    return $grouped;
  }

  private function summarize_incremental_context_rows($rows) {
    $summary = [];
    foreach ((array)$rows as $row) {
      if (!is_array($row)) {
        continue;
      }
      $summary[] = [
        'row_id' => (string)($row['row_id'] ?? ''),
        'link' => (string)($row['link'] ?? ''),
        'anchor_text' => (string)($row['anchor_text'] ?? ''),
        'alt_text' => (string)($row['alt_text'] ?? ''),
        'rel_raw' => (string)($row['rel_raw'] ?? ''),
        'value_type' => (string)($row['value_type'] ?? ''),
        'snippet' => (string)($row['snippet'] ?? ''),
      ];
    }

    return wp_json_encode($summary);
  }

  private function get_changed_incremental_context_keys($oldRows, $newRows) {
    $oldGrouped = $this->group_incremental_rows_by_context($oldRows);
    $newGrouped = $this->group_incremental_rows_by_context($newRows);
    $allKeys = array_values(array_unique(array_merge(array_keys($oldGrouped), array_keys($newGrouped))));
    $changedKeys = [];

    foreach ($allKeys as $key) {
      $oldSummary = $this->summarize_incremental_context_rows(isset($oldGrouped[$key]) ? $oldGrouped[$key] : []);
      $newSummary = $this->summarize_incremental_context_rows(isset($newGrouped[$key]) ? $newGrouped[$key] : []);
      if ($oldSummary !== $newSummary) {
        $changedKeys[] = (string)$key;
      }
    }

    return $changedKeys;
  }

  private function get_incremental_snapshot_target_langs($postId) {
    $langs = ['all'];
    $resolvedLang = $this->resolve_post_wpml_lang((int)$postId, 'all');
    if ($resolvedLang !== '' && $resolvedLang !== 'all') {
      $langs[] = $resolvedLang;
    }

    return array_values(array_unique(array_filter(array_map([$this, 'normalize_rebuild_wpml_lang'], $langs))));
  }

  private function build_incremental_snapshot_rows_for_post($post, $enabledSources, $wpmlLang = 'all') {
    if (!is_object($post) || !isset($post->ID)) {
      return [];
    }

    $postId = (int)$post->ID;
    if ($postId < 1) {
      return [];
    }

    $enabledSources = array_values(array_unique(array_map('sanitize_key', (array)$enabledSources)));
    if (empty($enabledSources)) {
      return [];
    }

    $wpmlLang = $this->normalize_rebuild_wpml_lang((string)$wpmlLang);
    $baseContext = [
      'post_id' => (string)$postId,
      'post_title' => isset($post->post_title) ? (string)$post->post_title : '',
      'post_type' => isset($post->post_type) ? (string)$post->post_type : '',
      'post_date' => isset($post->post_date) ? (string)$post->post_date : '',
      'post_modified' => isset($post->post_modified) ? (string)$post->post_modified : '',
      'post_author' => isset($post->post_author) ? (string)$this->get_author_display_name_cached((int)$post->post_author) : '',
      'post_author_id' => (string)((int)($post->post_author ?? 0)),
      'page_url' => (string)get_permalink($postId),
    ];

    $rows = [];
    if (in_array('content', $enabledSources, true)) {
      $content = isset($post->post_content) ? (string)$post->post_content : '';
      $contentHasLinkMarkers = ($content !== '') && ((stripos($content, '<a') !== false) || (stripos($content, 'href=') !== false));
      $hasBlockMarkup = (strpos($content, '<!-- wp:') !== false);
      $blocks = ($contentHasLinkMarkers && $hasBlockMarkup && function_exists('parse_blocks')) ? parse_blocks($content) : [];
      $blockRows = [];

      if (!empty($blocks)) {
        $i = 0;
        foreach ($blocks as $block) {
          if (!$this->block_may_contain_link_marker($block)) {
            $i++;
            continue;
          }

          $blockName = isset($block['blockName']) && $block['blockName'] ? (string)$block['blockName'] : 'classic';
          $html = $this->render_block_html_best_effort($block);
          if (stripos($html, '<a') === false && stripos($html, 'href=') === false) {
            $i++;
            continue;
          }

          $context = array_merge($baseContext, [
            'source' => 'content',
            'link_location' => $blockName,
            'block_index' => (string)$i,
          ]);
          if ($wpmlLang !== 'all') {
            $context['wpml_lang'] = $wpmlLang;
          }
          $this->append_rows($blockRows, $this->parse_links_from_html($html, $context));
          $i++;
        }
      }

      $fullHtmlRows = [];
      if ($contentHasLinkMarkers) {
        $context = array_merge($baseContext, [
          'source' => 'content',
          'link_location' => 'classic',
          'block_index' => '',
        ]);
        if ($wpmlLang !== 'all') {
          $context['wpml_lang'] = $wpmlLang;
        }
        $fullHtmlRows = $this->parse_links_from_html($content, $context);
      }

      $contentRows = !empty($blocks) ? $this->merge_block_and_full_html_rows($blockRows, $fullHtmlRows) : $fullHtmlRows;
      $this->append_rows($rows, $contentRows);
    }

    if (in_array('excerpt', $enabledSources, true)) {
      $excerpt = isset($post->post_excerpt) ? (string)$post->post_excerpt : '';
      if ($excerpt !== '' && ((stripos($excerpt, '<a') !== false) || (stripos($excerpt, 'href=') !== false))) {
        $context = array_merge($baseContext, [
          'source' => 'excerpt',
          'link_location' => 'excerpt',
          'block_index' => '',
        ]);
        if ($wpmlLang !== 'all') {
          $context['wpml_lang'] = $wpmlLang;
        }
        $this->append_rows($rows, $this->parse_links_from_html($excerpt, $context));
      }
    }

    return $this->dedupe_crawl_rows_by_row_id($rows);
  }

  private function build_incremental_snapshot_rows_for_meta_key($post, $metaKey, $wpmlLang = 'all') {
    if (!is_object($post) || !isset($post->ID)) {
      return [];
    }

    $postId = (int)$post->ID;
    if ($postId < 1) {
      return [];
    }

    $metaKey = (string)$metaKey;
    if ($metaKey === '') {
      return [];
    }

    $metaValue = get_post_meta($postId, $metaKey, true);
    if (!is_string($metaValue) || trim($metaValue) === '') {
      return [];
    }
    if (stripos($metaValue, '<a') === false && stripos($metaValue, 'href=') === false) {
      return [];
    }

    $wpmlLang = $this->normalize_rebuild_wpml_lang((string)$wpmlLang);
    $context = [
      'post_id' => (string)$postId,
      'post_title' => isset($post->post_title) ? (string)$post->post_title : '',
      'post_type' => isset($post->post_type) ? (string)$post->post_type : '',
      'post_date' => isset($post->post_date) ? (string)$post->post_date : '',
      'post_modified' => isset($post->post_modified) ? (string)$post->post_modified : '',
      'post_author' => isset($post->post_author) ? (string)$this->get_author_display_name_cached((int)$post->post_author) : '',
      'post_author_id' => (string)((int)($post->post_author ?? 0)),
      'page_url' => (string)get_permalink($postId),
      'source' => 'meta',
      'link_location' => 'meta:' . $metaKey,
      'block_index' => '',
    ];
    if ($wpmlLang !== 'all') {
      $context['wpml_lang'] = $wpmlLang;
    }

    return $this->dedupe_crawl_rows_by_row_id($this->parse_links_from_html($metaValue, $context));
  }

  private function replace_cached_rows_for_context_keys($rows, $postId, $changedKeys, $replacementRowsByKey, &$changed = false) {
    $changed = false;
    $rows = is_array($rows) ? array_values($rows) : [];
    $postId = max(0, (int)$postId);
    $changedMap = array_fill_keys(array_values(array_filter(array_map('strval', (array)$changedKeys))), true);
    if ($postId < 1 || empty($changedMap)) {
      return $rows;
    }

    $updatedRows = [];
    foreach ($rows as $row) {
      if (!is_array($row)) {
        $updatedRows[] = $row;
        continue;
      }

      $rowPostId = isset($row['post_id']) ? (int)$row['post_id'] : 0;
      $contextKey = $this->get_incremental_context_key($row);
      if ($rowPostId === $postId && $contextKey !== '' && isset($changedMap[$contextKey])) {
        $changed = true;
        continue;
      }

      $updatedRows[] = $row;
    }

    foreach ($changedMap as $contextKey => $unused) {
      if (!empty($replacementRowsByKey[$contextKey]) && is_array($replacementRowsByKey[$contextKey])) {
        $this->append_rows($updatedRows, $replacementRowsByKey[$contextKey]);
        $changed = true;
      }
    }

    return $updatedRows;
  }

  private function patch_existing_caches_for_snapshot_change($postBefore, $postAfter) {
    if (!is_object($postBefore) || !isset($postBefore->ID) || !is_object($postAfter) || !isset($postAfter->ID)) {
      return false;
    }

    $postId = (int)$postAfter->ID;
    if ($postId < 1) {
      return false;
    }

    $enabledSources = array_values(array_intersect($this->get_enabled_scan_source_types(), ['content', 'excerpt']));
    if (empty($enabledSources)) {
      return false;
    }

    $postType = sanitize_key((string)($postAfter->post_type ?? $postBefore->post_type ?? ''));
    if ($postType === '') {
      return false;
    }

    $patchedAny = false;
    $targetLangs = $this->get_incremental_snapshot_target_langs($postId);
    foreach ($targetLangs as $lang) {
      $oldRows = $this->build_incremental_snapshot_rows_for_post($postBefore, $enabledSources, $lang);
      $newRows = $this->build_incremental_snapshot_rows_for_post($postAfter, $enabledSources, $lang);
      $changedKeys = $this->get_changed_incremental_context_keys($oldRows, $newRows);
      if (empty($changedKeys)) {
        continue;
      }

      $replacementRowsByKey = $this->group_incremental_rows_by_context($newRows);
      foreach (['any', $postType] as $scope) {
        $mainRows = get_transient($this->cache_key($scope, $lang));
        $backupRows = get_transient($this->cache_backup_key($scope, $lang));
        if (!is_array($mainRows) && !is_array($backupRows)) {
          continue;
        }

        $baseRows = is_array($mainRows) ? $mainRows : (is_array($backupRows) ? $backupRows : []);
        $didReplace = false;
        $patchedRows = $this->replace_cached_rows_for_context_keys($baseRows, $postId, $changedKeys, $replacementRowsByKey, $didReplace);
        if (!$didReplace) {
          continue;
        }

        $this->persist_cache_payload($scope, $lang, $patchedRows);
        $this->schedule_rest_list_prewarm($scope, $lang, 2);
        $patchedAny = true;
      }
    }

    $indexedPatched = $this->patch_indexed_datastore_for_post_change($postAfter, $enabledSources);
    return ($patchedAny || $indexedPatched);
  }

  private function patch_existing_caches_for_meta_key_change($post, $metaKey) {
    if (!is_object($post) || !isset($post->ID)) {
      return false;
    }

    $postId = (int)$post->ID;
    if ($postId < 1) {
      return false;
    }

    $metaKey = (string)$metaKey;
    if ($metaKey === '') {
      return false;
    }

    $postType = sanitize_key((string)($post->post_type ?? ''));
    if ($postType === '') {
      return false;
    }

    $contextKey = 'meta|meta:' . $metaKey . '|';
    $patchedAny = false;
    foreach ($this->get_incremental_snapshot_target_langs($postId) as $lang) {
      $replacementRowsByKey = [
        $contextKey => $this->build_incremental_snapshot_rows_for_meta_key($post, $metaKey, $lang),
      ];

      foreach (['any', $postType] as $scope) {
        $mainRows = get_transient($this->cache_key($scope, $lang));
        $backupRows = get_transient($this->cache_backup_key($scope, $lang));
        if (!is_array($mainRows) && !is_array($backupRows)) {
          continue;
        }

        $baseRows = is_array($mainRows) ? $mainRows : (is_array($backupRows) ? $backupRows : []);
        $didReplace = false;
        $patchedRows = $this->replace_cached_rows_for_context_keys($baseRows, $postId, [$contextKey], $replacementRowsByKey, $didReplace);
        if (!$didReplace) {
          continue;
        }

        $this->persist_cache_payload($scope, $lang, $patchedRows);
        $this->schedule_rest_list_prewarm($scope, $lang, 2);
        $patchedAny = true;
      }
    }

    $indexedPatched = $this->patch_indexed_datastore_for_post_change($post);
    return ($patchedAny || $indexedPatched);
  }

  public function handle_post_meta_change_incremental_refresh($metaIds, $postId, $metaKey, $metaValue) {
    $postId = max(0, (int)$postId);
    if ($postId < 1) {
      return;
    }

    $enabledSources = $this->get_enabled_scan_source_types();
    if (!in_array('meta', $enabledSources, true)) {
      return;
    }

    $scanMetaKeys = $this->get_scan_meta_keys_cached();
    $metaKey = (string)$metaKey;
    if ($metaKey === '' || !in_array($metaKey, $scanMetaKeys, true)) {
      return;
    }

    $post = get_post($postId);
    if (!$this->should_trigger_incremental_refresh_for_post($post)) {
      return;
    }

    $patched = false;
    try {
      $patched = $this->patch_existing_caches_for_meta_key_change($post, $metaKey);
    } catch (Throwable $e) {
      if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('LM incremental patch error (post_meta): ' . sanitize_text_field($e->getMessage()));
      }
      $patched = false;
    }

    if (!$patched) {
      $this->schedule_incremental_refresh_fallback(4);
    }
  }

  private function build_incremental_updated_row($post, $baseRow, $newLink, $newRel = '', $newAnchor = null) {
    if (!is_object($post) || !isset($post->ID) || !is_array($baseRow) || empty($baseRow)) {
      return null;
    }

    $pageUrl = get_permalink((int)$post->ID);
    $resolvedLink = $this->normalize_url($this->resolve_to_absolute((string)$newLink, (string)$pageUrl));
    if ($resolvedLink === '') {
      return null;
    }

    $valueType = $this->detect_link_value_type((string)$newLink);
    if (!$this->is_scan_value_type_enabled($valueType, $this->get_enabled_scan_value_types_map_cached())) {
      return null;
    }

    $effectiveRelRaw = ($newRel !== '') ? $this->parse_rel_flags($newRel)['raw'] : (string)($baseRow['rel_raw'] ?? '');
    $relFlags = $this->parse_rel_flags($effectiveRelRaw);
    $effectiveAnchor = ($newAnchor !== null)
      ? $this->normalize_anchor_text_value((string)$newAnchor, true)
      : $this->normalize_anchor_text_value((string)($baseRow['anchor_text'] ?? ''), true);
    $authorId = (int)($post->post_author ?? 0);

    $updatedRow = $baseRow;
    $updatedRow['post_id'] = (string)((int)$post->ID);
    $updatedRow['post_title'] = isset($post->post_title) ? (string)$post->post_title : '';
    $updatedRow['post_type'] = isset($post->post_type) ? (string)$post->post_type : (string)($baseRow['post_type'] ?? '');
    $updatedRow['post_author'] = $authorId > 0 ? (string)$this->get_author_display_name_cached($authorId) : '';
    $updatedRow['post_author_id'] = (string)$authorId;
    $updatedRow['post_date'] = isset($post->post_date) ? (string)$post->post_date : (string)($baseRow['post_date'] ?? '');
    $updatedRow['post_modified'] = isset($post->post_modified) ? (string)$post->post_modified : (string)($baseRow['post_modified'] ?? '');
    $updatedRow['page_url'] = (string)$pageUrl;
    $updatedRow['link'] = $resolvedLink;
    $updatedRow['link_raw'] = (string)$newLink;
    $updatedRow['anchor_text'] = $effectiveAnchor;
    $updatedRow['alt_text'] = ($newAnchor !== null) ? '' : (string)($baseRow['alt_text'] ?? '');
    $updatedRow['snippet'] = $this->update_incremental_row_snippet(
      (string)($baseRow['snippet'] ?? ''),
      (string)($baseRow['anchor_text'] ?? ''),
      $effectiveAnchor
    );
    $updatedRow['link_type'] = $this->is_external($resolvedLink) ? 'exlink' : 'inlink';
    $updatedRow['rel_raw'] = $relFlags['raw'];
    $updatedRow['relationship'] = $this->relationship_label($relFlags['raw']);
    $updatedRow['rel_nofollow'] = $relFlags['nofollow'] ? '1' : '0';
    $updatedRow['rel_sponsored'] = $relFlags['sponsored'] ? '1' : '0';
    $updatedRow['rel_ugc'] = $relFlags['ugc'] ? '1' : '0';
    $updatedRow['value_type'] = $valueType;
    $updatedRow['row_id'] = $this->row_id(
      (string)($updatedRow['post_id'] ?? ''),
      (string)($updatedRow['source'] ?? ''),
      (string)($updatedRow['link_location'] ?? ''),
      (string)($updatedRow['block_index'] ?? ''),
      (string)($updatedRow['occurrence'] ?? ''),
      $resolvedLink
    );

    return $updatedRow;
  }

  private function replace_cached_row_by_row_id($rows, $oldRowId, $replacementRow, &$changed = false) {
    $changed = false;
    $rows = is_array($rows) ? array_values($rows) : [];
    $oldRowId = trim((string)$oldRowId);
    if ($oldRowId === '' || empty($rows)) {
      return $rows;
    }

    $updatedRows = [];
    foreach ($rows as $row) {
      if (!is_array($row)) {
        $updatedRows[] = $row;
        continue;
      }

      $rowId = isset($row['row_id']) ? (string)$row['row_id'] : '';
      if ($rowId !== $oldRowId) {
        $updatedRows[] = $row;
        continue;
      }

      $changed = true;
      if (is_array($replacementRow) && !empty($replacementRow)) {
        $updatedRows[] = array_merge($row, $replacementRow);
      }
    }

    return $updatedRows;
  }

  private function get_incremental_indexed_target_langs($postId = 0) {
    $postId = max(0, (int)$postId);
    $langs = ($postId > 0) ? $this->get_incremental_snapshot_target_langs($postId) : ['all'];
    $langs = array_values(array_unique(array_filter(array_map([$this, 'normalize_rebuild_wpml_lang'], (array)$langs))));
    if (empty($langs)) {
      $langs = ['all'];
    }

    usort($langs, function($left, $right) {
      if ($left === 'all' && $right !== 'all') {
        return 1;
      }
      if ($left !== 'all' && $right === 'all') {
        return -1;
      }
      return strcmp((string)$left, (string)$right);
    });

    return $langs;
  }

  private function delete_indexed_fact_rows_by_row_ids($rowIds, $langs) {
    global $wpdb;

    if (!$this->is_indexed_datastore_ready()) {
      return false;
    }

    $table = $wpdb->prefix . 'lm_link_fact';
    $rowIds = array_values(array_unique(array_filter(array_map('strval', (array)$rowIds))));
    $langs = array_values(array_unique(array_filter(array_map([$this, 'normalize_rebuild_wpml_lang'], (array)$langs))));
    if (empty($rowIds) || empty($langs)) {
      return false;
    }

    $deletedAny = false;
    foreach ($langs as $lang) {
      foreach (array_chunk($rowIds, 200) as $chunk) {
        $placeholders = implode(',', array_fill(0, count($chunk), '%s'));
        $sql = "DELETE FROM $table WHERE wpml_lang = %s AND row_id IN ($placeholders)";
        $params = array_merge([(string)$lang], $chunk);
        $result = $wpdb->query($wpdb->prepare($sql, $params));
        if ($result) {
          $deletedAny = true;
        }
      }
    }

    return $deletedAny;
  }

  private function delete_indexed_fact_rows_by_post_ids($postIds, $langs) {
    global $wpdb;

    if (!$this->is_indexed_datastore_ready()) {
      return false;
    }

    $table = $wpdb->prefix . 'lm_link_fact';
    $postIds = array_values(array_unique(array_filter(array_map('intval', (array)$postIds))));
    $langs = array_values(array_unique(array_filter(array_map([$this, 'normalize_rebuild_wpml_lang'], (array)$langs))));
    if (empty($postIds) || empty($langs)) {
      return false;
    }

    $deletedAny = false;
    foreach ($langs as $lang) {
      foreach (array_chunk($postIds, 200) as $chunk) {
        $placeholders = implode(',', array_fill(0, count($chunk), '%d'));
        $sql = "DELETE FROM $table WHERE wpml_lang = %s AND post_id IN ($placeholders)";
        $params = array_merge([(string)$lang], $chunk);
        $result = $wpdb->query($wpdb->prepare($sql, $params));
        if ($result) {
          $deletedAny = true;
        }
      }
    }

    return $deletedAny;
  }

  private function patch_indexed_datastore_for_row_change($oldRowId, $replacementRow = null, $postId = 0) {
    if (!$this->is_indexed_datastore_ready()) {
      return false;
    }

    $oldRowId = trim((string)$oldRowId);
    $replacementRowId = is_array($replacementRow) ? trim((string)($replacementRow['row_id'] ?? '')) : '';
    if ($oldRowId === '' && $replacementRowId === '') {
      return false;
    }

    $targetLangs = $this->get_incremental_indexed_target_langs($postId);
    $rowIdsToDelete = array_values(array_unique(array_filter([$oldRowId, $replacementRowId], 'strlen')));
    $changed = $this->delete_indexed_fact_rows_by_row_ids($rowIdsToDelete, $targetLangs);

    if (is_array($replacementRow) && !empty($replacementRow)) {
      $this->append_indexed_datastore_rows([$replacementRow], 'all');
      $changed = true;
    }

    if (!$changed) {
      return false;
    }

    foreach ($targetLangs as $lang) {
      $this->rebuild_indexed_summary_for_lang($lang);
    }

    $this->bump_dataset_cache_version();
    return true;
  }

  private function patch_indexed_datastore_for_post_change($post, $enabledSources = null) {
    if (!$this->is_indexed_datastore_ready() || !is_object($post) || !isset($post->ID)) {
      return false;
    }

    $postId = max(0, (int)$post->ID);
    if ($postId < 1) {
      return false;
    }

    $enabledSources = is_array($enabledSources) ? $enabledSources : $this->get_enabled_scan_source_types();
    $targetLangs = $this->get_incremental_snapshot_target_langs($postId);
    $exactLangs = array_values(array_filter($targetLangs, function($lang) {
      return (string)$lang !== 'all';
    }));
    $isPublish = sanitize_key((string)($post->post_status ?? '')) === 'publish';

    $this->delete_indexed_fact_rows_by_post_ids([$postId], $targetLangs);

    if ($isPublish) {
      if (!empty($exactLangs)) {
        foreach ($exactLangs as $lang) {
          $freshRows = $this->crawl_post_for_cache_language($post, $lang, $enabledSources);
          if (is_array($freshRows) && !empty($freshRows)) {
            $this->append_indexed_datastore_rows($freshRows, 'all');
          }
        }
      } else {
        $freshRows = $this->crawl_post_for_cache_language($post, 'all', $enabledSources);
        if (is_array($freshRows) && !empty($freshRows)) {
          $this->append_indexed_datastore_rows($freshRows, 'all');
        }
      }
    }

    foreach ($targetLangs as $lang) {
      $this->rebuild_indexed_summary_for_lang($lang);
    }

    $this->bump_dataset_cache_version();
    return true;
  }

  private function patch_indexed_datastore_for_menu_item_change($menuItemId, $replacementRows = null) {
    global $wpdb;

    if (!$this->is_indexed_datastore_ready()) {
      return false;
    }

    $menuItemId = max(0, (int)$menuItemId);
    if ($menuItemId < 1) {
      return false;
    }

    $table = $wpdb->prefix . 'lm_link_fact';
    $targetBlockIndex = $this->get_menu_item_block_index($menuItemId);
    if ($targetBlockIndex === '') {
      return false;
    }

    $changed = false;
    $result = $wpdb->query(
      $wpdb->prepare(
        "DELETE FROM $table WHERE source = %s AND block_index = %s",
        'menu',
        $targetBlockIndex
      )
    );
    if ($result) {
      $changed = true;
    }

    if (is_array($replacementRows) && !empty($replacementRows)) {
      $this->append_indexed_datastore_rows($replacementRows, 'all');
      $changed = true;
    }

    if (!$changed) {
      return false;
    }

    $this->rebuild_indexed_summary_for_lang('all');
    $this->bump_dataset_cache_version();
    return true;
  }

  private function persist_cache_payload_from_indexed_scope($scope_post_type, $wpml_lang = 'all') {
    $scope_post_type = sanitize_key((string)$scope_post_type);
    if ($scope_post_type === '') {
      $scope_post_type = 'any';
    }
    $wpml_lang = $this->normalize_rebuild_wpml_lang((string)$wpml_lang);

    $rows = $this->get_indexed_fact_rows($scope_post_type, $wpml_lang, null);
    $rows = is_array($rows) ? array_values($rows) : [];

    set_transient($this->cache_key($scope_post_type, $wpml_lang), $rows, self::CACHE_TTL);
    set_transient($this->cache_backup_key($scope_post_type, $wpml_lang), $rows, self::CACHE_BASE_TTL);
    update_option($this->cache_scan_option_key($scope_post_type, $wpml_lang), gmdate('Y-m-d H:i:s'), false);
    $this->bump_dataset_cache_version();

    if ($scope_post_type === 'any') {
      $this->warm_common_precomputed_stats_snapshots($rows, $wpml_lang, false);
    }

    return $rows;
  }

  private function delete_indexed_menu_rows($wpml_lang = 'all') {
    global $wpdb;

    if (!$this->is_indexed_datastore_ready()) {
      return false;
    }

    $table = $wpdb->prefix . 'lm_link_fact';
    $wpml_lang = $this->normalize_rebuild_wpml_lang((string)$wpml_lang);

    if ($wpml_lang === 'all') {
      $result = $wpdb->query(
        $wpdb->prepare(
          "DELETE FROM $table WHERE source = %s AND wpml_lang = %s",
          'menu',
          'all'
        )
      );
      return (bool)$result;
    }

    $result = $wpdb->query(
      $wpdb->prepare(
        "DELETE FROM $table WHERE source = %s AND wpml_lang = %s",
        'menu',
        $wpml_lang
      )
    );
    return (bool)$result;
  }

  private function patch_existing_caches_for_row_change($post, $currentRow, $newLink, $newRel = '', $newAnchor = null) {
    if (!is_object($post) || !isset($post->ID) || !is_array($currentRow) || empty($currentRow)) {
      return false;
    }

    $oldRowId = isset($currentRow['row_id']) ? trim((string)$currentRow['row_id']) : '';
    if ($oldRowId === '') {
      return false;
    }

    $replacementRow = $this->build_incremental_updated_row($post, $currentRow, $newLink, $newRel, $newAnchor);
    $postType = sanitize_key((string)($post->post_type ?? ''));
    if ($postType === '') {
      return false;
    }

    $contexts = $this->get_incremental_cache_contexts_for_post($postType);
    if (empty($contexts)) {
      return false;
    }

    $patchedAny = false;
    foreach ($contexts as $ctx) {
      $scope = (string)($ctx['scope'] ?? 'any');
      $lang = (string)($ctx['lang'] ?? 'all');
      $mainRows = get_transient($this->cache_key($scope, $lang));
      $backupRows = get_transient($this->cache_backup_key($scope, $lang));
      if (!is_array($mainRows) && !is_array($backupRows)) {
        continue;
      }

      $baseRows = is_array($mainRows) ? $mainRows : (is_array($backupRows) ? $backupRows : []);
      $didReplace = false;
      $patchedRows = $this->replace_cached_row_by_row_id($baseRows, $oldRowId, $replacementRow, $didReplace);
      if (!$didReplace) {
        continue;
      }

      $this->persist_cache_payload($scope, $lang, $patchedRows);
      $this->schedule_rest_list_prewarm($scope, $lang, 2);
      $patchedAny = true;
    }

    $indexedPatched = $this->patch_indexed_datastore_for_row_change($oldRowId, $replacementRow, (int)$post->ID);
    return ($patchedAny || $indexedPatched);
  }

  private function build_incremental_updated_menu_row($baseRow, $newLink, $newAnchor = null) {
    if (!is_array($baseRow) || empty($baseRow)) {
      return null;
    }

    $resolvedLink = $this->normalize_url($this->resolve_to_absolute((string)$newLink, home_url('/')));
    if ($resolvedLink === '') {
      return null;
    }

    $valueType = $this->detect_link_value_type((string)$newLink);
    if (!$this->is_scan_value_type_enabled($valueType, $this->get_enabled_scan_value_types_map_cached())) {
      return null;
    }

    $updatedRow = $baseRow;
    $updatedRow['post_id'] = '';
    $updatedRow['post_title'] = '';
    $updatedRow['post_type'] = 'menu';
    $updatedRow['post_date'] = '';
    $updatedRow['post_modified'] = '';
    $updatedRow['post_author'] = '';
    $updatedRow['post_author_id'] = '0';
    $updatedRow['page_url'] = '';
    $updatedRow['link'] = $resolvedLink;
    $updatedRow['link_raw'] = (string)$newLink;
    if ($newAnchor !== null) {
      $updatedRow['anchor_text'] = $this->normalize_anchor_text_value((string)$newAnchor, true);
    }
    $updatedRow['alt_text'] = '';
    $updatedRow['snippet'] = '';
    $updatedRow['link_type'] = $this->is_external($resolvedLink) ? 'exlink' : 'inlink';
    $updatedRow['relationship'] = 'dofollow';
    $updatedRow['rel_raw'] = '';
    $updatedRow['rel_nofollow'] = '0';
    $updatedRow['rel_sponsored'] = '0';
    $updatedRow['rel_ugc'] = '0';
    $updatedRow['value_type'] = $valueType;
    $updatedRow['row_id'] = $this->row_id(
      '',
      'menu',
      (string)($updatedRow['link_location'] ?? ''),
      (string)($updatedRow['block_index'] ?? ''),
      (string)($updatedRow['occurrence'] ?? ''),
      $resolvedLink
    );

    return $updatedRow;
  }

  private function patch_existing_caches_for_menu_row_change($currentRow, $newLink, $newAnchor = null) {
    if (!is_array($currentRow) || empty($currentRow)) {
      return false;
    }

    $oldRowId = isset($currentRow['row_id']) ? trim((string)$currentRow['row_id']) : '';
    if ($oldRowId === '') {
      return false;
    }

    $replacementRow = $this->build_incremental_updated_menu_row($currentRow, $newLink, $newAnchor);
    if (!is_array($replacementRow) || empty($replacementRow)) {
      return false;
    }

    $patchedAny = false;
    $langs = ['all'];
    if ($this->is_wpml_active()) {
      $langs = array_merge($langs, array_keys($this->get_wpml_languages_map()));
    }
    $langs = array_values(array_unique(array_filter(array_map([$this, 'normalize_rebuild_wpml_lang'], $langs))));
    $scopes = array_merge(['any'], array_keys($this->get_available_post_types()));

    foreach ($langs as $lang) {
      foreach ($scopes as $scope) {
        $mainRows = get_transient($this->cache_key($scope, $lang));
        $backupRows = get_transient($this->cache_backup_key($scope, $lang));
        if (!is_array($mainRows) && !is_array($backupRows)) {
          continue;
        }

        $baseRows = is_array($mainRows) ? $mainRows : (is_array($backupRows) ? $backupRows : []);
        $didReplace = false;
        $patchedRows = $this->replace_cached_row_by_row_id($baseRows, $oldRowId, $replacementRow, $didReplace);
        if (!$didReplace) {
          continue;
        }

        $this->persist_cache_payload($scope, $lang, $patchedRows);
        $this->schedule_rest_list_prewarm($scope, $lang, 2);
        $patchedAny = true;
      }
    }

    $indexedPatched = $this->patch_indexed_datastore_for_row_change($oldRowId, $replacementRow, 0);
    return ($patchedAny || $indexedPatched);
  }

  private function get_menu_item_block_index($itemId) {
    $itemId = max(0, (int)$itemId);
    return $itemId > 0 ? 'menu_item:' . $itemId : '';
  }

  private function build_incremental_menu_rows_for_item($itemId) {
    $itemId = max(0, (int)$itemId);
    if ($itemId < 1) {
      return [];
    }

    $enabledSources = $this->get_enabled_scan_source_types();
    if (!in_array('menu', $enabledSources, true)) {
      return [];
    }

    $item = wp_setup_nav_menu_item(get_post($itemId));
    if (!$item || empty($item->ID)) {
      return [];
    }

    $menuTermIds = wp_get_object_terms($itemId, 'nav_menu', ['fields' => 'ids']);
    if (!is_array($menuTermIds) || empty($menuTermIds)) {
      return [];
    }

    $rows = [];
    $enabledValueTypesMap = $this->get_enabled_scan_value_types_map_cached();
    foreach ($menuTermIds as $menuTermId) {
      $menuTermId = (int)$menuTermId;
      if ($menuTermId < 1) {
        continue;
      }

      $menu = wp_get_nav_menu_object($menuTermId);
      if (!$menu || empty($menu->name)) {
        continue;
      }

      $items = wp_get_nav_menu_items($menuTermId);
      if (!is_array($items) || empty($items)) {
        continue;
      }

      $occurrence = null;
      foreach (array_values($items) as $index => $menuItem) {
        if ((int)($menuItem->ID ?? 0) === $itemId) {
          $occurrence = (int)$index;
          break;
        }
      }
      if ($occurrence === null) {
        continue;
      }

      $url = isset($item->url) ? (string)$item->url : '';
      $resolved = $this->normalize_url($this->resolve_to_absolute($url, home_url('/')));
      $valueType = $this->detect_link_value_type($url);
      if (!$this->is_scan_value_type_enabled($valueType, $enabledValueTypesMap)) {
        continue;
      }

      $rows[] = [
        'post_id' => '',
        'post_title' => '',
        'post_type' => 'menu',
        'post_date' => '',
        'post_modified' => '',
        'post_author' => '',
        'post_author_id' => '0',
        'page_url' => '',
        'row_id' => $this->row_id('', 'menu', 'menu:' . (string)$menu->name, $this->get_menu_item_block_index($itemId), $occurrence, $resolved),
        'occurrence' => (string)$occurrence,
        'source' => 'menu',
        'link_location' => 'menu:' . (string)$menu->name,
        'block_index' => $this->get_menu_item_block_index($itemId),
        'link' => $resolved,
        'link_raw' => $url,
        'anchor_text' => isset($item->title) ? $this->normalize_anchor_text_value((string)$item->title, true) : '',
        'alt_text' => '',
        'snippet' => '',
        'link_type' => $this->is_external($resolved) ? 'exlink' : 'inlink',
        'relationship' => 'dofollow',
        'rel_raw' => '',
        'rel_nofollow' => '0',
        'rel_sponsored' => '0',
        'rel_ugc' => '0',
        'value_type' => $valueType,
      ];
    }

    return $rows;
  }

  private function replace_cached_menu_item_rows($rows, $menuItemId, $replacementRows, &$changed = false) {
    $changed = false;
    $rows = is_array($rows) ? array_values($rows) : [];
    $targetBlockIndex = $this->get_menu_item_block_index($menuItemId);
    if ($targetBlockIndex === '') {
      return $rows;
    }

    $updatedRows = [];
    foreach ($rows as $row) {
      if (!is_array($row)) {
        $updatedRows[] = $row;
        continue;
      }

      $isTargetMenuRow = ((string)($row['source'] ?? '') === 'menu') && ((string)($row['block_index'] ?? '') === $targetBlockIndex);
      if ($isTargetMenuRow) {
        $changed = true;
        continue;
      }

      $updatedRows[] = $row;
    }

    if (!empty($replacementRows)) {
      $this->append_rows($updatedRows, $replacementRows);
      $changed = true;
    }

    return $updatedRows;
  }

  private function patch_existing_caches_for_menu_item_change($menuItemId, $replacementRows = null) {
    $menuItemId = max(0, (int)$menuItemId);
    if ($menuItemId < 1) {
      return false;
    }

    if ($replacementRows === null) {
      $replacementRows = $this->build_incremental_menu_rows_for_item($menuItemId);
    }
    $replacementRows = is_array($replacementRows) ? $replacementRows : [];
    $patchedAny = false;
    $langs = ['all'];
    if ($this->is_wpml_active()) {
      $langs = array_merge($langs, array_keys($this->get_wpml_languages_map()));
    }
    $langs = array_values(array_unique(array_filter(array_map([$this, 'normalize_rebuild_wpml_lang'], $langs))));
    $scopes = array_merge(['any'], array_keys($this->get_available_post_types()));

    foreach ($langs as $lang) {
      foreach ($scopes as $scope) {
        $mainRows = get_transient($this->cache_key($scope, $lang));
        $backupRows = get_transient($this->cache_backup_key($scope, $lang));
        if (!is_array($mainRows) && !is_array($backupRows)) {
          continue;
        }

        $baseRows = is_array($mainRows) ? $mainRows : (is_array($backupRows) ? $backupRows : []);
        $didReplace = false;
        $patchedRows = $this->replace_cached_menu_item_rows($baseRows, $menuItemId, $replacementRows, $didReplace);
        if (!$didReplace) {
          continue;
        }

        $this->persist_cache_payload($scope, $lang, $patchedRows);
        $this->schedule_rest_list_prewarm($scope, $lang, 2);
        $patchedAny = true;
      }
    }

    $indexedPatched = $this->patch_indexed_datastore_for_menu_item_change($menuItemId, $replacementRows);
    return ($patchedAny || $indexedPatched);
  }

  public function handle_menu_item_change_incremental_refresh($postId, $post, $update) {
    $postId = max(0, (int)$postId);
    if ($postId < 1) {
      return;
    }

    $enabledSources = $this->get_enabled_scan_source_types();
    if (!in_array('menu', $enabledSources, true)) {
      return;
    }

    $patched = false;
    try {
      $patched = $this->patch_existing_caches_for_menu_item_change($postId);
    } catch (Throwable $e) {
      if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('LM incremental patch error (menu_item): ' . sanitize_text_field($e->getMessage()));
      }
      $patched = false;
    }

    if (!$patched) {
      $this->schedule_incremental_refresh_fallback(4);
    }
  }

  public function handle_post_status_transition_incremental_refresh($newStatus, $oldStatus, $post) {
    if (!is_object($post) || !isset($post->ID)) {
      return;
    }

    $postId = max(0, (int)$post->ID);
    if ($postId < 1 || wp_is_post_revision($postId) || wp_is_post_autosave($postId)) {
      return;
    }

    $newStatus = sanitize_key((string)$newStatus);
    $oldStatus = sanitize_key((string)$oldStatus);
    if ($newStatus === $oldStatus) {
      return;
    }

    $watchStatuses = ['publish', 'future', 'private', 'draft', 'pending', 'trash'];
    if (!in_array($newStatus, $watchStatuses, true) && !in_array($oldStatus, $watchStatuses, true)) {
      return;
    }

    $postType = sanitize_key((string)($post->post_type ?? ''));
    if ($postType === 'nav_menu_item') {
      return;
    }

    if (!$this->should_trigger_incremental_refresh_for_post($post)) {
      $enabledPostTypes = $this->get_enabled_scan_post_types();
      if ($postType === '' || !in_array($postType, $enabledPostTypes, true)) {
        return;
      }
    }

    $patched = false;
    try {
      $patched = $this->patch_existing_caches_for_post_change($post);
    } catch (Throwable $e) {
      if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('LM incremental patch error (transition_post_status): ' . sanitize_text_field($e->getMessage()));
      }
      $patched = false;
    }

    if (!$patched) {
      $this->schedule_incremental_refresh_fallback(4);
    }
  }

  private function remove_post_rows_from_cache_rows($rows, $postId, &$removedCount = 0) {
    $postId = (int)$postId;
    $removedCount = 0;
    if ($postId < 1 || !is_array($rows) || empty($rows)) {
      return is_array($rows) ? $rows : [];
    }

    $filtered = [];
    foreach ($rows as $row) {
      $rowPostId = isset($row['post_id']) ? (int)$row['post_id'] : 0;
      if ($rowPostId === $postId) {
        $removedCount++;
        continue;
      }
      $filtered[] = $row;
    }

    return $filtered;
  }

  private function get_incremental_cache_contexts_for_post($postType) {
    $contexts = [];
    $postType = sanitize_key((string)$postType);

    $scopes = ['any'];
    if ($postType !== '') {
      $scopes[] = $postType;
    }

    $langs = ['all'];
    if ($this->is_wpml_active()) {
      $langs = array_merge($langs, array_keys($this->get_wpml_languages_map()));
      $lastContext = sanitize_key((string)get_option('lm_last_wpml_lang_context', ''));
      if ($lastContext !== '' && $lastContext !== 'all') {
        $langs[] = $lastContext;
      }
    }

    $scopes = array_values(array_unique(array_map('sanitize_key', $scopes)));
    $langs = array_values(array_unique(array_map('sanitize_key', $langs)));

    foreach ($scopes as $scope) {
      if ($scope === '') {
        continue;
      }
      foreach ($langs as $lang) {
        if ($lang === '') {
          continue;
        }
        $contexts[] = [
          'scope' => $scope,
          'lang' => $this->normalize_rebuild_wpml_lang($lang),
        ];
      }
    }

    return $contexts;
  }

  private function crawl_post_for_cache_language($post, $lang, $enabledSources) {
    $lang = $this->normalize_rebuild_wpml_lang((string)$lang);
    $wpmlWasSwitched = false;
    $wpmlPreviousLang = '';
    try {
      if ($this->is_wpml_active()) {
        $prev = $this->safe_wpml_apply_filters('wpml_current_language', null);
        if (is_string($prev)) {
          $wpmlPreviousLang = sanitize_key($prev);
        }
        $switchLang = ($lang === 'all') ? null : $lang;
        $wpmlWasSwitched = $this->safe_wpml_switch_language($switchLang);
      }

      $rows = $this->crawl_post($post, $enabledSources);
      if ($lang === 'all' || empty($rows) || !is_array($rows)) {
        return $rows;
      }

      foreach ($rows as &$row) {
        if (!is_array($row)) {
          continue;
        }
        $row['wpml_lang'] = $lang;
      }
      unset($row);

      return $rows;
    } finally {
      if ($wpmlWasSwitched) {
        if ($wpmlPreviousLang !== '') {
          $this->safe_wpml_switch_language($wpmlPreviousLang);
        } else {
          $this->safe_wpml_switch_language(null);
        }
      }
    }
  }

  private function patch_existing_caches_for_post_change($post) {
    if (!is_object($post) || !isset($post->ID)) {
      return false;
    }

    $postId = (int)$post->ID;
    if ($postId < 1) {
      return false;
    }

    $postType = sanitize_key((string)($post->post_type ?? ''));
    if ($postType === '') {
      return false;
    }

    $isPublish = sanitize_key((string)($post->post_status ?? '')) === 'publish';
    $enabledSources = $this->get_enabled_scan_source_types();
    $contexts = $this->get_incremental_cache_contexts_for_post($postType);
    if (empty($contexts)) {
      return false;
    }

    $rowsByLang = [];
    $patchedAny = false;

    foreach ($contexts as $ctx) {
      $scope = (string)($ctx['scope'] ?? 'any');
      $lang = (string)($ctx['lang'] ?? 'all');

      $mainRows = get_transient($this->cache_key($scope, $lang));
      $backupRows = get_transient($this->cache_backup_key($scope, $lang));
      if (!is_array($mainRows) && !is_array($backupRows)) {
        continue;
      }

      $baseRows = is_array($mainRows) ? $mainRows : (is_array($backupRows) ? $backupRows : []);
      $removedCount = 0;
      $patchedRows = $this->remove_post_rows_from_cache_rows($baseRows, $postId, $removedCount);

      $addedCount = 0;
      $scopeAllowsPost = ($scope === 'any' || $scope === $postType);
      if ($isPublish && $scopeAllowsPost) {
        if (!isset($rowsByLang[$lang])) {
          $rowsByLang[$lang] = $this->crawl_post_for_cache_language($post, $lang, $enabledSources);
        }
        $freshRows = is_array($rowsByLang[$lang]) ? $rowsByLang[$lang] : [];
        $addedCount = count($freshRows);
        if (!empty($freshRows)) {
          $this->append_rows($patchedRows, $freshRows);
        }
      }

      if ($removedCount > 0 || $addedCount > 0 || !is_array($mainRows)) {
        $this->persist_cache_payload($scope, $lang, $patchedRows);
        $this->schedule_rest_list_prewarm($scope, $lang, 2);
        $patchedAny = true;
      }
    }

    $indexedPatched = $this->patch_indexed_datastore_for_post_change($post, $enabledSources);
    return ($patchedAny || $indexedPatched);
  }

  public function handle_post_change_schedule_incremental_refresh($postId, $post, $update) {
    $postId = (int)$postId;
    if ($postId < 1) {
      return;
    }

    if (!$this->should_trigger_incremental_refresh_for_post($post)) {
      return;
    }

    $patched = false;
    $queuedPatch = $this->consume_incremental_row_patch($postId);
    $preUpdateSnapshot = $this->consume_pre_post_update_snapshot($postId);
    try {
      if (is_array($queuedPatch) && !empty($queuedPatch['current_row'])) {
        $patched = $this->patch_existing_caches_for_row_change(
          $post,
          (array)$queuedPatch['current_row'],
          (string)($queuedPatch['new_link'] ?? ''),
          (string)($queuedPatch['new_rel'] ?? ''),
          $queuedPatch['new_anchor'] ?? null
        );
      }
      if (!$patched && is_object($preUpdateSnapshot) && isset($preUpdateSnapshot->ID)) {
        $patched = $this->patch_existing_caches_for_snapshot_change($preUpdateSnapshot, $post);
      }
      if (!$patched) {
        $patched = $this->patch_existing_caches_for_post_change($post);
      }
    } catch (Throwable $e) {
      if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('LM incremental patch error (save_post): ' . sanitize_text_field($e->getMessage()));
      }
      $patched = false;
    }

    if (!$patched) {
      $this->schedule_incremental_refresh_fallback(4);
    }
  }

  public function handle_deleted_post_schedule_incremental_refresh($postId, $post = null) {
    $postId = (int)$postId;
    if ($postId < 1) {
      return;
    }

    if (!is_object($post) || !isset($post->ID)) {
      $post = get_post($postId);
    }
    if (!is_object($post) || !isset($post->ID)) {
      $this->schedule_incremental_refresh_fallback(4);
      return;
    }

    $postType = sanitize_key((string)($post->post_type ?? ''));
    if ($postType === 'nav_menu_item') {
      $enabledSources = $this->get_enabled_scan_source_types();
      if (!in_array('menu', $enabledSources, true)) {
        return;
      }

      $patched = false;
      try {
        $patched = $this->patch_existing_caches_for_menu_item_change($postId, []);
      } catch (Throwable $e) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
          error_log('LM incremental patch error (delete_menu_item): ' . sanitize_text_field($e->getMessage()));
        }
        $patched = false;
      }

      if (!$patched) {
        $this->schedule_incremental_refresh_fallback(4);
      }
      return;
    }

    $enabledPostTypes = $this->get_enabled_scan_post_types();
    if ($postType !== '' && !in_array($postType, $enabledPostTypes, true)) {
      return;
    }

    $post->post_status = 'trash';

    $patched = false;
    try {
      $patched = $this->patch_existing_caches_for_post_change($post);
    } catch (Throwable $e) {
      if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('LM incremental patch error (before_delete_post): ' . sanitize_text_field($e->getMessage()));
      }
      $patched = false;
    }

    if (!$patched) {
      $this->schedule_incremental_refresh_fallback(4);
    }
  }

  private function schedule_rest_list_prewarm($scope_post_type = 'any', $wpml_lang = 'all', $delaySeconds = 2) {
    $delaySeconds = max(1, (int)$delaySeconds);

    $args = $this->rest_list_prewarm_event_args($scope_post_type, $wpml_lang);
    if (!wp_next_scheduled('lm_prewarm_rest_list_cache', $args)) {
      $state = $this->get_rebuild_job_state();
      if (is_array($state) && !empty($state)) {
        $state['prewarm_pending'] = '1';
        $state['updated_at'] = current_time('mysql');
        $this->save_rebuild_job_state($state);
      }
      wp_schedule_single_event(time() + $delaySeconds, 'lm_prewarm_rest_list_cache', $args);
    }
  }

  public function run_rest_list_prewarm($scope_post_type = 'any', $wpml_lang = 'all') {
    $scope_post_type = sanitize_key((string)$scope_post_type);
    if ($scope_post_type === '') {
      $scope_post_type = 'any';
    }
    $wpml_lang = $this->normalize_rebuild_wpml_lang((string)$wpml_lang);

    try {
      $pagesRequest = new WP_REST_Request('GET', '/links-manager/v1/pages-link/list');
      $pagesRequest->set_param('post_type', $scope_post_type);
      $pagesRequest->set_param('wpml_lang', $wpml_lang);
      $pagesRequest->set_param('paged', 1);
      $pagesRequest->set_param('per_page', 20);
      $pagesRequest->set_param('rebuild', 0);
      $this->rest_pages_link_list($pagesRequest);

      $editorRequest = new WP_REST_Request('GET', '/links-manager/v1/editor/list');
      $editorRequest->set_param('post_type', $scope_post_type);
      $editorRequest->set_param('wpml_lang', $wpml_lang);
      $editorRequest->set_param('paged', 1);
      $editorRequest->set_param('per_page', 20);
      $editorRequest->set_param('rebuild', 0);
      $this->rest_editor_list($editorRequest);
    } catch (Throwable $e) {
      if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('LM REST prewarm error: ' . sanitize_text_field($e->getMessage()));
      }
    } finally {
      $state = $this->get_rebuild_job_state();
      if (is_array($state) && !empty($state)) {
        unset($state['prewarm_pending']);
        $state['updated_at'] = current_time('mysql');
        $this->save_rebuild_job_state($state);
      }
    }
  }

  private function background_rebuild_lock_key($scope_post_type, $wpml_lang) {
    return 'lm_bg_rebuild_lock_' . md5(get_current_blog_id() . '|' . (string)$scope_post_type . '|' . (string)$wpml_lang);
  }

  private function get_auto_refresh_schedule_config($settings = null) {
    $settings = is_array($settings) ? $settings : $this->get_settings();

    $frequency = sanitize_key((string)($settings['auto_refresh_frequency'] ?? 'weekly'));
    if (!in_array($frequency, ['hourly', 'daily', 'weekly', 'monthly'], true)) {
      $frequency = 'weekly';
    }

    $hourlyInterval = isset($settings['auto_refresh_hourly_interval']) ? (int)$settings['auto_refresh_hourly_interval'] : 1;
    $hourlyInterval = max(1, min(24, $hourlyInterval));

    $timeValue = isset($settings['auto_refresh_time']) ? (string)$settings['auto_refresh_time'] : '21:00';
    if (!preg_match('/^\d{2}:\d{2}$/', $timeValue)) {
      $timeValue = '21:00';
    }
    $timeParts = explode(':', $timeValue);
    $hour = isset($timeParts[0]) ? max(0, min(23, (int)$timeParts[0])) : 21;
    $minute = isset($timeParts[1]) ? max(0, min(59, (int)$timeParts[1])) : 0;
    $timeValue = sprintf('%02d:%02d', $hour, $minute);

    $weekday = sanitize_key((string)($settings['auto_refresh_weekday'] ?? 'saturday'));
    if (!in_array($weekday, ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'], true)) {
      $weekday = 'saturday';
    }

    $monthday = isset($settings['auto_refresh_monthday']) ? (int)$settings['auto_refresh_monthday'] : 1;
    $monthday = max(1, min(31, $monthday));

    return [
      'enabled' => ((string)($settings['auto_refresh_enabled'] ?? '1') === '0') ? '0' : '1',
      'frequency' => $frequency,
      'hourly_interval' => $hourlyInterval,
      'time' => $timeValue,
      'hour' => $hour,
      'minute' => $minute,
      'weekday' => $weekday,
      'monthday' => $monthday,
    ];
  }

  private function get_auto_refresh_schedule_timezone() {
    if (function_exists('wp_timezone')) {
      try {
        $timezone = wp_timezone();
        if ($timezone instanceof DateTimeZone) {
          return $timezone;
        }
      } catch (Throwable $e) {
      }
    }

    try {
      $tzString = wp_timezone_string();
      if (is_string($tzString) && trim($tzString) !== '') {
        return new DateTimeZone($tzString);
      }
    } catch (Throwable $e) {
    }

    try {
      return new DateTimeZone($this->get_auto_refresh_timezone_offset_string());
    } catch (Throwable $e) {
      return new DateTimeZone('UTC');
    }
  }

  private function get_auto_refresh_schedule_timezone_label() {
    $timezone = $this->get_auto_refresh_schedule_timezone();
    $timezoneName = (string)$timezone->getName();

    $now = new DateTimeImmutable('now', $timezone);
    $offsetSeconds = (int)$now->getOffset();
    $offsetSign = ($offsetSeconds < 0) ? '-' : '+';
    $offsetSeconds = abs($offsetSeconds);
    $offsetHours = (int)floor($offsetSeconds / HOUR_IN_SECONDS);
    $offsetMinutes = (int)floor(($offsetSeconds % HOUR_IN_SECONDS) / MINUTE_IN_SECONDS);
    $offsetLabel = sprintf('UTC%s%02d:%02d', $offsetSign, $offsetHours, $offsetMinutes);

    if (preg_match('/^[+-]\d{2}:\d{2}$/', $timezoneName)) {
      return $offsetLabel;
    }

    return sprintf('%s (%s)', $timezoneName, $offsetLabel);
  }

  private function get_auto_refresh_weekday_labels() {
    return [
      'monday' => __('Monday', 'links-manager'),
      'tuesday' => __('Tuesday', 'links-manager'),
      'wednesday' => __('Wednesday', 'links-manager'),
      'thursday' => __('Thursday', 'links-manager'),
      'friday' => __('Friday', 'links-manager'),
      'saturday' => __('Saturday', 'links-manager'),
      'sunday' => __('Sunday', 'links-manager'),
    ];
  }

  private function get_auto_refresh_schedule_preview($settings = null) {
    $config = $this->get_auto_refresh_schedule_config($settings);
    if ((string)$config['enabled'] !== '1') {
      return __('Automatic refresh is turned off.', 'links-manager');
    }

    $timezoneLabel = $this->get_auto_refresh_schedule_timezone_label();
    $timeLabel = (string)$config['time'];
    $weekdayLabels = $this->get_auto_refresh_weekday_labels();

    if ($config['frequency'] === 'hourly') {
      return sprintf(
        _n('Runs every %d hour in the background. Times follow %s.', 'Runs every %d hours in the background. Times follow %s.', (int)$config['hourly_interval'], 'links-manager'),
        (int)$config['hourly_interval'],
        $timezoneLabel
      );
    }

    if ($config['frequency'] === 'daily') {
      return sprintf(__('Runs every day at %1$s %2$s.', 'links-manager'), $timeLabel, $timezoneLabel);
    }

    if ($config['frequency'] === 'monthly') {
      return sprintf(__('Runs on day %1$d of each month at %2$s %3$s. If a month is shorter, the last day is used.', 'links-manager'), (int)$config['monthday'], $timeLabel, $timezoneLabel);
    }

    $weekdayLabel = isset($weekdayLabels[$config['weekday']]) ? $weekdayLabels[$config['weekday']] : ucfirst((string)$config['weekday']);
    return sprintf(__('Runs every %1$s at %2$s %3$s.', 'links-manager'), $weekdayLabel, $timeLabel, $timezoneLabel);
  }

  private function get_auto_refresh_schedule_frequency_label($settings = null) {
    $config = $this->get_auto_refresh_schedule_config($settings);
    switch ((string)$config['frequency']) {
      case 'hourly':
        return __('Hourly', 'links-manager');
      case 'daily':
        return __('Daily', 'links-manager');
      case 'monthly':
        return __('Monthly', 'links-manager');
      default:
        return __('Weekly', 'links-manager');
    }
  }

  private function get_next_configured_cache_rebuild_timestamp($settings = null, $referenceTimestamp = null) {
    $config = $this->get_auto_refresh_schedule_config($settings);
    if ((string)$config['enabled'] !== '1') {
      return 0;
    }

    $timezone = $this->get_auto_refresh_schedule_timezone();
    $referenceTs = (int)$referenceTimestamp;
    if ($referenceTs < 1) {
      $referenceTs = time();
    }
    $now = (new DateTimeImmutable('@' . $referenceTs))->setTimezone($timezone);

    if ($config['frequency'] === 'hourly') {
      $candidate = $now->setTime((int)$now->format('G'), 0, 0)->modify('+1 hour');
      while (((int)$candidate->format('G')) % (int)$config['hourly_interval'] !== 0) {
        $candidate = $candidate->modify('+1 hour');
      }
      return (int)$candidate->getTimestamp();
    }

    if ($config['frequency'] === 'daily') {
      $candidate = $now->setTime((int)$config['hour'], (int)$config['minute'], 0);
      if ($candidate <= $now) {
        $candidate = $candidate->modify('+1 day');
      }
      return (int)$candidate->getTimestamp();
    }

    if ($config['frequency'] === 'weekly') {
      $weekdayMap = [
        'monday' => 1,
        'tuesday' => 2,
        'wednesday' => 3,
        'thursday' => 4,
        'friday' => 5,
        'saturday' => 6,
        'sunday' => 7,
      ];
      $targetWeekday = isset($weekdayMap[$config['weekday']]) ? (int)$weekdayMap[$config['weekday']] : 6;
      $candidate = $now->setTime((int)$config['hour'], (int)$config['minute'], 0);
      while (((int)$candidate->format('N') !== $targetWeekday) || $candidate <= $now) {
        $candidate = $candidate->modify('+1 day');
      }
      return (int)$candidate->getTimestamp();
    }

    $year = (int)$now->format('Y');
    $month = (int)$now->format('n');
    $targetDay = (int)$config['monthday'];
    $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $month, $year);
    $clampedDay = min($targetDay, $daysInMonth);
    $candidate = $now->setDate($year, $month, $clampedDay)->setTime((int)$config['hour'], (int)$config['minute'], 0);
    if ($candidate <= $now) {
      $nextMonth = $now->modify('first day of next month');
      $year = (int)$nextMonth->format('Y');
      $month = (int)$nextMonth->format('n');
      $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $month, $year);
      $clampedDay = min($targetDay, $daysInMonth);
      $candidate = $nextMonth->setDate($year, $month, $clampedDay)->setTime((int)$config['hour'], (int)$config['minute'], 0);
    }

    return (int)$candidate->getTimestamp();
  }

  private function clear_configured_cache_rebuild_schedule() {
    wp_clear_scheduled_hook('lm_scheduled_cache_rebuild');
  }

  private function get_configured_cache_rebuild_schedule_timestamps() {
    $cron = _get_cron_array();
    if (!is_array($cron) || empty($cron)) {
      return [];
    }

    $timestamps = [];
    foreach ($cron as $timestamp => $hooks) {
      if (!isset($hooks['lm_scheduled_cache_rebuild']) || !is_array($hooks['lm_scheduled_cache_rebuild'])) {
        continue;
      }
      foreach ($hooks['lm_scheduled_cache_rebuild'] as $event) {
        $args = isset($event['args']) && is_array($event['args']) ? $event['args'] : [];
        if (!empty($args)) {
          continue;
        }
        $timestamps[] = (int)$timestamp;
      }
    }

    sort($timestamps, SORT_NUMERIC);
    return array_values(array_unique(array_filter($timestamps, function($timestamp) {
      return (int)$timestamp > 0;
    })));
  }

  private function normalize_configured_cache_rebuild_schedule($settings = null) {
    $settings = is_array($settings) ? $settings : $this->get_settings();
    $config = $this->get_auto_refresh_schedule_config($settings);
    if ((string)$config['enabled'] !== '1') {
      $this->clear_configured_cache_rebuild_schedule();
      return 0;
    }

    $desiredNextTs = $this->get_next_configured_cache_rebuild_timestamp($settings);
    $scheduledTimestamps = $this->get_configured_cache_rebuild_schedule_timestamps();
    $now = time();

    $needsReset = false;
    if ($desiredNextTs < 1) {
      $needsReset = true;
    } elseif (count($scheduledTimestamps) !== 1) {
      $needsReset = true;
    } else {
      $scheduledTs = (int)$scheduledTimestamps[0];
      if ($scheduledTs < ($now - MINUTE_IN_SECONDS)) {
        $needsReset = true;
      } elseif (abs($scheduledTs - $desiredNextTs) > MINUTE_IN_SECONDS) {
        $needsReset = true;
      }
    }

    if ($needsReset) {
      $this->clear_configured_cache_rebuild_schedule();
      return $this->schedule_next_configured_cache_rebuild($settings);
    }

    return (int)$scheduledTimestamps[0];
  }

  private function schedule_next_configured_cache_rebuild($settings = null) {
    $nextTs = $this->get_next_configured_cache_rebuild_timestamp($settings);
    if ($nextTs < 1) {
      return 0;
    }

    if (!wp_next_scheduled('lm_scheduled_cache_rebuild')) {
      wp_schedule_single_event($nextTs, 'lm_scheduled_cache_rebuild');
    }

    return $nextTs;
  }

  private function reschedule_configured_cache_rebuild($settings = null) {
    $this->clear_configured_cache_rebuild_schedule();
    return $this->schedule_next_configured_cache_rebuild($settings);
  }

  public function ensure_scheduled_cache_rebuild() {
    $this->normalize_configured_cache_rebuild_schedule();
  }

  private function get_scheduled_cache_rebuild_debounce_key() {
    return 'lm_scheduled_cache_rebuild_last_run_' . get_current_blog_id();
  }

  public function run_scheduled_cache_rebuild() {
    $debounceKey = $this->get_scheduled_cache_rebuild_debounce_key();
    $lastRunTs = (int)get_transient($debounceKey);
    $now = time();
    if ($lastRunTs > 0 && ($now - $lastRunTs) < 300) {
      $this->schedule_next_configured_cache_rebuild();
      return;
    }

    set_transient($debounceKey, $now, 10 * MINUTE_IN_SECONDS);
    try {
      $this->run_background_rebuild_cache('any', 'all', 'changed_only');
    } finally {
      $this->schedule_next_configured_cache_rebuild();
    }
  }

  public function run_background_rebuild_cache($scope_post_type = 'any', $wpml_lang = 'all', $refreshMode = 'full_rebuild') {
    $args = $this->background_rebuild_event_args($scope_post_type, $wpml_lang, $refreshMode);
    $scope_post_type = (string)$args[0];
    $wpml_lang = (string)$args[1];
    $refreshMode = (string)$args[2];

    $lockKey = $this->background_rebuild_lock_key($scope_post_type, $wpml_lang);
    if (get_transient($lockKey)) {
      return;
    }

    set_transient($lockKey, '1', 5 * MINUTE_IN_SECONDS);
    try {
      $request = new WP_REST_Request('POST', '/links-manager/v1/rebuild/start');
      $request->set_param('post_type', $scope_post_type);
      $request->set_param('wpml_lang', $wpml_lang);
      $request->set_param('refresh_mode', $refreshMode);
      $this->rest_rebuild_start($request);
    } catch (Throwable $e) {
      if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('LM background rebuild error: ' . sanitize_text_field($e->getMessage()));
      }
    } finally {
      delete_transient($lockKey);
    }
  }

  private function build_or_get_cache($scope_post_type, $force_rebuild = false, $wpml_lang = 'all', $allow_stale_serve = true, $force_incremental = false) {
    $profileTotalStarted = $this->profile_start();
    $this->reset_crawl_runtime_stats();
    $wpml_lang = $this->normalize_rebuild_wpml_lang($wpml_lang);

    $key = $this->cache_key($scope_post_type, $wpml_lang);
    $backupKey = $this->cache_backup_key($scope_post_type, $wpml_lang);
    $this->purge_oversized_transient($key, $this->get_safe_transient_limit_bytes(false));
    $this->purge_oversized_transient($backupKey, $this->get_safe_transient_limit_bytes(true));

    if (!$force_rebuild) {
      $cached = get_transient($key);
      if (is_array($cached)) {
        $this->profile_end('cache_read_hit', $profileTotalStarted, [
          'rows' => count($cached),
          'scope_post_type' => (string)$scope_post_type,
          'wpml_lang' => (string)$wpml_lang,
        ]);
        return $cached;
      }
    }

    $backup = get_transient($backupKey);
    $lastScanGmt = (string)get_option($this->cache_scan_option_key($scope_post_type, $wpml_lang), '');
    if (!$force_rebuild && $allow_stale_serve && is_array($backup)) {
      $this->profile_end('cache_read_backup_stale', $profileTotalStarted, [
        'rows' => count($backup),
        'scope_post_type' => (string)$scope_post_type,
        'wpml_lang' => (string)$wpml_lang,
        'last_scan_gmt' => (string)$lastScanGmt,
      ]);
      return $backup;
    }

    $enabledPostTypes = $this->get_enabled_scan_post_types();
    $scope_post_type = sanitize_key((string)$scope_post_type);
    $post_types = ($scope_post_type === 'any')
      ? $enabledPostTypes
      : (in_array($scope_post_type, $enabledPostTypes, true) ? [$scope_post_type] : []);

    if (empty($post_types)) {
      $all = $this->crawl_menus();
      $this->persist_cache_payload($scope_post_type, $wpml_lang, $all);
      if ($this->is_wpml_active()) {
        update_option('lm_last_wpml_lang_context', (string)$wpml_lang, false);
      }
      $this->profile_end('cache_rebuild_menus_only', $profileTotalStarted, [
        'rows' => count($all),
        'scope_post_type' => (string)$scope_post_type,
        'wpml_lang' => (string)$wpml_lang,
      ]);
      return $all;
    }

    $enabledSources = $this->get_enabled_scan_source_types();
    $scanModifiedAfterGmt = $this->get_scan_modified_after_gmt('');
    $maxPostsPerRebuild = $this->get_max_posts_per_rebuild();
    $crawlBatch = $this->get_crawl_post_batch_size();
    $incrementalEnabled = $force_incremental ? true : $this->is_incremental_rebuild_enabled();
    if ($wpml_lang === 'all') {
      $incrementalEnabled = false;
    }
    if (!$incrementalEnabled && $force_rebuild && is_array($backup) && $lastScanGmt !== '') {
      $incrementalEnabled = true;
    }

    $all = [];
    $processedPosts = 0;
    $crawlStartedAt = microtime(true);
    $crawlAborted = false;
    $wpmlWasSwitched = false;
    $wpmlPreviousLang = '';

    try {
      if ($this->is_wpml_active()) {
        $prev = $this->safe_wpml_apply_filters('wpml_current_language', null);
        if (is_string($prev)) {
          $wpmlPreviousLang = sanitize_key($prev);
        }
        $switchLang = ($wpml_lang === 'all') ? null : $wpml_lang;
        $wpmlWasSwitched = $this->safe_wpml_switch_language($switchLang);
      }

      if ($incrementalEnabled && is_array($backup) && $lastScanGmt !== '') {
        $incremental = $this->build_incremental_cache_from_backup($backup, $post_types, $wpml_lang, $lastScanGmt, $enabledSources);
        if (is_array($incremental)) {
          $all = $incremental;
          $this->persist_cache_payload($scope_post_type, $wpml_lang, $all);
          if ($this->is_wpml_active()) {
            update_option('lm_last_wpml_lang_context', (string)$wpml_lang, false);
          }
          $this->profile_end('cache_rebuild_incremental', $profileTotalStarted, $this->profile_meta_with_crawl_stats([
            'rows' => count($all),
            'force_rebuild' => $force_rebuild ? '1' : '0',
            'scope_post_type' => (string)$scope_post_type,
            'wpml_lang' => (string)$wpml_lang,
          ]));
          return $all;
        }
      }

      $chunkSize = $crawlBatch > 0 ? (int)$crawlBatch : (int)self::CRAWL_POST_BATCH;
      if ($chunkSize < 1) $chunkSize = 100;

      if ($wpml_lang === 'all') {
        $globalResult = $this->crawl_rows_for_rebuild_lang_queue(
          $post_types,
          $this->get_rebuild_crawl_lang_queue($wpml_lang),
          $scanModifiedAfterGmt,
          $enabledSources,
          $maxPostsPerRebuild,
          $chunkSize
        );
        $all = is_array($globalResult['rows'] ?? null) ? $globalResult['rows'] : [];
        $processedPosts = max(0, (int)($globalResult['processed_posts'] ?? 0));
        $crawlAborted = !empty($globalResult['crawl_aborted']);
      } else {
        $lastSeenId = 0;
        while (true) {
          $postIds = $this->query_cache_post_ids_chunk($post_types, $wpml_lang, $scanModifiedAfterGmt, $lastSeenId, $chunkSize);
          if (empty($postIds)) {
            break;
          }

          foreach ($postIds as $post_id) {
            $post_id = (int)$post_id;
            if ($post_id > $lastSeenId) {
              $lastSeenId = $post_id;
            }
            if ($post_id < 1) {
              continue;
            }

            if ($maxPostsPerRebuild > 0 && $processedPosts >= $maxPostsPerRebuild) {
              $crawlAborted = true;
              break 2;
            }

            $this->append_rows($all, $this->crawl_post($post_id, $enabledSources));
            $processedPosts++;
            if ($this->should_abort_crawl($crawlStartedAt)) {
              $crawlAborted = true;
              break 2;
            }
          }

          if (count($postIds) < $chunkSize) {
            break;
          }
        }
      }
    } finally {
      if ($wpmlWasSwitched) {
        if ($wpmlPreviousLang !== '') {
          $this->safe_wpml_switch_language($wpmlPreviousLang);
        } else {
          $this->safe_wpml_switch_language(null);
        }
      }
    }

    $this->append_rows($all, $this->crawl_menus($enabledSources));

    if ($crawlAborted && defined('WP_DEBUG') && WP_DEBUG) {
      error_log('LM crawl stopped early to prevent timeout/memory exhaustion; returning partial cache.');
    }

    $this->persist_cache_payload($scope_post_type, $wpml_lang, $all);
    if ($this->is_wpml_active()) {
      update_option('lm_last_wpml_lang_context', (string)$wpml_lang, false);
    }
    $this->profile_end('cache_rebuild_full', $profileTotalStarted, $this->profile_meta_with_crawl_stats([
      'rows' => count($all),
      'processed_posts' => (int)$processedPosts,
      'crawl_aborted' => $crawlAborted ? '1' : '0',
      'scope_post_type' => (string)$scope_post_type,
      'wpml_lang' => (string)$wpml_lang,
    ]));
    return $all;
  }
}
