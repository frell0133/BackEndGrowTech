<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use App\Models\Faq;
use Illuminate\Http\Request;

class AdminFaqController extends Controller
{
    use ApiResponse;

    public function index(Request $request)
    {
        $q = Faq::query()->orderBy('sort_order')->orderBy('id');
        if ($request->filled('is_active')) {
            $q->where(
                'is_active',
                filter_var($request->query('is_active'), FILTER_VALIDATE_BOOLEAN)
            );
        }

        return $this->ok($q->get());
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'question'   => ['required','string','max:255'],
            'answer'     => ['required','string'],
            'sort_order' => ['nullable','integer'],
            'is_active'  => ['required','boolean'],
        ]);

        $data['sort_order'] = $data['sort_order'] ?? 0;

        return $this->ok(Faq::create($data));
    }

    public function update(Request $request, int $id)
    {
        $faq = Faq::find($id);
        if (!$faq) return $this->fail('FAQ tidak ditemukan', 404);

        $data = $request->validate([
            'question'   => ['nullable','string','max:255'],
            'answer'     => ['nullable','string'],
            'sort_order' => ['nullable','integer'],
            'is_active'  => ['nullable','boolean'],
        ]);

        $faq->update($data);

        return $this->ok($faq);
    }

    public function destroy(int $id)
    {
        $faq = Faq::find($id);
        if (!$faq) return $this->fail('FAQ tidak ditemukan', 404);

        $faq->delete();

        return $this->ok(['deleted' => true]);
    }
}
