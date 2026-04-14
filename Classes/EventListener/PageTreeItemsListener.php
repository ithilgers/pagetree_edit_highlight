<?php

declare(strict_types=1);

namespace Ithilgers\PagetreeEditHighlight\EventListener;

use Ithilgers\PagetreeEditHighlight\Service\PageTreeItemFactory;
use Ithilgers\PagetreeEditHighlight\Service\PermittedPagesResolver;
use TYPO3\CMS\Backend\Controller\Event\AfterPageTreeItemsPreparedEvent;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

/**
 * Event listener that highlights pages in the backend page tree where the user
 * has content editing permissions.
 *
 * When the permissions filter is inactive: pages with edit permission receive a
 * highlight colour and the tree is returned unchanged.
 *
 * When the filter is active: the listener queries every page the user may edit
 * (independent of the currently expanded tree state) together with the
 * ancestors required as bridge nodes, then rebuilds the item list so those
 * pages are always visible.
 *
 * Admin users are skipped — they have full access anyway.
 *
 * @final
 */
final class PageTreeItemsListener
{
    private const DEFAULT_HIGHLIGHT_COLOR = 'rgba(0, 255, 0, 0.1)';
    private const BRIDGE_COLOR = 'rgba(0, 0, 0, 0.05)';

    private string $highlightColor;

    public function __construct(
        ExtensionConfiguration $extensionConfiguration,
        private readonly PermittedPagesResolver $resolver,
        private readonly PageTreeItemFactory $itemFactory,
    ) {
        $configuredColor = (string)($extensionConfiguration->get('pagetree_edit_highlight', 'highlightColor') ?? self::DEFAULT_HIGHLIGHT_COLOR);
        $this->highlightColor = $this->validateColor($configuredColor);
    }

    public function __invoke(AfterPageTreeItemsPreparedEvent $event): void
    {
        $backendUser = $GLOBALS['BE_USER'];
        if ($backendUser->isAdmin()) {
            return;
        }

        $items = $event->getItems();
        $filterActive = !empty($backendUser->uc['pageTree_permissionsFilterActive']);

        if (!$filterActive) {
            $event->setItems($this->colorizeOnly($items, $backendUser));
            return;
        }

        $event->setItems($this->buildFilteredTree($items, $backendUser));
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    private function colorizeOnly(array $items, BackendUserAuthentication $user): array
    {
        foreach ($items as &$item) {
            if (isset($item['_page']) && $user->doesUserHaveAccess($item['_page'], Permission::CONTENT_EDIT)) {
                $item['backgroundColor'] = $this->highlightColor;
            }
        }
        unset($item);
        return $items;
    }

    /**
     * @param array<int, array<string, mixed>> $originalItems
     * @return array<int, array<string, mixed>>
     */
    private function buildFilteredTree(array $originalItems, BackendUserAuthentication $user): array
    {
        $resolved = $this->resolver->resolve($user);
        $permittedMap = array_flip($resolved['permittedUids']);
        $bridgeMap = array_flip($resolved['bridgeUids']);
        $visibleMap = $permittedMap + $bridgeMap;

        $existingByIdentifier = [];
        $virtualRootItem = null;
        foreach ($originalItems as $item) {
            $identifier = (string)($item['identifier'] ?? '');
            if ($identifier === '') {
                continue;
            }
            $existingByIdentifier[$identifier] = $item;
            if (($item['depth'] ?? null) === 0 && $identifier === '0') {
                $virtualRootItem = $item;
            }
        }

        $mountUids = array_values(array_unique(array_map('intval', array_filter(
            $user->getWebmounts(),
            static fn($uid): bool => (int)$uid > 0,
        ))));

        if ($mountUids === []) {
            $mountUids = $resolved['childrenOfUid'][0] ?? [];
        }

        $output = [];

        if ($virtualRootItem !== null) {
            $virtualRoot = $virtualRootItem;
            $virtualRoot['hasChildren'] = $mountUids !== [];
            $virtualRoot['loaded'] = true;
            $virtualRoot['expanded'] = true;
            unset($virtualRoot['backgroundColor']);
            $output[] = $virtualRoot;
            $baseDepth = 1;
        } else {
            $baseDepth = 0;
        }

        $siblingsCount = count($mountUids);
        $position = 0;
        foreach ($mountUids as $mountUid) {
            $this->appendSubtree(
                $output,
                $mountUid,
                $mountUid,
                $baseDepth,
                $siblingsCount,
                ++$position,
                $visibleMap,
                $permittedMap,
                $bridgeMap,
                $resolved,
                $existingByIdentifier,
                $user,
            );
        }
        return $output;
    }

    /**
     * Depth-first expansion of the filtered tree.
     *
     * @param array<int, array<string, mixed>> $output Accumulator, modified in place
     * @param array<int, int> $visibleMap Flipped uid map of permitted + bridge nodes
     * @param array<int, int> $permittedMap Flipped uid map of permitted pages
     * @param array<int, int> $bridgeMap Flipped uid map of bridge pages
     * @param array{permittedUids:int[], bridgeUids:int[], pagesByUid:array<int,array<string,mixed>>, childrenOfUid:array<int,int[]>, pidOfUid:array<int,int>} $resolved
     * @param array<int, array<string, mixed>> $existingByUid
     */
    private function appendSubtree(
        array &$output,
        int $uid,
        int $mountUid,
        int $depth,
        int $siblingsCount,
        int $siblingsPosition,
        array $visibleMap,
        array $permittedMap,
        array $bridgeMap,
        array $resolved,
        array $existingByUid,
        BackendUserAuthentication $user,
    ): void {
        $visibleChildren = $this->visibleChildrenOf($uid, $resolved, $visibleMap);
        $hasChildren = $visibleChildren !== [];

        $item = $this->resolveItem(
            $uid,
            $existingByUid,
            $resolved,
            $user,
            $depth,
            $mountUid,
            $siblingsCount,
            $siblingsPosition,
            $hasChildren,
        );

        if (isset($permittedMap[$uid])) {
            $item['backgroundColor'] = $this->highlightColor;
        } elseif (isset($bridgeMap[$uid]) && $depth > 0) {
            $item['backgroundColor'] = self::BRIDGE_COLOR;
        }

        $output[] = $item;

        $childCount = count($visibleChildren);
        $position = 0;
        foreach ($visibleChildren as $childUid) {
            $this->appendSubtree(
                $output,
                $childUid,
                $mountUid,
                $depth + 1,
                $childCount,
                ++$position,
                $visibleMap,
                $permittedMap,
                $bridgeMap,
                $resolved,
                $existingByUid,
                $user,
            );
        }
    }

    /**
     * @param array{childrenOfUid:array<int,int[]>} $resolved
     * @param array<int, int> $visibleMap
     * @return int[]
     */
    private function visibleChildrenOf(int $uid, array $resolved, array $visibleMap): array
    {
        $all = $resolved['childrenOfUid'][$uid] ?? [];
        $visible = [];
        foreach ($all as $childUid) {
            if (isset($visibleMap[$childUid])) {
                $visible[] = $childUid;
            }
        }
        return $visible;
    }

    /**
     * Prefers the original item TYPO3 prepared (keeps icons, tooltips, mount
     * metadata intact). Falls back to the factory for pages that were not part
     * of the lazy-loaded subset.
     *
     * @param array<int, array<string, mixed>> $existingByUid
     * @param array{pagesByUid:array<int,array<string,mixed>>} $resolved
     * @return array<string, mixed>
     */
    private function resolveItem(
        int $uid,
        array $existingByUid,
        array $resolved,
        BackendUserAuthentication $user,
        int $depth,
        int $mountUid,
        int $siblingsCount,
        int $siblingsPosition,
        bool $hasChildren,
    ): array {
        if (isset($existingByUid[$uid])) {
            $item = $existingByUid[$uid];
            $item['depth'] = $depth;
            $item['siblingsCount'] = $siblingsCount;
            $item['siblingsPosition'] = $siblingsPosition;
            if ($hasChildren) {
                $item['hasChildren'] = true;
                $item['loaded'] = true;
                $item['expanded'] = true;
            } else {
                unset($item['hasChildren'], $item['loaded'], $item['expanded']);
            }
            return $item;
        }

        $pageRow = $resolved['pagesByUid'][$uid]
            ?? throw new \LogicException(sprintf('Resolver delivered no pages row for uid %d', $uid));

        return $this->itemFactory->build(
            $pageRow,
            $user,
            $depth,
            $mountUid,
            $siblingsCount,
            $siblingsPosition,
            $hasChildren,
        );
    }

    /**
     * Validates a CSS color value to prevent CSS injection attacks.
     */
    private function validateColor(string $color): string
    {
        $color = trim($color);

        if (preg_match('/^#[0-9a-f]{3,8}$/i', $color)) {
            return $color;
        }
        if (preg_match('/^rgba?\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}\s*(,\s*[\d.]+\s*)?\)$/i', $color)) {
            return $color;
        }
        if (preg_match('/^hsla?\(\s*\d{1,3}\s*,\s*\d{1,3}%\s*,\s*\d{1,3}%\s*(,\s*[\d.]+\s*)?\)$/i', $color)) {
            return $color;
        }
        if (preg_match('/^[a-z]+$/i', $color)) {
            return $color;
        }
        return self::DEFAULT_HIGHLIGHT_COLOR;
    }
}
