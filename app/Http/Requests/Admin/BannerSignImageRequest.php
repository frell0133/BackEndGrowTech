<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class BannerSignImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // kalau kamu punya gate/role admin, ganti sesuai policy
    }

    public function rules(): array
    {
        return [
            'mime' => ['required', 'string', 'starts_with:image/'],
        ];
    }
}
