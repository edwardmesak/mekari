<?php
/**
 * Database schema lifecycle helpers for Links Manager.
 */

if (!defined('ABSPATH')) {
  exit;
}

trait LM_Schema_Trait {
  public function maybe_upgrade_schema() {
    $installedVersion = (string)get_option('lm_db_version', '0');
    if (!version_compare($installedVersion, self::DB_VERSION, '<')) {
      return;
    }

    $this->run_schema_migrations($installedVersion);
    update_option('lm_db_version', self::DB_VERSION, false);
  }

  public function maybe_create_audit_table() {
    // Backward-compatible wrapper for older call sites.
    $this->maybe_upgrade_schema();
  }

  private function run_schema_migrations($installedVersion) {
    $installedVersion = (string)$installedVersion;

    $this->create_audit_table();
    $this->create_stats_table();
    $this->create_link_fact_table();
    $this->create_link_post_summary_table();

    if (version_compare($installedVersion, '4.7', '<')) {
      $this->ensure_link_fact_domain_schema();
    }

    if (version_compare($installedVersion, '4.8', '<')) {
      $this->ensure_link_fact_domain_schema();
    }

    if (version_compare($installedVersion, '4.9', '<')) {
      $this->maybe_migrate_legacy_weak_anchor_patterns();
    }
  }

  private function ensure_link_fact_domain_schema() {
    global $wpdb;

    $table = $wpdb->prefix . 'lm_link_fact';
    $tableExists = (string)$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
    if ($tableExists !== $table) {
      return;
    }

    $column = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM $table LIKE %s", 'link_domain'));
    if (empty($column)) {
      $wpdb->query("ALTER TABLE $table ADD COLUMN link_domain VARCHAR(255) NOT NULL DEFAULT '' AFTER link");
    }

    $requiredIndexes = [
      'idx_lang_link_domain' => "(wpml_lang, link_domain)",
      'idx_lang_post_type_link_domain' => "(wpml_lang, post_type, link_domain)",
      'idx_lang_link_type_domain' => "(wpml_lang, link_type, link_domain)",
      'idx_lang_post_type_link_type_domain' => "(wpml_lang, post_type, link_type, link_domain)",
      'idx_lang_post_type_link_type_source_domain' => "(wpml_lang, post_type, link_type, source, link_domain)",
      'idx_lang_post_type_link_type_location_domain' => "(wpml_lang, post_type, link_type, link_location, link_domain)",
    ];

    foreach ($requiredIndexes as $indexName => $definition) {
      $existing = $wpdb->get_var($wpdb->prepare("SHOW INDEX FROM $table WHERE Key_name = %s", $indexName));
      if (empty($existing)) {
        $wpdb->query("ALTER TABLE $table ADD INDEX $indexName $definition");
      }
    }
  }

  private function create_audit_table() {
    global $wpdb;
    $table = $wpdb->prefix . 'lm_audit_log';
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS $table (
      id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
      user_id BIGINT(20) UNSIGNED,
      user_name VARCHAR(255),
      post_id BIGINT(20) UNSIGNED,
      action VARCHAR(50),
      old_url VARCHAR(2048),
      new_url VARCHAR(2048),
      old_rel VARCHAR(255),
      new_rel VARCHAR(255),
      changed_count INT(11),
      status VARCHAR(20),
      message TEXT,
      KEY timestamp (timestamp),
      KEY post_id (post_id),
      KEY action (action)
    ) $charset;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
  }

  private function create_stats_table() {
    global $wpdb;
    $table = $wpdb->prefix . 'lm_stats_log';
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS $table (
      id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      stat_date DATE NOT NULL,
      total_links INT(11) NOT NULL DEFAULT 0,
      internal_links INT(11) NOT NULL DEFAULT 0,
      external_links INT(11) NOT NULL DEFAULT 0,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY stat_date (stat_date)
    ) $charset;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
  }

  private function create_link_fact_table() {
    global $wpdb;
    $table = $wpdb->prefix . 'lm_link_fact';
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS $table (
      id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
      wpml_lang VARCHAR(20) NOT NULL DEFAULT 'all',
      row_id VARCHAR(80) NOT NULL,
      post_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
      post_title TEXT,
      post_type VARCHAR(32) NOT NULL DEFAULT '',
      post_author VARCHAR(255) NOT NULL DEFAULT '',
      post_date DATETIME NULL,
      post_modified DATETIME NULL,
      page_url VARCHAR(1024) NOT NULL DEFAULT '',
      source VARCHAR(32) NOT NULL DEFAULT '',
      link_location VARCHAR(128) NOT NULL DEFAULT '',
      block_index VARCHAR(64) NOT NULL DEFAULT '',
      occurrence VARCHAR(32) NOT NULL DEFAULT '',
      link_type VARCHAR(16) NOT NULL DEFAULT '',
      link VARCHAR(1024) NOT NULL DEFAULT '',
      link_domain VARCHAR(255) NOT NULL DEFAULT '',
      anchor_text TEXT,
      alt_text TEXT,
      snippet LONGTEXT,
      rel_raw TEXT,
      relationship VARCHAR(255) NOT NULL DEFAULT '',
      rel_nofollow TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
      rel_sponsored TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
      rel_ugc TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
      value_type VARCHAR(20) NOT NULL DEFAULT '',
      PRIMARY KEY (id),
      UNIQUE KEY uniq_lang_row (wpml_lang, row_id),
      KEY idx_lang_post (wpml_lang, post_id),
      KEY idx_lang_post_type (wpml_lang, post_type),
      KEY idx_lang_link_type (wpml_lang, link_type),
      KEY idx_lang_post_type_date (wpml_lang, post_type, post_date),
      KEY idx_lang_post_type_modified (wpml_lang, post_type, post_modified),
      KEY idx_lang_post_type_link_type (wpml_lang, post_type, link_type),
      KEY idx_lang_post_type_source (wpml_lang, post_type, source),
      KEY idx_lang_post_type_location (wpml_lang, post_type, link_location),
      KEY idx_lang_post_type_source_date (wpml_lang, post_type, source, post_date),
      KEY idx_lang_post_type_location_date (wpml_lang, post_type, link_location, post_date),
      KEY idx_lang_post_type_date_post_row (wpml_lang, post_type, post_date, post_id, row_id),
      KEY idx_lang_post_type_source_date_post_row (wpml_lang, post_type, source, post_date, post_id, row_id),
      KEY idx_lang_post_type_location_date_post_row (wpml_lang, post_type, link_location, post_date, post_id, row_id),
      KEY idx_lang_occurrence (wpml_lang, occurrence),
      KEY idx_lang_link_domain (wpml_lang, link_domain),
      KEY idx_lang_link_type_domain (wpml_lang, link_type, link_domain),
      KEY idx_link (link(191)),
      KEY idx_page_url (page_url(191)),
      KEY idx_lang_post_type_page_url (wpml_lang, post_type, page_url(191)),
      KEY idx_lang_post_type_link (wpml_lang, post_type, link(191)),
      KEY idx_lang_post_type_link_domain (wpml_lang, post_type, link_domain),
      KEY idx_lang_post_type_link_type_domain (wpml_lang, post_type, link_type, link_domain),
      KEY idx_lang_post_type_link_type_source_domain (wpml_lang, post_type, link_type, source, link_domain),
      KEY idx_lang_post_type_link_type_location_domain (wpml_lang, post_type, link_type, link_location, link_domain)
    ) $charset;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
  }

  private function create_link_post_summary_table() {
    global $wpdb;
    $table = $wpdb->prefix . 'lm_link_post_summary';
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS $table (
      wpml_lang VARCHAR(20) NOT NULL DEFAULT 'all',
      post_id BIGINT(20) UNSIGNED NOT NULL,
      post_title TEXT,
      post_type VARCHAR(32) NOT NULL DEFAULT '',
      post_author VARCHAR(255) NOT NULL DEFAULT '',
      post_date DATETIME NULL,
      post_modified DATETIME NULL,
      page_url VARCHAR(1024) NOT NULL DEFAULT '',
      inbound INT(11) NOT NULL DEFAULT 0,
      internal_outbound INT(11) NOT NULL DEFAULT 0,
      outbound INT(11) NOT NULL DEFAULT 0,
      PRIMARY KEY (wpml_lang, post_id),
      KEY idx_lang_post_type (wpml_lang, post_type),
      KEY idx_lang_inbound (wpml_lang, inbound),
      KEY idx_lang_internal_outbound (wpml_lang, internal_outbound),
      KEY idx_lang_outbound (wpml_lang, outbound),
      KEY idx_page_url (page_url(191))
    ) $charset;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
  }
}
