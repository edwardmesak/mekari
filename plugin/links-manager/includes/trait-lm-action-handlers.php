<?php
/**
 * Admin action and AJAX handlers for Links Manager.
 */

if (!defined('ABSPATH')) {
  exit;
}

trait LM_Action_Handlers_Trait {
  private function send_ajax_rebuild_response($response) {
    if (is_wp_error($response)) {
      $status = (int)$response->get_error_data('status');
      if ($status < 100) {
        $status = 400;
      }
      wp_send_json_error([
        'message' => $response->get_error_message(),
        'last_error' => $response->get_error_message(),
      ], $status);
    }

    if ($response instanceof WP_REST_Response) {
      $status = (int)$response->get_status();
      if ($status < 100) {
        $status = 200;
      }
      wp_send_json_success($response->get_data(), $status);
    }

    wp_send_json_success(is_array($response) ? $response : []);
  }

  private function verify_rebuild_ajax_access() {
    if (!$this->current_user_can_access_plugin()) {
      wp_send_json_error(['message' => $this->unauthorized_message()], 403);
    }

    $nonce = $this->request_text('lm_ajax_nonce', '');
    if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) {
      wp_send_json_error(['message' => $this->invalid_nonce_message()], 403);
    }
  }

  public function handle_rebuild_start_ajax() {
    $this->verify_rebuild_ajax_access();

    $request = new WP_REST_Request('POST', '/links-manager/v1/rebuild/start');
    $request->set_param('post_type', $this->request_text('post_type', ''));
    $request->set_param('wpml_lang', $this->request_text('wpml_lang', ''));
    $this->send_ajax_rebuild_response($this->rest_rebuild_start($request));
  }

  public function handle_rebuild_status_ajax() {
    $this->verify_rebuild_ajax_access();

    $request = new WP_REST_Request('GET', '/links-manager/v1/rebuild/status');
    $this->send_ajax_rebuild_response($this->rest_rebuild_status($request));
  }

  public function handle_rebuild_step_ajax() {
    $this->verify_rebuild_ajax_access();

    $request = new WP_REST_Request('POST', '/links-manager/v1/rebuild/step');
    $request->set_param('batch', $this->request_int('batch', 0));
    $this->send_ajax_rebuild_response($this->rest_rebuild_step($request));
  }

  private function get_selected_anchor_group_names_from_request($fieldName, $legacyFieldName = '') {
    $rawGroups = $this->request_array($fieldName);
    if (empty($rawGroups) && $legacyFieldName !== '' && $this->request_has($legacyFieldName)) {
      $legacyValue = trim($this->request_text($legacyFieldName, ''));
      if ($legacyValue !== '') {
        $rawGroups = [$legacyValue];
      }
    }

    $validGroupNames = [];
    foreach ($this->get_anchor_groups() as $group) {
      $groupName = trim((string)($group['name'] ?? ''));
      if ($groupName !== '') {
        $validGroupNames[$groupName] = true;
      }
    }

    $selectedGroups = [];
    foreach ((array)$rawGroups as $rawGroup) {
      $groupName = trim(sanitize_text_field((string)$rawGroup));
      if ($groupName === '' || $groupName === 'no_group') {
        continue;
      }
      if (isset($validGroupNames[$groupName])) {
        $selectedGroups[$groupName] = true;
      }
    }

    return array_keys($selectedGroups);
  }

  public function handle_update_link() {
    if (!$this->current_user_can_access_plugin()) wp_die($this->unauthorized_message());

    $nonce = $this->request_text(self::NONCE_NAME, '');
    if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) wp_die($this->invalid_nonce_message());

    $filters = $this->get_filters_from_request();

    $post_id = $this->request_int('post_id', 0);
    $old_link = $this->request_text('old_link', '');
    $new_link = $this->request_has('new_link') ? esc_url_raw((string)$this->request_raw('new_link', '')) : '';
    $new_rel  = $this->request_text('new_rel', '');
    $old_anchor = $this->request_text('old_anchor', '');
    $new_anchor_raw = $this->request_has('new_anchor') ? (string)$this->request_raw('new_anchor', '') : null;
    $new_anchor = $this->normalize_new_anchor_input($new_anchor_raw, $old_anchor);

    if ($new_anchor !== null && $filters['anchor_contains'] !== '') {
      $anchorFilter = $filters['anchor_contains'];
      $textMode = isset($filters['text_match_mode']) ? $this->sanitize_text_match_mode($filters['text_match_mode']) : 'contains';
      $matchesFilter = $this->text_matches((string)$new_anchor, $anchorFilter, $textMode);

      if (!$matchesFilter) {
        $filters['anchor_contains'] = $new_anchor;
      }
    }

    $source = $this->request_text('source', '');
    $location = $this->request_text('link_location', '');
    $block_index = $this->request_text('block_index', '');
    $occurrence = $this->request_int('occurrence', 0);

    if (!$this->current_user_can_edit_link_target($post_id, $source)) {
      wp_die($this->unauthorized_message());
    }

    $row_id = $this->request_text('row_id', '');

    $has_change = ($new_link !== '') || ($new_rel !== '') || ($new_anchor !== null);
    $effective_new_link = $new_link !== '' ? $new_link : $old_link;

    $isMenuSource = ($source === 'menu');

    if ((!$isMenuSource && $post_id <= 0) || $old_link === '' || $effective_new_link === '' || $source === '' || $location === '' || $row_id === '' || !$has_change) {
      $msg = __('Failed: incomplete input.', 'links-manager');
      if (!$has_change) {
        $msg = __('Failed: no changes provided.', 'links-manager');
      }
      if ($row_id === '' && $post_id > 0 && $old_link !== '') {
        $msg .= ' ' . __('Data is not synchronized yet. Run Refresh Data, then reload this page and try again.', 'links-manager');
      }
      $this->record_link_update_diagnostic('single_update_validate', 'failed', [
        'post_id' => $post_id,
        'source' => $source,
        'link_location' => $location,
        'block_index' => $block_index,
        'occurrence' => $occurrence,
        'row_id' => $row_id,
        'old_link' => $old_link,
        'effective_new_link' => $effective_new_link,
        'has_change' => $has_change,
        'message' => $msg,
      ]);
      $this->safe_redirect_back($filters, ['lm_msg' => $msg]);
    }

    $currentRowResult = $this->get_current_row_for_update_context($post_id, $source, $location, $block_index, $occurrence);
    if (empty($currentRowResult['ok'])) {
      $contextMessage = isset($currentRowResult['msg']) ? (string)$currentRowResult['msg'] : __('Target link not found (content changed?)', 'links-manager');
      $this->record_link_update_diagnostic('single_update_context_lookup', 'failed', [
        'post_id' => $post_id,
        'source' => $source,
        'link_location' => $location,
        'block_index' => $block_index,
        'occurrence' => $occurrence,
        'row_id' => $row_id,
        'old_link' => $old_link,
        'message' => $contextMessage,
      ]);
      $this->safe_redirect_back($filters, ['lm_msg' => sprintf(__('Failed: %s', 'links-manager'), $contextMessage)]);
    }

    $currentRow = (array)($currentRowResult['row'] ?? []);
    $currentLink = (string)($currentRow['link'] ?? '');
    if ($this->normalize_for_compare($currentLink) !== $this->normalize_for_compare($old_link)) {
      $this->record_link_update_diagnostic('single_update_target_compare', 'failed', [
        'post_id' => $post_id,
        'source' => $source,
        'link_location' => $location,
        'block_index' => $block_index,
        'occurrence' => $occurrence,
        'row_id' => $row_id,
        'old_link' => $old_link,
        'current_link' => $currentLink,
      ]);
      $this->safe_redirect_back($filters, ['lm_msg' => __('Failed: Link target changed. Reload this page or run Refresh Data and try again.', 'links-manager')]);
    }

    $res = $this->update_post_by_context($post_id, $old_link, $source, $location, $block_index, $occurrence, $effective_new_link, $new_rel, $new_anchor);
    if (!$res['ok']) {
      $this->record_link_update_diagnostic('single_update_apply', 'failed', [
        'post_id' => $post_id,
        'source' => $source,
        'link_location' => $location,
        'block_index' => $block_index,
        'occurrence' => $occurrence,
        'row_id' => $row_id,
        'old_link' => $old_link,
        'effective_new_link' => $effective_new_link,
        'message' => isset($res['msg']) ? (string)$res['msg'] : '',
      ]);
    }

    $this->clear_cache_all();

    $old_rel = $this->request_text('old_rel', '');
    $this->log_audit_trail(
      'update_single',
      $post_id,
      $old_link,
      $effective_new_link,
      $old_rel,
      $new_rel,
      $res['ok'] ? 1 : 0,
      $res['ok'] ? 'success' : 'failed',
      $res['msg']
    );

    $msg = $res['ok']
      ? sprintf(__('Success: %s', 'links-manager'), $res['msg'])
      : sprintf(__('Failed: %s', 'links-manager'), $res['msg']);
    $this->safe_redirect_back($filters, ['lm_msg' => $msg]);
  }

  public function handle_update_link_ajax() {
    if (!$this->current_user_can_access_plugin()) {
      wp_send_json_error(['msg' => $this->unauthorized_message()], 403);
    }

    $nonce = $this->request_text(self::NONCE_NAME, '');
    if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) {
      wp_send_json_error(['msg' => $this->invalid_nonce_message()], 403);
    }

    $post_id = $this->request_int('post_id', 0);
    $old_link = $this->request_text('old_link', '');
    $new_link = $this->request_has('new_link') ? esc_url_raw((string)$this->request_raw('new_link', '')) : '';
    $new_rel  = $this->request_text('new_rel', '');
    $old_anchor = $this->request_text('old_anchor', '');
    $new_anchor_raw = $this->request_has('new_anchor') ? (string)$this->request_raw('new_anchor', '') : null;
    $new_anchor = $this->normalize_new_anchor_input($new_anchor_raw, $old_anchor);
    $old_snippet = $this->request_text('old_snippet', '');

    $source = $this->request_text('source', '');
    $location = $this->request_text('link_location', '');
    $block_index = $this->request_text('block_index', '');
    $occurrence = $this->request_int('occurrence', 0);
    $row_id = $this->request_text('row_id', '');

    if (!$this->current_user_can_edit_link_target($post_id, $source)) {
      wp_send_json_error(['msg' => $this->unauthorized_message()], 403);
    }

    $has_change = ($new_link !== '') || ($new_rel !== '') || ($new_anchor !== null);
    $effective_new_link = $new_link !== '' ? $new_link : $old_link;
    $isMenuSource = ($source === 'menu');

    if ((!$isMenuSource && $post_id <= 0) || $old_link === '' || $effective_new_link === '' || $source === '' || $location === '' || $row_id === '' || !$has_change) {
      $msg = __('Failed: incomplete input.', 'links-manager');
      if (!$has_change) $msg = __('Failed: no changes provided.', 'links-manager');
      $this->record_link_update_diagnostic('ajax_update_validate', 'failed', [
        'post_id' => $post_id,
        'source' => $source,
        'link_location' => $location,
        'block_index' => $block_index,
        'occurrence' => $occurrence,
        'row_id' => $row_id,
        'old_link' => $old_link,
        'effective_new_link' => $effective_new_link,
        'has_change' => $has_change,
        'message' => $msg,
      ]);
      wp_send_json_error(['msg' => $msg], 400);
    }

    $currentRowResult = $this->get_current_row_for_update_context($post_id, $source, $location, $block_index, $occurrence);
    if (empty($currentRowResult['ok'])) {
      $contextMessage = isset($currentRowResult['msg']) ? (string)$currentRowResult['msg'] : __('Target link not found (content changed?)', 'links-manager');
      $this->record_link_update_diagnostic('ajax_update_context_lookup', 'failed', [
        'post_id' => $post_id,
        'source' => $source,
        'link_location' => $location,
        'block_index' => $block_index,
        'occurrence' => $occurrence,
        'row_id' => $row_id,
        'old_link' => $old_link,
        'message' => $contextMessage,
      ]);
      wp_send_json_error(['msg' => sprintf(__('Failed: %s', 'links-manager'), $contextMessage)], 409);
    }

    $currentRow = (array)($currentRowResult['row'] ?? []);
    $currentLink = (string)($currentRow['link'] ?? '');
    if ($this->normalize_for_compare($currentLink) !== $this->normalize_for_compare($old_link)) {
      $this->record_link_update_diagnostic('ajax_update_target_compare', 'failed', [
        'post_id' => $post_id,
        'source' => $source,
        'link_location' => $location,
        'block_index' => $block_index,
        'occurrence' => $occurrence,
        'row_id' => $row_id,
        'old_link' => $old_link,
        'current_link' => $currentLink,
      ]);
      wp_send_json_error(['msg' => __('Failed: Link target changed. Reload this page or run Refresh Data and try again.', 'links-manager')], 409);
    }

    $res = $this->update_post_by_context($post_id, $old_link, $source, $location, $block_index, $occurrence, $effective_new_link, $new_rel, $new_anchor);
    if (!$res['ok']) {
      $this->record_link_update_diagnostic('ajax_update_apply', 'failed', [
        'post_id' => $post_id,
        'source' => $source,
        'link_location' => $location,
        'block_index' => $block_index,
        'occurrence' => $occurrence,
        'row_id' => $row_id,
        'old_link' => $old_link,
        'effective_new_link' => $effective_new_link,
        'message' => isset($res['msg']) ? (string)$res['msg'] : '',
      ]);
    }
    $this->clear_cache_all();

    $old_rel = $this->request_text('old_rel', '');
    $this->log_audit_trail(
      'update_single',
      $post_id,
      $old_link,
      $effective_new_link,
      $old_rel,
      $new_rel,
      $res['ok'] ? 1 : 0,
      $res['ok'] ? 'success' : 'failed',
      $res['msg']
    );

    $effective_rel_raw = $new_rel !== '' ? $new_rel : $old_rel;
    $effective_flags = $this->parse_rel_flags($effective_rel_raw);
    $effective_rel_parts = [];
    if ($effective_flags['nofollow']) $effective_rel_parts[] = 'nofollow';
    if ($effective_flags['sponsored']) $effective_rel_parts[] = 'sponsored';
    if ($effective_flags['ugc']) $effective_rel_parts[] = 'ugc';
    $effective_rel_text = !empty($effective_rel_parts) ? implode(', ', $effective_rel_parts) : 'dofollow';

    $anchor_quality = $this->get_anchor_quality_suggestion($new_anchor !== null ? $new_anchor : $old_anchor);
    $anchor_quality_label = 'Good';
    if ((string)($anchor_quality['quality'] ?? '') === 'poor') $anchor_quality_label = 'Poor';
    if ((string)($anchor_quality['quality'] ?? '') === 'bad') $anchor_quality_label = 'Bad';

    $effective_anchor = $new_anchor !== null ? $new_anchor : $old_anchor;
    $updated_snippet_full = $old_snippet;
    if ($updated_snippet_full !== '' && $old_anchor !== '' && $effective_anchor !== '' && $effective_anchor !== $old_anchor) {
      $quoted_old_anchor = preg_quote($old_anchor, '/');
      $updated_snippet_full = preg_replace_callback('/' . $quoted_old_anchor . '/iu', function() use ($effective_anchor) {
        return $effective_anchor;
      }, $updated_snippet_full, 1);
    }
    $updated_snippet_display = $this->text_snippet_with_anchor_offset($updated_snippet_full, $effective_anchor, 60, 4);
    $updated_row_id = $this->row_id(
      $isMenuSource ? '' : (string)$post_id,
      $source,
      $location,
      $block_index,
      $occurrence,
      $this->normalize_for_compare($effective_new_link)
    );

    $response = [
      'msg' => $res['msg'],
      'updated_row_id' => $updated_row_id,
      'updated_link' => $effective_new_link,
      'updated_anchor' => $effective_anchor,
      'updated_rel_raw' => $effective_rel_raw,
      'updated_rel_text' => $effective_rel_text,
      'updated_quality' => $anchor_quality_label,
      'updated_snippet_full' => $updated_snippet_full,
      'updated_snippet_display' => $updated_snippet_display,
    ];

    if ($res['ok']) {
      wp_send_json_success($response);
    }
    wp_send_json_error($response, 400);
  }

  public function handle_bulk_update() {
    if (!$this->current_user_can_access_plugin()) wp_die($this->unauthorized_message());

    $nonce = $this->request_text(self::NONCE_NAME, '');
    if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) wp_die($this->invalid_nonce_message());

    $filters = $this->get_filters_from_request();

    if (empty($_FILES['lm_csv']) || !is_array($_FILES['lm_csv'])) {
      $this->safe_redirect_back($filters, ['lm_msg' => __('Failed: CSV file not found.', 'links-manager')]);
    }

    $file = $_FILES['lm_csv'];
    $uploadError = isset($file['error']) ? (int)$file['error'] : UPLOAD_ERR_NO_FILE;
    if ($uploadError !== UPLOAD_ERR_OK) {
      $this->safe_redirect_back($filters, ['lm_msg' => __('Failed: upload error.', 'links-manager')]);
    }

    $originalName = isset($file['name']) ? sanitize_file_name((string)$file['name']) : '';
    $fileType = wp_check_filetype($originalName);
    $ext = strtolower((string)($fileType['ext'] ?? ''));
    if ($ext !== 'csv') {
      $this->safe_redirect_back($filters, ['lm_msg' => __('Failed: file must be CSV (.csv).', 'links-manager')]);
    }

    $tmp = isset($file['tmp_name']) ? (string)$file['tmp_name'] : '';
    if ($tmp === '' || !is_uploaded_file($tmp)) {
      $this->safe_redirect_back($filters, ['lm_msg' => __('Failed: invalid upload temporary file.', 'links-manager')]);
    }

    $fh = fopen($tmp, 'r');
    if (!$fh) $this->safe_redirect_back($filters, ['lm_msg' => __('Failed: cannot read CSV.', 'links-manager')]);

    $delimiter = $this->detect_csv_delimiter($tmp);
    $header = fgetcsv($fh, 0, $delimiter);
    if (!$header) {
      fclose($fh);
      $this->safe_redirect_back($filters, ['lm_msg' => __('Failed: CSV is empty.', 'links-manager')]);
    }

    if (isset($header[0])) {
      $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header[0]);
    }

    $header = array_map(function($h) { return strtolower(trim((string)$h)); }, $header);
    $idx = array_flip($header);

    $required = ['post_id', 'old_link', 'row_id'];
    foreach ($required as $req) {
      if (!isset($idx[$req])) {
        fclose($fh);
        $this->safe_redirect_back($filters, ['lm_msg' => __('Failed: required header: post_id, old_link, row_id (optional: new_link, new_rel, new_anchor).', 'links-manager')]);
      }
    }

    $hasSource = isset($idx['source']);
    $hasLocation = isset($idx['link_location']);
    $hasBlockIndex = isset($idx['block_index']);
    $hasOccurrence = isset($idx['occurrence']);

    $needsContextLookup = !($hasSource && $hasLocation && $hasBlockIndex && $hasOccurrence);
    $parsedRows = [];
    $lookupRowIds = [];
    while (($rawRow = fgetcsv($fh, 0, $delimiter)) !== false) {
      $entry = [
        'post_id' => intval($rawRow[$idx['post_id']] ?? 0),
        'old_link' => sanitize_text_field((string)($rawRow[$idx['old_link']] ?? '')),
        'row_id' => sanitize_text_field((string)($rawRow[$idx['row_id']] ?? '')),
        'new_link' => isset($idx['new_link']) ? esc_url_raw((string)($rawRow[$idx['new_link']] ?? '')) : '',
        'new_rel' => isset($idx['new_rel']) ? sanitize_text_field((string)($rawRow[$idx['new_rel']] ?? '')) : '',
        'new_anchor' => array_key_exists('new_anchor', $idx)
          ? $this->normalize_new_anchor_input((string)($rawRow[$idx['new_anchor']] ?? ''), null)
          : null,
        'raw' => $rawRow,
      ];
      $parsedRows[] = $entry;
      if ($needsContextLookup && $entry['row_id'] !== '') {
        $lookupRowIds[$entry['row_id']] = true;
      }
    }
    fclose($fh);

    $rowMap = [];
    if ($needsContextLookup && !empty($lookupRowIds)) {
      $wpmlLang = isset($filters['wpml_lang']) ? $filters['wpml_lang'] : 'all';
      $rowMap = $this->get_indexed_fact_row_map_by_row_ids(array_keys($lookupRowIds), $wpmlLang, true);

      if (count($rowMap) < count($lookupRowIds)) {
        $cacheAny = $this->get_canonical_rows_for_scope('any', false, $wpmlLang);
        foreach ($cacheAny as $r) {
          $rid = isset($r['row_id']) ? (string)$r['row_id'] : '';
          if ($rid === '' || isset($rowMap[$rid])) continue;
          if (!isset($lookupRowIds[$rid])) continue;
          $rowMap[$rid] = $r;
        }
      }
    }

    $totalRows = 0;
    $ok = 0;
    $fail = 0;

    foreach ($parsedRows as $entry) {
      $totalRows++;

      $post_id = (int)$entry['post_id'];
      $old_link = (string)$entry['old_link'];
      $row_id = (string)$entry['row_id'];
      $new_link = (string)$entry['new_link'];
      $new_rel = (string)$entry['new_rel'];
      $new_anchor = $entry['new_anchor'];

      $has_change = ($new_link !== '') || ($new_rel !== '') || ($new_anchor !== null);
      $effective_new_link = $new_link !== '' ? $new_link : $old_link;

      if ($old_link === '' || $row_id === '' || $effective_new_link === '' || !$has_change) {
        $this->record_link_update_diagnostic('bulk_update_validate', 'failed', [
          'post_id' => $post_id,
          'row_id' => $row_id,
          'old_link' => $old_link,
          'effective_new_link' => $effective_new_link,
          'has_change' => $has_change,
        ]);
        $fail++;
        continue;
      }

      $source = '';
      $location = '';
      $block_index = '';
      $occurrence = 0;

      if ($hasSource && $hasLocation && $hasBlockIndex && $hasOccurrence) {
        $raw = (array)$entry['raw'];
        $source = sanitize_text_field((string)($raw[$idx['source']] ?? ''));
        $location = sanitize_text_field((string)($raw[$idx['link_location']] ?? ''));
        $block_index = sanitize_text_field((string)($raw[$idx['block_index']] ?? ''));
        $occurrence = intval($raw[$idx['occurrence']] ?? 0);
      } else {
        if (!isset($rowMap[$row_id])) {
          $this->record_link_update_diagnostic('bulk_update_context_lookup', 'failed', [
            'post_id' => $post_id,
            'row_id' => $row_id,
            'old_link' => $old_link,
            'message' => 'Row not found in lookup map.',
          ]);
          $fail++;
          continue;
        }
        $found = $rowMap[$row_id];

        $expectedPostId = ((string)$found['source'] === 'menu') ? '' : (string)$post_id;
        if ((string)$found['post_id'] !== $expectedPostId) {
          $this->record_link_update_diagnostic('bulk_update_context_lookup', 'failed', [
            'post_id' => $post_id,
            'row_id' => $row_id,
            'old_link' => $old_link,
            'found_post_id' => (string)$found['post_id'],
            'expected_post_id' => $expectedPostId,
            'message' => 'Lookup post_id mismatch.',
          ]);
          $fail++;
          continue;
        }
        if ($this->normalize_for_compare((string)$found['link']) !== $this->normalize_for_compare($old_link)) {
          $this->record_link_update_diagnostic('bulk_update_context_lookup', 'failed', [
            'post_id' => $post_id,
            'row_id' => $row_id,
            'old_link' => $old_link,
            'current_link' => (string)$found['link'],
            'message' => 'Lookup link mismatch.',
          ]);
          $fail++;
          continue;
        }

        $source = (string)$found['source'];
        $location = (string)$found['link_location'];
        $block_index = (string)$found['block_index'];
        $occurrence = intval($found['occurrence'] ?? 0);
      }

      if ($source !== 'menu' && $post_id <= 0) {
        $this->record_link_update_diagnostic('bulk_update_validate', 'failed', [
          'post_id' => $post_id,
          'row_id' => $row_id,
          'source' => $source,
          'message' => 'Invalid post_id for non-menu source.',
        ]);
        $fail++;
        continue;
      }

      if (!$this->current_user_can_edit_link_target($post_id, $source)) {
        $this->record_link_update_diagnostic('bulk_update_permission', 'failed', [
          'post_id' => $post_id,
          'row_id' => $row_id,
          'source' => $source,
        ]);
        $fail++;
        continue;
      }

      $currentRowResult = $this->get_current_row_for_update_context($post_id, $source, $location, $block_index, $occurrence);
      if (empty($currentRowResult['ok'])) {
        $this->record_link_update_diagnostic('bulk_update_context_lookup', 'failed', [
          'post_id' => $post_id,
          'row_id' => $row_id,
          'source' => $source,
          'link_location' => $location,
          'block_index' => $block_index,
          'occurrence' => $occurrence,
          'message' => isset($currentRowResult['msg']) ? (string)$currentRowResult['msg'] : 'Context lookup failed.',
        ]);
        $fail++;
        continue;
      }
      $currentRow = (array)($currentRowResult['row'] ?? []);
      if ($this->normalize_for_compare((string)($currentRow['link'] ?? '')) !== $this->normalize_for_compare($old_link)) {
        $this->record_link_update_diagnostic('bulk_update_target_compare', 'failed', [
          'post_id' => $post_id,
          'row_id' => $row_id,
          'source' => $source,
          'link_location' => $location,
          'block_index' => $block_index,
          'occurrence' => $occurrence,
          'old_link' => $old_link,
          'current_link' => (string)($currentRow['link'] ?? ''),
        ]);
        $fail++;
        continue;
      }

      $res = $this->update_post_by_context($post_id, $old_link, $source, $location, $block_index, $occurrence, $effective_new_link, $new_rel, $new_anchor);
      if (!$res['ok']) {
        $this->record_link_update_diagnostic('bulk_update_apply', 'failed', [
          'post_id' => $post_id,
          'row_id' => $row_id,
          'source' => $source,
          'link_location' => $location,
          'block_index' => $block_index,
          'occurrence' => $occurrence,
          'old_link' => $old_link,
          'effective_new_link' => $effective_new_link,
          'message' => isset($res['msg']) ? (string)$res['msg'] : '',
        ]);
      }

      $this->log_audit_trail(
        'update_bulk',
        $post_id,
        $old_link,
        $effective_new_link,
        '',
        $new_rel,
        $res['ok'] ? 1 : 0,
        $res['ok'] ? 'success' : 'failed',
        $res['msg']
      );

      if ($res['ok']) $ok++;
      else $fail++;
    }

    $this->clear_cache_all();

    $filters['post_type'] = 'any';
    $filters['post_category'] = 0;
    $filters['post_tag'] = 0;
    $filters['location'] = 'any';
    $filters['source_type'] = 'any';
    $filters['link_type'] = 'any';
    $filters['value_type'] = 'any';
    $filters['quality'] = 'any';
    $filters['seo_flag'] = 'any';
    $filters['value_contains'] = '';
    $filters['source_contains'] = '';
    $filters['title_contains'] = '';
    $filters['author'] = 0;
    $filters['publish_date_from'] = '';
    $filters['publish_date_to'] = '';
    $filters['updated_date_from'] = '';
    $filters['updated_date_to'] = '';
    $filters['anchor_contains'] = '';
    $filters['alt_contains'] = '';
    $filters['rel_contains'] = '';
    $filters['group'] = '0';
    $filters['paged'] = 1;

    $this->safe_redirect_back($filters, [
      'lm_msg' => sprintf(
        __('Bulk finished. Rows: %1$d | OK: %2$d | Failed: %3$d', 'links-manager'),
        (int)$totalRows,
        (int)$ok,
        (int)$fail
      )
    ]);
  }

  public function handle_save_anchor_groups() {
    if (!$this->current_user_can_access_plugin()) wp_die($this->unauthorized_message());
    $nonce = $this->request_text(self::NONCE_NAME, '');
    if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) wp_die($this->invalid_nonce_message());

    $name = $this->request_text('lm_group_name', '');
    $anchorsRaw = (string)$this->request_raw('lm_group_anchors', '');
    $anchors = $this->normalize_anchor_list($anchorsRaw);
    if ($name !== '') {
      $groups = $this->get_anchor_groups();
      $groups[] = ['name' => $name, 'anchors' => $anchors];
      $this->save_anchor_groups($groups);
    }

    wp_safe_redirect($this->admin_page_url('links-manager-target', ['lm_msg' => 'Group saved.']));
    exit;
  }

  public function handle_delete_anchor_group() {
    if (!$this->current_user_can_access_plugin()) wp_die($this->unauthorized_message());
    $nonce = $this->request_text(self::NONCE_NAME, '');
    if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) wp_die($this->invalid_nonce_message());

    $idx = $this->request_int('lm_group_idx', -1);
    $groups = $this->get_anchor_groups();
    if ($idx >= 0 && isset($groups[$idx])) {
      array_splice($groups, $idx, 1);
      $this->save_anchor_groups($groups);
    }

    wp_safe_redirect($this->admin_page_url('links-manager-target', ['lm_msg' => 'Group deleted.']));
    exit;
  }

  public function handle_bulk_delete_anchor_groups() {
    if (!$this->current_user_can_access_plugin()) wp_die($this->unauthorized_message());
    $nonce = $this->request_text(self::NONCE_NAME, '');
    if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) wp_die($this->invalid_nonce_message());

    $rawIndices = $this->request_array('lm_group_indices');
    $indices = array_values(array_unique(array_filter(array_map('intval', $rawIndices), function($idx) {
      return $idx >= 0;
    })));

    if (empty($indices)) {
      wp_safe_redirect($this->admin_page_url('links-manager-target', ['lm_msg' => 'No group selected.']));
      exit;
    }

    rsort($indices);
    $groups = $this->get_anchor_groups();
    $deleted = 0;
    foreach ($indices as $idx) {
      if (isset($groups[$idx])) {
        array_splice($groups, $idx, 1);
        $deleted++;
      }
    }

    if ($deleted > 0) {
      $this->save_anchor_groups($groups);
      $msg = 'Deleted ' . $deleted . ' group(s).';
    } else {
      $msg = 'No groups were deleted.';
    }

    wp_safe_redirect($this->admin_page_url('links-manager-target', ['lm_msg' => $msg]));
    exit;
  }

  public function handle_update_anchor_group() {
    if (!$this->current_user_can_access_plugin()) wp_die($this->unauthorized_message());
    $nonce = $this->request_text(self::NONCE_NAME, '');
    if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) wp_die($this->invalid_nonce_message());

    $idx = $this->request_int('lm_group_idx', -1);
    $name = $this->request_text('lm_group_name', '');
    $anchorsRaw = (string)$this->request_raw('lm_group_anchors', '');
    $anchors = $this->normalize_anchor_list($anchorsRaw);

    $groups = $this->get_anchor_groups();
    if ($idx < 0 || !isset($groups[$idx])) {
      wp_safe_redirect($this->admin_page_url('links-manager-target', ['lm_msg' => 'Group not found.']));
      exit;
    }

    if ($name === '') {
      wp_safe_redirect($this->admin_page_url('links-manager-target', ['lm_msg' => 'Group name is required.']));
      exit;
    }

    $groups[$idx] = ['name' => $name, 'anchors' => $anchors];
    $this->save_anchor_groups($groups);

    wp_safe_redirect($this->admin_page_url('links-manager-target', ['lm_msg' => 'Group updated.']));
    exit;
  }

  public function handle_save_anchor_targets() {
    if (!$this->current_user_can_access_plugin()) wp_die($this->unauthorized_message());
    $nonce = $this->request_text(self::NONCE_NAME, '');
    if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) wp_die($this->invalid_nonce_message());

    $mode = $this->request_text('lm_anchor_mode', 'only');
    if (!in_array($mode, ['only', 'group'], true)) $mode = 'only';

    $targetsRaw = (string)wp_unslash($this->request_raw('lm_anchor_targets', ''));
    $targets = [];
    $groups = $this->get_anchor_groups();
    $existingTargets = $this->get_anchor_targets();

    $lines = preg_split('/[\r\n]+/', $targetsRaw);
    foreach ($lines as $line) {
      $line = trim((string)$line);
      if ($line === '') continue;

      if ($mode === 'group') {
        $parts = array_map('trim', explode(',', $line, 2));
        $anchor = $parts[0] ?? '';
        $groupName = $parts[1] ?? '';
        if ($anchor !== '') $targets[] = $anchor;
        if ($anchor !== '' && $groupName !== '') {
          $found = false;
          foreach ($groups as &$g) {
            if ((string)($g['name'] ?? '') === $groupName) {
              $anchors = isset($g['anchors']) ? (array)$g['anchors'] : [];
              $anchors[] = $anchor;
              $g['anchors'] = $this->normalize_anchor_list(implode("\n", $anchors));
              $found = true;
              break;
            }
          }
          unset($g);
          if (!$found) {
            $groups[] = ['name' => $groupName, 'anchors' => [$anchor]];
          }
        }
      } else {
        $targets[] = $line;
      }
    }

    $targets = $this->normalize_anchor_list(implode("\n", array_merge((array)$existingTargets, $targets)));
    if (!empty($targets)) $this->save_anchor_targets($targets);
    if (!empty($groups)) $this->save_anchor_groups($groups);

    wp_safe_redirect($this->admin_page_url('links-manager-target', ['lm_msg' => 'Targets saved.']));
    exit;
  }

  public function handle_update_anchor_target() {
    if (!$this->current_user_can_access_plugin()) wp_die($this->unauthorized_message());
    $nonce = $this->request_text(self::NONCE_NAME, '');
    if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) wp_die($this->invalid_nonce_message());

    $idx = $this->request_int('lm_target_idx', -1);
    $newVal = $this->request_text('lm_target_value', '');
    $newVal = trim($newVal);

    $targets = $this->get_anchor_targets();
    if ($idx < 0 || !isset($targets[$idx])) {
      wp_safe_redirect($this->admin_page_url('links-manager-target', ['lm_msg' => 'Target not found.']));
      exit;
    }

    $oldVal = trim((string)$targets[$idx]);

    if ($newVal === '') {
      wp_safe_redirect($this->admin_page_url('links-manager-target', ['lm_msg' => 'Target cannot be empty.']));
      exit;
    }

    $targets[$idx] = $newVal;
    $targets = $this->normalize_anchor_list(implode("\n", $targets));
    $this->save_anchor_targets($targets);

    if ($oldVal !== '' && strtolower($oldVal) !== strtolower($newVal)) {
      $groups = $this->get_anchor_groups();
      $groupsChanged = false;
      foreach ($groups as &$group) {
        $anchors = isset($group['anchors']) ? (array)$group['anchors'] : [];
        $updatedAnchors = [];
        $localChanged = false;
        foreach ($anchors as $anchor) {
          $anchor = trim((string)$anchor);
          if ($anchor === '') {
            continue;
          }
          if (strtolower($anchor) === strtolower($oldVal)) {
            $updatedAnchors[] = $newVal;
            $localChanged = true;
          } else {
            $updatedAnchors[] = $anchor;
          }
        }

        if ($localChanged) {
          $group['anchors'] = $this->normalize_anchor_list(implode("\n", $updatedAnchors));
          $groupsChanged = true;
        }
      }
      unset($group);

      if ($groupsChanged) {
        $this->save_anchor_groups($groups);
      }
    }

    wp_safe_redirect($this->admin_page_url('links-manager-target', ['lm_msg' => 'Target updated.']));
    exit;
  }

  public function handle_update_anchor_target_group() {
    if (!$this->current_user_can_access_plugin()) wp_die($this->unauthorized_message());
    $nonce = $this->request_text(self::NONCE_NAME, '');
    if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) wp_die($this->invalid_nonce_message());

    $anchor = $this->request_text('lm_anchor_value', '');
    $anchor = trim($anchor);
    $selectedGroups = $this->get_selected_anchor_group_names_from_request('lm_anchor_groups', 'lm_anchor_group');

    if ($anchor === '') {
      wp_safe_redirect($this->admin_page_url('links-manager-target', ['lm_msg' => 'Invalid anchor.']));
      exit;
    }

    $groups = $this->get_anchor_groups();

    foreach ($groups as &$g) {
      $anchors = isset($g['anchors']) ? (array)$g['anchors'] : [];
      $anchors = array_values(array_filter($anchors, function($a) use ($anchor) {
        return strtolower(trim((string)$a)) !== strtolower($anchor);
      }));
      $g['anchors'] = $anchors;
    }
    unset($g);

    foreach ($selectedGroups as $selectedGroup) {
      $found = false;
      foreach ($groups as &$g) {
        $gname = isset($g['name']) ? (string)$g['name'] : '';
        if ($gname !== $selectedGroup) {
          continue;
        }
        $anchors = isset($g['anchors']) ? (array)$g['anchors'] : [];
        $anchors[] = $anchor;
        $g['anchors'] = $this->normalize_anchor_list(implode("\n", $anchors));
        $found = true;
        break;
      }
      unset($g);
      if (!$found) {
        wp_safe_redirect($this->admin_page_url('links-manager-target', ['lm_msg' => 'Group not found.']));
        exit;
      }
    }

    $this->save_anchor_groups($groups);

    $message = empty($selectedGroups) ? 'Anchor removed from all groups.' : 'Anchor groups updated.';
    wp_safe_redirect($this->admin_page_url('links-manager-target', ['lm_msg' => $message]));
    exit;
  }

  public function handle_update_anchor_target_group_ajax() {
    if (!$this->current_user_can_access_plugin()) {
      wp_send_json_error(['msg' => $this->unauthorized_message()], 403);
    }

    $nonce = $this->request_text(self::NONCE_NAME, '');
    if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) {
      wp_send_json_error(['msg' => $this->invalid_nonce_message()], 403);
    }

    $anchor = $this->request_text('lm_anchor_value', '');
    $anchor = trim($anchor);
    $selectedGroups = $this->get_selected_anchor_group_names_from_request('lm_anchor_groups', 'lm_anchor_group');

    if ($anchor === '') {
      wp_send_json_error(['msg' => 'Invalid anchor.'], 400);
    }

    $groups = $this->get_anchor_groups();

    foreach ($groups as &$g) {
      $anchors = isset($g['anchors']) ? (array)$g['anchors'] : [];
      $anchors = array_values(array_filter($anchors, function($a) use ($anchor) {
        return strtolower(trim((string)$a)) !== strtolower($anchor);
      }));
      $g['anchors'] = $anchors;
    }
    unset($g);

    foreach ($selectedGroups as $selectedGroup) {
      $found = false;
      foreach ($groups as &$g) {
        $gname = isset($g['name']) ? (string)$g['name'] : '';
        if ($gname !== $selectedGroup) {
          continue;
        }
        $anchors = isset($g['anchors']) ? (array)$g['anchors'] : [];
        $anchors[] = $anchor;
        $g['anchors'] = $this->normalize_anchor_list(implode("\n", $anchors));
        $found = true;
        break;
      }
      unset($g);
      if (!$found) {
        wp_send_json_error(['msg' => 'Group not found.'], 404);
      }
    }

    $this->save_anchor_groups($groups);

    $response = [
      'msg' => empty($selectedGroups) ? 'Anchor removed from all groups.' : 'Groups updated successfully.',
      'updated_groups' => $selectedGroups,
    ];

    wp_send_json_success($response);
  }

  public function handle_bulk_update_anchor_target_group() {
    if (!$this->current_user_can_access_plugin()) wp_die($this->unauthorized_message());
    $nonce = $this->request_text(self::NONCE_NAME, '');
    if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) wp_die($this->invalid_nonce_message());

    $rawIndices = $this->request_array('lm_target_indices');
    $indices = array_values(array_unique(array_filter(array_map('intval', $rawIndices), function($idx) {
      return $idx >= 0;
    })));

    if (empty($indices)) {
      wp_safe_redirect($this->admin_page_url('links-manager-target', ['lm_msg' => 'No target selected.']));
      exit;
    }

    $selectedGroups = $this->get_selected_anchor_group_names_from_request('lm_bulk_anchor_groups', 'lm_bulk_anchor_group');
    $targets = $this->get_anchor_targets();
    $selectedAnchors = [];
    foreach ($indices as $idx) {
      if (!isset($targets[$idx])) continue;
      $anchor = trim((string)$targets[$idx]);
      if ($anchor === '') continue;
      $selectedAnchors[strtolower($anchor)] = $anchor;
    }

    if (empty($selectedAnchors)) {
      wp_safe_redirect($this->admin_page_url('links-manager-target', ['lm_msg' => 'No valid targets selected.']));
      exit;
    }

    $groups = $this->get_anchor_groups();
    foreach ($groups as &$g) {
      $anchors = isset($g['anchors']) ? (array)$g['anchors'] : [];
      $anchors = array_values(array_filter($anchors, function($a) use ($selectedAnchors) {
        $key = strtolower(trim((string)$a));
        return $key !== '' && !isset($selectedAnchors[$key]);
      }));
      $g['anchors'] = $anchors;
    }
    unset($g);

    foreach ($selectedGroups as $selectedGroup) {
      $found = false;
      foreach ($groups as &$g) {
        $gname = isset($g['name']) ? (string)$g['name'] : '';
        if ($gname !== $selectedGroup) {
          continue;
        }
        $anchors = isset($g['anchors']) ? (array)$g['anchors'] : [];
        foreach ($selectedAnchors as $anchor) {
          $anchors[] = $anchor;
        }
        $g['anchors'] = $this->normalize_anchor_list(implode("\n", $anchors));
        $found = true;
        break;
      }
      unset($g);

      if (!$found) {
        wp_safe_redirect($this->admin_page_url('links-manager-target', ['lm_msg' => 'Group not found.']));
        exit;
      }
    }

    $this->save_anchor_groups($groups);

    $msg = empty($selectedGroups)
      ? ('Removed all groups from ' . count($selectedAnchors) . ' target(s).')
      : ('Updated groups for ' . count($selectedAnchors) . ' target(s).');
    wp_safe_redirect($this->admin_page_url('links-manager-target', ['lm_msg' => $msg]));
    exit;
  }

  public function handle_delete_anchor_target() {
    if (!$this->current_user_can_access_plugin()) wp_die($this->unauthorized_message());
    $nonce = $this->request_text(self::NONCE_NAME, '');
    if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) wp_die($this->invalid_nonce_message());

    $idx = $this->request_int('lm_target_idx', -1);
    $targets = $this->get_anchor_targets();
    $deletedAnchor = '';
    if ($idx >= 0 && isset($targets[$idx])) {
      $deletedAnchor = (string)$targets[$idx];
      array_splice($targets, $idx, 1);
      $this->save_anchor_targets($targets);
    }

    if ($deletedAnchor !== '') {
      $groups = $this->get_anchor_groups();
      foreach ($groups as &$g) {
        $anchors = isset($g['anchors']) ? (array)$g['anchors'] : [];
        $anchors = array_values(array_filter($anchors, function($a) use ($deletedAnchor) {
          return strtolower(trim((string)$a)) !== strtolower(trim($deletedAnchor));
        }));
        $g['anchors'] = $anchors;
      }
      unset($g);
      $this->save_anchor_groups($groups);
    }

    wp_safe_redirect($this->admin_page_url('links-manager-target', ['lm_msg' => 'Target deleted.']));
    exit;
  }

  public function handle_bulk_delete_anchor_targets() {
    if (!$this->current_user_can_access_plugin()) wp_die($this->unauthorized_message());
    $nonce = $this->request_text(self::NONCE_NAME, '');
    if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) wp_die($this->invalid_nonce_message());

    $rawIndices = $this->request_array('lm_target_indices');
    $indices = array_values(array_unique(array_filter(array_map('intval', $rawIndices), function($idx) {
      return $idx >= 0;
    })));

    if (empty($indices)) {
      wp_safe_redirect($this->admin_page_url('links-manager-target', ['lm_msg' => 'No target selected.']));
      exit;
    }

    rsort($indices);
    $targets = $this->get_anchor_targets();
    $deletedAnchors = [];

    foreach ($indices as $idx) {
      if (!isset($targets[$idx])) continue;
      $deletedAnchors[] = (string)$targets[$idx];
      array_splice($targets, $idx, 1);
    }

    $deletedAnchors = array_values(array_unique(array_filter(array_map('trim', $deletedAnchors))));
    if (empty($deletedAnchors)) {
      wp_safe_redirect($this->admin_page_url('links-manager-target', ['lm_msg' => 'No targets were deleted.']));
      exit;
    }

    $this->save_anchor_targets($targets);

    $deletedMap = [];
    foreach ($deletedAnchors as $anchor) {
      $deletedMap[strtolower($anchor)] = true;
    }

    $groups = $this->get_anchor_groups();
    foreach ($groups as &$g) {
      $anchors = isset($g['anchors']) ? (array)$g['anchors'] : [];
      $anchors = array_values(array_filter($anchors, function($a) use ($deletedMap) {
        $k = strtolower(trim((string)$a));
        return $k !== '' && !isset($deletedMap[$k]);
      }));
      $g['anchors'] = $anchors;
    }
    unset($g);
    $this->save_anchor_groups($groups);

    $msg = 'Deleted ' . count($deletedAnchors) . ' target(s).';
    wp_safe_redirect($this->admin_page_url('links-manager-target', ['lm_msg' => $msg]));
    exit;
  }
}
