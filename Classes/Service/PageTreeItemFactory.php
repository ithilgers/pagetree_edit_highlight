<?php

declare(strict_types=1);

namespace Ithilgers\PagetreeEditHighlight\Service;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

/**
 * Builds page tree items matching the shape emitted by
 * {@see \TYPO3\CMS\Backend\Controller\Page\TreeController::pagesToFlatArray()}
 * so injected items look identical to the ones TYPO3 produced natively.
 */
final class PageTreeItemFactory
{
    public function __construct(
        private readonly IconFactory $iconFactory,
    ) {}

    /**
     * @param array<string, mixed> $pageRow Full pages row
     * @return array<string, mixed>
     */
    public function build(
        array $pageRow,
        BackendUserAuthentication $user,
        int $depth,
        int $mountPoint,
        int $siblingsCount,
        int $siblingsPosition,
        bool $hasChildren,
    ): array {
        $uid = (int)$pageRow['uid'];
        $title = (string)($pageRow['title'] ?? '');
        $icon = $this->iconFactory->getIconForRecord('pages', $pageRow);

        $item = [
            'stateIdentifier' => $mountPoint . '_' . $uid,
            'identifier' => (string)$uid,
            '_page' => $pageRow,
            'depth' => $depth,
            'tip' => strip_tags($title),
            'icon' => $icon->getIdentifier(),
            'name' => $title,
            'type' => (int)($pageRow['doktype'] ?? 0),
            'nameSourceField' => 'title',
            'mountPoint' => $mountPoint,
            'workspaceId' => !empty($pageRow['t3ver_oid']) ? (int)$pageRow['t3ver_oid'] : $uid,
            'siblingsCount' => $siblingsCount,
            'siblingsPosition' => $siblingsPosition,
            'allowDelete' => $user->doesUserHaveAccess($pageRow, Permission::PAGE_DELETE),
            'allowEdit' => $user->doesUserHaveAccess($pageRow, Permission::PAGE_EDIT),
        ];

        if ($hasChildren) {
            $item['hasChildren'] = true;
            $item['loaded'] = true;
            $item['expanded'] = true;
        }

        if ($icon->getOverlayIcon()) {
            $item['overlayIcon'] = $icon->getOverlayIcon()->getIdentifier();
        }

        if ($depth === 0) {
            $item['isMountPoint'] = true;
        }

        return $item;
    }
}
