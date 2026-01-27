<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Popup extends Model
{
    protected $table = 'popups'; // kalau schema public, ini cukup

    protected $fillable = [
        'title',
        'content',
        'cta_text',
        'cta_url',
        'is_active',
        'target',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
