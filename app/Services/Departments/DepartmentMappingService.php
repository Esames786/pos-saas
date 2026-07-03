<?php

namespace App\Services\Departments;

use App\Models\Tenant\Category;
use App\Models\Tenant\Department;
use Illuminate\Support\Collection;

/**
 * DEPARTMENT-FOUNDATION-1 — resolves product → department per branch.
 *
 * Centralised so the sales/consumption reports (and future DEPT-2/3 phases)
 * never duplicate the mapping rules:
 *   1. explicit EXCLUDE override → department rejected for that product
 *   2. explicit INCLUDE override → department claims the product
 *   3. category map (with include_children tree expansion) → department claims it
 *   4. nothing matches → null (reports show "Unassigned"; never an error)
 * Multiple departments matching → first by sort_order then id wins; the others
 * are reported as a setup warning ("multiple") so admins can fix mappings.
 *
 * READ-ONLY: this service is NOT used by POS/stock posting in this phase.
 */
class DepartmentMappingService
{
    /** @var Collection<int, Department> active departments keyed by id (sorted) */
    private Collection $departments;

    /** @var array<int, array<int, true>> deptId => set of category ids (children expanded) */
    private array $categoryIdsByDept = [];

    /** @var array<int, array<int, string>> productId => [deptId => 'include'|'exclude'] */
    private array $overridesByProduct = [];

    private function __construct()
    {
        $this->departments = collect();
    }

    /**
     * Build a resolver for one branch. Loads everything up-front (a handful of
     * config rows) so report loops resolve in memory with zero extra queries.
     */
    public static function forBranch(int $branchId): self
    {
        $svc = new self();

        $svc->departments = Department::query()
            ->with(['categoryMaps', 'productOverrides'])
            ->where('branch_id', $branchId)
            ->where('status', 'active')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->keyBy('id');

        if ($svc->departments->isEmpty()) {
            return $svc;
        }

        // Expand each department's category maps once, sharing one tree query.
        $childrenByParent = Category::query()
            ->get(['id', 'parent_id'])
            ->groupBy('parent_id');

        foreach ($svc->departments as $dept) {
            $ids = [];
            foreach ($dept->categoryMaps as $map) {
                $ids[(int) $map->category_id] = true;
                if ($map->include_children) {
                    $queue = [(int) $map->category_id];
                    while ($queue) {
                        $parentId = array_shift($queue);
                        foreach ($childrenByParent->get($parentId, collect()) as $child) {
                            $ids[(int) $child->id] = true;
                            $queue[] = (int) $child->id;
                        }
                    }
                }
            }
            $svc->categoryIdsByDept[$dept->id] = $ids;

            foreach ($dept->productOverrides as $override) {
                $svc->overridesByProduct[(int) $override->product_id][$dept->id] = $override->mapping_type;
            }
        }

        return $svc;
    }

    public function hasDepartments(): bool
    {
        return $this->departments->isNotEmpty();
    }

    /** @return Collection<int, Department> */
    public function departments(): Collection
    {
        return $this->departments;
    }

    /**
     * All departments that claim this product (override + category rules applied).
     * Explicit INCLUDE claims are listed BEFORE category claims — "overrides win"
     * across departments, not just within one (e.g. Packing's include on foil
     * beats Kitchen's Grocery category map).
     *
     * @return array<int> department ids, include-claims first, then sort_order/id
     */
    public function matchingDepartmentIds(int $productId, ?int $categoryId): array
    {
        $includeClaims  = [];
        $categoryClaims = [];

        foreach ($this->departments as $deptId => $dept) {
            $override = $this->overridesByProduct[$productId][$deptId] ?? null;

            if ($override === 'exclude') {
                continue;
            }
            if ($override === 'include') {
                $includeClaims[] = $deptId;
                continue;
            }
            if ($categoryId !== null && isset($this->categoryIdsByDept[$deptId][$categoryId])) {
                $categoryClaims[] = $deptId;
            }
        }

        return array_merge($includeClaims, $categoryClaims);
    }

    /**
     * The winning department for a product, or null (= Unassigned).
     * Priority: first explicit-include department (by sort order), else first
     * category-mapped department (by sort order).
     */
    public function resolve(int $productId, ?int $categoryId): ?Department
    {
        $matches = $this->matchingDepartmentIds($productId, $categoryId);

        return $matches ? $this->departments->get($matches[0]) : null;
    }

    /**
     * True when the product's department is genuinely ambiguous — a
     * mapping-setup warning for reports, not an error. A single include
     * override deliberately overriding other departments' category maps is
     * intentional setup, NOT ambiguous; two include overrides (or two
     * category claims with no override) are.
     */
    public function isMultiMatch(int $productId, ?int $categoryId): bool
    {
        $includeClaims  = 0;
        $categoryClaims = 0;

        foreach ($this->departments as $deptId => $dept) {
            $override = $this->overridesByProduct[$productId][$deptId] ?? null;

            if ($override === 'exclude') {
                continue;
            }
            if ($override === 'include') {
                $includeClaims++;
                continue;
            }
            if ($categoryId !== null && isset($this->categoryIdsByDept[$deptId][$categoryId])) {
                $categoryClaims++;
            }
        }

        return $includeClaims > 1 || ($includeClaims === 0 && $categoryClaims > 1);
    }
}
