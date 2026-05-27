<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Func;

use Nene\Func\TreeHelper;
use PHPUnit\Framework\TestCase;

final class TreeHelperTest extends TestCase
{
    /**
     * Fixture: Electronics (1) → Laptops (2) → Gaming (3)
     *          Electronics (1) → Tablets (4)
     *          Clothing (5) → Shirts (6)
     *
     * @return list<array<string,mixed>>
     */
    private function fixture(): array
    {
        return [
            ['id' => 1, 'name' => 'Electronics', 'parent_id' => null],
            ['id' => 2, 'name' => 'Laptops',     'parent_id' => 1],
            ['id' => 3, 'name' => 'Gaming',       'parent_id' => 2],
            ['id' => 4, 'name' => 'Tablets',      'parent_id' => 1],
            ['id' => 5, 'name' => 'Clothing',     'parent_id' => null],
            ['id' => 6, 'name' => 'Shirts',       'parent_id' => 5],
        ];
    }

    // --- build() ---

    public function testBuildReturnsOnlyRootNodes(): void
    {
        $tree = TreeHelper::build($this->fixture());
        self::assertCount(2, $tree);
        $names = array_column($tree, 'name');
        self::assertContains('Electronics', $names);
        self::assertContains('Clothing', $names);
    }

    public function testBuildNestesChildren(): void
    {
        $tree = TreeHelper::build($this->fixture());
        // Find Electronics node
        $electronics = null;
        foreach ($tree as $node) {
            if ($node['name'] === 'Electronics') {
                $electronics = $node;
                break;
            }
        }
        self::assertNotNull($electronics);
        /** @var array<string,mixed> $electronics */
        self::assertArrayHasKey('children', $electronics);
        self::assertCount(2, $electronics['children']);
        $childNames = array_column($electronics['children'], 'name');
        self::assertContains('Laptops', $childNames);
        self::assertContains('Tablets', $childNames);
    }

    public function testBuildNestesGrandchildren(): void
    {
        $tree = TreeHelper::build($this->fixture());
        $electronics = null;
        foreach ($tree as $node) {
            if ($node['name'] === 'Electronics') {
                $electronics = $node;
                break;
            }
        }
        self::assertNotNull($electronics);
        /** @var array<string,mixed> $electronics */
        $laptops = null;
        foreach ($electronics['children'] as $child) {
            if ($child['name'] === 'Laptops') {
                $laptops = $child;
                break;
            }
        }
        self::assertNotNull($laptops);
        /** @var array<string,mixed> $laptops */
        self::assertCount(1, $laptops['children']);
        self::assertSame('Gaming', $laptops['children'][0]['name']);
    }

    public function testBuildLeafNodeHasEmptyChildren(): void
    {
        $tree = TreeHelper::build($this->fixture());
        $electronics = null;
        foreach ($tree as $node) {
            if ($node['name'] === 'Electronics') {
                $electronics = $node;
                break;
            }
        }
        self::assertNotNull($electronics);
        /** @var array<string,mixed> $electronics */
        $laptops = null;
        foreach ($electronics['children'] as $child) {
            if ($child['name'] === 'Laptops') {
                $laptops = $child;
                break;
            }
        }
        self::assertNotNull($laptops);
        /** @var array<string,mixed> $laptops */
        $gaming = $laptops['children'][0];
        self::assertSame('Gaming', $gaming['name']);
        self::assertSame([], $gaming['children']);
    }

    public function testBuildWithExplicitRootId(): void
    {
        $result = TreeHelper::build($this->fixture(), 1);
        self::assertCount(2, $result);
        $names = array_column($result, 'name');
        self::assertContains('Laptops', $names);
        self::assertContains('Tablets', $names);
    }

    public function testBuildReturnsEmptyForUnknownParent(): void
    {
        $result = TreeHelper::build($this->fixture(), 999);
        self::assertSame([], $result);
    }

    // --- ancestors() ---

    public function testAncestorsForRootNodeReturnsEmpty(): void
    {
        $ancestors = TreeHelper::ancestors($this->fixture(), 1);
        self::assertSame([], $ancestors);
    }

    public function testAncestorsForChildReturnsParent(): void
    {
        $ancestors = TreeHelper::ancestors($this->fixture(), 2);
        self::assertCount(1, $ancestors);
        self::assertSame('Electronics', $ancestors[0]['name']);
    }

    public function testAncestorsForGrandchildReturnsBothAncestors(): void
    {
        $ancestors = TreeHelper::ancestors($this->fixture(), 3);
        self::assertCount(2, $ancestors);
        // Root-first order
        self::assertSame('Electronics', $ancestors[0]['name']);
        self::assertSame('Laptops', $ancestors[1]['name']);
    }

    public function testAncestorsForUnknownNodeReturnsEmpty(): void
    {
        $ancestors = TreeHelper::ancestors($this->fixture(), 999);
        self::assertSame([], $ancestors);
    }

    // --- descendants() ---

    public function testDescendantsOfRootReturnsAllUnder(): void
    {
        $descendants = TreeHelper::descendants($this->fixture(), 1);
        self::assertCount(3, $descendants);
        $names = array_column($descendants, 'name');
        self::assertContains('Laptops', $names);
        self::assertContains('Gaming', $names);
        self::assertContains('Tablets', $names);
    }

    public function testDescendantsOfLeafReturnsEmpty(): void
    {
        $descendants = TreeHelper::descendants($this->fixture(), 3);
        self::assertSame([], $descendants);
    }

    public function testDescendantsOfMiddleNodeReturnsSubtree(): void
    {
        $descendants = TreeHelper::descendants($this->fixture(), 2);
        self::assertCount(1, $descendants);
        self::assertSame('Gaming', $descendants[0]['name']);
    }

    // --- depth() ---

    public function testDepthForRootIsZero(): void
    {
        self::assertSame(0, TreeHelper::depth($this->fixture(), 1));
    }

    public function testDepthForChildIsOne(): void
    {
        self::assertSame(1, TreeHelper::depth($this->fixture(), 2));
    }

    public function testDepthForGrandchildIsTwo(): void
    {
        self::assertSame(2, TreeHelper::depth($this->fixture(), 3));
    }

    public function testDepthForUnknownNodeIsNegativeOne(): void
    {
        self::assertSame(-1, TreeHelper::depth($this->fixture(), 999));
    }

    // --- flatten() ---

    public function testFlattenPreservesDepthInfo(): void
    {
        $tree = TreeHelper::build($this->fixture());
        $flat = TreeHelper::flatten($tree);
        // Find Electronics and Laptops
        $byName = [];
        foreach ($flat as $node) {
            $byName[$node['name']] = $node;
        }
        self::assertSame(0, $byName['Electronics']['_depth']);
        self::assertSame(1, $byName['Laptops']['_depth']);
        self::assertSame(2, $byName['Gaming']['_depth']);
    }

    public function testFlattenOrderIsDepthFirst(): void
    {
        $tree = TreeHelper::build($this->fixture());
        $flat = TreeHelper::flatten($tree);
        $names = array_column($flat, 'name');
        // Electronics should appear before its children
        $idxElectronics = array_search('Electronics', $names);
        $idxLaptops = array_search('Laptops', $names);
        $idxGaming = array_search('Gaming', $names);
        self::assertGreaterThan($idxElectronics, $idxLaptops);
        self::assertGreaterThan($idxLaptops, $idxGaming);
    }
}
