<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Models\Tenant\Category;
use App\Services\Finance\NonStockCostAccounts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CategoryController extends Controller
{
    public function index(Request $request)
    {
        $query = Category::with(['parent', 'branch'])->orderBy('sort_order')->orderBy('name');

        if ($request->filled('search')) {
            $search = trim($request->search);
            $query->where(function ($q) use ($search) {
                $q->where('code', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%");
            });
        }

        return view('tenant.categories.index', [
            'categories' => $query->paginate(15)->withQueryString(),
        ]);
    }

    public function create()
    {
        return view('tenant.categories.form', [
            'category' => null,
            'title'    => 'Create Category',
            'parents'  => $this->parentChoices(null),
            'branches' => Branch::orderBy('name')->get(['id', 'name']),
            'costAccounts' => NonStockCostAccounts::choices(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $category = Category::create($this->withUpdatedBy($data));

        $category->translations()->updateOrCreate(
            ['language_code' => 'en'],
            ['name' => $category->name, 'description' => $request->input('description')]
        );

        return redirect('/categories')->with('status', 'Category created successfully.');
    }

    public function edit(Category $category)
    {
        return view('tenant.categories.form', [
            'category' => $category,
            'title'    => 'Edit Category',
            'parents'  => $this->parentChoices($category),
            'branches' => Branch::orderBy('name')->get(['id', 'name']),
            'costAccounts' => NonStockCostAccounts::choices(),
        ]);
    }

    public function update(Request $request, Category $category)
    {
        $data = $this->validated($request, $category);
        $category->update($this->withUpdatedBy($data));

        $category->translations()->updateOrCreate(
            ['language_code' => 'en'],
            ['name' => $category->name, 'description' => $request->input('description')]
        );

        return redirect('/categories')->with('status', 'Category updated successfully.');
    }

    public function destroy(Category $category)
    {
        if ($category->children()->exists() || $category->products()->exists()) {
            return back()->withErrors(['category' => 'Category has children or products and cannot be deleted.']);
        }

        $category->delete();

        return back()->with('status', 'Category deleted successfully.');
    }

    /**
     * CATEGORY-PARENT-GUARD-1: the parents a category may be put under — active ones, minus itself and everything below
     * it — each labelled with its full path ("Chicken Biryani › Biryani Saadi").
     */
    private function parentChoices(?Category $category)
    {
        $excluded = $category ? $category->descendantIds() + [(int) $category->id => true] : [];
        $labels = Category::pathLabels();

        return Category::where('is_active', true)->get(['id', 'parent_id', 'name'])
            ->reject(fn (Category $parent) => isset($excluded[(int) $parent->id]))
            ->each(fn (Category $parent) => $parent->setAttribute('path_label', $labels[(int) $parent->id] ?? $parent->name))
            ->sortBy('path_label', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();
    }

    /** CATEGORY-PARENT-GUARD-1: written only once the column exists, so a save between `git pull` and `migrate` never fails. */
    private function withUpdatedBy(array $fields): array
    {
        if (Schema::connection('tenant')->hasColumn('categories', 'updated_by')) {
            $fields['updated_by'] = auth('tenant')->id();
        }

        return $fields;
    }

    private function validated(Request $request, ?Category $category = null): array
    {
        $data = $request->validate([
            'parent_id'  => ['nullable', 'exists:categories,id'],
            // CATEGORY-BRANCH-SCOPE-1: blank = every branch, which is what every existing row is.
            'branch_id'  => ['nullable', 'exists:branches,id'],
            'code'       => ['nullable', 'string', 'max:50', Rule::unique('categories', 'code')->ignore($category?->id)],
            'name'       => ['required', 'string', 'max:190'],
            'description'=> ['nullable', 'string'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active'  => ['nullable', 'boolean'],
            // PNL-COST-BY-CATEGORY-1: only a 5… expense account that can take postings (not a group head).
            'cogs_account_id' => ['nullable', 'integer', Rule::in(NonStockCostAccounts::choices()->pluck('id')->all())],
        ], [
            'cogs_account_id.in' => 'Choose an active cost-of-sales account under 5000 (not a group head such as 5000 itself).',
        ]);

        $data['branch_id'] = $data['branch_id'] ?? null;

        // CATEGORY-PARENT-GUARD-1: never under itself or under anything below it. That makes a loop, and a category in
        // a loop always has a parent, so the POS (which shows only top-level tabs) shows it nowhere — 21 Oct, Khatri.
        // The parent list already leaves these out; this catches an old open tab or a hand-made request.
        if ($category && ! empty($data['parent_id'])) {
            $parentId = (int) $data['parent_id'];
            if ($parentId === (int) $category->id) {
                throw ValidationException::withMessages([
                    'parent_id' => 'A category cannot be its own parent. Choose another parent, or “None (Top Level)”.',
                ]);
            }
            if (isset($category->descendantIds()[$parentId])) {
                $parentName = Category::whereKey($parentId)->value('name');
                throw ValidationException::withMessages([
                    'parent_id' => "“{$category->name}” cannot go inside “{$parentName}”, because “{$parentName}” is already inside “{$category->name}”. "
                        . 'Choose another parent, or “None (Top Level)”.',
                ]);
            }
        }

        // A child may sit under a shared parent, or under a parent of its own branch — never under
        // a parent belonging to a DIFFERENT branch. Otherwise the POS would show a child whose tab
        // its branch never renders, and the Edge snapshot would ship a child without its parent.
        if (! empty($data['parent_id'])) {
            $parentBranchId = Category::whereKey($data['parent_id'])->value('branch_id');
            if ($parentBranchId !== null && (int) $parentBranchId !== (int) ($data['branch_id'] ?? 0)) {
                throw ValidationException::withMessages([
                    'branch_id' => 'This parent category belongs to another branch. Choose that branch, or a parent shared by all branches.',
                ]);
            }
        }

        $slugBase = Str::slug($data['name']);
        $slug = $slugBase;
        $counter = 1;

        while (
            Category::where('slug', $slug)
                ->when($category, fn ($q) => $q->where('id', '!=', $category->id))
                ->exists()
        ) {
            $slug = $slugBase . '-' . $counter++;
        }

        $fields = [
            'parent_id'  => $data['parent_id'] ?? null,
            'branch_id'  => $data['branch_id'],
            'code'       => $data['code'] ? strtoupper($data['code']) : null,
            'name'       => $data['name'],
            'slug'       => $slug,
            'sort_order' => $data['sort_order'] ?? 0,
            'is_active'  => !empty($data['is_active']),
            'cogs_account_id' => $data['cogs_account_id'] ?? null,
        ];

        // ONLINE-ORDERING-1: "Website" hissa sirf Online Ordering wale plan par form par aata hai. Na
        // aaye to website ke khaane nahi chhute; POS ke khaanon par kabhi asar nahi.
        if ($request->has('_website')) {
            $web = $request->validate([
                'website_name'         => ['nullable', 'string', 'max:120'],
                'website_sort_order'   => ['nullable', 'integer', 'min:0'],
                'website_visible_from' => ['nullable', 'date_format:H:i', 'required_with:website_visible_to'],
                'website_visible_to'   => ['nullable', 'date_format:H:i', 'required_with:website_visible_from'],
            ], [
                'website_visible_from.required_with' => 'Enter both times, or leave both empty to show it all day.',
                'website_visible_to.required_with'   => 'Enter both times, or leave both empty to show it all day.',
            ]);
            $fields += [
                'show_on_website'      => $request->boolean('show_on_website'),
                'website_name'         => trim((string) ($web['website_name'] ?? '')) ?: null,
                'website_sort_order'   => $web['website_sort_order'] ?? null,
                'website_visible_from' => $web['website_visible_from'] ?? null,
                'website_visible_to'   => $web['website_visible_to'] ?? null,
            ];
        }

        return $fields;
    }
}
