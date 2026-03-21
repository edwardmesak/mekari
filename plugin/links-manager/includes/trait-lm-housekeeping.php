<?php
/**
 * Maintenance and data pruning helpers.
 */

if (!defined('ABSPATH')) {
  exit;
}

trait LM_Housekeeping_Trait {
  public function run_daily_maintenance() {
    $today = gmdate('Y-m-d');
    $last = (string)get_option('lm_maintenance_last_date', '');
    if ($last === $today) return;

    $this->prune_old_audit_logs();
    $this->prune_old_stats_logs();

    update_option('lm_maintenance_last_date', $today);
  }

  private function prune_old_audit_logs() {
    global $wpdb;
    $table = $wpdb->prefix . 'lm_audit_log';
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) return;

    $settings = $this->get_settings();
    $retentionDays = (int)($settings['audit_retention_days'] ?? self::AUDIT_RETENTION_DAYS);
    if ($retentionDays < 30) $retentionDays = 30;
    if ($retentionDays > 3650) $retentionDays = 3650;

    $cutoff = gmdate('Y-m-d H:i:s', time() - ($retentionDays * DAY_IN_SECONDS));
    $wpdb->query(
      $wpdb->prepare(
        "DELETE FROM $table WHERE timestamp < %s",
        $cutoff
      )
    );
  }

  private function prune_old_stats_logs() {
    global $wpdb;
    $table = $wpdb->prefix . 'lm_stats_log';
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) return;

    $retentionDays = self::STATS_RETENTION_DAYS;
    if ($retentionDays < 30) $retentionDays = 30;

    $cutoff = gmdate('Y-m-d', time() - ($retentionDays * DAY_IN_SECONDS));
    $wpdb->query(
      $wpdb->prepare(
        "DELETE FROM $table WHERE stat_date < %s",
        $cutoff
      )
    );
  }
}