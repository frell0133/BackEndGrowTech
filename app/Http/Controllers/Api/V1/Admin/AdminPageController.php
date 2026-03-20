<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Page;
use App\Support\PublicCache;
use Illuminate\Http\Request;

class AdminPageController extends Controller
{
    public function index()
    {
        $pages = Page::orderByDesc('updated_at')->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $pages,
        ]);
    }

    public function showBySlug(string $slug)
    {
        $page = Page::where('slug', $slug)->first();

        return response()->json([
            'success' => true,
            'data' => $page,
        ]);
    }

    public function upsertBySlug(Request $request, string $slug)
    {
        $data = $request->validate([
            'title'        => ['required', 'string', 'max:255'],
            'content'      => ['required', 'string'],
            'is_published' => ['sometimes', 'boolean'],
        ]);

        $page = Page::updateOrCreate(
            ['slug' => $slug],
            [
                'title' => $data['title'],
                'content' => $data['content'],
                'is_published' => $data['is_published'] ?? true,
            ]
        );

        PublicCache::bumpContent();

        return response()->json([
            'success' => true,
            'data' => $page,
        ]);
    }

    public function patch(Request $request, int $id)
    {
        $data = $request->validate([
            'slug'         => ['sometimes', 'string', 'max:255', 'unique:pages,slug,' . $id],
            'title'        => ['sometimes', 'string', 'max:255'],
            'content'      => ['sometimes', 'string'],
            'is_published' => ['sometimes', 'boolean'],
        ]);

        $page = Page::findOrFail($id);
        $page->fill($data)->save();

        PublicCache::bumpContent();

        return response()->json([
            'success' => true,
            'data' => $page,
        ]);
    }

    public function destroy(int $id)
    {
        $page = Page::findOrFail($id);
        $page->delete();

        PublicCache::bumpContent();

        return response()->json([
            'success' => true,
        ]);
    }
}