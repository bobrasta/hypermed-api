<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CategoryController extends Controller
{
    public function index(Request $request)
    {
        $query = Category::withCount('items');

        if (!$request->boolean('include_inactive')) {
            $query->where('is_active', true);
        }

        return CategoryResource::collection($query->orderBy('name')->get());
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'        => ['required', 'string', 'max:255'],
            'parent_id'   => ['nullable', 'exists:categories,id'],
            'description' => ['nullable', 'string'],
        ]);

        $data['slug'] = Str::slug($data['name']);

        $category = Category::create($data);
        return new CategoryResource($category);
    }

    public function show(Category $category)
    {
        return new CategoryResource($category->loadCount('items'));
    }

    public function update(Request $request, Category $category)
    {
        $data = $request->validate([
            'name'        => ['sometimes', 'string', 'max:255'],
            'parent_id'   => ['nullable', 'exists:categories,id'],
            'description' => ['nullable', 'string'],
            'is_active'   => ['sometimes', 'boolean'],
        ]);

        if (isset($data['name'])) {
            $data['slug'] = Str::slug($data['name']);
        }

        $category->update($data);
        return new CategoryResource($category);
    }

    public function destroy(Category $category)
    {
        $category->update(['is_active' => false]);
        return response()->noContent();
    }
}
