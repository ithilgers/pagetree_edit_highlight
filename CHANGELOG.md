# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.0] - 2026-06-12

### Added
- `PermittedPagesResolver`: resolves the full set of editable pages directly from the database, independent of the lazy-loaded tree state
- `PageTreeItemFactory`: builds tree item arrays for pages that were not part of the lazy-loaded subset

### Fixed
- Filter now finds editable pages located in collapsed branches of the tree
- Empty tree when multiple webmounts are configured (virtual root is now preserved)

### Changed
- Ancestor resolution uses an iterative level-by-level walk (one query per tree depth) instead of a full-table scan

## [1.0.0] - 2026-03-16

### Added
- Highlights pages in the backend page tree where the user has content editing permissions
- Configurable highlight color via extension configuration
- Automatic exclusion of admin users (who have access to all pages)
- Page tree filter toggle: dropdown menu item "Show editable pages only" to filter down to editable pages
- Bridge nodes: parent pages without edit permissions displayed greyed out to preserve tree structure
- Session-based filter state
- Info banner on active filter with deactivation button
- Secure color validation (hex, rgb, rgba, hsl, hsla, named CSS colors)
- Compatible with TYPO3 12.4 LTS

[1.1.0]: https://github.com/ithilgers/pagetree_edit_highlight/releases/tag/1.1.0
[1.0.0]: https://github.com/ithilgers/pagetree_edit_highlight/releases/tag/v1.0.0
