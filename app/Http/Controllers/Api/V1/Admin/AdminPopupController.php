<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Popup;
use App\Support\PublicCache;
use Illuminate\Http\Request;

class AdminPopupController extends Controller
{
    public function index()
    {
        $rows = Popup::orderByDesc('id')->get();

        return response()->json([
            'success' => true,
            'data' => $rows,
            'meta' => (object) [],
            'error' => null,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'content' => ['required', 'string'],
            'cta_text' => ['nullable', 'string', 'max:60'],
            'cta_url' => ['nullable', 'url', 'max:255'],
            'is_active' => ['required'],
            'target' => ['required', 'string', 'max:50'],
        ]);

        $data['is_active'] = filter_var($data['is_active'], FILTER_VALIDATE_BOOLEAN);

        $popup = Popup::create($data);

        PublicCache::bumpContent();

        return response()->json([
            'success' => true,
            'data' => $popup,
            'meta' => (object) [],
            'error' => null,
        ], 201);
    }

    public function show(Popup $popup)
    {
        return response()->json([
            'success' => true,
            'data' => $popup,
            'meta' => (object) [],
            'error' => null,
        ]);
    }

    public function update(Request $request, Popup $popup)
    {
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:200'],
            'content' => ['sometimes', 'string'],
            'cta_text' => ['sometimes', 'nullable', 'string', 'max:60'],
            'cta_url' => ['sometimes', 'nullable', 'url', 'max:255'],
            'is_active' => ['sometimes'],
            'target' => ['sometimes', 'string', 'max:50'],
        ]);

        if (array_key_exists('is_active', $data)) {
            $data['is_active'] = filter_var($data['is_active'], FILTER_VALIDATE_BOOLEAN);
        }

        $popup->update($data);

        PublicCache::bumpContent();

        return response()->json([
            'success' => true,
            'data' => $popup->fresh(),
            'meta' => (object) [],
            'error' => null,
        ]);
    }

    public function destroy(Popup $popup)
    {
        $popup->delete();

        PublicCache::bumpContent();

        return response()->json([
            'success' => true,
            'data' => true,
            'meta' => (object) [],
            'error' => null,
        ]);
    }
}