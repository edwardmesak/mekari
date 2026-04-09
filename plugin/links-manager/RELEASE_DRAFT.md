# Release Draft: v4.4.6

## Title

Links Manager v4.4.6

## Tag

`v4.4.6`

## Summary

This release improves WordPress compatibility and makes author filtering consistent across admin screens by switching author filters to exact author-ID dropdowns.

## What's Changed

- Replaced deprecated author lookups with capability-based user queries to remove WordPress "Doing it wrong" warnings
- Converted Author filters across Links Editor, Cited Domains, All Anchor Text, and Links Target to dropdowns backed by author IDs
- Added indexed datastore and summary support for `post_author_id`, including schema upgrades and compatibility fallbacks for older cached rows
- Kept Text Search Mode copy aligned with the new Author dropdown behavior so author selection is no longer described as a text filter

## Compatibility

- Requires WordPress 5.0+
- Requires PHP 7.2+
- Tested up to WordPress 6.7

## Upgrade Notes

This is a recommended update for cleaner WordPress compatibility and more reliable author filtering across admin reports.

## Full Changelog

### 4.4.6

- Replaced deprecated author lookups with capability-based user queries to remove WordPress "Doing it wrong" warnings.
- Converted Author filters across Links Editor, Cited Domains, All Anchor Text, and Links Target to dropdowns backed by author IDs.
- Added indexed datastore and summary support for `post_author_id`, including schema upgrades and compatibility fallbacks for older cached rows.
- Kept Text Search Mode copy aligned with the new Author dropdown behavior so author selection is no longer described as a text filter.

### 4.4.4

- Removed dynamic cache row caps from the main rebuild, cache persistence, and legacy cache-builder paths so large datasets are no longer cut off by memory-tier row limits.
- Shifted Pages Link and Links Editor reads further toward indexed datastore fast paths, and kept indexed datasets in sync with refreshed cache payloads.
- Restored Links Editor updates for content rows discovered through the classic-content fallback so edits no longer fail with `Block target changed` on classic locations.
- Improved Links Editor export to stream indexed results when supported instead of loading the full filtered dataset into memory first.
- Clarified All Anchor Text metrics by separating unique anchor totals from usage totals in the summary and results tables.
- Split Refresh Data finalizing into separate summary-seed and inbound-finalize stages so large-site refreshes complete much faster and with more stable per-step timing.
- Replaced the heaviest inbound summary finalizing query with chunked normalized-link lookups scoped to the current target batch instead of a broad fact-to-fact self join.
- Expanded **Settings > Troubleshooting** with Refresh Diagnostics details for normalized backfill, finalizing stages, query timing, and summary progress.
- Fixed finalizing diagnostics so completed refreshes report authoritative final summary row counts instead of stale stage counters.
- Separated Refresh Diagnostics into **Last Refresh Job** and **Current Indexed Datastore Health** so refresh snapshots no longer get confused with current datastore coverage.
- Added a Troubleshooting **Pages Link Parity Audit** tool to compare row-based and indexed Pages Link counts for specific post IDs.
- Fixed indexed Pages Link inbound summaries for target-only pages that receive inbound links but have no outgoing rows of their own.
- Added a parity-safe guard for Pages Link indexed fast paths so filtered requests automatically fall back to row-based counting when indexed filtering is not fully supported.
- Added Refresh Diagnostics visibility for target-only summary rows created during inbound finalizing.

### 4.4.3

- Changed weak anchor phrase detection to exact-match rules and reduced overly generic default weak phrases.
- Added a one-time upgrade routine to refresh legacy default weak anchor phrase settings.
- Synced Anchor Text Quality statistics with All Anchor Text summary totals, including empty anchors.
- Improved Statistics percentages to use the same anchor summary base as Anchor Text Summary.
- Added multi-group support for Links Target anchors, including bulk updates and checklist-based group pickers.
- Clarified that Links Target group totals use overlapping coverage when one anchor belongs to multiple groups.
- Improved Links Target UI separation between bulk actions and result filters.
- Added a `Doesn't contain` text search mode across supported filter screens.
- Improved large-site refresh flow with chunked finalization and clearer running/finalizing states in Refresh Data.
- Fixed Refresh Data progress handling so running jobs resume polling after reload and no longer show 100% before completion.
- Added safer partial refresh messaging when rebuilds stop at configured safety limits instead of reporting stale data as fully up to date.
- Fixed editor REST cursor pagination so rows no longer repeat, disappear, or paginate unstably when sort values match.
- Corrected REST pagination totals and next-cursor metadata after cursor filtering.
- Fixed Pages Link inbound counting to resolve target landing pages from the full in-scope candidate map.
- Kept Pages Link inbound reporting aligned with Links Editor while preserving scope restrictions from settings.
- Improved link extraction for Gutenberg content by keeping full post-content fallback parsing and deduplicating overlaps with block-based parsing.
- Added a Troubleshooting `Post Scan Debug` tool to inspect crawl results for a specific post, compare extracted rows with indexed datastore rows, and search for a specific URL/domain.

### 4.4.2

- Enforced per-post capability checks in link update handlers to prevent broad role-based edits.
- Added uninstall cleanup for scheduled cron hooks.
- Maintenance and performance updates.

### 4.4.1

- Fixed audit logging for updates that only change anchor text or rel attributes.
- Moved daily maintenance hooks to admin context to avoid frontend overhead.
- Improved pluralization in Links Editor result heading.
- Updated export headers for RFC 6266-compliant quoted filenames.
- Preserved WPML language context in pagination and export links.

### 4.4.0

- Internal improvements and maintenance updates.

### Earlier versions

- Versions prior to `4.4.0` are not documented in this repository's current changelog history.
