<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Attribute;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AttributeController extends Controller
{
    public function index(Request $request): Response
    {
        $attributes = Attribute::query()
            ->with('values')
            ->withCount('products')
            ->when($request->string('search')->toString(), fn ($query, $search) => $query->where('name', 'like', "%{$search}%"))
            ->when($request->string('type')->toString(), fn ($query, $type) => $query->where('type', $type))
            ->orderBy('position')
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('admin/attributes/Index', [
            'attributes' => $attributes,
            'filters' => $request->only(['search', 'type']),
            'types' => Attribute::TYPES,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/attributes/Form', [
            'attribute' => null,
            'types' => Attribute::TYPES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $attribute = Attribute::create($this->attributePayload($data));
        $this->syncValues($attribute, $data['values']);

        return to_route('admin.attributes.index')
            ->with('success', "Attribute \"{$attribute->name}\" created.");
    }

    public function edit(Attribute $attribute): Response
    {
        return Inertia::render('admin/attributes/Form', [
            'attribute' => $attribute->load('values'),
            'types' => Attribute::TYPES,
        ]);
    }

    public function update(Request $request, Attribute $attribute): RedirectResponse
    {
        $data = $this->validated($request, $attribute);

        $attribute->update($this->attributePayload($data));
        $this->syncValues($attribute, $data['values']);

        return to_route('admin.attributes.index')
            ->with('success', "Attribute \"{$attribute->name}\" updated.");
    }

    public function destroy(Attribute $attribute): RedirectResponse
    {
        if ($attribute->products()->exists()) {
            return back()->with('error', 'This attribute is used by products and cannot be deleted.');
        }

        $attribute->delete();

        return to_route('admin.attributes.index')->with('success', 'Attribute deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function validated(Request $request, ?Attribute $attribute = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['nullable', 'string', 'max:140', Rule::unique('attributes', 'slug')->ignore($attribute)],
            'type' => ['required', Rule::in(Attribute::TYPES)],
            'is_variant' => ['boolean'],
            'is_filterable' => ['boolean'],
            'is_active' => ['boolean'],
            'position' => ['nullable', 'integer', 'min:0'],
            'values' => ['required', 'array', 'min:1'],
            'values.*.id' => ['nullable', 'integer'],
            'values.*.value' => ['required', 'string', 'max:120'],
            'values.*.color_hex' => ['nullable', 'string', 'max:9'],
        ], [
            'values.min' => 'Add at least one value for this attribute.',
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function attributePayload(array $data): array
    {
        return [
            'name' => $data['name'],
            'slug' => Str::slug(empty($data['slug']) ? $data['name'] : $data['slug']),
            'type' => $data['type'],
            'is_variant' => $data['is_variant'] ?? true,
            'is_filterable' => $data['is_filterable'] ?? true,
            'is_active' => $data['is_active'] ?? true,
            'position' => $data['position'] ?? 0,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $values
     */
    protected function syncValues(Attribute $attribute, array $values): void
    {
        $keptIds = [];

        foreach (array_values($values) as $position => $value) {
            $record = $attribute->values()->updateOrCreate(
                ['id' => $value['id'] ?? null],
                [
                    'value' => $value['value'],
                    'slug' => Str::slug($value['value']),
                    'color_hex' => $value['color_hex'] ?? null,
                    'position' => $position,
                ],
            );

            $keptIds[] = $record->id;
        }

        $attribute->values()->whereNotIn('id', $keptIds)->delete();
    }
}
