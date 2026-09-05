<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Models\Tenant\Category;
use Illuminate\Http\Request;
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
            'parents'  => Category::where('is_active', true)->orderBy('name')->get(),
            'branches' => Branch::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $category = Category::create($data);

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
            'parents'  => Category::where('id', '!=', $category->id)->where('is_active', true)->orderBy('name')->get(),
            'branches' => Branch::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function update(Request $request, Category $category)
    {
        $data = $this->validated($request, $category);
        $category->update($data);

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
        ]);

        $data['branch_id'] = $data['branch_id'] ?? null;

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

        return [
            'parent_id'  => $data['parent_id'] ?? null,
            'branch_id'  => $data['branch_id'],
            'code'       => $data['code'] ? strtoupper($data['code']) : null,
            'name'       => $data['name'],
            'slug'       => $slug,
            'sort_order' => $data['sort_order'] ?? 0,
            'is_active'  => !empty($data['is_active']),
        ];
    }
}
