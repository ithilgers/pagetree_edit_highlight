<?php

declare(strict_types=1);

namespace Ithilgers\PagetreeEditHighlight\EventListener;

use TYPO3\CMS\Backend\Controller\Event\AfterPageTreeItemsPreparedEvent;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

/**
 * Event listener that highlights pages in the backend page tree where the user has content editing permissions.
 *
 * This listener modifies the page tree items after they have been prepared by adding a background color
 * to pages where the current backend user has edit permissions. Admin users are excluded from highlighting
 * as they have full access to all pages.
 *
 * @final
 */
final class PageTreeItemsListener
{
    private const DEFAULT_HIGHLIGHT_COLOR = 'rgba(0, 255, 0, 0.1)';

    private string $highlightColor;

    /**
     * @param ExtensionConfiguration $extensionConfiguration Extension configuration service
     */
    public function __construct(ExtensionConfiguration $extensionConfiguration)
    {
        $configuredColor = (string)($extensionConfiguration->get('pagetree_edit_highlight', 'highlightColor') ?? self::DEFAULT_HIGHLIGHT_COLOR);
        $this->highlightColor = $this->validateColor($configuredColor);
    }

    /**
     * Highlights pages in the page tree where the user has content editing permissions.
     * When the filter is active, removes pages without permissions (keeping parent bridge nodes).
     *
     * @param AfterPageTreeItemsPreparedEvent $event The page tree event containing all page items
     * @return void
     */
    public function __invoke(AfterPageTreeItemsPreparedEvent $event): void
    {
        $backendUser = $GLOBALS['BE_USER'];

        // Admins haben alle Rechte - Färbung für sie überspringen
        if ($backendUser->isAdmin()) {
            return;
        }

        $items = $event->getItems();
        $filterActive = !empty($backendUser->uc['pageTree_permissionsFilterActive']);

        // Identify pages with edit permissions
        $permittedIdentifiers = [];
        foreach ($items as &$item) {
            if (isset($item['_page']) && $backendUser->doesUserHaveAccess($item['_page'], Permission::CONTENT_EDIT)) {
                $item['backgroundColor'] = $this->highlightColor;
                $permittedIdentifiers[] = $item['identifier'];
            }
        }
        unset($item);

        if ($filterActive) {
            // Build lookup: identifier => item (identifier is a string of the page uid)
            $itemsByIdentifier = [];
            foreach ($items as $item) {
                $itemsByIdentifier[$item['identifier']] = $item;
            }

            // O(1) lookups via array keys instead of in_array()
            $permittedMap = array_flip($permittedIdentifiers);

            // Collect all bridge node identifiers (ancestors of permitted pages)
            $bridgeMap = [];
            foreach ($permittedIdentifiers as $id) {
                $parentId = (string)($itemsByIdentifier[$id]['_page']['pid'] ?? 0);
                while ($parentId !== '0' && isset($itemsByIdentifier[$parentId]) && !isset($permittedMap[$parentId]) && !isset($bridgeMap[$parentId])) {
                    $bridgeMap[$parentId] = true;
                    $parentId = (string)($itemsByIdentifier[$parentId]['_page']['pid'] ?? 0);
                }
            }

            // Filter items: keep permitted pages and bridge nodes
            $filteredItems = [];
            foreach ($items as $item) {
                $id = $item['identifier'];
                if (isset($permittedMap[$id])) {
                    $filteredItems[] = $item;
                } elseif (isset($bridgeMap[$id]) || ($item['depth'] ?? 1) === 0) {
                    $item['backgroundColor'] = 'rgba(0, 0, 0, 0.05)';
                    $filteredItems[] = $item;
                }
            }

            $event->setItems($filteredItems);
        } else {
            $event->setItems($items);
        }
    }

    /**
     * Validates a CSS color value to prevent CSS injection attacks.
     *
     * Supports the following formats:
     * - Hex colors: #fff, #ffffff, #ffffffff
     * - RGB/RGBA: rgb(255, 255, 255), rgba(255, 255, 255, 0.5)
     * - HSL/HSLA: hsl(120, 100%, 50%), hsla(120, 100%, 50%, 0.5)
     * - Named colors: red, blue, green, transparent, etc.
     *
     * @param string $color The color value to validate
     * @return string The validated color value or the default color if validation fails
     */
    private function validateColor(string $color): string
    {
        $color = trim($color);

        // Allow hex colors (#fff, #ffffff, #ffffffff)
        if (preg_match('/^#[0-9a-f]{3,8}$/i', $color)) {
            return $color;
        }

        // Allow rgb/rgba colors
        if (preg_match('/^rgba?\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}\s*(,\s*[\d.]+\s*)?\)$/i', $color)) {
            return $color;
        }

        // Allow hsl/hsla colors
        if (preg_match('/^hsla?\(\s*\d{1,3}\s*,\s*\d{1,3}%\s*,\s*\d{1,3}%\s*(,\s*[\d.]+\s*)?\)$/i', $color)) {
            return $color;
        }

        // Allow named colors (letters only, common CSS color names)
        if (preg_match('/^[a-z]+$/i', $color)) {
            return $color;
        }

        // Invalid color format - return default
        return self::DEFAULT_HIGHLIGHT_COLOR;
    }
}
