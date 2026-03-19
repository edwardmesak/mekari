# Release Draft: v4.4.2

## Title

Links Manager v4.4.2

## Tag

`v4.4.2`

## Summary

This release improves edit authorization safety and strengthens uninstall cleanup while including maintenance and performance updates.

## What's Changed

- Enforced per-post capability checks in link update handlers to prevent broad role-based edits
- Added uninstall cleanup for scheduled cron hooks
- Included maintenance and performance updates

## Compatibility

- Requires WordPress 5.0+
- Requires PHP 7.2+
- Tested up to WordPress 6.7

## Upgrade Notes

This is a recommended update for stronger edit authorization and uninstall cleanup.

## Full Changelog

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
