=== Links Manager ===
Contributors: edwardmesak
Tags: seo, links, internal links, external links, link audit
Requires at least: 5.0
Tested up to: 6.7
Requires PHP: 7.2
Stable tag: 4.4.6
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Manage and analyze links across your WordPress site. Edit links, review anchor text, inspect cited domains, find orphan pages, manage target groups, and export SEO data.

== Description ==

Links Manager helps you audit, edit, and monitor links across your WordPress site from one admin interface with dedicated admin screens for editing, page-level analysis, anchor summaries, cited domains, target management, and troubleshooting.

Main capabilities:

* Review a Statistics dashboard for internal vs external links, rel distribution, and anchor quality.
* Scan published content and menus for internal and external links.
* Edit link URL, anchor text, and rel attributes in Links Editor.
* Analyze page-level internal-link coverage in Pages Link, including orphan and low-link detection.
* Review Cited External Domains and All Anchor Text reports with exportable SEO datasets.
* Manage Links Target anchor targets and multi-group assignments for internal-link strategy.
* Run bulk link updates using CSV import.
* Export filtered report datasets as CSV for audits and content workflows.
* Use Settings tabs for access control, scan scope, performance tuning, data quality rules, and troubleshooting tools.
* Run troubleshooting tools such as Automated REST Self-Test, Post Scan Debug, Pages Link Parity Audit, and Refresh Diagnostics.

WPML compatibility:

* If WPML is active, Links Editor includes a language filter.
* You can scan all languages together or focus on one language code.
* Caches are separated by language context for consistent results.

== Installation ==

1. Upload the plugin folder to the `/wp-content/plugins/` directory, or install the ZIP from Plugins > Add New.
2. Activate the plugin through the Plugins menu in WordPress.
3. Open Links Manager in the WordPress admin sidebar.
4. (Optional) Configure access roles in Links Manager > Settings.

== Frequently Asked Questions ==

= Who can access this plugin? =

Administrators always have full access. Additional roles can be allowed from Settings > Access Control.

= What file format is supported for bulk updates? =

Only CSV (`.csv`) files are supported. Use the plugin export format as the import template.

= What reports and tools are included? =

Links Manager includes Statistics, Links Editor, Pages Link, Cited External Domains, All Anchor Text, Links Target, and a Settings area with troubleshooting tools such as REST self-test, scan debug, parity checks, and refresh diagnostics.

= What happens on uninstall? =

When the plugin is deleted from WordPress, it removes plugin options, transient caches, scheduled events, and plugin database tables.

== Changelog ==

= 4.4.6 =
* Migrated report pages fully to the Refresh Data workflow so report screens no longer depend on the old per-page rebuild cache flow.
* Added admin-ajax fallback for Refresh Data when custom REST routes are unavailable, and blocked overlapping refresh starts while a job is still finalizing.
* Treated empty refresh datasets as valid results, improved first-run empty states, and prevented fresh-install report screens from triggering critical errors before a dataset exists.
* Expanded uninstall cleanup to remove additional refresh/cache runtime options and rebuild state left behind by previous installs.
* Fixed WPML indexed datastore writes so global Refresh Data stores exact per-post language rows dynamically instead of collapsing everything into `all`.
* Aligned report pages with scoped WPML datasets, clarified scoped vs global row counts in Settings, and improved zero-row messaging for valid language scopes.
* Hardened Pages Link, Links Target, Cited Domains, and All Anchor Text against heavy filter requests by preferring safer indexed or lightweight row paths and keeping correctness-first fallbacks where needed.
* Fixed All Anchor Text indexed filtering for quality, usage type, group, min/max thresholds, and sorting so filtered results stay consistent with fallback builders.
* Improved Statistics cache invalidation to refresh snapshot data after new Refresh Data runs and avoid stale scoped statistics.
* Added active-filter highlighting and active-filter counts in report forms, while excluding modifier-only controls such as sort, pagination, and text-search mode from the active-filter summary.

= 4.4.5 =
* Replaced deprecated author lookups with capability-based user queries to remove WordPress "Doing it wrong" warnings.
* Converted Author filters across Links Editor, Cited Domains, All Anchor Text, and Links Target to dropdowns backed by author IDs.
* Added indexed datastore and summary support for `post_author_id`, including schema upgrades and compatibility fallbacks for older cached rows.
* Kept Text Search Mode copy aligned with the new Author dropdown behavior so author selection is no longer described as a text filter.

= 4.4.4 =
* Removed dynamic cache row caps from the main rebuild, cache persistence, and legacy cache-builder paths so large datasets are no longer cut off by memory-tier row limits.
* Shifted Pages Link and Links Editor reads further toward indexed datastore fast paths, and kept indexed datasets in sync with refreshed cache payloads.
* Restored Links Editor updates for content rows discovered through the classic-content fallback so edits no longer fail with "Block target changed" on classic locations.
* Improved Links Editor export to stream indexed results when supported instead of loading the full filtered dataset into memory first.
* Clarified All Anchor Text metrics by separating unique anchor totals from usage totals in the summary and results tables.
* Split Refresh Data finalizing into separate summary-seed and inbound-finalize stages so large-site refreshes complete much faster and with more stable per-step timing.
* Replaced the heaviest inbound summary finalizing query with chunked normalized-link lookups scoped to the current target batch instead of a broad fact-to-fact self join.
* Expanded Settings > Troubleshooting with Refresh Diagnostics details for normalized backfill, finalizing stages, query timing, and summary progress to support future performance tuning.
* Fixed finalizing diagnostics so completed refreshes report authoritative final summary row counts instead of stale stage counters.
* Separated Refresh Diagnostics into Last Refresh Job and Current Indexed Datastore Health so refresh snapshots no longer get confused with current datastore coverage.
* Added a Troubleshooting Pages Link Parity Audit tool to compare row-based and indexed Pages Link counts for specific post IDs.
* Fixed indexed Pages Link inbound summaries for target-only pages that receive inbound links but have no outgoing rows of their own.
* Added a parity-safe guard for Pages Link indexed fast paths so filtered requests automatically fall back to row-based counting when indexed filtering is not fully supported.
* Added Refresh Diagnostics visibility for target-only summary rows created during inbound finalizing.

= 4.4.3 =
* Changed weak anchor phrase detection to exact-match rules and reduced overly generic default weak phrases.
* Added a one-time upgrade routine to refresh legacy default weak anchor phrase settings.
* Synced Anchor Text Quality statistics with All Anchor Text summary totals, including empty anchors.
* Improved Statistics percentages to use the same anchor summary base as Anchor Text Summary.
* Added multi-group support for Links Target anchors, including bulk updates, checklist-based group pickers, and clearer bulk action vs filter UI.
* Clarified that Links Target group totals use overlapping coverage when one anchor belongs to multiple groups.
* Added a "Doesn't contain" text search mode across supported filter screens.
* Improved large-site refresh flow with chunked finalization and clearer running/finalizing states in Refresh Data.
* Fixed Refresh Data progress handling so running jobs resume polling after reload and no longer show 100% before completion.
* Added safer partial refresh messaging when rebuilds stop at configured safety limits instead of reporting stale data as fully up to date.
* Fixed editor REST cursor pagination so rows no longer repeat, disappear, or paginate unstably when sort values match.
* Corrected REST pagination totals and next-cursor metadata after cursor filtering.
* Fixed Pages Link inbound counting to resolve target landing pages from the full in-scope candidate map.
* Kept Pages Link inbound reporting aligned with Links Editor while preserving scope restrictions from settings.
* Improved link extraction for Gutenberg content by keeping full post-content fallback parsing and deduplicating overlaps with block-based parsing.
* Added a Troubleshooting "Post Scan Debug" tool to inspect crawl results for a specific post, compare extracted rows with indexed datastore rows, and search for a specific URL/domain.

= 4.4.2 =
* Enforced per-post capability checks in link update handlers to prevent broad role-based edits.
* Added uninstall cleanup for scheduled cron hooks.
* Maintenance and performance updates.

= 4.4.1 =
* Fixed audit logging for updates that only change anchor text or rel attributes.
* Moved daily maintenance hooks to admin context to avoid frontend overhead.
* Improved pluralization in Links Editor result heading.
* Updated export headers for RFC 6266-compliant quoted filenames.
* Preserved WPML language context in pagination and export links.

= 4.4.0 =
* Internal improvements and maintenance updates.

= Earlier versions =
* Versions prior to 4.4.0 are not documented in this repository's current changelog history.

== Upgrade Notice ==

= 4.4.6 =
Recommended update for the new Refresh Data-only workflow, safer first-run behavior after reinstall, stronger WPML language scoping, more stable report filtering, and clearer active-filter UI across report pages.

= 4.4.5 =
Recommended update for cleaner WordPress compatibility, exact author filtering via dropdowns across admin reports, and indexed author-ID support for more reliable filtering.

= 4.4.4 =
Recommended update for large-dataset rebuild stability, much faster Refresh Data finalizing on big sites, classic-content editor reliability, clearer troubleshooting metrics, more accurate indexed inbound summaries for target-only pages, and safer Pages Link fast-path fallbacks.

= 4.4.3 =
Recommended update for improved anchor quality rules, synchronized anchor reporting, large-dataset rebuild stability, editor reliability, Pages Link accuracy, and Gutenberg link extraction.

= 4.4.2 =
Recommended update for stronger edit authorization and uninstall cleanup.
