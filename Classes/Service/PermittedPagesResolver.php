<?php

declare(strict_types=1);

namespace Ithilgers\PagetreeEditHighlight\Service;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

/**
 * Resolves the full set of pages a backend user may edit, together with the
 * ancestor pages needed as bridge nodes to keep the tree connected.
 *
 * The resolver bypasses the lazy-loaded page tree state: it queries the
 * database directly so pages beyond currently expanded nodes are found.
 */
final class PermittedPagesResolver
{
    /**
     * @var array<int, array{
     *     permittedUids: int[],
     *     bridgeUids: int[],
     *     pagesByUid: array<int, array<string, mixed>>,
     *     childrenOfUid: array<int, int[]>,
     *     pidOfUid: array<int, int>
     * }>
     */
    private array $cache = [];

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * @return array{
     *     permittedUids: int[],
     *     bridgeUids: int[],
     *     pagesByUid: array<int, array<string, mixed>>,
     *     childrenOfUid: array<int, int[]>,
     *     pidOfUid: array<int, int>
     * }
     */
    public function resolve(BackendUserAuthentication $user): array
    {
        $userId = (int)($user->user['uid'] ?? 0);
        if (isset($this->cache[$userId])) {
            return $this->cache[$userId];
        }

        $permittedUids = $this->queryPermittedUids($user);
        [$pidOfUid, $sortingOfUid, $bridgeUids] = $this->walkAncestorsFromSeed($permittedUids);

        $allUids = array_values(array_unique(array_merge($permittedUids, $bridgeUids)));
        $pagesByUid = $allUids === [] ? [] : $this->queryPagesByUids($allUids);

        $childrenOfUid = $this->buildChildrenIndex($pagesByUid, $pidOfUid, $sortingOfUid);

        return $this->cache[$userId] = [
            'permittedUids' => array_map('intval', $permittedUids),
            'bridgeUids' => $bridgeUids,
            'pagesByUid' => $pagesByUid,
            'childrenOfUid' => $childrenOfUid,
            'pidOfUid' => $pidOfUid,
        ];
    }

    /**
     * @return int[]
     */
    private function queryPermittedUids(BackendUserAuthentication $user): array
    {
        $permsClause = $user->getPagePermsClause(Permission::CONTENT_EDIT);
        $qb = $this->connectionPool->getQueryBuilderForTable('pages');
        $qb->getRestrictions()->removeAll();

        $rows = $qb->select('uid')
            ->from('pages')
            ->where(
                $qb->expr()->eq('deleted', $qb->createNamedParameter(0, \PDO::PARAM_INT)),
                $permsClause,
            )
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(static fn(array $row): int => (int)$row['uid'], $rows);
    }

    /**
     * Walks the ancestor chain of the permitted pages level by level with one
     * query per tree depth. Avoids loading the entire `pages` table just to
     * hop from a page to its parent.
     *
     * @param int[] $permittedUids
     * @return array{0: array<int, int>, 1: array<int, int>, 2: int[]}
     *         [pidOfUid, sortingOfUid, bridgeUids]
     */
    private function walkAncestorsFromSeed(array $permittedUids): array
    {
        if ($permittedUids === []) {
            return [[], [], []];
        }

        $permittedMap = array_flip($permittedUids);
        $pidOfUid = [];
        $sortingOfUid = [];
        $bridgeMap = [];

        $current = array_values(array_unique($permittedUids));
        while ($current !== []) {
            $qb = $this->connectionPool->getQueryBuilderForTable('pages');
            $qb->getRestrictions()->removeAll();

            $rows = $qb->select('uid', 'pid', 'sorting')
                ->from('pages')
                ->where(
                    $qb->expr()->eq('deleted', $qb->createNamedParameter(0, \PDO::PARAM_INT)),
                    $qb->expr()->in('uid', $qb->createNamedParameter($current, Connection::PARAM_INT_ARRAY)),
                )
                ->executeQuery()
                ->fetchAllAssociative();

            $nextParents = [];
            foreach ($rows as $row) {
                $uid = (int)$row['uid'];
                $pid = (int)$row['pid'];
                $pidOfUid[$uid] = $pid;
                $sortingOfUid[$uid] = (int)$row['sorting'];

                if (!isset($permittedMap[$uid])) {
                    $bridgeMap[$uid] = true;
                }

                if ($pid > 0
                    && !isset($permittedMap[$pid])
                    && !isset($pidOfUid[$pid])
                    && !isset($nextParents[$pid])
                ) {
                    $nextParents[$pid] = true;
                }
            }

            $current = array_keys($nextParents);
        }

        return [$pidOfUid, $sortingOfUid, array_keys($bridgeMap)];
    }

    /**
     * @param int[] $uids
     * @return array<int, array<string, mixed>>
     */
    private function queryPagesByUids(array $uids): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('pages');
        $qb->getRestrictions()->removeAll();

        $rows = $qb->select('*')
            ->from('pages')
            ->where(
                $qb->expr()->eq('deleted', $qb->createNamedParameter(0, \PDO::PARAM_INT)),
                $qb->expr()->in('uid', $qb->createNamedParameter($uids, Connection::PARAM_INT_ARRAY)),
            )
            ->executeQuery()
            ->fetchAllAssociative();

        $byUid = [];
        foreach ($rows as $row) {
            $byUid[(int)$row['uid']] = $row;
        }
        return $byUid;
    }

    /**
     * @param array<int, array<string, mixed>> $pagesByUid
     * @param array<int, int> $pidOfUid
     * @param array<int, int> $sortingOfUid
     * @return array<int, int[]>
     */
    private function buildChildrenIndex(array $pagesByUid, array $pidOfUid, array $sortingOfUid): array
    {
        $childrenOfUid = [];
        foreach ($pagesByUid as $uid => $_row) {
            $parent = $pidOfUid[$uid] ?? 0;
            $childrenOfUid[$parent][] = $uid;
        }
        foreach ($childrenOfUid as $parent => $children) {
            usort(
                $children,
                static fn(int $a, int $b): int => ($sortingOfUid[$a] ?? 0) <=> ($sortingOfUid[$b] ?? 0)
            );
            $childrenOfUid[$parent] = $children;
        }
        return $childrenOfUid;
    }
}
