<?php

declare(strict_types=1);

namespace Nene\Func;

/**
 * Adjacency-list tree operations for nested data (categories, menus, threads).
 *
 * All methods accept a flat array of associative-array nodes. Nodes must have
 * at minimum an `$idKey` field (integer) and a `$parentKey` field (integer|null).
 * Root nodes have `parent_id === null` or `parent_id === 0`.
 *
 * No database calls are made — fetch the entire table once, then use these
 * methods on the result set.
 *
 * Usage:
 *   $categories = $mapper->findALL();          // flat SQL result
 *   $tree = TreeHelper::build($categories);    // nested with 'children' key
 *   $breadcrumb = TreeHelper::ancestors($categories, $currentId); // root→leaf
 */
final class TreeHelper
{
    /**
     * Build a nested tree from a flat adjacency-list array.
     *
     * Returns nodes whose $parentKey matches $rootId (null/0 = top-level),
     * each enriched with a `children` key containing their subtree recursively.
     *
     * @param array<int,array<string,mixed>> $nodes     Flat list of nodes.
     * @param int|null                       $rootId    Parent ID to start from (null or 0 = roots).
     * @param string                         $idKey     Key for the node's own ID.
     * @param string                         $parentKey Key for the node's parent ID.
     * @return list<array<string,mixed>>
     */
    public static function build(
        array $nodes,
        int|null $rootId = null,
        string $idKey = 'id',
        string $parentKey = 'parent_id',
    ): array {
        $result = [];
        foreach ($nodes as $node) {
            $nodeParent = $node[$parentKey] ?? null;
            // Treat 0 and null both as root
            $nodeParent = ($nodeParent === 0 || $nodeParent === '0') ? null : $nodeParent;
            $rootId = ($rootId === 0) ? null : $rootId;

            if ($nodeParent == $rootId) {
                $node['children'] = self::build($nodes, (int) $node[$idKey], $idKey, $parentKey);
                $result[] = $node;
            }
        }
        return $result;
    }

    /**
     * Return the ancestor chain from the root down to (but not including) the target node.
     *
     * Result is ordered root-first (suitable for breadcrumbs). Returns an empty
     * array if the node is already a root or does not exist.
     *
     * @param array<int,array<string,mixed>> $nodes
     * @return list<array<string,mixed>>
     */
    public static function ancestors(
        array $nodes,
        int $nodeId,
        string $idKey = 'id',
        string $parentKey = 'parent_id',
    ): array {
        // Build an id→node index for O(n) lookups
        $index = [];
        foreach ($nodes as $node) {
            $index[(int) $node[$idKey]] = $node;
        }

        $chain = [];
        $current = $index[$nodeId] ?? null;
        while ($current !== null) {
            $parentId = isset($current[$parentKey]) ? (int) $current[$parentKey] : 0;
            if ($parentId === 0) {
                break;
            }
            $parent = $index[$parentId] ?? null;
            if ($parent === null) {
                break;
            }
            array_unshift($chain, $parent);
            $current = $parent;
        }
        return $chain;
    }

    /**
     * Return all descendant nodes (flat list, any depth) of the given node.
     *
     * @param array<int,array<string,mixed>> $nodes
     * @return list<array<string,mixed>>
     */
    public static function descendants(
        array $nodes,
        int $nodeId,
        string $idKey = 'id',
        string $parentKey = 'parent_id',
    ): array {
        $result = [];
        foreach ($nodes as $node) {
            if ((int) ($node[$parentKey] ?? 0) === $nodeId) {
                $result[] = $node;
                $childId = (int) $node[$idKey];
                foreach (self::descendants($nodes, $childId, $idKey, $parentKey) as $desc) {
                    $result[] = $desc;
                }
            }
        }
        return $result;
    }

    /**
     * Return the depth of a node (root nodes = 0).
     *
     * Returns -1 if the node ID does not exist in $nodes.
     *
     * @param array<int,array<string,mixed>> $nodes
     */
    public static function depth(
        array $nodes,
        int $nodeId,
        string $idKey = 'id',
        string $parentKey = 'parent_id',
    ): int {
        $index = [];
        foreach ($nodes as $node) {
            $index[(int) $node[$idKey]] = $node;
        }
        if (!isset($index[$nodeId])) {
            return -1;
        }
        $depth = 0;
        $current = $index[$nodeId];
        while (true) {
            $parentId = isset($current[$parentKey]) ? (int) $current[$parentKey] : 0;
            if ($parentId === 0) {
                break;
            }
            $parent = $index[$parentId] ?? null;
            if ($parent === null) {
                break;
            }
            $depth++;
            $current = $parent;
        }
        return $depth;
    }

    /**
     * Flatten a nested tree (built by build()) back to a flat list.
     *
     * Useful for outputting a tree as a sorted flat list with depth information.
     * Each node gets a `_depth` key indicating its level.
     *
     * @param list<array<string,mixed>> $tree   Nested tree from build().
     * @param int                       $depth  Starting depth (default 0).
     * @return list<array<string,mixed>>
     */
    public static function flatten(array $tree, int $depth = 0): array
    {
        $result = [];
        foreach ($tree as $node) {
            $children = $node['children'] ?? [];
            unset($node['children']);
            $node['_depth'] = $depth;
            $result[] = $node;
            if (!empty($children)) {
                foreach (self::flatten($children, $depth + 1) as $child) {
                    $result[] = $child;
                }
            }
        }
        return $result;
    }
}
