<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Page;
use Illuminate\Http\Request;

class AdminPageController extends Controller
{
    // GET /api/v1/admin/pages
    public function index()
    {
        $pages = Page::orderByDesc('updated_at')->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $pages,
        ]);
    }

    // GET /api/v1/admin/pages/slug/{slug}  (buat load form edit)
    public function showBySlug(string $slug)
    {
        $page = Page::where('slug', $slug)->first();

        return response()->json([
            'success' => true,
            'data' => $page, // bisa null kalau belum ada
        ]);
    }

    // PUT /api/v1/admin/pages/slug/{slug} (UPSERT)
    public function upsertBySlug(Request $request, string $slug)
    {
        $data = $request->validate([
            'title'        => ['required', 'string', 'max:255'],
            'content'      => ['required', 'string'],
            'is_published' => ['sometimes', 'boolean'], // optional
        ]);

        $page = Page::updateOrCreate(
            ['slug' => $slug],
            [
                'title' => $data['title'],
                'content' => $data['content'],
                'is_published' => $data['is_published'] ?? true,
            ]
        );

        return response()->json([
            'success' => true,
            'data' => $page,
        ]);
    }

    // PATCH /api/v1/admin/pages/{id}
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

        return response()->json([
            'success' => true,
            'data' => $page,
        ]);
    }


    // (opsional) DELETE /api/v1/admin/pages/{id}
    public function destroy(int $id)
    {
        $page = Page::findOrFail($id);
        $page->delete();

        return response()->json(['success' => true]);
    }
}
