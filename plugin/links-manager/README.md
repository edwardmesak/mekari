# Links Manager

Manage and analyze links across your WordPress site from one admin interface.

Links Manager helps you audit, edit, and monitor internal and external links with practical SEO workflows, including bulk updates, anchor text management, and orphan-page discovery.

## Features

- Scan published content and menus for internal and external links
- Edit link URL, anchor text, and `rel` attributes
- Bulk update links using CSV import
- Export link and anchor datasets for SEO audits
- Identify pages with low or zero inbound internal links
- Review link-change audit logs and dashboard stats
- Manage anchor targets and grouping for internal-link strategy
- WPML-aware language filtering and cache separation

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
- CSV is the supported format for bulk updates.
- If WPML is active, you can filter scans by language.

## Uninstall Behavior

When deleted from WordPress (not just deactivated), the plugin removes:

- Plugin options
- Transient/cache entries
- Scheduled cron hooks
- Plugin database tables

## Changelog

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

## License

This plugin is licensed under the GNU General Public License v2.0 or later.

See [LICENSE](LICENSE) for the full text.

## Author

Edward Mesak Dua Padang
