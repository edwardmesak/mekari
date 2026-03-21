<?php
/**
 * Audit trail logging and historical stats snapshot helpers.
 */

trait LM_Audit_Stats_Trait {
  private function log_audit_trail($action, $post_id, $old_url, $new_url, $old_rel, $new_rel, $changed_count, $status = 'success', $message = '') {
    global $wpdb;

    $current_user = wp_get_current_user();
    $user_id = get_current_user_id();
    $user_name = $current_user ? $current_user->display_name : 'unknown';

    $wpdb->insert(
      $wpdb->prefix . 'lm_audit_log',
      [
        'user_id' => $user_id,
        'user_name' => $user_name,
        'post_id' => $post_id,
        'action' => $action,
        'old_url' => $old_url,
        'new_url' => $new_url,
        'old_rel' => $old_rel,
        'new_rel' => $new_rel,
        'changed_count' => $changed_count,
        'status' => $status,
        'message' => $message,
      ],
      ['%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s']
    );
  }

  private function get_audit_log($limit = 50) {
    global $wpdb;
    $table = $wpdb->prefix . 'lm_audit_log';

    $logs = $wpdb->get_results(
      $wpdb->prepare(
        "SELECT * FROM $table ORDER BY timestamp DESC LIMIT %d",
        $limit
      )
    );

    return $logs ?: [];
  }

  private function record_stats_snapshot($stats) {
    global $wpdb;
    $table = $wpdb->prefix . 'lm_stats_log';

    $today = gmdate('Y-m-d');
    $last = (string)get_option('lm_stats_last_date', '');
    if ($last === $today) return;

    $wpdb->replace(
      $table,
      [
        'stat_date' => $today,
        'total_links' => (int)($stats['total_links'] ?? 0),
        'internal_links' => (int)($stats['internal_links'] ?? 0),
        'external_links' => (int)($stats['external_links'] ?? 0),
      ],
      ['%s', '%d', '%d', '%d']
    );

    update_option('lm_stats_last_date', $today);
  }

  private function get_stats_history($days = 14) {
    global $wpdb;
    $table = $wpdb->prefix . 'lm_stats_log';
    $days = max(1, (int)$days);

    $rows = $wpdb->get_results(
      $wpdb->prepare(
        "SELECT stat_date, total_links, internal_links, external_links
         FROM $table
         ORDER BY stat_date DESC
         LIMIT %d",
        $days
      ),
      ARRAY_A
    );

    return array_reverse($rows ?: []);
  }

  private function get_trend_days_from_request() {
    $days = isset($_GET['lm_trend_days']) ? intval($_GET['lm_trend_days']) : 14;
    if (!in_array($days, [7, 14, 30], true)) $days = 14;
    return $days;
  }
}
