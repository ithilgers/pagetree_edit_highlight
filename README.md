# TYPO3 Extension: PageTree Edit Highlight

[![TYPO3 12](https://img.shields.io/badge/TYPO3-12.4-orange.svg)](https://get.typo3.org/version/12)
[![License](https://img.shields.io/badge/license-GPL--2.0--or--later-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)
[![Packagist Version](https://img.shields.io/packagist/v/ithilgers/pagetree-edit-highlight)](https://packagist.org/packages/ithilgers/pagetree-edit-highlight)
[![Packagist Downloads](https://img.shields.io/packagist/dt/ithilgers/pagetree-edit-highlight)](https://packagist.org/packages/ithilgers/pagetree-edit-highlight)

Visual highlighting of pages in the TYPO3 backend page tree where the current user has content editing permissions.

## Features

- **Visual Feedback**: Highlights pages with a customizable background color where the user has content editing rights
- **Filter Toggle**: Dropdown menu item "Editable pages only" in the page tree to filter down to editable pages only, with bridge nodes keeping the tree structure intact
- **Finds Hidden Pages**: When the filter is active, editable pages are found even in collapsed branches of the tree, independent of the currently expanded state
- **Localized**: Full English and German translations, respects TYPO3 backend language setting
- **Permission-Aware**: Only shows highlights based on actual user permissions
- **Admin-Optimized**: Skips highlighting for admin users (who have all permissions anyway)
- **Configurable**: Customize the highlight color through extension configuration
- **Lightweight**: Minimal performance impact using TYPO3's event system

## Why This Extension?

In large TYPO3 installations with complex permission structures, editors often struggle to identify which pages they can actually edit. This extension provides instant visual feedback in the page tree, making it immediately clear where users have content editing permissions.

## Installation

### Via Composer (recommended)

```bash
composer require ithilgers/pagetree-edit-highlight
```

### Activation

After installation, activate the extension in the Extension Manager or via CLI:

```bash
vendor/bin/typo3 extension:setup
```

## Configuration

The extension can be configured in the Extension Configuration:

1. Go to **Admin Tools > Settings > Extension Configuration**
2. Select `pagetree_edit_highlight`
3. Configure the following options:

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `highlightColor` | string | `rgba(0, 255, 0, 0.1)` | Background color for pages where the user has content editing permissions. Accepts any valid CSS color value (hex, rgb, rgba, named colors). |

### Example Configuration

```php
// config/system/settings.php
'EXTENSIONS' => [
    'pagetree_edit_highlight' => [
        'highlightColor' => 'rgba(255, 215, 0, 0.15)', // Golden highlight
    ],
],
```

## Usage

Once installed and activated, the extension works automatically:

1. Log in to the TYPO3 backend as a non-admin user
2. Open the page tree
3. Pages where you have content editing permissions are highlighted with the configured color
4. Admin users see no highlighting (as they have permissions everywhere)

## Technical Details

### Requirements

- TYPO3 12.4 or higher
- PHP 8.1 or higher

### How It Works

The extension uses TYPO3's PSR-14 event system:

- Listens to `AfterPageTreeItemsPreparedEvent`
- Checks each page for `Permission::CONTENT_EDIT` rights
- Applies background color to permitted pages
- Skips processing for admin users

When the filter is **inactive**, only the prepared (lazy-loaded) items are colorized — a lightweight per-item check. When the filter is **active**, the `PermittedPagesResolver` queries every editable page directly from the database and rebuilds the item list so editable pages are shown even when their branch is collapsed. The resolver runs a single permission query (via `getPagePermsClause`), walks ancestors level by level (one query per tree depth) to build bridge nodes, and caches its result per user for the request.

### Architecture

```
Classes/
├── EventListener/
│   ├── BackendTemplateListener.php  # Loads JS module in backend
│   └── PageTreeItemsListener.php    # Main event listener
└── Service/
    ├── PermittedPagesResolver.php   # Resolves editable pages + bridge nodes from DB
    └── PageTreeItemFactory.php      # Builds tree items for non-lazy-loaded pages

Configuration/
├── JavaScriptModules.php            # ES6 module registration
└── Services.yaml                    # Service registration

Resources/
├── Private/
│   └── Language/
│       ├── locallang.xlf                # English labels
│       └── de.locallang.xlf             # German translation
└── Public/
    └── JavaScript/
        └── permissions-filter-toggle.js  # Filter toggle UI

ext_conf_template.txt               # Extension configuration template
ext_emconf.php                      # Extension metadata
composer.json                       # Composer metadata
```

## Compatibility

| TYPO3 Version | Extension Version | Support |
|---------------|-------------------|---------|
| 12.4 LTS      | 1.0.x, 1.1.x     | ✅ Active |

## Contributing

Contributions are welcome! Please:

1. Fork the repository
2. Create a feature branch
3. Commit your changes
4. Push to the branch
5. Create a Pull Request

## License

This extension is licensed under the [GNU General Public License v2.0 or later](https://www.gnu.org/licenses/gpl-2.0.html).

## Support

For bugs, feature requests, or questions:

- Open an issue on GitHub: https://github.com/ithilgers/pagetree_edit_highlight

## Credits

Developed by Theodor Hilgers.

---

**Keywords**: TYPO3, backend, page tree, permissions, visual feedback, editor experience
