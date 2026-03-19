# Release Checklist

Use this checklist before publishing a new Links Manager release to GitHub.

## 1. Versioning

- [ ] Bump plugin version in [links-manager.php](links-manager.php)
- [ ] Update Stable tag and changelog in [readme.txt](readme.txt)
- [ ] Add/update changelog section in [README.md](README.md)

## 2. Quality and Compatibility

- [ ] Activate plugin on a clean WordPress install
- [ ] Confirm plugin loads with no PHP warnings/fatal errors
- [ ] Test key workflows:
  - [ ] Scan links
  - [ ] Edit a link
  - [ ] Run CSV export/import
  - [ ] Review stats and audit logs
- [ ] Verify uninstall cleanup behavior using [uninstall.php](uninstall.php)
- [ ] Re-check WPML behavior if multilingual support is expected

## 3. Security and Permissions

- [ ] Confirm nonce checks are present in write actions
- [ ] Confirm capability checks exist for data-changing actions
- [ ] Verify no debug output or sensitive data is exposed

## 4. Documentation

- [ ] Review plugin header metadata in [links-manager.php](links-manager.php)
- [ ] Review WordPress directory readme in [readme.txt](readme.txt)
- [ ] Review GitHub readme in [README.md](README.md)
- [ ] Confirm license references point to [LICENSE](LICENSE)

## 5. GitHub Release

- [ ] Commit all changes with a clear message
- [ ] Tag release (example: `v4.4.3`)
- [ ] Push branch and tag to GitHub
- [ ] Create GitHub Release notes from changelog
- [ ] Attach plugin ZIP asset if you distribute binaries

## 6. Post-Release

- [ ] Validate release files and changelog on GitHub
- [ ] Smoke test installation from release ZIP
- [ ] Record known issues or follow-up tasks
