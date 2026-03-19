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
