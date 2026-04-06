=== Links Manager ===
Contributors: edwardmesak
Tags: seo, links, internal links, external links, link audit
Requires at least: 5.0
Tested up to: 6.7
Requires PHP: 7.2
Stable tag: 4.4.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Manage and analyze links across your WordPress site. Edit links, review anchor text, find orphan pages, and export SEO data.

== Description ==

Links Manager helps you audit, edit, and monitor links across your WordPress site from one admin interface.

Main capabilities:

* Scan published content and menus for internal and external links.
* Edit link URL, anchor text, and rel attributes.
* Run bulk link updates using CSV import.
* Find pages with low or zero inbound internal links.
* Review dashboard stats and link-change audit logs.
* Manage anchor target lists and grouping for internal linking strategy.

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

= What happens on uninstall? =

When the plugin is deleted from WordPress, it removes plugin options, transient caches, scheduled events, and plugin database tables.

== Changelog ==

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

== Upgrade Notice ==

= 4.4.3 =
Recommended update for improved anchor quality rules, synchronized anchor reporting, Pages Link inbound accuracy, Refresh Data stability, and Gutenberg link extraction.

= 4.4.2 =
Recommended update for stronger edit authorization and uninstall cleanup.
