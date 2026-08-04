<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CategoryController extends Controller
{
    public function index(Request $request): Response
    {
        $categories = Category::query()
            ->with('parent:id,name')
            ->withCount('products')
            ->when($request->string('search')->toString(), fn ($query, $search) => $query->where(
                fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('slug', 'like', "%{$search}%")
            ))
            ->when($request->string('status')->toString(), fn ($query, $status) => $query->where('is_active', $status === 'active'))
            ->when($request->filled('parent'), fn ($query) => $query->where('parent_id', $request->integer('parent')))
            ->orderBy('position')
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('admin/categories/Index', [
            'categories' => $categories,
            'filters' => $request->only(['search', 'status', 'parent']),
            'parents' => Category::whereNull('parent_id')->orderBy('name')->get(['id', 'name']),
            'counts' => [
                'all' => Category::count(),
                'active' => Category::where('is_active', true)->count(),
                'inactive' => Category::where('is_active', false)->count(),
            ],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/categories/Form', [
            'category' => null,
            'parents' => $this->parentOptions(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $category = Category::create($data);

        return to_route('admin.categories.index')
            ->with('success', "Category \"{$category->name}\" created.");
    }

    public function edit(Category $category): Response
    {
        return Inertia::render('admin/categories/Form', [
            'category' => $category,
            'parents' => $this->parentOptions($category),
        ]);
    }

    public function update(Request $request, Category $category): RedirectResponse
    {
        $data = $this->validated($request, $category);

        $category->update($data);

        return to_route('admin.categories.index')
            ->with('success', "Category \"{$category->name}\" updated.");
    }

    public function destroy(Category $category): RedirectResponse
    {
        if ($category->children()->exists()) {
            return back()->with('error', 'Move or delete the sub-categories first.');
        }

        $category->products()->update(['category_id' => null]);
        $category->delete();

        return to_route('admin.categories.index')->with('success', 'Category deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function validated(Request $request, ?Category $category = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'slug' => ['nullable', 'string', 'max:170', Rule::unique('categories', 'slug')->ignore($category)],
            'parent_id' => [
                'nullable',
                'integer',
                Rule::exists('categories', 'id')->where(fn ($q) => $category ? $q->whereNot('id', $category->id) : $q),
            ],
            'description' => ['nullable', 'string', 'max:2000'],
            'image_path' => ['nullable', 'string', 'max:255'],
            'position' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['boolean'],
            'is_featured' => ['boolean'],
            'seo_title' => ['nullable', 'string', 'max:180'],
            'seo_description' => ['nullable', 'string', 'max:400'],
        ]);

        $data['slug'] = ! empty($data['slug'])
            ? Category::uniqueSlug($data['slug'], $category?->id)
            : Category::uniqueSlug($data['name'], $category?->id);
        $data['position'] ??= 0;

        return $data;
    }

    /**
     * Top-level categories that can be used as a parent.
     *
     * @return Collection<int, Category>
     */
    protected function parentOptions(?Category $category = null): Collection
    {
        return Category::query()
            ->when($category, fn ($query) => $query->whereNot('id', $category->id))
            ->orderBy('name')
            ->get(['id', 'name', 'parent_id']);
    }
}
