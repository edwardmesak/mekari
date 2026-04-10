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
    $this->finalize_schema_upgrade($installedVersion);
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
    $this->create_link_domain_summary_table();
    $this->create_anchor_text_summary_table();

    if (version_compare($installedVersion, '4.7', '<')) {
      $this->ensure_link_fact_domain_schema();
    }

    if (version_compare($installedVersion, '4.8', '<')) {
      $this->ensure_link_fact_domain_schema();
    }

    if (version_compare($installedVersion, '4.9', '<')) {
      $this->maybe_migrate_legacy_weak_anchor_patterns();
    }

    if (version_compare($installedVersion, '5.0', '<')) {
      $this->ensure_link_fact_normalized_url_schema();
    }

    if (version_compare($installedVersion, '5.1', '<')) {
      $this->ensure_author_id_schema();
    }
  }

  private function finalize_schema_upgrade($installedVersion) {
    $installedVersion = (string)$installedVersion;

    // Drop volatile rebuild state so upgraded code never resumes an older job shape.
    $this->clear_rebuild_job_state();
    $this->release_rebuild_job_lock();
    delete_option($this->rebuild_last_finalize_metrics_option_key());

    // Invalidate cached report payloads and indexed summaries so upgraded readers only
    // see fresh data generated with the latest schema/logic.
    $this->clear_cache_all();

    // Fresh installs can remain empty until the first manual refresh; upgrades should
    // self-heal and repopulate data automatically in the background.
    if ($installedVersion !== '0') {
      $this->schedule_background_rebuild('any', 'all', 2);
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

  private function ensure_link_fact_normalized_url_schema() {
    global $wpdb;

    $table = $wpdb->prefix . 'lm_link_fact';
    $tableExists = (string)$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
    if ($tableExists !== $table) {
      return;
    }

    $normalizedPageColumn = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM $table LIKE %s", 'normalized_page_url'));
    if (empty($normalizedPageColumn)) {
      $wpdb->query("ALTER TABLE $table ADD COLUMN normalized_page_url VARCHAR(1024) NOT NULL DEFAULT '' AFTER page_url");
    }

    $normalizedLinkColumn = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM $table LIKE %s", 'normalized_link'));
    if (empty($normalizedLinkColumn)) {
      $wpdb->query("ALTER TABLE $table ADD COLUMN normalized_link VARCHAR(1024) NOT NULL DEFAULT '' AFTER link");
    }

    $requiredIndexes = [
      'idx_lang_norm_page_url_post' => "(wpml_lang, normalized_page_url(191), post_id)",
      'idx_lang_link_type_norm_link_post' => "(wpml_lang, link_type, normalized_link(191), post_id)",
    ];

    foreach ($requiredIndexes as $indexName => $definition) {
      $existing = $wpdb->get_var($wpdb->prepare("SHOW INDEX FROM $table WHERE Key_name = %s", $indexName));
      if (empty($existing)) {
        $wpdb->query("ALTER TABLE $table ADD INDEX $indexName $definition");
      }
    }
  }

  private function ensure_author_id_schema() {
    global $wpdb;

    $factTable = $wpdb->prefix . 'lm_link_fact';
    $factExists = (string)$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $factTable));
    if ($factExists === $factTable) {
      $factAuthorIdColumn = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM $factTable LIKE %s", 'post_author_id'));
      if (empty($factAuthorIdColumn)) {
        $wpdb->query("ALTER TABLE $factTable ADD COLUMN post_author_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0 AFTER post_author");
      }

      $factAuthorIdIndex = $wpdb->get_var($wpdb->prepare("SHOW INDEX FROM $factTable WHERE Key_name = %s", 'idx_lang_post_author_id'));
      if (empty($factAuthorIdIndex)) {
        $wpdb->query("ALTER TABLE $factTable ADD INDEX idx_lang_post_author_id (wpml_lang, post_author_id)");
      }
    }

    $summaryTable = $wpdb->prefix . 'lm_link_post_summary';
    $summaryExists = (string)$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $summaryTable));
    if ($summaryExists !== $summaryTable) {
      return;
    }

    $summaryAuthorIdColumn = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM $summaryTable LIKE %s", 'post_author_id'));
    if (empty($summaryAuthorIdColumn)) {
      $wpdb->query("ALTER TABLE $summaryTable ADD COLUMN post_author_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0 AFTER post_author");
    }

    $summaryAuthorIdIndex = $wpdb->get_var($wpdb->prepare("SHOW INDEX FROM $summaryTable WHERE Key_name = %s", 'idx_lang_post_author_id'));
    if (empty($summaryAuthorIdIndex)) {
      $wpdb->query("ALTER TABLE $summaryTable ADD INDEX idx_lang_post_author_id (wpml_lang, post_author_id)");
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
      post_author_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
      post_date DATETIME NULL,
      post_modified DATETIME NULL,
      page_url VARCHAR(1024) NOT NULL DEFAULT '',
      normalized_page_url VARCHAR(1024) NOT NULL DEFAULT '',
      source VARCHAR(32) NOT NULL DEFAULT '',
      link_location VARCHAR(128) NOT NULL DEFAULT '',
      block_index VARCHAR(64) NOT NULL DEFAULT '',
      occurrence VARCHAR(32) NOT NULL DEFAULT '',
      link_type VARCHAR(16) NOT NULL DEFAULT '',
      link VARCHAR(1024) NOT NULL DEFAULT '',
      normalized_link VARCHAR(1024) NOT NULL DEFAULT '',
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
      KEY idx_lang_post_author_id (wpml_lang, post_author_id),
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
      KEY idx_lang_norm_page_url_post (wpml_lang, normalized_page_url(191), post_id),
      KEY idx_lang_link_type_norm_link_post (wpml_lang, link_type, normalized_link(191), post_id),
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
      post_author_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
      post_date DATETIME NULL,
      post_modified DATETIME NULL,
      page_url VARCHAR(1024) NOT NULL DEFAULT '',
      inbound INT(11) NOT NULL DEFAULT 0,
      internal_outbound INT(11) NOT NULL DEFAULT 0,
      outbound INT(11) NOT NULL DEFAULT 0,
      PRIMARY KEY (wpml_lang, post_id),
      KEY idx_lang_post_type (wpml_lang, post_type),
      KEY idx_lang_post_author_id (wpml_lang, post_author_id),
      KEY idx_lang_inbound (wpml_lang, inbound),
      KEY idx_lang_internal_outbound (wpml_lang, internal_outbound),
      KEY idx_lang_outbound (wpml_lang, outbound),
      KEY idx_page_url (page_url(191))
    ) $charset;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
  }

  private function create_link_domain_summary_table() {
    global $wpdb;
    $table = $wpdb->prefix . 'lm_link_domain_summary';
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS $table (
      id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
      wpml_lang VARCHAR(20) NOT NULL DEFAULT 'all',
      post_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
      post_title TEXT,
      post_type VARCHAR(32) NOT NULL DEFAULT '',
      post_author VARCHAR(255) NOT NULL DEFAULT '',
      post_author_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
      post_date DATETIME NULL,
      post_modified DATETIME NULL,
      page_url VARCHAR(1024) NOT NULL DEFAULT '',
      source_type VARCHAR(32) NOT NULL DEFAULT '',
      link_location VARCHAR(128) NOT NULL DEFAULT '',
      rel_nofollow TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
      rel_sponsored TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
      rel_ugc TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
      domain VARCHAR(255) NOT NULL DEFAULT '',
      link_url VARCHAR(1024) NOT NULL DEFAULT '',
      link_hash CHAR(32) NOT NULL DEFAULT '',
      anchor_text VARCHAR(255) NOT NULL DEFAULT '',
      anchor_hash CHAR(32) NOT NULL DEFAULT '',
      cites INT(11) NOT NULL DEFAULT 0,
      PRIMARY KEY (id),
      UNIQUE KEY uniq_domain_row (wpml_lang, post_id, source_type, link_location, rel_nofollow, rel_sponsored, rel_ugc, domain(191), link_hash, anchor_hash),
      KEY idx_lang_domain (wpml_lang, domain),
      KEY idx_lang_post_type_domain (wpml_lang, post_type, domain),
      KEY idx_lang_post_author_domain (wpml_lang, post_author_id, domain),
      KEY idx_lang_source_domain (wpml_lang, source_type, domain),
      KEY idx_lang_location_domain (wpml_lang, link_location, domain),
      KEY idx_lang_post (wpml_lang, post_id),
      KEY idx_page_url (page_url(191)),
      KEY idx_link_url (link_url(191))
    ) $charset;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
  }

  private function create_anchor_text_summary_table() {
    global $wpdb;
    $table = $wpdb->prefix . 'lm_anchor_text_summary';
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS $table (
      id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
      wpml_lang VARCHAR(20) NOT NULL DEFAULT 'all',
      post_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
      post_title TEXT,
      post_type VARCHAR(32) NOT NULL DEFAULT '',
      post_author VARCHAR(255) NOT NULL DEFAULT '',
      post_author_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
      post_date DATETIME NULL,
      post_modified DATETIME NULL,
      page_url VARCHAR(1024) NOT NULL DEFAULT '',
      source_type VARCHAR(32) NOT NULL DEFAULT '',
      link_location VARCHAR(128) NOT NULL DEFAULT '',
      link_type VARCHAR(16) NOT NULL DEFAULT '',
      rel_nofollow TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
      rel_sponsored TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
      rel_ugc TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
      anchor_text VARCHAR(255) NOT NULL DEFAULT '',
      anchor_hash CHAR(32) NOT NULL DEFAULT '',
      quality VARCHAR(16) NOT NULL DEFAULT '',
      link_url VARCHAR(1024) NOT NULL DEFAULT '',
      link_hash CHAR(32) NOT NULL DEFAULT '',
      uses INT(11) NOT NULL DEFAULT 0,
      PRIMARY KEY (id),
      UNIQUE KEY uniq_anchor_row (wpml_lang, post_id, source_type, link_location, link_type, rel_nofollow, rel_sponsored, rel_ugc, anchor_hash, link_hash),
      KEY idx_lang_anchor (wpml_lang, anchor_hash),
      KEY idx_lang_post_type_anchor (wpml_lang, post_type, anchor_hash),
      KEY idx_lang_post_author_anchor (wpml_lang, post_author_id, anchor_hash),
      KEY idx_lang_quality_anchor (wpml_lang, quality, anchor_hash),
      KEY idx_lang_source_anchor (wpml_lang, source_type, anchor_hash),
      KEY idx_lang_location_anchor (wpml_lang, link_location, anchor_hash),
      KEY idx_lang_post (wpml_lang, post_id),
      KEY idx_link_url (link_url(191))
    ) $charset;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
  }
}
