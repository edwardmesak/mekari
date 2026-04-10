# Links Manager

Manage and analyze links across your WordPress site from one admin interface.

Links Manager helps you audit, edit, and monitor internal and external links with practical SEO workflows across dedicated admin screens for editing, page-level analysis, anchor summaries, cited domains, target management, and troubleshooting.

## Features

- Review sitewide link health from a `Statistics` dashboard with internal vs external, rel, and anchor-quality summaries
- Audit and update individual links in `Links Editor`, including URL, anchor text, and `rel` attributes
- Analyze page-level internal-link coverage in `Pages Link`, including orphan, low-link, and inbound-link reporting
- Review `Cited External Domains` and `All Anchor Text` reports with exportable SEO datasets
- Manage `Links Target` anchor targets and multi-group assignments for internal-link strategy
- Run bulk link updates through CSV import and export filtered report datasets as CSV
- Use `Settings` tabs for access control, scan scope, performance tuning, data quality rules, and troubleshooting
- Run troubleshooting tools such as Automated REST Self-Test, Post Scan Debug, Pages Link Parity Audit, and Refresh Diagnostics
- Filter scans and reports with WPML-aware language context and separate language caches

## Requirements

- WordPress 5.0 or higher
- PHP 7.2 or higher

## Installation

1. Upload the plugin folder to `wp-content/plugins/`, or install the ZIP from **Plugins > Add New**.
2. Activate the plugin in the WordPress admin.
3. Open **Links Manager** from the admin sidebar.
4. (Optional) Configure access roles in **Links Manager > Settings**.

## Usage Notes

- Administrators always have full access.
- Additional roles can be granted from **Settings > Access Control**.
- **Settings > Performance** controls scan scope, authors, post types, WPML languages, and rebuild-related behavior.
- **Settings > Troubleshooting** includes REST self-test, crawl/index debug tools, and refresh diagnostics.
- CSV is the supported format for bulk updates.
- If WPML is active, you can filter scans by language.

## Uninstall Behavior

When deleted from WordPress (not just deactivated), the plugin removes:

- Plugin options
- Transient/cache entries
- Scheduled cron hooks
- Plugin database tables

## Changelog

### 4.4.6

- Migrated report pages fully to the `Refresh Data` workflow so report screens no longer depend on the old per-page rebuild cache flow
- Added `admin-ajax` fallback for `Refresh Data` when custom REST routes are unavailable, and blocked overlapping refresh starts while a job is still finalizing
- Treated empty refresh datasets as valid results, improved first-run empty states, and prevented fresh-install report screens from triggering critical errors before a dataset exists
- Expanded uninstall cleanup to remove additional refresh/cache runtime options and rebuild state left behind by previous installs
- Fixed WPML indexed datastore writes so global `Refresh Data` stores exact per-post language rows dynamically instead of collapsing everything into `all`
- Aligned report pages with scoped WPML datasets, clarified scoped vs global row counts in `Settings`, and improved zero-row messaging for valid language scopes
- Hardened `Pages Link`, `Links Target`, `Cited Domains`, and `All Anchor Text` against heavy filter requests by preferring safer indexed or lightweight row paths and keeping correctness-first fallbacks where needed
- Fixed `All Anchor Text` indexed filtering for quality, usage type, group, min/max thresholds, and sorting so filtered results stay consistent with fallback builders
- Improved `Statistics` cache invalidation to refresh snapshot data after new `Refresh Data` runs and avoid stale scoped statistics
- Added active-filter highlighting and active-filter counts in report forms, while excluding modifier-only controls such as sort, pagination, and text-search mode from the active-filter summary

### 4.4.5

- Replaced deprecated author lookups with capability-based user queries to remove WordPress "Doing it wrong" warnings
- Converted Author filters across Links Editor, Cited Domains, All Anchor Text, and Links Target to dropdowns backed by author IDs
- Added indexed datastore and summary support for `post_author_id`, including schema upgrades and compatibility fallbacks for older cached rows
- Kept Text Search Mode copy aligned with the new Author dropdown behavior so author selection is no longer described as a text filter

### 4.4.4

- Removed dynamic cache row caps from the main rebuild, cache persistence, and legacy cache-builder paths so large datasets are no longer cut off by memory-tier row limits
- Shifted Pages Link and Links Editor reads further toward indexed datastore fast paths, and kept indexed datasets in sync with refreshed cache payloads
- Restored Links Editor updates for content rows discovered through the classic-content fallback so edits no longer fail with `Block target changed` on classic locations
- Improved Links Editor export to stream indexed results when supported instead of loading the full filtered dataset into memory first
- Clarified All Anchor Text metrics by separating unique anchor totals from usage totals in the summary and results tables
- Split Refresh Data finalizing into separate summary-seed and inbound-finalize stages so large-site refreshes complete much faster and with more stable per-step timing
- Replaced the heaviest inbound summary finalizing query with chunked normalized-link lookups scoped to the current target batch instead of a broad fact-to-fact self join
- Expanded **Settings > Troubleshooting** with Refresh Diagnostics details for normalized backfill, finalizing stages, query timing, and summary progress
- Fixed finalizing diagnostics so completed refreshes report authoritative final summary row counts instead of stale stage counters
- Separated Refresh Diagnostics into **Last Refresh Job** and **Current Indexed Datastore Health** so refresh snapshots no longer get confused with current datastore coverage
- Added a Troubleshooting **Pages Link Parity Audit** tool to compare row-based and indexed Pages Link counts for specific post IDs
- Fixed indexed Pages Link inbound summaries for target-only pages that receive inbound links but have no outgoing rows of their own
- Added a parity-safe guard for Pages Link indexed fast paths so filtered requests automatically fall back to row-based counting when indexed filtering is not fully supported
- Added Refresh Diagnostics visibility for target-only summary rows created during inbound finalizing

### 4.4.4

- Added adaptive runtime safeguards for heavy Links Editor filter combinations so unsafe PHP fallback requests now show safe warnings instead of causing critical errors
- Expanded indexed editor filtering to keep more text-search combinations on fast indexed paths, including additional text modes and ALT filtering support
- Added workload-specific safety guards for Cited Domains and All Anchor Text so broad aggregation requests fail safely instead of crashing
- Added structural summary tables for Cited Domains and All Anchor Text and moved report and export flows toward summary-first indexed reads
- Extended indexed datastore finalization to rebuild post, cited-domain, and anchor-text summaries during Refresh Data
- Improved Troubleshooting and Status visibility with summary row counts, runtime guard details, and safer runtime-profile capture scoped to Links Manager requests
- Added consistent active-filter highlighting for Links Target filter grids to match other report pages
- Hardened WPML refresh orchestration so rebuild jobs preserve explicit language scope, expose crawl and finalize language progress, and stamp rebuilt rows with the active crawl language

### 4.4.3

- Changed weak anchor phrase detection to exact-match rules and reduced overly generic default weak phrases
- Added a one-time upgrade routine to refresh legacy default weak anchor phrase settings
- Synced Anchor Text Quality statistics with All Anchor Text summary totals, including empty anchors
- Improved Statistics percentages to use the same anchor summary base as Anchor Text Summary
- Added multi-group support for Links Target anchors, including bulk updates and checklist-based group pickers
- Clarified that Links Target group totals use overlapping coverage when one anchor belongs to multiple groups
- Improved Links Target UI separation between bulk actions and result filters
- Added a `Doesn't contain` text search mode across supported filter screens
- Improved large-site refresh flow with chunked finalization and clearer running/finalizing states in Refresh Data
- Fixed Refresh Data progress handling so running jobs resume polling after reload and no longer show 100% before completion
- Added safer partial refresh messaging when rebuilds stop at configured safety limits instead of reporting stale data as fully up to date
- Fixed editor REST cursor pagination so rows no longer repeat, disappear, or paginate unstably when sort values match
- Corrected REST pagination totals and next-cursor metadata after cursor filtering
- Fixed Pages Link inbound counting to resolve target landing pages from the full in-scope candidate map
- Kept Pages Link inbound reporting aligned with Links Editor while preserving scope restrictions from settings
- Improved link extraction for Gutenberg content by keeping full post-content fallback parsing and deduplicating overlaps with block-based parsing
- Added a Troubleshooting `Post Scan Debug` tool to inspect crawl results for a specific post, compare extracted rows with indexed datastore rows, and search for a specific URL/domain

### 4.4.2

- Enforced per-post capability checks in link update handlers
- Added uninstall cleanup for scheduled cron hooks
- Maintenance and performance updates

### 4.4.1

- Fixed audit logging for updates that only change anchor text or `rel`
- Moved daily maintenance hooks to admin context
- Improved pluralization in Links Editor results heading
- Updated export headers for RFC 6266-compliant quoted filenames
- Preserved WPML language context in pagination and export links

### 4.4.0

- Internal improvements and maintenance updates

### Earlier Versions

- Versions prior to `4.4.0` are not documented in this repository's current changelog history.

## License

This plugin is licensed under the GNU General Public License v2.0 or later.

See [LICENSE](LICENSE) for the full text.

## Author

Edward Mesak Dua Padang
